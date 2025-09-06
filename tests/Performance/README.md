# Export Performance Benchmark Suite

Comprehensive performance testing suite for the timetracker export functionality, focusing on Excel generation, data processing, and JIRA integration performance characteristics.

## Overview

The export functionality is performance-critical as it processes large datasets and generates complex Excel files with PhpSpreadsheet. This test suite provides:

- **Performance benchmarking** for different data volumes
- **Memory usage analysis** and optimization validation  
- **Regression detection** through historical comparison
- **Stress testing** for edge cases and large datasets
- **End-to-end integration** testing with real database operations

## Test Structure

### Core Performance Tests

#### `ExportPerformanceTest.php`
- **Purpose**: Tests ExportService performance in isolation
- **Coverage**: Data export, ticket enrichment, memory scaling
- **Scenarios**:
  - Small dataset (50 entries) - baseline performance
  - Medium dataset (500 entries) - realistic load
  - Large dataset (5000 entries) - stress testing
  - Ticket enrichment with JIRA API simulation
  - Memory usage scaling analysis

#### `ExportActionPerformanceTest.php` 
- **Purpose**: Tests complete Excel generation pipeline
- **Coverage**: PhpSpreadsheet processing, template loading, statistics
- **Scenarios**:
  - Excel generation with different data volumes
  - Template processing performance
  - Memory usage during Excel creation
  - File size scaling analysis
  - Statistics calculation performance

#### `ExportWorkflowIntegrationTest.php`
- **Purpose**: End-to-end workflow testing with real database
- **Coverage**: HTTP requests, database queries, complete pipeline
- **Scenarios**:
  - Full export workflow via HTTP
  - Database query performance
  - Concurrent request handling
  - Filter combinations impact
  - Large dataset memory management

### Performance Baselines

Performance thresholds are configured to detect regressions:

| Scenario | Time Threshold | Memory Threshold | Notes |
|----------|---------------|------------------|-------|
| Small Dataset Export | 100ms | 5MB | 50 entries baseline |
| Medium Dataset Export | 500ms | 25MB | 500 entries realistic |
| Large Dataset Export | 2000ms | 50MB | 5000 entries stress test |
| Ticket Enrichment | 1000ms | - | 100 tickets with JIRA |
| Excel Generation Small | 500ms | 25MB | With PhpSpreadsheet |
| Excel Generation Large | 10000ms | 100MB | 5000 entries + Excel |
| End-to-End Small | 1000ms | - | HTTP + DB + Excel |
| End-to-End Medium | 3000ms | - | Full pipeline |

## Running Performance Tests

### Individual Test Suites

```bash
# Run ExportService performance tests
php bin/phpunit tests/Performance/ExportPerformanceTest.php

# Run ExportAction performance tests  
php bin/phpunit tests/Performance/ExportActionPerformanceTest.php

# Run integration performance tests
php bin/phpunit tests/Performance/ExportWorkflowIntegrationTest.php
```

### Complete Benchmark Suite

```bash
# Run all performance tests with detailed reporting
php bin/phpunit --group=performance

# Run benchmark runner for comprehensive analysis
php tests/Performance/PerformanceBenchmarkRunner.php

# Run with custom report location
php tests/Performance/PerformanceBenchmarkRunner.php /path/to/custom/report.json
```

### Composer Scripts

Add to composer.json for convenience:

```json
{
  "scripts": {
    "perf:export": "APP_ENV=test php bin/phpunit --group=performance tests/Performance/",
    "perf:benchmark": "APP_ENV=test php tests/Performance/PerformanceBenchmarkRunner.php",
    "perf:report": "APP_ENV=test php tests/Performance/PerformanceBenchmarkRunner.php var/performance-report.json"
  }
}
```

## Performance Analysis

### Key Metrics Tracked

1. **Execution Time**: Processing duration in milliseconds
2. **Memory Usage**: Peak and delta memory consumption  
3. **Throughput**: Records processed per second
4. **File Size**: Generated Excel file size scaling
5. **Database Performance**: Query execution time

### Regression Detection

The benchmark runner automatically detects performance regressions by comparing with historical data:

- **Execution Time**: >20% increase triggers warning
- **Memory Usage**: >25% increase triggers warning  
- **Throughput**: >15% decrease triggers warning

Historical data is stored in `var/performance-history.json` (last 10 runs).

### Performance Reports

Generated reports include:

#### JSON Report (`performance-report-YYYY-MM-DD-HH-mm-ss.json`)
- Machine-readable format for CI/CD integration
- Complete benchmark data with timestamps
- Suitable for trend analysis and dashboards

#### Text Report (`performance-report-YYYY-MM-DD-HH-mm-ss.txt`)
- Human-readable summary format
- Performance statistics and regression warnings
- Ideal for development team review

## Performance Optimization Areas

Based on benchmark analysis, key optimization opportunities:

### 1. Database Query Optimization
- **Current**: Multiple queries for relationships
- **Target**: Optimized joins and eager loading
- **Impact**: Reduce query count by 60-80%

### 2. Memory Usage Optimization
- **Current**: Full dataset loaded into memory
- **Target**: Streaming/batch processing
- **Impact**: Reduce memory usage by 40-60%

### 3. Excel Generation Optimization
- **Current**: Row-by-row cell population
- **Target**: Bulk data insertion with arrays
- **Impact**: Reduce Excel generation time by 30-50%

### 4. Ticket Enrichment Optimization
- **Current**: Individual API calls per ticket
- **Target**: Batch JIRA API requests
- **Impact**: Reduce enrichment time by 70-80%

## CI/CD Integration

### Performance Gates

Recommended CI/CD pipeline integration:

```yaml
# Example GitHub Actions step
- name: Run Performance Tests
  run: |
    composer perf:benchmark
    # Fail build if critical regressions detected
    if grep -q "execution time increased by [3-9][0-9]%" var/performance-report*.txt; then
      echo "Critical performance regression detected"
      exit 1
    fi
```

### Performance Monitoring

- Store historical reports in artifact repository
- Set up alerts for regression thresholds
- Generate trend analysis dashboards
- Include performance metrics in release notes

## Development Guidelines

### When to Run Performance Tests

1. **Before release**: Full benchmark suite
2. **After export changes**: Related performance tests
3. **Weekly**: Regression monitoring
4. **After infrastructure changes**: Complete validation

### Performance Test Maintenance

1. **Update baselines** when hardware/infrastructure changes
2. **Adjust thresholds** based on acceptable performance criteria  
3. **Add new scenarios** for new export features
4. **Archive old reports** to prevent disk usage growth

### Troubleshooting Performance Issues

1. **Identify bottlenecks** using detailed benchmark reports
2. **Profile with Xdebug** for deep analysis of slow components
3. **Use database query logs** to identify slow queries
4. **Monitor memory usage** patterns for optimization opportunities
5. **Test with production-like data** volumes for realistic analysis

## Architecture Considerations

### Scalability Factors

- **Data Volume**: Export time scales roughly O(n) with entry count
- **Excel Complexity**: Memory usage scales with worksheet complexity  
- **Ticket Enrichment**: Network latency impacts greatly with many tickets
- **Concurrent Users**: Memory pressure increases with simultaneous exports

### Performance Monitoring in Production

Consider implementing:
- Export request duration monitoring
- Memory usage tracking for large exports
- Queue-based processing for very large exports
- Export result caching for repeated requests

## Future Enhancements

### Potential Performance Improvements

1. **Asynchronous Processing**: Queue large exports for background processing
2. **Streaming Responses**: Stream Excel generation to reduce memory usage
3. **Result Caching**: Cache export results for repeated identical requests  
4. **Database Optimization**: Implement more efficient queries and indexes
5. **Partial Loading**: Load and process data in chunks rather than full datasets

### Advanced Performance Testing

1. **Load Testing**: Simulate multiple concurrent export requests
2. **Stress Testing**: Test with extremely large datasets (50k+ entries)
3. **Network Simulation**: Test JIRA integration with various network conditions
4. **Resource Constraints**: Test with limited memory/CPU resources