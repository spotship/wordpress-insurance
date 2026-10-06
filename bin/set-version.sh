#!/usr/bin/env bash
#
# Write the plugin version to BOTH places that hold it, then prove they agree.
#
# semantic-release decides the number; this is what makes the tree match it.
# There is no package.json version to bump — this is a WordPress plugin, and
# package.json exists only to carry the release configuration — so without this
# semantic-release would tag v0.2.0 against a tree still declaring 0.1.0, which
# bin/assert-version.sh is written to catch and which would therefore fail the
# release rather than ship a wrong one. Correct, but late.
#
# Both writes are anchored to the exact line shapes assert-version.sh reads
# back, so a drift in either direction fails loudly here rather than producing
# a zip whose header and constant disagree.
#
# Usage: bin/set-version.sh 1.2.3

set -euo pipefail

VERSION="${1:-}"
FILE=spotship-war-insurance.php

if [ -z "$VERSION" ]; then
	echo "set-version: a version is required (e.g. bin/set-version.sh 1.2.3)" >&2
	exit 1
fi

# Reject anything that is not a plain semver-ish string before writing it into
# a PHP define: this value arrives from semantic-release, and a malformed one
# would produce a file that does not parse.
if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
	echo "set-version: '$VERSION' is not a version this will write" >&2
	exit 1
fi

if [ ! -f "$FILE" ]; then
	echo "set-version: $FILE not found — run from the repository root" >&2
	exit 1
fi

# Beside the target rather than in $TMPDIR: `mv` across filesystems is not
# atomic, and a half-written plugin header is a file that does not parse.
tmp="${FILE}.set-version.tmp"
trap 'rm -f "$tmp"' EXIT

sed \
	-e "s/^ \* Version:\([[:space:]]*\).*$/ * Version:\1${VERSION}/" \
	-e "s/^define( 'SPOTSHIP_WI_VERSION', '.*' );$/define( 'SPOTSHIP_WI_VERSION', '${VERSION}' );/" \
	"$FILE" > "$tmp"

mv "$tmp" "$FILE"
trap - EXIT

# Read it back rather than trusting the sed. A pattern that matches nothing
# exits 0 and changes nothing, which is exactly how a release ships the
# previous version's number.
bash bin/assert-version.sh

written=$( sed -n "s/^define( 'SPOTSHIP_WI_VERSION', '\(.*\)' );$/\1/p" "$FILE" | head -1 )

if [ "$written" != "$VERSION" ]; then
	echo "set-version: asked for '$VERSION' but the file says '$written'" >&2
	exit 1
fi

echo "set-version: $VERSION"
