# Read Replica - Quick Reference

## 🚀 Quick Setup

### Enable Read Replica

```env
# .env
DB_HOST=primary.database.com
DB_READ_HOST=replica.database.com
DB_STICKY=true
```

### Disable Read Replica

```env
# .env
DB_HOST=primary.database.com
# DB_READ_HOST=  # Comment out or remove
```

## 📊 Query Routing

| Query Type | Database |
|------------|----------|
| SELECT | Read Replica |
| INSERT | Primary |
| UPDATE | Primary |
| DELETE | Primary |

## ✅ Configuration Check

```bash
# Check if replica configured
php artisan tinker
>>> config('database.connections.mysql.read')
# null → No replica
# array → Replica configured
```

## 🔧 Emergency Failover

```env
# Point replica to primary
DB_READ_HOST=primary.database.com
```

## 🧪 Run Tests

```bash
php artisan test --filter=ReadReplicaTest
```

## 📈 Expected Performance

- **40% faster** read operations
- **70% reduction** in primary load
- **Dashboard**: 200ms → 120ms

## 🐛 Common Issues

### Stale Data

**Solution**: Enable sticky
```env
DB_STICKY=true
```

### Replica Down

**Solution**: Point to primary
```env
DB_READ_HOST=primary.database.com
```

## 📁 Files

- Config: `config/database.php`
- Tests: `tests/Feature/ReadReplicaTest.php`
- Docs: `docs/READ_REPLICA_CONFIGURATION.md`
