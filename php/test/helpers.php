<?php
declare(strict_types=1);
/* Test yardımcıları: her test dosyası başında require eder. Geçici bir veri tabanı kurar ve inc/bootstrap.php'yi yükler. */
$tmp = sys_get_temp_dir() . '/fiyatavi-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($tmp, 0775, true);
$_ENV['DB_PATH'] = $tmp . '/test.db';
$_ENV['DATA_DIR'] = $tmp;
$_ENV['SMTP_HOST'] = '';
$_ENV['SITE_URL'] = 'http://test.local';
$_ENV['ADMIN_EMAILS'] = 'admin@test.local';
register_shutdown_function(function () use ($tmp) { foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f); @rmdir($tmp); });
require_once __DIR__ . '/../inc/bootstrap.php';

$GLOBALS['__t'] = ['pass' => 0, 'fail' => 0];
function t(string $name, callable $fn): void {
    try { $fn(); $GLOBALS['__t']['pass']++; fwrite(STDOUT, "  ✔ $name\n"); }
    catch (Throwable $e) { $GLOBALS['__t']['fail']++; fwrite(STDOUT, "  ✘ $name\n      " . $e->getMessage() . "\n      " . basename($e->getFile()) . ':' . $e->getLine() . "\n"); }
}
function eq($a, $b, string $msg = ''): void { if ($a !== $b) throw new Exception(($msg ? "$msg: " : '') . 'beklenen ' . var_export($b, true) . ', gelen ' . var_export($a, true)); }
function ok($c, string $msg = 'koşul sağlanmadı'): void { if (!$c) throw new Exception($msg); }
register_shutdown_function(function () { fwrite(STDOUT, '  → ' . $GLOBALS['__t']['pass'] . ' geçti, ' . $GLOBALS['__t']['fail'] . " kaldı\n"); if ($GLOBALS['__t']['fail']) exit(1); });

/** Sahte HTTP isteği: handle_request() çalıştırır, [durum, çözülmüş JSON ya da ham metin, atanan çerez] döner. */
function call(string $method, string $path, array $query = [], ?array $body = null, ?string $sid = null): array {
    foreach (['__req', '__user', '__raw_body', '__last_set_cookie'] as $k) unset($GLOBALS[$k]);
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path . ($query ? '?' . http_build_query($query) : '');
    $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
    $_SERVER['HTTP_COOKIE'] = $sid ? "sid=$sid" : '';
    if ($body !== null) $GLOBALS['__raw_body'] = json_encode($body);
    http_response_code(200);
    ob_start();
    handle_request();
    $out = ob_get_clean();
    $json = json_decode($out, true);
    $cookie = $GLOBALS['__last_set_cookie'] ?? null;
    $sid2 = $cookie && preg_match('/sid=([a-f0-9]{64})/', $cookie, $m) ? $m[1] : null;
    return ['status' => http_response_code(), 'json' => $json, 'raw' => $out, 'sid' => $sid2, 'cookie' => $cookie];
}
