#!/bin/bash

# Parallel Test Execution Script for TimeTracker
# Provides easy-to-use commands for running tests with optimal performance

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DEFAULT_PROCESSES=$(nproc)
SAFE_PROCESSES=4
MAX_BATCH_SIZE=50
SAFE_BATCH_SIZE=25

# Functions
print_header() {
    echo -e "${BLUE}=== TimeTracker Parallel Test Runner ===${NC}"
    echo -e "Available CPU cores: ${GREEN}${DEFAULT_PROCESSES}${NC}"
    echo ""
}

print_usage() {
    echo -e "${YELLOW}Usage:${NC} $0 [command] [options]"
    echo ""
    echo -e "${YELLOW}Commands:${NC}"
    echo "  unit          Run unit tests in parallel (full CPU)"
    echo "  unit-safe     Run unit tests in parallel (4 cores max)"
    echo "  all           Run all tests optimally (parallel + sequential)"
    echo "  coverage      Run parallel tests with coverage report"
    echo "  benchmark     Compare sequential vs parallel performance"
    echo "  validate      Validate parallel test configuration"
    echo ""
    echo -e "${YELLOW}Options:${NC}"
    echo "  --processes N    Override number of processes"
    echo "  --batch-size N   Override max batch size"
    echo "  --verbose        Enable verbose output"
    echo "  --help           Show this help message"
    echo ""
    echo -e "${YELLOW}Examples:${NC}"
    echo "  $0 unit                    # Run unit tests with all CPU cores"
    echo "  $0 unit-safe               # Run unit tests with 4 cores"
    echo "  $0 unit --processes 8      # Run unit tests with 8 cores"
    echo "  $0 all                     # Run all tests optimally"
    echo "  $0 benchmark               # Compare performance"
}

validate_environment() {
    echo -e "${YELLOW}Validating test environment...${NC}"
    
    # Check if Docker is running
    if ! docker info >/dev/null 2>&1; then
        echo -e "${RED}Error: Docker is not running${NC}"
        exit 1
    fi
    
    # Check if paratest.xml exists
    if [ ! -f "paratest.xml" ]; then
        echo -e "${RED}Error: paratest.xml configuration not found${NC}"
        exit 1
    fi
    
    # Check if test SQL files exist
    if [ ! -f "sql/full.sql" ]; then
        echo -e "${RED}Error: sql/full.sql not found${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}Environment validation passed${NC}"
}

prepare_database() {
    echo -e "${YELLOW}Preparing test database...${NC}"
    make prepare-test-sql >/dev/null 2>&1
    echo -e "${GREEN}Test database prepared${NC}"
}

run_parallel_unit() {
    local processes=${1:-$DEFAULT_PROCESSES}
    local batch_size=${2:-$MAX_BATCH_SIZE}
    
    echo -e "${YELLOW}Running unit tests in parallel...${NC}"
    echo -e "Processes: ${GREEN}${processes}${NC}, Batch size: ${GREEN}${batch_size}${NC}"
    echo ""
    
    start_time=$(date +%s)
    
    docker compose run --rm -e APP_ENV=test -e PARATEST_PARALLEL=1 app-dev \
        ./bin/paratest --configuration=paratest.xml \
        --processes=${processes} \
        --testsuite=unit-parallel \
        --max-batch-size=${batch_size}
    
    end_time=$(date +%s)
    duration=$((end_time - start_time))
    
    echo ""
    echo -e "${GREEN}Parallel unit tests completed in ${duration}s${NC}"
}

run_parallel_safe() {
    echo -e "${YELLOW}Running unit tests in safe mode...${NC}"
    run_parallel_unit $SAFE_PROCESSES $SAFE_BATCH_SIZE
}

run_all_optimized() {
    echo -e "${YELLOW}Running optimized test suite...${NC}"
    
    start_time=$(date +%s)
    
    echo -e "${BLUE}Phase 1: Parallel unit tests${NC}"
    run_parallel_unit
    
    echo ""
    echo -e "${BLUE}Phase 2: Sequential controller tests${NC}"
    docker compose run --rm -e APP_ENV=test app-dev \
        php -d memory_limit=512M ./bin/phpunit --testsuite=controller-sequential
    
    end_time=$(date +%s)
    duration=$((end_time - start_time))
    
    echo ""
    echo -e "${GREEN}All tests completed in ${duration}s${NC}"
}

run_coverage() {
    local processes=${1:-$DEFAULT_PROCESSES}
    
    echo -e "${YELLOW}Running parallel tests with coverage...${NC}"
    echo -e "Processes: ${GREEN}${processes}${NC}"
    echo ""
    
    start_time=$(date +%s)
    
    docker compose run --rm -e APP_ENV=test -e PARATEST_PARALLEL=1 app-dev \
        ./bin/paratest --configuration=paratest.xml \
        --processes=${processes} \
        --testsuite=unit-parallel \
        --coverage-html var/coverage-parallel
    
    end_time=$(date +%s)
    duration=$((end_time - start_time))
    
    echo ""
    echo -e "${GREEN}Coverage report generated in ${duration}s${NC}"
    echo -e "Report available at: ${BLUE}var/coverage-parallel/index.html${NC}"
}

run_benchmark() {
    echo -e "${YELLOW}Running performance benchmark...${NC}"
    echo ""
    
    # Sequential test
    echo -e "${BLUE}Running sequential tests...${NC}"
    seq_start=$(date +%s)
    
    docker compose run --rm -e APP_ENV=test app-dev \
        php -d memory_limit=512M ./bin/phpunit --testsuite=unit --do-not-cache-result >/dev/null 2>&1
    
    seq_end=$(date +%s)
    seq_duration=$((seq_end - seq_start))
    
    # Parallel test
    echo -e "${BLUE}Running parallel tests...${NC}"
    par_start=$(date +%s)
    
    docker compose run --rm -e APP_ENV=test -e PARATEST_PARALLEL=1 app-dev \
        ./bin/paratest --configuration=paratest.xml \
        --processes=${DEFAULT_PROCESSES} \
        --testsuite=unit-parallel >/dev/null 2>&1
    
    par_end=$(date +%s)
    par_duration=$((par_end - par_start))
    
    # Calculate improvement
    if [ $seq_duration -gt 0 ]; then
        improvement=$(echo "scale=1; ($seq_duration - $par_duration) * 100 / $seq_duration" | bc -l)
        speedup=$(echo "scale=2; $seq_duration / $par_duration" | bc -l)
    else
        improvement=0
        speedup=1
    fi
    
    echo ""
    echo -e "${YELLOW}=== Performance Benchmark Results ===${NC}"
    echo -e "Sequential execution: ${RED}${seq_duration}s${NC}"
    echo -e "Parallel execution:   ${GREEN}${par_duration}s${NC}"
    echo -e "Performance gain:     ${GREEN}${improvement}%${NC}"
    echo -e "Speed multiplier:     ${GREEN}${speedup}x${NC}"
    echo ""
}

# Parse command line arguments
COMMAND=""
PROCESSES=""
BATCH_SIZE=""
VERBOSE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        unit|unit-safe|all|coverage|benchmark|validate)
            COMMAND="$1"
            shift
            ;;
        --processes)
            PROCESSES="$2"
            shift 2
            ;;
        --batch-size)
            BATCH_SIZE="$2"
            shift 2
            ;;
        --verbose)
            VERBOSE=true
            shift
            ;;
        --help)
            print_header
            print_usage
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            print_usage
            exit 1
            ;;
    esac
done

# Main execution
print_header

if [ -z "$COMMAND" ]; then
    print_usage
    exit 1
fi

if [ "$VERBOSE" = true ]; then
    set -x
fi

validate_environment
prepare_database

case $COMMAND in
    unit)
        run_parallel_unit "$PROCESSES" "$BATCH_SIZE"
        ;;
    unit-safe)
        run_parallel_safe
        ;;
    all)
        run_all_optimized
        ;;
    coverage)
        run_coverage "$PROCESSES"
        ;;
    benchmark)
        run_benchmark
        ;;
    validate)
        echo -e "${GREEN}Validation completed successfully${NC}"
        ;;
    *)
        echo -e "${RED}Unknown command: $COMMAND${NC}"
        print_usage
        exit 1
        ;;
esac