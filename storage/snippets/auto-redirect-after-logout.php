<?php
// Redirect to Homepage after logout

if (!defined('ABSPATH')) exit;

function auto_redirect_after_logout(){
    $home_url = home_url();
    wp_safe_redirect( $home_url );
    exit;
}
add_action('wp_logout','auto_redirect_after_logout');

