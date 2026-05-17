<?php
/**
 * Plugin Name:       ReportedIP Hive Light
 * Plugin URI:        https://wordpress.org/plugins/reportedip-hive/
 * Description:       Lightweight brute-force login protection with optional community-powered IP reputation checks.
 * Version:           1.3.3
 * Requires at least: 6.0
 * Tested up to:      6.9
 * Requires PHP:      8.1
 * Author:            Patrick Schlesinger
 * Author URI:        https://reportedip.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reportedip-hive
 * Domain Path:       /languages
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

defined( 'REPORTEDIP_HIVE_VERSION' ) || define( 'REPORTEDIP_HIVE_VERSION', '1.3.3' );
defined( 'REPORTEDIP_HIVE_DB_VERSION' ) || define( 'REPORTEDIP_HIVE_DB_VERSION', '1.1.0' );
defined( 'REPORTEDIP_HIVE_PLUGIN_FILE' ) || define( 'REPORTEDIP_HIVE_PLUGIN_FILE', __FILE__ );
defined( 'REPORTEDIP_HIVE_PLUGIN_DIR' ) || define( 'REPORTEDIP_HIVE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'REPORTEDIP_HIVE_PLUGIN_URL' ) || define( 'REPORTEDIP_HIVE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'REPORTEDIP_HIVE_PLUGIN_BASENAME' ) || define( 'REPORTEDIP_HIVE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
defined( 'REPORTEDIP_HIVE_LANGUAGES_DIR' ) || define( 'REPORTEDIP_HIVE_LANGUAGES_DIR', REPORTEDIP_HIVE_PLUGIN_DIR . 'languages' );

require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-defaults.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-logger.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-database.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-cache.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-ip-manager.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-block-escalation.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-api-client.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-security-monitor.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'includes/class-cron-handler.php';

if ( is_admin() ) {
	require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-admin-settings.php';
	require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-setup-wizard.php';
}

if ( ! class_exists( 'ReportedIP_Hive' ) ) {

	/**
	 * Main plugin class — bootstrap, hook wiring, helpers.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * IP manager service.
		 *
		 * @var ReportedIP_Hive_IP_Manager
		 */
		private ReportedIP_Hive_IP_Manager $ip_manager;

		/**
		 * Security monitor service.
		 *
		 * @var ReportedIP_Hive_Security_Monitor
		 */
		private ReportedIP_Hive_Security_Monitor $security_monitor;

		/**
		 * API client service.
		 *
		 * @var ReportedIP_Hive_API
		 */
		private ReportedIP_Hive_API $api_client;

		/**
		 * Cron handler service.
		 *
		 * @var ReportedIP_Hive_Cron_Handler
		 */
		private ReportedIP_Hive_Cron_Handler $cron_handler;

		/**
		 * Get the singleton instance.
		 *
		 * @return self
		 * @since  1.0.0
		 */
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor — wires services and hooks.
		 *
		 * @since 1.0.0
		 */
		private function __construct() {
			$cache                  = new ReportedIP_Hive_Cache();
			$this->database         = new ReportedIP_Hive_Database();
			$this->ip_manager       = new ReportedIP_Hive_IP_Manager( $this->database );
			$this->api_client       = new ReportedIP_Hive_API( $cache, $this->database );
			$this->security_monitor = new ReportedIP_Hive_Security_Monitor( $this->database, $this->ip_manager, $this->api_client );
			$this->cron_handler     = new ReportedIP_Hive_Cron_Handler( $this->database, $this->api_client );

			ReportedIP_Hive_Logger::register_default_listener();

			$this->init_hooks();

			if ( is_admin() ) {
				new ReportedIP_Hive_Admin_Settings( $this->database, $this->api_client );
				new ReportedIP_Hive_Setup_Wizard();
			}
		}

		/**
		 * Wire WordPress hooks.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function init_hooks(): void {
			add_action( 'init', array( $this, 'enforce_block' ), 1 );

			add_action( 'wp_login_failed', array( $this->security_monitor, 'handle_failed_login' ) );
			add_filter( 'wp_authenticate_user', array( $this->security_monitor, 'pre_auth_check' ), 10, 2 );
			add_action( 'wp_login', array( $this->security_monitor, 'handle_successful_login' ), 10, 2 );

			add_action( 'admin_init', array( $this, 'register_privacy_suggestion' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_welcome_styles' ) );
			add_action( 'admin_notices', array( $this, 'maybe_show_welcome_notice' ) );
			add_action( 'wp_ajax_reportedip_hive_dismiss_welcome', array( $this, 'ajax_dismiss_welcome' ) );

			add_action( 'reportedip_hive_process_queue', array( $this->cron_handler, 'run_queue_worker' ) );
			add_action( 'reportedip_hive_cleanup', array( $this->cron_handler, 'run_cleanup' ) );
		}

		/**
		 * Enqueue the design-system stylesheet on non-plugin admin pages when
		 * the welcome notice is about to be rendered. Plugin pages enqueue the
		 * stylesheet themselves, so we skip them.
		 *
		 * @return void
		 * @since  1.2.0
		 */
		public function maybe_enqueue_welcome_styles(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			if ( ! get_transient( 'reportedip_hive_just_activated' ) ) {
				return;
			}
			$user_id = get_current_user_id();
			if ( $user_id > 0 && get_user_meta( $user_id, 'reportedip_hive_welcome_dismissed', true ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && false !== strpos( (string) $screen->id, 'reportedip-hive' ) ) {
				return;
			}
			wp_enqueue_style(
				'reportedip-hive-design-system',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/css/design-system.css',
				array(),
				REPORTEDIP_HIVE_VERSION
			);
		}

		/**
		 * Short-circuit any request from a blocked IP early in the bootstrap.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function enforce_block(): void {
			$ip = self::get_client_ip();

			if ( '' === $ip ) {
				return;
			}

			if ( $this->ip_manager->is_whitelisted( $ip ) ) {
				return;
			}

			if ( ! $this->ip_manager->is_blocked( $ip ) ) {
				return;
			}

			self::emit_block_response_headers();

			$message = esc_html__( 'Access denied. Your IP address has been temporarily blocked due to suspicious activity.', 'reportedip-hive' );

			wp_die(
				esc_html( $message ),
				esc_html__( 'Access denied', 'reportedip-hive' ),
				array(
					'response'  => 403,
					'back_link' => false,
				)
			);
		}

		/**
		 * Detect the client IP, honouring the optional trusted-proxy header setting.
		 *
		 * The default reads `REMOTE_ADDR` only. A trusted proxy header is consulted
		 * only when the administrator has explicitly selected one from a fixed
		 * whitelist (X-Forwarded-For, CF-Connecting-IP, X-Real-IP, True-Client-IP,
		 * X-Cluster-Client-IP). The selected header is split on the first comma
		 * and validated via `filter_var( ..., FILTER_VALIDATE_IP )`.
		 *
		 * @return string Validated IP address or empty string.
		 * @since  1.0.0
		 */
		public static function get_client_ip(): string {
			$trusted = (string) get_option( 'reportedip_hive_trusted_ip_header', '' );

			$allowed = array(
				'HTTP_X_FORWARDED_FOR',
				'HTTP_CF_CONNECTING_IP',
				'HTTP_X_REAL_IP',
				'HTTP_TRUE_CLIENT_IP',
				'HTTP_X_CLUSTER_CLIENT_IP',
			);

			$ip = '';

			if ( '' !== $trusted && in_array( $trusted, $allowed, true ) && isset( $_SERVER[ $trusted ] ) ) {
				$header_value = sanitize_text_field( wp_unslash( (string) $_SERVER[ $trusted ] ) );
				$first        = trim( (string) strtok( $header_value, ',' ) );
				if ( '' !== $first && false !== filter_var( $first, FILTER_VALIDATE_IP ) ) {
					$ip = $first;
				}
			}

			if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
				$candidate = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
				if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
				}
			}

			/**
			 * Filter the detected client IP.
			 *
			 * @param string $ip Detected IP address.
			 */
			$ip = (string) apply_filters( 'reportedip_hive_get_client_ip', $ip );

			return $ip;
		}

		/**
		 * Emit cache-busting response headers for the block page so popular
		 * caching plugins (WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed)
		 * do not serve a cached "Access denied" to legitimate visitors later.
		 *
		 * The block response uses HTTP 403 (which the major caching plugins
		 * already skip) plus an explicit `Cache-Control: no-store` and
		 * `Pragma: no-cache` header. No global PHP constants are defined here
		 * so this method does not change behaviour outside the immediate
		 * block-response path.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function emit_block_response_headers(): void {
			add_filter( 'nocache_headers', array( __CLASS__, 'filter_block_response_nocache_headers' ) );

			if ( ! headers_sent() ) {
				nocache_headers();
				header( 'Pragma: no-cache' );
			}
		}

		/**
		 * Strengthen the `Cache-Control` header for the block response so
		 * shared caches treat the response as `no-store`. Registered from
		 * {@see self::emit_block_response_headers()} immediately before
		 * `wp_die()` is invoked; `wp_die()` itself calls `nocache_headers()`
		 * and would otherwise overwrite the explicit `header()` call.
		 *
		 * @param array<string, string> $headers Headers passed by `nocache_headers()`.
		 *
		 * @return array<string, string>
		 * @since  1.3.1
		 */
		public static function filter_block_response_nocache_headers( $headers ): array {
			if ( ! is_array( $headers ) ) {
				$headers = array();
			}

			$headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';

			return $headers;
		}

		/**
		 * Inline shield logo (rendered via wp_kses with the allow-list below).
		 *
		 * @return string
		 * @since  1.0.0
		 */
		public static function get_logo_svg(): string {
			return '<svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
				. '<path d="M24 4L8 12v12c0 11 7.7 21.3 16 24 8.3-2.7 16-13 16-24V12L24 4z" fill="currentColor" opacity="0.15"/>'
				. '<path d="M24 4L8 12v12c0 11 7.7 21.3 16 24 8.3-2.7 16-13 16-24V12L24 4zm0 4.2l12 6v10c0 8.4-6 16.3-12 18.5-6-2.2-12-10.1-12-18.5v-10l12-6z" fill="currentColor"/>'
				. '<path d="M21 28l-5-5 1.8-1.8 3.2 3.2 7.2-7.2L30 19l-9 9z" fill="currentColor"/>'
				. '</svg>';
		}

		/**
		 * Allow-list for `wp_kses` when echoing inline SVGs.
		 *
		 * @return array<string, array<string, true>>
		 * @since  1.0.0
		 */
		public static function get_allowed_svg_tags(): array {
			return array(
				'svg'  => array(
					'viewbox'     => true,
					'fill'        => true,
					'xmlns'       => true,
					'aria-hidden' => true,
					'class'       => true,
					'width'       => true,
					'height'      => true,
				),
				'path' => array(
					'd'       => true,
					'fill'    => true,
					'opacity' => true,
				),
				'g'    => array(
					'fill' => true,
				),
			);
		}

		/**
		 * Register a privacy-policy suggestion for site administrators.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function register_privacy_suggestion(): void {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}

			$content  = '<p>' . esc_html__( 'This plugin processes IP addresses to detect brute-force login attacks against your site. IP addresses are stored in the site database and may be displayed in the WordPress admin under "ReportedIP Hive Light → Blocked IPs".', 'reportedip-hive' ) . '</p>';
			$content .= '<p>' . esc_html__( 'Usernames submitted to the login form are stored as a SHA-256 hash, salted with the site\'s wp_salt(). Plain-text usernames are never stored or transmitted.', 'reportedip-hive' ) . '</p>';
			$content .= '<p>' . esc_html__( 'In Local Shield mode (default), no data leaves your server. In Community Network mode, blocked IP addresses and minimal context (event type, hashed username, timestamp) are shared with reportedip.de for collective threat intelligence. The site is identified only by the Community Access Key — no domain or contact information is transmitted.', 'reportedip-hive' ) . '</p>';
			$content .= '<p>' . esc_html__( 'Legal basis: GDPR Art. 6(1)(f), legitimate interest in network security.', 'reportedip-hive' ) . '</p>';

			wp_add_privacy_policy_content( 'ReportedIP Hive Light', wp_kses_post( $content ) );
		}

		/**
		 * Render a dismissible welcome notice once after activation.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function maybe_show_welcome_notice(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && false !== strpos( (string) $screen->id, 'reportedip-hive' ) ) {
				return;
			}

			if ( ! get_transient( 'reportedip_hive_just_activated' ) ) {
				return;
			}

			$user_id = get_current_user_id();
			if ( $user_id > 0 && get_user_meta( $user_id, 'reportedip_hive_welcome_dismissed', true ) ) {
				delete_transient( 'reportedip_hive_just_activated' );
				return;
			}

			$settings_url = admin_url( 'admin.php?page=reportedip-hive' );
			$nonce        = wp_create_nonce( 'reportedip_hive_dismiss_welcome' );

			printf(
				'<div class="rip-alert rip-alert--info rip-welcome-notice" data-rip-welcome data-nonce="%1$s" role="status">'
				. '<div class="rip-alert__content"><p>%2$s</p></div>'
				. '<button type="button" class="rip-welcome-notice__dismiss" aria-label="%3$s">&times;</button>'
				. '</div>',
				esc_attr( $nonce ),
				sprintf(
					/* translators: %s: settings page URL */
					wp_kses(
						/* translators: %s: settings page URL */
						__( 'ReportedIP Hive Light is active. <a href="%s">Configure protection</a> at Settings &rarr; ReportedIP Hive Light.', 'reportedip-hive' ),
						array( 'a' => array( 'href' => true ) )
					),
					esc_url( $settings_url )
				),
				esc_attr__( 'Dismiss notice', 'reportedip-hive' )
			);
		}

		/**
		 * AJAX handler that dismisses the welcome notice for the current user.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function ajax_dismiss_welcome(): void {
			check_ajax_referer( 'reportedip_hive_dismiss_welcome', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'reportedip-hive' ) ), 403 );
			}

			$user_id = get_current_user_id();
			if ( $user_id > 0 ) {
				update_user_meta( $user_id, 'reportedip_hive_welcome_dismissed', 1 );
			}

			delete_transient( 'reportedip_hive_just_activated' );

			wp_send_json_success();
		}

		/**
		 * Activation callback — schema, defaults, cron schedule, welcome flag.
		 *
		 * Refuses to activate if a different ReportedIP Hive edition is already
		 * loaded (signal: `ReportedIP_Hive_Two_Factor` class exists). The other
		 * edition is the canonical one; this one self-deactivates and shows a
		 * notice.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function activate(): void {
			if ( class_exists( 'ReportedIP_Hive_Two_Factor', false ) ) {
				deactivate_plugins( REPORTEDIP_HIVE_PLUGIN_BASENAME );
				add_option( 'reportedip_hive_activation_blocked_by_other_edition', 1 );
				return;
			}

			$database = new ReportedIP_Hive_Database();
			$database->install_schema();

			ReportedIP_Hive_Defaults::seed_options();

			ReportedIP_Hive_Cron_Handler::schedule_jobs();

			set_transient( 'reportedip_hive_just_activated', 1, MINUTE_IN_SECONDS * 30 );

			if ( ! get_option( 'reportedip_hive_wizard_completed', false ) ) {
				set_transient( 'reportedip_hive_activation_redirect', 1, MINUTE_IN_SECONDS * 30 );
			}
		}

		/**
		 * Deactivation callback — clears scheduled cron events.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public static function deactivate(): void {
			ReportedIP_Hive_Cron_Handler::clear_jobs();
		}
	}

	register_activation_hook( __FILE__, array( 'ReportedIP_Hive', 'activate' ) );
	register_deactivation_hook( __FILE__, array( 'ReportedIP_Hive', 'deactivate' ) );

	add_action(
		'plugins_loaded',
		static function (): void {
			ReportedIP_Hive::get_instance();
		},
		5
	);
}
