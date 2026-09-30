<?php
/**
 * The one-click re-bind actually re-binds.
 *
 * This shipped broken. register_setting()'s sanitize_callback filters EVERY
 * update_option() on the option, not only Settings API saves, and sanitize()
 * deliberately preserves an existing binding so that a cloned site cannot adopt
 * itself by saving settings. bind_to_current() therefore wrote the new binding
 * and had it reverted in the same call, while the admin screen reported
 * "Re-bound to this site. Submissions are enabled again."
 *
 * A false success on the documented recovery path for a cloned site — the
 * failure class this plugin treats as non-negotiable.
 *
 * These tests register the sanitize callback the way the plugin does, because
 * WITHOUT IT EVERY ONE OF THEM PASSES AGAINST THE BROKEN CODE. That is exactly
 * why the existing suite missed this: nothing exercised a write with the filter
 * attached.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

/**
 * @covers Spotship_WI_Settings::bind_to_current
 */
final class RebindTest extends WP_UnitTestCase {

	private const CLONE_URL = 'cloned-to-staging.example.test';

	public function set_up(): void {
		parent::set_up();

		/*
		 * SEED FIRST, REGISTER SECOND, and the order is the point.
		 *
		 * With the sanitize callback already attached there is no way to write a
		 * foreign binding at all: on a fresh option there is no existing binding
		 * to preserve, so sanitize() takes its second branch and binds to the
		 * CURRENT site. The fixture would then describe a healthy install and
		 * every assertion below would pass over a site that was never broken.
		 *
		 * This is also how the state arises in reality: the clone carries a
		 * database written elsewhere, before any filter on this installation
		 * existed.
		 */
		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'   => 'https://api.example.test',
				'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url' => self::CLONE_URL,
			)
		);

		// Exactly what spotship-war-insurance.php does on admin_init.
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

	public function tear_down(): void {
		unregister_setting( 'spotship_wi', Spotship_WI_Settings::OPTION );
		parent::tear_down();
	}

	public function test_the_fixture_really_is_a_mismatch(): void {
		// Otherwise the tests below could pass over a site that was never broken.
		$this->assertSame( self::CLONE_URL, Spotship_WI_Settings::bound_site_url() );
		$this->assertTrue( Spotship_WI_Settings::binding_mismatch() );
	}

	public function test_bind_to_current_persists_through_the_sanitizer(): void {
		Spotship_WI_Settings::bind_to_current();

		$this->assertSame(
			Spotship_WI_Settings::current_site_url(),
			Spotship_WI_Settings::bound_site_url(),
			'the re-bind was reverted by the option sanitizer'
		);
		$this->assertFalse(
			Spotship_WI_Settings::binding_mismatch(),
			'the mismatch notice would still be shown after a successful re-bind'
		);
	}

	public function test_an_ordinary_save_still_cannot_repoint_the_binding(): void {
		/*
		 * The security property the sanitizer exists for, asserted alongside the
		 * fix so that "make the re-bind work" cannot be satisfied by simply
		 * dropping it. A clone that saves settings must NOT adopt itself.
		 */
		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'   => 'https://api.example.test',
				'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url' => Spotship_WI_Settings::current_site_url(),
			)
		);

		$this->assertSame(
			self::CLONE_URL,
			Spotship_WI_Settings::bound_site_url(),
			'saving settings must not silently re-point the binding'
		);
	}

	public function test_rebinding_does_not_disturb_the_rest_of_the_configuration(): void {
		/*
		 * A re-bind must not re-validate everything it did not touch.
		 *
		 * sanitize() requires https on the base url — correctly, it carries a
		 * bearer credential. But a clone's database can hold a value that was
		 * written before that rule, and routing a re-bind through the full
		 * sanitizer BLANKS it. The install ends up bound, unconfigured, and
		 * answering 502 to every visitor, with the admin screen reporting that
		 * the re-bind succeeded. A recovery action that destroys the
		 * configuration it is recovering is worse than the original fault.
		 *
		 * Written straight to the option, before the filter exists, because
		 * that is the only way the state arises: a database from elsewhere.
		 */
		unregister_setting( 'spotship_wi', Spotship_WI_Settings::OPTION );

		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'       => 'http://legacy.example.test',
				'api_key'            => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url'     => self::CLONE_URL,
				'privacy_notice_url' => 'https://example.test/privacy',
				'support_email'      => 'insurance@example.test',
			)
		);

		register_setting(
			'spotship_wi',
			Spotship_WI_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Spotship_WI_Settings', 'sanitize' ),
				'default'           => array(),
			)
		);

		Spotship_WI_Settings::bind_to_current();

		$this->assertSame(
			Spotship_WI_Settings::current_site_url(),
			Spotship_WI_Settings::bound_site_url(),
			'the re-bind must still take effect'
		);
		$this->assertSame(
			'http://legacy.example.test',
			(string) Spotship_WI_Settings::get( 'api_base_url', '' ),
			'the re-bind silently blanked the base url and left the install unconfigured'
		);
		$this->assertTrue(
			Spotship_WI_Settings::is_configured(),
			'an install that was configured before a re-bind must be configured after it'
		);
	}

	public function test_the_flag_does_not_survive_the_call(): void {
		// A leaked flag would let the NEXT ordinary save re-point the binding,
		// quietly removing the protection the test above asserts.
		Spotship_WI_Settings::bind_to_current();

		update_option(
			Spotship_WI_Settings::OPTION,
			array(
				'api_base_url'   => 'https://api.example.test',
				'api_key'        => '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
				'bound_site_url' => 'somewhere-else.example.test',
			)
		);

		$this->assertSame(
			Spotship_WI_Settings::current_site_url(),
			Spotship_WI_Settings::bound_site_url(),
			'the re-binding flag leaked past bind_to_current()'
		);
	}
}
