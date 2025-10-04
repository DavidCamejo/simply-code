<?php
/**
 * PMPro Dynamic Pricing para Nextcloud Banda - VERSIÓN SINCRONIZADA v2.7.7
 * 
 * RESPONSABILIDAD: Lógica de checkout, campos dinámicos y cálculos de precio
 * CORREGIDO: Sincronización completa con theme-scripts.php y JavaScript
 * 
 * @version 2.7.7
 */

if (!defined('ABSPATH')) {
    exit('Acceso directo no permitido');
}

// ====
// CONFIGURACIÓN GLOBAL Y CONSTANTES - SINCRONIZADAS
// ====

define('NEXTCLOUD_BANDA_PLUGIN_VERSION', '2.7.7');
define('NEXTCLOUD_BANDA_CACHE_GROUP', 'nextcloud_banda_dynamic');
define('NEXTCLOUD_BANDA_CACHE_EXPIRY', HOUR_IN_SECONDS);

// CORREGIDO: Definir constante que será usada en JavaScript
if (!defined('NEXTCLOUD_BANDA_BASE_PRICE')) {
    define('NEXTCLOUD_BANDA_BASE_PRICE', 70.00); // Precio base del plan (1TB + 2 usuarios)
}

/**
 * FUNCIÓN CRÍTICA - Normaliza configuración Banda
 */
if (!function_exists('normalize_banda_config')) {
    function normalize_banda_config($config_data) {
        if (!is_array($config_data)) {
            return [
                'storage_space' => '1tb',
                'num_users' => 2,
                'payment_frequency' => 'monthly'
            ];
        }

        // Validar y normalizar storage
        $storage_space = sanitize_text_field($config_data['storage_space'] ?? '1tb');
        $valid_storage = ['1tb', '2tb', '3tb', '4tb', '5tb', '6tb', '7tb', '8tb', '9tb', '10tb', '15tb', '20tb'];
        if (!in_array($storage_space, $valid_storage, true)) {
        $storage_space = '1tb';
        }

        // Validar y normalizar usuarios (mínimo 2, máximo 20)
        $num_users = max(2, min(20, intval($config_data['num_users'] ?? 2)));

        // Validar y normalizar frecuencia
        $payment_frequency = sanitize_text_field($config_data['payment_frequency'] ?? 'monthly');
        $valid_frequencies = ['monthly', 'semiannual', 'annual', 'biennial', 'triennial', 'quadrennial', 'quinquennial'];
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

/**
 * Obtiene configuración real del usuario desde múltiples fuentes
 */
function nextcloud_banda_get_user_real_config($user_id, $membership = null) {
    $real_config = [
    'storage_space' => null,
    'num_users' => null,
    'payment_frequency' => null,
    'final_amount' => null,
    'source' => 'none'
    ];

    // 1. Intentar obtener desde configuración guardada (JSON)
    $config_json = get_user_meta($user_id, 'nextcloud_banda_config', true);
    if (!empty($config_json)) {
        $config = json_decode($config_json, true);
        if (is_array($config) && json_last_error() === JSON_ERROR_NONE && !isset($config['auto_created'])) {
            $real_config['storage_space'] = $config['storage_space'] ?? null;
            $real_config['num_users'] = $config['num_users'] ?? null;
            $real_config['payment_frequency'] = $config['payment_frequency'] ?? null;
            $real_config['final_amount'] = $config['final_amount'] ?? null;
            $real_config['source'] = 'saved_config';
            
            nextcloud_banda_log_debug("Real config found from saved JSON for user {$user_id}", $real_config);
            return $real_config;
        }
    }

    // 2. Intentar obtener desde campos personalizados de PMPro Register Helper
    if (function_exists('pmprorh_getProfileField')) {
        $storage_field = pmprorh_getProfileField('storage_space', $user_id);
        $users_field = pmprorh_getProfileField('num_users', $user_id);
        $frequency_field = pmprorh_getProfileField('payment_frequency', $user_id);

        if (!empty($storage_field) || !empty($users_field) || !empty($frequency_field)) {
            $real_config['storage_space'] = $storage_field ?: null;
            $real_config['num_users'] = $users_field ? intval($users_field) : null;
            $real_config['payment_frequency'] = $frequency_field ?: null;
            $real_config['source'] = 'profile_fields';
            
            nextcloud_banda_log_debug("Real config found from profile fields for user {$user_id}", $real_config);
            return $real_config;
        }
    }

    // 3. Intentar obtener desde user_meta directo
    $storage_meta = get_user_meta($user_id, 'storage_space', true);
    $users_meta = get_user_meta($user_id, 'num_users', true);
    $frequency_meta = get_user_meta($user_id, 'payment_frequency', true);

    if (!empty($storage_meta) || !empty($users_meta) || !empty($frequency_meta)) {
        $real_config['storage_space'] = $storage_meta ?: null;
        $real_config['num_users'] = $users_meta ? intval($users_meta) : null;
        $real_config['payment_frequency'] = $frequency_meta ?: null;
        $real_config['source'] = 'user_meta';
        
        nextcloud_banda_log_debug("Real config found from user meta for user {$user_id}", $real_config);
        return $real_config;
    }

    // 4. Intentar deducir desde información de membresía
    if ($membership && !empty($membership->initial_payment)) {
        $real_config['final_amount'] = (float)$membership->initial_payment;
        $real_config['source'] = 'membership_deduction';
        
        nextcloud_banda_log_debug("Config deduced from membership for user {$user_id}", $real_config);
    }

    // Verificar si el usuario tiene una membresía activa
        if (!pmpro_hasMembershipLevel($user_id)) {
            // Forzar valores por defecto si no hay membresía activa
            return [
                'storage_space' => '1tb',
                'num_users' => 2,
                'payment_frequency' => 'monthly',
                'final_amount' => null,
                'source' => 'defaults_no_membership'
            ];
        }

    nextcloud_banda_log_debug("No real config found for user {$user_id}, returning empty", $real_config);
    return $real_config;
}

/**
 * Configuración centralizada - SINCRONIZADA
 */
function nextcloud_banda_get_config($key = null) {
    static $config = null;

    if ($config === null) {
        $config = [
            'allowed_levels' => [2], // ID del nivel Nextcloud Banda
            'price_per_tb' => 70.00, // Precio por TB adicional
            'price_per_additional_user' => 10.00, // Precio por usuario adicional
            'base_users_included' => 2, // Usuarios incluidos en precio base
            'base_storage_included' => 1, // TB incluidos en precio base
            'base_price_default' => NEXTCLOUD_BANDA_BASE_PRICE, // CORREGIDO: Usar constante
            'min_users' => 2,
            'max_users' => 20,
            'min_storage' => 1,
            'max_storage' => 20,
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
                '10tb' => '10 Terabytes', '15tb' => '15 Terabytes', '20tb' => '20 Terabytes'
            ],
            'user_options' => [
                '2' => '2 usuários (incluídos)',
                '3' => '3 usuários',
                '4' => '4 usuários',
                '5' => '5 usuários',
                '6' => '6 usuários',
                '7' => '7 usuários',
                '8' => '8 usuários',
                '9' => '9 usuários',
                '10' => '10 usuários',
                '15' => '15 usuários',
                '20' => '20 usuários'
            ]
        ];
    }

    return $key ? ($config[$key] ?? null) : $config;
}

// ====
// SISTEMA DE LOGGING
// ====

function nextcloud_banda_log($level, $message, $context = []) {
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
        '[PMPro Banda %s] %s',
        $levels[$level],
        $message
    );

    if (!empty($context)) {
        $log_message .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    error_log($log_message);
}

function nextcloud_banda_log_error($message, $context = []) {
    nextcloud_banda_log(1, $message, $context);
}

function nextcloud_banda_log_info($message, $context = []) {
    nextcloud_banda_log(3, $message, $context);
}

function nextcloud_banda_log_debug($message, $context = []) {
    nextcloud_banda_log(4, $message, $context);
}

// ====
// SISTEMA DE CACHÉ
// ====

function nextcloud_banda_cache_get($key, $default = false) {
    $cached = wp_cache_get($key, NEXTCLOUD_BANDA_CACHE_GROUP);
    if ($cached !== false) {
        nextcloud_banda_log_debug("Cache hit for key: {$key}");
        return $cached;
    }

    nextcloud_banda_log_debug("Cache miss for key: {$key}");
    return $default;
}

function nextcloud_banda_cache_set($key, $data, $expiry = NEXTCLOUD_BANDA_CACHE_EXPIRY) {
    $result = wp_cache_set($key, $data, NEXTCLOUD_BANDA_CACHE_GROUP, $expiry);
    nextcloud_banda_log_debug("Cache set for key: {$key}", ['success' => $result]);
    return $result;
}

function nextcloud_banda_invalidate_user_cache($user_id) {
    $keys = [
        "banda_config_{$user_id}",
        "pmpro_membership_{$user_id}",
        "last_payment_date_{$user_id}",
        "used_space_{$user_id}"
    ];

    foreach ($keys as $key) {
        wp_cache_delete($key, NEXTCLOUD_BANDA_CACHE_GROUP);
    }

    nextcloud_banda_log_info("User cache invalidated", ['user_id' => $user_id]);
}

// ====
// FUNCIONES DE API DE NEXTCLOUD - CORREGIDAS
// ====

function nextcloud_banda_api_get_group_used_space_mb($user_id) {
    // CORREGIDO: Usar get_option en lugar de hardcoded
    $site_url = get_option('siteurl');
    $nextcloud_api_url = 'https://cloud.' . parse_url($site_url, PHP_URL_HOST);

    // Obtener credenciales de variables de entorno
    $nextcloud_api_admin = getenv('NEXTCLOUD_API_ADMIN');
    $nextcloud_api_pass = getenv('NEXTCLOUD_API_PASS');

    // Verificar que las credenciales estén disponibles
    if (empty($nextcloud_api_admin) || empty($nextcloud_api_pass)) {
        nextcloud_banda_log_error('Las credenciales de la API de Nextcloud no están definidas en variables de entorno.');
        return false;
    }

    // Obtener el nombre de usuario de WordPress, que se usará como el ID del grupo en Nextcloud
    $wp_user = get_userdata($user_id);
    if (!$wp_user) {
        nextcloud_banda_log_error("No se pudo encontrar el usuario de WordPress con ID: {$user_id}");
        return false;
    }
    $group_id = 'banda-' . $user_id;

    // Argumentos base para las peticiones a la API
    $api_args = [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($nextcloud_api_admin . ':' . $nextcloud_api_pass),
            'OCS-APIRequest' => 'true',
            'Accept' => 'application/json',
        ],
        'timeout' => 20,
    ];

    // Obtener la lista de usuarios del grupo
    $users_url = sprintf('%s/ocs/v2.php/cloud/groups/%s/users', $nextcloud_api_url, urlencode($group_id));
    $response_users = wp_remote_get($users_url, $api_args);

    if (is_wp_error($response_users)) {
        nextcloud_banda_log_error('Error en la conexión a la API de Nextcloud (obteniendo usuarios)', ['error' => $response_users->get_error_message()]);
        return false;
    }

    $status_code_users = wp_remote_retrieve_response_code($response_users);
    if ($status_code_users !== 200) {
        nextcloud_banda_log_error("La API de Nextcloud devolvió un error al obtener usuarios del grupo '{$group_id}'", ['status_code' => $status_code_users]);
        return false;
    }

    $users_body = wp_remote_retrieve_body($response_users);
    $users_data = json_decode($users_body, true);

    if (empty($users_data['ocs']['data']['users'])) {
        nextcloud_banda_log_info("El grupo '{$group_id}' no tiene usuarios o no existe en Nextcloud. Se devuelve 0MB.");
        return 0.0;
    }

    $nextcloud_user_ids = $users_data['ocs']['data']['users'];
    $total_used_bytes = 0;

    // Obtener el espacio usado por cada usuario y sumarlo
    foreach ($nextcloud_user_ids as $nc_user_id) {
        $user_detail_url = sprintf('%s/ocs/v2.php/cloud/users/%s', $nextcloud_api_url, urlencode($nc_user_id));
        $response_user = wp_remote_get($user_detail_url, $api_args);

        if (is_wp_error($response_user) || wp_remote_retrieve_response_code($response_user) !== 200) {
            nextcloud_banda_log_error("No se pudo obtener la información del usuario de Nextcloud: {$nc_user_id}");
            continue;
        }

        $user_body = wp_remote_retrieve_body($response_user);
        $user_data = json_decode($user_body, true);

        if (isset($user_data['ocs']['data']['quota']['used'])) {
            $total_used_bytes += (int) $user_data['ocs']['data']['quota']['used'];
        }
    }

    // Convertir bytes a Megabytes
    $total_used_mb = $total_used_bytes / (1024 * 1024);

    nextcloud_banda_log_debug("Cálculo de espacio finalizado para el grupo '{$group_id}'", [
        'total_bytes' => $total_used_bytes,
        'total_mb' => $total_used_mb,
        'users_count' => count($nextcloud_user_ids)
    ]);

    return $total_used_mb;
}

// ====
// VERIFICACIÓN DE DEPENDENCIAS
// ====

function nextcloud_banda_check_dependencies() {
    static $dependencies_checked = false;
    static $dependencies_ok = false;

    if ($dependencies_checked) {
        return $dependencies_ok;
    }

    $missing_plugins = [];

    if (!function_exists('pmprorh_add_registration_field')) {
        $missing_plugins[] = 'PMPro Register Helper';
        nextcloud_banda_log_error('PMPro Register Helper functions not found');
    }

    if (!function_exists('pmpro_getOption')) {
        $missing_plugins[] = 'Paid Memberships Pro';
        nextcloud_banda_log_error('PMPro core functions not found');
    }

    if (!class_exists('PMProRH_Field')) {
        $missing_plugins[] = 'PMProRH_Field class';
        nextcloud_banda_log_error('PMProRH_Field class not available');
    }

    if (!empty($missing_plugins) && is_admin() && current_user_can('manage_options')) {
        add_action('admin_notices', function() use ($missing_plugins) {
            $plugins_list = implode(', ', $missing_plugins);
            printf(
                '<div class="notice notice-error"><p><strong>PMPro Banda Dynamic:</strong> Los siguientes plugins son requeridos: %s</p></div>',
                esc_html($plugins_list)
            );
        });
    }

    $dependencies_ok = empty($missing_plugins);
    $dependencies_checked = true;

    return $dependencies_ok;
}

// ====
// FUNCIONES AUXILIARES
// ====

/**
 * Convierte un valor de fecha a timestamp (segundos) de forma segura.
 * Acepta: int (unix), string (Y-m-d H:i:s, Y-m-d, d/m/Y, ISO) o DateTimeInterface.
 * Si no puede convertir, retorna time().
 * Además, recorta a un rango razonable (1970-01-01 a 2100-01-01).
 */
function nextcloud_banda_safe_ts($value) {
    // int ya válido
    if (is_int($value) && $value > 0 && $value < 4102444800) { // ~2100-01-01
        return $value;
    }
    // DateTime/Immutable
    if ($value instanceof DateTimeInterface) {
        $ts = $value->getTimestamp();
        return ($ts > 0 && $ts < 4102444800) ? $ts : time();
    }
    // String
    if (is_string($value) && $value !== '') {
        // Intentar formatos explícitos primero
        $formats = ['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y', DateTimeInterface::ATOM, DATE_RFC3339];
        foreach ($formats as $fmt) {
            $dt = DateTimeImmutable::createFromFormat($fmt, $value);
            if ($dt instanceof DateTimeImmutable) {
                $ts = $dt->getTimestamp();
                return ($ts > 0 && $ts < 4102444800) ? $ts : time();
            }
        }
        // Fallback general
        $ts = strtotime($value);
        if ($ts !== false && $ts > 0 && $ts < 4102444800) {
            return $ts;
        }
    }
    return time();
}

/**
 * Obtiene info del ciclo actual basado estrictamente en cycle_number/cycle_period.
 * Usa DateTimeImmutable y la zona horaria de WordPress para evitar desajustes.
 * Devuelve timestamps (según timezone de WP) y next_payment_date como timestamp del final del ciclo actual (próximo cobro).
 */
function nextcloud_banda_get_next_payment_info($user_id) {
    global $wpdb;

    nextcloud_banda_log('proration', "=== GET NEXT PAYMENT INFO START ===");
    nextcloud_banda_log('proration', "User ID: $user_id");

    $current_level = pmpro_getMembershipLevelForUser($user_id);
    if (empty($current_level) || empty($current_level->id)) {
        nextcloud_banda_log('proration', "ERROR: Usuario sin membresía activa");
        return false;
    }

    $cycle_number = (int) ($current_level->cycle_number ?? 0);
    $cycle_period = strtolower((string) ($current_level->cycle_period ?? ''));
    nextcloud_banda_log('proration', "Level Cycle: $cycle_number $cycle_period");

    if ($cycle_number <= 0 || empty($cycle_period)) {
        nextcloud_banda_log('proration', "ERROR: Ciclo inválido en el nivel");
        return false;
    }

    // Zona horaria de WP
    $tz_string = get_option('timezone_string');
    if (!$tz_string) {
        $gmt_offset = (float) get_option('gmt_offset', 0);
        // Fallback a un UTC offset fijo si no hay timezone_string
        $tz_string = sprintf('UTC%+d', (int) $gmt_offset);
    }
    try {
        $tz = new DateTimeZone($tz_string);
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    // NOW en zona WP
    $now_ts = current_time('timestamp');
    $now = (new DateTimeImmutable('@' . $now_ts))->setTimezone($tz);

    // 1) Buscar última orden "success". Si no hay, probar cancelled, luego refunded.
    $last_order = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->pmpro_membership_orders}
         WHERE user_id = %d AND status = 'success'
         ORDER BY timestamp DESC
         LIMIT 1",
        $user_id
    ));

    if (!$last_order) {
        $last_order = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->pmpro_membership_orders}
             WHERE user_id = %d AND status = 'cancelled'
             ORDER BY timestamp DESC
             LIMIT 1",
            $user_id
        )) ?: $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->pmpro_membership_orders}
             WHERE user_id = %d AND status = 'refunded'
             ORDER BY timestamp DESC
             LIMIT 1",
            $user_id
        ));
    }

    // Determinar cycle_start_anchor
    if ($last_order && !empty($last_order->timestamp)) {
        // pmpro guarda 'timestamp' como datetime en zona del sitio (comúnmente).
        // Creamos DateTime desde ese string en la zona WP.
        try {
            $anchor_dt = new DateTimeImmutable($last_order->timestamp, $tz);
        } catch (Exception $e) {
            $anchor_dt = $now;
            nextcloud_banda_log('proration', "WARN: No se pudo parsear timestamp de orden, usando NOW");
        }
        nextcloud_banda_log('proration', "Última orden: ID {$last_order->id}, Timestamp: " . $anchor_dt->format('Y-m-d H:i:s'));
    } elseif (!empty($current_level->startdate)) {
        // startdate normalmente es un timestamp Unix
        $start_ts = (int) $current_level->startdate;
        $anchor_dt = (new DateTimeImmutable('@' . $start_ts))->setTimezone($tz);
        nextcloud_banda_log('proration', "Usando startdate: " . $anchor_dt->format('Y-m-d H:i:s'));
    } else {
        $anchor_dt = $now;
        nextcloud_banda_log('proration', "No hay órdenes ni startdate, usando NOW como ancla");
    }

    // Normalizar a 00:00 si tu prorrateo es por días completos
    $cycle_start_dt = $anchor_dt->setTime(0, 0, 0);
    nextcloud_banda_log('proration', "Cycle Start (normalizado): " . $cycle_start_dt->format('Y-m-d H:i:s'));

    // Construir intervalo del ciclo
    // Mapear period a DateInterval
    switch ($cycle_period) {
        case 'day':
        case 'days':
            $interval_spec = 'P' . $cycle_number . 'D';
            break;
        case 'week':
        case 'weeks':
            $interval_spec = 'P' . ($cycle_number * 7) . 'D';
            break;
        case 'month':
        case 'months':
            $interval_spec = 'P' . $cycle_number . 'M';
            break;
        case 'year':
        case 'years':
            $interval_spec = 'P' . $cycle_number . 'Y';
            break;
        default:
            nextcloud_banda_log('proration', "ERROR: Período desconocido: $cycle_period");
            return false;
    }

    try {
        $interval = new DateInterval($interval_spec);
    } catch (Exception $e) {
        nextcloud_banda_log('proration', "ERROR: No se pudo crear DateInterval: $interval_spec");
        return false;
    }

    // Calcular cuántos ciclos completos han pasado entre cycle_start_dt y now para saltar directo
    // Evita while iterativo.
    $cycle_end_dt = $cycle_start_dt;
    if ($cycle_period === 'month' || $cycle_period === 'months' || $cycle_period === 'year' || $cycle_period === 'years') {
        // Para meses/años, iterar por saltos puede ser necesario por longitudes variables.
        // Aun así, podemos estimar y luego ajustar.
        $estimate_cycles = 0;
        if ($cycle_period === 'month' || $cycle_period === 'months') {
            // Estimación aproximada por meses
            $diff = $cycle_start_dt->diff($now);
            $months_total = $diff->y * 12 + $diff->m;
            // Ajusta por días si ya cruzó el día del mes
            if ($diff->d > 0 || $diff->h > 0 || $diff->i > 0 || $diff->s > 0) {
                // ya pasó parte del siguiente mes
            }
            $estimate_cycles = (int) floor($months_total / $cycle_number);
        } else {
            // años
            $diff = $cycle_start_dt->diff($now);
            $years_total = $diff->y;
            $estimate_cycles = (int) floor($years_total / $cycle_number);
        }
        if ($estimate_cycles > 0) {
            // Avanza por bloques grandes
            try {
                $block_interval_spec = ($cycle_period === 'month' || $cycle_period === 'months')
                    ? 'P' . ($estimate_cycles * $cycle_number) . 'M'
                    : 'P' . ($estimate_cycles * $cycle_number) . 'Y';
                $block_interval = new DateInterval($block_interval_spec);
                $cycle_start_dt = $cycle_start_dt->add($block_interval);
                $cycle_end_dt   = $cycle_start_dt->add($interval);
            } catch (Exception $e) {
                // fallback a lógica simple
                $cycle_end_dt = $cycle_start_dt->add($interval);
            }
        } else {
            $cycle_end_dt = $cycle_start_dt->add($interval);
        }

        // Si aún estamos más allá del end, avanza ciclos hasta pasarlo (pocos pasos)
        $guard = 0;
        while ($cycle_end_dt <= $now && $guard < 24) { // 24 saltos máximos de seguridad
            $cycle_start_dt = $cycle_end_dt;
            $cycle_end_dt = $cycle_start_dt->add($interval);
            $guard++;
        }
        if ($guard >= 24) {
            nextcloud_banda_log('proration', "ERROR: Guardia excedida al ajustar ciclos (mes/año)");
            return false;
        }
    } else {
        // Días/semanas tienen longitud fija en días: podemos saltar directo con aritmética
        $days_per_cycle = ($cycle_period === 'week' || $cycle_period === 'weeks') ? $cycle_number * 7 : $cycle_number;
        $days_since_start = (int) floor(($now->getTimestamp() - $cycle_start_dt->getTimestamp()) / DAY_IN_SECONDS);
        $cycles_passed = ($days_since_start > 0) ? (int) floor($days_since_start / $days_per_cycle) : 0;
        if ($cycles_passed > 0) {
            $cycle_start_dt = $cycle_start_dt->add(new DateInterval('P' . ($cycles_passed * $days_per_cycle) . 'D'));
        }
        $cycle_end_dt = $cycle_start_dt->add(new DateInterval('P' . $days_per_cycle . 'D'));
        // Si aún quedó atrás, avanza uno más
        if ($cycle_end_dt <= $now) {
            $cycle_start_dt = $cycle_end_dt;
            $cycle_end_dt = $cycle_start_dt->add(new DateInterval('P' . $days_per_cycle . 'D'));
        }
    }

    $cycle_start_ts = $cycle_start_dt->getTimestamp();
    $cycle_end_ts   = $cycle_end_dt->getTimestamp();

    nextcloud_banda_log('proration', "Cycle Start: " . $cycle_start_dt->format('Y-m-d H:i:s'));
    nextcloud_banda_log('proration', "Cycle End (Next Payment): " . $cycle_end_dt->format('Y-m-d H:i:s'));
    nextcloud_banda_log('proration', "=== GET NEXT PAYMENT INFO END ===");

    return [
        'next_payment_date' => $cycle_end_ts,
        'cycle_start'       => $cycle_start_ts,
        'cycle_end'         => $cycle_end_ts
    ];
}

/**
 * Suma un período usando DateTimeImmutable para evitar errores de strtotime/DST.
 */
function nextcloud_banda_add_period($timestamp, $number, $period) {
    $number = (int)$number ?: 1;
    $period = strtolower($period);
    $dt = (new DateTimeImmutable('@' . nextcloud_banda_safe_ts($timestamp)))->setTimezone(wp_timezone());

    switch ($period) {
        case 'day':
        case 'days':
            $interval = new DateInterval('P' . $number . 'D');
            break;
        case 'week':
        case 'weeks':
            $interval = new DateInterval('P' . $number . 'W');
            break;
        case 'month':
        case 'months':
            $interval = new DateInterval('P' . $number . 'M');
            break;
        case 'year':
        case 'years':
            $interval = new DateInterval('P' . $number . 'Y');
            break;
        default:
            $interval = new DateInterval('P' . $number . 'M');
            break;
    }
    $res = $dt->add($interval);
    $ts = $res->getTimestamp();
    // Saneo de rango
    if ($ts <= 0 || $ts >= 4102444800) {
        $ts = time();
    }
    return $ts;
}

/**
 * Resta un período usando DateTimeImmutable.
 */
function nextcloud_banda_sub_period($timestamp, $number, $period) {
    $number = (int)$number ?: 1;
    $period = strtolower($period);
    $dt = (new DateTimeImmutable('@' . nextcloud_banda_safe_ts($timestamp)))->setTimezone(wp_timezone());

    switch ($period) {
        case 'day':
        case 'days':
            $interval = new DateInterval('P' . $number . 'D');
            break;
        case 'week':
        case 'weeks':
            $interval = new DateInterval('P' . $number . 'W');
            break;
        case 'month':
        case 'months':
            $interval = new DateInterval('P' . $number . 'M');
            break;
        case 'year':
        case 'years':
            $interval = new DateInterval('P' . $number . 'Y');
            break;
        default:
            $interval = new DateInterval('P' . $number . 'M');
            break;
    }
    $res = $dt->sub($interval);
    $ts = $res->getTimestamp();
    if ($ts <= 0 || $ts >= 4102444800) {
        $ts = time();
    }
    return $ts;
}

function nextcloud_banda_get_used_space_tb($user_id) {
    $cache_key = "used_space_{$user_id}";
    $cached = nextcloud_banda_cache_get($cache_key);

    if ($cached !== false) {
        return $cached;
    }

    // Llamada a la función de API real
    $used_space_mb = nextcloud_banda_api_get_group_used_space_mb($user_id);

    // Si la llamada a la API falla, usar 0 como valor por defecto
    if ($used_space_mb === false) {
        nextcloud_banda_log_error("Fallo al obtener el espacio usado desde la API para user_id: {$user_id}. Se utilizará 0 como valor por defecto.");
        $used_space_mb = 0;
    }

    // Convierte el valor de MB a TB y redondea a 2 decimales
    $used_space_tb = round($used_space_mb / 1024, 2);

    // Guarda el resultado en caché por 5 minutos
    nextcloud_banda_cache_set($cache_key, $used_space_tb, 300);

    nextcloud_banda_log_debug("Espacio calculado desde API para user {$user_id}", [
    'used_space_mb' => $used_space_mb,
    'used_space_tb' => $used_space_tb
    ]);

    return $used_space_tb;
}

function nextcloud_banda_get_current_level_id() {
    static $cached_level_id = null;

    if ($cached_level_id !== null) {
        return $cached_level_id;
    }

    // CORREGIDO: Usar filter_input para mayor seguridad
    $sources = [
    filter_input(INPUT_GET, 'level', FILTER_VALIDATE_INT),
    filter_input(INPUT_GET, 'pmpro_level', FILTER_VALIDATE_INT),
    filter_input(INPUT_POST, 'level', FILTER_VALIDATE_INT),
    filter_input(INPUT_POST, 'pmpro_level', FILTER_VALIDATE_INT),
    isset($_SESSION['pmpro_level']) ? (int)$_SESSION['pmpro_level'] : null,
    isset($GLOBALS['pmpro_checkout_level']->id) ? (int)$GLOBALS['pmpro_checkout_level']->id : null,
    isset($GLOBALS['pmpro_level']->id) ? (int)$GLOBALS['pmpro_level']->id : null,
    ];

    foreach ($sources as $source) {
        if ($source > 0) {
            $cached_level_id = $source;
            nextcloud_banda_log_debug("Nivel detectado: {$source}");
            return $source;
        }
    }

    $cached_level_id = 0;
    return 0;
}

// ====
// CAMPOS DINÁMICOS - CORREGIDOS
// ====

// Actualizar la función de campos dinámicos para manejar mejor el estado
function nextcloud_banda_add_dynamic_fields() {
    $user_id = get_current_user_id();
    
    // Verificar membresía activa usando next_payment_info
    if ($user_id) {
        $user_levels = pmpro_getMembershipLevelsForUser($user_id);
        $allowed_levels = nextcloud_banda_get_config('allowed_levels');
        $has_active_banda_membership = false;
        
        if (!empty($user_levels)) {
            foreach ($user_levels as $level) {
                if (in_array((int)$level->id, $allowed_levels, true)) {
                    // Verificar usando next_payment_info
                    $cycle_info = nextcloud_banda_get_next_payment_info($user_id);
                    if ($cycle_info && isset($cycle_info['cycle_end']) && $cycle_info['cycle_end'] > time()) {
                        $has_active_banda_membership = true;
                        break;
                    }
                }
            }
        }
        
        // Si no tiene membresía Banda activa, limpiar caché
        if (!$has_active_banda_membership) {
            nextcloud_banda_invalidate_user_cache($user_id);
        }
    }

    static $fields_added = false;

    if ($fields_added) {
        return true;
    }

    if (!nextcloud_banda_check_dependencies()) {
        return false;
    }

    $current_level_id = nextcloud_banda_get_current_level_id();
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');

    if (!in_array($current_level_id, $allowed_levels, true)) {
        nextcloud_banda_log_info("Level {$current_level_id} not in allowed levels, skipping fields");
        return false;
    }

    try {
        $config = nextcloud_banda_get_config();
        $fields = [];
        
                // Obtener configuración real del usuario si existe
        $real_config = [];
        $current_banda_level = null;
        
        if ($user_id) {
            $user_levels = pmpro_getMembershipLevelsForUser($user_id);
            
            if (!empty($user_levels)) {
                foreach ($user_levels as $lvl) {
                    if (in_array((int)$lvl->id, $allowed_levels, true)) {
                        $current_banda_level = $lvl;
                        break;
                    }
                }
            }
            
            // CORREGIDO: Usar la función base sin mejoras para obtener config real
            if ($current_banda_level) {
                $real_config = nextcloud_banda_get_user_real_config($user_id, $current_banda_level);
            }
        }

        // Definir opciones de frecuencia
        $frequency_options = [
            'monthly'     => 'Mensal',
            'semiannual'  => 'Semestral (-5%)',
            'annual'      => 'Anual (-10%)',
            'biennial'    => 'Bienal (-15%)',
            'triennial'   => 'Trienal (-20%)',
            'quadrennial' => 'Quadrienal (-25%)',
            'quinquennial'=> 'Quinquenal (-30%)'
        ];

        // CORREGIDO: Definir valores por defecto verificando source
        $default_storage   = '1tb';
        $default_users     = '2';
        $default_frequency = 'monthly';
        
        // Si hay configuración real guardada, usarla
        if (!empty($real_config) && 
            isset($real_config['source']) && 
            !in_array($real_config['source'], ['none', 'defaults_no_active_membership', 'membership_deduction'], true)) {
            
            $default_storage   = $real_config['storage_space'] ?? '1tb';
            $default_users     = isset($real_config['num_users']) ? strval($real_config['num_users']) : '2';
            $default_frequency = $real_config['payment_frequency'] ?? 'monthly';
            
            nextcloud_banda_log_debug("Usando configuración real del usuario", [
                'user_id' => $user_id,
                'source' => $real_config['source'],
                'storage' => $default_storage,
                'users' => $default_users,
                'frequency' => $default_frequency
            ]);
        } else {
            nextcloud_banda_log_debug("Usando valores por defecto (sin config guardada)", [
                'user_id' => $user_id,
                'source' => $real_config['source'] ?? 'empty'
            ]);
        }
                
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
                'location' => 'after_level',
                'default' => $default_storage
            ]
        );

        // Campo de número de usuários
        $fields[] = new PMProRH_Field(
            'num_users',
            'select',
            [
                'label' => 'Número de usuários',
                'options' => $config['user_options'],
                'profile' => true,
                'required' => false,
                'memberslistcsv' => true,
                'addmember' => true,
                'location' => 'after_level',
                'default' => $default_users
            ]
        );

        // Campo de ciclo
        $fields[] = new PMProRH_Field(
            'payment_frequency',
            'select',
            [
                'label' => 'Ciclo de pagamento',
                'options' => $frequency_options,
                'profile' => true,
                'required' => false,
                'memberslistcsv' => true,
                'addmember' => true,
                'location' => 'after_level',
                'default' => $default_frequency
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
                'default' => 'R$ ' . number_format(NEXTCLOUD_BANDA_BASE_PRICE, 2, ',', '.')
            ]
        );
        
        // Añadir campos
        foreach($fields as $field) {
            pmprorh_add_registration_field('Configuração do plano', $field);
        }
        
        $fields_added = true;
        
        nextcloud_banda_log_info("Dynamic fields added successfully", [
            'level_id' => $current_level_id,
            'fields_count' => count($fields),
            'base_price' => NEXTCLOUD_BANDA_BASE_PRICE,
            'default_values' => [
                'storage' => $default_storage,
                'users' => $default_users,
                'frequency' => $default_frequency
            ]
        ]);
        
        return true;
        
    } catch (Exception $e) {
        nextcloud_banda_log_error('Exception adding dynamic fields', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return false;
    }
}

// ====
// CÁLCULOS DE PRECIO - CORREGIDOS
// ====

function nextcloud_banda_calculate_pricing($storage_space, $num_users, $payment_frequency, $base_price) {
    if (empty($storage_space) || empty($num_users) || empty($payment_frequency)) {
    nextcloud_banda_log_error('Missing parameters for price calculation');
    return $base_price ?: NEXTCLOUD_BANDA_BASE_PRICE; // CORREGIDO: Usar constante
    }

    // Verificar caché
    $cache_key = "pricing_{$storage_space}_{$num_users}_{$payment_frequency}_{$base_price}";
    $cached_price = nextcloud_banda_cache_get($cache_key);
    if ($cached_price !== false) {
    return $cached_price;
    }

    $config = nextcloud_banda_get_config();
    $price_per_tb = $config['price_per_tb'];
    $price_per_user = $config['price_per_additional_user'];
    $base_users_included = $config['base_users_included'];
    $base_storage_included = $config['base_storage_included'];

    // CORREGIDO: Asegurar precio base válido
    if ($base_price <= 0) {
    $base_price = NEXTCLOUD_BANDA_BASE_PRICE;
    }

    // Calcular precio de almacenamiento (1TB incluido en base_price)
    $storage_tb = (int)str_replace('tb', '', $storage_space);
    $additional_tb = max(0, $storage_tb - $base_storage_included);
    $storage_price = $base_price + ($price_per_tb * $additional_tb);

    // Calcular precio por usuarios (2 usuarios incluidos en base_price)
    $additional_users = max(0, (int)$num_users - $base_users_included);
    $user_price = $price_per_user * $additional_users;

    // Precio combinado
    $combined_price = $storage_price + $user_price;

    // Aplicar multiplicador de frecuencia
    $multipliers = $config['frequency_multipliers'];
    $frequency_multiplier = $multipliers[$payment_frequency] ?? 1.0;

    // Calcular precio total
    $total_price = ceil($combined_price * $frequency_multiplier);

    // Guardar en caché
    nextcloud_banda_cache_set($cache_key, $total_price, 300);

    nextcloud_banda_log_debug('Price calculated', [
    'storage_space' => $storage_space,
    'storage_tb' => $storage_tb,
    'additional_tb' => $additional_tb,
    'num_users' => $num_users,
    'additional_users' => $additional_users,
    'payment_frequency' => $payment_frequency,
    'base_price' => $base_price,
    'storage_price' => $storage_price,
    'user_price' => $user_price,
    'combined_price' => $combined_price,
    'total_price' => $total_price
    ]);

    return $total_price;
}

function nextcloud_banda_configure_billing_period($level, $payment_frequency, $total_price) {
    if (empty($level) || !is_object($level)) {
        nextcloud_banda_log_error('Invalid level object provided');
        return $level;
    }

    // Mapa de frecuencias a ciclo PMPro
    $billing_cycles = [
        'monthly'      => ['number' => 1,  'period' => 'Month'],
        'semiannual'   => ['number' => 6,  'period' => 'Month'],
        'annual'       => ['number' => 12, 'period' => 'Month'],
        'biennial'     => ['number' => 24, 'period' => 'Month'],
        'triennial'    => ['number' => 36, 'period' => 'Month'],
        'quadrennial'  => ['number' => 48, 'period' => 'Month'],
        'quinquennial' => ['number' => 60, 'period' => 'Month'],
    ];
    $freq = strtolower($payment_frequency ?: 'monthly');
    $cycle = $billing_cycles[$freq] ?? $billing_cycles['monthly'];

    // Establecer ciclo real en el level (PMPro/gateway usarán estos valores)
    $level->cycle_number   = (int)$cycle['number'];
    $level->cycle_period   = $cycle['period'];
    $level->billing_amount = (float)$total_price;
    $level->initial_payment = (float)$total_price;
    $level->trial_amount   = 0;
    $level->trial_limit    = 0;
    $level->recurring      = true;

    return $level;
}

// ====
// HOOK PRINCIPAL DE MODIFICACIÓN DE PRECIO (MODIFICADO CON PRORRATEO)
// ====

function nextcloud_banda_modify_level_pricing($level) {
    if (!empty($level->_nextcloud_banda_applied)) {
        return $level;
    }

    $allowed_levels = nextcloud_banda_get_config('allowed_levels');
    if (!in_array((int)$level->id, $allowed_levels, true)) {
        return $level;
    }

    $required_fields = ['storage_space', 'num_users', 'payment_frequency'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            return $level;
        }
    }

    try {
        // Entradas saneadas
        $storage_space     = sanitize_text_field(wp_unslash($_POST['storage_space']));
        $num_users         = (int)sanitize_text_field(wp_unslash($_POST['num_users']));
        $payment_frequency = sanitize_text_field(wp_unslash($_POST['payment_frequency']));

        // Base price fallback al level original
        $base_price = NEXTCLOUD_BANDA_BASE_PRICE;
        $original_level = pmpro_getLevel($level->id);
        if ($original_level && !empty($original_level->initial_payment)) {
            $base_price = (float)$original_level->initial_payment;
        } elseif (!empty($level->initial_payment)) {
            $base_price = (float)$level->initial_payment;
        }

        // 1) Calcular precio total para la nueva configuración
        $new_total_price = nextcloud_banda_calculate_pricing($storage_space, $num_users, $payment_frequency, $base_price);

        // 2) Configurar el ciclo del level según frecuencia (canon PMPro)
        $level = nextcloud_banda_configure_billing_period($level, $payment_frequency, $new_total_price);

        // 3) Si el usuario ya tiene el mismo level activo y es upgrade -> PRORRATEO
        $user_id = get_current_user_id();
        if ($user_id && function_exists('pmpro_hasMembershipLevel') && pmpro_hasMembershipLevel($level->id, $user_id)) {
            if (nextcloud_banda_is_plan_upgrade($user_id, $storage_space, $num_users, $payment_frequency)) {
                // Cálculo canon: usar último pago + intervalo del ciclo del level para derivar próximo pago.
                $proration = nextcloud_banda_calculate_proration_core_aligned($user_id, $level->id, $storage_space, $num_users);
                
                // CORREGIDO: Validar que $proration tenga las claves necesarias
                $prorated_amount = isset($proration['prorated_amount']) ? (float)$proration['prorated_amount'] : 0.0;
                $days_remaining  = isset($proration['days_remaining']) ? (int)$proration['days_remaining'] : 0;
                $total_days      = isset($proration['total_days']) ? (int)$proration['total_days'] : 1;
                
                if ($prorated_amount > 0) {
                    // Cobro actual = monto prorrateado
                    $level->initial_payment = $prorated_amount;
                    // Mantener billing_amount como el nuevo total por ciclo
                    $level->billing_amount  = (float)$new_total_price;

                    nextcloud_banda_log_info('Proration (core-aligned) applied', [
                        'user_id'         => $user_id,
                        'level_id'        => $level->id,
                        'new_total_price' => $new_total_price,
                        'initial_payment' => $level->initial_payment,
                        'days_remaining'  => $days_remaining,
                        'total_days'      => $total_days
                    ]);
                } else {
                    // Sin prorrateo válido, usar precio completo
                    $level->initial_payment = (float)$new_total_price;
                    $level->billing_amount  = (float)$new_total_price;
                }
            }
        } else {
            // Nueva suscripción
            $level->initial_payment = (float)$new_total_price;
            $level->billing_amount  = (float)$new_total_price;
        }

        $level->_nextcloud_banda_applied = true;

        nextcloud_banda_log_info('Level pricing modified (core-aligned)', [
            'level_id'          => $level->id,
            'final_initial'     => $level->initial_payment,
            'billing_amount'    => $level->billing_amount,
            'storage_space'     => $storage_space,
            'num_users'         => $num_users,
            'payment_frequency' => $payment_frequency,
            'base_price'        => $base_price
        ]);

    } catch (Exception $e) {
        nextcloud_banda_log_error('Exception in pricing modification', [
            'message' => $e->getMessage()
        ]);
    }

    return $level;
}

// ====
// >>> PRORATION SYSTEM: begin
// ====

/**
 * Calcula el monto prorrateado basado en días restantes del ciclo actual (sin enddate).
 */
/*function nextcloud_banda_calculate_proration($user_id, $new_total_price, $payment_frequency) {
    $user_levels = pmpro_getMembershipLevelsForUser($user_id);
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');

    $current_banda_level = null;
    if (!empty($user_levels)) {
        foreach ($user_levels as $level) {
            if (in_array((int)$level->id, $allowed_levels, true)) {
                $current_banda_level = $level;
                break;
            }
        }
    }

    if (!$current_banda_level) {
        return [
            'is_upgrade'      => true,
            'new_total_price' => $new_total_price,
            'prorated_amount' => $new_total_price,
            'days_remaining'  => 0,
            'total_days'      => 0,
            'current_amount'  => 0,
            'savings'         => 0,
        ];
    }

    // 1) Info de ciclo calculada (puede no reflejar tu “frecuencia vigente”)
    $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $current_banda_level);
    if (!$cycle_info) {
        return [
            'is_upgrade'      => true,
            'new_total_price' => $new_total_price,
            'prorated_amount' => $new_total_price,
            'days_remaining'  => 0,
            'total_days'      => 0,
            'current_amount'  => 0,
            'savings'         => 0,
        ];
    }

    // 2) Config actual (tu fuente de verdad de frecuencia vigente)
    $current_config = nextcloud_banda_get_user_real_config_improved($user_id, $current_banda_level);
    $current_storage   = $current_config['storage_space']     ?: '1tb';
    $current_users     = $current_config['num_users']         ?: 2;
    $current_frequency = $current_config['payment_frequency'] ?: 'monthly';

    // 3) Recalcular total_days según la frecuencia vigente (override)
    // Mapa frecuencia -> número de meses
    $freq_months_map = [
        'monthly'      => 1,
        'semiannual'   => 6,
        'annual'       => 12,
        'biennial'     => 24,
        'triennial'    => 36,
        'quadrennial'  => 48,
        'quinquennial' => 60,
    ];

    $cycle_start_ts = $cycle_info['cycle_start_ts'];
    $cycle_end_ts   = $cycle_info['cycle_end_ts'];
    $now            = time();

    if (isset($freq_months_map[$current_frequency])) {
        $expected_end = nextcloud_banda_add_period($cycle_start_ts, $freq_months_map[$current_frequency], 'Month');
        // Recalcular total del ciclo y días restantes a partir de start + meses esperados
        $total_days     = max(1, (int)ceil(($expected_end - $cycle_start_ts) / DAY_IN_SECONDS));
        $days_remaining = max(0, (int)ceil(($expected_end - $now) / DAY_IN_SECONDS));

        // Si el end real cae cerca del esperado, acepta el real para days_remaining
        $tolerance = 36 * HOUR_IN_SECONDS;
        if (abs(($cycle_end_ts - $expected_end)) <= $tolerance) {
            $total_days     = max(1, (int)ceil(($cycle_end_ts - $cycle_start_ts) / DAY_IN_SECONDS));
            $days_remaining = max(0, (int)ceil(($cycle_end_ts - $now) / DAY_IN_SECONDS));
        }
    } else {
        // Fallback al cálculo original
        $total_days     = $cycle_info['total_days'];
        $days_remaining = $cycle_info['days_remaining'];
    }

    // 4) Precio del plan actual con la misma fórmula que el nuevo
    $current_amount = nextcloud_banda_calculate_pricing(
        $current_storage,
        $current_users,
        $current_frequency,
        NEXTCLOUD_BANDA_BASE_PRICE
    );

    // 5) Prorrateo
    $new_plan_prorated = ($new_total_price * $days_remaining) / $total_days;
    $current_credit    = ($current_amount * $days_remaining) / $total_days;
    $final_amount      = max(0, $new_plan_prorated - $current_credit);

    nextcloud_banda_log_debug('Proration calculated (frequency override)', [
        'user_id'           => $user_id,
        'current_frequency' => $current_frequency,
        'cycle_start'       => wp_date('Y-m-d H:i:s', $cycle_start_ts, wp_timezone()),
        'cycle_end'         => wp_date('Y-m-d H:i:s', $cycle_end_ts, wp_timezone()),
        'total_days'        => $total_days,
        'days_remaining'    => $days_remaining,
        'current_amount'    => $current_amount,
        'new_total_price'   => $new_total_price,
        'new_plan_prorated' => $new_plan_prorated,
        'current_credit'    => $current_credit,
        'final_amount'      => $final_amount
    ]);

    return [
        'is_upgrade'      => true,
        'new_total_price' => $new_total_price,
        'prorated_amount' => round($final_amount, 2),
        'days_remaining'  => $days_remaining,
        'total_days'      => $total_days,
        'current_amount'  => $current_amount,
        'savings'         => max(0, $new_total_price - $final_amount),
    ];
}*/

/**
 * Obtiene la configuración actual del usuario de forma simplificada
 */
function nextcloud_banda_get_user_config($user_id) {
    $config_json = get_user_meta($user_id, 'nextcloud_banda_config', true);
    
    if (empty($config_json)) {
        return [
            'storage' => 1,
            'users' => 2,
            'frequency' => 'monthly'
        ];
    }
    
    $config = json_decode($config_json, true);
    if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
        return [
            'storage' => 1,
            'users' => 2,
            'frequency' => 'monthly'
        ];
    }
    
    // Convertir storage_space a número (TB)
    $storage_tb = 1;
    if (!empty($config['storage_space'])) {
        $storage_tb = (int)str_replace('tb', '', strtolower($config['storage_space']));
    }
    
    return [
        'storage' => $storage_tb,
        'users' => isset($config['num_users']) ? (int)$config['num_users'] : 2,
        'frequency' => $config['payment_frequency'] ?? 'monthly'
    ];
}

/**
 * Calcula el precio para una configuración específica
 */
function nextcloud_banda_calculate_price($level_id, $storage_tb, $num_users) {
    $config = nextcloud_banda_get_config();
    
    $base_price = $config['base_price_default'];
    $price_per_tb = $config['price_per_tb'];
    $price_per_user = $config['price_per_additional_user'];
    $base_storage_included = $config['base_storage_included'];
    $base_users_included = $config['base_users_included'];
    
    // Calcular precio de almacenamiento
    $additional_tb = max(0, $storage_tb - $base_storage_included);
    $storage_price = $base_price + ($price_per_tb * $additional_tb);
    
    // Calcular precio por usuarios
    $additional_users = max(0, $num_users - $base_users_included);
    $user_price = $price_per_user * $additional_users;
    
    // Precio total (sin multiplicador de frecuencia)
    $total_price = $storage_price + $user_price;
    
    nextcloud_banda_log_debug('Price calculated for config', [
        'level_id' => $level_id,
        'storage_tb' => $storage_tb,
        'num_users' => $num_users,
        'additional_tb' => $additional_tb,
        'additional_users' => $additional_users,
        'total_price' => $total_price
    ]);
    
    return $total_price;
}

/**
 * Calcula el prorrateo basado en el ciclo real del nivel PMPro
 */
function nextcloud_banda_calculate_proration_core_aligned($user_id, $new_level_id, $new_storage, $new_users) {
    nextcloud_banda_log_debug("=== PRORATION CORE-ALIGNED START ===", [
        'user_id' => $user_id,
        'new_level_id' => $new_level_id,
        'new_storage' => $new_storage,
        'new_users' => $new_users
    ]);
    
    $current_level = pmpro_getMembershipLevelForUser($user_id);
    
    if (empty($current_level) || empty($current_level->id)) {
        nextcloud_banda_log_debug("Usuario sin membresía activa, proration = 0");
        return [
            'prorated_amount' => 0,
            'days_remaining' => 0,
            'total_days' => 1,
            'next_payment_date' => '',
            'message' => 'No active membership'
        ];
    }
    
    // Obtener configuración actual
    $user_config = nextcloud_banda_get_user_config($user_id);
    $current_storage = $user_config['storage'];
    $current_users = $user_config['users'];
    
    nextcloud_banda_log_debug("Current config", [
        'storage' => $current_storage,
        'users' => $current_users
    ]);
    
    // Convertir new_storage a número
    $new_storage_tb = (int)str_replace('tb', '', strtolower($new_storage));
    
    // Calcular precios
    $current_price = nextcloud_banda_calculate_price($current_level->id, $current_storage, $current_users);
    $new_price = nextcloud_banda_calculate_price($new_level_id, $new_storage_tb, $new_users);
    
    nextcloud_banda_log_debug("Prices calculated", [
        'current_price' => $current_price,
        'new_price' => $new_price
    ]);
    
    // Verificar si es upgrade
    if ($new_price <= $current_price) {
        nextcloud_banda_log_debug("Nuevo precio <= precio actual, no proration");
        return [
            'prorated_amount' => 0,
            'days_remaining' => 0,
            'total_days' => 1,
            'next_payment_date' => '',
            'message' => 'Downgrade or same price'
        ];
    }
    
    // Obtener información del ciclo de pago
    $payment_info = nextcloud_banda_get_next_payment_info($user_id);
    
    if (!$payment_info || empty($payment_info['next_payment_date'])) {
        nextcloud_banda_log_error("No se pudo obtener next_payment_date");
        return [
            'prorated_amount' => 0,
            'days_remaining' => 0,
            'total_days' => 1,
            'next_payment_date' => '',
            'message' => 'Could not determine next payment date'
        ];
    }
    
    $next_payment_timestamp = $payment_info['next_payment_date'];
    $cycle_start_timestamp = $payment_info['cycle_start'];
    $cycle_end_timestamp = $payment_info['cycle_end'];
    
    $now = current_time('timestamp');
    
    // Calcular días
    $total_days = max(1, round(($cycle_end_timestamp - $cycle_start_timestamp) / DAY_IN_SECONDS));
    $days_remaining = max(0, round(($cycle_end_timestamp - $now) / DAY_IN_SECONDS));
    
    // Defensa: si estamos dentro del ciclo pero days_remaining es 0, forzar a 1
    if ($now >= $cycle_start_timestamp && $now < $cycle_end_timestamp && $days_remaining == 0) {
        $days_remaining = 1;
    }
    
    nextcloud_banda_log_debug("Cycle info", [
        'cycle_start' => date('Y-m-d H:i:s', $cycle_start_timestamp),
        'cycle_end' => date('Y-m-d H:i:s', $cycle_end_timestamp),
        'now' => date('Y-m-d H:i:s', $now),
        'total_days' => $total_days,
        'days_remaining' => $days_remaining
    ]);
    
    // Calcular prorrateo
    $price_diff = $new_price - $current_price;
    $prorated_amount = 0;
    
    if ($days_remaining > 0 && $total_days > 0) {
        $prorated_amount = round(($price_diff * $days_remaining) / $total_days, 2);
    }
    
    nextcloud_banda_log_debug("Proration result", [
        'price_diff' => $price_diff,
        'prorated_amount' => $prorated_amount
    ]);
    
    nextcloud_banda_log_debug("=== PRORATION CORE-ALIGNED END ===");
    
    return [
        'prorated_amount' => $prorated_amount,
        'days_remaining' => $days_remaining,
        'total_days' => $total_days,
        'next_payment_date' => date('Y-m-d', $next_payment_timestamp),
        'current_price' => $current_price,
        'new_price' => $new_price,
        'message' => 'Success'
    ];
}

/**
 * Determina si el usuario está actualizando su plan (considera membresía activa vía próxima fecha de pago).
 */
function nextcloud_banda_is_plan_upgrade($user_id, $new_storage, $new_users, $new_frequency) {
    $user_levels = pmpro_getMembershipLevelsForUser($user_id);
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');

    $current_banda_level = null;
    if (!empty($user_levels)) {
        foreach ($user_levels as $level) {
            if (in_array((int)$level->id, $allowed_levels, true)) {
                $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $level);
                if ($cycle_info && $cycle_info['days_remaining'] > 0) {
                    $current_banda_level = $level;
                    break;
                }
            }
        }
    }

    if (!$current_banda_level) {
        nextcloud_banda_log_debug('No upgrade - no active membership by next payment', ['user_id' => $user_id]);
        return false;
    }

    $current_config = nextcloud_banda_get_user_real_config_improved($user_id, $current_banda_level);

    $current_storage = $current_config['storage_space'] ?: '1tb';
    $current_users = $current_config['num_users'] ?: 2;
    $current_frequency = $current_config['payment_frequency'] ?: 'monthly';

    $current_storage_tb = (int)str_replace('tb', '', $current_storage);
    $new_storage_tb = (int)str_replace('tb', '', $new_storage);

    $frequency_order = [
        'monthly' => 1,
        'semiannual' => 2,
        'annual' => 3,
        'biennial' => 4,
        'triennial' => 5,
        'quadrennial' => 6,
        'quinquennial' => 7
    ];

    $is_upgrade = (
        $new_storage_tb > $current_storage_tb ||
        $new_users > $current_users ||
        (($frequency_order[$new_frequency] ?? 1) > ($frequency_order[$current_frequency] ?? 1))
    );

    return $is_upgrade;
}

/**
 * Info detallada de suscripción basada en próxima fecha de pago (sin enddate).
 */
function nextcloud_banda_get_detailed_subscription_info($user_id) {
    $user_levels = pmpro_getMembershipLevelsForUser($user_id);
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');

    $current_banda_level = null;
    if (!empty($user_levels)) {
        foreach ($user_levels as $level) {
            if (in_array((int)$level->id, $allowed_levels, true)) {
                $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $level);
                if ($cycle_info && $cycle_info['days_remaining'] > 0) {
                    $current_banda_level = $level;
                    break;
                }
            }
        }
    }

    if (!$current_banda_level) {
        return false;
    }

    $current_config = nextcloud_banda_get_user_real_config_improved($user_id, $current_banda_level);
    $current_amount = $current_config['final_amount'] ?: (float)$current_banda_level->initial_payment;

    $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $current_banda_level);
    if (!$cycle_info) {
        return false;
    }

    return [
        'current_amount' => $current_amount,
        'days_remaining' => $cycle_info['days_remaining'],
        'total_days' => $cycle_info['total_days'],
        'start_date' => date('Y-m-d H:i:s', $cycle_info['cycle_start_ts']),
        'end_date' => date('Y-m-d H:i:s', $cycle_info['cycle_end_ts']),
        'next_payment_date' => date('Y-m-d H:i:s', $cycle_info['next_payment_ts']),
        'current_config' => $current_config,
        'source' => $cycle_info['source']
    ];
}

/**
 * AJAX handler para cálculo de prorrateo en tiempo real
 */
function nextcloud_banda_ajax_calculate_proration() {
    nextcloud_banda_log_debug("=== AJAX CALCULATE PRORATION START ===");
    
    if (!check_ajax_referer('nextcloud_banda_nonce', 'nonce', false)) {
        nextcloud_banda_log_error("Nonce inválido");
        wp_send_json_error(['message' => 'Invalid nonce']);
        return;
    }
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        nextcloud_banda_log_error("Usuario no autenticado");
        wp_send_json_error(['message' => 'User not authenticated']);
        return;
    }
    
    // Obtener parámetros
    $storage_space = isset($_POST['storage_space']) ? sanitize_text_field($_POST['storage_space']) : '1tb';
    $num_users = isset($_POST['num_users']) ? intval($_POST['num_users']) : 2;
    $payment_frequency = isset($_POST['payment_frequency']) ? sanitize_text_field($_POST['payment_frequency']) : 'monthly';
    
    nextcloud_banda_log_debug("AJAX Params", [
        'user_id' => $user_id,
        'storage_space' => $storage_space,
        'num_users' => $num_users,
        'payment_frequency' => $payment_frequency
    ]);
    
    // Obtener nivel actual
    $current_level = pmpro_getMembershipLevelForUser($user_id);
    if (!$current_level) {
        nextcloud_banda_log_debug("No active membership");
        wp_send_json_success([
            'is_upgrade' => false,
            'message' => 'No active membership'
        ]);
        return;
    }
    $level_id = (int) $current_level->id;

    // Obtener ciclo real del level
    $cycle_number = (int) ($current_level->cycle_number ?? 1);
    $cycle_period = (string) ($current_level->cycle_period ?? 'Month');
    $cycle_label  = nextcloud_banda_map_cycle_label($cycle_number, $cycle_period);
    $current_frequency_derived = nextcloud_banda_derive_frequency_from_cycle($cycle_number, $cycle_period);

    // Normalizar storage
    $storage_tb = (int) str_replace('tb', '', strtolower($storage_space));
    if ($storage_tb <= 0) {
        $storage_tb = 1;
    }

    // Config y multiplicadores
    $config = nextcloud_banda_get_config();
    $frequency_multipliers = isset($config['frequency_multipliers']) && is_array($config['frequency_multipliers'])
        ? $config['frequency_multipliers']
        : ['monthly' => 1.0, 'quarterly' => 3.0, 'semiannual' => 6.0, 'annual' => 12.0];

    // Validar frecuencia pedida
    if (!array_key_exists($payment_frequency, $frequency_multipliers)) {
        nextcloud_banda_log_debug("Frecuencia no válida recibida. Forzando monthly.", ['payment_frequency' => $payment_frequency]);
        $payment_frequency = 'monthly';
    }

    // Calcular nuevo precio con frecuencia
    $base_price = nextcloud_banda_calculate_price($level_id, $storage_tb, $num_users); // precio base "mensual"
    $multiplier = $frequency_multipliers[$payment_frequency] ?? 1.0;
    $new_total_price = (int) ceil($base_price * $multiplier);

    // Obtener config actual para calcular current_amount "por ciclo" con frecuencia vigente
    $current_config = nextcloud_banda_get_user_config($user_id); // storage numérico, users, frequency
    $current_storage_tb = (int) ($current_config['storage'] ?? 1);
    if ($current_storage_tb <= 0) { $current_storage_tb = 1; }

    $current_users = (int) ($current_config['users'] ?? 2);
    if ($current_users <= 0) { $current_users = 1; }

    $current_frequency = $current_config['frequency'] ?? 'monthly';
    if (!array_key_exists($current_frequency, $frequency_multipliers)) {
        $current_frequency = 'monthly';
    }

    // Precio base (sin multiplicador de frecuencia) para la config actual (mensual)
    $current_base_price = nextcloud_banda_calculate_price($level_id, $current_storage_tb, $current_users);
    // Aplicar multiplicador de frecuencia actual
    $current_multiplier = $frequency_multipliers[$current_frequency] ?? 1.0;
    $current_amount = (int) ceil($current_base_price * $current_multiplier);

    nextcloud_banda_log_debug("New price calculated", [
        'base_price' => $base_price,
        'multiplier' => $multiplier,
        'new_total_price' => $new_total_price
    ]);
    nextcloud_banda_log_debug("Current amount (cycle with frequency)", [
        'current_base_price' => $current_base_price,
        'current_multiplier' => $current_multiplier,
        'current_amount' => $current_amount,
        'current_frequency' => $current_frequency
    ]);
    
    // Calcular prorrateo (elige el formato correcto para storage)
    // Opción 1: si la función espera TB numérico:
    $proration = nextcloud_banda_calculate_proration_core_aligned($user_id, $level_id, $storage_tb, $num_users);
    // Opción 2 (elimina la opción 1) si espera string tipo "1tb":
    // $proration = nextcloud_banda_calculate_proration_core_aligned($user_id, $level_id, $storage_space, $num_users);

    // Asegurar claves para evitar notices
    $prorated_amount  = isset($proration['prorated_amount']) ? (float) $proration['prorated_amount'] : 0.0;
    $days_remaining   = isset($proration['days_remaining']) ? (int) $proration['days_remaining'] : 0;
    $total_days       = isset($proration['total_days']) ? (int) $proration['total_days'] : 0;
    $next_payment_date = $proration['next_payment_date'] ?? null;

    $response = [
        'is_upgrade'        => ($prorated_amount > 0),
        'new_total_price'   => $new_total_price,
        'prorated_amount'   => $prorated_amount,
        'days_remaining'    => $days_remaining,
        'total_days'        => $total_days,
        'next_payment_date' => $next_payment_date,
        'current_price'     => isset($proration['current_price']) ? (float) $proration['current_price'] : (float) $current_base_price,
        'new_price'         => isset($proration['new_price']) ? (float) $proration['new_price'] : (float) $base_price,
        'current_amount'    => $current_amount,
        // NUEVO: exponer ciclo real
        'cycle_number'      => $cycle_number,
        'cycle_period'      => $cycle_period,
        'cycle_label'       => $cycle_label,
        'current_frequency' => $current_frequency_derived, // para que JS sepa la frecuencia canónica
    ];

    nextcloud_banda_log_debug("Sending response", $response);
    nextcloud_banda_log_debug("=== AJAX CALCULATE PRORATION END ===");
    
    wp_send_json_success($response);
}

// Registrar el endpoint AJAX
add_action('wp_ajax_nextcloud_banda_calculate_proration', 'nextcloud_banda_ajax_calculate_proration');

// ====
// >>> PRORATION SYSTEM: end
// ====

// ====
// GUARDADO DE CONFIGURACIÓN
// ====

/**
 * Versión mejorada de guardado de configuración
 */
function nextcloud_banda_save_configuration($user_id, $morder) {
    if (!$user_id || !$morder) {
        nextcloud_banda_log_error('Invalid parameters for save configuration', [
            'user_id' => $user_id,
            'morder' => !empty($morder)
        ]);
        return;
    }

    // Validar campos requeridos
    $required_fields = ['storage_space', 'num_users', 'payment_frequency'];
    $config_data = [];

    foreach ($required_fields as $field) {
        if (!isset($_REQUEST[$field])) {
            nextcloud_banda_log_error('Missing required field in request', [
                'user_id' => $user_id,
                'missing_field' => $field
            ]);
            return;
        }
        $config_data[$field] = sanitize_text_field(wp_unslash($_REQUEST[$field]));
    }

    // Normalizar y validar configuración
    $normalized_config = normalize_banda_config($config_data);

    // Preparar datos finales para guardar
    $config = array_merge($normalized_config, [
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
        'level_id' => intval($morder->membership_id),
        'final_amount' => floatval($morder->InitialPayment),
        'order_id' => $morder->id ?? null,
        'version' => NEXTCLOUD_BANDA_PLUGIN_VERSION
    ]);

    $config_json = wp_json_encode($config);
    if ($config_json === false) {
        nextcloud_banda_log_error('Failed to encode configuration JSON', [
            'user_id' => $user_id,
            'config' => $config
        ]);
        return;
    }

    // Limpiar datos anteriores antes de guardar nuevos
    nextcloud_banda_delete_all_user_data($user_id);
    
    // Guardar nueva configuración
    $result = update_user_meta($user_id, 'nextcloud_banda_config', $config_json);

    if ($result === false) {
        nextcloud_banda_log_error('Failed to update user meta for configuration', [
            'user_id' => $user_id
        ]);
        return;
    }

    // Invalidar caché
    nextcloud_banda_invalidate_user_cache($user_id);

    nextcloud_banda_log_info('Configuration saved successfully', [
        'user_id' => $user_id,
        'config' => $config
    ]);
}

// ====
// VISUALIZACIÓN DE CONFIGURACIÓN DEL MIEMBRO
// ====

/**
 * Mapea cycle_number y cycle_period de PMPro a etiqueta legible en portugués
 */
function nextcloud_banda_map_cycle_label($cycle_number, $cycle_period) {
    $period = strtolower((string)$cycle_period);
    $num = (int)$cycle_number;
    
    if ($period === 'month' || $period === 'months') {
        switch ($num) {
            case 1:  return 'Mensal';
            case 6:  return 'Semestral';
            case 12: return 'Anual';
            case 24: return 'Bienal';
            case 36: return 'Trienal';
            case 48: return 'Quadrienal';
            case 60: return 'Quinquenal';
            default: return "{$num} meses";
        }
    }
    if ($period === 'year' || $period === 'years') {
        return ($num === 1) ? 'Anual' : "{$num} anos";
    }
    if ($period === 'week' || $period === 'weeks') {
        return ($num === 1) ? 'Semanal' : "{$num} semanas";
    }
    if ($period === 'day' || $period === 'days') {
        return ($num === 1) ? 'Diário' : "{$num} dias";
    }
    return "{$num} {$period}";
}

/**
 * Deriva payment_frequency canónico desde cycle_number/cycle_period
 */
function nextcloud_banda_derive_frequency_from_cycle($cycle_number, $cycle_period) {
    $period = strtolower((string)$cycle_period);
    $num = (int)$cycle_number;
    
    if ($period === 'month' || $period === 'months') {
        switch ($num) {
            case 1:  return 'monthly';
            case 6:  return 'semiannual';
            case 12: return 'annual';
            case 24: return 'biennial';
            case 36: return 'triennial';
            case 48: return 'quadrennial';
            case 60: return 'quinquennial';
            default: return 'monthly'; // fallback
        }
    }
    if ($period === 'year' || $period === 'years') {
        return ($num === 1) ? 'annual' : 'annual';
    }
    return 'monthly'; // fallback genérico
}

function nextcloud_banda_show_member_config_improved() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        nextcloud_banda_log_debug('No user logged in for member config display');
        return;
    }

    try {
        $user_levels = pmpro_getMembershipLevelsForUser($user_id);
        
        if (empty($user_levels)) {
            nextcloud_banda_log_debug("No memberships found for user {$user_id}");
            return;
        }

        $allowed_levels = nextcloud_banda_get_config('allowed_levels');
        $banda_membership = null;
        
        foreach ($user_levels as $level) {
            if (in_array((int)$level->id, $allowed_levels, true)) {
                // Verificar que tenga ciclo activo usando next_payment_info
                $cycle_info = nextcloud_banda_get_next_payment_info($user_id);
                
                // CORREGIDO: Validar que cycle_info tenga las claves necesarias
                $cycle_end = isset($cycle_info['cycle_end']) ? (int)$cycle_info['cycle_end'] : 0;
                
                if ($cycle_end > time()) {
                    $banda_membership = $level;
                    break;
                }
            }
        }

        if (!$banda_membership) {
            nextcloud_banda_log_debug("No active Banda membership found for user {$user_id}");
            return;
        }

        $membership = $banda_membership;
        $real_config = nextcloud_banda_get_user_real_config_improved($user_id, $membership);

        $storage_options = nextcloud_banda_get_config('storage_options');
        $user_options = nextcloud_banda_get_config('user_options');
        
        $frequency_labels = [
            'monthly' => 'Mensal',
            'semiannual' => 'Semestral (-5%)',
            'annual' => 'Anual (-10%)',
            'biennial' => 'Bienal (-15%)',
            'triennial' => 'Trienal (-20%)',
            'quadrennial' => 'Quadrienal (-25%)',
            'quinquennial' => 'Quinquenal (-30%)'
        ];

        $used_space_tb = nextcloud_banda_get_used_space_tb($user_id);
        
        // Obtener próxima fecha de pago usando la nueva función
        $tz = wp_timezone();
        $next_payment_date = '';
        $cycle_info = nextcloud_banda_get_next_payment_info($user_id);
        
        // CORREGIDO: Validar que cycle_info tenga las claves necesarias
        $next_payment_ts = isset($cycle_info['next_payment_date']) ? (int)$cycle_info['next_payment_date'] : 0;
        
        if ($next_payment_ts > 0) {
            $next_payment_date = wp_date('d/m/Y', $next_payment_ts, $tz);
        } else {
            $next_payment_date = __('Assinatura ativa até cancelamento', 'pmpro-banda');
        }

        $base_users_included = nextcloud_banda_get_config('base_users_included');
        
        $display_storage = $real_config['storage_space'] ?: '1tb';
        $display_users = $real_config['num_users'] ?: $base_users_included;

        // Derivar frecuencia desde el ciclo real del level (fuente de verdad)
        $cycle_number = (int)($membership->cycle_number ?? 1);
        $cycle_period = (string)($membership->cycle_period ?? 'Month');
        $display_frequency_derived = nextcloud_banda_derive_frequency_from_cycle($cycle_number, $cycle_period);
        $cycle_label = nextcloud_banda_map_cycle_label($cycle_number, $cycle_period);

        // Usar la derivada del ciclo real, no la guardada (que puede estar desactualizada)
        $display_frequency = $display_frequency_derived;

        $display_amount = $real_config['final_amount'] ?: (float)$membership->initial_payment;
        
        $additional_users = max(0, $display_users - $base_users_included);
        $is_estimated = ($real_config['source'] === 'none' || $real_config['source'] === 'membership_deduction');

        ?>
        <div class="pmpro_account-profile-field">
            <h3>Detalhes do plano <strong><?php echo esc_html($membership->name); ?></strong></h3>
        
            <?php if ($is_estimated): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 4px; margin: 10px 0; font-size: 0.9em;">
                    <strong>ℹ️ Informação:</strong> Os dados abaixo são estimados baseados na sua assinatura. 
                    Para configurar seu plano personalizado, entre em contato com o suporte.
                </div>
            <?php endif; ?>
        
            <div style="background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ff6b35;">
        
                <div style="margin-bottom: 15px;">
                    <p><strong>🗄️ Armazenamento:</strong> 
                        <?php echo esc_html($storage_options[$display_storage] ?? $display_storage); ?>
                        <?php if ($is_estimated && $real_config['source'] === 'none'): ?>
                            <em style="color: #666; font-size: 0.85em;">(estimado)</em>
                        <?php endif; ?>
                    </p>
            
                    <?php if ($used_space_tb > 0): ?>
                        <p style="margin-left: 20px; color: #666; font-size: 0.9em;">
                            <em>Espaço usado: <?php echo number_format_i18n($used_space_tb, 2); ?> TB</em>
                        </p>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 15px;">
                    <p><strong>👥 Usuários:</strong> 
                        <?php echo esc_html($user_options[$display_users] ?? "{$display_users} usuários"); ?>
                        <?php if ($is_estimated && $real_config['source'] === 'none'): ?>
                            <em style="color: #666; font-size: 0.85em;">(estimado)</em>
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($additional_users > 0): ?>
                        <p style="margin-left: 20px; color: #666; font-size: 0.9em;">
                            <em><?php echo $base_users_included; ?> incluídos + <?php echo $additional_users; ?> adicionais</em>
                        </p>
                    <?php else: ?>
                        <p style="margin-left: 20px; color: #666; font-size: 0.9em;">
                            <em><?php echo $base_users_included; ?> usuários incluídos no plano base</em>
                        </p>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom: 15px;">
                    <p><strong>💳 Ciclo de Pagamento:</strong> 
                        <?php echo esc_html($cycle_label); ?>
                        <?php if ($is_estimated && $real_config['source'] === 'none'): ?>
                            <em style="color: #666; font-size: 0.85em;">(estimado)</em>
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (!empty($display_amount)): ?>
                    <div style="margin-bottom: 15px;">
                        <p><strong>💰 Valor do plano:</strong> 
                            R$ <?php echo number_format_i18n((float)$display_amount, 2); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (!$is_estimated): ?>
                    <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 8px; border-radius: 4px; margin: 10px 0; font-size: 0.85em;">
                        <strong>✅ Configuração ativada</strong> - 
                        <?php 
                        $source_labels = [
                            'saved_config' => 'dados salvos do seu pedido',
                            'profile_fields' => 'campos do seu perfil',
                            'user_meta' => 'configuração do sistema'
                        ];
                        echo esc_html($source_labels[$real_config['source']] ?? 'fonte desconhecida');
                        ?>
                    </div>
                <?php endif; ?>

                <div style="border-top: 1px solid #ddd; padding-top: 15px; margin-top: 15px;">
                    <?php if (!empty($next_payment_date)): ?>
                        <p style="font-size: 0.9em; color: #666;">
                            <strong>🔄 Próximo pagamento:</strong> 
                            <?php echo esc_html($next_payment_date); ?>
                        </p>
                    <?php endif; ?>

                    <p style="font-size: 0.9em; color: #666;">
                        <strong>📅 Cliente desde:</strong> 
                        <?php echo wp_date('d/m/Y', nextcloud_banda_safe_ts($membership->startdate), wp_timezone()); ?>
                    </p>
                </div>

                <div style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                    <p style="font-size: 0.8em; color: #999;">
                        Grupo Nextcloud: <strong>banda-<?php echo esc_html($user_id); ?></strong>
                    </p>
                    <p style="font-size: 0.8em; color: #999;">
                        ID do plano: <?php echo esc_html($membership->id); ?>
                    </p>
                </div>

                <div style="border-top: 1px solid #eee; padding-top: 10px; margin-top: 10px;">
                    <p style="font-size: 0.6em; color: #999;">
                        Versão: <?php echo esc_html(NEXTCLOUD_BANDA_PLUGIN_VERSION); ?> | 
                        Fonte: <?php echo esc_html($real_config['source']); ?>
                        <?php if ($cycle_info && isset($cycle_info['cycle_start'])): ?>
                            | Ciclo: válido
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php

        nextcloud_banda_log_info("Banda member config displayed successfully for user {$user_id}", [
            'source' => $real_config['source'],
            'is_estimated' => $is_estimated
        ]);

    } catch (Exception $e) {
        nextcloud_banda_log_error('Exception in nextcloud_banda_show_member_config_improved', [
            'user_id' => $user_id,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        if (defined('WP_DEBUG') && WP_DEBUG && current_user_can('manage_options')) {
            echo '<div class="pmpro_account-profile-field">';
            echo '<p style="color: red;"><strong>Erro:</strong> Não foi possível carregar os detalhes do plano Banda.</p>';
            echo '</div>';
        }
    }
}

// ====
// MANEJO DE ELIMINACIÓN COMPLETA DE DATOS
// ====

/**
 * Elimina todos los datos de configuración de Banda para un usuario
 */
function nextcloud_banda_delete_all_user_data($user_id) {
    // Eliminar user meta específicos
    delete_user_meta($user_id, 'nextcloud_banda_config');
    delete_user_meta($user_id, 'storage_space');
    delete_user_meta($user_id, 'num_users');
    delete_user_meta($user_id, 'payment_frequency');
    
    // Eliminar campos de PMPro Register Helper si existen
    if (function_exists('pmprorh_getProfileFields')) {
        $fields = ['storage_space', 'num_users', 'payment_frequency'];
        foreach ($fields as $field_name) {
            delete_user_meta($user_id, $field_name);
        }
    }
    
    // Invalidar toda la caché del usuario
    nextcloud_banda_invalidate_user_cache($user_id);
    
    nextcloud_banda_log_info("Todos los datos de Banda eliminados para user_id: {$user_id}");
}

/**
 * Hook para eliminar datos cuando se elimina un usuario de WordPress
 */
add_action('delete_user', 'nextcloud_banda_cleanup_on_user_deletion');
function nextcloud_banda_cleanup_on_user_deletion($user_id) {
    nextcloud_banda_delete_all_user_data($user_id);
    nextcloud_banda_log_info("Limpieza completada al eliminar usuario: {$user_id}");
}

/**
 * Hook mejorado para eliminar configuración al cancelar membresía
 */
add_action('pmpro_after_cancel_membership_level', 'nextcloud_banda_clear_config_on_cancellation_improved', 10, 3);
function nextcloud_banda_clear_config_on_cancellation_improved($user_id, $membership_level_id, $cancelled_levels) {
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');
    
    // Verificar si alguno de los niveles cancelados es de Banda
    $has_banda_level = false;
    if (is_array($cancelled_levels)) {
        foreach ($cancelled_levels as $level) {
            if (in_array((int)$level->membership_id, $allowed_levels, true)) {
                $has_banda_level = true;
                break;
            }
        }
    } else {
        // Compatibilidad con versiones anteriores
        if (is_object($cancelled_levels) && isset($cancelled_levels->membership_id)) {
            if (in_array((int)$cancelled_levels->membership_id, $allowed_levels, true)) {
                $has_banda_level = true;
            }
        }
    }
    
    if ($has_banda_level) {
        nextcloud_banda_delete_all_user_data($user_id);
        nextcloud_banda_log_info("Configuración eliminada tras cancelación de membresía Banda", [
            'user_id' => $user_id,
            'cancelled_levels' => is_array($cancelled_levels) ? array_map(function($l) { return $l->membership_id; }, $cancelled_levels) : 'single_level'
        ]);
    }
}

/**
 * Hook para limpiar datos cuando se cambia completamente de nivel
 */
add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
    // Si el nuevo nivel no es de Banda, limpiar datos anteriores
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');
    if (!in_array((int)$level_id, $allowed_levels, true)) {
        nextcloud_banda_delete_all_user_data($user_id);
    } else {
        // Invalidar caché pero mantener datos
        nextcloud_banda_invalidate_user_cache($user_id);
    }
}, 20, 2); // Prioridad más baja para ejecutarse después de otros hooks

/**
 * Función mejorada para obtener configuración del usuario
 * Forzará valores por defecto si no hay membresía activa
 * Sincroniza payment_frequency con el ciclo real del level
 */
function nextcloud_banda_get_user_real_config_improved($user_id, $membership = null) {
    // Verificar si el usuario tiene membresía Banda activa
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');
    $user_levels = pmpro_getMembershipLevelsForUser($user_id);
    
    $has_active_banda_membership = false;
    $active_level = null;
    
    if (!empty($user_levels)) {
        foreach ($user_levels as $level) {
            if (in_array((int)$level->id, $allowed_levels, true)) {
                // Verificar usando next_payment_info
                $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $level);
                if ($cycle_info && $cycle_info['days_remaining'] > 0) {
                    $has_active_banda_membership = true;
                    $active_level = $level;
                    break;
                }
            }
        }
    }
    
    // Si no tiene membresía Banda activa, retornar valores por defecto
    if (!$has_active_banda_membership) {
        return [
            'storage_space' => '1tb',
            'num_users' => 2,
            'payment_frequency' => 'monthly',
            'final_amount' => null,
            'source' => 'defaults_no_active_membership'
        ];
    }
    
    // Si tiene membresía activa, obtener configuración real
    $real_config = nextcloud_banda_get_user_real_config($user_id, $membership);
    
    // Sincronizar payment_frequency con el ciclo real del level (fuente de verdad)
    if ($active_level) {
        $cycle_number = (int)($active_level->cycle_number ?? 1);
        $cycle_period = (string)($active_level->cycle_period ?? 'Month');
        $derived_freq = nextcloud_banda_derive_frequency_from_cycle($cycle_number, $cycle_period);
        
        // Sobrescribir payment_frequency con la derivada del ciclo real
        $real_config['payment_frequency'] = $derived_freq;
        $real_config['cycle_number'] = $cycle_number;
        $real_config['cycle_period'] = $cycle_period;
        
        nextcloud_banda_log('CONFIG_SYNC', [
            'user_id' => $user_id,
            'level_id' => $active_level->id,
            'cycle_number' => $cycle_number,
            'cycle_period' => $cycle_period,
            'derived_frequency' => $derived_freq,
            'previous_frequency' => $real_config['payment_frequency'] ?? 'none'
        ]);
    }
    
    return $real_config;
}

// ====
// FUNCIONES DE LIMPIEZA PARA CASOS ESPECIALES
// ====

/**
 * Función para limpiar datos de usuarios que ya no tienen membresía activa
 */
function nextcloud_banda_cleanup_inactive_users() {
    $users_with_config = get_users([
        'meta_key' => 'nextcloud_banda_config',
        'fields' => ['ID']
    ]);
    
    $cleaned_count = 0;
    $allowed_levels = nextcloud_banda_get_config('allowed_levels');
    
    foreach ($users_with_config as $user) {
        $user_id = $user->ID;
        $user_levels = pmpro_getMembershipLevelsForUser($user_id);
        
        $has_active_banda_membership = false;
        if (!empty($user_levels)) {
            foreach ($user_levels as $level) {
                if (in_array((int)$level->id, $allowed_levels, true)) {
                    // Verificar usando next_payment_info
                    $cycle_info = nextcloud_banda_get_next_payment_info($user_id, $level);
                    if ($cycle_info && $cycle_info['days_remaining'] > 0) {
                        $has_active_banda_membership = true;
                        break;
                    }
                }
            }
        }
        
        // Si no tiene membresía Banda activa, limpiar sus datos
        if (!$has_active_banda_membership) {
            nextcloud_banda_delete_all_user_data($user_id);
            $cleaned_count++;
        }
    }
    
    nextcloud_banda_log_info("Limpieza de usuarios inactivos completada", [
        'usuarios_revisados' => count($users_with_config),
        'usuarios_limpiados' => $cleaned_count
    ]);
    
    return $cleaned_count;
}

// Agregar endpoint para limpieza manual (solo para administradores)
add_action('wp_ajax_nextcloud_banda_cleanup_inactive', 'nextcloud_banda_cleanup_inactive_endpoint');
function nextcloud_banda_cleanup_inactive_endpoint() {
    if (!current_user_can('manage_options')) {
        wp_die('Acceso denegado');
    }
    
    $cleaned_count = nextcloud_banda_cleanup_inactive_users();
    
    wp_send_json_success([
        'message' => "Limpieza completada. {$cleaned_count} usuarios procesados.",
        'cleaned_count' => $cleaned_count
    ]);
}

// ====
// INICIALIZACIÓN Y HOOKS
// ====

// Hook de inicialización único
add_action('init', 'nextcloud_banda_add_dynamic_fields', 20);

// Hook principal de modificación de precio
add_filter('pmpro_checkout_level', 'nextcloud_banda_modify_level_pricing', 1);

// Mantiene el startdate original si el usuario permanece en el mismo level
add_filter('pmpro_checkout_start_date', function($startdate, $user_id, $level) {
    if (empty($level) || empty($level->id)) {
        return $startdate;
    }
    if (!function_exists('pmpro_hasMembershipLevel')) {
        return $startdate;
    }
    if (!pmpro_hasMembershipLevel($level->id, $user_id)) {
        return $startdate;
    }
    global $wpdb;
    $old = $wpdb->get_var($wpdb->prepare(
        "SELECT startdate FROM $wpdb->pmpro_memberships_users WHERE user_id = %d AND membership_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
        $user_id, $level->id
    ));
    if (!empty($old)) {
        return $old;
    }
    return $startdate;
}, 10, 3);

// Hooks de guardado
add_action('pmpro_after_checkout', 'nextcloud_banda_save_configuration', 10, 2);

// Hook para mostrar configuración en área de miembros
// Modificar la función de visualización para usar la versión mejorada
remove_action('pmpro_account_bullets_bottom', 'nextcloud_banda_show_member_config');
add_action('pmpro_account_bullets_bottom', 'nextcloud_banda_show_member_config_improved');

// Invalidación de caché
add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
    nextcloud_banda_invalidate_user_cache($user_id);
}, 10, 2);

nextcloud_banda_log_info('PMPro Banda Dynamic Pricing loaded - SYNCHRONIZED VERSION', [
    'version' => NEXTCLOUD_BANDA_PLUGIN_VERSION,
    'php_version' => PHP_VERSION,
    'base_price_constant' => NEXTCLOUD_BANDA_BASE_PRICE,
    'normalize_function_exists' => function_exists('normalize_banda_config'),
    'real_config_function_exists' => function_exists('nextcloud_banda_get_user_real_config')
]);
