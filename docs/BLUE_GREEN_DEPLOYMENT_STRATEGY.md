# 🔵🟢 Blue-Green Deployment Strategy

## 1. Concept Overview
The goal is to eliminate downtime by running two identical production environments (Blue and Green). Traffic is routed to one while the other is updated.

- **Blue Env**: Currently Live (serving traffic).
- **Green Env**: Staging/Idle (receiving updates).

## 2. Infrastructure Architecture
### 2.1 Load Balancer (Nginx / AWS ALB)
Traffic Routing is controlled by a simple variable/target switch.

#### **Nginx Configuration Strategy**
We use a variable `$active_upstream` mapped or symlinked to point to the active backend.

```nginx
upstream blue_servers {
    server 10.0.0.1:8000;
    server 10.0.0.2:8000;
}

upstream green_servers {
    server 10.0.0.3:8000;
    server 10.0.0.4:8000;
}

# Variable Toggle
map $deployment_color $backend_upstream {
    default blue_servers;
    "blue"  blue_servers;
    "green" green_servers;
}

server {
    listen 80;
    server_name api.absensi-qr.com;

    location / {
        proxy_pass http://$backend_upstream;
    }
}
```

### 2.2 Shared Resources
Both environments share:
- **Database**: Primary PostgreSQL (+ Read Replicas)
- **Redis Cluster**: Session & Cache
- **S3 Storage**: User uploads

**Critical Rule**: The Database Schema must always be **backward compatible** with the "Blue" version while "Green" is being deployed.

---

## 3. Zero-Downtime Migration Rules
Since the DB is shared, migrations must NOT break the running "Blue" app.

### ❌ BANNED ACTIONS (During Deployment)
1.  **Renaming Columns**: `RENAME COLUMN old TO new` -> *Breaks Blue immediately*.
2.  **Dropping Columns**: `DROP COLUMN` -> *Breaks Blue immediately*.
3.  **Changing Column Type**: Might break Blue if ORM casting fails.
4.  **Adding NOT NULL columns without Default**: *Breaks Blue inserts*.

### ✅ SAFE MIGRATION STRATEGY (2-Step Process)

#### **Scenario: Rename Column `address` to `location`**

**Step 1: Expand (Deploy N)**
1.  Add new column `location` (nullable).
2.  Update App Logic: Write to BOTH `address` and `location`. Read from `address` (or preferred).
3.  Deploy. Run migration.
4.  Data Backfill: Copy data from `address` to `location`.

**Step 2: Contract (Deploy N+1)**
1.  Update App Logic: Read/Write ONLY to `location`.
2.  Deploy.
3.  Remove `address` column in a separate cleanup migration (once N+1 is fully stable).

---

## 4. Deployment Workflow (Blue → Green)
*Assume Blue is Live. We deploy to Green.*

### Phase 1: Preparation (Green)
1.  **Pull Code**: `git pull origin release` on Green servers.
2.  **Install Dependencies**: `composer install --no-dev --optimize-autoloader`.
3.  **Safety Check**: Check for destructive migrations.
4.  **Migrate**: `php artisan migrate --force`. (Must be safe migrations!).
5.  **Cache**: `php artisan optimize`.
6.  **Queues**: Restart Green Supervisor workers (`php artisan queue:restart` affects all, safer to restart specific supervisor group).

### Phase 2: Smoke Test (Green)
1.  Internal Health Check: `curl localhost:8000/api/v1/health`
2.  Verify Database connection, Redis, and Storage.
3.  Manual QA: Developers access Green via specific internal IP/Port or header-based routing.

### Phase 3: Traffic Switch (The "Cutover")
1.  **Canary (Optional)**: Route 10% traffic to Green.
2.  **Full Switch**: Update Load Balancer to point to `green_servers`.
    - *Nginx*: Update symlink/map -> `nginx -s reload`.
    - *AWS*: Update Listener Rule.
3.  **Monitor**: Watch Error Rate & Latency for 5 minutes.

### Phase 4: Rollback (If needed)
1.  **Trigger**: Error Rate > 1% or Latency > 2000ms.
2.  **Action**: Revert Load Balancer to `blue_servers`.
3.  **Time**: < 30 seconds.

### Phase 5: Cleanup
1.  Once Green is stable (e.g., after 1 hour), Green becomes the new "Blue".
2.  Old Blue becomes the new "Idle Green" for next release.

---

## 5. Deployment Checklist
- [ ] **Migrations Verified**: No `DROP` or `RENAME`.
- [ ] **Tests Passed**: CI/CD pipeline green.
- [ ] **Backup Created**: Database Snapshot taken.
- [ ] **Green Healthy**: `/health` returns 200 OK.
- [ ] **Logs Clear**: No startup errors in `laravel.log` on Green.
- [ ] **Workers**: Green queues configured.

---

## 6. Canary Release (Bonus)
Using Nginx `split_clients`:

```nginx
split_clients "${remote_addr}AAA" $variant {
    10%     green_servers;
    *       blue_servers;
}

upstream backend {
    server $variant;
}
```
This allows gradual rollout (10% -> 50% -> 100%).
