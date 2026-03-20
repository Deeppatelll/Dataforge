# Saved Views Personalization With Solr-Driven Reads

## Goal
Persist user personalization preferences while keeping report read/query execution Solr-driven.

## Storage Approach Details

### Storage medium
- File-based JSON persistence in API container path from:
  - `SAVED_VIEWS_PATH` (default: `/tmp/reporting-saved-views.json`)

### Data model
Stored JSON shape:

- `users` object
- each user key has:
  - `default_view_id`: string or null
  - `views`: map keyed by view id

Per view record stores:
- `id`
- `user_id`
- `name`
- `description`
- `definition`
- `created_at`
- `updated_at`

`definition` persists personalization fields:
- `selectedColumns`
- `columnOrder`
- `columnWidths`
- `filters`
- `sort`
- plus related UI preferences (`visibleColumns`, `pageSize`, chart settings)

### Backward compatibility
If old flat-map saved-views format is found, repository auto-migrates in-memory to:
- user `guest`
- first existing view as default

### Solr read path separation
Saved views only store UI/query preference payloads.
Report reads still use existing Solr endpoints:
- `/api/query`
- `/api/facets`
- `/api/aggregation`
- `/api/compare`

No SQL engine introduced; Solr remains the read backend.

## API Contracts

User scoping is query-param based:
- `user_id=<id>`

### List saved views
- `GET /api/saved-views?user_id={userId}`

Response:
- `status`
- `user_id`
- `views[]` with `is_default`

### Create saved view
- `POST /api/saved-views?user_id={userId}`

Request:
- `name`
- `description`
- `definition` object
- optional `is_default` boolean

Response:
- `status`
- `view` with `is_default`

### Get saved view by id
- `GET /api/saved-views/{id}?user_id={userId}`

### Update saved view
- `PUT /api/saved-views/{id}?user_id={userId}`

Supports patch fields:
- `name`
- `description`
- `definition`
- `is_default`

### Delete saved view
- `DELETE /api/saved-views/{id}?user_id={userId}`

If deleted view is default, repository auto-falls back to remaining first view (or null).

### Load default view
- `GET /api/saved-views/default?user_id={userId}`

Returns:
- `view: null` when none
- or full default view object

### Set default by route
- `PUT /api/saved-views/{id}/default?user_id={userId}`

## Implementation

### Backend
- Repository with per-user buckets and default handling:
  - [backend/api/src/Repositories/SavedViewRepository.php](backend/api/src/Repositories/SavedViewRepository.php)
- Controller updated for user-scoped CRUD/default endpoints:
  - [backend/api/src/Controllers/SavedViewsController.php](backend/api/src/Controllers/SavedViewsController.php)
- Request helper for query/header extraction:
  - [backend/api/src/Core/HttpRequest.php](backend/api/src/Core/HttpRequest.php)
- API routing updates for default endpoints and user scoping:
  - [backend/api/public/index.php](backend/api/public/index.php)

### Frontend
- API client now sends `user_id` and supports default APIs:
  - [frontend/app/src/api/reportingApi.js](frontend/app/src/api/reportingApi.js)
- Browser user identity utility:
  - [frontend/app/src/utils/user.js](frontend/app/src/utils/user.js)
- Store adds `defaultViewId`:
  - [frontend/app/src/state/reportingStore.jsx](frontend/app/src/state/reportingStore.jsx)
- Data hook:
  - loads user id
  - lists saved views by user
  - loads default view on startup and applies definition
  - [frontend/app/src/hooks/useReportingData.js](frontend/app/src/hooks/useReportingData.js)
- Saved views panel:
  - save current
  - update active
  - delete
  - set default
  - apply default metadata
  - persists selected columns/order/widths/filters/sort
  - [frontend/app/src/components/SavedViews/SavedViewsPanel.jsx](frontend/app/src/components/SavedViews/SavedViewsPanel.jsx)

## End-to-End Validation

### Automated suite
- Script:
  - [scripts/run_saved_views_personalization_tests.ps1](scripts/run_saved_views_personalization_tests.ps1)

Run command:
- `powershell -ExecutionPolicy Bypass -File .\scripts\run_saved_views_personalization_tests.ps1`

### Verified scenarios
1. Create user-scoped saved view with full personalization fields
2. Set default view for user
3. Load default view for user
4. Update saved view
5. Delete saved view
6. User isolation (user A cannot see user B views)
7. Default fallback reassignment when default is deleted
8. Solr query endpoint remains operational and independent

### Latest test result
All scenarios passed.

### Frontend build validation
- `npm run build` passed after personalization updates.
