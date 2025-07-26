<?php
// @description Dynamic pricing for PMPro

if (!defined('ABSPATH')) exit;

// Agregar campos dinámicos de Nextcloud usando PMPro Register Helper
function nextcloud_add_dynamic_fields(){
    if(!function_exists('pmprorh_add_registration_field')) {
        return false;
    }
    
    // Obtener el nivel de membresía actualmente seleccionado en el checkout
    global $pmpro_checkout_level;
    $current_level_id = isset($pmpro_checkout_level->id) ? (int)$pmpro_checkout_level->id : 0;
    
    // Solo agregar campos si el nivel es alguno de la lista
    if (!in_array($current_level_id, array(10, 11, 12, 13, 14))) {
        return;
    }
    
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
    
    foreach($fields as $field){
        pmprorh_add_registration_field('Configuração do plano', $field);
    }
}

// Función para pasar datos dinámicos al JS
function nextcloud_localize_pricing_script() {
    // Solo en páginas relevantes
    if (!is_page(pmpro_getOption('checkout_page_slug')) && 
        !is_page(pmpro_getOption('billing_page_slug')) && 
        !is_page(pmpro_getOption('account_page_slug'))) {
        return;
    }
    
    global $pmpro_level;
    
    // Obtener datos del nivel actual
    $level_id = 1;
    $base_price = 0;
    
    if (!empty($pmpro_level) && isset($pmpro_level->initial_payment)) {
        $level_id = $pmpro_level->id;
        $base_price = floatval($pmpro_level->initial_payment);
    }
    
    // Localizar el script (pasar datos de PHP a JS)
    wp_localize_script(
        'simply-snippet-pmpro-dynamic-pricing', // Handle que usa Simply Code
        'nextcloud_pricing',
        array(
            'level_id' => $level_id,
            'base_price' => $base_price,
            'currency_symbol' => 'R$'
        )
    );
}

// Modificar o preço do nível baseado nas opções selecionadas
function nextcloud_modify_level_pricing($level) {
    // Verificar se estamos processando um checkout com os campos customizados
    $storage_space = pmpro_getParam('storage_space', 'POST');
    $payment_frequency = pmpro_getParam('payment_frequency', 'POST');
    
    if (empty($storage_space) || empty($payment_frequency)) {
        return $level;
    }
    
    // Multiplicadores de almacenamiento (igual que en JavaScript)
    $level_id = $level->id;
    $base_price = $level->initial_payment;
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
    
    // Meses según frecuencia (igual que en JavaScript)
    $frequency_months = array(
        'monthly' => 1,
        'semiannual' => 5.7,
        'annual' => 10.8,
        'biennial' => 20.4,
        'triennial' => 28.8,
        'quadrennial' => 36,
        'quinquennial' => 42
    );
    
    // Obtener multiplicadores
    $storage_price = $storage_prices[$storage_space] ?? 0;
    $months = $frequency_months[$payment_frequency] ?? 1;
    
    // Calcular precio total (misma fórmula que en JavaScript)
    $total_price = ceil($storage_price * $months);
    
    // Modificar el nivel
    $level->initial_payment = $total_price;
    
    // Configurar la periodicidad del pago
    if ($payment_frequency === 'monthly') {
        $level->cycle_number = 1;
        $level->cycle_period = 'Month';
        $level->billing_amount = $level->initial_payment;
    } 
    elseif ($payment_frequency === 'semiannual') {
        $level->cycle_number = 6;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    } 
    elseif ($payment_frequency === 'annual') {
        $level->cycle_number = 12;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    }
    elseif ($payment_frequency === 'biennial') {
        $level->cycle_number = 24;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    }
    elseif ($payment_frequency === 'triennial') {
        $level->cycle_number = 36;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    }
    elseif ($payment_frequency === 'quadrennial') {
        $level->cycle_number = 48;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    }
    elseif ($payment_frequency === 'quinquennial') {
        $level->cycle_number = 60;
        $level->cycle_period = 'Month';
        $level->billing_amount = $total_price;
    }
    
    // Configuraciones adicionales
    $level->trial_amount = 0;
    $level->trial_limit = 0;
    $level->recurring = true;
    $level->expiration_number = 0;
    $level->expiration_period = '';
    
    return $level;
}

// Salvar configuração na confirmação da compra
function nextcloud_save_configuration_and_provision($user_id, $morder) {
    if (!isset($_REQUEST['storage_space']) || !isset($_REQUEST['payment_frequency'])) {
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
        'level_id' => $morder->membership_id
    );
    update_user_meta($user_id, 'nextcloud_config', json_encode($config));
    
    error_log("PMPro Nextcloud: Configuração salva para usuário {$user_id}");
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
                <p><small><strong>Configurado em:</strong> <?php echo date('d/m/Y H:i', strtotime($config['created_at'])); ?></small></p>
                <p><small><strong>Próximo pagamento:</strong> <?php echo date('d/m/Y', strtotime($membership->next_payment_date)); ?></small></p>
            </div>
        </div>
        <?php
    }
}

// Registrar todos los hooks
add_action('init', 'nextcloud_add_dynamic_fields');
add_action('wp_enqueue_scripts', 'nextcloud_localize_pricing_script', 20); // Prioridad 20 para que se ejecute después del encolado
add_filter('pmpro_checkout_level', 'nextcloud_modify_level_pricing');
add_filter('pmpro_after_checkout_level', 'nextcloud_modify_level_pricing');
add_action('pmpro_after_checkout', 'nextcloud_save_configuration_and_provision', 10, 2);
add_action('pmpro_account_bullets_bottom', 'nextcloud_show_member_config');
