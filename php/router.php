<?php
/* Yerel geliştirme: php -S localhost:8000 router.php  (Apache'deki .htaccess'in karşılığı) */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$blocked = preg_match('#^/(inc|data|test|bin)/#', $path) || preg_match('#^/\.|\.(php|env|db|sh|md)$#i', $path) || $path === '/app.html';
if ($path !== '/' && !$blocked && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';
