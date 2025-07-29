<?php
// Enqueue scripts to WP
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style( 'dashicons' );
    if (is_page(pmpro_getOption('checkout_page_slug'))) {
        global $pmpro_level;
        wp_localize_script(
            'simply-snippet-pmpro-dynamic-pricing', // El handle debe coincidir con el que usa Simply Code
            'nextcloud_pricing',
            [
                'level_id' => $pmpro_level->id ?? 1,
                'base_price' => $pmpro_level->initial_payment ?? 0,
                'currency_symbol' => 'R$'
            ]
        );
    }
});
