<?php
/**
 * Blocked IPs admin list table.
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

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if ( ! class_exists( 'ReportedIP_Hive_Blocked_IPs_Table' ) ) {

	/**
	 * WP_List_Table for the `wp_reportedip_hive_blocked` table.
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Blocked_IPs_Table extends WP_List_Table {

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
		 * @since 1.0.0
		 */
		public function __construct( ReportedIP_Hive_Database $database ) {
			parent::__construct(
				array(
					'singular' => 'blocked_ip',
					'plural'   => 'blocked_ips',
					'ajax'     => false,
				)
			);
			$this->database = $database;
		}

		/**
		 * Define table columns.
		 *
		 * @return array<string, string>
		 * @since  1.0.0
		 */
		public function get_columns(): array {
			return array(
				'cb'              => '<input type="checkbox" />',
				'ip_address'      => __( 'IP Address', 'reportedip-hive' ),
				'reason'          => __( 'Reason', 'reportedip-hive' ),
				'block_type'      => __( 'Type', 'reportedip-hive' ),
				'failed_attempts' => __( 'Attempts', 'reportedip-hive' ),
				'blocked_until'   => __( 'Blocked until', 'reportedip-hive' ),
				'status'          => __( 'Status', 'reportedip-hive' ),
			);
		}

		/**
		 * Sortable columns.
		 *
		 * @return array<string, array{0:string,1:bool}>
		 * @since  1.0.0
		 */
		protected function get_sortable_columns(): array {
			return array(
				'ip_address'      => array( 'ip_address', false ),
				'block_type'      => array( 'block_type', false ),
				'failed_attempts' => array( 'failed_attempts', false ),
				'blocked_until'   => array( 'blocked_until', true ),
			);
		}

		/**
		 * Bulk actions.
		 *
		 * @return array<string, string>
		 * @since  1.0.0
		 */
		protected function get_bulk_actions(): array {
			return array(
				'unblock' => __( 'Unblock selected', 'reportedip-hive' ),
			);
		}

		/**
		 * Load rows from the database.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function prepare_items(): void {
			$this->process_bulk_action();

			$per_page     = 20;
			$current_page = max( 1, (int) $this->get_pagenum() );
			$offset       = ( $current_page - 1 ) * $per_page;

			$order_by = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort, validated against an allow-list inside the DB layer.
			$order    = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( (string) $_GET['order'] ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sort, normalised inside the DB layer.

			$total       = $this->database->get_blocked_count();
			$this->items = $this->database->get_blocked_rows( $per_page, $offset, $order_by, $order );

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
				$this->get_sortable_columns(),
			);
		}

		/**
		 * Bulk-action handler ("Unblock selected").
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function process_bulk_action(): void {
			if ( 'unblock' !== $this->current_action() ) {
				return;
			}

			check_admin_referer( 'bulk-blocked_ips' );

			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$ids = isset( $_POST['blocked_ip'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['blocked_ip'] ) ) : array();
			if ( empty( $ids ) ) {
				return;
			}

			$this->database->bulk_unblock_by_ids( $ids );
		}

		/**
		 * Default cell renderer.
		 *
		 * @param array<string, mixed> $item        Row.
		 * @param string               $column_name Column slug.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_default( $item, $column_name ): string {
			$value = isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
			return esc_html( (string) $value );
		}

		/**
		 * Checkbox column renderer.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_cb( $item ): string {
			return sprintf( '<input type="checkbox" name="blocked_ip[]" value="%d" />', (int) $item['id'] );
		}

		/**
		 * Block-type badge column.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_block_type( $item ): string {
			$type  = (string) ( $item['block_type'] ?? '' );
			$class = match ( $type ) {
				'manual'     => 'rip-badge--info',
				'reputation' => 'rip-badge--danger',
				default      => 'rip-badge--warning',
			};
			return sprintf(
				'<span class="rip-badge %s">%s</span>',
				esc_attr( $class ),
				esc_html( ucfirst( $type ) )
			);
		}

		/**
		 * "Blocked until" column with relative time.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_blocked_until( $item ): string {
			$value = (string) ( $item['blocked_until'] ?? '' );
			if ( '' === $value ) {
				return esc_html__( 'Never expires', 'reportedip-hive' );
			}

			$timestamp = strtotime( $value );
			if ( false === $timestamp ) {
				return esc_html( $value );
			}

			if ( $timestamp <= time() ) {
				return esc_html__( 'Expired', 'reportedip-hive' );
			}

			return sprintf(
				/* translators: %s: human-readable time difference */
				esc_html__( 'in %s', 'reportedip-hive' ),
				esc_html( human_time_diff( time(), $timestamp ) )
			);
		}

		/**
		 * Computed status badge.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_status( $item ): string {
			$active        = (int) ( $item['is_active'] ?? 0 ) === 1;
			$blocked_until = (string) ( $item['blocked_until'] ?? '' );

			if ( ! $active ) {
				return '<span class="rip-badge rip-badge--neutral">' . esc_html__( 'Inactive', 'reportedip-hive' ) . '</span>';
			}

			if ( '' === $blocked_until ) {
				return '<span class="rip-badge rip-badge--danger">' . esc_html__( 'Active', 'reportedip-hive' ) . '</span>';
			}

			$timestamp = strtotime( $blocked_until );
			if ( false === $timestamp || $timestamp <= time() ) {
				return '<span class="rip-badge rip-badge--neutral">' . esc_html__( 'Expired', 'reportedip-hive' ) . '</span>';
			}

			return '<span class="rip-badge rip-badge--danger">' . esc_html__( 'Active', 'reportedip-hive' ) . '</span>';
		}

		/**
		 * Override base wrapper class to use the design-system table style.
		 *
		 * @return array<int, string>
		 * @since  1.0.0
		 */
		protected function get_table_classes(): array {
			return array( 'rip-table', 'wp-list-table', 'widefat', 'fixed', 'striped' );
		}
	}
}
