<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Hooks;

use Address;
use Cart;
use Carrier;
use Configuration;
use Country;
use Db;
use PrestaShop\Module\PPOmniva\Api\OmnivaApiClient;
use PrestaShop\Module\PPOmniva\Carrier\TerminalSync;
use Tools;
use Validate;

trait CheckoutHooks
{
    /**
     * actionFrontControllerSetMedia hook
     *
     * Registers the pickup-point selector JS + CSS, plus the bundled Leaflet
     * build, on checkout pages. The page test lives in
     * AbstractPPCarrier::isCheckoutContext(): matching php_self against a
     * fixed list missed every one-page-checkout module, because a
     * ModuleFrontController leaves php_self null. The markup itself no longer
     * depends on these assets — the terminal list is rendered server-side --
     * so a miss here now costs the map, not the selector.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        $controller = $this->context->controller ?? null;

        if (!$this->isCheckoutContext($controller)) {
            return;
        }

        // Leaflet is bundled rather than pulled from unpkg.com at runtime: a
        // CDN fetch dies silently behind a shop's Content-Security-Policy or
        // restricted egress, and took the map down with it.
        $controller->registerStylesheet(
            'modules-ppomniva-leaflet',
            'modules/ppomniva/views/css/lib/leaflet.css',
            ['priority' => 190]
        );

        $controller->registerJavascript(
            'modules-ppomniva-leaflet',
            'modules/ppomniva/views/js/lib/leaflet.js',
            ['priority' => 190]
        );

        $controller->registerStylesheet(
            'modules-ppomniva-checkout',
            'modules/ppomniva/views/css/front/checkout.css',
            ['priority' => 200]
        );

        $controller->registerJavascript(
            'modules-ppomniva-checkout',
            'modules/ppomniva/views/js/front/checkout.js',
            ['priority' => 200]
        );
    }

    /**
     * Server-side filter for the checkout payment-options list.
     *
     * Fires from PaymentOptionsFinder::present() before the storefront
     * controller hands options to any theme template, so filtering here
     * works identically across Classic, Hummingbird and any other
     * theme — no JS, no theme-specific selectors, can't be bypassed by
     * disabling JavaScript or editing the DOM.
     *
     * Removes any payment module listed in PPOMNIVA_COD_MODULES when:
     *   - the cart's carrier is the Omniva pickup carrier, AND
     *   - the selected terminal has cod_enabled=0 (Locker, or a Shop the
     *     merchant hasn't enabled for COD).
     *
     * The hook receives `paymentOptions` by reference; we mutate it
     * directly so the change propagates back to the finder.
     */
    public function hookActionPresentPaymentOptions(array &$params): void
    {
        if (!isset($params['paymentOptions']) || !is_array($params['paymentOptions'])) {
            return;
        }

        $cart = $this->context->cart ?? null;
        if (!$cart || !Validate::isLoadedObject($cart) || !$cart->id_carrier) {
            return;
        }

        $carrier = new Carrier((int) $cart->id_carrier);
        if (!$carrier->id) {
            return;
        }

        $pickupRef = (int) Configuration::get('PPOMNIVA_PICKUP_ID_REF');
        if ($pickupRef <= 0 || (int) $carrier->id_reference !== $pickupRef) {
            return; // not a pickup-carrier order — nothing to filter
        }

        $row = Db::getInstance()->getRow(
            'SELECT `terminal_info` FROM `' . _DB_PREFIX_ . 'ppomniva_order`
             WHERE `id_cart` = ' . (int) $cart->id
        );

        if (!$row || empty($row['terminal_info'])) {
            return;
        }

        $terminal = json_decode((string) $row['terminal_info'], true);
        if (!is_array($terminal) || (int) ($terminal['cod_enabled'] ?? 0) === 1) {
            return; // terminal accepts COD — let everything through
        }

        $codModulesRaw = (string) Configuration::get('PPOMNIVA_COD_MODULES');
        $codModules = array_values(array_filter(array_map('trim', explode(',', $codModulesRaw))));
        if (empty($codModules)) {
            $codModules = ['ps_cashondelivery'];
        }

        // PaymentOptionsFinder::present() returns the options keyed by
        // module name, so removal is a direct unset.
        foreach ($codModules as $module) {
            if (isset($params['paymentOptions'][$module])) {
                unset($params['paymentOptions'][$module]);
            }
        }
    }

    /**
     * displayCarrierExtraContent hook
     * Renders pickup point selector OR courier extra fields depending on carrier type.
     */
    public function hookDisplayCarrierExtraContent(array $params): string
    {
        $carrier = is_array($params['carrier'] ?? null) ? $params['carrier'] : [];

        // Resolved through the shared helper so the module also answers
        // checkouts that hand over `id_carrier` / `id_reference` instead of
        // core's presented-Carrier `id`.
        $carrierKey = $this->getCarrierKeyFromHookParams($carrier);

        if ($carrierKey === 'pickup') {
            return $this->renderPickupSelector($params);
        }

        if ($carrierKey === 'courier') {
            return $this->renderCourierExtraFields($params);
        }

        return '';
    }

    /**
     * actionValidateOrder hook
     * Saves order data (weight, COD, warehouse) to ppomniva_order.
     */
    public function hookActionValidateOrder(array $params): void
    {
        $order = $params['order'];
        $cart = new Cart($order->id_cart);
        $carrier = new Carrier($order->id_carrier);

        if (!$this->isOwnCarrier((int) $carrier->id)) {
            return;
        }

        // Get weight in kg (convert from g if PS_WEIGHT_UNIT == 'g')
        $weight = (float) $order->getTotalWeight();
        if (strtolower((string) Configuration::get('PS_WEIGHT_UNIT')) === 'g') {
            $weight /= 1000;
        }

        // Detect COD
        $codModules = array_map('trim', explode(',', Configuration::get('PPOMNIVA_COD_MODULES') ?: 'ps_cashondelivery'));
        $isCod = in_array($order->module, $codModules, true);
        $codAmount = $isCod ? (float) $order->total_paid_tax_incl : 0.0;

        // Get delivery country
        $address = new Address($order->id_address_delivery);
        $country = new Country($address->id_country);

        // Default warehouse
        $warehouseId = $this->getDefaultWarehouseId();

        // Update ppomniva_order (row should already exist from checkout AJAX saves)
        $db = Db::getInstance();
        $exists = (int) $db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ppomniva_order`
             WHERE id_cart = ' . (int) $order->id_cart
        );

        $data = [
            'id_order' => (int) $order->id,
            'order_weight' => $weight,
            'cod_amount' => $codAmount,
            'is_cod' => $isCod ? 1 : 0,
            'id_warehouse' => $warehouseId,
            'country_code' => pSQL($country->iso_code),
            'id_carrier' => (int) $carrier->id_reference,
        ];

        if ($exists) {
            $db->update('ppomniva_order', $data, 'id_cart = ' . (int) $order->id_cart);
        } else {
            $data['id_cart'] = (int) $order->id_cart;
            $data['status'] = 'new';
            $db->insert('ppomniva_order', $data);
        }
    }

    private function renderPickupSelector(array $params): string
    {
        $carrier = is_array($params['carrier'] ?? null) ? $params['carrier'] : [];

        // Get cart delivery address country
        $cart = $this->context->cart;
        $address = new Address($cart->id_address_delivery);
        $country = new Country($address->id_country);
        $countryCode = $country->iso_code;

        // Get currently selected terminal (if any)
        $selectedTerminal = $this->getSelectedTerminal((int) $cart->id);

        // Build AJAX URL
        $ajaxUrl = $this->context->link->getModuleLink('ppomniva', 'ajax', [], true);

        // Terminals are resolved here, server-side, and rendered into the
        // template. Previously the template shipped an empty container and the
        // browser fetched the list over AJAX — which meant a blocked asset, a
        // checkout that never ran our JS, or an unreachable module route all
        // produced the same thing on screen: nothing at all.
        $terminals = $this->getCheckoutTerminals((string) $countryCode);

        $this->context->smarty->assign([
            'ppomniva_ajax_url' => $ajaxUrl,
            'ppomniva_country_code' => $countryCode,
            'ppomniva_postcode' => trim((string) $address->postcode),
            'ppomniva_selected_terminal' => $selectedTerminal,
            'ppomniva_carrier_id' => (int) ($carrier['id'] ?? $carrier['id_carrier'] ?? 0),
            'ppomniva_i18n' => json_encode($this->getFrontI18n()),
            'ppomniva_terminals_by_city' => $this->groupTerminalsByCity($terminals),
            'ppomniva_terminals_json' => json_encode(
                array_values($terminals),
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ),
        ]);

        return $this->context->smarty->fetch(
            'module:ppomniva/views/templates/hook/displayCarrierExtraContent_pickup.tpl'
        );
    }

    /**
     * Translations for the strings that checkout.js renders on the client. The
     * English source doubles as the lookup key AND the fallback, so the JS keeps
     * working if a key is missing from a locale. Passed to the template as a
     * JSON data-i18n attribute on the pickup container.
     *
     * @return array<string, string>
     */
    private function getFrontI18n(): array
    {
        $d = self::MODULE_SHOP_DOMAIN;
        $keys = [
            'No terminals found.',
            'Loading terminals…',
            'Failed to load terminals.',
            'Failed to save selection.',
            'Enter a postcode.',
            'Terminals are still loading…',
            'Locating…',
            'Could not locate postcode.',
            'Locker',
            'Shop',
            'Cash on Delivery available',
            'No Cash on Delivery',
            'Select',
            'Working hours:',
            'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun',
            'Showing %count% closest to %postcode%',
            'top match: %name%',
        ];

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->trans($key, [], $d);
        }

        return $out;
    }

    private function renderCourierExtraFields(array $params): string
    {
        // Read config for which fields to show
        $showDoorCode = (bool) Configuration::get('PPOMNIVA_SHOW_DOOR_CODE');
        $showCabinetNo = (bool) Configuration::get('PPOMNIVA_SHOW_CABINET_NO');
        $showWarehouseNo = (bool) Configuration::get('PPOMNIVA_SHOW_WAREHOUSE_NO');
        $showDeliveryTime = (bool) Configuration::get('PPOMNIVA_SHOW_DELIVERY_TIME');
        $showCallBefore = (bool) Configuration::get('PPOMNIVA_SHOW_CALL_BEFORE');

        // If the merchant has disabled every courier extra field, the
        // template would render an empty <div class="ppomniva-courier">
        // wrapper. Skip the hook output entirely so the carrier-extra slot
        // collapses cleanly instead of leaving an empty container.
        if (!$showDoorCode && !$showCabinetNo && !$showWarehouseNo
            && !$showDeliveryTime && !$showCallBefore) {
            return '';
        }

        // Build delivery time options from enabled config flags
        $timeOptions = [];
        $deliveryTypeMap = [
            'nwd' => 'Anytime',
            'nwd10' => 'Until 10:00',
            'nwd12' => 'Until 12:00',
            'nwd8_14' => '8:00 – 14:00',
            'nwd14_17' => '14:00 – 17:00',
            'nwd18_22' => '18:00 – 22:00',
            'tswd' => 'Same day',
            'tswd17' => 'Same day 17:00 – 22:00',
            'sat' => 'Saturday',
        ];
        foreach ($deliveryTypeMap as $key => $label) {
            $configKey = 'PPOMNIVA_' . strtoupper($key) . '_ENABLED';
            if (Configuration::get($configKey)) {
                $timeOptions[$key] = $this->trans($label, [], self::MODULE_SHOP_DOMAIN);
            }
        }

        // Get saved values (if any)
        $savedFields = $this->getSavedExtraFields((int) $this->context->cart->id);
        $ajaxUrl = $this->context->link->getModuleLink('ppomniva', 'ajax', [], true);

        $this->context->smarty->assign([
            'ppomniva_ajax_url' => $ajaxUrl,
            'ppomniva_carrier_id' => (int) ($params['carrier']['id'] ?? 0),
            'ppomniva_show_door_code' => $showDoorCode,
            'ppomniva_show_cabinet_no' => $showCabinetNo,
            'ppomniva_show_warehouse_no' => $showWarehouseNo,
            'ppomniva_show_delivery_time' => $showDeliveryTime,
            'ppomniva_show_call_before' => $showCallBefore,
            'ppomniva_time_options' => $timeOptions,
            'ppomniva_saved_fields' => $savedFields,
        ]);

        return $this->context->smarty->fetch(
            'module:ppomniva/views/templates/hook/displayCarrierExtraContent_courier.tpl'
        );
    }

    /**
     * actionValidateStepComplete hook
     *
     * The JS picker commits a terminal through the module's ajax controller,
     * but the server-rendered <select> is a plain form field: without this the
     * choice made when our JS never ran would be silently dropped. Runs on the
     * delivery step, before anything reads the stored terminal.
     *
     * @param array<string, mixed> $params
     *
     * @return bool
     */
    public function hookActionValidateStepComplete(array $params)
    {
        if (!isset($params['step_name']) || $params['step_name'] !== 'delivery') {
            return true;
        }

        $cart = $this->context->cart;

        if ($cart !== null && Validate::isLoadedObject($cart)) {
            $this->captureSubmittedTerminal($cart);
        }

        return true;
    }

    /**
     * Terminals to render into the checkout, for one country.
     *
     * Reads the locally synced table first. When that comes back empty the
     * table has simply never been populated — a fresh install where the
     * maintenance cron was never wired up and nobody pressed "Sync" in the
     * back office. Rather than showing an empty picker, pull once from the API
     * and retry; the attempt is throttled so a persistently failing or
     * unconfigured API cannot turn every checkout render into an HTTP call.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCheckoutTerminals(string $countryCode): array
    {
        if ($countryCode === '') {
            return [];
        }

        try {
            $sync = new TerminalSync(new OmnivaApiClient());
            $terminals = $sync->getTerminals($countryCode);

            if (empty($terminals) && $this->shouldAttemptTerminalAutoSync($countryCode)) {
                $sync->syncCountry($countryCode);
                $terminals = $sync->getTerminals($countryCode);
            }

            if (empty($terminals)) {
                return [];
            }

            $cart = $this->context->cart;
            if ($cart !== null && Validate::isLoadedObject($cart)) {
                $terminals = $sync->filterByConstraints(
                    $terminals,
                    (float) $cart->getTotalWeight(),
                    $this->getCartProductDimensions($cart)
                );
            }

            return is_array($terminals) ? $terminals : [];
        } catch (\Throwable $e) {
            // Never let a shipping API outage take the checkout down with it.
            \PrestaShopLogger::addLog(
                'ppomniva: could not load pickup terminals for ' . $countryCode . ' - ' . $e->getMessage(),
                2
            );

            return [];
        }
    }

    /**
     * Rate-limit the empty-table recovery sync to once every 15 minutes per
     * country, tracked globally so sibling shops share the cooldown.
     */
    private function shouldAttemptTerminalAutoSync(string $countryCode): bool
    {
        $key = 'PPOMNIVA_TSYNC_' . strtoupper(preg_replace('/[^A-Z]/i', '', $countryCode));
        $last = (int) Configuration::getGlobalValue($key);

        if ($last > 0 && (time() - $last) < 900) {
            return false;
        }

        Configuration::updateGlobalValue($key, (string) time());

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $terminals
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupTerminalsByCity(array $terminals): array
    {
        $grouped = [];

        foreach ($terminals as $terminal) {
            $city = trim((string) ($terminal['city'] ?? ''));
            if ($city === '') {
                $city = '-';
            }
            $grouped[$city][] = $terminal;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        return $grouped;
    }

    /**
     * @return array<int, array<string, float|int>>
     */
    private function getCartProductDimensions(Cart $cart): array
    {
        $products = $cart->getProducts();

        if (!is_array($products)) {
            return [];
        }

        $dimensions = [];
        foreach ($products as $product) {
            $dimensions[] = [
                'width' => (float) ($product['width'] ?? 0),
                'height' => (float) ($product['height'] ?? 0),
                'depth' => (float) ($product['depth'] ?? 0),
                'quantity' => (int) ($product['cart_quantity'] ?? 1),
            ];
        }

        return $dimensions;
    }

    /**
     * Persist a terminal id submitted with the delivery step. Writes the same
     * columns as the ajax controller's saveTerminal action.
     */
    private function captureSubmittedTerminal(Cart $cart): void
    {
        // The JS picker writes the hidden `ppomniva_terminal_id` input; the
        // server-rendered <select> posts `ppomniva_terminal_select`. Whichever
        // arrives filled is the customer's choice.
        $terminalId = (int) Tools::getValue('ppomniva_terminal_id', 0);

        if ($terminalId <= 0) {
            $terminalId = (int) Tools::getValue('ppomniva_terminal_select', 0);
        }

        if ($terminalId <= 0) {
            return;
        }

        try {
            $terminal = (new TerminalSync(new OmnivaApiClient()))->getTerminalById($terminalId);
        } catch (\Throwable $e) {
            return;
        }

        if ($terminal === null) {
            return;
        }

        $terminalInfo = json_encode([
            'name' => $terminal['name'],
            'code' => $terminal['code'],
            'display_name' => $terminal['display_name'],
            'address' => $terminal['address'],
            'city' => $terminal['city'],
            'zip' => $terminal['zip'],
            'country_code' => $terminal['country_code'],
            'cod_enabled' => (int) $terminal['cod_enabled'],
            'terminal_type' => (int) $terminal['terminal_type'],
        ], JSON_UNESCAPED_UNICODE);

        $db = Db::getInstance();
        $table = bqSQL('ppomniva_order');
        $now = date('Y-m-d H:i:s');

        $existing = (int) $db->getValue(
            'SELECT `id_ppomniva_order` FROM `' . _DB_PREFIX_ . $table . '`
             WHERE `id_cart` = ' . (int) $cart->id
        );

        if ($existing > 0) {
            $db->update($table, [
                'terminal_id' => $terminalId,
                'terminal_info' => pSQL((string) $terminalInfo),
                'country_code' => pSQL((string) $terminal['country_code']),
                'date_upd' => pSQL($now),
            ], '`id_ppomniva_order` = ' . $existing);

            return;
        }

        $db->insert($table, [
            'id_cart' => (int) $cart->id,
            'terminal_id' => $terminalId,
            'terminal_info' => pSQL((string) $terminalInfo),
            'country_code' => pSQL((string) $terminal['country_code']),
            'status' => 'new',
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ]);
    }

    private function getSelectedTerminal(int $idCart): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT terminal_id, terminal_info FROM `' . _DB_PREFIX_ . 'ppomniva_order`
             WHERE id_cart = ' . (int) $idCart
        );

        if (!$row || empty($row['terminal_id'])) {
            return null;
        }

        return [
            'id' => (int) $row['terminal_id'],
            'info' => json_decode($row['terminal_info'] ?? '{}', true),
        ];
    }

    private function getSavedExtraFields(int $idCart): array
    {
        $row = Db::getInstance()->getValue(
            'SELECT extra_fields FROM `' . _DB_PREFIX_ . 'ppomniva_order`
             WHERE id_cart = ' . (int) $idCart
        );

        if (!$row) {
            return [];
        }

        return json_decode($row, true) ?: [];
    }

    /**
     * Resolve the default warehouse for the current cart's shop. Per-shop
     * scoping means each shop in a multistore setup keeps its own default.
     * Falls back to the legacy global row (id_shop = 0) so installs that
     * predate the per-shop UI keep working.
     */
    private function getDefaultWarehouseId(): int
    {
        $idShop = (int) ($this->context->shop->id ?? 0);

        // Try the current shop first; fall back to a legacy global default
        // (id_shop=0) so older installs without per-shop warehouses still
        // resolve a sender address.
        // getValue() (via getRow) appends its own "LIMIT 1", so the query must
        // not include one — a second LIMIT triggers a SQL syntax error.
        return (int) Db::getInstance()->getValue(
            'SELECT `id_ppomniva_warehouse` FROM `' . _DB_PREFIX_ . 'ppomniva_warehouse`
             WHERE `is_default` = 1
               AND (`id_shop` = ' . $idShop . ' OR `id_shop` = 0)
             ORDER BY (`id_shop` = ' . $idShop . ') DESC'
        );
    }
}
