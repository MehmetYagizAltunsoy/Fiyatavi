<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';
/* Üyelik ve güvenlik testleri (Node'daki guvenlik.test.js'in karşılığı) */
function add_user(string $email): int { ex("INSERT INTO users (email, name, pass_hash) VALUES (?, 'Test', 'x')", [$email]); return last_id(); }

t('şifre düz metin saklanmaz, doğru şifre kabul edilir', function () {
    $h = hashPassword('gizli123');
    ok(!str_contains($h, 'gizli123'));
    ok(verifyPassword('gizli123', $h)); ok(!verifyPassword('yanlis123', $h));
});
t('aynı şifre iki kez özetlenince farklı sonuç çıkar (tuz)', fn() => ok(hashPassword('ayni') !== hashPassword('ayni')));
t('Node sürümünden gelen eski scrypt şifreleri de doğrulanır', function () {
    // Node: crypto.scryptSync("gizli123", salt, 64) ile üretilmiş "tuz:özet"
    $stored = '00112233445566778899aabbccddeeff:2c666deb97ddf4d4880c08e6e96e28e7cea544d09da1295fc0f7232da051c0e4465078c4e76bd2f98a154415a4a2a149b4754afd79e4daac912eac8e6ca36d28';
    ok(verifyPassword('gizli123', $stored), 'doğru şifre');
    ok(!verifyPassword('gizli124', $stored), 'yanlış şifre');
    ok(passwordNeedsRehash($stored), 'eski biçim yeniden özetlenmeli');
    ok(!passwordNeedsRehash(hashPassword('x')), 'yeni biçim güncel');
});
t('oturum çerezi kullanıcıyı tanır, oturum silinince tanımaz', function () {
    $id = add_user('oturum@test.com'); $tok = createSession($id);
    eq(userFromSid(sidFromCookieHeader("a=b; sid=$tok"))['email'], 'oturum@test.com');
    destroySession($tok);
    eq(userFromSid($tok), null);
});
t('çerez güvenlik ayarları: HttpOnly ve SameSite', function () {
    $c = sessionCookie(str_repeat('a', 64));
    ok(str_contains($c, 'HttpOnly') && str_contains($c, 'SameSite=Lax'));
});
t('geçersiz biçimdeki çerez reddedilir', fn() => eq(sidFromCookieHeader('sid=../../etc'), null));
t('e-posta bağlantısı tek kullanımlıktır ve DB’de yalnızca özeti tutulur', function () {
    $id = add_user('baglanti@test.com'); $kod = createEmailToken($id, 'reset', 1);
    ok(qv('SELECT token_hash FROM email_tokens WHERE user_id = ?', [$id]) !== $kod);
    eq(consumeEmailToken($kod, 'reset'), $id);
    eq(consumeEmailToken($kod, 'reset'), null, 'ikinci kullanım');
});
t('yanlış türdeki bağlantı kabul edilmez', function () {
    $id = add_user('tur@test.com'); $kod = createEmailToken($id, 'verify', 48);
    eq(consumeEmailToken($kod, 'reset'), null);
});
t('15 dakikada 10’dan fazla deneme engellenir', function () {
    for ($i = 1; $i <= 10; $i++) eq(tooManyAttempts('giris:1.2.3.4'), false, "$i. deneme");
    eq(tooManyAttempts('giris:1.2.3.4'), true, '11. deneme');
});
t('kullanıcı silinince oturumları da silinir (CASCADE)', function () {
    $id = add_user('sil@test.com'); createSession($id);
    ex('DELETE FROM users WHERE id = ?', [$id]);
    eq((int) qv('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]), 0);
});
t('eski bölge kayıtları bir kez temizlenir, Türkiye ve kullanıcılar korunur', function () {
    ex("INSERT INTO games (id, title) VALUES ('g1', 'Oyun')");
    foreach ([['TR', 500, 'TRY'], ['US', 20, 'USD'], ['DE', 18, 'EUR']] as [$c, $p, $cur])
        ex("INSERT INTO prices (game_id, country, shop_id, shop_name, price, regular, currency, cut) VALUES ('g1', ?, '61', 'Steam', ?, ?, ?, 0)", [$c, $p, $p, $cur]);
    ex("INSERT INTO price_history (game_id, country, day, lowest, currency) VALUES ('g1', 'US', '2026-01-01', 20, 'USD')");
    ex("INSERT INTO users (email, name, pass_hash, country) VALUES ('a@test.com', 'A', 'x', 'US')");
    ex("DELETE FROM meta WHERE key = 'only_tr'");
    migrate(db());   // sunucunun yeniden açılması
    eq(array_column(q('SELECT DISTINCT country AS c FROM prices'), 'c'), ['TR']);
    eq((int) qv('SELECT COUNT(*) FROM price_history'), 0);
    eq(qv("SELECT country FROM users WHERE email = 'a@test.com'"), 'TR');
    ok(qv("SELECT 1 FROM meta WHERE key = 'only_tr'") !== null);
});
