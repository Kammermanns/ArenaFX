#!/usr/bin/env bash
# Startet die echte App (Debug-Build) unter Xvfb und steuert sie mit tauri-driver.
# Voraussetzungen (Linux): webkit2gtk-driver, xvfb, tauri-driver (cargo install tauri-driver), python3-selenium.
set -u
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${E2E_WORK:-$(mktemp -d)}"
mkdir -p "$WORK"
APP="$ROOT/src-tauri/target/debug/arenafx"
[ -x "$APP" ] || (cd "$ROOT/src-tauri" && cargo build) || exit 1

Xvfb :99 -screen 0 1400x1000x24 >/dev/null 2>&1 &
XVFB=$!
export DISPLAY=:99 ARENAFX_DATA_DIR="$WORK/data" ARENAFX_TEST_DIALOGS="$WORK/dialogs.json"
export WEBKIT_DISABLE_COMPOSITING_MODE=1 NO_AT_BRIDGE=1
tauri-driver --port 4444 >"$WORK/driver.log" 2>&1 &
DRIVER=$!
sleep 2

python3 "$ROOT/tests/e2e/e2e.py" "$WORK" "$APP"
CODE=$?
kill "$DRIVER" "$XVFB" 2>/dev/null
pkill -x WebKitWebDriver 2>/dev/null
echo "Screenshots: $WORK/shots"
exit $CODE
