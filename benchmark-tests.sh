#!/bin/bash

# Function to display help information
show_help() {
    echo "Usage: $0 [options]"
    echo ""
    echo "Benchmark test performance with different configurations"
    echo ""
    echo "Options:"
    echo "  -s, --short      Run a shorter benchmark (fewer tests)"
    echo "  -h, --help       Display this help message"
}

# Default values
SHORT_MODE=0

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -s|--short)
            SHORT_MODE=1
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            show_help
            exit 1
            ;;
    esac
done

echo "========================================================"
echo "                TEST PERFORMANCE BENCHMARK               "
echo "========================================================"

# Run unit tests sequentially
echo -e "\n[1] Running unit tests sequentially..."
TIMEFORMAT="Sequential unit tests: %R seconds"
time ./run-tests.sh -u > /dev/null 2>&1

# Run unit tests in parallel
echo -e "\n[2] Running unit tests in parallel..."
TIMEFORMAT="Parallel unit tests:   %R seconds"
time ./run-tests.sh -p -u > /dev/null 2>&1

# Run controller tests
echo -e "\n[3] Running controller tests..."
TIMEFORMAT="Controller tests:      %R seconds"
time ./run-tests.sh -c > /dev/null 2>&1

echo -e "\nDone. Use the -p flag with run-tests.sh for non-controller tests to maximize performance."
