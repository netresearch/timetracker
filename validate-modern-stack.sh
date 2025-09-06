#!/bin/bash

# Modern Toolchain Validation Script
# Validates that all modern tools work correctly before legacy removal

set -euo pipefail

echo "🔍 VALIDATION: Testing modern linting toolchain..."

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Validation results
phpstan_status="UNKNOWN"
phpat_status="UNKNOWN"
pint_status="UNKNOWN"
overall_status="PENDING"

echo ""
echo "=== PHPStan Analysis ==="
if timeout 300 docker compose run --rm app-dev composer analyze > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PHPStan: WORKING${NC}"
    phpstan_status="WORKING"
else
    echo -e "${RED}❌ PHPStan: FAILED${NC}"
    phpstan_status="FAILED"
fi

echo ""
echo "=== PHPat Architecture Analysis ==="
if timeout 300 docker compose run --rm app-dev composer analyze:arch > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PHPat: WORKING${NC}"
    phpat_status="WORKING"
else
    echo -e "${RED}❌ PHPat: FAILED${NC}"
    phpat_status="FAILED"
fi

echo ""
echo "=== Pint Code Style Check ==="
if timeout 120 docker compose run --rm app-dev composer cs-check:pint > /dev/null 2>&1; then
    echo -e "${GREEN}✅ Pint: WORKING${NC}"
    pint_status="WORKING"
else
    echo -e "${YELLOW}⚠️  Pint: ISSUES (timeout or formatting needed)${NC}"
    pint_status="ISSUES"
fi

echo ""
echo "=== VALIDATION SUMMARY ==="
echo "PHPStan: $phpstan_status"
echo "PHPat:   $phpat_status"  
echo "Pint:    $pint_status"

# Determine overall status
if [[ "$phpstan_status" == "WORKING" && "$phpat_status" == "WORKING" ]]; then
    if [[ "$pint_status" == "WORKING" ]]; then
        overall_status="READY"
        echo -e ""
        echo -e "${GREEN}🎯 RESULT: READY FOR LEGACY REMOVAL${NC}"
        echo "All modern tools are working correctly."
    else
        overall_status="READY_WITH_WARNINGS"
        echo -e ""
        echo -e "${YELLOW}⚠️  RESULT: READY WITH WARNINGS${NC}"
        echo "Core tools (PHPStan + PHPat) working, Pint needs attention."
        echo "Safe to proceed, but fix Pint issues after legacy removal."
    fi
else
    overall_status="NOT_READY"
    echo -e ""
    echo -e "${RED}🚨 RESULT: NOT READY${NC}"
    echo "Critical modern tools are failing. Fix issues before proceeding."
    echo ""
    echo "Recommended actions:"
    [[ "$phpstan_status" == "FAILED" ]] && echo "- Debug PHPStan configuration and dependencies"
    [[ "$phpat_status" == "FAILED" ]] && echo "- Fix PHPat architectural rules and dependencies"
    echo "- Run rollback script if needed: ./rollback-legacy-removal.sh"
fi

echo ""
echo "=== DETAILED ERROR COUNTS ==="
echo "Run these commands for detailed analysis:"
echo "make stan              # PHPStan errors"
echo "make psalm             # Psalm status (currently crashed)"
echo "docker compose run --rm app-dev composer analyze:arch  # PHPat errors"
echo "docker compose run --rm app-dev composer cs-check:pint  # Pint issues"

# Exit with appropriate code
case $overall_status in
    "READY") exit 0 ;;
    "READY_WITH_WARNINGS") exit 1 ;;
    "NOT_READY") exit 2 ;;
    *) exit 3 ;;
esac