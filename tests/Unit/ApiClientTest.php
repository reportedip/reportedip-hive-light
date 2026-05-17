<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_API;
use ReportedIP_Hive_Cache;
use ReportedIP_Hive_Database;

final class ApiClientTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	public function test_is_active_false_when_local_mode(): void {
		update_option( 'reportedip_hive_operation_mode', 'local' );
		update_option( 'reportedip_hive_api_key', 'whatever' );

		$api = new ReportedIP_Hive_API( new ReportedIP_Hive_Cache(), new ReportedIP_Hive_Database() );
		$this->assertFalse( $api->is_active() );
	}

	public function test_is_active_false_when_no_key(): void {
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', '' );

		$api = new ReportedIP_Hive_API( new ReportedIP_Hive_Cache(), new ReportedIP_Hive_Database() );
		$this->assertFalse( $api->is_active() );
	}

	public function test_is_active_true_when_community_and_key(): void {
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', 'abc123' );

		$api = new ReportedIP_Hive_API( new ReportedIP_Hive_Cache(), new ReportedIP_Hive_Database() );
		$this->assertTrue( $api->is_active() );
	}

	public function test_check_ip_reputation_short_circuits_when_local(): void {
		update_option( 'reportedip_hive_operation_mode', 'local' );
		update_option( 'reportedip_hive_api_key', '' );

		$api = new ReportedIP_Hive_API( new ReportedIP_Hive_Cache(), new ReportedIP_Hive_Database() );
		$this->assertFalse( $api->check_ip_reputation( '203.0.113.20' ) );
	}

	public function test_check_ip_reputation_returns_cached_payload(): void {
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', 'abc123' );

		$cache = new ReportedIP_Hive_Cache();
		$cache->set_reputation( '203.0.113.20', array( 'abuseConfidencePercentage' => 90 ), false );

		$api    = new ReportedIP_Hive_API( $cache, new ReportedIP_Hive_Database() );
		$result = $api->check_ip_reputation( '203.0.113.20' );

		$this->assertIsArray( $result );
		$this->assertSame( 90, $result['abuseConfidencePercentage'] );
	}

	public function test_check_ip_reputation_returns_false_when_breaker_open(): void {
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', 'abc123' );

		$cache = new ReportedIP_Hive_Cache();
		$cache->open_breaker();

		$api = new ReportedIP_Hive_API( $cache, new ReportedIP_Hive_Database() );
		$this->assertFalse( $api->check_ip_reputation( '203.0.113.21' ) );
	}

	public function test_verify_key_with_empty_key_returns_invalid(): void {
		$api    = new ReportedIP_Hive_API( new ReportedIP_Hive_Cache(), new ReportedIP_Hive_Database() );
		$result = $api->verify_key( '' );

		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( $result['message'] );
	}
}
