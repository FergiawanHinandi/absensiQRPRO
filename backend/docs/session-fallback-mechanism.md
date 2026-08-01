# Session Fallback Mechanism

## Overview

The session fallback mechanism provides automatic failover from Redis to database storage when Redis becomes unavailable. This ensures users remain authenticated during Redis failures or maintenance.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Laravel Application                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│              TenantAwareSessionHandler                       │
│  (Primary: Redis with tenant isolation)                      │
└─────────────────────────────────────────────────────────────┘
                              │
                    ┌─────────┴─────────┐
                    │                   │
                    ▼                   ▼
         ┌──────────────────┐  ┌──────────────────┐
         │  Redis Storage   │  │ Database Storage │
         │  (Primary)       │  │  (Fallback)      │
         └──────────────────┘  └──────────────────┘
```

## Components

### 1. TenantAwareSessionHandler

**Location**: `app/Services/Session/TenantAwareSessionHandler.php`

**Responsibilities**:
- Implements `SessionHandlerInterface` for Laravel session management
- Provides multi-tenant session isolation using key prefixing
- Automatically falls back to database when Redis fails
- Maintains session TTL and expiration

**Key Format**: `session:tenant:{tenant_id}:session:{session_id}`

**Features**:
- Tenant isolation through school-based key prefixing
- Automatic exception handling with fallback
- TTL-based session expiration
- Seamless failover without user disruption

### 2. DatabaseSessionHandler

**Location**: `app/Services/Session/DatabaseSessionHandler.php`

**Responsibilities**:
- Provides database-based session storage as fallback
- Compatible with Laravel's standard sessions table
- Handles session garbage collection

**Table Structure**:
```sql
CREATE TABLE sessions (
    id VARCHAR PRIMARY KEY,
    user_id BIGINT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    payload LONGTEXT,
    last_activity INTEGER
);
```

### 3. SessionFallbackManager

**Location**: `app/Services/Session/SessionFallbackManager.php`

**Responsibilities**:
- Monitors Redis health status
- Provides health metrics for monitoring systems
- Tracks storage backend status
- Implements health check caching to reduce overhead

**Health Check Interval**: 30 seconds (configurable via `REDIS_HEALTH_CHECK_INTERVAL`)

### 4. SessionServiceProvider

**Location**: `app/Providers/SessionServiceProvider.php`

**Responsibilities**:
- Registers custom session driver `tenant_redis`
- Wires together all session components
- Configures fallback chain

## Configuration

### Environment Variables

```env
# Session Configuration
SESSION_DRIVER=tenant_redis          # Use tenant-aware Redis sessions
SESSION_LIFETIME=120                 # Session lifetime in minutes
SESSION_CONNECTION=session           # Redis connection name
SESSION_TABLE=sessions              # Database fallback table
SESSION_PREFIX=session              # Redis key prefix

# Redis Health Monitoring
REDIS_HEALTH_CHECK_INTERVAL=30      # Health check interval in seconds
```

### Enabling Tenant-Aware Sessions

Update `config/session.php`:
```php
'driver' => env('SESSION_DRIVER', 'tenant_redis'),
```

Or set in `.env`:
```env
SESSION_DRIVER=tenant_redis
```

## Failover Behavior

### Normal Operation (Redis Available)

1. User makes request
2. `TenantAwareSessionHandler` reads session from Redis
3. Session data returned with tenant isolation
4. Response sent to user

### Failover Scenario (Redis Unavailable)

1. User makes request
2. `TenantAwareSessionHandler` attempts Redis read
3. Redis connection fails (exception thrown)
4. Exception caught, fallback to `DatabaseSessionHandler`
5. Session data read from database
6. Response sent to user (user remains authenticated)

### Recovery (Redis Becomes Available)

1. `SessionFallbackManager` performs periodic health checks
2. Redis becomes available again
3. Next health check detects Redis is healthy
4. New sessions automatically use Redis
5. Existing database sessions gradually migrate back to Redis

## Multi-Tenant Isolation

### Key Prefixing Strategy

Each school tenant has isolated session storage:

```
session:tenant:1:session:abc123def456    # School 1
session:tenant:2:session:xyz789ghi012    # School 2
session:tenant:guest:session:mno345pqr   # Unauthenticated
```

### Tenant ID Resolution

1. **Authenticated Users**: Uses `user->school_id`
2. **Session-Stored**: Uses `session('tenant_id')`
3. **Default**: Uses `'guest'` for unauthenticated sessions

## Monitoring

### Health Check Endpoints

**Status Endpoint**:
```bash
GET /api/health/session/status
```

Response:
```json
{
  "status": true,
  "data": {
    "primary_backend": "redis",
    "fallback_backend": "database",
    "current_backend": "redis",
    "redis_healthy": true,
    "last_health_check": 1708876543,
    "health_check_interval": 30
  }
}
```

**Metrics Endpoint** (Prometheus format):
```bash
GET /api/health/session/metrics
```

Response:
```json
{
  "status": true,
  "data": {
    "redis_available": 1,
    "using_fallback": 0,
    "last_check_timestamp": 1708876543
  }
}
```

**Force Health Check**:
```bash
GET /api/health/session/check
```

Response:
```json
{
  "status": true,
  "data": {
    "redis_healthy": true,
    "backend": "redis"
  }
}
```

## Testing

### Manual Testing

1. **Test Normal Operation**:
```bash
# Login and verify session works
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}'

# Check session status
curl http://localhost:8000/api/health/session/status
```

2. **Test Fallback**:
```bash
# Stop Redis
docker stop redis

# Make authenticated request (should still work)
curl http://localhost:8000/api/v1/user \
  -H "Authorization: Bearer {token}"

# Check session status (should show database backend)
curl http://localhost:8000/api/health/session/status
```

3. **Test Recovery**:
```bash
# Start Redis
docker start redis

# Force health check
curl http://localhost:8000/api/health/session/check

# Verify Redis is back
curl http://localhost:8000/api/health/session/status
```

## Performance Considerations

### Health Check Caching

Health checks are cached for 30 seconds to avoid overhead:
- Reduces Redis ping operations
- Minimizes performance impact
- Configurable via `REDIS_HEALTH_CHECK_INTERVAL`

### Session Write Performance

- **Redis**: Sub-millisecond writes
- **Database**: 5-10ms writes (acceptable fallback)
- **Recommendation**: Keep Redis healthy for optimal performance

### Database Fallback Impact

When using database fallback:
- Slight increase in response time (5-10ms)
- No user-visible impact
- Sessions remain functional
- Automatic recovery when Redis returns

## Troubleshooting

### Sessions Not Persisting

**Symptom**: Users logged out after Redis restart

**Solution**:
1. Verify `SESSION_DRIVER=tenant_redis` in `.env`
2. Check `SessionServiceProvider` is registered
3. Verify sessions table exists: `php artisan migrate`

### Fallback Not Working

**Symptom**: Errors when Redis is down

**Solution**:
1. Verify sessions table exists
2. Check database connection is working
3. Review logs for exception details

### Cross-Tenant Session Access

**Symptom**: Users seeing other school's data

**Solution**:
1. Verify tenant ID resolution logic
2. Check `school_id` is set on user model
3. Review session key format in Redis

## Security Considerations

### Session Encryption

Enable session encryption for sensitive data:
```env
SESSION_ENCRYPT=true
```

### Session Lifetime

Configure appropriate session lifetime:
```env
SESSION_LIFETIME=120  # 2 hours
```

### Cookie Security

Ensure secure cookie settings:
```env
SESSION_SECURE_COOKIE=true  # HTTPS only
SESSION_HTTP_ONLY=true      # Prevent XSS
SESSION_SAME_SITE=lax       # CSRF protection
```

## Migration Guide

### From Standard Redis Sessions

1. Update `.env`:
```env
SESSION_DRIVER=tenant_redis
```

2. Run migration:
```bash
php artisan migrate
```

3. Restart application:
```bash
php artisan config:clear
php artisan cache:clear
```

### From Database Sessions

1. Update `.env`:
```env
SESSION_DRIVER=tenant_redis
```

2. Verify Redis is running:
```bash
redis-cli ping
```

3. Restart application:
```bash
php artisan config:clear
```

## Best Practices

1. **Monitor Redis Health**: Use health endpoints for monitoring
2. **Set Appropriate TTL**: Match session lifetime to business needs
3. **Enable Encryption**: For sensitive session data
4. **Regular Backups**: Backup sessions table for disaster recovery
5. **Load Testing**: Test fallback under load conditions
6. **Alert Configuration**: Set up alerts for Redis failures

## Related Documentation

- [Redis Sentinel Integration](redis-sentinel-integration.md)
- [Multi-Tenant Architecture](../docs/architecture/multi-tenant.md)
- [Session Security](../docs/security/session-security.md)
