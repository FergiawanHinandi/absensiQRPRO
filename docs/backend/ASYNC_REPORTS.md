# Async Report Export System

This document describes the asynchronous report generation system designed to handle heavy export tasks and prevent API abuse.

## Overview

Generating large Excel or PDF reports can be resource-intensive and slow, leading to request timeouts and server overload. The async system offloads these tasks to a background queue and provides status tracking for the user.

## Architecture

1.  **Request**: Client requests an export via `POST /api/v1/reports/export`.
2.  **Job Creation**: The server creates a `ReportExport` record (status: `pending`) and dispatches a `GenerateReportExport` job to the queue.
3.  **Response**: The server immediately returns a `202 Accepted` response with the `job_id`.
4.  **Processing**: The background worker processes the job, generating the file and saving it to storage.
5.  **Status Check**: Client polls `GET /api/v1/reports/export/{job_id}/status` to check progress.
6.  **Download**: Once completed, the client downloads the file via `GET /api/v1/reports/export/{job_id}/download`.
7.  **Cleanup**: Expired files are automatically deleted after 24 hours.

## Rate Limiting

To prevent abuse, exports are rate-limited:
- **Limit**: 5 export requests per hour per user.
- **Response**: `429 Too Many Requests` with `Retry-After` header.

## Endpoints

### 1. Trigger Export
`POST /api/v1/reports/export`

**Body**:
```json
{
    "start_date": "2024-01-01",
    "end_date": "2024-01-31",
    "format": "excel", // or "pdf"
    "class_id": 1 // optional
}
```

**Response (202 Accepted)**:
```json
{
    "success": true,
    "message": "Export sedang diproses...",
    "data": {
        "job_id": "9b3c...",
        "status": "pending",
        "estimated_time": "1-5 menit"
    }
}
```

### 2. Check Status
`GET /api/v1/reports/export/{job_id}/status`

**Response (200 OK)**:
```json
{
    "success": true,
    "data": {
        "job_id": "9b3c...",
        "status": "processing", // pending, processing, completed, failed
        "progress": 45, // 0-100
        "download_url": "..." // Only when completed
    }
}
```

### 3. Download File
`GET /api/v1/reports/export/{job_id}/download`

Returns the file stream or a redirect to a temporary S3 URL.

### 4. Export History
`GET /api/v1/reports/export/history`

Returns a list of the user's past exports.

## Database Schema (`report_exports`)

| Column | Type | Description |
|--------|------|-------------|
| `id` | UUID | Primary Key |
| `user_id` | FK | Requesting user |
| `status` | String | pending, processing, completed, failed |
| `progress` | Integer | 0-100 |
| `file_path` | String | Path in storage |
| `expires_at` | Timestamp | Auto-delete time |

## Automatic Cleanup

A scheduled command runs hourly to delete expired files and records:
```bash
php artisan exports:cleanup
```

## Implementation Details

- **Model**: `App\Models\ReportExport`
- **Job**: `App\Jobs\GenerateReportExport`
- **Controller**: `App\Http\Controllers\Api\V1\AsyncReportExportController`
- **Console Command**: `App\Console\Commands\CleanupExpiredExports`

## Configuration

- **File Storage**: Uses default filesystem disk (local or s3).
- **Queue**: Uses default queue connection (sync, redis, database).
- **Rate Limit**: configured in controller constant `RATE_LIMIT_EXPORTS`.
