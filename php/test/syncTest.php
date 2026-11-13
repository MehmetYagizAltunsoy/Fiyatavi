<?php
declare(strict_types=1);
/* Senkronizasyon modülleri: ağ yok, tüm dış istekler $GLOBALS['__http_stub'] ile taklit edilir. */
require __DIR__ . '/helpers.php';
ini_set('error_log', '/dev/null');   // log_line çıktısı testleri kirletmesin
$_ENV['ITAD_API_KEY'] = 'test-key';
$_ENV['ITAD_RATE_LIMIT'] = '1000';
$_ENV['SHOPS_HIDE'] = '';
unset($_ENV['STEAM_API_KEY']);

$calls = [];
/** Sahte ITAD/Steam sunucusu: rota → cevap (fn ya da dizi) */
function stub(array $routes): void {
    $GLOBALS['calls'] = [];
    $GLOBALS['__http_stub'] = function ($method, $url, $headers, $body) use ($routes) {
        $GLOBALS['calls'][] = [$method, $url, $body];
        foreach ($routes as $needle => $resp) {
            if (str_contains($url, $needle)) {
                $r = is_callable($resp) ? $resp($url, $body) : $resp;
                if (isset($r['status'])) return $r;
                return ['status' => 200, 'body' => json_encode($r)];
            }
        }
        return ['status' => 404, 'body' => 'yok: ' . $url];
    };
}
function reset_itad_counter(): void { meta_set('itad_sent', '[]'); }
function game(string $id, string $title, array $extra = []): void {
    sqRun('INSERT OR IGNORE INTO games (id, slug, title) VALUES (?, ?, ?)', [$id, $id, $title]);
    foreach ($extra as $k => $v) sqRun("UPDATE games SET $k = ? WHERE id = ?", [$v, $id]);
}
function deal(string $shopId, string $shop, float $price, float $regular, string $cur = 'TRY', int $cut = 0, ?string $url = null): array {
    return ['shop' => ['id' => $shopId, 'name' => $shop], 'price' => ['amount' => $price, 'currency' => $cur],
            'regular' => ['amount' => $regular, 'currency' => $cur], 'cut' => $cut, 'expiry' => null, 'url' => $url];
}

echo "Saf mantık\n";
t('parseOwners: aralığın ortası, tek sayı ve boş metin', function () {
    eq(parseOwners('1,000,000 .. 2,000,000'), 1500000);
    eq(parseOwners('20,000 .. 50,000'), 35000);
    eq(parseOwners('500'), 500);
    eq(parseOwners(''), 0);
});
t('kategori listesi 16 etiket, her biri ad/tr/en içerir', function () {
    eq(count(CATEGORIES), 16);
    eq(count(FETCH_TAGS), 16);
    foreach (CATEGORIES as $c) eq(count($c), 3);
    ok(in_array('FPS', FETCH_TAGS, true));
});
t('Steam gereksinim HTML\'i satırlara ayrılır', function () {
    $r = steamParseReq('<strong>Minimum:</strong><br><ul class="bb_ul"><li><strong>OS:</strong> Windows&nbsp;10 64-bit</li><li><strong>Memory:</strong> 8 GB RAM</li><li>Sadece not &amp; ek</li></ul>');
    eq($r, [['k' => 'OS', 'v' => 'Windows 10 64-bit'], ['k' => 'Memory', 'v' => '8 GB RAM'], ['k' => '', 'v' => 'Sadece not & ek']]);
    eq(steamParseReq(null), []);
    eq(steamParseReq('<strong>Önerilen:</strong>'), []);
    eq(count(steamParseReq(implode('<br>', array_map(fn($i) => "K$i: v", range(1, 30))))), 14, '14 satırla sınırlı');
});
t('Steam ayrıntıları: HLS fragman, ekran görüntüleri, https, gereksinimler', function () {
    stub(['appdetails' => ['730' => ['data' => [
        'movies' => [['highlight' => false, 'mp4' => ['480' => 'http://v/x.mp4']], ['highlight' => true, 'hls_h264' => 'http://v/hls.m3u8']],
        'screenshots' => [['path_full' => 'https://s/1.jpg'], ['path_full' => 'https://s/2.jpg'], ['x' => 1]],
        'header_image' => 'https://s/h.jpg',
        'pc_requirements' => ['minimum' => '<ul><li>OS: Win10</li></ul>', 'recommended' => '<ul><li>OS: Win11</li></ul>'],
    ]]]]);
    $d = steamDetails(730);
    eq($d['video'], 'https://v/hls.m3u8');
    eq($d['shots'], ['https://s/1.jpg', 'https://s/2.jpg']);
    eq($d['poster'], 'https://s/1.jpg');
    eq($d['reqMin'], [['k' => 'OS', 'v' => 'Win10']]);
    eq($d['reqRec'], [['k' => 'OS', 'v' => 'Win11']]);
});
t('Steam ayrıntıları: bölgede yoksa ABD mağazasından denenir; hiç yoksa boş', function () {
    stub(['appdetails' => function ($u) { return str_contains($u, 'cc=us') ? ['5' => ['data' => ['header_image' => 'https://s/h.jpg', 'pc_requirements' => []]]] : ['5' => ['success' => false]]; }]);
    $d = steamDetails(5);
    eq($d['poster'], 'https://s/h.jpg'); eq($d['reqMin'], []);
    eq(count($GLOBALS['calls']), 2);
    stub(['appdetails' => ['9' => ['success' => false]]]);
    ok(steamDetails(9)['empty'] === true);
});
t('Steam değerlendirmeleri', function () {
    stub(['appreviews' => ['query_summary' => ['total_positive' => 90, 'total_negative' => 10]]]);
    eq(steamReviews(1), ['pos' => 90, 'neg' => 10]);
    stub(['appreviews' => ['success' => 1]]);
    eq(steamReviews(1), null);
});
t('Steam listesi sayfalanır ve imleç verir', function () {
    $page = 0;
    stub(['GetAppList' => function ($u) use (&$page) {
        $page++;
        return ['response' => $page === 1 ? ['apps' => [['appid' => 1, 'name' => 'A']], 'have_more_results' => true, 'last_appid' => 1]
                                          : ['apps' => [['appid' => 2, 'name' => 'B']]]];
    }]);
    $got = [];
    foreach (steamGamePages('k') as $next => $apps) $got[] = [$next, count($apps)];
    eq($got, [[1, 1], [0, 1]]);
    ok(str_contains($GLOBALS['calls'][1][1], 'last_appid=1'));
});

echo "Kurlar\n";
t('kur: Frankfurter\'dan alınır, kaydedilir, usdRate çevirir', function () {
    stub(['frankfurter' => ['rates' => ['EUR' => 0.9, 'TRY' => 40.0, 'GBP' => 0.8]]]);
    refreshRates();
    $c = currentRates();
    eq($c['perUsd']['TRY'], 40.0);
    ok(abs(usdRate('TRY') - 0.025) < 1e-9);
    ok(abs(usdRate('EUR') - 1 / 0.9) < 1e-9);
    eq(usdRate('XXX'), 1.0, 'bilinmeyen para birimi 1');
    ok($c['updatedAt'] !== null);
    ok(str_contains((string) meta_get('rates'), '"TRY":40'));
    $n = count($GLOBALS['calls']);
    refreshRates();
    eq(count($GLOBALS['calls']), $n, '20 saat içinde tekrar çekilmez');
});
t('kur: ilk kaynak çökerse ikinci kaynak denenir', function () {
    $GLOBALS['__rates'] = null; unset($GLOBALS['__rates']);
    db()->exec("DELETE FROM meta WHERE key = 'rates'");
    stub(['frankfurter' => ['status' => 500, 'body' => 'x'], 'er-api' => ['rates' => ['TRY' => 42.0, 'EUR' => 0.95]]]);
    refreshRates();
    eq(currentRates()['perUsd']['TRY'], 42.0);
});
t('kur: ikisi de çökerse sabit kurlarla devam', function () {
    unset($GLOBALS['__rates']);
    db()->exec("DELETE FROM meta WHERE key = 'rates'");
    stub(['frankfurter' => ['status' => 500, 'body' => 'x'], 'er-api' => ['status' => 500, 'body' => 'x']]);
    refreshRates();
    ok(abs(usdRate('TRY') - 0.024) < 1e-9);
});
t('kur: veri tabanındaki son kurlar yüklenir', function () {
    unset($GLOBALS['__rates']);
    meta_set('rates', json_encode(['rates' => ['TRY' => 50.0], 'at' => gmdate('Y-m-d\TH:i:s') . '.000Z']));
    ok(abs(usdRate('TRY') - 0.02) < 1e-9);
    unset($GLOBALS['__rates']); db()->exec("DELETE FROM meta WHERE key = 'rates'");
});

echo "ITAD istemcisi\n";
t('ITAD: anahtar sorguda, gövde POST, 401 anlaşılır hata verir', function () {
    reset_itad_counter();
    stub(['games/prices' => []]);
    itadPrices(['a', 'b'], 'TR');
    [$m, $u, $b] = $GLOBALS['calls'][0];
    eq($m, 'POST'); ok(str_contains($u, 'key=test-key') && str_contains($u, 'country=TR')); eq($b, '["a","b"]');
    stub(['games/info' => ['status' => 401, 'body' => 'no']]);
    $msg = ''; try { itadInfo('x'); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, 'API anahtarı kabul edilmedi (kod 401)'));
    stub(['games/info' => ['status' => 404, 'body' => 'yok']]);
    $msg = ''; try { itadInfo('x'); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
    ok(str_contains($msg, '/games/info/v2 → 404'));
});
t('ITAD: null parametre sorguya girmez; arama ve lookup adresleri', function () {
    stub(['games/lookup' => ['found' => false]]);
    itadLookup(620);
    ok(str_contains($GLOBALS['calls'][0][1], 'appid=620') && !str_contains($GLOBALS['calls'][0][1], 'title='));
    stub(['games/search' => []]);
    itadSearch('half life', 5);
    ok(str_contains($GLOBALS['calls'][0][1], 'title=half%20life') && str_contains($GLOBALS['calls'][0][1], 'results=5'));
    stub(['lookup/id/shop/61' => []]);
    itadLookupSteam([620, 10]);
    eq($GLOBALS['calls'][0][2], '["app/620","app/10"]');
});
t('ITAD: sayaç ve sınır; limit dolunca bütçe yetmiyorsa BudgetExhausted', function () {
    $_ENV['ITAD_RATE_LIMIT'] = '2';   // 2*0.9 = 1 istek
    eq(itadRateLimit(), 1);
    reset_itad_counter();
    stub(['games/info' => ['title' => 'x']]);
    itadInfo('a');
    eq(itadUsage(), 1);
    budget_start(1.0);
    $hit = false;
    try { itadInfo('b'); } catch (BudgetExhausted $e) { $hit = true; }
    ok($hit, 'ikinci istek BudgetExhausted fırlatmalı');
    eq(count($GLOBALS['calls']), 1, 'ağa ikinci istek gitmemeli');
    unset($GLOBALS['__budget_end']);
    $_ENV['ITAD_RATE_LIMIT'] = '1000';
    reset_itad_counter();
    ok(bgThrottled() === false);
    $_ENV['ITAD_RATE_LIMIT'] = '10';   // limit 9, pay 5.4
    meta_set('itad_sent', json_encode(array_fill(0, 6, (int) (microtime(true) * 1000))));
    ok(bgThrottled() === true, 'arka plan payı dolunca true');
    $_ENV['ITAD_RATE_LIMIT'] = '1000'; reset_itad_counter();
});
t('ITAD: 5xx tekrar denenir (bütçe yoksa kısa bekleme), 429 bütçeye sığmazsa bırakılır', function () {
    reset_itad_counter();
    budget_start(1.0);
    stub(['games/info' => ['status' => 429, 'body' => 'slow']]);
    $hit = false;
    try { itadInfo('a'); } catch (BudgetExhausted $e) { $hit = true; }
    ok($hit, '60 sn bekleme 1 sn bütçeye sığmaz');
    unset($GLOBALS['__budget_end']);
});

echo "Fiyat senkronizasyonu\n";
t('fiyatlar yazılır: mağaza başına en ucuz teklif, en ucuz mağaza lows\'a, geçmiş satırı', function () {
    reset_itad_counter();
    game('g1', 'Oyun Bir');
    stub(['games/prices' => [[
        'id' => 'g1',
        'historyLow' => ['m3' => ['amount' => 90, 'currency' => 'TRY'], 'all' => ['amount' => 50, 'currency' => 'USD']],
        'deals' => [
            deal('61', 'Steam', 120.0, 240.0, 'TRY', 50, 'https://itad.link/a'),
            deal('61', 'Steam', 110.0, 240.0, 'TRY', 54, 'https://itad.link/b'),   // aynı mağazada daha ucuzu kazanır
            deal('16', 'Epic', 3.0, 20.0, 'USD', 85),                              // 3 USD ≈ 3/0.024.. TRY'den pahalı sayılmaz? (kura göre)
            deal('37', 'GOG', 100.0, 200.0, 'TRY', 50, 'https://itad.link/g'),
        ],
    ]]]);
    syncPricesFor(['g1']);
    $p = q('SELECT shop_id, shop_name, price FROM prices WHERE game_id = ? ORDER BY shop_id', ['g1']);
    eq(array_column($p, 'shop_name'), ['Epic', 'GOG', 'Steam']);
    eq((float) q1('SELECT price FROM prices WHERE shop_id = ?', ['61'])['price'], 110.0);
    // 3 USD ≈ 125 TRY (1/0.024) → GOG 100 TL en ucuz
    $low = q1('SELECT * FROM lows WHERE game_id = ?', ['g1']);
    eq($low['shop'], 'GOG'); eq((float) $low['price'], 100.0);
    eq($low['low_m3'] === null ? null : (float) $low['low_m3'], 90.0);
    eq($low['low_all'], null, 'tüm zamanların en düşüğü farklı para biriminde: yazılmaz');
    eq((float) q1('SELECT lowest FROM price_history WHERE game_id = ?', ['g1'])['lowest'], 100.0);
    ok(qv('SELECT last_priced FROM games WHERE id = ?', ['g1']) !== null);
    // mağaza örnek bağlantıları kaydedildi
    $sm = q1('SELECT samples FROM shop_health WHERE shop_id = ?', ['61']);
    eq(json_decode($sm['samples'], true), ['https://itad.link/a', 'https://itad.link/b']);
});
t('bugünkü geçmiş satırı gün içinde en düşüğü korur', function () {
    stub(['games/prices' => [['id' => 'g1', 'deals' => [deal('37', 'GOG', 150.0, 200.0)]]]]);
    syncPricesFor(['g1']);
    eq((float) q1("SELECT lowest FROM price_history WHERE game_id = 'g1'")['lowest'], 100.0);
    eq((float) q1("SELECT price FROM lows WHERE game_id = 'g1'")['price'], 150.0, 'lows güncel fiyatı gösterir');
});
t('fiyatı kalmayan oyunun lows kaydı silinir', function () {
    stub(['games/prices' => [['id' => 'g1', 'deals' => []]]]);
    syncPricesFor(['g1']);
    eq(qv("SELECT COUNT(*) FROM lows WHERE game_id = 'g1'"), 0);
    eq(qv("SELECT COUNT(*) FROM prices WHERE game_id = 'g1'"), 0);
});
t('200\'lük parçalar halinde istek atılır', function () {
    $ids = [];
    for ($i = 0; $i < 450; $i++) { $ids[] = "bulk$i"; }
    stub(['games/prices' => []]);
    syncPricesFor($ids);
    eq(count($GLOBALS['calls']), 3);
});
t('arka plan payı doluysa syncPricesFor(background) BudgetExhausted verir', function () {
    $_ENV['ITAD_RATE_LIMIT'] = '10';
    meta_set('itad_sent', json_encode(array_fill(0, 7, (int) (microtime(true) * 1000))));
    stub(['games/prices' => []]);
    $hit = false;
    try { syncPricesFor(['x'], COUNTRIES, true); } catch (BudgetExhausted $e) { $hit = true; }
    ok($hit); eq(count($GLOBALS['calls']), 0);
    $_ENV['ITAD_RATE_LIMIT'] = '1000'; reset_itad_counter();
});

echo "Mağaza engelleme\n";
t('probe: yönlendirme izlenir, 451/5xx = kapalı, 403 = açık, ağ hatası = kapalı, ITAD ulaşılamıyorsa belirsiz', function () {
    $GLOBALS['__http_stub'] = function ($m, $url) {
        return match (true) {
            str_contains($url, 'redir') => ['status' => 302, 'body' => '', 'headers' => ['location' => '/son']],
            str_contains($url, '/son') => ['status' => 200, 'body' => ''],
            str_contains($url, 'engel') => ['status' => 451, 'body' => ''],
            str_contains($url, 'bozuk') => ['status' => 503, 'body' => ''],
            str_contains($url, 'bot') => ['status' => 403, 'body' => ''],
            str_contains($url, 'itad.link/ag') => throw new RuntimeException('x', 6),
            default => throw new RuntimeException('x', 28),
        };
    };
    eq(shopProbe('https://magaza.com/redir')['verdict'], 'up');
    eq(shopProbe('https://magaza.com/engel')['verdict'], 'down');
    eq(shopProbe('https://magaza.com/bozuk')['verdict'], 'down');
    eq(shopProbe('https://magaza.com/bot')['verdict'], 'up');
    $r = shopProbe('https://magaza.com/kayip'); eq($r['verdict'], 'down'); ok(str_contains($r['why'], 'TimeoutError'));
    eq(shopProbe('https://itad.link/ag')['verdict'], 'unknown');
    eq(shopResolveUrl('/a', 'https://x.com/b/c'), 'https://x.com/a');
    eq(shopResolveUrl('d', 'https://x.com/b/c'), 'https://x.com/b/d');
});
t('checkShops: açılmayan mağaza gizlenir, fiyatları silinir, en ucuz yeniden hesaplanır, sonra geri gelir', function () {
    reset_itad_counter();
    game('g2', 'Oyun İki');
    stub(['games/prices' => [['id' => 'g2', 'deals' => [
        deal('7', 'ÖlüMağaza', 50.0, 100.0, 'TRY', 50, 'https://olu.example/1'),
        deal('8', 'SağMağaza', 80.0, 100.0, 'TRY', 20, 'https://diri.example/1'),
    ]]]]);
    syncPricesFor(['g2']);
    eq(q1("SELECT shop FROM lows WHERE game_id = 'g2'")['shop'], 'ÖlüMağaza');
    $GLOBALS['__http_stub'] = fn($m, $u) => str_contains($u, 'olu.example') ? ['status' => 451, 'body' => ''] : ['status' => 200, 'body' => ''];
    checkShops(true);
    eq((int) qv("SELECT ok FROM shop_health WHERE shop_id = '7'"), 0);
    eq((int) qv("SELECT ok FROM shop_health WHERE shop_id = '8'"), 1);
    eq(qv("SELECT COUNT(*) FROM prices WHERE shop_id = '7'"), 0);
    $low = q1("SELECT shop, price FROM lows WHERE game_id = 'g2'");
    eq($low['shop'], 'SağMağaza'); eq((float) $low['price'], 80.0);
    // gizliyken yeni fiyat çekilince atlanır
    stub(['games/prices' => [['id' => 'g2', 'deals' => [deal('7', 'ÖlüMağaza', 40.0, 100.0, 'TRY', 60, 'https://olu.example/1'), deal('8', 'SağMağaza', 80.0, 100.0)]]]]);
    syncPricesFor(['g2']);
    eq(qv("SELECT COUNT(*) FROM prices WHERE shop_id = '7'"), 0);
    // mağaza tekrar açılırsa geri eklenir (örnek bağlantılar prices'ta olmadığı için saklı örneklerle denenir)
    $GLOBALS['__http_stub'] = fn($m, $u) => ['status' => 200, 'body' => ''];
    checkShops(true);
    eq((int) qv("SELECT ok FROM shop_health WHERE shop_id = '7'"), 1);
});
t('SHOPS_HIDE ile elle gizleme (büyük/küçük harf fark etmez)', function () {
    $_ENV['SHOPS_HIDE'] = ' sağmağaza , Başka ';
    $b = blockedShops();
    ok(isBlocked($b, '999', 'SAĞMAĞAZA'));
    ok(isBlocked($b, '999', 'başka'));
    ok(!isBlocked($b, '999', 'Steam'));
    purgeBlocked();
    eq(qv("SELECT COUNT(*) FROM prices WHERE shop_name = 'SağMağaza'"), 0);
    $_ENV['SHOPS_HIDE'] = '';
});

echo "Oyun bilgisi, geçmiş, arama, puan\n";
t('ensureInfo: etiketler, geliştirici, DLC ve yetişkin içerik gizlenir, bir kez çekilir', function () {
    reset_itad_counter();
    game('g3', 'Geçici Ad');
    stub(['games/info' => ['title' => 'Gerçek Ad', 'releaseDate' => '2020-01-01', 'type' => 'game', 'mature' => false,
        'developers' => [['name' => 'Stüdyo']], 'tags' => ['RPG', 'Free to Play'], 'assets' => ['boxart' => 'https://img/b.jpg']]]);
    ensureInfo('g3');
    $g = q1("SELECT * FROM games WHERE id = 'g3'");
    eq($g['title'], 'Gerçek Ad'); eq($g['hidden'], 0); eq($g['free'], 1); eq($g['boxart'], 'https://img/b.jpg');
    eq(json_decode($g['developers'], true), ['Stüdyo']);
    eq(qv("SELECT COUNT(*) FROM game_tags WHERE game_id = 'g3'"), 2);
    ensureInfo('g3');
    eq(count($GLOBALS['calls']), 1, 'ikinci çağrıda ağa gidilmez');
    game('g4', 'DLC');
    stub(['games/info' => ['title' => 'DLC', 'type' => 'dlc', 'tags' => []]]);
    ensureInfo('g4');
    eq(qv("SELECT hidden FROM games WHERE id = 'g4'"), 1);
    game('g5', 'Mature');
    stub(['games/info' => ['title' => 'M', 'type' => 'game', 'mature' => true]]);
    ensureInfo('g5');
    eq(qv("SELECT hidden FROM games WHERE id = 'g5'"), 1);
});
t('ensureHistory: günlük en düşük, bir kez çekilir', function () {
    reset_itad_counter();
    stub(['games/history' => [
        ['timestamp' => '2026-10-01T10:00:00+01:00', 'deal' => ['price' => ['amount' => 100, 'currency' => 'TRY']]],
        ['timestamp' => '2026-10-01T18:00:00+01:00', 'deal' => ['price' => ['amount' => 80, 'currency' => 'TRY']]],
        ['timestamp' => '2026-10-02T10:00:00+01:00', 'deal' => null],
    ]]);
    ensureHistory('g3', 'TR');
    eq(qv("SELECT COUNT(*) FROM price_history WHERE game_id = 'g3'"), 1);
    eq((float) qv("SELECT lowest FROM price_history WHERE game_id = 'g3'"), 80.0);
    ensureHistory('g3', 'TR');
    eq(count($GLOBALS['calls']), 1);
    ok(str_contains($GLOBALS['calls'][0][1], 'since='));
});
t('searchRemote: yeni oyunlar eklenir, DLC/yetişkin atlanır, aynı arama bir saat tekrarlanmaz', function () {
    reset_itad_counter();
    stub([
        'games/search' => [['id' => 'n1', 'slug' => 'yeni', 'title' => 'Yeni Oyun', 'type' => 'game', 'assets' => ['boxart' => 'https://img/n1.jpg']],
                           ['id' => 'n2', 'slug' => 'dlc', 'title' => 'DLC', 'type' => 'dlc'], ['id' => 'n3', 'title' => 'M', 'type' => 'game', 'mature' => true]],
        'games/prices' => [['id' => 'n1', 'deals' => [deal('61', 'Steam', 10.0, 20.0)]]],
    ]);
    eq(searchRemote('Yeni Oyun', 'TR'), 1);
    eq(qv("SELECT boxart FROM games WHERE id = 'n1'"), 'https://img/n1.jpg');
    eq(qv("SELECT COUNT(*) FROM games WHERE id IN ('n2','n3')"), 0);
    eq((float) qv("SELECT price FROM lows WHERE game_id = 'n1'"), 10.0);
    eq(searchRemote('YENİ OYUN', 'TR'), 0, 'büyük/küçük harf ve Türkçe İ farkı aynı aramadır');
    $before = count($GLOBALS['calls']);
    searchRemote('Yeni Oyun', 'TR');
    eq(count($GLOBALS['calls']), $before);
});
t('ensureAppids + ensureRating: Steam numarası eşlenir, puan yazılır, 7 gün tazelenmez', function () {
    reset_itad_counter();
    game('g6', 'Puanlı');
    stub(['lookup/shop/61/id' => ['g6' => ['sub/1', 'app/620']], 'appreviews' => ['query_summary' => ['total_positive' => 70, 'total_negative' => 30]]]);
    ensureRating('g6');
    $g = q1("SELECT appid, appid_checked, rating_pos, rating_neg, rating_at FROM games WHERE id = 'g6'");
    eq((int) $g['appid'], 620); eq((int) $g['rating_pos'], 70); ok($g['rating_at'] !== null);
    $n = count($GLOBALS['calls']);
    ensureRating('g6');
    eq(count($GLOBALS['calls']), $n);
    game('g7', 'Numarasız');
    stub(['lookup/shop/61/id' => ['g7' => []]]);
    ensureAppids(['g7']);
    eq((int) qv("SELECT appid_checked FROM games WHERE id = 'g7'"), 1);
    eq(qv("SELECT appid FROM games WHERE id = 'g7'"), null);
    ensureAppids(['g7']);
    eq(count($GLOBALS['calls']), 1, 'tekrar sorulmaz');
});
t('ensureDetails: medya yazılır, v3 + taze kayıt tekrar çekilmez', function () {
    reset_itad_counter();
    game('g8', 'Medya', ['appid' => 440]);
    stub(['appdetails' => ['440' => ['data' => ['screenshots' => [['path_full' => 'https://s/1.jpg']], 'pc_requirements' => ['minimum' => '<ul><li>OS: X</li></ul>']]]]]);
    $m = ensureDetails('g8', 440);
    eq(json_decode($m['shots'], true), ['https://s/1.jpg']);
    eq(json_decode($m['req_min'], true), [['k' => 'OS', 'v' => 'X']]);
    eq((int) $m['details_v'], 3);
    $n = count($GLOBALS['calls']);
    ensureDetails('g8', 440);
    eq(count($GLOBALS['calls']), $n);
    eq(ensureDetails('yok-oyun'), null);
    $s = spotlightMedia([['id' => 'g8', 'appid' => 440]]);
    eq($s['id'], 'g8'); eq($s['video'], null); eq($s['poster'], 'https://s/1.jpg');
});

echo "Kuyruk ve ana döngü\n";
t('öncelik kuyruğu: tekrar eklenmez, işlenince boşalır, fiyat yazılır', function () {
    reset_itad_counter();
    game('p1', 'Kuyruk Bir'); game('p2', 'Kuyruk İki');
    queuePriority(['p1', 'p2']); queuePriority(['p1']);
    eq(qv('SELECT COUNT(*) FROM queue'), 2);
    stub(['games/prices' => [['id' => 'p1', 'deals' => [deal('61', 'Steam', 5.0, 10.0)]], ['id' => 'p2', 'deals' => [deal('61', 'Steam', 6.0, 10.0)]]]]);
    budget_start(10);
    processPriority();
    unset($GLOBALS['__budget_end']);
    eq(qv('SELECT COUNT(*) FROM queue'), 0);
    eq((float) qv("SELECT price FROM lows WHERE game_id = 'p2'"), 6.0);
});
t('öncelik kuyruğu: API payı doluysa işler sıraya geri konur', function () {
    $_ENV['ITAD_RATE_LIMIT'] = '10';
    meta_set('itad_sent', json_encode(array_fill(0, 7, (int) (microtime(true) * 1000))));
    queuePriority(['p1']);
    stub([]);
    budget_start(10); processPriority(); unset($GLOBALS['__budget_end']);
    eq(qv('SELECT COUNT(*) FROM queue'), 1);
    $_ENV['ITAD_RATE_LIMIT'] = '1000'; reset_itad_counter();
    db()->exec('DELETE FROM queue');
});
t('runSync: katalog + sıralamalar + sık bakılan fiyatlar, last_sync, bitince durum temizlenir; hemen tekrar çağrılırsa atlanır', function () {
    reset_itad_counter();
    db()->exec("DELETE FROM meta WHERE key IN ('last_sync','sync_state','rankings_at')");
    $pop = [['id' => 'c1', 'slug' => 'c1', 'title' => 'Popüler Bir', 'position' => 1], ['id' => 'c2', 'slug' => 'c2', 'title' => 'Popüler İki', 'position' => 2]];
    stub([
        'stats/most-popular' => $pop,
        'stats/most-waitlisted' => [['id' => 'c2', 'title' => 'Popüler İki', 'position' => 1]],
        'stats/most-collected' => [['id' => 'c1', 'title' => 'Popüler Bir', 'position' => 1]],
        'lookup/shop/61/id' => [],
        'games/lookup' => ['found' => false],
        'games/prices' => fn($u, $b) => array_map(fn($id) => ['id' => $id, 'deals' => [deal('61', 'Steam', 10.0, 20.0, 'TRY', 50)]], json_decode($b, true)),
    ]);
    eq(runSync(), 'done');
    eq(qv("SELECT popular_rank FROM games WHERE id = 'c1'"), 1);
    eq(qv("SELECT waitlist_rank FROM games WHERE id = 'c2'"), 1);
    eq(qv("SELECT collected_rank FROM games WHERE id = 'c1'"), 1);
    eq((float) qv("SELECT price FROM lows WHERE game_id = 'c2'"), 10.0);
    ok(meta_get('last_sync') !== null); eq(meta_get('sync_state'), null);
    $n = count($GLOBALS['calls']);
    eq(runSync(), 'skipped');
    eq(count($GLOBALS['calls']), $n);
});
t('runSync: bütçe bitince imleci saklar, sonraki turda kaldığı yerden devam eder', function () {
    reset_itad_counter();
    db()->exec("DELETE FROM meta WHERE key IN ('last_sync','sync_state','rankings_at')");
    // 450 sık bakılan oyun → 3 parça
    tx(function () { for ($i = 0; $i < 450; $i++) { $id = sprintf('h%03d', $i); sqRun('INSERT OR IGNORE INTO games (id, slug, title, popular_rank) VALUES (?, ?, ?, ?)', [$id, $id, "Sıcak $i", 1000 + $i]); } });
    $calls = 0;
    stub([
        'stats/most-popular' => fn() => [],
        'stats/most-waitlisted' => [], 'stats/most-collected' => [], 'lookup/shop/61/id' => [], 'games/lookup' => ['found' => false],
        'games/prices' => function ($u, $b) use (&$calls) {
            $calls++;
            if ($calls === 2) { budget_start(0.0); }   // ikinci parçadan sonra bütçe biter
            return array_map(fn($id) => ['id' => $id, 'deals' => [deal('61', 'Steam', 10.0, 20.0)]], json_decode($b, true));
        },
    ]);
    budget_start(30);
    eq(runSync(true), 'partial');
    $cur = json_decode((string) meta_get('sync_state'), true)['cursor'];
    ok($cur !== '', 'imleç kaydedildi');
    ok(meta_get('last_sync') === null, 'tur bitmedi');
    $priced = (int) qv("SELECT COUNT(*) FROM games WHERE id LIKE 'h%' AND last_priced IS NOT NULL");
    ok($priced > 0 && $priced < 450, "kısmen fiyatlandı ($priced)");
    budget_start(30);
    eq(runSync(), 'done');
    eq((int) qv("SELECT COUNT(*) FROM games WHERE id LIKE 'h%' AND last_priced IS NOT NULL"), 450);
    unset($GLOBALS['__budget_end']);
});
t('syncCategories: etiketler oyunlara bağlanır, sahipler ve puanlar yazılır, ilerleme kaydedilir', function () {
    reset_itad_counter();
    game('s1', 'Spy Oyun', ['appid' => 4242]);
    db()->exec("DELETE FROM meta WHERE key IN ('categories_at','categories_progress')");
    stub(['steamspy.com' => ['4242' => ['appid' => 4242, 'owners' => '1,000 .. 3,000', 'positive' => 80, 'negative' => 20]]]);
    budget_start(300);
    syncCategories();
    unset($GLOBALS['__budget_end']);
    eq(qv("SELECT COUNT(*) FROM game_tags WHERE game_id = 's1'"), 16);
    $g = q1("SELECT owners, rating_pos, rating_neg FROM games WHERE id = 's1'");
    eq((int) $g['owners'], 2000); eq((int) $g['rating_pos'], 80);
    ok(meta_get('categories_at') !== null); eq(meta_get('categories_progress'), null);
});
t('importSteamList + mapSteamInBackground: liste alınır, ITAD ile eşlenip siteye eklenir', function () {
    $_ENV['STEAM_API_KEY'] = 'sk';
    reset_itad_counter();
    db()->exec("DELETE FROM meta WHERE key IN ('steam_import','steam_import_cursor')");
    stub([
        'GetAppList' => ['response' => ['apps' => [['appid' => 111, 'name' => 'Steam Bir'], ['appid' => 222, 'name' => 'Steam İki']]]],
        'lookup/id/shop/61' => ['app/111' => 'itad-111', 'app/222' => null],
    ]);
    budget_start(120);
    importSteamList();
    eq(qv('SELECT COUNT(*) FROM steam_apps'), 2);
    ok(meta_get('steam_import') !== null); eq(meta_get('steam_import_cursor'), null);
    mapSteamInBackground();
    unset($GLOBALS['__budget_end']);
    eq(qv("SELECT title FROM games WHERE id = 'itad-111'"), 'Steam Bir');
    eq((int) qv("SELECT appid FROM games WHERE id = 'itad-111'"), 111);
    eq(qv('SELECT COUNT(*) FROM steam_apps WHERE mapped = 0'), 0);
    $n = count($GLOBALS['calls']);
    budget_start(120); importSteamList(); unset($GLOBALS['__budget_end']);
    eq(count($GLOBALS['calls']), $n, 'günde bir kez');
    unset($_ENV['STEAM_API_KEY']);
});
t('startBackgroundWork: hata vermeden dönmeli, adımlar dönüşümlü başlar', function () {
    reset_itad_counter();
    stub(['games/info' => ['title' => 'x', 'type' => 'game'], 'games/prices' => [], 'lookup' => [], 'steamspy.com' => [], 'appreviews' => [], 'appdetails' => []]);
    budget_start(8);
    $r0 = meta_get('bg_rot');
    startBackgroundWork();
    unset($GLOBALS['__budget_end']);
    ok(meta_get('bg_rot') !== $r0);
});
