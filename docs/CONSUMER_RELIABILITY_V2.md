# Consumer To Solr Advanced Reliability

## Scope
This document defines the upgraded consumer reliability behavior for Kafka -> Solr indexing.

## Code Changes

### 1) Bootstrap now guarantees DLQ Solr core setup
- File: `scripts/bootstrap.sh`
- Added DLQ core and schema URLs:
  - `SOLR_DLQ_CORE=reportcore_dlq`
  - `SOLR_DLQ_SCHEMA_URL=http://solr:8983/solr/reportcore_dlq/schema`
- `verify_solr_core` now takes a core name argument and creates missing cores.
- `apply_schema` now applies to both main and DLQ schemas.
- Added DLQ fields:
  - `error_reason_s`
  - `failed_at_dt`

### 2) Solr container now precreates both cores
- File: `docker-compose.yml`
- Solr startup command now:
  - Ensures `/var/solr/data/configsets/_default` exists
  - Precreates `reportcore`
  - Precreates `reportcore_dlq`
  - Starts Solr foreground process

### 3) Consumer reliability upgrade
- File: `backend/worker/consumer.php`
- Added:
  - Consumer-group processing (group id env-based)
  - In-batch dedup logic (event_id or partition:offset fallback)
  - Batch Solr indexing with retries and exponential backoff
  - Partial update support using Solr atomic updates (`{"set": ...}`)
  - `commitWithin` + soft commit strategy via envs
  - Offset commit only after successful Solr write
  - Unrecoverable failures routed to DLQ topic
  - Invalid payloads routed to DLQ topic
  - Clear counters and logs for retries/commits/DLQ outcomes

## Transformation Contract

### Input message contract (Kafka value)
JSON object.

Supported operations:
- `operation = upsert` (default if missing)
- `operation = partial_update`

Required fields by operation:
- `upsert`: no strict required field, but if `event_id` missing, consumer derives from payload hash path in upsert conversion
- `partial_update`: `event_id` is required; otherwise sent to DLQ as invalid event

### Mapping for upsert
- `id` and `event_id_s` = `event_id`
- `source_file` -> `source_file_s`
- `row_number` -> `row_num_i`
- `ingested_at` date -> `ingested_at_dt`
- `event_date` date -> `event_date_dt`
- Dynamic type suffix mapping:
  - int -> `*_i`
  - float -> `*_f`
  - bool -> `*_b`
  - date-like field names (`date`/`time`) -> `*_dt`
  - else -> `*_s`

### Mapping for partial_update
- Solr atomic update document shape:
  - `id = event_id`
  - each changed field mapped to typed Solr field with `{"set": value}`
- Example:
  - input: `{"event_id":"a1","operation":"partial_update","amount":99,"status":"patched"}`
  - Solr doc: `{"id":"a1","amount_i":{"set":99},"status_s":{"set":"patched"}}`

## DLQ Payload Format

DLQ topic message (JSON):

```json
{
  "dlq_id": "sha1(...)",
  "failed_at": "2026-03-18T10:11:43+00:00",
  "reason": "invalid_event_missing_id | invalid_json_payload | solr_unrecoverable",
  "error": {
    "http_code": 0,
    "error": "invalid_document",
    "response": "",
    "attempts": 0,
    "retryable": false
  },
  "kafka": {
    "source_topic": "reliability_test_topic",
    "source_partition": 0,
    "source_offset": 3,
    "source_key": ""
  },
  "event_id": "",
  "operation": "partial_update",
  "payload": {
    "operation": "partial_update",
    "status": "bad-no-id"
  }
}
```

## Runtime Knobs

Consumer env vars:
- `KAFKA_TOPIC`
- `KAFKA_DLQ_TOPIC`
- `KAFKA_GROUP_ID`
- `CONSUMER_BATCH_SIZE`
- `CONSUMER_POLL_TIMEOUT_MS`
- `CONSUMER_IDLE_POLLS`
- `CONSUMER_MAX_RETRIES`
- `CONSUMER_RETRY_BACKOFF_MS`
- `DEDUP_MAX_ENTRIES`
- `SOLR_UPDATE_BASE_URL`
- `SOLR_TIMEOUT_SECONDS`
- `SOLR_COMMIT_WITHIN_MS`
- `SOLR_SOFT_COMMIT_EVERY_BATCH`
- `SOLR_SOFT_COMMIT_ON_FINAL_FLUSH`

## Test Scenarios And Expected Results

### Scenario 1: Upsert success
- Input: valid JSON with `event_id`
- Expected:
  - `indexed +1`
  - `commits +1`
  - document appears in `reportcore`

### Scenario 2: Partial update success
- Input: same `event_id`, `operation=partial_update`, changed fields
- Expected:
  - only specified fields are updated
  - `indexed +1`, `commits +1`

### Scenario 3: Invalid partial update (missing event_id)
- Input: `{"operation":"partial_update"...}` without `event_id`
- Expected:
  - not indexed
  - DLQ message published
  - source offset committed only after DLQ publish succeeds

### Scenario 4: Retryable Solr failure then recovery
- Step A: run consumer with bad Solr URL (connection refused)
- Expected A:
  - `solr_retryable_failures +1`
  - `commits = 0` for failed batch
- Step B: rerun same group with correct Solr URL
- Expected B:
  - same message reprocessed from Kafka
  - `indexed +1`, `commits +1`

### Scenario 5: Unrecoverable Solr failure (e.g., 4xx)
- Expected:
  - batch routed to DLQ
  - offsets committed only if all DLQ publishes succeed
  - if any DLQ publish fails, offsets remain uncommitted
