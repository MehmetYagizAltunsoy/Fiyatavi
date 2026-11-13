<?php
declare(strict_types=1);
/* Fiyat takibi ve mail testleri: fiyat düşünce / hedef fiyata inince bildirim oluşuyor mu, mailler doğru kuruluyor mu?
   (test/bildirim.test.js'in PHP karşılığı + mailer için ağsız testler; SMTP konuşması yerel sahte sunucuyla denenir) */
require __DIR__ . '/helpers.php';

ini_set('error_log', $_ENV['DATA_DIR'] . '/php-error.log');   // geliştirme modundaki mail dökümü ekranı kirletmesin

$sent = [];
$GLOBALS['__mail_stub'] = function (string $to, string $subject, string $html, string $text) use (&$sent) {
    $sent[] = compact('to', 'subject', 'html', 'text');
    return true;
};

$uid = 0;
function hazirla(array $o = []): array {
    global $uid;
    $o += ['dogrulandi' => 1, 'sonFiyat' => 1000, 'hedef' => null, 'simdi' => 900, 'minCut' => 0, 'indirim' => 10];
    $uid++;
    $oyun = "oyun-$uid";
    ex('INSERT INTO games (id, title) VALUES (?, ?)', [$oyun, "Oyun $uid"]);
    ex("INSERT INTO users (email, name, pass_hash, country, email_verified, min_cut) VALUES (?, 'Test', 'x', 'TR', ?, ?)",
        ["k$uid@test.com", $o['dogrulandi'], $o['minCut']]);
    $kullanici = last_id();
    ex('INSERT INTO wishlist (user_id, game_id, last_price, target_price, target_currency) VALUES (?, ?, ?, ?, ?)',
        [$kullanici, $oyun, $o['sonFiyat'], $o['hedef'], $o['hedef'] ? 'TRY' : null]);
    ex("INSERT INTO lows (game_id, country, shop, price, regular, currency, cut) VALUES (?, 'TR', 'Steam', ?, 1000, 'TRY', ?)",
        [$oyun, $o['simdi'], $o['indirim']]);
    return [$kullanici, $oyun];
}
function bildirimler(int $k): array { return array_column(q('SELECT kind FROM notifications WHERE user_id = ? ORDER BY id', [$k]), 'kind'); }
function fiyatDegistir(string $oyun, float $f): void { ex('UPDATE lows SET price = ? WHERE game_id = ?', [$f, $oyun]); }
function temizle(): void { global $sent; ex('DELETE FROM notifications'); $sent = []; }

/* ---------- Fiyat düşüşü ve hedef fiyat ---------- */
t("fiyat düşünce 'drop' bildirimi oluşur ve son fiyat güncellenir", function () {
    temizle(); [$k, $oyun] = hazirla(['sonFiyat' => 1000, 'simdi' => 900]);
    checkPriceDrops();
    eq(bildirimler($k), ['drop']);
    eq((float) qv('SELECT last_price FROM wishlist WHERE game_id = ?', [$oyun]), 900.0);
});
t('fiyat değişmediyse ikinci kez bildirim gönderilmez', function () {
    temizle(); [$k] = hazirla(['sonFiyat' => 1000, 'simdi' => 900]);
    checkPriceDrops(); checkPriceDrops();
    eq(count(bildirimler($k)), 1);
});
t('fiyat yükselirse bildirim gitmez', function () {
    temizle(); [$k] = hazirla(['sonFiyat' => 900, 'simdi' => 1000]);
    checkPriceDrops();
    eq(bildirimler($k), []);
});
t('e-postası doğrulanmamış kullanıcıya bildirim gitmez', function () {
    temizle(); [$k] = hazirla(['dogrulandi' => 0, 'sonFiyat' => 1000, 'simdi' => 500]);
    checkPriceDrops();
    eq(bildirimler($k), []);
});
t('indirim, kullanıcının seçtiği orandan küçükse bildirim gitmez', function () {
    temizle(); [$k] = hazirla(['sonFiyat' => 1000, 'simdi' => 900, 'indirim' => 10, 'minCut' => 50]);
    checkPriceDrops();
    eq(bildirimler($k), []);
});
t("hedef fiyata inince 'target' bildirimi bir kez gider", function () {
    temizle(); [$k, $oyun] = hazirla(['sonFiyat' => 1000, 'hedef' => 800, 'simdi' => 900]);
    checkPriceDrops();
    eq(bildirimler($k), [], 'hedefin üstündeyken haber verilmez');
    fiyatDegistir($oyun, 750);
    checkPriceDrops(); checkPriceDrops();
    eq(bildirimler($k), ['target'], 'hedefe inince tek bildirim');
});
t('fiyat hedefin üstüne çıkıp tekrar inerse yeniden haber verilir', function () {
    temizle(); [$k, $oyun] = hazirla(['sonFiyat' => 1000, 'hedef' => 800, 'simdi' => 750]);
    checkPriceDrops();
    fiyatDegistir($oyun, 950); checkPriceDrops();
    fiyatDegistir($oyun, 700); checkPriceDrops();
    eq(bildirimler($k), ['target', 'target']);
});
t('düşüş maili doğru kişiye, doğru konuyla ve TL biçimiyle gider', function () {
    global $sent, $uid; temizle(); [$k] = hazirla(['sonFiyat' => 1000, 'simdi' => 900]);
    checkPriceDrops();
    eq(count($sent), 1);
    eq($sent[0]['to'], "k$uid@test.com");
    ok(str_contains($sent[0]['subject'], 'ucuzladı'), $sent[0]['subject']);
    ok(str_contains($sent[0]['subject'], '₺900,00'), $sent[0]['subject']);
    ok(str_contains($sent[0]['text'], 'Önceki fiyat: ₺1.000,00'));
    ok(str_contains($sent[0]['html'], '<table'));
});
t('doğrulanmamış kullanıcıya mail de gitmez (sadece son fiyat güncellenir)', function () {
    global $sent; temizle(); [$k, $oyun] = hazirla(['dogrulandi' => 0, 'sonFiyat' => 1000, 'simdi' => 500]);
    checkPriceDrops();
    eq($sent, []);
    eq((float) qv('SELECT last_price FROM wishlist WHERE game_id = ?', [$oyun]), 500.0);
});

/* ---------- İstek listesine eklenince ---------- */
t("notifyAdded: 'added' kaydı oluşur, son fiyat yazılır, mail gider", function () {
    global $sent; temizle(); [$k, $oyun] = hazirla(['sonFiyat' => 1, 'simdi' => 640]);
    $u = q1('SELECT * FROM users WHERE id = ?', [$k]);
    notifyAdded($u, ['id' => $oyun, 'title' => 'x']);
    eq(bildirimler($k), ['added']);
    eq((float) qv('SELECT last_price FROM wishlist WHERE game_id = ?', [$oyun]), 640.0);
    eq(count($sent), 1);
    ok(str_contains($sent[0]['subject'], 'istek listene eklendi'));
    ok(str_contains($sent[0]['text'], 'Şu an en ucuz: ₺640,00 (Steam)'));
});
t("notifyAdded: fiyatı olmayan oyunda 'Türkiye mağazalarında' metni kullanılır", function () {
    global $sent; temizle(); [$k, $oyun] = hazirla();
    ex('DELETE FROM lows WHERE game_id = ?', [$oyun]);
    notifyAdded(q1('SELECT * FROM users WHERE id = ?', [$k]), ['id' => $oyun, 'title' => 'x']);
    ok(str_contains($sent[0]['text'], 'Türkiye mağazalarında'));
    eq(qv('SELECT last_price FROM wishlist WHERE game_id = ?', [$oyun]), null);
});
t('notifyAdded: doğrulanmamış kullanıcıya kayıt da mail de yok', function () {
    global $sent; temizle(); [$k, $oyun] = hazirla(['dogrulandi' => 0]);
    notifyAdded(q1('SELECT * FROM users WHERE id = ?', [$k]), ['id' => $oyun, 'title' => 'x']);
    eq(bildirimler($k), []); eq($sent, []);
});
t('buildMail: İngilizce metinler ve oyun bağlantısı (slug, kaçışlı)', function () {
    $lo = ['shop' => 'Steam', 'price' => 900.0, 'regular' => 1000.0, 'currency' => 'TRY', 'cut' => 10];
    $m = buildMail('drop', 'en', ['id' => 'a', 'slug' => 'elden ring', 'title' => 'Elden Ring', 'appid' => 1245620], $lo,
        ['g' => 'Elden Ring', 'p' => '$9.00', 's' => 'Steam', 'old' => '$10.00']);
    eq($m['subject'], 'Elden Ring price drop: $9.00');
    ok(str_contains($m['text'], 'See prices: http://test.local/oyun/elden%20ring'));
    ok(str_contains($m['html'], 'apps/1245620/header.jpg'));
    ok(str_contains($m['html'], '-%10'));
});

/* ---------- Haftalık özet ---------- */
function ozetSayisi(int $k): int { return (int) qv("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'digest'", [$k]); }
t('haftalık özet: ilk turda gönderilmez, sadece sayaç başlar', function () {
    temizle(); [$k] = hazirla();
    ex('UPDATE users SET last_digest_at = NULL WHERE id = ?', [$k]);
    sendWeeklyDigests();
    eq(ozetSayisi($k), 0);
    ok(qv('SELECT last_digest_at FROM users WHERE id = ?', [$k]));
});
t('haftalık özet: 7 gün dolmadan tekrar gönderilmez, dolunca gönderilir', function () {
    global $sent; temizle(); [$k] = hazirla();
    ex("UPDATE users SET last_digest_at = datetime('now', '-3 days') WHERE id = ?", [$k]);
    sendWeeklyDigests();
    eq(ozetSayisi($k), 0, '3 gün sonra gitmemeli');
    ex("UPDATE users SET last_digest_at = datetime('now', '-8 days') WHERE id = ?", [$k]);
    $n = sendWeeklyDigests();
    eq(ozetSayisi($k), 1, '8 gün sonra gitmeli');
    ok($n >= 1);
    eq(count($sent), 1);
});
t('haftalık özeti kapatan kullanıcıya özet gitmez', function () {
    temizle(); [$k] = hazirla();
    ex("UPDATE users SET weekly_digest = 0, last_digest_at = datetime('now', '-30 days') WHERE id = ?", [$k]);
    sendWeeklyDigests();
    eq(ozetSayisi($k), 0);
});
t('haftalık özet: force ve onlyUserId yalnızca o kullanıcıya zorla gönderir', function () {
    global $sent; temizle(); [$k] = hazirla(); [$k2] = hazirla();
    ex("UPDATE users SET last_digest_at = datetime('now') WHERE id IN (?, ?)", [$k, $k2]);
    $n = sendWeeklyDigests(['force' => true, 'onlyUserId' => $k]);
    eq($n, 1); eq(ozetSayisi($k), 1); eq(ozetSayisi($k2), 0);
});
t('haftalık özet maili indirimdeki oyunları ve fiyat değişimini yazar', function () {
    temizle(); [$k, $oyun] = hazirla(['simdi' => 500, 'indirim' => 50]);
    ex("INSERT INTO price_history (game_id, country, day, lowest, currency) VALUES (?, 'TR', date('now', '-8 days'), 1000, 'TRY')", [$oyun]);
    $mail = buildDigest(q1('SELECT * FROM users WHERE id = ?', [$k]));
    ok(preg_match('/1 oyun indirimde/', $mail['subject']) === 1, $mail['subject']);
    ok(preg_match('/-%50/', $mail['text']) === 1);
    ok(preg_match('/Geçen haftaya göre .* ucuz/u', $mail['text']) === 1, $mail['text']);
    ok(str_contains($mail['html'], '<table'));
    eq($mail['count'], 1);
});

/* ---------- Hesap mailleri ---------- */
t('sendAccountMail: doğrulama bağlantısı ve dil seçimi', function () {
    global $sent; temizle();
    sendAccountMail('verify', ['email' => 'a@test.com', 'lang' => 'tr'], str_repeat('ab', 32));
    sendAccountMail('reset', ['email' => 'b@test.com', 'lang' => 'en'], str_repeat('cd', 32));
    eq(count($sent), 2);
    eq($sent[0]['subject'], 'Fiyatavı: e-posta adresini doğrula');
    ok(str_contains($sent[0]['text'], 'http://test.local/?verify=' . str_repeat('ab', 32)));
    eq($sent[1]['subject'], 'Fiyatavı: reset your password');
    ok(str_contains($sent[1]['html'], '?reset=' . str_repeat('cd', 32)));
    ok(str_contains($sent[1]['html'], 'lang="en"'));
});

/* ---------- Mailer: saf yardımcılar ---------- */
t('başlık kodlama: ASCII aynen, Türkçe =?UTF-8?B? olarak, satır sonu temizlenir', function () {
    eq(mailEncodeHeader('Hello'), 'Hello');
    $e = mailEncodeHeader('Fiyatavı: e-posta adresini doğrula ve şifreni sıfırla');
    ok(str_starts_with($e, '=?UTF-8?B?'));
    ok(!preg_match('/[^\x20-\x7E\r\n]/', $e), 'kodlanmış başlık ASCII olmalı');
    $dec = '';
    foreach (preg_split("/\r\n /", $e) as $w) { ok(preg_match('/^=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=$/', $w, $m) === 1, $w); $dec .= base64_decode($m[1]); }
    eq($dec, 'Fiyatavı: e-posta adresini doğrula ve şifreni sıfırla');
    eq(mailEncodeHeader("a\r\nBcc: x@y.z"), 'aBcc: x@y.z', 'başlık enjeksiyonu engellenir');
});
t('adres ayrıştırma ve biçimleme', function () {
    eq(mailParseAddress('Fiyatavı <noreply@x.com>'), ['Fiyatavı', 'noreply@x.com']);
    eq(mailParseAddress('noreply@x.com'), ['', 'noreply@x.com']);
    eq(mailFormatAddress('noreply@x.com'), 'noreply@x.com');
    ok(str_starts_with(mailFormatAddress('Fiyatavı <n@x.com>'), '=?UTF-8?B?'));
    ok(str_ends_with(mailFormatAddress('Fiyatavı <n@x.com>'), ' <n@x.com>'));
});
t('SMTP nokta kaçışı ve CRLF', function () {
    eq(smtpDotStuff("a\n.b\n..c\n"), "a\r\n..b\r\n...c\r\n");
    eq(smtpDotStuff('x'), "x\r\n");
    eq(smtpDotStuff(".\r\nson"), "..\r\nson\r\n");
});
t('MIME: çok parçalı gövde çözülünce metin ve HTML geri gelir', function () {
    $m = mailBuildMime(['from' => 'Fiyatavı <n@x.com>', 'to' => 'k@test.com', 'subject' => 'Çok güzel indirim',
        'text' => ".nokta ile başlar\nşğü", 'html' => '<p>Merhaba İstanbul</p>', 'boundary' => 'BND', 'date' => 'Thu, 05 Nov 2026 12:00:00 +0000', 'msgid' => '<id@x.com>']);
    eq($m['fromAddr'], 'n@x.com'); eq($m['toAddr'], 'k@test.com');
    ok(str_contains($m['headers'], 'Content-Type: multipart/alternative; boundary="BND"'));
    ok(str_contains($m['headers'], 'Message-ID: <id@x.com>'));
    preg_match_all('/Content-Type: text\/(plain|html); charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*?)--BND/s', $m['body'], $mm, PREG_SET_ORDER);
    eq(count($mm), 2);
    eq(base64_decode($mm[0][2]), ".nokta ile başlar\nşğü");
    eq(base64_decode($mm[1][2]), '<p>Merhaba İstanbul</p>');
    foreach (explode("\r\n", $m['raw']) as $line) ok(strlen($line) <= 998, 'satır çok uzun');
    ok(str_ends_with($m['body'], "--BND--\r\n"));
    $d = mailBuildMime(['from' => 'n@x.com', 'to' => 'k@test.com', 'subject' => 's', 'text' => 'sade', 'html' => '']);
    ok(str_contains($d['headers'], 'Content-Type: text/plain'));
});
t('para biçimi: tr ve en', function () {
    eq(money(1733.81, 'TRY'), '₺1.733,81');
    ok(str_contains(money(9, 'USD', 'en'), '$9.00'));
});
t('SMTP ayarsızken mail gönderilmez, mail.log dosyasına yazılır ve true döner', function () {
    $keep = $GLOBALS['__mail_stub']; unset($GLOBALS['__mail_stub']);
    $r = sendMail('x@test.com', 'Deneme konusu', '<b>x</b>', "satır 1\nsatır 2");
    $GLOBALS['__mail_stub'] = $keep;
    eq($r, true);
    $log = file_get_contents(data_dir() . '/mail.log');
    ok(str_contains($log, 'Kime: x@test.com') && str_contains($log, 'Deneme konusu') && str_contains($log, "  satır 2"));
});

/* ---------- Mailer: yerel sahte SMTP sunucusuyla uçtan uca ---------- */
function sahteSmtp(string $mod = 'ok'): array {
    $out = $_ENV['DATA_DIR'] . '/smtp-' . bin2hex(random_bytes(3)) . '.json';
    $p = proc_open([PHP_BINARY, __DIR__ . '/fakesmtp.php', $out, $mod], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $line = fgets($pipes[1]);
    if (!$line || !preg_match('/^PORT (\d+)/', $line, $m)) throw new Exception('sahte SMTP sunucusu açılamadı');
    return [$p, (int) $m[1], $out];
}
function smtpAyar(int $port): void { $_ENV['SMTP_HOST'] = '127.0.0.1'; $_ENV['SMTP_PORT'] = (string) $port; $_ENV['SMTP_USER'] = 'kullanici'; $_ENV['SMTP_PASS'] = 'ab cd ef gh'; $_ENV['MAIL_FROM'] = 'Fiyatavı <noreply@fiyatavi.test>'; }
function smtpTemizle(): void { foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'MAIL_FROM'] as $k) unset($_ENV[$k]); }

t('SMTP uçtan uca: bağlanır, AUTH PLAIN yapar, gönderir; içerik doğru çözülür', function () {
    [$p, $port, $out] = sahteSmtp();
    $keep = $GLOBALS['__mail_stub']; unset($GLOBALS['__mail_stub']);
    smtpAyar($port);
    $r = sendMail('alici@test.com', 'Şifre sıfırlama ✓', '<p>Merhaba .dünya</p>', ".nokta\nikinci satır");
    smtpTemizle(); $GLOBALS['__mail_stub'] = $keep;
    proc_close($p);
    eq($r, true, 'sendMail true dönmeli');
    $log = json_decode(file_get_contents($out), true);
    eq($log['auth'], "\0kullanici\0abcdefgh", 'şifredeki boşluklar silinmeli');
    ok(in_array('MAIL FROM:<noreply@fiyatavi.test>', $log['commands'], true), implode(' | ', $log['commands']));
    ok(in_array('RCPT TO:<alici@test.com>', $log['commands'], true));
    ok(preg_match('/^EHLO [A-Za-z0-9.\-]+$/', $log['commands'][0]) === 1);
    ok(str_contains($log['data'], 'To: alici@test.com'));
    ok(str_contains($log['data'], 'Subject: =?UTF-8?B?'));
    preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+?)--/', $log['data'], $mm);
    eq(base64_decode($mm[1][0]), ".nokta\nikinci satır");
    eq(base64_decode($mm[1][1]), '<p>Merhaba .dünya</p>');
});
t('SMTP: yanlış şifre → SmtpException(auth), sendMail false döner ve site çökmez', function () {
    [$p, $port, $out] = sahteSmtp('bad-auth');
    smtpAyar($port);
    $err = null;
    try { smtpDeliver(smtpSettings(), mailBuildMime(['from' => 'n@x.com', 'to' => 'a@b.c', 'subject' => 's', 'text' => 't', 'html' => '<i>t</i>'])); }
    catch (SmtpException $e) { $err = $e; }
    smtpTemizle(); proc_close($p);
    ok($err !== null, 'hata beklenirdi');
    eq($err->kind, 'auth'); eq($err->smtpCode, 535);

    [$p, $port] = sahteSmtp('bad-auth');
    $keep = $GLOBALS['__mail_stub']; unset($GLOBALS['__mail_stub']);
    smtpAyar($port);
    $r = sendMail('a@b.c', 's', '<i>t</i>', 't');
    smtpTemizle(); $GLOBALS['__mail_stub'] = $keep; proc_close($p);
    eq($r, false);
});
t('SMTP: kapalı kapıya bağlanma → SmtpException(connect)', function () {
    $srv = stream_socket_server('tcp://127.0.0.1:0'); $port = (int) substr(strrchr(stream_socket_get_name($srv, false), ':'), 1); fclose($srv);
    smtpAyar($port);
    $err = null;
    try { smtpClose(smtpOpen(smtpSettings())); } catch (SmtpException $e) { $err = $e; }
    smtpTemizle();
    ok($err !== null); eq($err->kind, 'connect');
});
