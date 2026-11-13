<?php
declare(strict_types=1);
/* Testler için sahte SMTP sunucusu (sadece 127.0.0.1, tek bağlantı kabul eder).
   Kullanım: php fakesmtp.php <kayıt-dosyası> [bad-auth]
   Açılınca stdout'a "PORT <numara>" yazar; konuşmayı JSON olarak kayıt dosyasına bırakır. */
$out = $argv[1]; $mode = $argv[2] ?? 'ok';
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
echo "PORT $port\n"; fflush(STDOUT);
stream_set_timeout($srv, 15);
$c = @stream_socket_accept($srv, 15);
if (!$c) exit(1);
stream_set_timeout($c, 10);
$log = ['commands' => [], 'data' => null, 'auth' => null];
$w = fn(string $s) => fwrite($c, $s . "\r\n");
$w('220 fake.smtp ESMTP');
while (($line = fgets($c)) !== false) {
    $line = rtrim($line, "\r\n");
    $log['commands'][] = preg_match('/^AUTH /i', $line) ? 'AUTH …' : $line;
    $u = strtoupper($line);
    if (str_starts_with($u, 'EHLO')) { $w('250-fake.smtp'); $w('250-SIZE 10485760'); $w('250 AUTH PLAIN LOGIN'); }
    elseif (str_starts_with($u, 'AUTH PLAIN')) {
        $log['auth'] = base64_decode(substr($line, 11));
        $mode === 'bad-auth' ? $w('535 5.7.8 Username and Password not accepted') : $w('235 2.7.0 Accepted');
    }
    elseif (str_starts_with($u, 'MAIL FROM') || str_starts_with($u, 'RCPT TO')) $w('250 OK');
    elseif ($u === 'DATA') {
        $w('354 go ahead');
        $data = '';
        while (($l = fgets($c)) !== false) { if ($l === ".\r\n") break; $data .= $l; }
        $log['data'] = preg_replace('/^\.\./m', '.', $data);   // nokta kaçışını geri al
        $w('250 2.0.0 queued');
    }
    elseif ($u === 'QUIT') { $w('221 bye'); break; }
    else $w('502 not implemented');
}
file_put_contents($out, json_encode($log));
