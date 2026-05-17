<?php
/**
 * Admin settings page (Connection / Protection / Privacy) and Blocked IPs page.
 *
 * Greenfield implementation — does not port from any other plugin's settings
 * code. Uses the WordPress Settings API for persistence and renders content
 * inside the ReportedIP design-system frame (`.rip-wrap` → `.rip-header` →
 * `.rip-content` → `.rip-trust-badges`).
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

require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-blocked-ips-table.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-attempts-list-table.php';
require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-whitelist-table.php';

if ( ! class_exists( 'ReportedIP_Hive_Admin_Settings' ) ) {

	/**
	 * Admin pages and AJAX handlers.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Admin_Settings {

		private const MENU_SLUG      = 'reportedip-hive';
		private const SETTINGS_SLUG  = 'reportedip-hive-settings';
		private const BLOCKED_SLUG   = 'reportedip-hive-blocked';
		private const WHITELIST_SLUG = 'reportedip-hive-whitelist';

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * API client (used for the Test connection AJAX handler).
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

			add_action( 'admin_menu', array( $this, 'register_menu' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
			add_action( 'admin_init', array( $this, 'maybe_handle_whitelist_add' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

			add_action( 'wp_ajax_reportedip_hive_test_connection', array( $this, 'ajax_test_connection' ) );
			add_action( 'wp_ajax_reportedip_hive_unblock_ip', array( $this, 'ajax_unblock_ip' ) );
		}

		/**
		 * Register the top-level menu and its two submenus.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function register_menu(): void {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding the menu icon SVG as a data URI is the documented WordPress pattern; the input is a hard-coded literal, not user data.
			$icon = 'data:image/svg+xml;base64,' . base64_encode(
				'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="#a7aaad"><path d="M10 1L3 4v5c0 4.6 3.2 8.9 7 10 3.8-1.1 7-5.4 7-10V4l-7-3z"/></svg>'
			);

			add_menu_page(
				__( 'ReportedIP Hive', 'reportedip-hive' ),
				__( 'ReportedIP Hive', 'reportedip-hive' ),
				'manage_options',
				self::MENU_SLUG,
				array( $this, 'render_dashboard_page' ),
				$icon,
				75
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Dashboard', 'reportedip-hive' ),
				__( 'Dashboard', 'reportedip-hive' ),
				'manage_options',
				self::MENU_SLUG,
				array( $this, 'render_dashboard_page' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Settings', 'reportedip-hive' ),
				__( 'Settings', 'reportedip-hive' ),
				'manage_options',
				self::SETTINGS_SLUG,
				array( $this, 'render_settings_page' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Blocked IPs', 'reportedip-hive' ),
				__( 'Blocked IPs', 'reportedip-hive' ),
				'manage_options',
				self::BLOCKED_SLUG,
				array( $this, 'render_blocked_page' )
			);

			add_submenu_page(
				self::MENU_SLUG,
				__( 'Whitelist', 'reportedip-hive' ),
				__( 'Whitelist', 'reportedip-hive' ),
				'manage_options',
				self::WHITELIST_SLUG,
				array( $this, 'render_whitelist_page' )
			);
		}

		/**
		 * Handle the "Add to whitelist" form post (regular admin page submit).
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function maybe_handle_whitelist_add(): void {
			if ( ! isset( $_POST['reportedip_hive_whitelist_action'] ) || 'add' !== $_POST['reportedip_hive_whitelist_action'] ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			check_admin_referer( 'reportedip_hive_whitelist_add', 'reportedip_hive_whitelist_nonce' );

			$ip      = isset( $_POST['whitelist_ip'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whitelist_ip'] ) ) : '';
			$reason  = isset( $_POST['whitelist_reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whitelist_reason'] ) ) : '';
			$expires = isset( $_POST['whitelist_expires'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['whitelist_expires'] ) ) : '';

			$expires_mysql = '';
			if ( '' !== $expires ) {
				$timestamp = strtotime( $expires );
				if ( false !== $timestamp ) {
					$expires_mysql = gmdate( 'Y-m-d H:i:s', $timestamp );
				}
			}

			$ok = $this->database->add_to_whitelist(
				$ip,
				$reason,
				get_current_user_id(),
				'' === $expires_mysql ? null : $expires_mysql
			);

			if ( $ok ) {
				add_settings_error(
					'reportedip_hive_whitelist',
					'whitelist_added',
					sprintf(
						/* translators: %s: IP or CIDR that was just whitelisted */
						__( 'Added %s to the whitelist.', 'reportedip-hive' ),
						$ip
					),
					'success'
				);
			} else {
				add_settings_error(
					'reportedip_hive_whitelist',
					'whitelist_invalid',
					__( 'Could not add the entry — please provide a valid IPv4/v6 address or CIDR range.', 'reportedip-hive' ),
					'error'
				);
			}

			set_transient( 'settings_errors', get_settings_errors(), 30 );

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::WHITELIST_SLUG . '&settings-updated=1' ) );
			exit;
		}

		/**
		 * Register every setting and section across the three tabs.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function register_settings(): void {
			$bool_args = array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'default'           => false,
			);
			$int_args  = array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			);
			$str_args  = array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			);

			register_setting(
				'reportedip_hive_connection',
				'reportedip_hive_operation_mode',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_operation_mode' ),
					'default'           => 'local',
				)
			);
			register_setting( 'reportedip_hive_connection', 'reportedip_hive_api_key', $str_args );
			register_setting(
				'reportedip_hive_connection',
				'reportedip_hive_trusted_ip_header',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_trusted_header' ),
					'default'           => '',
				)
			);

			register_setting( 'reportedip_hive_protection', 'reportedip_hive_failed_login_threshold', $int_args );
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_failed_login_timeframe', $int_args );
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_password_spray_threshold', $int_args );
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_password_spray_timeframe', $int_args );
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_auto_block', $bool_args );
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_block_escalation_enabled', $bool_args );
			register_setting(
				'reportedip_hive_protection',
				'reportedip_hive_block_ladder_minutes',
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_ladder' ),
					'default'           => '5,15,30,1440,2880,10080',
				)
			);
			register_setting( 'reportedip_hive_protection', 'reportedip_hive_report_only_mode', $bool_args );

			register_setting( 'reportedip_hive_privacy', 'reportedip_hive_cache_duration', $int_args );
			register_setting( 'reportedip_hive_privacy', 'reportedip_hive_negative_cache_duration', $int_args );
			register_setting( 'reportedip_hive_privacy', 'reportedip_hive_queue_max_age_days', $int_args );
			register_setting( 'reportedip_hive_privacy', 'reportedip_hive_delete_data_on_uninstall', $bool_args );
		}

		/**
		 * Sanitization helper: boolean coercion.
		 *
		 * @param mixed $value Submitted value.
		 * @return bool
		 * @since  1.0.0
		 */
		public function sanitize_bool( $value ): bool {
			return (bool) $value;
		}

		/**
		 * Sanitization helper: operation mode whitelist.
		 *
		 * @param mixed $value Submitted value.
		 * @return string `'local'` or `'community'`.
		 * @since  1.0.0
		 */
		public function sanitize_operation_mode( $value ): string {
			$value = is_string( $value ) ? $value : '';
			return in_array( $value, array( 'local', 'community' ), true ) ? $value : 'local';
		}

		/**
		 * Sanitization helper: trusted-proxy header whitelist.
		 *
		 * @param mixed $value Submitted value.
		 * @return string Allowed header name or empty string.
		 * @since  1.0.0
		 */
		public function sanitize_trusted_header( $value ): string {
			$value   = is_string( $value ) ? $value : '';
			$allowed = array( '', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_CLUSTER_CLIENT_IP' );
			return in_array( $value, $allowed, true ) ? $value : '';
		}

		/**
		 * Sanitization helper: CSV ladder of positive integers.
		 *
		 * @param mixed $value Submitted value.
		 * @return string Cleaned CSV.
		 * @since  1.0.0
		 */
		public function sanitize_ladder( $value ): string {
			$value = is_string( $value ) ? $value : '';
			$parts = array_map( 'trim', explode( ',', $value ) );
			$out   = array();
			foreach ( $parts as $p ) {
				$int = (int) $p;
				if ( $int > 0 ) {
					$out[] = $int;
				}
			}
			if ( empty( $out ) ) {
				return '5,15,30,1440,2880,10080';
			}
			return implode( ',', $out );
		}

		/**
		 * Enqueue admin CSS + JS, but only on the plugin's pages.
		 *
		 * @param string $hook_suffix Current admin page hook.
		 * @return void
		 * @since  1.0.0
		 */
		public function enqueue_assets( string $hook_suffix ): void {
			$plugin_pages = array(
				'toplevel_page_' . self::MENU_SLUG,
				self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG,
				self::MENU_SLUG . '_page_' . self::BLOCKED_SLUG,
				self::MENU_SLUG . '_page_' . self::WHITELIST_SLUG,
			);

			if ( ! in_array( $hook_suffix, $plugin_pages, true ) ) {
				return;
			}

			wp_enqueue_style(
				'reportedip-hive-design-system',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/css/design-system.css',
				array(),
				REPORTEDIP_HIVE_VERSION
			);
			wp_enqueue_style(
				'reportedip-hive-admin',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/css/admin.css',
				array( 'reportedip-hive-design-system' ),
				REPORTEDIP_HIVE_VERSION
			);
			wp_enqueue_script(
				'reportedip-hive-admin',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/js/admin.js',
				array(),
				REPORTEDIP_HIVE_VERSION,
				true
			);
			wp_localize_script(
				'reportedip-hive-admin',
				'reportedipHiveAdmin',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'testNonce' => wp_create_nonce( 'reportedip_hive_test_connection' ),
					'i18n'      => array(
						'testing' => __( 'Testing connection…', 'reportedip-hive' ),
						'noKey'   => __( 'Please enter an access key first.', 'reportedip-hive' ),
						'error'   => __( 'Could not reach reportedip.de. Check your network and try again.', 'reportedip-hive' ),
					),
				)
			);
		}

		/**
		 * Render the Dashboard landing page (stats, API health, queue, recent activity).
		 *
		 * @return void
		 * @since  1.2.0
		 */
		public function render_dashboard_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$stats = $this->database->get_dashboard_stats();
			$mode  = (string) get_option( 'reportedip_hive_operation_mode', 'local' );
			$quota = $this->api_client->get_quota_status();

			$this->render_page_open( __( 'ReportedIP Hive', 'reportedip-hive' ), __( 'Dashboard', 'reportedip-hive' ) );
			$this->render_inline_notices();
			echo '<div class="rip-content">';

			$cards = array(
				array(
					'label'   => __( 'Active blocks', 'reportedip-hive' ),
					'value'   => $stats['blocks_active'],
					'variant' => 'danger',
				),
				array(
					'label'   => __( 'Blocks (last 24 h)', 'reportedip-hive' ),
					'value'   => $stats['blocks_24h'],
					'variant' => 'warning',
				),
				array(
					'label'   => __( 'Attempts (last 24 h)', 'reportedip-hive' ),
					'value'   => $stats['attempts_24h'],
					'variant' => 'info',
				),
				array(
					'label'   => __( 'Whitelisted IPs', 'reportedip-hive' ),
					'value'   => $stats['whitelist'],
					'variant' => 'success',
				),
			);

			echo '<div class="rip-stat-grid">';
			foreach ( $cards as $c ) {
				printf(
					'<div class="rip-stat rip-stat--%s"><div class="rip-stat__value">%d</div><div class="rip-stat__label">%s</div></div>',
					esc_attr( (string) $c['variant'] ),
					(int) $c['value'],
					esc_html( (string) $c['label'] )
				);
			}
			echo '</div>';

			$long_term = array(
				array(
					'label' => __( 'Last 24 h', 'reportedip-hive' ),
					'value' => $stats['blocks_24h'],
				),
				array(
					'label' => __( 'Last 7 days', 'reportedip-hive' ),
					'value' => $stats['blocks_7d'],
				),
				array(
					'label' => __( 'Last 30 days', 'reportedip-hive' ),
					'value' => $stats['blocks_30d'],
				),
				array(
					'label' => __( 'All time', 'reportedip-hive' ),
					'value' => $stats['blocks_total'],
				),
			);
			?>
			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Defended attacks', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-stat-grid rip-stat-grid--compact">
						<?php foreach ( $long_term as $lt ) : ?>
							<div class="rip-stat rip-stat--neutral">
								<div class="rip-stat__value"><?php echo (int) $lt['value']; ?></div>
								<div class="rip-stat__label"><?php echo esc_html( (string) $lt['label'] ); ?></div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="rip-help-text"><?php esc_html_e( 'Each block represents one IP that was prevented from logging in after exceeding the configured thresholds.', 'reportedip-hive' ); ?></p>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Operation mode', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<?php if ( 'community' === $mode ) : ?>
						<p><strong><?php esc_html_e( 'Community Network', 'reportedip-hive' ); ?></strong> &mdash; <?php esc_html_e( 'IP reputation lookups + queued reports active.', 'reportedip-hive' ); ?></p>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Local Shield', 'reportedip-hive' ); ?></strong> &mdash; <?php esc_html_e( 'No external requests. Switch to Community Network in Settings to enable reputation lookups.', 'reportedip-hive' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( 'community' === $mode ) : ?>
				<div class="rip-card">
					<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'API health & quota', 'reportedip-hive' ); ?></h2></div>
					<div class="rip-card__body">
						<?php
						$pct       = $quota['max'] > 0 ? min( 100, (int) round( ( $quota['used'] / $quota['max'] ) * 100 ) ) : 0;
						$pct_class = $pct >= 90 ? 'rip-progress--danger' : ( $pct >= 70 ? 'rip-progress--warning' : 'rip-progress--success' );
						?>
						<p>
							<?php
							printf(
								/* translators: 1: used calls, 2: max calls per hour */
								esc_html__( 'Outbound calls this hour: %1$d / %2$d', 'reportedip-hive' ),
								(int) $quota['used'],
								(int) $quota['max']
							);
							?>
						</p>
						<div class="rip-progress <?php echo esc_attr( $pct_class ); ?>"><div class="rip-progress__bar" style="width: <?php echo (int) $pct; ?>%"></div></div>
						<p class="rip-help-text"><?php esc_html_e( 'Rolling 1-hour window. When exhausted, reputation lookups are skipped (login still proceeds, fail-open).', 'reportedip-hive' ); ?></p>
					</div>
				</div>
			<?php endif; ?>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Report queue', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<p>
						<span class="rip-badge rip-badge--info"><?php echo (int) $stats['queue_pending']; ?> <?php esc_html_e( 'pending', 'reportedip-hive' ); ?></span>
						<span class="rip-badge rip-badge--warning"><?php echo (int) $stats['queue_processing']; ?> <?php esc_html_e( 'processing', 'reportedip-hive' ); ?></span>
						<span class="rip-badge rip-badge--success"><?php echo (int) $stats['queue_completed']; ?> <?php esc_html_e( 'completed', 'reportedip-hive' ); ?></span>
						<span class="rip-badge rip-badge--danger"><?php echo (int) $stats['queue_failed']; ?> <?php esc_html_e( 'failed', 'reportedip-hive' ); ?></span>
					</p>
					<p class="rip-help-text"><?php esc_html_e( 'Reports are queued in the database and dispatched by a 15-minute cron job — never from the login path. Failed rows retry up to 3 times.', 'reportedip-hive' ); ?></p>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Recent activity', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<?php
					require_once REPORTEDIP_HIVE_PLUGIN_DIR . 'admin/class-attempts-list-table.php';
					$attempts = new ReportedIP_Hive_Attempts_List_Table( $this->database );
					$attempts->prepare_items();
					if ( empty( $attempts->items ) ) {
						echo '<p class="rip-help-text">' . esc_html__( 'No login attempts recorded yet.', 'reportedip-hive' ) . '</p>';
					} else {
						$attempts->display();
					}
					?>
				</div>
			</div>
			<?php

			echo '</div>';
			$this->render_trust_badges();
			$this->render_page_close();
		}

		/**
		 * Render the Settings page (with three tabs).
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function render_settings_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation only; the form itself is nonce-protected by the Settings API.
			$tabs        = array(
				'connection' => __( 'Connection', 'reportedip-hive' ),
				'protection' => __( 'Protection', 'reportedip-hive' ),
				'privacy'    => __( 'Privacy', 'reportedip-hive' ),
			);
			if ( ! isset( $tabs[ $current_tab ] ) ) {
				$current_tab = 'connection';
			}

			$this->render_page_open( __( 'ReportedIP Hive', 'reportedip-hive' ), __( 'Brute-force protection for WordPress logins', 'reportedip-hive' ) );
			$this->render_inline_notices();
			$this->render_tabs( $tabs, $current_tab );

			echo '<div class="rip-content">';

			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			$this->render_hidden_tab_field( $current_tab );

			switch ( $current_tab ) {
				case 'protection':
					settings_fields( 'reportedip_hive_protection' );
					$this->render_protection_tab();
					break;
				case 'privacy':
					settings_fields( 'reportedip_hive_privacy' );
					$this->render_privacy_tab();
					break;
				case 'connection':
				default:
					settings_fields( 'reportedip_hive_connection' );
					$this->render_connection_tab();
					break;
			}

			submit_button( __( 'Save Changes', 'reportedip-hive' ), 'primary rip-button rip-button--primary' );
			echo '</form>';
			echo '</div>';

			$this->render_trust_badges();
			$this->render_page_close();
		}

		/**
		 * Render the Blocked IPs page.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function render_blocked_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$table = new ReportedIP_Hive_Blocked_IPs_Table( $this->database );
			$table->prepare_items();

			$this->render_page_open( __( 'ReportedIP Hive', 'reportedip-hive' ), __( 'Blocked IP addresses', 'reportedip-hive' ) );
			$this->render_inline_notices();
			echo '<div class="rip-content">';

			if ( $this->database->get_blocked_count() === 0 ) {
				$this->render_empty_state();
			} else {
				echo '<form method="post">';
				wp_nonce_field( 'bulk-blocked-ips' );
				$table->display();
				echo '</form>';
			}

			echo '</div>';
			$this->render_trust_badges();
			$this->render_page_close();
		}

		/**
		 * Render the Whitelist page (add-form + list-table).
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function render_whitelist_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$transient_errors = get_transient( 'settings_errors' );
			if ( is_array( $transient_errors ) && ! empty( $transient_errors ) ) {
				foreach ( $transient_errors as $err ) {
					if ( is_array( $err ) ) {
						add_settings_error(
							(string) ( $err['setting'] ?? 'reportedip_hive_whitelist' ),
							(string) ( $err['code'] ?? 'whitelist' ),
							(string) ( $err['message'] ?? '' ),
							(string) ( $err['type'] ?? 'success' )
						);
					}
				}
				delete_transient( 'settings_errors' );
			}

			$table = new ReportedIP_Hive_Whitelist_Table( $this->database );
			$table->prepare_items();

			$this->render_page_open( __( 'ReportedIP Hive', 'reportedip-hive' ), __( 'IP whitelist', 'reportedip-hive' ) );
			$this->render_inline_notices();

			echo '<div class="rip-content">';

			?>
			<div class="rip-card">
				<div class="rip-card__header">
					<h2 class="rip-card__title"><?php esc_html_e( 'Add to whitelist', 'reportedip-hive' ); ?></h2>
				</div>
				<div class="rip-card__body">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::WHITELIST_SLUG ) ); ?>">
						<?php wp_nonce_field( 'reportedip_hive_whitelist_add', 'reportedip_hive_whitelist_nonce' ); ?>
						<input type="hidden" name="reportedip_hive_whitelist_action" value="add" />

						<div class="rip-form-group">
							<label for="rip-whitelist-ip"><?php esc_html_e( 'IP address or CIDR range', 'reportedip-hive' ); ?></label>
							<input type="text" id="rip-whitelist-ip" name="whitelist_ip" class="regular-text" placeholder="192.0.2.10 or 198.51.100.0/24" required />
							<span class="rip-help-text"><?php esc_html_e( 'IPv4, IPv6 or IPv4 CIDR (e.g. 203.0.113.0/24).', 'reportedip-hive' ); ?></span>
						</div>

						<div class="rip-form-group">
							<label for="rip-whitelist-reason"><?php esc_html_e( 'Reason (optional)', 'reportedip-hive' ); ?></label>
							<input type="text" id="rip-whitelist-reason" name="whitelist_reason" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Office VPN', 'reportedip-hive' ); ?>" />
						</div>

						<div class="rip-form-group">
							<label for="rip-whitelist-expires"><?php esc_html_e( 'Expires (optional)', 'reportedip-hive' ); ?></label>
							<input type="datetime-local" id="rip-whitelist-expires" name="whitelist_expires" />
							<span class="rip-help-text"><?php esc_html_e( 'Leave blank for a permanent entry.', 'reportedip-hive' ); ?></span>
						</div>

						<div class="rip-form-actions">
							<button type="submit" class="rip-button rip-button--primary"><?php esc_html_e( 'Add to whitelist', 'reportedip-hive' ); ?></button>
						</div>
					</form>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header">
					<h2 class="rip-card__title"><?php esc_html_e( 'Current whitelist', 'reportedip-hive' ); ?></h2>
				</div>
				<div class="rip-card__body">
					<?php if ( $this->database->get_whitelist_count() === 0 ) : ?>
						<p class="rip-help-text"><?php esc_html_e( 'No whitelist entries yet.', 'reportedip-hive' ); ?></p>
					<?php else : ?>
						<form method="post">
							<?php wp_nonce_field( 'bulk-whitelist_entries' ); ?>
							<?php $table->display(); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
			<?php

			echo '</div>';
			$this->render_trust_badges();
			$this->render_page_close();
		}

		/**
		 * AJAX: validate the entered access key against `/verify-key`.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function ajax_test_connection(): void {
			check_ajax_referer( 'reportedip_hive_test_connection', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'reportedip-hive' ) ), 403 );
			}

			$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
			$result = $this->api_client->verify_key( $key );

			wp_send_json_success(
				array(
					'valid'   => (bool) $result['valid'],
					'message' => (string) $result['message'],
				)
			);
		}

		/**
		 * AJAX: deactivate a single block from the Blocked IPs page.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function ajax_unblock_ip(): void {
			check_ajax_referer( 'reportedip_hive_unblock_ip', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'reportedip-hive' ) ), 403 );
			}

			$ip = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ip'] ) ) : '';
			if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid IP address.', 'reportedip-hive' ) ), 400 );
			}

			$this->database->unblock_ip( $ip );
			wp_send_json_success();
		}

		/**
		 * Render the Connection tab.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_connection_tab(): void {
			$mode     = (string) get_option( 'reportedip_hive_operation_mode', 'local' );
			$key      = (string) get_option( 'reportedip_hive_api_key', '' );
			$endpoint = (string) get_option( 'reportedip_hive_api_endpoint', 'https://reportedip.de/wp-json/reportedip/v2/' );
			$header   = (string) get_option( 'reportedip_hive_trusted_ip_header', '' );

			$proxy_options = array(
				''                         => __( 'None — use REMOTE_ADDR (default)', 'reportedip-hive' ),
				'HTTP_X_FORWARDED_FOR'     => 'X-Forwarded-For',
				'HTTP_CF_CONNECTING_IP'    => 'CF-Connecting-IP (Cloudflare)',
				'HTTP_X_REAL_IP'           => 'X-Real-IP',
				'HTTP_TRUE_CLIENT_IP'      => 'True-Client-IP',
				'HTTP_X_CLUSTER_CLIENT_IP' => 'X-Cluster-Client-IP',
			);

			?>
			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Operation Mode', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<fieldset>
						<label class="rip-form-group">
							<input type="radio" name="reportedip_hive_operation_mode" value="local" <?php checked( $mode, 'local' ); ?> />
							<strong><?php esc_html_e( 'Local Shield (recommended)', 'reportedip-hive' ); ?></strong>
							<span class="rip-help-text"><?php esc_html_e( 'Uses only your site\'s data. No external requests.', 'reportedip-hive' ); ?></span>
						</label>

						<label class="rip-form-group">
							<input type="radio" name="reportedip_hive_operation_mode" value="community" <?php checked( $mode, 'community' ); ?> />
							<strong><?php esc_html_e( 'Community Network (optional)', 'reportedip-hive' ); ?></strong>
						</label>

						<div class="rip-alert rip-alert--info">
							<div class="rip-alert__content">
								<p><?php esc_html_e( 'Enabling Community Network mode will:', 'reportedip-hive' ); ?></p>
								<ul>
									<li><?php esc_html_e( 'Send blocked IP addresses to reportedip.de when your site detects an attack.', 'reportedip-hive' ); ?></li>
									<li><?php esc_html_e( 'Query reportedip.de during login attempts to check IP reputation.', 'reportedip-hive' ); ?></li>
									<li><?php esc_html_e( 'Hash usernames (SHA-256, salted with wp_salt()) before any transmission — plaintext usernames never leave your server.', 'reportedip-hive' ); ?></li>
									<li><?php esc_html_e( 'Identify your site only via your Community Access Key — no domain or contact information is transmitted.', 'reportedip-hive' ); ?></li>
								</ul>
								<p><?php esc_html_e( 'Legal basis: GDPR Art. 6(1)(f), legitimate interest in network security.', 'reportedip-hive' ); ?></p>
							</div>
						</div>
					</fieldset>
				</div>
			</div>

			<div class="rip-card" data-rip-mode-gated="community">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Community Access Key', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-form-group">
						<label for="reportedip_hive_api_key"><?php esc_html_e( 'Access Key', 'reportedip-hive' ); ?></label>
						<input type="text" id="reportedip_hive_api_key" name="reportedip_hive_api_key" value="<?php echo esc_attr( $key ); ?>" class="regular-text" autocomplete="off" />
						<span class="rip-help-text"><?php esc_html_e( 'Need an access key? Visit reportedip.de.', 'reportedip-hive' ); ?></span>
					</div>

					<div class="rip-form-group">
						<label for="reportedip_hive_api_endpoint"><?php esc_html_e( 'API Endpoint', 'reportedip-hive' ); ?></label>
						<input type="text" id="reportedip_hive_api_endpoint" value="<?php echo esc_attr( $endpoint ); ?>" class="regular-text" readonly />
						<span class="rip-help-text"><?php esc_html_e( 'Default endpoint. Override via the reportedip_hive_api_endpoint filter.', 'reportedip-hive' ); ?></span>
					</div>

					<div class="rip-form-actions">
						<button type="button" id="rip-test-connection" class="rip-button rip-button--secondary"><?php esc_html_e( 'Test connection', 'reportedip-hive' ); ?></button>
						<span id="rip-test-result" aria-live="polite"></span>
					</div>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Reverse Proxy', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-form-group">
						<label for="reportedip_hive_trusted_ip_header"><?php esc_html_e( 'Trusted Proxy Header', 'reportedip-hive' ); ?></label>
						<select id="reportedip_hive_trusted_ip_header" name="reportedip_hive_trusted_ip_header">
							<?php foreach ( $proxy_options as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $header, $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="rip-help-text"><?php esc_html_e( 'Only set this if your site sits behind a reverse proxy (Cloudflare, AWS, NGINX) that overrides the header for every incoming request. Otherwise the header can be spoofed.', 'reportedip-hive' ); ?></span>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the Protection tab.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_protection_tab(): void {
			$ladder      = (string) get_option( 'reportedip_hive_block_ladder_minutes', '5,15,30,1440,2880,10080' );
			$auto_block  = (bool) get_option( 'reportedip_hive_auto_block', true );
			$escalation  = (bool) get_option( 'reportedip_hive_block_escalation_enabled', true );
			$report_only = (bool) get_option( 'reportedip_hive_report_only_mode', false );
			$th_login    = (int) get_option( 'reportedip_hive_failed_login_threshold', 5 );
			$tf_login    = (int) get_option( 'reportedip_hive_failed_login_timeframe', 15 );
			$th_spray    = (int) get_option( 'reportedip_hive_password_spray_threshold', 5 );
			$tf_spray    = (int) get_option( 'reportedip_hive_password_spray_timeframe', 10 );
			?>
			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Brute-force thresholds', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-form-group">
						<label for="rip-th-login"><?php esc_html_e( 'Failed-login threshold', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-th-login" name="reportedip_hive_failed_login_threshold" value="<?php echo esc_attr( (string) $th_login ); ?>" min="1" max="50" />
						<span class="rip-help-text"><?php esc_html_e( 'Block an IP after this many failed login attempts.', 'reportedip-hive' ); ?></span>
					</div>
					<div class="rip-form-group">
						<label for="rip-tf-login"><?php esc_html_e( 'Time window (minutes)', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-tf-login" name="reportedip_hive_failed_login_timeframe" value="<?php echo esc_attr( (string) $tf_login ); ?>" min="1" max="60" />
						<span class="rip-help-text"><?php esc_html_e( 'Time window during which failed attempts are counted.', 'reportedip-hive' ); ?></span>
					</div>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Password-spray detection', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-form-group">
						<label for="rip-th-spray"><?php esc_html_e( 'Distinct usernames threshold', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-th-spray" name="reportedip_hive_password_spray_threshold" value="<?php echo esc_attr( (string) $th_spray ); ?>" min="1" max="20" />
						<span class="rip-help-text"><?php esc_html_e( 'Block an IP that fails logins against this many distinct usernames.', 'reportedip-hive' ); ?></span>
					</div>
					<div class="rip-form-group">
						<label for="rip-tf-spray"><?php esc_html_e( 'Time window (minutes)', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-tf-spray" name="reportedip_hive_password_spray_timeframe" value="<?php echo esc_attr( (string) $tf_spray ); ?>" min="1" max="60" />
					</div>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Blocking behaviour', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<label class="rip-toggle">
						<input type="checkbox" name="reportedip_hive_auto_block" value="1" <?php checked( $auto_block, true ); ?> />
						<span><?php esc_html_e( 'Automatic blocking', 'reportedip-hive' ); ?></span>
					</label>
					<p class="rip-help-text"><?php esc_html_e( 'If disabled, attempts are logged but no block is enforced (report-only mode).', 'reportedip-hive' ); ?></p>

					<label class="rip-toggle">
						<input type="checkbox" name="reportedip_hive_block_escalation_enabled" value="1" <?php checked( $escalation, true ); ?> />
						<span><?php esc_html_e( 'Progressive block duration', 'reportedip-hive' ); ?></span>
					</label>
					<p class="rip-help-text"><?php esc_html_e( 'Increases block duration with each repeat offense (5 min → 15 min → 30 min → 24 h → 48 h → 7 days).', 'reportedip-hive' ); ?></p>

					<label class="rip-toggle">
						<input type="checkbox" name="reportedip_hive_report_only_mode" value="1" <?php checked( $report_only, true ); ?> />
						<span><?php esc_html_e( 'Report-only mode', 'reportedip-hive' ); ?></span>
					</label>
					<p class="rip-help-text"><?php esc_html_e( 'Detect and report attacks but never block. Useful for staging environments.', 'reportedip-hive' ); ?></p>

					<details>
						<summary><?php esc_html_e( 'Show advanced (block ladder)', 'reportedip-hive' ); ?></summary>
						<div class="rip-form-group">
							<label for="rip-ladder"><?php esc_html_e( 'Block ladder (CSV minutes)', 'reportedip-hive' ); ?></label>
							<input type="text" id="rip-ladder" name="reportedip_hive_block_ladder_minutes" value="<?php echo esc_attr( $ladder ); ?>" class="regular-text" />
						</div>
					</details>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the Privacy tab.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_privacy_tab(): void {
			$cache    = (int) get_option( 'reportedip_hive_cache_duration', 24 );
			$negative = (int) get_option( 'reportedip_hive_negative_cache_duration', 2 );
			$queue    = (int) get_option( 'reportedip_hive_queue_max_age_days', 7 );
			$wipe     = (bool) get_option( 'reportedip_hive_delete_data_on_uninstall', false );
			?>
			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Cache & retention', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<div class="rip-form-group">
						<label for="rip-cache"><?php esc_html_e( 'Reputation cache duration (hours)', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-cache" name="reportedip_hive_cache_duration" value="<?php echo esc_attr( (string) $cache ); ?>" min="1" max="168" />
					</div>
					<div class="rip-form-group">
						<label for="rip-neg-cache"><?php esc_html_e( 'Negative cache duration (hours)', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-neg-cache" name="reportedip_hive_negative_cache_duration" value="<?php echo esc_attr( (string) $negative ); ?>" min="1" max="48" />
					</div>
					<div class="rip-form-group">
						<label for="rip-queue"><?php esc_html_e( 'API queue retention (days)', 'reportedip-hive' ); ?></label>
						<input type="number" id="rip-queue" name="reportedip_hive_queue_max_age_days" value="<?php echo esc_attr( (string) $queue ); ?>" min="1" max="90" />
					</div>
				</div>
			</div>

			<div class="rip-card">
				<div class="rip-card__header"><h2 class="rip-card__title"><?php esc_html_e( 'Uninstall', 'reportedip-hive' ); ?></h2></div>
				<div class="rip-card__body">
					<label class="rip-toggle">
						<input type="checkbox" name="reportedip_hive_delete_data_on_uninstall" value="1" <?php checked( $wipe, true ); ?> />
						<span><?php esc_html_e( 'Delete all data on uninstall', 'reportedip-hive' ); ?></span>
					</label>
					<p class="rip-help-text"><?php esc_html_e( 'Drop all plugin tables and options when the plugin is deleted. Recommended OFF for forensic reasons.', 'reportedip-hive' ); ?></p>
				</div>
			</div>

			<div class="rip-alert rip-alert--info">
				<div class="rip-alert__content">
					<h3><?php esc_html_e( 'Privacy notice', 'reportedip-hive' ); ?></h3>
					<p><?php esc_html_e( 'This plugin processes IP addresses to detect attacks. Username inputs are stored hashed (SHA-256, salted with wp_salt()), never in plaintext. In Local Shield mode, no data leaves your server. In Community Network mode, blocked IP addresses and minimal context (event type, hashed username, timestamp) are shared with reportedip.de. See the readme.txt "External services" and "Privacy" sections for full details.', 'reportedip-hive' ); ?></p>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the empty-state shown when no IPs have ever been blocked.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_empty_state(): void {
			$settings_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=protection' );
			?>
			<div class="rip-empty-state">
				<div class="rip-empty-state__icon"><?php echo wp_kses( ReportedIP_Hive::get_logo_svg(), ReportedIP_Hive::get_allowed_svg_tags() ); ?></div>
				<h3 class="rip-empty-state__title"><?php esc_html_e( 'No blocked IPs yet', 'reportedip-hive' ); ?></h3>
				<p class="rip-empty-state__text"><?php esc_html_e( 'When the plugin blocks an attacker, you will see them listed here. Configure thresholds in Settings → Protection.', 'reportedip-hive' ); ?></p>
				<a class="rip-button rip-button--primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Configure Protection', 'reportedip-hive' ); ?></a>
			</div>
			<?php
		}

		/**
		 * Render the page header markup.
		 *
		 * @param string $title    Page title.
		 * @param string $subtitle Subtitle below the title.
		 * @return void
		 * @since  1.0.0
		 */
		private function render_page_open( string $title, string $subtitle ): void {
			$mode = (string) get_option( 'reportedip_hive_operation_mode', 'local' );
			?>
			<div class="wrap rip-wrap" data-rip-operation-mode="<?php echo esc_attr( $mode ); ?>">
				<div class="rip-header">
					<div class="rip-header__brand">
						<div class="rip-header__logo"><?php echo wp_kses( ReportedIP_Hive::get_logo_svg(), ReportedIP_Hive::get_allowed_svg_tags() ); ?></div>
						<div>
							<h1 class="rip-header__title"><?php echo esc_html( $title ); ?></h1>
							<p class="rip-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
						</div>
					</div>
				</div>
			<?php
		}

		/**
		 * Render the page closing markup.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_page_close(): void {
			echo '</div>';
		}

		/**
		 * Render the navigation tabs.
		 *
		 * @param array<string, string> $tabs        Tab slug → label.
		 * @param string                $current_tab Active tab slug.
		 * @return void
		 * @since  1.0.0
		 */
		private function render_tabs( array $tabs, string $current_tab ): void {
			echo '<nav class="rip-tabs" role="tablist">';
			foreach ( $tabs as $slug => $label ) {
				$url    = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=' . $slug );
				$active = $slug === $current_tab ? ' rip-tab--active' : '';
				printf(
					'<a class="rip-tab%1$s" role="tab" aria-selected="%2$s" href="%3$s">%4$s</a>',
					esc_attr( $active ),
					esc_attr( $slug === $current_tab ? 'true' : 'false' ),
					esc_url( $url ),
					esc_html( $label )
				);
			}
			echo '</nav>';
		}

		/**
		 * Persist the active tab across form submissions via a hidden field.
		 *
		 * @param string $tab Tab slug.
		 * @return void
		 * @since  1.0.0
		 */
		private function render_hidden_tab_field( string $tab ): void {
			printf( '<input type="hidden" name="_wp_http_referer" value="%s" />', esc_attr( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=' . $tab ) ) );
		}

		/**
		 * Render the trust-badges row.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function render_trust_badges(): void {
			$tags = ReportedIP_Hive::get_allowed_svg_tags();
			?>
			<div class="rip-trust-badges">
				<div class="rip-trust-badge">
					<?php echo wp_kses( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>', $tags ); ?>
					<span><?php esc_html_e( 'Security Focused', 'reportedip-hive' ); ?></span>
				</div>
				<div class="rip-trust-badge">
					<?php echo wp_kses( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>', $tags ); ?>
					<span><?php esc_html_e( 'GDPR Compliant', 'reportedip-hive' ); ?></span>
				</div>
				<div class="rip-trust-badge">
					<?php echo wp_kses( '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg>', $tags ); ?>
					<span><?php esc_html_e( 'Made in Germany', 'reportedip-hive' ); ?></span>
				</div>
			</div>
			<?php
		}

		/**
		 * Render Settings-API errors as design-system alerts (replaces the
		 * default WordPress yellow/red boxes that ignore our visual language).
		 * Called immediately under the page header.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function render_inline_notices(): void {
			$errors       = get_settings_errors();
			$updated_flag = isset( $_GET['settings-updated'] ) ? sanitize_key( wp_unslash( (string) $_GET['settings-updated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- settings-updated is read for visual-feedback only; the form itself is nonce-protected by the Settings API.
			if ( empty( $errors ) && '' !== $updated_flag && '0' !== $updated_flag ) {
				$errors = array(
					array(
						'setting' => 'general',
						'code'    => 'settings_updated',
						'message' => __( 'Settings saved.', 'reportedip-hive' ),
						'type'    => 'success',
					),
				);
			}

			if ( empty( $errors ) ) {
				return;
			}

			foreach ( $errors as $error ) {
				$type    = isset( $error['type'] ) ? (string) $error['type'] : 'success';
				$variant = match ( $type ) {
					'error', 'danger' => 'rip-alert--danger',
					'warning'         => 'rip-alert--warning',
					'info'            => 'rip-alert--info',
					default           => 'rip-alert--success',
				};
				$message = isset( $error['message'] ) ? (string) $error['message'] : '';
				if ( '' === $message ) {
					continue;
				}
				printf(
					'<div class="rip-alert %1$s" role="status"><div class="rip-alert__content"><p>%2$s</p></div></div>',
					esc_attr( $variant ),
					esc_html( $message )
				);
			}
		}
	}
}
