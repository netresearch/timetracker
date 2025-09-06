#!/bin/bash
# Database State Verification for Test Isolation
# Ensures database remains unchanged after test runs

set -e

# Expected row counts from fresh test database
EXPECTED_USERS=5
EXPECTED_ENTRIES=8  
EXPECTED_PROJECTS=3
EXPECTED_CUSTOMERS=3
EXPECTED_ACTIVITIES=3
EXPECTED_CONTRACTS=4
EXPECTED_HOLIDAYS=1

# Query current state
CURRENT_STATE=$(docker compose exec -T db_unittest mariadb -h 127.0.0.1 -uunittest -punittest unittest -e "
SELECT 
  CONCAT(
    (SELECT COUNT(*) FROM users), ',',
    (SELECT COUNT(*) FROM entries), ',', 
    (SELECT COUNT(*) FROM projects), ',',
    (SELECT COUNT(*) FROM customers), ',',
    (SELECT COUNT(*) FROM activities), ',',
    (SELECT COUNT(*) FROM contracts), ',',
    (SELECT COUNT(*) FROM holidays)
  ) as state;
" 2>/dev/null | tail -n 1)

# Expected state string
EXPECTED_STATE="${EXPECTED_USERS},${EXPECTED_ENTRIES},${EXPECTED_PROJECTS},${EXPECTED_CUSTOMERS},${EXPECTED_ACTIVITIES},${EXPECTED_CONTRACTS},${EXPECTED_HOLIDAYS}"

# Compare states
if [ "$CURRENT_STATE" = "$EXPECTED_STATE" ]; then
    echo "✅ Database state verification PASSED"
    echo "   State: users=$EXPECTED_USERS, entries=$EXPECTED_ENTRIES, projects=$EXPECTED_PROJECTS, customers=$EXPECTED_CUSTOMERS, activities=$EXPECTED_ACTIVITIES, contracts=$EXPECTED_CONTRACTS, holidays=$EXPECTED_HOLIDAYS"
    exit 0
else
    echo "❌ Database state verification FAILED"
    echo "   Expected: $EXPECTED_STATE"
    echo "   Current:  $CURRENT_STATE"
    echo ""
    echo "This indicates test isolation failed - some test modified the database without proper rollback."
    echo "Consider:"
    echo "  1. Check if any tests are calling forceReset() or disabling transactions"
    echo "  2. Verify all tests properly extend AbstractWebTestCase"
    echo "  3. Look for tests that might be committing transactions explicitly"
    echo "  4. Run 'make reset-test-db' to restore clean state"
    exit 1
fi