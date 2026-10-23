# MailerCloud Task: System Usage & Architecture Guide

A production-grade, distributed Form Submission API with real-time stream aggregation built using **PHP 8.2+ / Laravel 11**, **MySQL**, **Apache Kafka**, and **Apache Flink**.

---

## Architecture Overview

```
                      ┌──────────────────────┐
                      │  Client / Load Test  │
                      └──────────┬───────────┘
                                 │ HTTP POST / GET
                                 ▼
                      ┌──────────────────────┐
                      │  Laravel REST API    │
                      │ (Atomic Transaction) │
                      └───────┬──────────────┘
                              │
               ┌──────────────┴──────────────┐
               ▼                             ▼
       ┌───────────────┐             ┌───────────────┐
       │ `submissions` │             │`outbox_events`│
       └───────────────┘             └───────┬───────┘
                                             │
                                   php artisan outbox:publish
                                             │
                                             ▼
                                     ┌───────────────┐
                                     │  Apache Kafka │
                                     │     Topic:    │
                                     │  submission-  │
                                     │    events     │
                                     └───────┬───────┘
                                             │
                                     Streaming Consumer
                                             │
                                             ▼
                                     ┌───────────────┐
                                     │ Apache Flink  │
                                     │ Streaming SQL │
                                     │  (Hourly Agg) │
                                     └───────┬───────┘
                                             │ JDBC Upsert Sink
                                             ▼
                               ┌───────────────────────────┐
                               │hourly_submission_aggregates│
                               └───────────────────────────┘
```

### Key Architectural Highlights
1. **Idempotency Guarantee**: Submissions enforce a database `UNIQUE` constraint on `idempotency_key` and row-level locks within a transaction. Re-submitting the same key returns the existing record (`HTTP 200 OK`) without creating duplicates.
2. **Transactional Outbox Pattern**: Ingestion atomically writes to `submissions` and `outbox_events` in a single MySQL transaction. Even if the message broker is temporarily down, no event is ever lost.
3. **Guaranteed Delivery to Kafka**: The Outbox Publisher reads pending events, publishes them to Kafka with partition keying on `form_id`, and marks them `published_at` only upon broker acknowledgment.
4. **Real-time Live Hourly Aggregation (Apache Flink)**: Flink continuously consumes Kafka events, applies event-time windowing/hourly truncation, counts unique event IDs, and upserts live counts into MySQL (`hourly_submission_aggregates`).
5. **Fault Tolerance & Exactly-Once Semantics**:
   - Flink maintains state using HashMap state backend and checkpoints every `5,000ms`.
   - On crash and restart, Flink resumes from the last checkpoint offset.
   - Upsert sink (`PRIMARY KEY (form_id, hour_start)`) ensures idempotency at the database sink layer, preventing double-counting.

---

## Prerequisites

- **PHP**: `8.2` or later with `pdo_mysql`, `curl`, and `mbstring` extensions.
- **Composer**: `2.x`
- **MySQL / MariaDB**: `8.0+` or `10.4+` running on port `3306`.
- **Docker & Docker Compose**: (for Kafka & Apache Flink).
- **Python 3.8+**: (optional, for high-concurrency async load testing).

---

## Step 1: Environment Setup

1. Open a terminal in the project directory:
   ```bash
   cd task
   ```

2. Verify `.env` database settings:
   ```ini
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=mailer_cloud
   DB_USERNAME=root
   DB_PASSWORD=
   ```

3. Run database migrations:
   ```bash
   php artisan migrate
   ```

4. Run automated test suite:
   ```bash
   php artisan test
   ```
   *Expected output: All 16 unit and feature tests pass (`100% OK`).*

---

## Step 2: Start the Laravel API Server

Start the Laravel HTTP server:
```bash
cd task
php artisan serve --port=8000
```
The API is now live at: `http://127.0.0.1:8000`

---

## Step 3: Start Kafka and Apache Flink (Docker)

1. Start the Kafka broker, Flink JobManager, and Flink TaskManager:
   ```bash
   cd task
   docker compose up -d
   ```

2. Check container status:
   ```bash
   docker ps
   ```
   You should see:
   - `mailercloud-kafka` (ports 9092, 29092)
   - `mailercloud-flink-jobmanager` (port 8081 - Web UI)
   - `mailercloud-flink-taskmanager`

3. Access the Flink Web Dashboard:
   Open [http://localhost:8081](http://localhost:8081) in your browser.

---

## Step 4: Run the Transactional Outbox Publisher

The outbox publisher fetches pending events from the `outbox_events` table and publishes them to the Kafka topic `submission-events`.

Run continuously in polling mode:
```bash
cd task
php artisan outbox:publish --poll --batch=200 --interval=500
```

*Options:*
- `--poll`: Runs as a daemon continuously polling for new events.
- `--batch=200`: Number of records to publish per batch.
- `--interval=500`: Polling sleep in milliseconds when idle.
- `--topic=submission-events`: Target Kafka topic.

---

## Step 5: Start Live Aggregation with Apache Flink

Deploy the Flink SQL streaming job inside the JobManager container:

```bash
docker exec -it mailercloud-flink-jobmanager /opt/flink/bin/sql-client.sh -f /opt/flink/jobs/hourly_aggregation.sql
```

This job:
1. Connects to Kafka topic `submission-events`.
2. Extracts event time from `created_at`.
3. Calculates `COUNT(DISTINCT event_id)` grouped by `(form_id, hour_start)`.
4. Continuously upserts the hourly counts into MySQL table `hourly_submission_aggregates`.

---

## Step 6: Running the Load Test (Part 2 Specifications)

The requirements state:
- **100,000 total requests** to `POST /submissions`.
- **20,000 duplicates** (reusing same `idempotency_key` values).
- **80,000 unique submissions**.
- Report: Stored count, Duplicates rejected/reused, Errors, Throughput (RPS), and Latency.

You have **three ways** to run the load test:

### Option A: High-Concurrency Async Python Script (`load_test.py`) (Recommended)
Uses `asyncio` and `aiohttp` for true HTTP network benchmarking with connection pooling and latency percentiles.

```bash
# Run full 100,000 test with 20,000 duplicates and 50 concurrent workers
python load_test.py --total 100000 --duplicates 20000 --concurrency 50 --url http://127.0.0.1:8000/api/submissions

# Or run a quick smoke test (e.g., 500 requests with 100 duplicates):
python load_test.py --total 500 --duplicates 100 --concurrency 10
```

### Option B: Built-in Artisan Command (`php artisan submissions:load-test`)
Runs in-process directly using Laravel's application container:

```bash
cd task
# Full test:
php artisan submissions:load-test --total=100000 --duplicates=20000

# Quick test:
php artisan submissions:load-test --total=500 --duplicates=100 --chunk=50
```

### Option C: Standalone PHP HTTP Script (`load_test.php`)
Uses PHP's native `curl_multi` for parallel asynchronous HTTP dispatching:

```bash
# Full test:
php load_test.php --total 100000 --duplicates 20000 --concurrency 50

# Quick test:
php load_test.php --total 500 --duplicates 100 --concurrency 10
```

---

## Step 7: Verifying Hourly Aggregates

Query the hourly aggregation API endpoint:
```bash
curl http://127.0.0.1:8000/api/forms/contact-form/aggregates/hourly
```

Sample JSON response:
```json
{
  "form_id": "contact-form",
  "aggregates": [
    {
      "id": 1,
      "form_id": "contact-form",
      "hour_start": "2026-10-23 14:00:00",
      "submission_count": 80000,
      "created_at": "2026-10-23T08:30:00.000000Z",
      "updated_at": "2026-10-23T09:15:00.000000Z"
    }
  ]
}
```

---

## Fault Tolerance & Crash Recovery Explanation

| Component | Failure Scenario | Recovery & Exactly-Once Mechanism |
|-----------|------------------|------------------------------------|
| **Laravel API** | Crashes during submission | Database transaction rolls back completely. Neither `submissions` nor `outbox_events` is partially written. |
| **Kafka Broker** | Broker is unreachable | API continues taking submissions safely. Events queue up in `outbox_events`. Outbox Publisher backs off and resumes publishing once Kafka recovers. |
| **Apache Flink Job** | JobManager or TaskManager crashes | Flink restores from the latest checkpoint stored in `flink-checkpoints` (`EXACTLY_ONCE` state backend). Kafka consumer group offset resets to the checkpointed position. |
| **MySQL Upsert Sink** | Repeated message playback | Flink uses `INSERT ... ON DUPLICATE KEY UPDATE` on the composite primary key `(form_id, hour_start)`. Replayed messages produce idempotent upserts without inflated duplicate counts. |
