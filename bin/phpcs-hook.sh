#!/usr/bin/env bash
#
# pre-commit hook wrapper for PHPCS.
#
# Same host-or-container resolution as Taskfile.yml: this repo has no host PHP
# requirement, so on a machine without `php` the sniffs run in a container.
#
# Deliberately NON-MUTATING. It runs phpcs (report), never phpcbf (rewrite): a
# hook that rewrites files mid-commit leaves the staged and working copies
# disagreeing, and the fix you get is not the fix you reviewed. Run
# `task lint-fix` yourself when you want the rewrite.

set -euo pipefail

# pre-commit invokes the hook with no arguments when nothing matched the `files`
# pattern. Nothing to lint is a pass, not an error.
if [ "$#" -eq 0 ]; then
	exit 0
fi

if [ ! -f vendor/bin/phpcs ]; then
	echo "bin/phpcs-hook.sh: vendor/bin/phpcs is missing. Run 'task init' first." >&2
	exit 1
fi

if command -v php >/dev/null 2>&1; then
	exec php vendor/bin/phpcs "$@"
fi

if ! command -v docker >/dev/null 2>&1; then
	echo "bin/phpcs-hook.sh: neither a host 'php' nor 'docker' is available." >&2
	echo "Install PHP 8.1+, or start Docker so the container fallback can run." >&2
	exit 1
fi

# pre-commit passes paths relative to the repo root, and the bind mount makes the
# repo root the container's working directory, so they resolve unchanged.
exec docker run --rm -v "$PWD":/app -w /app php:8.1-cli php vendor/bin/phpcs "$@"
