#!/usr/bin/env python3
"""Regenerates docs/documentation-contract.json.

The contract is the machine-checked description of the maintainer handoff set:
which documents exist, which prerequisites they must keep, which documented
steps are bound to source files by SHA-256 plus a review date, and which
production settings, collections, roles, recurring tasks, update paths and
recovery actions must appear in at least one document.

This script is the only supported way to refresh the contract. It re-reads the
existing contract, re-hashes every declared freshness source, verifies that
every coverage item's declared source still contains the asserted token, and
writes the contract back with updated hashes and review dates. A fabricated or
drifted coverage item fails generation instead of being recorded.

Usage:
    python3 tests/docs/build-documentation-contract.py [--now=YYYY-MM-DD]

Exit codes: 0 written, 1 verification failure, 2 usage error.
"""

import hashlib
import json
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "docs" / "documentation-contract.json"
GENERATED_BY = "tests/docs/build-documentation-contract.py"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def fail(message: str) -> "None":
    print(f"build-documentation-contract: {message}", file=sys.stderr)
    sys.exit(1)


def main() -> int:
    today = date.today().isoformat()
    for arg in sys.argv[1:]:
        if arg.startswith("--now="):
            today = arg.split("=", 1)[1]
        else:
            print(f"usage: {sys.argv[0]} [--now=YYYY-MM-DD]", file=sys.stderr)
            return 2

    if not CONTRACT.is_file():
        fail(f"{CONTRACT} does not exist; the contract is authored, not invented")

    contract = json.loads(CONTRACT.read_text(encoding="utf-8"))
    if contract.get("schemaVersion") != 1:
        fail("contract schemaVersion must be 1")

    # Every declared document must exist; a missing document is a generation
    # failure, not a silent drop.
    for doc in contract.get("documentSet", []) + contract.get("referenceDocuments", []):
        if not (ROOT / doc).is_file():
            fail(f"declared document {doc} does not exist")

    # Command surfaces must exist so the checker can resolve documented commands.
    surfaces = contract.get("commandSurfaces", {})
    for key, path in surfaces.items():
        if not (ROOT / path).exists():
            fail(f"command surface {key}={path} does not exist")
    if not (ROOT / contract.get("policySource", "")).is_file():
        fail(f"policy source {contract['policySource']} does not exist")

    # Freshness: re-hash each declared source and stamp today's review date.
    # The reviewer must have re-read the source before running this script;
    # stamping a hash without review is falsifying a review.
    for entry in contract.get("freshness", []):
        for source in entry.get("sources", []):
            path = ROOT / source["path"]
            if not path.is_file():
                fail(f"freshness source {source['path']} for {entry['id']} is missing")
            source["sha256"] = sha256(path)
        entry["reviewedAt"] = today

    # Coverage: verify every item's declared source still contains the token it
    # claims to prove. An unverifiable item must not be documented as fact.
    for item in contract.get("coverage", []):
        source = item.get("source", {})
        path = ROOT / source.get("file", "")
        if not path.is_file():
            fail(f"coverage item {item['id']} declares missing source {source.get('file')}")
        if source.get("mustContain", "") not in path.read_text(encoding="utf-8"):
            fail(
                f"coverage item {item['id']} declares {source['file']} containing "
                f"{source['mustContain']!r}, which is absent"
            )

    contract["generatedBy"] = GENERATED_BY
    CONTRACT.write_text(json.dumps(contract, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {CONTRACT.relative_to(ROOT)} ({today})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
