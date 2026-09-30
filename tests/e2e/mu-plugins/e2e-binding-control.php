<?php
/**
 * Set the origin binding from a test. TEST ENVIRONMENT ONLY.
 *
 * The origin-mismatch state cannot be reached through the product's own UI, and
 * that is deliberate: Settings::sanitize() preserves an existing binding and
 * otherwise binds to the current site, so there is no supported way for an
 * administrator to record a binding for a site they are not on. The state only
 * arises from a real clone — copying a site to staging, carrying the key with
 * it — which cannot be staged inside a single WordPress installation.
 *
 * So the state is set directly here, and the ONE-CLICK REBIND that recovers
 * from it is then exercised through the real admin notice, the real nonce and
 * the real admin-post handler, exactly as an administrator would. The thing
 * under test stays the product's code; only the starting state is borrowed.
 *
 * A state-changing GET with no nonce would be a CSRF hole in shipped code. It
 * is acceptable here and ONLY here: this file lives outside the plugin
 * directory, is mounted solely by .wp-env.json's mappings, is excluded from the
 * release zip by bin/build-dist.sh's allow-list, and is capability-gated so it
 * ignores anonymous requests even inside the test environment.
 *
 * @package Spotship_WI
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_init',
	static function (): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- See the file docblock: test-only, capability-gated, never shipped.
		if ( ! isset( $_GET['spotship_e2e_bind'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$requested = sanitize_text_field( wp_unslash( (string) $_GET['spotship_e2e_bind'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$settings = get_option( 'spotship_wi_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		// An empty value means "bind to wherever we actually are", which is how
		// a test restores the environment it borrowed.
		$settings['bound_site_url'] = '' === $requested
			? Spotship_WI_Settings::current_site_url()
			: $requested;

		update_option( 'spotship_wi_settings', $settings );
	},
	1
);
