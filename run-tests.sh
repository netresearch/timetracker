#!/bin/bash

# Function to display help information
show_help() {
    echo "Usage: $0 [options] [test-path]"
    echo ""
    echo "Run PHPUnit tests with optimized configuration for Symfony"
    echo ""
    echo "Options:"
    echo "  -u, --unit       Run unit tests only"
    echo "  -c, --controller Run controller tests only"
    echo "  -p, --parallel   Run tests in parallel (unit tests only)"
    echo "  -m, --memory     Increase memory limit (useful for coverage)"
    echo "  -v, --verbose    Run tests in verbose mode"
    echo "  --coverage       Generate HTML coverage report"
    echo "  --coverage-text  Show coverage report in terminal"
    echo "  -h, --help       Display this help message"
    echo ""
    echo "Examples:"
    echo "  $0                           # Run all tests"
    echo "  $0 -u                        # Run unit tests only"
    echo "  $0 -p                        # Run unit tests in parallel"
    echo "  $0 --coverage                # Run tests with coverage"
    echo "  $0 tests/Controller/SecurityControllerTest.php  # Run specific test file"
}

# Default values
TESTSUITE=""
PARALLEL=0
MEMORY_LIMIT="128M"
VERBOSE=""
COVERAGE=""
TEST_PATH=""

# Parse arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -u|--unit)
            TESTSUITE="--testsuite=unit"
            shift
            ;;
        -c|--controller)
            TESTSUITE="--testsuite=controller"
            shift
            ;;
        -p|--parallel)
            PARALLEL=1
            shift
            ;;
        -m|--memory)
            MEMORY_LIMIT="512M"
            shift
            ;;
        -v|--verbose)
            VERBOSE="--verbose"
            shift
            ;;
        --coverage)
            COVERAGE="--coverage-html=var/coverage"
            MEMORY_LIMIT="512M"
            shift
            ;;
        --coverage-text)
            COVERAGE="--coverage-text"
            MEMORY_LIMIT="512M"
            shift
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            TEST_PATH="$1"
            shift
            ;;
    esac
done

# Determine the command to run
CMD="bin/phpunit"
ENV_VARS="-e APP_ENV=test"

# Add memory limit if needed
if [ "$MEMORY_LIMIT" != "128M" ]; then
    ENV_VARS="$ENV_VARS -e PHP_INI_MEMORY_LIMIT=$MEMORY_LIMIT"
    CMD="php -d memory_limit=$MEMORY_LIMIT $CMD"
fi

# Check if we should run in parallel
if [ $PARALLEL -eq 1 ]; then
    # Only allow parallel for unit tests
    if [ "$TESTSUITE" != "--testsuite=unit" ] && [ "$TESTSUITE" != "" ]; then
        echo "Warning: Parallel execution is only supported for unit tests."
        echo "Switching to unit test suite."
        TESTSUITE="--testsuite=unit"
    fi

    # Check if test path contains Controller and disable parallel
    if [[ "$TEST_PATH" == *"Controller"* ]]; then
        echo "Warning: Controller tests cannot be run in parallel."
        echo "Disabling parallel execution."
        PARALLEL=0
    else
        CMD="bin/paratest --processes=$(nproc)"
    fi
fi

# Build final command
FINAL_CMD="docker compose run --rm $ENV_VARS app $CMD $TESTSUITE $VERBOSE $COVERAGE $TEST_PATH"

# Print the command being executed
echo "Running: $FINAL_CMD"

# Record start time
START_TIME=$(date +%s.%N)

# Execute the command
eval $FINAL_CMD
EXIT_CODE=$?

# Record end time
END_TIME=$(date +%s.%N)

# Calculate and display runtime
RUNTIME=$(echo "$END_TIME - $START_TIME" | bc)
echo -e "\nTest execution completed in $RUNTIME seconds."

exit $EXIT_CODE
