<?php
// Custom Simple Accordion
// Shortcode: [simple_accordion]
// Uso:
// [simple_accordion]
// [accordion_item title="Nextcloud TI" icon="dashicons-groups" open="true"]
// [accordion_item title="Nextcloud Solo" icon="dashicons-users"]
// [/simple_accordion]

function simple_accordion_shortcode($atts, $content = null) {
    ob_start();
    ?>
    <div class="simple-accordion">
        <?php echo do_shortcode($content); ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('simple_accordion', 'simple_accordion_shortcode');

function accordion_item_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'title' => '',
        'icon' => 'dashicons-menu',
        'open' => 'false',
    ), $atts, 'accordion_item');

    $title = $atts['title'];
    $icon = $atts['icon'];
    $is_open = ($atts['open'] === 'true' || $atts['open'] === '1');

    $header_class = $is_open ? 'accordion-header active' : 'accordion-header';
    $content_class = $is_open ? 'accordion-content active' : 'accordion-content';
    $aria_expanded = $is_open ? 'true' : 'false';

    ob_start();
    ?>
    <div class="accordion-item">
        <div class="<?php echo esc_attr($header_class); ?>" tabindex="0" role="button" aria-expanded="<?php echo esc_attr($aria_expanded); ?>">
            <div class="accordion-title-wrapper">
                <span class="dashicons <?php echo esc_attr($icon); ?> accordion-icon-left"></span>
                <span class="accordion-title"><?php echo esc_html($title); ?></span>
            </div>
            <span class="accordion-icon">+</span>
        </div>
        <div class="<?php echo esc_attr($content_class); ?>">
            <div class="accordion-body">
                <?php echo do_shortcode($content); ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('accordion_item', 'accordion_item_shortcode');
