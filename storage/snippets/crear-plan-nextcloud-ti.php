<?php
// @description Nuevo snippet

if (!defined('ABSPATH')) exit;

// Importar funciones de logging si están disponibles
if (!function_exists('nextcloud_log_info') && function_exists('error_log')) {
    function nextcloud_log_info($message, $context = []) {
        $log_message = '[Nextcloud TI] ' . $message;
        if (!empty($context)) {
            $log_message .= ' | Context: ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        error_log($log_message);
    }
}

if (!function_exists('nextcloud_log_error') && function_exists('error_log')) {
    function nextcloud_log_error($message, $context = []) {
        nextcloud_log_info('ERROR: ' . $message, $context);
    }
}

/**
 * Responder a solicitud de plan Nextcloud TI - Versión mejorada
 * 
 * @param int $user_id ID del usuario
 * @param MemberOrder $morder Objeto de orden de PMPro
 */
function plan_nextcloud_ti($user_id, $morder) {
    // Validaciones iniciales
    if (empty($user_id) || empty($morder)) {
        nextcloud_log_error('Invalid parameters provided', [
            'user_id' => $user_id,
            'morder_exists' => !empty($morder)
        ]);
        return false;
    }

    try {
        // Generar password para la nueva cuenta Nextcloud
        $password = wp_generate_password(12, false);
        
        // Obtener información del usuario con validaciones
        $user = get_userdata($user_id);
        if (!$user) {
            nextcloud_log_error('User not found', ['user_id' => $user_id]);
            return false;
        }

        $email = $user->user_email;
        $username = $user->user_login;
        $displayname = $user->display_name ?: $username;

        // Obtener nivel de membresía actual
        $level = pmpro_getMembershipLevelForUser($user_id);
        if (!$level) {
            nextcloud_log_error('No membership level found for user', ['user_id' => $user_id]);
            return false;
        }

        // Configurar timezone y fecha del pedido
        $dt = new DateTime();
        $dt->setTimezone(new DateTimeZone('America/Boa_Vista'));
        
        // Usar timestamp del morder o timestamp actual
        $order_timestamp = !empty($morder->timestamp) ? $morder->timestamp : current_time('timestamp');
        $dt->setTimestamp($order_timestamp);
        $fecha_pedido = $dt->format('d/m/Y H:i:s');

        // Obtener configuración dinámica del usuario (del sistema de pricing dinámico)
        $config_data = get_nextcloud_user_config($user_id);
        
        // Obtener fecha del próximo pago usando PMPro
        $fecha_pago_proximo = get_pmpro_next_payment_date($user_id, $level);

        // Preparar datos del email
        $email_data = prepare_nextcloud_email_data($user, $level, $morder, $config_data, [
            'password' => $password,
            'fecha_pedido' => $fecha_pedido,
            'fecha_pago_proximo' => $fecha_pago_proximo
        ]);

        // Enviar email al usuario
        $user_email_sent = send_nextcloud_user_email($email_data);
        
        // Enviar email al administrador
        $admin_email_sent = send_nextcloud_admin_email($email_data);

        // Log del resultado
        nextcloud_log_info('Nextcloud TI plan processing completed', [
            'user_id' => $user_id,
            'username' => $username,
            'level_name' => $level->name,
            'user_email_sent' => $user_email_sent,
            'admin_email_sent' => $admin_email_sent,
            'config_data' => $config_data
        ]);

        return true;

    } catch (Exception $e) {
        nextcloud_log_error('Exception in plan_nextcloud_ti', [
            'user_id' => $user_id,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return false;
    }
}

/**
 * Obtiene la configuración dinámica del usuario
 */
function get_nextcloud_user_config($user_id) {
    $config_json = get_user_meta($user_id, 'nextcloud_config', true);
    
    if (empty($config_json)) {
        nextcloud_log_info('No dynamic config found for user, using defaults', ['user_id' => $user_id]);
        return [
            'storage_space' => '1tb',
            'office_suite' => '20users',
            'payment_frequency' => 'monthly',
            'storage_display' => '1 Terabyte',
            'office_display' => '±20 usuários (CODE - Grátis)',
            'frequency_display' => 'Mensal'
        ];
    }

    $config = json_decode($config_json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        nextcloud_log_error('Invalid JSON in user config', [
            'user_id' => $user_id,
            'json_error' => json_last_error_msg()
        ]);
        return null;
    }

    // Enriquecer con información de display
    $config['storage_display'] = get_storage_display_name($config['storage_space'] ?? '1tb');
    $config['office_display'] = get_office_display_name($config['office_suite'] ?? '20users');
    $config['frequency_display'] = get_frequency_display_name($config['payment_frequency'] ?? 'monthly');

    return $config;
}

/**
 * Obtiene la fecha del próximo pago usando PMPro nativo
 */
function get_pmpro_next_payment_date($user_id, $level) {
    // Usar función nativa de PMPro si está disponible
    if (function_exists('pmpro_next_payment')) {
        $next_payment = pmpro_next_payment($user_id);
        if (!empty($next_payment)) {
            return date('d/m/Y', $next_payment);
        }
    }

    // Fallback: calcular basado en el nivel y la última orden
    if (class_exists('MemberOrder')) {
        $last_order = new MemberOrder();
        $last_order->getLastMemberOrder($user_id, 'success');
        
        if (!empty($last_order->timestamp)) {
            $last_payment_timestamp = is_numeric($last_order->timestamp) 
                ? $last_order->timestamp 
                : strtotime($last_order->timestamp);
                
            // Calcular próximo pago basado en el ciclo
            $cycle_seconds = get_cycle_seconds_from_level($level);
            $next_payment_timestamp = $last_payment_timestamp + $cycle_seconds;
            
            return date('d/m/Y', $next_payment_timestamp);
        }
    }

    // Último fallback: basado en la fecha actual y ciclo del nivel
    $cycle_seconds = get_cycle_seconds_from_level($level);
    $next_payment_timestamp = current_time('timestamp') + $cycle_seconds;
    
    return date('d/m/Y', $next_payment_timestamp);
}

/**
 * Calcula segundos del ciclo basado en el nivel
 */
function get_cycle_seconds_from_level($level) {
    if (empty($level->cycle_number) || empty($level->cycle_period)) {
        return 30 * DAY_IN_SECONDS; // Default: 30 días
    }

    $multipliers = [
        'Day' => DAY_IN_SECONDS,
        'Week' => WEEK_IN_SECONDS,
        'Month' => 30 * DAY_IN_SECONDS,
        'Year' => YEAR_IN_SECONDS
    ];

    $multiplier = $multipliers[$level->cycle_period] ?? (30 * DAY_IN_SECONDS);
    return $level->cycle_number * $multiplier;
}

/**
 * Prepara los datos para los emails
 */
function prepare_nextcloud_email_data($user, $level, $morder, $config_data, $additional_data) {
    // Determinar mensajes basados en la frecuencia
    $frequency_messages = get_frequency_messages($config_data['payment_frequency'] ?? 'monthly');
    
    return [
        'user' => $user,
        'level' => $level,
        'morder' => $morder,
        'config' => $config_data,
        'password' => $additional_data['password'],
        'fecha_pedido' => $additional_data['fecha_pedido'],
        'fecha_pago_proximo' => $additional_data['fecha_pago_proximo'],
        'monthly_message' => $frequency_messages['monthly_message'],
        'date_message' => $frequency_messages['date_message']
    ];
}

/**
 * Obtiene mensajes según la frecuencia de pago
 */
function get_frequency_messages($payment_frequency) {
    $messages = [
        'monthly' => [
            'monthly_message' => 'mensal ',
            'date_message' => 'Data do próximo pagamento: '
        ],
        'semiannual' => [
            'monthly_message' => 'semestral ',
            'date_message' => 'Data da próxima cobrança semestral: '
        ],
        'annual' => [
            'monthly_message' => 'anual ',
            'date_message' => 'Data da próxima cobrança anual: '
        ],
        'biennial' => [
            'monthly_message' => 'bienal ',
            'date_message' => 'Data da próxima cobrança (em 2 anos): '
        ],
        'triennial' => [
            'monthly_message' => 'trienal ',
            'date_message' => 'Data da próxima cobrança (em 3 anos): '
        ],
        'quadrennial' => [
            'monthly_message' => 'quadrienal ',
            'date_message' => 'Data da próxima cobrança (em 4 anos): '
        ],
        'quinquennial' => [
            'monthly_message' => 'quinquenal ',
            'date_message' => 'Data da próxima cobrança (em 5 anos): '
        ]
    ];

    return $messages[$payment_frequency] ?? $messages['monthly'];
}

/**
 * Envía email al usuario
 */
function send_nextcloud_user_email($data) {
    $user = $data['user'];
    $level = $data['level'];
    $morder = $data['morder'];
    $config = $data['config'];

    // Configuración del email
    $brdrv_email = "cloud@" . basename(get_site_url());
    $mailto = "mailto:" . $brdrv_email;

    // Título del email
    $subject = "Sua instância Nextcloud será criada";
    
    // Construir mensaje
    $message = "<h1>Cloud Brasdrive</h1>";
    $message .= "<p>Prezado(a) <b>" . $user->display_name . "</b> (" . $user->user_login . "),</p>";
    $message .= "<p>Parabéns! Seu pagamento foi confirmado e sua instância Nextcloud será criada em breve.</p>";
    
    // Datos de acceso
    $message .= "<h3>Dados da sua conta admin do Nextcloud:</h3>";
    $message .= "<p><strong>Usuário:</strong> " . $user->user_login . "<br/>";
    $message .= "<strong>Senha:</strong> " . $data['password'] . "</p>";
    
    // Detalles del plan
    $message .= "<h3>Detalhes do seu plano:</h3>";
    $message .= "<p><strong>Plano:</strong> " . $level->name . "<br/>";
    
    // Agregar información de configuración dinámica si está disponible
    if (!empty($config)) {
        $message .= "<strong>Armazenamento:</strong> " . ($config['storage_display'] ?? 'N/A') . "<br/>";
        $message .= "<strong>Suite Office:</strong> " . ($config['office_display'] ?? 'N/A') . "<br/>";
        $message .= "<strong>Frequência:</strong> " . ($config['frequency_display'] ?? 'N/A') . "<br/>";
    }
    
    $message .= "<strong>Data do pedido:</strong> " . $data['fecha_pedido'] . "<br/>";
    $message .= "<strong>Valor " . $data['monthly_message'] . ":</strong> R$ " . number_format($morder->total, 2, ',', '.') . "<br/>";
    $message .= $data['date_message'] . $data['fecha_pago_proximo'] . "</p>";
    
    // Recomendações de segurança
    $message .= "<div style='background-color: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 5px;'>";
    $message .= "<p><strong>⚠️ Importante - Segurança:</strong><br/>";
    $message .= "Por segurança, recomendamos:</p>";
    $message .= "<ul>";
    $message .= "<li>Manter guardada a senha da instância Nextcloud em um local seguro</li>";
    $message .= "<li>Excluir este e-mail após salvar as informações</li>";
    $message .= "<li>Alterar sua senha nas Configurações pessoais de usuário da sua instância Nextcloud</li>";
    $message .= "</ul></div>";
    
    // Informações de contato
    $message .= "<p>Se você tiver alguma dúvida, entre em contato conosco no e-mail: <a href='" . $mailto . "'>" . $brdrv_email . "</a>.</p>";
    $message .= "<p>Atenciosamente,<br/><strong>Equipe Brasdrive</strong></p>";

    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Enviar email
    $result = wp_mail($user->user_email, $subject, $message, $headers);
    
    if (!$result) {
        nextcloud_log_error('Failed to send user email', [
            'user_id' => $user->ID,
            'email' => $user->user_email
        ]);
    }

    return $result;
}

/**
 * Envía email al administrador
 */
function send_nextcloud_admin_email($data) {
    $user = $data['user'];
    $level = $data['level'];
    $config = $data['config'];

    $to = get_option('admin_email');
    $subject = "Nova instância Nextcloud TI - " . $level->name;
    
    $admin_message = "<h2>Nova instância Nextcloud TI contratada</h2>";
    $admin_message .= "<p><strong>Plano:</strong> " . $level->name . "<br/>";
    $admin_message .= "<strong>Nome:</strong> " . $user->display_name . "<br/>";
    $admin_message .= "<strong>Usuário:</strong> " . $user->user_login . "<br/>";
    $admin_message .= "<strong>Email:</strong> " . $user->user_email . "<br/>";
    $admin_message .= "<strong>Senha gerada:</strong> " . $data['password'] . "</p>";
    
    // Agregar configuración dinámica
    if (!empty($config)) {
        $admin_message .= "<h3>Configuração do plano:</h3>";
        $admin_message .= "<p><strong>Armazenamento:</strong> " . ($config['storage_display'] ?? 'N/A') . "<br/>";
        $admin_message .= "<strong>Suite Office:</strong> " . ($config['office_display'] ?? 'N/A') . "<br/>";
        $admin_message .= "<strong>Frequência:</strong> " . ($config['frequency_display'] ?? 'N/A') . "</p>";
    }
    
    $admin_message .= "<p><strong>Data do pedido:</strong> " . $data['fecha_pedido'] . "<br/>";
    $admin_message .= "<strong>Próximo pagamento:</strong> " . $data['fecha_pago_proximo'] . "</p>";

    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $result = wp_mail($to, $subject, $admin_message, $headers);
    
    if (!$result) {
        nextcloud_log_error('Failed to send admin email', [
            'admin_email' => $to,
            'user_id' => $user->ID
        ]);
    }

    return $result;
}

// Funciones auxiliares para nombres de display
function get_storage_display_name($storage_space) {
    $storage_options = [
        '1tb' => '1 Terabyte', '2tb' => '2 Terabytes', '3tb' => '3 Terabytes',
        '4tb' => '4 Terabytes', '5tb' => '5 Terabytes', '6tb' => '6 Terabytes',
        '7tb' => '7 Terabytes', '8tb' => '8 Terabytes', '9tb' => '9 Terabytes',
        '10tb' => '10 Terabytes', '15tb' => '15 Terabytes', '20tb' => '20 Terabytes',
        '30tb' => '30 Terabytes', '40tb' => '40 Terabytes', '50tb' => '50 Terabytes',
        '60tb' => '60 Terabytes', '70tb' => '70 Terabytes', '80tb' => '80 Terabytes',
        '90tb' => '90 Terabytes', '100tb' => '100 Terabytes', '200tb' => '200 Terabytes',
        '300tb' => '300 Terabytes', '400tb' => '400 Terabytes', '500tb' => '500 Terabytes'
    ];


    return $storage_options[$storage_space] ?? $storage_space;
}

function get_office_display_name($office_suite) {
    $office_options = [
        '20users' => '±20 usuários',
        '30users' => '30 usuários',
        '50users' => '50 usuários',
        '80users' => '80 usuários',
        '100users' => '100 usuários',
        '150users' => '150 usuários',
        '200users' => '200 usuários',
        '300users' => '300 usuários',
        '400users' => '400 usuários',
        '500users' => '500 usuários'
    ];
    
    return $office_options[$office_suite] ?? $office_suite;
}

function get_frequency_display_name($payment_frequency) {
    $frequency_options = [
        'monthly' => 'Mensal',
        'semiannual' => 'Semestral',
        'annual' => 'Anual',
        'biennial' => 'Bienal',
        'triennial' => 'Trienal',
        'quadrennial' => 'Quadrienal',
        'quinquennial' => 'Quinquenal'
    ];
    
    return $frequency_options[$payment_frequency] ?? $payment_frequency;
}
