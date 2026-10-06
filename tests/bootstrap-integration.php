<?php
/**
 * Integration-suite bootstrap. Real WordPress, loaded by wp-env.
 *
 * @package Spotship_WI
 */

$spotship_wi_tests_env = getenv( 'WP_TESTS_DIR' );
$spotship_wi_tests_dir = is_string( $spotship_wi_tests_env ) && '' !== $spotship_wi_tests_env
	? $spotship_wi_tests_env
	: '/wordpress-phpunit';

require_once $spotship_wi_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/spotship-war-insurance.php';
	}
);

require $spotship_wi_tests_dir . '/includes/bootstrap.php';
