<?php
declare(strict_types=1);
/* Dış servislere (ITAD, Steam, kur servisi) HTTP isteği. Ağ hatasında RuntimeException fırlatır. */

/**
 * @return array{status:int, body:string}
 * $headers: ["Content-Type: application/json", ...]; $body: string ya da null
 */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array {
    // testlerde gerçek ağ yerine sahte cevap: $GLOBALS['__http_stub'] = fn($method,$url,$headers,$body) => ['status'=>200,'body'=>'{}'];
    if (isset($GLOBALS['__http_stub']) && is_callable($GLOBALS['__http_stub'])) return ($GLOBALS['__http_stub'])($method, $url, $headers, $body);
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: Fiyatavi/1.0 (+' . site_url() . ')'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $h,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $proxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
    if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);
    $out = curl_exec($ch);
    if ($out === false) {
        $err = curl_error($ch); $no = curl_errno($ch);
        curl_close($ch);
        throw new RuntimeException("bağlantı kurulamadı ($no): $err", $no);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string) $out];
}

/** JSON döndüren GET; 2xx değilse RuntimeException (kod = HTTP durumu). */
function http_get_json(string $url, array $headers = [], int $timeout = 20) {
    $r = http_request('GET', $url, $headers, null, $timeout);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException('HTTP ' . $r['status'] . ' ' . $url, $r['status']);
    $j = json_decode($r['body'], true);
    if ($j === null && trim($r['body']) !== 'null') throw new RuntimeException('JSON okunamadı: ' . $url);
    return $j;
}
function http_post_json(string $url, $data, array $headers = [], int $timeout = 30) {
    $r = http_request('POST', $url, array_merge(['Content-Type: application/json'], $headers), jenc($data), $timeout);
    if ($r['status'] < 200 || $r['status'] >= 300) throw new RuntimeException('HTTP ' . $r['status'] . ' ' . $url, $r['status']);
    $j = json_decode($r['body'], true);
    return $j;
}
