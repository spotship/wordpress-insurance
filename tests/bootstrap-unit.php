<?php
/**
 * Unit-suite bootstrap. Brain Monkey, NO WordPress.
 *
 * The unit suite deliberately never loads WordPress: the risk in this plugin is
 * concentrated in pure functions — the response trimmers (a security control),
 * the HMAC verify and time trap, cache tiering, payload mapping and the settings
 * sanitisers — and testing those against a real WP bootstrap would make the fast
 * suite slow for no added coverage. Anything that genuinely needs WordPress
 * belongs in tests/integration.
 *
 * @package Spotship_WI
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}
