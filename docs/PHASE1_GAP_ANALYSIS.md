# Phase 1: Gap Analysis From Current State

## 1) Already Done (Validated From Existing Codebase)

1. Dockerized core services are already running in the existing project.
   - Kafka + Zookeeper + Solr + Kafka UI are defined in `docker-compose.yml`.
2. Kafka UI is already integrated and connected to broker (`kafka:29092`).
3. Kafka topic-based pipeline already works with producer and consumer scripts.
4. Topic partition target has already been reached operationally (4 partitions for main topic).
5. Solr indexing is already working (`csvcore` core is active).
6. Producer has baseline batching and retry.
7. Consumer has baseline batch indexing into Solr with `commitWithin`.

## 2) Missing For Required Reporting Module

### A. Platform automation gaps
1. Idempotent bootstrap is missing:
   - Automatic creation/verification of reporting topic (`report_data_topic`) with 4 partitions.
   - Automatic creation/verification of DLQ topic.
   - Automatic creation/verification of dedicated reporting Solr core/collection.
2. Composer auto-install at container startup is missing for reporting backend/worker services.
3. Single-command, fresh-PC reproducibility for new reporting module is not yet packaged.

### B. Data pipeline hardening gaps
1. Producer does not yet enforce schema validation contract for reporting payloads.
2. Producer chunk size is low vs requirement (currently 50; target 500-1000 configurable).
3. Consumer does not yet implement explicit DLQ write path.
4. Consumer does not yet implement production-grade offset management flow (group consumer semantics with post-success commits).
5. Dedupe strategy is not formalized as a requirement-level module.

### C. Solr design gaps
1. Reporting schema is not explicitly defined (strict + controlled dynamic strategy).
2. Faceting/pivot faceting/highlighting coverage is not packaged by contract.
3. Compare-query-friendly date and metric fields are not explicitly standardized.

### D. Backend API gaps (major)
1. No reporting API layer yet for:
   - Dynamic query endpoint
   - Facets endpoint
   - Aggregations endpoint
   - Date compare endpoint
   - Saved views endpoints
2. Advanced nested filter-to-Solr query builder (AND/OR tree) not implemented.
3. Standard pagination/sorting response contract not implemented.

### E. Frontend gaps (major)
1. React reporting module is not present yet.
2. Missing components:
   - FilterBuilder
   - DataTable
   - ChartRenderer
   - SavedViews
   - DateCompare panel
3. Missing personalization UX:
   - Column show/hide
   - Drag reorder
   - Width adjustments with persistence

### F. Saved views and preference persistence
1. Persistence layer for per-user report state is missing in the reporting module.
2. Default-view behavior and restore flow are missing.

### G. Delivery/quality gaps
1. Formal requirement-coverage matrix not yet documented.
2. End-to-end acceptance checklist not yet packaged for evaluator run.
3. Demo script/README for zero-touch setup not yet written for reporting module.

## 3) Exact Implementation Order (From Here)

1. Bootstrap and portability foundation in new folder.
2. Reporting producer hardening.
3. Reporting consumer hardening + DLQ + dedupe.
4. Reporting Solr schema and query capabilities.
5. PHP API + dynamic nested filter engine.
6. Saved views persistence + endpoints.
7. React reporting UI and component integration.
8. Compare mode, final QA, README, and demo script.

## 4) Acceptance Checklist Per Implementation Item

### Item 1: Bootstrap foundation
- [ ] `docker compose up --build` in reporting module starts all services.
- [ ] `report_data_topic` exists with partition count 4.
- [ ] `report_data_topic_dlq` exists.
- [ ] reporting Solr core exists.
- [ ] Composer dependencies install automatically for PHP services.

### Item 2: Producer hardening
- [ ] Streams large CSV without loading full file in memory.
- [ ] Configurable chunk size defaults to 500 or higher.
- [ ] Validation rejects malformed records to error log.
- [ ] Retry policy handles transient Kafka failures.

### Item 3: Consumer hardening
- [ ] Group-based consumption is used.
- [ ] Dedupe key is consistently enforced.
- [ ] Solr writes are batched and robust.
- [ ] Failed records route to DLQ.
- [ ] Commit/ack occurs only after successful write.

### Item 4: Solr schema
- [ ] Field types support string/text/number/date/boolean.
- [ ] Facets and pivot facets are queryable.
- [ ] Sorting and compare fields are validated.

### Item 5: API layer
- [ ] Nested AND/OR filter tree converts to valid Solr params.
- [ ] Pagination and sorting contract is stable.
- [ ] Facets and aggregations endpoints return usable data.
- [ ] Compare endpoint returns current/previous/delta/%change.

### Item 6: Saved views
- [ ] Can save/load/delete views.
- [ ] Includes columns/order/widths/filters/sorting.
- [ ] Default view restore works.

### Item 7: React UI
- [ ] Advanced filter builder functional.
- [ ] Data table customization functional.
- [ ] Charts (bar/line/pie) functional.
- [ ] Drill-down filter from chart click works.

### Item 8: Delivery readiness
- [ ] README enables fresh-machine run.
- [ ] Requirement coverage matrix completed.
- [ ] End-to-end demo script executes successfully.
