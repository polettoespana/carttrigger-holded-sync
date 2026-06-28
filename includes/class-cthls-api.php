<?php

defined( 'ABSPATH' ) || exit;

/**
 * Holded REST API client.
 *
 * Base URL: https://api.holded.com/api/v2/
 * Auth:     Authorization: Bearer <api_key>
 */
class CTHLS_API {

    const BASE_URL = 'https://api.holded.com/api/v2/';

    /** @var string */
    private $api_key;

    public function __construct() {
        $this->api_key = get_option( 'cthls_api_key', '' );
    }

    // ── Products ────────────────────────────────────────────────────────────

    /**
     * Fetch one page of products using cursor-based pagination.
     *
     * Returns array with keys: items (product array), cursor (string), has_more (bool).
     *
     * @param string $cursor  Opaque cursor from the previous response; empty for first page.
     * @return array|WP_Error
     */
    public function get_products( $cursor = '' ) {
        $query = [ 'limit' => 100 ];
        if ( $cursor ) {
            $query['cursor'] = $cursor;
        }
        return $this->request( 'GET', 'products', $query );
    }

    /**
     * Find a Holded product by SKU.
     * Returns the first matching product array, or null if not found.
     *
     * @param string $sku
     * @return array|null
     */
    public function find_product_by_sku( $sku ) {
        $cursor     = '';
        $iterations = 0;
        do {
            $response = $this->get_products( $cursor );
            if ( is_wp_error( $response ) || empty( $response['items'] ) ) {
                break;
            }
            foreach ( $response['items'] as $p ) {
                if ( isset( $p['sku'] ) && $p['sku'] === $sku ) {
                    return $p;
                }
            }
            $cursor = isset( $response['cursor'] ) ? $response['cursor'] : '';
            $iterations++;
        } while ( ! empty( $response['has_more'] ) && $iterations < 50 );

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
     * Upload an image file to a Holded product.
     *
     * @param string $holded_id   Holded product ID.
     * @param string $file_path   Absolute filesystem path to the image.
     * @return array|WP_Error
     */
    public function upload_product_image( $holded_id, $file_path ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'cthls_no_api_key', __( 'Holded API key is not configured.', 'carttrigger-holded-sync' ) );
        }
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'cthls_no_image_file', 'Image file not found: ' . $file_path );
        }

        $file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $file_content ) {
            return new WP_Error( 'cthls_image_read_error', 'Could not read image file.' );
        }

        $filename = basename( $file_path );
        $mime     = mime_content_type( $file_path ) ?: 'image/jpeg';
        $boundary = wp_generate_password( 24, false );

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $file_content . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $url  = self::BASE_URL . 'products/' . $holded_id . '/images';
        $args = [
            'method'  => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
                'Accept'        => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 30,
        ];

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 ) {
            $message = isset( $decoded['detail'] ) ? $decoded['detail']
                     : ( isset( $decoded['info'] ) ? $decoded['info'] : wp_remote_retrieve_body( $response ) );
            return new WP_Error( 'cthls_api_error_' . $code, $message );
        }

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Set absolute stock for a product in Holded.
     *
     * The Holded /stock endpoint is delta-based (positive = add, negative = remove).
     * Body format: {"stock": {"<warehouseId>": {"<productId>": delta}}}
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

        $warehouse_id = get_option( 'cthls_warehouse_id', '' );
        if ( ! $warehouse_id ) {
            return new WP_Error( 'cthls_no_warehouse', 'No warehouse configured — cannot update stock.' );
        }

        // Fetch current Holded stock to compute the delta.
        $product = $this->get_product( $holded_id );
        if ( is_wp_error( $product ) ) {
            return $product;
        }
        // v2 returns stock as a string (e.g. "5") — normalise to int.
        $current = isset( $product['stock'] ) ? (int) str_replace( ',', '.', (string) $product['stock'] ) : 0;
        $delta   = $desired - $current;

        if ( 0 === $delta ) {
            return null; // Already in sync — nothing to do.
        }

        // Store for caller logging.
        $this->last_stock_debug = [ 'holded_current' => $current, 'delta' => $delta ];

        // v2 stock body: {"warehouse_id":"…","variant_id":null,"stock_variation":delta}
        $body = [
            'warehouse_id'    => $warehouse_id,
            'variant_id'      => $variant_id ?: null,
            'stock_variation' => $delta,
        ];
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
            // v2: filter by code field (NIF/CIF) directly.
            $result = $this->request( 'GET', 'contacts', [ 'code' => $nif, 'limit' => 1 ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            foreach ( (array) ( $result['items'] ?? [] ) as $c ) {
                if ( ! empty( $c['code'] ) && strtoupper( trim( $c['code'] ) ) === strtoupper( trim( $nif ) ) ) {
                    return $c;
                }
            }
        }

        if ( $email ) {
            // v2: search by name (best-effort for email); filter client-side by email match.
            $result = $this->request( 'GET', 'contacts/search', [ 'name' => $email ] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            foreach ( (array) ( $result['items'] ?? [] ) as $c ) {
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
        return $this->request( 'POST', 'invoices', [], $data );
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
        return $this->request( 'POST', 'sales-orders', [], $data );
    }

    // ── Warehouses ──────────────────────────────────────────────────────────

    public function get_warehouses() {
        $response = $this->request( 'GET', 'warehouses' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return $response['items'] ?? [];
    }

    /**
     * Return the list of taxes configured in Holded.
     * Result is cached in a transient for 1 hour.
     *
     * @return array  Array of tax objects (each with at least 'id' and 'rate' keys).
     */
    public function get_taxes() {
        $cached = get_transient( 'cthls_holded_taxes' );
        if ( false !== $cached ) {
            return $cached;
        }
        $response = $this->request( 'GET', 'taxes' );
        if ( is_wp_error( $response ) ) {
            return [];
        }
        $taxes = $response['items'] ?? ( is_array( $response ) ? $response : [] );
        set_transient( 'cthls_holded_taxes', $taxes, HOUR_IN_SECONDS );
        return $taxes;
    }

    // ── Connection test ─────────────────────────────────────────────────────

    /**
     * Test the API key by fetching the first page of products.
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        $result = $this->get_products();
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
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
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
