# Interviewer Evaluation Guide

This document maps the assessment requirements directly to the implementation and provides quick commands for verification.

---

## Quick Evaluation Commands

Run these from the repository:

```bash
cd task

# 1. Run all unit & feature tests
php artisan test

# 2. Run the 100k load test (80k unique, 20k duplicate)
php artisan submissions:load-test --total=100000 --duplicates=20000 --truncate
```

---

## Requirements Verification Matrix

### Part 1: Submission API

| Requirement | Implementation File | Verification Command | Result |
|---|---|---|---|
| POST /submissions accepts JSON | `app/Http/Controllers/SubmissionController.php` | `php artisan test --filter=test_valid_submission_creates_a_record` | Passed |
| Return 400 if email invalid | `app/Http/Requests/StoreSubmissionRequest.php` | `php artisan test --filter=test_invalid_email_returns_http_400` | Passed |
| Return 400 if form_id missing | `app/Http/Requests/StoreSubmissionRequest.php` | `php artisan test --filter=test_missing_form_id_returns_http_400` | Passed |
| Prevent duplicates via idempotency_key | `app/Services/SubmissionService.php` | `php artisan test --filter=test_duplicate_key_returns_existing_submission` | Passed |
| Return existing record on duplicate | `app/Services/SubmissionService.php` | `php artisan test --filter=test_repeated_requests_do_not_create_duplicate_records` | Passed |
| GET /forms/{form_id}/submissions (10 per page) | `app/Http/Controllers/SubmissionController.php` | `php artisan test --filter=test_pagination_returns_no_more_than_10_records` | Passed |
| Accurate pagination metadata | `app/Http/Controllers/SubmissionController.php` | `php artisan test --filter=test_pagination_returns_correct_metadata` | Passed |

---

### Part 2: Load Testing

| Requirement | Target | Achieved | Verification Method |
|---|---|---|---|
| Total Requests | 100,000 | 100,000 | `php artisan submissions:load-test --total=100000 --duplicates=20000 --truncate` |
| Unique Keys | 80,000 | 80,000 | Verified in `submissions` table count |
| Duplicate Keys | 20,000 | 20,000 | Verified: returned HTTP 200 without inserting |
| Error Count | 0 | 0 | Reported in summary table |
| Throughput | Calculated | Reported | Displayed in console output (req/s) |

---

### Part 3: Live Aggregation with Apache Flink

| Requirement | Implementation | Notes |
|---|---|---|
| Hourly counts per form | `docker/flink-jobs/hourly_aggregation.sql` | Tumbling window grouped by `form_id` and hour start |
| Continuous live updates | `app/Console/Commands/OutboxPublishCommand.php` | Transactional outbox pattern streaming to Kafka |
| Aggregate query endpoint | `routes/api.php` (`GET /forms/{id}/aggregates/hourly`) | Reads from `hourly_submission_aggregates` |
| Crash recovery explanation | Section in `README.md` | Chandy-Lamport checkpointing, Kafka offset rewind, composite PK upsert, and `COUNT(DISTINCT event_id)` |

---

## Technical Architecture Notes

1. **Transactional Outbox**: Submissions and their outbox events are committed in a single MySQL transaction. The background worker publishes to Kafka asynchronously. This prevents dual-write inconsistencies and decouples client response time from Kafka latency.
2. **Concurrency Safety**: To handle simultaneous requests with the same `idempotency_key`, the service combines an initial index check, a transaction with `lockForUpdate()`, and a MySQL unique constraint. If a race condition triggers a duplicate key exception (SQLSTATE 23000), it catches the exception and returns the existing record.
3. **Flink Exactly-Once Semantics**: Checkpointing runs every 5 seconds. If a node fails, Flink rolls back Kafka consumer offsets to the last checkpoint. The MySQL sink uses upsert on `(form_id, hour_start)` with `COUNT(DISTINCT event_id)` to prevent double counting.
