# Submission API with Real-Time Stream Aggregation

A backend service built with PHP 8 / Laravel 11, MySQL, Apache Kafka, and Apache Flink. It handles form submissions with idempotency guarantees, validates input, supports paginated queries, executes high-volume load tests, and provides real-time hourly submission counts via stream processing.

---

## Architecture Overview

```
[Client / Load Test]
        |
        | POST /submissions
        v
[Laravel API Controller]
        |
        | Database Transaction
        v
[MySQL]
  - submissions (unique idempotency_key)
  - outbox_events (transactional outbox)
        |
        | Polled by Worker (php artisan outbox:publish)
        v
[Kafka Topic: submission-events]
        |
        | Stream Source
        v
[Apache Flink Job]
  - Tumbling 1-hour window
  - State checkpointing (EXACTLY_ONCE)
  - COUNT(DISTINCT event_id)
        |
        | JDBC Upsert Sink
        v
[MySQL: hourly_submission_aggregates]
        ^
        | GET /forms/{form_id}/aggregates/hourly
[Client / Dashboard]
```

### Key Technical Decisions
- **Idempotency & Race Handling**: Each incoming submission checks for existing `idempotency_key` values. Within a database transaction, a pessimistic row lock (`lockForUpdate`) combined with a database unique index prevents race conditions during concurrent duplicate requests. Duplicates return the existing record with HTTP 200.
- **Transactional Outbox Pattern**: Instead of publishing directly to Kafka inside the HTTP request cycle, events are inserted into `outbox_events` within the same ACID transaction as the submission. A background worker streams events to Kafka. This decouples API latency from broker availability and prevents dual-write inconsistencies.
- **Stream Deduplication**: Flink uses `COUNT(DISTINCT event_id)` and commits offsets synchronously with checkpoints. Writing to MySQL uses an upsert sink on `PRIMARY KEY (form_id, hour_start)`, preventing count corruption during replay.

---

## Directory Layout

```
.
├── INTERVIEWER_GUIDE.md        # Quick checklist and testing matrix
├── README.md                   # This documentation
├── load_test.py                # Standalone Python load test client
└── task/                       # Laravel application directory
    ├── app/
    │   ├── Console/Commands/   # Load test and outbox publishing commands
    │   ├── Http/Controllers/   # API controller endpoints
    │   ├── Http/Requests/      # Form request validation
    │   ├── Models/             # Eloquent models
    │   └── Services/           # SubmissionService business logic
    ├── docker/
    │   ├── flink-jobs/         # Flink SQL aggregation script
    │   └── flink-lib/          # Kafka and MySQL connector JARs
    ├── docker-compose.yml      # Kafka and Flink cluster definition
    ├── routes/
    │   └── api.php             # API route definitions
    └── tests/
        └── Feature/            # End-to-end API and idempotency tests
```

---

## Prerequisites

- PHP 8.2 or higher (extensions: `pdo_mysql`, `curl`, `mbstring`, `json`)
- Composer
- MySQL 8.0 or MariaDB running on port 3306
- Docker & Docker Compose (for Kafka and Flink in Part 3)

---

## Setup Instructions

### 1. Configure the Application

```bash
cd task
composer install
cp .env.example .env
php artisan key:generate
```

Verify your database credentials in `task/.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailer_cloud
DB_USERNAME=root
DB_PASSWORD=
```

### 2. Run Database Migrations

```bash
php artisan migrate
```

---

## Part 1: API Testing & Verification

### Run the Automated Test Suite

```bash
cd task
php artisan test
```

All 16 tests should pass:
- Record creation and field persistence
- HTTP 400 rejection on missing `form_id` or invalid `email`
- Idempotency: duplicate `idempotency_key` returns original record with HTTP 200
- Concurrent submission safety
- Pagination: strictly 10 items per page with accurate page metadata
- Aggregate endpoint queries

### Manual API Verification (cURL)

Start the local server:
```bash
cd task
php artisan serve
```

#### 1. Valid Submission (Expects HTTP 201)
```bash
curl -i -X POST http://127.0.0.1:8000/submissions \
  -H "Content-Type: application/json" \
  -d '{
    "form_id": "contact-form",
    "idempotency_key": "req-1001",
    "email": "user@example.com",
    "data": { "name": "John", "message": "Inquiry about service" }
  }'
```

#### 2. Duplicate Submission (Expects HTTP 200)
Resend the exact same command above. The service returns HTTP 200 with the previously stored record without inserting a duplicate.

#### 3. Validation Errors (Expects HTTP 400)
```bash
curl -i -X POST http://127.0.0.1:8000/submissions \
  -H "Content-Type: application/json" \
  -d '{
    "idempotency_key": "req-1002",
    "email": "invalid-email-address",
    "data": {}
  }'
```

#### 4. Paginated List (Expects HTTP 200, 10 items per page)
```bash
curl -i "http://127.0.0.1:8000/forms/contact-form/submissions?page=1"
```

---

## Part 2: Load Testing (100,000 Requests)

The specification requires:
- 100,000 total requests to `POST /submissions`
- 20,000 duplicate keys (80,000 unique records)
- Reporting stored submissions, handled duplicates, error count, and throughput

### Command

```bash
cd task
php artisan submissions:load-test --total=100000 --duplicates=20000 --truncate
```

### Sample Output Report

```
==========================================================
      MAILERCLOUD LOAD TEST — PART 2 SPECIFICATION        
==========================================================
Total Requests:  100,000
Unique Keys:     80,000
Duplicate Keys:  20,000
==========================================================
Truncating submissions and outbox_events tables...
Tables truncated successfully.
Initial Submissions in DB: 0
Generating workload and executing requests...
 100000/100000 [============================] 100%

==========================================================
               LOAD TEST REPORT RESULTS                   
==========================================================
+-------------------------------+----------+----------------+--------+
| Metric                        | Expected | Actual Result  | Status |
+-------------------------------+----------+----------------+--------+
| Total Requests Sent           | 100,000  | 100,000        | OK     |
| Submissions Stored            | 80,000   | 80,000         | PASS   |
| Duplicates Handled / Returned | 20,000   | 20,000         | PASS   |
| Errors Occurred               | 0        | 0              | PASS   |
| Total Time Taken              | -        | ~6.5 seconds   | INFO   |
| Throughput                    | -        | ~15,380 req/s  | INFO   |
+-------------------------------+----------+----------------+--------+
```

### Alternative HTTP Load Testers

If you want to test against an active HTTP server:

```bash
# Python async client (requires pip install aiohttp)
python task/scripts/load_test.py --url http://127.0.0.1:8000/submissions --total 100000 --duplicates 20000 --concurrency 50

# PHP cURL multi client
php task/scratch/http_load_test.php 10000 2000 50 http://127.0.0.1:8000
```

---

## Part 3: Live Aggregation with Apache Flink

This pipeline continuously aggregates submission counts per form, broken down by hour.

### 1. Start Docker Containers (Kafka & Flink)

```bash
cd task
docker compose up -d
```

Services exposed:
- Kafka broker: `localhost:9092`
- Flink Web Dashboard: `http://localhost:8081`

### 2. Submit the Flink SQL Job

```bash
docker exec -i mailercloud-flink-jobmanager /opt/flink/bin/sql-client.sh -f /opt/flink/jobs/hourly_aggregation.sql
```

The job definition in `task/docker/flink-jobs/hourly_aggregation.sql`:
- Reads from Kafka topic `submission-events`.
- Derives event timestamps and defines a 5-second watermark delay.
- Groups by `form_id` and hour start (`yyyy-MM-dd HH:00:00`).
- Sinks counts into `hourly_submission_aggregates` in MySQL.

### 3. Start the Outbox Publisher

In a dedicated terminal, run the publisher worker:

```bash
cd task
php artisan outbox:publish --poll
```

This worker continuously queries unpublished outbox records from MySQL, publishes them to Kafka, and marks them as published.

### 4. Query the Live Hourly Aggregates

```bash
curl "http://127.0.0.1:8000/forms/contact-form/aggregates/hourly"
```

Response format:
```json
{
  "form_id": "contact-form",
  "aggregates": [
    {
      "id": 1,
      "form_id": "contact-form",
      "hour_start": "2026-10-23 08:00:00",
      "submission_count": 80000,
      "created_at": "2026-10-23T08:52:10.000000Z",
      "updated_at": "2026-10-23T08:52:14.000000Z"
    }
  ]
}
```

---

## Fault Tolerance & Crash Recovery

The task requires explaining how the Flink job handles crashes without losing counts or double-counting when it restarts.

1. **State Checkpointing**: Flink is configured with `state.backend: hashmap` and `execution.checkpointing.mode: EXACTLY_ONCE` at a 5,000ms interval. Periodic checkpoints save the internal aggregation state to durable storage (`/tmp/flink-checkpoints`).
2. **Kafka Offset Replay**: Kafka partition offsets are tracked in sync with checkpoints. Upon failure, Flink rewinds Kafka consumer offsets to the exact position of the last successful checkpoint.
3. **Idempotent Upsert Sink**: The MySQL aggregate table has a composite primary key: `PRIMARY KEY (form_id, hour_start)`. Replaying records between the last checkpoint and the crash executes an upsert (`INSERT ... ON DUPLICATE KEY UPDATE`).
4. **Distinct Counting**: The SQL query aggregates using `COUNT(DISTINCT event_id)`. Even if Kafka replays previously consumed messages across a recovery window, duplicate deliveries will not increase the count.
5. **Transactional Ingestion**: In the Laravel application, submissions and outbox records are inserted in a single MySQL transaction. If the API fails mid-request, no orphaned records or partial state can exist.
