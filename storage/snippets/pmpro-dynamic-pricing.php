<?php
// Dynamic pricing for PMPro - PRODUCTION OPTIMIZED FINAL VERSION WITH GUARANTEED PRICING
if (!defined('ABSPATH')) {
    exit;
}

// MEJORADO: Verificar dependencias con admin notices
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
    
    // NUEVO: Admin notice para usuarios administradores
    if (!empty($missing_plugins) && current_user_can('manage_options')) {
        add_action('admin_notices', function() use ($missing_plugins) {
            $plugins_list = implode(', ', $missing_plugins);
            echo '<div class="notice notice-error"><p><strong>PMPro Dynamic Pricing:</strong> Los siguientes plugins son requeridos: ' . esc_html($plugins_list) . '</p></div>';
        });
    }
    
    return empty($missing_plugins);
}

// CRÍTICO: Función mejorada con prevención de ejecución múltiple
function nextcloud_add_dynamic_fields() {
    // ⚠️ CRÍTICO: Evitar ejecución múltiple
    static $fields_added = false;
    if ($fields_added) {
        error_log('PMPro Dynamic: Campos ya agregados, evitando duplicación');
        return true;
    }
    
    // NUEVO: Log detallado para debugging en producción
    error_log('PMPro Dynamic: Intentando agregar campos dinámicos...');
    error_log('PMPro Dynamic: Hook actual: ' . current_action());
    error_log('PMPro Dynamic: Tiempo: ' . current_time('mysql'));
    
    if (!nextcloud_check_dependencies()) {
        error_log('PMPro Dynamic: Dependencias faltantes, reintentando más tarde...');
        return false;
    }
    
    // NUEVO: Verificar contexto de página
    if (is_admin() && !wp_doing_ajax() && !wp_doing_cron()) {
        error_log('PMPro Dynamic: Contexto admin detectado, registrando campos');
    }
    
    // ⭐ CORREGIDO: Usar función de detección múltiple
    $current_level_id = nextcloud_get_current_level_id();
    
    error_log('PMPro Dynamic: Level ID detectado: ' . $current_level_id . ' (tipo: ' . gettype($current_level_id) . ')');
    
    // ⭐ MEJORADO: Verificación más robusta con conversión de tipos
    $allowed_levels = array(10, 11, 12, 13, 14);
    $current_level_id = (int)$current_level_id; // Forzar a entero
    
    if (!in_array($current_level_id, $allowed_levels, true)) { // strict comparison
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
                '100tb' => '100 Terabytes'
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
        error_log('PMPro Dynamic: Error agregando campos: ' . $e->getMessage());
        return false;
    }
}

// ⭐ NUEVA FUNCIÓN: Detectar Level ID de múltiples fuentes
function nextcloud_get_current_level_id() {
    $level_id = 0;
    
    // Fuente 1: Global $pmpro_checkout_level
    global $pmpro_checkout_level;
    if (isset($pmpro_checkout_level->id) && !empty($pmpro_checkout_level->id)) {
        $level_id = $pmpro_checkout_level->id;
        error_log("PMPro Dynamic: Level ID obtenido de \$pmpro_checkout_level: {$level_id}");
        return (int)$level_id;
    }
    
    // Fuente 2: URL parameter 'level'
    if (isset($_GET['level']) && !empty($_GET['level'])) {
        $level_id = sanitize_text_field($_GET['level']);
        error_log("PMPro Dynamic: Level ID obtenido de \$_GET['level']: {$level_id}");
        return (int)$level_id;
    }
    
    // Fuente 3: POST parameter 'level'
    if (isset($_POST['level']) && !empty($_POST['level'])) {
        $level_id = sanitize_text_field($_POST['level']);
        error_log("PMPro Dynamic: Level ID obtenido de \$_POST['level']: {$level_id}");
        return (int)$level_id;
    }
    
    // Fuente 4: Global $pmpro_level
    global $pmpro_level;
    if (isset($pmpro_level->id) && !empty($pmpro_level->id)) {
        $level_id = $pmpro_level->id;
        error_log("PMPro Dynamic: Level ID obtenido de \$pmpro_level: {$level_id}");
        return (int)$level_id;
    }
    
    // Fuente 5: Session/Cookie (si PMPro lo usa)
    if (isset($_SESSION['pmpro_level']) && !empty($_SESSION['pmpro_level'])) {
        $level_id = $_SESSION['pmpro_level'];
        error_log("PMPro Dynamic: Level ID obtenido de session: {$level_id}");
        return (int)$level_id;
    }
    
    // Fuente 6: Obtener de la URL actual si estamos en checkout
    if (function_exists('pmpro_getOption')) {
        $checkout_page_slug = pmpro_getOption('checkout_page_slug');
        if (!empty($checkout_page_slug) && is_page($checkout_page_slug)) {
            // Examinar la URL completa
            $full_url = "http" . (isset($_SERVER['HTTPS']) ? "s" : "") . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            if (preg_match('/level=(\d+)/', $full_url, $matches)) {
                $level_id = $matches[1];
                error_log("PMPro Dynamic: Level ID extraído de URL completa: {$level_id}");
                return (int)$level_id;
            }
        }
    }
    
    error_log("PMPro Dynamic: NO se pudo detectar Level ID desde ninguna fuente. Usando 0 por defecto.");
    error_log("PMPro Dynamic: \$_GET: " . print_r($_GET, true));
    error_log("PMPro Dynamic: \$_POST keys: " . implode(', ', array_keys($_POST)));
    
    return 0;
}

// MEJORADO: Función para pasar datos dinámicos al JS con debugging robusto
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
    
    // CRÍTICO: Verificar que el script existe antes de localizar
    $script_handle = 'simply-snippet-pmpro-dynamic-pricing';
    
    if (!isset($wp_scripts->registered[$script_handle])) {
        error_log('PMPro Dynamic: SCRIPT JS NO ENCOLADO: ' . $script_handle);
        
        // Lista todos los scripts registrados para debugging
        $registered_scripts = array_keys($wp_scripts->registered);
        $pmpro_scripts = array_filter($registered_scripts, function($script) {
            return strpos($script, 'pmpro') !== false || strpos($script, 'simply') !== false;
        });
        error_log('PMPro Dynamic: Scripts PMPro/Simply registrados: ' . implode(', ', $pmpro_scripts));
        return;
    }
    
    // Localizar el script
    wp_localize_script(
        $script_handle,
        'nextcloud_pricing',
        array(
            'level_id' => $level_id,
            'base_price' => $base_price,
            'currency_symbol' => 'R$',
            'debug' => true, // SIEMPRE activar debug en este caso
            'timestamp' => time(), // Para verificar que se actualiza
            'page_type' => $is_checkout ? 'checkout' : ($is_billing ? 'billing' : 'account'),
            'ajax_url' => admin_url('admin-ajax.php')
        )
    );
    
    error_log('PMPro Dynamic: Script localizado exitosamente - base_price: ' . $base_price . ', level_id: ' . $level_id);
}

// NUEVO: Función auxiliar para calcular precio (DRY principle)
function nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price) {
    $add_tb = 120;
    
    $storage_prices = array(
        '1tb' => $base_price,
        '2tb' => $base_price + $add_tb,
        '3tb' => $base_price + ($add_tb * 2),
        '4tb' => $base_price + ($add_tb * 3),
        '5tb' => $base_price + ($add_tb * 4),
        '6tb' => $base_price + ($add_tb * 5),
        '7tb' => $base_price + ($add_tb * 6),
        '8tb' => $base_price + ($add_tb * 7),
        '9tb' => $base_price + ($add_tb * 8),
        '10tb' => $base_price + ($add_tb * 9),
        '15tb' => $base_price + ($add_tb * 14),
        '20tb' => $base_price + ($add_tb * 19),
        '30tb' => $base_price + ($add_tb * 29),
        '40tb' => $base_price + ($add_tb * 39),
        '50tb' => $base_price + ($add_tb * 49),
        '60tb' => $base_price + ($add_tb * 59),
        '70tb' => $base_price + ($add_tb * 69),
        '80tb' => $base_price + ($add_tb * 79),
        '90tb' => $base_price + ($add_tb * 89),
        '100tb' => $base_price + ($add_tb * 99)
    );
    
    $frequency_months = array(
        'monthly' => 1,
        'semiannual' => 5.7,
        'annual' => 10.8,
        'biennial' => 20.4,
        'triennial' => 28.8,
        'quadrennial' => 36,
        'quinquennial' => 42
    );
    
    $storage_price = $storage_prices[$storage_space] ?? $base_price;
    $months = $frequency_months[$payment_frequency] ?? 1;
    
    return ceil($storage_price * $months);
}

// NUEVO: Función auxiliar para configurar periodicidad
function nextcloud_configure_billing_period($level, $payment_frequency, $total_price) {
    // Configurar la periodicidad del pago
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
    
    $level->billing_amount = $total_price;
    $level->trial_amount = 0;
    $level->trial_limit = 0;
    $level->recurring = true;
    $level->expiration_number = 0;
    $level->expiration_period = '';
    
    return $level;
}

// Modificar o preço do nível baseado nas opções selecionadas - VERSIÓN PRINCIPAL
function nextcloud_modify_level_pricing($level) {
    // ⭐ NUEVO: Verificar que el nivel está permitido
    $allowed_levels = array(10, 11, 12, 13, 14);
    $level_id = (int)$level->id;
    
    if (!in_array($level_id, $allowed_levels, true)) {
        error_log("PMPro Dynamic: Modificación de precio saltada para nivel no permitido: {$level_id}");
        return $level; // Devolver sin modificar
    }
    
    // Verificar se estamos processando um checkout com os campos customizados
    $storage_space = pmpro_getParam('storage_space', 'POST');
    $payment_frequency = pmpro_getParam('payment_frequency', 'POST');
    
    if (empty($storage_space) || empty($payment_frequency)) {
        return $level;
    }
    
    error_log("PMPro Dynamic: Modificando precio - Storage: {$storage_space}, Frequency: {$payment_frequency}");
    
    $base_price = $level->initial_payment;
    $total_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);
    
    error_log("PMPro Dynamic: Precio calculado: {$total_price} (base: {$base_price})");
    
    // Modificar el nivel
    $level->initial_payment = $total_price;
    $level = nextcloud_configure_billing_period($level, $payment_frequency, $total_price);
    
    return $level;
}

// ⭐ NUEVO: Hook adicional para asegurar modificación del texto del precio
function nextcloud_modify_cost_display($cost_text, $level) {
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return $cost_text;
    }
    
    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);
    
    $base_price = $level->initial_payment;
    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);
    
    error_log("PMPro Dynamic: Modificando display de precio - Calculado: {$calculated_price}");
    
    return pmpro_formatPrice($calculated_price);
}

// ⭐ NUEVO: Validación pre-procesamiento para asegurar que el precio se aplique
function nextcloud_validate_and_apply_pricing() {
    global $pmpro_level;
    
    if (!isset($_POST['storage_space']) || !isset($_POST['payment_frequency'])) {
        return;
    }
    
    if (empty($pmpro_level)) {
        return;
    }
    
    error_log('PMPro Dynamic: Validación pre-procesamiento - Aplicando precio final');
    
    $storage_space = sanitize_text_field($_POST['storage_space']);
    $payment_frequency = sanitize_text_field($_POST['payment_frequency']);
    
    $base_price = $pmpro_level->initial_payment;
    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);
    
    // CRÍTICO: Asegurar que el precio se aplique
    $pmpro_level->initial_payment = $calculated_price;
    $pmpro_level = nextcloud_configure_billing_period($pmpro_level, $payment_frequency, $calculated_price);
    
    error_log("PMPro Dynamic: Precio final aplicado en pre-procesamiento: {$calculated_price}");
}

// ⭐ NUEVO: Hook tardío para interceptar cualquier sobrescritura
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
    
    $base_price = $level->initial_payment;
    $calculated_price = nextcloud_calculate_pricing($storage_space, $payment_frequency, $base_price);
    
    // CRÍTICO: Asegurar que el orden refleje el precio correcto
    if ($order->InitialPayment != $calculated_price) {
        error_log("PMPro Dynamic: CORRECCIÓN FINAL - Precio en orden: {$order->InitialPayment}, Calculado: {$calculated_price}");
        $order->InitialPayment = $calculated_price;
        $order->PaymentAmount = $calculated_price;
    }
    
    return $order;
}

// ⭐ NUEVO: Verificación final antes del pago
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

// Salvar configuração na confirmação da compra
function nextcloud_save_configuration_and_provision($user_id, $morder) {
    if (!isset($_REQUEST['storage_space']) || !isset($_REQUEST['payment_frequency'])) {
        error_log('PMPro Dynamic: Datos de configuración no encontrados en el checkout');
        return;
    }
    
    $storage_space = sanitize_text_field($_REQUEST['storage_space']);
    $payment_frequency = sanitize_text_field($_REQUEST['payment_frequency']);
    
    update_user_meta($user_id, 'nextcloud_storage_space', $storage_space);
    update_user_meta($user_id, 'nextcloud_payment_frequency', $payment_frequency);
    
    $config = array(
        'storage_space' => $storage_space,
        'payment_frequency' => $payment_frequency,
        'created_at' => current_time('mysql'),
        'level_id' => $morder->membership_id,
        'final_amount' => $morder->InitialPayment // ⭐ NUEVO: Guardar precio final
    );
    update_user_meta($user_id, 'nextcloud_config', json_encode($config));
    
    error_log("PMPro Nextcloud: Configuração salva para usuário {$user_id}: " . json_encode($config, JSON_UNESCAPED_UNICODE));
}

// Mostrar configuração na área de membros
function nextcloud_show_member_config() {
    $user_id = get_current_user_id();
    if (!$user_id) return;
    
    $membership = pmpro_getMembershipLevelForUser($user_id);
    $config_json = get_user_meta($user_id, 'nextcloud_config', true);
    
    if ($config_json) {
        $config = json_decode($config_json, true);
        
        $storage_labels = array(
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
            '100tb' => '100 Terabytes'
        );
        
        $frequency_labels = array(
            'monthly' => 'Mensal',
            'semiannual' => 'Semestral',
            'annual' => 'Anual',
            'biennial' => 'Bienal',
            'triennial' => 'Trienal',
            'quadrennial' => 'Quadrienal',
            'quinquennial' => 'Quinquenal'
        );
        ?>
        <div class="pmpro_account-profile-field">
            <h3>Detalhes do plano <strong><?php echo $membership->name; ?></strong></h3>
            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin: 10px 0;">
                <p><strong>Armazenamento:</strong> <?php echo esc_html($storage_labels[$config['storage_space']] ?? $config['storage_space']); ?></p>
                <p><strong>Plano de Pagamento:</strong> <?php echo esc_html($frequency_labels[$config['payment_frequency']] ?? $config['payment_frequency']); ?></p>
                <?php if (isset($config['final_amount'])): ?>
                    <p><strong>Valor Pago:</strong> R$ <?php echo number_format($config['final_amount'], 2, ',', '.'); ?></p>
                <?php endif; ?>
                <p><small><strong>Configurado em:</strong> <?php echo date('d/m/Y H:i', strtotime($config['created_at'])); ?></small></p>
                <?php if (isset($membership->next_payment_date)): ?>
                    <p><small><strong>Próximo pagamento:</strong> <?php echo date('d/m/Y', strtotime($membership->next_payment_date)); ?></small></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// FUNCIÓN DE DIAGNÓSTICO TEMPORAL - REMOVER DESPUÉS DEL DEBUGGING
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

// ⚠️ CRÍTICO: HOOKS CON TIMING OPTIMIZADO PARA PRODUCCIÓN
// Usar múltiples hooks para asegurar que funcione en diferentes configuraciones
add_action('plugins_loaded', 'nextcloud_production_diagnostics', 1);
add_action('plugins_loaded', 'nextcloud_add_dynamic_fields', 25); // MÁS TARDÍO
add_action('init', 'nextcloud_production_diagnostics', 1);
add_action('init', 'nextcloud_add_dynamic_fields', 20); // HOOK DE RESPALDO
add_action('wp_loaded', 'nextcloud_production_diagnostics', 1);
add_action('wp_loaded', 'nextcloud_add_dynamic_fields', 5); // HOOK FINAL

add_action('wp_enqueue_scripts', 'nextcloud_localize_pricing_script', 30);

// ⭐ HOOKS PRINCIPALES DE MODIFICACIÓN DE PRECIO
add_filter('pmpro_checkout_level', 'nextcloud_modify_level_pricing', 1); // MUY TEMPRANO
add_filter('pmpro_after_checkout_level', 'nextcloud_modify_level_pricing', 1); // RESPALDO

// ⭐ NUEVOS HOOKS ADICIONALES PARA GARANTIZAR MODIFICACIÓN
add_filter('pmpro_checkout_level_cost_text', 'nextcloud_modify_cost_display', 10, 2); // MODIFICAR DISPLAY
add_action('pmpro_checkout_before_processing', 'nextcloud_validate_and_apply_pricing', 1); // PRE-PROCESAMIENTO
add_filter('pmpro_checkout_order', 'nextcloud_final_price_validation', 1); // VALIDACIÓN FINAL
add_action('pmpro_checkout_before_payment', 'nextcloud_before_payment_validation', 1); // ANTES DEL PAGO

// HOOKS DE GUARDADO Y DISPLAY
add_action('pmpro_after_checkout', 'nextcloud_save_configuration_and_provision', 10, 2);
add_action('pmpro_account_bullets_bottom', 'nextcloud_show_member_config', 10);

// HOOKS DE VERIFICACIÓN EN TIEMPO REAL
add_action('wp_footer', function() {
    if (function_exists('pmpro_getOption') && is_page(pmpro_getOption('checkout_page_slug'))) {
        global $wp_scripts;
        $script_handle = 'simply-snippet-pmpro-dynamic-pricing';
        $script_enqueued = isset($wp_scripts->registered[$script_handle]) ? 'SÍ' : 'NO';
        
        echo '<!-- PMPro Dynamic Debug: Script Encolado: ' . $script_enqueued . ' -->';
        echo '<!-- PMPro Dynamic Debug: RH Available: ' . (function_exists('pmprorh_add_registration_field') ? 'SÍ' : 'NO') . ' -->';
        echo '<!-- PMPro Dynamic Debug: RH Class: ' . (class_exists('PMProRH_Field') ? 'SÍ' : 'NO') . ' -->';
        
        // CRÍTICO: Mostrar mensaje de error visible para debugging
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

// VERIFICACIÓN DE ESTADO EN ADMIN BAR
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
