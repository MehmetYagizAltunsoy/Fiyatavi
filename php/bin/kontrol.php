<?php
declare(strict_types=1);
/* Tanı: kaç oyunun Türkiye fiyatı var, fiyatı olmayanlar hangileri.   Kullanım:  php bin/kontrol.php [TR] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../inc/bootstrap.php';
shopHealthInit();

$total = (int) qv('SELECT COUNT(*) FROM games WHERE hidden = 0', [], 0);
$pending = (int) qv('SELECT COUNT(*) FROM games WHERE info_done = 0', [], 0);
echo "\nSitedeki oyun: $total   (kapak/bilgisi henüz gelmeyen: $pending)\n\n";
echo "Bölge  Fiyatı olan\n";
foreach (COUNTRIES as $c) {
    $n = (int) qv('SELECT COUNT(DISTINCT p.game_id) FROM prices p JOIN games g ON g.id = p.game_id WHERE g.hidden = 0 AND p.country = ?', [$c], 0);
    printf("  %s    %4d  (%%%d)\n", $c, $n, $total ? (int) round($n / $total * 100) : 0);
}
$bolge = strtoupper($argv[1] ?? 'TR');
$missing = q('SELECT g.title, g.tags FROM games g WHERE g.hidden = 0
    AND NOT EXISTS (SELECT 1 FROM prices p WHERE p.game_id = g.id AND p.country = ?)
  ORDER BY g.popular_rank IS NULL, g.popular_rank LIMIT 15', [$bolge]);
echo "\n$bolge bölgesinde fiyatı olmayan ilk 15 popüler oyun:\n";
foreach ($missing as $m) {
    $free = in_array('Free to Play', json_decode_arr($m['tags']), true) ? '  [ücretsiz oyun]' : '';
    echo "  - {$m['title']}$free\n";
}
$shops = q('SELECT shop_name AS s, COUNT(DISTINCT game_id) AS n FROM prices WHERE country = ? GROUP BY shop_name ORDER BY n DESC', [$bolge]);
echo "\n$bolge bölgesinde fiyat veren mağazalar (oyun sayısı):\n";
foreach ($shops as $x) echo '  ' . $x['s'] . str_repeat(' ', max(0, 28 - mb_strlen($x['s']))) . ' ' . $x['n'] . "\n";
$health = q('SELECT shop_name, ok, reason, checked_at FROM shop_health ORDER BY ok, shop_name');
if ($health) {
    $bad = array_filter($health, fn($h) => !$h['ok']);
    echo "\nMağaza erişim kontrolü: " . count($health) . ' mağaza, ' . count($bad) . " tanesi açılmadığı için gizli\n";
    foreach ($bad as $h) echo "  ✗ {$h['shop_name']}  ({$h['reason']})\n";
}
if (env('SHOPS_HIDE')) echo 'Elle gizlenenler (.env SHOPS_HIDE): ' . env('SHOPS_HIDE') . "\n";
$tagged = (int) qv('SELECT COUNT(DISTINCT game_id) FROM game_tags', [], 0);
$rated = (int) qv('SELECT COUNT(*) FROM games WHERE rating_pos IS NOT NULL', [], 0);
$withReq = (int) qv('SELECT COUNT(*) FROM media WHERE length(req_min) > 2 OR length(req_rec) > 2', [], 0);
echo "\nKategorisi olan oyun: $tagged   Puanı olan oyun: $rated   Sistem gereksinimi olan oyun: $withReq\n";
$steamTotal = (int) qv('SELECT COUNT(*) FROM steam_apps', [], 0);
if ($steamTotal) {
    $mapped = (int) qv('SELECT COUNT(*) FROM steam_apps WHERE mapped = 1', [], 0);
    echo "\nSteam listesi: $steamTotal oyun, $mapped tanesi işlendi\n";
}
$stale = (int) qv('SELECT COUNT(*) FROM games WHERE hidden = 0 AND last_priced IS NULL', [], 0);
echo "Fiyatı henüz hiç çekilmemiş oyun: $stale\n\n";
