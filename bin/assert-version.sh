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

echo "assert-version: $header"
