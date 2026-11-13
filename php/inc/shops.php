<?php
declare(strict_types=1);
/* Mağaza erişim kontrolü: bu bilgisayardan (sunucudan) açılmayan mağazalar siteden gizlenir.
   Kontrol sunucunun internet bağlantısıyla yapılır. */

const SHOP_CHECK_HOURS = 6;
const SHOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

function shopHealthInit(): void {
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS shop_health (
      shop_id TEXT PRIMARY KEY, shop_name TEXT, ok INTEGER DEFAULT 1, reason TEXT, samples TEXT, checked_at TEXT)");
    $done = true;
}

function hiddenByUser(): array {
    return array_values(array_filter(array_map(fn($s) => mb_strtolower(trim($s)), explode(',', (string) env('SHOPS_HIDE', '')))));
}

/** Fiyat çekerken görülen bağlantıları örnek olarak sakla (mağaza gizliyken de tekrar denenebilsin).
 *  $map: [shopId => ['name' => ..., 'urls' => [...]]] */
function rememberSamples(array $map): void {
    shopHealthInit();
    $st = db()->prepare("INSERT INTO shop_health (shop_id, shop_name, samples) VALUES (?, ?, ?)
      ON CONFLICT(shop_id) DO UPDATE SET shop_name = excluded.shop_name,
        samples = CASE WHEN shop_health.samples IS NULL OR shop_health.checked_at IS NULL THEN excluded.samples ELSE shop_health.samples END");
    tx(function () use ($map, $st) {
        foreach ($map as $id => $v) $st->execute([(string) $id, $v['name'], jenc(array_slice($v['urls'], 0, 3))]);
    });
}

/** Fiyat eklenirken atlanacak mağazalar: ['ids' => [id => true], 'names' => [küçük harf ad => true]] */
function blockedShops(): array {
    shopHealthInit();
    $ids = [];
    foreach (q('SELECT shop_id FROM shop_health WHERE ok = 0') as $r) $ids[(string) $r['shop_id']] = true;
    $names = [];
    foreach (hiddenByUser() as $n) $names[$n] = true;
    return ['ids' => $ids, 'names' => $names];
}
function isBlocked(array $b, $shopId, $shopName): bool {
    return isset($b['ids'][(string) $shopId]) || isset($b['names'][mb_strtolower((string) $shopName)]);
}

/* ---------- Bağlantı denemesi ---------- */

/** curCode → Node'daki hata kodlarına benzer kısa ad */
function shopErrCode(int $errno): string {
    return match ($errno) { 6 => 'ENOTFOUND', 7 => 'ECONNREFUSED', 28 => 'TimeoutError', 35, 51, 58, 59, 60 => 'CERT/TLS', 52, 56 => 'ECONNRESET', default => "curl$errno" };
}

/** Tek bir adresi dener, yönlendirmeyi takip ETMEZ. @return array{status:int, location:?string}; ağ hatasında RuntimeException (kod=curl hata no) */
function shopFetchOnce(string $url): array {
    if (isset($GLOBALS['__http_stub']) && is_callable($GLOBALS['__http_stub'])) {   // testler için
        $r = ($GLOBALS['__http_stub'])('GET', $url, [], null);
        return ['status' => (int) $r['status'], 'location' => $r['headers']['location'] ?? null];
    }
    $loc = null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 12,
        CURLOPT_NOBODY => false, CURLOPT_USERAGENT => SHOP_UA,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8'],
        CURLOPT_WRITEFUNCTION => fn($c, $d) => 0,   // gövdeyi indirme: başlıklar gelince yeter
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) {
            if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9));
            return strlen($h);
        },
    ]);
    $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
    if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    // gövde kasıtlı olarak atıldığı için "yazma hatası" (23) normal; başlık geldiyse sorun yok
    if ($errno && !($errno === 23 && $status > 0)) throw new RuntimeException(curl_strerror($errno), $errno);
    return ['status' => $status, 'location' => $loc];
}

function shopResolveUrl(string $loc, string $base): string {
    if (parse_url($loc, PHP_URL_SCHEME)) return $loc;
    $b = parse_url($base);
    $origin = ($b['scheme'] ?? 'https') . '://' . ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($loc, '//')) return ($b['scheme'] ?? 'https') . ':' . $loc;
    if (str_starts_with($loc, '/')) return $origin . $loc;
    $dir = preg_replace('~/[^/]*$~', '/', $b['path'] ?? '/');
    return $origin . $dir . $loc;
}

/** Tek bir bağlantıyı dene: yönlendirmeleri takip et, gerçekten açılıyor mu bak. @return array{verdict:string, why:string} */
function shopProbe(string $url): array {
    $u = $url;
    for ($hop = 0; $hop < 8; $hop++) {
        try { $r = shopFetchOnce($u); }
        catch (RuntimeException $e) {
            $host = (string) parse_url($u, PHP_URL_HOST);
            $why = $host . ': ' . shopErrCode((int) $e->getCode());
            // IsThereAnyDeal'ın kendi yönlendirme adresine ulaşılamıyorsa mağaza hakkında karar verme
            if (preg_match('~(^|\.)itad\.link$|isthereanydeal\.com$~', $host)) return ['verdict' => 'unknown', 'why' => $why];
            return ['verdict' => 'down', 'why' => $why];
        }
        if ($r['status'] >= 300 && $r['status'] < 400 && $r['location']) { $u = shopResolveUrl($r['location'], $u); continue; }
        $host = (string) parse_url($u, PHP_URL_HOST);
        if ($r['status'] === 451) return ['verdict' => 'down', 'why' => "$host: erişim engeli (451)"];
        if ($r['status'] >= 500) return ['verdict' => 'down', 'why' => "$host: sunucu hatası (" . $r['status'] . ')'];
        // 401/403/429: site cevap veriyor (çoğu zaman bot koruması, tarayıcıda açılır); 404: oyuna özel
        return ['verdict' => 'up', 'why' => "$host: " . $r['status']];
    }
    return ['verdict' => 'unknown', 'why' => 'çok fazla yönlendirme'];
}

/**
 * Altı saatte bir mağazaların açılıp açılmadığına bakar. Bütçe biterse kalanlar sonraki tura kalır
 * (checked_at güncellenmediği için bir sonraki çağrıda yine "vadesi gelmiş" görünürler).
 */
function checkShops(bool $force = false): void {
    shopHealthInit();
    $due = q("SELECT shop_id, shop_name, ok, samples FROM shop_health
      WHERE samples IS NOT NULL AND (? OR checked_at IS NULL OR checked_at < datetime('now', ?))", [$force ? 1 : 0, '-' . SHOP_CHECK_HOURS . ' hours']);
    if (!$due) return;
    $changed = [];
    foreach ($due as $s) {
        if (!budget_ok(14)) break;   // her mağaza en fazla ~2 bağlantı × 12 sn
        $urls = array_column(q('SELECT url FROM prices WHERE shop_id = ? AND url IS NOT NULL ORDER BY updated_at DESC LIMIT 3', [$s['shop_id']]), 'url');
        if ($urls) ex('UPDATE shop_health SET samples = ? WHERE shop_id = ?', [jenc($urls), $s['shop_id']]);
        else $urls = json_decode_arr($s['samples']);
        if (!$urls) continue;
        $results = [];
        foreach (array_slice($urls, 0, 2) as $u) $results[] = shopProbe($u);
        $ups = count(array_filter($results, fn($r) => $r['verdict'] === 'up'));
        $downs = count(array_filter($results, fn($r) => $r['verdict'] === 'down'));
        $ok = (int) $s['ok'];
        $reason = implode('; ', array_column($results, 'why'));
        if ($ups) $ok = 1;
        elseif ($downs === count($results)) $ok = 0;   // hepsi açılmadıysa gizle
        ex("UPDATE shop_health SET ok = ?, reason = ?, checked_at = datetime('now') WHERE shop_id = ?", [$ok, $reason, $s['shop_id']]);
        if ($ok !== (int) $s['ok']) $changed[] = $s['shop_name'] . ': ' . ($ok ? 'tekrar açılıyor, geri eklendi' : 'açılmıyor, gizlendi') . " ($reason)";
    }
    foreach ($changed as $c) log_line('mağaza ' . $c);
    purgeBlocked();
}

/** Gizlenen mağazaların fiyatlarını sil, "en ucuz" bilgisini kalan mağazalardan yeniden hesapla */
function purgeBlocked(): void {
    $b = blockedShops();
    if (!$b['ids'] && !$b['names']) return;
    $all = array_values(array_filter(q('SELECT DISTINCT shop_id, shop_name FROM prices'), fn($r) => isBlocked($b, $r['shop_id'], $r['shop_name'])));
    if (!$all) return;
    $fixed = 0;
    tx(function () use ($all, &$fixed) {
        foreach ($all as $s) ex('DELETE FROM prices WHERE shop_id = ?', [$s['shop_id']]);
        $orphans = q('SELECT game_id, country, low_m3, low_all FROM lows l WHERE NOT EXISTS
          (SELECT 1 FROM prices p WHERE p.game_id = l.game_id AND p.country = l.country AND p.shop_name = l.shop)');
        foreach ($orphans as $o) {
            $rest = q('SELECT shop_name, price, regular, currency, cut, expiry FROM prices WHERE game_id = ? AND country = ?', [$o['game_id'], $o['country']]);
            foreach ($rest as &$p) $p['usd'] = $p['price'] * usdRate((string) $p['currency']);
            unset($p);
            usort($rest, fn($a, $b) => $a['usd'] <=> $b['usd']);
            $best = $rest[0] ?? null;
            if ($best) {
                ex('INSERT OR REPLACE INTO lows (game_id, country, shop, price, regular, currency, cut, expiry, usd, low_m3, low_all) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$o['game_id'], $o['country'], $best['shop_name'], $best['price'], $best['regular'], $best['currency'], $best['cut'], $best['expiry'], $best['usd'], $o['low_m3'], $o['low_all']]);
            } else ex('DELETE FROM lows WHERE game_id = ? AND country = ?', [$o['game_id'], $o['country']]);
            $fixed++;
        }
    });
    log_line('gizlenen mağazalar: ' . implode(', ', array_column($all, 'shop_name')) . " ($fixed oyunun en ucuz fiyatı yeniden hesaplandı)");
}
