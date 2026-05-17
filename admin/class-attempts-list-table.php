<?php
/**
 * Read-only recent-attempts list table (used inside the admin UI).
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

if ( ! class_exists( 'ReportedIP_Hive_Attempts_List_Table' ) ) {

	/**
	 * WP_List_Table for `wp_reportedip_hive_attempts` (read-only).
	 *
	 * @since 1.0.0
	 */
	final class ReportedIP_Hive_Attempts_List_Table extends WP_List_Table {

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
					'singular' => 'attempt',
					'plural'   => 'attempts',
					'ajax'     => false,
				)
			);
			$this->database = $database;
		}

		/**
		 * Define columns.
		 *
		 * @return array<string, string>
		 * @since  1.0.0
		 */
		public function get_columns(): array {
			return array(
				'ip_address'    => __( 'IP Address', 'reportedip-hive' ),
				'attempt_type'  => __( 'Type', 'reportedip-hive' ),
				'attempt_count' => __( 'Count', 'reportedip-hive' ),
				'last_attempt'  => __( 'Last attempt', 'reportedip-hive' ),
			);
		}

		/**
		 * Load rows.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function prepare_items(): void {
			$this->items = $this->database->get_recent_attempts( 50 );

			$this->_column_headers = array( $this->get_columns(), array(), array() );
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
		 * Last-attempt column with relative time.
		 *
		 * @param array<string, mixed> $item Row.
		 * @return string
		 * @since  1.0.0
		 */
		protected function column_last_attempt( $item ): string {
			$value     = (string) ( $item['last_attempt'] ?? '' );
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
		 * @since  1.0.0
		 */
		protected function get_table_classes(): array {
			return array( 'rip-table', 'wp-list-table', 'widefat', 'fixed', 'striped' );
		}
	}
}
