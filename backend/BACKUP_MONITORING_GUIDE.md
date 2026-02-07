# Real-Time Backup & Restore Monitoring System

## Overview

A comprehensive real-time monitoring system for backup and restore operations with intelligent alerting, dashboard visualization, and automated anomaly detection.

## 🚀 Features

### Real-Time Monitoring
- **Live Job Tracking**: Monitor backup, restore, and rollback jobs in real-time
- **Progress Updates**: Track job progress with percentage completion
- **Duration Tracking**: Monitor job execution time with anomaly detection
- **Size Monitoring**: Track backup file sizes with automatic anomaly alerts

### Intelligent Alerting
- **Failure Alerts**: Instant notifications for failed jobs
- **Size Anomaly Detection**: Alerts when backup size drops >30% from average
- **Duration Anomaly Detection**: Alerts when jobs exceed 50% of average duration
- **Multi-Channel Notifications**: Email, Slack, and Webhook alerts

### Dashboard Features
- **Real-Time Updates**: Auto-refreshing dashboard with live data
- **Interactive Charts**: Visual representation of job statistics
- **Filterable Job List**: Filter by type, status, school, and date range
- **Job Details**: Detailed information for each job including metrics

## 📊 Dashboard Components

### Summary Cards
- **Total Jobs Today**: Daily job count
- **Successful Jobs**: Completed successfully
- **Failed Jobs**: Failed operations
- **Running Jobs**: Currently executing

### Job Management
- **Job List**: Comprehensive table with filtering
- **Real-Time Status**: Live progress updates for running jobs
- **Job Actions**: View details, cancel running jobs
- **Status Indicators**: Visual status badges with icons

### Statistics & Analytics
- **Success Rate**: Overall system reliability
- **Average Duration**: Performance metrics
- **Size Trends**: Backup size analysis
- **Historical Data**: 30-day trends and patterns

## 🔧 Configuration

### Environment Variables
```env
# Enable monitoring
BACKUP_MONITORING_ENABLED=true

# Alert configuration
BACKUP_ALERTS_ENABLED=true
BACKUP_EMAIL_ALERTS_ENABLED=true
BACKUP_ALERT_EMAILS=admin@school.com,ops@school.com

# Slack integration
BACKUP_SLACK_ALERTS_ENABLED=true
BACKUP_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...

# Webhook integration
BACKUP_WEBHOOK_ALERTS_ENABLED=true
BACKUP_WEBHOOK_URL=https://your-webhook-endpoint.com/alerts
BACKUP_WEBHOOK_AUTH=Bearer your-token

# Thresholds
BACKUP_SIZE_ANOMALY_THRESHOLD=30
BACKUP_DURATION_ANOMALY_THRESHOLD=50
BACKUP_MAX_DURATION_SECONDS=3600
```

### Alert Channels Setup

#### Email Alerts
```php
// config/backup_monitoring.php
'email' => [
    'enabled' => true,
    'recipients' => ['admin@school.com', 'ops@school.com'],
    'from_address' => 'alerts@yourapp.com',
    'from_name' => 'Backup Monitoring',
],
```

#### Slack Integration
```php
'slack' => [
    'enabled' => true,
    'webhook_url' => env('BACKUP_SLACK_WEBHOOK_URL'),
    'channel' => '#alerts',
    'username' => 'BackupBot',
],
```

#### Webhook Alerts
```php
'webhook' => [
    'enabled' => true,
    'url' => env('BACKUP_WEBHOOK_URL'),
    'headers' => [
        'Authorization' => env('BACKUP_WEBHOOK_AUTH'),
        'Content-Type' => 'application/json',
    ],
],
```

## 📈 API Endpoints

### Dashboard Data
```http
GET /api/monitoring/dashboard
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "summary": {
            "total_jobs_today": 15,
            "successful_jobs_today": 12,
            "failed_jobs_today": 2,
            "running_jobs": 1
        },
        "recent_jobs": [...],
        "job_stats": {...},
        "alerts": [...]
    }
}
```

### Job List
```http
GET /api/monitoring/jobs?job_type=backup&status=success&date_from=2024-01-01
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "data": [...],
        "current_page": 1,
        "total": 150
    }
}
```

### Job Details
```http
GET /api/monitoring/jobs/{jobId}
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "job_id": "job_abc123_1640995200",
        "job_type": "backup",
        "status": "success",
        "duration_seconds": 120,
        "backup_size_bytes": 1048576,
        "school": {...},
        "user": {...}
    }
}
```

### Real-Time Status
```http
GET /api/monitoring/jobs/{jobId}/status
Authorization: Bearer {token}

Response:
{
    "success": true,
    "data": {
        "job_id": "job_abc123_1640995200",
        "status": "running",
        "progress_percentage": 75,
        "status_message": "Compressing backup files..."
    }
}
```

## 🔍 Monitoring Integration

### Backup Job Monitoring
```php
use App\Services\BackupRestoreMonitoringService;

class BackupService
{
    private $monitoring;

    public function __construct(BackupRestoreMonitoringService $monitoring)
    {
        $this->monitoring = $monitoring;
    }

    public function createBackup($schoolId)
    {
        // Start monitoring
        $jobId = $this->monitoring->startJob('backup', [
            'school_id' => $schoolId,
            'backup_type' => 'full'
        ]);

        try {
            // Update progress
            $this->monitoring->updateProgress(25, 'Creating database dump...');
            
            // Perform backup
            $backupPath = $this->performBackup($schoolId);
            
            $this->monitoring->updateProgress(75, 'Compressing files...');
            
            // Get file size
            $fileSize = filesize($backupPath);
            
            // Complete job with metrics
            $this->monitoring->completeJob([
                'backup_size_bytes' => $fileSize,
                'backup_path' => $backupPath
            ]);

        } catch (\Exception $e) {
            $this->monitoring->failJob($e->getMessage());
            throw $e;
        }
    }
}
```

### Restore Job Monitoring
```php
public function restoreBackup($backupIdentifier, $schoolId)
{
    $jobId = $this->monitoring->startJob('restore', [
        'school_id' => $schoolId,
        'backup_identifier' => $backupIdentifier
    ]);

    try {
        $this->monitoring->updateProgress(10, 'Validating backup...');
        
        // Validate backup
        $this->validateBackup($backupIdentifier, $schoolId);
        
        $this->monitoring->updateProgress(50, 'Restoring database...');
        
        // Perform restore
        $this->performRestore($backupIdentifier);
        
        $this->monitoring->updateProgress(90, 'Verifying integrity...');
        
        // Verify restore
        $this->verifyRestore($schoolId);
        
        $this->monitoring->completeJob();

    } catch (\Exception $e) {
        $this->monitoring->failJob($e->getMessage());
        throw $e;
    }
}
```

## 🚨 Alert Types

### Failure Alerts
**Trigger**: Job fails with error
**Channels**: Email, Slack, Webhook
**Content**: Error message, job details, timestamp

```json
{
    "type": "failure",
    "job_id": "job_abc123_1640995200",
    "job_type": "backup",
    "error_message": "Database connection failed",
    "timestamp": "2024-01-01T12:00:00Z"
}
```

### Size Anomaly Alerts
**Trigger**: Backup size drops >30% from 7-day average
**Channels**: Email, Slack, Webhook
**Content**: Current size, average size, drop percentage

```json
{
    "type": "size_anomaly",
    "job_id": "job_def456_1640995200",
    "job_type": "backup",
    "current_size_bytes": 1048576,
    "average_size_bytes": 2097152,
    "size_drop_percentage": 50.0
}
```

### Duration Anomaly Alerts
**Trigger**: Job duration exceeds 50% of 7-day average
**Channels**: Email, Slack, Webhook
**Content**: Current duration, average duration, increase percentage

```json
{
    "type": "duration_anomaly",
    "job_id": "job_ghi789_1640995200",
    "job_type": "restore",
    "current_duration_seconds": 3600,
    "average_duration_seconds": 1800,
    "duration_increase_percentage": 100.0
}
```

## 📱 Frontend Integration

### Vue.js Dashboard Component
```vue
<template>
  <BackupMonitoringDashboard />
</template>

<script>
import BackupMonitoringDashboard from '@/components/BackupMonitoringDashboard.vue'

export default {
  components: {
    BackupMonitoringDashboard
  }
}
</script>
```

### Real-Time Updates
The dashboard automatically refreshes every 30 seconds and updates running job status every 5 seconds.

## 🧹 Maintenance

### Automated Cleanup
```bash
# Clean up old monitoring data
php artisan backup:monitoring-cleanup

# Dry run to see what would be deleted
php artisan backup:monitoring-cleanup --dry-run

# Force cleanup without confirmation
php artisan backup:monitoring-cleanup --force
```

### Retention Periods
- **Job Records**: 90 days (configurable)
- **Log Files**: 30 days (configurable)
- **Temporary Files**: Cleaned automatically

## 📊 Performance Metrics

### Key Performance Indicators
- **Success Rate**: Percentage of successful jobs
- **Average Duration**: Mean execution time by job type
- **Size Trends**: Backup size patterns over time
- **Error Rate**: Frequency of failed jobs

### Monitoring Overhead
- **Minimal Impact**: <1% CPU overhead
- **Efficient Storage**: Compressed metrics storage
- **Optimized Queries**: Indexed database queries
- **Caching**: Redis caching for dashboard data

## 🔒 Security

### Access Control
- **Admin Only**: Monitoring endpoints require admin role
- **Authentication**: All API endpoints protected
- **Audit Trail**: All monitoring actions logged
- **School Isolation**: Multi-tenant data separation

### Data Privacy
- **Sensitive Data**: No sensitive data in alerts
- **Secure Storage**: Encrypted metrics storage
- **Access Logs**: Complete access audit trail

## 🚀 Deployment

### Production Setup
1. Configure environment variables
2. Set up alert channels (Email/Slack/Webhook)
3. Run database migrations
4. Schedule cleanup command
5. Configure monitoring dashboard

### Monitoring Commands
```bash
# Start monitoring service
php artisan backup:monitor

# Check monitoring status
php artisan backup:monitor:status

# Test alert channels
php artisan backup:test-alerts
```

This comprehensive monitoring system ensures reliable backup and restore operations with intelligent alerting and real-time visibility into system performance.
