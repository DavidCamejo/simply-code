<?php
/**
 * Theme Scripts - PMPro Nextcloud Banda Integration SINCRONIZADO v2.7.7
 *
 * RESPONSABILIDAD: Manejo de handles de script y localización para Simply Code
 * CORREGIDO: Sincronización completa con el sistema de precios, inyección 'before'
 * MEJORADO: Sanitización defensiva, control de race conditions, logging mejorado
 *
 * @version 2.7.7
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

// Función principal de localización mejorada
function banda_localize_pricing_script_improved() {
    if (!function_exists('pmpro_getOption')) {
        banda_theme_log('PMPro functions not available, skipping localization');
        return;
    }

    $checkout_page_id = pmpro_getOption('checkout_page_id');
    $account_page_id = pmpro_getOption('account_page_id');

    $is_relevant_page = (
        (is_page($checkout_page_id)) ||
        (is_page($account_page_id)) ||
        (isset($_GET['level']) && $_GET['level'])
    );

    if (!$is_relevant_page) {
        banda_theme_log('Not on relevant page, skipping localization');
        return;
    }

    // Determinar level_id con sanitización
    $level_id = 0;
    if (!empty($_GET['level'])) {
        $level_id = (int)sanitize_text_field($_GET['level']);
    } elseif (!empty($_GET['pmpro_level'])) {
        $level_id = (int)sanitize_text_field($_GET['pmpro_level']);
    } elseif (function_exists('nextcloud_banda_get_current_level_id')) {
        $level_id = nextcloud_banda_get_current_level_id();
    }

    $allowed_levels = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('allowed_levels') : [2];
    if (!in_array($level_id, $allowed_levels, true)) {
        banda_theme_log('Level not allowed for localization', ['level_id' => $level_id, 'allowed_levels' => $allowed_levels]);
        return;
    }

    // Obtener precio base con fallback
    $base_price = NEXTCLOUD_BANDA_BASE_PRICE;
    if ($level_id > 0) {
        $level = pmpro_getLevel($level_id);
        if ($level && !empty($level->initial_payment) && $level->initial_payment > 0) {
            $base_price = (float)$level->initial_payment;
        }
    }

    // Inicializar valores por defecto
    $current_storage = '1tb';
    $current_users = 2;
    $current_frequency = 'monthly';
    $has_previous_config = false;
    $used_space_tb = 0;
    $next_payment_date = null;
    $has_active_membership = false;
    $current_subscription_data = null;

    // Procesar datos del usuario si está logueado
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $user_levels = function_exists('pmpro_getMembershipLevelsForUser') ? pmpro_getMembershipLevelsForUser($user_id) : [];
        $has_banda_membership = false;
        
        // Verificar membresía activa
        if (!empty($user_levels)) {
            foreach ($user_levels as $l) {
                if (in_array((int)$l->id, $allowed_levels, true)) {
                    // Verificar que la membresía esté activa
                    if (empty($l->enddate) || $l->enddate === '0000-00-00 00:00:00' || strtotime($l->enddate) > time()) {
                        $has_banda_membership = true;
                        $has_active_membership = true;
                        break;
                    }
                }
            }
        }

        // Obtener configuración previa si hay membresía activa
        if ($has_banda_membership && $has_active_membership) {
            $config_json = get_user_meta($user_id, 'nextcloud_banda_config', true);
            if (!empty($config_json)) {
                $config = json_decode($config_json, true);
                if (is_array($config) && json_last_error() === JSON_ERROR_NONE && !isset($config['auto_created'])) {
                    $current_storage = sanitize_text_field($config['storage_space'] ?? '1tb');
                    $current_users = max(2, min(20, intval($config['num_users'] ?? 2)));
                    $current_frequency = sanitize_text_field($config['payment_frequency'] ?? 'monthly');
                    $has_previous_config = true;
                }
            }

            // Obtener datos de suscripción para prorrateo
            if (function_exists('pmpro_getMembershipLevelForUser')) {
                $level = pmpro_getMembershipLevelForUser($user_id);
                if (!empty($level)) {
                    $current_subscription_data = [
                        'storage_space' => $current_storage,
                        'num_users' => $current_users,
                        'payment_frequency' => $current_frequency,
                        'final_amount' => !empty($level->initial_payment) ? (float)$level->initial_payment : 0,
                        'subscription_end_date' => !empty($level->enddate) && $level->enddate !== '0000-00-00 00:00:00' ? $level->enddate : null,
                        'subscription_start_date' => !empty($level->startdate) ? $level->startdate : null
                    ];
                }
            }

            // Obtener espacio usado
            if (function_exists('nextcloud_banda_get_used_space_tb')) {
                $used_space_tb = nextcloud_banda_get_used_space_tb($user_id);
            }

            // Obtener fecha de próximo pago
            if (function_exists('pmpro_getMembershipLevelForUser')) {
                $level = pmpro_getMembershipLevelForUser($user_id);
				// Obtener fecha de próximo pago usando la nueva función
				if (function_exists('nextcloud_banda_get_next_payment_info')) {
					$level = pmpro_getMembershipLevelForUser($user_id);
					$cycle_info = nextcloud_banda_get_next_payment_info($user_id, $level);
					if ($cycle_info && !empty($cycle_info['next_payment_ts'])) {
						$next_payment_date = date('c', $cycle_info['next_payment_ts']);
					}
				}
            }
        }
    }

    // Obtener configuraciones del sistema
    $price_per_tb = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('price_per_tb') : 70.00;
    $price_per_user = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('price_per_additional_user') : 10.00;
    $base_users_included = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('base_users_included') : 2;
    $base_storage_included = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('base_storage_included') : 1;
    $frequency_multipliers = function_exists('nextcloud_banda_get_config') ? nextcloud_banda_get_config('frequency_multipliers') : [
        'monthly' => 1.0, 'semiannual' => 5.7, 'annual' => 10.8, 'biennial' => 20.4, 'triennial' => 28.8, 'quadrennial' => 36.0, 'quinquennial' => 42.0
    ];

    // Preparar datos de localización
    $localization = [
        'level_id' => $level_id,
        'base_price' => $base_price,
        'currency_symbol' => 'R$',
        'price_per_tb' => (float)$price_per_tb,
        'price_per_user' => (float)$price_per_user,
        'base_users_included' => (int)$base_users_included,
        'base_storage_included' => (int)$base_storage_included,
        'current_storage' => $current_storage,
        'current_users' => $current_users,
        'current_frequency' => $current_frequency,
        'has_previous_config' => (bool)$has_previous_config,
        'hasActiveMembership' => (bool)$has_active_membership,
        'current_subscription_data' => $current_subscription_data,
        'used_space_tb' => (float)$used_space_tb,
        'next_payment_date' => $next_payment_date,
        'frequency_multipliers' => $frequency_multipliers,
        'frequency_days' => [
            'monthly' => 30, 'semiannual' => 182, 'annual' => 365,
            'biennial' => 365*2, 'triennial' => 365*3, 'quadrennial' => 365*4, 'quinquennial' => 365*5
        ],
        'debug' => defined('WP_DEBUG') && WP_DEBUG,
        'version' => defined('NEXTCLOUD_BANDA_PLUGIN_VERSION') ? NEXTCLOUD_BANDA_PLUGIN_VERSION : '2.7.7',
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('nextcloud_banda_nonce'),
        'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE
    ];

    banda_theme_log('Localization data prepared', [
        'level_id' => $level_id,
        'base_price' => $base_price,
        'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE,
        'has_previous_config' => $has_previous_config,
        'has_active_membership' => $has_active_membership,
        'user_logged_in' => is_user_logged_in(),
        'current_values' => ['storage' => $current_storage, 'users' => $current_users, 'frequency' => $current_frequency]
    ]);

    // Codificar datos como JSON
    $json = wp_json_encode($localization, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        banda_theme_log('Failed to json_encode localization data', []);
        return;
    }

    $inline_script = "window.nextcloud_banda_pricing = {$json};";

    // Intentar inyectar como inline 'before' sobre un handle detectado
    $handles = banda_detect_script_handles();
    $localized = false;

    foreach ($handles as $handle) {
        if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
            // Inyectar inline justo antes del handle detectado para evitar race conditions
            wp_add_inline_script($handle, $inline_script, 'before');
            banda_theme_log("Inline localization injected before handle: {$handle}");
            $localized = true;
            break;
        }
    }

    // Fallbacks si no se pudo inyectar 'before' en ningún handle
    if (!$localized) {
        // Fallback 1: inyectar en wp_head temprano
        add_action('wp_head', function() use ($json) {
            echo "<script>window.nextcloud_banda_pricing = {$json};</script>\n";
        }, 1);

        // Fallback 2: wp_footer como backup
        add_action('wp_footer', function() use ($json) {
            echo "<script>if (typeof window.nextcloud_banda_pricing === 'undefined') { window.nextcloud_banda_pricing = {$json}; console.log('[PMPro Banda Theme] Localization injected via footer fallback'); }</script>\n";
        }, 5);

        banda_theme_log('Localization injected via inline head/footer fallback', []);
    } else {
        banda_theme_log('Localization injected via wp_add_inline_script(before)', ['handles_checked' => count($handles)]);
    }

    banda_theme_log('Localization process completed', [
        'method' => $localized ? 'inline_before_handle' : 'inline_head_footer_fallback',
        'handles_checked' => count($handles),
        'base_price' => $base_price,
        'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE
    ]);
}

// Hooks para ejecutar la localización
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('dashicons');
    banda_localize_pricing_script_improved();
}, 20);

add_action('wp_head', function() {
    banda_localize_pricing_script_improved();
}, 5);

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
/*
// Hook AJAX para cálculo de prorrateo (si no existe en el plugin principal)
if (!has_action('wp_ajax_nextcloud_banda_calculate_proration')) {
    add_action('wp_ajax_nextcloud_banda_calculate_proration', 'banda_handle_proration_ajax');
    add_action('wp_ajax_nopriv_nextcloud_banda_calculate_proration', 'banda_handle_proration_ajax');
}

function banda_handle_proration_ajax() {
    // Verificar nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'nextcloud_banda_nonce')) {
        wp_die('Nonce verification failed');
    }

    // Verificar usuario logueado
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'User not logged in']);
        return;
    }

    $user_id = get_current_user_id();
    $storage_space = sanitize_text_field($_POST['storage_space'] ?? '1tb');
    $num_users = max(2, min(20, intval($_POST['num_users'] ?? 2)));
    $payment_frequency = sanitize_text_field($_POST['payment_frequency'] ?? 'monthly');

    // Obtener datos de suscripción actual
    if (!function_exists('pmpro_getMembershipLevelForUser')) {
        wp_send_json_error(['message' => 'PMPro functions not available']);
        return;
    }

    $current_level = pmpro_getMembershipLevelForUser($user_id);
    if (!$current_level) {
        wp_send_json_error(['message' => 'No active membership found']);
        return;
    }

    // Obtener configuración actual
    $config_json = get_user_meta($user_id, 'nextcloud_banda_config', true);
    $current_config = [];
    if (!empty($config_json)) {
        $config = json_decode($config_json, true);
        if (is_array($config) && json_last_error() === JSON_ERROR_NONE) {
            $current_config = $config;
        }
    }

    $current_storage = $current_config['storage_space'] ?? '1tb';
    $current_users = $current_config['num_users'] ?? 2;
    $current_frequency = $current_config['payment_frequency'] ?? 'monthly';

    // Verificar si es upgrade
    $current_storage_tb = intval(str_replace('tb', '', strtolower($current_storage)));
    $new_storage_tb = intval(str_replace('tb', '', strtolower($storage_space)));
    
    $frequency_order = [
        'monthly' => 1, 'semiannual' => 2, 'annual' => 3, 'biennial' => 4,
        'triennial' => 5, 'quadrennial' => 6, 'quinquennial' => 7
    ];
    
    $current_freq_order = $frequency_order[$current_frequency] ?? 1;
    $new_freq_order = $frequency_order[$payment_frequency] ?? 1;
    
    $is_upgrade = (
        $new_storage_tb > $current_storage_tb ||
        $num_users > $current_users ||
        $new_freq_order > $current_freq_order
    );

    if (!$is_upgrade) {
        wp_send_json_success([
            'is_upgrade' => false,
            'message' => 'Not an upgrade'
        ]);
        return;
    }

    // Calcular prorrateo básico
    $current_amount = (float)$current_level->initial_payment;
    
    // Calcular nuevo precio (simplificado)
    $base_price = NEXTCLOUD_BANDA_BASE_PRICE;
    $price_per_tb = 70.00;
    $price_per_user = 10.00;
    
    $additional_tb = max(0, $new_storage_tb - 1);
    $additional_users = max(0, $num_users - 2);
    
    $storage_price = $base_price + ($price_per_tb * $additional_tb);
    $user_price = $price_per_user * $additional_users;
    $combined_price = $storage_price + $user_price;
    
    $frequency_multipliers = [
        'monthly' => 1.0, 'semiannual' => 5.7, 'annual' => 10.8, 'biennial' => 20.4,
        'triennial' => 28.8, 'quadrennial' => 36.0, 'quinquennial' => 42.0
    ];
    
    $multiplier = $frequency_multipliers[$payment_frequency] ?? 1.0;
    $new_total_price = ceil($combined_price * $multiplier);

    // Calcular días restantes
    $days_remaining = 30; // Simplificado
    if (!empty($current_level->enddate) && $current_level->enddate !== '0000-00-00 00:00:00') {
        $end_date = strtotime($current_level->enddate);
        $now = time();
        $days_remaining = max(1, ceil(($end_date - $now) / (24 * 60 * 60)));
    }

    // Cálculo de prorrateo simplificado
    $total_days = 30; // Simplificado para monthly
    $current_proportional = ($current_amount * $days_remaining) / $total_days;
    $new_proportional = ($new_total_price * $days_remaining) / $total_days;
    $prorated_amount = max(0, $new_proportional - $current_proportional);

    wp_send_json_success([
        'is_upgrade' => true,
        'new_total_price' => $new_total_price,
        'prorated_amount' => round($prorated_amount, 2),
        'days_remaining' => $days_remaining,
        'current_amount' => $current_amount,
        'savings' => max(0, $new_total_price - $prorated_amount)
    ]);
}
*/
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
    'version' => '2.7.7',
    'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE,
    'functions_available' => [
        'normalize_banda_config' => function_exists('normalize_banda_config'),
        'pmpro_functions' => function_exists('pmpro_getOption')
    ]
]);
