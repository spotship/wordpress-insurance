#!/usr/bin/env bash
#
# Collect a CI run's results into a readable evidence file.
#
# The pipeline's task directory otherwise holds only planning documents, so a
# claim like "all gates green" has nothing behind it that a reviewer can check
# weeks later. This writes the per-job conclusions and the suite totals, with
# the run URL, so the claim and its evidence travel together.
#
# Usage: bin/ci-evidence.sh <run-id> [output-file]

set -euo pipefail

RUN="${1:?usage: ci-evidence.sh <run-id> [output-file]}"
OUT="${2:-ci-evidence.md}"
REPO="spotship/wordpress-insurance"

meta=$(gh run view "$RUN" --repo "$REPO" --json headSha,conclusion,createdAt,url)
sha=$(jq -r .headSha <<<"$meta")
conclusion=$(jq -r .conclusion <<<"$meta")
created=$(jq -r .createdAt <<<"$meta")
url=$(jq -r .url <<<"$meta")

{
	echo "# CI evidence"
	echo
	echo "| | |"
	echo "|---|---|"
	echo "| Run | $url |"
	echo "| Commit | \`$sha\` |"
	echo "| Started | $created |"
	echo "| Result | **$conclusion** |"
	echo
	echo "Collected by \`bin/ci-evidence.sh\` — re-run it against a newer run id"
	echo "rather than editing the numbers below by hand."
	echo
	echo "## Jobs"
	echo
	echo "| Result | Job |"
	echo "|---|---|"

	gh run view "$RUN" --repo "$REPO" --json jobs \
		--jq '.jobs[] | "| \(.conclusion) | \(.name) |"'

	echo
	echo "## Suite totals"
	echo

	for job in "Playwright + axe" "Front-end (jsdom)"; do
		# gh's own --jq takes no --arg, so pipe to jq for the parameterised query.
		id=$(gh run view "$RUN" --repo "$REPO" --json jobs \
			| jq -r --arg n "$job" '.jobs[] | select(.name==$n) | .databaseId')

		[ -n "$id" ] || continue

		echo "### $job"
		echo
		echo '```'
		gh api "repos/$REPO/actions/jobs/$id/logs" 2>/dev/null \
			| grep -oE '[0-9]+ (passed|failed|skipped)( \([0-9.]+[ms]+\))?|all [0-9]+ mutations detected|# (tests|pass|fail) [0-9]+' \
			| sort -u || echo '(no totals found in log)'
		echo '```'
		echo
	done
} > "$OUT"

echo "wrote $OUT"
