<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Database;

final class DatabaseMigrationTest extends TestCase {

	private const OLD_DEFAULT = 'https://reportedip.de/wp-json/reportedip/v2/';
	private const NEW_DEFAULT = 'https://reportedip.com/wp-json/reportedip/v2/';

	protected function setUp(): void {
		rip_test_reset();
	}

	/**
	 * Build a Database instance whose install_schema() is a no-op so the
	 * migration path can run without dbDelta / upgrade.php.
	 */
	private function make_database(): ReportedIP_Hive_Database {
		return new class() extends ReportedIP_Hive_Database {
			public function install_schema(): void {
				update_option( 'reportedip_hive_db_version', REPORTEDIP_HIVE_DB_VERSION );
			}
		};
	}

	public function test_old_default_endpoint_is_migrated_to_new_domain(): void {
		global $rip_test_options;

		$rip_test_options['reportedip_hive_db_version']   = '1.1.0';
		$rip_test_options['reportedip_hive_api_endpoint'] = self::OLD_DEFAULT;

		$this->make_database()->maybe_update_schema();

		$this->assertSame( self::NEW_DEFAULT, get_option( 'reportedip_hive_api_endpoint' ) );
		$this->assertSame( REPORTEDIP_HIVE_DB_VERSION, get_option( 'reportedip_hive_db_version' ) );
	}

	public function test_custom_endpoint_is_left_untouched(): void {
		global $rip_test_options;

		$custom = 'https://example.test/api/';

		$rip_test_options['reportedip_hive_db_version']   = '1.1.0';
		$rip_test_options['reportedip_hive_api_endpoint'] = $custom;

		$this->make_database()->maybe_update_schema();

		$this->assertSame( $custom, get_option( 'reportedip_hive_api_endpoint' ) );
	}

	public function test_migration_is_skipped_when_schema_is_current(): void {
		global $rip_test_options;

		$rip_test_options['reportedip_hive_db_version']   = REPORTEDIP_HIVE_DB_VERSION;
		$rip_test_options['reportedip_hive_api_endpoint'] = self::OLD_DEFAULT;

		$this->make_database()->maybe_update_schema();

		$this->assertSame( self::OLD_DEFAULT, get_option( 'reportedip_hive_api_endpoint' ) );
	}
}
