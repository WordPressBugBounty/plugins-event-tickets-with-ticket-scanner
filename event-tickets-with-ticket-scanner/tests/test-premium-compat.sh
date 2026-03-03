#!/bin/bash
#
# Tests basic plugin against all premium variants via git checkout.
#
#   1. Stop premium (tag 1.2.9)
#   2. Starter premium (tag 1.3.6)
#   3. Current release (latest vX.Y.Z tag)
#   4. Current dev (HEAD, only if code differs from release)
#
# Usage: bash tests/test-premium-compat.sh
#

PREMIUM_DIR="/var/www/html/verwicklung.de/wordpress/wp-content/plugins/event-tickets-with-ticket-scanner-premium"
WP_DIR="/var/www/html/verwicklung.de/wordpress"

PASS=0
FAIL=0
SKIP=0
RESULTS=""

# --- Helper: load WP, extract only the OK| line ---
run_wp_test() {
    local label="$1"
    local expected_premium="$2"
    local expected_old="$3"

    local raw
    raw=$(cd "$WP_DIR" && php -d xdebug.mode=off -d display_errors=Off -r "
        error_reporting(0);
        require_once 'wp-load.php';
        \$m = sasoEventtickets::Instance();
        echo 'OK|' . (\$m->isPremium() ? 'true' : 'false') . '|' . (\$m->isOldPremiumDetected() ? 'true' : 'false') . '|' . (defined('SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION') ? SASO_EVENTTICKETS_PREMIUM_PLUGIN_VERSION : 'none');
    " 2>/dev/null) || true

    local output
    output=$(echo "$raw" | grep '^OK|' | tail -1)

    if [[ -z "$output" ]]; then
        FAIL=$((FAIL + 1))
        RESULTS+="  FAIL  $label — CRASH or no output\n"
        return
    fi

    IFS='|' read -r status isPrem isOld version <<< "$output"

    if [[ "$isPrem" == "$expected_premium" ]] && [[ "$isOld" == "$expected_old" ]]; then
        PASS=$((PASS + 1))
        RESULTS+="  OK    $label — isPremium=$isPrem, oldDetected=$isOld, version=$version\n"
    else
        FAIL=$((FAIL + 1))
        RESULTS+="  FAIL  $label — isPremium=$isPrem (exp $expected_premium), oldDetected=$isOld (exp $expected_old), version=$version\n"
    fi
}

# --- Helper: switch premium to a git ref ---
switch_premium() {
    local ref="$1"
    cd "$PREMIUM_DIR"
    git checkout "$ref" --force --quiet 2>/dev/null
    git clean -fd --quiet 2>/dev/null
}

echo "=== Premium Compatibility Test ==="
echo ""

# Save current state
ORIGINAL_BRANCH=$(cd "$PREMIUM_DIR" && git symbolic-ref --short HEAD 2>/dev/null || echo "")
ORIGINAL_REF=$(cd "$PREMIUM_DIR" && git rev-parse HEAD)

# --- 1. Stop Premium (tag 1.2.9) ---
echo "[1/4] Stop Premium (1.2.9)..."
switch_premium "1.2.9"
run_wp_test "Stop Premium (1.2.9)" "false" "true"

# --- 2. Starter Premium (tag 1.3.6) ---
echo "[2/4] Starter Premium (1.3.6)..."
switch_premium "1.3.6"
run_wp_test "Starter Premium (1.3.6)" "false" "true"

# --- 3. Current Release (latest vX.Y.Z tag) ---
echo "[3/4] Current Release..."
LATEST_TAG=$(cd "$PREMIUM_DIR" && git tag --sort=-v:refname | grep '^v' | head -1)
switch_premium "$LATEST_TAG"
run_wp_test "Current Release ($LATEST_TAG)" "true" "false"

# --- 4. Current Dev (only if code differs from release) ---
echo "[4/4] Current Dev..."
CODE_DIFF=$(cd "$PREMIUM_DIR" && git diff "$LATEST_TAG".."$ORIGINAL_REF" -- . ':!changelog.txt' ':!*.md' --stat 2>/dev/null)

if [[ -z "$CODE_DIFF" ]]; then
    SKIP=$((SKIP + 1))
    RESULTS+="  SKIP  Current Dev — no code changes vs $LATEST_TAG\n"
else
    switch_premium "$ORIGINAL_REF"
    DEV_VERSION=$(grep " \* Version:" "$PREMIUM_DIR/index.php" | head -1 | sed 's/.*Version: *//')
    run_wp_test "Current Dev ($DEV_VERSION)" "true" "false"
fi

# --- Restore ---
if [[ -n "$ORIGINAL_BRANCH" ]]; then
    switch_premium "$ORIGINAL_BRANCH"
else
    switch_premium "$ORIGINAL_REF"
fi

# --- Summary ---
echo ""
echo "=== Results ==="
echo -e "$RESULTS"
echo "Pass: $PASS  Fail: $FAIL  Skip: $SKIP"
echo ""

if [[ $FAIL -gt 0 ]]; then
    echo "FAILED"
    exit 1
else
    echo "ALL OK"
    exit 0
fi
