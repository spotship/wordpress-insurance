#!/usr/bin/env bash
#
# Write the plugin version to EVERY place that holds it, then prove they agree.
#
# There are four, and all four are shipped inside the zip:
#
#   spotship-war-insurance.php  `Version:` header     read by the update checker
#   spotship-war-insurance.php  SPOTSHIP_WI_VERSION   sent as X-Plugin-Version
#   readme.txt                  `Stable tag:`         read by whoever unpacks it
#   languages/*.pot             `Project-Id-Version:` read by translators
#
# semantic-release decides the number; this is what makes the tree match it.
# There is no package.json version to bump — this is a WordPress plugin, and
# package.json exists only to carry the release configuration — so without this
# semantic-release would tag v0.2.0 against a tree still declaring 0.1.0, which
# bin/assert-version.sh is written to catch and which would therefore fail the
# release rather than ship a wrong one. Correct, but late.
#
# A NUMBER A HUMAN HAS TO REMEMBER TO BUMP IS A NUMBER THAT DRIFTS. `Stable tag`
# sat at 0.1.0 through the entire 1.0.0 release, and the catalogue header sat at
# 0.1.0 beside it, both for exactly that reason. So each of the four is written
# here rather than merely asserted, every write is read back, and
# bin/assert-version.sh then refuses a tree where any of them disagree.
#
# EVERY FILE WRITTEN HERE MUST ALSO APPEAR IN THE @semantic-release/git `assets`
# LIST IN package.json. That plugin commits only the paths it is given, so a
# file written here and missing there is rewritten during prepare, shipped
# correctly in the zip, and then thrown away with the runner — leaving the tag
# pointing at a tree that still carries the previous number. ci.yaml's
# release-config job asserts the two lists agree, because readme.txt was added
# to this script without being added to that list, and that is what it would
# have done at the next release.
#
# Usage: bin/set-version.sh 1.2.3

set -euo pipefail

VERSION="${1:-}"
FILE=spotship-war-insurance.php
README=readme.txt
POT=languages/spotship-war-insurance.pot
NOTES=.sswi-release-notes.md

if [ -z "$VERSION" ]; then
	echo "set-version: a version is required (e.g. bin/set-version.sh 1.2.3)" >&2
	exit 1
fi

# Reject anything that is not a plain semver-ish string before writing it into
# a PHP define: this value arrives from semantic-release, and a malformed one
# would produce a file that does not parse. It is also substituted into awk
# replacements below, which this keeps free of `&` and backslashes.
if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'; then
	echo "set-version: '$VERSION' is not a version this will write" >&2
	exit 1
fi

for required in "$FILE" "$README" "$POT"; do
	if [ ! -f "$required" ]; then
		echo "set-version: $required not found — run from the repository root" >&2
		exit 1
	fi
done

# ONE TRAP, INSTALLED ONCE.
#
# Each rewrite used to install its own `trap ... EXIT`, which silently REPLACES
# the previous one rather than adding to it — safe only because the preceding
# rewrite had already cleared it, an ordering nobody reading the file would
# notice they were depending on. A single trap over a single variable has no
# such ordering.
rewrite_tmp=
trap 'if [ -n "$rewrite_tmp" ]; then rm -f "$rewrite_tmp"; fi' EXIT

# Rewrite one file in place, atomically, by piping it through "$@".
#
# The temp file sits beside the target rather than in $TMPDIR: `mv` across
# filesystems is not atomic, and a half-written plugin header is a file that
# does not parse. One copy of that reasoning for every file written below,
# rather than one copy per file to drift apart.
rewrite_in_place() {
	local file=$1
	shift

	rewrite_tmp="${file}.set-version.tmp"

	"$@" < "$file" > "$rewrite_tmp"
	mv "$rewrite_tmp" "$file"
	rewrite_tmp=
}

# Read it back rather than trusting the rewrite. A pattern that matches nothing
# changes nothing and still exits 0, which is exactly how a release ships the
# previous version's number.
#
# EVERY FILE IS VERIFIED HERE, LOCALLY. readme.txt used to be covered only
# transitively, because assert-version.sh happened to compare it against the
# header; reordering two lines, or an early exit added to that script, would
# have removed its verification while this one still reported success.
read_back() {
	local what=$1 file=$2 expr=$3
	local got

	got=$( sed -n "$expr" "$file" | head -1 | tr -d '[:space:]' )

	if [ "$got" != "$VERSION" ]; then
		echo "set-version: asked for '$VERSION' but $what in $file says '$got'" >&2
		exit 1
	fi
}

# awk rather than sed for the writes: the catalogue header ends in a literal
# `\n` and the replacement escaping needed to preserve it through sed differs
# between GNU and BSD. awk substitutes the value as given.
#
# Each rule fires on the FIRST matching line only. `Stable tag:` at the start of
# a line is legal prose in a WordPress readme FAQ or changelog, and
# bin/assert-version.sh reads only the first one, so rewriting every occurrence
# would produce a file that passes its own check having mangled someone's
# documentation on the way.
rewrite_in_place "$FILE" awk -v ver="$VERSION" '
	!hdr && /^ \* Version:[ \t]+[^ \t]/ {
		sub( /[^ \t]+$/, ver )
		hdr = 1
	}

	!cst && /^define\( '\''SPOTSHIP_WI_VERSION'\'', '\''.*'\'' \);$/ {
		print "define( '\''SPOTSHIP_WI_VERSION'\'', '\''" ver "'\'' );"
		cst = 1
		next
	}

	{ print }
'

read_back "the Version: header" "$FILE" 's/^ \* Version:[[:space:]]*\(.*\)$/\1/p'
read_back "SPOTSHIP_WI_VERSION" "$FILE" "s/^define( 'SPOTSHIP_WI_VERSION', '\(.*\)' );\$/\1/p"

# THE THIRD PLACE A VERSION APPEARS.
#
# readme.txt carries `Stable tag:`, and nothing used to write it — so it sat at
# 0.1.0 while the plugin shipped 1.0.0, and the zip customers unpack contained a
# readme contradicting the plugin beside it.
#
# It tracks the plugin version exactly, prereleases included. On wordpress.org
# `Stable tag` means something more specific, but this plugin is licensed
# Proprietary and cannot be listed there, so the only useful property is that it
# never disagrees with the plugin beside it.
rewrite_in_place "$README" awk -v ver="$VERSION" '
	!st && /^Stable tag:[ \t]*[^ \t]/ {
		sub( /[^ \t]+$/, ver )
		st = 1
	}

	{ print }
'

read_back "'Stable tag'" "$README" 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p'

# AND THE CHANGELOG SECTION, WHICH DRIFTED THE SAME WAY AND FOR THE SAME REASON.
#
# `== Changelog ==` still said "0.1.0 — Initial release" when 1.0.0 shipped.
# Asserting that a section for the current version exists is only possible if
# something creates it: semantic-release computes the number during prepare, so
# no human can have written the heading in advance, and a release that aborted
# because the heading was missing would abort after the version had already been
# decided.
#
# THE ENTRY IS THE RELEASE NOTES THAT ARE BEING PUBLISHED ANYWAY.
#
# The prepareCmd in package.json writes ${nextRelease.notes} to NOTES with a
# QUOTED heredoc — `<<'SSWI_NOTES_EOF'` — so that nothing inside a commit
# subject is ever evaluated. Interpolating the notes straight into the command
# string instead would hand every backtick and `$(...)` in a commit message
# to /bin/sh.
#
# A FILE, NOT A COMMAND SUBSTITUTION. The obvious `X=$(cat <<'EOF' ...)` form
# does not parse in bash 3.2, which is still /bin/sh on macOS: an apostrophe
# inside the heredoc body aborts it with "unexpected EOF while looking for
# matching `'". A heredoc at the top level parses everywhere.
#
# Reduced to the bullets and their section labels: the markdown heading repeats
# the version that is already the heading above it, and the commit-sha links go
# nowhere useful in a plain-text file on a customer's server.
#
# Absent — any run that is not a release, ci.yaml's rehearsal included — it
# falls back to the heading and a link to the GitHub release, so the assertion
# in bin/assert-version.sh still has something true to find. An entry written by
# hand before the release is left alone either way.
if ! grep -q "^= ${VERSION} =$" "$README"; then
	SSWI_CHANGELOG_BODY=

	if [ -f "$NOTES" ]; then
		SSWI_CHANGELOG_BODY=$( awk '
			/^#+[ \t]/ {
				# The first heading is the version, which readme.txt is about to
				# state itself. Later ones are "Bug Fixes" and friends, worth
				# keeping as labels.
				if ( ++depth > 1 ) {
					sub( /^#+[ \t]+/, "" )
					print ""
					print $0
				}
				next
			}

			/^[*-][ \t]/ {
				# conventional-changelog appends ([abc1234](url)) to every
				# subject. A link to a commit is not changelog prose.
				gsub( /[ \t]*\(\[[0-9a-f]+\]\([^)]*\)\)[ \t]*$/, "" )
				sub( /^-[ \t]/, "* " )
				print
				next
			}
		' "$NOTES" )
	fi

	if [ -z "$SSWI_CHANGELOG_BODY" ]; then
		SSWI_CHANGELOG_BODY="* See https://github.com/spotship/wordpress-insurance/releases/tag/v${VERSION}"
	fi

	# Through the environment, not `awk -v`: -v applies backslash-escape
	# processing to the value, which would mangle any commit subject containing
	# one. ENVIRON hands the string over exactly as it is.
	export SSWI_CHANGELOG_BODY

	rewrite_in_place "$README" awk -v ver="$VERSION" '
		{ print }

		!seen && /^== Changelog ==$/ {
			print ""
			print "= " ver " ="
			print ENVIRON["SSWI_CHANGELOG_BODY"]
			seen = 1
		}
	'
fi

# Consumed. Leaving it would put an untracked file in front of
# bin/build-dist.sh's ship-list check in the same prepare run, and a stale copy
# in front of the next one.
rm -f "$NOTES"

# THE FOURTH PLACE, AND THE ONE NOBODY HAD NOTICED AT ALL.
#
# bin/build-dist.sh ships languages/, so the catalogue header goes to every
# customer too. `Project-Id-Version` was hardcoded in bin/refresh-pot.sh and
# still read 0.1.0 — the identical defect as `Stable tag`, one file over.
# refresh-pot.sh now interpolates it from the plugin header, so a regenerated
# catalogue agrees; this is what keeps a RELEASE agreeing without regenerating.
rewrite_in_place "$POT" awk -v ver="$VERSION" -v bs='\\' '
	!pid && /^"Project-Id-Version: Spot Ship War Insurance / {
		print "\"Project-Id-Version: Spot Ship War Insurance " ver bs "n\""
		pid = 1
		next
	}

	{ print }
'

read_back "'Project-Id-Version'" "$POT" 's/^"Project-Id-Version: Spot Ship War Insurance \(.*\)\\n"$/\1/p'

# The cross-file check, plus the tag check this script cannot do on its own.
bash bin/assert-version.sh

echo "set-version: $VERSION"
