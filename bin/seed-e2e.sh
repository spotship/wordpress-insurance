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
SPOTSHIP_UPSTREAM="${SPOTSHIP_UPSTREAM:-http://host.docker.internal:8080}"
echo "upstream: $SPOTSHIP_UPSTREAM"

# A throwaway uuid by default, because the stand-in upstream does not check it.
# The real-backend workflow passes a REAL credential here instead. Never echoed.
SPOTSHIP_API_KEY="${SPOTSHIP_API_KEY:-3f2504e0-4f89-41d3-9a0c-0305e82c3301}"

# Kept in step with tests/e2e/origin-binding.spec.js. Test fixture, local only.
E2E_ADMIN_PASS="${E2E_ADMIN_PASS:-e2e-admin-password}"

wp() { npx wp-env run cli wp "$@"; }

# BRING THE DATABASE UP TO THE CORE VERSION FIRST.
#
# wp-env keeps its database across runs, so changing .wp-env.json's `core`
# leaves the schema at the old version. WordPress then redirects the FIRST
# admin request to wp-admin/upgrade.php — a minimal screen with no admin bar
# and no settings page — and serves everything after it normally. In the
# browser suite that presents as the first admin spec failing and the rest
# passing, which reads like a flaky test and is not one.
echo "updating the database to match core"
wp core update-db

# A TEST ADMINISTRATOR WITH KNOWN CREDENTIALS.
#
# The browser suite needs to reach wp-admin to exercise the origin-mismatch
# notice and the one-click rebind. Assuming wp-env's default admin/password
# cost three CI runs: the login POST simply returned to wp-login.php, and the
# missing admin bar downstream read first as an upgrade screen and then as a
# navigation race. Create the account instead of guessing at one.
#
# Local test fixture only. This runs against a throwaway wp-env database on
# localhost and never touches a real site.
echo "ensuring the e2e administrator exists"
wp user create e2e-admin e2e-admin@example.test \
	--role=administrator --user_pass="$E2E_ADMIN_PASS" \
	|| wp user update e2e-admin --user_pass="$E2E_ADMIN_PASS"

echo "activating the plugin"
wp plugin activate "$(basename "$PWD")" || true

echo "configuring and binding"
# Unquoted delimiter so $SPOTSHIP_UPSTREAM expands. No other $ appears below.
wp option update spotship_wi_settings --format=json "$(cat <<JSON
{
  "api_base_url": "${SPOTSHIP_UPSTREAM}",
  "api_key": "${SPOTSHIP_API_KEY}",
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

# BOTH FORMS ON ONE PAGE.
#
# The expected customer layout, and the case no spec covered: every other page
# renders exactly one form, so duplicate element ids were invisible to axe. A
# colliding id re-points the second form's labels at the first form's inputs.
if ! wp post list --post_type=page --name=insurance-both --format=count | grep -q '^1$'; then
	wp post create --post_type=page --post_status=publish \
		--post_title='Insurance Both' --post_name='insurance-both' \
		--post_content='[spotship_war_hull]
[spotship_war_cargo]'
fi

# THE SAME SHORTCODE TWICE. Legal, and a stricter collision test than two
# different products, which at least differ in their product-specific fields.
if ! wp post list --post_type=page --name=insurance-twice --format=count | grep -q '^1$'; then
	wp post create --post_type=page --post_status=publish \
		--post_title='Insurance Twice' --post_name='insurance-twice' \
		--post_content='[spotship_war_hull]
[spotship_war_hull]'
fi

# ELEMENTOR.
#
# Named in the spec because it is what the first customer builds pages with, and
# because a page builder is the realistic source of conflict: it enqueues its own
# CSS reset and JS, and renders the shortcode inside its own wrapper markup
# rather than in the_content directly.
#
# Elementor requires WordPress >= 6.8, which is why .wp-env.json pins 6.8 rather
# than the plugin's 6.4 floor. The floor is still enforced, on four PHP/WP
# combinations, by the PHPUnit integration matrix; nothing is lost by running the
# BROWSER suite on a version customers actually have.
#
# Non-fatal: a wordpress.org outage must not turn the whole browser suite red.
# The spec skips when the page is absent rather than failing.
if wp plugin install elementor --activate; then
	# BOTH products, because they are different templates: cargo carries the
	# destinations chip list and two selects that hull does not, and a page
	# builder wrapping only the simpler one proves less than it appears to.
	for product in hull cargo; do
		slug="insurance-elementor-${product}"

		if wp post list --post_type=page --name="$slug" --format=count | grep -q '^1$'; then
			continue
		fi

		page_id=$(wp post create --post_type=page --post_status=publish \
			--post_title="Insurance Elementor ${product}" --post_name="$slug" \
			--post_content='' --porcelain)

		# Elementor renders from _elementor_data, not post_content. A shortcode
		# widget is the supported way to place a third-party shortcode.
		wp post meta update "$page_id" _elementor_edit_mode builder
		wp post meta update "$page_id" _elementor_template_type wp-page
		wp post meta update "$page_id" _elementor_data \
			"[{\"id\":\"s1\",\"elType\":\"section\",\"elements\":[{\"id\":\"c1\",\"elType\":\"column\",\"settings\":{\"_column_size\":100},\"elements\":[{\"id\":\"w1\",\"elType\":\"widget\",\"widgetType\":\"shortcode\",\"settings\":{\"shortcode\":\"[spotship_war_${product}]\"}}],\"isInner\":false}],\"isInner\":false}]"
	done

	# The NATIVE widgets, which are a different code path from the shortcode
	# above: Elementor instantiates Spotship_WI_Elementor_Hull and calls its
	# render(), so these pages fail if registration never happened, if the
	# widget name stored in _elementor_data does not resolve, or if the class
	# could not be autoloaded. A shortcode page would pass through all three.
	for product in hull cargo; do
		slug="insurance-elementor-widget-${product}"

		if wp post list --post_type=page --name="$slug" --format=count | grep -q '^1$'; then
			continue
		fi

		page_id=$(wp post create --post_type=page --post_status=publish \
			--post_title="Insurance Elementor widget ${product}" --post_name="$slug" \
			--post_content='' --porcelain)

		# widgetType is Spotship_WI_Elementor_{Hull,Cargo}::get_name(). Elementor
		# renders a missing-widget placeholder rather than erroring if it does
		# not resolve, which is why the spec asserts on the form, not the page.
		wp post meta update "$page_id" _elementor_edit_mode builder
		wp post meta update "$page_id" _elementor_template_type wp-page
		wp post meta update "$page_id" _elementor_data \
			"[{\"id\":\"s1\",\"elType\":\"section\",\"elements\":[{\"id\":\"c1\",\"elType\":\"column\",\"settings\":{\"_column_size\":100},\"elements\":[{\"id\":\"w1\",\"elType\":\"widget\",\"widgetType\":\"spotship-war-${product}\",\"settings\":{}}],\"isInner\":false}],\"isInner\":false}]"
	done
else
	echo "WARNING: Elementor could not be installed; its specs will skip."
fi

wp rewrite structure '/%postname%/'
wp rewrite flush --hard

echo "seed complete"
