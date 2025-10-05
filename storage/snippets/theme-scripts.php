<?php
/**
 * Theme Scripts - PMPro Nextcloud Banda Integration SINCRONIZADO v2.8.0
 * 
 * Nombre del archivo: theme-scripts.php
 * 
 * RESPONSABILIDAD: Manejo de handles de script y localización para Simply Code
 * CORREGIDO: Sincronización completa con el sistema de precios, inyección 'before'
 * MEJORADO: Sanitización defensiva, control de race conditions, logging mejorado
 *
 * @version 2.8.0
 */

if (!defined('ABSPATH')) {
    exit('Acceso directo no permitido');
}

// CORREGIDO: Usar la misma constante que el archivo principal
if (!defined('NEXTCLOUD_BANDA_BASE_PRICE')) {
    define('NEXTCLOUD_BANDA_BASE_PRICE', 70.00);
}

// Función de normalización de configuración (backup/fallback)
if (!function_exists('normalize_banda_config')) {
    function normalize_banda_config($config_data) {
        if (!is_array($config_data)) {
            return [
                'storage_space' => '1tb',
                'num_users' => 2,
                'payment_frequency' => 'monthly'
            ];
        }

        $storage_space = sanitize_text_field($config_data['storage_space'] ?? '1tb');
        $valid_storage = ['1tb','2tb','3tb','4tb','5tb','6tb','7tb','8tb','9tb','10tb','15tb','20tb'];
        if (!in_array($storage_space, $valid_storage, true)) {
            $storage_space = '1tb';
        }

        $num_users = max(2, min(20, intval($config_data['num_users'] ?? 2)));

        $payment_frequency = sanitize_text_field($config_data['payment_frequency'] ?? 'monthly');
        $valid_frequencies = ['monthly','semiannual','annual','biennial','triennial','quadrennial','quinquennial'];
        if (!in_array($payment_frequency, $valid_frequencies, true)) {
            $payment_frequency = 'monthly';
        }

        return [
            'storage_space' => $storage_space,
            'num_users' => $num_users,
            'payment_frequency' => $payment_frequency
        ];
    }
}

// Detecta posibles handles de script
function banda_detect_script_handles() {
    global $wp_scripts;

    $possible_handles = [
        'simply-snippet-nextcloud-banda-dynamic-pricing',
        'simply-code-nextcloud-banda-dynamic-pricing',
        'nextcloud-banda-dynamic-pricing',
        'banda-dynamic-pricing',
        'simply-snippet-banda-pricing',
        'simply-code-banda-pricing',
        'simply-snippet-nextcloud-banda',
        'simply-code-nextcloud-banda',
        'pmpro-banda-pricing',
        'banda-pricing-script'
    ];

    $detected_handles = [];

    if (isset($wp_scripts->registered)) {
        foreach ($wp_scripts->registered as $handle => $script) {
            if (stripos($handle, 'banda') !== false ||
                stripos($handle, 'nextcloud') !== false ||
                stripos($handle, 'pricing') !== false ||
                (stripos($handle, 'simply') !== false && stripos($handle, 'snippet') !== false)) {
                $detected_handles[] = $handle;
            }
        }
    }

    $all_handles = array_unique(array_merge($possible_handles, $detected_handles));

    banda_theme_log('Script handles detection completed', [
        'possible_count' => count($possible_handles),
        'detected_count' => count($detected_handles),
        'total_handles' => count($all_handles),
        'detected_handles' => array_slice($detected_handles, 0, 5)
    ]);

    return $all_handles;
}

// Función principal de localización mejorada - CORREGIDA
function banda_localize_pricing_script_improved() {
    if (!function_exists('pmpro_getOption')) {
        banda_theme_log('PMPro functions not available, skipping localization');
        return;
    }

    $checkout_page_id = pmpro_getOption('checkout_page_id');
    $account_page_id  = pmpro_getOption('account_page_id');

    $is_relevant_page = (
        is_page($checkout_page_id) ||
        is_page($account_page_id) ||
        !empty($_GET['level'])
    );

    if (!$is_relevant_page) {
        banda_theme_log('Not on relevant page, skipping localization');
        return;
    }

    $level_id = 0;
    if (!empty($_GET['level'])) {
        $level_id = (int)sanitize_text_field($_GET['level']);
    } elseif (!empty($_GET['pmpro_level'])) {
        $level_id = (int)sanitize_text_field($_GET['pmpro_level']);
    } elseif (function_exists('nextcloud_banda_get_current_level_id')) {
        $level_id = nextcloud_banda_get_current_level_id();
    }

    $allowed_levels = function_exists('nextcloud_banda_get_config')
        ? nextcloud_banda_get_config('allowed_levels')
        : [2];

    if (!in_array($level_id, $allowed_levels, true)) {
        banda_theme_log('Level not allowed for localization', [
            'level_id'       => $level_id,
            'allowed_levels' => $allowed_levels,
        ]);
        return;
    }

    $base_price = NEXTCLOUD_BANDA_BASE_PRICE;
    if ($level_id > 0) {
        $level = pmpro_getLevel($level_id);
        if ($level && !empty($level->initial_payment) && $level->initial_payment > 0) {
            $base_price = (float) $level->initial_payment;
        }
    }

    $current_storage        = '1tb';
    $current_users          = 2;
    $current_frequency      = 'monthly';
    $has_previous_config    = false;
    $used_space_tb          = 0;
    $next_payment_date      = null;
    $has_active_membership  = false;
    $current_subscription   = null;
    $current_price_paid     = 0;
    $last_credit_value      = 0;

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();

        if (
            function_exists('nextcloud_banda_get_next_payment_info') &&
            function_exists('nextcloud_banda_get_user_real_config_improved')
        ) {
            $user_levels = pmpro_getMembershipLevelsForUser($user_id);

            if (!empty($user_levels)) {
                foreach ($user_levels as $l) {
                    if (in_array((int) $l->id, $allowed_levels, true)) {
                        $cycle_info = nextcloud_banda_get_next_payment_info($user_id);
                        if ($cycle_info && !empty($cycle_info['cycle_end']) && $cycle_info['cycle_end'] > time()) {
                            $has_active_membership = true;
                            break;
                        }
                    }
                }
            }

            if ($has_active_membership) {
                $level = pmpro_getMembershipLevelForUser($user_id);

                if ($level) {
                    $real_config = nextcloud_banda_get_user_real_config_improved($user_id, $level);

                    if (!empty($real_config) && $real_config['source'] !== 'defaults_no_active_membership') {
                        $current_storage     = sanitize_text_field($real_config['storage_space'] ?? '1tb');
                        $current_users       = max(2, min(20, intval($real_config['num_users'] ?? 2)));
                        $current_frequency   = sanitize_text_field($real_config['payment_frequency'] ?? 'monthly');
                        $has_previous_config = true;

                        $current_price_paid = !empty($real_config['current_cycle_amount'])
                            ? (float) $real_config['current_cycle_amount']
                            : ((float) $level->initial_payment);
                        $last_credit_value = !empty($real_config['last_proration_credit'])
                            ? (float) $real_config['last_proration_credit']
                            : 0.0;
                    }
                }

                if ($level) {
                    $cycle_number = (int) ($level->cycle_number ?? 1);
                    $cycle_period = (string) ($level->cycle_period ?? 'Month');

                    $derived_frequency = function_exists('nextcloud_banda_derive_frequency_from_cycle')
                        ? nextcloud_banda_derive_frequency_from_cycle($cycle_number, $cycle_period)
                        : 'monthly';

                    $cycle_label = function_exists('nextcloud_banda_map_cycle_label')
                        ? nextcloud_banda_map_cycle_label($cycle_number, $cycle_period)
                        : 'Mensal';

                    $current_subscription = [
                        'storage_space'            => $current_storage,
                        'num_users'                => $current_users,
                        'payment_frequency'        => $derived_frequency,
                        'cycle_label'              => $cycle_label,
                        'cycle_number'             => $cycle_number,
                        'cycle_period'             => $cycle_period,
                        'final_amount'             => !empty($level->initial_payment) ? (float) $level->initial_payment : 0,
                        'current_price_paid'       => $current_price_paid,
                        'last_credit_value'        => $last_credit_value,
                        'subscription_end_date'    => (!empty($level->enddate) && $level->enddate !== '0000-00-00 00:00:00') ? $level->enddate : null,
                        'subscription_start_date'  => !empty($level->startdate) ? $level->startdate : null,
                    ];
                }

                if (function_exists('nextcloud_banda_get_used_space_tb')) {
                    $used_space_tb = nextcloud_banda_get_used_space_tb($user_id);
                }

                $cycle_info = nextcloud_banda_get_next_payment_info($user_id);
                if ($cycle_info && !empty($cycle_info['next_payment_date'])) {
                    $next_payment_date = date('c', (int) $cycle_info['next_payment_date']);
                }
            }
        }
    }

    $configs_available = function_exists('nextcloud_banda_get_config');

    $price_per_tb         = $configs_available ? nextcloud_banda_get_config('price_per_tb') : 70.00;
    $price_per_user       = $configs_available ? nextcloud_banda_get_config('price_per_additional_user') : 10.00;
    $base_users_included  = $configs_available ? nextcloud_banda_get_config('base_users_included') : 2;
    $base_storage_tb      = $configs_available ? nextcloud_banda_get_config('base_storage_included') : 1;
    $frequency_multipliers = $configs_available
        ? nextcloud_banda_get_config('frequency_multipliers')
        : [
            'monthly'     => 1.0,
            'semiannual'  => 5.7,
            'annual'      => 10.8,
            'biennial'    => 20.4,
            'triennial'   => 28.8,
            'quadrennial' => 36.0,
            'quinquennial'=> 42.0,
        ];

    $localization = [
        'level_id'                 => $level_id,
        'base_price'               => $base_price,
        'currency_symbol'          => 'R$',
        'price_per_tb'             => (float) $price_per_tb,
        'price_per_user'           => (float) $price_per_user,
        'base_users_included'      => (int) $base_users_included,
        'base_storage_included'    => (int) $base_storage_tb,
        'current_storage'          => $current_storage,
        'current_users'            => $current_users,
        'current_frequency'        => $current_frequency,
        'has_previous_config'      => (bool) $has_previous_config,
        'hasActiveMembership'      => (bool) $has_active_membership,
        'current_subscription_data'=> $current_subscription,
        'used_space_tb'            => (float) $used_space_tb,
        'next_payment_date'        => $next_payment_date,
        'frequency_multipliers'    => $frequency_multipliers,
        'frequency_days'           => [
            'monthly'     => 30,
            'semiannual'  => 182,
            'annual'      => 365,
            'biennial'    => 730,
            'triennial'   => 1095,
            'quadrennial' => 1460,
            'quinquennial'=> 1825,
        ],
        'debug'                    => defined('WP_DEBUG') && WP_DEBUG,
        'version'                  => defined('NEXTCLOUD_BANDA_PLUGIN_VERSION') ? NEXTCLOUD_BANDA_PLUGIN_VERSION : '2.8.0',
        'ajax_url'                 => admin_url('admin-ajax.php'),
        'nonce'                    => wp_create_nonce('nextcloud_banda_proration'),
        'base_price_constant'      => NEXTCLOUD_BANDA_BASE_PRICE,
    ];

    $json = wp_json_encode($localization, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        banda_theme_log('Failed to json_encode localization data');
        return;
    }

    $inline_script = "window.nextcloud_banda_pricing = {$json};";
    $handles       = banda_detect_script_handles();
    $localized     = false;

    foreach ($handles as $handle) {
        if (wp_script_is($handle, 'registered')) {
            wp_add_inline_script($handle, $inline_script, 'before');
            banda_theme_log("Inline localization injected before handle: {$handle}");
            $localized = true;
            break;
        }
    }

    // Fallbacks si no se pudo inyectar 'before' en ningún handle
    if (!$localized) {
        // Fallback 1: wp_head temprano
        add_action('wp_head', function() use ($inline_script) {
            echo "<script>{$inline_script}</script>\n";
        }, 1);

        // Fallback 2: wp_footer como backup
        add_action('wp_footer', function() use ($inline_script) {
            echo "<script>if (typeof window.nextcloud_banda_pricing === 'undefined') { {$inline_script} console.log('[PMPro Banda Theme] Localization injected via footer fallback'); }</script>\n";
        }, 5);

        banda_theme_log('Localization injected via inline head/footer fallback', []);
    } else {
        banda_theme_log('Localization injected via wp_add_inline_script(before)', ['handles_checked' => count($handles)]);
    }

    banda_theme_log('Localization process completed', [
        'method' => $localized ? 'inline_before_handle' : 'inline_head_footer_fallback',
        'handles_checked' => count($handles),
        'base_price' => $base_price,
        'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE,
        'has_subscription_data' => !empty($current_subscription_data)
    ]);
}

// Hooks para ejecutar la localización - MEJORADOS
add_action('wp_enqueue_scripts', 'banda_enqueue_banda_assets', 20);
function banda_enqueue_banda_assets() {
    wp_enqueue_style('dashicons');
    banda_localize_pricing_script_improved();
}

// Fallback adicional para asegurar ejecución
add_action('wp_head', function() {
    if (!did_action('banda_localize_pricing_script_improved')) {
        banda_localize_pricing_script_improved();
    }
}, 999);

// Función de logging simplificada
function banda_theme_log($message, $context = []) {
    if (function_exists('nextcloud_banda_log_info')) {
        nextcloud_banda_log_info('[Theme Scripts] ' . $message, $context);
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        $log_message = '[Banda Theme] ' . $message;
        if (!empty($context)) {
            $log_message .= ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        error_log($log_message);
    }
}

// Enqueue de scripts personalizados adicionales
function enqueue_custom_contact_form_scripts() {
    wp_enqueue_script('custom-contact-form', get_template_directory_uri() . '/js/custom-contact-form.js', array('jquery'), '1.0', true);
    wp_localize_script('custom-contact-form', 'customContactForm', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('custom_contact_form_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'enqueue_custom_contact_form_scripts');

// Log de inicialización
banda_theme_log('Theme Scripts loaded successfully - SYNCHRONIZED VERSION', [
    'version' => '2.8.0',
    'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE,
    'functions_available' => [
        'normalize_banda_config' => function_exists('normalize_banda_config'),
        'pmpro_functions' => function_exists('pmpro_getOption'),
        'nextcloud_banda_functions' => function_exists('nextcloud_banda_get_config')
    ]
]);
