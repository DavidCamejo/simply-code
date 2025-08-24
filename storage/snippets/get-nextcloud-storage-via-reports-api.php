<?php
// Monitorear el espacio en Nextcloud y enviar reporte diario

if (!defined('ABSPATH')) exit;

function get_nextcloud_storage_via_reports_api() {
    $nextcloud_api_admin = getenv('NEXTCLOUD_API_ADMIN');
    $nextcloud_api_pass = getenv('NEXTCLOUD_API_PASS');
    $current_date = date('Y-m-d', time());
    $site_url = get_option('siteurl');
    $to_admin = get_option('admin_email');
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $nextcloud_url = 'https://cloud.' . basename($site_url);
    $max_storage = 1000; // 1 TB en GB

    $endpoint = $nextcloud_url . '/ocs/v2.php/apps/serverinfo/api/v1/info?format=json';
    
    $args = array(
        'headers' => array(
            'OCS-APIRequest' => 'true',
            'Authorization' => 'Basic ' . base64_encode($nextcloud_api_admin . ':' . $nextcloud_api_pass),
        ),
    );
    
    $response = wp_remote_get($endpoint, $args);
    
    if (!is_wp_error($response)) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['ocs']['data']['nextcloud']['storage']['users'])) {
            $total_used = $body['ocs']['data']['nextcloud']['storage']['used'];
            $total_free = $body['ocs']['data']['nextcloud']['storage']['free'];
            $total_space = $total_used + $total_free;
            
            return array(
                'used' => round($total_used / (1024 * 1024 * 1024), 2), // Convertir a GB
                'total' => round($total_space / (1024 * 1024 * 1024), 2)
            );
        }
    }

    // Preparar y enviar el email
    $to = get_option('admin_email');
    $subject = 'Reporte de Almacenamiento Cloud Brasdrive';
    $message = sprintf(
	$current_date,
        'Uso de almacenamiento en Cloud Brasdrive: %.2f GB de %d GB (%.1f%%) utilizados.',
        $total_used,
        $max_storage,
        ($total_used / $max_storage) * 100
    );
    
    wp_mail($to, $subject, $message);

    return false;
}

// Programar el evento diario
function schedule_nextcloud_storage_check() {
    if (!wp_next_scheduled('daily_nextcloud_storage_check')) {
        wp_schedule_event(strtotime('01:00:00'), 'daily', 'daily_nextcloud_storage_check');
    }
}
add_action('wp', 'schedule_nextcloud_storage_check');

// Conectar la acción programada con nuestra función
add_action('daily_nextcloud_storage_check', 'get_nextcloud_storage_via_reports_api');
