<?php
declare(strict_types=1);
/* HTTP katmanı: Node sürümündeki src/server.js'in birebir karşılığı (aynı adresler, aynı JSON). */

/* ---------- İstek / cevap yardımcıları ---------- */
final class HttpExit extends Exception {}

function req(): array {
    if (isset($GLOBALS['__req'])) return $GLOBALS['__req'];
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = rawurldecode(parse_url($uri, PHP_URL_PATH) ?: '/');
    $body = [];
    $raw = $GLOBALS['__raw_body'] ?? file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        if (strlen($raw) > 10240) { json_out(['code' => 'server'], 413); }
        $j = json_decode($raw, true);
        if (is_array($j)) $body = $j;
    }
    return $GLOBALS['__req'] = ['method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'path' => $path, 'query' => $_GET, 'body' => $body];
}
function qs(string $k, string $default = ''): string { $v = $_GET[$k] ?? $default; return is_array($v) ? $default : (string) $v; }
function body(string $k, $default = null) { return req()['body'][$k] ?? $default; }

/** Ziyaretçinin adresi: yalnızca aynı bilgisayardaki aracıdan (Caddy/nginx/tünel) gelen başlığa güvenilir */
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $loop = in_array($ip, ['127.0.0.1', '::1'], true);
    if ($loop || env('TRUST_PROXY') === '1') {
        $f = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($f) { $first = trim(explode(',', $f)[0]); if (filter_var($first, FILTER_VALIDATE_IP)) return $first; }
    }
    return $ip;
}

function json_out($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo jenc($data);
    throw new HttpExit();
}
function fail(string $code, int $status): never { json_out(['code' => $code], $status); }

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https:; media-src 'self' https:; connect-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (is_prod() || $https) header('Strict-Transport-Security: max-age=31536000');
}

/* ---------- Sayfa: paylaşım önizlemesi için başlık/görsel sunucuda doldurulur ---------- */
function index_html(): string {
    static $html = null;
    if ($html !== null) return $html;
    foreach ([ROOT . '/app.html', ROOT . '/../public/index.html'] as $f) if (is_file($f)) return $html = (string) file_get_contents($f);
    return $html = '<!doctype html><title>Fiyatavı</title><p>app.html bulunamadı (php/sync-assets.sh çalıştırın).';
}
function page(array $og = []): string {
    $url = site_url();
    $title = $og['title'] ?? 'Fiyatavı — Türk oyuncular için oyun fiyat karşılaştırma';
    $desc = $og['desc'] ?? 'Steam, Epic, GOG ve diğer mağazalardaki fiyatları TL olarak karşılaştır, en ucuzunu bul, fiyatı düşünce haber al.';
    $img = $og['image'] ?? "$url/og.png";
    $big = empty($og['image']) || !empty($og['wide']);
    $p = $og['path'] ?? '/';
    $tags = "<!--OG-->\n<meta property=\"og:type\" content=\"website\">\n<meta property=\"og:site_name\" content=\"Fiyatavı\">\n<meta property=\"og:locale\" content=\"tr_TR\">\n"
        . '<meta property="og:url" content="' . esc_attr($url . $p) . "\">\n"
        . '<meta property="og:title" content="' . esc_attr($title) . "\">\n"
        . '<meta property="og:description" content="' . esc_attr($desc) . "\">\n"
        . '<meta property="og:image" content="' . esc_attr($img) . "\">\n"
        . ($big ? "<meta property=\"og:image:width\" content=\"1200\">\n<meta property=\"og:image:height\" content=\"630\">\n" : '')
        . '<meta name="twitter:card" content="' . ($big ? 'summary_large_image' : 'summary') . "\">\n"
        . "<meta name=\"theme-color\" content=\"#13152A\">\n"
        . '<link rel="canonical" href="' . esc_attr($url . $p) . "\">\n<!--/OG-->";
    return preg_replace_callback('/<!--OG-->[\s\S]*?<!--\/OG-->/', fn() => $tags, index_html(), 1);
}
function send_page(array $og = [], int $status = 200): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    echo page($og);
    throw new HttpExit();
}
function money_tr($v, string $cur = 'TRY'): string {
    $sym = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€', 'GBP' => '£'][$cur] ?? ($cur . "\u{00A0}");
    return $sym . number_format((float) $v, 2, ',', '.');
}

/* ---------- Oyun listeleri için ortak SQL parçaları ---------- */
const GAME_COLS = 'g.id, g.slug, g.title, g.boxart, g.appid, g.release_date, g.tags, g.popular_rank, g.free, g.rating_pos, g.rating_neg, g.last_priced, g.mature';
const PREV7 = "(SELECT h.lowest FROM price_history h WHERE h.game_id = l.game_id AND h.country = l.country AND h.currency = l.currency
    AND h.day <= date('now', '-7 days') ORDER BY h.day DESC LIMIT 1) AS l_prev7";
const LOW_COLS = 'l.shop AS l_shop, l.price AS l_price, l.regular AS l_regular, l.currency AS l_currency, l.cut AS l_cut, l.expiry AS l_expiry, l.low_m3 AS l_m3, l.low_all AS l_all, ' . PREV7;
const SHOP_COLS = 'l.shop_name AS l_shop, l.price AS l_price, l.regular AS l_regular, l.currency AS l_currency, l.cut AS l_cut, l.expiry AS l_expiry, NULL AS l_m3, NULL AS l_all, ' . PREV7;
const LIST_COLS = GAME_COLS . ', ' . LOW_COLS;

function listItem(array $r): array {
    $rp = $r['rating_pos'] ?? null; $rn = $r['rating_neg'] ?? null;
    return [
        'id' => $r['id'], 'slug' => $r['slug'] ?? null, 'title' => $r['title'], 'boxart' => $r['boxart'] ?? null, 'appid' => $r['appid'] ?? null,
        'releaseDate' => $r['release_date'] ?? null,
        'tags' => array_slice(json_decode_arr($r['tags'] ?? null), 0, 2),
        'popularRank' => $r['popular_rank'] ?? null,
        'free' => !empty($r['free']),
        'mature' => !empty($r['mature']),
        'pending' => empty($r['last_priced']),
        'rating' => ($rp !== null && $rp + $rn > 0) ? ['pos' => $rp, 'neg' => $rn] : null,
        'low' => ($r['l_price'] ?? null) === null ? null : [
            'shop' => $r['l_shop'], 'price' => $r['l_price'], 'regular' => $r['l_regular'], 'currency' => $r['l_currency'], 'cut' => $r['l_cut'],
            'expiry' => $r['l_expiry'], 'lowM3' => $r['l_m3'] ?? null, 'lowAll' => $r['l_all'] ?? null, 'prev7' => $r['l_prev7'] ?? null],
    ];
}

const UNPRICED_LAST = '(l.price IS NULL AND g.free = 0)';
const RATING_SCORE = '((g.rating_pos + 1.0) / (g.rating_pos + g.rating_neg + 2))';
function order_by(string $key): string {
    $o = [
        'popular' => UNPRICED_LAST . ', g.popular_rank IS NULL, g.popular_rank, g.collected_rank IS NULL, g.collected_rank, g.owners IS NULL, g.owners DESC, g.title COLLATE NOCASE',
        'bestsellers' => UNPRICED_LAST . ', g.collected_rank IS NULL, g.collected_rank, g.owners IS NULL, g.owners DESC, g.popular_rank IS NULL, g.popular_rank',
        'favorites' => UNPRICED_LAST . ', g.waitlist_rank IS NULL, g.waitlist_rank, g.popular_rank IS NULL, g.popular_rank, g.owners IS NULL, g.owners DESC',
        'rating' => '(COALESCE(g.rating_pos, 0) + COALESCE(g.rating_neg, 0) < 50), ' . RATING_SCORE . ' DESC, g.rating_pos DESC',
        'price_asc' => 'l.usd IS NULL, l.usd, g.title COLLATE NOCASE',
        'price_desc' => 'l.usd IS NULL, l.usd DESC, g.title COLLATE NOCASE',
        'discount' => UNPRICED_LAST . ', COALESCE(l.cut, 0) DESC, g.popular_rank IS NULL, g.popular_rank',
        'az' => 'g.title COLLATE NOCASE',
        'newest' => 'g.release_date IS NULL, g.release_date DESC, g.title COLLATE NOCASE',
    ];
    return 'ORDER BY ' . ($o[$key] ?? $o['popular']);
}

function validCountry(): string {
    $c = strtoupper(qs('country', 'TR'));
    if (!in_array($c, COUNTRIES, true)) fail('country', 400);
    return $c;
}
function cleanCountry($c): string { $c = strtoupper((string) $c); return in_array($c, COUNTRIES, true) ? $c : 'TR'; }
function cleanLang($l): string { return $l === 'en' ? 'en' : 'tr'; }

/** Filtreli sorgu: arama, harf, kategori, mağaza, sadece indirimdekiler, fiyatı olmayanlar */
function buildQuery(string $country): array {
    $q = mb_substr(trim(qs('q')), 0, 80);
    $letter = preg_match('/^[A-Z]$/', qs('letter')) ? qs('letter') : null;
    $cat = mb_substr(qs('cat'), 0, 40);
    $shop = mb_substr(qs('shop'), 0, 60);
    $where = ['g.hidden = 0']; $args = [];
    if ($shop !== '') {
        $join = 'JOIN prices l ON l.game_id = g.id AND l.country = ? AND l.shop_name = ?';
        array_push($args, $country, $shop); $cols = SHOP_COLS;
    } else {
        $join = 'LEFT JOIN lows l ON l.game_id = g.id AND l.country = ?';
        $args[] = $country; $cols = LOW_COLS;
        if (qs('all') !== '1') $where[] = '(l.price IS NOT NULL OR g.free = 1)';
    }
    if (qs('deals') === '1') $where[] = 'l.cut > 0';
    if ($q !== '') { $where[] = "g.title LIKE ? ESCAPE '\\'"; $args[] = '%' . preg_replace('/([\\\\%_])/', '\\\\$1', $q) . '%'; }
    if ($letter) { $where[] = 'upper(substr(g.title, 1, 1)) = ?'; $args[] = $letter; }
    if ($cat !== '') { $where[] = 'EXISTS (SELECT 1 FROM game_tags t WHERE t.game_id = g.id AND t.tag = ?)'; $args[] = $cat; }
    return ['from' => 'FROM games g ' . $join . ' WHERE ' . implode(' AND ', $where), 'args' => $args, 'cols' => $cols];
}

/* ---------- Giriş kontrolleri ---------- */
function admins(): array {
    return array_values(array_filter(array_map(fn($s) => trim($s), explode(',', strtolower((string) env('ADMIN_EMAILS', env('SUPPORT_EMAIL', '')))))));
}
function isAdmin(?array $u): bool { return $u && in_array(strtolower((string) $u['email']), admins(), true); }
function current_user(): ?array {
    if (!array_key_exists('__user', $GLOBALS)) $GLOBALS['__user'] = userFromSid(sidFromCookieHeader($_SERVER['HTTP_COOKIE'] ?? ''));
    return $GLOBALS['__user'];
}
function current_sid(): ?string { return sidFromCookieHeader($_SERVER['HTTP_COOKIE'] ?? ''); }
function require_user(): array { $u = current_user(); if (!$u) fail('login', 401); return $u; }
function require_admin(): array { $u = require_user(); if (!isAdmin($u)) fail('forbidden', 403); return $u; }
function publicUser(array $u): array {
    $av = null;
    if (!empty($u['avatar'])) $av = q1('SELECT id, title, boxart, appid FROM games WHERE id = ?', [$u['avatar']]) ?? [];
    return ['email' => $u['email'], 'name' => $u['name'], 'country' => $u['country'], 'lang' => $u['lang'],
        'notifyDrops' => ($u['notify_drops'] ?? 1) != 0, 'minCut' => (int) ($u['min_cut'] ?? 0), 'emailVerified' => !empty($u['email_verified']),
        'weeklyDigest' => ($u['weekly_digest'] ?? 1) != 0, 'isAdmin' => isAdmin($u), 'avatar' => $av === null ? null : ($av ?: new stdClass())];
}
function maskEmail(string $e): string {
    return preg_replace_callback('/^(.)(.*)(@.*)$/', fn($m) => $m[1] . str_repeat('*', min(strlen($m[2]), 6)) . $m[3], $e);
}
const EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
function user_row(int $id): array { return q1('SELECT * FROM users WHERE id = ?', [$id]) ?? []; }
function with_cache(string $key, int $ttl, callable $make) {
    $hit = cache_get($key, $ttl);
    if ($hit !== null) return $hit;
    $data = $make();
    cache_set($key, $data);
    return $data;
}
function tolerate(callable $fn): void { try { $fn(); } catch (Throwable $e) { log_line('  ' . $e->getMessage()); } }

/* ---------- Yönlendirme ---------- */
function handle_request(): void {
    security_headers();
    $r = req(); $m = $r['method'] === 'HEAD' ? 'GET' : $r['method']; $p = $r['path'];   // HEAD (izleme servisleri) GET gibi cevaplanır
    try {
        route($m, $p);
        // eşleşme yok
        if (str_starts_with($p, '/api/')) fail('notfound', 404);
        send_page([], 404);
    } catch (HttpExit $e) {
        return;
    } catch (Throwable $e) {
        log_line('HATA: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo jenc(['code' => 'server']);
    }
}

function route(string $m, string $p) {
    if ($m === 'GET' && ($p === '/' || $p === '/index.html')) send_page();
    if ($m === 'GET' && preg_match('#^/oyun/([^/]+)$#', $p, $x)) return game_page($x[1]);
    if ($m === 'GET' && $p === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\nAllow: /\nDisallow: /api/\nSitemap: " . site_url() . "/sitemap.xml\n"; throw new HttpExit();
    }
    if ($m === 'GET' && $p === '/sitemap.xml') return sitemap();
    if ($m === 'GET' && $p === '/api/health') { qv('SELECT 1'); json_out(['ok' => true, 'uptime' => max(0, time() - (int) (meta_get('first_run') ?? time()))]); }

    if ($m === 'GET' && $p === '/api/games') return api_games();
    if ($m === 'GET' && $p === '/api/deals') return api_deals();
    if ($m === 'GET' && $p === '/api/shops') return api_shops();
    if ($m === 'GET' && $p === '/api/categories') return api_categories();
    if ($m === 'GET' && $p === '/api/showcase') return api_showcase();
    if ($m === 'GET' && $p === '/api/free') return api_free();
    if ($m === 'GET' && $p === '/api/ending') return api_ending();
    if ($m === 'GET' && $p === '/api/shelves') return api_shelves();
    if ($m === 'GET' && preg_match('#^/api/games/([^/]+)/similar$#', $p, $x)) return api_similar($x[1]);
    if ($m === 'GET' && preg_match('#^/api/games/([^/]+)$#', $p, $x)) return api_game($x[1]);
    if ($m === 'GET' && $p === '/api/config') return api_config();
    if ($m === 'GET' && $p === '/api/covers') return api_covers();
    if ($m === 'GET' && $p === '/api/search') return api_search();

    if ($m === 'POST' && $p === '/api/auth/register') return api_register();
    if ($m === 'POST' && $p === '/api/auth/login') return api_login();
    if ($m === 'POST' && $p === '/api/auth/verify') return api_verify();
    if ($m === 'POST' && $p === '/api/auth/resend') return api_resend();
    if ($m === 'POST' && $p === '/api/auth/forgot') return api_forgot();
    if ($m === 'POST' && $p === '/api/auth/reset') return api_reset();
    if ($m === 'POST' && $p === '/api/auth/logout') return api_logout();
    if ($m === 'GET' && $p === '/api/me') { $u = current_user(); json_out(['user' => $u ? publicUser($u) : null]); }
    if ($m === 'POST' && $p === '/api/me/password') return api_password();
    if ($m === 'DELETE' && $p === '/api/me') return api_delete_me();
    if ($m === 'PATCH' && $p === '/api/me') return api_patch_me();

    if ($m === 'GET' && $p === '/api/wishlist') return api_wishlist();
    if (preg_match('#^/api/wishlist/([^/]+)$#', $p, $x)) {
        if ($m === 'POST') return api_wish_add($x[1]);
        if ($m === 'PATCH') return api_wish_target($x[1]);
        if ($m === 'DELETE') return api_wish_del($x[1]);
    }
    if ($p === '/api/lists' && $m === 'GET') return api_lists();
    if ($p === '/api/lists' && $m === 'POST') return api_list_create();
    if (preg_match('#^/api/lists/join/([^/]+)$#', $p, $x)) {
        if ($m === 'GET') return api_list_preview($x[1]);
        if ($m === 'POST') return api_list_join($x[1]);
    }
    if (preg_match('#^/api/lists/([^/]+)/games/([^/]+)$#', $p, $x) && $m === 'DELETE') return api_list_game_del($x[1], $x[2]);
    if (preg_match('#^/api/lists/([^/]+)/games$#', $p, $x) && $m === 'POST') return api_list_game_add($x[1]);
    if (preg_match('#^/api/lists/([^/]+)/leave$#', $p, $x) && $m === 'POST') return api_list_leave($x[1]);
    if (preg_match('#^/api/lists/([^/]+)$#', $p, $x)) {
        if ($m === 'GET') return api_list_get($x[1]);
        if ($m === 'DELETE') return api_list_delete($x[1]);
    }
    if ($m === 'GET' && $p === '/api/admin/stats') return api_admin_stats();
    if ($m === 'POST' && $p === '/api/admin/digest-test') return api_digest_test();
    if ($m === 'GET' && $p === '/api/notifications') {
        $u = require_user();
        json_out(['items' => q('SELECT kind, text, sent_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$u['id']])]);
    }
}

/* ---------- Sayfalar ---------- */
function game_page(string $key): void {
    $k = mb_substr($key, 0, 200);
    $g = q1('SELECT id, slug, title, appid, boxart FROM games WHERE (slug = ? OR id = ?) AND hidden = 0', [$k, $k]);
    if (!$g) send_page();
    $lo = q1("SELECT shop, price, regular, cut, currency FROM lows WHERE game_id = ? AND country = 'TR'", [$g['id']]);
    if ($lo) {
        $cur = $lo['currency'] ?: 'TRY';
        $desc = ($lo['cut'] > 0 ? 'En ucuz: ' . money_tr($lo['price'], $cur) . " ({$lo['shop']}, %{$lo['cut']} indirim, normalde " . money_tr($lo['regular'], $cur) . ').'
            : 'En ucuz: ' . money_tr($lo['price'], $cur) . " ({$lo['shop']}).") . ' Fiyatı düşünce haber almak için takibe al.';
    } else $desc = 'Bu oyunun mağaza fiyatlarını karşılaştır, fiyatı düşünce haber al.';
    $image = $g['appid'] ? "https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/{$g['appid']}/header.jpg" : ($g['boxart'] ?: null);
    send_page(['title' => $g['title'] . ' — Fiyatavı', 'desc' => $desc, 'image' => $image, 'wide' => (bool) $g['appid'],
        'path' => '/oyun/' . rawurlencode($g['slug'] ?: $g['id'])]);
}
function sitemap(): void {
    $xml = with_cache('sitemap', 6 * 3600, function () {
        $rows = q('SELECT slug FROM games WHERE hidden = 0 AND slug IS NOT NULL ORDER BY popular_rank IS NULL, popular_rank, owners IS NULL, owners DESC LIMIT 5000');
        $u = site_url();
        $urls = ["$u/"];
        foreach ($rows as $r) $urls[] = "$u/oyun/" . rawurlencode($r['slug']);
        return ['xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . implode("\n", array_map(fn($x) => '<url><loc>' . esc_attr($x) . '</loc></url>', $urls)) . "\n</urlset>\n"];
    })['xml'];
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml; throw new HttpExit();
}

/* ---------- Oyunlar ve fiyatlar ---------- */
function api_games(): void {
    $country = validCountry();
    $page = max(0, min((int) qs('page', '0'), 2000));
    $size = max(1, min((int) qs('limit', (string) PAGE_SIZE) ?: PAGE_SIZE, PAGE_SIZE));
    $b = buildQuery($country);
    $total = (int) qv('SELECT COUNT(*) ' . $b['from'], $b['args'], 0);
    $rows = q('SELECT ' . GAME_COLS . ', ' . $b['cols'] . ' ' . $b['from'] . ' ' . order_by(qs('sort')) . ' LIMIT ' . $size . ' OFFSET ' . ($page * $size), $b['args']);
    $games = array_map('listItem', $rows);
    $pend = array_values(array_map(fn($g) => $g['id'], array_filter($games, fn($g) => $g['pending'])));
    if ($pend) tolerate(fn() => queuePriority($pend));
    json_out(['country' => $country, 'updatedAt' => meta_get('last_sync'), 'total' => $total, 'pageSize' => $size, 'games' => $games]);
}
function api_deals(): void {
    $country = validCountry(); $shop = mb_substr(qs('shop'), 0, 60);
    $rows = $shop !== ''
        ? q('SELECT ' . GAME_COLS . ', ' . SHOP_COLS . ' FROM games g JOIN prices l ON l.game_id = g.id AND l.country = ? AND l.shop_name = ?
            WHERE g.hidden = 0 AND l.cut > 0 ORDER BY g.popular_rank IS NULL, g.popular_rank, g.owners IS NULL, g.owners DESC LIMIT 12', [$country, $shop])
        : q('SELECT ' . LIST_COLS . ' FROM games g JOIN lows l ON l.game_id = g.id AND l.country = ?
            WHERE g.hidden = 0 AND g.popular_rank IS NOT NULL AND l.cut > 0 ORDER BY g.popular_rank LIMIT 12', [$country]);
    json_out(['games' => array_map('listItem', $rows)]);
}
function api_shops(): void {
    $country = validCountry();
    $data = with_cache("shops:$country", 1800, fn() => ['shops' => q('SELECT shop_name AS name, COUNT(*) AS n FROM prices WHERE country = ?
        GROUP BY shop_name HAVING n >= 5 ORDER BY n DESC', [$country])]);
    json_out($data);
}
function api_categories(): void {
    $data = with_cache('categories', 1800, function () {
        $out = [];
        foreach (CATEGORIES as [$tag, $tr, $en]) {
            $out[] = ['tag' => $tag, 'tr' => $tr, 'en' => $en,
                'n' => (int) qv('SELECT COUNT(*) FROM game_tags t JOIN games g ON g.id = t.game_id WHERE t.tag = ? AND g.hidden = 0', [$tag], 0)];
        }
        return ['categories' => $out];
    });
    json_out($data);
}
function api_showcase(): void {
    $country = validCountry(); $shop = mb_substr(qs('shop'), 0, 60);
    $key = "showcase:$country|$shop";
    $hit = cache_get($key, 3600);
    if ($hit !== null) json_out($hit);
    // vitrin (ana sayfanın büyük kayan slaytları) öne çıkan bir yer olduğu için +18 oyunlar hiç aday olmaz
    $sql = $shop !== ''
        ? 'SELECT ' . GAME_COLS . ', ' . SHOP_COLS . ' FROM games g JOIN prices l ON l.game_id = g.id AND l.country = ? AND l.shop_name = ?
           WHERE g.hidden = 0 AND g.mature = 0 AND l.cut > 0 ORDER BY g.popular_rank IS NULL, g.popular_rank, g.collected_rank IS NULL, g.collected_rank, g.owners IS NULL, g.owners DESC LIMIT 20'
        : 'SELECT ' . LIST_COLS . ' FROM games g JOIN lows l ON l.game_id = g.id AND l.country = ?
           WHERE g.hidden = 0 AND g.mature = 0 AND l.cut > 0 AND (g.popular_rank IS NOT NULL OR g.collected_rank IS NOT NULL OR g.waitlist_rank IS NOT NULL)
           ORDER BY COALESCE(g.popular_rank, g.collected_rank + 1000, g.waitlist_rank + 2000) LIMIT 20';
    $args = $shop !== '' ? [$country, $shop] : [$country];
    budget_start(18);
    $rows = q($sql, $args);
    $missing = array_values(array_map(fn($r) => $r['id'], array_filter($rows, fn($r) => empty($r['appid']))));
    if ($missing) tolerate(fn() => ensureAppids($missing));
    $rows = q($sql, $args);
    $slides = [];
    foreach (array_slice($rows, 0, 12) as $r) {
        $m = null;
        if (!empty($r['appid']) && budget_ok(2)) { try { $m = ensureDetails($r['id'], $r['appid']); } catch (Throwable $e) { $m = null; } }
        elseif (!empty($r['appid'])) $m = q1('SELECT shots, poster FROM media WHERE game_id = ?', [$r['id']]);
        $shots = json_decode_arr($m['shots'] ?? null);
        if (!$shots && empty($m['poster'])) continue;
        $slides[] = array_merge(listItem($r), ['shots' => $shots ?: [$m['poster']]]);
    }
    unset($GLOBALS['__budget_end']);
    $data = ['games' => array_slice($slides, 0, 10)];
    cache_set($key, $data, $data['games'] ? time() : time() - 3000);
    json_out($data);
}
function api_free(): void {
    $country = validCountry();
    json_out(with_cache("free:$country", 900, function () use ($country) {
        $rows = q('SELECT ' . GAME_COLS . ', ' . SHOP_COLS . ' FROM games g
            JOIN prices l ON l.game_id = g.id AND l.country = ? AND l.price = 0 AND l.regular > 0
            WHERE g.hidden = 0 ORDER BY g.popular_rank IS NULL, g.popular_rank, g.owners IS NULL, g.owners DESC, l.regular DESC LIMIT 12', [$country]);
        $seen = []; $games = [];
        foreach ($rows as $r) if (!isset($seen[$r['id']])) { $seen[$r['id']] = 1; $games[] = listItem($r); }
        return ['games' => $games];
    }));
}
function api_ending(): void {
    $country = validCountry();
    json_out(with_cache("ending:$country", 600, function () use ($country) {
        $rows = q('SELECT ' . GAME_COLS . ', ' . LOW_COLS . " FROM games g JOIN lows l ON l.game_id = g.id AND l.country = ?
            WHERE g.hidden = 0 AND l.cut > 0 AND l.price > 0 AND l.expiry IS NOT NULL
              AND datetime(l.expiry) > datetime('now') AND datetime(l.expiry) <= datetime('now', '+7 days')
              AND (g.popular_rank IS NOT NULL OR g.collected_rank IS NOT NULL OR COALESCE(g.rating_pos, 0) + COALESCE(g.rating_neg, 0) >= 500)
            ORDER BY datetime(l.expiry), l.cut DESC LIMIT 12", [$country]);
        return ['games' => array_map('listItem', $rows)];
    }));
}
function api_shelves(): void {
    $country = validCountry();
    json_out(with_cache("shelves:$country", 600, function () use ($country) {
        $known = '(g.popular_rank IS NOT NULL OR g.collected_rank IS NOT NULL OR COALESCE(g.rating_pos, 0) + COALESCE(g.rating_neg, 0) >= 300)';
        $pop = 'g.popular_rank IS NULL, g.popular_rank, g.owners IS NULL, g.owners DESC';
        $base = 'SELECT ' . LIST_COLS . " FROM games g JOIN lows l ON l.game_id = g.id AND l.country = ? WHERE g.hidden = 0 AND l.price > 0 AND $known";
        $under = q("$base AND l.currency = 'TRY' AND l.price <= 100 ORDER BY $pop LIMIT 12", [$country]);
        $atl = q("$base AND l.cut > 0 AND l.low_all IS NOT NULL AND l.price <= l.low_all + 0.005 ORDER BY $pop LIMIT 12", [$country]);
        $drop = q("SELECT * FROM ($base) WHERE l_prev7 IS NOT NULL AND l_price < l_prev7 * 0.9 ORDER BY (l_prev7 - l_price) / l_prev7 DESC LIMIT 12", [$country]);
        return ['under100' => array_map('listItem', $under), 'atl' => array_map('listItem', $atl), 'dropped' => array_map('listItem', $drop)];
    }));
}
function api_similar(string $id): void {
    $country = validCountry();
    $generic = ['Indie', 'Singleplayer', 'Multiplayer', 'Casual', 'Free to Play', 'Early Access', 'Great Soundtrack', 'Atmospheric', '2D', '3D', 'Colorful'];
    json_out(with_cache("similar:$id$country", 3600, function () use ($id, $country, $generic) {
        $rows = q('SELECT ' . LIST_COLS . ', COUNT(*) AS shared FROM game_tags t
            JOIN game_tags t2 ON t2.tag = t.tag AND t2.game_id <> t.game_id
            JOIN games g ON g.id = t2.game_id
            JOIN lows l ON l.game_id = g.id AND l.country = ?
            WHERE t.game_id = ? AND t.tag NOT IN (' . implode(',', array_fill(0, count($generic), '?')) . ') AND g.hidden = 0
            GROUP BY g.id ORDER BY shared DESC, g.popular_rank IS NULL, g.popular_rank, g.owners IS NULL, g.owners DESC LIMIT 8', array_merge([$country, $id], $generic));
        return ['games' => array_map('listItem', $rows)];
    }));
}
function api_game(string $idIn): void {
    $country = validCountry();
    $id = $idIn;
    if (!q1('SELECT 1 FROM games WHERE id = ?', [$id])) {
        $bySlug = q1('SELECT id FROM games WHERE slug = ? AND hidden = 0', [$id]);
        if (!$bySlug) fail('game', 404);
        $id = $bySlug['id'];
    }
    ex("UPDATE games SET viewed_at = datetime('now') WHERE id = ?", [$id]);
    $hasPrices = fn() => (bool) q1('SELECT 1 FROM prices WHERE game_id = ? AND country = ?', [$id, $country]);
    foreach ([fn() => ensureInfo($id), fn() => ensureHistory($id, $country), fn() => ensureRating($id), fn() => ensureDetails($id)] as $job) tolerate($job);
    if (!$hasPrices() && !cache_get("pricetried:$id$country", 86400 * 365)) {
        cache_set("pricetried:$id$country", 1);
        tolerate(fn() => syncPricesFor([$id], [$country]));
        tolerate(fn() => queuePriority([$id]));
    }
    $g = q1('SELECT * FROM games WHERE id = ?', [$id]);
    $low = q1('SELECT shop, price, regular, currency, cut, expiry, low_m3, low_all FROM lows WHERE game_id = ? AND country = ?', [$id, $country]);
    $media = q1('SELECT shots, req_min, req_rec FROM media WHERE game_id = ?', [$id]);
    $min = json_decode_arr($media['req_min'] ?? null); $rec = json_decode_arr($media['req_rec'] ?? null);
    $item = listItem($g + ['l_shop' => $low['shop'] ?? null, 'l_price' => $low['price'] ?? null, 'l_regular' => $low['regular'] ?? null,
        'l_currency' => $low['currency'] ?? null, 'l_cut' => $low['cut'] ?? null, 'l_expiry' => $low['expiry'] ?? null,
        'l_m3' => $low['low_m3'] ?? null, 'l_all' => $low['low_all'] ?? null]);
    json_out(['game' => $item + [
        'elsewhere' => [],
        'playMin' => !empty($g['play_min']) ? (int) $g['play_min'] : null,
        'developers' => json_decode_arr($g['developers']),
        'tags' => json_decode_arr($g['tags']),
        'prices' => q('SELECT shop_name AS shop, price, regular, currency, cut, expiry, url FROM prices WHERE game_id = ? AND country = ? ORDER BY price', [$id, $country]),
        'history' => q("SELECT day, lowest, currency FROM price_history WHERE game_id = ? AND country = ? AND day >= date('now', ?) ORDER BY day", [$id, $country, '-' . HISTORY_MONTHS . ' months']),
        'shots' => array_slice(json_decode_arr($media['shots'] ?? null), 0, 6),
        'requirements' => ($min || $rec) ? ['min' => $min, 'rec' => $rec] : ['min' => [], 'rec' => [], 'none' => true, 'steam' => !empty($g['appid'])],
    ]]);
}
function api_config(): void {
    $stats = with_cache('stats', 600, fn() => [
        'games' => (int) qv('SELECT COUNT(*) FROM games WHERE hidden = 0'),
        'priced' => (int) qv('SELECT COUNT(DISTINCT game_id) FROM lows'),
        'shops' => (int) qv('SELECT COUNT(DISTINCT shop_name) FROM prices'),
        'deals' => (int) qv('SELECT COUNT(*) FROM lows l JOIN games g ON g.id = l.game_id WHERE g.hidden = 0 AND l.cut > 0'),
    ]);
    $rates = null; try { $rates = currentRates(); } catch (Throwable $e) {}
    json_out(['siteName' => site_name(), 'email' => (string) env('SUPPORT_EMAIL', ''), 'instagram' => ltrim((string) env('SUPPORT_INSTAGRAM', ''), '@'),
        'stats' => $stats, 'controller' => (string) env('CONTROLLER_NAME', ''), 'rates' => $rates]);
}
function api_covers(): void {
    json_out(['games' => q('SELECT id, title, boxart, appid FROM games WHERE hidden = 0 AND (boxart IS NOT NULL OR appid IS NOT NULL)
        ORDER BY popular_rank IS NULL, popular_rank LIMIT 60')]);
}
function api_search(): void {
    $country = strtoupper(qs('country', 'TR')); $q = mb_substr(trim(qs('q')), 0, 80);
    if (!in_array($country, COUNTRIES, true) || mb_strlen($q) < 3) json_out(['added' => 0]);
    try { json_out(['added' => searchRemote($q, $country)]); }
    catch (HttpExit $e) { throw $e; }
    catch (Throwable $e) { log_line('  arama: ' . $e->getMessage()); json_out(['added' => 0]); }
}

/* ---------- Hesap ---------- */
function api_register(): void {
    $email = strtolower(trim((string) body('email', '')));
    $password = (string) body('password', '');
    $name = mb_substr(trim((string) body('name', '')), 0, 40) ?: explode('@', $email)[0];
    if ($email === '' || $password === '') fail('fill', 400);
    if (!preg_match(EMAIL_RE, $email)) fail('email', 400);
    if (mb_strlen($password) < 6) fail('pass', 400);
    if (body('consent') !== true) fail('consent', 400);
    if (tooManyAttempts('reg:' . clientIp())) fail('rate', 429);
    try {
        ex("INSERT INTO users (email, name, pass_hash, country, lang, email_verified, consent_at) VALUES (?, ?, ?, ?, ?, 0, datetime('now'))",
            [$email, $name, hashPassword($password), cleanCountry(body('country')), cleanLang(body('lang'))]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) fail('exists', 409);
        throw $e;
    }
    $user = user_row(last_id());
    setSessionCookie(createSession((int) $user['id']));
    tolerate(fn() => sendAccountMail('verify', $user, createEmailToken((int) $user['id'], 'verify', 48)));
    json_out(['user' => publicUser($user)]);
}
function api_login(): void {
    $email = strtolower(trim((string) body('email', ''))); $password = (string) body('password', '');
    if ($email === '' || $password === '') fail('fill', 400);
    if (tooManyAttempts('login:' . clientIp() . ':' . $email)) fail('rate', 429);
    $user = q1('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !verifyPassword($password, $user['pass_hash'])) fail('wrong', 401);
    if (passwordNeedsRehash($user['pass_hash'])) ex('UPDATE users SET pass_hash = ? WHERE id = ?', [hashPassword($password), $user['id']]);
    setSessionCookie(createSession((int) $user['id']));
    json_out(['user' => publicUser($user)]);
}
function api_verify(): void {
    $uid = consumeEmailToken(body('token'), 'verify');
    if (!$uid) fail('token', 400);
    ex('UPDATE users SET email_verified = 1 WHERE id = ?', [$uid]);
    json_out(['ok' => true]);
}
function api_resend(): void {
    $u = require_user();
    if (!empty($u['email_verified'])) json_out(['ok' => true]);
    if (tooManyAttempts('resend:' . $u['id'])) fail('rate', 429);
    tolerate(fn() => sendAccountMail('verify', $u, createEmailToken((int) $u['id'], 'verify', 48)));
    json_out(['ok' => true]);
}
function api_forgot(): void {
    $email = strtolower(trim((string) body('email', '')));
    if (!preg_match(EMAIL_RE, $email)) fail('email', 400);
    if (tooManyAttempts('forgot:' . clientIp())) fail('rate', 429);
    $user = q1('SELECT * FROM users WHERE email = ?', [$email]);
    if ($user) tolerate(fn() => sendAccountMail('reset', $user, createEmailToken((int) $user['id'], 'reset', 1)));
    json_out(['ok' => true]);
}
function api_reset(): void {
    $password = (string) body('password', '');
    if (mb_strlen($password) < 6) fail('pass', 400);
    if (tooManyAttempts('reset:' . clientIp())) fail('rate', 429);
    $uid = consumeEmailToken(body('token'), 'reset');
    if (!$uid) fail('token', 400);
    ex('UPDATE users SET pass_hash = ?, email_verified = 1 WHERE id = ?', [hashPassword($password), $uid]);
    destroyUserSessions($uid);
    setSessionCookie(createSession($uid));
    json_out(['user' => publicUser(user_row($uid))]);
}
function api_logout(): void {
    destroySession(current_sid());
    setSessionCookie('', 0);
    json_out(['ok' => true]);
}
function api_password(): void {
    $u = require_user();
    $cur = (string) body('current', ''); $next = (string) body('password', '');
    if (mb_strlen($next) < 6) fail('pass', 400);
    if (tooManyAttempts('pw:' . $u['id'])) fail('rate', 429);
    $row = q1('SELECT pass_hash FROM users WHERE id = ?', [$u['id']]);
    if (!verifyPassword($cur, $row['pass_hash'])) fail('wrong', 401);
    ex('UPDATE users SET pass_hash = ? WHERE id = ?', [hashPassword($next), $u['id']]);
    destroyUserSessions((int) $u['id']);
    setSessionCookie(createSession((int) $u['id']));
    json_out(['ok' => true]);
}
function api_delete_me(): void {
    $u = require_user();
    $user = user_row((int) $u['id']);
    if (!verifyPassword((string) body('password', ''), $user['pass_hash'])) fail('wrong', 401);
    foreach (q('SELECT list_id FROM list_members WHERE user_id = ?', [$user['id']]) as $m) leaveList((int) $m['list_id'], (int) $user['id']);
    ex('DELETE FROM users WHERE id = ?', [$user['id']]);
    setSessionCookie('', 0);
    json_out(['ok' => true]);
}
function api_patch_me(): void {
    $u = require_user(); $id = $u['id'];
    $b = req()['body'];
    if (!empty($b['lang'])) ex('UPDATE users SET lang = ? WHERE id = ?', [cleanLang($b['lang']), $id]);
    if (isset($b['notifyDrops']) && is_bool($b['notifyDrops'])) ex('UPDATE users SET notify_drops = ? WHERE id = ?', [$b['notifyDrops'] ? 1 : 0, $id]);
    if (isset($b['minCut']) && in_array($b['minCut'], [0, 25, 50, 75], true)) ex('UPDATE users SET min_cut = ? WHERE id = ?', [$b['minCut'], $id]);
    if (isset($b['weeklyDigest']) && is_bool($b['weeklyDigest'])) ex('UPDATE users SET weekly_digest = ? WHERE id = ?', [$b['weeklyDigest'] ? 1 : 0, $id]);
    if (isset($b['name']) && is_string($b['name']) && trim($b['name']) !== '') ex('UPDATE users SET name = ? WHERE id = ?', [mb_substr(trim($b['name']), 0, 40), $id]);
    json_out(['user' => publicUser(user_row((int) $id))]);
}

/* ---------- İstek listesi ---------- */
function api_wishlist(): void {
    $u = require_user();
    $c = strtoupper(qs('country'));
    $country = in_array($c, COUNTRIES, true) ? $c : $u['country'];
    $rows = q('SELECT ' . LIST_COLS . ', w.target_price, w.target_currency FROM wishlist w JOIN games g ON g.id = w.game_id
        LEFT JOIN lows l ON l.game_id = g.id AND l.country = ? WHERE w.user_id = ? ORDER BY w.added_at', [$country, $u['id']]);
    $targets = [];
    foreach ($rows as $r) if ($r['target_price'] !== null) $targets[$r['id']] = ['price' => $r['target_price'], 'currency' => $r['target_currency']];
    json_out(['ids' => array_map(fn($r) => $r['id'], $rows), 'games' => array_map('listItem', $rows), 'targets' => $targets ?: new stdClass()]);
}
function api_wish_add(string $gid): void {
    $u = require_user();
    $game = q1('SELECT id, title FROM games WHERE id = ?', [$gid]);
    if (!$game) fail('game', 404);
    $changed = ex('INSERT OR IGNORE INTO wishlist (user_id, game_id) VALUES (?, ?)', [$u['id'], $game['id']]);
    if ($changed) tolerate(fn() => notifyAdded($u, $game));
    json_out(['ok' => true]);
}
function api_wish_target(string $gid): void {
    $u = require_user();
    if (!q1('SELECT 1 FROM wishlist WHERE user_id = ? AND game_id = ?', [$u['id'], $gid])) fail('game', 404);
    $b = req()['body'];
    if (array_key_exists('target', $b) && $b['target'] === null) {
        ex('UPDATE wishlist SET target_price = NULL, target_currency = NULL, target_hit = 0 WHERE user_id = ? AND game_id = ?', [$u['id'], $gid]);
        json_out(['ok' => true]);
    }
    $price = is_numeric($b['target'] ?? null) ? (float) $b['target'] : NAN;
    $cur = strtoupper((string) ($b['currency'] ?? ''));
    if (!($price > 0 && $price < 1e7) || !preg_match('/^[A-Z]{3}$/', $cur)) fail('target', 400);
    $low = q1('SELECT price, currency FROM lows WHERE game_id = ? AND country = ?', [$gid, $u['country']]);
    $already = $low && $low['currency'] === $cur && $low['price'] <= $price;
    ex('UPDATE wishlist SET target_price = ?, target_currency = ?, target_hit = ? WHERE user_id = ? AND game_id = ?', [$price, $cur, $already ? 1 : 0, $u['id'], $gid]);
    json_out(['ok' => true, 'already' => (bool) $already]);
}
function api_wish_del(string $gid): void {
    $u = require_user();
    ex('DELETE FROM wishlist WHERE user_id = ? AND game_id = ?', [$u['id'], $gid]);
    ex("DELETE FROM notifications WHERE user_id = ? AND game_id = ? AND kind = 'added'", [$u['id'], $gid]);
    json_out(['ok' => true]);
}

/* ---------- Ortak listeler ---------- */
const LIST_MAX_OWNED = 5, LIST_MAX_GAMES = 100, LIST_MAX_MEMBERS = 10;
function isListMember(int $listId, int $uid): bool { return (bool) q1('SELECT 1 FROM list_members WHERE list_id = ? AND user_id = ?', [$listId, $uid]); }
function listMembers(int $listId): array {
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
        q('SELECT u.id, u.name FROM list_members m JOIN users u ON u.id = m.user_id WHERE m.list_id = ? ORDER BY m.joined_at, u.id', [$listId]));
}
function listPayload(array $l, ?int $uid, string $country): array {
    $members = listMembers((int) $l['id']);
    $games = array_map(fn($r) => listItem($r) + ['addedBy' => $r['added_by'] !== null ? (int) $r['added_by'] : null],
        q('SELECT ' . LIST_COLS . ', lg.added_by FROM list_games lg JOIN games g ON g.id = lg.game_id
            LEFT JOIN lows l ON l.game_id = g.id AND l.country = ? WHERE lg.list_id = ? ORDER BY lg.added_at, g.title', [$country, $l['id']]));
    $owner = null; $joined = false;
    foreach ($members as $m) { if ($m['id'] === (int) $l['owner_id']) $owner = $m['name']; if ($uid !== null && $m['id'] === $uid) $joined = true; }
    return ['id' => (int) $l['id'], 'token' => $l['token'], 'name' => $l['name'], 'ownerId' => (int) $l['owner_id'], 'owner' => $owner,
        'joined' => $joined, 'isOwner' => $uid !== null && (int) $l['owner_id'] === $uid, 'members' => $members, 'games' => $games];
}
// Üye listeden çıkar; sahibi çıkarsa en eski üye sahip olur, kimse kalmazsa liste silinir
function leaveList(int $listId, int $uid): void {
    ex('DELETE FROM list_members WHERE list_id = ? AND user_id = ?', [$listId, $uid]);
    $rest = listMembers($listId);
    $l = q1('SELECT * FROM lists WHERE id = ?', [$listId]);
    if (!$l) return;
    if (!$rest) { ex('DELETE FROM list_games WHERE list_id = ?', [$listId]); ex('DELETE FROM lists WHERE id = ?', [$listId]); }
    elseif ((int) $l['owner_id'] === $uid) ex('UPDATE lists SET owner_id = ? WHERE id = ?', [$rest[0]['id'], $listId]);
}
function listCtx(string $idIn): array {
    $u = require_user();
    $l = ctype_digit($idIn) ? q1('SELECT * FROM lists WHERE id = ?', [(int) $idIn]) : null;
    if (!$l || !isListMember((int) $l['id'], (int) $u['id'])) fail('list', 404);
    return [$u, $l];
}
function validListToken(string $t): bool { return (bool) preg_match('/^[\w-]{10,40}$/', $t); }
function api_lists(): void {
    $u = require_user();
    $rows = q('SELECT l.id, l.token, l.name, l.owner_id,
        (SELECT COUNT(*) FROM list_members WHERE list_id = l.id) AS members, (SELECT COUNT(*) FROM list_games WHERE list_id = l.id) AS games
        FROM lists l JOIN list_members m ON m.list_id = l.id WHERE m.user_id = ? ORDER BY l.id', [$u['id']]);
    json_out(['lists' => array_map(fn($r) => ['id' => (int) $r['id'], 'token' => $r['token'], 'name' => $r['name'],
        'isOwner' => (int) $r['owner_id'] === (int) $u['id'], 'members' => (int) $r['members'], 'games' => (int) $r['games']], $rows)]);
}
function api_list_create(): void {
    $u = require_user();
    $name = mb_substr(trim((string) body('name', '')), 0, 40);
    if ($name === '') fail('name', 400);
    if ((int) qv('SELECT COUNT(*) FROM lists WHERE owner_id = ?', [$u['id']], 0) >= LIST_MAX_OWNED) fail('listLimit', 400);
    $token = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    ex('INSERT INTO lists (token, name, owner_id) VALUES (?, ?, ?)', [$token, $name, $u['id']]);
    $id = last_id();
    ex('INSERT INTO list_members (list_id, user_id) VALUES (?, ?)', [$id, $u['id']]);
    json_out(['list' => listPayload(q1('SELECT * FROM lists WHERE id = ?', [$id]), (int) $u['id'], $u['country'])]);
}
function api_list_preview(string $token): void {
    $l = validListToken($token) ? q1('SELECT * FROM lists WHERE token = ?', [$token]) : null;
    if (!$l) fail('list', 404);
    $u = current_user();
    json_out(['list' => listPayload($l, $u ? (int) $u['id'] : null, 'TR')]);
}
function api_list_join(string $token): void {
    $u = require_user();
    $l = validListToken($token) ? q1('SELECT * FROM lists WHERE token = ?', [$token]) : null;
    if (!$l) fail('list', 404);
    if (!isListMember((int) $l['id'], (int) $u['id'])) {
        if (count(listMembers((int) $l['id'])) >= LIST_MAX_MEMBERS) fail('listFull', 400);
        tx(function () use ($l, $u) {
            ex('INSERT INTO list_members (list_id, user_id) VALUES (?, ?)', [$l['id'], $u['id']]);
            foreach (q('SELECT game_id FROM list_games WHERE list_id = ?', [$l['id']]) as $g) ex('INSERT OR IGNORE INTO wishlist (user_id, game_id) VALUES (?, ?)', [$u['id'], $g['game_id']]);
        });
    }
    json_out(['list' => listPayload($l, (int) $u['id'], $u['country'])]);
}
function api_list_get(string $id): void {
    [$u, $l] = listCtx($id);
    json_out(['list' => listPayload($l, (int) $u['id'], $u['country'])]);
}
function api_list_game_add(string $id): void {
    [$u, $l] = listCtx($id);
    $game = q1('SELECT id FROM games WHERE id = ? AND hidden = 0', [(string) body('gameId', '')]);
    if (!$game) fail('game', 404);
    if ((int) qv('SELECT COUNT(*) FROM list_games WHERE list_id = ?', [$l['id']], 0) >= LIST_MAX_GAMES) fail('listGames', 400);
    tx(function () use ($l, $u, $game) {
        ex('INSERT OR IGNORE INTO list_games (list_id, game_id, added_by) VALUES (?, ?, ?)', [$l['id'], $game['id'], $u['id']]);
        foreach (listMembers((int) $l['id']) as $m) ex('INSERT OR IGNORE INTO wishlist (user_id, game_id) VALUES (?, ?)', [$m['id'], $game['id']]);
    });
    json_out(['list' => listPayload($l, (int) $u['id'], $u['country'])]);
}
function api_list_game_del(string $id, string $gid): void {
    [$u, $l] = listCtx($id);
    ex('DELETE FROM list_games WHERE list_id = ? AND game_id = ?', [$l['id'], $gid]);
    json_out(['list' => listPayload($l, (int) $u['id'], $u['country'])]);
}
function api_list_leave(string $id): void {
    [$u, $l] = listCtx($id);
    leaveList((int) $l['id'], (int) $u['id']);
    json_out(['ok' => true]);
}
function api_list_delete(string $id): void {
    [$u, $l] = listCtx($id);
    if ((int) $l['owner_id'] !== (int) $u['id']) fail('forbidden', 403);
    ex('DELETE FROM list_games WHERE list_id = ?', [$l['id']]);
    ex('DELETE FROM list_members WHERE list_id = ?', [$l['id']]);
    ex('DELETE FROM lists WHERE id = ?', [$l['id']]);
    json_out(['ok' => true]);
}
// Yönetim paneli grafikleri: son N günde günlük yeni üye ve istek listesi eklemesi (eksik günler 0)
function dailySeries(int $days): array {
    $bucket = function (string $sql) use ($days): array {
        $o = []; foreach (q($sql, ['-' . ($days - 1) . ' days']) as $r) $o[$r['d']] = (int) $r['n']; return $o;
    };
    $users = $bucket("SELECT date(created_at) AS d, COUNT(*) AS n FROM users WHERE date(created_at) >= date('now', ?) GROUP BY d");
    $wish = $bucket("SELECT date(added_at) AS d, COUNT(*) AS n FROM wishlist WHERE date(added_at) >= date('now', ?) GROUP BY d");
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = gmdate('Y-m-d', time() - $i * 86400);
        $out[] = ['day' => $d, 'users' => $users[$d] ?? 0, 'wishes' => $wish[$d] ?? 0];
    }
    return $out;
}

/* ---------- Yönetim (sadece yönetici) ---------- */
function api_admin_stats(): void {
    require_admin();
    $n = fn(string $sql, array $a = []) => (int) qv($sql, $a, 0);
    $file = (string) env('DB_PATH', data_dir() . '/ganimet.db');
    $size = 0; foreach ([$file, $file . '-wal'] as $f) if (is_file($f)) $size += (int) filesize($f);
    $rates = null; try { $rates = currentRates(); } catch (Throwable $e) {}
    json_out([
        'users' => ['total' => $n('SELECT COUNT(*) FROM users'), 'verified' => $n('SELECT COUNT(*) FROM users WHERE email_verified = 1'),
            'new7' => $n("SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 days')"),
            'online' => $n("SELECT COUNT(DISTINCT user_id) FROM sessions WHERE expires_at > datetime('now')"),
            'digestOn' => $n('SELECT COUNT(*) FROM users WHERE weekly_digest = 1 AND email_verified = 1')],
        'wishlist' => ['total' => $n('SELECT COUNT(*) FROM wishlist'), 'users' => $n('SELECT COUNT(DISTINCT user_id) FROM wishlist'),
            'targets' => $n('SELECT COUNT(*) FROM wishlist WHERE target_price IS NOT NULL'),
            'top' => q('SELECT g.title, COUNT(*) AS n FROM wishlist w JOIN games g ON g.id = w.game_id GROUP BY w.game_id ORDER BY n DESC, g.title LIMIT 5')],
        'games' => ['total' => $n('SELECT COUNT(*) FROM games WHERE hidden = 0'), 'priced' => $n('SELECT COUNT(*) FROM games WHERE hidden = 0 AND last_priced IS NOT NULL'),
            'prices' => $n('SELECT COUNT(*) FROM prices'), 'history' => $n('SELECT COUNT(*) FROM price_history'),
            'onSale' => $n('SELECT COUNT(DISTINCT game_id) FROM lows WHERE cut > 0')],
        'mails' => ['total' => $n('SELECT COUNT(*) FROM notifications'),
            'byKind' => q("SELECT kind, COUNT(*) AS n FROM notifications WHERE sent_at >= datetime('now', '-7 days') GROUP BY kind ORDER BY n DESC"),
            'recent' => array_map(fn($r) => ['kind' => $r['kind'], 'text' => $r['text'], 'sentAt' => $r['sent_at'], 'to' => maskEmail($r['email'])],
                q('SELECT n.kind, n.text, n.sent_at, u.email FROM notifications n JOIN users u ON u.id = n.user_id ORDER BY n.id DESC LIMIT 8')),
            'smtp' => (bool) env('SMTP_HOST')],
        'daily' => dailySeries(14),
        'recentUsers' => array_map(fn($u) => ['name' => $u['name'], 'email' => maskEmail($u['email']), 'createdAt' => $u['created_at'], 'verified' => (bool) $u['email_verified']],
            q('SELECT name, email, created_at, email_verified FROM users ORDER BY id DESC LIMIT 5')),
        'system' => ['lastSync' => meta_get('last_sync'), 'ratesAt' => $rates['updatedAt'] ?? null,
            'api' => ['used' => function_exists('itadUsage') ? itadUsage() : 0, 'limit' => function_exists('itadRateLimit') ? itadRateLimit() : 0],
            'dbBytes' => $size, 'uptimeSec' => max(0, time() - (int) (meta_get('first_run') ?? time())), 'node' => 'PHP ' . PHP_VERSION],
    ]);
}
function api_digest_test(): void {
    $u = require_admin();
    $user = q1('SELECT email_verified FROM users WHERE id = ?', [$u['id']]);
    if (!$user || !$user['email_verified']) fail('unverified', 400);
    if (!q1('SELECT 1 FROM wishlist WHERE user_id = ?', [$u['id']])) fail('emptyWish', 400);
    $sent = sendWeeklyDigests(['force' => true, 'onlyUserId' => (int) $u['id']]);
    json_out(['sent' => $sent, 'smtp' => (bool) env('SMTP_HOST')]);
}
