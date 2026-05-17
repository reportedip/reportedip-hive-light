<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Cache;

final class CacheTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	public function test_get_returns_false_when_missing(): void {
		$cache = new ReportedIP_Hive_Cache();
		$this->assertFalse( $cache->get_reputation( '203.0.113.5' ) );
	}

	public function test_set_and_get_round_trip(): void {
		$cache = new ReportedIP_Hive_Cache();
		$cache->set_reputation( '203.0.113.5', array( 'abuseConfidencePercentage' => 80 ), false );
		$this->assertSame( array( 'abuseConfidencePercentage' => 80 ), $cache->get_reputation( '203.0.113.5' ) );
	}

	public function test_breaker_opens_after_three_failures_in_five_calls(): void {
		$cache = new ReportedIP_Hive_Cache();
		$this->assertFalse( $cache->is_breaker_open() );

		$cache->record_call_outcome( true );
		$cache->record_call_outcome( false );
		$cache->record_call_outcome( false );
		$cache->record_call_outcome( true );
		$cache->record_call_outcome( false );

		$this->assertTrue( $cache->is_breaker_open() );
	}

	public function test_breaker_stays_closed_with_only_two_failures(): void {
		$cache = new ReportedIP_Hive_Cache();

		$cache->record_call_outcome( false );
		$cache->record_call_outcome( true );
		$cache->record_call_outcome( true );
		$cache->record_call_outcome( false );
		$cache->record_call_outcome( true );

		$this->assertFalse( $cache->is_breaker_open() );
	}

	public function test_set_rate_limited_marks_state(): void {
		$cache = new ReportedIP_Hive_Cache();
		$this->assertFalse( $cache->is_rate_limited() );

		$cache->set_rate_limited( 600 );

		$this->assertTrue( $cache->is_rate_limited() );
	}
}
