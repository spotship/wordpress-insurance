#!/usr/bin/env bash
#
# Regenerate tests/fixtures/backend-201-keys.json from the backend source.
#
# The shape of a genuine 201 is NOT ours to assert. A previous implementation
# hand-wrote a response shape the API never emits, the anti-bot decoy was built
# to imitate that fixture, and the resulting tell shipped. Deriving the key list
# from the serializer is the control that stops it recurring.
#
# Usage: BACKEND=/path/to/backend bash bin/derive-backend-keys.sh

set -euo pipefail

BACKEND="${BACKEND:-$HOME/spotship/otto/backend}"

if [ ! -d "$BACKEND/.git" ]; then
	echo "derive-backend-keys: no backend checkout at $BACKEND (set BACKEND=)" >&2
	exit 1
fi

git -C "$BACKEND" fetch -q origin dev
python3 "$(dirname "${BASH_SOURCE[0]}")/derive-backend-keys.py" "$BACKEND"
