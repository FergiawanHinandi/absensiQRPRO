# Laravel Load Balancer Setup Guide

## Overview

High-availability load balancer setup for Laravel application with automatic health checks, failover, and Redis-based session management.

## 🏗️ Architecture

```
Internet → Nginx Load Balancer → App Server 1 (Primary)
                           → App Server 2 (Primary)
                           → App Server 3 (Backup)
                           → Redis Cluster (Sessions/Cache)
```

## ⚙️ Nginx Configuration

### Key Features
- **Least Connections Algorithm**: Distributes load based on active connections
- **Health Checks**: 10-second interval with automatic failover
- **SSL Termination**: HTTPS with modern security headers
- **Rate Limiting**: API protection with different limits for endpoints
- **Gzip Compression**: Optimized content delivery
- **Static File Caching**: 1-year cache for assets

### Server Configuration
```nginx
upstream laravel_app_servers {
    least_conn;
    
    # Primary servers
    server app1.yourdomain.com:80 max_fails=3 fail_timeout=30s weight=1;
    server app2.yourdomain.com:80 max_fails=3 fail_timeout=30s weight=1;
    
    # Backup server (only used when primaries fail)
    server app3.yourdomain.com:80 max_fails=3 fail_timeout=30s weight=1 backup;
    
    keepalive 32;
}
```

### Health Check Configuration
```nginx
# Load balancer health check
location /nginx-health {
    access_log off;
    return 200 "healthy\n";
    add_header Content-Type text/plain;
}

# Application health check (proxied to app servers)
location /health {
    proxy_pass http://laravel_app_servers;
    proxy_connect_timeout 5s;
    proxy_send_timeout 5s;
    proxy_read_timeout 5s;
    proxy_next_upstream off;
}
```

## 🐳 Docker Setup

### Complete Stack
```yaml
version: '3.8'

services:
  nginx-lb:
    image: nginx:alpine
    ports:
      - "80:80"
      - "443:443"
    healthcheck:
      test: ["CMD", "wget", "--quiet", "--tries=1", "--spider", "http://localhost/nginx-health"]
      interval: 10s
      timeout: 5s
      retries: 3

  app1:
    build: .
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 10s
      timeout: 5s
      retries: 3

  app2:
    build: .
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 10s
      timeout: 5s
      retries: 3

  redis-cluster:
    image: redis:7-alpine
    command: redis-server --appendonly yes
```

## 🔍 Health Check Endpoints

### Simple Health Check
```http
GET /health
Response: {"status": "healthy", "timestamp": "2024-01-01T12:00:00Z"}
```

### Detailed Health Check
```http
GET /health/detailed
Response: {
    "status": "healthy",
    "checks": {
        "database": {"status": "healthy", "response_time_ms": 15},
        "redis": {"status": "healthy", "response_time_ms": 5},
        "cache": {"status": "healthy"},
        "storage": {"status": "healthy"},
        "memory": {"status": "healthy", "usage_percentage": 45.2}
    }
}
```

### Load Balancer Health Check
```http
GET /health/load-balancer
Response: {"status": "healthy", "timestamp": "2024-01-01T12:00:00Z"}
```

## 🚀 Deployment Steps

### 1. Configure Environment
```env
# Session Configuration
SESSION_DRIVER=redis
REDIS_HOST=redis-cluster
REDIS_PASSWORD=your_redis_password

# Cache Configuration
CACHE_DRIVER=redis

# Queue Configuration
QUEUE_CONNECTION=redis
```

### 2. Deploy with Docker
```bash
# Build and start all services
docker-compose -f docker-compose-load-balancer.yml up -d

# Check service status
docker-compose ps

# View logs
docker-compose logs -f nginx-lb
```

### 3. Verify Health Checks
```bash
# Test individual app servers
curl http://localhost:8000/health

# Test through load balancer
curl http://yourdomain.com/health

# Test detailed health check
curl http://yourdomain.com/health/detailed
```

## 📊 Monitoring

### Nginx Status Page
Access internal status page:
```bash
curl http://localhost:8080/nginx_status
```

### Health Check Monitoring
```bash
# Monitor health check responses
watch -n 5 'curl -s http://yourdomain.com/health | jq'

# Monitor detailed health
watch -n 10 'curl -s http://yourdomain.com/health/detailed | jq'
```

## 🔧 Configuration Options

### Load Balancing Algorithms
```nginx
# Round Robin (default)
upstream laravel_app_servers {
    server app1:80;
    server app2:80;
}

# Least Connections (recommended)
upstream laravel_app_servers {
    least_conn;
    server app1:80;
    server app2:80;
}

# IP Hash (if sticky sessions needed)
upstream laravel_app_servers {
    ip_hash;
    server app1:80;
    server app2:80;
}
```

### Health Check Tuning
```nginx
server app1:80 max_fails=3 fail_timeout=30s;
# max_fails: Number of failed attempts before marking as down
# fail_timeout: Time to keep server marked as down
```

### Rate Limiting
```nginx
# API rate limiting
limit_req_zone $binary_remote_addr zone=api:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=login:10m rate=1r/s;

location /api/ {
    limit_req zone=api burst=20 nodelay;
}

location /api/login {
    limit_req zone=login burst=5 nodelay;
}
```

## 🛡️ Security Features

### SSL Configuration
```nginx
ssl_protocols TLSv1.2 TLSv1.3;
ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
ssl_prefer_server_ciphers off;
ssl_session_cache shared:SSL:10m;
```

### Security Headers
```nginx
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Strict-Transport-Security "max-age=31536000" always;
```

## 🔄 Failover Behavior

### Automatic Failover
1. **Health Check Fails**: Server marked as down after 3 failed attempts
2. **Traffic Redirected**: Requests sent to remaining healthy servers
3. **Recovery Check**: Failed server checked every 30 seconds
4. **Auto Recovery**: Server automatically added back when healthy

### Backup Server
- **Standby Mode**: Only receives traffic when primary servers fail
- **Weight Configuration**: `weight=1 backup` directive
- **Seamless Transition**: No configuration changes needed

## 📈 Performance Optimization

### Connection Pooling
```nginx
upstream laravel_app_servers {
    least_conn;
    keepalive 32;  # Keep 32 connections open
}
```

### Static File Caching
```nginx
location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2)$ {
    expires 1y;
    add_header Cache-Control "public, immutable";
}
```

### Gzip Compression
```nginx
gzip on;
gzip_comp_level 6;
gzip_types text/plain text/css application/json application/javascript;
```

## 🔍 Troubleshooting

### Common Issues

#### Server Not Responding
```bash
# Check Nginx status
docker-compose exec nginx-lb nginx -t

# Check upstream servers
docker-compose exec nginx-lb curl http://app1:80/health

# View Nginx logs
docker-compose logs nginx-lb
```

#### Health Check Failures
```bash
# Test health endpoint directly
curl -v http://app1:8000/health

# Check detailed health
curl http://yourdomain.com/health/detailed

# Monitor in real-time
watch -n 2 'curl -s http://yourdomain.com/health | jq .status'
```

#### Session Issues
```bash
# Check Redis connection
docker-compose exec redis-cluster redis-cli ping

# Monitor Redis
docker-compose exec redis-cluster redis-cli monitor
```

### Performance Monitoring
```bash
# Monitor Nginx connections
watch -n 1 'curl -s http://localhost:8080/nginx_status'

# Monitor response times
curl -w "@curl-format.txt" -o /dev/null -s http://yourdomain.com/health
```

## 🚀 Production Checklist

- [ ] SSL certificates configured
- [ ] Health check endpoints accessible
- [ ] Redis cluster operational
- [ ] Session driver set to Redis
- [ ] Rate limiting configured
- [ ] Security headers added
- [ ] Monitoring enabled
- [ ] Backup servers configured
- [ ] Log rotation setup
- [ ] Performance testing completed

This load balancer setup provides high availability, automatic failover, and optimal performance for Laravel applications with Redis-based session management.
