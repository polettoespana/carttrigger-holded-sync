<?php

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page for CartTrigger – Holded.
 *
 * Settings stored as WP options:
 *   cthls_api_key       string
 *   cthls_sync_enabled  bool
 *   cthls_sync_prices   bool
 *   cthls_sync_stock    bool
 *   cthls_sync_desc     bool
 *   cthls_debug_log     bool
 */
class CTHLS_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cthls_test_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_cthls_manual_pull', [ __CLASS__, 'ajax_manual_pull' ] );
        add_action( 'wp_ajax_cthls_clear_log', [ __CLASS__, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_cthls_get_warehouses', [ __CLASS__, 'ajax_get_warehouses' ] );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__( 'CartTrigger – Holded Sync', 'carttrigger-holded-sync' ),
            esc_html__( 'Holded Sync', 'carttrigger-holded-sync' ),
            'manage_woocommerce',
            'cthls-settings',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function register_settings() {
        $options = [
            'cthls_api_key',
            'cthls_warehouse_id',
            'cthls_warehouse_name',
            'cthls_default_tax_rate',
            'cthls_prices_include_tax',
            'cthls_pull_interval',
            'cthls_sync_enabled',
            'cthls_sync_prices',
            'cthls_sync_stock',
            'cthls_sync_desc',
            'cthls_desc_source',
            'cthls_append_brand',
            'cthls_debug_log',
        ];
        $text_options = [ 'cthls_api_key', 'cthls_warehouse_id', 'cthls_warehouse_name', 'cthls_default_tax_rate', 'cthls_prices_include_tax', 'cthls_pull_interval', 'cthls_desc_source' ];

        // Reschedule Action Scheduler when interval changes.
        add_action( 'update_option_cthls_pull_interval', function( $old, $new ) {
            CTHLS_Cron::schedule( (int) $new );
        }, 10, 2 );
        foreach ( $options as $option ) {
            register_setting( 'cthls_settings_group', $option, [
                'sanitize_callback' => in_array( $option, $text_options, true ) ? 'sanitize_text_field' : 'rest_sanitize_boolean',
            ] );
        }
    }

    public static function enqueue_assets( $hook ) {
        if ( 'woocommerce_page_cthls-settings' !== $hook ) {
            return;
        }
        wp_enqueue_style(
            'cthls-admin',
            CTHLS_URL . 'assets/css/cthls-admin.css',
            [],
            CTHLS_VERSION
        );
        wp_enqueue_script(
            'cthls-admin',
            CTHLS_URL . 'assets/js/cthls-admin.js',
            [ 'jquery' ],
            CTHLS_VERSION,
            true
        );
        wp_localize_script( 'cthls-admin', 'cthls', [
            'nonce'                 => wp_create_nonce( 'cthls_admin' ),
            'i18n_testing'         => esc_html__( 'Testing…', 'carttrigger-holded-sync' ),
            'i18n_pulling'         => esc_html__( 'Pulling from Holded…', 'carttrigger-holded-sync' ),
            'i18n_loading'         => esc_html__( 'Loading…', 'carttrigger-holded-sync' ),
            'i18n_success'         => esc_html__( 'Success', 'carttrigger-holded-sync' ),
            'i18n_error'           => esc_html__( 'Error', 'carttrigger-holded-sync' ),
            'i18n_select_warehouse' => esc_html__( '— Select warehouse —', 'carttrigger-holded-sync' ),
            'i18n_no_warehouses'   => esc_html__( 'No warehouses found.', 'carttrigger-holded-sync' ),
        ] );
    }

    // ── AJAX handlers ────────────────────────────────────────────────────────

    public static function ajax_test_connection() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }

        // Use the key from the form (not yet saved) if provided.
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( $api_key ) {
            update_option( 'cthls_api_key', $api_key );
        }
        $api    = new CTHLS_API();
        $result = $api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => __( 'Connection successful.', 'carttrigger-holded-sync' ) ] );
    }

    public static function ajax_manual_pull() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }

        CTHLS_Sync::pull_from_holded( 'manual' );
        $last = get_option( 'cthls_last_pull', '' );
        wp_send_json_success( [ 'message' => __( 'Pull completed.', 'carttrigger-holded-sync' ), 'last_pull' => $last ] );
    }

    public static function ajax_get_warehouses() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }
        $api    = new CTHLS_API();
        $result = $api->get_warehouses();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( $result );
    }

    public static function ajax_clear_log() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }
        delete_option( 'cthls_log' );
        wp_send_json_success();
    }

    // ── Settings page ────────────────────────────────────────────────────────

    public static function render_page() {
        $last_pull = get_option( 'cthls_last_pull', '' );
        $log       = get_option( 'cthls_log', [] );
        ?>
        <div class="wrap cthls-wrap">

            <div class="cthls-header">
                <h1><?php esc_html_e( 'CartTrigger – Holded Sync', 'carttrigger-holded-sync' ); ?></h1>
                <span class="cthls-version">v<?php echo esc_html( CTHLS_VERSION ); ?></span>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'cthls_settings_group' ); ?>

                <!-- ── Connection ── -->
                <div class="cthls-card">
                    <h2>
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e( 'Connection', 'carttrigger-holded-sync' ); ?>
                    </h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Holded API Key', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <div class="cthls-field-group">
                                    <input type="password"
                                        name="cthls_api_key"
                                        id="cthls_api_key"
                                        value="<?php echo esc_attr( get_option( 'cthls_api_key' ) ); ?>"
                                        class="regular-text"
                                        autocomplete="off" />
                                    <button type="button" id="cthls-test-btn" class="button button-secondary">
                                        <span class="dashicons dashicons-superhero" style="margin-top:3px;margin-right:3px;font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Test connection', 'carttrigger-holded-sync' ); ?>
                                    </button>
                                    <span id="cthls-test-result"></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Warehouse', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <div class="cthls-field-group">
                                    <?php
                                    $saved_wh      = get_option( 'cthls_warehouse_id', '' );
                                    $saved_wh_name = get_option( 'cthls_warehouse_name', '' );
                                    ?>
                                    <select name="cthls_warehouse_id" id="cthls_warehouse_id">
                                        <option value=""><?php esc_html_e( '— Load warehouses —', 'carttrigger-holded-sync' ); ?></option>
                                        <?php if ( $saved_wh ) : ?>
                                            <option value="<?php echo esc_attr( $saved_wh ); ?>" selected>
                                                <?php echo esc_html( $saved_wh_name ?: $saved_wh ); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                    <input type="hidden" name="cthls_warehouse_name" id="cthls_warehouse_name" value="<?php echo esc_attr( $saved_wh_name ); ?>" />
                                    <button type="button" id="cthls-load-warehouses" class="button button-secondary">
                                        <span class="dashicons dashicons-update" style="margin-top:3px;margin-right:3px;font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Load warehouses', 'carttrigger-holded-sync' ); ?>
                                    </button>
                                    <span id="cthls-warehouses-result"></span>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Default tax rate (%)', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <input type="number" name="cthls_default_tax_rate"
                                    value="<?php echo esc_attr( get_option( 'cthls_default_tax_rate', 21 ) ); ?>"
                                    min="0" max="100" step="0.01" style="width:80px" />
                                <p class="description"><?php esc_html_e( 'Fallback tax rate if WooCommerce cannot determine it automatically. Default: 21 (Spain — standard VAT).', 'carttrigger-holded-sync' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Sync settings ── -->
                <div class="cthls-card">
                    <h2>
                        <span class="dashicons dashicons-controls-repeat"></span>
                        <?php esc_html_e( 'Sync settings', 'carttrigger-holded-sync' ); ?>
                    </h2>

                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Enable sync', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_sync_enabled" value="1"
                                        <?php checked( get_option( 'cthls_sync_enabled' ) ); ?> />
                                    <?php esc_html_e( 'Activate bidirectional sync (WooCommerce ↔ Holded)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Pull interval', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <div class="cthls-field-group">
                                    <input type="number" name="cthls_pull_interval"
                                        value="<?php echo esc_attr( get_option( 'cthls_pull_interval', 15 ) ); ?>"
                                        min="5" max="1440" step="1" style="width:80px" />
                                    <span style="font-size:13px;color:#50575e;"><?php esc_html_e( 'minutes', 'carttrigger-holded-sync' ); ?></span>
                                </div>
                                <p class="description"><?php esc_html_e( 'How often to pull changes from Holded into WooCommerce. Minimum: 5 min. Default: 15.', 'carttrigger-holded-sync' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Prices include tax', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_prices_include_tax" value="yes"
                                        <?php checked( get_option( 'cthls_prices_include_tax', get_option( 'woocommerce_prices_include_tax', 'no' ) ), 'yes' ); ?> />
                                    <?php esc_html_e( 'Prices in WooCommerce are tax-inclusive — strip tax before sending to Holded', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync stock', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_sync_stock" value="1"
                                        <?php checked( get_option( 'cthls_sync_stock', true ) ); ?> />
                                    <?php esc_html_e( 'Sync stock quantity (both directions)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync prices', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_sync_prices" value="1"
                                        <?php checked( get_option( 'cthls_sync_prices', true ) ); ?> />
                                    <?php esc_html_e( 'Sync regular price (both directions)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Sync description', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_sync_desc" value="1"
                                        <?php checked( get_option( 'cthls_sync_desc', false ) ); ?> />
                                    <?php esc_html_e( 'Sync product description (both directions)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Description source', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <?php $desc_source = get_option( 'cthls_desc_source', 'custom' ); ?>
                                <select name="cthls_desc_source">
                                    <option value="custom" <?php selected( $desc_source, 'custom' ); ?>>
                                        <?php esc_html_e( 'Custom field (Holded Sync tab)', 'carttrigger-holded-sync' ); ?>
                                    </option>
                                    <option value="full" <?php selected( $desc_source, 'full' ); ?>>
                                        <?php esc_html_e( 'Full product description (WooCommerce)', 'carttrigger-holded-sync' ); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Choose which description to send to Holded when "Sync description" is enabled.', 'carttrigger-holded-sync' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Append brand to name', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_append_brand" value="1"
                                        <?php checked( get_option( 'cthls_append_brand', false ) ); ?> />
                                    <?php esc_html_e( 'Append the product brand (product_brand taxonomy) to the product name when syncing to Holded', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Enable log', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_debug_log" value="1"
                                        <?php checked( get_option( 'cthls_debug_log' ) ); ?> />
                                    <?php esc_html_e( 'Enable log (last 100 messages)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Save settings', 'carttrigger-holded-sync' ) ); ?>
            </form>

            <!-- ── Manual pull ── -->
            <div class="cthls-card">
                <h2>
                    <span class="dashicons dashicons-download"></span>
                    <?php esc_html_e( 'Manual sync', 'carttrigger-holded-sync' ); ?>
                </h2>
                <div class="cthls-sync-row">
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: last pull datetime */
                            esc_html__( 'Pull all products from Holded and update WooCommerce. Last pull: %s', 'carttrigger-holded-sync' ),
                            $last_pull ? esc_html( $last_pull ) : esc_html__( 'never', 'carttrigger-holded-sync' )
                        );

                        $next_ts = function_exists( 'as_next_scheduled_action' )
                            ? as_next_scheduled_action( CTHLS_Cron::HOOK )
                            : false;

                        if ( $next_ts ) {
                            $next_local = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
                            echo ' &mdash; ';
                            printf(
                                /* translators: %s: next scheduled run datetime */
                                esc_html__( 'Next scheduled run: %s', 'carttrigger-holded-sync' ),
                                '<strong>' . esc_html( $next_local ) . '</strong>'
                            );
                        } else {
                            echo ' &mdash; <em>' . esc_html__( 'No scheduled run found. Enable sync to activate the scheduler.', 'carttrigger-holded-sync' ) . '</em>';
                        }

                        // Temporary debug (visible only with log enabled).
                        if ( get_option( 'cthls_debug_log' ) && function_exists( 'as_get_scheduled_actions' ) ) {
                            $actions = as_get_scheduled_actions( [
                                'hook'     => CTHLS_Cron::HOOK,
                                'per_page' => 5,
                                'status'   => '',
                            ], 'ARRAY_A' );
                            if ( $actions ) {
                                echo '<br><small style="color:#999">AS debug: ';
                                foreach ( $actions as $a ) {
                                    echo esc_html( sprintf( '[%s | group:%s | %s] ', $a['status'] ?? '?', $a['group'] ?? '?', $a['scheduled_date_gmt'] ?? '?' ) );
                                }
                                echo '</small>';
                            } else {
                                echo '<br><small style="color:#999">AS debug: no actions found for hook ' . esc_html( CTHLS_Cron::HOOK ) . '</small>';
                            }
                        }
                        ?>
                    </p>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <button type="button" id="cthls-pull-btn" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top:3px;margin-right:4px;font-size:14px;width:14px;height:14px;"></span>
                            <?php esc_html_e( 'Pull from Holded now', 'carttrigger-holded-sync' ); ?>
                        </button>
                        <span id="cthls-pull-result"></span>
                    </div>
                </div>
            </div>

            <!-- ── System log ── -->
            <?php if ( get_option( 'cthls_debug_log' ) && ! empty( $log ) ) : ?>
            <div class="cthls-card">
                <div class="cthls-log-header">
                    <h2>
                        <span class="dashicons dashicons-list-view"></span>
                        <?php esc_html_e( 'System log', 'carttrigger-holded-sync' ); ?>
                    </h2>
                    <button type="button" id="cthls-clear-log" class="cthls-btn-danger">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e( 'Clear log', 'carttrigger-holded-sync' ); ?>
                    </button>
                </div>
                <details class="cthls-log-legend">
                    <summary><?php esc_html_e( 'Event reference', 'carttrigger-holded-sync' ); ?></summary>
                    <table class="cthls-log-legend-table">
                        <tbody>
                            <tr><td><code>product_payload</code></td><td><?php esc_html_e( 'Full payload sent to Holded when a product is saved in WooCommerce (debug).', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>product_save</code></td><td><?php esc_html_e( 'Error returned by Holded when creating or updating a product.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>stock_change</code></td><td><?php esc_html_e( 'Error returned by Holded when updating stock quantity.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_start</code></td><td><?php esc_html_e( 'Pull from Holded started. Message indicates whether triggered by the scheduler or manually.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_error</code></td><td><?php esc_html_e( 'API error while fetching products from Holded.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_complete</code></td><td><?php esc_html_e( 'Pull finished. Message shows how many products were processed.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_create</code></td><td><?php esc_html_e( 'A new product was created in WooCommerce from Holded data.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_update</code></td><td><?php esc_html_e( 'An existing WooCommerce product was updated with data from Holded.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_save_error</code></td><td><?php esc_html_e( 'Error while saving a product in WooCommerce during the pull.', 'carttrigger-holded-sync' ); ?></td></tr>
                        </tbody>
                    </table>
                </details>

                <table class="widefat striped cthls-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Time', 'carttrigger-holded-sync' ); ?></th>
                            <th><?php esc_html_e( 'Event', 'carttrigger-holded-sync' ); ?></th>
                            <th><?php esc_html_e( 'Product ID', 'carttrigger-holded-sync' ); ?></th>
                            <th><?php esc_html_e( 'Message', 'carttrigger-holded-sync' ); ?></th>
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
