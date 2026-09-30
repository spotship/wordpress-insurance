<?php
/**
 * Integration-suite bootstrap. Real WordPress, loaded by wp-env.
 *
 * @package Spotship_WI
 */

$spotship_wi_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

require_once $spotship_wi_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/spotship-war-insurance.php';
	}
);

require $spotship_wi_tests_dir . '/includes/bootstrap.php';
