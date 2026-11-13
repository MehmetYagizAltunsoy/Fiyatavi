<?php
declare(strict_types=1);
/* Tanı: php tani.php  — fiyatlar neden dolmuyor, tek bakışta gösterir (veri tabanına yazmaz). */
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sync.php';
$age = '-' . tailHours() . ' hours';
$rows = [
  'toplam oyun kaydı' => qv('SELECT COUNT(*) FROM games', [], 0),
  'görünen (hidden=0)' => qv('SELECT COUNT(*) FROM games WHERE hidden = 0', [], 0),
  'fiyatı çekilmiş' => qv('SELECT COUNT(*) FROM games WHERE hidden = 0 AND last_priced IS NOT NULL', [], 0),
  'fiyat sırası bekleyen' => qv("SELECT COUNT(*) FROM games WHERE hidden = 0 AND (last_priced IS NULL OR last_priced < datetime('now', ?))", [$age], 0),
  'bilgisi bekleyen' => qv('SELECT COUNT(*) FROM games WHERE info_done = 0', [], 0),
  'ITAD son 5 dk istek' => itadUsage() . ' / limit ' . itadRateLimit() . ' (arka plan payı: ' . (int) floor(itadRateLimit() * BACKGROUND_SHARE) . ', bilgi payı: ' . (int) floor(itadRateLimit() * (defined('INFO_SHARE') ? INFO_SHARE : 0.6)) . ')',
  'arka plan sırası (bg_rot)' => meta_get('bg_rot') ?? '-',
  'son tur' => meta_get('last_tick_at') ? date('c', (int) meta_get('last_tick_at')) : '-',
  'TICK_BUDGET (sn)' => env_int('TICK_BUDGET', PHP_SAPI === 'cli' ? 240 : 25),
];
foreach ($rows as $k => $v) echo str_pad($k, 28), $v, "\n";
