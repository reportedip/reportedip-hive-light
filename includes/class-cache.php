<?php
/**
 * Reputation cache + rate-limit + circuit-breaker state.
 *
 * Wraps WordPress's object cache with a 24 h positive / 2 h negative TTL for
 * IP reputation responses, plus transient-based markers for upstream rate-limit
 * and circuit-breaker state.
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

if ( ! class_exists( 'ReportedIP_Hive_Cache' ) ) {

	/**
	 * Cache facade for reputation + breaker state.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Cache {

		/**
		 * Object-cache group name. Plugin uninstall flushes this group.
		 */
		private const CACHE_GROUP = 'reportedip';

		/**
		 * Read a cached reputation entry for an IP.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return array<string, mixed>|false Cached payload or `false` when missing.
		 * @since  1.0.0
		 */
		public function get_reputation( string $ip_address ) {
			$key   = $this->reputation_cache_key( $ip_address );
			$value = wp_cache_get( $key, self::CACHE_GROUP );

			return is_array( $value ) ? $value : false;
		}

		/**
		 * Store a reputation entry with positive- or negative-TTL.
		 *
		 * @param string               $ip_address  IPv4/v6 string.
		 * @param array<string, mixed> $data        Payload to cache.
		 * @param bool                 $is_negative `true` for "no data found / error" entries.
		 * @return void
		 * @since  1.0.0
		 */
		public function set_reputation( string $ip_address, array $data, bool $is_negative = false ): void {
			$ttl_hours = $is_negative
				? (int) get_option( 'reportedip_hive_negative_cache_duration', 2 )
				: (int) get_option( 'reportedip_hive_cache_duration', 24 );

			if ( $ttl_hours < 1 ) {
				$ttl_hours = $is_negative ? 2 : 24;
			}

			wp_cache_set(
				$this->reputation_cache_key( $ip_address ),
				$data,
				self::CACHE_GROUP,
				$ttl_hours * HOUR_IN_SECONDS
			);
		}

		/**
		 * Whether the upstream API is currently rate-limited (HTTP 429).
		 *
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_rate_limited(): bool {
			return false !== get_transient( 'reportedip_hive_rate_limit' );
		}

		/**
		 * Mark the API as rate-limited until `$retry_after_seconds` from now.
		 *
		 * @param int $retry_after_seconds Seconds until the limit clears.
		 * @return void
		 * @since  1.0.0
		 */
		public function set_rate_limited( int $retry_after_seconds ): void {
			$ttl = max( 60, min( $retry_after_seconds, HOUR_IN_SECONDS ) );
			set_transient( 'reportedip_hive_rate_limit', 1, $ttl );
		}

		/**
		 * Whether the circuit breaker is currently open (skip remote calls).
		 *
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_breaker_open(): bool {
			return false !== get_transient( 'reportedip_hive_api_breaker_open' );
		}

		/**
		 * Open the circuit breaker for 5 minutes.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function open_breaker(): void {
			set_transient( 'reportedip_hive_api_breaker_open', 1, 5 * MINUTE_IN_SECONDS );
		}

		/**
		 * Append the outcome of a remote call to the rolling window used by
		 * the circuit breaker (last 5 calls). Opens the breaker when 3 of the
		 * last 5 outcomes are failures.
		 *
		 * @param bool $success Whether the call succeeded.
		 * @return void
		 * @since  1.0.0
		 */
		public function record_call_outcome( bool $success ): void {
			$key     = 'reportedip_hive_api_outcomes';
			$history = get_transient( $key );
			if ( ! is_array( $history ) ) {
				$history = array();
			}

			$history[] = $success ? 1 : 0;
			$history   = array_slice( $history, -5 );

			set_transient( $key, $history, 30 * MINUTE_IN_SECONDS );

			if ( count( $history ) >= 5 ) {
				$failures = count( array_filter( $history, static fn( int $v ): bool => 0 === $v ) );
				if ( $failures >= 3 ) {
					$this->open_breaker();
				}
			}
		}

		/**
		 * Build a stable, prefix-namespaced cache key for an IP.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return string
		 * @since  1.0.0
		 */
		private function reputation_cache_key( string $ip_address ): string {
			return 'reputation_' . md5( $ip_address );
		}
	}
}
