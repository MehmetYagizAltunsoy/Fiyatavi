<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';
/* API testleri: sahte istekle handle_request() çağrılır; ağ ve mail yakalanır. */
$GLOBALS['__http_stub'] = fn() => throw new RuntimeException('test: ağ yok');
$mails = [];
$GLOBALS['__mail_stub'] = function ($to, $subject, $html, $text = '') use (&$mails) { $mails[] = compact('to', 'subject', 'html', 'text'); return true; };

// örnek veri
$games = [['g1', 'elden-ring', 'Elden Ring', 1, 245], ['g2', 'hades', 'Hades', 2, 100], ['g3', 'celeste', 'Celeste', 3, 50], ['g4', 'a-100%_oyun', 'A 100%_Oyun', null, 10]];
foreach ($games as [$id, $slug, $title, $rank, $price]) {
    ex("INSERT INTO games (id, slug, title, popular_rank, tags, last_priced, appid, rating_pos, rating_neg) VALUES (?, ?, ?, ?, '[\"RPG\",\"Indie\",\"Açık dünya\"]', datetime('now'), ?, 90, 10)", [$id, $slug, $title, $rank, 1000 + (int) $price]);
    ex("INSERT INTO game_tags (game_id, tag) VALUES (?, 'RPG')", [$id]);
    ex("INSERT INTO prices (game_id, country, shop_id, shop_name, price, regular, currency, cut, usd, url) VALUES (?, 'TR', '61', 'Steam', ?, ?, 'TRY', 0, ?, 'https://x')", [$id, $price, $price * 2, $price * .024]);
    ex("INSERT INTO prices (game_id, country, shop_id, shop_name, price, regular, currency, cut, usd, url) VALUES (?, 'TR', '16', 'Epic', ?, ?, 'TRY', 50, ?, 'https://y')", [$id, $price + 20, $price * 2, $price * .024]);
    ex("INSERT INTO lows (game_id, country, shop, price, regular, currency, cut, usd, low_all) VALUES (?, 'TR', 'Steam', ?, ?, 'TRY', 50, ?, ?)", [$id, $price, $price * 2, $price * .024, $price]);
}

t('GET / sayfa döner, paylaşım etiketleri yerleşir', function () {
    $r = call('GET', '/');
    eq($r['status'], 200); ok(str_contains($r['raw'], 'og:title')); ok(str_contains($r['raw'], 'http://test.local/og.png'));
});
t('/oyun/<slug> o oyunun adını ve fiyatını önizlemeye koyar', function () {
    $r = call('GET', '/oyun/elden-ring');
    ok(str_contains($r['raw'], 'Elden Ring — Fiyatavı')); ok(str_contains($r['raw'], '₺245,00')); ok(str_contains($r['raw'], '/oyun/elden-ring'));
});
t('bilinmeyen sayfa 404 durumuyla siteyi, API 404 JSON döner', function () {
    $r = call('GET', '/yok/boyle/bir/sayfa'); eq($r['status'], 404); ok(str_contains($r['raw'], '<html') || str_contains($r['raw'], '<!doctype'));
    $a = call('GET', '/api/yok'); eq($a['status'], 404); eq($a['json'], ['code' => 'notfound']);
});
t('robots.txt ve sitemap.xml', function () {
    ok(str_contains(call('GET', '/robots.txt')['raw'], 'Sitemap: http://test.local/sitemap.xml'));
    $s = call('GET', '/sitemap.xml')['raw']; ok(str_contains($s, '<loc>http://test.local/oyun/hades</loc>'));
});
t('sağlık kontrolü', fn() => eq(call('GET', '/api/health')['json']['ok'], true));
t('oyun listesi: toplam, sayfa boyutu ve şekil', function () {
    $r = call('GET', '/api/games')['json'];
    eq($r['total'], 4); eq($r['country'], 'TR');
    $g = $r['games'][0]; eq($g['id'], 'g1'); eq($g['low']['price'], 245.0); eq($g['low']['shop'], 'Steam'); eq($g['tags'], ['RPG', 'Indie']); eq($g['pending'], false);
    eq($g['rating'], ['pos' => 90, 'neg' => 10]);
});
t('arama, joker karakterler kaçırılır', function () {
    eq(call('GET', '/api/games', ['q' => 'hades'])['json']['total'], 1);
    eq(call('GET', '/api/games', ['q' => '100%_'])['json']['total'], 1, '% ve _ düz karakter sayılmalı');
    eq(call('GET', '/api/games', ['q' => '%'])['json']['total'], 1, 'tek % sadece % içeren başlığı bulur');
});
t('harf, kategori, mağaza ve sıralama filtreleri', function () {
    eq(call('GET', '/api/games', ['letter' => 'C'])['json']['games'][0]['id'], 'g3');
    eq(call('GET', '/api/games', ['cat' => 'RPG'])['json']['total'], 4);
    eq(call('GET', '/api/games', ['shop' => 'Epic'])['json']['games'][0]['low']['shop'], 'Epic');
    eq(call('GET', '/api/games', ['sort' => 'price_asc'])['json']['games'][0]['id'], 'g4');
    eq(call('GET', '/api/games', ['sort' => 'az'])['json']['games'][0]['title'], 'A 100%_Oyun');
    eq(call('GET', '/api/games', ['sort' => 'DROP TABLE'])['json']['total'], 4, 'bilinmeyen sıralama güvenli');
});
t('geçersiz bölge 400 döner', fn() => eq(call('GET', '/api/games', ['country' => 'US'])['status'], 400));
t('oyun sayfası: kimlikle ve kısa adla açılır, fiyatlar sıralı gelir', function () {
    $r = call('GET', '/api/games/elden-ring'); eq($r['status'], 200);
    $g = $r['json']['game']; eq($g['id'], 'g1'); eq($g['prices'][0]['shop'], 'Steam'); eq(count($g['prices']), 2);
    eq($g['requirements']['none'], true); ok(array_key_exists('shots', $g));
    eq(call('GET', '/api/games/g2')['json']['game']['slug'], 'hades');
    eq(call('GET', '/api/games/olmayan')['status'], 404);
});
t('vitrin, raflar, ücretsiz, biten indirimler, benzer oyunlar, kategoriler çalışır', function () {
    foreach (['/api/deals', '/api/free', '/api/ending', '/api/shelves', '/api/shops', '/api/categories', '/api/showcase', '/api/covers', '/api/config', '/api/games/g1/similar'] as $p) {
        $r = call('GET', $p); eq($r['status'], 200, $p); ok(is_array($r['json']), "$p JSON");
    }
    eq(count(call('GET', '/api/games/g1/similar')['json']['games']), 3);
    eq(call('GET', '/api/config')['json']['stats']['games'], 4);
});
t('üyelik: kayıt şartları', function () {
    eq(call('POST', '/api/auth/register', [], ['email' => '', 'password' => ''])['json']['code'], 'fill');
    eq(call('POST', '/api/auth/register', [], ['email' => 'kotu', 'password' => '123456', 'consent' => true])['json']['code'], 'email');
    eq(call('POST', '/api/auth/register', [], ['email' => 'a@b.co', 'password' => '123', 'consent' => true])['json']['code'], 'pass');
    eq(call('POST', '/api/auth/register', [], ['email' => 'a@b.co', 'password' => '123456'])['json']['code'], 'consent');
});
$sid = null; $uid = null;
t('üyelik: kayıt, doğrulama maili, oturum, çıkış, giriş', function () use (&$sid, &$uid, &$mails) {
    $r = call('POST', '/api/auth/register', [], ['email' => 'Yagiz@Test.com', 'password' => 'gizli123', 'name' => 'Yağız', 'consent' => true]);
    eq($r['status'], 200); eq($r['json']['user']['email'], 'yagiz@test.com'); eq($r['json']['user']['emailVerified'], false);
    ok($r['sid'] !== null, 'çerez atanmalı'); $sid = $r['sid'];
    ok(count($mails) === 1 && $mails[0]['to'] === 'yagiz@test.com', 'doğrulama maili gitmeli');
    preg_match('/[a-f0-9]{64}/', $mails[0]['html'] . $mails[0]['text'], $m); ok(isset($m[0]), 'mailde bağlantı jetonu');
    eq(call('POST', '/api/auth/verify', [], ['token' => 'x'])['json']['code'], 'token');
    eq(call('POST', '/api/auth/verify', [], ['token' => $m[0]])['json'], ['ok' => true]);
    eq(call('GET', '/api/me', [], null, $sid)['json']['user']['emailVerified'], true);
    eq(call('POST', '/api/auth/register', [], ['email' => 'yagiz@test.com', 'password' => 'gizli123', 'consent' => true])['status'], 409);
    eq(call('POST', '/api/auth/logout', [], [], $sid)['json'], ['ok' => true]);
    eq(call('GET', '/api/me', [], null, $sid)['json']['user'], null);
    eq(call('POST', '/api/auth/login', [], ['email' => 'yagiz@test.com', 'password' => 'yanlis'])['status'], 401);
    $l = call('POST', '/api/auth/login', [], ['email' => 'yagiz@test.com', 'password' => 'gizli123']); eq($l['status'], 200); $sid = $l['sid'];
    $uid = (int) qv("SELECT id FROM users WHERE email = 'yagiz@test.com'");
});
t('giriş yapmadan korumalı adresler 401 döner', function () {
    foreach ([['GET', '/api/wishlist'], ['POST', '/api/wishlist/g1'], ['PATCH', '/api/me'], ['GET', '/api/notifications']] as [$m, $p])
        eq(call($m, $p)['status'], 401, "$m $p");
});
t('eski (scrypt) şifreli hesap girişte yeni biçime geçer', function () {
    ex("INSERT INTO users (email, name, pass_hash, email_verified) VALUES ('eski@test.com', 'Eski', '00112233445566778899aabbccddeeff:2c666deb97ddf4d4880c08e6e96e28e7cea544d09da1295fc0f7232da051c0e4465078c4e76bd2f98a154415a4a2a149b4754afd79e4daac912eac8e6ca36d28', 1)");
    eq(call('POST', '/api/auth/login', [], ['email' => 'eski@test.com', 'password' => 'gizli123'])['status'], 200);
    ok(str_starts_with((string) qv("SELECT pass_hash FROM users WHERE email = 'eski@test.com'"), '$'), 'yeniden özetlenmeli');
});
t('ayarlar: ad, bildirim, minimum indirim', function () use (&$sid) {
    $r = call('PATCH', '/api/me', [], ['name' => 'Yeni Ad', 'notifyDrops' => false, 'minCut' => 50, 'weeklyDigest' => false, 'lang' => 'en'], $sid)['json']['user'];
    eq($r['name'], 'Yeni Ad'); eq($r['notifyDrops'], false); eq($r['minCut'], 50); eq($r['weeklyDigest'], false); eq($r['lang'], 'en');
    eq(call('PATCH', '/api/me', [], ['minCut' => 33], $sid)['json']['user']['minCut'], 50, 'geçersiz değer yok sayılır');
});
t('istek listesi: ekle, hedef fiyat, listele, sil', function () use (&$sid, &$mails) {
    eq(call('POST', '/api/wishlist/yok', [], [], $sid)['status'], 404);
    eq(call('POST', '/api/wishlist/g2', [], [], $sid)['json'], ['ok' => true]);
    eq(call('POST', '/api/wishlist/g2', [], [], $sid)['json'], ['ok' => true], 'ikinci ekleme zararsız');
    eq(call('PATCH', '/api/wishlist/g2', [], ['target' => -5, 'currency' => 'TRY'], $sid)['json']['code'], 'target');
    eq(call('PATCH', '/api/wishlist/g2', [], ['target' => 50, 'currency' => 'TRY'], $sid)['json']['already'], false);
    eq(call('PATCH', '/api/wishlist/g2', [], ['target' => 500, 'currency' => 'TRY'], $sid)['json']['already'], true, 'şu an hedefin altında');
    $w = call('GET', '/api/wishlist', [], null, $sid)['json'];
    eq($w['ids'], ['g2']); eq($w['targets']['g2'], ['price' => 500.0, 'currency' => 'TRY']);
    eq(call('PATCH', '/api/wishlist/g2', [], ['target' => null], $sid)['json'], ['ok' => true]);
    eq(call('GET', '/api/wishlist', [], null, $sid)['json']['targets'], []);
    eq(call('DELETE', '/api/wishlist/g2', [], null, $sid)['json'], ['ok' => true]);
    eq(call('GET', '/api/wishlist', [], null, $sid)['json']['ids'], []);
});
t('şifre değiştirme: eski şifre doğrulanır, diğer oturumlar kapanır', function () use (&$sid) {
    eq(call('POST', '/api/me/password', [], ['current' => 'yanlis', 'password' => 'yeniSifre1'], $sid)['status'], 401);
    $r = call('POST', '/api/me/password', [], ['current' => 'gizli123', 'password' => 'yeniSifre1'], $sid);
    eq($r['status'], 200); ok($r['sid'] && $r['sid'] !== $sid, 'yeni oturum');
    eq(call('GET', '/api/me', [], null, $sid)['json']['user'], null, 'eski oturum kapanmalı');
    $sid = $r['sid'];
    eq(call('POST', '/api/auth/login', [], ['email' => 'yagiz@test.com', 'password' => 'yeniSifre1'])['status'], 200);
});
t('şifremi unuttum: hesap olsa da olmasa da aynı cevap; sıfırlama bağlantısı tek kullanımlık', function () use (&$mails) {
    $n = count($mails);
    eq(call('POST', '/api/auth/forgot', [], ['email' => 'yok@yok.com'])['json'], ['ok' => true]); eq(count($mails), $n, 'olmayan hesaba mail gitmez');
    eq(call('POST', '/api/auth/forgot', [], ['email' => 'yagiz@test.com'])['json'], ['ok' => true]); eq(count($mails), $n + 1);
    preg_match('/[a-f0-9]{64}/', end($mails)['html'] . end($mails)['text'], $m);
    eq(call('POST', '/api/auth/reset', [], ['token' => $m[0], 'password' => '12'])['json']['code'], 'pass');
    $r = call('POST', '/api/auth/reset', [], ['token' => $m[0], 'password' => 'sifirlandi1']); eq($r['status'], 200);
    eq(call('POST', '/api/auth/reset', [], ['token' => $m[0], 'password' => 'sifirlandi1'])['json']['code'], 'token');
});
t('yönetim sayfası: yönetici değilse 403', function () {
    eq(call('GET', '/api/admin/stats')['status'], 401);
    $s = call('POST', '/api/auth/login', [], ['email' => 'yagiz@test.com', 'password' => 'sifirlandi1'])['sid'];
    eq(call('GET', '/api/admin/stats', [], null, $s)['status'], 403);
});
t('yönetim sayfası: ADMIN_EMAILS’teki hesap görür', function () {
    $r = call('POST', '/api/auth/register', [], ['email' => 'admin@test.local', 'password' => 'adminpass1', 'consent' => true]);
    $s = call('GET', '/api/admin/stats', [], null, $r['sid']); eq($s['status'], 200);
    ok($s['json']['users']['total'] >= 3); ok(str_starts_with($s['json']['system']['node'], 'PHP')); ok(str_contains($s['json']['recentUsers'][0]['email'], '*'));
});
t('hesap silme: şifre ister, her şeyi siler', function () use (&$sid, &$uid) {
    $r = call('POST', '/api/auth/login', [], ['email' => 'yagiz@test.com', 'password' => 'sifirlandi1']); $s = $r['sid'];
    eq(call('DELETE', '/api/me', [], ['password' => 'yanlis'], $s)['status'], 401);
    eq(call('DELETE', '/api/me', [], ['password' => 'sifirlandi1'], $s)['json'], ['ok' => true]);
    eq((int) qv('SELECT COUNT(*) FROM users WHERE id = ?', [$uid]), 0);
    eq((int) qv('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$uid]), 0);
});
t('kaba kuvvet: aynı hesaba çok yanlış giriş 429 döner', function () {
    $last = 0; for ($i = 0; $i < 12; $i++) $last = call('POST', '/api/auth/login', [], ['email' => 'hedef@test.com', 'password' => 'x'])['status'];
    eq($last, 429);
});
