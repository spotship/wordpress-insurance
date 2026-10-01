#!/usr/bin/env bash
#
# The plugin version lives in exactly two places. This asserts they agree.
#
# They drift silently otherwise: the update checker reads the header while
# X-Plugin-Version on every outbound request reports the constant, so a mismatch
# makes the fleet update against one version and report another, and
# stale-version alerting quietly lies.

set -euo pipefail

FILE=spotship-war-insurance.php

header=$( sed -n 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p' "$FILE" | head -1 | tr -d '[:space:]' )
constant=$( sed -n "s/^define( 'SPOTSHIP_WI_VERSION', '\(.*\)' );$/\1/p" "$FILE" | head -1 )

if [ -z "$header" ] || [ -z "$constant" ]; then
	echo "assert-version: could not read both versions (header='$header' constant='$constant')" >&2
	exit 1
fi

if [ "$header" != "$constant" ]; then
	echo "assert-version: MISMATCH — header '$header' vs SPOTSHIP_WI_VERSION '$constant'" >&2
	exit 1
fi

# AND THE TAG BEING RELEASED, WHEN THERE IS ONE.
#
# Agreeing with each other is not agreeing with the release. A `v0.2.0` tag on a
# tree still declaring 0.1.0 passed the check above, built a 0.1.0 zip, and
# published it as the v0.2.0 asset — and plugin-update-checker reads the header
# out of that zip, so every install would have seen 0.1.0 offered as the upgrade
# to 0.1.0 and no site would ever have taken it. A silent non-release.
#
# GITHUB_REF_NAME is set on tag runs only; nothing here fires on other events.
#
# EXPECTED_TAG EXISTS BECAUSE THE TAG CHECK MUST SURVIVE BEING CALLED.
#
# release.yaml is now also reachable through `workflow_call`, from the
# semantic-release job that creates the tag in the first place. In that context
# GITHUB_REF_TYPE is not `tag` — it is the caller's ref — so the check above
# would silently skip, and skipping is the one outcome it exists to prevent.
# The caller passes the tag it just made instead.
expected="${EXPECTED_TAG:-}"

if [ -z "$expected" ] && [ "${GITHUB_REF_TYPE:-}" = "tag" ]; then
	expected="${GITHUB_REF_NAME:-}"
fi

if [ -n "$expected" ]; then
	if [ "$expected" != "v$header" ]; then
		echo "assert-version: TAG MISMATCH — tag '$expected' vs plugin version '$header' (expected 'v$header')" >&2
		exit 1
	fi

	echo "assert-version: $header (matches tag $expected)"
	exit 0
fi

echo "assert-version: $header"
