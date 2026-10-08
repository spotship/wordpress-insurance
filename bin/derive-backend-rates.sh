#!/usr/bin/env bash
#
# Regenerate tests/fixtures/backend-rates.json from the backend source.
#
# The rate table's payload is NOT ours to assert. It was hand-written flat —
# a top-level `columns` array of plain strings, a top-level row list per
# product, rows keyed `zone`, rates preformatted as "8.500%" — and the backend
# emits none of that. It nests every one of those under the product, labels its
# columns, keys its rows `name`, and sends rates as JSON numbers.
#
# Nothing caught it because bin/e2e-upstream.js reproduced the invented shape,
# so the browser suite asserted a contract that does not exist. This is the same
# failure bin/derive-backend-keys.sh exists to prevent for the 201 body, so it
# gets the same control.
#
# Usage: BACKEND=/path/to/backend bash bin/derive-backend-rates.sh

set -euo pipefail

BACKEND="${BACKEND:-$HOME/spotship/otto/backend}"

if [ ! -d "$BACKEND/.git" ]; then
	echo "derive-backend-rates: no backend checkout at $BACKEND (set BACKEND=)" >&2
	exit 1
fi

git -C "$BACKEND" fetch -q origin dev
python3 "$(dirname "${BASH_SOURCE[0]}")/derive-backend-rates.py" "$BACKEND"
