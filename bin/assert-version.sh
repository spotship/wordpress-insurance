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
#
# EVERY RELEASE-DERIVED VALUE ASSERTED HERE IS WRITTEN BY bin/set-version.sh.
#
# That script calls this one as its last act, from inside semantic-release's
# `prepare`. A value that VARIES WITH THE RELEASE, compared here and written by
# nobody, therefore fails the release AFTER the version has been computed and
# four files already rewritten — late, and in the one place where late is
# expensive. `Tested up to` was exactly that: derived from .wp-env.json's core
# pin and written by nobody, so a wp-env bump left readme.txt disagreeing with
# the pin and no tooling able to put it right. set-version.sh writes it now.
#
# THE SUPPORT FLOORS BELOW ARE THE DELIBERATE EXCEPTION, and the line between
# them is the rule to apply when adding a check:
#
#   varies with the release number -> bin/set-version.sh MUST write it
#   hand-maintained tree invariant -> it must not; assert it and leave it
#
# `Requires at least`, `Requires PHP` and their four tooling counterparts are a
# support policy a human decides, not an output of a version bump. Releasing
# never introduces drift between them — only a commit that edits one of the six
# without the others does, which is exactly what these are here to catch. If
# such a commit lands, the next release aborts in `prepare` rather than shipping
# a tree whose declared floors disagree; that is the correct outcome, and it is
# why they stay here. Writing them from set-version.sh would turn a deliberate
# policy change into a silent side effect of releasing.
#
# They stay in this script rather than moving to ci.yaml because
# .github/workflows/cd.yaml does not depend on ci.yaml, and because ci.yaml
# already runs this whole script twice. Moving them would lose the only check
# standing between a drifted tree and a release, and gain nothing.

set -euo pipefail

FILE=spotship-war-insurance.php
README=readme.txt
POT=languages/spotship-war-insurance.pot
WP_ENV=.wp-env.json
COMPOSER=composer.json
PHPCS=phpcs.xml.dist

# ABSENT IS A FAILURE, NOT A SKIP.
#
# The whole reason these checks exist is that nobody noticed a missing one for a
# whole release. Without this guard a missing file reaches `sed` instead, which
# under `pipefail` aborts the script on a raw "No such file or directory" before
# the named message below can explain what is actually wrong.
for required in "$FILE" "$README" "$POT" "$WP_ENV"; do
	if [ ! -f "$required" ]; then
		echo "assert-version: $required not found — run from the repository root" >&2
		exit 1
	fi
done

# THE PRODUCT NAME IS READ, NOT WRITTEN DOWN AGAIN. It had four hardcoded
# copies across this script, bin/set-version.sh and bin/refresh-pot.sh; a rename
# would have made every one of them match nothing and report an empty version,
# which names the wrong cause. The header the script is already parsing has it.
plugin_name=$( sed -n 's/^ \* Plugin Name:[[:space:]]*\(.*\)$/\1/p' "$FILE" | head -1 | sed 's/[[:space:]]*$//' )

if [ -z "$plugin_name" ]; then
	echo "assert-version: could not read the Plugin Name: header from $FILE" >&2
	exit 1
fi

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
#
# AND THEY APPEAR IN THE TOOLING, WHICH IS WHERE THEY DO THE MOST DAMAGE.
#
# Checking only the two declarations left the two enforcers out: composer
# resolves dependencies against `config.platform.php`, and PHPCompatibilityWP
# scans against phpcs's `testVersion` while WPCS reads `minimum_wp_version`.
# Raise the header and the readme to 8.2 with those three behind and everything
# is green while the suites still prove 8.1 — a declared floor nothing tests.
# Six numbers with two checks between them was the same defect, further out.
#
# Each spelling differs, so each is compared to the form it is supposed to take
# rather than to a bare number.
check_echo() {
	local label=$1 file=$2 expr=$3 want=$4
	local got

	if [ ! -f "$file" ]; then
		echo "assert-version: $file not found — run from the repository root" >&2
		exit 1
	fi

	got=$( sed -n "$expr" "$file" | head -1 | tr -d '[:space:]' )

	if [ -z "$got" ]; then
		echo "assert-version: $label not found in $file" >&2
		exit 1
	fi

	if [ "$got" != "$want" ]; then
		echo "assert-version: MISMATCH — $label is '$got' in $file, expected '$want'" >&2
		exit 1
	fi
}

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

	if [ "$field" = 'Requires PHP' ]; then
		# "php": ">=8.1" — what composer will install for.
		check_echo "the 'php' constraint" "$COMPOSER" \
			's/.*"php"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' ">=$plugin_value"

		# platform.php is a full x.y.z — what composer RESOLVES against, which
		# is the one that silently pulls a dependency the floor cannot run.
		check_echo "config.platform.php" "$COMPOSER" \
			's/^[[:space:]]*"php"[[:space:]]*:[[:space:]]*"\([0-9][^"]*\)".*/\1/p' "$plugin_value.0"

		# testVersion is a RANGE: "8.1-" means 8.1 and up.
		check_echo "phpcs testVersion" "$PHPCS" \
			's/.*name="testVersion"[[:space:]]*value="\([^"]*\)".*/\1/p' "$plugin_value-"
	else
		check_echo "phpcs minimum_wp_version" "$PHPCS" \
			's/.*name="minimum_wp_version"[[:space:]]*value="\([^"]*\)".*/\1/p' "$plugin_value"
	fi
done

# `Tested up to` IS A CLAIM ABOUT WHAT THE SUITES ACTUALLY RAN AGAINST.
#
# It was hand-corrected 6.7 -> 6.8 with nothing checking it, which is the same
# shape of defect as everything above. .wp-env.json pins the core, so that pin
# is the evidence — and ci.yaml's integration matrix is checked against the same
# pin by its own job, because wp-env's pin is overridden by WP_ENV_CORE on every
# runner and a pin no suite honours is not evidence of anything.
#
# ABSENT IS A FAILURE HERE TOO. This used to skip when .wp-env.json was missing
# or pinned `latest`, so the readme could claim any version at all and stay
# green — an advisory check on the one value nothing wrote. bin/set-version.sh
# writes it from this same pin now, so there is always a number to compare.
pinned=$( sed -n 's/.*"core"[[:space:]]*:[[:space:]]*"[^"]*#\([0-9][^"]*\)".*/\1/p' "$WP_ENV" | head -1 )

if [ -z "$pinned" ]; then
	echo "assert-version: $WP_ENV does not pin a numbered core (\"core\": \"WordPress/WordPress#6.8\")" >&2
	echo "assert-version: $README 'Tested up to' is a numeric claim, so there has to be a number behind it." >&2
	exit 1
fi

check_echo "'Tested up to'" "$README" 's/^Tested up to:[[:space:]]*\(.*\)$/\1/p' "$pinned"

# THE CATALOGUE HEADER IS SHIPPED TOO.
#
# bin/build-dist.sh puts languages/ in the zip, so `Project-Id-Version` reaches
# every customer and every translator. It was hardcoded to 0.1.0 in
# bin/refresh-pot.sh and nobody had ever looked at it.
catalogue=$( sed -n "s/^\"Project-Id-Version: ${plugin_name} \(.*\)\\\\n\"\$/\1/p" "$POT" | head -1 | tr -d '[:space:]' )

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
