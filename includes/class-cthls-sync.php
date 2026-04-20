<?php

defined( 'ABSPATH' ) || exit;

/**
 * Bidirectional sync logic WooCommerce ↔ Holded.
 *
 * WC → Holded: real-time via WC hooks.
 * Holded → WC: scheduled cron (see CTHLS_Cron).
 *
 * Mapping is stored in WC product meta:
 *   _cthls_product_id  → Holded product ID (on simple products and on each variation)
 *
 * Variable products: each WC variation is pushed as a separate simple product in Holded.
 * Holded does not handle variants correctly, so variant products are never created.
 */
class CTHLS_Sync {

    /** @var CTHLS_API */
    private static $api;

    /** @var int[] Products already synced in this request (prevent double fire). */
    private static $synced = [];

    /**
     * When true, on_product_saved() skips the push_enabled() check.
     * Used by push_single_sku() so manual operations always run
     * regardless of whether automatic push is enabled.
     */
    private static $force_push = false;

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
        if ( ! self::$force_push && ! self::push_enabled() ) {
            return;
        }

        if ( in_array( $product_id, self::$synced, true ) ) {
            return;
        }
        self::$synced[] = $product_id;

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->get_parent_id() ) {
            return; // Skip variations (handled inside the parent).
        }

        // Skip drafts unless the user explicitly enabled draft sync.
        $status = $product->get_status();
        if ( 'publish' !== $status && ! ( 'draft' === $status && get_option( 'cthls_sync_drafts', false ) ) ) {
            return;
        }

        // Variable products: sync each variation as a separate simple product in Holded.
        if ( $product->is_type( 'variable' ) ) {
            self::sync_variable_product( $product );
            return;
        }

        // Simple product.
        $holded_id = get_post_meta( $product_id, '_cthls_product_id', true );

        if ( $holded_id ) {
            $data   = self::wc_product_to_holded( $product, $product_id );
            self::log( 'product_payload', $product_id, wp_json_encode( $data ) );
            $result = self::$api->update_product( $holded_id, $data );
        } else {
            $result = null;

            // Before creating, check if a product with the same SKU already exists in Holded.
            $sku = $product->get_sku();
            if ( $sku ) {
                $existing = self::$api->find_product_by_sku( $sku );
                if ( $existing && isset( $existing['id'] ) ) {
                    $holded_id = $existing['id'];
                    update_post_meta( $product_id, '_cthls_product_id', sanitize_text_field( $holded_id ) );
                    $data   = self::wc_product_to_holded( $product, $product_id );
                    self::log( 'product_payload', $product_id, wp_json_encode( $data ) );
                    $result = self::$api->update_product( $holded_id, $data );
                    self::log( 'product_linked', $product_id, $holded_id );
                }
            }

            if ( null === $result ) {
                $data   = self::wc_product_to_holded( $product, $product_id );
                self::log( 'product_payload', $product_id, wp_json_encode( $data ) );
                $result = self::$api->create_product( $data );
                if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
                    update_post_meta( $product_id, '_cthls_product_id', sanitize_text_field( $result['id'] ) );
                }
            }
        }

        if ( is_wp_error( $result ) ) {
            self::log( 'product_save', $product_id, $result->get_error_message() );
        }
    }

    /**
     * Sync each variation of a variable WC product as a separate simple product in Holded.
     * Holded does not support variant-type products reliably, so we flatten to simples.
     *
     * @param WC_Product_Variable $product
     */
    private static function sync_variable_product( WC_Product $product ) {
        foreach ( $product->get_children() as $variation_id ) {
            $variation = wc_get_product( $variation_id );
            if ( ! $variation ) {
                continue;
            }

            $holded_id = get_post_meta( $variation_id, '_cthls_product_id', true );
            $data      = self::variation_to_holded( $variation, $product );

            if ( $holded_id ) {
                self::log( 'product_payload', $variation_id, wp_json_encode( $data ) );
                $result = self::$api->update_product( $holded_id, $data );
            } else {
                $result = null;

                $sku = $variation->get_sku();
                if ( $sku ) {
                    $existing = self::$api->find_product_by_sku( $sku );
                    if ( $existing && isset( $existing['id'] ) ) {
                        $holded_id = $existing['id'];
                        update_post_meta( $variation_id, '_cthls_product_id', sanitize_text_field( $holded_id ) );
                        self::log( 'product_payload', $variation_id, wp_json_encode( $data ) );
                        $result = self::$api->update_product( $holded_id, $data );
                        self::log( 'product_linked', $variation_id, $holded_id );
                    }
                }

                if ( null === $result ) {
                    self::log( 'product_payload', $variation_id, wp_json_encode( $data ) );
                    $result = self::$api->create_product( $data );
                    if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
                        update_post_meta( $variation_id, '_cthls_product_id', sanitize_text_field( $result['id'] ) );
                    }
                }
            }

            if ( is_wp_error( $result ) ) {
                self::log( 'product_save', $variation_id, $result->get_error_message() );
            }
        }
    }

    /**
     * Sync stock change to Holded.
     *
     * @param WC_Product $product
     */
    public static function on_stock_changed( $product ) {
        if ( ! self::push_enabled() ) {
            return;
        }

        $product_id = $product->get_id();
        $stock      = $product->get_stock_quantity();

        // Each variation is its own simple product in Holded — use its own _cthls_product_id.
        $holded_id = get_post_meta( $product_id, '_cthls_product_id', true );

        if ( ! $holded_id || null === $stock ) {
            return;
        }

        $result = self::$api->update_stock( $holded_id, $stock, '' );
        if ( is_wp_error( $result ) ) {
            self::log( 'stock_change', $product_id, $result->get_error_message() );
        }
    }

    public static function on_stock_status_changed( $product_id, $status ) {
        // Optionally handle out-of-stock transitions.
    }

    // ── WC → Holded / Holded → WC (single SKU) ──────────────────────────────

    /**
     * Push a single WC product or variation (by SKU) to Holded.
     *
     * @param  string $sku
     * @return string|WP_Error  Success message or WP_Error.
     */
    public static function push_single_sku( $sku ) {
        if ( ! self::$api ) {
            self::$api = new CTHLS_API();
        }

        $product_id = wc_get_product_id_by_sku( $sku );
        if ( ! $product_id ) {
            return new WP_Error( 'cthls_sku_not_found', sprintf( 'SKU "%s" not found in WooCommerce.', $sku ) );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return new WP_Error( 'cthls_product_not_found', sprintf( 'Product for SKU "%s" could not be loaded.', $sku ) );
        }

        self::$synced = [];

        if ( $product instanceof WC_Product_Variation ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( ! $parent ) {
                return new WP_Error( 'cthls_parent_not_found', 'Parent product not found.' );
            }
            $holded_id = get_post_meta( $product_id, '_cthls_product_id', true );
            $data      = self::variation_to_holded( $product, $parent );

            if ( $holded_id ) {
                $result = self::$api->update_product( $holded_id, $data );
            } else {
                $existing = self::$api->find_product_by_sku( $sku );
                if ( $existing && isset( $existing['id'] ) ) {
                    $holded_id = $existing['id'];
                    update_post_meta( $product_id, '_cthls_product_id', sanitize_text_field( $holded_id ) );
                    $result = self::$api->update_product( $holded_id, $data );
                } else {
                    $result = self::$api->create_product( $data );
                    if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
                        update_post_meta( $product_id, '_cthls_product_id', sanitize_text_field( $result['id'] ) );
                    }
                }
            }
        } else {
            // Simple or variable parent — delegate to existing logic (force bypass enabled check).
            self::$force_push = true;
            self::on_product_saved( $product_id );
            self::$force_push = false;
            return sprintf( 'SKU "%s" pushed to Holded.', $sku );
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return sprintf( 'SKU "%s" pushed to Holded.', $sku );
    }

    /**
     * Pull a single product from Holded by SKU and update WooCommerce.
     *
     * @param  string $sku
     * @return string|WP_Error  Success message or WP_Error.
     */
    public static function pull_single_sku( $sku ) {
        if ( ! self::$api ) {
            self::$api = new CTHLS_API();
        }

        self::$pulled = [];

        $holded_product = self::$api->find_product_by_sku( $sku );
        if ( ! $holded_product ) {
            return new WP_Error( 'cthls_sku_not_found_holded', sprintf( 'SKU "%s" not found in Holded.', $sku ) );
        }
        if ( is_wp_error( $holded_product ) ) {
            return $holded_product;
        }

        self::holded_product_to_wc( $holded_product );
        return sprintf( 'SKU "%s" pulled from Holded.', $sku );
    }

    // ── WC → Holded (bulk) ──────────────────────────────────────────────────

    /**
     * Push all WC products to Holded.
     * Called manually from admin.
     *
     * @return int Number of products processed.
     */
    public static function push_to_holded() {
        // Manual bulk push always runs regardless of automatic push setting.
        self::log( 'push_start', 0, 'manual' );
        self::$synced = [];

        $statuses    = get_option( 'cthls_sync_drafts', false ) ? [ 'publish', 'draft' ] : [ 'publish' ];
        $product_ids = wc_get_products( [
            'status'  => $statuses,
            'limit'   => -1,
            'return'  => 'ids',
            'type'    => [ 'simple', 'variable' ],
        ] );

        $processed        = 0;
        self::$force_push = true;
        foreach ( $product_ids as $product_id ) {
            self::on_product_saved( $product_id );
            $processed++;
        }
        self::$force_push = false;

        self::log( 'push_complete', 0, sprintf( '%d products processed', $processed ) );
        return $processed;
    }

    // ── Holded → WC ─────────────────────────────────────────────────────────

    /**
     * Pull all products from Holded and update WooCommerce.
     * Called by cron.
     */
    public static function pull_from_holded( $source = 'scheduled' ) {
        // Manual pull always runs; automatic pull respects the pull enabled setting.
        if ( 'manual' !== $source && ! self::pull_enabled() ) {
            return;
        }

        self::log( 'pull_start', 0, $source );

        self::$pulled = [];
        $page         = 1;
        $processed    = 0;

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

        // Purge LiteSpeed Cache if any WC products were actually updated.
        if ( ! empty( self::$pulled ) ) {
            do_action( 'litespeed_purge_all' );
        }
    }

    // ── Data mapping ─────────────────────────────────────────────────────────

    /**
     * Build Holded product payload from a WC simple product.
     *
     * @param WC_Product  $product
     * @param int|null    $product_id
     * @return array
     */
    private static function wc_product_to_holded( WC_Product $product, $product_id = null ) {
        if ( null === $product_id ) {
            $product_id = $product->get_id();
        }

        $data = [
            'kind'     => 'simple',
            'name'     => self::product_name_with_brand( $product ),
            'desc'     => 'full' === get_option( 'cthls_desc_source', 'custom' )
                            ? $product->get_description()
                            : $product->get_meta( '_cthls_description' ),
            'sku'      => $product->get_sku(),
            'price'    => self::resolve_price_for_holded( $product ),
            'tax'      => self::get_tax_rate( $product ),
            'cost'     => (float) $product->get_meta( '_cost_price' ),
            'barcode'  => $product->get_meta( '_barcode' ),
            'weight'   => (float) $product->get_weight(),
            'hasStock' => $product->managing_stock(),
            'forSale'  => $product->is_purchasable(),
        ];

        // NOTE: Holded API silently ignores the `image` field.
        if ( $product->managing_stock() ) {
            $data['stock'] = (int) $product->get_stock_quantity();
        }

        return array_filter( $data, function( $v ) {
            return $v !== '' && $v !== null;
        } );
    }

    /**
     * Build Holded product payload for a WC variation (pushed as a simple product).
     * The variation name is composed of the parent name + attribute values.
     * Prices in Holded (standard, horeca tiers) must be set manually — only the
     * base price is sent via API.
     *
     * @param WC_Product_Variation $variation
     * @param WC_Product_Variable  $parent
     * @return array
     */
    private static function variation_to_holded( WC_Product $variation, WC_Product $parent ) {
        // Build a descriptive name: parent name + readable attribute labels.
        // get_attributes() returns slugs for taxonomy-based attributes (e.g. "magnum-15-litros").
        // We resolve each to its term name to get the human-readable label (e.g. "Magnum 15 litros").
        $labels = [];
        foreach ( $variation->get_variation_attributes() as $attribute_name => $attribute_value ) {
            if ( '' === $attribute_value ) {
                continue;
            }
            $taxonomy = str_replace( 'attribute_', '', $attribute_name );
            if ( taxonomy_exists( $taxonomy ) ) {
                $term = get_term_by( 'slug', $attribute_value, $taxonomy );
                $labels[] = $term ? $term->name : $attribute_value;
            } else {
                $labels[] = $attribute_value;
            }
        }

        $variation_name = $parent->get_name();
        if ( ! empty( $labels ) ) {
            $attr_string = implode( ' / ', $labels );
            $fmt         = get_option( 'cthls_variation_name_format', 'space' );
            if ( 'parens' === $fmt ) {
                $variation_name .= ' (' . $attr_string . ')';
            } elseif ( 'dash' === $fmt ) {
                $variation_name .= ' – ' . $attr_string;
            } else {
                $variation_name .= ' ' . $attr_string;
            }
        }

        if ( get_option( 'cthls_append_brand', false ) ) {
            $terms = get_the_terms( $parent->get_id(), 'product_brand' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $brand          = reset( $terms );
                $variation_name = $variation_name . ' ' . $brand->name;
            }
        }

        // Cost: use variation-level cost; fall back to parent.
        $cost = (float) $variation->get_meta( '_cost_price' );
        if ( 0.0 === $cost ) {
            $cost = (float) $parent->get_meta( '_cost_price' );
        }

        if ( 'full' === get_option( 'cthls_desc_source', 'custom' ) ) {
            $desc = $variation->get_description() ?: $parent->get_description();
        } else {
            $desc = $variation->get_meta( '_cthls_description' ) ?: $parent->get_meta( '_cthls_description' );
        }

        $data = [
            'kind'     => 'simple',
            'name'     => $variation_name,
            'desc'     => $desc,
            'sku'      => $variation->get_sku(),
            'price'    => self::resolve_price_for_holded( $variation ),
            'tax'      => self::get_tax_rate( $variation ),
            'cost'     => $cost,
            'barcode'  => $variation->get_meta( '_barcode' ) ?: $parent->get_meta( '_barcode' ),
            'weight'   => (float) ( $variation->get_weight() ?: $parent->get_weight() ),
            'hasStock' => $variation->managing_stock(),
            'forSale'  => $variation->is_purchasable(),
        ];

        if ( $variation->managing_stock() ) {
            $data['stock'] = (int) $variation->get_stock_quantity();
        }

        return array_filter( $data, function( $v ) {
            return $v !== '' && $v !== null;
        } );
    }

    /**
     * Update or create a WC product/variation from Holded data.
     *
     * Matching: SKU first (may resolve to a variation), then _cthls_product_id meta.
     *
     * Variable products in WC are never created from Holded — their variations are
     * pushed to Holded as simple products and pulled back by SKU into the existing
     * WC variation. Only stock and price are updated on variations; name/description
     * are managed in WC and must not be overwritten.
     *
     * @param array $holded_product
     */
    /** WC product IDs already processed in this pull run — prevents duplicate updates. */
    private static $pulled = [];

    private static function holded_product_to_wc( array $holded_product ) {
        $holded_id = isset( $holded_product['id'] ) ? $holded_product['id'] : '';
        $sku       = isset( $holded_product['sku'] ) ? $holded_product['sku'] : '';

        // Find matching WC product or variation.
        $wc_product_id = null;
        if ( $sku ) {
            $wc_product_id = wc_get_product_id_by_sku( $sku );
        }
        if ( ! $wc_product_id && $holded_id ) {
            $wc_product_id = self::find_wc_product_by_holded_id( $holded_id );
        }

        // Skip if already processed in this pull run.
        if ( $wc_product_id && in_array( $wc_product_id, self::$pulled, true ) ) {
            return;
        }

        if ( $wc_product_id ) {
            $wc_product = wc_get_product( $wc_product_id );
            if ( ! $wc_product ) {
                return;
            }

            // Holded product maps to a WC variation: update stock and price only.
            // Name, description, and SKU are managed in WC — do not overwrite.
            if ( $wc_product instanceof WC_Product_Variation ) {
                $fields_changed = false;

                if ( isset( $holded_product['price'] ) ) {
                    $holded_raw = $holded_product['price'];
                    $new_price  = self::holded_price_to_wc( $holded_raw, $wc_product );
                    $wc_price   = $wc_product->get_regular_price();
                    self::log( 'pull_price_check', $wc_product_id, sprintf(
                        'SKU %s — Holded raw: %s → converted: %s | WC current: %s | match: %s',
                        $sku,
                        $holded_raw,
                        $new_price,
                        $wc_price,
                        $wc_price === $new_price ? 'yes (no update)' : 'no (will update)'
                    ) );
                    if ( $wc_price !== $new_price ) {
                        $wc_product->set_regular_price( $new_price );
                        $fields_changed = true;
                    }
                }

                if ( isset( $holded_product['stock'] ) && $wc_product->managing_stock() ) {
                    $new_stock = (int) $holded_product['stock'];
                    if ( (int) $wc_product->get_stock_quantity() !== $new_stock ) {
                        $wc_product->set_stock_quantity( $new_stock );
                        $fields_changed = true;
                    }
                }

                if ( $fields_changed ) {
                    // Variation save fires woocommerce_update_product_variation, not
                    // woocommerce_update_product — no need to remove our hook.
                    $saved_id = $wc_product->save();
                    if ( $saved_id ) {
                        if ( $holded_id ) {
                            update_post_meta( $saved_id, '_cthls_product_id', sanitize_text_field( $holded_id ) );
                        }
                        self::$pulled[] = $saved_id;
                        self::log( 'pull_update', $saved_id, $sku ?: $holded_id );
                    } else {
                        self::log( 'pull_save_error', $wc_product_id, sprintf( 'Could not save variation (sku: %s)', $sku ) );
                    }
                }
                return;
            }

            // Simple product update.
            $fields_changed = false;
            $is_new         = false;
        } else {
            // No match — create a new simple WC product.
            $wc_product     = new WC_Product_Simple();
            $fields_changed = false;
            $is_new         = true;
        }

        // ── Simple product: update all syncable fields ────────────────────────

        // WC is the source of truth for product names — never overwrite from Holded.
        // Log a notice if the name differs so the user is aware of the discrepancy.
        if ( isset( $holded_product['name'] ) && $wc_product->get_name() !== $holded_product['name'] ) {
            self::log( 'pull_name_skipped', $wc_product->get_id(), sprintf(
                'Name in Holded differs — WC: "%s" / Holded: "%s"',
                $wc_product->get_name(),
                $holded_product['name']
            ) );
        }

        if ( isset( $holded_product['price'] ) ) {
            $new_price = self::holded_price_to_wc( $holded_product['price'], $wc_product );
            if ( $wc_product->get_regular_price() !== $new_price ) {
                $wc_product->set_regular_price( $new_price );
                $fields_changed = true;
            }
        }

        if ( isset( $holded_product['desc'] ) ) {
            $desc = wp_kses_post( $holded_product['desc'] );
            if ( 'full' === get_option( 'cthls_desc_source', 'custom' ) ) {
                if ( $wc_product->get_description() !== $desc ) {
                    $wc_product->set_description( $desc );
                    $fields_changed = true;
                }
            } else {
                $existing = $wc_product->get_meta( '_cthls_description' );
                if ( $existing !== $desc ) {
                    $wc_product->update_meta_data( '_cthls_description', $desc );
                    $fields_changed = true;
                }
            }
        }

        if ( $sku && $wc_product->get_sku() !== $sku ) {
            $wc_product->set_sku( sanitize_text_field( $sku ) );
            $fields_changed = true;
        }

        if ( isset( $holded_product['stock'] ) && $wc_product->managing_stock() ) {
            $new_stock = (int) $holded_product['stock'];
            if ( (int) $wc_product->get_stock_quantity() !== $new_stock ) {
                $wc_product->set_stock_quantity( $new_stock );
                $fields_changed = true;
            }
        }

        if ( $fields_changed || $is_new ) {
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
                self::$pulled[] = $saved_id;
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

    public static function push_enabled() {
        return (bool) get_option( 'cthls_sync_push', false );
    }

    public static function pull_enabled() {
        return (bool) get_option( 'cthls_sync_pull', false );
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

    /**
     * Determine which price to send to Holded for a product.
     * Uses sale price if "Sync sale price" is enabled and the product is currently on sale
     * (WC handles date range checks internally via is_on_sale()).
     *
     * @param WC_Product $product
     * @return float
     */
    private static function resolve_price_for_holded( WC_Product $product ) {
        if ( get_option( 'cthls_sync_sale_price', false ) && $product->is_on_sale() ) {
            return self::price_ex_tax( $product, $product->get_sale_price() );
        }
        return self::price_ex_tax( $product );
    }

    /**
     * Return the price excluding tax to send to Holded.
     * Accepts an explicit price value (e.g. sale price); falls back to regular price.
     *
     * @param WC_Product  $product
     * @param string|null $price  Raw price string; null = use regular price.
     * @return float
     */
    private static function price_ex_tax( WC_Product $product, $price = null ) {
        if ( null === $price ) {
            $price = $product->get_regular_price();
        }
        if ( '' === $price || null === $price ) {
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
        return round( (float) $price, 2 );
    }

    /**
     * Convert a Holded net price to the WC price format (adds tax if needed, rounds to 2 decimals).
     *
     * @param float|string $holded_price  Net price from Holded.
     * @param WC_Product   $product
     * @return string  Price string to pass to set_regular_price() / set_sale_price().
     */
    private static function holded_price_to_wc( $holded_price, WC_Product $product ) {
        $price              = (float) $holded_price;
        $prices_include_tax = 'yes' === get_option( 'cthls_prices_include_tax', get_option( 'woocommerce_prices_include_tax', 'no' ) );

        if ( $prices_include_tax
            && 'taxable' === $product->get_tax_status()
            && wc_tax_enabled() ) {
            $tax_rate = self::get_tax_rate( $product );
            if ( $tax_rate > 0 ) {
                $price = $price * ( 1 + $tax_rate / 100 );
            }
        }
        return (string) round( $price, 2 );
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
        // Keep last 50 entries.
        update_option( 'cthls_log', array_slice( $log, 0, 50 ) );
    }
}
