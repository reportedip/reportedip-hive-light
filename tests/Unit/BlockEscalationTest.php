<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Block_Escalation;
use ReportedIP_Hive_Database;

final class BlockEscalationTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	private function escalation( int $prior_count ): ReportedIP_Hive_Block_Escalation {
		$db = new class( $prior_count ) extends ReportedIP_Hive_Database {
			public function __construct( public int $prior_count ) {
				parent::__construct();
			}
			public function count_recent_blocks( string $ip_address, int $window_days ): int {
				return $this->prior_count;
			}
		};
		return new ReportedIP_Hive_Block_Escalation( $db );
	}

	public function test_first_block_uses_first_step(): void {
		update_option( 'reportedip_hive_block_ladder_minutes', '5,15,30,1440,2880,10080' );
		update_option( 'reportedip_hive_block_escalation_enabled', true );

		$this->assertSame( 5, $this->escalation( 0 )->compute_block_minutes( '203.0.113.1' ) );
	}

	public function test_third_offense_uses_third_step(): void {
		update_option( 'reportedip_hive_block_ladder_minutes', '5,15,30,1440,2880,10080' );
		update_option( 'reportedip_hive_block_escalation_enabled', true );

		$this->assertSame( 30, $this->escalation( 2 )->compute_block_minutes( '203.0.113.1' ) );
	}

	public function test_count_clamps_to_top_of_ladder(): void {
		update_option( 'reportedip_hive_block_ladder_minutes', '5,15,30' );
		update_option( 'reportedip_hive_block_escalation_enabled', true );

		$this->assertSame( 30, $this->escalation( 99 )->compute_block_minutes( '203.0.113.1' ) );
	}

	public function test_disabled_escalation_returns_first_step(): void {
		update_option( 'reportedip_hive_block_ladder_minutes', '5,15,30' );
		update_option( 'reportedip_hive_block_escalation_enabled', false );

		$this->assertSame( 5, $this->escalation( 5 )->compute_block_minutes( '203.0.113.1' ) );
	}

	public function test_invalid_csv_falls_back_to_default_ladder(): void {
		update_option( 'reportedip_hive_block_ladder_minutes', 'abc,not-a-number' );
		update_option( 'reportedip_hive_block_escalation_enabled', true );

		$this->assertSame( 5, $this->escalation( 0 )->compute_block_minutes( '203.0.113.1' ) );
	}
}
