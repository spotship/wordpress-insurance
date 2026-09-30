<?php
/**
 * Bundled fallback rate table — GENERATED AT RELEASE TIME, do not hand-edit.
 *
 * `bin/build-dist.sh` replaces this file by fetching the live rates endpoint
 * when the release zip is built, so a customer install with no connectivity
 * still has something to show.
 *
 * IN THE REPOSITORY IT IS DELIBERATELY EMPTY. These are commercially approved
 * figures; a hand-written placeholder would be invented numbers published on a
 * customer's public website under a Spot Ship footer, which is worse in every
 * way than showing no table at all. An empty return makes the bundled tier
 * unavailable, the chain falls through to hiding the table, and the form still
 * works — which is the honest behaviour for a build nobody generated.
 *
 * @package Spotship_WI
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

return array();
