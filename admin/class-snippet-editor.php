<?php
if (!defined('ABSPATH')) exit;

class Simply_Snippet_Editor {
    private static $templates = null;

    /**
     * Página principal del editor - maneja tanto creación como edición
     */
    public static function new_snippet() {
        // CRÍTICO: Manejar POST antes de cualquier output
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handle_post_request();
            // Si llegamos aquí, hubo un error (el éxito hace redirect y exit)
        }
        // Mostrar errores si los hay
        self::show_stored_errors();

        // Preparar y mostrar la vista
        $edit_mode = isset($_GET['edit']);
        $snippet_name = $edit_mode ? sanitize_file_name($_GET['edit']) : '';
        $view_data = self::prepare_view_data($edit_mode, $snippet_name);
        self::render_view($view_data);
    }

    /**
     * Maneja la solicitud POST de forma segura
     */
    private static function handle_post_request() {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            self::store_error('Permisos insuficientes');
            self::redirect_back();
            return;
        }

        // Verificar nonce
        if (empty($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'simply_code_actions')) {
            self::store_error('Error de seguridad');
            self::redirect_back();
            return;
        }

        $edit_mode = isset($_GET['edit']);
        $form_data = self::sanitize_form_data($_POST);

        // Validar datos
        $validation_result = self::validate_form_data($form_data, $edit_mode);
        if ($validation_result !== true) {
            self::store_error($validation_result);
            self::redirect_back();
            return;
        }

        // Aplicar plantilla si es necesario
        if (!$edit_mode && !empty($form_data['template'])) {
            $form_data = self::apply_template($form_data);
        }

        // Determinar estado activo
        $active = $edit_mode ?
            self::get_existing_active_status($form_data['snippet_name']) :
            true;

        // Guardar snippet
        $save_result = Simply_Snippet_Manager::save_snippet(
            $form_data['snippet_name'],
            $form_data['php_code'],
            $form_data['js_code'],
            $form_data['css_code'],
            $form_data['description'],
            $active,
            $form_data['hook_priorities']
        );

        if (!$save_result) {
            self::store_error('Error al guardar el snippet. Verifica los permisos de escritura y revisa debug.log.');
            self::redirect_back();
            return;
        }

        self::redirect_with_success($edit_mode, $form_data['snippet_name']);
    }

    // Agregar AJAX handler para detección de hooks
    public static function ajax_detect_hooks() {
        check_ajax_referer('simply_code_detect_hooks', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos suficientes');
        }
        $php_code = stripslashes($_POST['php_code'] ?? '');
        $hooks = [];
        $critical_hooks = [];

        if (class_exists('Simply_Hook_Detector') && method_exists('Simply_Hook_Detector', 'detect_hooks')) {
            $hooks = Simply_Hook_Detector::detect_hooks($php_code);
            $critical_hooks = Simply_Hook_Detector::get_critical_hooks();
        }

        wp_send_json_success([
            'hooks' => $hooks,
            'critical_hooks' => $critical_hooks
        ]);
    }

    /**
     * Sanitize form data
     */
    private static function sanitize_form_data($post_data) {
        $hook_priorities = [];
        if (isset($post_data['hook_priorities']) && is_array($post_data['hook_priorities'])) {
            foreach ($post_data['hook_priorities'] as $hook => $priority) {
                $hook_priorities[sanitize_text_field($hook)] = (int)$priority;
            }
        }

        return [
            'snippet_name'    => sanitize_file_name($post_data['snippet_name'] ?? ''),
            'php_code'        => stripslashes($post_data['php_code'] ?? ''),
            'js_code'         => stripslashes($post_data['js_code'] ?? ''),
            'css_code'        => stripslashes($post_data['css_code'] ?? ''),
            'description'     => stripslashes($post_data['description'] ?? ''),
            'template'        => sanitize_text_field($post_data['template'] ?? ''),
            'hook_priorities' => $hook_priorities
        ];
    }

    /**
     * Validate form data
     */
    private static function validate_form_data($form_data, $edit_mode) {
        // Check required fields
        if (empty($form_data['snippet_name'])) {
            return 'El nombre del snippet es requerido';
        }
        // Validar formato del nombre para prevenir XSS
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $form_data['snippet_name'])) {
            return 'El nombre del snippet solo puede contener letras, números, guiones y guiones bajos';
        }
        // Check for duplicate names (creation mode only)
        if (!$edit_mode && Simply_Snippet_Manager::get_snippet($form_data['snippet_name'])) {
            return 'Ya existe un snippet con ese nombre';
        }
        // Validate PHP syntax (si existe la clase)
        if (!empty($form_data['php_code']) && class_exists('Simply_Syntax_Checker') && method_exists('Simply_Syntax_Checker', 'validate_php')) {
            $syntax_result = Simply_Syntax_Checker::validate_php($form_data['php_code']);
            if (isset($syntax_result['valid']) && $syntax_result['valid'] === false) {
                return 'Error de sintaxis en PHP: ' . ($syntax_result['message'] ?? 'error desconocido');
            }
        }
        return true;
    }

    /**
     * Apply template to form data
     */
    private static function apply_template($form_data) {
        $templates = self::get_templates();
        if (isset($templates[$form_data['template']])) {
            $template = $templates[$form_data['template']];
            $form_data['php_code'] = $template['code'];
            if (empty($form_data['description'])) {
                $form_data['description'] = $template['description'];
            }
        }
        return $form_data;
    }

    /**
     * Get existing snippet active status
     */
    private static function get_existing_active_status($snippet_name) {
        $existing_snippet = Simply_Snippet_Manager::get_snippet($snippet_name);
        return $existing_snippet ? $existing_snippet['active'] : true;
    }

    /**
     * Almacena un error para mostrarlo después del redirect
     */
    private static function store_error($message) {
        set_transient('simply_code_error', $message, 45);
    }

    /**
     * Muestra los errores almacenados
     */
    private static function show_stored_errors() {
        $error = get_transient('simply_code_error');
        if ($error) {
            self::show_error($error);
            delete_transient('simply_code_error');
        }
    }

    /**
     * Redirecciona de vuelta a la página anterior
     */
    private static function redirect_back() {
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = admin_url('admin.php?page=simply-code');
        }
        self::safe_redirect($redirect_url);
    }

    /**
     * Realiza un redirect seguro
     */
    private static function safe_redirect($url) {
        // Solo limpiar si hay buffers de usuario con contenido
        if (ob_get_level() > 0 && ob_get_contents() !== false) {
            $buffer_content = ob_get_contents();
            if (!empty(trim($buffer_content))) {
                @ob_end_clean();
            }
        }
        // Verificar que no se hayan enviado headers
        if (headers_sent($file, $line)) {
            error_log("Simply Code: Headers already sent in {$file} at line {$line}, cannot redirect to: {$url}");
            echo '';
            exit;
        }
        // Usar wp_safe_redirect con verificación
        $result = wp_safe_redirect($url);
        if (!$result) {
            error_log("Simply Code: wp_safe_redirect failed for URL: {$url}");
            echo '';
        }
        exit;
    }

    /**
     * Redirect with success message
     */
    private static function redirect_with_success($edit_mode, $snippet_name) {
        $action_text = $edit_mode ? 'actualizado' : 'creado';
        $success_message = sprintf('Snippet "%s" %s correctamente.', esc_html($snippet_name), $action_text);
        set_transient('simply_code_success', $success_message, 45);
        $redirect_url = admin_url('admin.php?page=simply-code');
        self::safe_redirect($redirect_url);
    }

    /**
     * Show error message
     */
    private static function show_error($message) {
        echo '<div class="notice notice-error inline"><p>' . esc_html($message) . '</p></div>';
    }

    /**
     * Render the view
     */
    private static function render_view($view_data) {
        extract($view_data);
        include SC_PATH . 'admin/views/snippet-editor.php';
    }

    /**
     * Prepara los datos para la vista
     */
    private static function prepare_view_data($edit_mode, $snippet_name) {
        $templates = self::get_templates();
        // Valores por defecto
        $php_code = $templates['empty']['code'] ?? "<?php\n\n";
        $js_code = '';
        $css_code = '';
        $description = '';
        $snippet = null;
        // Si estamos editando, cargar datos existentes
        if ($edit_mode && !empty($snippet_name)) {
            $snippet = Simply_Snippet_Manager::get_snippet($snippet_name);
            if ($snippet) {
                $php_code = $snippet['php'];
                $js_code = $snippet['js'];
                $css_code = $snippet['css'];
                $description = $snippet['description'];
            }
        }
        $hooks_data = [];
        if ($edit_mode && $snippet) {
            // Obtener metadatos del snippet
            $json_file = rtrim(SC_STORAGE, '/\\') . "/snippets/{$snippet_name}.json";
            if (file_exists($json_file)) {
                $metadata = json_decode(file_get_contents($json_file), true) ?: [];
                $hooks_data = $metadata['hooks'] ?? [];
            }
        }
        // Retornar array con todas las variables necesarias para la vista
        return [
            'templates' => $templates,
            'php_code' => $php_code,
            'js_code' => $js_code,
            'css_code' => $css_code,
            'description' => $description,
            'snippet' => $snippet,
            'edit_mode' => $edit_mode,
            'hooks_data' => $hooks_data,
            'critical_hooks' => (class_exists('Simply_Hook_Detector') && method_exists('Simply_Hook_Detector', 'get_critical_hooks')) ? Simply_Hook_Detector::get_critical_hooks() : []
        ];
    }

    /**
     * Get templates with caching
     */
    public static function get_templates() {
        if (self::$templates !== null) {
            return self::$templates;
        }

        self::$templates = [
            'empty' => [
                'code' => "<?php\n// @description Nuevo snippet\n\nif (!defined('ABSPATH')) exit;\n\n",
                'description' => 'Snippet vacío'
            ],
            'function' => [
                'code' => "<?php\n// @description Nueva función\n\nif (!defined('ABSPATH')) exit;\n\nfunction my_custom_function() {\n    // Tu código aquí\n}\n",
                'description' => 'Función personalizada'
            ],
            'action' => [
                'code' => "<?php\n// @description Nueva acción WordPress\n\nif (!defined('ABSPATH')) exit;\n\nadd_action('init', function() {\n    // Tu código aquí\n});\n",
                'description' => 'Acción de WordPress'
            ],
            'filter' => [
                'code' => "<?php\n// @description Nuevo filtro WordPress\n\nif (!defined('ABSPATH')) exit;\n\nadd_filter('the_content', function(\$content) {\n    // Tu código aquí\n    return \$content;\n});\n",
                'description' => 'Filtro de WordPress'
            ]
        ];
        return self::$templates;
    }
}
