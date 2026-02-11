#!/usr/bin/env python3
"""
Load Test Results Analyzer

Analyzes k6/Artillery results and system metrics to provide:
- Performance bottleneck identification
- Optimization recommendations
- Pass/Fail verdict based on acceptance criteria

Usage:
    python analyze-results.py load-test-results.json system-metrics.log
"""

import json
import sys
import csv
from datetime import datetime
from typing import Dict, List, Tuple

# ANSI Colors
RED = '\033[0;31m'
GREEN = '\033[0;32m'
YELLOW = '\033[1;33m'
BLUE = '\033[0;34m'
NC = '\033[0m'  # No Color

# ============================================================================
# ACCEPTANCE CRITERIA
# ============================================================================

ACCEPTANCE_CRITERIA = {
    'error_rate_max': 0.01,  # 1%
    'p95_response_time_max': 1000,  # 1 second
    'p99_response_time_max': 2000,  # 2 seconds
    'duplicate_attendance_max': 0,  # ZERO duplicates
    'deadlock_count_max': 10,  # Max 10 deadlocks (should retry)
    'cpu_usage_max': 80,  # 80%
    'memory_usage_max': 80,  # 80%
    'redis_memory_max': 1000,  # 1GB
}

# ============================================================================
# LOAD TEST RESULTS ANALYSIS
# ============================================================================

def analyze_load_test_results(results_file: str) -> Dict:
    """Analyze k6/Artillery JSON results"""
    
    print(f"\n{BLUE}📊 Analyzing Load Test Results...{NC}")
    print("=" * 70)
    
    with open(results_file, 'r') as f:
        data = json.load(f)
    
    # Extract metrics
    metrics = data.get('metrics', {})
    
    # HTTP metrics
    http_reqs = metrics.get('http_reqs', {}).get('values', {})
    http_req_duration = metrics.get('http_req_duration', {}).get('values', {})
    http_req_failed = metrics.get('http_req_failed', {}).get('values', {})
    
    # Custom metrics
    errors = metrics.get('errors', {}).get('values', {})
    duplicate_attendance = metrics.get('duplicate_attendance', {}).get('values', {})
    deadlock_errors = metrics.get('deadlock_errors', {}).get('values', {})
    redis_errors = metrics.get('redis_errors', {}).get('values', {})
    db_lock_waits = metrics.get('db_lock_waits', {}).get('values', {})
    
    analysis = {
        'total_requests': http_reqs.get('count', 0),
        'error_rate': http_req_failed.get('rate', 0),
        'p50_response_time': http_req_duration.get('p(50)', 0),
        'p95_response_time': http_req_duration.get('p(95)', 0),
        'p99_response_time': http_req_duration.get('p(99)', 0),
        'max_response_time': http_req_duration.get('max', 0),
        'avg_response_time': http_req_duration.get('avg', 0),
        'duplicate_attendance': duplicate_attendance.get('count', 0),
        'deadlock_errors': deadlock_errors.get('count', 0),
        'redis_errors': redis_errors.get('count', 0),
        'db_lock_waits': db_lock_waits.get('count', 0),
    }
    
    # Print results
    print(f"Total Requests:        {analysis['total_requests']:,}")
    print(f"Error Rate:            {analysis['error_rate']*100:.2f}%")
    print(f"Avg Response Time:     {analysis['avg_response_time']:.2f}ms")
    print(f"P50 Response Time:     {analysis['p50_response_time']:.2f}ms")
    print(f"P95 Response Time:     {analysis['p95_response_time']:.2f}ms")
    print(f"P99 Response Time:     {analysis['p99_response_time']:.2f}ms")
    print(f"Max Response Time:     {analysis['max_response_time']:.2f}ms")
    print(f"Duplicate Attendance:  {analysis['duplicate_attendance']}")
    print(f"Deadlock Errors:       {analysis['deadlock_errors']}")
    print(f"Redis Errors:          {analysis['redis_errors']}")
    print(f"DB Lock Waits:         {analysis['db_lock_waits']}")
    
    return analysis

# ============================================================================
# SYSTEM METRICS ANALYSIS
# ============================================================================

def analyze_system_metrics(metrics_file: str) -> Dict:
    """Analyze system monitoring metrics"""
    
    print(f"\n{BLUE}📈 Analyzing System Metrics...{NC}")
    print("=" * 70)
    
    metrics = []
    with open(metrics_file, 'r') as f:
        reader = csv.DictReader(f)
        for row in reader:
            metrics.append({
                'timestamp': row['timestamp'],
                'cpu_percent': float(row['cpu_percent']),
                'mem_percent': float(row['mem_percent']),
                'redis_mem_mb': float(row['redis_mem_mb']),
                'mysql_connections': int(row['mysql_connections']),
                'db_lock_waits': int(row['db_lock_waits']),
                'redis_ops_per_sec': int(row['redis_ops_per_sec']),
            })
    
    if not metrics:
        print(f"{RED}❌ No system metrics found{NC}")
        return {}
    
    # Calculate statistics
    cpu_values = [m['cpu_percent'] for m in metrics]
    mem_values = [m['mem_percent'] for m in metrics]
    redis_mem_values = [m['redis_mem_mb'] for m in metrics]
    mysql_conn_values = [m['mysql_connections'] for m in metrics]
    db_lock_values = [m['db_lock_waits'] for m in metrics]
    redis_ops_values = [m['redis_ops_per_sec'] for m in metrics]
    
    analysis = {
        'cpu_avg': sum(cpu_values) / len(cpu_values),
        'cpu_max': max(cpu_values),
        'mem_avg': sum(mem_values) / len(mem_values),
        'mem_max': max(mem_values),
        'redis_mem_avg': sum(redis_mem_values) / len(redis_mem_values),
        'redis_mem_max': max(redis_mem_values),
        'mysql_conn_avg': sum(mysql_conn_values) / len(mysql_conn_values),
        'mysql_conn_max': max(mysql_conn_values),
        'db_lock_waits_total': sum(db_lock_values),
        'redis_ops_avg': sum(redis_ops_values) / len(redis_ops_values),
        'redis_ops_max': max(redis_ops_values),
    }
    
    # Print results
    print(f"CPU Usage:             Avg: {analysis['cpu_avg']:.2f}%, Max: {analysis['cpu_max']:.2f}%")
    print(f"Memory Usage:          Avg: {analysis['mem_avg']:.2f}%, Max: {analysis['mem_max']:.2f}%")
    print(f"Redis Memory:          Avg: {analysis['redis_mem_avg']:.2f}MB, Max: {analysis['redis_mem_max']:.2f}MB")
    print(f"MySQL Connections:     Avg: {analysis['mysql_conn_avg']:.0f}, Max: {analysis['mysql_conn_max']}")
    print(f"DB Lock Waits:         Total: {analysis['db_lock_waits_total']}")
    print(f"Redis Ops/Sec:         Avg: {analysis['redis_ops_avg']:.0f}, Max: {analysis['redis_ops_max']}")
    
    return analysis

# ============================================================================
# BOTTLENECK IDENTIFICATION
# ============================================================================

def identify_bottlenecks(load_analysis: Dict, system_analysis: Dict) -> List[Tuple[str, str, str]]:
    """Identify performance bottlenecks"""
    
    print(f"\n{BLUE}🔍 Identifying Bottlenecks...{NC}")
    print("=" * 70)
    
    bottlenecks = []
    
    # Check CPU
    if system_analysis.get('cpu_max', 0) > 80:
        bottlenecks.append((
            'CPU',
            f"Max CPU usage: {system_analysis['cpu_max']:.2f}%",
            "HIGH"
        ))
    
    # Check Memory
    if system_analysis.get('mem_max', 0) > 80:
        bottlenecks.append((
            'Memory',
            f"Max memory usage: {system_analysis['mem_max']:.2f}%",
            "HIGH"
        ))
    
    # Check Redis
    if system_analysis.get('redis_mem_max', 0) > 1000:
        bottlenecks.append((
            'Redis Memory',
            f"Max Redis memory: {system_analysis['redis_mem_max']:.2f}MB",
            "MEDIUM"
        ))
    
    # Check Database
    if system_analysis.get('db_lock_waits_total', 0) > 100:
        bottlenecks.append((
            'Database Locks',
            f"Total lock waits: {system_analysis['db_lock_waits_total']}",
            "HIGH"
        ))
    
    # Check Response Time
    if load_analysis.get('p95_response_time', 0) > 1000:
        bottlenecks.append((
            'Response Time',
            f"P95: {load_analysis['p95_response_time']:.2f}ms",
            "HIGH"
        ))
    
    # Check Error Rate
    if load_analysis.get('error_rate', 0) > 0.01:
        bottlenecks.append((
            'Error Rate',
            f"Error rate: {load_analysis['error_rate']*100:.2f}%",
            "CRITICAL"
        ))
    
    # Check Duplicates
    if load_analysis.get('duplicate_attendance', 0) > 0:
        bottlenecks.append((
            'Duplicate Attendance',
            f"Duplicates: {load_analysis['duplicate_attendance']}",
            "CRITICAL"
        ))
    
    # Print bottlenecks
    if bottlenecks:
        for component, issue, severity in bottlenecks:
            color = RED if severity == 'CRITICAL' else YELLOW if severity == 'HIGH' else NC
            print(f"{color}[{severity}] {component}: {issue}{NC}")
    else:
        print(f"{GREEN}✅ No bottlenecks detected{NC}")
    
    return bottlenecks

# ============================================================================
# OPTIMIZATION RECOMMENDATIONS
# ============================================================================

def generate_recommendations(bottlenecks: List[Tuple[str, str, str]]) -> List[str]:
    """Generate optimization recommendations"""
    
    print(f"\n{BLUE}💡 Optimization Recommendations...{NC}")
    print("=" * 70)
    
    recommendations = []
    
    for component, issue, severity in bottlenecks:
        if component == 'CPU':
            recommendations.append("1. Scale horizontally: Add more application servers")
            recommendations.append("2. Optimize CPU-intensive operations (encryption, hashing)")
            recommendations.append("3. Enable opcache for PHP")
        
        elif component == 'Memory':
            recommendations.append("1. Implement export chunking (use cursor() instead of get())")
            recommendations.append("2. Reduce memory footprint of Eloquent models")
            recommendations.append("3. Scale vertically: Increase server RAM")
        
        elif component == 'Redis Memory':
            recommendations.append("1. Reduce cache TTL for large objects")
            recommendations.append("2. Implement cache eviction policy (LRU)")
            recommendations.append("3. Scale Redis: Use Redis Cluster")
        
        elif component == 'Database Locks':
            recommendations.append("1. Implement deadlock retry middleware")
            recommendations.append("2. Reduce transaction scope")
            recommendations.append("3. Add database indexes for hot queries")
            recommendations.append("4. Use optimistic locking where possible")
        
        elif component == 'Response Time':
            recommendations.append("1. Create dashboard summary table (pre-aggregated data)")
            recommendations.append("2. Add missing database indexes")
            recommendations.append("3. Implement query caching")
            recommendations.append("4. Use CDN for static assets")
        
        elif component == 'Error Rate':
            recommendations.append("1. Implement circuit breaker pattern")
            recommendations.append("2. Add Redis fallback to database locks")
            recommendations.append("3. Increase timeout values")
        
        elif component == 'Duplicate Attendance':
            recommendations.append("1. CRITICAL: Implement single active QR per schedule")
            recommendations.append("2. CRITICAL: Add idempotency check by student+schedule+date")
            recommendations.append("3. Verify unique constraint is active")
    
    # Remove duplicates
    recommendations = list(dict.fromkeys(recommendations))
    
    # Print recommendations
    for i, rec in enumerate(recommendations, 1):
        print(f"{i}. {rec}")
    
    return recommendations

# ============================================================================
# PASS/FAIL VERDICT
# ============================================================================

def generate_verdict(load_analysis: Dict, system_analysis: Dict, bottlenecks: List) -> str:
    """Generate pass/fail verdict"""
    
    print(f"\n{BLUE}🎯 Acceptance Criteria Evaluation...{NC}")
    print("=" * 70)
    
    failures = []
    
    # Check error rate
    if load_analysis.get('error_rate', 0) > ACCEPTANCE_CRITERIA['error_rate_max']:
        failures.append(f"Error rate: {load_analysis['error_rate']*100:.2f}% (max: {ACCEPTANCE_CRITERIA['error_rate_max']*100}%)")
    else:
        print(f"{GREEN}✅ Error rate: {load_analysis.get('error_rate', 0)*100:.2f}% < {ACCEPTANCE_CRITERIA['error_rate_max']*100}%{NC}")
    
    # Check P95 response time
    if load_analysis.get('p95_response_time', 0) > ACCEPTANCE_CRITERIA['p95_response_time_max']:
        failures.append(f"P95 response time: {load_analysis['p95_response_time']:.2f}ms (max: {ACCEPTANCE_CRITERIA['p95_response_time_max']}ms)")
    else:
        print(f"{GREEN}✅ P95 response time: {load_analysis.get('p95_response_time', 0):.2f}ms < {ACCEPTANCE_CRITERIA['p95_response_time_max']}ms{NC}")
    
    # Check duplicate attendance
    if load_analysis.get('duplicate_attendance', 0) > ACCEPTANCE_CRITERIA['duplicate_attendance_max']:
        failures.append(f"Duplicate attendance: {load_analysis['duplicate_attendance']} (max: {ACCEPTANCE_CRITERIA['duplicate_attendance_max']})")
    else:
        print(f"{GREEN}✅ No duplicate attendance{NC}")
    
    # Check deadlocks
    if load_analysis.get('deadlock_errors', 0) > ACCEPTANCE_CRITERIA['deadlock_count_max']:
        failures.append(f"Deadlock errors: {load_analysis['deadlock_errors']} (max: {ACCEPTANCE_CRITERIA['deadlock_count_max']})")
    else:
        print(f"{GREEN}✅ Deadlock errors: {load_analysis.get('deadlock_errors', 0)} < {ACCEPTANCE_CRITERIA['deadlock_count_max']}{NC}")
    
    # Generate verdict
    print("\n" + "=" * 70)
    if failures:
        print(f"{RED}❌ VERDICT: FAIL{NC}")
        print(f"\n{RED}Failures:{NC}")
        for failure in failures:
            print(f"  - {failure}")
        return "FAIL"
    else:
        print(f"{GREEN}✅ VERDICT: PASS{NC}")
        print(f"\n{GREEN}All acceptance criteria met!{NC}")
        return "PASS"

# ============================================================================
# MAIN
# ============================================================================

def main():
    if len(sys.argv) < 3:
        print("Usage: python analyze-results.py <load-test-results.json> <system-metrics.log>")
        sys.exit(1)
    
    results_file = sys.argv[1]
    metrics_file = sys.argv[2]
    
    print(f"\n{BLUE}{'=' * 70}{NC}")
    print(f"{BLUE}🔬 LOAD TEST RESULTS ANALYSIS{NC}")
    print(f"{BLUE}{'=' * 70}{NC}")
    
    # Analyze load test results
    load_analysis = analyze_load_test_results(results_file)
    
    # Analyze system metrics
    system_analysis = analyze_system_metrics(metrics_file)
    
    # Identify bottlenecks
    bottlenecks = identify_bottlenecks(load_analysis, system_analysis)
    
    # Generate recommendations
    recommendations = generate_recommendations(bottlenecks)
    
    # Generate verdict
    verdict = generate_verdict(load_analysis, system_analysis, bottlenecks)
    
    print(f"\n{BLUE}{'=' * 70}{NC}")
    print(f"{BLUE}Analysis complete!{NC}")
    print(f"{BLUE}{'=' * 70}{NC}\n")
    
    sys.exit(0 if verdict == "PASS" else 1)

if __name__ == '__main__':
    main()
