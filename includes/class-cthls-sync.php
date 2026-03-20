<?php

defined( 'ABSPATH' ) || exit;

/**
 * Bidirectional sync logic WooCommerce ↔ Holded.
 *
 * WC → Holded: real-time via WC hooks.
 * Holded → WC: scheduled cron (see CTHLS_Cron).
 *
 * Mapping is stored in WC product meta:
 *   _cthls_product_id  → Holded product ID
 *   _cthls_variant_map → JSON {wc_variation_id: holded_variant_id}
 */
class CTHLS_Sync {

    /** @var CTHLS_API */
    private static $api;

    /** @var int[] Products already synced in this request (prevent double fire). */
    private static $synced = [];

    public static function init() {
        self::$api = new CTHLS_API();

        // WC → Holded: product saved (create or update).
        add_action( 'woocommerce_update_product', [ __CLASS__, 'on_product_saved' ], 20 );
        add_action( 'woocommerce_new_product',    [ __CLASS__, 'on_product_saved' ], 20 );

        // WC → Holded: stock changed.
        add_action( 'woocommerce_product_set_stock',           [ __CLASS__, 'on_stock_changed' ] );
        add_action( 'woocommerce_variation_set_stock',         [ __CLASS__, 'on_stock_changed' ] );
        add_action( 'woocommerce_product_set_stock_status',    [ __CLASS__, 'on_stock_status_changed' ], 10, 2 );
    }

    // ── WC → Holded ─────────────────────────────────────────────────────────

    /**
     * Sync a WC product (simple or variable) to Holded.
     */
    public static function on_product_saved( $product_id ) {
        if ( ! self::sync_enabled() ) {
            return;
        }

        if ( in_array( $product_id, self::$synced, true ) ) {
            return;
        }
        self::$synced[] = $product_id;

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_parent_id() ) {
            return; // Skip variations (handled as variants inside the parent).
        }

        $holded_id = get_post_meta( $product_id, '_cthls_product_id', true );
        $data      = self::wc_product_to_holded( $product );

        self::log( 'product_payload', $product_id, wp_json_encode( $data ) );

        if ( $holded_id ) {
            $result = self::$api->update_product( $holded_id, $data );
        } else {
            $result = self::$api->create_product( $data );
            if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
                update_post_meta( $product_id, '_cthls_product_id', sanitize_text_field( $result['id'] ) );
                $holded_id = $result['id'];
            }
        }

        if ( is_wp_error( $result ) ) {
            self::log( 'product_save', $product_id, $result->get_error_message() );
        }
    }

    /**
     * Sync stock change to Holded.
     *
     * @param WC_Product $product
     */
    public static function on_stock_changed( $product ) {
        if ( ! self::sync_enabled() ) {
            return;
        }

        $product_id = $product->get_id();
        $stock      = $product->get_stock_quantity();

        if ( $product->get_parent_id() ) {
            // Variation.
            $parent_id  = $product->get_parent_id();
            $holded_id  = get_post_meta( $parent_id, '_cthls_product_id', true );
            $variant_map = json_decode( get_post_meta( $parent_id, '_cthls_variant_map', true ), true );
            $variant_id  = isset( $variant_map[ $product_id ] ) ? $variant_map[ $product_id ] : '';
        } else {
            $holded_id  = get_post_meta( $product_id, '_cthls_product_id', true );
            $variant_id = '';
        }

        if ( ! $holded_id || null === $stock ) {
            return;
        }

        $result = self::$api->update_stock( $holded_id, $stock, $variant_id );
        if ( is_wp_error( $result ) ) {
            self::log( 'stock_change', $product_id, $result->get_error_message() );
        }
    }

    public static function on_stock_status_changed( $product_id, $status ) {
        // Optionally handle out-of-stock transitions.
    }

    // ── Holded → WC ─────────────────────────────────────────────────────────

    /**
     * Pull all products from Holded and update WooCommerce.
     * Called by cron.
     */
    public static function pull_from_holded( $source = 'scheduled' ) {
        if ( ! self::sync_enabled() ) {
            return;
        }

        self::log( 'pull_start', 0, $source );

        $page      = 1;
        $processed = 0;

        do {
            $products = self::$api->get_products( $page );

            if ( is_wp_error( $products ) ) {
                self::log( 'pull_error', 0, $products->get_error_message() );
                break;
            }

            if ( empty( $products ) ) {
                break;
            }

            foreach ( $products as $holded_product ) {
                self::holded_product_to_wc( $holded_product );
                $processed++;
            }

            $page++;
        } while ( count( $products ) >= 50 && $page <= 50 );

        update_option( 'cthls_last_pull', current_time( 'mysql' ) );
        self::log( 'pull_complete', 0, sprintf( '%d products processed', $processed ) );
    }

    // ── Data mapping ─────────────────────────────────────────────────────────

    /**
     * Build Holded product payload from a WC product.
     *
     * @param WC_Product $product
     * @return array
     */
    private static function wc_product_to_holded( WC_Product $product ) {
        $data = [
            'kind'     => $product->is_type( 'variable' ) ? 'variants' : 'simple',
            'name'     => self::product_name_with_brand( $product ),
            'desc'     => 'full' === get_option( 'cthls_desc_source', 'custom' )
                            ? $product->get_description()
                            : $product->get_meta( '_cthls_description' ),
            'sku'      => $product->get_sku(),
            'price'    => self::price_ex_tax( $product ),
            'tax'      => self::get_tax_rate( $product ),
            'cost'     => (float) $product->get_meta( '_cost_price' ),
            'barcode'  => $product->get_meta( '_barcode' ),
            'weight'   => (float) $product->get_weight(),
            'hasStock' => $product->managing_stock(),
            'forSale'  => $product->is_purchasable(),
        ];

        if ( $product->managing_stock() && ! $product->is_type( 'variable' ) ) {
            $data['stock'] = (int) $product->get_stock_quantity();
        }

        // Variable product → variants.
        if ( $product->is_type( 'variable' ) ) {
            $variants    = [];
            $variant_map = [];

            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }

                $holded_variant_id = get_post_meta( $variation_id, '_cthls_variant_id', true );

                $variant_entry = [
                    'sku'   => $variation->get_sku(),
                    'price' => self::price_ex_tax( $variation ),
                    'cost'  => (float) $variation->get_meta( '_cost_price' ),
                    'stock' => (int) $variation->get_stock_quantity(),
                ];

                if ( $holded_variant_id ) {
                    $variant_entry['id'] = $holded_variant_id;
                }

                $variants[] = $variant_entry;
            }

            $data['variants'] = $variants;
        }

        return array_filter( $data, function( $v ) {
            return $v !== '' && $v !== null;
        } );
    }

    /**
     * Update or create a WC product from Holded data.
     *
     * Matching: SKU first, then _cthls_product_id meta.
     *
     * @param array $holded_product
     */
    private static function holded_product_to_wc( array $holded_product ) {
        $holded_id = isset( $holded_product['id'] ) ? $holded_product['id'] : '';
        $sku       = isset( $holded_product['sku'] ) ? $holded_product['sku'] : '';

        // Find matching WC product.
        $wc_product_id = null;
        if ( $sku ) {
            $wc_product_id = wc_get_product_id_by_sku( $sku );
        }
        if ( ! $wc_product_id && $holded_id ) {
            $wc_product_id = self::find_wc_product_by_holded_id( $holded_id );
        }

        if ( ! $wc_product_id ) {
            // Create new WC product.
            $wc_product  = new WC_Product_Simple();
            $is_new      = true;
        } else {
            $wc_product = wc_get_product( $wc_product_id );
            if ( ! $wc_product ) {
                return;
            }
            $is_new = false;
        }

        // Only update fields if they differ to avoid triggering update hooks.
        $fields_changed = false;

        if ( isset( $holded_product['name'] ) && $wc_product->get_name() !== $holded_product['name'] ) {
            $wc_product->set_name( sanitize_text_field( $holded_product['name'] ) );
            $fields_changed = true;
        }

        if ( isset( $holded_product['price'] ) ) {
            $new_price = (string) $holded_product['price'];
            if ( $wc_product->get_regular_price() !== $new_price ) {
                $wc_product->set_regular_price( $new_price );
                $fields_changed = true;
            }
        }

        if ( isset( $holded_product['desc'] ) && $wc_product->get_description() !== $holded_product['desc'] ) {
            $wc_product->set_description( wp_kses_post( $holded_product['desc'] ) );
            $fields_changed = true;
        }

        if ( $sku && $wc_product->get_sku() !== $sku ) {
            $wc_product->set_sku( sanitize_text_field( $sku ) );
            $fields_changed = true;
        }

        // Stock.
        if ( isset( $holded_product['stock'] ) && $wc_product->managing_stock() ) {
            $new_stock = (int) $holded_product['stock'];
            if ( (int) $wc_product->get_stock_quantity() !== $new_stock ) {
                $wc_product->set_stock_quantity( $new_stock );
                $fields_changed = true;
            }
        }

        if ( $fields_changed || ! $wc_product_id ) {
            // Temporarily remove our own hook to avoid loop.
            remove_action( 'woocommerce_update_product', [ 'CTHLS_Sync', 'on_product_saved' ], 20 );
            remove_action( 'woocommerce_new_product',    [ 'CTHLS_Sync', 'on_product_saved' ], 20 );

            $saved_id = $wc_product->save();

            add_action( 'woocommerce_update_product', [ 'CTHLS_Sync', 'on_product_saved' ], 20 );
            add_action( 'woocommerce_new_product',    [ 'CTHLS_Sync', 'on_product_saved' ], 20 );

            if ( $saved_id ) {
                if ( $holded_id ) {
                    update_post_meta( $saved_id, '_cthls_product_id', sanitize_text_field( $holded_id ) );
                }
                $event = $is_new ? 'pull_create' : 'pull_update';
                self::log( $event, $saved_id, $sku ?: $holded_id );
            } else {
                self::log( 'pull_save_error', $wc_product_id ?: 0, sprintf( 'Could not save product (sku: %s)', $sku ) );
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function find_wc_product_by_holded_id( $holded_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_cthls_product_id' AND meta_value = %s LIMIT 1",
                $holded_id
            )
        );
    }

    public static function sync_enabled() {
        return (bool) get_option( 'cthls_sync_enabled', false );
    }

    /**
     * Return the price excluding tax for a product.
     * If WC is configured with tax-inclusive prices, uses wc_get_price_excluding_tax().
     */
    /**
     * Return the price excluding tax for a product.
     * Respects tax_status: if not taxable, returns price as-is.
     */
    /**
     * Return the product name with the brand appended, if available.
     * e.g. "Groppello D.O.C. Notorius Averoldi"
     */
    private static function product_name_with_brand( WC_Product $product ) {
        $name = $product->get_name();
        if ( ! get_option( 'cthls_append_brand', false ) ) {
            return $name;
        }
        $terms = get_the_terms( $product->get_id(), 'product_brand' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            $brand = reset( $terms );
            $name  = $name . ' ' . $brand->name;
        }
        return $name;
    }

    private static function price_ex_tax( WC_Product $product ) {
        $price = $product->get_regular_price();
        if ( '' === $price ) {
            return 0.0;
        }
        $prices_include_tax = 'yes' === get_option( 'cthls_prices_include_tax', get_option( 'woocommerce_prices_include_tax', 'no' ) );

        if ( 'taxable' === $product->get_tax_status()
            && wc_tax_enabled()
            && $prices_include_tax ) {
            $tax_rate = self::get_tax_rate( $product );
            if ( $tax_rate > 0 ) {
                return round( (float) $price / ( 1 + $tax_rate / 100 ), 2 );
            }
        }
        return (float) $price;
    }

    /**
     * Return the tax rate percentage for a product (e.g. 21).
     * Returns 0 if product is not taxable or taxes are disabled.
     */
    private static function get_tax_rate( WC_Product $product ) {
        if ( ! wc_tax_enabled() || 'taxable' !== $product->get_tax_status() ) {
            return 0;
        }

        // Try WC base rates for the product's tax class.
        $tax_class = $product->get_tax_class();
        $rates     = WC_Tax::get_base_tax_rates( $tax_class );

        if ( empty( $rates ) ) {
            // Fallback: try get_rates() with empty location.
            $rates = WC_Tax::get_rates( $tax_class );
        }

        if ( ! empty( $rates ) ) {
            $rate = reset( $rates );
            return (float) $rate['rate'];
        }

        // Last resort: use the default rate configured in plugin settings.
        return (float) get_option( 'cthls_default_tax_rate', 21 );
    }

    private static function log( $event, $product_id, $message ) {
        if ( ! get_option( 'cthls_debug_log', false ) ) {
            return;
        }
        $log = get_option( 'cthls_log', [] );
        array_unshift( $log, [
            'time'       => current_time( 'mysql' ),
            'event'      => $event,
            'product_id' => $product_id,
            'message'    => $message,
        ] );
        // Keep last 100 entries.
        update_option( 'cthls_log', array_slice( $log, 0, 100 ) );
    }
}
