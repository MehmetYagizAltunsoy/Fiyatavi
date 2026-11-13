<?php
declare(strict_types=1);
/* IsThereAnyDeal API istemcisi — https://docs.isthereanydeal.com/
   Node sürümünden farkı: istek zamanları bellekte değil veri tabanında (meta: itad_sent) tutulur;
   limit dolunca bekleyemeyecek kadar az süre kalmışsa BudgetExhausted fırlatılır, işi çağıran bırakıp sonra devam eder. */

const ITAD_BASE = 'https://api.isthereanydeal.com';
const ITAD_WINDOW_MS = 5 * 60 * 1000;

/** Bütçe/limit yüzünden iş sonraya bırakıldı (hata değil; çağıran temiz çıkıp sonraki turda devam eder) */
class BudgetExhausted extends RuntimeException {}

/** 5 dakikada ITAD_RATE_LIMIT istek (varsayılan 100); güvenli payla bunun %90'ını kullanır. */
function itadRateLimit(): int { return max(1, (int) floor(env_int('ITAD_RATE_LIMIT', 100) * 0.9)); }

function itadSentLoad(): array {
    $v = json_decode((string) (meta_get('itad_sent') ?? ''), true);
    return is_array($v) ? array_values(array_map('intval', $v)) : [];
}
function itadSentSave(array $sent): void { meta_set('itad_sent', json_encode(array_values($sent))); }

/** Son 5 dakikada kullanılan istek sayısı (arka plan işleri limiti kullanıcılara bırakmak için bakar) */
function itadUsage(): int {
    $now = (int) (microtime(true) * 1000);
    return count(array_filter(itadSentLoad(), fn($t) => $now - $t < ITAD_WINDOW_MS));
}

/** Bir bekleme için en fazla kaç ms beklenebilir: bütçe varsa kalanı, web isteğinde 8 sn, bütçesiz CLI'da sınırsız */
function itadMaxWaitMs(): int {
    if (isset($GLOBALS['__budget_end'])) return (int) max(0, (budget_left() - 2) * 1000);
    return PHP_SAPI === 'cli' ? PHP_INT_MAX : 8000;
}
function itadTimeout(): int {
    if (isset($GLOBALS['__budget_end'])) return (int) max(5, min(25, budget_left() - 1));
    return 15;
}

/** Pencere doluysa en eski istek pencereden çıkana kadar bekler; bekleyemiyorsa BudgetExhausted */
function itadThrottle(): void {
    for (;;) {
        $now = (int) (microtime(true) * 1000);
        $sent = array_values(array_filter(itadSentLoad(), fn($t) => $now - $t < ITAD_WINDOW_MS));
        if (count($sent) < itadRateLimit()) { $sent[] = $now; itadSentSave($sent); return; }
        $wait = ITAD_WINDOW_MS - ($now - $sent[0]) + 250;
        itadSentSave($sent);
        if ($wait > itadMaxWaitMs()) throw new BudgetExhausted('API limiti dolu, ' . (int) ceil($wait / 1000) . ' sn sonra tekrar denenecek');
        log_line('API limiti: ' . (int) ceil($wait / 1000) . ' sn bekleniyor…');
        usleep((int) ($wait * 1000));
    }
}

/** Beklemeyi bütçeye sığıyorsa yapar, sığmıyorsa BudgetExhausted fırlatır */
function itadPause(int $seconds): void {
    if ($seconds * 1000 > itadMaxWaitMs()) throw new BudgetExhausted("API $seconds sn bekletiyor, sonra tekrar denenecek");
    sleep($seconds);
}

function itadCall(string $path, array $query = [], $body = null, int $attempt = 1) {
    itadThrottle();
    $q = ['key' => env('ITAD_API_KEY', '')];
    foreach ($query as $k => $v) if ($v !== null) $q[$k] = $v;
    $url = ITAD_BASE . $path . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    $res = http_request($body !== null ? 'POST' : 'GET', $url, $body !== null ? ['Content-Type: application/json'] : [],
        $body !== null ? jenc($body) : null, itadTimeout());
    $st = $res['status'];
    if ($st === 429 && $attempt < 4) {
        log_line('API limiti aşıldı, 60 sn sonra tekrar denenecek…');   // Retry-After başlığı http_request'ten dönmüyor: varsayılan 60
        itadPause(60);
        return itadCall($path, $query, $body, $attempt + 1);
    }
    if ($st >= 500 && $attempt < 4) {
        itadPause(2 * $attempt);
        return itadCall($path, $query, $body, $attempt + 1);
    }
    if ($st === 401 || $st === 403) {
        throw new RuntimeException("API anahtarı kabul edilmedi (kod $st): " . mb_substr($res['body'], 0, 200) . "\n  ITAD_API_KEY değerini .env dosyasında kontrol et.");
    }
    if ($st < 200 || $st >= 300) throw new RuntimeException("ITAD $path → $st: " . mb_substr($res['body'], 0, 300));
    return json_decode($res['body'], true);
}

function itadLookup(?int $appid = null, ?string $title = null) { return itadCall('/games/lookup/v1', ['appid' => $appid, 'title' => $title]); }
function itadInfo(string $id) { return itadCall('/games/info/v2', ['id' => $id]); }
function itadPrices(array $ids, string $country) { return itadCall('/games/prices/v3', ['country' => $country], array_values($ids)); }
function itadHistory(string $id, string $country, string $since) { return itadCall('/games/history/v2', ['id' => $id, 'country' => $country, 'since' => $since]); }
function itadMostPopular(int $offset = 0, int $limit = 100) { return itadCall('/stats/most-popular/v1', ['offset' => $offset, 'limit' => $limit]); }
/** Steam numaralarını IsThereAnyDeal kimliklerine toplu çevirir: { "app/620": "id" | null } */
function itadLookupSteam(array $appids) { return itadCall('/lookup/id/shop/61/v1', [], array_map(fn($a) => "app/$a", array_values($appids))); }
/** IsThereAnyDeal kimliklerinden Steam numaralarına: { "<id>": ["app/620", ...] } */
function itadSteamIdsOf(array $ids) { return itadCall('/lookup/shop/61/id/v1', [], array_values($ids)); }
function itadMostWaitlisted(int $offset = 0, int $limit = 100) { return itadCall('/stats/most-waitlisted/v1', ['offset' => $offset, 'limit' => $limit]); }
function itadMostCollected(int $offset = 0, int $limit = 100) { return itadCall('/stats/most-collected/v1', ['offset' => $offset, 'limit' => $limit]); }
function itadSearch(string $title, int $results = 20) { return itadCall('/games/search/v1', ['title' => $title, 'results' => $results]); }
