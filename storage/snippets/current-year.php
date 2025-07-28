<?php
// Current year shortcode

if (!defined('ABSPATH')) exit;

function current_year() {
    return date('Y');
}
add_shortcode( 'year', 'current_year' );
