<?php
declare(strict_types=1);
/* Steam'in resmi oyun listesi — ücretsiz Steam Web API anahtarı gerekir: https://steamcommunity.com/dev/apikey */

const STEAM_LIST_URL = 'https://api.steampowered.com/IStoreService/GetAppList/v1/';

/**
 * Sayfa sayfa oyun listesi. Her adımda anahtar = bir sonraki sayfanın başlangıcı (last_appid), değer = oyunlar.
 * $last verilirse o sayfadan devam eder (bütçe bitince kaldığı yerden sürdürmek için).
 */
function steamGamePages(string $key, int $last = 0): Generator {
    for (;;) {
        $q = ['key' => $key, 'include_games' => 'true', 'include_dlc' => 'false', 'include_software' => 'false',
              'include_videos' => 'false', 'include_hardware' => 'false', 'max_results' => '50000'];
        if ($last) $q['last_appid'] = (string) $last;
        $r = http_request('GET', STEAM_LIST_URL . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986), [], null, 60);
        if ($r['status'] === 401 || $r['status'] === 403) throw new RuntimeException('Steam API anahtarı geçersiz. STEAM_API_KEY değerini .env dosyasında kontrol et.');
        if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException('Steam listesi alınamadı (kod ' . $r['status'] . ')');
        $d = (json_decode($r['body'], true)['response'] ?? []);
        $next = (int) ($d['last_appid'] ?? 0);
        yield $next => ($d['apps'] ?? []);
        if (empty($d['have_more_results']) || !$next) break;
        $last = $next;
    }
}

function steamTestKey(string $key): array {
    $r = http_request('GET', STEAM_LIST_URL . '?' . http_build_query(['key' => $key, 'max_results' => '1'], '', '&', PHP_QUERY_RFC3986));
    return ['status' => $r['status'], 'body' => mb_substr($r['body'], 0, 200)];
}
