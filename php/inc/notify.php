<?php
declare(strict_types=1);
/* Fiyat bildirimleri: istek listesine eklendi, fiyat düştü, hedef fiyata indi, haftalık özet.
   Cron turunda çağrılır (checkPriceDrops her fiyat güncellemesinden sonra, sendWeeklyDigests her turda). */

/** Para birimiyle biçimler: tr → ₺1.733,81, en → $1,733.81 */
function money($v, string $cur, string $lang = 'tr'): string {
    static $fmt = [];
    $locale = $lang === 'en' ? 'en_US' : 'tr_TR';
    if (class_exists('NumberFormatter')) {
        $fmt[$locale] ??= new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $s = $fmt[$locale]->formatCurrency((float) $v, $cur);
        if ($s !== false) return $s;
    }
    $sym = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥'][$cur] ?? null;
    $num = $lang === 'en' ? number_format((float) $v, 2, '.', ',') : number_format((float) $v, 2, ',', '.');
    return $sym !== null ? $sym . $num : "$num $cur";
}

/** JS'teki encodeURIComponent */
function notifyUriComponent(string $s): string {
    return strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
}
function gameUrl(array $g): string { return site_url() . '/oyun/' . notifyUriComponent((string) (!empty($g['slug']) ? $g['slug'] : $g['id'])); }

// Her bildirim türünün yazıları. $d = ['g' => oyun adı, 'p' => şimdiki fiyat, 'old' => önceki fiyat, 's' => mağaza, 't' => hedef fiyat]
function notifyTexts(): array {
    return [
        'tr' => [
            'added' => [
                'subject' => fn($d) => "{$d['g']} istek listene eklendi",
                'kicker' => 'İstek listesi', 'title' => fn($d) => "{$d['g']} istek listende",
                'body' => fn($d) => !empty($d['p'])
                    ? ['Bu oyunu takip etmeye başladık. Fiyatı düştüğünde sana hemen haber vereceğiz.']
                    : ['Bu oyunu takip etmeye başladık. Türkiye mağazalarında fiyatı çıktığında ve fiyat düştüğünde sana haber vereceğiz.'],
                'priceLabel' => 'Şu an en ucuz',
            ],
            'drop' => [
                'subject' => fn($d) => "{$d['g']} ucuzladı: {$d['p']}",
                'kicker' => 'Fiyat düştü', 'title' => fn($d) => "{$d['g']} ucuzladı",
                'body' => fn($d) => ['İstek listendeki oyunun fiyatı düştü.'],
                'priceLabel' => 'Yeni fiyat', 'extra' => fn($d) => "Önceki fiyat: {$d['old']}",
            ],
            'target' => [
                'subject' => fn($d) => "{$d['g']} hedef fiyatına indi: {$d['p']}",
                'kicker' => 'Hedef fiyat', 'title' => fn($d) => "{$d['g']} hedef fiyatına indi",
                'body' => fn($d) => ['Belirlediğin hedef fiyata ulaşıldı. Bu fiyat ne kadar sürer bilinmez, kaçırma.'],
                'priceLabel' => 'Şu an', 'extra' => fn($d) => "Hedefin: {$d['t']}",
            ],
            'cta' => 'Fiyatları gör', 'ctaHint' => 'Düğme çalışmazsa:',
            'footer' => ["Bu maili Fiyatavı'da istek listene eklediğin bir oyun için aldın.",
                'Fiyat bildirimlerini profilindeki Bildirimler sekmesinden kapatabilirsin.'],
        ],
        'en' => [
            'added' => [
                'subject' => fn($d) => "{$d['g']} added to your wishlist",
                'kicker' => 'Wishlist', 'title' => fn($d) => "{$d['g']} is on your wishlist",
                'body' => fn($d) => !empty($d['p'])
                    ? ["We're now tracking this game and will email you as soon as the price drops."]
                    : ["We're now tracking this game and will email you once it's priced in Turkish stores and when it drops."],
                'priceLabel' => 'Cheapest right now',
            ],
            'drop' => [
                'subject' => fn($d) => "{$d['g']} price drop: {$d['p']}",
                'kicker' => 'Price drop', 'title' => fn($d) => "{$d['g']} got cheaper",
                'body' => fn($d) => ['A game on your wishlist just dropped in price.'],
                'priceLabel' => 'New price', 'extra' => fn($d) => "Before: {$d['old']}",
            ],
            'target' => [
                'subject' => fn($d) => "{$d['g']} hit your target price: {$d['p']}",
                'kicker' => 'Target price', 'title' => fn($d) => "{$d['g']} hit your target price",
                'body' => fn($d) => ["The price reached the target you set. Deals like this don't always last."],
                'priceLabel' => 'Now', 'extra' => fn($d) => "Your target: {$d['t']}",
            ],
            'cta' => 'See prices', 'ctaHint' => "If the button doesn't work:",
            'footer' => ['You got this email because this game is on your Fiyatavı wishlist.',
                'You can turn price alerts off in the Notifications tab of your profile.'],
        ],
    ];
}

/** Bir bildirim mailinin konu, düz metin ve HTML hallerini üretir. @return array{subject:string,text:string,html:string} */
function buildMail(string $kind, string $lang, array $game, ?array $lo, array $d): array {
    $T = notifyTexts(); $L = $T[$lang]; $K = $L[$kind];
    $url = gameUrl($game); $name = site_name(); $site = site_url();
    $subject = $K['subject']($d);
    $extra = isset($K['extra']) ? $K['extra']($d) : null;
    $priceLine = $lo ? "{$K['priceLabel']}: {$d['p']} ({$d['s']})" : null;
    $body = $K['body']($d);
    $lines = array_filter([$K['title']($d), '', ...$body, $priceLine, $extra, '', "{$L['cta']}: $url", '', $name], fn($x) => $x !== null);
    $text = implode("\n", $lines);
    $cut = $lo['cut'] ?? 0;
    $html = layout([
        'lang' => $lang, 'site' => $name, 'siteUrl' => $site, 'preheader' => $priceLine ?: $body[0],
        'image' => gameImage($game), 'imageAlt' => $game['title'] ?? '',
        'kicker' => $K['kicker'], 'title' => $K['title']($d), 'paragraphs' => $body,
        'price' => $lo ? [
            'label' => $K['priceLabel'], 'now' => $d['p'],
            'old' => ($cut > 0 && $lo['regular'] > $lo['price']) ? money($lo['regular'], $lo['currency'], $lang) : null,
            'cut' => $cut > 0 ? $cut : null, 'shop' => $lo['shop'], 'extra' => $extra,
        ] : null,
        'cta' => ['href' => $url, 'label' => $L['cta']], 'ctaHint' => $L['ctaHint'], 'footer' => $L['footer'],
    ]);
    return ['subject' => $subject, 'text' => $text, 'html' => $html];
}

function notifyLow(string $gameId, string $country): ?array {
    return q1('SELECT shop, price, regular, currency, cut FROM lows WHERE game_id = ? AND country = ?', [$gameId, $country]);
}
function notifySetLast(?float $price, $userId, string $gameId): void { ex('UPDATE wishlist SET last_price = ? WHERE user_id = ? AND game_id = ?', [$price, $userId, $gameId]); }
function notifySetHit(int $hit, $userId, string $gameId): void { ex('UPDATE wishlist SET target_hit = ? WHERE user_id = ? AND game_id = ?', [$hit, $userId, $gameId]); }
function notifyLog($userId, ?string $gameId, string $kind, string $text): void {
    ex('INSERT INTO notifications (user_id, game_id, kind, text) VALUES (?, ?, ?, ?)', [$userId, $gameId, $kind, $text]);
}

/** $user: id, email, verified (bool) */
function notifyDeliver(array $user, ?string $gameId, string $kind, array $mail): void {
    if (empty($user['verified'])) return;   // doğrulanmamış adreslere mail gönderilmez
    notifyLog($user['id'], $gameId, $kind, $mail['subject']);
    try { sendMail((string) $user['email'], $mail['subject'], $mail['html'], $mail['text']); }
    catch (Throwable $e) { log_line('Mail gönderilemedi: ' . $e->getMessage()); }
}

// İstek listesine eklenince
function notifyAdded(array $user, array $game): void {
    $lang = ($user['lang'] ?? 'tr') === 'en' ? 'en' : 'tr';
    $g = q1('SELECT id, slug, title, appid, boxart FROM games WHERE id = ?', [$game['id']]) ?? $game;
    $lo = notifyLow((string) $game['id'], (string) ($user['country'] ?? 'TR'));
    notifySetLast($lo ? (float) $lo['price'] : null, $user['id'], (string) $game['id']);
    $d = ['g' => $g['title'], 'p' => $lo ? money($lo['price'], $lo['currency'], $lang) : null, 's' => $lo['shop'] ?? null];
    notifyDeliver(['id' => $user['id'], 'email' => $user['email'], 'verified' => !empty($user['email_verified'])],
        (string) $game['id'], 'added', buildMail('added', $lang, $g, $lo, $d));
}

// Her fiyat güncellemesinden sonra: istek listesindeki oyunlar ucuzladı mı?
// Süre bütçesi biterse kalanı sonraki tura bırakır (last_price güncellenmemiş satırlar bir sonraki turda yakalanır).
function checkPriceDrops(): int {
    $rows = q('SELECT w.user_id, w.game_id, w.last_price, w.target_price, w.target_currency, w.target_hit,
        u.email, u.email_verified, u.country, u.lang, u.notify_drops, u.min_cut, g.title, g.slug, g.appid, g.boxart
      FROM wishlist w JOIN users u ON u.id = w.user_id JOIN games g ON g.id = w.game_id');
    $sent = 0;
    foreach ($rows as $r) {
        if (!budget_ok(1.0)) break;
        $lo = notifyLow((string) $r['game_id'], (string) $r['country']);
        if (!$lo) continue;
        $price = (float) $lo['price'];
        $lang = $r['lang'] === 'en' ? 'en' : 'tr';
        $who = ['id' => $r['user_id'], 'email' => $r['email'], 'verified' => !empty($r['email_verified'])];
        $game = ['id' => $r['game_id'], 'slug' => $r['slug'], 'title' => $r['title'], 'appid' => $r['appid'], 'boxart' => $r['boxart']];
        $last = $r['last_price'] === null ? null : (float) $r['last_price'];
        // hedef fiyat alarmı: hedef konmuşsa genel düşüş maili yerine sadece hedefe inince haber ver
        if ($r['target_price'] !== null) {
            $target = (float) $r['target_price'];
            if ($lo['currency'] === $r['target_currency']) {
                if ($price <= $target + 1e-9 && !$r['target_hit']) {
                    $d = ['g' => $r['title'], 'p' => money($price, $lo['currency'], $lang), 's' => $lo['shop'], 't' => money($target, $lo['currency'], $lang)];
                    notifyDeliver($who, (string) $r['game_id'], 'target', buildMail('target', $lang, $game, $lo, $d));
                    notifySetHit(1, $r['user_id'], (string) $r['game_id']); $sent++;
                } elseif ($price > $target && $r['target_hit']) notifySetHit(0, $r['user_id'], (string) $r['game_id']);
            }
            if ($last === null || $price !== $last) notifySetLast($price, $r['user_id'], (string) $r['game_id']);
            continue;
        }
        if ($last !== null && $price === $last) continue;
        if ($last !== null && $price < $last - 0.005 && $r['notify_drops'] && (int) ($lo['cut'] ?? 0) >= (int) ($r['min_cut'] ?? 0)) {
            $d = ['g' => $r['title'], 'p' => money($price, $lo['currency'], $lang), 's' => $lo['shop'], 'old' => money($last, $lo['currency'], $lang)];
            notifyDeliver($who, (string) $r['game_id'], 'drop', buildMail('drop', $lang, $game, $lo, $d));
            $sent++;
        }
        notifySetLast($price, $r['user_id'], (string) $r['game_id']);   // fiyat yükseldiyse de yeni taban olarak kaydet
    }
    if ($sent) log_line("$sent fiyat düşüşü bildirimi gönderildi");
    return $sent;
}

/* ---------- Haftalık özet ---------- */
function digestTexts(): array {
    return [
        'tr' => [
            'subject' => fn($n, $m) => $m ? "Haftalık özet: istek listende $m oyun indirimde" : "Haftalık özet: istek listendeki $n oyun",
            'kicker' => 'Haftalık özet', 'title' => 'İstek listende bu hafta',
            'lead' => fn($n, $m) => $m ? "Takip ettiğin $n oyunun $m tanesi şu an indirimde." : "Takip ettiğin $n oyunun şu anki fiyatları aşağıda.",
            'down' => fn($p) => "↓ Geçen haftaya göre $p ucuz", 'up' => fn($p) => "↑ Geçen haftaya göre $p pahalı", 'same' => 'Geçen haftayla aynı',
            'noPrice' => 'Türkiye mağazalarında fiyat yok', 'atl' => 'En düşük fiyat', 'cta' => 'İstek listemi aç',
            'footer' => ['Bu özet haftada bir gönderilir.', 'Profilindeki Bildirimler sekmesinden kapatabilirsin.'],
        ],
        'en' => [
            'subject' => fn($n, $m) => $m ? "Weekly digest: $m games on your wishlist are on sale" : "Weekly digest: $n games on your wishlist",
            'kicker' => 'Weekly digest', 'title' => 'Your wishlist this week',
            'lead' => fn($n, $m) => $m ? "$m of the $n games you track are on sale right now." : "Here are the current prices of the $n games you track.",
            'down' => fn($p) => "↓ $p cheaper than last week", 'up' => fn($p) => "↑ $p more than last week", 'same' => 'Same as last week',
            'noPrice' => 'No price in Turkish stores', 'atl' => 'All-time low', 'cta' => 'Open my wishlist',
            'footer' => ['This digest is sent once a week.', 'You can turn it off in the Notifications tab of your profile.'],
        ],
    ];
}

// Bir kullanıcının özet mailini hazırlar (gönderimden ayrı, böylece test edilebilir)
function buildDigest(array $user): array {
    $lang = ($user['lang'] ?? 'tr') === 'en' ? 'en' : 'tr';
    $L = digestTexts()[$lang]; $hint = notifyTexts()[$lang]['ctaHint'];
    $name = site_name(); $site = site_url();
    $games = q('SELECT g.id, g.slug, g.title, g.appid, g.boxart, l.price, l.regular, l.currency, l.cut, l.low_all, l.shop
        FROM wishlist w JOIN games g ON g.id = w.game_id LEFT JOIN lows l ON l.game_id = g.id AND l.country = ?
        WHERE w.user_id = ? ORDER BY COALESCE(l.cut, 0) DESC, g.title LIMIT 20', [$user['country'], $user['id']]);
    $onSale = count(array_filter($games, fn($g) => (int) ($g['cut'] ?? 0) > 0));
    $items = [];
    foreach ($games as $g) {
        $it = ['title' => $g['title'], 'url' => gameUrl($g), 'image' => gameImage($g), 'noPrice' => $L['noPrice']];
        if ($g['price'] !== null) {
            $it['price'] = money($g['price'], $g['currency'], $lang);
            $it['cut'] = (int) $g['cut'] > 0 ? (int) $g['cut'] : null;
            $it['atl'] = ($g['low_all'] !== null && $g['price'] <= $g['low_all'] + 0.005) ? $L['atl'] : null;
            $old = q1("SELECT lowest, currency FROM price_history WHERE game_id = ? AND country = ? AND day <= date('now', '-7 days') ORDER BY day DESC LIMIT 1", [$g['id'], $user['country']]);
            if ($old && $old['currency'] === $g['currency']) {
                $diff = (float) $g['price'] - (float) $old['lowest'];
                if ($diff < -0.005) { $it['change'] = $L['down'](money(-$diff, $g['currency'], $lang)); $it['changeColor'] = 'down'; }
                elseif ($diff > 0.005) { $it['change'] = $L['up'](money($diff, $g['currency'], $lang)); $it['changeColor'] = 'up'; }
                else $it['change'] = $L['same'];
            }
        }
        $items[] = $it;
    }
    $n = count($games);
    $subject = $L['subject']($n, $onSale);
    $rows = array_map(fn($it) => "- {$it['title']}: " . ($it['price'] ?? $it['noPrice']) . (!empty($it['cut']) ? " (-%{$it['cut']})" : '') . (!empty($it['change']) ? " · {$it['change']}" : ''), $items);
    $text = implode("\n", [$L['title'], '', $L['lead']($n, $onSale), '', ...$rows, '', "{$L['cta']}: $site", '', $name]);
    $html = layout([
        'lang' => $lang, 'site' => $name, 'siteUrl' => $site, 'preheader' => $L['lead']($n, $onSale),
        'kicker' => $L['kicker'], 'title' => $L['title'], 'paragraphs' => [$L['lead']($n, $onSale)],
        'blocks' => gameRows($items), 'cta' => ['href' => $site, 'label' => $L['cta']], 'ctaHint' => $hint, 'footer' => $L['footer'],
    ]);
    return ['subject' => $subject, 'text' => $text, 'html' => $html, 'count' => $n];
}

/**
 * Haftalık özetleri gönderir. Her turda çağrılır; her kullanıcıya en fazla 7 günde bir gider.
 * İlk kez sırası gelen kullanıcıya hemen gönderilmez, sayaç başlatılır (ilk açılışta herkese birden mail gitmesin).
 * opts: force (süreye bakmadan gönder; yönetim sayfasındaki deneme düğmesi), onlyUserId (sadece bu kullanıcıya)
 */
function sendWeeklyDigests(array $opts = []): int {
    $force = !empty($opts['force']);
    $only = $opts['onlyUserId'] ?? null;
    $sent = 0;
    $users = q('SELECT id, email, name, country, lang, last_digest_at FROM users
        WHERE email_verified = 1 AND weekly_digest = 1 AND EXISTS (SELECT 1 FROM wishlist w WHERE w.user_id = users.id)');
    foreach ($users as $u) {
        if ($only !== null && (int) $u['id'] !== (int) $only) continue;
        if (!budget_ok(1.0)) break;
        if (!$force) {
            if (!$u['last_digest_at']) { ex("UPDATE users SET last_digest_at = datetime('now') WHERE id = ?", [$u['id']]); continue; }
            if (time() - (int) strtotime($u['last_digest_at'] . ' UTC') < 7 * 86400) continue;
        }
        $mail = buildDigest($u);
        if (!$mail['count']) continue;
        notifyLog($u['id'], null, 'digest', $mail['subject']);
        ex("UPDATE users SET last_digest_at = datetime('now') WHERE id = ?", [$u['id']]);
        try { if (sendMail((string) $u['email'], $mail['subject'], $mail['html'], $mail['text'])) $sent++; }
        catch (Throwable $e) { log_line('Haftalık özet gönderilemedi: ' . $e->getMessage()); }
    }
    if ($sent) log_line("$sent haftalık özet maili gönderildi");
    return $sent;
}
