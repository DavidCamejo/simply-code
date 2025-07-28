<?php
class Simply_Snippet_Manager {
    private static $order_cache = null;
    private static $snippets_cache = null;
    private static $cache_version = '1.0';

    /**
     * Get snippets order with improved caching
     */
    private static function get_order() {
        if (self::$order_cache !== null) {
            return self::$order_cache;
        }

        $order_file = SC_PATH . 'includes/snippets-order.php';
        if (!file_exists($order_file)) {
            self::$order_cache = [];
            return [];
        }

        // Use WordPress transients for better caching
        $cache_key = 'simply_code_order_' . md5_file($order_file);
        $order = get_transient($cache_key);

        if ($order === false) {
            $content = file_get_contents($order_file);
            $order = [];
            if (preg_match('/return\s+(.+);/s', $content, $matches)) {
                $order = eval('return ' . $matches[1] . ';');
            }
            set_transient($cache_key, $order, HOUR_IN_SECONDS);
        }

        self::$order_cache = $order;
        return $order;
    }

    /**
     * Improved cache invalidation - CORREGIDO
     */
    private static function invalidate_cache() {
        self::$order_cache = null;
        self::$snippets_cache = null;

        // Clear WordPress transients with proper SQL query
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_simply_code_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_simply_code_%'");

        clearstatcache();

        // Clear opcache if available
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Save snippet with proper error handling and return value - MÉTODO FALTANTE
     */
    public static function save_snippet($name, $php, $js, $css, $description = '', $active = true) {
        try {
            // Crear directorios si no existen
            $directories = [
                SC_STORAGE . '/snippets/',
                SC_STORAGE . '/js/',
                SC_STORAGE . '/css/',
                SC_STORAGE . '/backups/'
            ];

            foreach ($directories as $dir) {
                if (!is_dir($dir)) {
                    if (!mkdir($dir, 0755, true)) {
                        error_log("Simply Code: Failed to create directory: {$dir}");
                        return false;
                    }
                }
            }

            $php_file = SC_STORAGE . "/snippets/{$name}.php";
            $backup_dir = SC_STORAGE . "/backups/";

            // Crear backup si existe el archivo original
            if (file_exists($php_file)) {
                $backup_file = $backup_dir . $name . '.php.' . time();
                if (!copy($php_file, $backup_file)) {
                    error_log("Simply Code: Failed to create backup for {$name}");
                }
            }

            // Guardar todos los archivos con verificación de errores
            $files_to_save = [
                SC_STORAGE . "/snippets/{$name}.php" => $php,
                SC_STORAGE . "/js/{$name}.js" => $js,
                SC_STORAGE . "/css/{$name}.css" => $css,
                SC_STORAGE . "/snippets/{$name}.json" => json_encode([
                    'description' => $description,
                    'last_updated' => date('Y-m-d H:i:s'),
                    'active' => $active
                ], JSON_PRETTY_PRINT)
            ];

            $all_success = true;
            foreach ($files_to_save as $file_path => $content) {
                $result = file_put_contents($file_path, $content, LOCK_EX);
                if ($result === false) {
                    error_log("Simply Code: Failed to write file: {$file_path}");
                    $all_success = false;
                }
            }

            if ($all_success) {
                self::invalidate_cache();
                return true;
            }

            return false;

        } catch (Exception $e) {
            error_log("Simply Code: Error saving snippet {$name} - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Optimized snippet loading with error handling
     */
    public static function load_snippets() {
        $dir = SC_STORAGE . '/snippets/';
        if (!is_dir($dir)) return;

        $order = self::get_order();
        $all_snippets = self::get_available_snippets();
        $ordered = array_unique(array_merge($order, $all_snippets));
        $safe_mode = get_option(Simply_Code_Admin::OPTION_SAFE_MODE, 'on') === 'on';

        foreach ($ordered as $basename) {
            if (!self::load_single_snippet($basename, $safe_mode)) {
                error_log("Simply Code: Failed to load snippet: {$basename}");
            }
        }
    }

    /**
     * Load a single snippet with proper error handling
     */
    private static function load_single_snippet($basename, $safe_mode = true) {
        $php_file = SC_STORAGE . "/snippets/{$basename}.php";
        $json_file = SC_STORAGE . "/snippets/{$basename}.json";

        // Check if snippet is active
        if (!self::is_snippet_active($json_file)) {
            return false;
        }

        if (!file_exists($php_file)) {
            return false;
        }

        try {
            if ($safe_mode) {
                $snippet_code = file_get_contents($php_file);
                $syntax_result = Simply_Syntax_Checker::validate_php($snippet_code);

                if (!$syntax_result['valid']) {
                    error_log("Simply Code: Syntax error in {$php_file} - " . $syntax_result['message']);
                    return false;
                }
            }

            require_once $php_file;
            return true;

        } catch (Throwable $e) {
            error_log("Simply Code: Error loading {$php_file} - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if snippet is active
     */
    private static function is_snippet_active($json_file) {
        if (!file_exists($json_file)) {
            return true; // Default to active if no metadata
        }

        $meta = json_decode(file_get_contents($json_file), true);
        return !isset($meta['active']) || $meta['active'] === true;
    }

    /**
     * Get available snippets efficiently
     */
    private static function get_available_snippets() {
        $dir = SC_STORAGE . '/snippets/';
        $snippets = [];

        $files = glob($dir . '*.php');
        foreach ($files as $file) {
            $snippets[] = basename($file, '.php');
        }

        return $snippets;
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

    /**
     * Toggle snippet status - CORREGIDO
     */
    public static function toggle_snippet_status($name, $active) {
        $json_file = SC_STORAGE . "/snippets/{$name}.json";
        $snippet_data = [];

        // Clear filesystem cache
        clearstatcache(true, $json_file);

        // Get existing data
        if (file_exists($json_file)) {
            $content = file_get_contents($json_file);
            $snippet_data = json_decode($content, true) ?: [];
        }

        // Update status
        $snippet_data['active'] = (bool) $active;
        $snippet_data['last_updated'] = date('Y-m-d H:i:s');

        // Force immediate write with JSON_PRETTY_PRINT
        $success = file_put_contents($json_file, json_encode($snippet_data, JSON_PRETTY_PRINT), LOCK_EX);

        if ($success) {
            // Clear cache after writing
            clearstatcache(true, $json_file);

            // Verify the content was written correctly
            $verify_content = file_get_contents($json_file);
            $verify_data = json_decode($verify_content, true);

            if (!$verify_data || $verify_data['active'] !== (bool) $active) {
                error_log("Simply Code: Status toggle verification failed for {$name}");
                return false;
            }

            self::invalidate_cache();
        }

        return $success !== false;
    }

    /**
     * Lista todos los snippets con caching mejorado
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
                    $content = file_get_contents($json_file);
                    $meta = json_decode($content, true) ?: [];
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
     * Actualiza el orden de los snippets con mejor manejo de errores
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
            $content = file_get_contents($json_file);
            $meta = json_decode($content, true) ?: [];
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

    /**
     * Delete snippet with improved cleanup
     */
    public static function delete_snippet($name) {
        $files_to_delete = [
            SC_STORAGE . "/snippets/{$name}.php",
            SC_STORAGE . "/snippets/{$name}.json",
            SC_STORAGE . "/js/{$name}.js",
            SC_STORAGE . "/css/{$name}.css"
        ];

        $success = true;
        foreach ($files_to_delete as $file) {
            if (file_exists($file) && !unlink($file)) {
                $success = false;
                error_log("Simply Code: Failed to delete file: {$file}");
            }
        }

        if ($success) {
            self::invalidate_cache();
        }

        return $success;
    }
}
