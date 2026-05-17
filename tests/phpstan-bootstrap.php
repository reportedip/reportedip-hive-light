<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines plugin and WordPress runtime constants that PHPStan's static scan
 * cannot resolve from `define()` calls. Values are sentinels — only the symbol
 * existence matters for analysis.
 *
 * @package ReportedIP_Hive
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );
defined( 'WP_DEBUG_LOG' ) || define( 'WP_DEBUG_LOG', false );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

defined( 'REPORTEDIP_HIVE_VERSION' ) || define( 'REPORTEDIP_HIVE_VERSION', '0.0.0-dev' );
defined( 'REPORTEDIP_HIVE_DB_VERSION' ) || define( 'REPORTEDIP_HIVE_DB_VERSION', '1.0.0' );
defined( 'REPORTEDIP_HIVE_PLUGIN_DIR' ) || define( 'REPORTEDIP_HIVE_PLUGIN_DIR', __DIR__ . '/' );
defined( 'REPORTEDIP_HIVE_PLUGIN_URL' ) || define( 'REPORTEDIP_HIVE_PLUGIN_URL', 'https://example.test/wp-content/plugins/reportedip-hive/' );
defined( 'REPORTEDIP_HIVE_PLUGIN_FILE' ) || define( 'REPORTEDIP_HIVE_PLUGIN_FILE', __DIR__ . '/reportedip-hive.php' );
defined( 'REPORTEDIP_HIVE_PLUGIN_BASENAME' ) || define( 'REPORTEDIP_HIVE_PLUGIN_BASENAME', 'reportedip-hive/reportedip-hive.php' );
defined( 'REPORTEDIP_HIVE_LANGUAGES_DIR' ) || define( 'REPORTEDIP_HIVE_LANGUAGES_DIR', __DIR__ . '/languages' );

defined( 'COOKIEPATH' ) || define( 'COOKIEPATH', '/' );
defined( 'COOKIE_DOMAIN' ) || define( 'COOKIE_DOMAIN', '' );
defined( 'SITECOOKIEPATH' ) || define( 'SITECOOKIEPATH', '/' );
defined( 'ADMIN_COOKIE_PATH' ) || define( 'ADMIN_COOKIE_PATH', '/wp-admin' );
defined( 'PLUGINS_COOKIE_PATH' ) || define( 'PLUGINS_COOKIE_PATH', '/wp-content/plugins' );
