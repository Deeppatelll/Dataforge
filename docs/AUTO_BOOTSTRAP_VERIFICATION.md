# Auto Bootstrap And Portability Verification

## One-Command Startup

```powershell
cd c:\xampp\htdocs\kafka-php\DataForge
docker compose up -d --build
```

This should start all services and run bootstrap exactly once per startup cycle.

## Verify Bootstrap Completion

```powershell
docker logs dataforge-bootstrap --tail 200
docker compose ps -a
```

Expected markers:
- `[bootstrap] waiting for Kafka...`
- `[bootstrap] waiting for Solr...`
- topic describe output for `report_data_topic`
- topic describe output for `report_data_topic_dlq`
- optional informational line: `skipping copyField description_txt -> text because destination field is missing`
- `[bootstrap] completed`
- bootstrap container status should be `Exited (0)`

## Verify Topic Auto-Creation

```powershell
docker exec dataforge-kafka kafka-topics --bootstrap-server kafka:29092 --describe --topic report_data_topic
docker exec dataforge-kafka kafka-topics --bootstrap-server kafka:29092 --describe --topic report_data_topic_dlq
```

Expected outcomes:
- `Topic: report_data_topic`
- `PartitionCount:4`
- `Topic: report_data_topic_dlq`

## Verify Solr Core Auto-Creation

```powershell
Invoke-RestMethod "http://localhost:18983/solr/admin/cores?action=STATUS&core=reportcore"
```

Expected outcome:
- `status.reportcore.name = reportcore`

## Verify Solr Schema Auto-Apply

```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/schema/fields" | ConvertTo-Json -Depth 6
```

Expected fields present:
- `event_id_s`
- `amount_f`
- `event_date_dt`
- `description_txt`

## Verify Composer Auto-Install For PHP API

```powershell
docker logs dataforge-php-api --tail 200
```

Expected first run marker:
- `[php-api] vendor missing, running composer install`

## Verify Composer Auto-Install For PHP Worker

```powershell
docker logs dataforge-php-worker --tail 200
```

Expected first run marker:
- `[php-worker] vendor missing, running composer install`

## Verify API Health

```powershell
Invoke-RestMethod "http://localhost:18081/health"
```

Expected outcome:
- JSON with `status: ok` and `service: dataforge-php-api`

## Idempotency Re-Run Check

```powershell
docker compose down
docker compose up -d
```

Expected outcomes:
- Startup succeeds without manual commands.
- Existing topics/core are reused.
- Bootstrap does not create duplicates.
