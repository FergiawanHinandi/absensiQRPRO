# Redis Sentinel Deployment Guide

This guide covers deploying Redis Sentinel high availability cluster for AbsensiQR Pro in different environments.

## Pre-Deployment Checklist

### Security Requirements
- [ ] Strong Redis password configured (minimum 32 characters)
- [ ] Firewall rules configured to restrict Redis ports
- [ ] TLS/SSL certificates prepared for production
- [ ] Backup storage configured and tested
- [ ] Monitoring and alerting configured

### Infrastructure Requirements
- [ ] Minimum 2GB RAM per Redis node
- [ ] SSD storage for Redis data persistence
- [ ] Low-latency network between nodes
- [ ] Docker and Docker Compose installed
- [ ] Sufficient disk space for backups (3x data size)

### Network Requirements
- [ ] Ports 6379-6381 available for Redis nodes
- [ ] Ports 26379-26381 available for Sentinel nodes
- [ ] Internal network configured for cluster communication
- [ ] External access restricted to application servers only

## Development Environment

### Setup
```bash
# 1. Navigate to redis directory
cd backend/redis

# 2. Copy environment template
cp .env.example .env

# 3. Edit .env and set development password
# Use a simple password for development only
nano .env

# 4. Start cluster
./start-redis-sentinel.sh
# or on Windows:
start-redis-sentinel.bat

# 5. Verify cluster status
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters
```

### Development Configuration
- Use `config/development.env` settings
- Lower memory limits (256MB per node)
- Verbose logging enabled
- No TLS encryption required

## Staging Environment

### Setup
```bash
# 1. Copy staging configuration
cp config/staging.env .env

# 2. Update passwords
# Generate strong password:
openssl rand -base64 32

# 3. Update .env with generated password
nano .env

# 4. Start cluster with staging config
docker-compose -f docker-compose.redis-sentinel.yml up -d

# 5. Verify cluster health
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO replication
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters
```

### Staging Configuration
- Use `config/staging.env` settings
- Production-like memory limits (512MB)
- Standard logging
- Test TLS configuration if planned for production

## Production Environment

### Pre-Production Steps

1. **Generate Strong Credentials**
```bash
# Generate Redis password (64 characters)
openssl rand -base64 48

# Save to secure password manager
```

2. **Configure TLS/SSL** (Recommended)
```bash
# Generate certificates
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout redis.key -out redis.crt

# Update redis-master.conf
echo "tls-port 6380" >> config/redis-master.conf
echo "tls-cert-file /etc/redis/certs/redis.crt" >> config/redis-master.conf
echo "tls-key-file /etc/redis/certs/redis.key" >> config/redis-master.conf
```

3. **Configure Firewall**
```bash
# Allow only application servers to access Redis
# Example using ufw:
sudo ufw allow from APP_SERVER_IP to any port 6379
sudo ufw allow from APP_SERVER_IP to any port 26379
```

### Production Deployment

```bash
# 1. Copy production configuration
cp config/production.env .env

# 2. Update all passwords and secrets
nano .env

# 3. Review and update Docker Compose for production
# - Add resource limits
# - Configure restart policies
# - Add health checks
# - Configure logging drivers

# 4. Deploy cluster
docker-compose -f docker-compose.redis-sentinel.yml up -d

# 5. Verify deployment
./verify-cluster.sh

# 6. Test failover
docker stop redis-master
# Wait 30 seconds
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster
# Verify new master was elected

# 7. Restore original master
docker start redis-master
```

### Production Configuration
- Use `config/production.env` settings
- High memory limits (1024MB+)
- Warning-level logging only
- TLS encryption enabled
- Automated backups configured
- Monitoring and alerting active

## Docker Compose Production Enhancements

Add these configurations to `docker-compose.redis-sentinel.yml` for production:

```yaml
services:
  redis-master:
    # ... existing config ...
    restart: always
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 1024M
        reservations:
          cpus: '1'
          memory: 512M
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "3"
```

## Kubernetes Deployment (Optional)

For Kubernetes environments, use StatefulSets:

```yaml
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: redis-sentinel
spec:
  serviceName: redis-sentinel
  replicas: 3
  selector:
    matchLabels:
      app: redis-sentinel
  template:
    metadata:
      labels:
        app: redis-sentinel
    spec:
      containers:
      - name: redis
        image: redis:7-alpine
        # ... configuration ...
      - name: sentinel
        image: redis:7-alpine
        # ... configuration ...
```

## Monitoring Setup

### Prometheus Metrics
```bash
# Install Redis exporter
docker run -d --name redis-exporter \
  --network redis-network \
  -p 9121:9121 \
  oliver006/redis_exporter \
  --redis.addr=redis://redis-master:6379 \
  --redis.password=YOUR_PASSWORD
```

### Grafana Dashboard
- Import Redis dashboard ID: 11835
- Configure alerts for:
  - Replication lag > 10 seconds
  - Memory usage > 80%
  - Failed commands rate
  - Sentinel failover events

## Backup Configuration

### Automated Backups
```bash
# Create backup script
cat > /opt/redis/backup.sh << 'EOF'
#!/bin/bash
BACKUP_DIR="/backup/redis"
DATE=$(date +%Y%m%d_%H%M%S)

# Trigger RDB snapshot
docker exec redis-master redis-cli -a $REDIS_PASSWORD BGSAVE

# Wait for snapshot to complete
sleep 10

# Copy snapshot
docker cp redis-master:/data/dump.rdb $BACKUP_DIR/dump_$DATE.rdb

# Compress
gzip $BACKUP_DIR/dump_$DATE.rdb

# Upload to S3 (optional)
aws s3 cp $BACKUP_DIR/dump_$DATE.rdb.gz s3://your-bucket/redis-backups/

# Cleanup old backups (keep 30 days)
find $BACKUP_DIR -name "dump_*.rdb.gz" -mtime +30 -delete
EOF

chmod +x /opt/redis/backup.sh

# Add to crontab (daily at 2 AM)
echo "0 2 * * * /opt/redis/backup.sh" | crontab -
```

## Disaster Recovery Procedures

### Complete Cluster Failure
```bash
# 1. Stop all containers
docker-compose -f docker-compose.redis-sentinel.yml down

# 2. Restore latest backup
gunzip /backup/redis/dump_LATEST.rdb.gz
docker cp /backup/redis/dump_LATEST.rdb redis-master:/data/dump.rdb

# 3. Start cluster
docker-compose -f docker-compose.redis-sentinel.yml up -d

# 4. Verify data integrity
docker exec redis-master redis-cli -a YOUR_PASSWORD DBSIZE
```

### Single Node Failure
```bash
# Redis automatically handles single node failures
# Sentinel will promote a replica if master fails

# To manually recover a failed node:
docker restart redis-replica-1

# Verify replication resumed
docker exec redis-replica-1 redis-cli -a YOUR_PASSWORD INFO replication
```

## Performance Tuning

### For High Write Loads
```conf
# Update redis-master.conf
appendfsync no  # Less durable but faster
repl-backlog-size 10mb  # Larger backlog
maxmemory 2048mb  # More memory
```

### For High Read Loads
```yaml
# Add more replicas in docker-compose.yml
redis-replica-3:
  # ... same config as replica-1 ...
  ports:
    - "6382:6379"
```

### For Large Datasets
```conf
# Update redis configuration
maxmemory 4096mb
maxmemory-policy allkeys-lru
save ""  # Disable RDB if using AOF only
```

## Troubleshooting Production Issues

### High Memory Usage
```bash
# Check memory stats
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO memory

# Analyze key distribution
docker exec redis-master redis-cli -a YOUR_PASSWORD --bigkeys

# Force eviction
docker exec redis-master redis-cli -a YOUR_PASSWORD CONFIG SET maxmemory-policy allkeys-lru
```

### Replication Lag
```bash
# Check replication status
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO replication

# Check network latency
docker exec redis-master ping redis-replica-1

# Increase replication buffer
docker exec redis-master redis-cli -a YOUR_PASSWORD CONFIG SET repl-backlog-size 10485760
```

### Sentinel Not Failing Over
```bash
# Check sentinel status
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters

# Check quorum
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL ckquorum mymaster

# Force failover (if needed)
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL failover mymaster
```

## Rollback Procedures

### Rollback to Previous Version
```bash
# 1. Stop current cluster
docker-compose -f docker-compose.redis-sentinel.yml down

# 2. Restore previous configuration
git checkout HEAD~1 -- redis/

# 3. Restore data from backup
# (see Disaster Recovery section)

# 4. Start cluster with previous version
docker-compose -f docker-compose.redis-sentinel.yml up -d
```

## Post-Deployment Validation

Run the verification script:
```bash
./verify-cluster.sh
```

Expected output:
- ✓ All Redis nodes running
- ✓ All Sentinels running
- ✓ Master elected and replicating
- ✓ Failover test successful
- ✓ Data persistence working
- ✓ Authentication working

## Maintenance Windows

### Zero-Downtime Updates
```bash
# 1. Update replicas first
docker-compose -f docker-compose.redis-sentinel.yml up -d redis-replica-1
# Wait for sync
docker-compose -f docker-compose.redis-sentinel.yml up -d redis-replica-2
# Wait for sync

# 2. Trigger failover to updated replica
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL failover mymaster

# 3. Update old master (now replica)
docker-compose -f docker-compose.redis-sentinel.yml up -d redis-master
```

## Support and Escalation

### Log Collection
```bash
# Collect all logs for support
docker-compose -f docker-compose.redis-sentinel.yml logs > redis-logs.txt

# Collect configuration
tar -czf redis-config.tar.gz config/

# Collect metrics
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO ALL > redis-info.txt
```

### Emergency Contacts
- Redis Support: [support contact]
- DevOps Team: [team contact]
- On-Call Engineer: [on-call rotation]

## Compliance and Auditing

### Audit Logging
```bash
# Enable Redis command logging (production)
docker exec redis-master redis-cli -a YOUR_PASSWORD CONFIG SET slowlog-log-slower-than 0

# Review slow log
docker exec redis-master redis-cli -a YOUR_PASSWORD SLOWLOG GET 100
```

### Security Audits
- Review access logs monthly
- Rotate passwords quarterly
- Update Redis version within 30 days of security releases
- Conduct failover drills quarterly

## Additional Resources

- [Redis Sentinel Documentation](https://redis.io/docs/management/sentinel/)
- [Redis Security Best Practices](https://redis.io/docs/management/security/)
- [Docker Compose Production Guide](https://docs.docker.com/compose/production/)
- [AbsensiQR Pro Documentation](../../docs/)
