<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Hooks;

use Db;

/**
 * Product edit-page hooks for the 18+ age-restricted flag (Omniva age-verified
 * delivery service). Stored in ppomniva_18_plus_product and applied at shipment
 * time by the service-code resolver.
 */
trait ProductHooks
{
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if ($idProduct <= 0) {
            return '';
        }

        $is18Plus = (bool) Db::getInstance()->getValue(
            'SELECT `is_18_plus` FROM `' . _DB_PREFIX_ . 'ppomniva_18_plus_product` WHERE `id_product` = ' . $idProduct
        );

        $this->context->smarty->assign([
            'ppomniva_is_18_plus' => $is18Plus,
            'ppomniva_id_product' => $idProduct,
        ]);

        return $this->display(dirname(__DIR__, 2) . '/ppomniva.php', 'views/templates/admin/product_18plus.tpl');
    }

    public function hookActionProductUpdate(array $params): void
    {
        $idProduct = (int) ($params['id_product'] ?? 0);
        if ($idProduct <= 0 || !isset($_POST['ppomniva_is_18_plus'])) {
            return;
        }

        $is18Plus = (int) (bool) $_POST['ppomniva_is_18_plus'];
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'ppomniva_18_plus_product` (`id_product`, `is_18_plus`)
             VALUES (' . $idProduct . ', ' . $is18Plus . ')
             ON DUPLICATE KEY UPDATE `is_18_plus` = ' . $is18Plus
        );
    }
}
