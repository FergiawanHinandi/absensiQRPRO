# Redis Sentinel High Availability Setup

This directory contains the configuration and setup files for Redis Sentinel high availability cluster for AbsensiQR Pro.

## Architecture Overview

The Redis HA cluster consists of:
- **1 Redis Master**: Primary node handling write operations
- **2 Redis Replicas**: Secondary nodes replicating master data
- **3 Redis Sentinels**: Monitoring and automatic failover coordination

## Quick Start

### Prerequisites
- Docker and Docker Compose installed
- Minimum 2GB RAM available for Redis cluster
- Ports 6379-6381 and 26379-26381 available

### Setup Steps

1. **Configure Environment Variables**
   ```bash
   cd backend/redis
   cp .env.example .env
   # Edit .env and set a strong REDIS_PASSWORD
   ```

2. **Start the Redis Sentinel Cluster**
   ```bash
   cd backend
   docker-compose -f docker-compose.redis-sentinel.yml up -d
   ```

3. **Verify Cluster Status**
   ```bash
   # Check master status
   docker exec redis-master redis-cli -a YOUR_PASSWORD INFO replication
   
   # Check sentinel status
   docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters
   ```

4. **Update Laravel Configuration**
   Update `backend/.env` with:
   ```env
   REDIS_SENTINELS=true
   REDIS_PASSWORD=your_redis_password
   ```

## Directory Structure

```
redis/
├── config/
│   ├── redis-master.conf       # Master node configuration
│   ├── redis-replica.conf      # Replica nodes configuration
│   ├── sentinel-1.conf         # Sentinel 1 configuration
│   ├── sentinel-2.conf         # Sentinel 2 configuration
│   ├── sentinel-3.conf         # Sentinel 3 configuration
│   ├── development.env         # Development environment settings
│   ├── staging.env             # Staging environment settings
│   └── production.env          # Production environment settings
├── .env.example                # Environment template
└── README.md                   # This file
```

## Configuration Details

### Redis Persistence

Both AOF (Append Only File) and RDB (Redis Database) persistence are enabled:

**AOF Configuration:**
- `appendonly yes`: Enable AOF persistence
- `appendfsync everysec`: Sync to disk every second
- Auto-rewrite when file grows 100% and reaches 64MB

**RDB Configuration:**
- Save after 900 seconds if at least 1 key changed
- Save after 300 seconds if at least 10 keys changed
- Save after 60 seconds if at least 10000 keys changed

### Sentinel Configuration

**Key Parameters:**
- `quorum: 2`: Minimum 2 sentinels must agree for failover
- `down-after-milliseconds: 5000`: Mark master down after 5 seconds
- `failover-timeout: 30000`: Maximum 30 seconds for failover
- `parallel-syncs: 1`: Sync one replica at a time during failover

### Security Settings

- **Authentication**: Password required for all connections
- **Master Auth**: Replicas authenticate to master
- **Network Isolation**: Internal Docker network for cluster communication

### Memory Management

- **Max Memory**: 512MB per node (configurable)
- **Eviction Policy**: `allkeys-lru` (Least Recently Used)
- **Memory Samples**: 5 keys sampled for LRU

## Environment-Specific Configuration

### Development
- Lower memory limits (256MB)
- Verbose logging
- Relaxed security for local testing

### Staging
- Production-like configuration
- Medium memory limits (512MB)
- Standard logging

### Production
- High memory limits (1024MB)
- Warning-level logging only
- Strong password requirements
- TLS encryption (configure separately)

## Common Operations

### Start Cluster
```bash
docker-compose -f docker-compose.redis-sentinel.yml up -d
```

### Stop Cluster
```bash
docker-compose -f docker-compose.redis-sentinel.yml down
```

### View Logs
```bash
# All services
docker-compose -f docker-compose.redis-sentinel.yml logs -f

# Specific service
docker logs -f redis-master
docker logs -f redis-sentinel-1
```

### Check Cluster Health
```bash
# Master info
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO replication

# Sentinel status
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL masters
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL replicas mymaster
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL sentinels mymaster
```

### Test Failover
```bash
# Simulate master failure
docker stop redis-master

# Watch sentinel logs for failover
docker logs -f redis-sentinel-1

# Check new master
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL get-master-addr-by-name mymaster
```

### Manual Failover
```bash
# Trigger manual failover
docker exec redis-sentinel-1 redis-cli -p 26379 SENTINEL failover mymaster
```

## Monitoring

### Health Checks
All Redis nodes have health checks configured:
- Interval: 5 seconds
- Timeout: 3 seconds
- Retries: 5 attempts

### Key Metrics to Monitor
- Replication lag
- Memory usage
- Connected clients
- Commands per second
- Keyspace hits/misses
- Failover events

### Monitoring Commands
```bash
# Memory usage
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO memory

# Stats
docker exec redis-master redis-cli -a YOUR_PASSWORD INFO stats

# Clients
docker exec redis-master redis-cli -a YOUR_PASSWORD CLIENT LIST

# Slow log
docker exec redis-master redis-cli -a YOUR_PASSWORD SLOWLOG GET 10
```

## Backup and Recovery

### Manual Backup
```bash
# Trigger RDB snapshot
docker exec redis-master redis-cli -a YOUR_PASSWORD BGSAVE

# Copy backup file
docker cp redis-master:/data/dump.rdb ./backup/dump-$(date +%Y%m%d).rdb
```

### Restore from Backup
```bash
# Stop cluster
docker-compose -f docker-compose.redis-sentinel.yml down

# Copy backup to volume
docker cp ./backup/dump.rdb redis-master:/data/dump.rdb

# Start cluster
docker-compose -f docker-compose.redis-sentinel.yml up -d
```

## Troubleshooting

### Sentinel Not Detecting Master
- Check network connectivity between containers
- Verify password configuration matches
- Check sentinel logs for errors

### Replication Lag
- Check network latency
- Verify master is not overloaded
- Check disk I/O performance

### Failover Not Triggering
- Verify quorum is met (2 sentinels minimum)
- Check sentinel configuration
- Ensure sentinels can reach all nodes

### Connection Refused
- Verify ports are not blocked
- Check Docker network configuration
- Ensure Redis is running: `docker ps`

## Security Best Practices

1. **Change Default Passwords**: Never use default passwords in production
2. **Network Isolation**: Use Docker networks or VPNs
3. **TLS Encryption**: Enable TLS for production (requires additional configuration)
4. **Regular Updates**: Keep Redis version updated
5. **Access Control**: Limit Redis commands using ACLs (Redis 6+)
6. **Firewall Rules**: Restrict access to Redis ports

## Performance Tuning

### For High Write Loads
- Increase `repl-backlog-size`
- Adjust `appendfsync` to `no` (less durable but faster)
- Increase `maxmemory`

### For High Read Loads
- Add more replicas
- Enable replica reads in application
- Increase connection pool size

### For Large Datasets
- Increase `maxmemory`
- Adjust eviction policy
- Consider Redis Cluster for sharding

## Integration with Laravel

See the main Laravel configuration in `backend/config/database.php` for Redis Sentinel integration details.

Key Laravel environment variables:
```env
REDIS_SENTINELS=true
REDIS_PASSWORD=your_password
REDIS_CLIENT=predis
```

## Additional Resources

- [Redis Sentinel Documentation](https://redis.io/docs/management/sentinel/)
- [Redis Persistence](https://redis.io/docs/management/persistence/)
- [Redis Security](https://redis.io/docs/management/security/)
- [Laravel Redis Documentation](https://laravel.com/docs/11.x/redis)

## Support

For issues or questions:
1. Check logs: `docker-compose logs`
2. Verify configuration files
3. Review Laravel logs: `backend/storage/logs/laravel.log`
4. Consult Redis Sentinel documentation
