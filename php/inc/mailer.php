<?php
declare(strict_types=1);
/* E-posta gönderimi: saf PHP SMTP istemcisi (Nodemailer yerine; composer gerekmez).
   - 465 numaralı kapı: baştan şifreli (ssl://). Diğerleri (587): STARTTLS ile şifrelenir.
   - Giriş: AUTH PLAIN, olmazsa AUTH LOGIN.
   - SMTP_HOST boş ise mail gönderilmez; içeriği error_log'a ve data/mail.log dosyasına yazılır (geliştirme için).
   Testlerde gerçek gönderim yerine $GLOBALS['__mail_stub'] = fn($to,$subject,$html,$text) => true; kullanılabilir. */

final class SmtpException extends RuntimeException {
    /** kind: connect | tls | auth | protocol */
    public function __construct(string $message, public string $kind = 'protocol', public ?int $smtpCode = null) { parent::__construct($message); }
}

function smtpSettings(): array {
    $port = (int) env('SMTP_PORT', 587);
    return [
        'host' => (string) env('SMTP_HOST', ''),
        'port' => $port,
        'secure' => $port === 465,
        'user' => (string) env('SMTP_USER', ''),
        // Gmail uygulama şifresi 4'lü gruplar halinde boşluklu kopyalanır; boşluklar silinir
        'pass' => preg_replace('/\s+/', '', (string) env('SMTP_PASS', '')),
        'from' => (string) env('MAIL_FROM', env('SMTP_USER', '')),
        'timeout' => 15,
    ];
}

/* ---------- Saf yardımcılar (ağ gerektirmez, testlenebilir) ---------- */

function mailNoCrlf(string $s): string { return trim(str_replace(["\r", "\n", "\0"], '', $s)); }

/** RFC 2047: ASCII olmayan başlık metnini =?UTF-8?B?...?= parçalarına böler */
function mailEncodeHeader(string $s): string {
    $s = mailNoCrlf($s);
    if ($s === '' || preg_match('/^[\x20-\x7E]*$/', $s)) return $s;
    $words = []; $cur = '';
    foreach (mb_str_split($s, 1, 'UTF-8') as $ch) {
        if ($cur !== '' && strlen($cur) + strlen($ch) > 42) { $words[] = $cur; $cur = ''; }
        $cur .= $ch;
    }
    if ($cur !== '') $words[] = $cur;
    return implode("\r\n ", array_map(fn($w) => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
}

/** "Ad <adres@ornek.com>" ya da yalnızca "adres@ornek.com" → [görünen ad, adres] */
function mailParseAddress(string $s): array {
    $s = mailNoCrlf($s);
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/u', $s, $m)) return [trim($m[1]), trim($m[2])];
    return ['', $s];
}
function mailFormatAddress(string $s): string {
    [$name, $addr] = mailParseAddress($s);
    return $name === '' ? $addr : mailEncodeHeader($name) . ' <' . $addr . '>';
}

/** Satır sonlarını CRLF yapar, satır başındaki noktaları ikiler ve CRLF ile bitirir (SMTP DATA kuralı) */
function smtpDotStuff(string $data): string {
    $data = preg_replace("/\r\n|\r|\n/", "\r\n", $data);
    $data = preg_replace('/^\./m', '..', $data);
    return str_ends_with($data, "\r\n") ? $data : $data . "\r\n";
}

function mailBase64Body(string $s): string { return rtrim(chunk_split(base64_encode($s), 76, "\r\n")) . "\r\n"; }

/**
 * Mailin ham halini (başlıklar + gövde) üretir.
 * @param array $m from, to, subject, text, html [, boundary, date, msgid]
 * @return array{raw:string, headers:string, body:string, fromAddr:string, toAddr:string}
 */
function mailBuildMime(array $m): array {
    [, $fromAddr] = mailParseAddress((string) $m['from']);
    [, $toAddr] = mailParseAddress((string) $m['to']);
    $domain = str_contains($fromAddr, '@') ? substr(strrchr($fromAddr, '@'), 1) : 'localhost';
    $boundary = $m['boundary'] ?? ('=_fiyatavi_' . bin2hex(random_bytes(12)));
    $date = $m['date'] ?? gmdate('D, d M Y H:i:s') . ' +0000';
    $msgid = $m['msgid'] ?? ('<' . bin2hex(random_bytes(12)) . '@' . $domain . '>');
    $text = (string) ($m['text'] ?? '');
    $html = (string) ($m['html'] ?? '');
    $h = [
        'Date: ' . $date,
        'From: ' . mailFormatAddress((string) $m['from']),
        'To: ' . mailFormatAddress((string) $m['to']),
        'Subject: ' . mailEncodeHeader((string) $m['subject']),
        'Message-ID: ' . $msgid,
        'MIME-Version: 1.0',
    ];
    if ($html === '') {
        $h[] = 'Content-Type: text/plain; charset=utf-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $body = mailBase64Body($text);
    } else {
        $h[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
        $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . mailBase64Body($text)
              . "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . mailBase64Body($html)
              . "--$boundary--\r\n";
    }
    $headers = implode("\r\n", $h);
    return ['raw' => $headers . "\r\n\r\n" . $body, 'headers' => $headers, 'body' => $body, 'fromAddr' => $fromAddr, 'toAddr' => $toAddr];
}

/* ---------- SMTP konuşması ---------- */

/** Sunucu cevabını okur (çok satırlı "250-..." cevapları dahil). @return array{0:int,1:string} */
function smtpRead($fp): array {
    $code = 0; $text = '';
    while (true) {
        $line = fgets($fp, 4096);
        if ($line === false) {
            $meta = stream_get_meta_data($fp);
            throw new SmtpException(!empty($meta['timed_out']) ? 'mail sunucusu zamanında cevap vermedi (zaman aşımı)' : 'mail sunucusuyla bağlantı koptu', 'connect');
        }
        $line = rtrim($line, "\r\n");
        if (!preg_match('/^(\d{3})([ -])?(.*)$/', $line, $m)) continue;
        $code = (int) $m[1]; $text .= ($text === '' ? '' : "\n") . $m[3];
        if (($m[2] ?? ' ') !== '-') break;
    }
    return [$code, $text];
}

function smtpCmd($fp, string $cmd, array $ok, string $kind = 'protocol', bool $secret = false): array {
    if (fwrite($fp, $cmd . "\r\n") === false) throw new SmtpException('mail sunucusuna yazılamadı', 'connect');
    [$code, $text] = smtpRead($fp);
    if (!in_array($code, $ok, true)) {
        $shown = $secret ? '(gizli)' : strtok($cmd, ' ');
        throw new SmtpException("SMTP $code ($shown): $text", $kind, $code);
    }
    return [$code, $text];
}

function smtpEhloName(): string {
    $host = parse_url(site_url(), PHP_URL_HOST) ?: 'localhost';
    return preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?: 'localhost';
}

/** Bağlanır, (gerekirse) şifreler ve giriş yapar. Döndürdüğü kaynağı smtpClose ile kapat. */
function smtpOpen(array $s) {
    $scheme = $s['secure'] ? 'ssl' : 'tcp';
    $ctx = stream_context_create(['ssl' => ['peer_name' => $s['host'], 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client("$scheme://{$s['host']}:{$s['port']}", $errno, $errstr, $s['timeout'], STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new SmtpException("mail sunucusuna bağlanılamadı ($errno): $errstr", 'connect');
    stream_set_timeout($fp, (int) $s['timeout']);
    try {
        [$code, $text] = smtpRead($fp);
        if ($code !== 220) throw new SmtpException("SMTP karşılama hatası $code: $text", 'protocol', $code);
        $ehlo = fn() => smtpCmd($fp, 'EHLO ' . smtpEhloName(), [250])[1];
        $caps = strtoupper($ehlo());
        $loopback = in_array($s['host'], ['127.0.0.1', 'localhost', '::1'], true);
        if (!$s['secure']) {
            if (str_contains($caps, 'STARTTLS')) {
                smtpCmd($fp, 'STARTTLS', [220], 'tls');
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new SmtpException('TLS (şifreli bağlantı) kurulamadı', 'tls');
                $caps = strtoupper($ehlo());
            } elseif ($s['user'] !== '' && !$loopback) {
                throw new SmtpException('mail sunucusu şifreli bağlantı (STARTTLS) sunmuyor; şifre açık gönderilmez', 'tls');
            }
        }
        if ($s['user'] !== '') {
            preg_match('/^AUTH[ =](.*)$/mi', $caps, $am);   // smtpRead cevap satırlarını kodsuz birleştirir
            $mechs = preg_split('/\s+/', strtoupper(trim($am[1] ?? '')));
            if (in_array('PLAIN', $mechs, true)) {
                smtpCmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $s['user'] . "\0" . $s['pass']), [235], 'auth', true);
            } elseif (in_array('LOGIN', $mechs, true)) {
                smtpCmd($fp, 'AUTH LOGIN', [334], 'auth');
                smtpCmd($fp, base64_encode($s['user']), [334], 'auth', true);
                smtpCmd($fp, base64_encode($s['pass']), [235], 'auth', true);
            } else {
                throw new SmtpException('mail sunucusu desteklediğimiz bir giriş yöntemi (PLAIN/LOGIN) sunmuyor', 'auth');
            }
        }
    } catch (Throwable $e) { @fclose($fp); throw $e; }
    return $fp;
}
function smtpClose($fp): void { try { @fwrite($fp, "QUIT\r\n"); } catch (Throwable $e) {} @fclose($fp); }

/** Hazır bir mailin ham halini gönderir. Hata olursa SmtpException fırlatır. */
function smtpDeliver(array $s, array $mime): void {
    $fp = smtpOpen($s);
    try {
        smtpCmd($fp, 'MAIL FROM:<' . $mime['fromAddr'] . '>', [250]);
        smtpCmd($fp, 'RCPT TO:<' . $mime['toAddr'] . '>', [250, 251]);
        smtpCmd($fp, 'DATA', [354]);
        if (fwrite($fp, smtpDotStuff($mime['raw']) . ".\r\n") === false) throw new SmtpException('mail içeriği gönderilemedi', 'connect');
        [$code, $text] = smtpRead($fp);
        if ($code !== 250) throw new SmtpException("SMTP $code (DATA): $text", 'protocol', $code);
    } finally { smtpClose($fp); }
}

/* ---------- Genel arayüz ---------- */

/** SMTP ayarlı değilse içeriği dosyaya ve error_log'a yazar (geliştirme için) */
function mailLogOnly(string $to, string $subject, string $text): void {
    $line = "\n[mail — SMTP ayarlı değil, gönderilmedi]\n  Kime: $to\n  Konu: $subject\n  " . str_replace("\n", "\n  ", $text) . "\n";
    $f = data_dir() . '/mail.log';
    if (is_file($f) && filesize($f) > 524288) @file_put_contents($f, '');
    @file_put_contents($f, gmdate('c') . $line, FILE_APPEND | LOCK_EX);
    error_log($line);
}

/**
 * Mail gönderir. true: gönderildi (ya da SMTP ayarsızken kayda yazıldı), false: gönderilemedi (hata kaydedildi).
 * html: güzel görünüm, text: HTML göstermeyen programlar için.
 */
function sendMail(string $to, string $subject, string $html, string $text = ''): bool {
    if (isset($GLOBALS['__mail_stub']) && is_callable($GLOBALS['__mail_stub'])) return ($GLOBALS['__mail_stub'])($to, $subject, $html, $text) !== false;
    $s = smtpSettings();
    if ($s['host'] === '') { mailLogOnly($to, $subject, $text); return true; }
    try {
        smtpDeliver($s, mailBuildMime(['from' => $s['from'], 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html]));
        return true;
    } catch (Throwable $e) {
        log_line('Mail gönderilemedi: ' . $e->getMessage());
        return false;
    }
}
