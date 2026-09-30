#!/usr/bin/env bash
#
# Seed wp-env for the e2e suite.
#
# REQUIRED before Playwright runs. Without it the plugin is unbound, every proxy
# route answers 503, and /insurance/ 404s — so the specs fail in a way that looks
# like broken code rather than an unseeded environment.

set -euo pipefail

# WHERE THE STAND-IN UPSTREAM LIVES IS THE CALLER'S TO SAY.
#
# `host.docker.internal` is a Docker Desktop convenience and does NOT resolve
# inside a Linux container, which is what CI runs. Hardcoding it made every
# search in the browser suite answer "unavailable", and three specs failed for
# a reason that looked like a combobox bug.
SPOTSHIP_UPSTREAM="${SPOTSHIP_UPSTREAM:-http://host.docker.internal:9911}"
echo "upstream: $SPOTSHIP_UPSTREAM"

wp() { npx wp-env run cli wp "$@"; }

echo "activating the plugin"
wp plugin activate "$(basename "$PWD")" || true

echo "configuring and binding"
# Unquoted delimiter so $SPOTSHIP_UPSTREAM expands. No other $ appears below.
wp option update spotship_wi_settings --format=json "$(cat <<JSON
{
  "api_base_url": "${SPOTSHIP_UPSTREAM}",
  "api_key": "3f2504e0-4f89-41d3-9a0c-0305e82c3301",
  "privacy_notice_url": "https://example.test/privacy/",
  "support_email": "insurance@example.test",
  "rates_cache_ttl": 3600,
  "bound_site_url": "localhost:8888"
}
JSON
)"

echo "creating the /insurance/ page"
if ! wp post list --post_type=page --name=insurance --format=count | grep -q '^1$'; then
	wp post create --post_type=page --post_status=publish \
		--post_title='Insurance' --post_name='insurance' \
		--post_content='[spotship_war_hull]'
fi

if ! wp post list --post_type=page --name=insurance-cargo --format=count | grep -q '^1$'; then
	wp post create --post_type=page --post_status=publish \
		--post_title='Insurance Cargo' --post_name='insurance-cargo' \
		--post_content='[spotship_war_cargo]'
fi

wp rewrite structure '/%postname%/'
wp rewrite flush --hard

echo "seed complete"
