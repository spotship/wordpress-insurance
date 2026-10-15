<?php
/**
 * Plugin Name:       Spot Ship War Insurance
 * Plugin URI:        https://github.com/spotship/wordpress-insurance
 * Description:       War hull and war cargo insurance quote capture, proxied server-side to Spot Ship. The API credential never reaches the browser.
 * Version:           1.1.0-dev.1
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
 * These two are what the CODE reads: the update checker reads the header;
 * `X-Plugin-Version` on every outbound request reports the constant. If they
 * drift, the fleet reports one version while updating against another, and
 * stale-version alerting lies.
 *
 * They are not the only two. readme.txt's `Stable tag` and the catalogue's
 * `Project-Id-Version` ship in the same zip, and this comment claimed there
 * were two for as long as both of those sat at 0.1.0 under a 1.0.0 plugin.
 * bin/set-version.sh writes all four and bin/assert-version.sh asserts them;
 * edit none of them by hand.
 */
define( 'SPOTSHIP_WI_VERSION', '1.1.0-dev.1' );
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
	/*
	 * ON `init`, NOT `plugins_loaded`, AND THAT MATTERS MORE THAN IT LOOKS.
	 *
	 * wp_register_script() and wp_register_style() call
	 * _wp_scripts_maybe_doing_it_wrong(), which raises _doing_it_wrong() when
	 * neither `init` nor an enqueue hook has fired. On a site with
	 * WP_DEBUG_DISPLAY on, that notice PRINTS — before headers — and WordPress
	 * can then no longer set a cookie. The visible symptom is that nobody can
	 * log in: wp-login.php answers "Cookies are blocked due to unexpected
	 * output" and returns the form.
	 *
	 * That is a customer-visible outage on any install with debug display
	 * enabled, which staging sites routinely have. It surfaced here as the
	 * browser suite failing to reach wp-admin, and was diagnosed as three
	 * different test faults before the notice in the CI log turned out to be
	 * the plugin's own.
	 *
	 * load_plugin_textdomain() moves for the same reason: since WordPress 6.7
	 * it warns when translations are loaded before `init`.
	 */
	load_plugin_textdomain(
		'spotship-war-insurance',
		false,
		dirname( plugin_basename( SPOTSHIP_WI_FILE ) ) . '/languages'
	);

	Spotship_WI_Shortcodes::register();

	/*
	 * Assets are REGISTERED here but enqueued by the renderer, so a page with no
	 * form loads neither the script nor the stylesheet. On a customer site we do
	 * not control, adding ~10KB to every page for a form on one of them is not
	 * ours to spend.
	 */
	wp_register_script(
		'spotship-wi',
		SPOTSHIP_WI_URL . 'assets/js/war-insurance.js',
		array(),
		SPOTSHIP_WI_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	/*
	 * The page-builder handler, registered here and enqueued only by the
	 * Elementor widgets through get_script_depends(). Nothing on a site without
	 * Elementor ever asks for it, so registering it unconditionally costs a row
	 * in WP_Scripts and nothing else.
	 *
	 * THE `spotship-wi` DEPENDENCY IS LOAD-BEARING, NOT TIDINESS. In the editor
	 * Elementor enqueues every registered widget's script dependencies up front
	 * (Preview::enqueue_scripts -> Widgets_Manager::enqueue_widgets_scripts),
	 * before any widget is on the page. On an empty page the renderer has
	 * therefore never run, so nothing has enqueued `spotship-wi` — and this
	 * handler would load with no window.spotshipWI to call. Declaring the
	 * dependency is what drags the real script into the preview, which is why
	 * the very first widget dropped onto a blank page comes up live.
	 */
	wp_register_script(
		Spotship_WI_Elementor::HANDLER_SCRIPT,
		SPOTSHIP_WI_URL . 'assets/js/elementor.js',
		array( 'spotship-wi' ),
		SPOTSHIP_WI_VERSION,
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);

	wp_register_style(
		'spotship-wi',
		SPOTSHIP_WI_URL . 'assets/css/war-insurance.css',
		array(),
		SPOTSHIP_WI_VERSION
	);
}

add_action( 'init', 'spotship_wi_boot' );

/**
 * Wire the update checker against the private repository's Releases feed.
 *
 * The plugin runs on servers we do not control, against an API we version, so
 * version skew is the real operational risk — the design names it as such. This
 * plus the "enable auto-updates" line in the install runbook is what dissolves
 * most of it.
 *
 * The repository is PRIVATE, so an unauthenticated check sees nothing. A token
 * is read from an OPTIONAL customer-set constant; we never ship one. A Spot Ship
 * token inside an artifact that is unpacked verbatim onto third-party servers
 * would be a credential handed to every customer at once.
 */
add_action(
	'init',
	static function (): void {
		$autoload = SPOTSHIP_WI_PATH . 'vendor/autoload.php';

		if ( ! is_readable( $autoload ) ) {
			return;
		}

		require_once $autoload;

		if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/spotship/wordpress-insurance/',
			SPOTSHIP_WI_FILE,
			'spotship-war-insurance'
		);

		if ( defined( 'SPOTSHIP_WI_GITHUB_TOKEN' ) && is_string( SPOTSHIP_WI_GITHUB_TOKEN ) && '' !== SPOTSHIP_WI_GITHUB_TOKEN ) {
			$checker->setAuthentication( SPOTSHIP_WI_GITHUB_TOKEN );
		}

		if ( method_exists( $checker, 'getVcsApi' ) && $checker->getVcsApi() ) {
			$checker->getVcsApi()->enableReleaseAssets();
		}
	}
);

add_action( 'init', array( 'Spotship_WI_Shortcodes', 'register_blocks' ) );

/*
 * Elementor's own hooks, added unconditionally. They cost nothing on a site
 * without Elementor, because nothing fires them there — and crucially this
 * call loads no Elementor class, so it is safe at file scope. The widget
 * subclasses, which DO extend an Elementor class, are reached only through the
 * autoloader from inside the registration callback.
 *
 * Not deferred to spotship_wi_boot(): that runs on `init`, Elementor builds
 * its widget registry during `init` as well, and losing that race would mean
 * the widgets quietly never appear in the panel.
 */
Spotship_WI_Elementor::register();

if ( is_admin() ) {
	Spotship_WI_Admin::register();
}

add_action(
	'rest_api_init',
	array( 'Spotship_WI_Rest_Proxy', 'register' )
);

add_action(
	'admin_init',
	static function (): void {
		register_setting(
			'spotship_wi',
			Spotship_WI_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Spotship_WI_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);
	}
);
