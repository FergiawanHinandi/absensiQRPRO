# Disaster Recovery Plan (DRP)

## 1. Introduction
This plan outlines the specific technical steps to recover the AbsensiQRPro system in the event of a catastrophic failure. It is a subset of the broader Business Continuity Plan.

## 2. Emergency Contacts
| Role | Name | Phone | Email |
|------|------|-------|-------|
| Incident Commander | [Name] | [Phone] | [Email] |
| Lead DevOps | [Name] | [Phone] | [Email] |
| Database Admin | [Name] | [Phone] | [Email] |
| Legal Counsel | [Name] | [Phone] | [Email] |

## 3. Critical Systems Inventory
1. **Core Database (MySQL):** Criticality: HIGH. RTO: 4h. RPO: 15m.
2. **Redis Cache/Session:** Criticality: MEDIUM. RTO: 1h. RPO: N/A (Ephemeral).
3. **File Storage (S3):** Criticality: HIGH. RTO: 24h. RPO: 1h.
4. **Compute (App Servers):** Criticality: HIGH. RTO: 1h.

## 4. Activation Criteria
This plan is activated when:
- Primary Data Center is unreachable for > 1 hour.
- Data corruption affects > 20% of customer base.
- Security breach compromises system integrity.

## 5. Recovery Procedures

### 5.1. Database Failover
1. **Verify Primary is Down:** Confirm via AWS Console / Monitoring.
2. **Promote Replica:** 
   ```bash
   # Example Command (AWS RDS)
   aws rds promote-read-replica --db-instance-identifier absensi-replica-1
   ```
3. **Update Config:** Change `DB_HOST` in `.env` (or via Secrets Manager) to new Replica endpoint.
4. **Restart Application:** `php artisan config:clear && php artisan queue:restart`.

### 5.2. Web Server Restoration
1. If Compute is down, trigger Auto Scaling Group to launch new instances in Secondary Availability Zone (AZ).
2. Verify Load Balancer health checks are passing.

### 5.3. Communication
1. Update Status Page (Statuspage.io / In-app banner).
2. Email School Admins with estimated recovery time.

## 6. Post-Recovery
1. Verify data consistency (run Integrity Check script).
2. Perform root cause analysis (RCA).
3. Synchronize old Primary (if recoverable) as new Replica.

## 7. Plan Maintenance
- This plan is reviewed quarterly.
- Last Tested: [Date]
- Next Test Due: [Date]
