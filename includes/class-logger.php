<?php
/**
 * Action-hook based logger sink.
 *
 * The plugin emits security events via `do_action( 'reportedip_hive_log', ... )`.
 * The default listener writes to PHP's error log when WP_DEBUG_LOG is on, and
 * is a no-op otherwise. Site owners can register their own listener to forward
 * events to Loki, Sentry, or any other sink without touching this file.
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

if ( ! class_exists( 'ReportedIP_Hive_Logger' ) ) {

	/**
	 * Default listener for the `reportedip_hive_log` action.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Logger {

		/**
		 * Whether the listener has been registered already (idempotency guard).
		 *
		 * @var bool
		 */
		private static bool $registered = false;

		/**
		 * Register the default listener if `WP_DEBUG_LOG` is enabled.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function register_default_listener(): void {
			if ( self::$registered ) {
				return;
			}

			self::$registered = true;

			if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				return;
			}

			if ( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
				return;
			}

			add_action( 'reportedip_hive_log', array( __CLASS__, 'write' ), 10, 3 );
		}

		/**
		 * Emit a single log line via PHP error_log.
		 *
		 * @param string               $level   One of low|medium|high|critical.
		 * @param string               $message Human-readable message.
		 * @param array<string, mixed> $context Additional context payload.
		 * @return void
		 * @since  1.0.0
		 */
		public static function write( string $level, string $message, array $context = array() ): void {
			$context_string = '';
			if ( ! empty( $context ) ) {
				$encoded = wp_json_encode( $context );
				if ( is_string( $encoded ) ) {
					$context_string = ' ' . $encoded;
				}
			}

			$line = sprintf( '[reportedip-hive][%s] %s%s', $level, $message, $context_string );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug-only logger sink, gated behind WP_DEBUG_LOG above.
			error_log( $line );
		}
	}
}
