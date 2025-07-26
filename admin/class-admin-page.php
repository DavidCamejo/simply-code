<?php

class Simply_Code_Admin {
    // Opción de modo seguro
    const OPTION_SAFE_MODE = 'simply_code_safe_mode';
    
    public static function register_menu() {
        add_menu_page(
            'Simply Code', 'Simply Code', 'manage_options', 'simply-code',
            [self::class, 'main_page'], 'dashicons-editor-code', 60
        );
        add_submenu_page(
            'simply-code', 'Nuevo Snippet', 'Nuevo Snippet', 
            'manage_options', 'simply-code-new', 
            [Simply_Snippet_Editor::class, 'new_snippet_page']
        );
        add_submenu_page(
            'simply-code', 'Editar Snippet', '', 
            'manage_options', 'simply-code-edit', 
            [Simply_Snippet_Editor::class, 'edit_snippet_page']
        );
        add_submenu_page(
            'simply-code', 'Eliminar Snippet', '', 
            'manage_options', 'simply-code-delete', 
            [self::class, 'delete_snippet_page']
        );
    }

    public static function main_page() {
        $snippets = Simply_Snippet_Manager::list_snippets(true);
        $order_file = SC_PATH . 'includes/snippets-order.php';
        $order = file_exists($order_file) ? include $order_file : [];
        include SC_PATH . 'admin/views/snippet-list.php';
    }

    public static function delete_snippet_page() {
        if (!isset($_REQUEST['snippet']) || !current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción.'));
        }

        $name = sanitize_file_name($_REQUEST['snippet']);
        Simply_Snippet_Manager::delete_snippet($name);
        wp_redirect(admin_url('admin.php?page=simply-code'));
        exit;
    }
}