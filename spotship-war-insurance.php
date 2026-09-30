<?php
/**
 * Plugin Name:       Spot Ship War Insurance
 * Plugin URI:        https://github.com/spotship/wordpress-insurance
 * Description:       War hull and war cargo insurance quote capture, proxied server-side to Spot Ship. The API credential never reaches the browser.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Spot Ship
 * Author URI:        https://spot-ship.com
 * Text Domain:       spotship-war-insurance
 * Domain Path:       /languages
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * SPOTSHIP_WI_VERSION must match the `Version:` header above.
 *
 * These are the only two places a version number appears, and CI asserts they
 * agree. The update checker reads the header; `X-Plugin-Version` on every
 * outbound request reports the constant. If they drift, the fleet reports one
 * version while updating against another, and stale-version alerting lies.
 */
define( 'SPOTSHIP_WI_VERSION', '0.1.0' );
define( 'SPOTSHIP_WI_FILE', __FILE__ );
define( 'SPOTSHIP_WI_PATH', plugin_dir_path( __FILE__ ) );
define( 'SPOTSHIP_WI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for this plugin's classes.
 *
 * `Spotship_WI_Rest_Proxy` -> includes/class-rest-proxy.php, following the
 * WordPress file-naming convention rather than PSR-4. Deliberately narrow: it
 * returns immediately for anything not prefixed `Spotship_WI_`, so it never
 * competes with another plugin's autoloader on a shared install.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'Spotship_WI_' ) ) {
			return;
		}

		$slug = strtolower( str_replace( '_', '-', substr( $class_name, strlen( 'Spotship_WI_' ) ) ) );
		$path = SPOTSHIP_WI_PATH . 'includes/class-' . $slug . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Boot the plugin.
 *
 * Everything is registered from here rather than at file scope, so the plugin is
 * inert until WordPress says otherwise and tests can require this file without
 * side effects beyond hook registration.
 *
 * @return void
 */
function spotship_wi_boot(): void {
	load_plugin_textdomain(
		'spotship-war-insurance',
		false,
		dirname( plugin_basename( SPOTSHIP_WI_FILE ) ) . '/languages'
	);
}

add_action( 'plugins_loaded', 'spotship_wi_boot' );
