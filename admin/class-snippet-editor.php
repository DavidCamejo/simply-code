<?php

class Simply_Snippet_Editor {
    public static function new_snippet_page() {
        $templates = [];
        foreach (glob(SC_PATH . 'templates/*.php') as $tpl) {
            $tpl_name = basename($tpl, '.php');
            $tpl_code = file_get_contents($tpl);
            $desc = 'Sin descripción';
            if (preg_match('/@description\s+(.+)/i', $tpl_code, $m)) {
                $desc = trim($m[1]);
            }
            $templates[$tpl_name] = [
                'code' => $tpl_code,
                'description' => $desc
            ];
        }

        $php_code = isset($_POST['php_code']) ? stripslashes($_POST['php_code']) : "<?php\n// Tu código PHP aquí\n";
        $js_code = isset($_POST['js_code']) ? stripslashes($_POST['js_code']) : "// Tu código JS aquí\n";
        $css_code = isset($_POST['css_code']) ? stripslashes($_POST['css_code']) : "/* Tu código CSS aquí */\n";
        $description = isset($_POST['description']) ? stripslashes($_POST['description']) : '';

        // Si se seleccionó una plantilla, cargar su código
        if (isset($_POST['template']) && isset($templates[$_POST['template']])) {
            $php_code = $templates[$_POST['template']]['code'];
            $description = $templates[$_POST['template']]['description'];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['snippet_name'])) {
            $name = sanitize_file_name($_POST['snippet_name']);
            
            // Validar sintaxis PHP antes de guardar
            $syntax_result = Simply_Syntax_Checker::validate_php($php_code);
            
            if ($syntax_result['valid']) {
                // Por defecto, los nuevos snippets están activos
                Simply_Snippet_Manager::save_snippet($name, $php_code, $js_code, $css_code, $description, true);
                echo '<div class="notice notice-success"><p>Snippet guardado correctamente.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error de sintaxis en PHP: ' . esc_html($syntax_result['message']) . '</p></div>';
            }
        }
        
        include SC_PATH . 'admin/views/snippet-editor.php';
    }

    public static function edit_snippet_page() {
        if (!isset($_REQUEST['snippet']) || !current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para realizar esta acción.'));
        }

        $name = sanitize_file_name($_REQUEST['snippet']);
        $snippet = Simply_Snippet_Manager::get_snippet($name);
        
        $php_code = $snippet['php'];
        $js_code = $snippet['js'];
        $css_code = $snippet['css'];
        $description = $snippet['description'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $php_clean = stripslashes($_POST['php_code']);
            $js_clean = stripslashes($_POST['js_code']);
            $css_clean = stripslashes($_POST['css_code']);
            $desc_clean = stripslashes($_POST['description']);
            
            // Validar sintaxis PHP antes de guardar
            $syntax_result = Simply_Syntax_Checker::validate_php($php_clean);
            
            if ($syntax_result['valid']) {
                // Mantener el estado activo actual al actualizar
                $active = isset($snippet['active']) ? $snippet['active'] : true;
                Simply_Snippet_Manager::save_snippet($name, $php_clean, $js_clean, $css_clean, $desc_clean, $active);
                echo '<div class="notice notice-success"><p>Snippet actualizado correctamente.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error de sintaxis en PHP: ' . esc_html($syntax_result['message']) . '</p></div>';
            }
            
            // Actualizar variables para mostrar el contenido actualizado
            $php_code = $php_clean;
            $js_code = $js_clean;
            $css_code = $css_clean;
            $description = $desc_clean;
        }
        
        include SC_PATH . 'admin/views/snippet-editor.php';
    }
}