# Date Compare Full Logic

## 1) Compare Algorithm

### Inputs
- `date_field` (default: `event_date`)
- `mode`:
  - `previous_period`
  - `same_period_last_year`
  - `custom_previous` (backward compatible for explicit `previous` range)
- `current` range with `from` and `to`
- base `filters` object

### Validation
- `date_field` must resolve to a date field in catalog.
- `current.from` and `current.to` must be parseable dates.
- Range order must be valid (`to >= from`).
- `mode` must be one of supported values.
- `custom_previous` requires `previous.from` and `previous.to`.

### Comparison Range Derivation
- `previous_period`:
  - `span = current.to - current.from` (seconds)
  - `compare.to = current.from - 1 second`
  - `compare.from = compare.to - span`
- `same_period_last_year`:
  - `compare.from = current.from - 1 year`
  - `compare.to = current.to - 1 year`
- `custom_previous`:
  - Use user-supplied `previous` range after validation.

### Solr Query Strategy
- Build one base filter query from `filters`.
- Execute **dual Solr count queries**:
  - Query A: base filters + current date range
  - Query B: base filters + compare date range

### Metrics
- `current_value = count(current query)`
- `compare_value = count(compare query)`
- `absolute_difference = current_value - compare_value`
- `percentage_change = null` when `compare_value == 0`
- Otherwise:
  - `percentage_change = ((current_value - compare_value) / compare_value) * 100`

This safely handles divide-by-zero and empty result sets.

## 2) Backend Implementation

Implemented in [backend/api/src/Controllers/ReportingController.php](backend/api/src/Controllers/ReportingController.php):
- New mode-aware compare flow in `compare()`.
- Added helpers:
  - `parseRange()`
  - `deriveCompareRange()`
  - `buildDateScopedFilter()`
  - `parseDate()`
  - `toSolrDateTime()`
  - `formatRange()`
- Response now includes:
  - `mode`, `date_field`
  - `current.value`, `compare.value`
  - `absolute_difference`, `percentage_change`
  - backward-compatible `delta`, `delta_percent`, `previous`

Browser support fix:
- Added CORS headers in [backend/api/src/Core/HttpResponse.php](backend/api/src/Core/HttpResponse.php).
- Added OPTIONS preflight handling in [backend/api/public/index.php](backend/api/public/index.php).

## 3) Frontend Rendering Logic

Implemented in:
- [frontend/app/src/hooks/useReportingData.js](frontend/app/src/hooks/useReportingData.js)
- [frontend/app/src/components/Compare/ComparePanel.jsx](frontend/app/src/components/Compare/ComparePanel.jsx)

Behavior:
- User selects date field + mode (`previous_period` or `same_period_last_year`).
- Frontend sends:
  - `date_field`
  - `mode`
  - `current` range
  - global `filters`
- Panel renders:
  - current value
  - compare value
  - absolute difference
  - percentage change (`n/a` when null)
  - both current and compare ranges
- Safe fallback for backward-compatible fields if older payload keys are present.

## 4) Test Cases With Expected Outputs

Automated script:
- [scripts/run_compare_tests.ps1](scripts/run_compare_tests.ps1)

Run:
```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_compare_tests.ps1
```

### Case 1: previous period formula
Input:
- `mode = previous_period`
- current range March 2026
Expected:
- status `ok`
- compare payload exists
- `absolute_difference = current.value - compare.value`
- `percentage_change = null` only when `compare.value == 0`, else exact formula

### Case 2: same period last year
Input:
- `mode = same_period_last_year`
Expected:
- status `ok`
- mode returned as `same_period_last_year`
- compare range starts in previous year (`2025-*` for March 2026 input)

### Case 3: divide-by-zero guard
Input:
- `mode = custom_previous`
- previous range in year 1900 (empty baseline)
Expected:
- `compare.value = 0`
- `percentage_change = null`

### Case 4: empty result set stability
Input:
- future current range where no records exist
- `mode = same_period_last_year`
Expected:
- `current.value = 0`
- `compare.value = 0`
- `absolute_difference = 0`
- `percentage_change = null`

### Case 5: invalid range order
Input:
- `current.from > current.to`
Expected:
- HTTP error (400) from API

## 5) Live Runtime Verification Snapshot

From live API call:
- mode `previous_period`
- current value `3`
- compare value `0`
- absolute difference `3`
- percentage change `null`

This confirms divide-by-zero behavior and dual range execution in runtime.
