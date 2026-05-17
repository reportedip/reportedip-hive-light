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
use ReportedIP_Hive_IP_Manager;
use ReportedIP_Hive_Security_Monitor;
use WP_Error;

final class SecurityMonitorTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	private function monitor_with_state( bool $blocked = false, ?array $reputation = null ): ReportedIP_Hive_Security_Monitor {
		$db = new class( $blocked ) extends ReportedIP_Hive_Database {
			public function __construct( public bool $blocked_flag ) {
				parent::__construct();
			}
			public function is_blocked( string $ip_address ): bool {
				return $this->blocked_flag;
			}
		};

		$ip_manager = new ReportedIP_Hive_IP_Manager( $db );

		$cache = new ReportedIP_Hive_Cache();
		$api   = new class( $cache, $db, $reputation ) extends ReportedIP_Hive_API {
			public function __construct(
				ReportedIP_Hive_Cache $cache,
				ReportedIP_Hive_Database $db,
				public ?array $stub_reputation
			) {
				parent::__construct( $cache, $db );
			}
			public function is_active(): bool {
				return null !== $this->stub_reputation;
			}
			public function check_ip_reputation( string $ip_address ) {
				return $this->stub_reputation ?? false;
			}
		};

		return new ReportedIP_Hive_Security_Monitor( $db, $ip_manager, $api );
	}

	public function test_pre_auth_check_passes_when_not_blocked(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.30';
		$monitor                = $this->monitor_with_state( false, null );

		$user   = (object) array( 'ID' => 1 );
		$result = $monitor->pre_auth_check( $user, 'pw' );

		$this->assertSame( $user, $result );
	}

	public function test_pre_auth_check_returns_wp_error_when_blocked(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.31';
		$monitor                = $this->monitor_with_state( true, null );

		$user   = (object) array( 'ID' => 1 );
		$result = $monitor->pre_auth_check( $user, 'pw' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reportedip_hive_blocked', $result->get_error_code() );
	}

	public function test_pre_auth_check_returns_wp_error_on_high_reputation_score(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.32';
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', 'abc' );

		$monitor = $this->monitor_with_state( false, array( 'abuseConfidencePercentage' => 95 ) );

		$user   = (object) array( 'ID' => 1 );
		$result = $monitor->pre_auth_check( $user, 'pw' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'reportedip_hive_reputation_blocked', $result->get_error_code() );
	}

	public function test_pre_auth_check_passes_on_low_reputation_score(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.33';
		update_option( 'reportedip_hive_operation_mode', 'community' );
		update_option( 'reportedip_hive_api_key', 'abc' );

		$monitor = $this->monitor_with_state( false, array( 'abuseConfidencePercentage' => 25 ) );

		$user   = (object) array( 'ID' => 1 );
		$result = $monitor->pre_auth_check( $user, 'pw' );

		$this->assertSame( $user, $result );
	}
}
