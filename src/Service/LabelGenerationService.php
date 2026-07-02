<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Service;

use Address;
use Configuration;
use Country;
use Currency;
use Customer;
use Db;
use Order;
use OrderState;
use PrestaShop\Module\PPOmniva\Api\OmnivaApiClient;
use PrestaShop\Module\PPOmniva\Api\PostcodeFormatter;
use Validate;

/**
 * Registers Omniva shipments over OMX REST (business-to-client) and persists
 * the returned barcodes onto ppomniva_order.
 *
 * OMX assigns barcodes server-side, so there is no pack-number serial / code-45
 * self-heal machinery (that was Venipak-specific). Public interface is kept so
 * the ported OrderController/AdminHooks keep working.
 *
 * NOTE: the exact OMX business-to-client field names are contract/manual
 * specific — the payload here follows the documented structure; validate the
 * field mapping against the account-manager OMX manual. Any OMX rejection is
 * surfaced verbatim in the Logs tab.
 */
class LabelGenerationService
{
    private OmnivaApiClient $apiClient;
    private PostcodeFormatter $postcodeFormatter;

    public function __construct()
    {
        $this->apiClient = new OmnivaApiClient();
        $this->postcodeFormatter = new PostcodeFormatter();
    }

    /**
     * @param int[] $orderIds
     * @return array{success: bool, results: array, errors: array}
     */
    public function generateForOrders(array $orderIds): array
    {
        $results = [];
        $errors = [];

        $omnivaOrders = $this->loadOmnivaOrders($orderIds);
        if (empty($omnivaOrders)) {
            return ['success' => false, 'results' => [], 'errors' => ['No Omniva orders found for the given order IDs.']];
        }

        foreach ($omnivaOrders as $vo) {
            if (($vo['status'] ?? '') === 'registered') {
                $errors[] = sprintf('Order #%d already has labels generated.', (int) $vo['id_order']);
                continue;
            }

            $res = $this->registerOne($vo);
            if ($res['success']) {
                $results[] = $res['order'];
            } else {
                $errors[] = sprintf('Order #%d: %s', (int) $vo['id_order'], $res['error']);
                $this->updateOrderStatus((int) $vo['id_ppomniva_order'], 'error', $res['error']);
                $this->setPrestaShopOrderStatus((int) $vo['id_order'], 'error');
            }
        }

        return ['success' => empty($errors), 'results' => $results, 'errors' => $errors];
    }

    /**
     * Build + submit a single order's OMX shipment.
     *
     * @return array{success: bool, order?: array, error?: string}
     */
    private function registerOne(array $vo): array
    {
        $orderId = (int) $vo['id_order'];
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return ['success' => false, 'error' => 'PrestaShop order not found.'];
        }

        $idShop = (int) $order->id_shop;
        $this->apiClient->setShopId($idShop ?: null);

        $warehouse = $this->loadDefaultWarehouse($idShop);
        if (!$warehouse) {
            return ['success' => false, 'error' => 'No default warehouse configured (Warehouses tab).'];
        }
        $missing = self::validateWarehouseFields($warehouse);
        if (!empty($missing)) {
            return ['success' => false, 'error' => 'Warehouse missing fields: ' . implode(', ', $missing)];
        }

        $address = new Address($order->id_address_delivery);
        $customer = new Customer($order->id_customer);
        $country = new Country($address->id_country);
        $currency = new Currency($order->id_currency);

        $isPickup = ((int) $vo['id_carrier'] === (int) Configuration::get('PPOMNIVA_PICKUP_ID_REF'));
        $terminalInfo = json_decode((string) ($vo['terminal_info'] ?? '{}'), true) ?: [];

        $destCountry = $country->iso_code;

        // Receiver addressee (OMX: parcel machine uses country + offloadPostcode;
        // courier uses full street address).
        if ($isPickup) {
            $offload = (string) ($terminalInfo['zip'] ?? $terminalInfo['id'] ?? $vo['terminal_id'] ?? '');
            $receiverAddress = ['country' => $destCountry, 'offloadPostcode' => $offload];
            $channel = 'PARCEL_MACHINE';
        } else {
            $receiverAddress = [
                'country' => $destCountry,
                'deliverypoint' => $address->city,
                'postcode' => $this->postcodeFormatter->format($address->postcode, $destCountry),
                'street' => trim($address->address1 . ' ' . (string) $address->address2),
            ];
            $channel = 'COURIER';
        }

        $receiverAddressee = [
            'address' => $receiverAddress,
            'contactEmail' => $customer->email,
            'contactMobile' => $address->phone_mobile ?: $address->phone,
            'personName' => trim($address->firstname . ' ' . $address->lastname),
        ];

        $senderAddressee = [
            'address' => [
                'country' => $warehouse['country_code'],
                'deliverypoint' => $warehouse['city'],
                'postcode' => $this->postcodeFormatter->format($warehouse['zip_code'], $warehouse['country_code']),
                'street' => $warehouse['address'],
            ],
            'contactEmail' => (string) Configuration::get('PS_SHOP_EMAIL'),
            'contactMobile' => $warehouse['phone'],
            'personName' => $warehouse['contact'] ?: $warehouse['name'],
        ];

        $shipment = [
            'mainService' => 'PARCEL',
            'deliveryChannel' => $channel,
            'partnerShipmentId' => $order->reference,
            'receiverAddressee' => $receiverAddressee,
            'senderAddressee' => $senderAddressee,
            'measurement' => ['weight' => round(max(0.001, (float) $vo['order_weight']), 3)],
        ];

        $isCod = (int) $vo['is_cod'] && (float) $vo['cod_amount'] > 0;
        $addServices = [];
        if ($isCod) {
            $addServices[] = ['code' => 'COD', 'params' => [
                ['key' => 'COD_AMOUNT', 'value' => (string) round((float) $vo['cod_amount'], 2)],
                ['key' => 'COD_BANK_ACCOUNT_NO', 'value' => (string) Configuration::get('PPOMNIVA_COD_IBAN', null, null, $idShop)],
                ['key' => 'COD_RECEIVER', 'value' => $warehouse['name']],
                ['key' => 'COD_REFERENCE_NO', 'value' => $order->reference],
            ]];
        }
        if ((int) ($vo['is_18_plus'] ?? 0) === 1) {
            $addServices[] = ['code' => 'DELIVERY_TO_AN_ADULT'];
        }
        if (!empty($addServices)) {
            $shipment['addServices'] = $addServices;
        }

        // Legacy service label kept for our own records (QH courier / PU machine).
        $serviceCode = $isPickup ? 'PU' : 'QH';

        $resp = $this->apiClient->createShipment([$shipment], [$orderId]);
        if (!$resp['ok'] || empty($resp['barcodes'])) {
            return ['success' => false, 'error' => $resp['error'] ?? 'No barcode returned by Omniva.'];
        }

        $barcodes = $resp['barcodes'];
        $this->saveOrderResult((int) $vo['id_ppomniva_order'], $orderId, $serviceCode, $barcodes);
        $this->setPrestaShopOrderStatus($orderId, 'ready');
        $this->saveTrackingToOrderCarrier($orderId, $barcodes[0]);

        return ['success' => true, 'order' => ['id_order' => $orderId, 'tracking_numbers' => $barcodes]];
    }

    /**
     * Resolve the Omniva service code. Exact codes are contract/country
     * specific — confirm against the account-manager manual (docs §6).
     */
    private function resolveServiceCode(bool $isPickup, string $destCountry, bool $isCod, bool $is18Plus): string
    {
        // Placeholder mapping — override from the OMX service-code list.
        $base = $isPickup ? 'PA' : 'QH'; // PA = parcel machine, QH = courier (verify)

        return $base;
    }

    public function getLabelPdf(int $idOrder): string
    {
        $barcodes = $this->trackingNumbersFor($idOrder);
        if (empty($barcodes)) {
            return '';
        }
        $format = (string) Configuration::get('PPOMNIVA_LABEL_FORMAT') ?: 'a4';

        return $this->apiClient->printLabel($barcodes, $format);
    }

    // ---- helpers -------------------------------------------------------

    private function loadOmnivaOrders(array $orderIds): array
    {
        if (empty($orderIds)) {
            return [];
        }
        $ids = implode(',', array_map('intval', $orderIds));

        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppomniva_order` WHERE `id_order` IN (' . $ids . ')'
        ) ?: [];
    }

    private function loadDefaultWarehouse(int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'ppomniva_warehouse`
             WHERE `is_default` = 1 AND (`id_shop` = ' . $idShop . ' OR `id_shop` = 0)
             ORDER BY (`id_shop` = ' . $idShop . ') DESC'
        );

        return $row ?: null;
    }

    /** @return string[] */
    private function trackingNumbersFor(int $idOrder): array
    {
        $raw = (string) Db::getInstance()->getValue(
            'SELECT `tracking_numbers` FROM `' . _DB_PREFIX_ . 'ppomniva_order`
             WHERE `id_order` = ' . (int) $idOrder
        );
        $arr = json_decode($raw, true);

        return is_array($arr) ? array_map('strval', $arr) : [];
    }

    /** @return string[] */
    public static function validateWarehouseFields(array $warehouse): array
    {
        $required = [
            'name' => 'Name', 'country_code' => 'Country', 'city' => 'City',
            'address' => 'Address', 'zip_code' => 'Postal code',
        ];
        $missing = [];
        foreach ($required as $key => $label) {
            if (empty(trim((string) ($warehouse[$key] ?? '')))) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private function saveOrderResult(int $omnivaOrderId, int $orderId, string $serviceCode, array $barcodes): void
    {
        Db::getInstance()->update('ppomniva_order', [
            'service_code' => pSQL($serviceCode),
            'tracking_numbers' => pSQL(json_encode($barcodes)),
            'status' => 'registered',
            'error' => '',
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_ppomniva_order` = ' . $omnivaOrderId);

        $orderRef = (string) Db::getInstance()->getValue(
            'SELECT `reference` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $orderId
        );
        ApiLogger::clearForOrder($orderId, $orderRef !== '' ? $orderRef : null);
    }

    private function updateOrderStatus(int $omnivaOrderId, string $status, string $error = ''): void
    {
        Db::getInstance()->update('ppomniva_order', [
            'status' => pSQL($status),
            'error' => pSQL($error),
            'date_upd' => date('Y-m-d H:i:s'),
        ], '`id_ppomniva_order` = ' . $omnivaOrderId);
    }

    private function setPrestaShopOrderStatus(int $orderId, string $type): void
    {
        try {
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return;
            }
            $stateKey = $type === 'ready' ? 'PPOMNIVA_STATE_READY' : 'PPOMNIVA_STATE_ERROR';
            $stateId = (int) Configuration::get($stateKey, null, null, (int) $order->id_shop);
            if ($stateId <= 0 || !Validate::isLoadedObject(new OrderState($stateId))) {
                return;
            }
            if ((int) $order->current_state !== $stateId) {
                $order->setCurrentState($stateId);
            }
        } catch (\Throwable $e) {
            ApiLogger::logException('LabelGenerationService::setPrestaShopOrderStatus', $e, '', $orderId, ['target_state' => $type]);
        }
    }

    private function saveTrackingToOrderCarrier(int $orderId, string $trackingNumber): void
    {
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'order_carrier` SET `tracking_number` = \'' . pSQL($trackingNumber) . '\'
             WHERE `id_order` = ' . (int) $orderId . ' ORDER BY `id_order_carrier` DESC LIMIT 1'
        );
    }
}
