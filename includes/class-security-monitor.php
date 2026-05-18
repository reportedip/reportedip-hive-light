<?php
/**
 * Failed-login + password-spray sensor and threshold dispatcher.
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

if ( ! class_exists( 'ReportedIP_Hive_Security_Monitor' ) ) {

	/**
	 * Security event handler — wires WordPress login hooks to the storage and
	 * API layers.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Security_Monitor {

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * IP manager.
		 *
		 * @var ReportedIP_Hive_IP_Manager
		 */
		private ReportedIP_Hive_IP_Manager $ip_manager;

		/**
		 * API client.
		 *
		 * @var ReportedIP_Hive_API
		 */
		private ReportedIP_Hive_API $api_client;

		/**
		 * Block-escalation calculator (lazily constructed).
		 *
		 * @var ReportedIP_Hive_Block_Escalation
		 */
		private ReportedIP_Hive_Block_Escalation $escalation;

		/**
		 * Constructor.
		 *
		 * @param ReportedIP_Hive_Database    $database  Persistence service.
		 * @param ReportedIP_Hive_IP_Manager  $ip_manager IP block-state facade.
		 * @param ReportedIP_Hive_API         $api_client HTTP client.
		 * @since 1.0.0
		 */
		public function __construct( ReportedIP_Hive_Database $database, ReportedIP_Hive_IP_Manager $ip_manager, ReportedIP_Hive_API $api_client ) {
			$this->database   = $database;
			$this->ip_manager = $ip_manager;
			$this->api_client = $api_client;
			$this->escalation = new ReportedIP_Hive_Block_Escalation( $database );
		}

		/**
		 * Hook callback: `wp_login_failed` — record the attempt and dispatch
		 * thresholds when exceeded.
		 *
		 * Already-blocked IPs short-circuit immediately. The threshold has
		 * already been dispatched for this attack window; continuing to
		 * `track_attempt` would keep climbing the block-escalation ladder
		 * and queue duplicate community reports while the attacker simply
		 * retries against the locked door.
		 *
		 * @param string $username Submitted username (any string).
		 * @return void
		 * @since  1.0.0
		 */
		public function handle_failed_login( $username ): void {
			$ip = ReportedIP_Hive::get_client_ip();
			if ( '' === $ip ) {
				return;
			}

			if ( $this->ip_manager->is_whitelisted( $ip ) ) {
				return;
			}

			if ( $this->ip_manager->is_blocked( $ip ) ) {
				return;
			}

			$user_string = is_string( $username ) ? $username : '';
			$hash        = '' !== $user_string ? hash( 'sha256', $user_string . wp_salt() ) : null;
			$user_agent  = $this->current_user_agent();

			$timeframe_login = (int) get_option( 'reportedip_hive_failed_login_timeframe', 15 );
			$timeframe_spray = (int) get_option( 'reportedip_hive_password_spray_timeframe', 10 );

			$this->database->track_attempt( $ip, 'login', $hash, $user_agent, $timeframe_login );
			if ( null !== $hash ) {
				$this->database->track_attempt( $ip, 'spray_sample', $hash, $user_agent, $timeframe_spray );
			}

			$threshold_login = (int) get_option( 'reportedip_hive_failed_login_threshold', 5 );
			$threshold_spray = (int) get_option( 'reportedip_hive_password_spray_threshold', 5 );

			$count_login    = $this->database->get_attempt_count( $ip, 'login', $timeframe_login );
			$distinct_spray = $this->database->count_distinct_spray_usernames( $ip, $timeframe_spray );

			if ( $count_login >= $threshold_login ) {
				$this->handle_threshold_exceeded(
					$ip,
					'failed_login',
					sprintf( '%d failed logins in %d minutes', $count_login, $timeframe_login ),
					'18'
				);
				return;
			}

			if ( $distinct_spray >= $threshold_spray ) {
				$this->handle_threshold_exceeded(
					$ip,
					'password_spray',
					sprintf( '%d distinct usernames sprayed in %d minutes', $distinct_spray, $timeframe_spray ),
					'18,31'
				);
			}
		}

		/**
		 * Hook callback: `wp_authenticate_user` — short-circuit when the IP
		 * is already blocked or has a poor community reputation.
		 *
		 * @param mixed  $user     Existing WP_User|WP_Error|null.
		 * @param string $password Submitted password (unused).
		 * @return mixed `WP_Error` to deny, `$user` to pass through.
		 * @since  1.0.0
		 */
		public function pre_auth_check( $user, $password ) {
			unset( $password );

			$ip = ReportedIP_Hive::get_client_ip();
			if ( '' === $ip ) {
				return $user;
			}

			if ( $this->ip_manager->is_whitelisted( $ip ) ) {
				return $user;
			}

			if ( $this->ip_manager->is_blocked( $ip ) ) {
				return new WP_Error(
					'reportedip_hive_blocked',
					esc_html__( 'Access denied. Your IP address has been temporarily blocked due to suspicious activity.', 'reportedip-hive' )
				);
			}

			if ( ! $this->api_client->is_active() ) {
				return $user;
			}

			$reputation = $this->api_client->check_ip_reputation( $ip );
			if ( ! is_array( $reputation ) ) {
				return $user;
			}

			$confidence = isset( $reputation['abuseConfidencePercentage'] ) ? (int) $reputation['abuseConfidencePercentage'] : 0;
			if ( $confidence < 75 ) {
				return $user;
			}

			$this->handle_threshold_exceeded(
				$ip,
				'reputation_block',
				sprintf( 'community confidence %d%%', $confidence ),
				'18'
			);

			return new WP_Error(
				'reportedip_hive_reputation_blocked',
				esc_html__( 'Access denied. This IP address has been flagged by the community.', 'reportedip-hive' )
			);
		}

		/**
		 * Hook callback: `wp_login` — reset login counters for the IP.
		 *
		 * @param string $user_login Username (unused).
		 * @param mixed  $user       WP_User instance (unused).
		 * @return void
		 * @since  1.0.0
		 */
		public function handle_successful_login( $user_login, $user ): void {
			unset( $user_login, $user );

			$ip = ReportedIP_Hive::get_client_ip();
			if ( '' === $ip ) {
				return;
			}

			$this->database->reset_attempts_for_ip( $ip );
			$this->ip_manager->forget( $ip );
		}

		/**
		 * Apply the configured response when a threshold is exceeded — block,
		 * log, and queue an API report when in Community mode.
		 *
		 * @param string $ip_address     IPv4/v6 string.
		 * @param string $event_type     Event identifier.
		 * @param string $reason         Short human-readable reason.
		 * @param string $category_ids   Comma-separated category IDs for the report API.
		 * @return void
		 * @since  1.0.0
		 */
		private function handle_threshold_exceeded( string $ip_address, string $event_type, string $reason, string $category_ids ): void {
			$auto_block  = (bool) get_option( 'reportedip_hive_auto_block', true );
			$report_only = (bool) get_option( 'reportedip_hive_report_only_mode', false );

			do_action(
				'reportedip_hive_log',
				'high',
				sprintf( 'threshold exceeded: %s', $event_type ),
				array(
					'ip'     => $ip_address,
					'event'  => $event_type,
					'reason' => $reason,
				)
			);

			if ( $auto_block && ! $report_only ) {
				$minutes = $this->escalation->compute_block_minutes( $ip_address );
				$this->database->block_ip_for_minutes( $ip_address, $reason, 'automatic', $minutes );
				$this->ip_manager->forget( $ip_address );
				do_action( 'reportedip_hive_ip_blocked', $ip_address, $reason );
			}

			if ( $this->api_client->is_active() ) {
				$this->database->queue_api_report( $ip_address, $category_ids, $reason );
				do_action( 'reportedip_hive_report_queued', $ip_address, $category_ids );
			}
		}

		/**
		 * Read the User-Agent header, sanitised and truncated to 255 chars.
		 *
		 * @return string|null
		 * @since  1.0.0
		 */
		private function current_user_agent(): ?string {
			if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
				return null;
			}
			$ua = sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) );
			return '' === $ua ? null : substr( $ua, 0, 255 );
		}
	}
}
