<?php
/**
 * Resilient HTTP client for the reportedip.de API.
 *
 * Reputation lookups are synchronous on the login path with a 2 s timeout and
 * fail-open semantics (errors do not block legitimate users). A circuit
 * breaker opens after 3 failures within 5 calls and skips the upstream for
 * 5 minutes. Reports are queued in the database and flushed by a cron worker.
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

if ( ! class_exists( 'ReportedIP_Hive_API' ) ) {

	/**
	 * HTTP facade for the reportedip.de community API.
	 *
	 * @since 1.0.0
	 */
	class ReportedIP_Hive_API {

		private const TIMEOUT_REPUTATION = 2;
		private const TIMEOUT_DEFAULT    = 30;

		/**
		 * Cache facade.
		 *
		 * @var ReportedIP_Hive_Cache
		 */
		private ReportedIP_Hive_Cache $cache;

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * Constructor.
		 *
		 * @param ReportedIP_Hive_Cache    $cache    Cache facade.
		 * @param ReportedIP_Hive_Database $database Persistence service.
		 * @since 1.0.0
		 */
		public function __construct( ReportedIP_Hive_Cache $cache, ReportedIP_Hive_Database $database ) {
			$this->cache    = $cache;
			$this->database = $database;
		}

		/**
		 * Whether the plugin is configured to talk to the upstream API.
		 *
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_active(): bool {
			$mode = (string) get_option( 'reportedip_hive_operation_mode', 'local' );
			$key  = (string) get_option( 'reportedip_hive_api_key', '' );
			return ( 'community' === $mode ) && ( '' !== $key );
		}

		/**
		 * Per-hour outbound-call quota status. Returns the configured maximum,
		 * how many have been consumed in the current rolling hour, and the
		 * remaining headroom.
		 *
		 * @return array{max:int,used:int,remaining:int,exhausted:bool}
		 * @since  1.2.0
		 */
		public function get_quota_status(): array {
			$max  = max( 1, (int) get_option( 'reportedip_hive_max_api_calls_per_hour', 100 ) );
			$used = (int) get_transient( 'reportedip_hive_hourly_call_count' );
			$used = max( 0, $used );
			return array(
				'max'       => $max,
				'used'      => $used,
				'remaining' => max( 0, $max - $used ),
				'exhausted' => $used >= $max,
			);
		}

		/**
		 * Increment the hourly outbound-call counter (rolling 1-hour window).
		 *
		 * @return void
		 * @since  1.2.0
		 */
		private function increment_quota_counter(): void {
			$used = (int) get_transient( 'reportedip_hive_hourly_call_count' );
			set_transient( 'reportedip_hive_hourly_call_count', $used + 1, HOUR_IN_SECONDS );
		}

		/**
		 * Synchronously fetch IP-reputation data with caching, breaker and
		 * fail-open semantics. Returns `false` when no decision can be made.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return array<string, mixed>|false
		 * @since  1.0.0
		 */
		public function check_ip_reputation( string $ip_address ) {
			if ( ! $this->is_active() ) {
				return false;
			}

			$cached = $this->cache->get_reputation( $ip_address );
			if ( false !== $cached ) {
				return $cached;
			}

			if ( $this->cache->is_breaker_open() || $this->cache->is_rate_limited() ) {
				return false;
			}

			$quota = $this->get_quota_status();
			if ( $quota['exhausted'] ) {
				return false;
			}

			$response = $this->make_request( 'GET', 'check', array( 'ip' => $ip_address ), null, self::TIMEOUT_REPUTATION );
			$this->increment_quota_counter();

			if ( is_wp_error( $response ) ) {
				$this->cache->record_call_outcome( false );
				$this->cache->set_reputation( $ip_address, array( 'error' => $response->get_error_code() ), true );
				return false;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$body   = wp_remote_retrieve_body( $response );

			if ( 429 === $status ) {
				$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$this->cache->set_rate_limited( $retry > 0 ? $retry : 600 );
				$this->cache->record_call_outcome( false );
				return false;
			}

			if ( 200 !== $status ) {
				$this->cache->record_call_outcome( false );
				$this->cache->set_reputation( $ip_address, array( 'error' => 'http_' . $status ), true );
				return false;
			}

			$decoded = json_decode( $body, true );
			if ( ! is_array( $decoded ) ) {
				$this->cache->record_call_outcome( false );
				return false;
			}

			$this->cache->record_call_outcome( true );
			$payload = isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : $decoded;
			$this->cache->set_reputation( $ip_address, $payload, false );

			return $payload;
		}

		/**
		 * Verify the configured Community Access Key against the upstream.
		 *
		 * @param string $api_key Key to verify (allows testing before save).
		 * @return array{valid: bool, message: string}
		 * @since  1.0.0
		 */
		public function verify_key( string $api_key ): array {
			if ( '' === $api_key ) {
				return array(
					'valid'   => false,
					'message' => __( 'No access key provided.', 'reportedip-hive' ),
				);
			}

			$response = $this->make_request( 'GET', 'verify-key', array(), null, self::TIMEOUT_DEFAULT, $api_key );

			if ( is_wp_error( $response ) ) {
				return array(
					'valid'   => false,
					'message' => sprintf(
						/* translators: %s: human-readable error message from wp_remote */
						__( 'Could not reach reportedip.de: %s', 'reportedip-hive' ),
						$response->get_error_message()
					),
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $status ) {
				return array(
					'valid'   => true,
					'message' => __( 'Connection successful. Your access key is valid.', 'reportedip-hive' ),
				);
			}

			if ( 401 === $status || 403 === $status ) {
				return array(
					'valid'   => false,
					'message' => __( 'Connection failed: invalid access key. Check the key and try again.', 'reportedip-hive' ),
				);
			}

			return array(
				'valid'   => false,
				'message' => sprintf(
					/* translators: %d: HTTP status code */
					__( 'Connection failed (HTTP %d). Please retry in a few minutes.', 'reportedip-hive' ),
					$status
				),
			);
		}

		/**
		 * Submit a single queued report.
		 *
		 * @param string $ip_address    IPv4/v6 string.
		 * @param string $category_ids  Comma-separated category IDs.
		 * @param string $comment       Optional comment.
		 * @return array{success: bool, message: string}
		 * @since  1.0.0
		 */
		public function report_ip( string $ip_address, string $category_ids, string $comment = '' ): array {
			if ( ! $this->is_active() ) {
				return array(
					'success' => false,
					'message' => 'inactive',
				);
			}

			if ( $this->cache->is_rate_limited() ) {
				return array(
					'success' => false,
					'message' => 'rate_limited',
				);
			}

			$body = array(
				'ip'         => $ip_address,
				'categories' => $category_ids,
			);
			if ( '' !== $comment ) {
				$body['comment'] = $comment;
			}

			$response = $this->make_request( 'POST', 'report', array(), $body, self::TIMEOUT_DEFAULT );

			if ( is_wp_error( $response ) ) {
				$this->cache->record_call_outcome( false );
				return array(
					'success' => false,
					'message' => $response->get_error_message(),
				);
			}

			$status = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 === $status ) {
				$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				$this->cache->set_rate_limited( $retry > 0 ? $retry : 600 );
				$this->cache->record_call_outcome( false );
				return array(
					'success' => false,
					'message' => 'rate_limited',
				);
			}

			if ( 200 === $status || 201 === $status ) {
				$this->cache->record_call_outcome( true );
				return array(
					'success' => true,
					'message' => 'ok',
				);
			}

			$this->cache->record_call_outcome( false );
			return array(
				'success' => false,
				'message' => 'http_' . $status,
			);
		}

		/**
		 * Drain the queued-reports table; called from the cron worker.
		 *
		 * @param int $limit Maximum reports per run.
		 * @return int Number of reports dispatched (success or final failure).
		 * @since  1.0.0
		 */
		public function process_report_queue( int $limit = 10 ): int {
			$rows = $this->database->claim_pending_reports( $limit );
			if ( empty( $rows ) ) {
				return 0;
			}

			$processed = 0;
			foreach ( $rows as $row ) {
				$id           = (int) $row['id'];
				$ip           = (string) $row['ip_address'];
				$category_ids = (string) $row['category_ids'];
				$comment      = (string) ( $row['comment'] ?? '' );

				$result = $this->report_ip( $ip, $category_ids, $comment );
				if ( $result['success'] ) {
					$this->database->mark_report_completed( $id );
				} else {
					$this->database->mark_report_failed( $id, (string) $result['message'] );
				}
				++$processed;
			}

			return $processed;
		}

		/**
		 * Build and execute a single HTTP request via wp_remote_request.
		 *
		 * @param string                                $method        HTTP verb.
		 * @param string                                $endpoint      Path under the base endpoint.
		 * @param array<string, scalar>                 $query_params  GET parameters.
		 * @param array<string, scalar>|null            $body          POST body (sent as JSON).
		 * @param int                                   $timeout       Timeout in seconds.
		 * @param string|null                           $api_key       Optional override for the access key (used by verify_key).
		 * @return array<string, mixed>|WP_Error
		 * @since  1.0.0
		 */
		private function make_request( string $method, string $endpoint, array $query_params, ?array $body, int $timeout, ?string $api_key = null ) {
			$base = (string) get_option( 'reportedip_hive_api_endpoint', 'https://reportedip.de/wp-json/reportedip/v2/' );
			/**
			 * Filter the API base endpoint.
			 *
			 * @param string $base Base URL with trailing slash.
			 */
			$base = (string) apply_filters( 'reportedip_hive_api_endpoint', $base );
			$base = trailingslashit( $base );

			$url = $base . ltrim( $endpoint, '/' );
			if ( ! empty( $query_params ) ) {
				$url = add_query_arg( $query_params, $url );
			}

			$key = null === $api_key ? (string) get_option( 'reportedip_hive_api_key', '' ) : $api_key;

			$args = array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'X-Key'        => $key,
					'User-Agent'   => 'ReportedIP-Hive/' . REPORTEDIP_HIVE_VERSION,
				),
			);

			if ( null !== $body ) {
				$encoded = wp_json_encode( $body );
				if ( is_string( $encoded ) ) {
					$args['body'] = $encoded;
				}
			}

			return wp_remote_request( $url, $args );
		}
	}
}
