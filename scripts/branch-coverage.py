#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
# Copyright (c) 2026 Netresearch DTT GmbH
"""Report branch coverage from a Cobertura report.

WHY THIS EXISTS
===============
Codecov shows `branches: 0` for this project and always will. Not because
branch coverage is off — `phpunit.xml.dist` sets `branchCoverage="true"`, the
nightly runs Xdebug, and a real run produces `branches-valid="8463"`. The
reason is the report format as php-code-coverage 14.3.3 writes it: its
`<line>` elements carry only `number` and `hits`, never `branch="true"` or
`condition-coverage`, and per-line attributes are where Codecov reads branch
data from. Its Clover writer is no better — every line is `type="stmt"`, never
`"cond"`.

The numbers are in the report, just one level up: on `coverage`, `package`,
`class` and `method` as `branch-rate`, `branches-valid` and
`branches-covered`. This reads them there.

NOT ADDITIVE ACROSS SUITES
==========================
Each report is printed on its own line and no total is computed. Two suites
that both touch a file each count that file's branches, so adding the reports
would count those branches twice and divide by a denominator that is equally
inflated. The per-suite figures are the honest ones; a project-wide number
needs the reports merged before this step, not their totals added after it.
"""

from __future__ import annotations

import argparse
import os
import sys
import xml.etree.ElementTree as ET


def read(path: str) -> tuple[int, int]:
    """Return (covered, valid) branches from a Cobertura file.

    The report is produced by our own PHPUnit run in the same job, so it is
    not attacker-controlled — but this script is short enough that saying so
    is not a reason to parse carelessly, and a developer will run it over a
    downloaded artifact sooner or later. A DOCTYPE is what both entity
    expansion and the billion-laughs shape need, and a Cobertura report from
    php-code-coverage has none, so refusing one costs nothing here and closes
    both without pulling defusedxml into a PHP project's CI.
    """
    with open(path, "rb") as handle:
        head = handle.read(4096)
    if b"<!DOCTYPE" in head or b"<!ENTITY" in head:
        raise ValueError(
            f"{path}: carries a DOCTYPE or entity declaration; a Cobertura "
            "report written by php-code-coverage does not, so this file is "
            "not what it claims to be"
        )

    root = ET.parse(path).getroot()
    covered = root.get("branches-covered")
    valid = root.get("branches-valid")
    if covered is None or valid is None:
        raise ValueError(
            f"{path}: no branches-covered/branches-valid on the root element — "
            "this is not a Cobertura report, or it was written by a tool that "
            "omits them"
        )
    return int(covered), int(valid)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("reports", nargs="+", help="Cobertura XML files")
    parser.add_argument(
        "--summary",
        action="store_true",
        help="also append a table to $GITHUB_STEP_SUMMARY when that is set",
    )
    args = parser.parse_args()

    rows: list[tuple[str, int, int, float]] = []
    missing: list[str] = []
    for path in args.reports:
        if not os.path.isfile(path):
            missing.append(path)
            continue
        try:
            covered, valid = read(path)
        except (ValueError, ET.ParseError) as error:
            # A traceback in a CI log buries the one line that says what is
            # wrong with the file.
            print(f"error: {error}", file=sys.stderr)
            return 1
        # A report with no executable branches is a fact worth printing, not a
        # division to guess at.
        rate = (covered / valid * 100) if valid else 0.0
        rows.append((path, covered, valid, rate))

    for path, covered, valid, rate in rows:
        print(f"{path}: {covered}/{valid} branches = {rate:.2f}%")
    for path in missing:
        print(f"{path}: not found", file=sys.stderr)

    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if args.summary and summary and rows:
        with open(summary, "a", encoding="utf-8") as handle:
            handle.write("### Branch coverage\n\n")
            handle.write("| Report | Covered | Executable | Rate |\n")
            handle.write("|---|---:|---:|---:|\n")
            for path, covered, valid, rate in rows:
                handle.write(f"| `{path}` | {covered} | {valid} | {rate:.2f}% |\n")
            handle.write(
                "\nRead from the Cobertura root element. Codecov reports 0 "
                "branches for this project because php-code-coverage writes "
                "branch data only in aggregate, never per line — see "
                "`scripts/branch-coverage.py`. Suites are listed separately "
                "because their branch counts overlap and cannot be added.\n"
            )

    # Nothing to report is a failure of this step, not a pass: it means the
    # coverage run did not produce what the caller said it would.
    if not rows:
        print("no readable Cobertura report among the given paths", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
