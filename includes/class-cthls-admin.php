<?php

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page for CartTrigger – Holded.
 *
 * Settings stored as WP options:
 *   cthls_api_key       string
 *   cthls_sync_push     bool  (WC → Holded)
 *   cthls_sync_pull     bool  (Holded → WC)
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
        add_action( 'wp_ajax_cthls_manual_push', [ __CLASS__, 'ajax_manual_push' ] );
        add_action( 'wp_ajax_cthls_reschedule', [ __CLASS__, 'ajax_reschedule' ] );
        add_action( 'wp_ajax_cthls_sync_sku_push', [ __CLASS__, 'ajax_sync_sku_push' ] );
        add_action( 'wp_ajax_cthls_sync_sku_pull', [ __CLASS__, 'ajax_sync_sku_pull' ] );
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
            'cthls_sync_push',
            'cthls_sync_pull',
            'cthls_sync_prices',
            'cthls_sync_sale_price',
            'cthls_sync_stock',
            'cthls_sync_desc',
            'cthls_desc_source',
            'cthls_append_brand',
            'cthls_variation_name_format',
            'cthls_sync_drafts',
            'cthls_debug_log',
        ];
        $text_options = [ 'cthls_api_key', 'cthls_warehouse_id', 'cthls_warehouse_name', 'cthls_default_tax_rate', 'cthls_prices_include_tax', 'cthls_pull_interval', 'cthls_desc_source', 'cthls_variation_name_format' ];

        // Reschedule Action Scheduler when interval changes.
        add_action( 'update_option_cthls_pull_interval', function( $old, $new ) {
            CTHLS_Cron::schedule( (int) $new );
        }, 10, 2 );

        // Unschedule when pull sync is disabled; reschedule when re-enabled.
        add_action( 'update_option_cthls_sync_pull', function( $old, $new ) {
            if ( $new ) {
                CTHLS_Cron::schedule();
            } else {
                CTHLS_Cron::unschedule();
            }
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
            'i18n_pushing'         => esc_html__( 'Pushing to Holded…', 'carttrigger-holded-sync' ),
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

    public static function ajax_manual_push() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }

        $count = CTHLS_Sync::push_to_holded();
        wp_send_json_success( [
            'message' => sprintf(
                /* translators: %d: number of products pushed */
                _n( 'Push completed: %d product sent.', 'Push completed: %d products sent.', $count, 'carttrigger-holded-sync' ),
                $count
            ),
        ] );
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

    public static function ajax_sync_sku_push() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }
        $sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
        if ( ! $sku ) {
            wp_send_json_error( [ 'message' => __( 'SKU is required.', 'carttrigger-holded-sync' ) ] );
        }
        $result = CTHLS_Sync::push_single_sku( $sku );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => $result ] );
    }

    public static function ajax_sync_sku_pull() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }
        $sku = isset( $_POST['sku'] ) ? sanitize_text_field( wp_unslash( $_POST['sku'] ) ) : '';
        if ( ! $sku ) {
            wp_send_json_error( [ 'message' => __( 'SKU is required.', 'carttrigger-holded-sync' ) ] );
        }
        $result = CTHLS_Sync::pull_single_sku( $sku );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }
        wp_send_json_success( [ 'message' => $result ] );
    }

    public static function ajax_reschedule() {
        check_ajax_referer( 'cthls_admin', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized', 'carttrigger-holded-sync' ) ] );
        }

        if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
            wp_send_json_error( [ 'message' => __( 'Action Scheduler not available.', 'carttrigger-holded-sync' ) ] );
        }

        CTHLS_Cron::unschedule();
        CTHLS_Cron::schedule();

        $next = as_next_scheduled_action( CTHLS_Cron::HOOK );
        if ( $next ) {
            $next_local = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
            wp_send_json_success( [ 'message' => $next_local ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'Scheduled but could not read next run time.', 'carttrigger-holded-sync' ) ] );
        }
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
                                        <span class="dashicons dashicons-superhero"></span>
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
                                        <span class="dashicons dashicons-update"></span>
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
                            <th><?php esc_html_e( 'Sync direction', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <fieldset>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox" name="cthls_sync_push" value="1"
                                            <?php checked( get_option( 'cthls_sync_push' ) ); ?> />
                                        <?php esc_html_e( 'WooCommerce → Holded (real-time on product save / stock change)', 'carttrigger-holded-sync' ); ?>
                                    </label>
                                    <label style="display:block;">
                                        <input type="checkbox" name="cthls_sync_pull" value="1"
                                            <?php checked( get_option( 'cthls_sync_pull' ) ); ?> />
                                        <?php esc_html_e( 'Holded → WooCommerce (scheduled pull every X minutes)', 'carttrigger-holded-sync' ); ?>
                                    </label>
                                    <label style="display:block;margin-top:6px;">
                                        <input type="checkbox" name="cthls_sync_drafts" value="1"
                                            <?php checked( get_option( 'cthls_sync_drafts' ) ); ?> />
                                        <?php esc_html_e( 'Include draft products in WooCommerce → Holded sync (real-time and bulk push)', 'carttrigger-holded-sync' ); ?>
                                    </label>
                                </fieldset>
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
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="cthls_sync_prices" value="1"
                                        <?php checked( get_option( 'cthls_sync_prices', true ) ); ?> />
                                    <?php esc_html_e( 'Sync regular price (both directions)', 'carttrigger-holded-sync' ); ?>
                                </label>
                                <label style="display:block;">
                                    <input type="checkbox" name="cthls_sync_sale_price" value="1"
                                        <?php checked( get_option( 'cthls_sync_sale_price', false ) ); ?> />
                                    <?php esc_html_e( 'Sync sale price (WC → Holded): if a sale price is set in WooCommerce, send it to Holded instead of the regular price', 'carttrigger-holded-sync' ); ?>
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
                                <p class="description">
                                    <?php esc_html_e( 'WC → Holded: which field to read the description from.', 'carttrigger-holded-sync' ); ?><br>
                                    <?php esc_html_e( 'Holded → WC: which field to write the description to.', 'carttrigger-holded-sync' ); ?>
                                </p>
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
                            <th><?php esc_html_e( 'Variation name format', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <?php $fmt = get_option( 'cthls_variation_name_format', 'space' ); ?>
                                <select name="cthls_variation_name_format">
                                    <option value="space" <?php selected( $fmt, 'space' ); ?>>
                                        <?php esc_html_e( 'Space — Benaco Magnum 15 litros', 'carttrigger-holded-sync' ); ?>
                                    </option>
                                    <option value="parens" <?php selected( $fmt, 'parens' ); ?>>
                                        <?php esc_html_e( 'Parentheses — Benaco (Magnum 15 litros)', 'carttrigger-holded-sync' ); ?>
                                    </option>
                                </select>
                                <p class="description"><?php esc_html_e( 'How to append attribute values to the parent product name when syncing variations to Holded.', 'carttrigger-holded-sync' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Enable log', 'carttrigger-holded-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cthls_debug_log" value="1"
                                        <?php checked( get_option( 'cthls_debug_log' ) ); ?> />
                                    <?php esc_html_e( 'Enable log (last 50 messages)', 'carttrigger-holded-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Save settings', 'carttrigger-holded-sync' ) ); ?>
            </form>

            <!-- ── Single SKU sync ── -->
            <div class="cthls-card">
                <h2>
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Single SKU sync', 'carttrigger-holded-sync' ); ?>
                </h2>
                <div class="cthls-sync-row">
                    <p class="description"><?php esc_html_e( 'Sync a single product or variation by SKU without running a full push/pull.', 'carttrigger-holded-sync' ); ?></p>
                    <div class="cthls-field-group" style="margin-bottom:10px;">
                        <input type="text" id="cthls-sku-input" placeholder="<?php esc_attr_e( 'Enter SKU…', 'carttrigger-holded-sync' ); ?>" style="width:260px;" />
                    </div>
                    <div class="cthls-sync-actions">
                        <button type="button" id="cthls-sku-push-btn" class="button button-primary">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e( 'Push to Holded', 'carttrigger-holded-sync' ); ?>
                        </button>
                        <button type="button" id="cthls-sku-pull-btn" class="button button-secondary">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Pull from Holded', 'carttrigger-holded-sync' ); ?>
                        </button>
                        <span id="cthls-sku-result"></span>
                    </div>
                </div>
            </div>

            <!-- ── Known limitations ── -->
            <div class="cthls-card cthls-card-notice">
                <h2>
                    <span class="dashicons dashicons-info-outline"></span>
                    <?php esc_html_e( 'Known limitations', 'carttrigger-holded-sync' ); ?>
                </h2>
                <ul class="cthls-limitations-list">
                    <li>
                        <strong><?php esc_html_e( 'Variable products — flattened to simple in Holded', 'carttrigger-holded-sync' ); ?></strong>
                        &mdash;
                        <?php esc_html_e( 'Holded does not handle product variants reliably via API. Variable products in WooCommerce are pushed to Holded as separate simple products — one per variation — each with its own SKU. The parent variable product is never created in Holded. During pull (Holded → WC), each Holded product is matched by SKU to the corresponding WC variation; only stock and price are updated. The product structure in WooCommerce is never altered by the pull.', 'carttrigger-holded-sync' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Multiple price tiers', 'carttrigger-holded-sync' ); ?></strong>
                        &mdash;
                        <?php esc_html_e( 'The Holded API does not expose secondary price rates (e.g. Ho.re.ca). Only the main price is synced. Additional price tiers must be set manually in Holded for each product (including each variation pushed as a simple product). This limitation will be addressed as soon as Holded adds API support.', 'carttrigger-holded-sync' ); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e( 'Product images', 'carttrigger-holded-sync' ); ?></strong>
                        &mdash;
                        <?php esc_html_e( 'The Holded API does not support setting product images via REST API. Images must be uploaded manually through the Holded interface.', 'carttrigger-holded-sync' ); ?>
                    </li>
                </ul>
            </div>

            <!-- ── Manual sync ── -->
            <div class="cthls-card">
                <h2>
                    <span class="dashicons dashicons-randomize"></span>
                    <?php esc_html_e( 'Manual sync', 'carttrigger-holded-sync' ); ?>
                </h2>

                <!-- WC → Holded push -->
                <div class="cthls-sync-row">
                    <p class="description">
                        <?php esc_html_e( 'Send all WooCommerce products to Holded now (create or update).', 'carttrigger-holded-sync' ); ?>
                    </p>
                    <div class="cthls-sync-actions">
                        <button type="button" id="cthls-push-btn" class="button button-primary">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e( 'Push to Holded now', 'carttrigger-holded-sync' ); ?>
                        </button>
                        <span id="cthls-push-result"></span>
                    </div>
                </div>

                <hr style="margin:16px 0;border:none;border-top:1px solid #e8eaed;">

                <!-- Holded → WC pull -->
                <div class="cthls-sync-row">
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: last pull datetime */
                            esc_html__( 'Pull all products from Holded and update WooCommerce. Last pull: %s', 'carttrigger-holded-sync' ),
                            $last_pull ? esc_html( $last_pull ) : esc_html__( 'never', 'carttrigger-holded-sync' )
                        );

                        $next_ts = ( CTHLS_Sync::pull_enabled() && function_exists( 'as_next_scheduled_action' ) )
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

                        ?>
                    </p>
                    <div class="cthls-sync-actions">
                        <button type="button" id="cthls-reschedule-btn" class="button button-secondary">
                            <span class="dashicons dashicons-clock"></span>
                            <?php esc_html_e( 'Reschedule', 'carttrigger-holded-sync' ); ?>
                        </button>
                        <span id="cthls-reschedule-result"></span>
                        <button type="button" id="cthls-pull-btn" class="button button-primary">
                            <span class="dashicons dashicons-download"></span>
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
                            <tr><td><code>push_start</code></td><td><?php esc_html_e( 'Manual push to Holded started.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>push_complete</code></td><td><?php esc_html_e( 'Manual push finished. Message shows how many products were sent.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>product_payload</code></td><td><?php esc_html_e( 'Full payload sent to Holded when a product is saved in WooCommerce (debug).', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>product_linked</code></td><td><?php esc_html_e( 'Existing Holded product found by SKU and linked to the WooCommerce product (avoids duplicate creation).', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>product_save</code></td><td><?php esc_html_e( 'Error returned by Holded when creating or updating a product.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>stock_change</code></td><td><?php esc_html_e( 'Error returned by Holded when updating stock quantity.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_start</code></td><td><?php esc_html_e( 'Pull from Holded started. Message indicates whether triggered by the scheduler or manually.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_error</code></td><td><?php esc_html_e( 'API error while fetching products from Holded.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_complete</code></td><td><?php esc_html_e( 'Pull finished. Message shows how many products were processed.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_create</code></td><td><?php esc_html_e( 'A new product was created in WooCommerce from Holded data.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_update</code></td><td><?php esc_html_e( 'An existing WooCommerce product was updated with data from Holded.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_save_error</code></td><td><?php esc_html_e( 'Error while saving a product in WooCommerce during the pull.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_price_check</code></td><td><?php esc_html_e( 'Price comparison detail for a variation during pull: shows Holded raw price, converted WC price, current WC price, and whether an update was triggered.', 'carttrigger-holded-sync' ); ?></td></tr>
                            <tr><td><code>pull_name_skipped</code></td><td><?php esc_html_e( 'The product name in Holded differs from WooCommerce but was not applied — WC is the source of truth for names.', 'carttrigger-holded-sync' ); ?></td></tr>
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
