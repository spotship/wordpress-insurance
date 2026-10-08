/**
 * Playwright configuration.
 *
 * THESE SPECS RUN IN CI, NOT LOCALLY. Playwright's runner does not start on the
 * development machine — `playwright --version` itself hangs, before any browser
 * is involved — which is ENG-6418. That is a local tooling fault; the `e2e` job
 * in ci.yaml is where these execute and where the accessibility result comes
 * from, so read CI rather than concluding from a local hang that they are
 * unproven.
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
