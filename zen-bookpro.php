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

define( 'ZBP_VERSION', '1.0.1' );
define( 'ZBP_PLUGIN_FILE', __FILE__ );
define( 'ZBP_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ZBP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-loader.php';
require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-shortcode.php';
require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-admin.php';
require_once ZBP_PLUGIN_PATH . 'includes/class-zbp-product-mode.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-slot-service.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-product-service.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-cancellation-service.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-booking-service.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-email-service.php';
require_once ZBP_PLUGIN_PATH . 'includes/services/class-zbp-waitlist-service.php';

/**
 * Check plugin dependencies for WooCommerce and WooCommerce Bookings.
 *
 * @return bool
 */
function zbp_dependencies_met() {
    return class_exists( 'WooCommerce' ) && class_exists( 'WC_Bookings' );
}

/**
 * Render admin notice when dependencies are missing.
 *
 * @return void
 */
function zbp_dependencies_admin_notice() {
    if ( zbp_dependencies_met() ) {
        return;
    }

    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'Zen-BookPro requires both WooCommerce and WooCommerce Bookings to be active.', 'zen-bookpro' );
    echo '</p></div>';
}

function zbp_init_plugin() {
    if ( ! zbp_dependencies_met() ) {
        if ( is_admin() ) {
            add_action( 'admin_notices', 'zbp_dependencies_admin_notice' );
        }
    }

    $loader = new ZBP_Loader();
    $loader->run();
}
add_action( 'plugins_loaded', 'zbp_init_plugin' );
