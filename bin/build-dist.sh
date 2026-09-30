#!/usr/bin/env bash
#
# Build the distributable zip.
#
# NON-MUTATING BY CONSTRUCTION. Everything happens in a temporary staging
# directory; this script never writes to, prunes, or reinstalls anything in the
# working checkout.
#
# That is not a style preference. A previous version of this script ran
# `composer install --no-dev` in place, which took the working tree's vendor/
# from 25 directories (102MB) to 3 (760KB) and destroyed the local toolchain —
# so the build succeeded and every subsequent `task lint` failed with a missing
# phpcs, which reads like a broken repo rather than a build that ate it.

set -euo pipefail

SLUG=spotship-war-insurance
ROOT=$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )
OUT="$ROOT/dist"

bash "$ROOT/bin/assert-version.sh"

VERSION=$( sed -n "s/^define( 'SPOTSHIP_WI_VERSION', '\(.*\)' );$/\1/p" "$ROOT/spotship-war-insurance.php" | head -1 )

STAGE=$( mktemp -d )
trap 'rm -rf "$STAGE"' EXIT

echo "staging in $STAGE"

# Copy the checkout, honouring .distignore. rsync --exclude-from wants one
# pattern per line, which is exactly the .distignore format.
rsync -a --exclude-from="$ROOT/.distignore" --exclude='dist' "$ROOT/" "$STAGE/$SLUG/"

# Production dependencies are installed IN THE STAGING COPY. The working
# checkout's vendor/ is never touched.
if [ -f "$ROOT/composer.json" ]; then
	cp "$ROOT/composer.json" "$STAGE/$SLUG/composer.json"
	[ -f "$ROOT/composer.lock" ] && cp "$ROOT/composer.lock" "$STAGE/$SLUG/composer.lock"

	if command -v composer >/dev/null 2>&1; then
		composer install --no-dev --no-interaction --optimize-autoloader --working-dir="$STAGE/$SLUG"
	else
		docker run --rm -v "$STAGE/$SLUG":/app -w /app composer:2 \
			install --no-dev --no-interaction --optimize-autoloader
	fi

	# composer.json itself does not ship.
	rm -f "$STAGE/$SLUG/composer.json" "$STAGE/$SLUG/composer.lock"
fi

mkdir -p "$OUT"
rm -f "$OUT/$SLUG-$VERSION.zip"

( cd "$STAGE" && zip -rq "$OUT/$SLUG-$VERSION.zip" "$SLUG" -x '*.DS_Store' )

echo "built $OUT/$SLUG-$VERSION.zip"

# WHAT SHIPS IS AN ALLOW-LIST, CHECKED AGAINST THE BUILT ZIP.
#
# .distignore is a blocklist, and a blocklist fails open: a file added to the
# repo ships unless someone remembers to exclude it. A dry run of this script
# found .claude/, CLAUDE.md, TESTING.md, renovate.json, .coderabbit.yml,
# playwright.config.js and phpunit-integration.xml.dist all reaching the zip —
# internal documentation of the architecture and the known gaps, bound for a
# customer's public web server.
#
# So the artifact is checked against what it is ALLOWED to contain. A new
# top-level entry fails the release until someone decides which side it is on.
ALLOWED="assets blocks includes languages readme.txt spotship-war-insurance.php templates uninstall.php vendor"

unexpected=""
while read -r entry; do
	case " $ALLOWED " in
		*" $entry "*) ;;
		*) unexpected="$unexpected $entry" ;;
	esac
done < <( unzip -Z1 "$OUT/$SLUG-$VERSION.zip" | sed "s|^$SLUG/||" | cut -d/ -f1 | sort -u | grep -v '^$' )

if [ -n "$unexpected" ]; then
	echo "build-dist: these are not on the ship list and would reach a customer's server:$unexpected" >&2
	echo "build-dist: add them to .distignore, or to ALLOWED in this script if they genuinely belong." >&2
	exit 1
fi

echo "build-dist: ship list clean"

# A build that shipped a credential would be unrecoverable once a customer had
# the zip, so the artifact is scanned before it is handed over. tests/ is
# excluded from the zip already, so any hit here is in shipped code.
if unzip -p "$OUT/$SLUG-$VERSION.zip" '*.php' | grep -nE "[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}" >/dev/null 2>&1; then
	echo "build-dist: a UUID-shaped literal is present in the shipped PHP — refusing to publish" >&2
	exit 1
fi

echo "build-dist: credential scan clean"
