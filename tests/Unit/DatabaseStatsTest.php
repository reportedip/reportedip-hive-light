<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Database;

final class DatabaseStatsTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	public function test_dashboard_stats_includes_long_term_block_keys(): void {
		global $wpdb;

		$wpdb = new class {
			public string $prefix  = 'wp_';
			public string $options = 'wp_options';

			public function get_charset_collate(): string {
				return '';
			}
			public function prepare( string $query, ...$args ): string {
				return $query;
			}
			public function query( string $sql ) {
				return 0;
			}
			public function get_var( string $sql ) {
				if ( false !== stripos( $sql, 'is_active = 1' ) ) {
					return 4;
				}
				if ( false !== stripos( $sql, 'attempt_count' ) ) {
					return 21;
				}
				if ( false !== stripos( $sql, 'reportedip_hive_whitelist' ) ) {
					return 2;
				}
				return 0;
			}
			public function get_row( string $sql, $output = ARRAY_A ) {
				if ( false !== stripos( $sql, 'reportedip_hive_blocked' ) ) {
					return array(
						'h24'   => '3',
						'd7'    => '11',
						'd30'   => '27',
						'total' => '108',
					);
				}
				if ( false !== stripos( $sql, 'reportedip_hive_api_queue' ) ) {
					return array(
						'p'  => '1',
						'pr' => '0',
						'c'  => '5',
						'f'  => '2',
					);
				}
				return null;
			}
			public function get_results( string $sql, $output = ARRAY_A ): array {
				return array();
			}
			public function get_col( string $sql ): array {
				return array();
			}
			public function insert( string $table, array $data, $format = null ): int {
				return 1;
			}
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				return 1;
			}
			public function delete( string $table, array $where, $where_format = null ): int {
				return 1;
			}
		};

		$db    = new ReportedIP_Hive_Database();
		$stats = $db->get_dashboard_stats();

		$this->assertArrayHasKey( 'blocks_active', $stats );
		$this->assertArrayHasKey( 'blocks_24h', $stats );
		$this->assertArrayHasKey( 'blocks_7d', $stats );
		$this->assertArrayHasKey( 'blocks_30d', $stats );
		$this->assertArrayHasKey( 'blocks_total', $stats );

		$this->assertSame( 4, $stats['blocks_active'] );
		$this->assertSame( 3, $stats['blocks_24h'] );
		$this->assertSame( 11, $stats['blocks_7d'] );
		$this->assertSame( 27, $stats['blocks_30d'] );
		$this->assertSame( 108, $stats['blocks_total'] );
		$this->assertSame( 21, $stats['attempts_24h'] );
		$this->assertSame( 2, $stats['whitelist'] );
		$this->assertSame( 1, $stats['queue_pending'] );
		$this->assertSame( 5, $stats['queue_completed'] );
		$this->assertSame( 2, $stats['queue_failed'] );
	}

	public function test_dashboard_stats_returns_zero_when_block_table_empty(): void {
		global $wpdb;

		$wpdb = new class {
			public string $prefix  = 'wp_';
			public string $options = 'wp_options';

			public function get_charset_collate(): string {
				return '';
			}
			public function prepare( string $query, ...$args ): string {
				return $query;
			}
			public function query( string $sql ) {
				return 0;
			}
			public function get_var( string $sql ) {
				return 0;
			}
			public function get_row( string $sql, $output = ARRAY_A ) {
				if ( false !== stripos( $sql, 'reportedip_hive_blocked' ) ) {
					return array(
						'h24'   => null,
						'd7'    => null,
						'd30'   => null,
						'total' => '0',
					);
				}
				return null;
			}
			public function get_results( string $sql, $output = ARRAY_A ): array {
				return array();
			}
			public function get_col( string $sql ): array {
				return array();
			}
			public function insert( string $table, array $data, $format = null ): int {
				return 1;
			}
			public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
				return 1;
			}
			public function delete( string $table, array $where, $where_format = null ): int {
				return 1;
			}
		};

		$db    = new ReportedIP_Hive_Database();
		$stats = $db->get_dashboard_stats();

		$this->assertSame( 0, $stats['blocks_24h'] );
		$this->assertSame( 0, $stats['blocks_7d'] );
		$this->assertSame( 0, $stats['blocks_30d'] );
		$this->assertSame( 0, $stats['blocks_total'] );
	}
}
