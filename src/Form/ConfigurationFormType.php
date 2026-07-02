<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Form;

use PrestaShopBundle\Form\Admin\Type\SwitchType;
use PrestaShopBundle\Form\Admin\Type\TranslatorAwareType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Omniva configuration form. Three presentation modes mirror ppvenipak's
 * multistore handling (see ConfigurationFormDataProvider):
 *   - 'shop'          per-shop fields only (per-store auth ON, a shop selected)
 *   - 'global_toggle' only the per-store auth toggle (per-store auth ON, All Shops)
 *   - 'global_full'   all fields + toggle (shared credentials, All Shops)
 */
class ConfigurationFormType extends TranslatorAwareType
{
    private const DOMAIN = 'Modules.Ppomniva.Admin';

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['mode' => 'shop']);
        $resolver->setAllowedValues('mode', ['shop', 'global_toggle', 'global_full']);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['mode'] === 'global_toggle') {
            $this->buildGlobalToggle($builder);

            return;
        }

        $this->buildApiGroup($builder);
        $this->buildShippingGroup($builder);

        if ($options['mode'] === 'global_full') {
            $this->buildGlobalToggle($builder);
        }
    }

    private function buildApiGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('api_user', TextType::class, [
                'label' => $this->trans('Omniva API username', self::DOMAIN),
                'required' => false,
            ])
            ->add('api_pass', PasswordType::class, [
                'label' => $this->trans('Omniva API password', self::DOMAIN),
                'required' => false,
                'empty_data' => '',
                'help' => $this->trans('Leave blank to keep the saved password.', self::DOMAIN),
            ])
            ->add('agent_id', TextType::class, [
                'label' => $this->trans('Integration agent id (X-Integration-Agent-Id)', self::DOMAIN),
                'required' => false,
                'help' => $this->trans('Format XXXXXX_YYYYYY (Omniva-issued id + integration version). Optional for tracking; supply if Omniva requires it for shipment registration.', self::DOMAIN),
            ]);
    }

    private function buildShippingGroup(FormBuilderInterface $builder): void
    {
        $builder
            ->add('live_mode', SwitchType::class, [
                'label' => $this->trans('Live mode', self::DOMAIN),
                'required' => false,
                'help' => $this->trans('On: production (omx.omniva.eu). Off: Omniva test environment (test-omx.omniva.eu). The host is chosen automatically.', self::DOMAIN),
            ])
            ->add('cod_enabled', SwitchType::class, [
                'label' => $this->trans('Enable cash on delivery (COD)', self::DOMAIN),
                'required' => false,
            ])
            ->add('18_plus_service', SwitchType::class, [
                'label' => $this->trans('Enable 18+ age-verified delivery service', self::DOMAIN),
                'required' => false,
            ])
            ->add('label_format', ChoiceType::class, [
                'label' => $this->trans('Label format', self::DOMAIN),
                'required' => false,
                'choices' => [
                    'A4' => 'a4',
                    'A6' => 'a6',
                ],
            ]);
    }

    private function buildGlobalToggle(FormBuilderInterface $builder): void
    {
        $builder->add('store_auth_mode', SwitchType::class, [
            'label' => $this->trans('Per-store authentication', self::DOMAIN),
            'required' => false,
            'help' => $this->trans('On: each shop holds its own credentials. Off: one shared credential set.', self::DOMAIN),
        ]);
    }
}
