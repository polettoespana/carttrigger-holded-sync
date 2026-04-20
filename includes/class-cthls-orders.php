<?php

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce order → Holded document sync.
 *
 * On payment confirmation (woocommerce_payment_complete):
 *   1. Finds or creates the Holded contact (by NIF then by email).
 *   2. Saves the Holded contact ID in order meta and, for logged-in users, in user meta.
 *   3. Creates an invoice (factura) or sales order (pedido de venta) in Holded.
 *   4. Saves the Holded document ID in order meta (_cthls_invoice_id).
 *
 * Only fires for paid orders — woocommerce_payment_complete is triggered by payment
 * gateways (Stripe, Redsys, PayPal, etc.) after a successful transaction.
 *
 * NOTE on stock: invoices reduce Holded stock; sales orders do not.
 * If WC→Holded stock push is enabled, use sales orders to avoid double stock reduction.
 *
 * Enabled/disabled via the cthls_create_invoices option.
 * Document type: cthls_document_type — 'invoice' (default) or 'salesorder'.
 * NIF meta key: cthls_nif_meta_key (default: _billing_nif).
 */
class CTHLS_Orders {

    /** @var CTHLS_API */
    private static $api;

    public static function init() {
        self::$api = new CTHLS_API();

        if ( self::invoices_enabled() ) {
            add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_payment_complete' ] );
        }
    }

    // ── Main handler ─────────────────────────────────────────────────────────

    /**
     * Hook: woocommerce_payment_complete.
     * Fires only when a payment gateway marks the order as paid.
     *
     * @param int $order_id
     */
    public static function on_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! ( $order instanceof WC_Order ) ) {
            return;
        }

        // Guard: skip if invoice already created (e.g. hook fired twice).
        if ( $order->get_meta( '_cthls_invoice_id' ) ) {
            return;
        }

        $order_id = $order->get_id();

        // ── 1. Find or create contact ────────────────────────────────────────
        $contact_id = self::resolve_contact( $order );
        if ( is_wp_error( $contact_id ) ) {
            self::log( 'order_contact_error', $order_id, $contact_id->get_error_message() );
            return;
        }

        $order->update_meta_data( '_cthls_contact_id', sanitize_text_field( $contact_id ) );

        // Persist contact ID on the WP user for future orders.
        $user_id = $order->get_customer_id();
        if ( $user_id ) {
            update_user_meta( $user_id, '_cthls_contact_id', sanitize_text_field( $contact_id ) );
        }

        $order->save();
        self::log( 'order_contact_resolved', $order_id, $contact_id );

        // ── 2. Create document (invoice or sales order) ──────────────────────
        $doc_type = self::document_type();
        $doc_data = self::build_invoice( $order, $contact_id );
        $result   = ( 'salesorder' === $doc_type )
            ? self::$api->create_salesorder( $doc_data )
            : self::$api->create_invoice( $doc_data );

        if ( is_wp_error( $result ) ) {
            self::log( 'order_invoice_error', $order_id, $result->get_error_message() );
            return;
        }

        if ( ! empty( $result['id'] ) ) {
            $order->update_meta_data( '_cthls_invoice_id', sanitize_text_field( $result['id'] ) );
            $order->save();
            self::log( 'order_invoice_created', $order_id, $doc_type . ':' . $result['id'] );
        }

        // ── 3. Re-sync stock to Holded (only for invoices, optional) ─────────
        // Holded invoices automatically reduce stock. If WC→Holded stock push is
        // also active, this would cause a double reduction. When this option is
        // enabled, we push the actual WC stock back to Holded after the document
        // is created, so Holded always reflects WooCommerce as the source of truth.
        if ( 'invoice' === $doc_type && self::resync_stock_enabled() ) {
            self::resync_order_stock( $order );
        }
    }

    /**
     * After an invoice is created, push the current WC stock of each ordered
     * product back to Holded to undo the stock reduction the invoice caused.
     *
     * @param WC_Order $order
     */
    private static function resync_order_stock( WC_Order $order ) {
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product || ! $product->managing_stock() ) {
                continue;
            }

            $holded_id = get_post_meta( $product->get_id(), '_cthls_product_id', true );
            if ( ! $holded_id ) {
                continue;
            }

            $stock  = (int) $product->get_stock_quantity();
            $result = self::$api->update_stock( $holded_id, $stock );

            if ( is_wp_error( $result ) ) {
                self::log( 'order_stock_resync_error', $order->get_id(), $product->get_id() . ': ' . $result->get_error_message() );
            } else {
                self::log( 'order_stock_resynced', $order->get_id(), 'product ' . $product->get_id() . ' → stock ' . $stock );
            }
        }
    }

    // ── Contact resolution ────────────────────────────────────────────────────

    /**
     * Returns the Holded contact ID for the order, finding or creating it as needed.
     *
     * Priority:
     *  1. Already stored on the order (_cthls_contact_id).
     *  2. Stored on the WP user (_cthls_contact_id).
     *  3. Search Holded by NIF, then by email.
     *  4. Create new contact.
     *
     * @param WC_Order $order
     * @return string|WP_Error  Holded contact ID.
     */
    private static function resolve_contact( WC_Order $order ) {
        // Already on the order (e.g. retry).
        $stored = $order->get_meta( '_cthls_contact_id' );
        if ( $stored ) {
            return $stored;
        }

        // Stored on the WP user.
        $user_id = $order->get_customer_id();
        if ( $user_id ) {
            $user_contact_id = get_user_meta( $user_id, '_cthls_contact_id', true );
            if ( $user_contact_id ) {
                return $user_contact_id;
            }
        }

        $nif_key   = get_option( 'cthls_nif_meta_key', '_billing_nif' );
        $email_key = get_option( 'cthls_email_meta_key', '' );
        $nif       = trim( (string) $order->get_meta( $nif_key ) );
        $email     = ( $email_key ? trim( (string) $order->get_meta( $email_key ) ) : '' )
                     ?: $order->get_billing_email();

        // Search in Holded.
        $contact = self::$api->find_contact( $nif, $email );
        if ( is_wp_error( $contact ) ) {
            return $contact;
        }
        if ( $contact && ! empty( $contact['id'] ) ) {
            return $contact['id'];
        }

        // Create new contact.
        $data   = self::order_to_contact( $order, $nif );
        $result = self::$api->create_contact( $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['id'] ) ) {
            return new WP_Error( 'cthls_contact_no_id', __( 'Contact created but no ID returned by Holded.', 'carttrigger-holded-sync' ) );
        }

        self::log( 'order_contact_created', $order->get_id(), $result['id'] );
        return $result['id'];
    }

    /**
     * Build the Holded contact payload from a WC order.
     *
     * @param WC_Order $order
     * @param string   $nif
     * @return array
     */
    private static function order_to_contact( WC_Order $order, $nif = '' ) {
        $company    = trim( (string) $order->get_billing_company() );
        $first_name = trim( (string) $order->get_billing_first_name() );
        $last_name  = trim( (string) $order->get_billing_last_name() );
        $name       = $company ?: trim( $first_name . ' ' . $last_name );

        $data = [
            'name'       => $name,
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
            'type'       => 'client',
            'address'    => $order->get_billing_address_1(),
            'city'       => $order->get_billing_city(),
            'postalCode' => $order->get_billing_postcode(),
            'province'   => $order->get_billing_state(),
            'country'    => $order->get_billing_country(),
        ];

        if ( $nif ) {
            $data['code'] = strtoupper( $nif );
        }

        return array_filter( $data, static function ( $v ) {
            return $v !== '' && $v !== null;
        } );
    }

    // ── Invoice builder ───────────────────────────────────────────────────────

    /**
     * Build the Holded invoice payload from a WC order.
     *
     * @param WC_Order $order
     * @param string   $contact_id
     * @return array
     */
    private static function build_invoice( WC_Order $order, $contact_id ) {
        $items = [];

        // ── Order line items ─────────────────────────────────────────────────
        foreach ( $order->get_items() as $item ) {
            /** @var WC_Order_Item_Product $item */
            $qty        = (int) $item->get_quantity();
            $line_total = (float) $item->get_total();      // net, after discounts
            $line_tax   = (float) array_sum( $item->get_taxes()['total'] ?? [] );

            $unit_price = $qty > 0 ? round( $line_total / $qty, 6 ) : 0.0;
            $tax_rate   = $line_total > 0 ? round( $line_tax / $line_total * 100, 2 ) : 0.0;

            $line = [
                'name'     => $item->get_name(),
                'units'    => $qty,
                'subtotal' => round( $unit_price, 2 ),
                'discount' => 0,
                'tax'      => $tax_rate,
            ];

            // Link to the Holded product if it exists.
            $product = $item->get_product();
            if ( $product ) {
                $holded_product_id = get_post_meta( $product->get_id(), '_cthls_product_id', true );
                if ( $holded_product_id ) {
                    $line['productId'] = $holded_product_id;
                }
            }

            $items[] = $line;
        }

        // ── Shipping ─────────────────────────────────────────────────────────
        foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
            /** @var WC_Order_Item_Shipping $shipping_item */
            $shipping_total = (float) $shipping_item->get_total();
            if ( $shipping_total <= 0 ) {
                continue;
            }
            $shipping_tax = (float) array_sum( $shipping_item->get_taxes()['total'] ?? [] );
            $tax_rate     = $shipping_total > 0 ? round( $shipping_tax / $shipping_total * 100, 2 ) : 0.0;

            $items[] = [
                'name'     => $shipping_item->get_name() ?: __( 'Shipping', 'carttrigger-holded-sync' ),
                'units'    => 1,
                'subtotal' => round( $shipping_total, 2 ),
                'discount' => 0,
                'tax'      => $tax_rate,
            ];
        }

        $invoice = [
            'contactId' => $contact_id,
            'date'      => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
            'notes'     => sprintf(
                /* translators: %s: WooCommerce order number */
                __( 'WooCommerce order #%s', 'carttrigger-holded-sync' ),
                $order->get_order_number()
            ),
            'currency'  => $order->get_currency(),
            'items'     => $items,
        ];

        return $invoice;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function invoices_enabled() {
        return (bool) get_option( 'cthls_create_invoices', false );
    }

    public static function document_type() {
        return get_option( 'cthls_document_type', 'invoice' ) === 'salesorder' ? 'salesorder' : 'invoice';
    }

    public static function resync_stock_enabled() {
        return (bool) get_option( 'cthls_resync_stock_after_invoice', false );
    }

    private static function log( $event, $order_id, $message ) {
        if ( ! get_option( 'cthls_debug_log', false ) ) {
            return;
        }
        $log = get_option( 'cthls_log', [] );
        array_unshift( $log, [
            'time'       => current_time( 'mysql' ),
            'event'      => $event,
            'product_id' => $order_id,
            'message'    => $message,
        ] );
        update_option( 'cthls_log', array_slice( $log, 0, 50 ) );
    }
}
