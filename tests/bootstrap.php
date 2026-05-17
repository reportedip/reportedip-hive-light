<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * @package ReportedIP_Hive
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'REPORTEDIP_HIVE_VERSION' ) ) {
	define( 'REPORTEDIP_HIVE_VERSION', '1.0.0-test' );
}
if ( ! defined( 'REPORTEDIP_HIVE_DB_VERSION' ) ) {
	define( 'REPORTEDIP_HIVE_DB_VERSION', '1.0.0' );
}
if ( ! defined( 'REPORTEDIP_HIVE_PLUGIN_DIR' ) ) {
	define( 'REPORTEDIP_HIVE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'REPORTEDIP_HIVE_PLUGIN_URL' ) ) {
	define( 'REPORTEDIP_HIVE_PLUGIN_URL', 'https://example.test/wp-content/plugins/reportedip-hive/' );
}
if ( ! defined( 'REPORTEDIP_HIVE_PLUGIN_FILE' ) ) {
	define( 'REPORTEDIP_HIVE_PLUGIN_FILE', dirname( __DIR__ ) . '/reportedip-hive.php' );
}
if ( ! defined( 'REPORTEDIP_HIVE_PLUGIN_BASENAME' ) ) {
	define( 'REPORTEDIP_HIVE_PLUGIN_BASENAME', 'reportedip-hive/reportedip-hive.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

global $wpdb;
$wpdb = new class {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function prepare( string $query, ...$args ): string {
		return $query;
	}
	public function query( string $sql ) {
		return 0;
	}
	public function get_var( string $sql ) {
		return null;
	}
	public function get_results( string $sql, $output = ARRAY_A ): array {
		return array();
	}
	public function get_col( string $sql ): array {
		return array();
	}
	public function get_row( string $sql, $output = ARRAY_A ) {
		return null;
	}
	public function insert( string $table, array $data, $format = null ): int {
		return 1;
	}
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
		return 1;
	}
	public function delete( string $table, array $where, $where_format = null ): int {
		return 1;
	}
};

global $rip_test_options;
$rip_test_options = array();

global $rip_test_filters;
$rip_test_filters = array();

global $rip_test_transients;
$rip_test_transients = array();

global $rip_test_cache;
$rip_test_cache = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $rip_test_options;
		return $rip_test_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = '' ) {
		global $rip_test_options;
		$rip_test_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $name, $value, $deprecated = '', $autoload = true ) {
		global $rip_test_options;
		if ( array_key_exists( $name, $rip_test_options ) ) {
			return false;
		}
		$rip_test_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		global $rip_test_filters;
		if ( isset( $rip_test_filters[ $tag ] ) ) {
			foreach ( $rip_test_filters[ $tag ] as $callback ) {
				$args[0] = call_user_func_array( $callback, $args );
			}
		}
		return $args[0];
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		global $rip_test_filters;
		$rip_test_filters[ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
		return null;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'reportedip-test-salt';
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( strip_tags( $value ) ) : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		global $rip_test_transients;
		if ( ! array_key_exists( $name, $rip_test_transients ) ) {
			return false;
		}
		$entry = $rip_test_transients[ $name ];
		if ( $entry['expires'] < time() ) {
			unset( $rip_test_transients[ $name ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) {
		global $rip_test_transients;
		$rip_test_transients[ $name ] = array(
			'value'   => $value,
			'expires' => time() + ( $ttl > 0 ? $ttl : 86400 ),
		);
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		global $rip_test_transients;
		unset( $rip_test_transients[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $key, $group = '' ) {
		global $rip_test_cache;
		$full_key = $group . '/' . $key;
		return $rip_test_cache[ $full_key ] ?? false;
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
		global $rip_test_cache;
		$full_key = $group . '/' . $key;
		$rip_test_cache[ $full_key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $path ) {
		return rtrim( $path, '/' ) . '/';
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		$query = http_build_query( $args );
		return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $query;
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		return new WP_Error_Stub( 'http_error', 'Stub: no real HTTP in unit tests' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_array( $response ) && isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		return is_array( $response ) && isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( array $list, string $field ): array {
		return array_column( $list, $field );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error_Stub;
	}
}

if ( ! class_exists( 'WP_Error_Stub' ) ) {
	class WP_Error_Stub {
		public string $code;
		public string $message;
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class_alias( 'WP_Error_Stub', 'WP_Error' );
}

if ( ! class_exists( 'ReportedIP_Hive' ) ) {
	class ReportedIP_Hive {
		public static function get_client_ip(): string {
			$candidate = $_SERVER['REMOTE_ADDR'] ?? '';
			if ( '' === $candidate ) {
				return '';
			}
			return false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-defaults.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-cache.php';
require_once dirname( __DIR__ ) . '/includes/class-block-escalation.php';
require_once dirname( __DIR__ ) . '/includes/class-database.php';
require_once dirname( __DIR__ ) . '/includes/class-ip-manager.php';
require_once dirname( __DIR__ ) . '/includes/class-api-client.php';
require_once dirname( __DIR__ ) . '/includes/class-security-monitor.php';
require_once dirname( __DIR__ ) . '/includes/class-cron-handler.php';

function rip_test_reset(): void {
	global $rip_test_options, $rip_test_filters, $rip_test_transients, $rip_test_cache;
	$rip_test_options    = array();
	$rip_test_filters    = array();
	$rip_test_transients = array();
	$rip_test_cache      = array();
}
