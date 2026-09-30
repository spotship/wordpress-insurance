/**
 * Playwright against the REAL staging backend.
 *
 * Separate from playwright.config.js on purpose. These specs need a live
 * credential, they write real records, and they must never run on a pull
 * request or a fork. They are driven only by the manually dispatched
 * staging-e2e workflow, which refuses to start without both secrets.
 *
 * What this CAN evidence: that a submission reaches the real API, persists, and
 * comes back with the attribution and consent provenance the milestone
 * requires; that a revoked credential degrades to the neutral sentence; and
 * what the plugin's own diagnostics screen reports against a real endpoint.
 *
 * What it CANNOT evidence, stated rather than glossed: referral email and Slack
 * delivery are side effects on Spot Ship's side of the API. Confirming those
 * needs a mailbox and a Slack channel this job has no access to, so they stay a
 * manual check. Do not read a green run here as proof either fired.
 */
'use strict';

const { defineConfig, devices } = require( '@playwright/test' );

module.exports = defineConfig( {
	testDir: './tests/staging',
	timeout: 60000,
	expect: { timeout: 15000 },
	fullyParallel: false,
	forbidOnly: true,
	// No retries: these write real records, and a retried submission is a
	// duplicate lead in a real inbox.
	retries: 0,
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'staging-report' } ] ],

	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [ { name: 'desktop', use: { ...devices[ 'Desktop Chrome' ] } } ],
} );
