#!/bin/bash

# Parallel Test Setup Script
# Configures and validates parallel test execution environment

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_header() {
    echo -e "${BLUE}=== TimeTracker Parallel Test Setup ===${NC}"
    echo ""
}

print_status() {
    local status=$1
    local message=$2
    if [ "$status" = "OK" ]; then
        echo -e "[${GREEN}✓${NC}] $message"
    elif [ "$status" = "WARN" ]; then
        echo -e "[${YELLOW}!${NC}] $message"
    else
        echo -e "[${RED}✗${NC}] $message"
    fi
}

check_dependencies() {
    echo -e "${YELLOW}Checking dependencies...${NC}"
    
    # Check Docker
    if docker info >/dev/null 2>&1; then
        print_status "OK" "Docker is running"
    else
        print_status "ERROR" "Docker is not running"
        exit 1
    fi
    
    # Check Docker Compose
    if docker compose version >/dev/null 2>&1; then
        print_status "OK" "Docker Compose is available"
    else
        print_status "ERROR" "Docker Compose is not available"
        exit 1
    fi
    
    # Check Paratest dependency
    if grep -q "brianium/paratest" composer.json; then
        print_status "OK" "Paratest dependency is installed"
    else
        print_status "WARN" "Paratest dependency not found in composer.json"
    fi
    
    echo ""
}

check_configuration_files() {
    echo -e "${YELLOW}Checking configuration files...${NC}"
    
    # Check paratest.xml
    if [ -f "config/testing/paratest.xml" ]; then
        print_status "OK" "paratest.xml configuration exists"
    else
        print_status "ERROR" "config/testing/paratest.xml configuration missing"
        exit 1
    fi
    
    # Check parallel bootstrap
    if [ -f "tests/parallel-bootstrap.php" ]; then
        print_status "OK" "Parallel bootstrap file exists"
    else
        print_status "WARN" "Parallel bootstrap file not found"
    fi
    
    # Check test structure
    if [ -d "tests" ]; then
        unit_tests=$(find tests -name "*.php" -not -path "tests/Controller/*" -not -path "tests/Entity/*" | wc -l)
        controller_tests=$(find tests/Controller -name "*.php" 2>/dev/null | wc -l || echo "0")
        print_status "OK" "Test structure valid (Unit: $unit_tests, Controller: $controller_tests)"
    else
        print_status "ERROR" "Tests directory not found"
        exit 1
    fi
    
    echo ""
}

check_database_configuration() {
    echo -e "${YELLOW}Checking database configuration...${NC}"
    
    # Check .env.test
    if [ -f ".env.test" ]; then
        if grep -q "DATABASE_URL" .env.test; then
            print_status "OK" "Test database configuration found"
        else
            print_status "WARN" "DATABASE_URL not found in .env.test"
        fi
    else
        print_status "WARN" ".env.test file not found"
    fi
    
    # Check SQL test data
    if [ -f "sql/full.sql" ]; then
        print_status "OK" "SQL test data source exists"
    else
        print_status "WARN" "sql/full.sql not found"
    fi
    
    echo ""
}

check_performance_config() {
    echo -e "${YELLOW}Checking performance configuration...${NC}"
    
    # CPU cores
    cores=$(nproc)
    if [ $cores -ge 4 ]; then
        print_status "OK" "Sufficient CPU cores available ($cores)"
    else
        print_status "WARN" "Limited CPU cores ($cores) - parallel benefits may be reduced"
    fi
    
    # Memory
    total_mem=$(free -m | awk 'NR==2{printf "%.0f", $2}')
    if [ $total_mem -ge 4096 ]; then
        print_status "OK" "Sufficient memory available (${total_mem}MB)"
    else
        print_status "WARN" "Limited memory (${total_mem}MB) - consider using test:parallel:safe"
    fi
    
    echo ""
}

run_basic_test() {
    echo -e "${YELLOW}Running basic test validation...${NC}"
    
    # Try to run a simple parallel test
    if make prepare-test-sql >/dev/null 2>&1; then
        print_status "OK" "Test SQL preparation works"
    else
        print_status "ERROR" "Failed to prepare test SQL"
        exit 1
    fi
    
    # Test Paratest configuration
    if docker compose run --rm -e APP_ENV=test app-dev ./bin/paratest --help >/dev/null 2>&1; then
        print_status "OK" "Paratest binary is accessible"
    else
        print_status "ERROR" "Paratest binary not accessible"
        exit 1
    fi
    
    echo ""
}

show_recommendations() {
    echo -e "${BLUE}=== Recommendations ===${NC}"
    
    cores=$(nproc)
    memory=$(free -m | awk 'NR==2{printf "%.0f", $2}')
    
    echo -e "${YELLOW}Based on your system configuration:${NC}"
    echo ""
    
    if [ $cores -ge 8 ] && [ $memory -ge 8192 ]; then
        echo -e "• ${GREEN}Optimal setup detected${NC}"
        echo -e "• Use: ${BLUE}make test-parallel${NC} for maximum speed"
        echo -e "• Use: ${BLUE}composer test:parallel:all${NC} for complete test suite"
    elif [ $cores -ge 4 ] && [ $memory -ge 4096 ]; then
        echo -e "• ${YELLOW}Good setup for parallel testing${NC}"
        echo -e "• Use: ${BLUE}make test-parallel-safe${NC} for reliable execution"
        echo -e "• Use: ${BLUE}./scripts/parallel-test.sh unit --processes 4${NC}"
    else
        echo -e "• ${YELLOW}Limited resources detected${NC}"
        echo -e "• Use: ${BLUE}./scripts/parallel-test.sh unit-safe${NC} for safe execution"
        echo -e "• Consider sequential tests: ${BLUE}make test${NC}"
    fi
    
    echo ""
    echo -e "${YELLOW}Available commands:${NC}"
    echo -e "• ${BLUE}./scripts/parallel-test.sh unit${NC}      - Fast parallel unit tests"
    echo -e "• ${BLUE}./scripts/parallel-test.sh all${NC}       - Complete optimized test suite"
    echo -e "• ${BLUE}./scripts/parallel-test.sh benchmark${NC} - Performance comparison"
    echo -e "• ${BLUE}make test-parallel-all${NC}               - Docker-based execution"
    echo ""
}

generate_ci_config() {
    echo -e "${YELLOW}Generating CI configuration example...${NC}"
    
    cat > .github-workflows-tests-example.yml << 'EOF'
name: Tests

on:
  push:
    branches: [ main, develop ]
  pull_request:
    branches: [ main, develop ]

jobs:
  parallel-tests:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Build test environment
      run: make build
    
    - name: Run parallel tests
      run: make test-parallel-all
    
    - name: Upload coverage
      if: success()
      run: |
        make coverage
        # Upload coverage results to your service
EOF
    
    print_status "OK" "CI configuration example generated: .github-workflows-tests-example.yml"
    echo ""
}

main() {
    print_header
    
    check_dependencies
    check_configuration_files
    check_database_configuration
    check_performance_config
    run_basic_test
    
    echo -e "${GREEN}=== Setup validation completed successfully! ===${NC}"
    echo ""
    
    show_recommendations
    
    if [ "$1" = "--generate-ci" ]; then
        generate_ci_config
    fi
    
    echo -e "${GREEN}Parallel test execution is ready to use!${NC}"
}

# Parse arguments
if [ "$1" = "--help" ]; then
    echo "Usage: $0 [--generate-ci] [--help]"
    echo ""
    echo "Options:"
    echo "  --generate-ci    Generate CI configuration example"
    echo "  --help           Show this help message"
    exit 0
fi

main "$@"