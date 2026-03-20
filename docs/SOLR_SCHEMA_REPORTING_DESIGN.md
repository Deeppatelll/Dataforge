# Solr Schema And Query Capability (Reporting)

## Goal
Optimize Solr schema for reporting workloads:
- fast filtering and faceting
- stable sorting
- text search + highlighting
- safe evolution with controlled dynamic fields

## Schema Definition Updates

These are applied by bootstrap in `scripts/bootstrap.sh`.

### Strict/important static fields
- `event_id_s` (`string`, indexed+stored+docValues, required)
- `source_file_s` (`string`, indexed+stored+docValues)
- `row_num_i` (`pint`, indexed+stored+docValues)
- `category_s` (`string`, indexed+stored+docValues)
- `status_s` (`string`, indexed+stored+docValues)
- `name_s` (`string`, indexed+stored+docValues)
- `description_txt` (`text_general`, indexed+stored)
- `search_text_txt` (`text_general`, indexed+stored)
- `amount_f` (`pfloat`, indexed+stored+docValues)
- `quantity_i` (`pint`, indexed+stored+docValues)
- `is_active_b` (`boolean`, indexed+stored+docValues)
- `event_date_dt` (`pdate`, indexed+stored+docValues)
- `ingested_at_dt` (`pdate`, indexed+stored+docValues)
- `error_reason_s` (`string`, indexed+stored+docValues)
- `failed_at_dt` (`pdate`, indexed+stored+docValues)

### Controlled dynamic fields
- `*_s` -> `string`
- `*_txt` -> `text_general`
- `*_i` -> `pint`
- `*_f` -> `pfloat`
- `*_b` -> `boolean`
- `*_dt` -> `pdate`

This keeps schema flexible but bounded to safe/reportable types.

### Copy fields for broader search/highlight
- `name_s -> search_text_txt`
- `category_s -> search_text_txt`
- `status_s -> search_text_txt`
- `description_txt -> search_text_txt`

## Why Each Field Type Is Chosen

- `string` (`*_s`): exact match, faceting, grouping, sorting. No tokenization.
- `text_general` (`*_txt`): full-text search and highlighting across analyzed content.
- `pint`/`pfloat` (`*_i`/`*_f`): numeric filters, ranges, stats, sorting.
- `pdate` (`*_dt`): time-range filters, sorting by recency, time-based facets.
- `boolean` (`*_b`): yes/no filters and facet counts.

DocValues are enabled where reporting needs fast sorting/faceting/aggregation.

## Faceting / Pivot / Sorting / Highlighting

### Faceting support
Use string/numeric/date/boolean fields with docValues:
- examples: `category_s`, `status_s`, `source_file_s`, `is_active_b`

### Pivot faceting support
Use multi-level categorical fields:
- example pivot: `category_s,status_s`

### Sorting support
Sort on docValues-backed fields:
- `event_date_dt desc`
- `amount_f desc`
- `row_num_i asc`

### Highlighting support
Highlight text in `description_txt` or aggregate `search_text_txt`.

## Sample Indexed Document

```json
{
  "id": "rel-evt-1",
  "event_id_s": "rel-evt-1",
  "source_file_s": "sample.csv",
  "row_num_i": 12,
  "category_s": "finance",
  "status_s": "patched",
  "name_s": "Alpha",
  "description_txt": "Payment was adjusted after review",
  "search_text_txt": "Alpha finance patched Payment was adjusted after review",
  "amount_f": 99.0,
  "quantity_i": 3,
  "is_active_b": true,
  "event_date_dt": "2026-03-18T00:00:00Z",
  "ingested_at_dt": "2026-03-18T10:11:43Z"
}
```

## Validation Queries (prove behavior)

Run from PowerShell on host:

### 1) Schema fields present
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/schema/fields" | ConvertTo-Json -Depth 8
```
Check for:
- `event_id_s`, `search_text_txt`, `amount_f`, `event_date_dt`, `is_active_b`

### 2) Dynamic fields present
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/schema/dynamicfields" | ConvertTo-Json -Depth 8
```
Check for:
- `*_s`, `*_txt`, `*_i`, `*_f`, `*_b`, `*_dt`

### 3) Facet query
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/select?q=*:*&rows=0&facet=true&facet.field=category_s&facet.field=status_s&wt=json" | ConvertTo-Json -Depth 8
```

### 4) Pivot facet query
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/select?q=*:*&rows=0&facet=true&facet.pivot=category_s,status_s&wt=json" | ConvertTo-Json -Depth 12
```

### 5) Sorting query
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/select?q=*:*&rows=5&sort=event_date_dt%20desc,amount_f%20desc&fl=event_id_s,event_date_dt,amount_f,status_s&wt=json" | ConvertTo-Json -Depth 8
```

### 6) Highlight query
```powershell
Invoke-RestMethod "http://localhost:18983/solr/reportcore/select?q=search_text_txt:patched&rows=5&hl=true&hl.fl=description_txt,search_text_txt&hl.simple.pre=<mark>&hl.simple.post=</mark>&wt=json" | ConvertTo-Json -Depth 12
```

## Schema Validation And Index Optimization Notes

- Keep critical reporting dimensions as explicit fields (not only dynamic).
- Keep dynamic fields controlled by suffix convention; avoid arbitrary field sprawl.
- Use docValues for all filter/sort/facet-heavy fields.
- Keep text fields separate from exact-match fields (`*_txt` vs `*_s`).
- For heavy ingest windows, rely on `commitWithin` and soft-commit strategy rather than frequent hard commits.
- Use periodic optimize only for maintenance windows; avoid frequent optimize during active ingestion.
- Use warm-up queries after restart for latency-sensitive dashboards.
