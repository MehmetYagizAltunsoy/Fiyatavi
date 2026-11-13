<?php
declare(strict_types=1);
/* Güncel döviz kurları: günde bir çekilir. Alınamazsa veri tabanındaki son kurlar, o da yoksa yaklaşık sabit kurlar kullanılır.
   USD_RATE sabit olduğundan canlı kurlar $GLOBALS['__rates'] içinde tutulur; okumak için usdRate() kullan. */

const RATE_CODES = ['USD', 'EUR', 'GBP', 'JPY', 'BRL', 'CAD', 'AUD', 'MXN', 'CHF', 'TRY'];

function &ratesState(): array {
    if (!isset($GLOBALS['__rates'])) {
        $perUsd = []; $usd = [];
        foreach (RATE_CODES as $c) { $perUsd[$c] = 1 / USD_RATE[$c]; $usd[$c] = (float) USD_RATE[$c]; }
        $GLOBALS['__rates'] = ['perUsd' => $perUsd, 'usd' => $usd, 'updatedAt' => null];
        try {   // veri tabanında kayıtlı son kurlar varsa onları kullan
            $saved = json_decode((string) (meta_get('rates') ?? ''), true);
            if (is_array($saved) && isset($saved['rates'])) ratesApply($saved['rates'], $saved['at'] ?? null);
        } catch (Throwable $e) { /* sabit kurlarla devam */ }
    }
    return $GLOBALS['__rates'];
}

function ratesApply(array $rates, ?string $at): void {
    $s = &$GLOBALS['__rates'];
    foreach (RATE_CODES as $c) {
        if (isset($rates[$c]) && $rates[$c] > 0) { $s['perUsd'][$c] = (float) $rates[$c]; $s['usd'][$c] = 1 / (float) $rates[$c]; }
    }
    $s['updatedAt'] = $at;
}

/** 1 birim $cur = ? USD (bilinmeyen para biriminde 1) */
function usdRate(string $cur): float { $s = &ratesState(); return (float) ($s['usd'][$cur] ?? 1); }

function refreshRates(): void {
    $s = &ratesState();
    if ($s['updatedAt'] && time() - strtotime($s['updatedAt']) < 20 * 3600) return;
    $rates = null;
    try {
        $to = implode(',', array_filter(RATE_CODES, fn($c) => $c !== 'USD'));
        $rates = http_get_json("https://api.frankfurter.app/latest?from=USD&to=$to", [], 15)['rates'] ?? null;
        if (!$rates) throw new RuntimeException('boş cevap');
    } catch (Throwable $e) {
        try {
            $rates = http_get_json('https://open.er-api.com/v6/latest/USD', [], 15)['rates'] ?? null;
            if (!$rates) throw new RuntimeException('boş cevap');
        } catch (Throwable $e2) { log_line('döviz kurları alınamadı, son bilinen kurlar kullanılıyor: ' . $e2->getMessage()); return; }
    }
    $rates['USD'] = 1;
    $at = gmdate('Y-m-d\TH:i:s') . '.000Z';
    ratesApply($rates, $at);
    $s = &ratesState();
    meta_set('rates', jenc(['rates' => $s['perUsd'], 'at' => $at]));
    log_line(sprintf('döviz kurları güncellendi (1 USD = %.2f TRY)', $s['perUsd']['TRY']));
}

/** ['perUsd' => ['TRY' => 41.7, ...], 'updatedAt' => iso|null] */
function currentRates(): array { $s = &ratesState(); return ['perUsd' => $s['perUsd'], 'updatedAt' => $s['updatedAt']]; }
