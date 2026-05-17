<?php
/**
 * Progressive block-duration ladder.
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

if ( ! class_exists( 'ReportedIP_Hive_Block_Escalation' ) ) {

	/**
	 * Calculates block duration based on prior block history.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Block_Escalation {

		/**
		 * Database service for prior-block lookups.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * Constructor.
		 *
		 * @param ReportedIP_Hive_Database $database Persistence service.
		 * @since 1.0.0
		 */
		public function __construct( ReportedIP_Hive_Database $database ) {
			$this->database = $database;
		}

		/**
		 * Compute the block duration in minutes for the next block of the IP.
		 *
		 * Reads the configured ladder (CSV minutes) and the IP's prior block
		 * count within the reset window. When escalation is disabled the first
		 * step of the ladder is always returned.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return int Block duration in minutes (≥ 1).
		 * @since  1.0.0
		 */
		public function compute_block_minutes( string $ip_address ): int {
			$ladder = $this->parse_ladder( (string) get_option( 'reportedip_hive_block_ladder_minutes', '5,15,30,1440,2880,10080' ) );
			$reset  = (int) get_option( 'reportedip_hive_block_ladder_reset_days', 30 );

			if ( ! get_option( 'reportedip_hive_block_escalation_enabled', true ) ) {
				return $ladder[0];
			}

			$prior = $this->database->count_recent_blocks( $ip_address, $reset );
			$step  = max( 0, min( $prior, count( $ladder ) - 1 ) );

			return $ladder[ $step ];
		}

		/**
		 * Parse the CSV ladder option into a list of positive integers.
		 *
		 * @param string $csv Raw option value.
		 * @return array<int, int>
		 * @since  1.0.0
		 */
		private function parse_ladder( string $csv ): array {
			$parts = array_map( 'trim', explode( ',', $csv ) );
			$out   = array();
			foreach ( $parts as $value ) {
				$int = (int) $value;
				if ( $int > 0 ) {
					$out[] = $int;
				}
			}
			if ( empty( $out ) ) {
				$out = array( 5, 15, 30, 1440, 2880, 10080 );
			}
			return $out;
		}
	}
}
