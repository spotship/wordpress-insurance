#!/usr/bin/env bash
#
# Generate includes/data/rates-fallback.php from the live rates endpoint.
#
# Run at RELEASE time only. The repository deliberately ships an empty table:
# these are commercially approved figures, and a hand-written placeholder would
# be invented numbers published on a customer's public website under a Spot Ship
# footer. Empty means the bundled tier is unavailable and the table hides, which
# is the honest behaviour for a build nobody generated.
#
# Failure here is FATAL. A release that silently shipped an empty table would
# remove the offline fallback with nothing to notice it.

set -euo pipefail

: "${RATES_URL:?RATES_URL is required}"
: "${RATES_KEY:?RATES_KEY is required}"

ROOT=$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )
TARGET="$ROOT/includes/data/rates-fallback.php"

body=$( curl -fsS -H "Authorization: Bearer $RATES_KEY" -H 'Accept: application/json' "$RATES_URL" )

if [ -z "$body" ] || [ "$body" = '{}' ]; then
	echo "generate-fallback-rates: refusing to write an empty table" >&2
	exit 1
fi

php -r '
$json = file_get_contents( "php://stdin" );
$data = json_decode( $json, true );

if ( ! is_array( $data ) || ! isset( $data["updatedAt"] ) ) {
	fwrite( STDERR, "generate-fallback-rates: payload is not a rate table\n" );
	exit( 1 );
}

$export = var_export( $data, true );

echo "<?php\n";
echo "/**\n";
echo " * Bundled fallback rate table — GENERATED AT RELEASE TIME, do not hand-edit.\n";
echo " *\n";
echo " * Fetched from the live rates endpoint by bin/generate-fallback-rates.sh.\n";
echo " * Regenerate rather than editing; a hand-edit here publishes numbers nobody\n";
echo " * approved onto customer websites.\n";
echo " *\n";
echo " * @package Spotship_WI\n";
echo " */\n\n";
echo "declare( strict_types = 1 );\n\n";
echo "defined( \"ABSPATH\" ) || exit;\n\n";
echo "return " . $export . ";\n";
' <<< "$body" > "$TARGET"

echo "generate-fallback-rates: wrote $TARGET"
