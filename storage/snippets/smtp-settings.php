<?php
// Configure SMTP settings

if (!defined('ABSPATH')) exit;

function setup_phpmailer_init( $phpmailer ) {
    $phpmailer->IsSMTP();
    $phpmailer->Host = 'smtp.smtpexample.com';
    $phpmailer->Port = 587;
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = 'yourname@yoursite.com';
    $phpmailer->Password = 'XXXXXXXXXXXXXXXX';
    $phpmailer->From = 'yourweb@yoursite.com';
    $phpmailer->FromName = 'Your Web';
}
add_action( 'phpmailer_init', 'setup_phpmailer_init' );
