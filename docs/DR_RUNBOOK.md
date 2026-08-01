# Disaster Recovery Runbook - AbsensiQRPro

## Overview
Dokumen ini berisi prosedur recovery untuk berbagai skenario disaster. Setiap tim operasional WAJIB memahami dan menguji prosedur ini sebelum production launch.

---

## Quick Reference

| Skenario | RTO | RPO | Prioritas |
|----------|-----|-----|-----------|
| Database Corruption | 30 menit | 15 menit | CRITICAL |
| Server Downtime | 15 menit | 0 | CRITICAL |
| Failed Deployment | 10 menit | 0 | HIGH |
| Data Center Outage | 2 jam | 15 menit | CRITICAL |
| Ransomware/Security Breach | 4 jam | 15 menit | CRITICAL |
| DDoS Attack | 30 menit | 0 | HIGH |

---

## 1. Database Corruption

### Symptoms
- Query errors di application logs
- Data inconsistency
- Application tidak bisa start
- "Connection refused" atau "Too many connections"

### Recovery Steps

```bash
# 1. Stop application
sudo supervisorctl stop absensiqrpro-worker:*
sudo systemctl stop nginx

# 2. Identify last good backup
ls -la /backups/absensiqrpro/database/ | tail -10

# 3. Restore from backup
# Option A: Full restore
pg_restore -d absensiqrpro -U absensiqrpro_user -c /backups/absensiqrpro/database/latest.dump

# Option B: Point-in-time recovery (if WAL archiving enabled)
# pg_waldump /backups/absensiqrpro/wal/ | grep -i "BEGIN"

# 4. Verify data integrity
php artisan db:table users --count
php artisan db:table attendances --count

# 5. Restart application
sudo systemctl start nginx
sudo supervisorctl start absensiqrpro-worker:*

# 6. Verify
curl -f http://localhost/health
```

### Rollback
If restore fails:
```bash
# Restore from pre-corruption backup
pg_restore -d absensiqrpro -U absensiqrpro_user -c /backups/absensiqrpro/database/pre-corruption.dump
```

---

## 2. Server Downtime

### Symptoms
- Application unreachable from browser
- Health check returns 5xx or timeout
- Monitoring alerts firing

### Recovery Steps

```bash
# 1. SSH to server
ssh deploy@your-server-ip

# 2. Check services status
sudo supervisorctl status
sudo systemctl status nginx
sudo systemctl status postgresql
sudo systemctl status redis-server

# 3. Check logs
tail -50 /var/log/supervisor/absensiqrpro-*.log
tail -50 /var/log/nginx/error.log

# 4. Restart failed services
sudo supervisorctl restart all
sudo systemctl restart nginx

# 5. Check disk space
df -h
du -sh /var/www/absensiqrpro/storage/*

# 6. Check memory
free -m

# 7. Verify
curl -f http://localhost/health
```

### If Server is Completely Down
```bash
# 1. From external machine, check if server is pingable
ping your-server-ip

# 2. If using cloud provider, check console/access
# - AWS: Check EC2 instance status
# - GCP: Check Compute Engine instance
# - DigitalOcean: Check droplet status

# 3. If needed, restart instance from cloud console

# 4. After server is back, follow steps above
```

---

## 3. Failed Deployment

### Symptoms
- Application errors after deployment
- Health check fails
- 500 errors on all pages

### Recovery Steps

```bash
# 1. Immediate rollback
cd /var/www/absensiqrpro

# 2. Get last working commit
LAST_COMMIT=$(cat /tmp/last_deployed_commit)

# 3. Reset to last commit
git reset --hard $LAST_COMMIT

# 4. Reinstall dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# 5. Rollback migrations if needed
php artisan migrate:rollback --force

# 6. Clear caches
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Restart services
php artisan queue:restart
sudo supervisorctl restart absensiqrpro-worker:*

# 8. Verify
curl -f http://localhost/health
```

### Prevention
- Always run tests before deployment
- Use staging environment first
- Keep backup of last working commit

---

## 4. Data Center Outage (Cloud Provider)

### Symptoms
- All services unreachable
- Cloud provider status page shows incident
- Multiple region/zone affected

### Recovery Steps

```bash
# 1. Check cloud provider status page
# - AWS: https://health.aws.amazon.com
# - GCP: https://status.cloud.google.com
# - DigitalOcean: https://status.digitalocean.com

# 2. If multi-region setup, failover to secondary region
# - Update DNS to point to secondary region
# - Or use load balancer failover

# 3. If single region, wait for provider recovery

# 4. After recovery, verify all services
ssh deploy@your-server-ip
sudo supervisorctl status
curl -f http://localhost/health

# 5. Verify data integrity
php artisan db:table users --count
php artisan db:table attendances --count
```

### Prevention
- Use multi-region deployment
- Regular backups to different region
- Have failover DNS configured

---

## 5. Ransomware / Security Breach

### Symptoms
- Unexpected file changes
- Unknown processes running
- Suspicious database queries
- Data encrypted or exfiltrated

### IMMEDIATE ACTIONS
```bash
# 1. ISOLATE - Disconnect from network
sudo iptables -A INPUT -j DROP
sudo iptables -A OUTPUT -j DROP

# 2. PRESERVE EVIDENCE
# DO NOT delete anything
# DO NOT reboot (unless necessary)
# Copy logs to external location
cp -r /var/log/ /external/backup/logs/
cp -r /var/www/absensiqrpro/storage/logs/ /external/backup/app-logs/

# 3. CHECK RUNNING PROCESSES
ps aux | grep -v "^root"
netstat -tulpn
lsof -i

# 4. CHECK CRONTAB
crontab -l
crontab -u www-data -l
ls -la /etc/cron.*
```

### Recovery Steps
```bash
# 1. From clean backup, restore application
# 2. Change ALL credentials:
#    - Database password
#    - Redis password
#    - APP_KEY
#    - JWT_SECRET
#    - All API keys
#    - SSH keys

# 3. Review and patch vulnerabilities

# 4. Deploy from clean git repository

# 5. Verify no backdoors exist

# 6. Monitor closely for 48 hours
```

### Post-Incident
- Document what happened
- Report to authorities if data breached
- Notify affected users
- Implement additional security measures

---

## 6. DDoS Attack

### Symptoms
- High traffic volume
- Application slow or unresponsive
- Server resources maxed out

### Mitigation Steps

```bash
# 1. Enable rate limiting at firewall level
sudo iptables -A INPUT -p tcp --dport 80 -m limit --limit 100/min --limit-burst 200 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 443 -m limit --limit 100/min --limit-burst 200 -j ACCEPT
sudo iptables -A INPUT -p tcp --dport 80 -j DROP
sudo iptables -A INPUT -p tcp --dport 443 -j DROP

# 2. If using Cloudflare, enable "I'm Under Attack" mode

# 3. Block suspicious IPs
sudo iptables -A INPUT -s SUSPICIOUS_IP -j DROP

# 4. Increase server resources (if using cloud)
# - Scale up instance
# - Add more load balancers

# 5. Contact hosting provider for DDoS protection

# 6. Monitor and adjust rules
```

### Prevention
- Use Cloudflare or similar CDN/WAF
- Implement rate limiting in application
- Use auto-scaling for sudden traffic spikes

---

## Emergency Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| Tech Lead | [NAME] | [PHONE] | [EMAIL] |
| DevOps | [NAME] | [PHONE] | [EMAIL] |
| Security | [NAME] | [PHONE] | [EMAIL] |
| Cloud Provider | [PROVIDER] | [SUPPORT_PHONE] | [SUPPORT_EMAIL] |

---

## Backup Schedule

| Type | Frequency | Retention | Location |
|------|-----------|-----------|----------|
| Full Database | Daily 2AM | 30 days | Local + S3 |
| Incremental | Every 15 min | 24 hours | Local |
| Files | Daily 2:45AM | 30 days | Local + S3 |
| Logs | Daily 4AM | 90 days | Local |

---

## Testing Schedule

| Test | Frequency | Last Tested | Next Test |
|------|-----------|-------------|-----------|
| Backup Restoration | Monthly | [DATE] | [DATE] |
| DR Drill | Quarterly | [DATE] | [DATE] |
| Security Scan | Monthly | [DATE] | [DATE] |
| Load Test | Monthly | [DATE] | [DATE] |

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 1.0 | 2026-07-29 | [AUTHOR] | Initial version |

---

## Notes

1. **RTO (Recovery Time Objective)**: Maximum acceptable time to restore service
2. **RPO (Recovery Point Objective)**: Maximum acceptable data loss (in time)
3. Always test recovery procedures in staging before production
4. Document any deviations from this runbook
5. Update this runbook after any incident
