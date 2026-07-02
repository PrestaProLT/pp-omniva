<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaPro\Common\Carrier\AbstractPPCarrier;
use PrestaPro\Common\Traits\AdminAssetsTrait;
use PrestaPro\Common\Traits\OrderStateTrait;
use PrestaShop\Module\PPOmniva\Carrier\OmnivaShippingCalculator;
use PrestaShop\Module\PPOmniva\Hooks\AdminHooks;
use PrestaShop\Module\PPOmniva\Hooks\CheckoutHooks;
use PrestaShop\Module\PPOmniva\Hooks\ProductHooks;
use PrestaShop\Module\PPOmniva\Module\Installer;
use PrestaShop\Module\PPOmniva\Module\Uninstaller;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * PrestaPro — Omniva.
 *
 * Omniva (Baltic post) courier + parcel-machine shipping integration for
 * PrestaShop 9. Modeled on the sibling `ppvenipak` module and the shared
 * `prestapro/pp-common` carrier base. See docs/OMNIVA_API.md for the Omniva
 * OMX API reference this module targets.
 */
class PPOmniva extends AbstractPPCarrier
{
    use OrderStateTrait;
    use AdminAssetsTrait;
    use CheckoutHooks;
    use AdminHooks;
    use ProductHooks;

    public const MODULE_ADMIN_DOMAIN = 'Modules.Ppomniva.Admin';
    public const MODULE_SHOP_DOMAIN = 'Modules.Ppomniva.Shop';

    /** @var string[] */
    public array $hooks = [
        'actionCarrierUpdate',
        'actionFrontControllerSetMedia',
        'displayCarrierExtraContent',
        'displayAdminOrderMainBottom',
        'displayAdminProductsExtra',
        'actionProductUpdate',
        'actionValidateOrder',
        'actionObjectOrderUpdateAfter',
        'actionPresentPaymentOptions',
        'displayBackOfficeHeader',
    ];

    public function __construct()
    {
        $this->name = 'ppomniva';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0.1';
        $this->author = 'PrestaPro';
        $this->author_uri = 'https://prestapro.lt/modules/ppomniva';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('PrestaPro — Omniva', [], self::MODULE_ADMIN_DOMAIN);
        $this->description = $this->trans('Omniva courier and parcel-machine shipping integration for PrestaShop.', [], self::MODULE_ADMIN_DOMAIN);
        $this->confirmUninstall = $this->trans('Are you sure? Uninstalling removes Omniva configuration; carriers are soft-deleted so existing orders keep their shipping data.', [], self::MODULE_ADMIN_DOMAIN);

        $this->tabs = [
            [
                'name' => 'PrestaPro — Omniva',
                'class_name' => 'AdminPPOmniva',
                'route_name' => 'ps_ppomniva_configuration',
                'parent_class_name' => 'INVISIBLE',
            ],
        ];
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function getContent(): void
    {
        $router = $this->get('router');
        Tools::redirectAdmin(
            $router->generate('ps_ppomniva_dashboard')
        );
    }

    protected function getConfigPrefix(): string
    {
        return 'PPOMNIVA';
    }

    /**
     * Public wrapper so Installer can call the protected trait method.
     */
    public function addOrderState(string $configKey, array $names, string $color): int
    {
        return $this->createOrderState($configKey, $names, $color);
    }

    protected function getCarrierDefinitions(): array
    {
        // PrestaShop substitutes "@" in the URL with the customer's tracking
        // number when it renders the order-detail page.
        $trackingUrl = 'https://mano.omniva.lt/track/@';

        return [
            'courier' => [
                'name' => 'Omniva Courier',
                'url' => $trackingUrl,
                'delay' => [
                    'en' => '1-3 business days',
                    'lt' => '1-3 darbo dienos',
                    'lv' => '1-3 darba dienas',
                    'et' => '1-3 tööpäeva',
                ],
                'is_free' => false,
                'shipping_handling' => true,
                'range_behavior' => 0,
            ],
            'pickup' => [
                'name' => 'Omniva Parcel Machine',
                'url' => $trackingUrl,
                'delay' => [
                    'en' => '2-4 business days',
                    'lt' => '2-4 darbo dienos',
                    'lv' => '2-4 darba dienas',
                    'et' => '2-4 tööpäeva',
                ],
                'is_free' => false,
                'shipping_handling' => true,
                'range_behavior' => 0,
            ],
        ];
    }

    protected function getInstaller(): object
    {
        return new Installer($this);
    }

    protected function getUninstaller(): object
    {
        return new Uninstaller($this);
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        if (!(bool) Configuration::get('PPOMNIVA_ENABLED')) {
            return false;
        }

        $cart = $this->context->cart;

        if (!$cart || !$cart->id) {
            return false;
        }

        $calculator = new OmnivaShippingCalculator();

        // For the parcel-machine carrier, hide it when no terminal can serve
        // the delivery country / cart weight.
        $carrierId = (int) ($this->id_carrier ?? 0);
        $carrierKey = $this->getCarrierKey($carrierId);

        if ($carrierKey === 'pickup' && !$calculator->hasAvailableTerminals((int) $cart->id)) {
            return false;
        }

        // Pass through PrestaShop zone/range-based pricing configured in the BO.
        return $shipping_cost;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }
}
