<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.3 — carrier logos + migration UI.
 *
 * Back-fills the carrier logo onto the already-created carriers so existing
 * installs get the logo too (new installs get it during createCarriers). The
 * migration UI change is template/asset only and needs no data migration.
 */
function upgrade_module_1_0_3(Module $module): bool
{
    $logo = _PS_MODULE_DIR_ . 'ppomniva/views/img/carrier_logo.png';

    if (file_exists($logo) && defined('_PS_SHIP_IMG_DIR_')) {
        foreach (['PPOMNIVA_COURIER_ID', 'PPOMNIVA_PICKUP_ID'] as $key) {
            $idCarrier = (int) Configuration::get($key);
            if ($idCarrier > 0) {
                @copy($logo, _PS_SHIP_IMG_DIR_ . $idCarrier . '.jpg');
            }
        }
    }

    return true;
}
