<?php

declare(strict_types=1);

namespace PrestaPro\Common\Carrier;

use Carrier;
use CarrierModule;
use Configuration;
use Context;
use Db;
use Language;
use Module;
use Tools;
use Validate;

abstract class AbstractPPCarrier extends CarrierModule
{
    /**
     * Hooks that every PrestaPro carrier module registers.
     *
     * @var string[]
     */
    public array $hooks = [
        'actionCarrierUpdate',
        'displayCarrierExtraContent',
        'displayOrderDetail',
        'displayAdminOrderSide',
        'actionOrderStatusUpdate',
        'actionValidateOrder',
        'displayBackOfficeHeader',
    ];

    /**
     * Must return the config key prefix for this module.
     * Example: 'PPVENIPAK'
     */
    abstract protected function getConfigPrefix(): string;

    /**
     * Must return carrier definitions for this module.
     * Example:
     * [
     *     'courier' => [
     *         'name' => 'Venipak Courier',
     *         'delay' => ['en' => '1-3 business days', 'lt' => '1-3 darbo dienos'],
     *         'is_free' => false,
     *         'grade' => 0,
     *         'shipping_handling' => true,
     *         'range_behavior' => 0,
     *         'is_module' => true,
     *     ],
     *     'pickup' => [ ... ],
     * ]
     */
    abstract protected function getCarrierDefinitions(): array;

    /**
     * Must return the Installer class instance.
     */
    abstract protected function getInstaller(): object;

    /**
     * Must return the Uninstaller class instance.
     */
    abstract protected function getUninstaller(): object;

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install() && ($this->getInstaller())();
    }

    public function uninstall(): bool
    {
        return ($this->getUninstaller())() && parent::uninstall();
    }

    /**
     * Redirect to Symfony configuration route.
     */
    public function getContent(): void
    {
        $route = 'ps_' . $this->name . '_configuration';
        Tools::redirectAdmin(
            $this->get('router')->generate($route)
        );
    }

    // -------------------------------------------------------------------------
    // Carrier Management
    // -------------------------------------------------------------------------

    /**
     * Create carriers defined by the module.
     *
     * @return array<string, int> Map of carrier key => carrier ID
     */
    public function createCarriers(): array
    {
        $prefix = $this->getConfigPrefix();
        $carriers = [];
        $context = Context::getContext();
        $languages = Language::getLanguages(false);

        foreach ($this->getCarrierDefinitions() as $key => $definition) {
            $carrier = new Carrier();
            $carrier->name = $definition['name'];
            $carrier->active = true;
            $carrier->is_free = $definition['is_free'] ?? false;
            $carrier->shipping_handling = $definition['shipping_handling'] ?? true;
            $carrier->range_behavior = $definition['range_behavior'] ?? 0;
            $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
            $carrier->is_module = true;
            $carrier->external_module_name = $this->name;
            $carrier->need_range = true;

            foreach ($languages as $lang) {
                $isoCode = $lang['iso_code'];
                $carrier->delay[$lang['id_lang']] = $definition['delay'][$isoCode]
                    ?? $definition['delay']['en']
                    ?? '';
            }

            if (!$carrier->add()) {
                continue;
            }

            $this->assignCarrierToAllGroups($carrier);
            $this->assignCarrierToAllZones($carrier);
            $this->createDefaultRange($carrier);

            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';
            $configKeyRef = $prefix . '_' . strtoupper($key) . '_ID_REF';

            // Written globally as well as in the current shop context. These
            // two keys are what a checkout carrier is matched against, and a
            // shop-scoped-only row makes the module invisible to every
            // sibling shop — the carrier renders, its extra content does not.
            Configuration::updateValue($configKeyId, (int) $carrier->id);
            Configuration::updateValue($configKeyRef, (int) $carrier->id);
            Configuration::updateGlobalValue($configKeyId, (int) $carrier->id);
            Configuration::updateGlobalValue($configKeyRef, (int) $carrier->id);

            $carriers[$key] = (int) $carrier->id;
        }

        $this->installCarrierLogos($carriers);

        return $carriers;
    }

    /**
     * Give each newly created carrier the module's logo so it isn't rendered
     * with PrestaShop's blank placeholder in the carrier list and at checkout.
     *
     * The module just needs to ship views/img/carrier_logo.png. The file is
     * copied to img/s/{id_carrier}.jpg — PrestaShop serves that directly and
     * copies it forward when a merchant edits the carrier (which clones it to a
     * new id). A PNG served under a .jpg name renders fine in browsers, so any
     * transparency in the source logo is preserved.
     *
     * @param array<string, int> $carrierIds
     */
    protected function installCarrierLogos(array $carrierIds): void
    {
        $logo = $this->getLocalPath() . 'views/img/carrier_logo.png';

        if (!file_exists($logo) || !defined('_PS_SHIP_IMG_DIR_')) {
            return;
        }

        foreach ($carrierIds as $idCarrier) {
            $idCarrier = (int) $idCarrier;
            if ($idCarrier > 0) {
                @copy($logo, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
            }
        }
    }

    /**
     * Soft-delete carriers on uninstall (active=0, deleted=0).
     */
    public function deleteCarriers(): bool
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyRef = $prefix . '_' . strtoupper($key) . '_ID_REF';
            $idReference = (int) Configuration::get($configKeyRef);

            if ($idReference <= 0) {
                continue;
            }

            $carriers = Carrier::getCarrierByReference($idReference);

            if (!$carriers) {
                continue;
            }

            if (!is_array($carriers)) {
                $carriers = [$carriers];
            }

            foreach ($carriers as $carrier) {
                $carrier->active = false;
                $carrier->update();
            }
        }

        return true;
    }

    /**
     * Track carrier ID changes when PS clones a carrier.
     */
    public function hookActionCarrierUpdate(array $params): void
    {
        $prefix = $this->getConfigPrefix();

        // PrestaShop fires this with `id_carrier` = the OLD (soft-deleted)
        // carrier and `carrier` = the freshly inserted replacement — see
        // AdminCarrierWizardController::processRanges(),
        // AdminCarriersController and EditCarrierHandler, which all pass
        // ['id_carrier' => $current_carrier->id, 'carrier' => $new_carrier].
        // Reading them the other way round wrote the dead id back over the
        // live one, so every back-office carrier edit quietly detached the
        // carrier from this module. id_reference needs no update: PrestaShop
        // preserves it across the swap.
        $oldCarrierId = (int) ($params['id_carrier'] ?? 0);
        $newCarrierId = (int) ($params['carrier']->id ?? 0);

        if ($oldCarrierId <= 0 || $newCarrierId <= 0) {
            return;
        }

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';

            if ((int) Configuration::get($configKeyId) === $oldCarrierId) {
                Configuration::updateValue($configKeyId, $newCarrierId);
                Configuration::updateGlobalValue($configKeyId, $newCarrierId);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Multilanguage Carrier Names
    // -------------------------------------------------------------------------

    /**
     * Get carrier display name for a specific language.
     * Falls back to ps_carrier.name if no custom name is set.
     */
    public function getCarrierName(int $idCarrier, int $idLang): string
    {
        $prefix = $this->getConfigPrefix();

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $configKeyId = $prefix . '_' . strtoupper($key) . '_ID';

            if ((int) Configuration::get($configKeyId) !== $idCarrier) {
                continue;
            }

            $nameKey = $prefix . '_' . strtoupper($key) . '_NAME_' . $idLang;
            $name = Configuration::get($nameKey);

            if (!empty($name)) {
                return $name;
            }

            break;
        }

        $carrier = new Carrier($idCarrier);

        return $carrier->name ?: '';
    }

    /**
     * Save multilanguage carrier names.
     *
     * @param string $carrierKey The carrier key (e.g. 'courier', 'pickup')
     * @param array<int, string> $names Map of id_lang => name
     */
    public function saveCarrierNames(string $carrierKey, array $names): void
    {
        $prefix = $this->getConfigPrefix();

        foreach ($names as $idLang => $name) {
            $configKey = $prefix . '_' . strtoupper($carrierKey) . '_NAME_' . $idLang;
            Configuration::updateValue($configKey, $name);
        }
    }

    // -------------------------------------------------------------------------
    // Carrier Helper Methods
    // -------------------------------------------------------------------------

    /**
     * Check if a given carrier ID belongs to this module.
     */
    public function isOwnCarrier(int $idCarrier): bool
    {
        return $this->resolveCarrierKey($idCarrier) !== null;
    }

    /**
     * Get the carrier key (e.g. 'courier', 'pickup') for a given carrier ID.
     */
    public function getCarrierKey(int $idCarrier): ?string
    {
        return $this->resolveCarrierKey($idCarrier);
    }

    /**
     * Resolve one of this module's carrier keys from whatever a checkout
     * hands to `displayCarrierExtraContent`.
     *
     * Core (DeliveryOptionsFinder) passes the presented Carrier array, which
     * carries every ObjectModel field plus `id`. Custom and one-page
     * checkouts routinely pass a hand-built array instead, naming the same
     * value `id_carrier`, or supplying only `id_reference`. Accepting all
     * three costs nothing on stock PrestaShop — the extra keys simply are
     * not there — and stops the hook returning an empty string on the
     * checkouts that do things their own way.
     *
     * @param array<string, mixed> $carrier the hook's `carrier` payload
     */
    public function getCarrierKeyFromHookParams(array $carrier): ?string
    {
        $idCarrier = (int) ($carrier['id'] ?? $carrier['id_carrier'] ?? 0);
        $idReference = (int) ($carrier['id_reference'] ?? 0);

        return $this->resolveCarrierKey($idCarrier, $idReference);
    }

    /**
     * Resolve a live carrier id to one of this module's carrier keys.
     *
     * Matches on the stable id_reference first. PrestaShop reassigns
     * id_carrier on every back-office carrier edit — it soft-deletes the old
     * row and inserts a replacement — but keeps id_reference constant.
     * Matching only on the volatile *_ID goes stale after such an edit, which
     * makes isOwnCarrier() silently miss: the carrier still shows in the
     * checkout, but its pickup-point selector never renders. Falls back to
     * *_ID for installs whose *_ID_REF was never populated.
     */
    private function resolveCarrierKey(int $idCarrier, int $idReference = 0): ?string
    {
        if ($idCarrier <= 0 && $idReference <= 0) {
            return null;
        }

        $prefix = $this->getConfigPrefix();

        if ($idReference <= 0 && $idCarrier > 0) {
            $idReference = (int) Db::getInstance()->getValue(
                'SELECT `id_reference` FROM `' . _DB_PREFIX_ . 'carrier`
                 WHERE `id_carrier` = ' . (int) $idCarrier
            );
        }

        foreach (array_keys($this->getCarrierDefinitions()) as $key) {
            $storedRef = $this->getConfigIntAnyShop($prefix . '_' . strtoupper($key) . '_ID_REF');
            $storedId = $this->getConfigIntAnyShop($prefix . '_' . strtoupper($key) . '_ID');

            if (($idReference > 0 && $storedRef === $idReference)
                || ($idCarrier > 0 && $storedId === $idCarrier)
            ) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Read an integer configuration value without being fooled by shop scope.
     *
     * Configuration::updateValue() writes a row scoped to whatever shop
     * context was active, so a value saved while installing or configuring in
     * shop A reads back empty in shop B. These keys decide whether a carrier
     * belongs to this module at all, and losing them means the checkout hook
     * renders nothing. Prefer the value for the current context, then fall
     * back to any non-zero row for the key.
     */
    private function getConfigIntAnyShop(string $key): int
    {
        $value = (int) Configuration::get($key);

        if ($value > 0) {
            return $value;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . "configuration`
             WHERE `name` = '" . pSQL($key) . "' AND CAST(`value` AS UNSIGNED) > 0
             ORDER BY IFNULL(`id_shop`, 0) DESC"
        );
    }

    /**
     * Is the front controller currently rendering a checkout page?
     *
     * Asset registration used to test `php_self` against a fixed list. A
     * ModuleFrontController leaves php_self null (Controller::$php_self has
     * no default), so every one-page-checkout module fell straight through
     * that test and the pickup-point CSS/JS never registered. Mirrors what
     * the DPD and Unisend modules do: instanceof check for core, then the
     * controller name, then the class name.
     */
    protected function isCheckoutContext(?object $controller): bool
    {
        if (!$controller) {
            return false;
        }

        // Core checkout — the only path that exists on stock PrestaShop.
        if ($controller instanceof \OrderController) {
            return true;
        }

        if (isset($controller->php_self) && (string) $controller->php_self !== '') {
            $page = (string) $controller->php_self;
        } else {
            $page = (string) Tools::getValue('controller');
        }
        $page = strtolower($page);

        if (in_array($page, ['order', 'checkout', 'order-opc', 'orderopc'], true)) {
            return true;
        }

        // One-page-checkout modules seen in the wild. Their front controllers
        // do not set php_self, so the controller parameter is matched too.
        $onePageCheckouts = [
            'supercheckout',
            'onepagecheckoutps',
            'onepagecheckout',
            'thecheckout',
            'ppcheckout',
            'steasycheckout',
            'fastcheckout',
        ];

        if (in_array($page, $onePageCheckouts, true)) {
            return true;
        }

        // Anything else that is a module front controller whose class name
        // says "checkout" — covers custom checkouts we do not know by name.
        return $controller instanceof \ModuleFrontController
            && strpos(strtolower(get_class($controller)), 'checkout') !== false;
    }

    /**
     * Get the carrier reference for a given carrier key.
     */
    public function getCarrierReference(string $carrierKey): int
    {
        $prefix = $this->getConfigPrefix();
        $configKeyRef = $prefix . '_' . strtoupper($carrierKey) . '_ID_REF';

        return (int) Configuration::get($configKeyRef);
    }

    // -------------------------------------------------------------------------
    // Back Office Header Hook
    // -------------------------------------------------------------------------

    /**
     * Load PrestaPro admin CSS/JS on configuration pages.
     */
    public function hookDisplayBackOfficeHeader(): string
    {
        $controller = Tools::getValue('controller');
        $moduleName = Tools::getValue('configure');

        if ($moduleName !== $this->name && strpos($controller, 'AdminPP') !== 0) {
            return '';
        }

        $this->context->controller->addCSS(
            $this->getPathUri() . 'views/css/prestapro-carrier.css'
        );
        $this->context->controller->addJS(
            $this->getPathUri() . 'views/js/prestapro-carrier.js'
        );

        return '';
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    private function assignCarrierToAllGroups(Carrier $carrier): void
    {
        $groups = \Group::getGroups(Context::getContext()->language->id);

        foreach ($groups as $group) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $group['id_group'],
            ]);
        }
    }

    private function assignCarrierToAllZones(Carrier $carrier): void
    {
        $zones = \Zone::getZones();

        foreach ($zones as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }
    }

    private function createDefaultRange(Carrier $carrier): void
    {
        $rangeWeight = new \RangeWeight();
        $rangeWeight->id_carrier = (int) $carrier->id;
        $rangeWeight->delimiter1 = 0;
        $rangeWeight->delimiter2 = 100;
        $rangeWeight->add();
    }
}
