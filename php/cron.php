<?php
declare(strict_types=1);
/* Zamanlanmış iş: fiyatları günceller, kuyruğu eritir, mailleri gönderir, yedek alır.
   Komut satırı:  php cron.php            (sunucu cron'u için her 10 dakikada bir: php /yol/cron.php)
   Web (ücretsiz cron servisleri):  https://siteadi/cron.php?key=CRON_KEY   (.env içinde CRON_KEY tanımlı olmalı) */
require __DIR__ . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $key = (string) env('CRON_KEY', '');
    if ($key === '' || !hash_equals($key, (string) ($_GET['key'] ?? ''))) { http_response_code(403); echo 'yetkisiz'; exit; }
    header('Content-Type: text/plain; charset=utf-8');
}
echo date('c'), ' → ', tick(), "\n";
