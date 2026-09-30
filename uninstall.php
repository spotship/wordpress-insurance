<?php
/**
 * Uninstall handler.
 *
 * Runs on delete, never on deactivate. Everything this plugin writes is an
 * option or a transient — it sets no cookies and creates no tables — so removal
 * is a bounded sweep.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The settings blob, which also holds the origin binding.
delete_option( 'spotship_wi_settings' );

/*
 * Transients written under our own prefix: the rates tiers, the stampede lock,
 * the diagnostics ledger and the per-IP rate-limit counters.
 *
 * A prefix sweep rather than a list of names, because the rate-limit counters
 * are keyed per IP and per route and so are not enumerable.
 */
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_spotship_wi_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_spotship_wi_' ) . '%'
	)
);

/*
 * The update checker's own cache row, which the sweep above CANNOT reach.
 *
 * plugin-update-checker names its state `external_updates-<slug>`, with no
 * `spotship_wi_` prefix anywhere in it, so a prefix sweep silently leaves it
 * behind and a reinstall inherits stale update state. Both the single-site and
 * network options are cleared because the plugin may have been network-active.
 */
delete_site_option( 'external_updates-spotship-war-insurance' );
delete_option( 'external_updates-spotship-war-insurance' );
