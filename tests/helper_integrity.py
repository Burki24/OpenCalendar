#!/usr/bin/env python3
"""Verify vendored helper files against the local helper manifest and subscription config."""

from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = json.loads((ROOT / ".helper-sync.json").read_text(encoding="utf-8"))
MANIFEST = json.loads((ROOT / "libs/helper/manifest.json").read_text(encoding="utf-8"))
VERSION_PATTERN = re.compile(r"@version\s+([0-9]+\.[0-9]+\.[0-9]+)")

subscriptions = CONFIG.get("helpers", {})
entries = MANIFEST.get("helpers", {})
if set(subscriptions) != set(entries):
    raise SystemExit(f"Helper subscription/manifest mismatch: subscriptions={sorted(subscriptions)}, manifest={sorted(entries)}")

for name, subscription in sorted(subscriptions.items()):
    meta = entries[name]
    target = str(subscription["target"])
    if meta.get("path") != target:
        raise SystemExit(f"Manifest path mismatch for {name}: {meta.get('path')} != {target}")
    path = ROOT / target
    if not path.is_file():
        raise SystemExit(f"Missing vendored helper: {target}")
    source = path.read_text(encoding="utf-8")
    match = VERSION_PATTERN.search(source)
    if match is None or match.group(1) != meta.get("version"):
        raise SystemExit(f"Version mismatch for {name}: source={match.group(1) if match else None}, manifest={meta.get('version')}")
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    if digest != meta.get("sha256"):
        raise SystemExit(f"SHA-256 mismatch for {name}: source={digest}, manifest={meta.get('sha256')}")

print(f"Vendored helper integrity verified ({len(entries)} helpers).")
