<?php

class Simply_Snippet_Manager {
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
            
            $is_active = true;
            if (file_exists($json)) {
                $meta = json_decode(file_get_contents($json), true);
                if (isset($meta['active']) && $meta['active'] === false) {
                    continue;
                }
            }
            
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

    public static function save_snippet($name, $php, $js, $css, $description = '', $active = true) {
        // Crear backup del snippet existente si existe
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
        
        // Obtener metadatos actuales para preservar el estado activo si no se proporciona
        $current_active = true;
        $json_file = SC_STORAGE . "/snippets/{$name}.json";
        if (file_exists($json_file)) {
            $meta = json_decode(file_get_contents($json_file), true);
            if (isset($meta['active'])) {
                $current_active = $meta['active'];
            }
        }
        
        // Usar el estado activo proporcionado o preservar el actual
        $active_state = $active !== null ? $active : $current_active;
        
        // Guardar todos los archivos
        file_put_contents(SC_STORAGE . "/snippets/{$name}.php", $php);
        file_put_contents(SC_STORAGE . "/js/{$name}.js", $js);
        file_put_contents(SC_STORAGE . "/css/{$name}.css", $css);
        file_put_contents(SC_STORAGE . "/snippets/{$name}.json", json_encode([
            'description' => $description,
            'last_updated' => date('Y-m-d H:i:s'),
            'active' => $active_state
        ]));
    }

    public static function get_snippet($name) {
        $desc = '';
        $active = true;
        $json = SC_STORAGE . "/snippets/{$name}.json";
        if (file_exists($json)) {
            $meta = json_decode(file_get_contents($json), true);
            $desc = $meta['description'] ?? '';
            $active = isset($meta['active']) ? $meta['active'] : true;
        }
        
        return [
            'php' => @file_get_contents(SC_STORAGE . "/snippets/{$name}.php"),
            'js'  => @file_get_contents(SC_STORAGE . "/js/{$name}.js"),
            'css' => @file_get_contents(SC_STORAGE . "/css/{$name}.css"),
            'description' => $desc,
            'active' => $active,
        ];
    }

    public static function list_snippets($apply_order = true) {
        $snippets = [];
        $dir = SC_STORAGE . '/snippets/';
        if (!is_dir($dir)) return $snippets;
        
        foreach (scandir($dir) as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $name = basename($file, '.php');
                $desc = '';
                $active = true;
                $json = $dir . "{$name}.json";
                if (file_exists($json)) {
                    $meta = json_decode(file_get_contents($json), true);
                    $desc = $meta['description'] ?? '';
                    $active = isset($meta['active']) ? $meta['active'] : true;
                }
                $snippets[] = [
                    'name' => $name,
                    'description' => $desc,
                    'active' => $active,
                ];
            }
        }
        
        if ($apply_order) {
            $order_file = SC_PATH . 'includes/snippets-order.php';
            if (file_exists($order_file)) {
                $order = include $order_file;
                if (!empty($order)) {
                    usort($snippets, function($a, $b) use ($order) {
                        $posA = array_search($a['name'], $order);
                        $posB = array_search($b['name'], $order);
                        return ($posA === false ? 999 : $posA) - ($posB === false ? 999 : $posB);
                    });
                }
            }
        }
        
        return $snippets;
    }

    public static function delete_snippet($name) {
        @unlink(SC_STORAGE . "/snippets/{$name}.php");
        @unlink(SC_STORAGE . "/snippets/{$name}.json");
        @unlink(SC_STORAGE . "/js/{$name}.js");
        @unlink(SC_STORAGE . "/css/{$name}.css");
    }

    public static function toggle_snippet_status($name, $active) {
        $json_file = SC_STORAGE . "/snippets/{$name}.json";
        if (!file_exists($json_file)) return false;
        
        $meta = json_decode(file_get_contents($json_file), true) ?: [];
        $meta['active'] = $active;
        $meta['last_updated'] = date('Y-m-d H:i:s');
        
        return file_put_contents($json_file, json_encode($meta)) !== false;
    }
}