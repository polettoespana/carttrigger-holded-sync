<?php

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled pull: Holded → WooCommerce.
 *
 * Runs every 15 minutes via WP-Cron.
 */
class CTHOLDED_Cron {

    const HOOK     = 'ctholded_pull_from_holded';
    const INTERVAL = 'ctholded_15min';

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'add_schedule' ] );
        add_action( self::HOOK, [ 'CTHOLDED_Sync', 'pull_from_holded' ] );
    }

    public static function add_schedule( $schedules ) {
        $schedules[ self::INTERVAL ] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => esc_html__( 'Every 15 minutes', 'carttrigger-holded' ),
        ];
        return $schedules;
    }

    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), self::INTERVAL, self::HOOK );
        }
    }

    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }
}
