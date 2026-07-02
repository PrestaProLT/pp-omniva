<?php

declare(strict_types=1);

namespace PrestaShop\Module\PPOmniva\Form;

use Configuration;
use PrestaShop\PrestaShop\Core\Form\FormDataProviderInterface;

/**
 * Loads/saves the Omniva configuration form, honoring the per-store auth mode.
 * Mirrors the ppvenipak provider: per-shop keys are read/written at global
 * scope when shared-credentials mode is active.
 */
class ConfigurationFormDataProvider implements FormDataProviderInterface
{
    private const CONFIG_PREFIX = 'PPOMNIVA_';

    /** form field => config key suffix (per-shop by default). */
    private const CONFIG_MAP = [
        'api_user' => 'API_USER',
        'api_pass' => 'API_PASS',
        'agent_id' => 'AGENT_ID',
        'live_mode' => 'LIVE_MODE',
        'cod_enabled' => 'COD_ENABLED',
        '18_plus_service' => '18_PLUS_SERVICE',
        'label_format' => 'LABEL_FORMAT',
    ];

    /** Global-scope fields. */
    private const GLOBAL_MAP = [
        'store_auth_mode' => 'STORE_AUTH_MODE',
    ];

    /** Blank submissions that must NOT overwrite the stored value. */
    private const PRESERVE_IF_BLANK = ['api_pass'];

    public function getData(): array
    {
        $sharedMode = !(bool) Configuration::getGlobalValue(self::CONFIG_PREFIX . self::GLOBAL_MAP['store_auth_mode']);
        $data = [];

        foreach (self::CONFIG_MAP as $field => $suffix) {
            $key = self::CONFIG_PREFIX . $suffix;
            if ($sharedMode) {
                $value = Configuration::getGlobalValue($key);
                if ($value === false || $value === '') {
                    $value = Configuration::get($key);
                }
            } else {
                $value = Configuration::get($key);
            }
            $data[$field] = $value === false ? '' : $value;
        }

        // Never surface the stored password.
        $data['api_pass'] = '';

        foreach (self::GLOBAL_MAP as $field => $suffix) {
            $data[$field] = Configuration::getGlobalValue(self::CONFIG_PREFIX . $suffix);
        }

        return $data;
    }

    public function setData(array $data): array
    {
        $errors = [];
        $sharedMode = !(bool) Configuration::getGlobalValue(self::CONFIG_PREFIX . self::GLOBAL_MAP['store_auth_mode']);

        foreach (self::CONFIG_MAP as $field => $suffix) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];

            if (in_array($field, self::PRESERVE_IF_BLANK, true) && ($value === '' || $value === null)) {
                continue;
            }

            $key = self::CONFIG_PREFIX . $suffix;
            if ($sharedMode) {
                Configuration::updateGlobalValue($key, (string) $value);
            } else {
                Configuration::updateValue($key, (string) $value);
            }
        }

        foreach (self::GLOBAL_MAP as $field => $suffix) {
            if (array_key_exists($field, $data)) {
                Configuration::updateGlobalValue(self::CONFIG_PREFIX . $suffix, (string) $data[$field]);
            }
        }

        return $errors;
    }
}
