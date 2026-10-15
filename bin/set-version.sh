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

# Does `== Changelog ==` already hold a section for this release?
#
# BOUNDED TO THAT SECTION, AND THE UNBOUNDED VERSION WAS A REAL FAILURE. Both
# callers used to grep the WHOLE readme. A WordPress readme's
# `== Upgrade Notice ==` conventionally files its entries under headings that
# are release versions, so one of those matched: the heading below was never
# inserted, the verification further down was satisfied by the Upgrade Notice
# heading, assert-version.sh agreed, and the release shipped with no changelog
# entry at all — silently, which is what that verification exists to prevent.
#
# An exact string comparison rather than a pattern, so a version is never read
# as a regular expression.
changelog_has_section() {
	awk -v ver="$VERSION" '
		/^== / { inlog = ( $0 == "== Changelog ==" ); next }
		inlog && $0 == "= " ver " =" { found = 1 }
		END { if ( found ) exit 0; exit 1 }
	' "$README"
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
# Absent on a RELEASE — ci.yaml's rehearsal, say — it falls back to the heading
# and a link to the GitHub release, so the assertion in bin/assert-version.sh
# still has something true to find. On a prerelease nothing falls back, because
# nothing is written and nothing is asserted about a section. An entry written
# by hand before the release is left alone in both cases.
#
# A PRERELEASE WRITES NO CHANGELOG SECTION AT ALL, AND THAT DELETES A BUG CLASS.
#
# `dev` is a prerelease branch, so every push to it runs a real release:
# 1.1.0-dev.1, -dev.2, -dev.3, each one landing here. Every one of those used to
# write its own `= X =` section, and the readme a customer unpacks would then
# carry one section per internal build — the same bullets three and four times
# over under numbers that mean nothing outside this repo.
#
# The previous answer was to write them and then prune them again on the next
# run. That prune had to know which headings were internal builds, which meant a
# predicate over human-written text. Two of those shipped, and both were wrong:
# one matched only prereleases of the release's own base, so an older one
# survived a minor bump; the other was switched off entirely by a hand-written
# entry. The rule they kept breaking is the reason there is no third attempt: a
# heading is prose, and `= 1.0.2 - 2026-10-14 =` is a RELEASED section that any
# predicate keying on `-` calls an internal build and deletes.
#
# None of those bugs are reachable if the section is never written. The notes a
# customer should read are the ones semantic-release generates for the real
# release on `main`, which aggregate every commit since the last one — so
# nothing is lost by staying quiet here, and `= 1.1.0 =` lands complete.
#
# THE `-` TEST IS SAFE ON THIS STRING AND WAS NOT SAFE ON A HEADING. $VERSION is
# semantic-release's own output: valid semver, where a `-` means exactly one
# thing. A changelog heading is prose someone typed, where it does not.
case "$VERSION" in
	*-* ) PRERELEASE=1 ;;
	*   ) PRERELEASE=0 ;;
esac

# ONE FLAG, ONE MEANING. `WRITE_SECTION` says whether the awk below inserts a
# heading, and nothing else reads it. Its predecessor, `HAVE_SECTION`, was asked
# to mean "the readme already has this section" AND to gate the prune, and
# conflating those is how a hand-written entry switched the pruning off.
SSWI_CHANGELOG_BODY=
WRITE_SECTION=0

if [ "$PRERELEASE" = 0 ] && ! changelog_has_section; then
	WRITE_SECTION=1
fi

if [ "$WRITE_SECTION" = 1 ] && [ -f "$NOTES" ]; then
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

if [ "$WRITE_SECTION" = 1 ] && [ -z "$SSWI_CHANGELOG_BODY" ]; then
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
# NO INTERNAL BUILD SECTION IS EVER WRITTEN, SO NONE HAS TO BE PRUNED.
#
# This used to carry an `internal()` predicate and a drop rule: every prerelease
# section below the insertion point was deleted on the way past, because `dev`
# writes one per push and nothing else would ever remove them.
#
# That predicate had to classify human-written headings, and the two that
# shipped were both wrong — one missed a prerelease of an earlier base, the
# other was switched off entirely by a hand-written entry. Neither is reachable
# now, and neither is anything else in their class: a prerelease does not write
# a section, so there is nothing below to clean up. See the WRITE_SECTION
# reasoning above.
#
# What is left here is the insert, gated on `write`, and the two header lines.
rewrite_in_place "$README" awk \
	-v ver="$VERSION" \
	-v tested="$TESTED" \
	-v write="$WRITE_SECTION" '
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

	/^== Changelog ==$/ {
		print

		# Newest first, so the insert goes immediately under the heading. `seen`
		# because a readme may legally say "== Changelog ==" more than once and
		# only the first is the real one.
		if ( ! seen ) {
			seen = 1

			if ( write == "1" ) {
				print ""
				print "= " ver " ="
				print ENVIRON["SSWI_CHANGELOG_BODY"]
			}
		}

		next
	}

	{ print }
'

read_back "'Stable tag'" "$README" 's/^Stable tag:[[:space:]]*\(.*\)$/\1/p'
read_back "'Tested up to'" "$README" 's/^Tested up to:[[:space:]]*\(.*\)$/\1/p' "$TESTED"

# READ BACK LOCALLY, LIKE EVERY OTHER WRITE.
#
# This was the one rewrite covered only transitively, by assert-version.sh's
# grep further down the pipeline — which is the arrangement the comment above
# read_back() exists to argue against. A readme that lost its literal
# `== Changelog ==` heading would have had nothing inserted, silently.
#
# IT LOOKS INSIDE THE CHANGELOG, not anywhere in the file. A heading elsewhere —
# `== Upgrade Notice ==` files its entries under release versions — would
# otherwise satisfy this check for a section that was never written.
#
# BOTH DIRECTIONS ARE ASSERTED. For a release the section must be there; for a
# prerelease it must NOT, which is the invariant that replaced the prune. An
# absent assertion is worth as much as a present one here: a prerelease section
# that somehow got written is exactly what used to reach a customer.
if [ "$PRERELEASE" = 1 ]; then
	if changelog_has_section; then
		echo "set-version: $README carries a '= $VERSION =' section under '== Changelog =='" >&2
		echo "set-version: this run wrote none, so it was already there; a prerelease must ship none." >&2
		exit 1
	fi
elif ! changelog_has_section; then
	echo "set-version: no '= $VERSION =' section under '== Changelog ==' in $README after writing it" >&2
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
