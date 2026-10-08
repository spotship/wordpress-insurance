<?php
/**
 * Let the e2e environment reach the stand-in upstream. TEST ENVIRONMENT ONLY.
 *
 * `Spotship_WI_Api_Client` uses wp_safe_remote_get()/wp_safe_remote_post(), and
 * the "safe" in those names is wp_http_validate_url(), which REFUSES private and
 * reserved address ranges. That is exactly right in production — it is the guard
 * that stops a mis-set base URL turning the plugin into an SSRF primitive
 * against the customer's own network — and it is not relaxed there.
 *
 * It also refuses the e2e stand-in, which necessarily lives on the Docker bridge
 * gateway (172.16.0.0/12). Without this filter every search answered
 * "unavailable" and three specs failed as though the combobox were broken.
 *
 * Narrow on purpose: this allows exactly the host this installation is already
 * configured to talk to, and nothing else. It is not a blanket
 * `__return_true`, and it is mounted only by .wp-env.json — it is not in the
 * plugin, not in the release zip, and never runs on a customer site.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'http_request_host_is_external',
	/**
	 * @param bool   $external Whether the host is treated as external.
	 * @param string $host     Host being requested.
	 */
	static function ( $external, $host ) {
		$settings = get_option( 'spotship_wi_settings', array() );
		$base     = is_array( $settings ) && isset( $settings['api_base_url'] ) ? (string) $settings['api_base_url'] : '';

		if ( '' === $base ) {
			return $external;
		}

		$configured = wp_parse_url( $base, PHP_URL_HOST );

		return ( is_string( $configured ) && $configured === $host ) ? true : $external;
	},
	10,
	2
);
