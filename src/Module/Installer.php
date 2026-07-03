<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Module;

use Configuration;
use Country;
use Db;
use Shop;

class Installer
{
    /**
     * Countries Omniva serves as plain domestic parcel-machine / courier.
     * Others are still allowed on the warehouse but the admin must confirm the
     * routing service.
     */
    private const SUPPORTED_COUNTRIES = ['LT', 'LV', 'EE'];

    private \Module $module;

    public function __construct(\Module $module)
    {
        $this->module = $module;
    }

    public function __invoke(): bool
    {
        $ok = $this->registerHooks()
            && $this->createTables()
            && $this->createOrderStates()
            && $this->createCarriers()
            && $this->setDefaults()
            && $this->createDefaultWarehouseFromShop()
            && $this->installCarrierOverride();

        if ($ok) {
            // The module ships Symfony admin routes/controllers. Clear the
            // Symfony cache so those routes are compiled into the router right
            // away — otherwise the first "Configure" click (which redirects to
            // ps_ppomniva_dashboard) throws RouteNotFoundException until the
            // cache is rebuilt.
            \Tools::clearSf2Cache();
        }

        return $ok;
    }

    private function registerHooks(): bool
    {
        foreach ($this->module->hooks as $hook) {
            if (!$this->module->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function createTables(): bool
    {
        $sqlFile = dirname(__FILE__, 3) . '/sql/install.sql';

        if (!file_exists($sqlFile)) {
            return false;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $db = Db::getInstance();

        foreach (explode(";\n", $sql) as $statement) {
            // Strip full-line SQL comments, then run whatever remains. (Do NOT
            // pre-filter chunks that *start* with a comment — the first chunk
            // carries the file header comments AND the first CREATE TABLE.)
            $clean = trim(preg_replace('/^\s*--.*$/m', '', $statement));
            if ($clean === '') {
                continue;
            }
            if (!$db->execute($clean)) {
                return false;
            }
        }

        return true;
    }

    private function createOrderStates(): bool
    {
        $this->module->addOrderState(
            'PPOMNIVA_STATE_READY',
            [
                'en' => 'Omniva shipment ready',
                'lt' => 'Omniva siunta paruošta',
            ],
            '#FCEAA8'
        );

        $this->module->addOrderState(
            'PPOMNIVA_STATE_ERROR',
            [
                'en' => 'Omniva shipment error',
                'lt' => 'Omniva siuntos klaida',
            ],
            '#F24017'
        );

        return true;
    }

    private function createCarriers(): bool
    {
        // Carrier logos are copied by AbstractPPCarrier::createCarriers()
        // (pp-common) from views/img/carrier_logo.png.
        $this->module->createCarriers();

        return true;
    }

    private function setDefaults(): bool
    {
        $defaults = [
            'PPOMNIVA_ENABLED' => '1',
            'PPOMNIVA_LIVE_MODE' => '0', // off = test host (test-omx.omniva.eu), on = production
            'PPOMNIVA_AGENT_ID' => '', // X-Integration-Agent-Id "XXXXXX_YYYYYY" (Omniva-issued id + version)
            'PPOMNIVA_LABEL_FORMAT' => 'a4',
            'PPOMNIVA_LABEL_COMMENT_TYPE' => '0',
            'PPOMNIVA_COD_ENABLED' => '0',
            'PPOMNIVA_COD_MODULES' => 'ps_cashondelivery',
            'PPOMNIVA_18_PLUS_SERVICE' => '0',
            'PPOMNIVA_CRON_TOKEN' => bin2hex(random_bytes(16)),
            'PPOMNIVA_LOG_RETENTION_DAYS' => '30',
            // Parcel-machine location source (public Omniva feed, EE/LV/LT).
            'PPOMNIVA_LOCATIONS_URL' => 'https://www.omniva.ee/locations.json',
            'PPOMNIVA_LOCATIONS_UPDATED' => '0',
        ];

        foreach ($defaults as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        // Global scope (shared across shops).
        Configuration::updateGlobalValue('PPOMNIVA_PACK_COUNTER', '0');
        Configuration::updateGlobalValue(
            'PPOMNIVA_MANIFEST_COUNTER',
            json_encode(['counter' => 0, 'date' => ''])
        );
        // Per-shop credentials by default; flip off from All Shops to share one set.
        Configuration::updateGlobalValue('PPOMNIVA_STORE_AUTH_MODE', '1');

        return true;
    }

    /**
     * Pre-fill the first warehouse (sender origin) from the shop contact
     * details. Idempotent — only when no warehouse exists for the active shop.
     */
    private function createDefaultWarehouseFromShop(): bool
    {
        $idShop = (int) (Shop::getContextShopID() ?: Configuration::get('PS_SHOP_DEFAULT'));
        if ($idShop <= 0) {
            $idShop = 1;
        }

        $existing = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppomniva_warehouse` WHERE `id_shop` = ' . $idShop
        );

        if ((int) $existing > 0) {
            return true;
        }

        $shopName = trim((string) Configuration::get('PS_SHOP_NAME'));
        if ($shopName === '') {
            return true;
        }

        $iso = '';
        $countryId = (int) Configuration::get('PS_SHOP_COUNTRY_ID');
        if ($countryId > 0 && class_exists('Country')) {
            $iso = strtoupper((string) Country::getIsoById($countryId));
        }
        $countryCode = $iso !== '' ? $iso : 'LT';
        if (!in_array($countryCode, self::SUPPORTED_COUNTRIES, true)) {
            $countryCode = 'LT';
        }

        $address = trim(
            (string) Configuration::get('PS_SHOP_ADDR1') . ' ' . (string) Configuration::get('PS_SHOP_ADDR2')
        );

        $payload = [
            'name' => pSQL(mb_substr($shopName, 0, 60)),
            'company_code' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_REGISTRATION_NUMBER'), 0, 16)),
            'contact' => pSQL(mb_substr($shopName, 0, 40)),
            'country_code' => pSQL($countryCode),
            'city' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_CITY'), 0, 50)),
            'address' => pSQL(mb_substr($address, 0, 255)),
            'zip_code' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_CODE'), 0, 10)),
            'phone' => pSQL(mb_substr((string) Configuration::get('PS_SHOP_PHONE'), 0, 15)),
            'id_shop' => $idShop,
            'is_default' => 1,
        ];

        return (bool) Db::getInstance()->insert('ppomniva_warehouse', $payload);
    }

    /**
     * Manage `override/classes/Carrier.php` ourselves so install doesn't fail
     * when sibling carrier modules (ppvenipak, ...) ship the same generic
     * override. See CarrierOverrideManager for the marker-based coexistence.
     */
    private function installCarrierOverride(): bool
    {
        $manager = new CarrierOverrideManager(dirname(__DIR__, 2));
        $result = $manager->install();

        if (!$result['success'] && class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog(
                'PPOmniva: ' . $result['message'],
                2,
                null,
                'CarrierOverrideManager'
            );
        }

        return true;
    }
}
