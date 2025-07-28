<?php
// Remove WP logo from login page

if (!defined('ABSPATH')) exit;

function custom_login_logo() {
    echo '<style type ="text/css">.login h1 a { visibility:hidden!important; }</style>';
}
add_action('login_head', 'custom_login_logo');
