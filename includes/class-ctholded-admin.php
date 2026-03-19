<?php

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page for CartTrigger – Holded.
 *
 * Settings stored as WP options:
 *   ctholded_api_key       string
 *   ctholded_sync_enabled  bool
 *   ctholded_sync_prices   bool
 *   ctholded_sync_stock    bool
 *   ctholded_sync_desc     bool
 *   ctholded_debug_log     bool
 */
class CTHOLDED_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_ctholded_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_ctholded_manual_pull', [ __CLASS__, 'ajax_manual_pull' ] );
        add_action( 'wp_ajax_ctholded_clear_log', [ __CLASS__, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_ctholded_get_warehouses', [ __CLASS__, 'ajax_get_warehouses' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__( 'Holded Sync', 'carttrigger-holded' ),
            esc_html__( 'Holded Sync', 'carttrigger-holded' ),
            'manage_woocommerce',
            'ctholded-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings() {
        $options = [
            'ctholded_api_key',
            'ctholded_warehouse_id',
            'ctholded_warehouse_name',
            'ctholded_default_tax_rate',
            'ctholded_prices_include_tax',
            'ctholded_sync_enabled',
            'ctholded_sync_prices',
            'ctholded_sync_stock',
            'ctholded_sync_desc',
            'ctholded_debug_log',
        ];
        $text_options = [ 'ctholded_api_key', 'ctholded_warehouse_id', 'ctholded_warehouse_name', 'ctholded_default_tax_rate', 'ctholded_prices_include_tax' ];
        foreach ( $options as $option ) {
            register_setting( 'ctholded_settings_group', $option, [
                'sanitize_callback' => in_array( $option, $text_options, true ) ? 'sanitize_text_field' : 'rest_sanitize_boolean',
            ] );
        }
    }

    public static function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_ctholded-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'ctholded-admin',
            CTHOLDED_URL . 'assets/css/ctholded-admin.css',
            [],
            CTHOLDED_VERSION
        );
        wp_enqueue_script(
            'ctholded-admin',
            CTHOLDED_URL . 'assets/js/ctholded-admin.js',
            [ 'jquery' ],
            CTHOLDED_VERSION,
            true
        );
        wp_localize_script( 'ctholded-admin', 'ctholded', [
            'nonce'         => wp_create_nonce( 'ctholded_admin' ),
            'i18n_testing'  => esc_html__( 'Testing…', 'carttrigger-holded' ),
            'i18n_pulling'  => esc_html__( 'Pulling from Holded…', 'carttrigger-holded' ),
            'i18n_success'  => esc_html__( 'Success', 'carttrigger-holded' ),
            'i18n_error'    => esc_html__( 'Error', 'carttrigger-holded' ),
        ] );
    }

    // ── AJAX handlers ────────────────────────────────────────────────────────

    public static function ajax_test_connection() {
        check_ajax_referer( 'ctholded_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded' ) ] );
        }

        // Use the key from the form (not yet saved) if provided.
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( $api_key ) {
            update_option( 'ctholded_api_key', $api_key );
        }
        $api    = new CTHOLDED_API();
        $result = $api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => __( 'Connection successful.', 'carttrigger-holded' ) ] );
    }

    public static function ajax_manual_pull() {
        check_ajax_referer( 'ctholded_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded' ) ] );
        }

        CTHOLDED_Sync::pull_from_holded();
        $last = get_option( 'ctholded_last_pull', '' );
        wp_send_json_success( [ 'message' => __( 'Pull completed.', 'carttrigger-holded' ), 'last_pull' => $last ] );
    }

    public static function ajax_get_warehouses() {
        check_ajax_referer( 'ctholded_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }
        $api    = new CTHOLDED_API();
        $result = $api->get_warehouses();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_clear_log() {
        check_ajax_referer( 'ctholded_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }
        delete_option( 'ctholded_log' );
        wp_send_json_success();
    }

    // ── Settings page ────────────────────────────────────────────────────────

    public static function render_page() {
        $last_pull = get_option( 'ctholded_last_pull', '' );
        $log       = get_option( 'ctholded_log', [] );
        ?>
        <div class="wrap ctholded-wrap">

            <div class="ctholded-header">
                <h1><?php esc_html_e( 'CartTrigger – Holded', 'carttrigger-holded' ); ?></h1>
                <span class="ctholded-version">v<?php echo esc_html( CTHOLDED_VERSION ); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'ctholded_settings_group' ); ?>

                <!-- ── API ── -->
                <div class="ctholded-card">
                    <h2><?php esc_html_e( 'Connection', 'carttrigger-holded' ); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Holded API Key', 'carttrigger-holded' ); ?></th>
                            <td>
                                <input type="password"
                                    name="ctholded_api_key"
                                    id="ctholded_api_key"
                                    value="<?php echo esc_attr( get_option( 'ctholded_api_key' ) ); ?>"
                                    class="regular-text"
                                    autocomplete="off" />
                                <button type="button" id="ctholded-test-btn" class="button button-secondary">
                                    <?php esc_html_e( 'Test connection', 'carttrigger-holded' ); ?>
                                </button>
                                <span id="ctholded-test-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Warehouse', 'carttrigger-holded' ); ?></th>
                            <td>
                                <select name="ctholded_warehouse_id" id="ctholded_warehouse_id">
                                    <option value=""><?php esc_html_e( '— Load warehouses —', 'carttrigger-holded' ); ?></option>
                                    <?php
                                    $saved_wh      = get_option( 'ctholded_warehouse_id', '' );
                                    $saved_wh_name = get_option( 'ctholded_warehouse_name', '' );
                                    if ( $saved_wh ) :
                                        $label = $saved_wh_name ?: $saved_wh;
                                        echo '<option value="' . esc_attr( $saved_wh ) . '" selected>' . esc_html( $label ) . '</option>';
                                    endif;
                                    ?>
                                </select>
                                <input type="hidden" name="ctholded_warehouse_name" id="ctholded_warehouse_name" value="<?php echo esc_attr( $saved_wh_name ); ?>" />
                                <button type="button" id="ctholded-load-warehouses" class="button button-secondary">
                                    <?php esc_html_e( 'Load warehouses', 'carttrigger-holded' ); ?>
                                </button>
                                <span id="ctholded-warehouses-result"></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Default tax rate (%)', 'carttrigger-holded' ); ?></th>
                            <td>
                                <input type="number" name="ctholded_default_tax_rate" value="<?php echo esc_attr( get_option( 'ctholded_default_tax_rate', 21 ) ); ?>" min="0" max="100" step="0.01" style="width:80px" />
                                <p class="description"><?php esc_html_e( 'Fallback tax rate if WooCommerce cannot determine it automatically. Default: 21 (Spain standard VAT).', 'carttrigger-holded' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Sync options ── -->
                <div class="ctholded-card">
                    <h2><?php esc_html_e( 'Sync settings', 'carttrigger-holded' ); ?></h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable sync', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_sync_enabled" value="1"
                                        <?php checked( get_option( 'ctholded_sync_enabled' ) ); ?> />
                                    <?php esc_html_e( 'Activate bidirectional sync', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'WooCommerce prices include tax', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_prices_include_tax" value="yes"
                                        <?php checked( get_option( 'ctholded_prices_include_tax', get_option( 'woocommerce_prices_include_tax', 'no' ) ), 'yes' ); ?> />
                                    <?php esc_html_e( 'Prices entered in WooCommerce are tax-inclusive — strip tax before sending to Holded', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync stock', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_sync_stock" value="1"
                                        <?php checked( get_option( 'ctholded_sync_stock', true ) ); ?> />
                                    <?php esc_html_e( 'Sync stock quantity (both directions)', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync prices', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_sync_prices" value="1"
                                        <?php checked( get_option( 'ctholded_sync_prices', true ) ); ?> />
                                    <?php esc_html_e( 'Sync regular price (both directions)', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync description', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_sync_desc" value="1"
                                        <?php checked( get_option( 'ctholded_sync_desc', false ) ); ?> />
                                    <?php esc_html_e( 'Sync product description (both directions)', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Debug log', 'carttrigger-holded' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="ctholded_debug_log" value="1"
                                        <?php checked( get_option( 'ctholded_debug_log' ) ); ?> />
                                    <?php esc_html_e( 'Log sync errors (last 100)', 'carttrigger-holded' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <!-- ── Manual pull ── -->
            <div class="ctholded-card">
                <h2><?php esc_html_e( 'Manual sync', 'carttrigger-holded' ); ?></h2>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: last pull datetime */
                        esc_html__( 'Pull all products from Holded and update WooCommerce. Last pull: %s', 'carttrigger-holded' ),
                        $last_pull ? esc_html( $last_pull ) : esc_html__( 'never', 'carttrigger-holded' )
                    );
                    ?>
                </p>
                <button type="button" id="ctholded-pull-btn" class="button button-secondary">
                    <?php esc_html_e( 'Pull from Holded now', 'carttrigger-holded' ); ?>
                </button>
                <span id="ctholded-pull-result"></span>
            </div>

            <!-- ── Log ── -->
            <?php if ( get_option( 'ctholded_debug_log' ) && ! empty( $log ) ) : ?>
            <div class="ctholded-card">
                <h2>
                    <?php esc_html_e( 'Error log', 'carttrigger-holded' ); ?>
                    <button type="button" id="ctholded-clear-log" class="button button-link ctholded-clear-log">
                        <?php esc_html_e( 'Clear', 'carttrigger-holded' ); ?>
                    </button>
                </h2>
                <table class="widefat striped ctholded-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'carttrigger-holded' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'carttrigger-holded' ); ?></th>
                            <th><?php esc_html_e( 'Product ID', 'carttrigger-holded' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'carttrigger-holded' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html( $entry['time'] ); ?></td>
                            <td><?php echo esc_html( $entry['event'] ); ?></td>
                            <td><?php echo esc_html( $entry['product_id'] ); ?></td>
                            <td><?php echo esc_html( $entry['message'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }
}
