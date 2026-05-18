<?php
/**
 * Race-safe database layer for attempts, blocked IPs and the API report queue.
 *
 * @package   ReportedIP_Hive
 * @author    Patrick Schlesinger <1@reportedip.de>
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://wordpress.org/plugins/reportedip-hive/
 * @since     1.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'ReportedIP_Hive_Database' ) ) {

	/**
	 * Persistence service. All SQL uses $wpdb->prefix inline so the WordPress.org
	 * Plugin Check sniffer recognises the table-name interpolation as safe.
	 *
	 * @since 1.0.0
	 */
	class ReportedIP_Hive_Database {

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Live-write paths and read paths that must reflect the latest data; per-request cache lives in IP_Manager.

		/**
		 * Constructor — no state to initialise; kept for subclasses that wish to
		 * call parent::__construct() in tests or extensions.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {}

		/**
		 * Run dbDelta to install or migrate the schema.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function install_schema(): void {
			global $wpdb;

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$charset_collate = $wpdb->get_charset_collate();
			$attempts_table  = $wpdb->prefix . 'reportedip_hive_attempts';
			$blocked_table   = $wpdb->prefix . 'reportedip_hive_blocked';
			$queue_table     = $wpdb->prefix . 'reportedip_hive_api_queue';
			$whitelist_table = $wpdb->prefix . 'reportedip_hive_whitelist';

			$attempts = "CREATE TABLE {$attempts_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				attempt_type VARCHAR(32) NOT NULL DEFAULT 'login',
				username_hash CHAR(64) DEFAULT NULL,
				user_agent VARCHAR(255) DEFAULT NULL,
				attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
				window_start DATETIME NOT NULL,
				last_attempt DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_ip_type_user (ip_address, attempt_type, username_hash),
				KEY idx_last_attempt (last_attempt)
			) {$charset_collate};";

			$blocked = "CREATE TABLE {$blocked_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				reason VARCHAR(255) NOT NULL,
				block_type VARCHAR(20) NOT NULL DEFAULT 'automatic',
				blocked_until DATETIME DEFAULT NULL,
				failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_ip (ip_address),
				KEY idx_active (is_active, blocked_until)
			) {$charset_collate};";

			$queue = "CREATE TABLE {$queue_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				category_ids VARCHAR(64) NOT NULL,
				comment TEXT DEFAULT NULL,
				attempts INT UNSIGNED NOT NULL DEFAULT 0,
				max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
				submitted_at DATETIME DEFAULT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				error_message VARCHAR(255) DEFAULT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY idx_status_created (status, created_at)
			) {$charset_collate};";

			$whitelist = "CREATE TABLE {$whitelist_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ip_address VARCHAR(45) NOT NULL,
				reason VARCHAR(255) DEFAULT NULL,
				added_by BIGINT UNSIGNED DEFAULT NULL,
				expires_at DATETIME DEFAULT NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_ip (ip_address),
				KEY idx_active (is_active, expires_at)
			) {$charset_collate};";

			dbDelta( $attempts );
			dbDelta( $blocked );
			dbDelta( $queue );
			dbDelta( $whitelist );

			update_option( 'reportedip_hive_db_version', REPORTEDIP_HIVE_DB_VERSION );
		}

		/**
		 * Add an IP (or CIDR range) to the whitelist.
		 *
		 * @param string      $ip_address IPv4/v6 string or CIDR range (e.g. "203.0.113.0/24").
		 * @param string      $reason     Optional human-readable reason.
		 * @param int|null    $added_by   Optional WP user ID who added the entry.
		 * @param string|null $expires_at MySQL DATETIME or null for permanent.
		 * @return bool True on insert/update, false on validation failure.
		 * @since  1.1.0
		 */
		public function add_to_whitelist( string $ip_address, string $reason = '', ?int $added_by = null, ?string $expires_at = null ): bool {
			global $wpdb;

			$ip = trim( $ip_address );
			if ( '' === $ip ) {
				return false;
			}

			if ( false === strpos( $ip, '/' ) && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}reportedip_hive_whitelist
						(ip_address, reason, added_by, expires_at, is_active, created_at)
					VALUES (%s, %s, %d, %s, 1, %s)
					ON DUPLICATE KEY UPDATE
						reason     = VALUES(reason),
						added_by   = VALUES(added_by),
						expires_at = VALUES(expires_at),
						is_active  = 1",
					$ip,
					$reason,
					(int) ( $added_by ?? 0 ),
					$expires_at,
					current_time( 'mysql' )
				)
			);

			wp_cache_delete( 'reportedip_whitelist_cache', 'reportedip' );

			return true;
		}

		/**
		 * Remove an IP from the whitelist (hard delete).
		 *
		 * @param string $ip_address IPv4/v6 string or CIDR range.
		 * @return void
		 * @since  1.1.0
		 */
		public function remove_from_whitelist( string $ip_address ): void {
			global $wpdb;

			$wpdb->delete(
				$wpdb->prefix . 'reportedip_hive_whitelist',
				array( 'ip_address' => $ip_address ),
				array( '%s' )
			);

			wp_cache_delete( 'reportedip_whitelist_cache', 'reportedip' );
		}

		/**
		 * Whether the given IP matches an active whitelist entry. Honours both
		 * exact-match and CIDR-range entries; expired rows are ignored.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return bool
		 * @since  1.1.0
		 */
		public function is_ip_whitelisted( string $ip_address ): bool {
			$ip = trim( $ip_address );
			if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return false;
			}

			$entries = wp_cache_get( 'reportedip_whitelist_cache', 'reportedip' );
			if ( false === $entries || ! is_array( $entries ) ) {
				$entries = $this->get_active_whitelist_entries_raw();
				wp_cache_set( 'reportedip_whitelist_cache', $entries, 'reportedip', 5 * MINUTE_IN_SECONDS );
			}

			foreach ( $entries as $entry ) {
				$candidate = (string) $entry;
				if ( $candidate === $ip ) {
					return true;
				}
				if ( false !== strpos( $candidate, '/' ) && self::ip_in_cidr( $ip, $candidate ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Internal: fetch active, non-expired whitelist entries as plain strings.
		 *
		 * @return array<int, string>
		 * @since  1.1.0
		 */
		private function get_active_whitelist_entries_raw(): array {
			global $wpdb;

			$rows = $wpdb->get_col(
				"SELECT ip_address FROM {$wpdb->prefix}reportedip_hive_whitelist
				WHERE is_active = 1
				  AND ( expires_at IS NULL OR expires_at = '0000-00-00 00:00:00' OR expires_at > NOW() )"
			);

			return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
		}

		/**
		 * Test whether an IPv4 address falls inside a CIDR range. IPv6 ranges
		 * are not supported in the Light edition and return false.
		 *
		 * @param string $ip   Address.
		 * @param string $cidr e.g. "203.0.113.0/24".
		 * @return bool
		 * @since  1.1.0
		 */
		public static function ip_in_cidr( string $ip, string $cidr ): bool {
			if ( false === strpos( $cidr, '/' ) ) {
				return false;
			}

			[ $subnet, $bits_raw ] = explode( '/', $cidr, 2 );
			$bits                  = (int) $bits_raw;

			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return false;
			}
			if ( false === filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return false;
			}
			if ( $bits < 0 || $bits > 32 ) {
				return false;
			}

			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );
			if ( false === $ip_long || false === $subnet_long ) {
				return false;
			}

			$mask = $bits === 0 ? 0 : ( ~0 << ( 32 - $bits ) );

			return ( $ip_long & $mask ) === ( $subnet_long & $mask );
		}

		/**
		 * Fetch a paginated slice of whitelist entries for the admin list-table.
		 *
		 * @param int $per_page Page size.
		 * @param int $offset   Row offset.
		 * @return array<int, array<string, mixed>>
		 * @since  1.1.0
		 */
		public function get_whitelist_rows( int $per_page, int $offset ): array {
			global $wpdb;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}reportedip_hive_whitelist
					ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Total number of whitelist rows.
		 *
		 * @return int
		 * @since  1.1.0
		 */
		public function get_whitelist_count(): int {
			global $wpdb;

			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reportedip_hive_whitelist" );

			return (int) $count;
		}

		/**
		 * Compare the stored schema version against the runtime constant and
		 * re-run dbDelta if the runtime is newer.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function maybe_update_schema(): void {
			$current = (string) get_option( 'reportedip_hive_db_version', '0.0.0' );
			if ( version_compare( $current, REPORTEDIP_HIVE_DB_VERSION, '>=' ) ) {
				return;
			}
			$this->install_schema();
		}

		/**
		 * Atomically record a single attempt for an IP, attempt-type, and
		 * (optional) hashed-username triplet. Resets the counter when the
		 * sliding window has expired.
		 *
		 * @param string      $ip_address        IPv4/v6 string.
		 * @param string      $attempt_type      One of 'login'|'spray_sample'.
		 * @param string|null $username_hash     SHA-256 hex string or null.
		 * @param string|null $user_agent        Truncated User-Agent.
		 * @param int         $timeframe_minutes Window length in minutes.
		 * @return void
		 * @since  1.0.0
		 */
		public function track_attempt( string $ip_address, string $attempt_type, ?string $username_hash, ?string $user_agent, int $timeframe_minutes ): void {
			global $wpdb;

			$timeframe = max( 1, $timeframe_minutes );
			$ua        = null === $user_agent ? null : substr( $user_agent, 0, 255 );

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}reportedip_hive_attempts
						(ip_address, attempt_type, username_hash, user_agent, attempt_count, window_start, last_attempt)
					VALUES (%s, %s, %s, %s, 1, %s, %s)
					ON DUPLICATE KEY UPDATE
						attempt_count = IF(window_start < (NOW() - INTERVAL %d MINUTE), 1, attempt_count + 1),
						window_start  = IF(window_start < (NOW() - INTERVAL %d MINUTE), NOW(), window_start),
						last_attempt  = NOW(),
						user_agent    = COALESCE(VALUES(user_agent), user_agent)",
					$ip_address,
					$attempt_type,
					$username_hash,
					$ua,
					current_time( 'mysql' ),
					current_time( 'mysql' ),
					$timeframe,
					$timeframe
				)
			);
		}

		/**
		 * Sum of `attempt_count` for the given IP and type within the window.
		 *
		 * @param string $ip_address        IPv4/v6 string.
		 * @param string $attempt_type      Attempt type.
		 * @param int    $timeframe_minutes Window length.
		 * @return int
		 * @since  1.0.0
		 */
		public function get_attempt_count( string $ip_address, string $attempt_type, int $timeframe_minutes ): int {
			global $wpdb;

			$timeframe = max( 1, $timeframe_minutes );

			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(attempt_count), 0) FROM {$wpdb->prefix}reportedip_hive_attempts
					WHERE ip_address = %s AND attempt_type = %s AND last_attempt > (NOW() - INTERVAL %d MINUTE)",
					$ip_address,
					$attempt_type,
					$timeframe
				)
			);

			return (int) $count;
		}

		/**
		 * Distinct hashed-username count seen for the IP within the window.
		 *
		 * @param string $ip_address        IPv4/v6 string.
		 * @param int    $timeframe_minutes Window length.
		 * @return int
		 * @since  1.0.0
		 */
		public function count_distinct_spray_usernames( string $ip_address, int $timeframe_minutes ): int {
			global $wpdb;

			$timeframe = max( 1, $timeframe_minutes );

			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT username_hash) FROM {$wpdb->prefix}reportedip_hive_attempts
					WHERE ip_address = %s AND attempt_type = %s AND username_hash IS NOT NULL AND last_attempt > (NOW() - INTERVAL %d MINUTE)",
					$ip_address,
					'spray_sample',
					$timeframe
				)
			);

			return (int) $count;
		}

		/**
		 * Reset all login counters for the IP (called on successful login).
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return void
		 * @since  1.0.0
		 */
		public function reset_attempts_for_ip( string $ip_address ): void {
			global $wpdb;

			$wpdb->delete(
				$wpdb->prefix . 'reportedip_hive_attempts',
				array( 'ip_address' => $ip_address ),
				array( '%s' )
			);
		}

		/**
		 * Whether the IP has an active block (blocked_until in the future or null).
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return bool
		 * @since  1.0.0
		 */
		public function is_blocked( string $ip_address ): bool {
			global $wpdb;

			$blocked_until = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT blocked_until FROM {$wpdb->prefix}reportedip_hive_blocked
					WHERE ip_address = %s AND is_active = 1
					LIMIT 1",
					$ip_address
				)
			);

			if ( null === $blocked_until ) {
				return false;
			}

			if ( '' === $blocked_until ) {
				return true;
			}

			return strtotime( (string) $blocked_until ) > time();
		}

		/**
		 * Insert or extend a block for the IP.
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @param string $reason     Human-readable reason.
		 * @param string $block_type One of 'manual'|'automatic'|'reputation'.
		 * @param int    $minutes    Block duration; 0 = permanent.
		 * @return void
		 * @since  1.0.0
		 */
		public function block_ip_for_minutes( string $ip_address, string $reason, string $block_type, int $minutes ): void {
			global $wpdb;

			$blocked_until = $minutes > 0
				? gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) )
				: null;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}reportedip_hive_blocked
						(ip_address, reason, block_type, blocked_until, failed_attempts, is_active, created_at)
					VALUES (%s, %s, %s, %s, 1, 1, %s)
					ON DUPLICATE KEY UPDATE
						reason          = VALUES(reason),
						block_type      = VALUES(block_type),
						blocked_until   = VALUES(blocked_until),
						failed_attempts = failed_attempts + 1,
						is_active       = 1",
					$ip_address,
					$reason,
					$block_type,
					$blocked_until,
					current_time( 'mysql' )
				)
			);
		}

		/**
		 * Deactivate a block (used by the "Unblock" admin action).
		 *
		 * @param string $ip_address IPv4/v6 string.
		 * @return void
		 * @since  1.0.0
		 */
		public function unblock_ip( string $ip_address ): void {
			global $wpdb;

			$wpdb->update(
				$wpdb->prefix . 'reportedip_hive_blocked',
				array(
					'is_active'     => 0,
					'blocked_until' => null,
				),
				array( 'ip_address' => $ip_address ),
				array( '%d', '%s' ),
				array( '%s' )
			);
		}

		/**
		 * Bulk-deactivate blocks by row ID. Single UPDATE replaces an
		 * unblock_ip()-per-row loop in the admin bulk-action handler.
		 *
		 * @param array<int, int> $ids Row IDs to deactivate.
		 * @return int Affected rows.
		 * @since  1.2.0
		 */
		public function bulk_unblock_by_ids( array $ids ): int {
			if ( empty( $ids ) ) {
				return 0;
			}

			global $wpdb;

			$ids          = array_values( array_map( 'intval', $ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a fixed-format string of %d placeholders matching the count of validated integer IDs; values are passed through prepare().
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}reportedip_hive_blocked
					SET is_active = 0, blocked_until = NULL
					WHERE id IN ({$placeholders})",
					$ids
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			return is_numeric( $result ) ? (int) $result : 0;
		}

		/**
		 * Number of recorded blocks for the IP within the reset window. Drives
		 * the progressive escalation ladder.
		 *
		 * @param string $ip_address  IPv4/v6 string.
		 * @param int    $window_days Reset window in days.
		 * @return int
		 * @since  1.0.0
		 */
		public function count_recent_blocks( string $ip_address, int $window_days ): int {
			global $wpdb;

			$days = max( 1, $window_days );

			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT failed_attempts FROM {$wpdb->prefix}reportedip_hive_blocked
					WHERE ip_address = %s AND created_at > (NOW() - INTERVAL %d DAY)",
					$ip_address,
					$days
				)
			);

			return (int) $count;
		}

		/**
		 * Append a queued API report (status=pending). No-op when an open
		 * report (pending or processing) already exists for this IP — a
		 * sustained brute-force on a single IP must yield exactly one
		 * outbound report per incident, not one per attempt.
		 *
		 * @param string $ip_address    IPv4/v6 string.
		 * @param string $category_ids  Comma-separated category IDs.
		 * @param string $comment       Optional human-readable comment.
		 * @return void
		 * @since  1.0.0
		 */
		public function queue_api_report( string $ip_address, string $category_ids, string $comment = '' ): void {
			global $wpdb;

			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}reportedip_hive_api_queue
					WHERE ip_address = %s AND status IN ('pending', 'processing')
					LIMIT 1",
					$ip_address
				)
			);
			if ( null !== $existing ) {
				return;
			}

			$wpdb->insert(
				$wpdb->prefix . 'reportedip_hive_api_queue',
				array(
					'ip_address'   => $ip_address,
					'category_ids' => $category_ids,
					'comment'      => '' === $comment ? null : $comment,
					'attempts'     => 0,
					'max_attempts' => 3,
					'status'       => 'pending',
					'created_at'   => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
		}

		/**
		 * Claim and return up to `$limit` queued reports, marking them as
		 * `processing`. Re-claims rows stuck in `processing` longer than the
		 * configured timeout (crash recovery).
		 *
		 * @param int $limit Maximum number of reports to claim.
		 * @return array<int, array<string, mixed>>
		 * @since  1.0.0
		 */
		public function claim_pending_reports( int $limit ): array {
			global $wpdb;

			$timeout = max( 1, (int) get_option( 'reportedip_hive_processing_timeout_minutes', 10 ) );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}reportedip_hive_api_queue
					SET status = 'pending', submitted_at = NULL
					WHERE status = 'processing' AND submitted_at IS NOT NULL AND submitted_at < (NOW() - INTERVAL %d MINUTE)",
					$timeout
				)
			);

			$batch = max( 1, $limit );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}reportedip_hive_api_queue
					WHERE status = 'pending' AND attempts < max_attempts
					ORDER BY created_at ASC
					LIMIT %d",
					$batch
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return array();
			}

			$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}reportedip_hive_api_queue
					SET status = 'processing', submitted_at = %s
					WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					array_merge( array( current_time( 'mysql' ) ), $ids )
				)
			);

			return $rows;
		}

		/**
		 * Mark a queued report as completed.
		 *
		 * @param int $report_id Row ID.
		 * @return void
		 * @since  1.0.0
		 */
		public function mark_report_completed( int $report_id ): void {
			global $wpdb;

			$wpdb->update(
				$wpdb->prefix . 'reportedip_hive_api_queue',
				array(
					'status'        => 'completed',
					'submitted_at'  => current_time( 'mysql' ),
					'error_message' => null,
				),
				array( 'id' => $report_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		/**
		 * Mark a queued report as failed and increment the attempt counter.
		 * Once `attempts >= max_attempts` the row is moved to status `failed`.
		 *
		 * @param int    $report_id Row ID.
		 * @param string $message   Short error message (≤ 255 chars).
		 * @return void
		 * @since  1.0.0
		 */
		public function mark_report_failed( int $report_id, string $message ): void {
			global $wpdb;

			$short = substr( $message, 0, 255 );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}reportedip_hive_api_queue
					SET attempts = attempts + 1,
					    status = IF(attempts + 1 >= max_attempts, 'failed', 'pending'),
					    error_message = %s,
					    submitted_at = NULL
					WHERE id = %d",
					$short,
					$report_id
				)
			);
		}

		/**
		 * Cleanup helper — delete attempt rows older than `$minutes`.
		 *
		 * @param int $minutes Retention window.
		 * @return int Affected rows.
		 * @since  1.0.0
		 */
		public function cleanup_old_attempts( int $minutes ): int {
			global $wpdb;

			$min = max( 1, $minutes );

			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}reportedip_hive_attempts WHERE last_attempt < (NOW() - INTERVAL %d MINUTE)",
					$min
				)
			);

			return is_numeric( $result ) ? (int) $result : 0;
		}

		/**
		 * Cleanup helper — deactivate expired automatic blocks.
		 *
		 * @return int Affected rows.
		 * @since  1.0.0
		 */
		public function cleanup_expired_blocks(): int {
			global $wpdb;

			$result = $wpdb->query(
				"UPDATE {$wpdb->prefix}reportedip_hive_blocked
				SET is_active = 0
				WHERE is_active = 1 AND blocked_until IS NOT NULL AND blocked_until < NOW()"
			);

			return is_numeric( $result ) ? (int) $result : 0;
		}

		/**
		 * Cleanup helper — delete completed/failed queue rows older than the
		 * retention window.
		 *
		 * @param int $days Retention window in days.
		 * @return int Affected rows.
		 * @since  1.0.0
		 */
		public function cleanup_old_queue_rows( int $days ): int {
			global $wpdb;

			$d = max( 1, $days );

			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}reportedip_hive_api_queue
					WHERE status IN ('completed', 'failed') AND created_at < (NOW() - INTERVAL %d DAY)",
					$d
				)
			);

			return is_numeric( $result ) ? (int) $result : 0;
		}

		/**
		 * Fetch a paginated slice of blocked IPs for the admin list-table.
		 *
		 * @param int    $per_page Page size.
		 * @param int    $offset   Row offset.
		 * @param string $order_by Column name (allow-listed).
		 * @param string $order    'ASC'|'DESC'.
		 * @return array<int, array<string, mixed>>
		 * @since  1.0.0
		 */
		public function get_blocked_rows( int $per_page, int $offset, string $order_by, string $order ): array {
			global $wpdb;

			$direction = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';
			$key       = $order_by . '|' . $direction;

			$rows = match ( $key ) {
				'ip_address|ASC'       => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY ip_address ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'ip_address|DESC'      => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY ip_address DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'reason|ASC'           => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY reason ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'reason|DESC'          => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY reason DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'block_type|ASC'       => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY block_type ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'block_type|DESC'      => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY block_type DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'failed_attempts|ASC'  => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY failed_attempts ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'failed_attempts|DESC' => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY failed_attempts DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'blocked_until|ASC'    => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY blocked_until ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'blocked_until|DESC'   => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY blocked_until DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				'created_at|ASC'       => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY created_at ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
				default                => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}reportedip_hive_blocked ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A ),
			};

			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Total number of blocked rows.
		 *
		 * @return int
		 * @since  1.0.0
		 */
		public function get_blocked_count(): int {
			global $wpdb;

			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}reportedip_hive_blocked" );

			return (int) $count;
		}

		/**
		 * Aggregate stats for the dashboard.
		 *
		 * @return array{blocks_active:int,blocks_24h:int,blocks_7d:int,blocks_30d:int,blocks_total:int,attempts_24h:int,whitelist:int,queue_pending:int,queue_processing:int,queue_completed:int,queue_failed:int}
		 * @since  1.2.0
		 */
		public function get_dashboard_stats(): array {
			global $wpdb;

			$blocks_active = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}reportedip_hive_blocked
				WHERE is_active = 1 AND ( blocked_until IS NULL OR blocked_until > NOW() )"
			);

			$block_windows = $wpdb->get_row(
				"SELECT
					SUM( CASE WHEN created_at > (NOW() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END ) AS h24,
					SUM( CASE WHEN created_at > (NOW() - INTERVAL 7 DAY)   THEN 1 ELSE 0 END ) AS d7,
					SUM( CASE WHEN created_at > (NOW() - INTERVAL 30 DAY)  THEN 1 ELSE 0 END ) AS d30,
					COUNT(*) AS total
				FROM {$wpdb->prefix}reportedip_hive_blocked",
				ARRAY_A
			);

			$attempts_24h = (int) $wpdb->get_var(
				"SELECT COALESCE(SUM(attempt_count), 0) FROM {$wpdb->prefix}reportedip_hive_attempts
				WHERE attempt_type = 'login' AND last_attempt > (NOW() - INTERVAL 24 HOUR)"
			);

			$whitelist = $this->get_whitelist_count();

			$queue_counts = $wpdb->get_row(
				"SELECT
					SUM( CASE WHEN status='pending' THEN 1 ELSE 0 END )    AS p,
					SUM( CASE WHEN status='processing' THEN 1 ELSE 0 END ) AS pr,
					SUM( CASE WHEN status='completed' THEN 1 ELSE 0 END )  AS c,
					SUM( CASE WHEN status='failed' THEN 1 ELSE 0 END )     AS f
				FROM {$wpdb->prefix}reportedip_hive_api_queue",
				ARRAY_A
			);

			return array(
				'blocks_active'    => $blocks_active,
				'blocks_24h'       => (int) ( $block_windows['h24'] ?? 0 ),
				'blocks_7d'        => (int) ( $block_windows['d7'] ?? 0 ),
				'blocks_30d'       => (int) ( $block_windows['d30'] ?? 0 ),
				'blocks_total'     => (int) ( $block_windows['total'] ?? 0 ),
				'attempts_24h'     => $attempts_24h,
				'whitelist'        => $whitelist,
				'queue_pending'    => (int) ( $queue_counts['p'] ?? 0 ),
				'queue_processing' => (int) ( $queue_counts['pr'] ?? 0 ),
				'queue_completed'  => (int) ( $queue_counts['c'] ?? 0 ),
				'queue_failed'     => (int) ( $queue_counts['f'] ?? 0 ),
			);
		}

		/**
		 * Fetch the most recent attempts for the activity tab.
		 *
		 * @param int $limit Max rows.
		 * @return array<int, array<string, mixed>>
		 * @since  1.0.0
		 */
		public function get_recent_attempts( int $limit ): array {
			global $wpdb;

			$max = max( 1, min( $limit, 200 ) );

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}reportedip_hive_attempts ORDER BY last_attempt DESC LIMIT %d",
					$max
				),
				ARRAY_A
			);

			return is_array( $rows ) ? $rows : array();
		}

		// phpcs:enable
	}
}
