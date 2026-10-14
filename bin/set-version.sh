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
# EVERY RELEASE-DERIVED VALUE bin/assert-version.sh COMPARES IS WRITTEN HERE.
#
# That is not tidiness, it is the difference between a failed check and a failed
# release. This script's last act is to call that one, from inside
# semantic-release's `prepare` — so a value that varies with the release, which
# that script asserts and this one does not write, aborts the release AFTER the
# number has been computed and four values across three files already
# rewritten. `Tested up to` was exactly that: asserted against .wp-env.json's
# core pin, written by nobody, so bumping wp-env would have taken a release
# down mid-prepare. It is written here now, like the rest.
#
# THE SUPPORT FLOORS ARE THE DELIBERATE EXCEPTION. assert-version.sh also
# compares `Requires at least` and `Requires PHP` across the plugin header and
# the readme, composer.json's php constraint and config.platform.php, and
# phpcs.xml.dist's testVersion and minimum_wp_version. Nothing here writes any
# of them, and nothing should: they are a support policy a human decides, not
# an output of a version bump, and a release never changes them.
#
# The rule for anything added later is the line those two cases divide on: if
# it varies with the release, write it here; if it is a hand-maintained
# invariant, leave it to be asserted.
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
WP_ENV=.wp-env.json
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

for required in "$FILE" "$README" "$POT" "$WP_ENV"; do
	if [ ! -f "$required" ]; then
		echo "set-version: $required not found — run from the repository root" >&2
		exit 1
	fi
done

# THE PRODUCT NAME IS READ, NOT WRITTEN DOWN AGAIN.
#
# `Spot Ship War Insurance` used to be a literal in this script twice, in
# bin/assert-version.sh and in bin/refresh-pot.sh — four copies of a string that
# only ever has one correct value. A rename would have left the catalogue awk
# below matching nothing and the read-back reporting an empty version, which
# names the wrong cause entirely. The plugin header already states it.
PLUGIN_NAME=$( sed -n 's/^ \* Plugin Name:[[:space:]]*\(.*\)$/\1/p' "$FILE" | head -1 | sed 's/[[:space:]]*$//' )

if [ -z "$PLUGIN_NAME" ]; then
	echo "set-version: could not read the Plugin Name: header from $FILE" >&2
	exit 1
fi

# `Tested up to` IS A CLAIM ABOUT WHAT THE SUITES RAN AGAINST, so it comes from
# the pin rather than from memory. It was hand-corrected 6.7 -> 6.8 with nothing
# writing it, which is the same shape of defect as `Stable tag`.
TESTED=$( sed -n 's/.*"core"[[:space:]]*:[[:space:]]*"[^"]*#\([0-9][^"]*\)".*/\1/p' "$WP_ENV" | head -1 )

if [ -z "$TESTED" ]; then
	echo "set-version: $WP_ENV does not pin a numbered core (\"core\": \"WordPress/WordPress#6.8\")" >&2
	echo "set-version: readme.txt's 'Tested up to' is a numeric claim, so there has to be a number behind it." >&2
	exit 1
fi

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
#
# `*.set-version.tmp` is in .gitignore and .distignore: the trap above does not
# run on SIGKILL or a cancelled runner, and a stray one left at the repo root
# fails bin/build-dist.sh's ship-list check on the next build, naming a file
# nobody wrote on purpose.
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
	local what=$1 file=$2 expr=$3 want=${4:-$VERSION}
	local got

	got=$( sed -n "$expr" "$file" | head -1 | tr -d '[:space:]' )

	if [ "$got" != "$want" ]; then
		echo "set-version: asked for '$want' but $what in $file says '$got'" >&2
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

# THE RELEASE NOTES THAT ARE BEING PUBLISHED ANYWAY ARE THE CHANGELOG ENTRY.
#
# `== Changelog ==` still said "0.1.0 — Initial release" when 1.0.0 shipped.
# Asserting that a section for the current version exists is only possible if
# something creates it: semantic-release computes the number during prepare, so
# no human can have written the heading in advance, and a release that aborted
# because the heading was missing would abort after the version had already been
# decided.
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
# the version that is already the heading above it, and the links go nowhere
# useful in a plain-text file on a customer's server.
#
# Absent — any run that is not a release, ci.yaml's rehearsal included — it
# falls back to the heading and a link to the GitHub release, so the assertion
# in bin/assert-version.sh still has something true to find. An entry written by
# hand before the release is left alone either way.
SSWI_CHANGELOG_BODY=
HAVE_SECTION=0

if grep -q "^= ${VERSION} =$" "$README"; then
	HAVE_SECTION=1
elif [ -f "$NOTES" ]; then
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
			# EVERY LINK GOES, NOT JUST A TRAILING COMMIT ONE.
			#
			# conventional-changelog decorates a subject with up to three
			# kinds of link, and the first version of this stripped only the
			# last of them — anchored to end-of-line and matching a hex sha —
			# so against the notes this repo actually produces it removed
			# nothing at all. v1.0.0 published
			#
			#   ... ([#5](…/issues/5)) ([2c18c5e](…/commit/2c18c5e…)), closes
			#   [hi#severity](https://github.com/hi/issues/severity)
			#
			# where the `closes` tail sits after the sha group, so the
			# end-anchor never matched, and the `#5` group is not a sha. A
			# plain-text readme would have shipped two 60-character URLs and
			# one known-broken link to every customer.
			#
			# The `closes` refs first, because they are bare `[x](y)` rather
			# than parenthesised and always run to end of line.
			sub( /,[ \t]*closes[ \t]+\[[^][]*\]\([^()]*\).*$/, "" )

			# Then every `([text](url))` group, wherever it sits.
			gsub( /[ \t]*\(\[[^][]*\]\([^()]*\)\)/, "" )

			sub( /[ \t]+$/, "" )
			sub( /^-[ \t]/, "* " )
			print
			next
		}
	' "$NOTES" )
fi

if [ "$HAVE_SECTION" = 0 ] && [ -z "$SSWI_CHANGELOG_BODY" ]; then
	SSWI_CHANGELOG_BODY="* See https://github.com/spotship/wordpress-insurance/releases/tag/v${VERSION}"
fi

# Through the environment, not `awk -v`: -v applies backslash-escape processing
# to the value, which would mangle any commit subject containing one. ENVIRON
# hands the string over exactly as it is.
export SSWI_CHANGELOG_BODY

# ONE PASS OVER readme.txt, NOT THREE.
#
# `Stable tag`, `Tested up to` and the changelog section used to be separate
# rewrites of the same file, each copying it whole. Beyond the wasted work that
# tripled the window in which a crash leaves readme.txt half-written — the tag
# bumped, no entry under it — and tripled the number of places the atomic-rename
# reasoning had to hold.
#
# NO INTERNAL BUILD SECTION SURVIVES EXCEPT THE ONE BEING WRITTEN.
#
# `dev` is a prerelease branch, so every push to it runs a real release:
# 1.1.0-dev.1, -dev.2, -dev.3, each one landing here and each one committed back
# by @semantic-release/git. Appending would put one section per internal build
# into the readme a customer unpacks — the same bullets three and four times
# over under numbers that mean nothing outside this repo — and nothing would
# ever remove them.
#
# THE TEST IS "IS IT A PRERELEASE", NOT "DOES IT SHARE THIS VERSION'S BASE".
#
# Anchoring on the base left two holes, and the first of them was live on `dev`:
#
#   * A prerelease of an EARLIER base was invisible. `= 1.0.1-dev.1 =` sat in
#     readme.txt while the next release was a minor bump, so `base` was 1.1.0
#     and nothing matched it — one orphaned internal build per minor, kept
#     forever.
#   * A section that already existed turned the scan off entirely, because the
#     same `have` flag gated both the insert and the drop. Hand-writing the
#     entry — which the comment above this one invites, and which is how the
#     1.0.0 entry was written — left every -dev.N stacked underneath it.
#
# The changelog is newest-first and set-version.sh only ever moves forward, so
# every prerelease section below the insertion point is one this release
# supersedes. Dropping all of them needs no version comparison and states the
# invariant directly: a `-dev.N` heading never reaches a customer. Released
# versions are never touched, and `ver` itself is kept so re-running the same
# prerelease is still idempotent.
rewrite_in_place "$README" awk \
	-v ver="$VERSION" \
	-v tested="$TESTED" \
	-v have="$HAVE_SECTION" '
	function internal( line,    v ) {
		# "= X =" -> X. A semver prerelease carries a `-` suffix; a release
		# does not. The section this run is writing is not its own predecessor.
		v = substr( line, 3, length( line ) - 4 )
		return index( v, "-" ) > 0 && v != ver
	}

	!st && /^Stable tag:[ \t]*[^ \t]/ {
		sub( /[^ \t]+$/, ver )
		st = 1
		print
		next
	}

	!tu && /^Tested up to:[ \t]*[^ \t]/ {
		sub( /[^ \t]+$/, tested )
		tu = 1
		print
		next
	}

	/^== / {
		# Any other section heading ends the changelog, so the prune scan
		# below cannot run off the end of it.
		inlog = ( $0 == "== Changelog ==" )
		drop = 0
		print

		if ( inlog && ! seen ) {
			seen = 1

			if ( have != "1" ) {
				print ""
				print "= " ver " ="
				print ENVIRON["SSWI_CHANGELOG_BODY"]
			}
		}

		next
	}

	# NOT GATED ON `have`. That flag says whether this run writes a heading; it
	# says nothing about whether the internal builds below it should go, and
	# conflating the two is what let a hand-written entry keep all of them.
	inlog && /^= .* =$/ {
		drop = internal( $0 )
	}

	{ if ( ! drop ) print }
'

read_back "'Stable tag'" "$README" 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p'
read_back "'Tested up to'" "$README" 's/^Tested up to:[[:space:]]*\(.*\)$/\1/p' "$TESTED"

# READ BACK LOCALLY, LIKE EVERY OTHER WRITE.
#
# This was the one rewrite covered only transitively, by assert-version.sh's
# grep further down the pipeline — which is the arrangement the comment above
# read_back() exists to argue against. A readme that lost its literal
# `== Changelog ==` heading would have had nothing inserted, silently.
if ! grep -q "^= ${VERSION} =$" "$README"; then
	echo "set-version: no '= $VERSION =' section in $README after writing it" >&2
	echo "set-version: the insert is anchored to a literal '== Changelog ==' heading; $README has none." >&2
	exit 1
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
rewrite_in_place "$POT" awk -v ver="$VERSION" -v name="$PLUGIN_NAME" -v bs='\\' '
	!pid && index( $0, "\"Project-Id-Version: " name " " ) == 1 {
		print "\"Project-Id-Version: " name " " ver bs "n\""
		pid = 1
		next
	}

	{ print }
'

read_back "'Project-Id-Version'" "$POT" \
	"s/^\"Project-Id-Version: ${PLUGIN_NAME} \(.*\)\\\\n\"\$/\1/p"

# The cross-file check, plus the tag check this script cannot do on its own.
bash bin/assert-version.sh

echo "set-version: $VERSION"
