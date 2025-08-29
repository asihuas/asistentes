<?php
/*
Plugin Name: AM Assistants (Chat + History + Analytics)
Description: Multi-agent chat with per-user conversation history, analytics, quick suggestions and per-message feedback.
Author: GrowthMind / Angello Sihuas
Version: 1.2.4
*/
if (!defined('ABSPATH')) exit;

define('AM_CA_VERSION', '1.2.4');
define('AM_CA_PLUGIN_FILE', __FILE__);
define('AM_CA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AM_CA_PLUGIN_URL', plugin_dir_url(__FILE__));

add_action('plugins_loaded', function () {
  $files = [
    'includes/constants.php',
    'includes/helpers.php',
    'includes/cpt-agent.php',
    'includes/db.php',
    'includes/openai.php',
    'includes/moderation.php',
    'includes/admin.php',
    'includes/analytics.php',
    'includes/rest.php',
    'includes/shortcodes.php',
  ];
  foreach ($files as $f) {
    $abs = AM_CA_PLUGIN_DIR . $f;
    if (file_exists($abs)) require_once $abs;
  }
}, 1);

register_activation_hook(__FILE__, function(){
  require_once AM_CA_PLUGIN_DIR.'includes/db.php';
  if (function_exists('am_install_tables')) am_install_tables();
});
