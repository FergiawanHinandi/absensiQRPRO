# 🔥 Chaos Engineering - Post-Mortem Template

**Site Reliability Engineer**  
**Date**: [Date]  
**Experiment**: [Experiment Name]

---

## 📋 Experiment Summary

| Field | Value |
|-------|-------|
| **Experiment ID** | CHAOS-[YYYY-MM-DD]-[Number] |
| **Date** | [Date] |
| **Duration** | [Duration] |
| **Environment** | Staging / Production |
| **Conducted By** | [Name] |
| **Status** | ✅ Passed / ❌ Failed / ⚠️ Partial |

---

## 🎯 Hypothesis

**What we believed would happen**:

[Describe the expected system behavior under chaos conditions]

Example:
> System can handle 10,000 concurrent attendance scans without duplicates or deadlocks. Expected error rate <1%, P95 latency <500ms.

---

## 🔬 Experiment Details

### Configuration

| Parameter | Value |
|-----------|-------|
| **Load Level** | [e.g., 1000 VUs] |
| **Duration** | [e.g., 10 seconds] |
| **Failure Type** | [e.g., Redis crash] |
| **Affected Components** | [e.g., Cache, Sessions] |

### Test Scenario

```
[Describe the exact steps taken]

Example:
1. Baseline metrics collected
2. Started k6 load test with 1000 VUs
3. Monitored for 10 seconds
4. Stopped Redis at 5 seconds
5. Observed system behavior
6. Restarted Redis at 7 seconds
7. Verified recovery
```

---

## 📊 Results

### Metrics Collected

| Metric | Target | Actual | Status |
|--------|--------|--------|--------|
| **Error Rate** | <1% | [X.XX]% | ✅/❌ |
| **P95 Latency** | <500ms | [XXX]ms | ✅/❌ |
| **DB Lock Wait** | <100ms | [XX]ms | ✅/❌ |
| **Duplicate Count** | 0 | [X] | ✅/❌ |
| **CPU Usage** | <80% | [XX]% | ✅/❌ |
| **Memory Usage** | <85% | [XX]% | ✅/❌ |
| **Queue Depth** | <5000 | [XXXX] | ✅/❌ |
| **Recovery Time** | <60s | [XX]s | ✅/❌ |

### Response Time Distribution

```
Min:    [XX]ms
Avg:    [XX]ms
P50:    [XX]ms
P95:    [XX]ms
P99:    [XX]ms
Max:    [XXX]ms
```

### Error Breakdown

| Error Type | Count | Percentage |
|------------|-------|------------|
| 500 Internal Server Error | [X] | [X.X]% |
| 503 Service Unavailable | [X] | [X.X]% |
| 409 Conflict (Duplicate) | [X] | [X.X]% |
| Timeout | [X] | [X.X]% |
| **Total Errors** | **[X]** | **[X.X]%** |

---

## 🔍 Observations

### What Went Well ✅

1. [Observation 1]
   - Example: System successfully fell back to database when Redis crashed
   - Impact: No data loss, degraded performance only

2. [Observation 2]
   - Example: Unique constraints prevented all duplicate attendance records
   - Impact: Data integrity maintained

3. [Observation 3]
   - Example: Auto-recovery completed in 45 seconds
   - Impact: Met <60 second recovery target

### What Didn't Go Well ❌

1. [Issue 1]
   - Example: Error rate spiked to 3.5% during Redis outage
   - Impact: Exceeded 1% target
   - Severity: Medium

2. [Issue 2]
   - Example: P95 latency reached 750ms
   - Impact: Exceeded 500ms target
   - Severity: Medium

3. [Issue 3]
   - Example: 2 duplicate records found
   - Impact: Data integrity compromised
   - Severity: Critical

### Unexpected Behavior ⚠️

1. [Unexpected 1]
   - Example: Queue depth increased to 8000 during DB latency
   - Expected: <5000
   - Actual: 8000
   - Reason: [Analysis]

2. [Unexpected 2]
   - Example: Memory usage spiked to 92%
   - Expected: <85%
   - Actual: 92%
   - Reason: [Analysis]

---

## 🐛 Issues Discovered

### Critical Issues 🔴

#### Issue #1: [Title]

**Description**:
[Detailed description of the issue]

**Impact**:
- Severity: Critical / High / Medium / Low
- Affected Users: [Number or percentage]
- Data Integrity: Compromised / Maintained
- Availability: Down / Degraded / Operational

**Root Cause**:
[Analysis of why this happened]

**Evidence**:
```
[Logs, screenshots, metrics]
```

**Reproduction Steps**:
1. [Step 1]
2. [Step 2]
3. [Step 3]

**Remediation**:
- [ ] Immediate fix: [Description]
- [ ] Long-term fix: [Description]
- [ ] Prevention: [Description]

---

### High Priority Issues 🟠

#### Issue #2: [Title]

[Same structure as above]

---

### Medium Priority Issues 🟡

#### Issue #3: [Title]

[Same structure as above]

---

## 📈 Performance Analysis

### Load Characteristics

```
Total Requests:     [XXXXX]
Successful:         [XXXXX] ([XX.X]%)
Failed:             [XXX] ([X.X]%)
Requests/sec:       [XXXX]
Data Transferred:   [XX] MB
```

### Resource Utilization

| Resource | Baseline | Peak | Average |
|----------|----------|------|---------|
| CPU | [XX]% | [XX]% | [XX]% |
| Memory | [XX]% | [XX]% | [XX]% |
| Disk I/O | [XX] MB/s | [XX] MB/s | [XX] MB/s |
| Network | [XX] MB/s | [XX] MB/s | [XX] MB/s |

### Database Performance

| Metric | Baseline | During Chaos | Recovery |
|--------|----------|--------------|----------|
| Queries/sec | [XXX] | [XXX] | [XXX] |
| Lock Wait Time | [XX]ms | [XX]ms | [XX]ms |
| Deadlocks | 0 | [X] | 0 |
| Connection Pool | [XX]% | [XX]% | [XX]% |

---

## 🔄 Recovery Analysis

### Recovery Timeline

```
00:00 - Chaos injection started
00:05 - First errors detected
00:10 - Error rate peaked at [X]%
00:15 - Recovery initiated
00:20 - Services restarted
00:25 - Health checks passing
00:30 - System fully recovered
```

### Recovery Metrics

| Metric | Target | Actual | Status |
|--------|--------|--------|--------|
| Detection Time | <10s | [X]s | ✅/❌ |
| Recovery Time | <60s | [X]s | ✅/❌ |
| Manual Intervention | No | Yes/No | ✅/❌ |
| Data Consistency | 100% | [XX]% | ✅/❌ |

---

## 💡 Lessons Learned

### Technical Insights

1. **[Insight 1]**
   - Learning: [What we learned]
   - Application: [How to apply this]

2. **[Insight 2]**
   - Learning: [What we learned]
   - Application: [How to apply this]

### Process Improvements

1. **[Improvement 1]**
   - Current: [Current process]
   - Proposed: [Improved process]
   - Benefit: [Expected benefit]

2. **[Improvement 2]**
   - Current: [Current process]
   - Proposed: [Improved process]
   - Benefit: [Expected benefit]

---

## 📝 Action Items

### Immediate Actions (Within 24 hours)

- [ ] **[Action 1]**
  - Owner: [Name]
  - Deadline: [Date]
  - Priority: Critical
  - Status: Not Started / In Progress / Done

- [ ] **[Action 2]**
  - Owner: [Name]
  - Deadline: [Date]
  - Priority: High
  - Status: Not Started / In Progress / Done

### Short-term Actions (Within 1 week)

- [ ] **[Action 3]**
  - Owner: [Name]
  - Deadline: [Date]
  - Priority: Medium
  - Status: Not Started / In Progress / Done

### Long-term Actions (Within 1 month)

- [ ] **[Action 4]**
  - Owner: [Name]
  - Deadline: [Date]
  - Priority: Low
  - Status: Not Started / In Progress / Done

---

## 🎯 Recommendations

### Architecture Changes

1. **[Recommendation 1]**
   - Current: [Current architecture]
   - Proposed: [Proposed change]
   - Benefit: [Expected benefit]
   - Effort: Low / Medium / High
   - Priority: Critical / High / Medium / Low

2. **[Recommendation 2]**
   - Current: [Current architecture]
   - Proposed: [Proposed change]
   - Benefit: [Expected benefit]
   - Effort: Low / Medium / High
   - Priority: Critical / High / Medium / Low

### Monitoring Improvements

1. **[Improvement 1]**
   - Gap: [What's missing]
   - Solution: [Proposed monitoring]
   - Benefit: [Expected benefit]

2. **[Improvement 2]**
   - Gap: [What's missing]
   - Solution: [Proposed monitoring]
   - Benefit: [Expected benefit]

### Testing Improvements

1. **[Improvement 1]**
   - Gap: [What's missing]
   - Solution: [Proposed test]
   - Benefit: [Expected benefit]

---

## 📊 Conclusion

### Overall Assessment

**Status**: ✅ Passed / ❌ Failed / ⚠️ Partial

**Summary**:
[1-2 paragraph summary of the experiment results]

Example:
> The chaos experiment successfully validated that the system can handle 10,000 concurrent requests with an error rate of 0.8%, meeting the <1% target. However, P95 latency reached 750ms, exceeding the 500ms target. Two duplicate records were found, indicating a race condition in the unique constraint check. Overall, the system demonstrated good resilience but requires improvements in latency optimization and duplicate prevention.

### Success Criteria Met

- ✅ Error rate <1%: PASS (0.8%)
- ❌ P95 latency <500ms: FAIL (750ms)
- ❌ Zero duplicates: FAIL (2 found)
- ✅ Recovery <60s: PASS (45s)
- ✅ No data corruption: PASS
- ✅ No tenant leakage: PASS

**Overall Score**: 4/6 (67%)

---

## 📎 Attachments

- [ ] k6 test results (JSON)
- [ ] Grafana dashboard screenshots
- [ ] Error logs
- [ ] Database slow query log
- [ ] System metrics (CPU, Memory, Disk)
- [ ] Network traces
- [ ] Video recording (if applicable)

---

## ✍️ Sign-off

**Conducted By**:
- Name: [Name]
- Role: Site Reliability Engineer
- Date: [Date]
- Signature: ________________

**Reviewed By**:
- Name: [Name]
- Role: Engineering Manager
- Date: [Date]
- Signature: ________________

**Approved By**:
- Name: [Name]
- Role: CTO / VP Engineering
- Date: [Date]
- Signature: ________________

---

**Document Version**: 1.0  
**Last Updated**: [Date]  
**Next Review**: [Date + 3 months]
