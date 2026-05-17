<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Database;

final class DatabaseCidrTest extends TestCase {

	public function test_ip_in_cidr_matches_full_range(): void {
		$this->assertTrue( ReportedIP_Hive_Database::ip_in_cidr( '192.0.2.10', '0.0.0.0/0' ) );
	}

	public function test_ip_in_cidr_matches_24_subnet(): void {
		$this->assertTrue( ReportedIP_Hive_Database::ip_in_cidr( '192.0.2.10', '192.0.2.0/24' ) );
		$this->assertTrue( ReportedIP_Hive_Database::ip_in_cidr( '192.0.2.255', '192.0.2.0/24' ) );
		$this->assertFalse( ReportedIP_Hive_Database::ip_in_cidr( '192.0.3.1', '192.0.2.0/24' ) );
	}

	public function test_ip_in_cidr_rejects_invalid_range(): void {
		$this->assertFalse( ReportedIP_Hive_Database::ip_in_cidr( '192.0.2.10', 'not-a-cidr' ) );
		$this->assertFalse( ReportedIP_Hive_Database::ip_in_cidr( '192.0.2.10', '192.0.2.0/33' ) );
		$this->assertFalse( ReportedIP_Hive_Database::ip_in_cidr( 'not-an-ip', '192.0.2.0/24' ) );
	}

	public function test_ip_in_cidr_rejects_ipv6(): void {
		$this->assertFalse( ReportedIP_Hive_Database::ip_in_cidr( '2001:db8::1', '2001:db8::/32' ) );
	}
}
