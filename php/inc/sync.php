<?php
declare(strict_types=1);
/* Fiyat, katalog, kategori, puan ve oyun bilgisi senkronizasyonu (Node sürümündeki src/sync.js).
   PHP'de sürekli çalışan süreç olmadığı için:
   - runSync() ve startBackgroundWork() bütçelidir (budget_start() ile verilen süre dolunca temiz çıkar) ve devam edebilir:
     ilerleme meta tablosunda tutulur (sync_state, steam_import_cursor, categories_progress, bg_rot).
   - Öncelikli kuyruk veri tabanındaki `queue` tablosundadır; queuePriority() ekler, processPriority() işler. */

const BACKGROUND_SHARE = 0.6;   // arka plan işleri ITAD limitinin en fazla %60'ı

function catalogSize(): int { return min(max(env_int('CATALOG_SIZE', 1000), 0), 1000); }
function tailHours(): float { return (float) env('TAIL_REFRESH_HOURS', 24); }
function syncMinutes(): int { return max(1, env_int('SYNC_MINUTES', 60)); }

/** "fetch failed" yerine gerçek sebebi göster */
function errText(Throwable $e): string { return $e->getMessage(); }

/* ---------- Küçük yardımcılar ---------- */

/** Hazır (önbellekli) ifade: döngülerde tekrar tekrar prepare etmemek için */
function sq(string $sql): PDOStatement { static $c = []; return $c[$sql] ??= db()->prepare($sql); }
function sqAll(string $sql, array $a = []): array { $s = sq($sql); $s->execute($a); return $s->fetchAll(); }
function sqOne(string $sql, array $a = []): ?array { $r = sqAll($sql, $a); return $r[0] ?? null; }
function sqRun(string $sql, array $a = []): int { $s = sq($sql); $s->execute($a); return $s->rowCount(); }

function chunks(array $a, int $n): array { return array_chunk(array_values($a), max(1, $n)); }
function nowIso(): string { return gmdate('Y-m-d\TH:i:s') . '.000Z'; }
/** SQLite'ın "YYYY-MM-DD HH:MM:SS" (UTC) zamanını unix saniyesine çevirir */
function sqliteTime(?string $s): int { return $s ? (int) strtotime($s . ' UTC') : 0; }
function olderThan(string $key, float $hours): bool { $v = meta_get($key); return !$v || time() - (int) strtotime($v) > $hours * 3600; }

/** $seconds saniyelik alt bütçe: mevcut bütçeyi aşmaz, bitince eskisine döner */
function withSlice(float $seconds, callable $fn) {
    $old = $GLOBALS['__budget_end'] ?? null;
    $end = microtime(true) + $seconds;
    if ($old !== null) $end = min($end, $old);
    $GLOBALS['__budget_end'] = $end;
    try { return $fn(); }
    finally { if ($old === null) unset($GLOBALS['__budget_end']); else $GLOBALS['__budget_end'] = $old; }
}
/** Bütçe hiç başlatılmadıysa (CLI ya da doğrudan çağrı) varsayılan bir bütçe başlat */
function ensureBudget(float $cliSeconds = 1800, float $webSeconds = 20): void {
    if (!isset($GLOBALS['__budget_end'])) budget_start(PHP_SAPI === 'cli' ? $cliSeconds : $webSeconds);
}
/** Arka plan işleri ITAD limitinin payını aştıysa true: iş durup sonra devam eder (Node'da burada bekleniyordu) */
function bgThrottled(float $share = BACKGROUND_SHARE): bool { return itadUsage() >= itadRateLimit() * $share; }
/** Kapak/bilgi çekme istek başına tek oyun harcar ve adayı çok olduğu için payın hepsini yerdi; fiyatlara pay kalsın diye üst sınırı küçük */
const INFO_SHARE = 0.2;
/** Hata sonrası bekleme (Node: 60 sn). Bütçe yetmiyorsa false: çağıran işi bırakır */
function retryPause(int $sec): bool {
    if (budget_left() < $sec + 4) return false;
    sleep($sec);
    return true;
}

function seedList(): array {
    static $seed = null;
    if ($seed !== null) return $seed;
    foreach ([ROOT . '/games.json', dirname(ROOT) . '/games.json'] as $f) {
        if (is_file($f)) { $j = json_decode((string) file_get_contents($f), true); return $seed = is_array($j) ? $j : []; }
    }
    return $seed = [];
}

function insGame($id, $slug, $title, $type): int {
    return sqRun('INSERT INTO games (id, slug, title, type) VALUES (?, ?, ?, ?) ON CONFLICT(id) DO NOTHING', [$id, $slug, $title, $type]);
}
function setAppidIfNull($appid, $id): void { sqRun('UPDATE games SET appid = ? WHERE id = ? AND appid IS NULL', [$appid, $id]); }

/** Bir kerelik göçler (eski veri tabanları için) */
function syncInit(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // Geçmiş eskiden 3 ay çekiliyordu; 3 yıla çıkınca oyunlar açıldıkça geçmişleri bir kez yeniden çekilsin
    if (!meta_get('history_v2')) { db()->exec('DELETE FROM history_fetched'); meta_set('history_v2', '1'); }
}

/* ---------- 1) Katalog ---------- */

/** games.json'daki oyunlar her zaman sitede olsun */
function ensureSeeds(): void {
    foreach (seedList() as $s) {
        $appid = (int) ($s['steamAppId'] ?? 0);
        if (!$appid || sqOne('SELECT 1 FROM games WHERE steam_appid = ?', [$appid])) continue;
        if (!budget_ok(5)) throw new BudgetExhausted('oyun listesi tohumları için süre kalmadı');
        $f = itadLookup($appid);
        if (empty($f['found'])) { log_line('bulunamadı: ' . ($s['title'] ?? $appid)); continue; }
        $g = $f['game'];
        insGame($g['id'], $g['slug'] ?? null, $g['title'], $g['type'] ?? null);
        sqRun('UPDATE games SET steam_appid = ?, appid = ? WHERE id = ? AND steam_appid IS NULL', [$appid, $appid, $g['id']]);
    }
}

/** En popüler oyunlar */
function syncCatalog(): void {
    $all = [];
    $size = catalogSize();
    for ($off = 0; $off < $size; $off += 100) {
        try { $page = itadMostPopular($off, min(100, $size - $off)); }
        catch (BudgetExhausted $e) { throw $e; }
        catch (Throwable $e) { log_line('popüler liste alınamadı: ' . errText($e)); break; }
        if (!$page) break;
        array_push($all, ...$page);
        if (count($page) < 100) break;
    }
    if (!$all) return;
    tx(function () use ($all) {
        db()->exec('UPDATE games SET popular_rank = NULL');
        foreach ($all as $x) {
            insGame($x['id'], $x['slug'] ?? null, $x['title'], null);
            sqRun('UPDATE games SET popular_rank = ? WHERE id = ?', [$x['position'], $x['id']]);
        }
    });
    log_line('katalog: ' . count($all) . ' popüler oyun');
}

/** Steam'deki tüm oyunlar: günde bir listeyi yenile (sayfa sayfa, kaldığı yerden), sonra arka planda sitenin kataloğuna çevir */
function importSteamList(): void {
    $key = env('STEAM_API_KEY');
    if (!$key) return;
    $cursor = (int) (meta_get('steam_import_cursor') ?? 0);
    $last = meta_get('steam_import');
    if (!$cursor && $last && time() - (int) strtotime($last) < 24 * 3600) return;
    if (!budget_ok(20)) return;
    $n = 0;
    foreach (steamGamePages((string) $key, $cursor) as $next => $apps) {
        tx(function () use ($apps, &$n) {
            $st = sq('INSERT INTO steam_apps (appid, name) VALUES (?, ?) ON CONFLICT(appid) DO UPDATE SET name = excluded.name');
            foreach ($apps as $a) if (!empty($a['appid']) && !empty($a['name'])) { $st->execute([$a['appid'], $a['name']]); $n++; }
        });
        if ($next) meta_set('steam_import_cursor', (string) $next);   // bütçe biterse buradan devam edilir
        if (!budget_ok(25)) { log_line("Steam listesi: $n oyun alındı, kalanı sonraki turda"); return; }
    }
    db()->exec("DELETE FROM meta WHERE key = 'steam_import_cursor'");
    meta_set('steam_import', nowIso());
    $left = (int) qv('SELECT COUNT(*) FROM steam_apps WHERE mapped = 0', [], 0);
    log_line("Steam listesi: $n oyun ($left tanesi siteye eklenmeyi bekliyor)");
}

function mapSteamInBackground(): void {
    if (!env('STEAM_API_KEY')) return;
    $added = 0; $errors = 0;
    while (budget_ok(5)) {
        $batch = sqAll('SELECT appid, name FROM steam_apps WHERE mapped = 0 ORDER BY appid DESC LIMIT 200');
        if (!$batch) break;
        if (bgThrottled()) break;
        try { $res = itadLookupSteam(array_column($batch, 'appid')); $errors = 0; }
        catch (BudgetExhausted $e) { break; }
        catch (Throwable $e) {
            if (++$errors >= 3) { log_line('Steam eşleme durdu, sonra devam edecek: ' . $e->getMessage()); break; }
            if (!retryPause(60)) break;
            continue;
        }
        tx(function () use ($batch, $res, &$added) {
            foreach ($batch as $b) {
                $id = $res["app/{$b['appid']}"] ?? null;
                if ($id && !sqOne('SELECT 1 FROM games WHERE appid = ?', [$b['appid']])) {
                    if (insGame($id, null, $b['name'], 'game')) $added++;
                    setAppidIfNull($b['appid'], $id);
                }
                sqRun('UPDATE steam_apps SET mapped = 1 WHERE appid = ?', [$b['appid']]);
            }
        });
    }
    if ($added) log_line("Steam oyunları siteye eklendi: $added yeni oyun");
}

/* ---------- 2) Fiyatlar ---------- */

function toUsd(array $p): float { return (float) $p['amount'] * usdRate((string) $p['currency']); }

/**
 * $ids için mağaza fiyatlarını çekip prices/lows/price_history tablolarına yazar.
 * $background true ise ITAD payının dolmasında BudgetExhausted fırlatır (çağıran sonra devam eder).
 */
function syncPricesFor(array $ids, array $countries = COUNTRIES, bool $background = false): void {
    $blocked = blockedShops();
    $samples = [];
    foreach (chunks($ids, 200) as $part) {
        foreach ($countries as $c) {
            if ($background && bgThrottled()) throw new BudgetExhausted('arka plan API payı doldu');
            $data = itadPrices($part, $c);
            tx(function () use ($data, $c, $blocked, &$samples) {
                foreach ($data ?? [] as $g) {
                    sqRun('DELETE FROM prices WHERE game_id = ? AND country = ?', [$g['id'], $c]);
                    $byShop = [];
                    foreach ($g['deals'] ?? [] as $d) {
                        $k = (string) $d['shop']['id'];
                        if (!empty($d['url'])) {
                            $sm = $samples[$k] ?? ['name' => $d['shop']['name'], 'urls' => []];
                            if (count($sm['urls']) < 3) $sm['urls'][] = $d['url'];
                            $samples[$k] = $sm;
                        }
                        if (isBlocked($blocked, $k, $d['shop']['name'])) continue;   // bu sunucudan açılmayan mağaza
                        if (!isset($byShop[$k]) || $d['price']['amount'] < $byShop[$k]['price']['amount']) $byShop[$k] = $d;
                    }
                    $low = null;
                    foreach ($byShop as $shopId => $d) {
                        sqRun("INSERT INTO prices (game_id, country, shop_id, shop_name, price, regular, currency, cut, expiry, url, usd, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?, datetime('now'))",
                            [$g['id'], $c, (string) $shopId, $d['shop']['name'], $d['price']['amount'], $d['regular']['amount'],
                             $d['price']['currency'], $d['cut'] ?? 0, $d['expiry'] ?? null, $d['url'] ?? null, toUsd($d['price'])]);
                        if (!$low || toUsd($d['price']) < toUsd($low['price'])) $low = $d;
                    }
                    if ($low) {
                        $sameCur = fn($h) => ($h && ($h['currency'] ?? null) === $low['price']['currency']) ? $h['amount'] : null;
                        sqRun('INSERT OR REPLACE INTO lows (game_id, country, shop, price, regular, currency, cut, expiry, usd, low_m3, low_all)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                            [$g['id'], $c, $low['shop']['name'], $low['price']['amount'], $low['regular']['amount'], $low['price']['currency'],
                             $low['cut'] ?? 0, $low['expiry'] ?? null, toUsd($low['price']),
                             $sameCur($g['historyLow']['m3'] ?? null), $sameCur($g['historyLow']['all'] ?? null)]);
                        sqRun("INSERT INTO price_history (game_id, country, day, lowest, currency) VALUES (?, ?, date('now'), ?, ?)
                          ON CONFLICT(game_id, country, day) DO UPDATE SET lowest = MIN(lowest, excluded.lowest)",
                            [$g['id'], $c, $low['price']['amount'], $low['price']['currency']]);
                    } else sqRun('DELETE FROM lows WHERE game_id = ? AND country = ?', [$g['id'], $c]);
                }
            });
        }
        if (count($countries) === count(COUNTRIES)) tx(function () use ($part) { foreach ($part as $id) sqRun("UPDATE games SET last_priced = datetime('now') WHERE id = ?", [$id]); });
    }
    if ($samples) rememberSamples($samples);
}

const HOT_WHERE = "hidden = 0 AND (popular_rank IS NOT NULL OR steam_appid IS NOT NULL OR waitlist_rank IS NOT NULL OR collected_rank IS NOT NULL
    OR viewed_at >= datetime('now', '-7 days') OR id IN (SELECT game_id FROM wishlist))";

/** Sık güncellenenler: popüler, istek listesinde olan, son 7 günde bakılan, games.json'dakiler. İmleçle devam eder; bitince true */
function syncHotPrices(string &$cursor): bool {
    $n = 0;
    while (budget_ok(6)) {
        $ids = array_column(sqAll('SELECT id FROM games WHERE ' . HOT_WHERE . ' AND id > ? ORDER BY id LIMIT 200', [$cursor]), 'id');
        if (!$ids) { log_line("fiyatlar güncellendi: $n oyun (bu turda)"); return true; }
        try { syncPricesFor($ids); }
        catch (BudgetExhausted $e) { return false; }
        $cursor = end($ids);
        $n += count($ids);
    }
    return false;
}

/** Diğer tüm oyunlar: arka planda, her biri tailHours() saatte bir */
function tailPricesInBackground(): void {
    $age = '-' . tailHours() . ' hours';
    $errors = 0; $done = 0;
    while (budget_ok(6)) {
        $ids = array_column(sqAll("SELECT id FROM games WHERE hidden = 0 AND (last_priced IS NULL OR last_priced < datetime('now', ?))
          ORDER BY last_priced IS NOT NULL, last_priced LIMIT 200", [$age]), 'id');
        if (!$ids) break;
        try { syncPricesFor($ids, COUNTRIES, true); $errors = 0; }
        catch (BudgetExhausted $e) { break; }
        catch (Throwable $e) {
            if (++$errors >= 3) { log_line('arka plan fiyat güncellemesi durdu, sonra devam edecek: ' . $e->getMessage()); break; }
            if (!retryPause(60)) break;
            continue;
        }
        $done += count($ids);
    }
    if (!$done && bgThrottled()) log_line('arka plan fiyatları: ITAD payı dolu, sonraki turda denenecek');
    if ($done) {
        $left = (int) qv("SELECT COUNT(*) FROM games WHERE hidden = 0 AND (last_priced IS NULL OR last_priced < datetime('now', ?))", [$age], 0);
        log_line("arka plan fiyatları: $done oyun güncellendi, $left kaldı");
    }
}

/* ---------- 3) Oyun bilgisi ve kapak ---------- */

function ensureInfo(string $id): void {
    $row = sqOne('SELECT info_done FROM games WHERE id = ?', [$id]);
    if (!$row || $row['info_done']) return;
    $i = itadInfo($id);
    $type = $i['type'] ?? null;
    // oyun olmayan şeyler (DLC, soundtrack vb.) tamamen gizlenir; +18 içerik gizlenmez, ön yüzde bulanıklaştırılır
    $hide = ($type && $type !== 'game') ? 1 : 0;
    $mature = !empty($i['mature']) ? 1 : 0;
    $tags = $i['tags'] ?? [];
    tx(function () use ($i, $id, $tags, $type, $hide, $mature) {
        sqRun('UPDATE games SET title = ?, release_date = ?, developers = ?, tags = ?,
          boxart = COALESCE(?, boxart), type = ?, hidden = ?, mature = ?, free = ?, info_done = 1 WHERE id = ?',
            [$i['title'], $i['releaseDate'] ?? null, jenc(array_map(fn($d) => $d['name'], $i['developers'] ?? [])),
             jenc($tags), $i['assets']['boxart'] ?? ($i['assets']['banner600'] ?? null), $type, $hide, $mature,
             in_array('Free to Play', $tags, true) ? 1 : 0, $id]);
        foreach ($tags as $t) sqRun('INSERT OR IGNORE INTO game_tags (game_id, tag) VALUES (?, ?)', [$id, $t]);
    });
}

/** Bilgiler arka planda sadece sık bakılan oyunlar için çekilir; diğerleri sayfaları açılınca */
function enrichInBackground(): void {
    $errors = 0; $done = 0;
    while (budget_ok(4)) {
        $g = sqOne('SELECT id FROM games WHERE info_done = 0 AND (
            popular_rank IS NOT NULL OR steam_appid IS NOT NULL OR viewed_at IS NOT NULL
            OR id IN (SELECT game_id FROM wishlist) OR appid IS NULL)
          ORDER BY popular_rank IS NULL, popular_rank LIMIT 1');
        if (!$g) break;
        if (bgThrottled(INFO_SHARE)) break;
        try { ensureInfo($g['id']); $errors = 0; $done++; }
        catch (BudgetExhausted $e) { break; }
        catch (Throwable $e) {
            if (++$errors >= 3) { log_line('kapak/bilgi çekme durdu, sonra devam edecek: ' . $e->getMessage()); break; }
            if (!retryPause(60)) break;
        }
    }
    if ($done) log_line("kapak ve bilgiler güncel ($done oyun)");
}

/* ---------- 4) Fiyat geçmişi (oyun sayfası ilk açıldığında) ---------- */

function ensureHistory(string $gameId, string $country): void {
    syncInit();
    if (sqOne('SELECT 1 FROM history_fetched WHERE game_id = ? AND country = ?', [$gameId, $country])) return;
    $since = gmdate('Y-m-d\TH:i:s', time() - (HISTORY_MONTHS * 31 + 5) * 86400) . '+00:00';
    $events = itadHistory($gameId, $country, $since);
    tx(function () use ($events, $gameId, $country) {
        foreach ($events ?? [] as $e) {
            if (empty($e['deal']['price'])) continue;
            sqRun('INSERT INTO price_history (game_id, country, day, lowest, currency) VALUES (?,?,?,?,?)
              ON CONFLICT(game_id, country, day) DO UPDATE SET lowest = MIN(lowest, excluded.lowest)',
                [$gameId, $country, substr((string) $e['timestamp'], 0, 10), $e['deal']['price']['amount'], $e['deal']['price']['currency']]);
        }
        sqRun('INSERT OR IGNORE INTO history_fetched (game_id, country) VALUES (?, ?)', [$gameId, $country]);
    });
}

/* ---------- 5) Arama: listede olmayan oyunları ekle ---------- */

function trLower(string $s): string { return mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $s)); }

/** Eklenen yeni oyun sayısı. Aynı arama bir saat içinde tekrarlanmaz (önbellek tablosunda). */
function searchRemote(string $q, string $country): int {
    $key = 'search:' . trLower($q);
    if (cache_get($key, 3600) !== null) return 0;
    cache_set($key, 1);
    $results = itadSearch($q, 20);
    $fresh = [];
    tx(function () use ($results, &$fresh) {
        foreach ($results ?? [] as $g) {
            $type = $g['type'] ?? null;
            if ($type && $type !== 'game') continue;   // DLC, soundtrack vb. aramada da eklenmez
            if (insGame($g['id'], $g['slug'] ?? null, $g['title'], $type)) {
                $fresh[] = $g['id'];
                if (!empty($g['assets']['boxart'])) sqRun('UPDATE games SET boxart = ? WHERE id = ? AND boxart IS NULL', [$g['assets']['boxart'], $g['id']]);
                if (!empty($g['mature'])) sqRun('UPDATE games SET mature = 1 WHERE id = ?', [$g['id']]);
            }
        }
    });
    if ($fresh) syncPricesFor($fresh, [$country]);   // bilgi/kapak arka plan turunda (enrichInBackground) tamamlanır
    return count($fresh);
}

/* ---------- 6) Çok satanlar, en çok favorilenenler, kategoriler, puanlar ---------- */

/** IsThereAnyDeal: en çok istek listesine eklenenler (favori) ve en çok sahip olunanlar (çok satan) — günde bir */
function syncRankings(): void {
    if (!olderThan('rankings_at', 24)) return;
    $ok = 0;
    foreach ([['itadMostWaitlisted', 'waitlist_rank'], ['itadMostCollected', 'collected_rank']] as [$fn, $col]) {
        $all = [];
        for ($off = 0; $off < 500; $off += 100) {
            try { $page = $fn($off, 100); }
            catch (BudgetExhausted $e) { throw $e; }
            catch (Throwable $e) { log_line("$col alınamadı: " . errText($e)); break; }
            if (!$page) break;
            array_push($all, ...$page);
            if (count($page) < 100) break;
        }
        if (!$all) continue;
        $ok++;
        tx(function () use ($all, $col) {
            db()->exec("UPDATE games SET $col = NULL");
            foreach ($all as $x) { insGame($x['id'], $x['slug'] ?? null, $x['title'], null); sqRun("UPDATE games SET $col = ? WHERE id = ?", [$x['position'], $x['id']]); }
        });
    }
    if (!$ok) return;   // hiçbiri gelmediyse bir sonraki turda tekrar dene
    meta_set('rankings_at', nowIso());
    log_line('sıralamalar güncellendi (en çok favorilenen, çok satan)');
}

/**
 * SteamSpy: kategoriler + değerlendirme sayıları + tahmini satışlar — günde bir.
 * Etiket etiket ilerler; ilerleme meta('categories_progress') içinde, bütçe bitince kaldığı yerden devam eder.
 * (Her etiket isteği uzun sürebilir: bütçesi kısa olan turlarda hiç ilerleyemeyebilir, uzun CLI turlarında tamamlanır.)
 */
function syncCategories(): void {
    if (!olderThan('categories_at', 24)) return;
    $prog = json_decode((string) (meta_get('categories_progress') ?? ''), true);
    if (!is_array($prog)) $prog = ['done' => [], 'tagged' => 0, 'failed' => 0, 'tries' => []];
    $save = fn() => meta_set('categories_progress', jenc($prog));
    foreach (FETCH_TAGS as $tag) {
        if (in_array($tag, $prog['done'], true)) continue;
        if (!budget_ok(12)) { $save(); return; }
        try { $apps = spyFetchTag($tag); }
        catch (BudgetExhausted $e) { $save(); return; }
        catch (Throwable $e) {
            $prog['tries'][$tag] = ($prog['tries'][$tag] ?? 0) + 1;
            if ($prog['tries'][$tag] < 3) { $save(); return; }   // zaman aşımı/geçici hata: sonraki turda yeniden dene
            $prog['failed']++; $prog['done'][] = $tag;
            log_line("kategori \"$tag\" alınamadı: " . errText($e));
            continue;
        }
        tx(function () use ($apps, $tag, &$prog) {
            foreach ($apps as $a) {
                $g = sqOne('SELECT id FROM games WHERE appid = ?', [$a['appid']]);
                if (!$g) continue;
                sqRun('INSERT OR IGNORE INTO game_tags (game_id, tag) VALUES (?, ?)', [$g['id'], $tag]);
                $pm = (int) round((float) ($a['median_forever'] ?? 0) ?: (float) ($a['average_forever'] ?? 0));
                sqRun('UPDATE games SET owners = ?, play_min = CASE WHEN ? > 0 THEN ? ELSE play_min END,
                    rating_pos = CASE WHEN rating_at IS NULL THEN ? ELSE rating_pos END,
                    rating_neg = CASE WHEN rating_at IS NULL THEN ? ELSE rating_neg END WHERE id = ?',
                    [parseOwners($a['owners'] ?? null), $pm, $pm, $a['positive'] ?? null, $a['negative'] ?? null, $g['id']]);
                $prog['tagged']++;
            }
        });
        $prog['done'][] = $tag;
        $save();
    }
    if ($prog['failed'] < count(FETCH_TAGS) && $prog['tagged'] > 0) meta_set('categories_at', nowIso());
    db()->exec("DELETE FROM meta WHERE key = 'categories_progress'");
    log_line("kategoriler güncellendi ({$prog['tagged']} eşleşme)");
}

/** Steam değerlendirmeleri: oyun sayfası açılınca (7 günde bir tazelenir) */
function ensureRating(string $id): void {
    try { ensureAppids([$id]); } catch (Throwable $e) { /* Steam numarası yoksa puan da yok */ }
    $g = sqOne('SELECT appid, rating_at FROM games WHERE id = ?', [$id]);
    if (!$g || !$g['appid'] || ($g['rating_at'] && time() - sqliteTime($g['rating_at']) < 7 * 86400)) return;
    $r = steamReviews($g['appid']);
    if ($r) sqRun("UPDATE games SET rating_pos = ?, rating_neg = ?, rating_at = datetime('now') WHERE id = ?", [$r['pos'], $r['neg'], $id]);
}

/** Sık bakılan oyunların puanları arka planda (saniyede birden az istek) */
function ratingsInBackground(): void {
    $rows = sqAll("SELECT id FROM games WHERE hidden = 0 AND appid IS NOT NULL
      AND (rating_at IS NULL OR rating_at < datetime('now', '-7 days'))
      AND (popular_rank IS NOT NULL OR waitlist_rank IS NOT NULL OR collected_rank IS NOT NULL OR viewed_at >= datetime('now', '-7 days'))
      LIMIT 1500");
    $n = 0;
    foreach ($rows as $r) {
        if (!budget_ok(5)) break;
        try { ensureRating($r['id']); } catch (Throwable $e) { /* sıradaki */ }
        $n++;
        usleep(1_000_000);
    }
    if ($n) log_line("puanlar güncellendi ($n oyun)");
}

/* ---------- Steam numarası: puan, kapak, fragman ve kategoriler için gerekli ---------- */

function ensureAppids(array $ids): void {
    $need = [];
    foreach ($ids as $id) {
        $g = sqOne('SELECT appid, appid_checked FROM games WHERE id = ?', [$id]);
        if ($g && !$g['appid'] && !$g['appid_checked']) $need[] = $id;
    }
    foreach (chunks($need, 200) as $part) {
        $res = itadSteamIdsOf($part);
        tx(function () use ($part, $res) {
            foreach ($part as $id) {
                $app = null;
                foreach ($res[$id] ?? [] as $x) if (preg_match('~^app/\d+$~', (string) $x)) { $app = $x; break; }
                sqRun('UPDATE games SET appid = COALESCE(appid, ?), appid_checked = 1 WHERE id = ?', [$app ? (int) substr($app, 4) : null, $id]);
            }
        });
    }
}
function fillHotAppids(): void {
    $ids = array_column(sqAll("SELECT id FROM games WHERE hidden = 0 AND appid IS NULL AND appid_checked = 0 AND (
        popular_rank IS NOT NULL OR waitlist_rank IS NOT NULL OR collected_rank IS NOT NULL
        OR viewed_at IS NOT NULL OR id IN (SELECT game_id FROM wishlist))"), 'id');
    if (!$ids) return;
    try { ensureAppids($ids); log_line('Steam numaraları eşlendi (' . count($ids) . ' oyun)'); }
    catch (BudgetExhausted $e) { throw $e; }
    catch (Throwable $e) { log_line('Steam numaraları alınamadı: ' . $e->getMessage()); }
}

/* ---------- 7) Öncelikli fiyat: listede fiyatı hiç çekilmemiş oyun görününce sıraya al ---------- */

function queuePriority(array $ids): void {
    if (!$ids) return;
    tx(function () use ($ids) { foreach ($ids as $id) sqRun('INSERT OR IGNORE INTO queue (game_id, added_at) VALUES (?, ?)', [$id, time()]); });
}
/** Kuyruğu işler (web isteğinden sonra ya da arka plan turunda). Bütçe yoksa kısa bir bütçe başlatır. */
function processPriority(): void {
    ensureBudget(1800, 15);
    while (budget_ok(4)) {
        $ids = array_column(sqAll('SELECT game_id FROM queue ORDER BY added_at LIMIT 200'), 'game_id');
        if (!$ids) break;
        tx(function () use ($ids) { foreach ($ids as $id) sqRun('DELETE FROM queue WHERE game_id = ?', [$id]); });
        try { syncPricesFor($ids, COUNTRIES, true); }
        catch (BudgetExhausted $e) { queuePriority($ids); break; }   // payı doldu: sıraya geri koy
        catch (Throwable $e) { log_line('öncelikli fiyat: ' . $e->getMessage()); break; }
    }
}

/* ---------- 8) Steam ayrıntıları: fragman, ekran görüntüleri, sistem gereksinimleri ---------- */

/** Steam ayrıntıları, 7 günde bir tazelenir. Satır (video, poster, shots, req_min, req_rec, ...) ya da null döner. */
function ensureDetails(string $id, $appid = null): ?array {
    if (!$appid) {
        try { ensureAppids([$id]); } catch (Throwable $e) { /* numara yok */ }
        $appid = qv('SELECT appid FROM games WHERE id = ?', [$id]);
        if (!$appid) return null;
    }
    $sel = 'SELECT video, poster, shots, req_min, req_rec, details_v, checked_at FROM media WHERE game_id = ?';
    $m = sqOne($sel, [$id]);
    $hasReq = $m && (strlen((string) $m['req_min']) > 2 || strlen((string) $m['req_rec']) > 2);
    $maxAge = $hasReq ? 7 * 86400 : 86400;   // gereksinim bulunamadıysa ertesi gün tekrar dene
    if ($m && (int) $m['details_v'] >= 3 && time() - sqliteTime($m['checked_at']) < $maxAge) return $m;
    try { $d = steamDetails($appid); }
    catch (Throwable $e) { log_line("Steam ayrıntıları alınamadı ($appid): " . errText($e)); throw $e; }
    sqRun("INSERT OR REPLACE INTO media (game_id, video, poster, shots, req_min, req_rec, details_v, checked_at)
      VALUES (?, ?, ?, ?, ?, ?, 3, datetime('now'))",
        [$id, $d['video'], $d['poster'], jenc($d['shots'] ?? []), jenc($d['reqMin']), jenc($d['reqRec'])]);
    return sqOne($sel, [$id]);
}

/** Aday oyunlar arasından fragmanı olan ilkini (yoksa görseli olanı) döndürür */
function spotlightMedia(array $candidates): ?array {
    $withPoster = null;
    foreach ($candidates as $c) {
        try { $m = ensureDetails($c['id'], $c['appid'] ?? null); } catch (Throwable $e) { continue; }
        if (!empty($m['video'])) return ['id' => $c['id'], 'video' => $m['video'], 'poster' => $m['poster']];
        if (!$withPoster && !empty($m['poster'])) $withPoster = ['id' => $c['id'], 'video' => null, 'poster' => $m['poster']];
    }
    return $withPoster;   // hiçbirinin fragmanı yoksa en azından görseliyle göster
}

/* ---------- Ana döngü ---------- */

/**
 * Bir senkronizasyon turu: tohumlar, katalog, sıralamalar, Steam numaraları, sık bakılan oyunların fiyatları, fiyat düşüşü bildirimleri.
 * Bütçelidir ve kaldığı yerden devam eder (meta: sync_state = JSON {cursor}). Önceki tur SYNC_MINUTES'ten yeni bittiyse bir şey yapmaz.
 * @return string 'skipped' (vakti gelmedi) | 'partial' (bütçe bitti, devam edecek) | 'done'
 */
function runSync(bool $force = false): string {
    if (!env('ITAD_API_KEY')) throw new RuntimeException('API anahtarı ayarlanmamış. .env dosyasında ITAD_API_KEY değerini doldur.');
    ensureBudget();
    syncInit();
    $state = json_decode((string) (meta_get('sync_state') ?? ''), true);
    if (!is_array($state)) {
        $last = meta_get('last_sync');
        if (!$force && $last && time() - (int) strtotime($last) < syncMinutes() * 60) return 'skipped';
        $t0 = time();
        log_line('Senkronizasyon başladı…');
        // yeni etiketler eklendiğinde kategorileri bir kez yeniden çek
        if (!meta_get('cats_v4')) { db()->exec("DELETE FROM meta WHERE key = 'categories_at'"); meta_set('cats_v4', '1'); }   // v4: oyun süreleri (play_min) bir kez doldurulsun
        try {
            ensureSeeds();
            syncCatalog();
            syncRankings();
            fillHotAppids();
        } catch (BudgetExhausted $e) { log_line('senkronizasyon bütçe/limit nedeniyle yarıda kaldı: ' . $e->getMessage()); return 'partial'; }
        $state = ['cursor' => '', 'started' => $t0];
        meta_set('sync_state', jenc($state));
    }
    $cursor = (string) ($state['cursor'] ?? '');
    $finished = syncHotPrices($cursor);
    if (!$finished) { $state['cursor'] = $cursor; meta_set('sync_state', jenc($state)); return 'partial'; }
    db()->exec("DELETE FROM meta WHERE key = 'sync_state'");
    meta_set('last_sync', nowIso());
    if (function_exists('checkPriceDrops')) checkPriceDrops();
    log_line('Fiyatlar hazır (' . (time() - (int) ($state['started'] ?? time())) . ' sn)');
    return 'done';
}

/**
 * Her senkronizasyondan sonra (ya da bağımsız cron turunda) çağrılır. İşler sırayla, her birine kalan sürenin payı verilerek çalışır;
 * hangi işin önce geleceği her turda döner (meta: bg_rot), böylece hiçbiri aç kalmaz.
 */
function startBackgroundWork(): void {
    ensureBudget();
    $steps = [
        'öncelik' => fn() => processPriority(),
        'bilgi' => fn() => enrichInBackground(),
        'kategori' => fn() => syncCategories(),
        'puan' => fn() => ratingsInBackground(),
        'fiyat' => fn() => tailPricesInBackground(),
        'steam-liste' => fn() => importSteamList(),
        'steam-eşleme' => fn() => mapSteamInBackground(),
    ];
    $names = array_keys($steps);
    $n = count($names);
    $rot = (int) (meta_get('bg_rot') ?? 0);
    for ($i = 0; $i < $n; $i++) {
        $left = budget_left();
        if ($left < 3) break;
        $name = $names[($rot + $i) % $n];
        // her adım, kalanın en az üçte birini alır (erken biterse sonrakilere kalır)
        try { withSlice($left / max(1, min($n - $i, 3)), $steps[$name]); }
        catch (BudgetExhausted $e) { /* sonra devam */ }
        catch (Throwable $e) { log_line("$name: " . $e->getMessage()); }
    }
    meta_set('bg_rot', (string) (($rot + 1) % $n));
}
