# PHP Reporting API With Advanced Filter Engine

## MVC Structure

The API is now split into MVC-style layers instead of keeping everything in one file.

- Front controller and routing:
  - `backend/api/public/index.php`
- Controllers:
  - `backend/api/src/Controllers/HealthController.php`
  - `backend/api/src/Controllers/ReportingController.php`
  - `backend/api/src/Controllers/SavedViewsController.php`
- Services (query/filter builder):
  - `backend/api/src/Services/FilterQueryService.php`
- Domain (field catalog and field constraints):
  - `backend/api/src/Domain/FieldCatalog.php`
- Repository (saved views persistence):
  - `backend/api/src/Repositories/SavedViewRepository.php`
- Core utilities:
  - `backend/api/src/Core/ApiException.php`
  - `backend/api/src/Core/HttpRequest.php`
  - `backend/api/src/Core/HttpResponse.php`
  - `backend/api/src/Core/SolrClient.php`
- Configuration:
  - `backend/api/src/Config/AppConfig.php`

This keeps responsibilities clean:
- Router handles endpoint dispatch only.
- Controllers orchestrate endpoint logic.
- Service builds Solr-ready filters/sort/pagination.
- Repository isolates file persistence.
- Core handles transport, Solr calls, and structured errors.

## Base URL
- `http://localhost:18081`

## Error Response Format
All API errors return:

```json
{
  "status": "error",
  "error": {
    "code": "ERROR_CODE",
    "message": "Human readable message",
    "details": {},
    "timestamp": "2026-03-18T11:00:00+00:00"
  }
}
```

## Filter Contract
Root filter supports nested groups:

```json
{
  "type": "group",
  "logic": "AND",
  "conditions": [
    {
      "type": "rule",
      "field": "status",
      "operator": "in",
      "value": ["approved", "pending"]
    },
    {
      "type": "group",
      "logic": "OR",
      "conditions": [
        {
          "type": "rule",
          "field": "amount",
          "operator": "gte",
          "value": 100
        },
        {
          "type": "rule",
          "field": "name",
          "operator": "contains",
          "value": "Alpha"
        }
      ]
    }
  ]
}
```

Supported operators:
- `eq`, `neq`
- `in`, `not_in`
- `contains`, `starts_with`
- `gte`, `lte`, `between`
- `exists`, `not_exists`

Supported filter types:
- text
- single or multi dropdown
- number range
- boolean
- date range
- nested AND OR groups

## Endpoint Contracts

### 1) Query Endpoint
- `POST /api/query`
- Purpose: table data with pagination and sorting.

Request:
```json
{
  "page": 1,
  "page_size": 25,
  "columns": ["event_id", "name", "category", "status", "amount", "event_date"],
  "sort": [
    {"field": "event_date", "direction": "desc"},
    {"field": "amount", "direction": "desc"}
  ],
  "filters": {
    "type": "group",
    "logic": "AND",
    "conditions": [
      {"type": "rule", "field": "category", "operator": "eq", "value": "finance"}
    ]
  }
}
```

Response:
```json
{
  "status": "ok",
  "data": [],
  "pagination": {
    "page": 1,
    "page_size": 25,
    "total": 123
  },
  "meta": {
    "fq": "(category_s:\"finance\")",
    "sort": "event_date_dt desc,amount_f desc",
    "columns": ["id", "event_id_s", "name_s", "category_s", "status_s", "amount_f", "event_date_dt"]
  }
}
```

### 2) Facets Endpoint
- `POST /api/facets`
- Purpose: dropdown options and autocomplete support.

Request:
```json
{
  "fields": ["category", "status", "source_file"],
  "limit": 20,
  "contains": "fin",
  "filters": {
    "type": "group",
    "logic": "AND",
    "conditions": []
  }
}
```

Response:
```json
{
  "status": "ok",
  "facets": {
    "category_s": [{"value": "finance", "count": 1}],
    "status_s": [{"value": "approved", "count": 1}]
  },
  "meta": {
    "contains": "fin",
    "limit": 20
  }
}
```

### 3) Aggregation Endpoint
- `POST /api/aggregation`
- Purpose: KPI and chart-friendly buckets.

Request:
```json
{
  "metrics": ["amount", "quantity"],
  "group_by": "status",
  "filters": {
    "type": "group",
    "logic": "AND",
    "conditions": [
      {"type": "rule", "field": "category", "operator": "eq", "value": "finance"}
    ]
  }
}
```

Response:
```json
{
  "status": "ok",
  "kpi": {
    "count": 10,
    "metrics": {
      "amount_f": {"min": 10.0, "max": 200.0, "sum": 500.0, "mean": 50.0, "count": 10},
      "quantity_i": {"min": 1.0, "max": 4.0, "sum": 15.0, "mean": 1.5, "count": 10}
    }
  },
  "buckets": [
    {"key": "approved", "count": 7},
    {"key": "pending", "count": 3}
  ]
}
```

### 4) Compare Endpoint
- `POST /api/compare`
- Purpose: current vs previous date-window comparison.

Request:
```json
{
  "date_field": "event_date",
  "current": {"from": "2026-03-01", "to": "2026-03-18"},
  "previous": {"from": "2026-02-11", "to": "2026-02-28"},
  "filters": {
    "type": "group",
    "logic": "AND",
    "conditions": [
      {"type": "rule", "field": "category", "operator": "eq", "value": "finance"}
    ]
  }
}
```

Response:
```json
{
  "status": "ok",
  "compare": {
    "current": {"count": 20, "range": {"from": "2026-03-01", "to": "2026-03-18"}},
    "previous": {"count": 10, "range": {"from": "2026-02-11", "to": "2026-02-28"}},
    "delta": 10,
    "delta_percent": 100.0
  }
}
```

### 5) Columns Endpoint
- `GET /api/columns`
- Purpose: dynamic column catalog from strict + controlled dynamic fields.

Response:
```json
{
  "status": "ok",
  "columns": [
    {
      "key": "event_id",
      "solr_field": "event_id_s",
      "type": "string",
      "filterable": true,
      "sortable": true,
      "facetable": true
    }
  ]
}
```

### 6) Saved Views Endpoints
- `GET /api/saved-views`
- `POST /api/saved-views`
- `GET /api/saved-views/{id}`
- `PUT /api/saved-views/{id}`
- `DELETE /api/saved-views/{id}`

Create request:
```json
{
  "name": "Finance Approved",
  "description": "My default finance table",
  "definition": {
    "columns": ["event_id", "name", "category", "status", "amount"],
    "sort": [{"field": "event_date", "direction": "desc"}],
    "filters": {
      "type": "group",
      "logic": "AND",
      "conditions": [
        {"type": "rule", "field": "category", "operator": "eq", "value": "finance"},
        {"type": "rule", "field": "status", "operator": "eq", "value": "approved"}
      ]
    }
  }
}
```

Create response:
```json
{
  "status": "ok",
  "view": {
    "id": "view_abc123",
    "name": "Finance Approved",
    "description": "My default finance table",
    "definition": {},
    "created_at": "2026-03-18T11:00:00+00:00",
    "updated_at": "2026-03-18T11:00:00+00:00"
  }
}
```

## Pagination And Sorting
- `page` starts from `1`.
- `page_size` default `50`, max `500`.
- sorting accepts array of `{field, direction}`.
- allowed directions: `asc` or `desc`.

## Endpoint-Level Test Payloads

### Health
```powershell
Invoke-RestMethod "http://localhost:18081/health" | ConvertTo-Json -Depth 6
```

### Query
```powershell
$body = @{
  page = 1
  page_size = 10
  columns = @("event_id", "name", "category", "status", "amount", "event_date")
  sort = @(@{ field = "event_date"; direction = "desc" })
  filters = @{
    type = "group"
    logic = "AND"
    conditions = @(
      @{ type = "rule"; field = "category"; operator = "eq"; value = "finance" }
    )
  }
} | ConvertTo-Json -Depth 12
Invoke-RestMethod "http://localhost:18081/api/query" -Method Post -ContentType "application/json" -Body $body | ConvertTo-Json -Depth 10
```

### Facets
```powershell
$body = @{
  fields = @("category", "status")
  limit = 10
  contains = "a"
  filters = @{ type = "group"; logic = "AND"; conditions = @() }
} | ConvertTo-Json -Depth 12
Invoke-RestMethod "http://localhost:18081/api/facets" -Method Post -ContentType "application/json" -Body $body | ConvertTo-Json -Depth 10
```

### Aggregation
```powershell
$body = @{
  metrics = @("amount", "quantity")
  group_by = "status"
  filters = @{ type = "group"; logic = "AND"; conditions = @() }
} | ConvertTo-Json -Depth 12
Invoke-RestMethod "http://localhost:18081/api/aggregation" -Method Post -ContentType "application/json" -Body $body | ConvertTo-Json -Depth 12
```

### Compare
```powershell
$body = @{
  date_field = "event_date"
  current = @{ from = "2026-03-01"; to = "2026-03-18" }
  previous = @{ from = "2026-02-11"; to = "2026-02-28" }
  filters = @{ type = "group"; logic = "AND"; conditions = @() }
} | ConvertTo-Json -Depth 12
Invoke-RestMethod "http://localhost:18081/api/compare" -Method Post -ContentType "application/json" -Body $body | ConvertTo-Json -Depth 10
```

### Columns
```powershell
Invoke-RestMethod "http://localhost:18081/api/columns" | ConvertTo-Json -Depth 8
```

### Saved Views
```powershell
$create = @{
  name = "Finance Approved"
  description = "Saved from API"
  definition = @{
    columns = @("event_id", "name", "status", "amount")
    sort = @(@{ field = "event_date"; direction = "desc" })
    filters = @{
      type = "group"
      logic = "AND"
      conditions = @(
        @{ type = "rule"; field = "category"; operator = "eq"; value = "finance" }
      )
    }
  }
} | ConvertTo-Json -Depth 12
$created = Invoke-RestMethod "http://localhost:18081/api/saved-views" -Method Post -ContentType "application/json" -Body $create
$created | ConvertTo-Json -Depth 10

Invoke-RestMethod "http://localhost:18081/api/saved-views" | ConvertTo-Json -Depth 8
Invoke-RestMethod "http://localhost:18081/api/saved-views/$($created.view.id)" | ConvertTo-Json -Depth 8

$update = @{ name = "Finance Approved Updated" } | ConvertTo-Json -Depth 5
Invoke-RestMethod "http://localhost:18081/api/saved-views/$($created.view.id)" -Method Put -ContentType "application/json" -Body $update | ConvertTo-Json -Depth 8

Invoke-RestMethod "http://localhost:18081/api/saved-views/$($created.view.id)" -Method Delete | ConvertTo-Json -Depth 8
```
