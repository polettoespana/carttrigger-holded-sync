<?php

defined( 'ABSPATH' ) || exit;

/**
 * Scheduled pull: Holded → WooCommerce via Action Scheduler.
 *
 * Action Scheduler (bundled with WooCommerce) is more reliable than WP-Cron
 * because it has its own queue and does not depend on site traffic.
 *
 * Interval is configurable via cthls_pull_interval (minutes, default: 15).
 */
class CTHLS_Cron {

    const HOOK  = 'cthls_pull_from_holded';
    const GROUP = 'ctholded';

    public static function init() {
        add_action( self::HOOK, [ 'CTHLS_Sync', 'pull_from_holded' ] );
    }

    /**
     * Schedule the recurring action (or reschedule if interval changed).
     *
     * @param int $interval_minutes
     */
    public static function schedule( $interval_minutes = null ) {
        if ( null === $interval_minutes ) {
            $interval_minutes = (int) get_option( 'cthls_pull_interval', 15 );
        }

        $interval_seconds = max( 5, $interval_minutes ) * MINUTE_IN_SECONDS;

        // If already scheduled with the same interval, do nothing.
        $next = as_next_scheduled_action( self::HOOK, [], self::GROUP );
        if ( $next ) {
            // Check if interval changed.
            $scheduled_interval = self::get_scheduled_interval();
            if ( $scheduled_interval === $interval_seconds ) {
                return;
            }
            // Interval changed — reschedule.
            self::unschedule();
        }

        as_schedule_recurring_action(
            time(),
            $interval_seconds,
            self::HOOK,
            [],
            self::GROUP
        );
    }

    public static function unschedule() {
        as_unschedule_all_actions( self::HOOK, [], self::GROUP );
    }

    /**
     * Return the interval in seconds of the currently scheduled action, or 0.
     */
    private static function get_scheduled_interval() {
        $store  = ActionScheduler::store();
        $args   = [
            'hook'     => self::HOOK,
            'group'    => self::GROUP,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 1,
        ];
        $actions = $store->query_actions( $args );
        if ( empty( $actions ) ) {
            return 0;
        }
        $action = $store->fetch_action( reset( $actions ) );
        if ( $action instanceof ActionScheduler_Action ) {
            $schedule = $action->get_schedule();
            if ( method_exists( $schedule, 'get_recurrence' ) ) {
                return (int) $schedule->get_recurrence();
            }
        }
        return 0;
    }
}
