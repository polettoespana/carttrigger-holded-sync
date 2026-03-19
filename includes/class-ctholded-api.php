<?php

defined( 'ABSPATH' ) || exit;

/**
 * Holded REST API client.
 *
 * Base URL: https://api.holded.com/api/invoicing/v1/
 * Auth:     header  key: <api_key>
 */
class CTHOLDED_API {

    const BASE_URL = 'https://api.holded.com/api/invoicing/v1/';

    /** @var string */
    private $api_key;

    public function __construct() {
        $this->api_key = get_option( 'ctholded_api_key', '' );
    }

    // ── Products ────────────────────────────────────────────────────────────

    public function get_products( $page = 1 ) {
        return $this->request( 'GET', 'products', [ 'page' => $page ] );
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
     * Update stock for a product (or variant).
     *
     * @param string $holded_id   Holded product ID.
     * @param int    $stock       Absolute stock quantity.
     * @param string $variant_id  Optional Holded variant ID.
     */
    public function update_stock( $holded_id, $stock, $variant_id = '' ) {
        $body = [ 'stock' => (int) $stock ];
        if ( $variant_id ) {
            $body['variantId'] = $variant_id;
        }
        return $this->request( 'PUT', 'products/' . $holded_id . '/stock', [], $body );
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
            return new WP_Error( 'ctholded_no_api_key', __( 'Holded API key is not configured.', 'carttrigger-holded' ) );
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
            return new WP_Error( 'ctholded_api_error_' . $code, $message );
        }

        return is_array( $decoded ) ? $decoded : [];
    }
}
