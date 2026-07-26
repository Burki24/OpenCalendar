#!/usr/bin/env python3
"""Verify OpenCalendar uses DataFlowHelper for Symcon parent/child transport envelopes."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CALENDAR = (ROOT / "Kalender/module.php").read_text(encoding="utf-8")
CONFIGURATOR = (ROOT / "Kalender Konfigurator/module.php").read_text(encoding="utf-8")
ACCOUNT = (ROOT / "Kalender Konto/module.php").read_text(encoding="utf-8")
CHILD_GATEWAY = (ROOT / "Kalender Konto/traits/ChildGatewayTrait.php").read_text(encoding="utf-8")

for name, source in {
    "Kalender": CALENDAR,
    "Kalender Konfigurator": CONFIGURATOR,
    "Kalender Konto": ACCOUNT,
}.items():
    if "DataFlowHelper.php" not in source:
        raise SystemExit(f"{name} does not load DataFlowHelper.")
    if "use DataFlowHelper;" not in source:
        raise SystemExit(f"{name} does not use DataFlowHelper.")

required = {
    "Kalender": [
        "$this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_PARENT)",
        "$this->EncodeDataFlowMessage(self::DATA_ID_TO_PARENT, $request)",
    ],
    "Kalender Konfigurator": [
        "$this->EncodeDataFlowMessage(",
        "self::DATA_ID_TO_PARENT",
    ],
    "Kalender Konto": [
        "$this->EncodeDataFlowMessage(",
        "self::DATA_ID_TO_CHILD",
        "private const DATA_ID_FROM_CHILD",
    ],
    "ChildGatewayTrait": [
        "$this->DecodeDataFlowMessage($JSONString, self::DATA_ID_FROM_CHILD)",
    ],
}

sources = {
    "Kalender": CALENDAR,
    "Kalender Konfigurator": CONFIGURATOR,
    "Kalender Konto": ACCOUNT,
    "ChildGatewayTrait": CHILD_GATEWAY,
}
for name, needles in required.items():
    for needle in needles:
        if needle not in sources[name]:
            raise SystemExit(f"{name} is missing DataFlowHelper integration: {needle}")

for name, source in {"Kalender": CALENDAR, "ChildGatewayTrait": CHILD_GATEWAY}.items():
    if "json_decode($JSONString" in source:
        raise SystemExit(f"{name} still decodes Symcon data-flow JSON directly.")

for name, source in {
    "Kalender": CALENDAR,
    "Kalender Konfigurator": CONFIGURATOR,
    "Kalender Konto": ACCOUNT,
}.items():
    if "'DataID'" in source and "DATA_ID" in source:
        # Constants are expected; payload-owned DataID array entries are not.
        if "'DataID'    =>" in source or "'DataID'     =>" in source:
            raise SystemExit(f"{name} still injects DataID manually into a transport payload.")

print("DataFlowHelper integration verified.")
