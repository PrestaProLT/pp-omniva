<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Api;

use Order;

/**
 * Builds the Omniva shipment request payload from a PrestaShop order.
 *
 * Resolves sender (warehouse), receiver (delivery address or parcel-machine
 * offloadPostcode), service code, weight, packages, COD, and the 18+ additional
 * service flag. See docs/OMNIVA_API.md §5 for the field list.
 */
class ShipmentBuilder
{
    /**
     * @return array the JSON-serializable shipment structure for OmnivaApiClient
     */
    public function build(Order $order, array $context): array
    {
        // TODO: map order + warehouse + terminal + COD + 18+ flag to the
        // Omniva OMX shipment schema. Parcel-machine shipments MUST include the
        // destination terminal ZIP as offloadPostcode.
        return [];
    }

    /**
     * Resolve the Omniva service code for a carrier + destination country,
     * factoring COD and 18+ additional services.
     *
     * Service/additional-service codes vary by contract/country — confirm
     * against the account-manager manual before hardcoding (docs §6).
     */
    public function resolveServiceCode(string $carrierKey, string $destCountry, bool $isCod, bool $is18Plus): string
    {
        // TODO: return the correct Omniva service code.
        return '';
    }
}
