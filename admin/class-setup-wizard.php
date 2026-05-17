<?php
/**
 * Standalone setup wizard shown on first activation.
 *
 * 4 steps: Welcome → Operation Mode → Protection → Done.
 * Sets `reportedip_hive_wizard_completed` to true on completion or skip.
 *
 * @package   ReportedIP_Hive
 * @author    Patrick Schlesinger <1@reportedip.de>
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://wordpress.org/plugins/reportedip-hive/
 * @since     1.1.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ReportedIP_Hive_Setup_Wizard' ) ) {

	/**
	 * Setup wizard for first-activation onboarding.
	 *
	 * @since 1.1.0
	 */
	final class ReportedIP_Hive_Setup_Wizard {

		public const PAGE_SLUG = 'reportedip-hive-setup';

		/**
		 * Constructor — wires admin hooks.
		 *
		 * @since 1.1.0
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_hidden_page' ) );
			add_action( 'admin_init', array( $this, 'maybe_redirect_on_activation' ) );
			add_action( 'admin_init', array( $this, 'maybe_handle_submit' ) );
		}

		/**
		 * Register the wizard as a hidden submenu (parent=null) so it gets a
		 * URL but no menu entry.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function register_hidden_page(): void {
			add_submenu_page(
				'',
				__( 'ReportedIP Hive Light Setup', 'reportedip-hive' ),
				__( 'Setup', 'reportedip-hive' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render' )
			);
		}

		/**
		 * Redirect to the wizard right after activation when setup is unfinished.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function maybe_redirect_on_activation(): void {
			if ( ! get_transient( 'reportedip_hive_activation_redirect' ) ) {
				return;
			}
			delete_transient( 'reportedip_hive_activation_redirect' );

			if ( wp_doing_ajax() || is_network_admin() ) {
				return;
			}
			if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WordPress core query string, no user data.
				return;
			}

			if ( get_option( 'reportedip_hive_wizard_completed', false ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		/**
		 * Handle the wizard form post (Save & continue / Skip / Finish).
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function maybe_handle_submit(): void {
			if ( ! isset( $_POST['reportedip_hive_wizard_action'] ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			check_admin_referer( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' );

			$action = sanitize_key( wp_unslash( (string) $_POST['reportedip_hive_wizard_action'] ) );

			if ( 'skip' === $action || 'finish' === $action ) {
				update_option( 'reportedip_hive_wizard_completed', true );
				delete_transient( 'reportedip_hive_just_activated' );
				delete_transient( 'reportedip_hive_activation_redirect' );
				wp_safe_redirect( admin_url( 'admin.php?page=reportedip-hive&wizard=' . $action ) );
				exit;
			}

			$step = isset( $_POST['step'] ) ? max( 1, (int) $_POST['step'] ) : 1;

			if ( 2 === $step ) {
				$mode = isset( $_POST['operation_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['operation_mode'] ) ) : 'local';
				if ( ! in_array( $mode, array( 'local', 'community' ), true ) ) {
					$mode = 'local';
				}
				update_option( 'reportedip_hive_operation_mode', $mode );

				$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : '';
				update_option( 'reportedip_hive_api_key', $key );
			}

			if ( 3 === $step ) {
				$threshold = isset( $_POST['failed_login_threshold'] ) ? absint( wp_unslash( (string) $_POST['failed_login_threshold'] ) ) : 5;
				$timeframe = isset( $_POST['failed_login_timeframe'] ) ? absint( wp_unslash( (string) $_POST['failed_login_timeframe'] ) ) : 15;
				$auto      = ! empty( $_POST['auto_block'] );
				update_option( 'reportedip_hive_failed_login_threshold', max( 1, min( 50, $threshold ) ) );
				update_option( 'reportedip_hive_failed_login_timeframe', max( 1, min( 60, $timeframe ) ) );
				update_option( 'reportedip_hive_auto_block', $auto );
			}

			$next = $step + 1;
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'step' => $next,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Render the standalone wizard.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function render(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$step = isset( $_GET['step'] ) ? max( 1, min( 4, (int) $_GET['step'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Step navigation is read-only; the step-form is nonce-protected on submit.

			show_admin_bar( false );

			wp_enqueue_style(
				'reportedip-hive-design-system',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/css/design-system.css',
				array(),
				REPORTEDIP_HIVE_VERSION
			);
			wp_enqueue_style(
				'reportedip-hive-wizard',
				REPORTEDIP_HIVE_PLUGIN_URL . 'assets/css/wizard.css',
				array( 'reportedip-hive-design-system' ),
				REPORTEDIP_HIVE_VERSION
			);

			$labels = array(
				1 => __( 'Welcome', 'reportedip-hive' ),
				2 => __( 'Operation mode', 'reportedip-hive' ),
				3 => __( 'Protection', 'reportedip-hive' ),
				4 => __( 'Done', 'reportedip-hive' ),
			);

			?><!DOCTYPE html>
			<html <?php language_attributes(); ?>>
			<head>
				<meta charset="<?php bloginfo( 'charset' ); ?>" />
				<meta name="viewport" content="width=device-width, initial-scale=1.0" />
				<title><?php esc_html_e( 'ReportedIP Hive Light Setup', 'reportedip-hive' ); ?></title>
				<?php wp_print_styles(); ?>
			</head>
			<body class="rip-wizard-page">
				<div class="rip-wizard">
					<header class="rip-wizard__header">
						<div class="rip-wizard__brand">
							<div class="rip-wizard__logo"><?php echo wp_kses( ReportedIP_Hive::get_logo_svg(), ReportedIP_Hive::get_allowed_svg_tags() ); ?></div>
							<h1 class="rip-wizard__title">ReportedIP Hive Light</h1>
						</div>
						<form method="post" class="rip-wizard__skip-form">
							<?php wp_nonce_field( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' ); ?>
							<input type="hidden" name="reportedip_hive_wizard_action" value="skip" />
							<button type="submit" class="rip-button rip-button--ghost"><?php esc_html_e( 'Skip setup', 'reportedip-hive' ); ?> &rarr;</button>
						</form>
					</header>

					<nav class="rip-wizard__steps" aria-label="<?php esc_attr_e( 'Setup progress', 'reportedip-hive' ); ?>">
						<?php foreach ( $labels as $i => $label ) : ?>
							<?php
							$state_class = $i === $step
								? 'rip-wizard__step--active'
								: ( $i < $step ? 'rip-wizard__step--done' : '' );
							?>
							<div class="rip-wizard__step <?php echo esc_attr( $state_class ); ?>">
								<div class="rip-wizard__step-circle"><?php echo esc_html( (string) $i ); ?></div>
								<div class="rip-wizard__step-label"><?php echo esc_html( $label ); ?></div>
							</div>
						<?php endforeach; ?>
					</nav>

					<main class="rip-wizard__content">
						<?php $this->render_step( $step ); ?>
					</main>
				</div>
				<?php wp_print_footer_scripts(); ?>
			</body>
			</html>
			<?php
			exit;
		}

		/**
		 * Dispatch the active step renderer.
		 *
		 * @param int $step Step index 1..4.
		 * @return void
		 * @since  1.1.0
		 */
		private function render_step( int $step ): void {
			match ( $step ) {
				2       => $this->render_step_mode(),
				3       => $this->render_step_protection(),
				4       => $this->render_step_done(),
				default => $this->render_step_welcome(),
			};
		}

		/**
		 * Step 1 — welcome.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function render_step_welcome(): void {
			?>
			<div class="rip-card">
				<div class="rip-card__body">
					<h2><?php esc_html_e( 'Welcome to ReportedIP Hive Light', 'reportedip-hive' ); ?></h2>
					<p><?php esc_html_e( 'This short setup configures the brute-force counter and lets you choose whether the plugin should also consult the reportedip.de community database during login attempts.', 'reportedip-hive' ); ?></p>
					<p><?php esc_html_e( 'You can change everything later under Settings → ReportedIP Hive Light.', 'reportedip-hive' ); ?></p>
				</div>
				<div class="rip-card__footer">
					<form method="post">
						<?php wp_nonce_field( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' ); ?>
						<input type="hidden" name="reportedip_hive_wizard_action" value="next" />
						<input type="hidden" name="step" value="1" />
						<button type="submit" class="rip-button rip-button--primary"><?php esc_html_e( 'Get started', 'reportedip-hive' ); ?></button>
					</form>
				</div>
			</div>
			<?php
		}

		/**
		 * Step 2 — operation mode + optional Community Access Key.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function render_step_mode(): void {
			$mode = (string) get_option( 'reportedip_hive_operation_mode', 'local' );
			$key  = (string) get_option( 'reportedip_hive_api_key', '' );
			?>
			<form method="post">
				<?php wp_nonce_field( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' ); ?>
				<input type="hidden" name="reportedip_hive_wizard_action" value="next" />
				<input type="hidden" name="step" value="2" />

				<div class="rip-card">
					<div class="rip-card__header">
						<h2 class="rip-card__title"><?php esc_html_e( 'Operation mode', 'reportedip-hive' ); ?></h2>
					</div>
					<div class="rip-card__body">
						<label class="rip-form-group">
							<input type="radio" name="operation_mode" value="local" <?php checked( $mode, 'local' ); ?> />
							<strong><?php esc_html_e( 'Local Shield (recommended)', 'reportedip-hive' ); ?></strong>
							<span class="rip-help-text"><?php esc_html_e( 'Uses only your site\'s data. No external requests.', 'reportedip-hive' ); ?></span>
						</label>

						<label class="rip-form-group">
							<input type="radio" name="operation_mode" value="community" <?php checked( $mode, 'community' ); ?> />
							<strong><?php esc_html_e( 'Community Network (optional)', 'reportedip-hive' ); ?></strong>
							<span class="rip-help-text"><?php esc_html_e( 'Checks IP reputation against the reportedip.de community database during login attempts and shares blocked IPs back. Hashed usernames only — never plaintext.', 'reportedip-hive' ); ?></span>
						</label>

						<div class="rip-form-group">
							<label for="rip-wizard-key"><?php esc_html_e( 'Community Access Key (optional)', 'reportedip-hive' ); ?></label>
							<input type="text" id="rip-wizard-key" name="api_key" value="<?php echo esc_attr( $key ); ?>" class="regular-text" autocomplete="off" />
							<span class="rip-help-text"><?php esc_html_e( 'Required only for Community Network. A free key is available at reportedip.de.', 'reportedip-hive' ); ?></span>
						</div>
					</div>
					<div class="rip-card__footer">
						<button type="submit" class="rip-button rip-button--primary"><?php esc_html_e( 'Continue', 'reportedip-hive' ); ?></button>
					</div>
				</div>
			</form>
			<?php
		}

		/**
		 * Step 3 — protection thresholds.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function render_step_protection(): void {
			$threshold = (int) get_option( 'reportedip_hive_failed_login_threshold', 5 );
			$timeframe = (int) get_option( 'reportedip_hive_failed_login_timeframe', 15 );
			$auto      = (bool) get_option( 'reportedip_hive_auto_block', true );
			?>
			<form method="post">
				<?php wp_nonce_field( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' ); ?>
				<input type="hidden" name="reportedip_hive_wizard_action" value="next" />
				<input type="hidden" name="step" value="3" />

				<div class="rip-card">
					<div class="rip-card__header">
						<h2 class="rip-card__title"><?php esc_html_e( 'Protection settings', 'reportedip-hive' ); ?></h2>
					</div>
					<div class="rip-card__body">
						<div class="rip-form-group">
							<label for="rip-wizard-threshold"><?php esc_html_e( 'Failed-login threshold', 'reportedip-hive' ); ?></label>
							<input type="number" id="rip-wizard-threshold" name="failed_login_threshold" value="<?php echo esc_attr( (string) $threshold ); ?>" min="1" max="50" />
							<span class="rip-help-text"><?php esc_html_e( 'Block an IP after this many failed login attempts.', 'reportedip-hive' ); ?></span>
						</div>

						<div class="rip-form-group">
							<label for="rip-wizard-timeframe"><?php esc_html_e( 'Time window (minutes)', 'reportedip-hive' ); ?></label>
							<input type="number" id="rip-wizard-timeframe" name="failed_login_timeframe" value="<?php echo esc_attr( (string) $timeframe ); ?>" min="1" max="60" />
							<span class="rip-help-text"><?php esc_html_e( 'Time window during which failed attempts are counted.', 'reportedip-hive' ); ?></span>
						</div>

						<label class="rip-toggle">
							<input type="checkbox" name="auto_block" value="1" <?php checked( $auto, true ); ?> />
							<span><?php esc_html_e( 'Enable automatic blocking', 'reportedip-hive' ); ?></span>
						</label>
						<p class="rip-help-text"><?php esc_html_e( 'When disabled, attempts are logged but no block is enforced.', 'reportedip-hive' ); ?></p>
					</div>
					<div class="rip-card__footer">
						<button type="submit" class="rip-button rip-button--primary"><?php esc_html_e( 'Continue', 'reportedip-hive' ); ?></button>
					</div>
				</div>
			</form>
			<?php
		}

		/**
		 * Step 4 — done.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function render_step_done(): void {
			?>
			<div class="rip-card">
				<div class="rip-card__body">
					<h2><?php esc_html_e( 'You are protected.', 'reportedip-hive' ); ?></h2>
					<p><?php esc_html_e( 'ReportedIP Hive Light is active and listening to login attempts. Visit Settings → ReportedIP Hive Light any time to fine-tune thresholds, manage the whitelist, or review blocked IPs.', 'reportedip-hive' ); ?></p>
					<ul class="rip-wizard__next-list">
						<li><?php esc_html_e( 'Try a wrong-password login a few times to see the protection trigger.', 'reportedip-hive' ); ?></li>
						<li><?php esc_html_e( 'Whitelist your office IP under Whitelist before any security drills.', 'reportedip-hive' ); ?></li>
						<li><?php esc_html_e( 'Review the Privacy notice in Settings → Privacy if you intend to enable Community Network mode.', 'reportedip-hive' ); ?></li>
					</ul>
				</div>
				<div class="rip-card__footer">
					<form method="post">
						<?php wp_nonce_field( 'reportedip_hive_wizard', 'reportedip_hive_wizard_nonce' ); ?>
						<input type="hidden" name="reportedip_hive_wizard_action" value="finish" />
						<button type="submit" class="rip-button rip-button--primary"><?php esc_html_e( 'Open dashboard', 'reportedip-hive' ); ?></button>
					</form>
				</div>
			</div>
			<?php
		}
	}
}
