<?php
declare(strict_types=1);
/* Ön denetleyici: bütün istekler (Apache .htaccess ile) buraya gelir. Statik dosyaları (resim, sw.js…) sunucu doğrudan verir. */
require __DIR__ . '/inc/bootstrap.php';

if (meta_get('first_run') === null) meta_set('first_run', (string) time());

ob_start();
handle_request();
if (!function_exists('fastcgi_finish_request')) {
    // Apache/mod_php: cevabı hemen kapat, ardından (gerekirse) arka plan turunu çalıştır
    if (!headers_sent()) { header('Content-Length: ' . ob_get_length()); header('Connection: close'); }
}
while (ob_get_level() > 1) ob_end_flush();
if (function_exists('fastcgi_finish_request')) { ob_end_flush(); } else { ob_end_flush(); @flush(); }
maybeLazyTick();
