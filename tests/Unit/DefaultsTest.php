<?php
/**
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

namespace ReportedIP_Hive\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReportedIP_Hive_Defaults;

final class DefaultsTest extends TestCase {

	protected function setUp(): void {
		rip_test_reset();
	}

	public function test_all_returns_expected_keys(): void {
		$defaults = ReportedIP_Hive_Defaults::all();

		$expected = array(
			'reportedip_hive_db_version',
			'reportedip_hive_operation_mode',
			'reportedip_hive_api_key',
			'reportedip_hive_api_endpoint',
			'reportedip_hive_trusted_ip_header',
			'reportedip_hive_failed_login_threshold',
			'reportedip_hive_failed_login_timeframe',
			'reportedip_hive_password_spray_threshold',
			'reportedip_hive_password_spray_timeframe',
			'reportedip_hive_auto_block',
			'reportedip_hive_block_escalation_enabled',
			'reportedip_hive_block_ladder_minutes',
			'reportedip_hive_block_ladder_reset_days',
			'reportedip_hive_report_only_mode',
			'reportedip_hive_cache_duration',
			'reportedip_hive_negative_cache_duration',
			'reportedip_hive_max_api_calls_per_hour',
			'reportedip_hive_queue_max_age_days',
			'reportedip_hive_processing_timeout_minutes',
			'reportedip_hive_delete_data_on_uninstall',
		);

		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "Missing default key: {$key}" );
		}
	}

	public function test_default_operation_mode_is_local(): void {
		$this->assertSame( 'local', ReportedIP_Hive_Defaults::get( 'reportedip_hive_operation_mode' ) );
	}

	public function test_seed_options_inserts_missing_options(): void {
		ReportedIP_Hive_Defaults::seed_options();

		$this->assertSame( 5, get_option( 'reportedip_hive_failed_login_threshold' ) );
		$this->assertSame( 'local', get_option( 'reportedip_hive_operation_mode' ) );
	}

	public function test_seed_options_is_idempotent(): void {
		update_option( 'reportedip_hive_failed_login_threshold', 99 );
		ReportedIP_Hive_Defaults::seed_options();
		$this->assertSame( 99, get_option( 'reportedip_hive_failed_login_threshold' ) );
	}

	public function test_get_unknown_returns_empty_string(): void {
		$this->assertSame( '', ReportedIP_Hive_Defaults::get( 'no_such_option' ) );
	}
}
