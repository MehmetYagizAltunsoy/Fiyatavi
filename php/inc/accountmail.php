<?php
declare(strict_types=1);
/* Hesap mailleri: e-posta doğrulama ve şifre sıfırlama */

function accountMailTexts(): array {
    $name = site_name();
    return [
        'tr' => [
            'verify' => [
                'subject' => "$name: e-posta adresini doğrula",
                'kicker' => 'Hesabın', 'title' => 'E-posta adresini doğrula',
                'body' => ["$name hesabını tamamlamak için aşağıdaki düğmeye bas. Doğruladıktan sonra istek listendeki oyunların fiyatı düşünce sana mail atabileceğiz."],
                'cta' => 'E-postamı doğrula',
                'note' => 'Bağlantı 48 saat geçerlidir. Bu hesabı sen açmadıysan bu maili görmezden gelebilirsin.',
            ],
            'reset' => [
                'subject' => "$name: şifre sıfırlama",
                'kicker' => 'Hesabın', 'title' => 'Şifreni sıfırla',
                'body' => ['Şifreni sıfırlamak için aşağıdaki düğmeye bas ve yeni şifreni belirle.'],
                'cta' => 'Yeni şifre belirle',
                'note' => 'Bağlantı 1 saat geçerlidir ve bir kez kullanılabilir. Bu isteği sen yapmadıysan bu maili görmezden gel; şifren değişmez.',
            ],
            'ctaHint' => 'Düğme çalışmazsa:',
        ],
        'en' => [
            'verify' => [
                'subject' => "$name: verify your email",
                'kicker' => 'Your account', 'title' => 'Verify your email',
                'body' => ["Press the button below to finish setting up your $name account. Once verified, we can email you when games on your wishlist get cheaper."],
                'cta' => 'Verify my email',
                'note' => "The link is valid for 48 hours. If you didn't create this account, ignore this email.",
            ],
            'reset' => [
                'subject' => "$name: reset your password",
                'kicker' => 'Your account', 'title' => 'Reset your password',
                'body' => ['Press the button below to choose a new password.'],
                'cta' => 'Choose a new password',
                'note' => "The link is valid for 1 hour and can be used once. If you didn't ask for this, ignore this email; your password stays the same.",
            ],
            'ctaHint' => "If the button doesn't work:",
        ],
    ];
}

/** kind: 'verify' | 'reset'; $user: en az email ve lang; $token: ham jeton. Hata olursa siteyi bozmaz, kaydeder. */
function sendAccountMail(string $kind, array $user, string $token): void {
    $T = accountMailTexts();
    $lang = ($user['lang'] ?? 'tr') === 'en' ? 'en' : 'tr';
    $site = site_url(); $name = site_name();
    $link = "$site/?$kind=$token";
    $K = $T[$lang][$kind];
    $subject = $K['subject'];
    $text = implode("\n", [$K['title'], '', ...$K['body'], '', $link, '', $K['note'], '', $name]);
    $html = layout([
        'lang' => $lang, 'site' => $name, 'siteUrl' => $site, 'preheader' => $K['body'][0],
        'kicker' => $K['kicker'], 'title' => $K['title'], 'paragraphs' => $K['body'],
        'cta' => ['href' => $link, 'label' => $K['cta']], 'ctaHint' => $T[$lang]['ctaHint'], 'footer' => [$K['note']],
    ]);
    try { sendMail((string) $user['email'], $subject, $html, $text); }
    catch (Throwable $e) { log_line('Hesap maili gönderilemedi: ' . $e->getMessage()); }
}
