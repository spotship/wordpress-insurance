#!/usr/bin/env node
/**
 * Proves the regression tests actually detect the defects they describe.
 *
 * A test is only worth its runtime if it fails when the bug is reintroduced.
 * Claiming "proven red first" in a commit message is unverifiable a week later,
 * and a test that silently stopped detecting anything looks exactly like a test
 * that passes. So the proof runs in CI, every time.
 *
 * Each mutation below reintroduces one shipped defect by editing the source the
 * way it actually read before the fix. The named tests MUST go red. If a
 * mutation leaves the suite green, the test has stopped earning its place and
 * this exits non-zero.
 *
 * THREE SUITES, SPLIT BY WHAT THEY NEED TO RUN, NOT BY TASTE.
 *
 *   --suite=js    (the default) mutates source the jsdom tests cover and needs
 *                 nothing but Node.
 *   --suite=unit  mutates source the PHPUnit UNIT tests cover and needs only
 *                 PHP and composer, so it rides along in the unit job.
 *   --suite=php   mutates source the PHPUnit INTEGRATION tests cover and needs
 *                 wp-env, so it runs in the integration job instead.
 *
 * Splitting them is not cosmetic: without the php suite the integration tests
 * had no fail-before-fix evidence at all, and without the unit suite neither
 * did the unit tests — the same unverifiable claim this file exists to replace.
 *
 * Usage: node bin/mutation-check.js [--suite=js|unit|php]
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { spawnSync } = require( 'child_process' );

const ROOT = path.join( __dirname, '..' );

const MUTATIONS = [
	{
		suite: 'js',
		what: 'the lookup reads only the body and ignores the HTTP status',
		file: 'assets/js/war-insurance.js',
		from: 'if ( ! answer.ok || ( answer.body && answer.body.unavailable ) ) {',
		to: 'if ( answer.body && answer.body.unavailable ) {',
		mustFail: [
			'a lookup refused by our own proxy blocks submission rather than reading as zero results',
		],
	},
	{
		suite: 'js',
		what: 'a throttled submit falls through to the generic sentence',
		file: 'assets/js/war-insurance.js',
		from: '} else if ( 429 === answer.status ) {',
		to: '} else if ( false ) {',
		mustFail: [
			'a throttled submission tells the visitor how long to wait',
			'a throttled submission falls back to the Retry-After header when the body carries no wait',
		],
	},
	{
		suite: 'js',
		what: 'text fields go back to a constant element id',
		file: 'templates/partials/text-field.php',
		from: "$sswi_id      = $sswi_uid . '-' . $sswi_name;",
		to: "$sswi_id      = 'sswi-' . $sswi_name;",
		mustFail: [ 'every partial derives its element ids from the instance prefix' ],
	},
	{
		suite: 'js',
		what: 'the renderer stops namespacing instances',
		file: 'includes/class-renderer.php',
		from: "'uid'       => $uid,",
		to: "'uid'       => 'sswi',",
		mustFail: [ 'the renderer namespaces ids per render, from the form uuid' ],
	},

	{
		suite: 'js',
		what: 'mounting the same root twice binds a second set of listeners',
		file: 'assets/js/war-insurance.js',
		from: 'if ( ! root || mounted.has( root ) ) {',
		to: 'if ( ! root ) {',
		// Elementor re-runs element_ready on every re-render, and its editor
		// re-renders freely, so a root reaching init() twice is routine rather
		// than exotic. Two submit handlers on one form is one visitor action and
		// two quote requests, which the backend cannot tell apart.
		mustFail: [ 'mounting twice does not bind a second set of listeners' ],
	},
	{
		suite: 'js',
		what: 'mountAll stops looking inside the scope it is given',
		file: 'assets/js/war-insurance.js',
		from: "within.querySelectorAll( '[data-sswi-root]' ).forEach( mount );",
		to: '',
		// Elementor passes the widget wrapper, never the root: our markup is a
		// descendant, inside .elementor-widget-container. Dropping the descendant
		// sweep leaves every builder-injected form dead, which is the defect this
		// whole mechanism exists to fix.
		mustFail: [ 'mountAll reaches a root nested inside the scope Elementor passes' ],
	},

	{
		suite: 'js',
		what: 'the customer-set accent goes back behind the submit button label',
		file: 'assets/css/war-insurance.css',
		from: 'background: #0b5fff;',
		to: 'background: var( --spotship-wi-accent );',
		// The shipped defect. Unreachable by axe, which only ever renders the
		// default accent and sees 5.1:1; the failure needs a customer's colour.
		mustFail: [ 'the customisable accent never lands on a text-contrast surface' ],
	},

	{
		suite: 'js',
		what: 'the decoy threshold goes back into the page source',
		file: 'includes/class-renderer.php',
		from: "'minQueryLength'  => self::MIN_QUERY_LENGTH,",
		to: "'minAgeSeconds'   => Spotship_WI_Request_Guard::MIN_AGE,\n\t\t\t'minQueryLength'  => self::MIN_QUERY_LENGTH,",
		// The shipped state. It was published for the whole life of the file,
		// read by no JavaScript, and told a bot author exactly how long to
		// sleep to walk past the timing trap.
		mustFail: [ 'the anti-bot thresholds are never published to the browser' ],
	},

	{
		suite: 'js',
		what: '.distignore names one playwright config instead of the shape',
		file: '.distignore',
		from: 'playwright*.config.js',
		to: 'playwright.config.js',
		// The shipped state. playwright.real-backend.config.js was unaccounted for
		// and would have failed the next release tag's ship-list check.
		mustFail: [ 'no tracked file reaches the customer zip without a decision' ],
	},

	{
		suite: 'js',
		what: '.distignore names one phpunit cache instead of the shape',
		file: '.distignore',
		from: '.phpunit*.result.cache',
		to: '.phpunit.result.cache',
		// Same defect as the playwright one above, on the half of the ship list
		// that covers UNTRACKED files: there are two cache files, and build-dist
		// copies the working tree, so naming one ships the other.
		mustFail: [ 'no IGNORED local artefact reaches the customer zip either' ],
	},

	{
		suite: 'js',
		what: 'the rate table goes back to reading a top-level columns list',
		file: 'templates/partials/rates-table.php',
		// THE WHOLE ASSIGNMENT, because `$sswi_section['columns']` appears three
		// times in two lines and `from` must identify exactly one edit. Named
		// by the single expression, this mutated only the `isset()` and left
		// the two below it reading the right place — a mutation that was not
		// the one described. The AMBIG guard below refuses that now.
		from:
			"$sswi_columns = isset( $sswi_section['columns'] ) && is_array( $sswi_section['columns'] )\n" +
			"\t? $sswi_section['columns']",
		to:
			"$sswi_columns = isset( $sswi_rates['columns'] ) && is_array( $sswi_rates['columns'] )\n" +
			"\t? $sswi_rates['columns']",
		// The shipped state. The endpoint nests columns under the product, so this
		// rendered a table with no header cells at all against the real backend.
		mustFail: [ 'the rate table reads the payload the rates endpoint actually returns' ],
	},

	{
		suite: 'js',
		what: 'a rate number is published without being formatted as a percentage',
		file: 'templates/partials/rates-table.php',
		from: 'Spotship_WI_Renderer::rate_percent( $sswi_row[ $sswi_key ] )',
		to: '(string) $sswi_row[ $sswi_key ]',
		// Publishes "0.085" where "8.50%" belongs — wrong by two orders of
		// magnitude, on a commercial rate table on a customer's public site.
		mustFail: [ 'the rate table reads the payload the rates endpoint actually returns' ],
	},

	{
		suite: 'js',
		what: 'the cargo risk zones are read as a row list again',
		file: 'assets/js/war-insurance.js',
		from: 'var zones = Array.isArray( cargo.zones ) ? cargo.zones : [];',
		to: 'var zones = Array.isArray( cargo ) ? cargo : [];',
		// The shipped state. `rates.cargo` is an object, so this leaves the
		// REQUIRED risk-zone select empty and the cargo form unsubmittable.
		mustFail: [
			'the cargo risk zone is populated from the served rate table, not a hardcoded list',
		],
	},

	{
		suite: 'js',
		what: 'a cargo risk zone is labelled by `zone` rather than `name`',
		file: 'assets/js/war-insurance.js',
		from: 'if ( ! row || ! row.name ) {',
		to: 'if ( ! row || ! row.zone ) {',
		// Same empty select, by the other half of the same wrong contract.
		mustFail: [
			'the cargo risk zone is populated from the served rate table, not a hardcoded list',
		],
	},

	{
		suite: 'js',
		what: 'a sub-threshold term goes back to announcing nothing at all',
		file: 'assets/js/war-insurance.js',
		from: 'text( live, minCharsMessage( minimum ) );',
		to: "text( live, '' );",
		// The shipped state after the hint paragraph was deleted: the live
		// region is written only from render(), which a term shorter than the
		// minimum never reaches.
		mustFail: [ 'a term below the minimum says so rather than going silent' ],
	},

	{
		suite: 'js',
		what: 'the destinations combobox goes silent below the minimum',
		file: 'assets/js/war-insurance.js',
		from: 'announce( minCharsMessage( minimum ) );',
		to: "announce( '' );",
		mustFail: [ 'the destinations combobox announces the minimum too' ],
	},

	{
		suite: 'js',
		what: 'the minimum sentence is substituted before the script ever sees it',
		file: 'includes/class-renderer.php',
		from: "'minChars'     => self::min_chars_template(),",
		to: "'minChars'     => sprintf( self::min_chars_template(), self::MIN_QUERY_LENGTH ),",
		// The shipped state: the browser received a finished sentence, so
		// minCharsMessage()'s `.replace( '%d', … )` never replaced anything.
		// Both behaviour tests stayed green, because the harness supplies a
		// template the server was not sending.
		mustFail: [ 'the minimum-characters sentence is published with its %d intact' ],
	},

	{
		suite: 'js',
		what: 'the combobox stops stating the minimum on focus',
		file: 'templates/partials/combobox.php',
		from: "esc_attr( $sswi_hint_id . ' ' . $sswi_err )",
		to: 'esc_attr( $sswi_err )',
		// The shipped state: the threshold left the accessibility tree, so a
		// user who focused the field and typed nothing was told nothing. The
		// live region only speaks on `input`.
		mustFail: [ 'every combobox states the minimum through aria-describedby' ],
	},

	{
		suite: 'js',
		what: 'the destinations combobox stops stating the minimum on focus',
		file: 'templates/cargo.php',
		from: "esc_attr( $sswi_dest_hint . ' ' . $sswi_dest_err )",
		to: 'esc_attr( $sswi_dest_err )',
		mustFail: [ 'every combobox states the minimum through aria-describedby' ],
	},

	{
		suite: 'js',
		what: 'the Elementor widgets stop declaring a stylesheet',
		file: 'includes/class-elementor-widget.php',
		from: "return array( 'spotship-wi' );",
		to: 'return array();',
		// The shipped state. The renderer's own enqueue is discarded inside
		// Elementor's render_widget AJAX request, so only a declared handle
		// reaches the preview. Without it the first widget dropped onto a page
		// came up live and unstyled — screen-reader hints as body copy, and the
		// anti-bot honeypot as a visible input.
		mustFail: [ 'the Elementor widgets depend on a style handle that is actually registered' ],
	},

	{
		suite: 'js',
		what: 'the changelog lookup goes back to grepping the whole readme',
		file: 'bin/set-version.sh',
		from: '\t\tinlog && $0 == "= " ver " =" { found = 1 }',
		to: '\t\t$0 == "= " ver " =" { found = 1 }',
		// The shipped state: `== Upgrade Notice ==` files its entries under
		// release versions, and one of those answered for the changelog. No
		// heading was inserted, the read-back was satisfied by the Upgrade
		// Notice, and the release shipped with no changelog entry.
		// It also drifts the two copies apart, which is the other thing that
		// guards this boundary now that there are two of them.
		mustFail: [
			'a heading elsewhere in the readme does not stand in for the changelog entry',
			'both scripts bound the changelog lookup with the same awk',
		],
	},

	{
		suite: 'js',
		what: 'the combobox hint id goes back into text-field.php\'s hint TEXT variable',
		file: 'templates/partials/combobox.php',
		from: "$sswi_hint_id = $sswi_id . '-hint';",
		to: "$sswi_hint = $sswi_id . '-hint';",
		// The shipped state. hull.php and cargo.php require both partials into
		// one scope, and text-field.php reads `$sswi_hint` as the hint TEXT it
		// is handed. A DOM id left in that name renders to the visitor as a
		// sentence, where an unset input would at least raise a notice.
		mustFail: [ 'no partial writes a variable another partial takes as an input' ],
	},

	{
		suite: 'js',
		what: 'a prerelease writes a changelog section again',
		file: 'bin/set-version.sh',
		from: 'if [ "$PRERELEASE" = 0 ] && ! changelog_has_section; then',
		to: 'if ! changelog_has_section; then',
		// The design this replaced. `dev` runs a real release per push, so every
		// internal build wrote its own `= X =` section and each run then had to
		// prune the previous ones — a predicate over human-written headings,
		// which shipped wrong twice.
		mustFail: [
			'a prerelease writes no changelog section at all',
			'the whole prerelease chain leaves one section, written by the release',
		],
	},

	{
		suite: 'js',
		what: 'the `== Changelog ==` heading itself stops being asserted',
		file: 'bin/assert-version.sh',
		from: "if ! grep -q '^== Changelog ==$' \"$README\"; then",
		to: 'if false; then',
		// Every changelog check in this script is bounded to that heading, so a
		// readme without it passes all of them vacuously. set-version.sh used to
		// catch it by reading its own insert back; a prerelease inserts nothing,
		// and `dev` releases a prerelease on every push.
		mustFail: [ 'a readme that has lost its `== Changelog ==` heading is refused' ],
	},

	{
		suite: 'js',
		what: 'the plugin header is whitespace-stripped down the middle again',
		file: 'bin/assert-version.sh',
		from: "header=$( sed -n 's/^ \\* Version:[[:space:]]*\\(.*\\)$/\\1/p' \"$FILE\" | head -1 | sed 's/[[:space:]]*$//' )",
		to: "header=$( sed -n 's/^ \\* Version:[[:space:]]*\\(.*\\)$/\\1/p' \"$FILE\" | head -1 | tr -d '[:space:]' )",
		// Deleting interior whitespace turns `1.0.1 - WIP` into `1.0.1-WIP`, which
		// is valid semver — so the guard below passes it, the `-` test reads it as
		// a prerelease, and the changelog assertion is skipped silently.
		mustFail: [
			'a plugin header that is prose, not a version, is refused',
			'a plugin version that is prose everywhere is still refused',
		],
	},

	{
		suite: 'js',
		what: 'the plugin header stops having to be a version',
		file: 'bin/assert-version.sh',
		from: "if ! printf '%s' \"$header\" | grep -Eq '^[0-9]+\\.[0-9]+\\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then",
		to: 'if false; then',
		// $header decides whether a changelog section is required, by testing it
		// for a `-`, so a value that is not a version must never reach it. Only
		// the consistent spelling proves this one: where the header and the
		// constant disagree, the MISMATCH check above refuses it first.
		mustFail: [ 'a plugin version that is prose everywhere is still refused' ],
	},

	{
		suite: 'js',
		what: 'assert-version.sh greps the whole readme for the changelog entry again',
		file: 'bin/assert-version.sh',
		from: '\t\tif ! changelog_has_section; then',
		to: '\t\tif ! grep -q "^= ${header} =$" "$README"; then',
		// The shipped state, and the same defect as the set-version.sh lookup
		// one file over. `== Upgrade Notice ==` files its entries under release
		// versions, so one of those answered for the changelog and a tree with
		// no entry for the shipped version passed. set-version.sh's bounded
		// read-back hides it there; ci.yaml's "Shipped versions agree" job and
		// bin/build-dist.sh run this script alone.
		mustFail: [ 'an Upgrade Notice heading does not satisfy the changelog check' ],
	},

	{
		suite: 'js',
		what: 'the stray-prerelease assertion stops recognising one',
		file: 'bin/assert-version.sh',
		from: '\tinlog && /^= [0-9]+\\.[0-9]+\\.[0-9]+-[0-9A-Za-z.-]+ =$/ {',
		to: '\tinlog && /^= nothing matches this =$/ {',
		// Nothing writes a prerelease section any more, but something still has
		// to notice one that arrives by another route — a hand edit, a revert, a
		// long-lived branch. This is where that invariant lives now, and a
		// refusal is the whole difference from the prune that preceded it.
		mustFail: [ 'a prerelease section is refused, not deleted, wherever it came from' ],
	},

	{
		suite: 'js',
		what: 'the stray-prerelease assertion is let loose outside == Changelog ==',
		file: 'bin/assert-version.sh',
		// NEWLINE-PREFIXED, BECAUSE THE BARE LINE IS NOT UNIQUE. The same awk
		// bound appears in changelog_has_section() one indent deeper, so
		// `\t/^== /` is a substring of `\t\t/^== /`. The leading \n pins the
		// indent to this one.
		from: '\n\t/^== / { inlog = ( $0 == "== Changelog ==" ); next }',
		to: '\n\t/^== / { inlog = 1; next }',
		// `== Upgrade Notice ==` files its entries under release versions, and a
		// release candidate parked there is not an internal build of ours.
		// Unbounded, this aborts every release of a readme that has one.
		mustFail: [ 'a `= X =` heading outside the changelog is out of reach' ],
	},


	{
		suite: 'js',
		what: 'a test helper takes a name PHPUnit already owns',
		file: 'tests/integration/RateLimiterTest.php',
		from: 'private function window_count(): int {',
		to: 'private function count(): int {',
		// The shipped state, briefly. TestCase implements Countable, so this is
		// a fatal at class resolution and every integration job dies on it.
		mustFail: [ 'no test class hides a method PHPUnit already declares' ],
	},

	{
		suite: 'js',
		what: 'the notes textarea loses the hook its error message needs',
		file: 'templates/cargo.php',
		from: '<div class="sswi-field" data-sswi-field="notes">',
		to: '<div class="sswi-field">',
		// The shipped state, in both templates. fieldError() returns false and the
		// visitor is told the service is unavailable instead.
		mustFail: [ 'every named input can be reached by a field error' ],
	},

	// --- unit: driven by the PHPUnit unit suite, needs only PHP ---

	{
		suite: 'unit',
		what: 'the widgets manager is called without checking it can be',
		file: 'includes/class-elementor.php',
		from: "if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {",
		to: 'if ( false ) {',
		// Elementor has renamed this hook before and passed a different object
		// to it. Calling a method that is not there is an Error — a white
		// screen on every page of a site that merely has this plugin
		// installed. The integration suite cannot see this: it runs without
		// Elementor, so the class_exists() guard above returns first and
		// nothing below it is ever reached, whichever manager is passed.
		mustFail: [ 'test_a_manager_of_the_wrong_shape_is_refused_rather_than_fatal' ],
	},

	{
		suite: 'unit',
		what: 'a widget is registered under the other product\'s stored name',
		file: 'includes/class-elementor-cargo.php',
		from: "return 'spotship-war-cargo';",
		to: "return 'spotship-war-hull';",
		// Elementor persists get_name() in `_elementor_data` on every page
		// using the widget, so this string is a compatibility surface: a page
		// whose stored type no longer resolves draws a missing-widget
		// placeholder and still returns 200.
		mustFail: [ 'test_both_widgets_are_registered_under_the_names_pages_store' ],
	},

	// --- php: driven by the PHPUnit integration suite, needs wp-env ---

	{
		suite: 'php',
		what: 'the renderer stops namespacing instances (rendered output)',
		file: 'includes/class-renderer.php',
		from: "'uid'       => $uid,",
		to: "'uid'       => 'sswi',",
		// The js mutation above proves the TEMPLATES read the prefix. This one
		// proves the rendered markup of two real forms on one page actually
		// comes out unique — the WCAG defect itself, not its mechanism.
		mustFail: [
			'test_hull_and_cargo_on_one_page_share_no_ids',
			'test_the_same_form_twice_shares_no_ids',
			'test_the_honeypot_is_namespaced_too',
		],
	},
	{
		suite: 'php',
		what: 'the one-click re-bind goes back through the ordinary sanitizer',
		file: 'includes/class-settings.php',
		from: 'if ( self::$rebinding ) {',
		to: 'if ( false ) {',
		// The shipped defect: bind_to_current() reported success and did not
		// re-bind, on the documented cloned-site recovery path.
		mustFail: [
			'test_bind_to_current_persists_through_the_sanitizer',
			'test_rebinding_does_not_disturb_the_rest_of_the_configuration',
		],
	},
	{
		suite: 'php',
		what: 'the backend grows a response field nobody mapped',
		file: 'tests/fixtures/backend-201-keys.json',
		from: '"hull": [',
		to: '"hull": [\n    "aFieldNobodyMapped",',
		// Mutating the DERIVED FIXTURE rather than the source, because what is
		// under test here is the drift alarm itself: RestProxyTest builds its
		// genuine 201 from this file, so a backend field it has no value for
		// must stop the suite rather than silently shrink the shape the decoy
		// is measured against.
		mustFail: [
			'test_the_decoy_and_a_genuine_acceptance_share_one_body_shape',
		],
	},
	{
		suite: 'php',
		what: 'an upstream 429 is filed as "unavailable" again',
		file: 'includes/class-diagnostics.php',
		from: 'if ( Spotship_WI_Api_Client::THROTTLED === $code ) {',
		to: 'if ( false ) {',
		// The shipped state: STATE_RATE_LIMITED was defined, listed, and written
		// by nothing, so the blob sent someone hunting an outage.
		mustFail: [ 'test_an_upstream_429_is_recorded_as_rate_limited' ],
	},
	{
		suite: 'php',
		what: 'a local throttle stops being recorded',
		file: 'includes/class-rest-proxy.php',
		from: 'Spotship_WI_Diagnostics::record_state( Spotship_WI_Diagnostics::STATE_RATE_LIMITED );',
		to: '/* not recorded */',
		// The shipped state: an install turning visitors away at its own limit
		// looked healthy in the one place anyone would check.
		mustFail: [ 'test_a_local_throttle_is_recorded_with_no_endpoint' ],
	},
	{
		suite: 'php',
		what: 'the ledger dedup the docblock promises goes away again',
		file: 'includes/class-diagnostics.php',
		from: '$newest = $ledger[0] ?? null;',
		to: '$newest = null;',
		// Twenty identical rows evict the 401 and the binding failure around
		// them, leaving a blob that says only "rate limited, twenty times".
		mustFail: [ 'test_a_repeat_collapses_rather_than_evicting_history' ],
	},
	{
		suite: 'php',
		what: 'the rate table reads columns from the top level again',
		file: 'templates/partials/rates-table.php',
		// THE WHOLE ASSIGNMENT, because `$sswi_section['columns']` appears three
		// times in two lines and `from` must identify exactly one edit. Named
		// by the single expression, this mutated only the `isset()` and left
		// the two below it reading the right place — a mutation that was not
		// the one described. The AMBIG guard below refuses that now.
		from:
			"$sswi_columns = isset( $sswi_section['columns'] ) && is_array( $sswi_section['columns'] )\n" +
			"\t? $sswi_section['columns']",
		to:
			"$sswi_columns = isset( $sswi_rates['columns'] ) && is_array( $sswi_rates['columns'] )\n" +
			"\t? $sswi_rates['columns']",
		// The shipped state: the endpoint nests columns under the product, so
		// this rendered a header row with only the risk-zone cell in it.
		mustFail: [ 'test_the_header_row_is_the_products_own_columns' ],
	},
	{
		suite: 'php',
		what: 'the disclaimers are read from the top level again',
		file: 'templates/partials/rates-table.php',
		// THE WHOLE ASSIGNMENT, because the section expression appears three
		// times across its two lines and `from` must identify exactly one edit.
		// Named by the single expression, this mutated only the `isset()` and
		// left the two reads below it pointing at the right place — a mutation
		// that was not the one described. The AMBIG guard below refuses that.
		//
		// NOTE THE TWO TABS on the continuation. Unlike the columns assignment
		// above, this one is nested inside an indented <?php block, so a
		// one-tab copy of that entry matches nothing and fails as STALE.
		from:
			"$sswi_disclaimers = isset( $sswi_section['disclaimers'] ) && is_array( $sswi_section['disclaimers'] )\n" +
			"\t\t? $sswi_section['disclaimers']",
		to:
			"$sswi_disclaimers = isset( $sswi_rates['disclaimers'] ) && is_array( $sswi_rates['disclaimers'] )\n" +
			"\t\t? $sswi_rates['disclaimers']",
		// The shipped state, and the one with a compliance consequence: the
		// block is simply never reached, on every install.
		mustFail: [ 'test_the_disclaimers_are_rendered' ],
	},
	{
		suite: 'php',
		what: 'a rate is published as the raw fraction the endpoint sent',
		file: 'includes/class-renderer.php',
		from: '$percent = round( (float) $rate * 100, 3 );',
		to: '$percent = round( (float) $rate, 3 );',
		// 0.085 instead of 8.50% — a war-risk rate wrong by two orders of
		// magnitude on a customer's public website.
		mustFail: [ 'test_a_rate_is_published_as_a_percentage' ],
	},
	{
		suite: 'php',
		what: 'a real null rate renders as an empty cell instead of an em dash',
		file: 'includes/class-renderer.php',
		from: "if ( null === $rate || '' === $rate || ! is_numeric( $rate ) ) {\n\t\t\treturn '—';",
		to: "if ( null === $rate || '' === $rate || ! is_numeric( $rate ) ) {\n\t\t\treturn '';",
		// Cargo's Hormuz Passage has no net rate. An empty cell reads as a
		// rendering fault; an em dash reads as "there is no figure".
		mustFail: [ 'test_an_absent_rate_reads_as_an_em_dash' ],
	},
	{
		suite: 'php',
		what: 'the fetch lock is released without checking who holds it',
		file: 'includes/class-rates-cache.php',
		from: "\"DELETE FROM {$wpdb->options}\n\t\t\t\t WHERE option_name = %s\n\t\t\t\t   AND option_value = %s\",\n\t\t\t\tself::LOCK,\n\t\t\t\tself::$lock_token",
		to: "\"DELETE FROM {$wpdb->options}\n\t\t\t\t WHERE option_name = %s\",\n\t\t\t\tself::LOCK",
		// A slow request deleting the lock of the request that took over from it
		// lets a third fetch start while the second is still running.
		mustFail: [ 'test_a_slow_request_does_not_release_the_lock_it_lost' ],
	},
	{
		suite: 'php',
		what: 'Cloudflare IPv6 edges stop being recognised',
		file: 'includes/class-rate-limiter.php',
		from: 'return self::in_v6_range( $ip );',
		to: 'return false;',
		// Every visitor behind a v6 edge collapses into one bucket, so five
		// submissions from anybody block the whole edge for an hour.
		mustFail: [ 'test_a_forwarded_ip_is_honoured_from_an_ipv6_cloudflare_edge' ],
	},
	{
		suite: 'php',
		what: 'the IPv6 prefix is compared whole bytes only',
		file: 'includes/class-rate-limiter.php',
		from: 'if ( 0 === $rest ) {\n\t\t\t\treturn true;\n\t\t\t}',
		to: 'return true;',
		// 2a06:98c0::/29 does not end on a byte, so this trusts eight times the
		// range Cloudflare publishes — every address in it able to name its own
		// limiter identity with a header.
		mustFail: [ 'test_the_cloudflare_ipv6_range_boundaries_are_right' ],
	},
];

const SUITE = ( process.argv.slice( 2 ).find( ( a ) => a.startsWith( '--suite=' ) ) || '--suite=js' )
	.slice( '--suite='.length );

if ( ! [ 'js', 'unit', 'php' ].includes( SUITE ) ) {
	process.stdout.write( `unknown suite "${ SUITE }" — expected js, unit or php\n` );
	process.exit( 1 );
}

const SELECTED = MUTATIONS.filter( ( m ) => m.suite === SUITE );

const JUNIT = 'mutation-junit.xml';

/**
 * How to run the integration suite. Overridable because the wp-env invocation
 * is the CI job's business, not this file's.
 *
 * @returns {string} shell command.
 */
function phpunitCommand() {
	return (
		process.env.MUTATION_PHPUNIT_CMD ||
		`npx wp-env run tests-cli --env-cwd=wp-content/plugins/${ path.basename( ROOT ) } ` +
			`vendor/bin/phpunit -c phpunit-integration.xml.dist --log-junit ${ JUNIT }`
	);
}

/**
 * How to run the unit suite. No container: Brain Monkey, no WordPress, so this
 * is the same command a developer runs. Overridable for the same reason as
 * above.
 *
 * @returns {string} shell command.
 */
function unitCommand() {
	return (
		process.env.MUTATION_UNIT_CMD ||
		`vendor/bin/phpunit -c phpunit.xml.dist --log-junit ${ JUNIT }`
	);
}

/**
 * Run the jsdom suite and return the set of failing test names.
 *
 * @returns {Set<string>} names reported as `not ok`.
 */
function failingJs() {
	// THE WHOLE SUITE, NOT A LIST TO KEEP IN STEP. Naming the files here meant
	// tests/js/release-assets.test.js was never mutation-checked at all — it was
	// added without this line being touched, which is the same drift the ship
	// lists and the assets list are each guarded against elsewhere. This is what
	// package.json's `test:js` and CI run.
	const files = fs
		.readdirSync( path.join( ROOT, 'tests', 'js' ) )
		.filter( ( name ) => name.endsWith( '.test.js' ) )
		.sort()
		.map( ( name ) => path.join( 'tests', 'js', name ) );

	const result = spawnSync( process.execPath, [ '--test', ...files ], {
		cwd: ROOT,
		encoding: 'utf8',
		maxBuffer: 32 * 1024 * 1024,
	} );

	const out = ( result.stdout || '' ) + ( result.stderr || '' );

	return new Set(
		[ ...out.matchAll( /^not ok \d+ - (.+)$/gm ) ].map( ( m ) => m[ 1 ].trim() )
	);
}

/**
 * Run a PHPUnit suite and return the set of failing test names.
 *
 * Read from the JUnit report rather than the console output: PHPUnit's
 * human-readable format prints a dot per test and names a failure only in a
 * summary block, so scraping it is guesswork. The report is a contract.
 *
 * @param {string} command How to run it — see phpunitCommand()/unitCommand().
 * @returns {Set<string>|null} names, or null if the run produced no report.
 */
function failingPhpunit( command ) {
	const report = path.join( ROOT, JUNIT );

	if ( fs.existsSync( report ) ) {
		fs.unlinkSync( report );
	}

	spawnSync( command, {
		cwd: ROOT,
		shell: true,
		encoding: 'utf8',
		stdio: [ 'ignore', 'ignore', 'inherit' ],
		maxBuffer: 32 * 1024 * 1024,
	} );

	// NO REPORT IS NOT THE SAME AS NO FAILURES. A fatal, a container that never
	// came up, or a mutation that broke the parse all land here, and returning
	// an empty set would read as "the suite was green".
	if ( ! fs.existsSync( report ) ) {
		return null;
	}

	const xml = fs.readFileSync( report, 'utf8' );
	const failing = new Set();

	// Split on the element boundary so each chunk ends where the next testcase
	// begins; that keeps a self-closing <testcase … /> from swallowing a later
	// sibling's <failure>.
	for ( const chunk of xml.split( '<testcase' ).slice( 1 ) ) {
		const name = ( chunk.match( /^[^>]*\bname="([^"]*)"/ ) || [] )[ 1 ];
		const body = chunk.split( '</testcase>' )[ 0 ];

		if ( name && ( body.includes( '<failure' ) || body.includes( '<error' ) ) ) {
			failing.add( name );
		}
	}

	return failing;
}

/**
 * @returns {Set<string>|null} failing test names for the selected suite.
 */
function failingTests() {
	if ( 'php' === SUITE ) {
		return failingPhpunit( phpunitCommand() );
	}

	if ( 'unit' === SUITE ) {
		return failingPhpunit( unitCommand() );
	}

	return failingJs();
}

/**
 * PHPUnit appends the data-set label to a parameterised test's name, so an
 * exact comparison would miss `… with data set "cargo"`.
 *
 * @param {Set<string>} failed Names reported as failing.
 * @param {string}      want   The name the mutation must make red.
 * @returns {boolean} whether `want` is among them.
 */
function isRed( failed, want ) {
	return failed.has( want ) || [ ...failed ].some( ( n ) => n.startsWith( `${ want } ` ) );
}

let broken = 0;

// Fail loudly if the suite is not green to begin with: every result below would
// otherwise be meaningless.
const baseline = failingTests();

if ( null === baseline ) {
	process.stdout.write( `the ${ SUITE } suite produced no report at all; cannot mutate\n` );
	process.exit( 1 );
}

if ( baseline.size > 0 ) {
	process.stdout.write(
		'the suite must be green before mutating; currently failing:\n  ' +
			[ ...baseline ].join( '\n  ' ) +
			'\n'
	);
	process.exit( 1 );
}

process.stdout.write( `baseline: green (${ SUITE } suite, ${ SELECTED.length } mutation(s))\n\n` );

for ( const mutation of SELECTED ) {
	const file = path.join( ROOT, mutation.file );
	const original = fs.readFileSync( file, 'utf8' );

	if ( ! original.includes( mutation.from ) ) {
		process.stdout.write(
			`STALE  ${ mutation.what }\n       ${ mutation.file } no longer contains the line this mutation edits.\n` +
				'       Update bin/mutation-check.js alongside the refactor.\n\n'
		);
		broken += 1;
		continue;
	}

	// AMBIGUOUS IS NOT APPLIED, BECAUSE A MUTATION THAT LANDS ON THE WRONG
	// OCCURRENCE IS WORSE THAN NO MUTATION.
	//
	// String.replace() with a string pattern edits the FIRST occurrence. So a
	// `from` that also appears in a comment above the line mutates the comment,
	// leaves the code untouched, and reports UNDETECTED — which reads as a weak
	// test rather than as a mutation that never happened, and sends you to fix
	// the wrong file. A comment added to .distignore disarmed the playwright
	// glob mutation exactly this way, in the same change that was adding a
	// second one beside it.
	const hits = original.split( mutation.from ).length - 1;

	if ( hits > 1 ) {
		process.stdout.write(
			`AMBIG  ${ mutation.what }\n       ${ mutation.file } contains this text ${ hits } times, so only the first would be edited.\n` +
				'       Make `from` unique — add surrounding context, or stop repeating it verbatim in a comment.\n\n'
		);
		broken += 1;
		continue;
	}

	fs.writeFileSync( file, original.replace( mutation.from, mutation.to ) );

	let failed;

	try {
		failed = failingTests();
	} finally {
		fs.writeFileSync( file, original );
	}

	if ( null === failed ) {
		process.stdout.write(
			`NO REPORT  ${ mutation.what }\n           the suite produced no report under this mutation.\n\n`
		);
		broken += 1;
		continue;
	}

	const undetected = mutation.mustFail.filter( ( name ) => ! isRed( failed, name ) );

	if ( undetected.length > 0 ) {
		process.stdout.write(
			`UNDETECTED  ${ mutation.what }\n` +
				undetected.map( ( n ) => `            "${ n }" stayed green\n` ).join( '' ) +
				'\n'
		);
		broken += 1;
	} else {
		process.stdout.write(
			`detected    ${ mutation.what }\n` +
				mutation.mustFail.map( ( n ) => `            red: ${ n }\n` ).join( '' ) +
				'\n'
		);
	}
}

if ( broken > 0 ) {
	process.stdout.write( `${ broken } mutation(s) went undetected.\n` );
	process.exit( 1 );
}

process.stdout.write( `all ${ SELECTED.length } ${ SUITE } mutation(s) detected.\n` );
