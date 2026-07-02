<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Controller\Admin;

use Configuration;
use Module;
use PrestaShop\Module\PPOmniva\Form\ConfigurationFormDataProvider;
use PrestaShop\Module\PPOmniva\Form\ConfigurationFormType;
use PrestaShop\Module\PPOmniva\Service\MigrationService;
use PrestaShop\PrestaShop\Core\Form\FormHandlerInterface;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Shop;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigurationController extends FrameworkBundleAdminController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))")]
    public function index(
        Request $request,
        #[Autowire(service: 'prestashop.module.ppomniva.form.configuration_handler')]
        FormHandlerInterface $formHandler,
    ): Response {
        $module = Module::getInstanceByName('ppomniva');

        $storeAuthMode = (bool) Configuration::getGlobalValue('PPOMNIVA_STORE_AUTH_MODE');
        $shopContext = Shop::getContext();

        // Lockdowns — render a banner instead of the form when the current
        // context can't sensibly edit the credentials:
        //   - per-shop mode + CONTEXT_GROUP: a group covers multiple shops,
        //     saving a single credential here would silently overwrite each
        //     member's.
        //   - shared mode + CONTEXT_SHOP/GROUP: credentials live globally,
        //     editing them from a specific shop would suggest that shop has
        //     its own copy when it doesn't.
        $lockdownReason = null;
        if ($storeAuthMode && $shopContext === Shop::CONTEXT_GROUP) {
            $lockdownReason = 'group';
        } elseif (!$storeAuthMode && $shopContext !== Shop::CONTEXT_ALL) {
            $lockdownReason = 'shared';
        }

        if ($lockdownReason !== null) {
            return $this->render('@Modules/ppomniva/views/templates/admin/configuration.html.twig', [
                'configurationForm' => null,
                'multistore_blocked' => true,
                'multistore_reason' => $lockdownReason,
                'connection_status' => ['connected' => null, 'message' => ''],
                'legacy_modules' => [],
                'test_connection_url' => '',
                'migration_preview_url' => '',
                'migration_migrate_url' => '',
                'migration_skip_url' => '',
                'module_version' => $module ? $module->version : '',
            ]);
        }

        // Pick the form variant for this (auth-mode, context) pair:
        //   - per-shop ON  + CONTEXT_ALL  → toggle only (creds belong per-shop)
        //   - per-shop ON  + CONTEXT_SHOP → full per-shop form, no toggle
        //   - per-shop OFF + CONTEXT_ALL  → full form + toggle, all global
        if ($storeAuthMode && $shopContext === Shop::CONTEXT_ALL) {
            $formMode = 'global_toggle';
        } elseif (!$storeAuthMode) {
            $formMode = 'global_full';
        } else {
            $formMode = 'shop';
        }

        if ($formMode === 'global_toggle') {
            $configurationForm = $this->createForm(
                ConfigurationFormType::class,
                ['store_auth_mode' => $storeAuthMode],
                ['mode' => 'global_toggle']
            );
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $data = $configurationForm->getData();
                Configuration::updateGlobalValue('PPOMNIVA_STORE_AUTH_MODE', !empty($data['store_auth_mode']) ? '1' : '0');
                $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppomniva.Admin'));

                return $this->redirectToRoute('ps_ppomniva_configuration');
            }
        } elseif ($formMode === 'global_full') {
            // Shared-credential mode: build the full form manually so we
            // can inject the toggle. The PS FormHandler service is bound
            // to mode='shop' and isn't reusable here, so we drive the data
            // provider directly.
            $dataProvider = new ConfigurationFormDataProvider();
            $configurationForm = $this->createForm(
                ConfigurationFormType::class,
                $dataProvider->getData(),
                ['mode' => 'global_full']
            );
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $errors = $dataProvider->setData($configurationForm->getData());

                if (empty($errors)) {
                    $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppomniva.Admin'));
                } else {
                    $this->flashErrors($errors);
                }

                return $this->redirectToRoute('ps_ppomniva_configuration');
            }
        } else {
            $configurationForm = $formHandler->getForm();
            $configurationForm->handleRequest($request);

            if ($request->isMethod('POST') && $configurationForm->isSubmitted() && $configurationForm->isValid()) {
                $errors = $formHandler->save($configurationForm->getData());

                if (empty($errors)) {
                    $this->addFlash('success', $this->trans('Settings updated successfully.', 'Modules.Ppomniva.Admin'));
                } else {
                    $this->flashErrors($errors);
                }

                return $this->redirectToRoute('ps_ppomniva_configuration');
            }
        }

        $connectionStatus = $this->getConnectionStatus();

        // Detect legacy mijoraomniva module for the migration banner.
        $legacyModules = [];
        $migrationSkipped = (bool) Configuration::get('PPOMNIVA_MIGRATION_SKIPPED');

        if (!$migrationSkipped) {
            $migrationService = new MigrationService();
            $detection = $migrationService->detect();

            if ($detection) {
                $detection['preview'] = $migrationService->preview();
                $legacyModules[] = $detection;
            }
        }

        return $this->render('@Modules/ppomniva/views/templates/admin/configuration.html.twig', [
            'configurationForm' => $configurationForm->createView(),
            'multistore_blocked' => false,
            'multistore_reason' => null,
            'connection_status' => $connectionStatus,
            'legacy_modules' => $legacyModules,
            'test_connection_url' => $this->generateUrl('ps_ppomniva_test_connection'),
            'migration_preview_url' => $this->generateUrl('ps_ppomniva_migration_preview'),
            'migration_migrate_url' => $this->generateUrl('ps_ppomniva_migration_migrate'),
            'migration_skip_url' => $this->generateUrl('ps_ppomniva_migration_skip'),
            'module_version' => $module ? $module->version : '',
            // form_mode drives template branching:
            //   - 'shop'           → render per-shop fields only
            //   - 'global_toggle'  → render only the per-store auth toggle
            //   - 'global_full'    → render every field + the toggle
            'form_mode' => $formMode,
        ]);
    }


    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))")]
    public function testConnection(Request $request): JsonResponse
    {
        $apiUser = (string) Configuration::get('PPOMNIVA_API_USER');
        $apiPass = (string) Configuration::get('PPOMNIVA_API_PASS');

        if ($apiUser === '' || $apiPass === '') {
            $msg = $this->trans('API credentials are not configured.', 'Modules.Ppomniva.Admin');
            $this->saveConnectionTestResult(false, $msg);

            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        // Real OMX ping (authenticated GET) — verifies the credentials against
        // the API, not just that a username is present. OMX uses ONE host
        // (omx.omniva.eu) for test and production; the environment is decided by
        // the credentials, so there is no sandbox/production host to report.
        try {
            $client = new \PrestaShop\Module\PPOmniva\Api\OmnivaApiClient();
            $result = $client->testConnection();
        } catch (\Throwable $e) {
            $msg = $this->trans(
                'Connection error: %error%',
                'Modules.Ppomniva.Admin',
                ['%error%' => $e->getMessage()]
            );
            $this->saveConnectionTestResult(false, $msg);

            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        if (empty($result['connected'])) {
            $this->saveConnectionTestResult(false, (string) $result['message']);

            return new JsonResponse(['success' => false, 'message' => (string) $result['message']]);
        }

        $live = (bool) Configuration::get('PPOMNIVA_LIVE_MODE');
        $host = $live ? 'https://omx.omniva.eu (production)' : 'https://test-omx.omniva.eu (test)';
        $msg = $this->trans(
            'Connected to Omniva OMX %host% as client %id%. Host follows the Live mode switch.',
            'Modules.Ppomniva.Admin',
            ['%host%' => $host, '%id%' => $client->getApiId()]
        );
        $this->saveConnectionTestResult(true, $msg);

        return new JsonResponse(['success' => true, 'message' => $msg]);
    }

    /**
     * Resolve the cached test result. Re-using a saved status keeps the page
     * from looking "untested" every reload — the only way the cache becomes
     * stale is when the merchant changes credentials, which the fingerprint
     * (api_user + api_pass + live_mode) detects automatically.
     */
    private function getConnectionStatus(): array
    {
        $apiUser = Configuration::get('PPOMNIVA_API_USER');
        $apiPass = Configuration::get('PPOMNIVA_API_PASS');

        if (empty($apiUser) || empty($apiPass)) {
            return [
                'connected' => false,
                'message' => 'API credentials not configured',
            ];
        }

        $savedSig = (string) Configuration::get('PPOMNIVA_API_LAST_TEST_SIG');
        $currentSig = $this->credentialsSignature();

        if ($savedSig === '' || $savedSig !== $currentSig) {
            return [
                'connected' => null,
                'message' => $savedSig === ''
                    ? 'Click "Test Connection" to verify'
                    : 'Credentials changed since last test — click Test Connection to verify',
            ];
        }

        $ok = (bool) Configuration::get('PPOMNIVA_API_LAST_TEST_OK');
        $msg = (string) Configuration::get('PPOMNIVA_API_LAST_TEST_MSG');
        $at = (int) Configuration::get('PPOMNIVA_API_LAST_TEST_AT');

        if ($at > 0) {
            $msg = trim($msg) . ' (last verified ' . $this->formatRelativeTime($at) . ')';
        }

        return [
            'connected' => $ok,
            'message' => $msg !== '' ? $msg : ($ok ? 'Connection verified' : 'Connection failed'),
        ];
    }

    private function saveConnectionTestResult(bool $ok, string $message): void
    {
        Configuration::updateValue('PPOMNIVA_API_LAST_TEST_OK', $ok ? 1 : 0);
        Configuration::updateValue('PPOMNIVA_API_LAST_TEST_MSG', $message);
        Configuration::updateValue('PPOMNIVA_API_LAST_TEST_AT', time());
        Configuration::updateValue('PPOMNIVA_API_LAST_TEST_SIG', $this->credentialsSignature());
    }

    private function credentialsSignature(): string
    {
        return sha1(
            (string) Configuration::get('PPOMNIVA_API_USER')
            . '|' . (string) Configuration::get('PPOMNIVA_API_PASS')
            . '|' . (Configuration::get('PPOMNIVA_LIVE_MODE') ? '1' : '0')
        );
    }

    private function formatRelativeTime(int $timestamp): string
    {
        $delta = max(0, time() - $timestamp);

        if ($delta < 60) {
            return 'just now';
        }
        if ($delta < 3600) {
            $m = (int) floor($delta / 60);
            return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
        }
        if ($delta < 86400) {
            $h = (int) floor($delta / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        $d = (int) floor($delta / 86400);
        if ($d < 30) {
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }

        return 'on ' . date('Y-m-d', $timestamp);
    }
}
