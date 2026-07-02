<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Controller\Admin;

use Configuration;
use Db;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends FrameworkBundleAdminController
{
    private const COUNTRIES = ['LT', 'LV', 'EE'];
    private const TERMINAL_STALE_DAYS = 7;

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(): Response
    {
        $db = Db::getInstance();
        $orderTable = _DB_PREFIX_ . 'ppomniva_order';
        $manifestTable = _DB_PREFIX_ . 'ppomniva_manifest';
        $terminalTable = _DB_PREFIX_ . 'ppomniva_terminal';
        $warehouseTable = _DB_PREFIX_ . 'ppomniva_warehouse';
        $logTable = _DB_PREFIX_ . 'ppomniva_log';

        // Orders awaiting labels — registered with no tracking, or status=new.
        $awaitingLabels = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $orderTable . '`
             WHERE `status` IN (\'new\', \'error\')
                OR (`tracking_numbers` IS NULL OR `tracking_numbers` = \'\' OR `tracking_numbers` = \'[]\')'
        );

        $errorOrders = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $orderTable . '` WHERE `status` = \'error\''
        );

        // Open manifests + age of oldest.
        $openManifestRow = $db->getRow(
            'SELECT COUNT(*) AS total, MIN(`date_add`) AS oldest, MIN(`id_ppomniva_manifest`) AS first_id,
                    (SELECT `manifest_id` FROM `' . $manifestTable . '` WHERE `closed` = 0 ORDER BY `id_ppomniva_manifest` DESC LIMIT 1) AS latest_manifest_id
             FROM `' . $manifestTable . '`
             WHERE `closed` = 0'
        );
        $openManifests = (int) ($openManifestRow['total'] ?? 0);
        $oldestOpenManifest = $openManifestRow['oldest'] ?? null;

        // Terminal freshness per country.
        $freshness = [];
        foreach (self::COUNTRIES as $cc) {
            $row = $db->getRow(
                'SELECT COUNT(*) AS total, MAX(`date_upd`) AS last_sync
                 FROM `' . $terminalTable . '` WHERE `country_code` = \'' . pSQL($cc) . '\''
            );
            $lastSync = $row['last_sync'] ?? null;
            $state = 'empty';
            if ($lastSync) {
                $days = (new \DateTimeImmutable())->diff(new \DateTimeImmutable($lastSync))->days;
                $state = $days > self::TERMINAL_STALE_DAYS ? 'danger' : ($days > (self::TERMINAL_STALE_DAYS - 2) ? 'warn' : 'success');
            }
            $freshness[] = [
                'country' => $cc,
                'count' => (int) ($row['total'] ?? 0),
                'last_sync' => $lastSync,
                'state' => $state,
            ];
        }

        $defaultWarehouse = $db->getRow(
            'SELECT * FROM `' . $warehouseTable . '` WHERE `is_default` = 1'
        ) ?: null;

        $recentErrors = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . $logTable . '`
             WHERE `level` = \'error\' AND `date_add` >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
        );

        $latestErrors = $db->executeS(
            'SELECT `id_ppomniva_log`, `date_add`, `source`, `error_code`, `message`
             FROM `' . $logTable . '`
             WHERE `level` = \'error\'
             ORDER BY `id_ppomniva_log` DESC LIMIT 5'
        ) ?: [];

        // Setup hints
        $setup = [
            // Omniva OMX uses the client code (username) + password — there is
            // no separate API ID (that was Venipak). Don't require it.
            'api_creds' => !empty(Configuration::get('PPOMNIVA_API_USER'))
                && !empty(Configuration::get('PPOMNIVA_API_PASS')),
            'warehouse' => $defaultWarehouse !== null,
            'cod' => !empty(Configuration::get('PPOMNIVA_COD_MODULES')),
            'live_mode' => (bool) Configuration::get('PPOMNIVA_LIVE_MODE'),
        ];

        $codCount = 0;
        $codRaw = (string) Configuration::get('PPOMNIVA_COD_MODULES');
        if ($codRaw !== '') {
            $codCount = count(array_filter(array_map('trim', explode(',', $codRaw))));
        }

        return $this->render('@Modules/ppomniva/views/templates/admin/dashboard.html.twig', [
            'multistore_blocked' => false,
            'stats' => [
                'awaiting_labels' => $awaitingLabels,
                'error_orders' => $errorOrders,
                'open_manifests' => $openManifests,
                'oldest_open_manifest' => $oldestOpenManifest,
                'recent_errors' => $recentErrors,
                'cod_count' => $codCount,
            ],
            'freshness' => $freshness,
            'default_warehouse' => $defaultWarehouse,
            'latest_errors' => $latestErrors,
            'setup' => $setup,
            'open_manifest_id' => $openManifestRow['latest_manifest_id'] ?? null,
            'sync_url' => $this->generateUrl('ps_ppomniva_terminals_sync'),
        ]);
    }

}
