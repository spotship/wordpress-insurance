#!/usr/bin/env bash
#
# The plugin version lives in four shipped places. This asserts they agree.
#
# They drift silently otherwise: the update checker reads the header while
# X-Plugin-Version on every outbound request reports the constant, so a mismatch
# makes the fleet update against one version and report another, and
# stale-version alerting quietly lies. readme.txt and the translation catalogue
# do not change behaviour, but both are unpacked onto a customer's server, and
# both spent the whole 1.0.0 release saying 0.1.0.
#
# bin/set-version.sh writes all four. This is what stops any of them being
# edited back out of step by hand, and what stops a fifth appearing unnoticed.

set -euo pipefail

FILE=spotship-war-insurance.php
README=readme.txt
POT=languages/spotship-war-insurance.pot
WP_ENV=.wp-env.json

# ABSENT IS A FAILURE, NOT A SKIP.
#
# The whole reason these checks exist is that nobody noticed a missing one for a
# whole release. Without this guard a missing file reaches `sed` instead, which
# under `pipefail` aborts the script on a raw "No such file or directory" before
# the named message below can explain what is actually wrong.
for required in "$FILE" "$README" "$POT"; do
	if [ ! -f "$required" ]; then
		echo "assert-version: $required not found — run from the repository root" >&2
		exit 1
	fi
done

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
# beside it.
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

# THE CHANGELOG IS PART OF THE README LAGGING, NOT A SEPARATE PROBLEM.
#
# Checking one line of the header and nothing else would have left a readme
# whose newest changelog entry was still a release old — which is the state
# 1.0.0 actually shipped in, under a `Stable tag` it had just been given.
# bin/set-version.sh writes the heading during prepare; this refuses a tree
# where it is missing.
if ! grep -q "^= ${header} =$" "$README"; then
	echo "assert-version: $README has no '= $header =' changelog section" >&2
	echo "assert-version: bin/set-version.sh writes the heading; fill in the entry." >&2
	exit 1
fi

# THE SUPPORT CLAIMS ARE DUPLICATED TOO, AND DUPLICATED IS DRIFTED.
#
# `Requires at least` and `Requires PHP` appear in the plugin header AND in the
# readme, and WordPress reads a different one in each context — the header for
# install-time blocking, the readme for what a human is told. Two numbers with
# no check between them is how `Stable tag` got where it did.
for field in 'Requires at least' 'Requires PHP'; do
	plugin_value=$( sed -n "s/^ \* ${field}:[[:space:]]*\(.*\)\$/\1/p" "$FILE" | head -1 | tr -d '[:space:]' )
	readme_value=$( sed -n "s/^${field}:[[:space:]]*\(.*\)\$/\1/p" "$README" | head -1 | tr -d '[:space:]' )

	if [ -z "$plugin_value" ] || [ -z "$readme_value" ]; then
		echo "assert-version: '$field' must appear in both $FILE and $README" >&2
		exit 1
	fi

	if [ "$plugin_value" != "$readme_value" ]; then
		echo "assert-version: MISMATCH — '$field' is '$plugin_value' in $FILE and '$readme_value' in $README" >&2
		exit 1
	fi
done

# `Tested up to` IS A CLAIM ABOUT WHAT CI ACTUALLY RAN.
#
# It was hand-corrected 6.7 -> 6.8 with nothing checking it, which is the same
# shape of defect as everything above. .wp-env.json pins the core the suites run
# against, so that pin is the evidence for the claim; when it says `latest`
# there is no number to compare and this does not pretend otherwise.
if [ -f "$WP_ENV" ]; then
	pinned=$( sed -n 's/.*"core"[[:space:]]*:[[:space:]]*"[^"]*#\([0-9][^"]*\)".*/\1/p' "$WP_ENV" | head -1 )
	tested=$( sed -n 's/^Tested up to:[[:space:]]*\(.*\)$/\1/p' "$README" | head -1 | tr -d '[:space:]' )

	if [ -n "$pinned" ] && [ "$pinned" != "$tested" ]; then
		echo "assert-version: MISMATCH — $README 'Tested up to: $tested' vs $WP_ENV core '$pinned'" >&2
		exit 1
	fi
fi

# THE CATALOGUE HEADER IS SHIPPED TOO.
#
# bin/build-dist.sh puts languages/ in the zip, so `Project-Id-Version` reaches
# every customer and every translator. It was hardcoded to 0.1.0 in
# bin/refresh-pot.sh and nobody had ever looked at it.
catalogue=$( sed -n 's/^"Project-Id-Version: Spot Ship War Insurance \(.*\)\\n"$/\1/p' "$POT" | head -1 | tr -d '[:space:]' )

if [ -z "$catalogue" ]; then
	echo "assert-version: no 'Project-Id-Version' line found in $POT" >&2
	exit 1
fi

if [ "$catalogue" != "$header" ]; then
	echo "assert-version: MISMATCH — $POT 'Project-Id-Version: ... $catalogue' vs plugin version '$header'" >&2
	echo "assert-version: bin/refresh-pot.sh reads this from the plugin header; regenerate it." >&2
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
