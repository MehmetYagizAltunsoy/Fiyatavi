<?php
declare(strict_types=1);
/* Ayarlar: php/.env dosyasından okunur (Node sürümündeki .env ile aynı anahtarlar). */
define('ROOT', dirname(__DIR__));

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
        if (getenv($k) === false && !isset($_ENV[$k])) $_ENV[$k] = $v;
    }
}
load_env(ROOT . '/.env');

/** Ayar değeri (ortam değişkeni ya da .env); boşsa varsayılan döner. */
function env(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}
function env_int(string $key, int $default): int { return (int) env($key, $default); }

const COUNTRIES = ['TR'];          // site sadece Türkiye fiyatlarını gösterir
const HISTORY_MONTHS = 36;     // oyun sayfasındaki grafik için en fazla 3 yıllık fiyat geçmişi
const PAGE_SIZE = 60;

function is_prod(): bool { return env('NODE_ENV', env('APP_ENV', '')) === 'production'; }
function site_name(): string { return (string) env('SITE_NAME', 'Fiyatavı'); }
function site_url(): string {
    $u = env('SITE_URL');
    if ($u) return rtrim((string) $u, '/');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function data_dir(): string {
    $d = (string) env('DATA_DIR', ROOT . '/data');
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}
function log_line(string $msg): void { error_log('[fiyatavi] ' . $msg); }
