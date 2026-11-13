<?php
declare(strict_types=1);
/* Çok basit test koşucusu:  php php/test/run.php   (her *Test.php dosyası kendi geçici veri tabanını kullanır) */
$files = glob(__DIR__ . '/*Test.php');
$fail = 0; $pass = 0;
foreach ($files as $f) {
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    echo implode("\n", $out), "\n";
    if ($code !== 0) $fail++; else $pass++;
}
echo "\n", $pass, " dosya geçti, ", $fail, " dosya kaldı\n";
exit($fail ? 1 : 0);
