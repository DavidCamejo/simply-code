<?php
/**
 * PMPro Dynamic Pricing for Nextcloud Storage Plans - SNIPPET OPTIMIZADO v2.1
 * 
 * Versión optimizada que mantiene la estructura de snippet con:
 * - Logging optimizado y configuración centralizada
 * - Sistema de caché mejorado
 * - Validaciones robustas y manejo de errores
 * - Optimizaciones de rendimiento
 * - Compatibilidad mejorada
 * 
 * @version 2.1.0
 */

if (!defined('ABSPATH')) {
    exit('Acceso directo no permitido');
}

// ====================================================================
// CONFIGURACIÓN GLOBAL Y CONSTANTES
// ====================================================================

define('NEXTCLOUD_PLUGIN_VERSION', '2.1.0');
define('NEXTCLOUD_CACHE_GROUP', 'nextcloud_dynamic');
define('NEXTCLOUD_CACHE_EXPIRY', HOUR_IN_SECONDS);

/**
 * Configuración centralizada - optimizada
 */
function nextcloud_get_config($key = null) {
    static $config = null;
    
    if ($config === null) {
        $config = [
            'allowed_levels' => [10, 11, 12, 13, 14],
            'price_per_tb' => 120.00,
            'office_user_price' => 25.00,
            'frequency_multipliers' => [
                'monthly' => 1.0,
                'semiannual' => 5.7,
                'annual' => 10.8,
                'biennial' => 20.4,
                'triennial' => 28.8,
                'quadrennial' => 36.0,
                'quinquennial' => 42.0
            ],
            'storage_options' => [
                '1tb' => '1 Terabyte', '2tb' => '2 Terabytes', '3tb' => '3 Terabytes',
                '4tb' => '4 Terabytes', '5tb' => '5 Terabytes', '6tb' => '6 Terabytes',
                '7tb' => '7 Terabytes', '8tb' => '8 Terabytes', '9tb' => '9 Terabytes',
                '10tb' => '10 Terabytes', '15tb' => '15 Terabytes', '20tb' => '20 Terabytes',
                '30tb' => '30 Terabytes', '40tb' => '40 Terabytes', '50tb' => '50 Terabytes',
                '60tb' => '60 Terabytes', '70tb' => '70 Terabytes', '80tb' => '80 Terabytes',
                '90tb' => '90 Terabytes', '100tb' => '100 Terabytes', '200tb' => '200 Terabytes',
                '300tb' => '300 Terabytes', '400tb' => '400 Terabytes', '500tb' => '500 Terabytes'
            ],
            'office_options' => [
                '20users' => '±20 usuários (CODE - Grátis)',
                '30users' => '30 usuários (Business)',
                '50users' => '50 usuários (Business)',
                '80users' => '80 usuários (Business)',
                '100users' => '100 usuários (Enterprise, -15%)',
                '150users' => '150 usuários (Enterprise, -15%)',
                '200users' => '200 usuários (Enterprise, -15%)',
                '300users' => '300 usuários (Enterprise, -15%)',
                '400users' => '400 usuários (Enterprise, -15%)',
                '500users' => '500 usuários (Enterprise, -15%)'
            ]
        ];
    }
    
    return $key ? ($config[$key] ?? null) : $config;
}

// ====================================================================
// SISTEMA DE LOGGING OPTIMIZADO
// ====================================================================

/**
 * Logging centralizado con niveles
 */
function nextcloud_log($level, $message, $context = []) {
    static $log_level = null;
    
    if ($log_level === null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_level = 4; // DEBUG
        } elseif (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_level = 3; // INFO
        } else {
            $log_level = 1; // ERROR only
        }
    }
    
    $levels = [1 => 'ERROR', 2 => 'WARNING', 3 => 'INFO', 4 => 'DEBUG'];
    
    if ($level > $log_level) return;
    
    $log_message = sprintf(
        '[PMPro Dynamic %s] %s',
        $levels[$level],
        $message
    );
    
    if (!empty($context)) {
        $log_message .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    
    error_log($log_message);
}

// Funciones de logging simplificadas
function nextcloud_log_error($message, $context = []) {
    nextcloud_log(1, $message, $context);
}

function nextcloud_log_warning($message, $context = []) {
    nextcloud_log(2, $message, $context);
}

function nextcloud_log_info($message, $context = []) {
    nextcloud_log(3, $message, $context);
}

function nextcloud_log_debug($message, $context = []) {
    nextcloud_log(4, $message, $context);
}

// ====================================================================
// SISTEMA DE CACHÉ OPTIMIZADO
// ====================================================================

/**
 * Obtener datos del caché
 */
function nextcloud_cache_get($key, $default = false) {
    $cached = wp_cache_get($key, NEXTCLOUD_CACHE_GROUP);
    if ($cached !== false) {
        nextcloud_log_debug("Cache hit for key: {$key}");
        return $cached;
    }
    
    nextcloud_log_debug("Cache miss for key: {$key}");
    return $default;
}

/**
 * Guardar datos en caché
 */
function nextcloud_cache_set($key, $data, $expiry = NEXTCLOUD_CACHE_EXPIRY) {
    $result = wp_cache_set($key, $data, NEXTCLOUD_CACHE_GROUP, $expiry);
    nextcloud_log_debug("Cache set for key: {$key}", ['success' => $result]);
    return $result;
}

/**
 * Eliminar datos del caché
 */
function nextcloud_cache_delete($key) {
    $result = wp_cache_delete($key, NEXTCLOUD_CACHE_GROUP);
    nextcloud_log_debug("Cache deleted for key: {$key}", ['success' => $result]);
    return $result;
}

/**
 * Invalidar caché de usuario
 */
function nextcloud_invalidate_user_cache($user_id) {
    $keys = [
        "nextcloud_config_{$user_id}",
        "pmpro_membership_{$user_id}",
        "nextcloud_used_space_{$user_id}",
        "last_payment_date_{$user_id}"
    ];
    
    foreach ($keys as $key) {
        nextcloud_cache_delete($key);
    }
    
    nextcloud_log_info("User cache invalidated", ['user_id' => $user_id]);
}

// ====================================================================
// VERIFICACIÓN DE DEPENDENCIAS OPTIMIZADA
// ====================================================================

/**
 * Verifica que los plugins requeridos estén activos
 */
function nextcloud_check_dependencies() {
    static $dependencies_checked = false;
    static $dependencies_ok = false;
    
    if ($dependencies_checked) {
        return $dependencies_ok;
    }
    
    $missing_plugins = [];

    // Verificaciones críticas
    if (!function_exists('pmprorh_add_registration_field')) {
        $missing_plugins[] = 'PMPro Register Helper';
        nextcloud_log_error('PMPro Register Helper functions not found');
    }

    if (!function_exists('pmpro_getOption')) {
        $missing_plugins[] = 'Paid Memberships Pro';
        nextcloud_log_error('PMPro core functions not found');
    }

    if (!class_exists('PMProRH_Field')) {
        $missing_plugins[] = 'PMProRH_Field class';
        nextcloud_log_error('PMProRH_Field class not available');
    }

    // Verificación opcional
    if (!class_exists('MemberOrder')) {
        nextcloud_log_warning('MemberOrder class not available - some features may be limited');
    }

    // Admin notice
    if (!empty($missing_plugins) && is_admin() && current_user_can('manage_options')) {
        add_action('admin_notices', function() use ($missing_plugins) {
            $plugins_list = implode(', ', $missing_plugins);
            printf(
                '<div class="notice notice-error"><p><strong>PMPro Dynamic Pricing:</strong> Los siguientes plugins son requeridos: %s</p></div>',
                esc_html($plugins_list)
            );
        });
    }

    $dependencies_ok = empty($missing_plugins);
    $dependencies_checked = true;
    
    nextcloud_log_info('Dependencies check completed', [
        'success' => $dependencies_ok,
        'missing_count' => count($missing_plugins)
    ]);
    
    return $dependencies_ok;
}

// ====================================================================
// DETECCIÓN DE NIVEL ACTUAL OPTIMIZADA
// ====================================================================

/**
 * Detecta el Level ID actual con múltiples estrategias
 */
function nextcloud_get_current_level_id() {
    static $cached_level_id = null;
    
    if ($cached_level_id !== null) {
        return $cached_level_id;
    }
    
    // Estrategias de detección en orden de prioridad
    $detectors = [
        'global_checkout_level' => function() {
            global $pmpro_checkout_level;
            return isset($pmpro_checkout_level->id) ? (int)$pmpro_checkout_level->id : 0;
        },
        'get_level' => function() {
            return !empty($_GET['level']) ? (int)sanitize_text_field($_GET['level']) : 0;
        },
        'get_pmpro_level' => function() {
            return !empty($_GET['pmpro_level']) ? (int)sanitize_text_field($_GET['pmpro_level']) : 0;
        },
        'post_level' => function() {
            return !empty($_POST['level']) ? (int)sanitize_text_field($_POST['level']) : 0;
        },
        'post_pmpro_level' => function() {
            return !empty($_POST['pmpro_level']) ? (int)sanitize_text_field($_POST['pmpro_level']) : 0;
        },
        'global_level' => function() {
            global $pmpro_level;
            return isset($pmpro_level->id) ? (int)$pmpro_level->id : 0;
        },
        'session_level' => function() {
            return !empty($_SESSION['pmpro_level']) ? (int)$_SESSION['pmpro_level'] : 0;
        }
    ];
    
    foreach ($detectors as $source => $detector) {
        $level_id = $detector();
        if ($level_id > 0) {
            nextcloud_log_debug("Level ID detected from {$source}: {$level_id}");
            $cached_level_id = $level_id;
            return $level_id;
        }
    }
    
    // Fallback: extraer de URL
    if (function_exists('pmpro_getOption')) {
        $checkout_page_slug = pmpro_getOption('checkout_page_slug');
        if (!empty($checkout_page_slug) && is_page($checkout_page_slug)) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            if (preg_match('/(?:^|[?&])(level|pmpro_level)=(\d+)/', $request_uri, $matches)) {
                $level_id = (int)$matches[2];
                nextcloud_log_debug("Level ID extracted from URL: {$level_id}");
                $cached_level_id = $level_id;
                return $level_id;
            }
        }
    }
    
    $cached_level_id = 0;
    nextcloud_log_warning('Could not detect Level ID, using default 0');
    return 0;
}

// ====================================================================
// CAMPOS DINÁMICOS OPTIMIZADOS
// ====================================================================

/**
 * Añade campos dinámicos con validación robusta
 */
function nextcloud_add_dynamic_fields() {
    static $fields_added = false;
    static $initialization_attempted = false;
    
    if ($initialization_attempted && !$fields_added) {
        return false;
    }
    
    $initialization_attempted = true;
    
    if ($fields_added) {
        nextcloud_log_debug('Dynamic fields already added, skipping');
        return true;
    }
    
    nextcloud_log_info('Attempting to add dynamic fields');
    
    if (!nextcloud_check_dependencies()) {
        nextcloud_log_error('Dependencies missing, cannot add fields');
        return false;
    }
    
    $current_level_id = nextcloud_get_current_level_id();
    $allowed_levels = nextcloud_get_config('allowed_levels');
    
    if (!in_array($current_level_id, $allowed_levels, true)) {
        nextcloud_log_info("Level {$current_level_id} not in allowed levels, skipping fields");
        return false;
    }
    
    try {
        $config = nextcloud_get_config();
        $fields = [];
        
        // Campo de almacenamiento
        $fields[] = new PMProRH_Field(
            'storage_space',
            'select',
            [
                'label' => 'Espaço de armazenamento',
                'options' => $config['storage_options'],
                'profile' => true,
                'required' => false,
                'memberslistcsv' => true,
                'addmember' => true,
                'location' => 'after_level'
            ]
        );
        
        // Campo de suite ofimática
        $fields[] = new PMProRH_Field(
            'office_suite',
            'select',
            [
                'label' => 'Nextcloud Office <span class="pmpro-tooltip-trigger dashicons dashicons-editor-help" data-tooltip-id="office-suite-tooltip"></span>',
                'options' => $config['office_options'],
                'profile' => true,
                'required' => false,
                'memberslistcsv' => true,
                'addmember' => true,
                'location' => 'after_level',
                'divclass' => 'pmpro_checkout-field-office-suite bordered-field'
            ]
        );
        
        // Campo de frecuencia
        $frequency_options = [
            'monthly' => 'Mensal',
            'semiannual' => 'Semestral (-5%)',
            'annual' => 'Anual (-10%)',
            'biennial' => 'Bienal (-15%)',
            'triennial' => 'Trienal (-20%)',
            'quadrennial' => 'Quadrienal (-25%)',
            'quinquennial' => 'Quinquenal (-30%)'
        ];
        
        $fields[] = new PMProRH_Field(
            'payment_frequency',
            'select',
            [
                'label' => 'Frequência de pagamento',
                'options' => $frequency_options,
                'profile' => true,
                'required' => false,
                'memberslistcsv' => true,
                'addmember' => true,
                'location' => 'after_level'
            ]
        );
        
        // Campo de precio total
        $fields[] = new PMProRH_Field(
            'total_price_display',
            'text',
            [
                'label' => 'Preço total',
                'profile' => false,
                'required' => false,
                'memberslistcsv' => false,
                'addmember' => false,
                'readonly' => true,
                'location' => 'after_level',
                'showrequired' => false,
                'divclass' => 'pmpro_checkout-field-price-display',
                'default' => 'R$ 0,00'
            ]
        );
        
        // Añadir campos
        $fields_added_count = 0;
        foreach($fields as $field) {
            pmprorh_add_registration_field('Configuração do plano', $field);
            $fields_added_count++;
        }
        
        $fields_added = true;
        
        nextcloud_log_info("Dynamic fields added successfully", [
            'level_id' => $current_level_id,
            'fields_count' => $fields_added_count
        ]);
        
        return true;
        
    } catch (Exception $e) {
        nextcloud_log_error('Exception adding dynamic fields', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die('Error en el sistema de membresías: ' . esc_html($e->getMessage()));
        }
        
        return false;
    }
}

// ====================================================================
// CÁLCULOS DE PRECIO OPTIMIZADOS
// ====================================================================

/**
 * Calcula el precio total con caché y validaciones
 */
function nextcloud_calculate_pricing($storage_space, $office_suite, $payment_frequency, $base_price) {
    // Validar parámetros
    if (empty($storage_space) || empty($office_suite) || empty($payment_frequency)) {
        nextcloud_log_warning('Missing parameters for price calculation');
        return $base_price;
    }
    
    // Verificar caché
    $cache_key = "pricing_{$storage_space}_{$office_suite}_{$payment_frequency}_{$base_price}";
    $cached_price = nextcloud_cache_get($cache_key);
    if ($cached_price !== false) {
        return $cached_price;
    }
    
    $config = nextcloud_get_config();
    $price_per_tb = $config['price_per_tb'];
    $office_user_price = $config['office_user_price'];
    
    // Calcular precio de almacenamiento
    $storage_tb = (int)str_replace('tb', '', $storage_space);
    $storage_price = $base_price + ($price_per_tb * max(0, $storage_tb - 1));
    
    // Calcular precio de suite ofimática
    $office_users = (int)str_replace('users', '', $office_suite);
    $office_user_price = ($office_users < 100) ? $office_user_price : ($office_user_price - 3.75);
    $office_price = ($office_users <= 20) ? 0 : ($office_user_price * $office_users);
    
    // Aplicar multiplicador de frecuencia
    $multipliers = $config['frequency_multipliers'];
    $frequency_multiplier = $multipliers[$payment_frequency] ?? 1.0;
    
    // Calcular precio total
    $total_price = ceil(($storage_price + $office_price) * $frequency_multiplier);
    
    // Validar resultado
    if ($total_price < $base_price || $total_price > ($base_price * 100)) {
        nextcloud_log_warning('Calculated price seems unreasonable', [
            'total_price' => $total_price,
            'base_price' => $base_price
        ]);
    }
    
    // Guardar en caché
    nextcloud_cache_set($cache_key, $total_price, 300); // 5 minutos
    
    nextcloud_log_debug('Price calculated', [
        'storage_space' => $storage_space,
        'office_suite' => $office_suite,
        'payment_frequency' => $payment_frequency,
        'total_price' => $total_price
    ]);
    
    return $total_price;
}

/**
 * Configura la periodicidad del nivel
 */
function nextcloud_configure_billing_period($level, $payment_frequency, $total_price) {
    if (empty($level) || !is_object($level)) {
        nextcloud_log_error('Invalid level object provided');
        return $level;
    }
    
    $billing_cycles = [
        'monthly' => ['number' => 1, 'period' => 'Month'],
        'semiannual' => ['number' => 6, 'period' => 'Month'],
        'annual' => ['number' => 12, 'period' => 'Month'],
        'biennial' => ['number' => 24, 'period' => 'Month'],
        'triennial' => ['number' => 36, 'period' => 'Month'],
        'quadrennial' => ['number' => 48, 'period' => 'Month'],
        'quinquennial' => ['number' => 60, 'period' => 'Month']
    ];
    
    $cycle_config = $billing_cycles[$payment_frequency] ?? $billing_cycles['monthly'];
    
    $level->cycle_number = $cycle_config['number'];
    $level->cycle_period = $cycle_config['period'];
    $level->billing_amount = $total_price;
    $level->initial_payment = $total_price;
    $level->trial_amount = 0;
    $level->trial_limit = 0;
    $level->recurring = true;
    
    // Preservar configuración de expiración
    if (!isset($level->expiration_number) || empty($level->expiration_number)) {
        $level->expiration_number = 0;
        $level->expiration_period = '';
        nextcloud_log_debug('Level configured as unlimited');
    }
    
    nextcloud_log_info('Billing period configured', [
        'payment_frequency' => $payment_frequency,
        'cycle_number' => $level->cycle_number,
        'billing_amount' => $level->billing_amount
    ]);
    
    return $level;
}

// ====================================================================
// FUNCIONES AUXILIARES OPTIMIZADAS
// ====================================================================

/**
 * Obtiene el espacio usado en Nextcloud (placeholder)
 */
function get_nextcloud_used_space_tb($user_id) {
    // TODO: Implementar conexión real a API de Nextcloud
    $cache_key = "used_space_{$user_id}";
    $cached = nextcloud_cache_get($cache_key);
    
    if ($cached !== false) {
        return $cached;
    }
    
    // Placeholder - implementar API real
    $used_space_mb = 1200;
    $used_space_tb = round($used_space_mb / 1024 / 1024, 2);
    
    nextcloud_cache_set($cache_key, $used_space_tb, 300);
    return $used_space_tb;
}

/**
 * Obtiene el espacio usado desde Nextcloud (placeholder)
 */
function get_nextcloud_used_storage($user_id) {
    // TODO: Implementar API call real a Nextcloud
    return 0;
}

/**
 * Calcula días restantes en el ciclo actual
 */
function get_remaining_days_in_cycle($user_id, $frequency) {
    if (!class_exists('MemberOrder')) {
        nextcloud_log_warning('MemberOrder class not available');
        return 0;
    }
    
    $last_order = new MemberOrder();
    $last_order->getLastMemberOrder($user_id, 'success');

    if (empty($last_order->timestamp)) {
        return 0;
    }

    $last_payment_date = is_numeric($last_order->timestamp) 
        ? $last_order->timestamp 
        : strtotime($last_order->timestamp);
        
    $cycle_days = get_cycle_days($frequency);
    $days_used = floor((current_time('timestamp') - $last_payment_date) / DAY_IN_SECONDS);

    return max(0, $cycle_days - $days_used);
}

/**
 * Obtiene la duración en días del ciclo
 */
function get_cycle_days($frequency) {
    $cycles = [
        'monthly' => 30,
        'semiannual' => 180,
        'annual' => 365,
        'biennial' => 730,
        'triennial' => 1095,
        'quadrennial' => 1460,
        'quinquennial' => 1825
    ];

    return $cycles[$frequency] ?? 30;
}

// ====================================================================
// SISTEMA DE PRORRATEO OPTIMIZADO
// ====================================================================

/**
 * Valida si un cambio de plan es permitido
 */
function is_change_allowed($current_config, $new_config) {
    $cur_storage = (int)str_replace('tb', '', $current_config['storage_space']);
    $new_storage = (int)str_replace('tb', '', $new_config['storage_space']);
    
    // Verificar downgrade de almacenamiento
    if ($new_storage < $cur_storage) {
        $used = get_nextcloud_used_space_tb(get_current_user_id());
        if ($used > $new_storage) {
            return [false, "Não é possível reduzir para {$new_storage} TB pois você usa {$used} TB"];
        }
    }
    
    return [true, ""];
}

/**
 * Valida y aplica prorrateo para cambios
 */
function nextcloud_validate_and_prorate_changes($level) {
    $user_id = get_current_user_id();
    
    // Cargar configuración actual
    $cache_key = "nextcloud_config_{$user_id}";
    $current_config_json = nextcloud_cache_get($cache_key);
    
    if ($current_config_json === false) {
        $current_config_json = get_user_meta($user_id, 'nextcloud_config', true);
        nextcloud_cache_set($cache_key, $current_config_json);
    }
    
    $current_config = $current_config_json ? json_decode($current_config_json, true) : null;

    // Si no hay configuración previa → nuevo registro
    if (!$current_config) {
        nextcloud_log_debug("New registration detected for user {$user_id}");
        return $level;
    }

    // Obtener nuevas selecciones
    $new_storage = sanitize_text_field($_POST['storage_space'] ?? $current_config['storage_space']);
    $new_suite = sanitize_text_field($_POST['office_suite'] ?? ($current_config['office_suite'] ?? '20users'));
    $new_frequency = sanitize_text_field($_POST['payment_frequency'] ?? $current_config['payment_frequency']);

    // Detectar cambios
    $has_changes = (
        $new_storage !== $current_config['storage_space'] ||
        $new_suite !== ($current_config['office_suite'] ?? '20users') ||
        $new_frequency !== $current_config['payment_frequency']
    );

    if (!$has_changes) {
        nextcloud_log_debug("No changes detected for user {$user_id}");
        return $level;
    }

    // Preparar nueva configuración
    $new_config = [
        'storage_space' => $new_storage,
        'office_suite' => $new_suite,
        'payment_frequency' => $new_frequency,
        'level_id' => $level->id
    ];

    // Validar cambio
    list($allowed, $error_message) = is_change_allowed($current_config, $new_config);
    
    if (!$allowed) {
        nextcloud_log_error("Change rejected for user {$user_id}: {$error_message}");
        wp_die(__($error_message, 'pmpro'));
    }

    // Aplicar prorrateo si es upgrade de almacenamiento
    $current_tb = (int)str_replace('tb', '', $current_config['storage_space']);
    $new_tb = (int)str_replace('tb', '', $new_storage);
    
    if ($new_tb > $current_tb) {
        $level = apply_storage_upgrade_prorate($level, $user_id, $current_tb, $new_tb, $current_config['payment_frequency']);
        nextcloud_log_info("Storage upgrade applied with prorate for user {$user_id}");
    }

    return $level;
}

/**
 * Aplica prorrateo para upgrade de almacenamiento
 */
function apply_storage_upgrade_prorate($level, $user_id, $current_tb, $new_tb, $current_frequency) {
    $price_per_tb = nextcloud_get_config('price_per_tb');
    $full_price_diff = ($new_tb - $current_tb) * $price_per_tb;
    
    $days_remaining = get_remaining_days_in_cycle($user_id, $current_frequency);
    
    if ($days_remaining > 0) {
        $total_days = get_cycle_days($current_frequency);
        $prorated_amount = ($full_price_diff / $total_days) * $days_remaining;
        
        $level->initial_payment += round($prorated_amount, 2);
        
        nextcloud_log_info("Prorate applied", [
            'user_id' => $user_id,
            'upgrade_tb' => $new_tb - $current_tb,
            'days_remaining' => $days_remaining,
            'prorated_amount' => $prorated_amount
        ]);
    }

    return $level;
}

// ====================================================================
// HOOKS Y FILTROS PRINCIPALES
// ====================================================================

/**
 * Hook principal de modificación de precio
 */
function nextcloud_modify_level_pricing($level) {
    // Prevenir procesamiento múltiple
    if (!empty($level->_nextcloud_applied)) {
        nextcloud_log_debug('Level pricing already applied');
        return $level;
    }
    
    $allowed_levels = nextcloud_get_config('allowed_levels');
    if (!in_array((int)$level->id, $allowed_levels, true)) {
        return $level;
    }

    $required_fields = ['storage_space', 'office_suite', 'payment_frequency'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            nextcloud_log_debug("Required field {$field} missing");
            return $level;
        }
    }

    try {
        // Aplicar validaciones y prorrateo
        $level = nextcloud_validate_and_prorate_changes($level);

        // Sanitizar entrada
        $storage_space = sanitize_text_field($_POST['storage_space']);
        $office_suite = sanitize_text_field($_POST['office_suite']);
        $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

        // Obtener precio base original
        $original_level = pmpro_getLevel($level->id);
        $base_price = $original_level ? (float)$original_level->initial_payment : (float)$level->initial_payment;

        // Calcular precio total
        $total_price = nextcloud_calculate_pricing($storage_space, $office_suite, $payment_frequency, $base_price);

        // Aplicar configuración
        $level->initial_payment = $total_price;
        $level = nextcloud_configure_billing_period($level, $payment_frequency, $total_price);
        $level->_nextcloud_applied = true;

        nextcloud_log_info('Level pricing modified successfully', [
            'level_id' => $level->id,
            'final_price' => $total_price,
            'storage_space' => $storage_space,
            'office_suite' => $office_suite,
            'payment_frequency' => $payment_frequency
        ]);

    } catch (Exception $e) {
        nextcloud_log_error('Exception in nextcloud_modify_level_pricing', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    }

    return $level;
}

/**
 * Guardado optimizado de configuración
 */
function nextcloud_save_configuration_and_provision($user_id, $morder) {
    if (!$user_id || !$morder) {
        nextcloud_log_error('Invalid parameters for save_configuration');
        return;
    }

    $required_fields = ['storage_space', 'office_suite', 'payment_frequency'];
    $config_data = [];

    foreach ($required_fields as $field) {
        if (!isset($_REQUEST[$field]) || empty($_REQUEST[$field])) {
            nextcloud_log_warning("Missing {$field} in configuration save");
            return;
        }
        $config_data[$field] = sanitize_text_field($_REQUEST[$field]);
    }

    $config = array_merge($config_data, [
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'level_id' => $morder->membership_id,
        'final_amount' => $morder->InitialPayment,
        'order_id' => $morder->id ?? null,
        'version' => NEXTCLOUD_PLUGIN_VERSION
    ]);

    $config_json = wp_json_encode($config);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        nextcloud_log_error('JSON encoding error', ['error' => json_last_error_msg()]);
        return;
    }

    $saved = update_user_meta($user_id, 'nextcloud_config', $config_json);
    
    // Invalidar caché
    nextcloud_invalidate_user_cache($user_id);

    if (!$saved) {
        nextcloud_log_error('Failed to save user configuration', ['user_id' => $user_id]);
    } else {
        nextcloud_log_info('Configuration saved successfully', [
            'user_id' => $user_id,
            'config' => $config
        ]);
    }
}

/**
 * Localización de script JS con datos optimizados
 */
function nextcloud_localize_pricing_script() {
    // Verificar páginas relevantes
    $is_relevant_page = false;
    
    if (function_exists('pmpro_getOption')) {
        $checkout_page = pmpro_getOption('checkout_page_slug');
        $billing_page = pmpro_getOption('billing_page_slug');
        $account_page = pmpro_getOption('account_page_slug');
        
        $is_relevant_page = (
            (!empty($checkout_page) && is_page($checkout_page)) ||
            (!empty($billing_page) && is_page($billing_page)) ||
            (!empty($account_page) && is_page($account_page))
        );
    }

    if (!$is_relevant_page) {
        return;
    }

    // Obtener datos del nivel actual
    $level_id = nextcloud_get_current_level_id();
    $base_price = 0;

    if ($level_id > 0) {
        $level = pmpro_getLevel($level_id);
        $base_price = $level ? (float)$level->initial_payment : 0;
    }

    // Datos del usuario actual
    $current_storage = '1tb';
    $current_suite = '20users';
    $used_space_tb = 0;

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $config_json = get_user_meta($user_id, 'nextcloud_config', true);

        if ($config_json) {
            $config = json_decode($config_json, true);
            $current_storage = $config['storage_space'] ?? '1tb';
            $current_suite = $config['office_suite'] ?? '20users';
        }

        $used_space_tb = get_nextcloud_used_space_tb($user_id);
    }

    // Localizar script
    $script_handle = 'simply-snippet-pmpro-dynamic-pricing';
    
    wp_localize_script(
        $script_handle,
        'nextcloud_pricing',
        [
            'level_id' => $level_id,
            'base_price' => $base_price,
            'currency_symbol' => 'R$',
            'current_storage' => $current_storage,
            'used_space_tb' => $used_space_tb,
            'current_suite' => $current_suite,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'timestamp' => time(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'version' => NEXTCLOUD_PLUGIN_VERSION
        ]
    );

    nextcloud_log_info('Script localized successfully', [
        'base_price' => $base_price,
        'level_id' => $level_id
    ]);
}

// ====================================================================
// INICIALIZACIÓN Y HOOKS
// ====================================================================

// Hooks de inicialización múltiples para compatibilidad
add_action('plugins_loaded', 'nextcloud_add_dynamic_fields', 25);
add_action('init', 'nextcloud_add_dynamic_fields', 20);
add_action('wp_loaded', 'nextcloud_add_dynamic_fields', 5);

// Hook principal de modificación de precio
add_filter('pmpro_checkout_level', 'nextcloud_modify_level_pricing', 1);

// Hooks de guardado
add_action('pmpro_after_checkout', 'nextcloud_save_configuration_and_provision', 10, 2);

// Localización de scripts
add_action('wp_enqueue_scripts', 'nextcloud_localize_pricing_script', 30);

// Invalidación de caché en cambios de membresía
add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
    nextcloud_invalidate_user_cache($user_id);
    nextcloud_log_info('Cache invalidated on membership change', [
        'user_id' => $user_id,
        'level_id' => $level_id
    ]);
}, 10, 2);

// Indicador de estado en admin bar
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;
    
    $dependencies_ok = nextcloud_check_dependencies();
    $status = $dependencies_ok ? '✅' : '❌';
    
    $wp_admin_bar->add_node([
        'id' => 'nextcloud-dynamic-status',
        'title' => "PMPro Dynamic {$status}",
        'href' => admin_url('plugins.php'),
        'meta' => ['title' => "PMPro Dynamic Status"]
    ]);
}, 100);

nextcloud_log_info('PMPro Dynamic Pricing snippet loaded successfully', [
    'version' => NEXTCLOUD_PLUGIN_VERSION,
    'php_version' => PHP_VERSION
]);
