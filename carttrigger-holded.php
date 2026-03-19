<?php

/**
 * Plugin Name:  CartTrigger – Holded
 * Plugin URI:   https://poletto.es
 * Description:  Bidirectional sync between WooCommerce products/stock and Holded ERP.
 * Version:      1.0.0
 * Author:       Poletto 1976 S.L.U.
 * Author URI:   https://poletto.es
 * License:      GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  carttrigger-holded
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CTHOLDED_VERSION', '1.0.0' );
define( 'CTHOLDED_FILE', __FILE__ );
define( 'CTHOLDED_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTHOLDED_URL', plugin_dir_url( __FILE__ ) );

require_once CTHOLDED_DIR . 'includes/class-ctholded-api.php';
require_once CTHOLDED_DIR . 'includes/class-ctholded-sync.php';
require_once CTHOLDED_DIR . 'includes/class-ctholded-admin.php';
require_once CTHOLDED_DIR . 'includes/class-ctholded-cron.php';
require_once CTHOLDED_DIR . 'includes/class-ctholded-product-meta.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function ctholded_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'ctholded_woocommerce_missing_notice' );
        return;
    }

    CTHOLDED_Admin::init();
    CTHOLDED_Sync::init();
    CTHOLDED_Cron::init();
    CTHOLDED_Product_Meta::init();
}
add_action( 'plugins_loaded', 'ctholded_init' );

function ctholded_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'CartTrigger – Holded requires WooCommerce to be active.', 'carttrigger-holded' );
    echo '</p></div>';
}

register_activation_hook( CTHOLDED_FILE, 'ctholded_activate' );
function ctholded_activate() {
    CTHOLDED_Cron::schedule();
}

register_deactivation_hook( CTHOLDED_FILE, 'ctholded_deactivate' );
function ctholded_deactivate() {
    CTHOLDED_Cron::unschedule();
}
