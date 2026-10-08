#!/usr/bin/env python3
"""Syntax-check every `run:` block in .github/workflows.

WHY THIS EXISTS. A workflow's shell is never parsed until the workflow runs,
so a `run:` block in a manually dispatched job is unverified code that reports
nothing. staging-e2e.yaml shipped with an unmatched quote in its evidence step
-- three `"` on one line -- which made the enclosing `{ ... }` block a syntax
error. Thirteen green CI jobs had no opinion about it, because none of them
parse that file. It was found by reading, which does not scale.

Not a linter. It answers exactly one question: would bash refuse to parse this?

GitHub expressions are substituted before the shell ever sees the script, and
`${{ ... }}` is not valid bash, so each one is replaced with a placeholder
first. The placeholder is quoted-string-safe and contains no metacharacters,
so it cannot mask or manufacture a quoting error.
"""

import pathlib
import re
import subprocess
import sys

import yaml

EXPRESSION = re.compile(r"\$\{\{.*?\}\}", re.DOTALL)
PLACEHOLDER = "GHA_EXPRESSION"


def run_blocks(document):
    """Yield (job, step index, step name, script) for every `run:` in a workflow."""
    for job_name, job in (document.get("jobs") or {}).items():
        for index, step in enumerate(job.get("steps") or []):
            if isinstance(step, dict) and isinstance(step.get("run"), str):
                yield job_name, index, step.get("name", f"step {index}"), step["run"]


def main() -> int:
    workflows = sorted(pathlib.Path(".github/workflows").glob("*.y*ml"))
    if not workflows:
        print("check-workflow-shell: no workflows found", file=sys.stderr)
        return 1

    failures = 0
    checked = 0

    for path in workflows:
        try:
            document = yaml.safe_load(path.read_text())
        except yaml.YAMLError as error:
            print(f"{path}: not valid YAML: {error}", file=sys.stderr)
            failures += 1
            continue

        for job_name, _index, step_name, script in run_blocks(document):
            # `shell:` may select something other than bash; those are the
            # caller's problem, and this check does not guess at them.
            checked += 1
            result = subprocess.run(
                ["bash", "-n"],
                input=EXPRESSION.sub(PLACEHOLDER, script),
                capture_output=True,
                text=True,
                check=False,
            )
            if result.returncode != 0:
                failures += 1
                print(
                    f"{path}: job '{job_name}', step '{step_name}':\n"
                    f"{result.stderr.strip()}",
                    file=sys.stderr,
                )

    print(f"check-workflow-shell: {checked} run block(s) checked, {failures} bad")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
