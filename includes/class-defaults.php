<?php
/**
 * Single source of truth for all plugin option defaults.
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

if ( ! class_exists( 'ReportedIP_Hive_Defaults' ) ) {

	/**
	 * Static option-defaults registry.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Defaults {

		/**
		 * All plugin option names mapped to their default values.
		 *
		 * @return array<string, mixed>
		 * @since  1.0.0
		 */
		public static function all(): array {
			return array(
				'reportedip_hive_db_version'               => REPORTEDIP_HIVE_DB_VERSION,

				'reportedip_hive_operation_mode'           => 'local',
				'reportedip_hive_api_key'                  => '',
				'reportedip_hive_api_endpoint'             => 'https://reportedip.de/wp-json/reportedip/v2/',
				'reportedip_hive_trusted_ip_header'        => '',

				'reportedip_hive_failed_login_threshold'   => 5,
				'reportedip_hive_failed_login_timeframe'   => 15,
				'reportedip_hive_password_spray_threshold' => 5,
				'reportedip_hive_password_spray_timeframe' => 10,
				'reportedip_hive_auto_block'               => true,
				'reportedip_hive_block_escalation_enabled' => true,
				'reportedip_hive_block_ladder_minutes'     => '5,15,30,1440,2880,10080',
				'reportedip_hive_block_ladder_reset_days'  => 30,
				'reportedip_hive_report_only_mode'         => false,

				'reportedip_hive_cache_duration'           => 24,
				'reportedip_hive_negative_cache_duration'  => 2,
				'reportedip_hive_max_api_calls_per_hour'   => 100,
				'reportedip_hive_queue_max_age_days'       => 7,
				'reportedip_hive_processing_timeout_minutes' => 10,

				'reportedip_hive_delete_data_on_uninstall' => false,
				'reportedip_hive_wizard_completed'         => false,
			);
		}

		/**
		 * Look up a single default value.
		 *
		 * @param string $option_name Fully prefixed option key.
		 * @return mixed Default value or empty string when unknown.
		 * @since  1.0.0
		 */
		public static function get( string $option_name ) {
			$all = self::all();
			return array_key_exists( $option_name, $all ) ? $all[ $option_name ] : '';
		}

		/**
		 * Idempotently seed every default option that is missing.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function seed_options(): void {
			$sentinel = '__reportedip_hive_missing__';
			foreach ( self::all() as $key => $value ) {
				if ( $sentinel === get_option( $key, $sentinel ) ) {
					add_option( $key, $value, '', true );
				}
			}
		}
	}
}
