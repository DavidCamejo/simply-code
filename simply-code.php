<?php
/*
Plugin Name: Simply Code
Description: Gestión modular de código personalizado como mini-plugins. La alternativa moderna a functions.php.
Version: 3.0
Author: David Camejo & AI
*/

if (!defined('ABSPATH')) exit;

define('SC_PATH', plugin_dir_path(__FILE__));
define('SC_URL', plugin_dir_url(__FILE__));
define('SC_STORAGE', SC_PATH . 'storage');

// Crear carpetas necesarias si no existen
$folders = [
    SC_STORAGE . '/snippets/',
    SC_STORAGE . '/js/',
    SC_STORAGE . '/css/',
    SC_PATH . 'templates/',
];
foreach ($folders as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
}

// Cargar clases principales
require_once SC_PATH . 'includes/class-snippet-manager.php';
require_once SC_PATH . 'includes/class-syntax-checker.php';
require_once SC_PATH . 'admin/class-admin-page.php';
require_once SC_PATH . 'admin/class-snippet-editor.php';

// Registrar acciones principales
add_action('after_setup_theme', ['Simply_Snippet_Manager', 'load_snippets'], 5);
add_action('admin_menu', ['Simply_Code_Admin', 'register_menu']);
add_action('wp_enqueue_scripts', ['Simply_Snippet_Manager', 'enqueue_snippet_assets']);