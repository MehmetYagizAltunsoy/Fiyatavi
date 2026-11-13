<?php
declare(strict_types=1);
/* E-posta denemesi: .env'deki SMTP ayarlarıyla bir deneme maili gönderir.
   Kullanım:  php bin/mailtest.php                    → SMTP_USER adresine gönderir
              php bin/mailtest.php adres@ornek.com    → verilen adrese gönderir */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$inc = dirname(__DIR__) . '/inc';
require_once "$inc/config.php";
require_once "$inc/util.php";
require_once "$inc/mailer.php";
require_once "$inc/mailtemplate.php";

$s = smtpSettings();
$to = $argv[1] ?? (string) env('SMTP_USER', '');
$site = site_url();
$say = fn(string $m = '') => print($m . "\n");

$say("\nE-posta ayarları (.env):");
$say('  SMTP_HOST : ' . ($s['host'] ?: '(boş)'));
$say('  SMTP_PORT : ' . $s['port']);
$say('  SMTP_USER : ' . ($s['user'] ?: '(boş)'));
$say('  SMTP_PASS : ' . ($s['pass'] !== '' ? 'dolu (' . strlen($s['pass']) . ' karakter)' : '(boş)'));
$say('  Gönderen  : ' . ($s['from'] ?: '(boş)'));
$say('  Alıcı     : ' . ($to ?: '(boş)') . "\n");

if ($s['host'] === '' || $s['user'] === '' || $s['pass'] === '') {
    $say('✗ SMTP ayarları eksik. .env dosyasını doldur (SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS).');
    $say('  Gmail için: SMTP_HOST=smtp.gmail.com, SMTP_PORT=465, SMTP_PASS = Google "uygulama şifresi" (normal şifre çalışmaz).' . "\n");
    exit(1);
}
if ($to === '') { $say('✗ Alıcı adresi yok. Şöyle yaz:  php bin/mailtest.php adresin@gmail.com' . "\n"); exit(1); }

$now = (new DateTime('now', new DateTimeZone('Europe/Istanbul')))->format('d.m.Y H:i:s');
try {
    $say('1) Mail sunucusuna bağlanılıyor…');
    smtpClose(smtpOpen($s));
    $say('   ✓ Bağlantı ve giriş başarılı.');
    $say('2) Deneme maili gönderiliyor…');
    $text = "Bu bir deneme mailidir.\n\nBunu görüyorsan Fiyatavı'nın mail ayarları çalışıyor: fiyat düşüşü, hedef fiyat, e-posta doğrulama ve şifre sıfırlama mailleri de gidecektir.\n\nGönderim zamanı: $now";
    // örnek bir "fiyat düştü" maili: tasarımın gelen kutusunda nasıl göründüğünü gösterir
    $html = layout([
        'site' => site_name(), 'siteUrl' => $site, 'preheader' => 'Deneme: mail ayarların çalışıyor',
        'image' => gameImage(['appid' => 292030]), 'imageAlt' => 'The Witcher 3: Wild Hunt',
        'kicker' => 'Deneme maili', 'title' => 'Mail ayarların çalışıyor',
        'paragraphs' => ['Bu bir deneme. Fiyat düşüşü, hedef fiyat, doğrulama ve şifre sıfırlama mailleri bu görünümle gelecek. Aşağıdaki kutu örnektir.'],
        'price' => ['label' => 'Yeni fiyat', 'now' => '₺1.733,81', 'old' => '₺2.476,87', 'cut' => 30, 'shop' => 'Steam', 'extra' => 'Önceki fiyat: ₺2.476,87'],
        'cta' => ['href' => $site, 'label' => 'Siteyi aç'], 'ctaHint' => 'Düğme çalışmazsa:',
        'footer' => ["Gönderim zamanı: $now"],
    ]);
    $mime = mailBuildMime(['from' => $s['from'], 'to' => $to, 'subject' => 'Fiyatavı – e-posta denemesi', 'text' => $text, 'html' => $html]);
    smtpDeliver($s, $mime);
    $say('   ✓ Gönderildi.');
    $say("\nŞimdi $to gelen kutusuna bak. Birkaç dakikada gelmezse Spam / Gereksiz klasörünü de kontrol et.\n");
} catch (Throwable $e) {
    $say('   ✗ Hata: ' . $e->getMessage() . "\n");
    $kind = $e instanceof SmtpException ? $e->kind : '';
    if ($kind === 'auth' || preg_match('/535|Username and Password|BadCredentials/i', $e->getMessage())) {
        $say('   Sebep: kullanıcı adı ya da şifre kabul edilmedi.');
        $say('   Gmail kullanıyorsan normal şifren çalışmaz; Google hesabında 2 adımlı doğrulamayı açıp');
        $say('   "Uygulama şifreleri" bölümünden 16 haneli bir şifre al ve SMTP_PASS\'e onu yaz (boşluksuz).' . "\n");
    } elseif ($kind === 'connect') {
        $say('   Sebep: mail sunucusuna bağlanılamadı. SMTP_HOST ve SMTP_PORT\'u kontrol et');
        $say('   (Gmail: smtp.gmail.com, 465). Okul/yurt ağı mail portlarını engelliyor olabilir; barındırma şirketi de dış SMTP\'yi kapatmış olabilir.' . "\n");
    } elseif ($kind === 'tls') {
        $say('   Sebep: şifreli bağlantı kurulamadı. 465 kapısını (SSL) ya da 587 kapısını (STARTTLS) dene.' . "\n");
    }
    exit(1);
}
