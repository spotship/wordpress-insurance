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

/**
 * Remove everything this plugin wrote for ONE site.
 *
 * Factored out because uninstall runs in a single blog context, and a
 * network-activated install has written these rows on every site in the
 * network. See the loop at the bottom.
 */
function spotship_wi_uninstall_site(): void {
	global $wpdb;

	// The settings blob, which also holds the origin binding.
	delete_option( 'spotship_wi_settings' );

	/*
	 * The rates fetch lock is a plain OPTION, not a transient — it is taken with
	 * add_option() because that is atomic against the unique index on option_name,
	 * which get_transient/set_transient is not. It therefore carries no
	 * `_transient_` prefix and the sweep below CANNOT reach it.
	 */
	delete_option( 'spotship_wi_rates_lock' );

	/*
	 * THE NAMED TRANSIENTS GO THROUGH THE API, NOT THE SWEEP BELOW.
	 *
	 * On an install with a persistent object cache — Redis, Memcached — a
	 * transient may never touch the options table at all, so a SQL sweep
	 * removes nothing and a reinstall inherits the previous install's rate
	 * table, diagnostics ledger and 401 state. delete_transient() is the only
	 * call that reaches the cache, and it needs an exact key.
	 *
	 * Every enumerable key is therefore deleted by name here. The per-IP
	 * rate-limit counters are the one set that cannot be enumerated; they are
	 * bounded by their own window (an hour at most) and expire on their own,
	 * which is the honest limit of what uninstall can promise.
	 */
	$spotship_wi_named = array(
		'spotship_wi_rates',        // Spotship_WI_Rates_Cache::FRESH.
		'spotship_wi_rates_backup', // Spotship_WI_Rates_Cache::BACKUP.
		'spotship_wi_ledger',       // Spotship_WI_Diagnostics::LEDGER.
		'spotship_wi_last_ok',      // Spotship_WI_Diagnostics::LAST_OK.
	);

	foreach ( $spotship_wi_named as $spotship_wi_transient ) {
		delete_transient( $spotship_wi_transient );
	}

	/*
	 * And the prefix sweep, for the rate-limit counters and anything else left
	 * in the options table on an install with no persistent object cache.
	 *
	 * A direct query is correct here and caching is meaningless. There is no API
	 * for "delete every transient matching a prefix" — delete_transient() needs an
	 * exact key, and the per-IP rate-limit keys are not enumerable. This runs once,
	 * at uninstall, after which the plugin is gone; a cache layer would be warming
	 * rows that are being deleted.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above.
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
	 * behind and a reinstall inherits stale update state.
	 */
	delete_option( 'external_updates-spotship-war-insurance' );
}

/*
 * UNINSTALL RUNS ONCE, IN ONE BLOG'S CONTEXT.
 *
 * On a network-activated multisite install the plugin has been active on every
 * site, and has written its settings — including the api_key and the origin
 * binding — into every site's options table. Deleting from the current site
 * only leaves a live credential behind on every other one, which is the part
 * that makes this a security matter rather than untidiness.
 *
 * get_sites() is capped: a network with more sites than this needs a WP-CLI
 * sweep rather than a PHP request that will time out halfway and leave the job
 * half done with no record of where it stopped.
 */
if ( is_multisite() ) {
	$spotship_wi_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 10000,
		)
	);

	foreach ( $spotship_wi_sites as $spotship_wi_site_id ) {
		switch_to_blog( (int) $spotship_wi_site_id );
		spotship_wi_uninstall_site();
		restore_current_blog();
	}
} else {
	spotship_wi_uninstall_site();
}

// Network-wide, written once rather than per site.
delete_site_option( 'external_updates-spotship-war-insurance' );
