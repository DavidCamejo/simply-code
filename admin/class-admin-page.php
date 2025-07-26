<?php
class Simply_Code_Admin {
    const OPTION_SAFE_MODE = 'simply_code_safe_mode';
    
    public static function register_menu() {
        add_menu_page(
            'Simply Code',
            'Simply Code',
            'manage_options',
            'simply-code',
            [self::class, 'main_page'],
            'dashicons-editor-code'
        );
        
        add_submenu_page(
            'simply-code',
            'Nuevo Snippet',
            'Nuevo Snippet',
            'manage_options',
            'simply-code-new',
            [Simply_Snippet_Editor::class, 'new_snippet']
        );
    }
    
    public static function main_page() {
        $notice = '';
        
        // Mostrar noticia de orden actualizado si viene de redirección (aunque ya no redirigimos para reordenar)
        if (isset($_GET['order_updated']) && $_GET['order_updated'] == '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Orden de snippets actualizado.</p></div>';
        }
        
        // PROCESAR POST ANTES DE INCLUIR LA VISTA
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('simply_code_actions');
            
            if (isset($_POST['toggle_snippet_status']) && isset($_POST['snippet_name'])) {
                $snippet_name = sanitize_text_field($_POST['snippet_name']);
                $new_status = isset($_POST['snippet_active']);
                
                if (Simply_Snippet_Manager::toggle_snippet_status($snippet_name, $new_status)) {
                    $status_text = $new_status ? 'activado' : 'desactivado';
                    $notice = '<div class="notice notice-success is-dismissible"><p>Snippet ' . esc_html($snippet_name) . ' ' . $status_text . '.</p></div>';
                } else {
                    $notice = '<div class="notice notice-error is-dismissible"><p>Error al cambiar el estado del snippet ' . esc_html($snippet_name) . '.</p></div>';
                }
            }
            // Procesar reordenamiento
            else if (isset($_POST['move_up']) || isset($_POST['move_down'])) {
                $snippets = Simply_Snippet_Manager::list_snippets(true); // Carga los snippets actuales para el reordenamiento
                $i = isset($_POST['move_up']) ? (int)$_POST['move_up'] : (int)$_POST['move_down'];
                $names = array_map(function($s) { return $s['name']; }, $snippets);
                $changed = false;

                if (isset($_POST['move_up']) && $i > 0) {
                    $tmp = $names[$i-1];
                    $names[$i-1] = $names[$i];
                    $names[$i] = $tmp;
                    $changed = true;
                }
                else if (isset($_POST['move_down']) && $i < count($names) - 1) {
                    $tmp = $names[$i+1];
                    $names[$i+1] = $names[$i];
                    $names[$i] = $tmp;
                    $changed = true;
                }

                if ($changed) {
                    if (Simply_Snippet_Manager::update_snippets_order($names)) {
                        $notice = '<div class="notice notice-success is-dismissible"><p>Orden de snippets actualizado.</p></div>';
                    } else {
                        $notice = '<div class="notice notice-error is-dismissible"><p>Error al actualizar el orden de los snippets.</p></div>';
                    }
                }
            }
            // Procesar cambio de modo seguro
            else if (isset($_POST['safe_mode_toggle'])) {
                update_option(self::OPTION_SAFE_MODE,
                    $_POST['safe_mode'] === 'on' ? 'on' : 'off'
                );
                $notice = '<div class="notice notice-success is-dismissible"><p>Configuración de modo seguro actualizada.</p></div>';
            }
        }
        
        // Cargar snippets con el orden actualizado (ahora sí, se recargará desde disco si el caché se limpió)
        $snippets = Simply_Snippet_Manager::list_snippets(true, true);
        
        // Mostrar la noticia si existe
        if ($notice) {
            echo $notice;
        }
        
        // Incluir la vista con los snippets actualizados
        include SC_PATH . 'admin/views/snippet-list.php';
    }
}
