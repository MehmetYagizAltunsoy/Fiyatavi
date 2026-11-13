<?php
declare(strict_types=1);
/* Zamanlayıcı: Node sürümündeki tick() (setInterval) yerine. PHP'de sürekli çalışan süreç olmadığı için iki yoldan tetiklenir:
   1) cron.php (sunucu cron'u ya da cron-job.org gibi ücretsiz bir servis) — önerilen
   2) cron yoksa: ziyaretçi isteğinin cevabı gönderildikten sonra "tembel" tur (LAZY_TICK=1).
   Her tur zaman bütçelidir; yarım kalan iş bir sonraki turda kaldığı yerden devam eder. */

/** Günlük veri tabanı yedeği: yedek/ klasörüne tarihli kopya; son 7 gün tutulur. VACUUM INTO canlı DB'den tutarlı kopya alır. */
function dailyBackup(): void {
    $dirEnv = (string) env('BACKUP_DIR', '');
    if ($dirEnv === 'off') return;
    $dir = $dirEnv !== '' ? $dirEnv : data_dir() . '/yedek';
    $file = $dir . '/ganimet-' . gmdate('Y-m-d') . '.db';
    if (is_file($file)) return;
    try {
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        db()->exec("VACUUM INTO '" . str_replace("'", "''", $file) . "'");
        $old = array_values(array_filter(scandir($dir) ?: [], fn($f) => preg_match('/^ganimet-\d{4}-\d{2}-\d{2}\.db$/', $f)));
        sort($old);
        foreach (array_slice($old, 0, -7) as $f) @unlink("$dir/$f");
        log_line("yedek alındı: $file");
    } catch (Throwable $e) { log_line('yedek alınamadı: ' . $e->getMessage()); }
}

/** Bir zamanlayıcı turu. Başka bir tur çalışıyorsa hemen çıkar. @return string durum satırı */
function tick(?int $budget = null): string {
    $lock = @fopen(data_dir() . '/tick.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 'başka bir tur çalışıyor';
    // komut satırında (kendi bilgisayarın) süre sınırı yok: tur uzun sürer, böylece fiyatlar çok daha hızlı dolar; webde kısa kalır
    $budget = $budget ?? env_int('TICK_BUDGET', PHP_SAPI === 'cli' ? 240 : 25);
    @set_time_limit($budget + 20);
    ignore_user_abort(true);
    budget_start((float) $budget);
    meta_set('last_tick_at', (string) time());
    $log = [];
    $steps = [
        'yedek' => fn() => dailyBackup(),
        'kur' => fn() => function_exists('refreshRates') ? refreshRates() : null,
        'engel' => fn() => function_exists('purgeBlocked') ? purgeBlocked() : null,
        'senkron' => fn() => function_exists('runSync') ? runSync() : null,
        'özet' => fn() => function_exists('sendWeeklyDigests') ? sendWeeklyDigests() : null,
        'oturum' => fn() => cleanupSessions(),
        'mağaza' => fn() => function_exists('checkShops') ? checkShops() : null,
        'arka plan' => fn() => function_exists('startBackgroundWork') ? startBackgroundWork() : null,
    ];
    foreach ($steps as $name => $fn) {
        if (!budget_ok(1.0) && !in_array($name, ['oturum', 'yedek'], true)) { $log[] = "$name: süre doldu, sonraya kaldı"; continue; }
        try { $fn(); $log[] = "$name: tamam"; }
        catch (Throwable $e) {
            $log[] = "$name: hata";
            log_line('Senkronizasyon hatası (' . $name . '): ' . $e->getMessage() . ($e->getPrevious() ? ' — ' . $e->getPrevious()->getMessage() : ''));
        }
    }
    unset($GLOBALS['__budget_end']);
    meta_set('last_tick_end', (string) time());
    flock($lock, LOCK_UN); fclose($lock);
    return implode(', ', $log);
}

/** Cron yokken: cevap gönderildikten sonra, son turun üzerinden TICK_MINUTES geçtiyse bir tur çalıştır. */
function maybeLazyTick(): void {
    if (env('LAZY_TICK', '1') !== '1' || PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') return;
    // ekranda görünen ama fiyatı henüz çekilmemiş oyunlar (kuyruk): en fazla 20 saniyede bir, kısa bir bütçeyle işle
    if (function_exists('processPriority') && (int) qv('SELECT COUNT(*) FROM queue', [], 0) > 0 && time() - (int) (meta_get('last_priority_at') ?? 0) > 20) {
        meta_set('last_priority_at', (string) time());
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
        budget_start(12.0); @set_time_limit(30);
        try { processPriority(); } catch (Throwable $e) { log_line('kuyruk: ' . $e->getMessage()); }
        unset($GLOBALS['__budget_end']);
    }
    $every = max(1, env_int('TICK_MINUTES', 10)) * 60;
    $last = (int) (meta_get('last_tick_at') ?? 0);
    if (time() - $last < $every) return;
    meta_set('last_tick_at', (string) time());   // aynı anda gelen diğer ziyaretçiler turu tekrar başlatmasın
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { while (ob_get_level() > 0) @ob_end_flush(); @flush(); }
    tick();
}
