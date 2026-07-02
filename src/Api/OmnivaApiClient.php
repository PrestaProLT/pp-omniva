<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Api;

use Configuration;
use PrestaShop\Module\PPOmniva\Service\ApiLogger;

/**
 * Omniva OMX REST API client (JSON).
 *
 * VERIFIED host: https://omx.omniva.eu  (path prefix /api/v01/omx)
 * Auth: HTTP Basic (client code + password). Optional X-Integration-Agent-Id
 * header ("XXXXXX_YYYYYY") required by some accounts for shipment registration.
 *
 * Parcel-machine locations come from the public Omniva feed
 * (https://www.omniva.ee/locations.json), not from an authenticated endpoint.
 *
 * Method names mirror the sibling ppvenipak client so the ported admin
 * controllers/services keep working; bodies target OMX REST. See
 * docs/OMNIVA_API.md.
 */
class OmnivaApiClient
{
    private const PATH_PREFIX = '/api/v01/omx';
    // OMX has two fixed hosts; the Live mode switch selects between them.
    private const PROD_HOST = 'https://omx.omniva.eu';
    private const TEST_HOST = 'https://test-omx.omniva.eu';
    private const LOCATIONS_URL = 'https://www.omniva.ee/locations.json';

    /** Pack-state buckets used by the order panel (see derivePackState). */
    public const PACK_STATUS_NEW = 0;
    public const PACK_STATUS_REGISTERED = 1;
    public const PACK_STATUS_IN_TRANSIT = 2;
    public const PACK_STATUS_DELIVERED = 3;
    public const PACK_STATUS_RETURNED = 4;
    public const PACK_STATUS_ERROR = 5;

    private ?int $shopId = null;

    public function setShopId(?int $shopId): self
    {
        $this->shopId = ($shopId !== null && $shopId > 0) ? $shopId : null;

        return $this;
    }

    // ---------------------------------------------------------------------
    // Parcel-machine locations (public feed)
    // ---------------------------------------------------------------------

    /**
     * Fetch parcel machines for a country from the public Omniva feed and
     * normalize to the shape TerminalSync expects.
     *
     * Feed fields: ZIP, NAME, TYPE (0=parcel machine/postal, 1=post office),
     * A0_NAME (country), A1_NAME (county), A2_NAME (city), X_COORDINATE (lng),
     * Y_COORDINATE (lat), SERVICE_HOURS.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getPickupPoints(string $country, ?string $postcode = null, ?string $city = null): array
    {
        $country = strtoupper($country);
        $raw = @file_get_contents(self::LOCATIONS_URL);

        if ($raw === false) {
            ApiLogger::logTransportFailure(
                'OmnivaApiClient::getPickupPoints',
                self::LOCATIONS_URL,
                'Could not fetch locations feed.',
                '',
                0,
                ['country' => $country]
            );

            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            ApiLogger::logParseFailure(
                'OmnivaApiClient::getPickupPoints',
                self::LOCATIONS_URL,
                'Invalid JSON in locations feed.',
                substr($raw, 0, 500),
                0,
                ['country' => $country]
            );

            return [];
        }

        $out = [];
        foreach ($data as $loc) {
            if (!is_array($loc) || strtoupper((string) ($loc['A0_NAME'] ?? '')) !== $country) {
                continue;
            }

            $zip = (string) ($loc['ZIP'] ?? '');
            if ($zip === '') {
                continue;
            }

            $name = (string) ($loc['NAME'] ?? '');
            $cityName = (string) ($loc['A2_NAME'] ?? ($loc['A1_NAME'] ?? ''));

            $out[] = [
                'id' => $zip,                              // Omniva uses ZIP as terminal identifier
                'code' => $zip,
                'name' => $name,
                'display_name' => $name,
                'address' => (string) ($loc['A2_NAME'] ?? ''),
                'city' => $cityName,
                'zip' => $zip,
                'type' => (int) ($loc['TYPE'] ?? 0) === 1 ? 1 : 3, // 1=post office, else locker
                'size_limit' => 0,                        // not published in the feed
                'max_height' => 0,
                'max_width' => 0,
                'max_length' => 0,
                'cod_enabled' => 0,                       // not published in the feed
                'lat' => (float) ($loc['Y_COORDINATE'] ?? 0),
                'lng' => (float) ($loc['X_COORDINATE'] ?? 0),
                'working_hours' => (string) ($loc['SERVICE_HOURS'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Legacy signature retained for callers; OMX has no route/zone endpoint in
     * the public scope, so this returns an empty structure.
     */
    public function getRoute(string $country, string $postcode, string $type = 'all'): array
    {
        return [];
    }

    // ---------------------------------------------------------------------
    // Shipment registration + labels
    // ---------------------------------------------------------------------

    /**
     * Register B2C shipment(s). POST /shipments/business-to-client.
     *
     * @param array $shipments OMX shipment objects (built by ShipmentBuilder).
     * @param int[] $orderIds  PrestaShop order ids for per-order error logging.
     * @return array{ok: bool, barcodes: string[], error?: string, raw?: array}
     */
    public function createShipment(array $shipments, array $orderIds = []): array
    {
        $body = [
            'customerCode' => $this->getCredentials()['user'],
            'shipments' => array_values($shipments),
            'fileId' => date('YmdHis'),
        ];
        $res = $this->request('POST', '/shipments/business-to-client', $body, $orderIds);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'barcodes' => [], 'error' => $this->extractError($res)];
        }

        $data = json_decode($res['body'], true) ?: [];
        $barcodes = [];
        // OMX returns barcodes per saved shipment; parse defensively.
        foreach ((array) ($data['savedShipments'] ?? $data['shipments'] ?? []) as $s) {
            if (!empty($s['barcode'])) {
                $barcodes[] = (string) $s['barcode'];
            } elseif (!empty($s['barcodes']) && is_array($s['barcodes'])) {
                $barcodes = array_merge($barcodes, array_map('strval', $s['barcodes']));
            }
        }

        // Surface per-shipment validation failures (resultCode ERROR).
        if (empty($barcodes) && !empty($data['failedShipments'])) {
            $msgs = [];
            foreach ((array) $data['failedShipments'] as $f) {
                $msgs[] = trim((string) ($f['message'] ?? $f['messageCode'] ?? 'unknown'));
            }
            $error = implode('; ', array_filter($msgs)) ?: 'Shipment rejected by Omniva.';
            ApiLogger::logApiError('OmnivaApiClient::createShipment', '/shipments/business-to-client', $error, [], '', $res['body']);

            return ['ok' => false, 'barcodes' => [], 'error' => $error];
        }

        return ['ok' => true, 'barcodes' => $barcodes, 'raw' => $data];
    }

    /**
     * Back-compat shim for the ported label service. OMX is JSON, not XML —
     * callers should migrate to createShipment(). Kept so nothing fatals.
     *
     * @deprecated Use createShipment() with structured shipment data.
     */
    public function submitShipmentXml(string $xmlText, array $orderIds = []): array
    {
        ApiLogger::log(
            'warning',
            'OmnivaApiClient::submitShipmentXml',
            'XML submission is not used on OMX REST; call createShipment() instead.',
            ['order_ids' => $orderIds]
        );

        return ['error' => 'submitShipmentXml is not supported on OMX REST — use createShipment().'];
    }

    /**
     * Fetch label / address-card PDF for barcodes.
     * POST /shipments/package-labels → PDF (base64 in JSON, or raw bytes).
     *
     * @param string[] $barcodes
     */
    public function printLabel(
        array $barcodes,
        string $format = 'a4',
        string $carrier = 'omniva',
        ?bool $printReturns = null,
        ?string $manifestCode = null
    ): string {
        $barcodes = array_values(array_filter(array_map('strval', $barcodes)));
        if (empty($barcodes)) {
            return '';
        }

        $res = $this->request('POST', '/shipments/package-labels', [
            'customerCode' => $this->getCredentials()['user'],
            'barcodes' => array_map(static fn ($b): array => ['barcode' => $b], $barcodes),
            'sendAddressCardTo' => 'RESPONSE',
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300) {
            ApiLogger::logApiError(
                'OmnivaApiClient::printLabel',
                self::PATH_PREFIX . '/shipments/package-labels',
                $this->extractError($res),
                [],
                json_encode(['barcodes' => $barcodes]),
                $res['body']
            );

            return '';
        }

        // Response may be raw PDF bytes or JSON with base64 in one of several
        // shapes (single string, or per-barcode array with addressCard).
        $body = $res['body'];
        if (str_starts_with($body, '%PDF')) {
            return $body;
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return '';
        }

        // OMX package-labels: { successAddressCards: [{ barcode, fileData(base64) }] }.
        // Concatenate is not needed — each card is a full PDF; return the first.
        foreach ((array) ($data['successAddressCards'] ?? []) as $card) {
            if (!empty($card['fileData']) && is_string($card['fileData'])) {
                $decoded = base64_decode($card['fileData'], true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        // Fallbacks for other shapes (single base64 string, or per-item keys).
        foreach (['labelsPdf', 'labels', 'pdf', 'file', 'addressCard', 'base64'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) {
                $decoded = base64_decode($data[$k], true);
                if ($decoded !== false && $decoded !== '') {
                    return $decoded;
                }
            }
        }

        return '';
    }

    /**
     * Manifest PDF — no direct OMX endpoint; kept as a graceful no-op.
     */
    public function printManifest(string $manifestId): string
    {
        ApiLogger::log('info', 'OmnivaApiClient::printManifest', 'Manifest PDF not available via OMX REST.', ['manifest' => $manifestId]);

        return '';
    }

    // ---------------------------------------------------------------------
    // Courier pickup
    // ---------------------------------------------------------------------

    /**
     * Create a courier pickup order. POST /courierorders/create-pickup-order.
     *
     * @return array{ok: bool, courier_order_number?: string, error?: string}
     */
    public function createPickupOrder(array $payload): array
    {
        $res = $this->request('POST', '/courierorders/create-pickup-order', $payload);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return ['ok' => false, 'error' => $this->extractError($res)];
        }
        $data = json_decode($res['body'], true) ?: [];

        return ['ok' => true, 'courier_order_number' => (string) ($data['courierOrderNumber'] ?? '')];
    }

    public function cancelPickupOrder(string $courierOrderNumber): bool
    {
        $res = $this->request('POST', '/courierorders/cancel-pickup-order', ['courierOrderNumber' => $courierOrderNumber]);

        return $res['status'] >= 200 && $res['status'] < 300;
    }

    // ---------------------------------------------------------------------
    // Tracking
    // ---------------------------------------------------------------------

    /**
     * Tracking events for one barcode. GET /shipments/{barCode}. (verified)
     *
     * @return array<int, array{eventCode?: string, eventName?: string, eventDate?: string}>
     */
    public function getTracking(string $code, string $lang = 'EN'): array
    {
        $res = $this->request('GET', '/shipments/' . rawurlencode($code));
        if ($res['status'] !== 200) {
            return [];
        }
        $data = json_decode($res['body'], true);

        return is_array($data['events'] ?? null) ? $data['events'] : [];
    }

    /**
     * @param string[] $codes
     * @return array<string, array>
     */
    public function getTrackingMultiple(array $codes, string $lang = 'EN'): array
    {
        $out = [];
        foreach ($codes as $code) {
            $out[(string) $code] = $this->getTracking((string) $code, $lang);
        }

        return $out;
    }

    /**
     * Reduce an OMX event list to a coarse pack state for the order panel.
     *
     * @param array $events
     * @return array{status: int, label: string, last_event?: string, last_date?: string}
     */
    public function derivePackState(array $events): array
    {
        if (empty($events)) {
            return ['status' => self::PACK_STATUS_NEW, 'label' => 'No events'];
        }
        $last = end($events);
        $code = strtoupper((string) ($last['eventCode'] ?? ''));

        $status = self::PACK_STATUS_REGISTERED;
        if (str_contains($code, 'DELIVERED')) {
            $status = self::PACK_STATUS_DELIVERED;
        } elseif (str_contains($code, 'RETURN')) {
            $status = self::PACK_STATUS_RETURNED;
        } elseif (str_contains($code, 'TRANSIT') || str_contains($code, 'COURIER') || str_contains($code, 'PICKED')) {
            $status = self::PACK_STATUS_IN_TRANSIT;
        }

        return [
            'status' => $status,
            'label' => (string) ($last['eventName'] ?? $code),
            'last_event' => $code,
            'last_date' => (string) ($last['eventDate'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------------
    // Connectivity + credentials
    // ---------------------------------------------------------------------

    /**
     * Lightweight authenticated ping. Returns [connected(bool), message].
     *
     * @return array{connected: bool, message: string}
     */
    public function testConnection(): array
    {
        $creds = $this->getCredentials();
        if ($creds['user'] === '' || $creds['pass'] === '') {
            return ['connected' => false, 'message' => 'API username/password not set.'];
        }

        // A tracking GET for a dummy barcode authenticates without side effects:
        // 200/404 => credentials accepted; 401/403 => rejected.
        $res = $this->request('GET', '/shipments/PING0000000000');
        if ($res['status'] === 401 || $res['status'] === 403) {
            return ['connected' => false, 'message' => 'Authentication failed (HTTP ' . $res['status'] . ').'];
        }
        if ($res['status'] === 0) {
            return ['connected' => false, 'message' => 'Could not reach ' . $this->getHost() . '.'];
        }

        return ['connected' => true, 'message' => 'Connected to ' . $this->getHost() . '.'];
    }

    public function getApiId(): string
    {
        return $this->getCredentials()['user'];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @return array{user: string, pass: string, agent: string}
     */
    private function getCredentials(): array
    {
        $get = fn (string $k): string => (string) ($this->shopId !== null
            ? Configuration::get($k, null, null, $this->shopId)
            : Configuration::get($k));

        return [
            'user' => $get('PPOMNIVA_API_USER'),
            'pass' => $get('PPOMNIVA_API_PASS'),
            'agent' => $get('PPOMNIVA_AGENT_ID'),
        ];
    }

    /**
     * OMX host is chosen by the Live mode switch — production vs Omniva's test
     * environment. There is no free-text host field (both hosts are fixed).
     */
    private function getHost(): string
    {
        $live = (bool) ($this->shopId !== null
            ? Configuration::get('PPOMNIVA_LIVE_MODE', null, null, $this->shopId)
            : Configuration::get('PPOMNIVA_LIVE_MODE'));

        return $live ? self::PROD_HOST : self::TEST_HOST;
    }

    /**
     * @param array|null $payload JSON body for POST; null for GET
     * @param int[] $orderIds order ids for per-order error logging
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, ?array $payload = null, array $orderIds = []): array
    {
        $creds = $this->getCredentials();
        $url = $this->getHost() . self::PATH_PREFIX . $path;

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($creds['user'] . ':' . $creds['pass']),
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($creds['agent'] !== '') {
            // OMX expects the "Developer_" prefix in front of the issued id.
            $agent = str_starts_with($creds['agent'], 'Developer_') ? $creds['agent'] : 'Developer_' . $creds['agent'];
            $headers[] = 'X-Integration-Agent-Id: ' . str_replace(' ', '_', $agent);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 40,
        ]);
        if ($payload !== null) {
            // Force shortest round-trip float encoding. Some hosts set
            // serialize_precision=17, which turns 6.044 into 6.0439999999999996
            // and OMX rejects it ("numeric value out of bounds, <12>.<4>").
            $prev = ini_get('serialize_precision');
            ini_set('serialize_precision', '-1');
            $json = json_encode($payload);
            ini_set('serialize_precision', (string) $prev);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err !== '') {
            ApiLogger::logTransportFailure('OmnivaApiClient::request', $path, $err !== '' ? $err : 'cURL failure', '', 0, ['url' => $url]);

            return ['status' => 0, 'body' => ''];
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * @param array{status: int, body: string} $res
     */
    private function extractError(array $res): string
    {
        $data = json_decode($res['body'], true);
        if (is_array($data)) {
            foreach (['error', 'message', 'errorMessage', 'fault'] as $k) {
                if (!empty($data[$k]) && is_string($data[$k])) {
                    return $data[$k];
                }
            }
        }

        return 'HTTP ' . $res['status'];
    }
}
