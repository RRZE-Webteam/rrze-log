<?php

declare(strict_types=1);

namespace RRZE\Log;

defined('ABSPATH') || exit;

final class Options {

    /**
     * Option name.
     * @var string
     */
    protected static string $optionName = 'rrze_log';

    /**
     * Default options.
     *
     * @return array
     */
    protected static function defaultOptions(): array {
        return [
            'enabled' => '0',
            'maxLines' => 1000,
            'adminMenu' => '0',
            'debugMaxLines' => 1000,
            'debugLogAccess' => '',

            'auditEnabled' => '0',
            'auditTypes' => [
                'cms' => 1,
                'site' => 1,
                'editorial' => 0,
            ],
            'auditMaxLines' => 1000,
        ];
    }

    /**
     * Returns the options.
     *
     * @return object
     */
    public static function getOptions(): object {
        $defaults = self::defaultOptions();

        $options = (array) get_site_option(self::$optionName);
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        $options['auditEnabled'] = !empty($options['auditEnabled']) ? 1 : 0;

        if (!isset($options['auditTypes']) || !is_array($options['auditTypes'])) {
            $options['auditTypes'] = $defaults['auditTypes'];
        }

        $options['auditTypes'] = self::normalizeAuditTypes($options['auditTypes']);

        if (is_multisite()) {
            $settingsOptions = get_site_option('rrze_settings');
            $enabledByNetwork = false;

            if (is_object($settingsOptions)
                && isset($settingsOptions->plugins)
                && is_object($settingsOptions->plugins)
                && !empty($settingsOptions->plugins->rrze_log_auditEnabled)) {
                $enabledByNetwork = true;
            } elseif (is_array($settingsOptions)
                && isset($settingsOptions['plugins'])
                && is_array($settingsOptions['plugins'])
                && !empty($settingsOptions['plugins']['rrze_log_auditEnabled'])) {
                $enabledByNetwork = true;
            }

            if ($enabledByNetwork) {
                $options['auditEnabled'] = 1;
            }
        }

        if ((int) $options['auditEnabled'] === 1) {
            $options['auditTypes'] = self::applyDefaultAuditTypesIfEmpty(
                $options['auditTypes'],
                $defaults['auditTypes']
            );
        }

        return (object) $options;
    }

    /**
     * Returns the name of the option.
     *
     * @return string
     */
    public static function getOptionName(): string {
        return self::$optionName;
    }

    /**
     * Normalizes audit types to a strict allowlist of keys and 0/1 values.
     *
     * @param array $types
     * @return array
     */
    protected static function normalizeAuditTypes(array $types): array {
        return [
            'cms' => !empty($types['cms']) ? 1 : 0,
            'site' => !empty($types['site']) ? 1 : 0,
            'editorial' => !empty($types['editorial']) ? 1 : 0,
        ];
    }

    /**
     * If audit is enabled but types were effectively "empty", apply the defaults:
     * cms=1, site=1, editorial=0.
     *
     * @param array $types
     * @param array $defaults
     * @return array
     */
    protected static function applyDefaultAuditTypesIfEmpty(array $types, array $defaults): array {
        $sum = (int) $types['cms'] + (int) $types['site'] + (int) $types['editorial'];

        if ($sum <= 0) {
            return $defaults;
        }

        return $types;
    }
}
