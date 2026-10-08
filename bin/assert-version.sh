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
README=readme.txt

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

# readme.txt IS SHIPPED, SO IT COUNTS.
#
# `Stable tag` is the third place a version appears, and for a long time it was
# the only one nothing checked: it sat at 0.1.0 through the 1.0.0 release, so
# every customer unpacking the zip got a readme disagreeing with the plugin
# beside it. bin/set-version.sh now rewrites it; this is what stops it being
# edited back out of step by hand.
#
# Absent or unreadable is a failure, not a skip. The whole reason this check
# exists is that nobody noticed its absence for a whole release.
stable=$( sed -n 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p' "$README" | head -1 | tr -d '[:space:]' )

if [ -z "$stable" ]; then
	echo "assert-version: no 'Stable tag:' line found in $README" >&2
	exit 1
fi

if [ "$stable" != "$header" ]; then
	echo "assert-version: MISMATCH — $README 'Stable tag: $stable' vs plugin version '$header'" >&2
	echo "assert-version: bin/set-version.sh writes this; do not edit it by hand." >&2
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
# The real release never meets this script on a tag ref. semantic-release runs
# prepare on a BRANCH, so GITHUB_REF_TYPE is `branch` and the check above would
# silently skip — and skipping is the one outcome it exists to prevent. A
# caller that knows the tag out of band passes it here instead, which is what
# prod-preflight.yaml does to rehearse the check rather than skip it, what
# ci.yaml does to prove the mismatch branch still fails, and what
# reattach-release-asset.yaml does so the check does not depend on having been
# reached from a tag ref.
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
