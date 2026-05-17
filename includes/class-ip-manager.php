<?php
/**
 * IP block-state and whitelist facade with per-request memoization.
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

if ( ! class_exists( 'ReportedIP_Hive_IP_Manager' ) ) {

	/**
	 * Per-request IP-state cache plus block-/whitelist-checks.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_IP_Manager {

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * Per-request memoization for `is_blocked()` lookups.
		 *
		 * @var array<string, bool>
		 */
		private array $blocked_cache = array();

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
		 * Whether the IP is currently blocked.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_blocked( string $ip_address ): bool {
			if ( '' === $ip_address ) {
				return false;
			}

			if ( array_key_exists( $ip_address, $this->blocked_cache ) ) {
				return $this->blocked_cache[ $ip_address ];
			}

			$blocked                            = $this->database->is_blocked( $ip_address );
			$this->blocked_cache[ $ip_address ] = $blocked;

			return $blocked;
		}

		/**
		 * Clear the per-request memoization for a specific IP (used after
		 * unblocking from the admin UI).
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return void
		 * @since  1.0.0
		 */
		public function forget( string $ip_address ): void {
			unset( $this->blocked_cache[ $ip_address ] );
		}

		/**
		 * Whether the IP is whitelisted via the `reportedip_hive_is_whitelisted`
		 * filter. The plugin ships without a whitelist UI; site owners hook
		 * this filter from a theme or MU-plugin.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_whitelisted( string $ip_address ): bool {
			if ( '' === $ip_address ) {
				return false;
			}

			$db_match = $this->database->is_ip_whitelisted( $ip_address );

			/**
			 * Filter whether an IP should bypass all block/report logic.
			 * Defaults to the database-backed whitelist; site owners can layer
			 * additional logic (e.g. office-network CIDR) by hooking this
			 * filter from a theme or MU-plugin.
			 *
			 * @param bool   $is_whitelisted Whether the IP is currently treated as whitelisted.
			 * @param string $ip_address     IPv4/v6 string.
			 */
			return (bool) apply_filters( 'reportedip_hive_is_whitelisted', $db_match, $ip_address );
		}
	}
}
