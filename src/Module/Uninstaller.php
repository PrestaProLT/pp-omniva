<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Module;

use Configuration;
use Db;

class Uninstaller
{
    /**
     * Config keys kept across uninstall so a re-install reuses the same
     * order-state rows and carrier references instead of orphaning them.
     */
    private const PRESERVED_KEYS = [
        'PPOMNIVA_STATE_READY',
        'PPOMNIVA_STATE_ERROR',
        'PPOMNIVA_COURIER_ID_REF',
        'PPOMNIVA_PICKUP_ID_REF',
    ];

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        return $this->deleteCarriers()
            && $this->unregisterHooks()
            && $this->deleteConfigurations()
            && $this->dropTables()
            && $this->releaseCarrierOverride();
    }

    private function deleteCarriers(): bool
    {
        // AbstractPPCarrier soft-deletes (deleted=1) so old orders keep their
        // carrier reference.
        if (method_exists($this->module, 'deleteCarriers')) {
            $this->module->deleteCarriers();
        }

        return true;
    }

    private function unregisterHooks(): bool
    {
        foreach ($this->module->hooks as $hook) {
            $this->module->unregisterHook($hook);
        }

        return true;
    }

    private function deleteConfigurations(): bool
    {
        $rows = Db::getInstance()->executeS(
            "SELECT DISTINCT `name` FROM `" . _DB_PREFIX_ . "configuration` WHERE `name` LIKE 'PPOMNIVA_%'"
        ) ?: [];

        foreach ($rows as $row) {
            $key = (string) $row['name'];
            if (in_array($key, self::PRESERVED_KEYS, true)) {
                continue;
            }
            Configuration::deleteByName($key);
        }

        return true;
    }

    private function dropTables(): bool
    {
        $sqlFile = dirname(__FILE__, 3) . '/sql/uninstall.sql';

        if (!file_exists($sqlFile)) {
            return true;
        }

        $sql = str_replace('PREFIX_', _DB_PREFIX_, file_get_contents($sqlFile));
        $db = Db::getInstance();

        foreach (explode(";\n", $sql) as $statement) {
            $clean = trim(preg_replace('/^--.*$/m', '', $statement));
            if ($clean === '') {
                continue;
            }
            $db->execute($clean);
        }

        return true;
    }

    /**
     * Leave the shared Carrier override in place — sibling carrier modules may
     * still depend on it. CarrierOverrideManager decides whether to strip our
     * marker only when we are the last owner.
     */
    private function releaseCarrierOverride(): bool
    {
        $manager = new CarrierOverrideManager(dirname(__DIR__, 2));
        $manager->uninstall();

        return true;
    }
}
