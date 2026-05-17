<?php
/**
 * Uninstall handler — wipes plugin tables, options, transients and cron events.
 *
 * Only runs when the user has opted in via the "Delete all data on uninstall"
 * setting. Multisite-aware: iterates every site on the network.
 *
 * @package   ReportedIP_Hive
 * @author    Patrick Schlesinger <1@reportedip.de>
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://wordpress.org/plugins/reportedip-hive/
 * @since     1.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drop all plugin data on the current site.
 *
 * Caller must ensure that `switch_to_blog()` has been invoked beforehand on
 * multisite installs.
 *
 * @return void
 */
function reportedip_hive_uninstall_current_site(): void {
	global $wpdb;

	if ( ! get_option( 'reportedip_hive_delete_data_on_uninstall', false ) ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'reportedip_hive_attempts',
		$wpdb->prefix . 'reportedip_hive_blocked',
		$wpdb->prefix . 'reportedip_hive_api_queue',
		$wpdb->prefix . 'reportedip_hive_whitelist',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is built from $wpdb->prefix and a hard-coded suffix; uninstall path runs once and cannot use prepared identifiers.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk option cleanup, no caching layer applies.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			'reportedip_hive_%'
		)
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup, no caching layer applies.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			'_transient_reportedip_hive_%',
			'_transient_timeout_reportedip_hive_%',
			'_site_transient_reportedip_hive_%',
			'_site_transient_timeout_reportedip_hive_%'
		)
	);

	wp_clear_scheduled_hook( 'reportedip_hive_process_queue' );
	wp_clear_scheduled_hook( 'reportedip_hive_cleanup' );

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'reportedip' );
	} else {
		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local-scope loop variables in the uninstall script.
	$reportedip_hive_site_ids = get_sites( array( 'fields' => 'ids' ) );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Local-scope loop variables in the uninstall script.
	foreach ( $reportedip_hive_site_ids as $reportedip_hive_site_id ) {
		switch_to_blog( (int) $reportedip_hive_site_id );
		reportedip_hive_uninstall_current_site();
		restore_current_blog();
	}
} else {
	reportedip_hive_uninstall_current_site();
}
