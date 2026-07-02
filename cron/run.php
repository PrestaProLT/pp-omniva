<?php

declare(strict_types=1);

/**
 * Server-side CLI entry point for ppomniva maintenance (parcel-machine refresh
 * + log cleanup). Uncapped — use this from system cron for the full sync.
 *
 * Usage:
 *   php /var/www/html/modules/ppomniva/cron/run.php
 *
 * Crontab (every 6h):
 *   0 *\/6 * * * /usr/bin/php /var/www/html/modules/ppomniva/cron/run.php >> /var/log/ppomniva-cron.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('CLI only');
}

// modules/ppomniva/cron/run.php → PS root is three levels up.
$root = dirname(__DIR__, 3);
require $root . '/config/config.inc.php';

use PrestaShop\Module\PPOmniva\Cron\MaintenanceRunner;

$module = Module::getInstanceByName('ppomniva');
if (!$module) {
    fwrite(STDERR, "ppomniva module not found\n");
    exit(1);
}

$result = (new MaintenanceRunner())->run();
fwrite(STDOUT, json_encode($result) . PHP_EOL);
exit($result['ok'] ? 0 : 1);
