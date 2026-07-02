<?php

declare(strict_types=1);

use PrestaShop\Module\PPOmniva\Cron\MaintenanceRunner;

/**
 * HTTP cron endpoint (token-guarded) for convenience / cron services.
 *
 * URL: /index.php?fc=module&module=ppomniva&controller=cron&token=XXXX
 *
 * NOTE: behind CloudFlare/reverse proxies this is capped at ~100s. For the full
 * parcel-machine refresh use the server-side CLI entry point cron/run.php.
 */
class ppomnivaCronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function postProcess(): void
    {
        $token = (string) Tools::getValue('token');
        $expected = (string) Configuration::get('PPOMNIVA_CRON_TOKEN');

        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            exit('Invalid token');
        }

        $result = (new MaintenanceRunner())->run();

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}
