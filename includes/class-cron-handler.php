<?php
/**
 * Two scheduled tasks: API report queue worker (15 min) and cleanup (daily).
 *
 * @package   ReportedIP_Hive
 * @author    Patrick Schlesinger <1@reportedip.de>
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://wordpress.org/plugins/reportedip-hive/
 * @since     1.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ReportedIP_Hive_Cron_Handler' ) ) {

	/**
	 * Schedules and runs the plugin's two cron hooks.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Cron_Handler {

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * API client.
		 *
		 * @var ReportedIP_Hive_API
		 */
		private ReportedIP_Hive_API $api_client;

		/**
		 * Constructor.
		 *
		 * @param ReportedIP_Hive_Database $database   Persistence service.
		 * @param ReportedIP_Hive_API      $api_client HTTP client.
		 * @since 1.0.0
		 */
		public function __construct( ReportedIP_Hive_Database $database, ReportedIP_Hive_API $api_client ) {
			$this->database   = $database;
			$this->api_client = $api_client;
		}

		/**
		 * Register both cron events if not already scheduled (idempotent).
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function schedule_jobs(): void {
			self::ensure_schedule( 'reportedip_hive_process_queue', 'fifteen_minutes' );
			self::ensure_schedule( 'reportedip_hive_cleanup', 'daily' );
		}

		/**
		 * Unregister both cron events (idempotent).
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function clear_jobs(): void {
			wp_clear_scheduled_hook( 'reportedip_hive_process_queue' );
			wp_clear_scheduled_hook( 'reportedip_hive_cleanup' );
		}

		/**
		 * Hook callback for the queue worker.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function run_queue_worker(): void {
			if ( ! $this->api_client->is_active() ) {
				return;
			}

			$processed = $this->api_client->process_report_queue( 10 );

			do_action(
				'reportedip_hive_log',
				'low',
				'queue worker run',
				array( 'processed' => $processed )
			);
		}

		/**
		 * Hook callback for the daily cleanup.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function run_cleanup(): void {
			$retention_attempts_minutes = max(
				(int) get_option( 'reportedip_hive_failed_login_timeframe', 15 ),
				(int) get_option( 'reportedip_hive_password_spray_timeframe', 10 )
			) * 4;

			$queue_retention = (int) get_option( 'reportedip_hive_queue_max_age_days', 7 );

			$attempts_deleted = $this->database->cleanup_old_attempts( $retention_attempts_minutes );
			$blocks_expired   = $this->database->cleanup_expired_blocks();
			$queue_deleted    = $this->database->cleanup_old_queue_rows( $queue_retention );

			do_action(
				'reportedip_hive_log',
				'low',
				'cleanup run',
				array(
					'attempts_deleted' => $attempts_deleted,
					'blocks_expired'   => $blocks_expired,
					'queue_deleted'    => $queue_deleted,
				)
			);
		}

		/**
		 * Schedule a cron event if it isn't already scheduled.
		 *
		 * @param string $hook        Hook name.
		 * @param string $recurrence  WP-cron schedule key.
		 * @return void
		 * @since  1.0.0
		 */
		private static function ensure_schedule( string $hook, string $recurrence ): void {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, $hook );
			}
		}
	}
}

add_filter(
	'cron_schedules',
	static function ( $schedules ) {
		if ( ! isset( $schedules['fifteen_minutes'] ) ) {
			$schedules['fifteen_minutes'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes', 'reportedip-hive' ),
			);
		}
		return $schedules;
	}
);
