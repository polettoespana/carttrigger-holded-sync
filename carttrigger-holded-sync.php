<?php

/**
 * Plugin Name:  CartTrigger – Holded Sync
 * Plugin URI:   https://poletto.es
 * Description:  Bidirectional sync between WooCommerce products/stock and Holded ERP.
 * Version:      1.4.4
 * Author:       Poletto 1976 S.L.U.
 * Author URI:   https://poletto.es
 * License:      GPLv2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  carttrigger-holded-sync
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'CTHLS_VERSION', '1.4.4' );
define( 'CTHLS_FILE', __FILE__ );
define( 'CTHLS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTHLS_URL', plugin_dir_url( __FILE__ ) );

require_once CTHLS_DIR . 'includes/class-cthls-api.php';
require_once CTHLS_DIR . 'includes/class-cthls-sync.php';
require_once CTHLS_DIR . 'includes/class-cthls-admin.php';
require_once CTHLS_DIR . 'includes/class-cthls-cron.php';
require_once CTHLS_DIR . 'includes/class-cthls-product-meta.php';
require_once CTHLS_DIR . 'includes/class-cthls-orders.php';

/**
 * Bootstrap the plugin after all plugins are loaded.
 */
function cthls_init() {
    load_plugin_textdomain( 'carttrigger-holded-sync', false, dirname( plugin_basename( CTHLS_FILE ) ) . '/languages' );

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'cthls_woocommerce_missing_notice' );
        return;
    }

    CTHLS_Admin::init();
    CTHLS_Sync::init();
    CTHLS_Cron::init();
    CTHLS_Product_Meta::init();
    CTHLS_Orders::init();

    // Migrate: remove any Action Scheduler actions registered under the old
    // group 'ctholded' (used before v1.0.4). Self-healing is handled in CTHLS_Cron::init().
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( CTHLS_Cron::HOOK, [], 'ctholded' );
    }
}
add_action( 'plugins_loaded', 'cthls_init', 20 );

function cthls_woocommerce_missing_notice() {
    echo '<div class="notice notice-error"><p>';
    echo esc_html__( 'CartTrigger – Holded requires WooCommerce to be active.', 'carttrigger-holded-sync' );
    echo '</p></div>';
}

register_activation_hook( CTHLS_FILE, 'cthls_activate' );
function cthls_activate() {
    // Schedule after WC/Action Scheduler are loaded.
    add_action( 'plugins_loaded', function() {
        if ( function_exists( 'as_schedule_recurring_action' ) ) {
            CTHLS_Cron::schedule();
        }
    }, 20 );
}

register_deactivation_hook( CTHLS_FILE, 'cthls_deactivate' );
function cthls_deactivate() {
    CTHLS_Cron::unschedule();
}
