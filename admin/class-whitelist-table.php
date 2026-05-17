<?php
/**
 * Whitelist admin list table.
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

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if ( ! class_exists( 'ReportedIP_Hive_Whitelist_Table' ) ) {

	/**
	 * WP_List_Table for `wp_reportedip_hive_whitelist`.
	 *
	 * @since 1.1.0
	 */
	final class ReportedIP_Hive_Whitelist_Table extends WP_List_Table {

		/**
		 * Database service.
		 *
		 * @var ReportedIP_Hive_Database
		 */
		private ReportedIP_Hive_Database $database;

		/**
		 * Constructor.
		 *
		 * @param ReportedIP_Hive_Database $database Persistence service.
		 * @since 1.1.0
		 */
		public function __construct( ReportedIP_Hive_Database $database ) {
			parent::__construct(
				array(
					'singular' => 'whitelist_entry',
					'plural'   => 'whitelist_entries',
					'ajax'     => false,
				)
			);
			$this->database = $database;
		}

		/**
		 * Define table columns.
		 *
		 * @return array<string, string>
		 * @since  1.1.0
		 */
		public function get_columns(): array {
			return array(
				'cb'         => '<input type="checkbox" />',
				'ip_address' => __( 'IP / CIDR', 'reportedip-hive' ),
				'reason'     => __( 'Reason', 'reportedip-hive' ),
				'expires_at' => __( 'Expires', 'reportedip-hive' ),
				'added_by'   => __( 'Added by', 'reportedip-hive' ),
				'created_at' => __( 'Added', 'reportedip-hive' ),
			);
		}

		/**
		 * Bulk actions.
		 *
		 * @return array<string, string>
		 * @since  1.1.0
		 */
		protected function get_bulk_actions(): array {
			return array(
				'remove' => __( 'Remove selected', 'reportedip-hive' ),
			);
		}

		/**
		 * Load rows.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		public function prepare_items(): void {
			$this->process_bulk_action();

			$per_page     = 20;
			$current_page = max( 1, (int) $this->get_pagenum() );
			$offset       = ( $current_page - 1 ) * $per_page;

			$total       = $this->database->get_whitelist_count();
			$this->items = $this->database->get_whitelist_rows( $per_page, $offset );

			$this->set_pagination_args(
				array(
					'total_items' => $total,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total / $per_page ),
				)
			);

			$this->_column_headers = array(
				$this->get_columns(),
				array(),
				array(),
			);
		}

		/**
		 * Bulk-action handler.
		 *
		 * @return void
		 * @since  1.1.0
		 */
		private function process_bulk_action(): void {
			if ( 'remove' !== $this->current_action() ) {
				return;
			}

			check_admin_referer( 'bulk-whitelist_entries' );

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$ips = isset( $_POST['whitelist_ip'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['whitelist_ip'] ) ) : array();
			foreach ( $ips as $ip ) {
				$this->database->remove_from_whitelist( (string) $ip );
			}
		}

		/**
		 * Default cell renderer.
		 *
		 * @param array<string, mixed> $item        Row.
		 * @param string               $column_name Column slug.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_default( $item, $column_name ): string {
			$value = isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
			return esc_html( (string) $value );
		}

		/**
		 * Checkbox column.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_cb( $item ): string {
			return sprintf(
				'<input type="checkbox" name="whitelist_ip[]" value="%s" />',
				esc_attr( (string) $item['ip_address'] )
			);
		}

		/**
		 * IP column with monospace styling.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_ip_address( $item ): string {
			return '<code>' . esc_html( (string) $item['ip_address'] ) . '</code>';
		}

		/**
		 * Reason column with empty-state placeholder.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_reason( $item ): string {
			$reason = (string) ( $item['reason'] ?? '' );
			if ( '' === $reason ) {
				return '<span class="rip-help-text">' . esc_html__( '(none)', 'reportedip-hive' ) . '</span>';
			}
			return esc_html( $reason );
		}

		/**
		 * Expiry column.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_expires_at( $item ): string {
			$value = (string) ( $item['expires_at'] ?? '' );
			if ( '' === $value ) {
				return '<span class="rip-badge rip-badge--success">' . esc_html__( 'Never', 'reportedip-hive' ) . '</span>';
			}

			$timestamp = strtotime( $value );
			if ( false === $timestamp ) {
				return esc_html( $value );
			}

			if ( $timestamp <= time() ) {
				return '<span class="rip-badge rip-badge--neutral">' . esc_html__( 'Expired', 'reportedip-hive' ) . '</span>';
			}

			return sprintf(
				/* translators: %s: human-readable time difference */
				esc_html__( 'in %s', 'reportedip-hive' ),
				esc_html( human_time_diff( time(), $timestamp ) )
			);
		}

		/**
		 * Added-by column.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_added_by( $item ): string {
			$user_id = (int) ( $item['added_by'] ?? 0 );
			if ( 0 === $user_id ) {
				return '<span class="rip-help-text">' . esc_html__( '(system)', 'reportedip-hive' ) . '</span>';
			}
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				return '#' . $user_id;
			}
			return esc_html( $user->user_login );
		}

		/**
		 * Created-at column.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.1.0
		 */
		protected function column_created_at( $item ): string {
			$value     = (string) ( $item['created_at'] ?? '' );
			$timestamp = strtotime( $value );
			if ( false === $timestamp ) {
				return esc_html( $value );
			}
			return sprintf(
				/* translators: %s: human-readable time difference */
				esc_html__( '%s ago', 'reportedip-hive' ),
				esc_html( human_time_diff( $timestamp, time() ) )
			);
		}

		/**
		 * Use the design-system table style.
		 *
		 * @return array<int, string>
		 * @since  1.1.0
		 */
		protected function get_table_classes(): array {
			return array( 'rip-table', 'wp-list-table', 'widefat', 'fixed', 'striped' );
		}
	}
}
