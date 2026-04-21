<?php

defined( 'ABSPATH' ) || exit;

/**
 * Holded REST API client.
 *
 * Base URL: https://api.holded.com/api/invoicing/v1/
 * Auth:     header  key: <api_key>
 */
class CTHLS_API {

    const BASE_URL = 'https://api.holded.com/api/invoicing/v1/';

    /** @var string */
    private $api_key;

    public function __construct() {
        $this->api_key = get_option( 'cthls_api_key', '' );
    }

    // ── Products ────────────────────────────────────────────────────────────

    public function get_products( $page = 1 ) {
        return $this->request( 'GET', 'products', [ 'page' => $page ] );
    }

    /**
     * Find a Holded product by SKU.
     * Returns the first matching product array, or null if not found.
     *
     * @param string $sku
     * @return array|null
     */
    public function find_product_by_sku( $sku ) {
        $page = 1;
        do {
            $products = $this->request( 'GET', 'products', [ 'page' => $page ] );
            if ( is_wp_error( $products ) || empty( $products ) ) {
                break;
            }
            foreach ( $products as $p ) {
                if ( isset( $p['sku'] ) && $p['sku'] === $sku ) {
                    return $p;
                }
            }
            $page++;
        } while ( count( $products ) >= 50 && $page <= 50 );

        return null;
    }

    public function get_product( $holded_id ) {
        return $this->request( 'GET', 'products/' . $holded_id );
    }

    public function create_product( array $data ) {
        return $this->request( 'POST', 'products', [], $data );
    }

    public function update_product( $holded_id, array $data ) {
        return $this->request( 'PUT', 'products/' . $holded_id, [], $data );
    }

    public function delete_product( $holded_id ) {
        return $this->request( 'DELETE', 'products/' . $holded_id );
    }

    /**
     * Set absolute stock for a product in Holded.
     *
     * The Holded /stock endpoint is delta-based (positive = add, negative = remove).
     * This method fetches the current Holded stock first and sends the required delta.
     * If the stock is already at the desired value, no API call is made.
     *
     * @param string $holded_id   Holded product ID.
     * @param int    $stock       Desired absolute stock quantity.
     * @param string $variant_id  Unused (kept for backwards compatibility).
     * @return array|WP_Error|null  API response, WP_Error on failure, null if no update needed.
     */
    public function update_stock( $holded_id, $stock, $variant_id = '' ) {
        $desired = (int) $stock;

        // Fetch current Holded stock to compute the delta.
        $product = $this->get_product( $holded_id );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        $current = isset( $product['stock'] ) ? (int) $product['stock'] : 0;
        $delta   = $desired - $current;

        if ( 0 === $delta ) {
            return null; // Already in sync — nothing to do.
        }

        // Store for caller logging.
        $this->last_stock_debug = [ 'holded_current' => $current, 'delta' => $delta ];

        // Holded expects: {"stock": {"<warehouseId>": delta}}
        // where delta is positive to add units, negative to remove.
        $warehouse_id = get_option( 'cthls_warehouse_id', '' );
        if ( ! $warehouse_id ) {
            return new WP_Error( 'cthls_no_warehouse', 'No warehouse configured — cannot update stock.' );
        }
        $body = [ 'stock' => [ $warehouse_id => $delta ] ];
        return $this->request( 'PUT', 'products/' . $holded_id . '/stock', [], $body );
    }

    // ── Contacts ────────────────────────────────────────────────────────────

    /**
     * Search contacts by NIF/CIF (code field) or email.
     * Returns first matching contact array, or null if not found.
     *
     * @param string $nif
     * @param string $email
     * @return array|null|WP_Error
     */
    public function find_contact( $nif = '', $email = '' ) {
        if ( $nif ) {
            $result = $this->request( 'GET', 'contacts', [ 'page' => 1, 'query' => $nif ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            foreach ( (array) $result as $c ) {
                if ( ! empty( $c['code'] ) && strtoupper( trim( $c['code'] ) ) === strtoupper( trim( $nif ) ) ) {
                    return $c;
                }
            }
        }

        if ( $email ) {
            $result = $this->request( 'GET', 'contacts', [ 'page' => 1, 'query' => $email ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            foreach ( (array) $result as $c ) {
                if ( ! empty( $c['email'] ) && strtolower( $c['email'] ) === strtolower( $email ) ) {
                    return $c;
                }
            }
        }

        return null;
    }

    /**
     * Create a contact in Holded.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public function create_contact( array $data ) {
        return $this->request( 'POST', 'contacts', [], $data );
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    /**
     * Create an invoice (factura) in Holded.
     *
     * NOTE: invoices reduce Holded stock automatically.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public function create_invoice( array $data ) {
        return $this->request( 'POST', 'documents/invoice', [], $data );
    }

    /**
     * Create a sales order (pedido de venta) in Holded.
     *
     * Sales orders do NOT reduce Holded stock — safe to use alongside WC stock sync.
     *
     * @param array $data
     * @return array|WP_Error
     */
    public function create_salesorder( array $data ) {
        return $this->request( 'POST', 'documents/salesorder', [], $data );
    }

    // ── Warehouses ──────────────────────────────────────────────────────────

    public function get_warehouses() {
        return $this->request( 'GET', 'warehouses' );
    }

    // ── Connection test ─────────────────────────────────────────────────────

    /**
     * Test the API key by fetching the first page of products.
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        $result = $this->request( 'GET', 'products', [ 'page' => 1 ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    // ── HTTP layer ──────────────────────────────────────────────────────────

    /**
     * @param string $method
     * @param string $endpoint  Relative to BASE_URL.
     * @param array  $query
     * @param array  $body
     * @return array|WP_Error
     */
    private function request( $method, $endpoint, array $query = [], array $body = [], $base_url = '' ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'cthls_no_api_key', __( 'Holded API key is not configured.', 'carttrigger-holded-sync' ) );
        }

        $url = ( $base_url ?: self::BASE_URL ) . ltrim( $endpoint, '/' );
        if ( $query ) {
            $url = add_query_arg( $query, $url );
        }

        $args = [
            'method'  => strtoupper( $method ),
            'headers' => [
                'key'          => $this->api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'timeout' => 20,
        ];

        if ( $body && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code          = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $decoded       = json_decode( $response_body, true );

        if ( $code >= 400 ) {
            $message = isset( $decoded['info'] ) ? $decoded['info'] : $response_body;
            return new WP_Error( 'cthls_api_error_' . $code, $message );
        }

        return is_array( $decoded ) ? $decoded : [];
    }
}
