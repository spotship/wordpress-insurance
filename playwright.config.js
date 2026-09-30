/**
 * Playwright configuration.
 *
 * ⛔ THESE SPECS HAVE NEVER EXECUTED. Playwright's runner does not start on the
 * development machine — `playwright --version` itself hangs, before any browser
 * is involved — which is tracked as ENG-6418 and gates this branch's merge.
 * Until the CI `e2e` job runs green, no WCAG 2.1 AA conformance claim can be
 * made for this plugin.
 *
 * They are committed rather than deferred because the specs are the definition
 * of the acceptance criteria; writing them later would mean writing them to fit
 * whatever the code happens to do.
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
	reporter: process.env.CI ? [ [ 'github' ], [ 'list' ] ] : [ [ 'list' ] ],

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
