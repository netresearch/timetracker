#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Netresearch DTT GmbH
"""Fail the build on Semgrep findings that the policy says must block.

The policy is in `docs/vulnerability-management.md`: an ERROR blocks the
merge, a WARNING is triaged within 30 days. Semgrep's own `--error` cannot
express that — it fails on any finding at any severity — and `--severity`
narrows what is SCANNED rather than what blocks, which would quietly stop
warnings reaching code scanning. So the gate reads the SARIF the scan already
produced.

Run after the SARIF upload, never before: a finding that turns the build red
has to remain visible in code scanning afterwards, or the only record of why
is a log that expires.
"""

from __future__ import annotations

import argparse
import collections
import json
import os
import sys

BLOCKING = "error"


def level_of(result: dict, rules: dict[str, str]) -> str:
    """The effective level of one result.

    A SARIF result may omit `level`, in which case the rule's
    `defaultConfiguration.level` applies; Semgrep relies on that for most
    findings, so reading only the result would score them all as unset.
    SARIF's own default when neither is present is `warning`.
    """
    if result.get("level"):
        return str(result["level"])
    rule_id = result.get("ruleId")
    return rules.get(str(rule_id), "warning")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("sarif", help="SARIF file written by the scan")
    args = parser.parse_args()

    # The gate reads the SARIF its own scan just wrote, which is always inside
    # the workspace. Requiring that is a true invariant rather than a
    # concession to a scanner: a gate pointed outside the tree it is gating is
    # a gate reading somebody else's findings. The sibling script
    # branch-coverage.py deliberately does NOT have this restriction — a
    # coverage report from another worktree is a legitimate thing to inspect.
    try:
        path = os.path.realpath(args.sarif, strict=True)
    except OSError as error:
        print(f"error: {args.sarif}: {error}", file=sys.stderr)
        return 1
    workspace = os.path.realpath(os.getcwd())
    if os.path.commonpath([path, workspace]) != workspace:
        print(
            f"error: {args.sarif} resolves to {path}, outside the workspace "
            f"{workspace}; the gate reads the report of its own run",
            file=sys.stderr,
        )
        return 1

    try:
        with open(path, encoding="utf-8") as handle:
            report = json.load(handle)
    except (OSError, json.JSONDecodeError) as error:
        print(f"error: {args.sarif}: {error}", file=sys.stderr)
        return 1

    counts: collections.Counter[str] = collections.Counter()
    blocking: list[tuple[str, str, str]] = []

    for run in report.get("runs", []):
        driver = (run.get("tool") or {}).get("driver") or {}
        rules = {
            str(rule.get("id")): str(
                (rule.get("defaultConfiguration") or {}).get("level", "warning")
            )
            for rule in driver.get("rules", [])
        }
        for result in run.get("results", []):
            level = level_of(result, rules)
            counts[level] += 1
            if level != BLOCKING:
                continue
            locations = result.get("locations") or [{}]
            physical = locations[0].get("physicalLocation") or {}
            artifact = (physical.get("artifactLocation") or {}).get("uri", "?")
            line = (physical.get("region") or {}).get("startLine", "?")
            message = ((result.get("message") or {}).get("text") or "").strip()
            blocking.append((f"{artifact}:{line}", str(result.get("ruleId")), message))

    total = sum(counts.values())
    print(
        f"semgrep findings: {total} " + (dict(counts).__repr__() if total else "(none)")
    )

    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if summary:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write("### Semgrep\n\n")
            if not total:
                handle.write("No findings.\n")
            else:
                handle.write("| Level | Count |\n|---|---:|\n")
                for level, count in sorted(counts.items()):
                    handle.write(f"| {level} | {count} |\n")
                handle.write(
                    "\n`error` blocks the merge; `warning` is triaged within 30 "
                    "days — see `docs/vulnerability-management.md`.\n"
                )

    if not blocking:
        return 0

    print(
        f"\n{len(blocking)} finding(s) at {BLOCKING} severity block this build:",
        file=sys.stderr,
    )
    for where, rule_id, message in blocking:
        print(f"  {where}  {rule_id}", file=sys.stderr)
        if message:
            print(f"    {message}", file=sys.stderr)
        # An annotation puts it on the diff, where the author is looking.
        print(f"::error file={where.rsplit(':', 1)[0]}::{rule_id}: {message}")
    print(
        "\nFix it, or if it is not exploitable here, add "
        "`# nosemgrep: <rule-id>` with the reason on the line it flags — the "
        "reason belongs in the code, not in the scanner configuration.",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())
