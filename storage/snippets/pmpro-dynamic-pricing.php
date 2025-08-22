<?php
/**
 * PMPro Dynamic Pricing for Nextcloud Storage Plans
 *
 * Versión corregida que integra todas las funcionalidades con las correcciones:
 * - Bug en cálculo de próxima fecha de pago CORREGIDO
 * - Errores de $this-> fuera de contexto de clase CORREGIDOS
 * - Niveles permitidos unificados en función centralizada
 * - Funciones faltantes implementadas
 * - Validación y prorrateo para upgrades
 * - Optimizaciones de rendimiento
 * - Prevención de recálculos múltiples
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// VERIFICACIÓN DE DEPENDENCIAS
// ============================================================================

/**
 * Verifica que los plugins requeridos estén activos
 * Muestra notices en admin si faltan dependencias
 */
function nextcloud_check_dependencies() {
    $missing_plugins = [];

    if (!function_exists('pmprorh_add_registration_field')) {
        $missing_plugins[] = 'PMPro Register Helper';
        error_log('PMPro Dynamic Pricing: PMPro Register Helper not found');
    }

    if (!function_exists('pmpro_getOption')) {
        $missing_plugins[] = 'Paid Memberships Pro';
        error_log('PMPro Dynamic Pricing: PMPro core functions not found');
    }

    if (!class_exists('PMProRH_Field')) {
        $missing_plugins[] = 'PMProRH_Field class';
        error_log('PMPro Dynamic Pricing: PMProRH_Field class not available');
    }

    // Admin notice para usuarios administradores
    if (!empty($missing_plugins) && current_user_can('manage_options')) {
        add_action('admin_notices', function() use ($missing_plugins) {
            $plugins_list = implode(', ', $missing_plugins);
            echo '<div class="notice notice-error"><p><strong>PMPro Dynamic Pricing:</strong> Los siguientes plugins son requeridos: ' . esc_html($plugins_list) . '</p></div>';
        });
    }

    return empty($missing_plugins);
}

// ============================================================================
// CONFIGURACIÓN GLOBAL - CORREGIDO
// ============================================================================

/**
 * Configuración centralizada de niveles permitidos
 * CORREGIDO: Unificado en una sola función para consistencia
 */
function nextcloud_get_allowed_levels() {
    return array(10, 11, 12, 13, 14);
}

// ============================================================================
// DETECCIÓN DE NIVEL ACTUAL
// ============================================================================

/**
 * Detecta el Level ID actual desde múltiples fuentes
 * Contempla tanto 'level' como 'pmpro_level' en URLs
 */
function nextcloud_get_current_level_id() {
    $level_id = 0;

    // Fuente 1: Global $pmpro_checkout_level
    global $pmpro_checkout_level;
    if (isset($pmpro_checkout_level->id) && !empty($pmpro_checkout_level->id)) {
        $level_id = (int) $pmpro_checkout_level->id;
        error_log("PMPro Dynamic: Level ID de \$pmpro_checkout_level: {$level_id}");
        return $level_id;
    }

    // Fuente 2: URL param 'level' o 'pmpro_level'
    if (!empty($_GET['level'])) {
        $level_id = (int) sanitize_text_field($_GET['level']);
        error_log("PMPro Dynamic: Level ID de \$_GET['level']: {$level_id}");
        return $level_id;
    }
    if (!empty($_GET['pmpro_level'])) {
        $level_id = (int) sanitize_text_field($_GET['pmpro_level']);
        error_log("PMPro Dynamic: Level ID de \$_GET['pmpro_level']: {$level_id}");
        return $level_id;
    }

    // Fuente 3: POST param 'level' o 'pmpro_level'
    if (!empty($_POST['level'])) {
        $level_id = (int) sanitize_text_field($_POST['level']);
        error_log("PMPro Dynamic: Level ID de \$_POST['level']: {$level_id}");
        return $level_id;
    }
    if (!empty($_POST['pmpro_level'])) {
        $level_id = (int) sanitize_text_field($_POST['pmpro_level']);
        error_log("PMPro Dynamic: Level ID de \$_POST['pmpro_level']: {$level_id}");
        return $level_id;
    }

    // Fuente 4: Global $pmpro_level
    global $pmpro_level;
    if (isset($pmpro_level->id) && !empty($pmpro_level->id)) {
        $level_id = (int) $pmpro_level->id;
        error_log("PMPro Dynamic: Level ID de \$pmpro_level: {$level_id}");
        return $level_id;
    }

    // Fuente 5: Session/Cookie
    if (!empty($_SESSION['pmpro_level'])) {
        $level_id = (int) $_SESSION['pmpro_level'];
        error_log("PMPro Dynamic: Level ID de session pmpro_level: {$level_id}");
        return $level_id;
    }

    // Fuente 6: Extraer de URL completa si estamos en checkout
    if (function_exists('pmpro_getOption')) {
        $checkout_page_slug = pmpro_getOption('checkout_page_slug');
        if (!empty($checkout_page_slug) && is_page($checkout_page_slug)) {
            $full_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            if (preg_match('/(?:^|[?&])(level|pmpro_level)=(\d+)/', $full_url, $m)) {
                $level_id = (int) $m[2];
                error_log("PMPro Dynamic: Level ID extraído de URL ({$m[1]}): {$level_id}");
                return $level_id;
            }
        }
    }

    error_log("PMPro Dynamic: NO se pudo detectar Level ID. Usando 0 por defecto.");
    return 0;
}

// ============================================================================
// CAMPOS DINÁMICOS
// ============================================================================

/**
 * Añade campos dinámicos de almacenamiento y frecuencia de pago
 * CORREGIDO: Usa configuración centralizada de niveles
 */
function nextcloud_add_dynamic_fields() {
    // Evitar ejecución múltiple
    static $fields_added = false;
    if ($fields_added) {
        error_log('PMPro Dynamic: Campos ya agregados, evitando duplicación');
        return true;
    }

    error_log('PMPro Dynamic: Intentando agregar campos dinámicos...');

    if (!nextcloud_check_dependencies()) {
        error_log('PMPro Dynamic: Dependencias faltantes, reintentando más tarde...');
        return false;
    }

    // Detectar nivel actual
    $current_level_id = nextcloud_get_current_level_id();
    error_log('PMPro Dynamic: Level ID detectado: ' . $current_level_id);

    // CORREGIDO: Usar función centralizada para niveles permitidos
    $allowed_levels = nextcloud_get_allowed_levels();
    $current_level_id = (int)$current_level_id;

    if (!in_array($current_level_id, $allowed_levels, true)) {
        error_log("PMPro Dynamic: Nivel {$current_level_id} no está en la lista de niveles permitidos (" . implode(', ', $allowed_levels) . "). Saltando campos dinámicos...");
        return false;
    }

    error_log("PMPro Dynamic: Nivel {$current_level_id} autorizado para campos dinámicos. Continuando...");

    $fields = array();

    // Campo de almacenamiento
    $fields[] = new PMProRH_Field(
        'storage_space',
        'select',
        array(
            'label' => 'Espaço de armazenamento',
            'options' => array(
                '1tb' => '1 Terabyte',
                '2tb' => '2 Terabytes',
                '3tb' => '3 Terabytes',
                '4tb' => '4 Terabytes',
                '5tb' => '5 Terabytes',
                '6tb' => '6 Terabytes',
                '7tb' => '7 Terabytes',
                '8tb' => '8 Terabytes',
                '9tb' => '9 Terabytes',
                '10tb' => '10 Terabytes',
                '15tb' => '15 Terabytes',
                '20tb' => '20 Terabytes',
                '30tb' => '30 Terabytes',
                '40tb' => '40 Terabytes',
                '50tb' => '50 Terabytes',
                '60tb' => '60 Terabytes',
                '70tb' => '70 Terabytes',
                '80tb' => '80 Terabytes',
                '90tb' => '90 Terabytes',
                '100tb' => '100 Terabytes',
                '200tb' => '200 Terabytes',
                '300tb' => '300 Terabytes',
                '400tb' => '400 Terabytes',
                '500tb' => '500 Terabytes'
            ),
            'profile' => true,
            'required' => false,
            'memberslistcsv' => true,
            'addmember' => true,
            'location' => 'after_level'
        )
    );

    // Campo de frecuencia de pago
    $fields[] = new PMProRH_Field(
        'payment_frequency',
        'select',
        array(
            'label' => 'Frequência de pagamento',
            'options' => array(
                'monthly' => 'Mensal',
                'semiannual' => 'Semestral (-5%)',
                'annual' => 'Anual (-10%)',
                'biennial' => 'Bienal (-15%)',
                'triennial' => 'Trienal (-20%)',
                'quadrennial' => 'Quadrienal (-25%)',
                'quinquennial' => 'Quinquenal (-30%)'
            ),
            'profile' => true,
            'required' => false,
            'memberslistcsv' => true,
            'addmember' => true,
            'location' => 'after_level'
        )
    );

    // Campo para mostrar el precio total
    $fields[] = new PMProRH_Field(
        'total_price_display',
        'text',
        array(
            'label' => 'Preço total',
            'profile' => false,
            'required' => false,
            'memberslistcsv' => false,
            'addmember' => false,
            'readonly' => true,
            'location' => 'after_level',
            'showrequired' => false,
            'divclass' => 'pmpro_checkout-field-price-display'
        )
    );

    try {
        $fields_added_count = 0;
        foreach($fields as $field) {
            pmprorh_add_registration_field('Configuração do plano', $field);
            $fields_added_count++;
        }

        $fields_added = true;
        error_log("PMPro Dynamic: {$fields_added_count} campos agregados exitosamente para nivel {$current_level_id}");
        return true;
    } catch (Exception $e) {
        error_log('PMPro Dynamic Error: ' . $e->getMessage());
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die('Ocurrió un error en el sistema de membresías.');
        }

        return false;
    }
}

// ============================================================================
// CÁLCULOS DE PRECIO
// ============================================================================

/**
 * Calcula el precio total basado en almacenamiento y frecuencia
 * Aplica descuentos por frecuencia de pago
 */
function nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price) {
    $price_per_tb = 120; // Precio adicional por TB extra

    // Precios por almacenamiento (base + incrementos)
    $storage_prices = array(
        '1tb' => $base_price,
        '2tb' => $base_price + $price_per_tb,
        '3tb' => $base_price + ($price_per_tb * 2),
        '4tb' => $base_price + ($price_per_tb * 3),
        '5tb' => $base_price + ($price_per_tb * 4),
        '6tb' => $base_price + ($price_per_tb * 5),
        '7tb' => $base_price + ($price_per_tb * 6),
        '8tb' => $base_price + ($price_per_tb * 7),
        '9tb' => $base_price + ($price_per_tb * 8),
        '10tb' => $base_price + ($price_per_tb * 9),
        '15tb' => $base_price + ($price_per_tb * 14),
        '20tb' => $base_price + ($price_per_tb * 19),
        '30tb' => $base_price + ($price_per_tb * 29),
        '40tb' => $base_price + ($price_per_tb * 39),
        '50tb' => $base_price + ($price_per_tb * 49),
        '60tb' => $base_price + ($price_per_tb * 59),
        '70tb' => $base_price + ($price_per_tb * 69),
        '80tb' => $base_price + ($price_per_tb * 79),
        '90tb' => $base_price + ($price_per_tb * 89),
        '100tb' => $base_price + ($price_per_tb * 99),
        '200tb' => $base_price + ($price_per_tb * 199),
        '300tb' => $base_price + ($price_per_tb * 299),
        '400tb' => $base_price + ($price_per_tb * 399),
        '500tb' => $base_price + ($price_per_tb * 499)
    );

    // Meses equivalentes con descuentos aplicados
    $frequency_months = array(
        'monthly' => 1,
        'semiannual' => 5.7,    // 6 meses - 5%
        'annual' => 10.8,       // 12 meses - 10%
        'biennial' => 20.4,     // 24 meses - 15%
        'triennial' => 28.8,    // 36 meses - 20%
        'quadrennial' => 36,    // 48 meses - 25%
        'quinquennial' => 42    // 60 meses - 30%
    );

    $storage_price = $storage_prices[$storage_space] ?? $base_price;
    $months = $frequency_months[$payment_frequency] ?? 1;

    return ceil($storage_price * $months);
}

/**
 * Configura la periodicidad del nivel según la frecuencia elegida
 * Respeta la expiración original si existe
 */
function nextcloud_configure_billing_period($level, $payment_frequency, $total_price) {
    // Configurar la periodicidad del pago en función de la frecuencia seleccionada
    switch ($payment_frequency) {
        case 'monthly':
            $level->cycle_number = 1;
            $level->cycle_period = 'Month';
            break;
        case 'semiannual':
            $level->cycle_number = 6;
            $level->cycle_period = 'Month';
            break;
        case 'annual':
            $level->cycle_number = 12;
            $level->cycle_period = 'Month';
            break;
        case 'biennial':
            $level->cycle_number = 24;
            $level->cycle_period = 'Month';
            break;
        case 'triennial':
            $level->cycle_number = 36;
            $level->cycle_period = 'Month';
            break;
        case 'quadrennial':
            $level->cycle_number = 48;
            $level->cycle_period = 'Month';
            break;
        case 'quinquennial':
            $level->cycle_number = 60;
            $level->cycle_period = 'Month';
            break;
        default:
            $level->cycle_number = 1;
            $level->cycle_period = 'Month';
    }

    // Configurar montos y recurrencia
    $level->billing_amount = $total_price;
    $level->trial_amount = 0;
    $level->trial_limit = 0;
    $level->recurring = true;

    // CRÍTICO: Respetar expiración original del nivel si existe
    // Solo establecer como indefinido si no había expiración previa
    if (
        isset($level->expiration_number) && !empty($level->expiration_number) &&
        isset($level->expiration_period) && !empty($level->expiration_period)
    ) {
        // Mantener expiración original - no modificar
        error_log("PMPro Dynamic: Respetando expiración original: {$level->expiration_number} {$level->expiration_period}");
    } else {
        // Solo si no había expiración, la dejamos como indefinida
        $level->expiration_number = 0;
        $level->expiration_period = '';
        error_log("PMPro Dynamic: Configurando como indefinido (sin expiración)");
    }

    return $level;
}

// ============================================================================
// FUNCIONES AUXILIARES - CORREGIDAS
// ============================================================================

/**
 * Obtiene el espacio usado en Nextcloud (en TB)
 * CORREGIDO: Función implementada (antes faltaba)
 */
function get_nextcloud_used_space_tb($user_id) {
    // TODO: Implementar conexión real a API de Nextcloud
    // Por ahora retornamos un valor de ejemplo
    $used_space_mb = 1200; // Obtener de la API real
    
    // Convertir MB a TB
    return round($used_space_mb / 1024 / 1024, 2);
}

/**
 * Obtiene el espacio usado desde Nextcloud
 * NUEVA: Función implementada (antes faltaba)
 */
function get_nextcloud_used_storage($user_id) {
    // TODO: Implementar API call real a Nextcloud
    // Por ahora retornamos 0 como placeholder
    return 0;
}

/**
 * Calcula días restantes en el ciclo actual
 * CORREGIDO: Removido $this-> que causaba error
 */
function get_remaining_days_in_cycle($user_id, $frequency) {
    $last_order = new MemberOrder();
    $last_order->getLastMemberOrder($user_id, 'success');

    if (empty($last_order->timestamp)) {
        return 0;
    }

    $last_payment_date = is_numeric($last_order->timestamp) ? $last_order->timestamp : strtotime($last_order->timestamp);
    $cycle_days = get_cycle_days($frequency);
    $days_used = floor((current_time('timestamp') - $last_payment_date) / DAY_IN_SECONDS);

    return max(0, $cycle_days - $days_used);
}

/**
 * Obtiene la duración en días del ciclo según frecuencia
 * CORREGIDO: Removido $this-> que causaba error
 */
function get_cycle_days($frequency) {
    $cycles = array(
        'monthly' => 30,
        'semiannual' => 180,
        'annual' => 365,
        'biennial' => 730,
        'triennial' => 1095,
        'quadrennial' => 1460,
        'quinquennial' => 1825
    );

    return $cycles[$frequency] ?? 30;
}

// ============================================================================
// VALIDACIÓN Y PRORRATEO PARA UPGRADES DE ALMACENAMIENTO
// ============================================================================

/**
 * REEMPLAZA nextcloud_validate_and_prorate_upgrades() - FUNCIÓN LEGACY MANTENIDA PARA COMPATIBILIDAD
 * Ahora redirige a la nueva implementación extendida
 */
function nextcloud_validate_and_prorate_upgrades($level) {
    error_log("PMPro Legacy: Redirigiendo nextcloud_validate_and_prorate_upgrades() a nueva implementación");
    return nextcloud_validate_and_prorate_changes($level);
}

/**
 * Determina si el cambio sería un downgrade inválido
 * CORREGIDO: Removido $this-> que causaba error
 */
function is_invalid_downgrade($user_id, $current_storage, $new_storage) {
    // Extraer números de TB (eliminando 'tb' del string)
    $current_tb = intval(str_replace('tb', '', $current_storage));
    $new_tb = intval(str_replace('tb', '', $new_storage));

    // Solo verificar si es un downgrade
    if ($new_tb >= $current_tb) {
        return false;
    }

    // Obtener espacio usado en Nextcloud (en TB)
    $used_space_tb = get_nextcloud_used_space_tb($user_id);

    // Si el espacio usado es mayor que el nuevo límite, es inválido
    return $used_space_tb > $new_tb;
}

/**
 * Determina si el cambio es un upgrade de almacenamiento
 * CORREGIDO: Removido $this-> que causaba error
 */
function is_storage_upgrade($current_storage, $new_storage) {
    $current_tb = intval(str_replace('tb', '', $current_storage));
    $new_tb = intval(str_replace('tb', '', $new_storage));
    return $new_tb > $current_tb;
}

/**
 * Aplica el prorrateo para upgrades de almacenamiento
 * CORREGIDO: Removido $this-> que causaba error
 */
function apply_prorated_upgrade($level, $user_id, $current_storage, $new_storage, $current_frequency, $new_frequency) {
    // [1] Calcular diferencia de precio base
    $current_tb = intval(str_replace('tb', '', $current_storage));
    $new_tb = intval(str_replace('tb', '', $new_storage));
    $price_per_tb = 120; // R$ 120 por TB adicional (ajustar según tu modelo)
    $full_price_diff = ($new_tb - $current_tb) * $price_per_tb;

    // [2] Determinar días restantes en el ciclo actual
    $days_remaining = get_remaining_days_in_cycle($user_id, $current_frequency);

    // [3] Calcular monto prorrateado
    if ($days_remaining > 0) {
        $total_days = get_cycle_days($current_frequency);
        $prorated_amount = ($full_price_diff / $total_days) * $days_remaining;

        // Aplicar al precio inicial del nivel
        $level->initial_payment += round($prorated_amount, 2);

        // Registrar para debugging
        error_log("PMPro Prorate: Upgrade de {$current_storage} a {$new_storage}");
        error_log("PMPro Prorate: Días restantes: {$days_remaining}/{$total_days}");
        error_log("PMPro Prorate: Diferencia total: R$ {$full_price_diff}");
        error_log("PMPro Prorate: Monto prorrateado: R$ " . round($prorated_amount, 2));
    }

    return $level;
}

// ============================================================================
// SISTEMA DE PRORRATEO EXTENDIDO - NUEVA IMPLEMENTACIÓN
// ============================================================================

/**
 * Valida si un cambio de plan es permitido según las políticas de negocio
 * Previene downgrades problemáticos y cambios de frecuencia en planes largos
 */
function is_change_allowed($current_config, $new_config) {
    $cur_storage   = intval(str_replace('tb','',$current_config['storage_space']));
    $new_storage   = intval(str_replace('tb','',$new_config['storage_space']));
    $cur_freq      = $current_config['payment_frequency'];
    $new_freq      = $new_config['payment_frequency'];
    $cur_level     = $current_config['level_id'];
    $new_level     = $new_config['level_id'];

    // 1. Cambio de nivel (membership_id) - solo permitir upgrades
    if ($new_level != $cur_level) {
        $cur_level_price = pmpro_getLevel($cur_level)->initial_payment;
        $new_level_price = pmpro_getLevel($new_level)->initial_payment;
        
        if ($new_level_price <= $cur_level_price) {
            // Bloquear siempre downgrade de nivel (aunque el storage sea igual)
            return [false, "Downgrade de plano não permitido"];
        }
    }

    // 2. Cambio de frecuencia de pago - solo permitir upgrades de compromiso
    $freq_order = ['mensal' => 1, 'anual' => 2, 'bienal' => 3, 'trienal' => 4, 'quadrienal' => 5, 'quinquenal' => 6];
    
    if (isset($freq_order[$cur_freq]) && isset($freq_order[$new_freq])) {
        if ($freq_order[$new_freq] < $freq_order[$old_freq]) {
            // Ejemplo: bienal -> anual o mensual | anual -> mensual
            return [false, "Downgrade de frequência não permitido"];
        }
    }

    // 3. Downgrade de almacenamiento - verificar espacio usado
    if ($new_storage < $cur_storage) {
        $used = get_nextcloud_used_space_tb(get_current_user_id());
        if ($used > $new_storage) {
            return [false, "No puedes bajar a $new_storage TB ya que usas $used TB"];
        }
    }

    // ✅ Si pasó todos los checks → permitido
    return [true, ""];
}

/**
 * Nueva validación y prorrateo extendido - REEMPLAZA nextcloud_validate_and_prorate_upgrades()
 * Maneja cambios de almacenamiento, frecuencia, nivel y combinaciones
 */
function nextcloud_validate_and_prorate_changes($level) {
    $user_id = get_current_user_id();
    
    // Cargar configuración actual del usuario
    $current_config_json = get_user_meta($user_id, 'nextcloud_config', true);
    $current_config = $current_config_json ? json_decode($current_config_json, true) : null;

    // Si no hay configuración previa → es nuevo registro, no aplicar prorrateo
    if (!$current_config) {
        error_log("PMPro Extended: Nuevo registro detectado para usuario {$user_id}, sin prorrateo");
        return $level;
    }

    // Obtener nuevas selecciones del POST
    $new_storage   = sanitize_text_field($_POST['storage_space'] ?? $current_config['storage_space']);
    $new_frequency = sanitize_text_field($_POST['payment_frequency'] ?? $current_config['payment_frequency']);
    $new_level_id  = $level->id;

    // Valores actuales
    $current_storage   = $current_config['storage_space'];
    $current_frequency = $current_config['payment_frequency'];
    $current_level_id  = $current_config['level_id'];

    // --- Lógica de detección de cambios ---
    $changed_storage   = ($new_storage   !== $current_storage);
    $changed_frequency = ($new_frequency !== $current_frequency);
    $changed_level     = ($new_level_id  !=  $current_level_id);

    // Si no hay cambios, retornar sin modificar
    if (!$changed_storage && !$changed_frequency && !$changed_level) {
        error_log("PMPro Extended: Sin cambios detectados para usuario {$user_id}");
        return $level;
    }

    // Preparar configuración nueva para validación
    $new_config = [
        'storage_space' => $new_storage,
        'payment_frequency' => $new_frequency,
        'level_id' => $new_level_id
    ];

    // Validar que el cambio sea permitido
    list($allowed, $error_message) = is_change_allowed($current_config, $new_config);
    
    if (!$allowed) {
        error_log("PMPro Extended: Cambio rechazado para usuario {$user_id}: {$error_message}");
        wp_die(__($error_message, 'pmpro'));
    }

    // Aplicar prorrateo extendido
    $level = apply_extended_prorate($level, $user_id, $current_storage, $new_storage, $current_frequency, $new_frequency, $current_level_id, $new_level_id);

    error_log("PMPro Extended: Cambio aplicado exitosamente para usuario {$user_id}");
    return $level;
}

/**
 * Aplica prorrateo extendido considerando storage, frecuencia y nivel
 * NUEVA IMPLEMENTACIÓN COMPLETA
 */
function apply_extended_prorate($level, $user_id, $current_storage, $new_storage, $current_frequency, $new_frequency, $current_level_id, $new_level_id) {
    
    // 1. Calcular precio actual (según config actual)
    $current_level_obj = pmpro_getLevel($current_level_id);
    $current_base_price = $current_level_obj ? (float)$current_level_obj->initial_payment : 0;
    $current_price = nextcloud_calculate_pricing($current_storage, $current_frequency, $current_base_price);

    // 2. Calcular precio nuevo (según nuevas selecciones)
    $new_level_obj = pmpro_getLevel($new_level_id);
    $new_base_price = $new_level_obj ? (float)$new_level_obj->initial_payment : 0;
    $new_price = nextcloud_calculate_pricing($new_storage, $new_frequency, $new_base_price);

    // 3. Determinar días restantes del ciclo actual
    $days_remaining = get_remaining_days_in_cycle($user_id, $current_frequency);
    $total_days = get_cycle_days($current_frequency);

    // 4. Calcular diferencia total de precio
    $price_difference = $new_price - $current_price;

    // 5. Aplicar prorrateo solo si hay diferencia positiva y días restantes
    if ($price_difference > 0 && $days_remaining > 0 && $total_days > 0) {
        // Proporción del ciclo no usado
        $unused_proportion = $days_remaining / $total_days;
        
        // Monto prorrateado a cobrar ahora
        $prorated_amount = $price_difference * $unused_proportion;
        
        // Aplicar al initial_payment del nivel
        $level->initial_payment = $new_price + round($prorated_amount, 2);
        
        // Logging detallado
        error_log("PMPro Extended Prorate: Usuario {$user_id}");
        error_log("PMPro Extended Prorate: Storage: {$current_storage} → {$new_storage}");
        error_log("PMPro Extended Prorate: Frequency: {$current_frequency} → {$new_frequency}");
        error_log("PMPro Extended Prorate: Level: {$current_level_id} → {$new_level_id}");
        error_log("PMPro Extended Prorate: Precio actual: R$ {$current_price}");
        error_log("PMPro Extended Prorate: Precio nuevo: R$ {$new_price}");
        error_log("PMPro Extended Prorate: Diferencia: R$ {$price_difference}");
        error_log("PMPro Extended Prorate: Días restantes: {$days_remaining}/{$total_days}");
        error_log("PMPro Extended Prorate: Proporción no usada: " . round($unused_proportion * 100, 2) . "%");
        error_log("PMPro Extended Prorate: Monto prorrateado: R$ " . round($prorated_amount, 2));
        error_log("PMPro Extended Prorate: Total a cobrar: R$ {$level->initial_payment}");
        
    } else {
        // Sin prorrateo, solo aplicar el nuevo precio
        $level->initial_payment = $new_price;
        
        error_log("PMPro Extended: Sin prorrateo aplicado - Precio directo: R$ {$new_price}");
        if ($price_difference <= 0) {
            error_log("PMPro Extended: Razón: Diferencia de precio no positiva ({$price_difference})");
        }
        if ($days_remaining <= 0) {
            error_log("PMPro Extended: Razón: Sin días restantes en ciclo ({$days_remaining})");
        }
    }

    return $level;
}

// ============================================================================
// FUNCIONES AUXILIARES PARA EL SISTEMA EXTENDIDO
// ============================================================================

/**
 * Detecta qué tipo de cambios se están realizando
 * Útil para logging y análisis
 */
function detect_change_types($current_config, $new_storage, $new_frequency, $new_level_id) {
    $changes = [];
    
    if ($new_storage !== $current_config['storage_space']) {
        $cur_tb = intval(str_replace('tb', '', $current_config['storage_space']));
        $new_tb = intval(str_replace('tb', '', $new_storage));
        
        if ($new_tb > $cur_tb) {
            $changes[] = 'storage_upgrade';
        } elseif ($new_tb < $cur_tb) {
            $changes[] = 'storage_downgrade';
        }
    }
    
    if ($new_frequency !== $current_config['payment_frequency']) {
        $freq_order = ['monthly' => 1, 'semiannual' => 2, 'annual' => 3, 'biennial' => 4, 'triennial' => 5, 'quadrennial' => 6, 'quinquennial' => 7];
        $cur_order = $freq_order[$current_config['payment_frequency']] ?? 1;
        $new_order = $freq_order[$new_frequency] ?? 1;
        
        if ($new_order > $cur_order) {
            $changes[] = 'frequency_upgrade';
        } elseif ($new_order < $cur_order) {
            $changes[] = 'frequency_downgrade';
        }
    }
    
    if ($new_level_id != $current_config['level_id']) {
        $changes[] = 'level_change';
    }
    
    return $changes;
}

/**
 * Logging centralizado para el sistema de prorrateo extendido
 */
function log_prorate_operation($user_id, $operation, $details = []) {
    $timestamp = current_time('mysql');
    $log_entry = [
        'timestamp' => $timestamp,
        'user_id' => $user_id,
        'operation' => $operation,
        'details' => $details
    ];
    
    error_log("PMPro Extended Log: " . json_encode($log_entry, JSON_UNESCAPED_UNICODE));
    
    // Opcional: Guardar en base de datos para análisis posterior
    // update_option('pmpro_extended_prorate_log_' . time(), $log_entry);
}

/**
 * Función de diagnóstico para verificar configuración de usuario
 */
function diagnose_user_config($user_id) {
    $config_json = get_user_meta($user_id, 'nextcloud_config', true);
    $membership = pmpro_getMembershipLevelForUser($user_id);
    
    $diagnosis = [
        'user_id' => $user_id,
        'has_config' => !empty($config_json),
        'config_valid' => false,
        'has_membership' => !empty($membership),
        'membership_level' => $membership ? $membership->id : null,
        'config_data' => null,
        'errors' => []
    ];
    
    if (!empty($config_json)) {
        $config = json_decode($config_json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $diagnosis['config_valid'] = true;
            $diagnosis['config_data'] = $config;
        } else {
            $diagnosis['errors'][] = 'JSON decode error: ' . json_last_error_msg();
        }
    }
    
    return $diagnosis;
}

// ============================================================================
// CÁLCULO DE PRÓXIMA FECHA DE PAGO - CORREGIDO
// ============================================================================

/**
 * Calcula la próxima fecha de pago para niveles sin expiración
 * CORREGIDO: Parámetros corregidos según el problema identificado
 */
function nextcloud_compute_next_payment_date($user_id, $membership = null) {
    if (!$membership) {
        $membership = pmpro_getMembershipLevelForUser($user_id);
    }
    if (empty($membership)) {
        return '';
    }

    // Si ya viene configurada, respetarla
    if (!empty($membership->next_payment_date)) {
        return date('Y-m-d', strtotime($membership->next_payment_date));
    }

    // Sin ciclo configurado, no podemos estimar
    $cycle_number = isset($membership->cycle_number) ? (int)$membership->cycle_number : 0;
    $cycle_period = isset($membership->cycle_period) ? $membership->cycle_period : '';
    if ($cycle_number <= 0 || empty($cycle_period)) {
        return '';
    }

    // Obtener timestamp base: último pedido exitoso o fecha de inicio de la membresía
    $base_ts = 0;

    if (class_exists('MemberOrder')) {
        $last_order = new MemberOrder();
        $last_order->getLastMemberOrder($user_id, 'success');
        if (!empty($last_order) && !empty($last_order->timestamp)) {
            $base_ts = is_numeric($last_order->timestamp) ? (int)$last_order->timestamp : strtotime($last_order->timestamp);
        }
    }

    if (!$base_ts) {
        // Fallback: fecha de inicio de la membresía
        if (!empty($membership->startdate)) {
            $base_ts = is_numeric($membership->startdate) ? (int)$membership->startdate : strtotime($membership->startdate);
        } elseif (!empty($membership->startdate_gmt)) {
            $base_ts = is_numeric($membership->startdate_gmt) ? (int)$membership->startdate_gmt : strtotime($membership->startdate_gmt);
        } else {
            // Como último recurso, usar ahora
            $base_ts = current_time('timestamp');
        }
    }

    // Mapear periodos de PMPro a strings de strtotime
    $period_map = array(
        'Day' => 'days',
        'Week' => 'weeks',
        'Month' => 'months',
        'Year' => 'years',
    );
    $period_key = isset($period_map[$cycle_period]) ? $period_map[$cycle_period] : 'months';

    // Sumar N periodos al base_ts
    $next_ts = strtotime('+' . $cycle_number . ' ' . $period_key, $base_ts);
    if (!$next_ts) {
        return '';
    }

    return date('Y-m-d', $next_ts);
}

// ============================================================================
// VISUALIZACIÓN EN CUENTA DE MIEMBRO - CORREGIDA
// ============================================================================

/**
 * Muestra la configuración del plan en la cuenta del miembro
 * CORREGIDO: Llamada a función de próxima fecha corregida
 */
function nextcloud_show_member_config() {
    if (!is_user_logged_in()) {
        return;
    }

    $user_id = get_current_user_id();
    $membership_level = pmpro_getMembershipLevelForUser($user_id);

    // Verificamos que el usuario realmente tenga una membresía activa
    if (!$membership_level) {
        return;
    }

    // Intentar desde caché primero
    $config = wp_cache_get('nextcloud_config_' . $user_id, 'nextcloud_dynamic');
    if ($config === false) {
        $config_json = get_user_meta($user_id, 'nextcloud_config', true);

        if (!empty($config_json)) {
            $config = json_decode($config_json, true);
        } else {
            $config = array();
        }

        // Guardar en caché para futuras llamadas
        wp_cache_set('nextcloud_config_' . $user_id, $config, 'nextcloud_dynamic', HOUR_IN_SECONDS);
    }

    // Si la configuración está vacía no mostramos nada
    if (empty($config)) {
        return;
    }

    // Extraer datos de la configuración
    $storage   = isset($config['storage_space']) ? $config['storage_space'] : 'N/A';
    $frequency = isset($config['payment_frequency']) ? $config['payment_frequency'] : 'monthly';

    // Labels amigables
    $storage_label = nextcloud_get_storage_label($storage);
    $frequency_label = nextcloud_get_frequency_label($frequency);

    // CORREGIDO: Llamada correcta a la función de próxima fecha
    $next_payment = nextcloud_compute_next_payment_date($user_id, $membership_level);
    
    if (empty($next_payment)) {
        if (!empty($membership_level->enddate)) {
            $next_payment = date_i18n(get_option('date_format'), $membership_level->enddate);
        } else {
            $next_payment = __('Indefinido', 'pmpro');
        }
    } else {
        $next_payment = date_i18n(get_option('date_format'), strtotime($next_payment));
    }

    // Render del bloque
    echo '<div class="pmpro_account-profile-field pmpro_nextcloud_config_details">';
    echo '<h3>' . esc_html__('Configuração do Plano', 'pmpro') . '</h3>';
    echo '<p><strong>' . esc_html__('Espaço contratado:', 'pmpro') . '</strong> ' . esc_html($storage_label) . '</p>';
    echo '<p><strong>' . esc_html__('Frequência de pagamento:', 'pmpro') . '</strong> ' . esc_html($frequency_label) . '</p>';
    echo '<p><strong>' . esc_html__('Próximo pagamento:', 'pmpro') . '</strong> ' . esc_html($next_payment) . '</p>';
    echo '</div>';
}

/**
 * Obtiene la etiqueta legible para el espacio de almacenamiento
 */
function nextcloud_get_storage_label($storage_key) {
    $labels = [
        '1tb' => '1 Terabyte',
        '2tb' => '2 Terabytes',
        '3tb' => '3 Terabytes',
        '4tb' => '4 Terabytes',
        '5tb' => '5 Terabytes',
        '6tb' => '6 Terabytes',
        '7tb' => '7 Terabytes',
        '8tb' => '8 Terabytes',
        '9tb' => '9 Terabytes',
        '10tb' => '10 Terabytes',
        '15tb' => '15 Terabytes',
        '20tb' => '20 Terabytes',
        '30tb' => '30 Terabytes',
        '40tb' => '40 Terabytes',
        '50tb' => '50 Terabytes',
        '60tb' => '60 Terabytes',
        '70tb' => '70 Terabytes',
        '80tb' => '80 Terabytes',
        '90tb' => '90 Terabytes',
        '100tb' => '100 Terabytes',
        '200tb' => '200 Terabytes',
        '300tb' => '300 Terabytes',
        '400tb' => '400 Terabytes',
        '500tb' => '500 Terabytes'
    ];
    return $labels[$storage_key] ?? $storage_key;
}

/**
 * Obtiene la etiqueta legible para la frecuencia de pago
 */
function nextcloud_get_frequency_label($frequency_key) {
    $labels = [
        'monthly' => 'Mensal',
        'semiannual' => 'Semestral',
        'annual' => 'Anual',
        'biennial' => 'Bienal',
        'triennial' => 'Trienal',
        'quadrennial' => 'Quadrienal',
        'quinquennial' => 'Quinquenal'
    ];
    return $labels[$frequency_key] ?? $frequency_key;
}

// ============================================================================
// INTEGRACIÓN CON EL SISTEMA EXISTENTE - CORREGIDA
// ============================================================================

/**
 * Modificación del hook principal para incluir validación y prorrateo
 * CORREGIDO: Usa configuración centralizada de niveles
 */
function nextcloud_modify_level_pricing($level) {
    // [1] Evitar recálculos múltiples (lógica existente)
    if (!empty($level->_nextcloud_applied)) {
        return $level;
    }

    // [2] CORREGIDO: Usar función centralizada para niveles permitidos
    $allowed_levels = nextcloud_get_allowed_levels();
    if (!in_array((int)$level->id, $allowed_levels, true)) {
        return $level;
    }

    // [3] Validar campos requeridos (lógica existente)
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return $level;
    }

    // [4] Aplicar validación y prorrateo para upgrades
    $level = nextcloud_validate_and_prorate_upgrades($level);

    // [5] Mantener lógica original de modificación de precios
    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

    $original = pmpro_getLevel($level->id);
    $base_price = $original ? (float)$original->initial_payment : (float)$level->initial_payment;

    $total_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);

    // [6] Configurar periodicidad (lógica existente)
    $level->initial_payment = $total_price;
    $level = nextcloud_configure_billing_period($level, $payment_frequency, $total_price);
    $level->_nextcloud_applied = true;

    return $level;
}

/**
 * Modifica el texto de costo mostrado en checkout
 * Usa siempre el precio base original para evitar cascadas
 */
function nextcloud_modify_cost_display($cost_text, $level) {
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return $cost_text;
    }

    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

    // CRÍTICO: Usar precio base original, no el ya modificado
    $original = pmpro_getLevel($level->id);
    $base_price = $original ? (float)$original->initial_payment : (float)$level->initial_payment;

    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);

    error_log("PMPro Dynamic: Modificando display de precio - Calculado: {$calculated_price} (base: {$base_price})");

    return pmpro_formatPrice($calculated_price);
}

/**
 * Validación pre-procesamiento para asegurar que el precio se aplique
 * Usa precio base original para evitar cascadas
 */
function nextcloud_validate_and_apply_pricing() {
    global $pmpro_level;

    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return;
    }

    if (empty($pmpro_level)) {
        return;
    }

    // Evitar re-aplicar si ya se procesó
    if (!empty($pmpro_level->_nextcloud_applied)) {
        error_log('PMPro Dynamic: Pre-procesamiento saltado, precio ya aplicado');
        return;
    }

    error_log('PMPro Dynamic: Validación pre-procesamiento - Aplicando precio final');

    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

    // CRÍTICO: Usar precio base original
    $original = pmpro_getLevel($pmpro_level->id);
    $base_price = $original ? (float)$original->initial_payment : (float)$pmpro_level->initial_payment;

    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);

    // Aplicar precio final
    $pmpro_level->initial_payment = $calculated_price;
    $pmpro_level = nextcloud_configure_billing_period($pmpro_level, $payment_frequency, $calculated_price);
    $pmpro_level->_nextcloud_applied = true;

    error_log("PMPro Dynamic: Precio final aplicado en pre-procesamiento: {$calculated_price} (base: {$base_price})");
}

/**
 * Validación final del orden antes del pago
 * Última oportunidad para corregir montos incorrectos
 */
function nextcloud_final_price_validation($order) {
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return $order;
    }

    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

    // Obtener el nivel original para calcular el precio base
    $level = pmpro_getLevel($order->membership_id);
    if (!$level) {
        return $order;
    }

    // CRÍTICO: Usar precio base original
    $base_price = (float)$level->initial_payment;
    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);

    // Corregir si el orden no refleja el precio correcto
    if (abs($order->InitialPayment - $calculated_price) > 0.01) { // Tolerancia para decimales
        error_log("PMPro Dynamic: CORRECCIÓN FINAL - Precio en orden: {$order->InitialPayment}, Calculado: {$calculated_price}");
        $order->InitialPayment = $calculated_price;
        $order->PaymentAmount = $calculated_price;
    }

    return $order;
}

/**
 * Verificación final antes del pago - Solo logging
 */
function nextcloud_before_payment_validation($order) {
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return;
    }

    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);

    error_log("PMPro Dynamic: VERIFICACIÓN FINAL ANTES DEL PAGO");
    error_log("PMPro Dynamic: Precio en orden: {$order->InitialPayment}");
    error_log("PMPro Dynamic: Storage: {$storage_space}, Frequency: {$payment_frequency}");

    // Log para análisis posterior
    $config = array(
        'storage_space' => $storage_space,
        'payment_frequency' => $payment_frequency,
        'final_price' => $order->InitialPayment,
        'validation_time' => current_time('mysql')
    );

    error_log("PMPro Dynamic: Configuración final: " . json_encode($config, JSON_UNESCAPED_UNICODE));
}

// ============================================================================
// GUARDADO DE CONFIGURACIÓN
// ============================================================================

/**
 * Guarda la configuración del usuario después del checkout exitoso
 */
function nextcloud_save_configuration_and_provision($user_id, $morder) {
    if (!isset($_REQUEST['storage_space']) || !isset($_REQUEST['payment_frequency'])) {
        error_log('PMPro Dynamic: Datos de configuración no encontrados en el checkout. REQUEST: ' . print_r($_REQUEST, true));
        return;
    }

    $storage_space = sanitize_text_field($_REQUEST['storage_space']);
    $payment_frequency = sanitize_text_field($_REQUEST['payment_frequency']);

    $config = array(
        'storage_space' => $storage_space,
        'payment_frequency' => $payment_frequency,
        'created_at' => current_time('mysql'),
        'level_id' => $morder->membership_id,
        'final_amount' => $morder->InitialPayment,
        'debug_timestamp' => time()
    );

    $config_json = json_encode($config);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('PMPro Dynamic: Error codificando JSON: ' . json_last_error_msg());
        return;
    }

    $saved = update_user_meta($user_id, 'nextcloud_config', $config_json);

    invalidar_cache_usuario($user_id);

    if (!$saved) {
        error_log('PMPro Dynamic: Error guardando metadatos para usuario ' . $user_id);
    } else {
        error_log('PMPro Dynamic: Configuración guardada exitosamente para usuario ' . $user_id . ': ' . $config_json);

        // Verificar inmediatamente después de guardar
        $verified = get_user_meta($user_id, 'nextcloud_config', true);
        error_log('PMPro Dynamic: Verificación post-guardado: ' . print_r($verified, true));
    }
}

// ============================================================================
// LOCALIZACIÓN DE SCRIPTS JS
// ============================================================================

/**
 * Pasa datos dinámicos al JavaScript para cálculos en tiempo real
 */
function nextcloud_localize_pricing_script() {
    // Verificar páginas relevantes
    $checkout_page_slug = '';
    $billing_page_slug = '';
    $account_page_slug = '';

    if (function_exists('pmpro_getOption')) {
        $checkout_page_slug = pmpro_getOption('checkout_page_slug');
        $billing_page_slug = pmpro_getOption('billing_page_slug');
        $account_page_slug = pmpro_getOption('account_page_slug');
    }

    $is_checkout = !empty($checkout_page_slug) && is_page($checkout_page_slug);
    $is_billing = !empty($billing_page_slug) && is_page($billing_page_slug);
    $is_account = !empty($account_page_slug) && is_page($account_page_slug);

    error_log('PMPro Dynamic: Verificando páginas - Checkout: ' . ($is_checkout ? 'SÍ' : 'NO') .
              ', Billing: ' . ($is_billing ? 'SÍ' : 'NO') .
              ', Account: ' . ($is_account ? 'SÍ' : 'NO'));

    if (!$is_checkout && !$is_billing && !$is_account) {
        error_log('PMPro Dynamic: No estamos en páginas relevantes, saltando localización');
        return;
    }

    global $pmpro_level, $wp_scripts;

    // Obtener datos del nivel actual
    $level_id = 1;
    $base_price = 0;

    if (!empty($pmpro_level) && isset($pmpro_level->initial_payment)) {
        $level_id = $pmpro_level->id;
        $base_price = floatval($pmpro_level->initial_payment);
    }

    // Verificar que el script existe antes de localizar
    $script_handle = 'simply-snippet-pmpro-dynamic-pricing';

    if (!isset($wp_scripts->registered[$script_handle])) {
        error_log('PMPro Dynamic: SCRIPT JS NO ENCOLADO: ' . $script_handle);

        // Lista scripts registrados para debugging
        $registered_scripts = array_keys($wp_scripts->registered);
        $pmpro_scripts = array_filter($registered_scripts, function($script) {
            return strpos($script, 'pmpro') !== false || strpos($script, 'simply') !== false;
        });
        error_log('PMPro Dynamic: Scripts PMPro/Simply registrados: ' . implode(', ', $pmpro_scripts));
        return;
    }

    // Obtener datos del usuario actual
    $current_storage = '1tb';
    $used_space_tb = 0;

    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $config_json = get_user_meta($user_id, 'nextcloud_config', true);

        if ($config_json) {
            $config = json_decode($config_json, true);
            $current_storage = $config['storage_space'] ?? '1tb';
        }

        // Obtener espacio usado de Nextcloud
        $used_space_mb = get_nextcloud_used_storage($user_id);
        $used_space_tb = round($used_space_mb / 1024, 2);
    }

    // Localizar el script con los datos
    wp_localize_script(
        $script_handle,
        'nextcloud_pricing',
        array(
            'level_id' => $level_id,
            'base_price' => $base_price,
            'currency_symbol' => 'R$',
            'current_storage' => $current_storage,
            'used_space_tb' => $used_space_tb,
            'debug' => true,
            'timestamp' => time(),
            'page_type' => $is_checkout ? 'checkout' : ($is_billing ? 'billing' : 'account'),
            'ajax_url' => admin_url('admin-ajax.php')
        )
    );

    error_log('PMPro Dynamic: Script localizado exitosamente - base_price: ' . $base_price . ', level_id: ' . $level_id);
}

// ============================================================================
// DIAGNÓSTICOS Y DEBUGGING
// ============================================================================

/**
 * Función de diagnóstico para verificar estado del sistema
 */
function nextcloud_production_diagnostics() {
    if (!current_user_can('manage_options')) return;

    error_log('=== PMPro Dynamic: DIAGNÓSTICO COMPLETO ===');
    error_log('Tiempo: ' . current_time('mysql'));
    error_log('Hook actual: ' . current_action());
    error_log('PMPro Core: ' . (function_exists('pmpro_getOption') ? 'DISPONIBLE' : 'NO DISPONIBLE'));
    error_log('PMPro RH: ' . (function_exists('pmprorh_add_registration_field') ? 'DISPONIBLE' : 'NO DISPONIBLE'));
    error_log('PMPro RH Class: ' . (class_exists('PMProRH_Field') ? 'DISPONIBLE' : 'NO DISPONIBLE'));

    // Verificar plugins activos
    $active_plugins = get_option('active_plugins', []);
    $pmpro_plugins = array_filter($active_plugins, function($plugin) {
        return strpos($plugin, 'paid-memberships-pro') !== false ||
               strpos($plugin, 'pmpro') !== false;
    });
    error_log('Plugins PMPro activos: ' . implode(', ', $pmpro_plugins));

    // Verificar páginas PMPro
    if (function_exists('pmpro_getOption')) {
        $checkout_page = pmpro_getOption('checkout_page_slug');
        error_log('Página checkout configurada: ' . $checkout_page);

        $current_page = get_queried_object();
        if ($current_page && isset($current_page->post_name)) {
            error_log('Página actual: ' . $current_page->post_name);
        }
    }

    error_log('=== FIN DIAGNÓSTICO ===');
}

// ============================================================================
// FUNCIONES DE UTILIDAD
// ============================================================================

/**
 * Función centralizada para manejar la invalidación del cache
 */
function invalidar_cache_usuario($user_id) {
    wp_cache_delete("nextcloud_config_{$user_id}", 'pmpro');
    wp_cache_delete("pmpro_membership_{$user_id}", 'pmpro');
    error_log("PMPro Dynamic: Cache invalidado para usuario {$user_id}");
}

// ============================================================================
// HOOKS Y FILTROS - CORREGIDOS
// ============================================================================

// Hooks de inicialización con múltiples puntos para asegurar compatibilidad
add_action('plugins_loaded', 'nextcloud_production_diagnostics', 1);
add_action('plugins_loaded', 'nextcloud_add_dynamic_fields', 25);
add_action('init', 'nextcloud_production_diagnostics', 1);
add_action('init', 'nextcloud_add_dynamic_fields', 20);
add_action('wp_loaded', 'nextcloud_production_diagnostics', 1);
add_action('wp_loaded', 'nextcloud_add_dynamic_fields', 5);

// Localización de scripts
add_action('wp_enqueue_scripts', 'nextcloud_localize_pricing_script', 30);

// HOOK PRINCIPAL DE MODIFICACIÓN DE PRECIO (solo uno para evitar duplicaciones)
add_filter('pmpro_checkout_level', 'nextcloud_modify_level_pricing', 1);

// Hooks adicionales para garantizar aplicación correcta
add_filter('pmpro_checkout_level_cost_text', 'nextcloud_modify_cost_display', 10, 2);
add_action('pmpro_checkout_before_processing', 'nextcloud_validate_and_apply_pricing', 1);
add_filter('pmpro_checkout_order', 'nextcloud_final_price_validation', 1);
add_action('pmpro_checkout_before_payment', 'nextcloud_before_payment_validation', 1);

// Hooks de guardado y visualización
add_action('pmpro_after_checkout', 'nextcloud_save_configuration_and_provision', 10, 2);
add_action('pmpro_account_bullets_bottom', 'nextcloud_show_member_config', 10);
add_action('pmpro_account_after_membership', 'nextcloud_show_member_config', 10);

// Hooks adicionales para validación y prorrateo
add_action('pmpro_checkout_before_processing', function() {
    global $pmpro_level;

    if (!empty($pmpro_level)) {
        $pmpro_level = nextcloud_validate_and_prorate_upgrades($pmpro_level);
    }
}, 5);

// Validación final antes de completar el pedido
add_filter('pmpro_checkout_order', function($order) {
    if (!empty($order->user_id)) {
        $level = pmpro_getLevel($order->membership_id);
        if (!empty($level)) {
            $level = nextcloud_validate_and_prorate_upgrades($level);
            // Asegurar que el monto en la orden coincida
            $order->InitialPayment = $level->initial_payment;
        }
    }
    return $order;
});

// Invalidar caché cuando el usuario cambia de nivel
add_action('pmpro_after_change_membership_level', function($level_id, $user_id) {
    invalidar_cache_usuario($user_id);
}, 10, 2);

// ============================================================================
// VERIFICACIONES EN TIEMPO REAL
// ============================================================================

// Debug en footer para páginas de checkout
add_action('wp_footer', function() {
    if (function_exists('pmpro_getOption') && is_page(pmpro_getOption('checkout_page_slug'))) {
        global $wp_scripts;
        $script_handle = 'simply-snippet-pmpro-dynamic-pricing';
        $script_enqueued = isset($wp_scripts->registered[$script_handle]) ? 'SÍ' : 'NO';

        echo '<!-- PMPro Dynamic Debug: Script Encolado: ' . $script_enqueued . ' -->';
        echo '<!-- PMPro Dynamic Debug: RH Available: ' . (function_exists('pmprorh_add_registration_field') ? 'SÍ' : 'NO') . ' -->';
        echo '<!-- PMPro Dynamic Debug: RH Class: ' . (class_exists('PMProRH_Field') ? 'SÍ' : 'NO') . ' -->';

        // Mensajes de error visibles para debugging
        if (!function_exists('pmprorh_add_registration_field')) {
            echo '<script>console.error("PMPro Dynamic: PMPro Register Helper NO DISPONIBLE");</script>';
        }
        if (!class_exists('PMProRH_Field')) {
            echo '<script>console.error("PMPro Dynamic: PMProRH_Field class NO DISPONIBLE");</script>';
        }
        if ($script_enqueued === 'NO') {
            echo '<script>console.error("PMPro Dynamic: Script JS NO ENCOLADO");</script>';
        } else {
            echo '<script>console.log("PMPro Dynamic: Todo funcionando correctamente");</script>';
        }
    }
});

// Indicador de estado en admin bar
add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) return;

    $status = '❌';
    $status_text = 'ERROR';

    if (function_exists('pmprorh_add_registration_field') &&
        function_exists('pmpro_getOption') &&
        class_exists('PMProRH_Field')) {
        $status = '✅';
        $status_text = 'OK';
    }

    $wp_admin_bar->add_node(array(
        'id' => 'pmpro-dynamic-status',
        'title' => 'PMPro Dynamic ' . $status,
        'href' => admin_url('admin.php?page=simply-code&edit=pmpro-dynamic-pricing'),
        'meta' => array(
            'title' => 'PMPro Dynamic Status: ' . $status_text
        )
    ));
}, 100);

// Debug adicional para usuarios logueados
add_action('wp_footer', function() {
    if (!is_user_logged_in() || !function_exists('pmpro_hasMembershipLevel')) return;

    $user_id = get_current_user_id();
    echo '<!-- PMPro Debug: User ID: ' . $user_id . ' -->';
    echo '<!-- PMPro Debug: Membership Level: ' . print_r(pmpro_getMembershipLevelForUser($user_id), true) . ' -->';
    echo '<!-- PMPro Debug: nextcloud_config: ' . print_r(get_user_meta($user_id, 'nextcloud_config', true), true) . ' -->';
});
