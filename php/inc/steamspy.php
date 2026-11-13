<?php
declare(strict_types=1);
/* SteamSpy: kategori (etiket) başına oyun listesi, oyuncu değerlendirmeleri ve tahmini satış sayıları.
   Ücretsiz ve anahtarsız; kısa aralıklarla çok istek kabul etmiyor (burada istekler arası en az 2 sn). */

// Sitedeki kategoriler: anahtar = Steam etiketi (değiştirme), tr/en = gösterilen ad
const CATEGORIES = [
    ['Action', 'Aksiyon', 'Action'], ['Adventure', 'Macera', 'Adventure'], ['Puzzle', 'Bulmaca', 'Puzzle'],
    ['RPG', 'Rol yapma', 'RPG'], ['Strategy', 'Strateji', 'Strategy'], ['Simulation', 'Simülasyon', 'Simulation'],
    ['FPS', 'Nişancı', 'Shooter'], ['Horror', 'Korku', 'Horror'], ['Open World', 'Açık dünya', 'Open world'],
    ['Survival', 'Hayatta kalma', 'Survival'], ['Racing', 'Yarış', 'Racing'], ['Sports', 'Spor', 'Sports'],
    ['Multiplayer', 'Çok oyunculu', 'Multiplayer'], ['Platformer', 'Platform', 'Platformer'],
    ['Casual', 'Rahat', 'Casual'], ['Indie', 'Bağımsız', 'Indie'],
];
// SteamSpy'dan çekilecek etiketler: sitedeki kategoriler
define('FETCH_TAGS', array_column(CATEGORIES, 0));

/** "1,000,000 .. 2,000,000" → 1500000 (boş metin 0 verir, Node sürümüyle aynı) */
function parseOwners($s): ?int {
    $parts = explode('..', (string) ($s ?? ''));
    $n = array_map(fn($x) => (int) preg_replace('/\D/', '', $x), $parts);
    if (count($n) === 2) return (int) round(($n[0] + $n[1]) / 2);
    return $n[0] ?? null;
}

function spyFetchTag(string $tag): array {
    static $last = 0.0;
    $wait = 2.0 - (microtime(true) - $last);
    if ($wait > 0) {
        if ($wait > budget_left() - 1) throw new BudgetExhausted('SteamSpy için bekleyecek süre kalmadı');
        usleep((int) ($wait * 1e6));
    }
    $last = microtime(true);
    $timeout = isset($GLOBALS['__budget_end']) ? (int) max(10, min(240, budget_left() - 1)) : 240;
    $r = http_request('GET', 'https://steamspy.com/api.php?request=tag&tag=' . rawurlencode($tag), [], null, $timeout);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException("SteamSpy $tag → " . $r['status']);
    // etiket başına on binlerce oyun gelir: belleği şişirmemek için sadece gereken alanları tut
    @ini_set('memory_limit', '768M');
    $d = json_decode($r['body'], true);
    unset($r);
    $keep = ['appid' => 1, 'owners' => 1, 'positive' => 1, 'negative' => 1, 'median_forever' => 1, 'average_forever' => 1];
    $out = [];
    if (is_array($d)) {
        foreach ($d as $k => $a) {
            if (!is_array($a) || empty($a['appid'])) continue;
            $out[] = array_intersect_key($a, $keep);
            unset($d[$k]);
        }
    }
    return $out;
}
