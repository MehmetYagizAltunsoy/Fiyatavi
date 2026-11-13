<?php
declare(strict_types=1);
/* Tüm modülleri yükler. Hem web (index.php) hem cron/CLI hem testler bunu kullanır. */
mb_internal_encoding('UTF-8');
date_default_timezone_set('UTC');
foreach (['config', 'util', 'http', 'db', 'auth', 'itad', 'steam', 'steamspy', 'steamstore', 'rates', 'shops', 'sync',
          'mailer', 'mailtemplate', 'accountmail', 'notify', 'api', 'tick'] as $m) {
    $f = __DIR__ . "/$m.php";
    if (is_file($f)) require_once $f;
}
