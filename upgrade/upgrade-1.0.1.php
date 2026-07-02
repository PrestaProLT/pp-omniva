<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.1 — merchant-disableable parcel machines.
 *
 * Adds the `enabled` flag to the terminal cache so a merchant can hide
 * individual parcel machines from checkout (Terminals admin screen). Defaults
 * to 1 so every existing terminal stays visible after the upgrade.
 */
function upgrade_module_1_0_1(Module $module): bool
{
    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'ppomniva_terminal`
         ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `cod_enabled`'
    );
}
