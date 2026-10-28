/**
 * Playwright configuration.
 *
 * THESE SPECS RUN LOCALLY AS WELL AS IN CI. The file used to say otherwise, on
 * the strength of ENG-6418's hang: what actually fails is Chromium inside an
 * agent's Seatbelt sandbox, which cannot register a Mach bootstrap port and
 * dies before the first page — and Playwright cannot even reap it afterwards,
 * so it presents as a hang rather than an error. In an ordinary shell the whole
 * suite is green in about two minutes. TESTING.md's "Running things" has the
 * four commands it needs.
 *
 * The `e2e` job in ci.yaml remains where the conformance result is earned, for
 * the usual reason: it runs on a machine nobody has configured by hand.
 */
'use strict';

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	timeout: 30000,
	expect: { timeout: 5000 },
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	// The html reporter is what the ci.yaml artifact uploads. Without it the
	// artifact uploads an empty directory and the evidence is only the log.
	reporter: process.env.CI
		? [ [ 'github' ], [ 'list' ], [ 'html', { open: 'never' } ], [ 'json', { outputFile: 'playwright-report/results.json' } ] ]
		: [ [ 'list' ] ],

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{ name: 'desktop', use: { ...devices[ 'Desktop Chrome' ] } },
		{
			// 320px reflow is an explicit acceptance criterion, not a nicety.
			name: 'narrow',
			use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 320, height: 800 } },
		},
	],
} );
