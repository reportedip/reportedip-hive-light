<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Database;
use ReportedIP_Hive_IP_Manager;

final class IpManagerTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	public function test_is_blocked_uses_database_and_caches_result(): void {
		$db = new class extends ReportedIP_Hive_Database {
			public int $call_count = 0;
			public function is_blocked( string $ip_address ): bool {
				++$this->call_count;
				return '203.0.113.7' === $ip_address;
			}
		};

		$manager = new ReportedIP_Hive_IP_Manager( $db );

		$this->assertTrue( $manager->is_blocked( '203.0.113.7' ) );
		$this->assertTrue( $manager->is_blocked( '203.0.113.7' ) );
		$this->assertFalse( $manager->is_blocked( '203.0.113.8' ) );

		$this->assertSame( 2, $db->call_count, 'Cached IP must not re-query DB.' );
	}

	public function test_forget_invalidates_cache(): void {
		$db = new class extends ReportedIP_Hive_Database {
			public int $call_count = 0;
			public function is_blocked( string $ip_address ): bool {
				++$this->call_count;
				return true;
			}
		};

		$manager = new ReportedIP_Hive_IP_Manager( $db );

		$manager->is_blocked( '203.0.113.9' );
		$manager->forget( '203.0.113.9' );
		$manager->is_blocked( '203.0.113.9' );

		$this->assertSame( 2, $db->call_count, 'forget() must invalidate the cache.' );
	}

	public function test_whitelist_filter_can_short_circuit(): void {
		$db      = new ReportedIP_Hive_Database();
		$manager = new ReportedIP_Hive_IP_Manager( $db );

		$this->assertFalse( $manager->is_whitelisted( '203.0.113.10' ) );

		add_filter(
			'reportedip_hive_is_whitelisted',
			static fn( bool $current, string $ip ): bool => '203.0.113.10' === $ip ? true : $current,
			10,
			2
		);

		$this->assertTrue( $manager->is_whitelisted( '203.0.113.10' ) );
		$this->assertFalse( $manager->is_whitelisted( '203.0.113.11' ) );
	}

	public function test_empty_ip_is_never_blocked(): void {
		$db      = new ReportedIP_Hive_Database();
		$manager = new ReportedIP_Hive_IP_Manager( $db );

		$this->assertFalse( $manager->is_blocked( '' ) );
		$this->assertFalse( $manager->is_whitelisted( '' ) );
	}
}
