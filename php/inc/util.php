<?php
declare(strict_types=1);

function esc_attr($s): string { return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now_utc(): string { return gmdate('Y-m-d H:i:s'); }
function json_decode_arr($s): array { $v = json_decode((string) ($s ?? ''), true); return is_array($v) ? $v : []; }
function jenc($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }
function sha256hex(string $s): string { return hash('sha256', $s); }
function rand_hex(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }
function sleep_ms(int $ms): void { usleep($ms * 1000); }

/* Zaman bütçesi: PHP'de sürekli çalışan süreç yok; arka plan işleri (cron ya da istek sonrası) belirli bir süre
   çalışıp bırakır, bir sonraki turda kaldığı yerden devam eder. */
function budget_start(float $seconds): void { $GLOBALS['__budget_end'] = microtime(true) + $seconds; }
function budget_left(): float { return isset($GLOBALS['__budget_end']) ? max(0.0, $GLOBALS['__budget_end'] - microtime(true)) : 1e9; }
/** Yeni bir iş başlatmaya yetecek süre var mı? */
function budget_ok(float $reserve = 3.0): bool { return budget_left() > $reserve; }
