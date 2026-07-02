<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Cron;

use Configuration;
use PrestaShop\Module\PPOmniva\Carrier\TerminalSync;
use PrestaShop\Module\PPOmniva\Service\ApiLogger;

/**
 * Shared maintenance runner invoked by BOTH the HTTP cron front controller and
 * the CLI entry point (cron/run.php). Keep all logic here so the two entry
 * points stay thin.
 *
 * The parcel-machine feed refresh can exceed the ~100s reverse-proxy timeout,
 * so the server-side CLI path is the supported way to run it (see cron/run.php).
 */
class MaintenanceRunner
{
    /**
     * @return array{ok: bool, terminals: int, logs_deleted: int}
     */
    public function run(): array
    {
        @set_time_limit(0);

        $terminals = 0;
        // Refresh parcel-machine locations at most once per 24h.
        $updated = (int) Configuration::get('PPOMNIVA_LOCATIONS_UPDATED');
        if ($updated === 0 || (time() - $updated) >= 24 * 3600) {
            $terminals = (new TerminalSync())->syncAll();
        }

        $logsDeleted = ApiLogger::cleanup((int) Configuration::get('PPOMNIVA_LOG_RETENTION_DAYS'));

        return ['ok' => true, 'terminals' => $terminals, 'logs_deleted' => $logsDeleted];
    }
}
