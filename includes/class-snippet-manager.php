<?php
class Simply_Snippet_Manager {
    private static $order_cache = null;
    private static $snippets_cache = null;
    
    /**
     * Obtiene el orden actual de los snippets
     */
    private static function get_order() {
        $order_file = SC_PATH . 'includes/snippets-order.php';
        if (!file_exists($order_file)) return [];
        // Forzar recarga real del archivo, evitando caché de include
        $content = file_get_contents($order_file);
        $order = [];
        if (preg_match('/return\s+(.+);/s', $content, $matches)) {
            $order = eval('return ' . $matches[1] . ';');
        }
        return $order;
    }

    /**
     * Invalida las cachés internas
     */
    private static function invalidate_cache() {
        self::$order_cache = null;
        self::$snippets_cache = null;
        clearstatcache();
    }

    public static function load_snippets() {
        $dir = SC_STORAGE . '/snippets/';
        if (!is_dir($dir)) return;

        clearstatcache(true);
        
        $order_file = SC_PATH . 'includes/snippets-order.php';
        $order = file_exists($order_file) ? include $order_file : [];
        $all_snippets = [];
        
        foreach (scandir($dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $all_snippets[] = basename($file, '.php');
            }
        }
        
        $ordered = array_unique(array_merge($order, $all_snippets));
        $safe_mode = get_option(Simply_Code_Admin::OPTION_SAFE_MODE, 'on');
        
        foreach ($ordered as $basename) {
            $php = $dir . $basename . '.php';
            $json = $dir . $basename . '.json';
            
            clearstatcache(true, $json);
            
            // Verificar si el snippet está activo
            $is_active = true;
            if (file_exists($json)) {
                $meta = json_decode(file_get_contents($json), true);
                if (isset($meta['active']) && $meta['active'] === false) {
                    continue; // Saltar snippets inactivos
                }
            }
            
            // Cargar snippet activo
            if (file_exists($php)) {
                try {
                    if ($safe_mode === 'on') {
                        $snippet_code = file_get_contents($php);
                        $syntax_result = Simply_Syntax_Checker::validate_php($snippet_code);
                        
                        if (!$syntax_result['valid']) {
                            error_log("Simply Code: Error de sintaxis en $php - " . $syntax_result['message']);
                            continue;
                        }
                    }
                    
                    require_once $php;
                } catch (Throwable $e) {
                    error_log("Simply Code: Error al cargar $php - " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Encola automáticamente los assets JS y CSS de los snippets activos
     */
    public static function enqueue_snippet_assets() {
        $dir_js = SC_STORAGE . '/js/';
        $dir_css = SC_STORAGE . '/css/';
        $snippets = self::list_snippets(true);

        foreach ($snippets as $snippet) {
            if (empty($snippet['active'])) continue;
            $name = $snippet['name'];

            // JS
            $js_file = $dir_js . $name . '.js';
            if (file_exists($js_file)) {
                wp_enqueue_script(
                    'simply-snippet-' . $name,
                    plugins_url('storage/js/' . $name . '.js', SC_PATH . 'simply-code.php'),
                    ['jquery'], // Dependencia de jQuery
                    filemtime($js_file),
                    true // En footer
                );
            }

            // CSS
            $css_file = $dir_css . $name . '.css';
            if (file_exists($css_file)) {
                wp_enqueue_style(
                    'simply-snippet-' . $name,
                    plugins_url('storage/css/' . $name . '.css', SC_PATH . 'simply-code.php'),
                    [],
                    filemtime($css_file)
                );
            }
        }
    }

    public static function toggle_snippet_status($name, $active) {
        $json_file = SC_STORAGE . "/snippets/{$name}.json";
        $snippet_data = [];
        
        // Limpiar la caché del sistema de archivos
        clearstatcache(true, $json_file);
        
        // Obtener datos existentes
        if (file_exists($json_file)) {
            $snippet_data = json_decode(file_get_contents($json_file), true) ?: [];
        }
        
        // Actualizar estado
        $snippet_data['active'] = (bool) $active;
        $snippet_data['last_updated'] = date('Y-m-d H:i:s');
        
        // Forzar la escritura inmediata al archivo
        $success = file_put_contents($json_file, json_encode($snippet_data), LOCK_EX);
        
        if ($success) {
            // Limpiar la caché después de escribir
            clearstatcache(true, $json_file);
        }
        
        return $success !== false;
    }

    public static function save_snippet($name, $php, $js, $css, $description = '', $active = true) {
        $php_file = SC_STORAGE . "/snippets/{$name}.php";
        $backup_file = SC_STORAGE . "/backups/{$name}.php." . time();
        
        // Crear directorio de backups si no existe
        if (!is_dir(SC_STORAGE . "/backups/")) {
            mkdir(SC_STORAGE . "/backups/", 0755, true);
        }
        
        // Crear backup si existe el archivo original
        if (file_exists($php_file)) {
            copy($php_file, $backup_file);
        }
        
        // Guardar todos los archivos
        file_put_contents(SC_STORAGE . "/snippets/{$name}.php", $php);
        file_put_contents(SC_STORAGE . "/js/{$name}.js", $js);
        file_put_contents(SC_STORAGE . "/css/{$name}.css", $css);
        file_put_contents(SC_STORAGE . "/snippets/{$name}.json", json_encode([
            'description' => $description,
            'last_updated' => date('Y-m-d H:i:s'),
            'active' => $active
        ]));
    }

    /**
     * Lista todos los snippets
     */
    public static function list_snippets($apply_order = true, $force_reload = false) {
        // Si no se fuerza la recarga y hay caché, devolverlo
        if (self::$snippets_cache !== null && !$force_reload) {
            return self::$snippets_cache;
        }

        $snippets = [];
        $dir = SC_STORAGE . '/snippets/';
        if (!is_dir($dir)) return $snippets;

        // Obtener snippets y orden
        $order = $apply_order ? self::get_order() : [];
        $available_snippets = [];

        foreach (scandir($dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $name = basename($file, '.php');
                $json_file = $dir . $name . '.json';
                
                $meta = [];
                if (file_exists($json_file)) {
                    $meta = json_decode(file_get_contents($json_file), true) ?: [];
                }

                $available_snippets[$name] = [
                    'name' => $name,
                    'description' => $meta['description'] ?? '',
                    'active' => $meta['active'] ?? true
                ];
            }
        }

        // Aplicar orden si se solicita
        if ($apply_order && !empty($order)) {
            $ordered_snippets = [];
            foreach ($order as $name) {
                if (isset($available_snippets[$name])) {
                    $ordered_snippets[] = $available_snippets[$name];
                    unset($available_snippets[$name]);
                }
            }
            // Agregar snippets no ordenados al final
            $snippets = array_merge($ordered_snippets, array_values($available_snippets));
        } else {
            $snippets = array_values($available_snippets);
        }

        // Guardar en caché
        self::$snippets_cache = $snippets;
        return $snippets;
    }

    /**
     * Actualiza el orden de los snippets
     */
    public static function update_snippets_order($names) {
        $order_file = SC_PATH . 'includes/snippets-order.php';

        if (!is_dir(dirname($order_file))) {
            mkdir(dirname($order_file), 0755, true);
        }

        $content = "<?php\nreturn " . var_export($names, true) . ";\n";
        $result = file_put_contents($order_file, $content, LOCK_EX);

        if ($result !== false) {
            // Invalida opcache si está habilitado
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($order_file, true);
            }
            self::invalidate_cache();
            return true;
        }
        return false;
    }

    /**
     * Obtiene los datos de un snippet específico
     * 
     * @param string $name Nombre del snippet
     * @return array|null Datos del snippet o null si no existe
     */
    public static function get_snippet($name) {
        $php_file = SC_STORAGE . "/snippets/{$name}.php";
        $js_file = SC_STORAGE . "/js/{$name}.js";
        $css_file = SC_STORAGE . "/css/{$name}.css";
        $json_file = SC_STORAGE . "/snippets/{$name}.json";

        // Verificar si existe el archivo PHP principal
        if (!file_exists($php_file)) {
            return null;
        }

        // Obtener metadatos
        $desc = '';
        $active = true;
        if (file_exists($json_file)) {
            $meta = json_decode(file_get_contents($json_file), true);
            $desc = $meta['description'] ?? '';
            $active = isset($meta['active']) ? $meta['active'] : true;
        }

        // Leer contenido de los archivos
        return [
            'name' => $name,
            'php' => file_exists($php_file) ? file_get_contents($php_file) : '',
            'js' => file_exists($js_file) ? file_get_contents($js_file) : '',
            'css' => file_exists($css_file) ? file_get_contents($css_file) : '',
            'description' => $desc,
            'active' => $active
        ];
    }

    public static function delete_snippet($name) {
        @unlink(SC_STORAGE . "/snippets/{$name}.php");
        @unlink(SC_STORAGE . "/snippets/{$name}.json");
        @unlink(SC_STORAGE . "/js/{$name}.js");
        @unlink(SC_STORAGE . "/css/{$name}.css");
    }
}
