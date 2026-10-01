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

/*
 * THE PER-IP SUBMIT LIMIT IS FIVE AN HOUR, AND THE SUITE NEEDS MORE THAN THAT.
 *
 * Every spec runs twice — once per Playwright project, desktop and narrow —
 * from one runner with one IP, and several of them submit. The second project
 * therefore ran into the limiter and every submit answered 429.
 *
 * That went unnoticed because the assertions it broke were too weak to see it:
 * the error-summary test was wrapped in `if ( await summary.isVisible() )`, and
 * the re-bind test asserted only that the message did not say "unavailable" —
 * which "Too many requests. Please wait 3488 seconds and try again." satisfies.
 * Strengthening both is what surfaced this.
 *
 * Resetting the buckets on every REST request effectively takes the limiter out
 * of the browser suite. That is the right trade here: the limiter's own
 * behaviour — counting, window expiry, the honest retry_after, the Cloudflare
 * header rules — is covered by tests/integration/RateLimiterTest.php, and the
 * 429 the visitor sees is covered in the jsdom suite. Neither needs a browser,
 * and neither should be hostage to how many times an unrelated spec submits.
 *
 * Test environment only: this file is mounted by .wp-env.json and excluded from
 * the release zip by bin/build-dist.sh's allow-list.
 */
add_action(
	'rest_api_init',
	static function (): void {
		/*
		 * UNLESS A TEST ASKS TO KEEP THEM.
		 *
		 * §9 requires the 429 path to be driven, and it cannot be while this
		 * resets the buckets before every request — the limiter would never
		 * trip. A test that wants to observe throttling sets
		 * `spotship_e2e_keep_limits` first and clears it afterwards, so the
		 * default stays "the limiter is out of the way" for every other spec.
		 */
		if ( get_option( 'spotship_e2e_keep_limits' ) ) {
			return;
		}

		Spotship_WI_Rate_Limiter::reset( 'submit' );
		Spotship_WI_Rate_Limiter::reset( 'search' );
	},
	1
);

add_action(
	'admin_init',
	static function (): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- See the file docblock: test-only, capability-gated, never shipped.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( 'spotship_wi_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$dirty    = false;

		/*
		 * RESTORE FROM A STASH, NEVER BY PASSING THE VALUES BACK IN.
		 *
		 * The obvious way to undo these changes is for the test to re-send the
		 * original api_base_url and api_key. That would put a live credential
		 * in a URL — into the webserver's access log, into Playwright's trace
		 * and screenshots, and into the CI job log. The first write stashes the
		 * original settings instead, and `spotship_e2e_restore=1` puts them
		 * back without the key ever appearing in a query string.
		 */

		/*
		 * Keep or drop the per-IP limiter reset above. Separate from the settings
		 * stash because it is not a setting — it changes how the TEST ENVIRONMENT
		 * behaves, not how the plugin is configured.
		 */
		if ( isset( $_GET['spotship_e2e_keep_limits'] ) ) {
			if ( '' === sanitize_text_field( wp_unslash( (string) $_GET['spotship_e2e_keep_limits'] ) ) ) {
				delete_option( 'spotship_e2e_keep_limits' );
			} else {
				update_option( 'spotship_e2e_keep_limits', '1', false );
			}

			return;
		}

		if ( isset( $_GET['spotship_e2e_restore'] ) ) {
			$stashed = get_option( 'spotship_e2e_stash', null );

			if ( is_array( $stashed ) ) {
				update_option( 'spotship_wi_settings', $stashed );
				delete_option( 'spotship_e2e_stash' );
				delete_transient( Spotship_WI_Rates_Cache::FRESH );
				delete_transient( Spotship_WI_Rates_Cache::BACKUP );
			}

			return;
		}

		if ( isset( $_GET['spotship_e2e_bind'] ) ) {
			$requested = sanitize_text_field( wp_unslash( (string) $_GET['spotship_e2e_bind'] ) );

			// An empty value means "bind to wherever we actually are", which is
			// how a test restores the environment it borrowed.
			$settings['bound_site_url'] = '' === $requested
				? Spotship_WI_Settings::current_site_url()
				: $requested;

			$dirty = true;
		}

		/*
		 * Swapping the credential is how the real-backend job drives a revoked key
		 * against the REAL backend, so the 401 under test is genuinely the
		 * backend's rather than a fixture imitating one.
		 */
		if ( isset( $_GET['spotship_e2e_key'] ) ) {
			$settings['api_key'] = sanitize_text_field( wp_unslash( (string) $_GET['spotship_e2e_key'] ) );
			$dirty               = true;
		}

		/*
		 * Swapping the base url is how "the backend is unreachable" is driven
		 * against a real deployment. Staging cannot be killed from a test, and
		 * pointing at a host that does not resolve produces the same WP_Error
		 * from the same wp_safe_remote_post() call that a real outage does.
		 *
		 * Note this runs at priority 1, BEFORE register_setting attaches
		 * sanitize() on admin_init at 10 — which is the only reason a value can
		 * be written here without being re-validated. See the rebind short
		 * circuit in class-settings.php for what that ordering cost once.
		 */
		if ( isset( $_GET['spotship_e2e_base'] ) ) {
			$settings['api_base_url'] = esc_url_raw(
				wp_unslash( (string) $_GET['spotship_e2e_base'] ),
				array( 'https' )
			);
			$dirty                    = true;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! $dirty ) {
			return;
		}

		// Stash once, on the first change, so a sequence of changes still
		// restores to the state the seed produced rather than to an
		// intermediate one.
		if ( null === get_option( 'spotship_e2e_stash', null ) ) {
			add_option( 'spotship_e2e_stash', get_option( 'spotship_wi_settings', array() ) );
		}

		update_option( 'spotship_wi_settings', $settings );

		/*
		 * The rates cache is keyed on neither the binding nor the credential, so
		 * both tiers would survive a swap and answer from before the change. The
		 * BACKUP tier matters most: it has a seven-day life, so without this a
		 * revoked-key test would keep rendering a table it is no longer entitled
		 * to and read as a pass.
		 */
		delete_transient( Spotship_WI_Rates_Cache::FRESH );
		delete_transient( Spotship_WI_Rates_Cache::BACKUP );
	},
	1
);
