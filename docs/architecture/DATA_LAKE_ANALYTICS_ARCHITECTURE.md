# Data Lake + Analytics Warehouse Architecture

## Executive Summary

This document defines the complete data platform architecture for the attendance SaaS, enabling school analytics, regional insights, fraud detection, and 5+ years of historical reporting.

**Key Components:**
- **OLTP:** MySQL (operational database)
- **Event Stream:** Kafka (real-time events)
- **Data Lake:** AWS S3 (raw data storage)
- **Warehouse:** ClickHouse (analytical queries)
- **Processing:** Apache Spark (ETL)
- **BI Tools:** Metabase / Superset

---

## Architecture Overview

### Data Pipeline Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                        OPERATIONAL LAYER                             │
│                                                                      │
│  ┌──────────────┐         ┌──────────────┐         ┌──────────────┐│
│  │   MySQL      │         │   Kafka      │         │  PostgreSQL  ││
│  │   (OLTP)     │────────▶│  (Events)    │────────▶│ (Event Store)││
│  │              │         │              │         │              ││
│  └──────────────┘         └──────┬───────┘         └──────────────┘│
│                                  │                                  │
└──────────────────────────────────┼──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          INGESTION LAYER                             │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │              Kafka Connect / Debezium                         │  │
│  │  • CDC from MySQL                                             │  │
│  │  • Event streaming from Kafka                                 │  │
│  │  • Schema validation                                          │  │
│  └────────────────────────┬─────────────────────────────────────┘  │
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                          DATA LAKE (S3)                              │
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐         │
│  │   Raw Zone   │───▶│ Staging Zone │───▶│ Curated Zone │         │
│  │              │    │              │    │              │         │
│  │ • JSON       │    │ • Parquet    │    │ • Parquet    │         │
│  │ • Immutable  │    │ • Partitioned│    │ • Optimized  │         │
│  │ • Compressed │    │ • Validated  │    │ • Aggregated │         │
│  └──────────────┘    └──────────────┘    └──────────────┘         │
│                                                                      │
│  Partitioning: /year=2026/month=02/day=11/hour=09/                 │
│  Retention: Raw (90 days), Staging (1 year), Curated (Forever)     │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       PROCESSING LAYER                               │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │                    Apache Spark / Airflow                     │  │
│  │                                                               │  │
│  │  ETL Jobs:                                                    │  │
│  │  • Raw → Staging (Validation, Deduplication)                 │  │
│  │  • Staging → Curated (Transformation, Enrichment)            │  │
│  │  • Curated → Warehouse (Dimensional Modeling)                │  │
│  │  • ML Pipelines (Fraud Detection, Dropout Prediction)        │  │
│  └────────────────────────┬─────────────────────────────────────┘  │
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    ANALYTICS WAREHOUSE (ClickHouse)                  │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │                    Dimensional Model                          │  │
│  │                                                               │  │
│  │  Fact Tables:                  Dimension Tables:             │  │
│  │  • fact_attendance              • dim_student                │  │
│  │  • fact_payment                 • dim_school                 │  │
│  │  • fact_security_event          • dim_teacher                │  │
│  │  • fact_notification            • dim_time                   │  │
│  │                                 • dim_location               │  │
│  │                                 • dim_subscription           │  │
│  └────────────────────────┬─────────────────────────────────────┘  │
│                           │                                         │
│  Features:                                                          │
│  • Columnar storage                                                 │
│  • Materialized views                                               │
│  • Real-time aggregations                                           │
│  • Row-level security (RLS)                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       ANALYTICS & BI LAYER                           │
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐         │
│  │  Metabase    │    │   Superset   │    │  Custom API  │         │
│  │  (BI Tool)   │    │  (Advanced)  │    │  (Embedded)  │         │
│  └──────────────┘    └──────────────┘    └──────────────┘         │
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐         │
│  │  ML Models   │    │   Jupyter    │    │   Grafana    │         │
│  │  (Fraud/ML)  │    │  (Analysis)  │    │ (Monitoring) │         │
│  └──────────────┘    └──────────────┘    └──────────────┘         │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Data Lake Architecture

### Storage Zones

#### 1. Raw Zone (Bronze Layer)

**Purpose:** Store immutable raw data exactly as received

**Structure:**
```
s3://attendance-data-lake/raw/
├── events/
│   ├── attendance/
│   │   └── year=2026/month=02/day=11/hour=09/
│   │       ├── attendance-20260211-090000.json.gz
│   │       ├── attendance-20260211-090100.json.gz
│   │       └── ...
│   ├── billing/
│   ├── notification/
│   └── audit/
├── cdc/
│   ├── students/
│   ├── schools/
│   └── teachers/
└── logs/
    ├── application/
    └── security/
```

**Characteristics:**
- Format: JSON (compressed with gzip)
- Partitioning: Year/Month/Day/Hour
- Retention: 90 days
- Immutable: Never modified or deleted
- Schema: Schema-on-read

**Example Raw Event:**
```json
{
  "event_id": "01HQZX9Y8K2M3N4P5Q6R7S8T9V",
  "event_type": "attendance.recorded.v1",
  "event_version": "1.0.0",
  "timestamp": "2026-02-11T09:00:00+08:00",
  "trace_id": "550e8400-e29b-41d4-a716-446655440000",
  "school_id": "school-123",
  "payload": {
    "student_id": "student-456",
    "status": "present",
    "check_in_time": "2026-02-11T08:00:00+08:00",
    "latitude": -6.2088,
    "longitude": 106.8456
  }
}
```

#### 2. Staging Zone (Silver Layer)

**Purpose:** Validated, deduplicated, and cleaned data

**Structure:**
```
s3://attendance-data-lake/staging/
├── attendance/
│   └── year=2026/month=02/day=11/
│       └── part-00000.parquet
├── students/
├── schools/
└── teachers/
```

**Characteristics:**
- Format: Parquet (columnar, compressed with Snappy)
- Partitioning: Year/Month/Day
- Retention: 1 year
- Schema: Enforced schema
- Quality: Validated, deduplicated

**Transformations:**
- Remove duplicates (by event_id)
- Validate schema
- Parse nested JSON
- Convert timestamps to UTC
- Enrich with lookup data

#### 3. Curated Zone (Gold Layer)

**Purpose:** Business-ready, aggregated, and optimized data

**Structure:**
```
s3://attendance-data-lake/curated/
├── attendance_daily_summary/
│   └── year=2026/month=02/
│       └── part-00000.parquet
├── student_attendance_history/
├── school_analytics/
└── regional_insights/
```

**Characteristics:**
- Format: Parquet (optimized)
- Partitioning: Year/Month
- Retention: Forever
- Schema: Dimensional model
- Quality: Aggregated, denormalized

**Example Curated Table:**
```sql
-- attendance_daily_summary
school_id, date, total_students, total_present, total_absent, 
total_late, attendance_rate, province, district
```

---

## Warehouse Schema Design

### Dimensional Model (Star Schema)

#### Fact Table: fact_attendance

```sql
CREATE TABLE fact_attendance (
    -- Surrogate Key
    attendance_key          UInt64,
    
    -- Dimension Keys
    student_key             UInt64,
    school_key              UInt64,
    teacher_key             UInt64,
    time_key                UInt32,
    location_key            UInt64,
    
    -- Degenerate Dimensions
    event_id                String,
    trace_id                String,
    qr_nonce                String,
    
    -- Metrics
    check_in_timestamp      DateTime,
    check_out_timestamp     Nullable(DateTime),
    duration_minutes        Nullable(UInt32),
    is_late                 UInt8,
    late_minutes            Nullable(UInt16),
    is_early_departure      UInt8,
    
    -- Location Metrics
    latitude                Nullable(Float64),
    longitude               Nullable(Float64),
    distance_from_school_m  Nullable(UInt32),
    is_location_valid       UInt8,
    
    -- Device Info
    device_id               String,
    platform                String,
    app_version             String,
    
    -- Audit
    created_at              DateTime,
    updated_at              DateTime
)
ENGINE = MergeTree()
PARTITION BY toYYYYMM(check_in_timestamp)
ORDER BY (school_key, time_key, student_key)
SETTINGS index_granularity = 8192;
```

#### Dimension Table: dim_student

```sql
CREATE TABLE dim_student (
    -- Surrogate Key
    student_key             UInt64,
    
    -- Natural Key
    student_id              String,
    
    -- Attributes
    student_name_encrypted  String,  -- PII encrypted
    student_nis             String,
    grade                   String,
    class_name              String,
    gender                  Enum8('M' = 1, 'F' = 2),
    date_of_birth           Date,
    
    -- Parent Info (encrypted)
    parent_name_encrypted   String,
    parent_phone_encrypted  String,
    parent_email_encrypted  String,
    
    -- Address (encrypted)
    address_encrypted       String,
    province                String,
    district                String,
    subdistrict             String,
    
    -- School Reference
    school_key              UInt64,
    
    -- SCD Type 2 (Slowly Changing Dimension)
    effective_date          Date,
    expiration_date         Nullable(Date),
    is_current              UInt8,
    
    -- Audit
    created_at              DateTime,
    updated_at              DateTime
)
ENGINE = MergeTree()
ORDER BY (student_key, effective_date)
SETTINGS index_granularity = 8192;
```

#### Dimension Table: dim_school

```sql
CREATE TABLE dim_school (
    -- Surrogate Key
    school_key              UInt64,
    
    -- Natural Key
    school_id               String,
    
    -- Attributes
    school_name             String,
    school_type             Enum8('SD' = 1, 'SMP' = 2, 'SMA' = 3, 'SMK' = 4),
    school_status           Enum8('Negeri' = 1, 'Swasta' = 2),
    accreditation           Enum8('A' = 1, 'B' = 2, 'C' = 3, 'Unaccredited' = 4),
    
    -- Location
    province                String,
    district                String,
    subdistrict             String,
    latitude                Float64,
    longitude               Float64,
    
    -- Contact
    phone                   String,
    email                   String,
    website                 Nullable(String),
    
    -- Subscription
    subscription_tier       Enum8('Free' = 1, 'Basic' = 2, 'Premium' = 3, 'Enterprise' = 4),
    max_students            UInt32,
    max_teachers            UInt32,
    
    -- SCD Type 2
    effective_date          Date,
    expiration_date         Nullable(Date),
    is_current              UInt8,
    
    -- Audit
    created_at              DateTime,
    updated_at              DateTime
)
ENGINE = MergeTree()
ORDER BY (school_key, effective_date)
SETTINGS index_granularity = 8192;
```

#### Dimension Table: dim_teacher

```sql
CREATE TABLE dim_teacher (
    -- Surrogate Key
    teacher_key             UInt64,
    
    -- Natural Key
    teacher_id              String,
    
    -- Attributes
    teacher_name_encrypted  String,  -- PII encrypted
    teacher_nip             String,
    subject                 String,
    role                    Enum8('Teacher' = 1, 'Head Teacher' = 2, 'Principal' = 3),
    
    -- Contact (encrypted)
    phone_encrypted         String,
    email_encrypted         String,
    
    -- School Reference
    school_key              UInt64,
    
    -- SCD Type 2
    effective_date          Date,
    expiration_date         Nullable(Date),
    is_current              UInt8,
    
    -- Audit
    created_at              DateTime,
    updated_at              DateTime
)
ENGINE = MergeTree()
ORDER BY (teacher_key, effective_date)
SETTINGS index_granularity = 8192;
```

#### Dimension Table: dim_time

```sql
CREATE TABLE dim_time (
    -- Surrogate Key
    time_key                UInt32,  -- Format: YYYYMMDD
    
    -- Date Attributes
    full_date               Date,
    year                    UInt16,
    quarter                 UInt8,
    month                   UInt8,
    month_name              String,
    week_of_year            UInt8,
    day_of_month            UInt8,
    day_of_week             UInt8,
    day_name                String,
    
    -- Fiscal Calendar
    fiscal_year             UInt16,
    fiscal_quarter          UInt8,
    fiscal_month            UInt8,
    
    -- Academic Calendar
    academic_year           String,  -- e.g., "2025/2026"
    semester                UInt8,   -- 1 or 2
    
    -- Flags
    is_weekend              UInt8,
    is_holiday              UInt8,
    is_school_day           UInt8,
    holiday_name            Nullable(String),
    
    -- Audit
    created_at              DateTime
)
ENGINE = MergeTree()
ORDER BY time_key
SETTINGS index_granularity = 8192;
```

#### Dimension Table: dim_location

```sql
CREATE TABLE dim_location (
    -- Surrogate Key
    location_key            UInt64,
    
    -- Geographic Hierarchy
    country                 String,
    province                String,
    district                String,
    subdistrict             String,
    village                 Nullable(String),
    
    -- Coordinates
    latitude                Float64,
    longitude               Float64,
    
    -- Classification
    urban_rural             Enum8('Urban' = 1, 'Rural' = 2),
    region                  Enum8('Java' = 1, 'Sumatra' = 2, 'Kalimantan' = 3, 
                                  'Sulawesi' = 4, 'Papua' = 5, 'Other' = 6),
    
    -- Audit
    created_at              DateTime,
    updated_at              DateTime
)
ENGINE = MergeTree()
ORDER BY (province, district, subdistrict)
SETTINGS index_granularity = 8192;
```

### Additional Fact Tables

#### fact_payment

```sql
CREATE TABLE fact_payment (
    payment_key             UInt64,
    school_key              UInt64,
    subscription_key        UInt64,
    time_key                UInt32,
    
    payment_id              String,
    amount                  Decimal(15, 2),
    currency                String,
    payment_method          String,
    payment_gateway         String,
    gateway_transaction_id  String,
    
    status                  Enum8('Pending' = 1, 'Settled' = 2, 'Failed' = 3, 'Refunded' = 4),
    
    created_at              DateTime,
    settled_at              Nullable(DateTime)
)
ENGINE = MergeTree()
PARTITION BY toYYYYMM(created_at)
ORDER BY (school_key, time_key)
SETTINGS index_granularity = 8192;
```

#### fact_security_event

```sql
CREATE TABLE fact_security_event (
    security_event_key      UInt64,
    school_key              UInt64,
    user_key                UInt64,
    time_key                UInt32,
    
    event_id                String,
    event_category          String,
    event_action            String,
    severity                Enum8('Low' = 1, 'Medium' = 2, 'High' = 3, 'Critical' = 4),
    
    ip_address              String,
    user_agent              String,
    
    is_anomaly              UInt8,
    anomaly_score           Nullable(Float32),
    
    created_at              DateTime
)
ENGINE = MergeTree()
PARTITION BY toYYYYMM(created_at)
ORDER BY (school_key, time_key, severity)
SETTINGS index_granularity = 8192;
```

---

## ETL Pipeline Design

### ETL Schedule

| Job | Frequency | Duration | Dependencies | Priority |
|-----|-----------|----------|--------------|----------|
| **Ingest Events to Raw** | Real-time | Continuous | Kafka | Critical |
| **Raw → Staging** | Every 15 min | 5-10 min | Raw data | High |
| **Staging → Curated** | Hourly | 10-20 min | Staging | High |
| **Curated → Warehouse** | Every 6 hours | 30-60 min | Curated | Medium |
| **Dimension Updates** | Daily at 2 AM | 20-30 min | OLTP DB | Medium |
| **Aggregations** | Daily at 3 AM | 30-60 min | Warehouse | Medium |
| **ML Model Training** | Weekly | 2-4 hours | Warehouse | Low |
| **Data Quality Checks** | Hourly | 5-10 min | All layers | High |

### ETL Job Definitions

#### Job 1: Ingest Events to Raw (Real-time)

```python
# airflow/dags/ingest_events_to_raw.py
from airflow import DAG
from airflow.providers.apache.kafka.operators.consume import ConsumeFromTopicOperator
from airflow.providers.amazon.aws.transfers.local_to_s3 import LocalFilesystemToS3Operator

dag = DAG(
    'ingest_events_to_raw',
    schedule_interval='@continuous',
    catchup=False,
)

# Consume from Kafka
consume_events = ConsumeFromTopicOperator(
    task_id='consume_attendance_events',
    topics=['attendance.events'],
    kafka_config={
        'bootstrap.servers': 'kafka:9092',
        'group.id': 'data-lake-ingestion',
    },
    max_messages=1000,
    max_batch_size=10 * 1024 * 1024,  # 10 MB
    dag=dag,
)

# Upload to S3 Raw Zone
upload_to_s3 = LocalFilesystemToS3Operator(
    task_id='upload_to_s3_raw',
    filename='/tmp/events/{{ ds }}/{{ ts_nodash }}.json.gz',
    dest_key='raw/events/attendance/year={{ execution_date.year }}/month={{ execution_date.month }}/day={{ execution_date.day }}/hour={{ execution_date.hour }}/{{ ts_nodash }}.json.gz',
    dest_bucket='attendance-data-lake',
    replace=True,
    dag=dag,
)

consume_events >> upload_to_s3
```

#### Job 2: Raw → Staging (Every 15 minutes)

```python
# spark/jobs/raw_to_staging.py
from pyspark.sql import SparkSession
from pyspark.sql.functions import *
from pyspark.sql.types import *

def raw_to_staging(spark, input_path, output_path):
    # Read raw JSON
    df = spark.read.json(input_path)
    
    # Deduplicate by event_id
    df = df.dropDuplicates(['event_id'])
    
    # Validate schema
    df = df.filter(
        col('event_id').isNotNull() &
        col('event_type').isNotNull() &
        col('timestamp').isNotNull() &
        col('school_id').isNotNull()
    )
    
    # Parse nested payload
    df = df.withColumn('student_id', col('payload.student_id'))
    df = df.withColumn('status', col('payload.status'))
    df = df.withColumn('check_in_time', col('payload.check_in_time'))
    df = df.withColumn('latitude', col('payload.latitude'))
    df = df.withColumn('longitude', col('payload.longitude'))
    
    # Convert timestamps to UTC
    df = df.withColumn('timestamp_utc', to_utc_timestamp(col('timestamp'), 'Asia/Jakarta'))
    df = df.withColumn('check_in_time_utc', to_utc_timestamp(col('check_in_time'), 'Asia/Jakarta'))
    
    # Add processing metadata
    df = df.withColumn('processed_at', current_timestamp())
    df = df.withColumn('processing_date', current_date())
    
    # Write to staging (Parquet, partitioned)
    df.write \
        .mode('append') \
        .partitionBy('year', 'month', 'day') \
        .parquet(output_path)
```

#### Job 3: Staging → Curated (Hourly)

```python
# spark/jobs/staging_to_curated.py
def staging_to_curated(spark, input_path, output_path):
    # Read staging data
    df = spark.read.parquet(input_path)
    
    # Enrich with school data
    schools = spark.read.jdbc(
        url='jdbc:mysql://mysql:3306/attendance',
        table='schools',
        properties={'user': 'user', 'password': 'pass'}
    )
    
    df = df.join(schools, df.school_id == schools.id, 'left')
    
    # Calculate derived metrics
    df = df.withColumn('is_late', 
        when(hour(col('check_in_time_utc')) > 8, 1).otherwise(0))
    
    df = df.withColumn('late_minutes',
        when(col('is_late') == 1, 
            (unix_timestamp(col('check_in_time_utc')) - unix_timestamp(lit('08:00:00'))) / 60
        ).otherwise(None))
    
    # Aggregate daily summary
    daily_summary = df.groupBy('school_id', 'date') \
        .agg(
            count('*').alias('total_students'),
            sum(when(col('status') == 'present', 1).otherwise(0)).alias('total_present'),
            sum(when(col('status') == 'absent', 1).otherwise(0)).alias('total_absent'),
            sum(when(col('is_late') == 1, 1).otherwise(0)).alias('total_late'),
            avg(when(col('status') == 'present', 1).otherwise(0)).alias('attendance_rate')
        )
    
    # Write to curated zone
    daily_summary.write \
        .mode('overwrite') \
        .partitionBy('year', 'month') \
        .parquet(output_path)
```

#### Job 4: Curated → Warehouse (Every 6 hours)

```python
# spark/jobs/curated_to_warehouse.py
def curated_to_warehouse(spark, input_path, clickhouse_url):
    # Read curated data
    df = spark.read.parquet(input_path)
    
    # Lookup dimension keys
    df = df.join(dim_student, df.student_id == dim_student.student_id, 'left') \
           .select(df['*'], dim_student.student_key)
    
    df = df.join(dim_school, df.school_id == dim_school.school_id, 'left') \
           .select(df['*'], dim_school.school_key)
    
    df = df.join(dim_time, df.date == dim_time.full_date, 'left') \
           .select(df['*'], dim_time.time_key)
    
    # Generate surrogate key
    df = df.withColumn('attendance_key', monotonically_increasing_id())
    
    # Write to ClickHouse
    df.write \
        .format('jdbc') \
        .option('url', clickhouse_url) \
        .option('dbtable', 'fact_attendance') \
        .option('driver', 'ru.yandex.clickhouse.ClickHouseDriver') \
        .mode('append') \
        .save()
```

---

## Analytics Examples

### 1. Monthly Attendance Rate per Province

```sql
-- ClickHouse Query
SELECT 
    l.province,
    t.year,
    t.month,
    COUNT(DISTINCT f.student_key) AS total_students,
    SUM(CASE WHEN f.is_late = 0 THEN 1 ELSE 0 END) AS on_time_count,
    SUM(CASE WHEN f.is_late = 1 THEN 1 ELSE 0 END) AS late_count,
    ROUND(on_time_count * 100.0 / total_students, 2) AS on_time_rate
FROM fact_attendance f
JOIN dim_school s ON f.school_key = s.school_key AND s.is_current = 1
JOIN dim_location l ON s.province = l.province
JOIN dim_time t ON f.time_key = t.time_key
WHERE t.year = 2026 AND t.month = 2
GROUP BY l.province, t.year, t.month
ORDER BY on_time_rate DESC;
```

### 2. Teacher Punctuality Ranking

```sql
-- Teacher attendance analysis
SELECT 
    t.teacher_name_encrypted,
    s.school_name,
    COUNT(*) AS total_days,
    SUM(CASE WHEN f.is_late = 0 THEN 1 ELSE 0 END) AS on_time_days,
    SUM(CASE WHEN f.is_late = 1 THEN 1 ELSE 0 END) AS late_days,
    ROUND(on_time_days * 100.0 / total_days, 2) AS punctuality_rate,
    AVG(f.late_minutes) AS avg_late_minutes
FROM fact_attendance f
JOIN dim_teacher t ON f.teacher_key = t.teacher_key AND t.is_current = 1
JOIN dim_school s ON f.school_key = s.school_key AND s.is_current = 1
JOIN dim_time dt ON f.time_key = dt.time_key
WHERE dt.year = 2026 AND dt.month = 2
GROUP BY t.teacher_key, t.teacher_name_encrypted, s.school_name
ORDER BY punctuality_rate DESC
LIMIT 100;
```

### 3. Anomaly Cluster Detection

```sql
-- Detect schools with unusual attendance patterns
WITH school_stats AS (
    SELECT 
        f.school_key,
        s.school_name,
        AVG(CASE WHEN f.status = 'present' THEN 1.0 ELSE 0.0 END) AS avg_attendance_rate,
        STDDEV(CASE WHEN f.status = 'present' THEN 1.0 ELSE 0.0 END) AS stddev_attendance_rate,
        COUNT(DISTINCT f.student_key) AS total_students
    FROM fact_attendance f
    JOIN dim_school s ON f.school_key = s.school_key AND s.is_current = 1
    JOIN dim_time t ON f.time_key = t.time_key
    WHERE t.year = 2026 AND t.month = 2
    GROUP BY f.school_key, s.school_name
),
national_stats AS (
    SELECT 
        AVG(avg_attendance_rate) AS national_avg,
        STDDEV(avg_attendance_rate) AS national_stddev
    FROM school_stats
)
SELECT 
    ss.school_name,
    ss.avg_attendance_rate,
    ns.national_avg,
    ABS(ss.avg_attendance_rate - ns.national_avg) / ns.national_stddev AS z_score,
    CASE 
        WHEN ABS(ss.avg_attendance_rate - ns.national_avg) / ns.national_stddev > 3 THEN 'Critical Anomaly'
        WHEN ABS(ss.avg_attendance_rate - ns.national_avg) / ns.national_stddev > 2 THEN 'Moderate Anomaly'
        ELSE 'Normal'
    END AS anomaly_status
FROM school_stats ss
CROSS JOIN national_stats ns
WHERE ABS(ss.avg_attendance_rate - ns.national_avg) / ns.national_stddev > 2
ORDER BY z_score DESC;
```

### 4. Dropout Risk Modeling

```sql
-- Identify students at risk of dropping out
WITH student_attendance AS (
    SELECT 
        f.student_key,
        s.student_name_encrypted,
        sch.school_name,
        COUNT(*) AS total_days,
        SUM(CASE WHEN f.status = 'present' THEN 1 ELSE 0 END) AS present_days,
        SUM(CASE WHEN f.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
        ROUND(present_days * 100.0 / total_days, 2) AS attendance_rate,
        -- Trend: last 30 days vs previous 30 days
        SUM(CASE WHEN t.full_date >= today() - 30 AND f.status = 'present' THEN 1 ELSE 0 END) AS recent_present,
        SUM(CASE WHEN t.full_date BETWEEN today() - 60 AND today() - 31 AND f.status = 'present' THEN 1 ELSE 0 END) AS previous_present
    FROM fact_attendance f
    JOIN dim_student s ON f.student_key = s.student_key AND s.is_current = 1
    JOIN dim_school sch ON f.school_key = sch.school_key AND sch.is_current = 1
    JOIN dim_time t ON f.time_key = t.time_key
    WHERE t.full_date >= today() - 90
    GROUP BY f.student_key, s.student_name_encrypted, sch.school_name
)
SELECT 
    student_name_encrypted,
    school_name,
    attendance_rate,
    recent_present,
    previous_present,
    recent_present - previous_present AS trend,
    CASE 
        WHEN attendance_rate < 50 AND (recent_present - previous_present) < -5 THEN 'Critical Risk'
        WHEN attendance_rate < 70 AND (recent_present - previous_present) < -3 THEN 'High Risk'
        WHEN attendance_rate < 80 THEN 'Moderate Risk'
        ELSE 'Low Risk'
    END AS dropout_risk
FROM student_attendance
WHERE dropout_risk IN ('Critical Risk', 'High Risk')
ORDER BY attendance_rate ASC, trend ASC;
```

---

## Security & Governance

### PII Encryption Strategy

**Encryption at Rest:**
```python
# Encrypt PII fields before storing
from cryptography.fernet import Fernet

class PIIEncryption:
    def __init__(self, key):
        self.cipher = Fernet(key)
    
    def encrypt(self, plaintext):
        if plaintext is None:
            return None
        return self.cipher.encrypt(plaintext.encode()).decode()
    
    def decrypt(self, ciphertext):
        if ciphertext is None:
            return None
        return self.cipher.decrypt(ciphertext.encode()).decode()

# Usage in ETL
encryptor = PIIEncryption(os.getenv('PII_ENCRYPTION_KEY'))

df = df.withColumn('student_name_encrypted', 
    udf(encryptor.encrypt, StringType())(col('student_name')))
```

**Fields to Encrypt:**
- Student names
- Parent names, phone, email
- Teacher names, phone, email
- Addresses

### Access Control (IAM)

**AWS IAM Policies:**
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::attendance-data-lake/curated/*"
      ],
      "Condition": {
        "StringEquals": {
          "aws:PrincipalTag/Department": "Analytics"
        }
      }
    },
    {
      "Effect": "Deny",
      "Action": [
        "s3:GetObject"
      ],
      "Resource": [
        "arn:aws:s3:::attendance-data-lake/raw/*"
      ]
    }
  ]
}
```

**Role-Based Access:**
- **Data Engineers:** Full access to all zones
- **Data Analysts:** Read access to curated zone only
- **BI Users:** Read access via ClickHouse with RLS
- **School Admins:** Read access to their school data only

### Row-Level Security (RLS)

**ClickHouse RLS Implementation:**
```sql
-- Create row policy for school-level access
CREATE ROW POLICY school_access ON fact_attendance
FOR SELECT
USING school_key IN (
    SELECT school_key 
    FROM dim_school 
    WHERE school_id IN (
        SELECT school_id 
        FROM user_school_access 
        WHERE user_id = currentUser()
    )
)
TO school_admin_role;

-- Create row policy for province-level access
CREATE ROW POLICY province_access ON fact_attendance
FOR SELECT
USING school_key IN (
    SELECT school_key 
    FROM dim_school s
    JOIN dim_location l ON s.province = l.province
    WHERE l.province IN (
        SELECT province 
        FROM user_province_access 
        WHERE user_id = currentUser()
    )
)
TO province_admin_role;
```

### Data Governance Policy

**Data Classification:**
| Level | Description | Examples | Retention |
|-------|-------------|----------|-----------|
| **Public** | Non-sensitive aggregated data | Regional statistics, anonymized trends | Forever |
| **Internal** | Business data without PII | School IDs, attendance counts | 7 years |
| **Confidential** | PII data | Student names, contact info | 5 years after graduation |
| **Restricted** | Sensitive PII | Health records, disciplinary records | 3 years after graduation |

**Data Retention Policy:**
```sql
-- Automated data retention
-- Delete raw data older than 90 days
DELETE FROM s3://attendance-data-lake/raw/
WHERE partition_date < today() - 90;

-- Archive staging data older than 1 year
MOVE s3://attendance-data-lake/staging/year=2024/
TO s3://attendance-data-lake-archive/staging/year=2024/;

-- Keep curated data forever (compressed)
-- No deletion
```

**Audit Logging:**
```sql
-- Log all data access
CREATE TABLE audit_log (
    log_id              UInt64,
    user_id             String,
    user_role           String,
    action              String,  -- SELECT, INSERT, UPDATE, DELETE
    table_name          String,
    query               String,
    rows_affected       UInt64,
    timestamp           DateTime,
    ip_address          String,
    success             UInt8
)
ENGINE = MergeTree()
PARTITION BY toYYYYMM(timestamp)
ORDER BY (timestamp, user_id);
```

---

## Cost Estimation

### Infrastructure Costs (Monthly)

| Component | Specification | Cost (USD) | Notes |
|-----------|--------------|------------|-------|
| **AWS S3 (Data Lake)** | 10 TB storage | $230 | Standard storage |
| | 1 TB transfer | $90 | Data transfer out |
| **ClickHouse Cluster** | 3 nodes × 16 vCPU, 64 GB RAM | $1,200 | c5.4xlarge instances |
| | 5 TB SSD storage | $500 | gp3 volumes |
| **Apache Spark (EMR)** | 5 nodes × 8 vCPU, 32 GB RAM | $600 | On-demand, 8 hours/day |
| **Kafka Cluster** | 3 brokers × 4 vCPU, 16 GB RAM | $300 | m5.xlarge instances |
| **Airflow (MWAA)** | Medium environment | $465 | Managed Airflow |
| **Metabase** | 1 instance × 2 vCPU, 8 GB RAM | $50 | t3.large |
| **Data Transfer** | Inter-region, internet | $200 | Estimated |
| **Backup & DR** | S3 Glacier, snapshots | $150 | 30-day retention |
| **Monitoring** | CloudWatch, Grafana Cloud | $100 | Logs + metrics |
| **Total** | | **$3,885/month** | ~$47,000/year |

### Cost Optimization Strategies

1. **Use Spot Instances for Spark:** Save 70% on EMR costs
2. **S3 Intelligent-Tiering:** Auto-move to cheaper storage tiers
3. **ClickHouse Compression:** Achieve 10:1 compression ratio
4. **Reserved Instances:** Save 40% on ClickHouse cluster
5. **Data Lifecycle Policies:** Auto-delete old raw data

**Optimized Cost:** ~$2,500/month (~$30,000/year)

### Scaling Projections

| Metric | Year 1 | Year 3 | Year 5 |
|--------|--------|--------|--------|
| **Schools** | 1,000 | 5,000 | 10,000 |
| **Students** | 500K | 2.5M | 5M |
| **Events/day** | 1M | 5M | 10M |
| **Data Lake Size** | 2 TB | 10 TB | 25 TB |
| **Warehouse Size** | 500 GB | 2.5 TB | 6 TB |
| **Monthly Cost** | $2,500 | $5,000 | $8,000 |

---

## Materialized Views for Performance

```sql
-- Materialized view for daily school summary
CREATE MATERIALIZED VIEW mv_daily_school_summary
ENGINE = SummingMergeTree()
PARTITION BY toYYYYMM(date)
ORDER BY (school_key, date)
AS
SELECT 
    school_key,
    toDate(check_in_timestamp) AS date,
    count() AS total_attendance,
    sumIf(1, is_late = 0) AS on_time_count,
    sumIf(1, is_late = 1) AS late_count,
    avg(late_minutes) AS avg_late_minutes
FROM fact_attendance
GROUP BY school_key, date;

-- Materialized view for monthly province summary
CREATE MATERIALIZED VIEW mv_monthly_province_summary
ENGINE = SummingMergeTree()
PARTITION BY toYYYYMM(month_start)
ORDER BY (province, month_start)
AS
SELECT 
    l.province,
    toStartOfMonth(check_in_timestamp) AS month_start,
    count() AS total_attendance,
    countDistinct(student_key) AS unique_students,
    avg(CASE WHEN status = 'present' THEN 1.0 ELSE 0.0 END) AS avg_attendance_rate
FROM fact_attendance f
JOIN dim_school s ON f.school_key = s.school_key
JOIN dim_location l ON s.province = l.province
GROUP BY l.province, month_start;
```

---

## Next Steps

1. **Week 1-2:** Set up AWS infrastructure (S3, ClickHouse, EMR)
2. **Week 3-4:** Implement Kafka → S3 ingestion pipeline
3. **Month 1:** Build Raw → Staging ETL jobs
4. **Month 2:** Build Staging → Curated ETL jobs
5. **Month 3:** Load dimension tables and fact tables
6. **Month 4:** Deploy BI tools and create dashboards
7. **Month 5:** Implement ML models (fraud detection, dropout prediction)
8. **Month 6:** Production rollout with monitoring

---

## Conclusion

This data platform provides:

✅ **Scalable Architecture:** Handle 10M+ events/day  
✅ **Historical Reporting:** 5+ years of data retention  
✅ **Advanced Analytics:** Fraud detection, dropout prediction  
✅ **Security & Compliance:** PII encryption, RLS, audit logging  
✅ **Cost-Effective:** ~$2,500/month optimized cost  

The platform is ready for implementation and will enable powerful insights for schools, regions, and national-level analytics.
