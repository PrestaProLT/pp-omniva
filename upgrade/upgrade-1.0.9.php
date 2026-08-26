<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.0.9 — the pickup selector renders server-side, and carrier
 * identity stops depending on shop-scoped configuration.
 *
 * Three things this migration has to do for an existing install:
 *
 * 1. Register actionValidateStepComplete. The selector is now a real
 *    <select> in the delivery step, and that hook is where its submitted
 *    value is captured for carts whose choice did not go through the ajax
 *    controller.
 *
 * 2. Backfill PPOMNIVA_<KEY>_ID and _ID_REF as GLOBAL configuration rows.
 *    They were written with Configuration::updateValue(), which stores a row
 *    scoped to whichever shop context the install ran in — so resolving
 *    "is this my carrier?" came back empty in every sibling shop and the
 *    checkout hook rendered nothing at all.
 *
 *    Value precedence: a candidate pointing at a LIVE carrier (deleted = 0)
 *    belonging to this module beats one that does not. A stale global row
 *    left over from a reinstall commonly points at a soft-deleted carrier and
 *    must not be the one preserved. Every write is logged for recovery.
 *
 * 3. Repair _ID rows corrupted by the inverted actionCarrierUpdate mapping
 *    fixed in this release: where the stored _ID no longer matches a live
 *    carrier but the module's _ID_REF does, adopt that carrier's id.
 */
function upgrade_module_1_0_9(Module $module): bool
{
    $db = Db::getInstance();
    $ok = (bool) $module->registerHook('actionValidateStepComplete');

    foreach (['COURIER', 'PICKUP'] as $carrierKey) {
        foreach (['ID_REF' => 'id_reference', 'ID' => 'id_carrier'] as $suffix => $column) {
            $name = 'PPOMNIVA_' . $carrierKey . '_' . $suffix;

            $rows = $db->executeS(
                'SELECT `value` FROM `' . _DB_PREFIX_ . "configuration`
                 WHERE `name` = '" . pSQL($name) . "' AND CAST(`value` AS UNSIGNED) > 0
                 ORDER BY IFNULL(`id_shop`, 0) DESC"
            );

            $candidates = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $value = (int) $row['value'];
                if ($value > 0 && !in_array($value, $candidates, true)) {
                    $candidates[] = $value;
                }
            }

            // Nothing stored for this key: recover the id from the reference
            // when the sibling key does resolve to a live carrier.
            if (empty($candidates) && $column === 'id_carrier') {
                $reference = (int) Configuration::getGlobalValue('PPOMNIVA_' . $carrierKey . '_ID_REF');
                if ($reference > 0) {
                    $recovered = (int) $db->getValue(
                        'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier`
                         WHERE `id_reference` = ' . $reference . " AND `deleted` = 0
                           AND `external_module_name` = '" . pSQL($module->name) . "'
                         ORDER BY `id_carrier` DESC"
                    );
                    if ($recovered > 0) {
                        $candidates[] = $recovered;
                    }
                }
            }

            if (empty($candidates)) {
                continue;
            }

            $chosen = 0;
            foreach ($candidates as $candidate) {
                $live = (int) $db->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'carrier`
                     WHERE `' . bqSQL($column) . '` = ' . $candidate . "
                       AND `deleted` = 0
                       AND `external_module_name` = '" . pSQL($module->name) . "'"
                );

                if ($live > 0) {
                    $chosen = $candidate;
                    break;
                }
            }

            // Nothing resolved to a live carrier: keep the highest-priority
            // value rather than dropping the key entirely.
            if ($chosen === 0) {
                $chosen = $candidates[0];
            }

            $current = (int) Configuration::getGlobalValue($name);
            if ($current === $chosen) {
                continue;
            }

            PrestaShopLogger::addLog(
                sprintf(
                    'ppomniva 1.0.9 migration: %s global value %d -> %d (candidates: %s)',
                    $name,
                    $current,
                    $chosen,
                    implode(',', $candidates)
                ),
                1
            );

            $ok = Configuration::updateGlobalValue($name, (string) $chosen) && $ok;
        }
    }

    Configuration::loadConfiguration();

    return $ok;
}
