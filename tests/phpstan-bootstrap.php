<?php
/**
 * Constants PHPStan cannot see, because they are defined at runtime by the
 * plugin bootstrap or by WordPress itself rather than declared in source
 * PHPStan analyses.
 *
 * @package Spotship_WI
 */

define( 'SPOTSHIP_WI_VERSION', '0.0.0' );
define( 'SPOTSHIP_WI_FILE', __DIR__ . '/../spotship-war-insurance.php' );
define( 'SPOTSHIP_WI_PATH', __DIR__ . '/../' );
define( 'SPOTSHIP_WI_URL', 'https://example.test/wp-content/plugins/spotship-war-insurance/' );
