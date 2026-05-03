<?php
/**
 * Plugin Name: Zen-BookPro
 * Description: Extension layer foundation for WooCommerce Bookings with static UI and shortcode rendering.
 * Version: 1.0.0
 * Author: Zenctuary
 * Text Domain: zen-bookpro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ZBP_VERSION', '1.0.0' );
define( 'ZBP_PLUGIN_FILE', __FILE__ );
define( 'ZBP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-loader.php';
require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-shortcode.php';

function zbp_init_plugin() {
    $loader = new ZBP_Loader();
    $loader->run();
}
add_action( 'plugins_loaded', 'zbp_init_plugin' );
