<?php
/**
 * Unit-suite bootstrap. Brain Monkey, NO WordPress.
 *
 * The unit suite deliberately never loads WordPress: the risk in this plugin is
 * concentrated in pure functions — the response trimmers (a security control),
 * the HMAC verify and time trap, cache tiering, payload mapping and the settings
 * sanitisers — and running those through a real WP bootstrap would make the fast
 * suite slow for no added coverage. Anything that genuinely needs WordPress
 * belongs in tests/integration.
 *
 * Not loading WordPress means this file has to supply the two things the plugin
 * files expect from it:
 *
 *   1. ABSPATH. Every class begins `defined( 'ABSPATH' ) || exit;` — the standard
 *      guard against a file being requested directly over HTTP. Without it here,
 *      requiring any class EXITS THE TEST RUN, and PHPUnit reports a process
 *      that died rather than a failure anyone can read.
 *
 *   2. An autoloader. The plugin registers its own inside the bootstrap file,
 *      which we do not load, so the same mapping is reproduced here.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

// See (1) above. Value is irrelevant; only its definedness is checked.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Constants the plugin bootstrap would normally define.
if ( ! defined( 'SPOTSHIP_WI_VERSION' ) ) {
	define( 'SPOTSHIP_WI_VERSION', '0.0.0-test' );
}
if ( ! defined( 'SPOTSHIP_WI_PATH' ) ) {
	define( 'SPOTSHIP_WI_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'SPOTSHIP_WI_FILE' ) ) {
	define( 'SPOTSHIP_WI_FILE', dirname( __DIR__ ) . '/spotship-war-insurance.php' );
}
if ( ! defined( 'SPOTSHIP_WI_URL' ) ) {
	define( 'SPOTSHIP_WI_URL', 'https://example.test/wp-content/plugins/spotship-war-insurance/' );
}

// WordPress time constants used by the plugin.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

/**
 * See (2) above. Mirrors the mapping in spotship-war-insurance.php:
 * `Spotship_WI_Rest_Proxy` -> includes/class-rest-proxy.php.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Spotship_WI_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'Spotship_WI_' ) ) ) );
		$path = dirname( __DIR__ ) . '/includes/class-' . $slug . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);
