<?php
// Configure SMTP settings

if (!defined('ABSPATH')) exit;
function setup_phpmailer_init( $phpmailer ) {
    $phpmailer->IsSMTP();
    $phpmailer->Host = 'smtp-relay.sendinblue.com';
    $phpmailer->Port = 587;
    $phpmailer->SMTPSecure = 'tls';
    $phpmailer->SMTPAuth = true;
    $phpmailer->Username = 'jdavidcamejo@gmail.com';
    $phpmailer->Password = 'Ij8DcFwLxmZM6ytX';
    $phpmailer->From = 'web@brasdrive.com.br';
    $phpmailer->FromName = 'Brasdrive';
}
add_action( 'phpmailer_init', 'setup_phpmailer_init' );
