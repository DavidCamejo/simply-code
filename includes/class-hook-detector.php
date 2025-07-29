<?php
class Simply_Hook_Detector {
    
    /**
     * Detectar hooks y sus prioridades existentes en código PHP
     */
    public static function detect_hooks($php_code) {
        $hooks = [];
        
        // Regex para add_action/add_filter con prioridades opcionales
        $pattern = '/add_(action|filter)\s*\(\s*[\'"]([^\'"]+)[\'"][\s,]*([^,)]+)?[\s,]*(\d+)?[\s,]*(\d+)?\s*\)/i';
        
        if (preg_match_all($pattern, $php_code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtolower($match[1]);
                $hook_name = $match[2];
                $priority = isset($match[4]) && is_numeric($match[4]) ? (int)$match[4] : 10;
                $accepted_args = isset($match[5]) && is_numeric($match[5]) ? (int)$match[5] : 1;
                
                $hooks[] = [
                    'name' => $hook_name,
                    'type' => $type,
                    'priority' => $priority,
                    'accepted_args' => $accepted_args,
                    'auto_detected' => true
                ];
            }
        }
        
        return $hooks;
    }
    
    /**
     * Obtener hooks críticos que necesitan prioridades específicas
     */
    public static function get_critical_hooks() {
        return [
            'init' => ['default_priority' => 10, 'description' => 'Inicialización temprana'],
            'wp_loaded' => ['default_priority' => 10, 'description' => 'WordPress completamente cargado'],
            'template_redirect' => ['default_priority' => 10, 'description' => 'Antes de cargar template'],
            'wp_head' => ['default_priority' => 10, 'description' => 'En el head del HTML'],
            'wp_footer' => ['default_priority' => 10, 'description' => 'En el footer del HTML'],
            'wp_enqueue_scripts' => ['default_priority' => 10, 'description' => 'Encolar scripts frontend'],
            'admin_enqueue_scripts' => ['default_priority' => 10, 'description' => 'Encolar scripts admin'],
            'the_content' => ['default_priority' => 10, 'description' => 'Filtrar contenido'],
            'wp_title' => ['default_priority' => 10, 'description' => 'Filtrar título']
        ];
    }
    
    /**
     * Calcular prioridad de carga del snippet
     */
    public static function calculate_load_priority($hooks) {
        if (empty($hooks)) return 10;
        
        $critical_hooks = ['init', 'after_setup_theme', 'wp_loaded'];
        $min_priority = 999;
        
        foreach ($hooks as $hook_data) {
            if (in_array($hook_data['name'], $critical_hooks)) {
                $min_priority = min($min_priority, $hook_data['priority']);
            }
        }
        
        return $min_priority === 999 ? 10 : $min_priority;
    }
}
