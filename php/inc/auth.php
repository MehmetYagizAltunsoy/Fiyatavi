<?php
declare(strict_types=1);
/* Üyelik: şifre özeti, oturum, e-posta bağlantıları. Node sürümünün veri tabanıyla uyumludur. */

/* ---- scrypt (yalnızca Node sürümünden gelen eski şifreleri doğrulamak için; N=16384, r=8, p=1, 64 bayt) ---- */
function _salsa8(array &$b): void {
    $x = $b; $m = 0xFFFFFFFF;
    $rot = fn(int $v, int $n): int => (($v << $n) | ($v >> (32 - $n))) & 0xFFFFFFFF;
    for ($i = 0; $i < 8; $i += 2) {
        $x[4]  ^= $rot(($x[0]  + $x[12]) & $m, 7);  $x[8]  ^= $rot(($x[4]  + $x[0])  & $m, 9);
        $x[12] ^= $rot(($x[8]  + $x[4])  & $m, 13); $x[0]  ^= $rot(($x[12] + $x[8])  & $m, 18);
        $x[9]  ^= $rot(($x[5]  + $x[1])  & $m, 7);  $x[13] ^= $rot(($x[9]  + $x[5])  & $m, 9);
        $x[1]  ^= $rot(($x[13] + $x[9])  & $m, 13); $x[5]  ^= $rot(($x[1]  + $x[13]) & $m, 18);
        $x[14] ^= $rot(($x[10] + $x[6])  & $m, 7);  $x[2]  ^= $rot(($x[14] + $x[10]) & $m, 9);
        $x[6]  ^= $rot(($x[2]  + $x[14]) & $m, 13); $x[10] ^= $rot(($x[6]  + $x[2])  & $m, 18);
        $x[3]  ^= $rot(($x[15] + $x[11]) & $m, 7);  $x[7]  ^= $rot(($x[3]  + $x[15]) & $m, 9);
        $x[11] ^= $rot(($x[7]  + $x[3])  & $m, 13); $x[15] ^= $rot(($x[11] + $x[7])  & $m, 18);
        $x[1]  ^= $rot(($x[0]  + $x[3])  & $m, 7);  $x[2]  ^= $rot(($x[1]  + $x[0])  & $m, 9);
        $x[3]  ^= $rot(($x[2]  + $x[1])  & $m, 13); $x[0]  ^= $rot(($x[3]  + $x[2])  & $m, 18);
        $x[6]  ^= $rot(($x[5]  + $x[4])  & $m, 7);  $x[7]  ^= $rot(($x[6]  + $x[5])  & $m, 9);
        $x[4]  ^= $rot(($x[7]  + $x[6])  & $m, 13); $x[5]  ^= $rot(($x[4]  + $x[7])  & $m, 18);
        $x[11] ^= $rot(($x[10] + $x[9])  & $m, 7);  $x[8]  ^= $rot(($x[11] + $x[10]) & $m, 9);
        $x[9]  ^= $rot(($x[8]  + $x[11]) & $m, 13); $x[10] ^= $rot(($x[9]  + $x[8])  & $m, 18);
        $x[12] ^= $rot(($x[15] + $x[14]) & $m, 7);  $x[13] ^= $rot(($x[12] + $x[15]) & $m, 9);
        $x[14] ^= $rot(($x[13] + $x[12]) & $m, 13); $x[15] ^= $rot(($x[14] + $x[13]) & $m, 18);
    }
    for ($i = 0; $i < 16; $i++) $b[$i] = ($b[$i] + $x[$i]) & $m;
}
function _blockmix(array $B, int $r): array {
    $X = array_slice($B, (2 * $r - 1) * 16, 16);
    $Y = [];
    for ($i = 0; $i < 2 * $r; $i++) {
        $blk = array_slice($B, $i * 16, 16);
        for ($k = 0; $k < 16; $k++) $X[$k] ^= $blk[$k];
        _salsa8($X);
        $Y[$i] = $X;
    }
    $out = [];
    for ($i = 0; $i < $r; $i++) foreach ($Y[2 * $i] as $v) $out[] = $v;
    for ($i = 0; $i < $r; $i++) foreach ($Y[2 * $i + 1] as $v) $out[] = $v;
    return $out;
}
function scryptRaw(string $password, string $salt, int $N = 16384, int $r = 8, int $p = 1, int $len = 64): string {
    $B = hash_pbkdf2('sha256', $password, $salt, 1, $p * 128 * $r, true);
    $words = 32 * $r;
    $out = '';
    for ($pi = 0; $pi < $p; $pi++) {
        $X = array_values(unpack('V*', substr($B, $pi * 128 * $r, 128 * $r)));
        $V = [];
        for ($i = 0; $i < $N; $i++) { $V[$i] = $X; $X = _blockmix($X, $r); }
        for ($i = 0; $i < $N; $i++) {
            $j = $X[($words - 16)] & ($N - 1);
            $Vj = $V[$j];
            for ($k = 0; $k < $words; $k++) $X[$k] ^= $Vj[$k];
            $X = _blockmix($X, $r);
        }
        $out .= pack('V*', ...$X);
    }
    return hash_pbkdf2('sha256', $password, $out, 1, $len, true);
}

/* ---- Şifre özeti: yeni hesaplar password_hash; Node'dan gelen "tuz:özet" (scrypt) biçimi de doğrulanır ---- */
function hashPassword(string $pw): string { return password_hash($pw, PASSWORD_DEFAULT); }
function verifyPassword(string $pw, string $stored): bool {
    if (preg_match('/^[0-9a-f]{32}:[0-9a-f]{128}$/', $stored)) {   // eski (Node) biçimi
        [$salt, $hash] = explode(':', $stored);
        return hash_equals($hash, bin2hex(scryptRaw($pw, hex2bin($salt))));
    }
    return password_verify($pw, $stored);
}
/** Eski biçimde ya da zayıf ayarla kaydedilmişse true: giriş başarılı olunca yeniden özetlenir */
function passwordNeedsRehash(string $stored): bool {
    return !str_starts_with($stored, '$') || password_needs_rehash($stored, PASSWORD_DEFAULT);
}

const SESSION_DAYS = 30;
const SESSION_MAX_AGE = SESSION_DAYS * 86400;

function createSession(int $userId): string {
    $token = rand_hex(32);
    ex("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, datetime('now', ?))", [$token, $userId, '+' . SESSION_DAYS . ' days']);
    return $token;
}
function destroySession(?string $token): void { if ($token) ex('DELETE FROM sessions WHERE token = ?', [$token]); }
function cleanupSessions(): void {
    ex("DELETE FROM sessions WHERE expires_at <= datetime('now')");
    ex("DELETE FROM email_tokens WHERE expires_at <= datetime('now')");
}
function sessionCookie(string $token, int $maxAge = SESSION_MAX_AGE): string {
    $secure = (is_prod() || (($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? '; Secure' : '';
    return "sid=$token; HttpOnly; SameSite=Lax; Path=/; Max-Age=$maxAge$secure";
}
function setSessionCookie(string $token, int $maxAge = SESSION_MAX_AGE): void { $GLOBALS['__last_set_cookie'] = sessionCookie($token, $maxAge); header('Set-Cookie: ' . $GLOBALS['__last_set_cookie'], false); }

/** Çerezden oturum kimliği (64 onaltılık karakter) */
function sidFromCookieHeader(string $cookie): ?string {
    return preg_match('/(?:^|;\s*)sid=([a-f0-9]{64})/', $cookie, $m) ? $m[1] : null;
}
/** Giriş yapmış kullanıcı (dizi) ya da null */
function userFromSid(?string $sid): ?array {
    if (!$sid) return null;
    return q1("SELECT u.id, u.email, u.name, u.country, u.lang, u.notify_drops, u.min_cut, u.email_verified, u.avatar, u.weekly_digest
      FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.token = ? AND s.expires_at > datetime('now')", [$sid]);
}

/* E-posta doğrulama ve şifre sıfırlama bağlantıları: veri tabanında yalnızca özeti tutulur */
function createEmailToken(int $userId, string $kind, int $hours): string {
    $raw = rand_hex(32);
    ex('DELETE FROM email_tokens WHERE user_id = ? AND kind = ?', [$userId, $kind]);
    ex("INSERT INTO email_tokens (token_hash, user_id, kind, expires_at) VALUES (?, ?, ?, datetime('now', ?))", [sha256hex($raw), $userId, $kind, "+$hours hours"]);
    return $raw;
}
function consumeEmailToken($raw, string $kind): ?int {
    if (!preg_match('/^[a-f0-9]{64}$/', (string) $raw)) return null;
    $h = sha256hex((string) $raw);
    $row = q1("SELECT user_id FROM email_tokens WHERE token_hash = ? AND kind = ? AND expires_at > datetime('now')", [$h, $kind]);
    ex('DELETE FROM email_tokens WHERE token_hash = ?', [$h]);
    return $row ? (int) $row['user_id'] : null;
}
function destroyUserSessions(int $userId): void { ex('DELETE FROM sessions WHERE user_id = ?', [$userId]); }
/** Kaba kuvvet denemelerine karşı: 15 dakikada en fazla 10 deneme */
function tooManyAttempts(string $key): bool { return too_many_attempts($key, 10, 900); }
