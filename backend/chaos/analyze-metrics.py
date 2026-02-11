#!/usr/bin/env python3

"""
Chaos Experiment Metrics Analyzer

Analyzes metrics collected during chaos experiments and generates reports.
"""

import sys
import csv
import statistics
from datetime import datetime
from collections import defaultdict

def analyze_metrics(filename):
    """Analyze metrics from CSV file"""
    
    timestamps = []
    redis_statuses = []
    circuit_states = []
    response_times = []
    
    # Read CSV
    with open(filename, 'r') as f:
        reader = csv.DictReader(f)
        for row in reader:
            timestamps.append(int(row['timestamp']))
            redis_statuses.append(row['redis_status'])
            circuit_states.append(row['circuit_state'])
            response_times.append(float(row['response_time']))
    
    if not timestamps:
        print("No data found in metrics file")
        return
    
    # Calculate statistics
    start_time = timestamps[0]
    end_time = timestamps[-1]
    duration = end_time - start_time
    
    # Response time stats
    avg_response = statistics.mean(response_times)
    p50_response = statistics.median(response_times)
    p95_response = sorted(response_times)[int(len(response_times) * 0.95)]
    p99_response = sorted(response_times)[int(len(response_times) * 0.99)]
    max_response = max(response_times)
    
    # Count states
    redis_down_count = redis_statuses.count('down')
    redis_up_count = redis_statuses.count('up')
    
    circuit_closed_count = circuit_states.count('closed')
    circuit_open_count = circuit_states.count('open')
    circuit_half_open_count = circuit_states.count('half_open')
    
    # Find state transitions
    circuit_transitions = []
    for i in range(1, len(circuit_states)):
        if circuit_states[i] != circuit_states[i-1]:
            circuit_transitions.append({
                'time': timestamps[i] - start_time,
                'from': circuit_states[i-1],
                'to': circuit_states[i]
            })
    
    # Print report
    print("=" * 60)
    print("CHAOS EXPERIMENT METRICS ANALYSIS")
    print("=" * 60)
    print()
    
    print(f"Experiment Duration: {duration}s ({duration/60:.1f} minutes)")
    print(f"Data Points: {len(timestamps)}")
    print()
    
    print("RESPONSE TIME STATISTICS")
    print("-" * 60)
    print(f"  Average:  {avg_response*1000:.2f}ms")
    print(f"  Median:   {p50_response*1000:.2f}ms")
    print(f"  p95:      {p95_response*1000:.2f}ms")
    print(f"  p99:      {p99_response*1000:.2f}ms")
    print(f"  Max:      {max_response*1000:.2f}ms")
    print()
    
    print("REDIS STATUS")
    print("-" * 60)
    print(f"  Up:       {redis_up_count} samples ({redis_up_count/len(redis_statuses)*100:.1f}%)")
    print(f"  Down:     {redis_down_count} samples ({redis_down_count/len(redis_statuses)*100:.1f}%)")
    print()
    
    print("CIRCUIT BREAKER STATE")
    print("-" * 60)
    print(f"  Closed:     {circuit_closed_count} samples ({circuit_closed_count/len(circuit_states)*100:.1f}%)")
    print(f"  Open:       {circuit_open_count} samples ({circuit_open_count/len(circuit_states)*100:.1f}%)")
    print(f"  Half-Open:  {circuit_half_open_count} samples ({circuit_half_open_count/len(circuit_states)*100:.1f}%)")
    print()
    
    if circuit_transitions:
        print("CIRCUIT BREAKER TRANSITIONS")
        print("-" * 60)
        for t in circuit_transitions:
            print(f"  T+{t['time']:3d}s: {t['from']:10s} → {t['to']}")
        print()
    
    # Calculate resilience score
    score = calculate_resilience_score(
        p95_response,
        redis_down_count,
        len(timestamps),
        circuit_transitions
    )
    
    print("RESILIENCE SCORE")
    print("-" * 60)
    print(f"  Overall Score: {score}/100")
    print(f"  Grade: {get_grade(score)}")
    print()
    
    print("=" * 60)

def calculate_resilience_score(p95_response, redis_down_count, total_samples, transitions):
    """Calculate resilience score based on metrics"""
    
    score = 0
    
    # Performance (30 points)
    if p95_response < 0.5:  # < 500ms
        score += 30
    elif p95_response < 1.0:  # < 1s
        score += 20
    elif p95_response < 2.0:  # < 2s
        score += 10
    
    # Availability (40 points)
    # System should remain operational even when Redis is down
    if redis_down_count > 0:
        # If Redis was down but system handled it
        score += 40
    else:
        # Redis never went down (not a real test)
        score += 20
    
    # Recovery (30 points)
    # Check if circuit breaker transitioned properly
    expected_transitions = ['closed→open', 'open→half_open', 'half_open→closed']
    actual_transitions = [f"{t['from']}→{t['to']}" for t in transitions]
    
    if 'closed→open' in actual_transitions or 'closed→half_open' in actual_transitions:
        score += 10  # Circuit opened
    
    if 'open→half_open' in actual_transitions:
        score += 10  # Circuit attempted recovery
    
    if 'half_open→closed' in actual_transitions or 'open→closed' in actual_transitions:
        score += 10  # Circuit recovered
    
    return min(score, 100)

def get_grade(score):
    """Convert score to letter grade"""
    if score >= 95:
        return "A+"
    elif score >= 90:
        return "A"
    elif score >= 85:
        return "A-"
    elif score >= 80:
        return "B+"
    elif score >= 75:
        return "B"
    elif score >= 70:
        return "B-"
    elif score >= 65:
        return "C+"
    elif score >= 60:
        return "C"
    else:
        return "F"

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 analyze-metrics.py <metrics.csv>")
        sys.exit(1)
    
    analyze_metrics(sys.argv[1])
