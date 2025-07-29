<?php
// Custom Simple Accordion
// Shortcode: [simple_accordion]
// Uso:
// [simple_accordion default_icon="dashicons-cloud"]
// [accordion_item title="Planos TI" icon="dashicons-admin-multisite" open="true"] [generate_pricing_tables tipo="ti"] [/accordion_item]
// [accordion_item title="Planos Solo"] [generate_pricing_tables tipo="solo"] [/accordion_item]
// [/simple_accordion]

function simple_accordion_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'default_icon' => 'dashicons-menu', // Icono por defecto si no se especifica
    ), $atts);

    // Permite shortcodes anidados
    $content = do_shortcode($content);

    // Extrae los items del accordion
    preg_match_all('/\[accordion_item([^\]]*)\](.*?)\[\/accordion_item\]/is', $content, $matches, PREG_SET_ORDER);

    ob_start();
    ?>
    <div class="simple-accordion">
        <?php foreach ($matches as $i => $item): 
            // Extrae los atributos del item
            preg_match('/title="([^"]*)"/', $item[1], $title_match);
            preg_match('/icon="([^"]*)"/', $item[1], $icon_match);
            preg_match('/open="([^"]*)"/', $item[1], $open_match);
            
            $title = isset($title_match[1]) ? $title_match[1] : 'Item '.($i+1);
            $icon = isset($icon_match[1]) ? $icon_match[1] : $atts['default_icon'];
            $is_open = isset($open_match[1]) && ($open_match[1] === 'true' || $open_match[1] === '1');
            
            // Clases CSS para el estado inicial
            $header_class = $is_open ? 'accordion-header active' : 'accordion-header';
            $content_class = $is_open ? 'accordion-content active' : 'accordion-content';
            $aria_expanded = $is_open ? 'true' : 'false';
        ?>
            <div class="accordion-item">
                <div class="<?php echo $header_class; ?>" tabindex="0" role="button" aria-expanded="<?php echo $aria_expanded; ?>">
                    <div class="accordion-title-wrapper">
                        <span class="dashicons <?php echo esc_attr($icon); ?> accordion-icon-left"></span>
                        <span class="accordion-title"><?php echo esc_html($title); ?></span>
                    </div>
                    <span class="accordion-icon">+</span>
                </div>
                <div class="<?php echo $content_class; ?>">
                    <div class="accordion-body">
                        <?php echo do_shortcode($item[2]); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('simple_accordion', 'simple_accordion_shortcode');
