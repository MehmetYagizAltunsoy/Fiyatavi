<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';
/* Ortak listeler, oyun süresi ve yönetim istatistiklerindeki günlük seri */
$GLOBALS['__http_stub'] = fn() => throw new RuntimeException('test: ağ yok');
$GLOBALS['__mail_stub'] = fn() => true;
foreach ([['g1', 'Oyun Bir'], ['g2', 'Oyun İki']] as [$id, $title]) ex('INSERT INTO games (id, title, hidden, play_min) VALUES (?, ?, 0, ?)', [$id, $title, $id === 'g1' ? 750 : null]);

function kayit(string $ad): string {
    $r = call('POST', '/api/auth/register', [], ['email' => "$ad@test.local", 'password' => 'gizli123', 'name' => $ad, 'consent' => true]);
    eq($r['status'], 200); ok($r['sid']); return $r['sid'];
}
$ali = kayit('ali'); $veli = kayit('veli'); $ayse = kayit('ayse'); $can = kayit('can'); $dis = kayit('dis');

t('liste kurulur, davetle katılınır, eklenen oyun herkesin istek listesine düşer', function () use ($ali, $veli) {
    $o = call('POST', '/api/lists', [], ['name' => 'Hafta sonu'], $ali);
    eq($o['status'], 200); $id = $o['json']['list']['id']; $token = $o['json']['list']['token'];
    eq(count($o['json']['list']['members']), 1); eq($o['json']['list']['isOwner'], true);

    $on = call('GET', "/api/lists/join/$token");
    eq($on['status'], 200); eq($on['json']['list']['name'], 'Hafta sonu'); eq($on['json']['list']['joined'], false);

    eq(call('POST', "/api/lists/$id/games", [], ['gameId' => 'g1'], $ali)['status'], 200);
    $k = call('POST', "/api/lists/join/$token", [], null, $veli);
    eq($k['status'], 200); eq(count($k['json']['list']['members']), 2);
    eq(call('GET', '/api/wishlist', [], null, $veli)['json']['ids'], ['g1'], 'katılan, listedeki oyunu istek listesinde bulur');

    call('POST', "/api/lists/$id/games", [], ['gameId' => 'g2'], $veli);
    $ids = call('GET', '/api/wishlist', [], null, $ali)['json']['ids']; sort($ids);
    eq($ids, ['g1', 'g2'], 'veli\'nin eklediği oyun ali\'ye de düşer');
    eq(count(call('GET', "/api/lists/$id", [], null, $ali)['json']['list']['games']), 2);

    call('DELETE', "/api/lists/$id/games/g2", [], null, $ali);
    eq(count(call('GET', "/api/lists/$id", [], null, $veli)['json']['list']['games']), 1);
    eq(count(call('GET', '/api/lists', [], null, $ali)['json']['lists']), 1);
});
t('yabancı listeye erişilemez, sahibi çıkınca devredilir, son üye çıkınca silinir', function () use ($ayse, $can, $dis) {
    $l = call('POST', '/api/lists', [], ['name' => 'Ortak'], $ayse)['json']['list']; $id = $l['id'];
    call('POST', '/api/lists/join/' . $l['token'], [], null, $can);
    eq(call('GET', "/api/lists/$id", [], null, $dis)['status'], 404);
    eq(call('POST', "/api/lists/$id/games", [], ['gameId' => 'g1'], $dis)['status'], 404);
    eq(call('DELETE', "/api/lists/$id", [], null, $can)['status'], 403, 'sahibi olmayan silemez');
    call('POST', "/api/lists/$id/leave", [], null, $ayse);
    eq(call('GET', "/api/lists/$id", [], null, $can)['json']['list']['owner'], 'can', 'ayrılan sahibin yerine en eski üye geçer');
    call('POST', "/api/lists/$id/leave", [], null, $can);
    eq((int) qv('SELECT COUNT(*) FROM lists WHERE id = ?', [$id]), 0);
});
t('sınırlar: boş ad, geçersiz kod, giriş şartı, tekrar katılım, 5 liste sınırı', function () use ($dis) {
    eq(call('POST', '/api/lists', [], ['name' => '  '], $dis)['status'], 400);
    eq(call('GET', '/api/lists/join/yanlis')['status'], 404);
    eq(call('GET', '/api/lists')['status'], 401);
    $l = call('POST', '/api/lists', [], ['name' => 'Bir'], $dis)['json']['list'];
    eq(count(call('POST', '/api/lists/join/' . $l['token'], [], null, $dis)['json']['list']['members']), 1, 'kendi listesine tekrar katılınca çoğalmaz');
    for ($i = 0; $i < 4; $i++) eq(call('POST', '/api/lists', [], ['name' => "L$i"], $dis)['status'], 200);
    eq(call('POST', '/api/lists', [], ['name' => 'Fazla'], $dis)['json']['code'], 'listLimit');
});
t('hesap silinince listeden çıkılır, liste boşalırsa silinir', function () {
    $x = kayit('silinecek');
    $l = call('POST', '/api/lists', [], ['name' => 'Geçici'], $x)['json']['list'];
    eq(call('DELETE', '/api/me', [], ['password' => 'gizli123'], $x)['status'], 200);
    eq((int) qv('SELECT COUNT(*) FROM lists WHERE id = ?', [$l['id']]), 0);
});
t('oyun ayrıntısında oyun süresi (dakika) döner, verisi olmayan oyunda boş', function () {
    eq(call('GET', '/api/games/g1')['json']['game']['playMin'], 750);
    eq(call('GET', '/api/games/g2')['json']['game']['playMin'], null);
});
t('yönetim istatistiklerinde son 14 günün günlük serisi var', function () {
    ex("UPDATE users SET email_verified = 1");
    $adm = kayit('admin');
    $s = call('GET', '/api/admin/stats', [], null, $adm);
    eq($s['status'], 200); eq(count($s['json']['daily']), 14);
    ok(end($s['json']['daily'])['users'] >= 5, 'bugünün üye sayısı artmış olmalı');
});
