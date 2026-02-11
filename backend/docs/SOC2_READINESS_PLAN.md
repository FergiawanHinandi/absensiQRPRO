# 🛡️ SOC 2 Type II Readiness Plan

**Compliance Architect**  
**Date**: 2026-02-10  
**Scope**: Security, Availability, Processing Integrity, Confidentiality, Privacy  
**Target**: SOC 2 Type II Certification (Observation Period: 6-12 Months)

---

## 📋 Control Mapping Matrix

We map technical implementations to SOC 2 Trust Services Criteria (TSC).

### 1. Security (Common Criteria - CC)

| Ref ID | SOC 2 Requirement | AbsensiQRPro Implementation | Evidence Artifact |
|:---|:---|:---|:---|
| **CC6.1** | Logical Access Security | **RBAC** via Laravel Policies/Gates. **MFA** for Admins/Teachers via TOTP. **Zero Trust Middleware** (Device Fingerprinting). | `users` table config, TenantResolver logs, MFA Screen config. |
| **CC6.7** | Data Transmission Security | **TLS 1.3** for all public endpoints. **mTLS** for internal service-to-service. **Signed Webhooks**. | SSL Certs (AWS ACM), Nginx config, Webhook signature verification code. |
| **CC6.8** | Component Security | **WAF** (AWS WAF) for filtering malicious traffic. **Rate Limiting** per tenant. **Vulnerability Scanning** in CI/CD. | WAF Rules screenshots, `ThrottleRequests` middleware, Snyk/SonarQube reports. |
| **CC7.1** | Configuration Management | **Terraform** (IaC) for all infra changes. **Migration Safe Mode** for DB changes. No manual changes in Prod. | Terraform repo commit history, CI/CD pipeline logs. |
| **CC8.1** | Change Management | **Pull Request** reviews mandatory. **CI/CD Pipeline** (Test, Lint, Security Scan) before deploy. **Blue/Green Deployment**. | GitHub PR protection settings, CircleCI/GitHub Actions logs. |

### 2. Availability (A)

| Ref ID | SOC 2 Requirement | AbsensiQRPro Implementation | Evidence Artifact |
|:---|:---|:---|:---|
| **A1.2** | Data Backup & Recovery | **S3 Immutable Backups** (AWS Object Lock). Daily DB Snapshot + 15 min Binlog. Weekly Restore Test. | AWS Backup config, Restore Test Logs (Automated). |
| **A1.3** | High Availability | **Multi-Region** (Active-Passive/Active-Active). **Redis Cluster** (Multi-AZ). **Global Accelerator**. | Architecture Diagram, Disaster Recovery Drill Report. |

### 3. Processing Integrity (PI)

| Ref ID | SOC 2 Requirement | AbsensiQRPro Implementation | Evidence Artifact |
|:---|:---|:---|:---|
| **PI1.2** | Completeness & Accuracy | **Atomic Database Locks** (Redis/DB Constraints). **Idempotent** Webhooks. **Zero Duplicate** Attendance logic. | Unit Tests (`AttendanceUniquenessTest`), DB Schema (`unique` constraints). |
| **PI1.4** | Data Storage Integrity | **Checksums** on critical data. **Replication Monitor** checking lag. | `ReplicationLagMonitor` logs, Data integrity check jobs. |

### 4. Confidentiality (C)

| Ref ID | SOC 2 Requirement | AbsensiQRPro Implementation | Evidence Artifact |
|:---|:---|:---|:---|
| **C1.1** | Data Isolation | **Per-Tenant Encryption** (KMS + DEK). **TenantResolver** Global Scope. **DB Sharding** by School. | Encryption Service Code, KMS Key Rotation Logs. |
| **C1.2** | Data Disposal | **Crypto-shredding** (Deleting the key makes data unreadable). | Key Deletion Logs, `PruneOldData` job logs. |

### 5. Privacy (P)

| Ref ID | SOC 2 Requirement | AbsensiQRPro Implementation | Evidence Artifact |
|:---|:---|:---|:---|
| **P4.3** | Data Subject Rights | **Export Personal Data** feature. **Anonymization** feature for graduated students. | Exported JSON/CSV samples, Anonymization code logic. |
| **P5.1** | Privacy Notice | **Implicit Consent** on login. Privacy Policy link on all footers. | Privacy Policy URL, User Consent Log (`accepted_terms_at`). |

---

## 🔍 Gap Analysis Checklist

Current state assessment vs Target state.

### ✅ Ready (Designed/Implemented)
*   [x] **Authentication**: RBAC, JWT, MFA foundation exists.
*   [x] **Architecture**: Sharding & Multi-region design is solid for Availability.
*   [x] **Data Integrity**: Redis Locks & DB Constraints prevent duplicates.
*   [x] **CI/CD**: Pipelines with testing exist.

### ⚠️ Needs Work (Implementation Gap)
*   [ ] **Per-Tenant Encryption**: Strategy exists (`DATA_ENCRYPTION_STRATEGY.md`), but code not fully implemented.
*   [ ] **Immutable Audit Log**: Need specific service to write logs to WORM storage (S3 Object Lock).
*   [ ] **Alerting**: Need to connect `SecurityQueryMonitor` to real alerting (PagerDuty/Slack).
*   [ ] **Disaster Recovery**: DR Plan exists on paper, but **Drills** haven't been executed.

### ❌ Critical Gaps (Missing)
*   [ ] **Policy Documents**: Formal PDFs for Incident Response, Access Control, etc.
*   [ ] **Vendor Management**: List of sub-processors (AWS, etc.) and *their* SOC 2 reports.
*   [ ] **Background Checks**: HR policy for developers with prod access.

---

## 📜 Audit Log Strategy (The "Evidence")

To satisfy **Common Criteria 2.2** (Internal Communication) and **CC6.1** (Access), we need a robust Audit Trail.

### Schema: `audit_logs` (Hot) -> `S3 Glacier` (Cold/Immutable)

```json
{
  "id": "uuid",
  "timestamp": "2026-02-10T04:00:00Z",
  "actor": {
    "id": "user_123",
    "ip": "203.0.113.1",
    "user_agent": "Mozilla/5.0..."
  },
  "event": {
    "type": "ATTENDANCE_CORRECTION",
    "resource_id": "att_999",
    "school_id": 100
  },
  "changes": {
    "before": { "status": "absent" },
    "after": { "status": "present", "reason": "System Error" }
  },
  "integrity_hash": "sha256_of_this_payload_signed_by_system_key"
}
```

**Requirement**: Logs MUST be stored in a way that *even DB admins* cannot modify (WORM - Write Once, Read Many). We will use S3 Object Lock for this.

---

## 📄 Required Policy Documents (Non-Technical)

You must draft and approve these policies. SOC 2 is 50% Tech, 50% Policy.

1.  **Acceptable Use Policy**: What users/devs can do.
2.  **Access Control Policy**: Who gets administrative access and why.
3.  **Audit & Logging Policy**: What events are logged and retention period (e.g., 1 year).
4.  **Backup & Recovery Policy**: RPO/RTO targets.
5.  **Change Management Policy**: PRs, Code Reviews, Approvals.
6.  **Incident Response Plan**: Step-by-step specific actions for breaches.
7.  **Data Classification Policy**: Public vs. Internal vs. Confidential.

---

## 🗓️ Remediation & Audit Timeline

### Phase 1: Implementation (Month 1-2)
*   Implement `SchoolEncryptionService`.
*   Implement `AuditLogService` with S3 Object Lock.
*   Deploy Multi-Region Infrastructure.
*   Finalize CI/CD Security Scanning (SAST/DAST).

### Phase 2: Policy & Training (Month 3)
*   Draft all 7 policy documents.
*   Conduct Incident Response Tabletop Exercise (Simulation).
*   Perform DR Drill (Failover test to Region B).

### Phase 3: Readiness Assessment / Mock Audit (Month 4)
*   Use a tool like Drata/Vanta or hire a consultant to check gaps.
*   Fix findings.

### Phase 4: SOC 2 Type I Audit (Month 5)
*   Snapshot of the system at a point in time.
*   Auditor verifies *design* of controls.

### Phase 5: Observation Period (Month 6-12)
*   System runs in compliance.
*   Evidence collected continuously.

### Phase 6: SOC 2 Type II Audit (Month 12)
*   Auditor verifies *operating effectiveness* over the 6-month period.
*   **Certification Issued**.

---

**Status**: ✅ Plan Created  
**Docs**: `SOC2_READINESS_PLAN.md`
