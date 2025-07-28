<?php
class Simply_Code_Admin {
    const OPTION_SAFE_MODE = 'simply_code_safe_mode';

    /**
     * Register admin menu and submenus
     */
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

    /**
     * Handle admin notices - VERSIÓN MEJORADA CON TRANSIENTS
     */
    private static function handle_admin_notices() {
        $notice = '';

        // Manejar mensajes de éxito desde transients (NUEVO)
        $success_message = get_transient('simply_code_success');
        if ($success_message) {
            $notice .= '<div class="notice notice-success is-dismissible"><p>' . esc_html($success_message) . '</p></div>';
            delete_transient('simply_code_success');
        }

        // Mantener compatibilidad con parámetros GET existentes (para otros casos)
        if (isset($_GET['created']) && isset($_GET['snippet'])) {
            $snippet_name = sanitize_text_field(urldecode($_GET['snippet']));
            $notice .= sprintf(
                '<div class="notice notice-success is-dismissible"><p>Snippet "%s" creado correctamente.</p></div>',
                esc_html($snippet_name)
            );
        }

        if (isset($_GET['updated']) && isset($_GET['snippet'])) {
            $snippet_name = sanitize_text_field(urldecode($_GET['snippet']));
            $notice .= sprintf(
                '<div class="notice notice-success is-dismissible"><p>Snippet "%s" actualizado correctamente.</p></div>',
                esc_html($snippet_name)
            );
        }

        if (isset($_GET['deleted']) && isset($_GET['snippet'])) {
            $snippet_name = sanitize_text_field(urldecode($_GET['snippet']));
            $notice .= sprintf(
                '<div class="notice notice-success is-dismissible"><p>Snippet "%s" eliminado correctamente.</p></div>',
                esc_html($snippet_name)
            );
        }

        if (isset($_GET['saved'])) {
            $message = 'Snippet guardado correctamente.';
            if (isset($_GET['snippet_name'])) {
                $snippet_name = sanitize_text_field(urldecode($_GET['snippet_name']));
                $message = sprintf('Snippet "%s" guardado correctamente.', esc_html($snippet_name));
            }
            $notice .= sprintf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                $message
            );
        }

        return $notice;
    }

    /**
     * Safe redirect with proper buffer cleanup - MÉTODO CORREGIDO
     */
    private static function safe_redirect($url) {
        // Solo limpiar si hay buffers de usuario con contenido
        if (ob_get_level() > 0 && ob_get_contents() !== false) {
            $buffer_content = ob_get_contents();
            if (!empty(trim($buffer_content))) {
                ob_end_clean();
            }
        }
        
        // Verificar que no se hayan enviado headers
        if (headers_sent($file, $line)) {
            error_log("Simply Code: Headers already sent in {$file} at line {$line}, cannot redirect to: {$url}");
            // CORREGIDO: Usar exit en lugar de return
            echo '<script>setTimeout(function(){ window.location.href = "' . esc_js($url) . '"; }, 100);</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_attr($url) . '" /></noscript>';
            exit; // CORREGIDO: exit en lugar de return
        }
        
        // Usar wp_safe_redirect que es más robusto
        $result = wp_safe_redirect($url);
        if (!$result) {
            error_log("Simply Code: wp_safe_redirect failed for URL: {$url}");
            echo '<script>window.location.href = "' . esc_js($url) . '";</script>';
        }
        exit;
    }

    /**
     * Main admin page handler - VERSIÓN MEJORADA SIN DOBLE REDIRECT
     */
    public static function main_page() {
        $action_message = '';
        
        // CRÍTICO: Manejar POST antes de cualquier output
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'simply_code_actions')) {
                wp_die('Error de seguridad');
            }

            // Solo delete requiere redirect (para evitar resubmisión accidental)
            if (isset($_POST['delete_snippet'])) {
                self::handle_deletion(); // Hace redirect y exit
            }
            
            // Otras acciones sin redirect para evitar el efecto "doble carga"
            if (isset($_POST['toggle_snippet_status'])) {
                $action_message = self::handle_status_toggle_inline();
            }
            elseif (isset($_POST['move_up']) || isset($_POST['move_down'])) {
                $action_message = self::handle_reordering_inline();
            }
            elseif (isset($_POST['safe_mode_toggle'])) {
                $action_message = self::handle_safe_mode_inline();
            }
            else {
                // Si no coincide con ninguna acción conocida
                wp_die('Acción POST no reconocida');
            }
        }

        // Mostrar notices de URL parameters
        $notice = self::handle_admin_notices();
        
        // Agregar notices para nuevas acciones
        if (isset($_GET['status_changed']) && isset($_GET['snippet']) && isset($_GET['status'])) {
            $snippet_name = sanitize_text_field(urldecode($_GET['snippet']));
            $status = $_GET['status'] === 'activated' ? 'activado' : 'desactivado';
            $notice .= sprintf(
                '<div class="notice notice-success is-dismissible"><p>Snippet "%s" %s correctamente.</p></div>',
                esc_html($snippet_name),
                $status
            );
        }

        if (isset($_GET['reordered'])) {
            $notice .= '<div class="notice notice-success is-dismissible"><p>Orden de snippets actualizado correctamente.</p></div>';
        }

        if (isset($_GET['safe_mode_updated'])) {
            $notice .= '<div class="notice notice-success is-dismissible"><p>Configuración de modo seguro actualizada correctamente.</p></div>';
        }

        // Agregar mensaje de acción inline si existe
        if ($action_message) {
            $notice .= '<div class="notice notice-success is-dismissible"><p>' . esc_html($action_message) . '</p></div>';
        }

        // Obtener snippets
        $snippets = Simply_Snippet_Manager::list_snippets(true, true);

        // Mostrar notices
        if ($notice) {
            echo wp_kses_post($notice);
        }

        // Renderizar vista
        include SC_PATH . 'admin/views/snippet-list.php';
    }

    /**
     * Handle status toggle without redirect - NUEVO MÉTODO
     */
    private static function handle_status_toggle_inline() {
        if (!isset($_POST['toggle_snippet_status'], $_POST['snippet_name'])) {
            return 'Error: Datos de formulario incompletos para cambio de estado.';
        }

        $snippet_name = sanitize_text_field($_POST['snippet_name']);
        $new_status = isset($_POST['snippet_active']);

        if (Simply_Snippet_Manager::toggle_snippet_status($snippet_name, $new_status)) {
            $status_text = $new_status ? 'activado' : 'desactivado';
            return sprintf('Snippet "%s" %s correctamente.', esc_html($snippet_name), $status_text);
        }

        return sprintf('Error al cambiar el estado del snippet "%s".', esc_html($snippet_name));
    }

    /**
     * Handle reordering without redirect - NUEVO MÉTODO
     */
    private static function handle_reordering_inline() {
        if (!isset($_POST['move_up']) && !isset($_POST['move_down'])) {
            return 'Error: Acción de reordenamiento no especificada.';
        }

        $snippets = Simply_Snippet_Manager::list_snippets(true);
        $i = isset($_POST['move_up']) ? (int)$_POST['move_up'] : (int)$_POST['move_down'];
        $names = array_map(function($s) { return $s['name']; }, $snippets);
        $changed = false;

        if (isset($_POST['move_up']) && $i > 0) {
            $tmp = $names[$i-1];
            $names[$i-1] = $names[$i];
            $names[$i] = $tmp;
            $changed = true;
        }
        elseif (isset($_POST['move_down']) && $i < count($names) - 1) {
            $tmp = $names[$i+1];
            $names[$i+1] = $names[$i];
            $names[$i] = $tmp;
            $changed = true;
        }

        if ($changed && Simply_Snippet_Manager::update_snippets_order($names)) {
            return 'Orden de snippets actualizado correctamente.';
        }

        return 'Error al actualizar el orden de los snippets.';
    }

    /**
     * Handle safe mode without redirect - NUEVO MÉTODO
     */
    private static function handle_safe_mode_inline() {
        if (!isset($_POST['safe_mode_toggle'])) {
            return 'Error: Configuración de modo seguro no especificada.';
        }

        update_option(
            self::OPTION_SAFE_MODE,
            isset($_POST['safe_mode']) && $_POST['safe_mode'] === 'on' ? 'on' : 'off'
        );

        return 'Configuración de modo seguro actualizada correctamente.';
    }

    /**
     * Handle deletion with redirect - MÉTODO ACTUALIZADO CON TRANSIENTS
     */
    private static function handle_deletion() {
        if (!isset($_POST['delete_snippet'], $_POST['snippet_name'])) {
            wp_die('Error: Datos de eliminación incompletos.');
        }

        $snippet_name = sanitize_text_field($_POST['snippet_name']);

        if (Simply_Snippet_Manager::delete_snippet($snippet_name)) {
            // NUEVO: Usar transient en lugar de parámetros GET
            $success_message = sprintf('Snippet "%s" eliminado correctamente.', esc_html($snippet_name));
            set_transient('simply_code_success', $success_message, 45);
            
            // Redirect simple sin parámetros
            $redirect_url = admin_url('admin.php?page=simply-code');
            self::safe_redirect($redirect_url);
            return; // Never reached
        }

        wp_die('Error al eliminar el snippet "' . esc_html($snippet_name) . '".');
    }
}
