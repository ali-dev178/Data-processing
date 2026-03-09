# Data Processing Service

Laravel-based microservice for processing data records from Kafka, storing them in PostgreSQL, and providing aggregation queries via REST API.

## Architecture

```
Producer Sources (banking, ecommerce, payroll)
  → Kafka (3 partitions)
    → Data Processing Service (3 consumer processes)
        → Idempotency check (insertOrIgnore on record_id)
        → Store record (DB transaction)
        → Upsert destination summary (atomic counters)
        → Dispatch notification job → Redis → Notification Worker
        → Dispatch alert job        → Redis → Alert Worker
    → PostgreSQL (records + destination_summaries)
  → REST API (GET /api/query — aggregation with filters)
```

## Technology Stack

- **Language:** PHP 8.3 — **Framework:** Laravel
- **Message Broker:** Kafka (KRaft mode, no Zookeeper) — high throughput, partition-based parallelism, offset tracking
- **Database:** PostgreSQL — ACID transactions, `ON CONFLICT` upserts, aggregation with `GROUP BY`
- **Queue backend:** Redis — job queue for async alert and notification dispatch (sub-millisecond push, non-blocking)
- **Containerization:** Docker Compose — PHP-FPM, nginx, supervisord .etc

## Project Structure

**Service code:**
- `app/Services/` — core business logic (record processing, aggregation)
- `app/Console/Commands/` — Kafka consumer, load test producer, verifier
- `app/Http/` — REST controller and request validation
- `app/Jobs/` — queued alert and notification jobs
- `app/Models/` and `app/Enums/` — Eloquent models and enums
- `database/migrations/` — table schemas and indexes
- `routes/api.php` — API route definitions
- `config/processing.php` — alert threshold and Kafka settings
- `tests/` — unit and integration tests

**Environment setup** (not service code):
- `Dockerfile`, `docker-compose.yml` — container definitions
- `setup.sh` — creates a fresh Laravel install and overlays the service code
- `docker/supervisord.conf`, `docker/nginx.conf` — process and web server config
- `load-test.sh` — multi-source load test runner

## Prerequisites

- Docker & Docker Compose

## Quick Start

```bash
docker-compose up -d --build
docker exec dps-worker sh -c "sh /app/setup.sh"
docker exec dps-worker sh -c "supervisorctl restart all"
```

## Multi-Source Load Test (Records Ingestions)

Produces N records per source (banking, ecommerce, payroll) in parallel via Kafka and verifies consumer throughput.

```bash
docker exec dps-worker sh /app/load-test.sh 100
```

## Query API

```bash
curl http://localhost:8000/api/query
curl "http://localhost:8000/api/query?type=positive&start_time=2026-03-09 00:00:00&end_time=2026-03-10 00:00:00"
```

## Scalability & Performance

Target: 100,000 messages/hour. Benchmark on a single Docker container with 3 consumer processes: **~150 records/sec (~544K records/hour)** — processed 102K records in ~11 minutes, exceeding the requirement by 5x.

Aggregation query benchmarks over 132K records (internal HTTP round trip via nginx, including JSON serialization):

| Query | Time |
|-------|------|
| No filters (all 132K records) | ~490ms |
| Type filter | ~340ms |
| Both filters (type + time range) | ~340ms |

> **Note:** These benchmarks are from a dev environment with no caching or Laravel optimizations enabled. Production results will be faster.

**Scaling further if needed:**
- Increase Kafka partitions + consumer processes (linear throughput scaling)
- Horizontal scaling of web and consumer tier
- Read replicas for the query API (separate read/write load)
- Table partitioning on `records.time` (keep query performance constant as data grows)
- Pagination on query API (`page` + `per_page`) for large result sets
- Exponential `$backoff` on jobs (e.g. `[5, 30, 120]`) — currently fixed at 5s between retries, exponential backoff would reduce pressure on downstream services during sustained failures

## Design Decisions

### Exactly-once processing
`insertOrIgnore` on a unique `record_id` + manual Kafka offset commit (`consume(100)` with `enable.auto.commit=false`). If the consumer crashes after insert but before commit, the message is re-delivered — but the duplicate insert is silently ignored.
**Alternative:** Kafka transactions — adds complexity with no real benefit since the DB is the source of truth. Auto-commit — risks losing records on crash.

### Atomic counters via `ON CONFLICT DO UPDATE`
Single `INSERT ... ON CONFLICT DO UPDATE ... RETURNING` atomically increments counters and returns updated values. No read-then-write, no row locks. Raw SQL instead of Laravel's `upsert()` because `upsert()` doesn't support `RETURNING`.
**Alternative:** `SELECT FOR UPDATE` + `UPDATE` — two round trips, explicit locking, slower under concurrency.

### Jobs dispatched outside the DB transaction
`EmitNotificationJob` and `EmitAlertJob` are dispatched after the transaction commits via Redis (sub-millisecond, non-blocking). If dispatched inside, a rollback would leave orphaned jobs.
**Alternative:** Event/listener layer — added unnecessary indirection. Direct dispatch is simpler.

### Separate queues for alerts vs notifications
Alerts go to `alerts` queue (1s poll), notifications to `notifications` (3s poll). Separate workers ensure alerts are never blocked by notifications. Separate jobs allow independent retry policies, log channels, and monitoring.
**Alternative:** Single queue — Laravel doesn't support priority within a queue. Alerts would block behind in-progress notifications.

### No REST endpoint for record ingestion
Records arrive exclusively via Kafka — the API is read-only (`GET /api/query`).

### `DECIMAL(20,4)` and `bcmath` for monetary values
All values stored as `DECIMAL(20,4)` in PostgreSQL, all arithmetic done with `bcmath` in PHP. Floating point would introduce rounding errors on aggregation.

### 3 Kafka partitions + 3 consumer processes
`numprocs=3` in supervisord, one consumer per partition. Maximum parallelism without rebalancing overhead.

### Two separate queries for aggregation instead of JSON_AGG
Summary query (`GROUP BY` with `COUNT`, `SUM`) computes totals in PostgreSQL. Records query fetches all matching rows. PHP groups records by `destination_id` and merges with summaries.

**Why not JSON_AGG?** Builds massive JSON strings per group inside PostgreSQL — memory overflow at 128MB on 100K+ records.
**Why not Eloquent?** Hydrates 100K+ model objects in PHP — ~100x slower than letting PostgreSQL do the aggregation.
**Why not `destination_summaries` table?** No `type`/`time` columns — can't apply query filters. Stores cumulative all-time totals, not filterable subsets.

### Composite indexes on `records` table
Two indexes — `(destination_id, time)` and `(destination_id, type)` — cover all query filter combinations. PostgreSQL combines them via BitmapAnd when both filters are used.
**Alternative:** Single composite index on all three columns — only efficient for one specific filter order. Separate indexes give the query planner more flexibility.


## Assumptions

- `recordId` is globally unique across all sources
- Message ordering is not guaranteed — processing is idempotent regardless of arrival order
- Each record belongs to exactly one destination and one source
- `value` is always a positive decimal — `type` field (`positive`/`negative`) indicates the direction
- Alert threshold compares raw `value` against a single threshold regardless of currency — see Known Limitations below

## Known Limitations

### Alert threshold does not account for currency
The alert threshold (`ALERT_VALUE_THRESHOLD`) compares the record's `value` directly without currency normalization. A threshold of `1000.00` treats 1000 SEK (≈90 EUR) the same as 1000 GBP (≈1170 EUR). This means alerts are inconsistent across currencies — low-value currencies trigger more false positives, high-value currencies may miss legitimate alerts.

**Possible solutions:**
- **Currency rates table** — store exchange rates in the database, normalize value to a base currency (e.g. EUR) before comparison. Rates can be updated periodically via an external API
- **Per-currency thresholds** — configure separate thresholds per unit in `config/processing.php` (e.g. `EUR => 1000, GBP => 850, SEK => 11000`)
- **Static mapping array** — hardcoded approximate rates for known currencies, simplest but least accurate

For this implementation, the threshold is applied as-is since currency normalization was not part of the requirement. The architecture supports adding it — the check happens in `RecordProcessingService` before dispatching `EmitAlertJob`, so a rate lookup can be inserted without changing the pipeline
