<?php
declare(strict_types=1);
/* Steam mağazasının herkese açık uç noktaları: oyuncu değerlendirmeleri, fragmanlar, ekran görüntüleri, sistem gereksinimleri */

const STEAM_UA = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Fiyatavi/1.0', 'Accept-Language: en'];

/** ['pos' => int, 'neg' => int] — Steam'deki tüm dillerdeki değerlendirmeler; yoksa null */
function steamReviews($appid): ?array {
    $r = http_request('GET', "https://store.steampowered.com/appreviews/$appid?json=1&language=all&purchase_type=all&num_per_page=1&filter=summary", STEAM_UA, null, 15);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException("Steam değerlendirmeleri $appid → " . $r['status']);
    $q = json_decode($r['body'], true)['query_summary'] ?? null;
    if (!$q) return null;
    return ['pos' => (int) ($q['total_positive'] ?? 0), 'neg' => (int) ($q['total_negative'] ?? 0)];
}

/** Steam'in gereksinim HTML'ini düz satırlara çevir: [['k' => 'OS', 'v' => 'Windows 10'], ...] */
function steamParseReq($html): array {
    if (!$html || !is_string($html)) return [];
    static $ent = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', '#39' => "'", 'nbsp' => ' ', 'reg' => '®', 'trade' => '™', 'copy' => '©'];
    $text = preg_replace('~<br\s*/?>~i', "\n", $html);
    $text = preg_replace('~</li>~i', "\n", $text);
    $text = preg_replace('~<li[^>]*>~i', "\n", $text);
    $text = preg_replace('~<[^>]+>~', '', $text);
    $text = preg_replace_callback('~&(#?\w+);~', function ($m) use ($ent) {
        $e = $m[1];
        if (isset($ent[$e])) return $ent[$e];
        if ($e[0] === '#' && ctype_digit(substr($e, 1))) return mb_chr((int) substr($e, 1), 'UTF-8') ?: $m[0];
        return $m[0];
    }, $text);
    $out = [];
    foreach (explode("\n", $text) as $l) {
        $l = trim((string) preg_replace('~\s+~u', ' ', $l));
        if ($l === '' || preg_match('~^(minimum|recommended|en az|önerilen)\s*:?$~iu', $l)) continue;
        $i = mb_strpos($l, ':');
        $x = ($i !== false && $i > 0 && $i < 40)
            ? ['k' => trim(mb_substr($l, 0, $i)), 'v' => trim(mb_substr($l, $i + 1))]
            : ['k' => '', 'v' => $l];
        if ($x['v'] !== '' || $x['k'] !== '') $out[] = $x;
    }
    return array_slice($out, 0, 14);
}

function steamAppdetails($appid, ?string $cc = null): ?array {
    $r = http_request('GET', "https://store.steampowered.com/api/appdetails?appids=$appid&l=english" . ($cc ? "&cc=$cc" : ''), STEAM_UA, null, 15);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException("Steam ayrıntıları $appid → " . $r['status']);
    $j = json_decode($r['body'], true);
    $d = is_array($j) ? ($j[$appid] ?? $j[(string) $appid] ?? null) : null;
    return is_array($d) ? ($d['data'] ?? null) : null;
}

function steamPickMovie(array $d): ?array {
    $movies = $d['movies'] ?? [];
    foreach ($movies as $m) if (!empty($m['highlight'])) return $m;
    return $movies[0] ?? null;
}

/** Oyunun fragmanı, ekran görüntüleri ve sistem gereksinimleri */
function steamDetails($appid): array {
    // bazı oyunlar Türkiye'den bakınca "bu bölgede yok" görünüyor; o zaman ABD mağazasından dene
    $d = steamAppdetails($appid) ?? steamAppdetails($appid, 'us');
    if (!$d) return ['video' => null, 'poster' => null, 'shots' => [], 'reqMin' => [], 'reqRec' => [], 'empty' => true];
    $m = steamPickMovie($d);
    // Steam artık çoğu oyunda doğrudan MP4/WebM vermiyor, sadece akış biçimi (HLS) veriyor
    $direct = $m['mp4']['480'] ?? $m['mp4']['max'] ?? $m['webm']['480'] ?? $m['webm']['max'] ?? null;
    $video = $direct ?? ($m['hls_h264'] ?? null);
    $pcr = $d['pc_requirements'] ?? [];
    $pc = (is_array($pcr) && !array_is_list($pcr)) ? $pcr : [];
    $shots = [];
    foreach (array_slice($d['screenshots'] ?? [], 0, 6) as $x) if (!empty($x['path_full'])) $shots[] = $x['path_full'];
    return [
        'shots' => $shots,
        'video' => $video ? preg_replace('~^http:~', 'https:', (string) $video) : null,
        'poster' => $d['screenshots'][0]['path_full'] ?? ($m['thumbnail'] ?? ($d['header_image'] ?? null)),
        'reqMin' => steamParseReq($pc['minimum'] ?? null), 'reqRec' => steamParseReq($pc['recommended'] ?? null),
    ];
}

/** (eski) sadece fragman */
function steamTrailer($appid): ?array {
    try { $d = steamAppdetails($appid); } catch (Throwable $e) { return null; }
    if (!$d) return null;
    $m = steamPickMovie($d);
    $video = $m['mp4']['480'] ?? $m['mp4']['max'] ?? $m['webm']['480'] ?? $m['webm']['max'] ?? null;
    $poster = $d['screenshots'][0]['path_full'] ?? ($m['thumbnail'] ?? ($d['header_image'] ?? null));
    if (!$video && !$poster) return null;
    return ['video' => $video ? preg_replace('~^http:~', 'https:', (string) $video) : null, 'poster' => $poster];
}
