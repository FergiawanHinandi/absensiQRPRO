# Backup & Disaster Recovery Policy

## 1. Overview
Ensures business continuity by defining the strategy for data backup, retention, and restoration in the event of system failure or disaster.

## 2. Backup Strategy

| Data Type | Frequency | Retention | Storage Location | Encryption |
|-----------|-----------|-----------|------------------|------------|
| Database (Full) | Daily (02:00 UTC) | 30 Days | S3 Bucket (Off-site) | AES-256 |
| Database (Binlog) | Every 15 Mins | 7 Days | S3 Bucket (Off-site) | AES-256 |
| App Code | On Commit | Indefinite | GitHub Repository | N/A |
| User Uploads | Real-time | Indefinite | S3 (Replicated) | AES-256 |

## 3. Restoration Procedures
- **Automated Restore Test:** Weekly (Sunday). A script restores the latest backup to a staging environment and runs verify queries.
- **Manual Restore:** Follow the `BACKUP_MONITORING_GUIDE.md` for manual recovery steps.
- **RTO (Recovery Time Objective):** 4 Hours.
- **RPO (Recovery Point Objective):** 15 Minutes.

## 4. Disaster Recovery Scenarios

### Scenario A: Database Corruption
1. Stop the application (Maintenance Mode).
2. Restore the latest healthy Full Backup to a new RDS instance.
3. Replay Binlogs up to the point of failure.
4. Update application config to point to new DB.
5. Verify data integrity.
6. Resume traffic.

### Scenario B: Region Failure (AWS/Cloud)
1. Trigger DNS failover to Secondary Region (if Multi-Region enabled).
2. Promote Read Replica in Secondary Region to Master.
3. Scale up application servers in Secondary Region.
4. Notify users of potential latency.

## 5. Testing & Validation
- **Quarterly Drill:** Perform a full disaster recovery simulation.
- **Audit:** Validation logs are stored in `AdminActivityLog` with `action_type='backup_restore'`.
- **Review:** Process is reviewed annually or after significant infrastructure changes.
