<?php

defined( 'ABSPATH' ) || exit;

/**
 * Product metabox — Holded-specific fields.
 *
 * Adds a "Holded" tab in the WC product data panel with:
 *   - _cthls_description : short description sent to Holded instead of the full product description
 *   - _cost_price           : cost price sent to Holded
 *   - _barcode              : barcode sent to Holded
 *   - _cthls_product_id  : (read-only) linked Holded product ID
 */
class CTHLS_Product_Meta {

    public static function init() {
        add_filter( 'woocommerce_product_data_tabs',   [ __CLASS__, 'add_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ __CLASS__, 'render_panel' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save' ] );
    }

    public static function add_tab( $tabs ) {
        $tabs['cthls'] = [
            'label'  => esc_html__( 'Holded Sync', 'carttrigger-holded-sync' ),
            'target' => 'cthls_product_data',
            'class'  => [],
        ];
        return $tabs;
    }

    public static function render_panel() {
        global $post;
        $description = get_post_meta( $post->ID, '_cthls_description', true );
        $cost_price  = get_post_meta( $post->ID, '_cost_price', true );
        $barcode     = get_post_meta( $post->ID, '_barcode', true );
        $holded_id   = get_post_meta( $post->ID, '_cthls_product_id', true );
        ?>
        <div id="cthls_product_data" class="panel woocommerce_options_panel">

            <div class="options_group">
                <p class="form-field">
                    <label for="_cthls_description">
                        <?php esc_html_e( 'Description for Holded', 'carttrigger-holded-sync' ); ?>
                    </label>
                    <textarea
                        id="_cthls_description"
                        name="_cthls_description"
                        rows="4"
                        style="width:100%"
                        placeholder="<?php esc_attr_e( 'Short description sent to Holded. Leave empty to skip.', 'carttrigger-holded-sync' ); ?>"
                    ><?php echo esc_textarea( $description ); ?></textarea>
                    <span class="description">
                        <?php esc_html_e( 'Replaces the full product description when syncing to Holded.', 'carttrigger-holded-sync' ); ?>
                    </span>
                </p>
            </div>

            <div class="options_group">
                <p class="form-field">
                    <label for="_cost_price">
                        <?php esc_html_e( 'Cost price', 'carttrigger-holded-sync' ); ?>
                    </label>
                    <input
                        type="number"
                        id="_cost_price"
                        name="_cost_price"
                        value="<?php echo esc_attr( $cost_price ); ?>"
                        step="0.01"
                        min="0"
                        style="width:120px"
                        placeholder="0.00" />
                    <span class="description">
                        <?php esc_html_e( 'Cost price sent to Holded (net, excluding tax).', 'carttrigger-holded-sync' ); ?>
                    </span>
                </p>
                <p class="form-field">
                    <label for="_barcode">
                        <?php esc_html_e( 'Barcode', 'carttrigger-holded-sync' ); ?>
                    </label>
                    <input
                        type="text"
                        id="_barcode"
                        name="_barcode"
                        value="<?php echo esc_attr( $barcode ); ?>"
                        class="short"
                        placeholder="EAN / UPC" />
                    <span class="description">
                        <?php esc_html_e( 'Barcode (EAN, UPC…) sent to Holded.', 'carttrigger-holded-sync' ); ?>
                    </span>
                </p>
            </div>

            <?php if ( $holded_id ) : ?>
            <div class="options_group">
                <p class="form-field">
                    <label><?php esc_html_e( 'Holded product ID', 'carttrigger-holded-sync' ); ?></label>
                    <code><?php echo esc_html( $holded_id ); ?></code>
                </p>
            </div>
            <?php endif; ?>

        </div>
        <?php
    }

    public static function save( $product_id ) {
        // WooCommerce already verifies the nonce before firing this hook, but
        // we check it explicitly so static-analysis tools can confirm it.
        if ( ! isset( $_POST['woocommerce_meta_nonce'] ) ||
             ! wp_verify_nonce( sanitize_key( $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' ) ) {
            return;
        }

        if ( isset( $_POST['_cthls_description'] ) ) {
            update_post_meta(
                $product_id,
                '_cthls_description',
                wp_strip_all_tags( wp_unslash( $_POST['_cthls_description'] ) )
            );
        }

        if ( isset( $_POST['_cost_price'] ) ) {
            update_post_meta(
                $product_id,
                '_cost_price',
                wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['_cost_price'] ) ) )
            );
        }

        if ( isset( $_POST['_barcode'] ) ) {
            update_post_meta(
                $product_id,
                '_barcode',
                sanitize_text_field( wp_unslash( $_POST['_barcode'] ) )
            );
        }
    }
}
