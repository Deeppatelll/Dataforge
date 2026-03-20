<?php

declare(strict_types=1);

namespace Reporting\Api\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Reporting\Api\Core\ApiException;
use Reporting\Api\Core\SolrClient;
use Reporting\Api\Domain\FieldCatalog;
use Reporting\Api\Services\FilterQueryService;

final class ReportingController
{
    public function __construct(
        private readonly SolrClient $solr,
        private readonly FilterQueryService $filters,
        private readonly string $solrBaseUrl,
        private readonly string $sampleDataPath = '/app/sample-data'
    ) {
    }

    public function query(array $payload): array
    {
        if ($this->isFrontendCompatQuery($payload)) {
            return $this->queryFrontendCompat($payload);
        }

        $pagination = $this->filters->parsePagination($payload);
        $sort = $this->filters->parseSort($payload);
        $fl = implode(',', $this->filters->parseColumns($payload));
        $fqExpr = $this->filters->buildBaseFilterQuery($payload);

        $url = $this->solrBaseUrl . '/select?wt=json&q=' . rawurlencode('*:*')
            . '&fq=' . rawurlencode($fqExpr)
            . '&start=' . $pagination['start']
            . '&rows=' . $pagination['page_size']
            . '&sort=' . rawurlencode($sort)
            . '&fl=' . rawurlencode($fl);

        $result = $this->solr->request('GET', $url);
        $docs = $result['response']['docs'] ?? [];
        if (!is_array($docs)) {
            $docs = [];
        }

        return [
            'status' => 'ok',
            'data' => $docs,
            'pagination' => [
                'page' => $pagination['page'],
                'page_size' => $pagination['page_size'],
                'total' => (int) ($result['response']['numFound'] ?? 0),
            ],
            'meta' => [
                'fq' => $fqExpr,
                'sort' => $sort,
                'columns' => explode(',', $fl),
            ],
        ];
    }

    public function facets(array $payload): array
    {
        $fields = $payload['fields'] ?? ['category', 'status'];
        if (!is_array($fields) || $fields === []) {
            throw new ApiException(400, 'INVALID_FACET_FIELDS', 'fields must be a non-empty array');
        }

        $limit = max(1, min(100, (int) ($payload['limit'] ?? 20)));
        $contains = trim((string) ($payload['contains'] ?? ''));
        $fqPayload = $this->buildCompatPayload($payload);
        $fq = $this->filters->buildBaseFilterQuery($fqPayload);

        $facetParts = [];
        foreach ($fields as $logicalField) {
            if (!is_string($logicalField) || trim($logicalField) === '') {
                continue;
            }

            $field = FieldCatalog::resolve(trim($logicalField));
            if (($field['facetable'] ?? false) !== true) {
                continue;
            }
            $facetParts[] = 'facet.field=' . rawurlencode($field['solr']);
        }

        if ($facetParts === []) {
            throw new ApiException(400, 'INVALID_FACET_FIELDS', 'No facetable fields were provided');
        }

        $url = $this->solrBaseUrl . '/select?wt=json&q=' . rawurlencode('*:*')
            . '&fq=' . rawurlencode($fq)
            . '&rows=0&facet=true&facet.limit=' . $limit
            . '&facet.mincount=1&facet.sort=count';

        if ($contains !== '') {
            $url .= '&facet.contains=' . rawurlencode($contains);
        }

        $url .= '&' . implode('&', $facetParts);
        $result = $this->solr->request('GET', $url);

        $rawFacets = $result['facet_counts']['facet_fields'] ?? [];
        $facets = [];
        foreach ($rawFacets as $fieldName => $values) {
            if (!is_array($values)) {
                continue;
            }

            $pairs = [];
            for ($i = 0; $i < count($values); $i += 2) {
                $pairs[] = [
                    'value' => $values[$i],
                    'count' => (int) ($values[$i + 1] ?? 0),
                ];
            }

            $facets[$fieldName] = $pairs;
            $logical = $this->logicalFieldFromSolr($fieldName);
            if ($logical !== $fieldName) {
                $facets[$logical] = $pairs;
            }
        }

        return [
            'status' => 'ok',
            'facets' => $facets,
            'meta' => [
                'contains' => $contains,
                'limit' => $limit,
            ],
        ];
    }

    public function aggregation(array $payload): array
    {
        $metricFields = $payload['metrics'] ?? ['amount'];
        if (!is_array($metricFields) || $metricFields === []) {
            throw new ApiException(400, 'INVALID_AGGREGATION_METRICS', 'metrics must be a non-empty array');
        }

        $fq = $this->filters->buildBaseFilterQuery($payload);
        $statsFields = [];
        foreach ($metricFields as $fieldName) {
            if (!is_string($fieldName) || trim($fieldName) === '') {
                continue;
            }

            $field = FieldCatalog::resolve(trim($fieldName));
            if ($field['type'] !== 'number') {
                continue;
            }
            $statsFields[] = 'stats.field=' . rawurlencode($field['solr']);
        }

        if ($statsFields === []) {
            throw new ApiException(400, 'INVALID_AGGREGATION_METRICS', 'No numeric metric fields provided');
        }

        $url = $this->solrBaseUrl . '/select?wt=json&q=' . rawurlencode('*:*')
            . '&fq=' . rawurlencode($fq)
            . '&rows=0&stats=true&' . implode('&', $statsFields);

        $groupBy = trim((string) ($payload['group_by'] ?? ''));
        $facetLimit = max(1, min(100, (int) ($payload['facet_limit'] ?? 25)));
        $bucketSortRaw = strtolower(trim((string) ($payload['bucket_sort'] ?? 'count')));
        $bucketSort = $bucketSortRaw === 'index' ? 'index' : 'count';
        if ($groupBy !== '') {
            $groupField = FieldCatalog::resolve($groupBy);
            if (($groupField['facetable'] ?? false) !== true) {
                throw new ApiException(400, 'INVALID_GROUP_BY_FIELD', 'group_by field must be facetable', ['field' => $groupBy]);
            }
            $url .= '&facet=true&facet.field=' . rawurlencode($groupField['solr']) . '&facet.mincount=1&facet.limit=' . $facetLimit . '&facet.sort=' . rawurlencode($bucketSort);
        }

        $result = $this->solr->request('GET', $url);
        $stats = $result['stats']['stats_fields'] ?? [];
        $kpi = [
            'count' => (int) ($result['response']['numFound'] ?? 0),
            'metrics' => $stats,
        ];

        $buckets = [];
        if ($groupBy !== '') {
            $groupField = FieldCatalog::resolve($groupBy);
            $raw = $result['facet_counts']['facet_fields'][$groupField['solr']] ?? [];
            if (is_array($raw)) {
                for ($i = 0; $i < count($raw); $i += 2) {
                    $buckets[] = ['key' => $raw[$i], 'count' => (int) ($raw[$i + 1] ?? 0)];
                }
            }
        }

        return [
            'status' => 'ok',
            'kpi' => $kpi,
            'buckets' => $buckets,
        ];
    }

    public function compare(array $payload): array
    {
        $dateFieldName = trim((string) ($payload['date_field'] ?? 'event_date'));
        $dateField = FieldCatalog::resolve($dateFieldName);
        if ($dateField['type'] !== 'date') {
            throw new ApiException(400, 'INVALID_DATE_FIELD', 'date_field must be a date-type field', ['field' => $dateFieldName]);
        }

        $modeRaw = strtolower(trim((string) ($payload['mode'] ?? '')));
        if ($modeRaw === '') {
            $modeRaw = isset($payload['previous']) ? 'custom_previous' : 'previous_period';
        }
        $allowedModes = ['previous_period', 'same_period_last_year', 'custom_previous'];
        if (!in_array($modeRaw, $allowedModes, true)) {
            throw new ApiException(400, 'INVALID_COMPARE_MODE', 'mode must be previous_period, same_period_last_year, or custom_previous', ['mode' => $modeRaw]);
        }

        $currentRange = $this->parseRange($payload['current'] ?? null, 'current');
        $compareRange = $this->deriveCompareRange($currentRange, $modeRaw, $payload['previous'] ?? null);

        $baseFq = $this->filters->buildBaseFilterQuery($payload);
        $currentFq = $this->buildDateScopedFilter($baseFq, $dateField['solr'], $currentRange);
        $compareFq = $this->buildDateScopedFilter($baseFq, $dateField['solr'], $compareRange);

        // Dual Solr query execution for current and comparison windows.
        $currentValue = $this->countByFilter($currentFq);
        $compareValue = $this->countByFilter($compareFq);
        $absoluteDifference = $currentValue - $compareValue;
        $percentageChange = $compareValue === 0 ? null : (($absoluteDifference / $compareValue) * 100.0);

        return [
            'status' => 'ok',
            'compare' => [
                'mode' => $modeRaw,
                'date_field' => $dateFieldName,
                'current' => ['value' => $currentValue, 'count' => $currentValue, 'range' => $this->formatRange($currentRange)],
                'compare' => ['value' => $compareValue, 'count' => $compareValue, 'range' => $this->formatRange($compareRange)],
                'previous' => ['count' => $compareValue, 'range' => $this->formatRange($compareRange)],
                'absolute_difference' => $absoluteDifference,
                'delta' => $absoluteDifference,
                'percentage_change' => $percentageChange,
                'delta_percent' => $percentageChange,
                'percentage_change_available' => $percentageChange !== null,
            ],
        ];
    }

    public function columns(): array
    {
        $catalog = FieldCatalog::catalog();
        $schema = $this->solr->request('GET', $this->solrBaseUrl . '/schema/fields');
        $schemaFields = $schema['fields'] ?? [];
        if (!is_array($schemaFields)) {
            $schemaFields = [];
        }

        $columns = [];
        foreach ($catalog as $logical => $meta) {
            $columns[] = [
                'key' => $logical,
                'solr_field' => $meta['solr'],
                'type' => $meta['type'],
                'filterable' => $meta['filterable'],
                'sortable' => $meta['sortable'],
                'facetable' => $meta['facetable'],
            ];
        }

        foreach ($schemaFields as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }

            $name = (string) $field['name'];
            if (!$this->isControlledDynamic($name)) {
                continue;
            }

            $already = false;
            foreach ($columns as $c) {
                if ($c['solr_field'] === $name) {
                    $already = true;
                    break;
                }
            }
            if ($already) {
                continue;
            }

            $type = 'string';
            if (str_ends_with($name, '_txt')) {
                $type = 'text';
            } elseif (str_ends_with($name, '_i') || str_ends_with($name, '_f')) {
                $type = 'number';
            } elseif (str_ends_with($name, '_b')) {
                $type = 'boolean';
            } elseif (str_ends_with($name, '_dt')) {
                $type = 'date';
            }

            $columns[] = [
                'key' => $name,
                'solr_field' => $name,
                'type' => $type,
                'filterable' => true,
                'sortable' => $type !== 'text',
                'facetable' => $type !== 'text',
            ];
        }

        // Include indexed dynamic fields discovered at runtime so UI can query real dataset columns.
        $luke = $this->solr->request('GET', $this->solrBaseUrl . '/admin/luke?wt=json&numTerms=0');
        $lukeFields = $luke['fields'] ?? [];
        if (is_array($lukeFields)) {
            foreach (array_keys($lukeFields) as $fieldName) {
                if (!is_string($fieldName) || !$this->isControlledDynamic($fieldName)) {
                    continue;
                }

                $already = false;
                foreach ($columns as $c) {
                    if (($c['solr_field'] ?? null) === $fieldName) {
                        $already = true;
                        break;
                    }
                }
                if ($already) {
                    continue;
                }

                $type = 'string';
                if (str_ends_with($fieldName, '_txt')) {
                    $type = 'text';
                } elseif (str_ends_with($fieldName, '_i') || str_ends_with($fieldName, '_f')) {
                    $type = 'number';
                } elseif (str_ends_with($fieldName, '_b')) {
                    $type = 'boolean';
                } elseif (str_ends_with($fieldName, '_dt')) {
                    $type = 'date';
                }

                $columns[] = [
                    'key' => $fieldName,
                    'solr_field' => $fieldName,
                    'type' => $type,
                    'filterable' => true,
                    'sortable' => $type !== 'text',
                    'facetable' => $type !== 'text',
                ];
            }
        }

        return [
            'status' => 'ok',
            'columns' => $columns,
        ];
    }

    public function schema(): array
    {
        $columnsResult = $this->columns();
        $columns = $columnsResult['columns'] ?? [];
        if (!is_array($columns)) {
            $columns = [];
        }

        $fields = [];
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }

            $name = (string) ($column['key'] ?? '');
            if ($name === '') {
                continue;
            }

            $solrField = (string) ($column['solr_field'] ?? $name);
            $type = $this->frontendTypeFromColumn($name, $solrField, (string) ($column['type'] ?? 'string'));

            $fields[] = [
                'name' => $name,
                'label' => $this->humanizeFieldLabel($name),
                'type' => $type,
                'filterable' => (bool) ($column['filterable'] ?? true),
                'sortable' => (bool) ($column['sortable'] ?? true),
                'facetable' => (bool) ($column['facetable'] ?? true),
            ];
        }

        $fields = $this->orderAndDeduplicateSchemaFields($fields, $this->fetchLukeFieldDocs());

        return [
            'status' => 'ok',
            'fields' => $fields,
        ];
    }

    public function produce(array $payload): array
    {
        $inputPath = trim((string) ($payload['path'] ?? $this->sampleDataPath));
        if ($inputPath === '') {
            throw new ApiException(400, 'INVALID_INPUT_PATH', 'path must be non-empty');
        }

        $files = $this->discoverCsvFiles($inputPath);
        if ($files === []) {
            throw new ApiException(404, 'CSV_NOT_FOUND', 'No CSV files found for indexing', ['path' => $inputPath]);
        }

        $updateUrl = $this->solrBaseUrl . '/update?wt=json&commitWithin=1000';
        $indexedFiles = 0;
        $importedRows = 0;
        $batch = [];

        foreach ($files as $filePath) {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                continue;
            }

            $headers = fgetcsv($handle);
            if (!is_array($headers) || $headers === []) {
                fclose($handle);
                continue;
            }

            $sampleRows = [];
            while (count($sampleRows) < 120 && ($sampleLine = fgetcsv($handle)) !== false) {
                if (!is_array($sampleLine)) {
                    continue;
                }
                $sampleRows[] = $sampleLine;
            }

            $fieldMap = $this->buildImportFieldMapFromHeaders($headers, $sampleRows);
            $rowNumber = 0;
            $sourceFile = basename($filePath);
            $indexedFiles++;

            foreach ($sampleRows as $line) {
                $rowNumber++;
                $doc = [
                    'source_file_s' => $sourceFile,
                    'row_num_i' => $rowNumber,
                    'ingested_at_dt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                ];

                foreach ($fieldMap as $idx => $meta) {
                    $raw = isset($line[$idx]) ? trim((string) $line[$idx]) : '';
                    if ($raw === '') {
                        continue;
                    }

                    $fieldName = (string) ($meta['field'] ?? '');
                    if ($fieldName === '') {
                        continue;
                    }

                    $value = $this->castForField($fieldName, $raw);
                    if ($value === null) {
                        continue;
                    }

                    $doc[$fieldName] = $value;
                }

                if (!isset($doc['event_id_s'])) {
                    $doc['event_id_s'] = sha1($sourceFile . ':' . $rowNumber);
                }
                $doc['id'] = (string) $doc['event_id_s'];

                $batch[] = $doc;
                $importedRows++;

                if (count($batch) >= 500) {
                    $this->solr->request('POST', $updateUrl, $batch);
                    $batch = [];
                }
            }

            while (($line = fgetcsv($handle)) !== false) {
                if (!is_array($line)) {
                    continue;
                }

                $rowNumber++;
                $doc = [
                    'source_file_s' => $sourceFile,
                    'row_num_i' => $rowNumber,
                    'ingested_at_dt' => gmdate('Y-m-d\\TH:i:s\\Z'),
                ];

                foreach ($fieldMap as $idx => $meta) {
                    $raw = isset($line[$idx]) ? trim((string) $line[$idx]) : '';
                    if ($raw === '') {
                        continue;
                    }

                    $fieldName = (string) ($meta['field'] ?? '');
                    if ($fieldName === '') {
                        continue;
                    }

                    $value = $this->castForField($fieldName, $raw);
                    if ($value === null) {
                        continue;
                    }

                    $doc[$fieldName] = $value;
                }

                if (!isset($doc['event_id_s'])) {
                    $doc['event_id_s'] = sha1($sourceFile . ':' . $rowNumber);
                }
                $doc['id'] = (string) $doc['event_id_s'];

                $batch[] = $doc;
                $importedRows++;

                if (count($batch) >= 500) {
                    $this->solr->request('POST', $updateUrl, $batch);
                    $batch = [];
                }
            }

            fclose($handle);
        }

        if ($batch !== []) {
            $this->solr->request('POST', $updateUrl, $batch);
        }

        $this->solr->request('POST', $updateUrl, ['commit' => (object) []]);

        return [
            'status' => 'ok',
            'indexed_files' => $indexedFiles,
            'imported' => $importedRows,
            'path' => $inputPath,
        ];
    }

    public function export(array $payload): array
    {
        $format = strtolower(trim((string) ($payload['format'] ?? 'csv')));
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new ApiException(400, 'INVALID_EXPORT_FORMAT', 'format must be csv or json');
        }

        $requestPayload = $payload;
        $requestPayload['page'] = 1;
        $requestPayload['page_size'] = max(1, min(10000, (int) ($payload['page_size'] ?? 2000)));
        $result = $this->query($requestPayload);

        $rows = $result['data'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $columns = $this->filters->parseColumns($requestPayload);
        $timestamp = gmdate('Ymd_His');

        if ($format === 'json') {
            return [
                'status' => 'ok',
                'format' => 'json',
                'file_name' => 'report_export_' . $timestamp . '.json',
                'content' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                'meta' => [
                    'rows' => count($rows),
                    'columns' => $columns,
                ],
            ];
        }

        return [
            'status' => 'ok',
            'format' => 'csv',
            'file_name' => 'report_export_' . $timestamp . '.csv',
            'content' => $this->toCsv($rows, $columns),
            'meta' => [
                'rows' => count($rows),
                'columns' => $columns,
            ],
        ];
    }

    public function importCsv(array $payload): array
    {
        $csvText = (string) ($payload['csv'] ?? '');
        if (trim($csvText) === '') {
            throw new ApiException(400, 'INVALID_IMPORT_PAYLOAD', 'csv must be a non-empty string');
        }

        $sourceFile = trim((string) ($payload['file_name'] ?? 'upload.csv'));
        $sourceFile = $sourceFile === '' ? 'upload.csv' : $sourceFile;
        $rows = $this->parseCsvRows($csvText);
        if ($rows === []) {
            return [
                'status' => 'ok',
                'imported' => 0,
                'message' => 'No rows found in CSV payload',
            ];
        }

        $headers = array_keys($rows[0]);
        $fieldMap = $this->buildImportFieldMap($headers, $rows);
        $now = gmdate('Y-m-d\\TH:i:s\\Z');

        $docs = [];
        foreach ($rows as $index => $row) {
            $doc = [
                'source_file_s' => $sourceFile,
                'row_num_i' => $index + 1,
                'ingested_at_dt' => $now,
            ];

            foreach ($fieldMap as $header => $meta) {
                $raw = $row[$header] ?? '';
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    continue;
                }

                $value = $this->castForField($meta['field'], $raw);
                if ($value === null) {
                    continue;
                }
                $doc[$meta['field']] = $value;
            }

            if (!isset($doc['event_id_s'])) {
                $doc['event_id_s'] = sha1($sourceFile . ':' . ($index + 1));
            }
            $doc['id'] = (string) $doc['event_id_s'];

            $docs[] = $doc;
        }

        $updateUrl = $this->solrBaseUrl . '/update?wt=json&commitWithin=1000';
        foreach (array_chunk($docs, 500) as $chunk) {
            $this->solr->request('POST', $updateUrl, $chunk);
        }
        $this->solr->request('POST', $updateUrl, ['commit' => (object) []]);

        return [
            'status' => 'ok',
            'imported' => count($docs),
            'source_file' => $sourceFile,
            'fields' => array_values(array_map(static fn(array $entry) => $entry['field'], $fieldMap)),
        ];
    }

    private function isFrontendCompatQuery(array $payload): bool
    {
        return array_key_exists('rows', $payload)
            || array_key_exists('fields', $payload)
            || is_string($payload['sort'] ?? null)
            || $this->isSimpleFilterList($payload['filters'] ?? null)
            || array_key_exists('dateCompare', $payload);
    }

    private function queryFrontendCompat(array $payload): array
    {
        $normalized = $this->buildCompatPayload($payload);
        $compare = $payload['dateCompare'] ?? null;
        if (!is_array($compare) || !isset($compare['from'], $compare['to'])) {
            $result = $this->executeQueryPayload($normalized);

            return [
                'status' => 'ok',
                'docs' => $result['docs'],
                'total' => $result['total'],
                'page' => $normalized['page'] ?? 1,
                'rows' => $normalized['page_size'] ?? 50,
            ];
        }

        $dateFieldName = trim((string) ($compare['field'] ?? 'ingested_at'));
        $dateField = FieldCatalog::resolve($dateFieldName);
        if ($dateField['type'] !== 'date') {
            throw new ApiException(400, 'INVALID_DATE_FIELD', 'dateCompare.field must resolve to a date field', ['field' => $dateFieldName]);
        }

        $modeRaw = strtolower(trim((string) ($compare['type'] ?? 'previous_period')));
        if ($modeRaw !== 'same_period_last_year') {
            $modeRaw = 'previous_period';
        }

        $currentRange = $this->parseRange([
            'from' => (string) $compare['from'],
            'to' => (string) $compare['to'],
        ], 'current');
        $compareRange = $this->deriveCompareRange($currentRange, $modeRaw, null);

        $currentPayload = $normalized;
        $currentPayload['filters'] = $this->appendDateRuleToFilters($normalized['filters'] ?? ['type' => 'group', 'logic' => 'AND', 'conditions' => []], $dateFieldName, $currentRange);
        $currentData = $this->executeQueryPayload($currentPayload);

        $previousPayload = $normalized;
        $previousPayload['filters'] = $this->appendDateRuleToFilters($normalized['filters'] ?? ['type' => 'group', 'logic' => 'AND', 'conditions' => []], $dateFieldName, $compareRange);
        $previousData = $this->executeQueryPayload($previousPayload);

        $absoluteDifference = $currentData['total'] - $previousData['total'];
        $percentageChange = $previousData['total'] === 0
            ? null
            : round(($absoluteDifference / $previousData['total']) * 100.0, 2);

        return [
            'status' => 'ok',
            'current' => [
                'docs' => $currentData['docs'],
                'total' => $currentData['total'],
            ],
            'compare' => [
                'docs' => $previousData['docs'],
                'total' => $previousData['total'],
            ],
            'difference' => [
                'absolute' => $absoluteDifference,
                'percentage' => $percentageChange,
            ],
        ];
    }

    private function executeQueryPayload(array $payload): array
    {
        $pagination = $this->filters->parsePagination($payload);
        $sort = $this->filters->parseSort($payload);
        $fl = implode(',', $this->filters->parseColumns($payload));
        $fqExpr = $this->filters->buildBaseFilterQuery($payload);

        $url = $this->solrBaseUrl . '/select?wt=json&q=' . rawurlencode('*:*')
            . '&fq=' . rawurlencode($fqExpr)
            . '&start=' . $pagination['start']
            . '&rows=' . $pagination['page_size']
            . '&sort=' . rawurlencode($sort)
            . '&fl=' . rawurlencode($fl);

        $result = $this->solr->request('GET', $url);
        $rawDocs = $result['response']['docs'] ?? [];
        if (!is_array($rawDocs)) {
            $rawDocs = [];
        }

        $docs = [];
        foreach ($rawDocs as $doc) {
            if (!is_array($doc)) {
                continue;
            }
            $docs[] = $this->mapDocToFrontendShape($doc);
        }

        return [
            'docs' => $docs,
            'total' => (int) ($result['response']['numFound'] ?? 0),
            'page' => $pagination['page'],
            'rows' => $pagination['page_size'],
            'sort' => $sort,
            'fq' => $fqExpr,
        ];
    }

    private function buildCompatPayload(array $payload): array
    {
        $normalized = $payload;
        $simpleFilters = [];
        $sortField = '';
        if (is_array($payload['filters'] ?? null)) {
            $simpleFilters = $payload['filters'];
        }

        if (isset($payload['rows']) && !isset($payload['page_size'])) {
            $normalized['page_size'] = (int) $payload['rows'];
        }

        if (isset($payload['sort']) && is_string($payload['sort'])) {
            $sortRaw = trim($payload['sort']);
            if ($sortRaw === '' || str_starts_with(strtolower($sortRaw), 'score ')) {
                $normalized['sort'] = [[
                    'field' => 'row_num',
                    'direction' => 'asc',
                ]];
            } else {
                $parts = preg_split('/\s+/', $sortRaw);
                $field = (string) ($parts[0] ?? '');
                $direction = strtolower((string) ($parts[1] ?? 'asc'));
                $sortField = trim($field);
                $normalized['sort'] = [[
                    'field' => $field,
                    'direction' => $direction,
                ]];
            }
        }

        if (isset($payload['fields']) && is_array($payload['fields'])) {
            if ($payload['fields'] === ['*']) {
                $normalized['columns'] = [];
            } else {
                $normalized['columns'] = $this->expandCompatColumns($payload['fields']);
            }
        }

        if ($this->isSimpleFilterList($simpleFilters)) {
            $normalized['filters'] = $this->simpleFiltersToTree($simpleFilters);
        }

        // For small selected unique-column sets, ensure result rows include at least one selected value.
        $selectedFields = $payload['fields'] ?? [];
        if (is_array($selectedFields) && $selectedFields !== ['*']) {
            $presenceRule = $this->buildSelectedColumnsPresenceRule($selectedFields);
            if ($presenceRule !== null) {
                $normalized['filters'] = $this->appendRuleWithAnd(
                    is_array($normalized['filters'] ?? null)
                        ? $normalized['filters']
                        : ['type' => 'group', 'logic' => 'AND', 'conditions' => []],
                    $presenceRule
                );
            }
        }

        if ($sortField !== '' && $this->hasSimpleSourceFileFilter($simpleFilters) && !$this->isSortFieldAlwaysPresent($sortField)) {
            $normalized['filters'] = $this->appendRuleWithAnd(
                is_array($normalized['filters'] ?? null)
                    ? $normalized['filters']
                    : ['type' => 'group', 'logic' => 'AND', 'conditions' => []],
                [
                    'type' => 'rule',
                    'field' => $sortField,
                    'operator' => 'exists',
                ]
            );
        }

        return $normalized;
    }

    private function isSimpleFilterList(mixed $filters): bool
    {
        if (!is_array($filters) || $filters === []) {
            return false;
        }

        if (isset($filters['type']) || isset($filters['conditions'])) {
            return false;
        }

        foreach ($filters as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['field']) || isset($entry['op']) || isset($entry['min']) || isset($entry['max'])) {
                return true;
            }
        }

        return false;
    }

    private function simpleFiltersToTree(array $filters): array
    {
        $rules = [];
        $ops = [];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $rule = $this->simpleFilterToRule($filter);
            if ($rule === null) {
                continue;
            }

            $rules[] = $rule;
            $ops[] = strtoupper((string) ($filter['op'] ?? 'AND')) === 'OR' ? 'OR' : 'AND';
        }

        if ($rules === []) {
            return ['type' => 'group', 'logic' => 'AND', 'conditions' => []];
        }

        $tree = $rules[0];
        for ($i = 1; $i < count($rules); $i++) {
            $tree = [
                'type' => 'group',
                'logic' => $ops[$i] ?? 'AND',
                'conditions' => [$tree, $rules[$i]],
            ];
        }

        return $tree;
    }

    private function simpleFilterToRule(array $filter): ?array
    {
        $field = trim((string) ($filter['field'] ?? ''));
        if ($field === '') {
            return null;
        }

        $type = strtolower(trim((string) ($filter['type'] ?? 'text')));
        $value = $filter['value'] ?? null;

        if ($type === 'range') {
            $min = $filter['min'] ?? null;
            $max = $filter['max'] ?? null;
            if ($min !== null && $min !== '' && $max !== null && $max !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'between', 'value' => ['from' => $min, 'to' => $max]];
            }
            if ($min !== null && $min !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'gte', 'value' => $min];
            }
            if ($max !== null && $max !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'lte', 'value' => $max];
            }
            return null;
        }

        if ($type === 'date_range') {
            $from = $filter['from'] ?? null;
            $to = $filter['to'] ?? null;
            if ($from !== null && $from !== '' && $to !== null && $to !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'between', 'value' => ['from' => $from, 'to' => $to]];
            }
            if ($from !== null && $from !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'gte', 'value' => $from];
            }
            if ($to !== null && $to !== '') {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'lte', 'value' => $to];
            }
            return null;
        }

        if ($type === 'multi_select') {
            if (is_array($value) && $value !== []) {
                return ['type' => 'rule', 'field' => $field, 'operator' => 'in', 'value' => $value];
            }
            return null;
        }

        if ($type === 'boolean') {
            if ($value === '' || $value === null) {
                return null;
            }
            if (is_string($value)) {
                $value = strtolower($value) === 'true';
            }
            return ['type' => 'rule', 'field' => $field, 'operator' => 'eq', 'value' => (bool) $value];
        }

        if ($type === 'exists') {
            return ['type' => 'rule', 'field' => $field, 'operator' => 'exists'];
        }

        if ($value === '' || $value === null) {
            return null;
        }

        return ['type' => 'rule', 'field' => $field, 'operator' => 'contains', 'value' => (string) $value];
    }

    private function appendDateRuleToFilters(array $filters, string $field, array $range): array
    {
        $dateRule = [
            'type' => 'rule',
            'field' => $field,
            'operator' => 'between',
            'value' => [
                'from' => $this->toSolrDateTime($range['from']),
                'to' => $this->toSolrDateTime($range['to']),
            ],
        ];

        if ($filters === [] || !isset($filters['type'])) {
            return [
                'type' => 'group',
                'logic' => 'AND',
                'conditions' => [$dateRule],
            ];
        }

        return [
            'type' => 'group',
            'logic' => 'AND',
            'conditions' => [$filters, $dateRule],
        ];
    }

    private function appendRuleWithAnd(array $filters, array $rule): array
    {
        if ($filters === [] || !isset($filters['type'])) {
            return [
                'type' => 'group',
                'logic' => 'AND',
                'conditions' => [$rule],
            ];
        }

        return [
            'type' => 'group',
            'logic' => 'AND',
            'conditions' => [$filters, $rule],
        ];
    }

    private function buildSelectedColumnsPresenceRule(array $selectedFields): ?array
    {
        // Avoid generating very large OR clauses for full/all-column queries.
        if (count($selectedFields) === 0 || count($selectedFields) > 20) {
            return null;
        }

        $commonSet = array_fill_keys([
            'event_id', 'source_file', 'row_num', 'category', 'status', 'name',
            'description', 'search_text', 'amount', 'quantity', 'is_active',
            'event_date', 'ingested_at', 'error_reason', 'failed_at',
        ], true);

        $rules = [];
        foreach ($selectedFields as $field) {
            if (!is_string($field)) {
                continue;
            }

            $name = trim($field);
            if ($name === '' || isset($commonSet[$name])) {
                continue;
            }

            try {
                FieldCatalog::resolve($name);
            } catch (ApiException) {
                continue;
            }

            $rules[] = [
                'type' => 'rule',
                'field' => $name,
                'operator' => 'exists',
            ];
        }

        if ($rules === []) {
            return null;
        }

        if (count($rules) === 1) {
            return $rules[0];
        }

        return [
            'type' => 'group',
            'logic' => 'OR',
            'conditions' => $rules,
        ];
    }

    private function hasSimpleSourceFileFilter(array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $field = trim((string) ($filter['field'] ?? ''));
            if ($field === 'source_file' || $field === 'source_file_s') {
                return true;
            }
        }
        return false;
    }

    private function isSortFieldAlwaysPresent(string $field): bool
    {
        return in_array($field, ['event_id', 'source_file', 'row_num', 'ingested_at'], true);
    }

    private function mapDocToFrontendShape(array $doc): array
    {
        $mapped = $doc;
        foreach ($doc as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $logical = $this->logicalFieldFromSolr($key);
            if (!array_key_exists($logical, $mapped)) {
                $mapped[$logical] = $value;
            }

            // Backfill sibling typed variants so frontend-selected keys are populated consistently.
            if (preg_match('/^(.+)_([a-z]+)$/', $key, $matches) === 1) {
                $base = $matches[1];
                $suffix = $matches[2];

                if ($suffix === 'i') {
                    if (!array_key_exists($base . '_f', $mapped)) {
                        $mapped[$base . '_f'] = (float) $value;
                    }
                    if (!array_key_exists($base . '_s', $mapped)) {
                        $mapped[$base . '_s'] = (string) $value;
                    }
                }

                if ($suffix === 'f') {
                    if (!array_key_exists($base . '_i', $mapped)) {
                        $mapped[$base . '_i'] = (int) round((float) $value);
                    }
                    if (!array_key_exists($base . '_s', $mapped)) {
                        $mapped[$base . '_s'] = (string) $value;
                    }
                }

                if ($suffix === 's') {
                    $asString = trim((string) $value);
                    if ($asString !== '' && is_numeric($asString)) {
                        if (!array_key_exists($base . '_i', $mapped)) {
                            $mapped[$base . '_i'] = (int) $asString;
                        }
                        if (!array_key_exists($base . '_f', $mapped)) {
                            $mapped[$base . '_f'] = (float) $asString;
                        }
                    }
                }
            }
        }

        return $mapped;
    }

    private function expandCompatColumns(array $columns): array
    {
        $expanded = [];
        foreach ($columns as $column) {
            if (!is_string($column) || trim($column) === '') {
                continue;
            }

            $name = trim($column);
            $expanded[] = $name;

            if (preg_match('/^(.+)_([a-z]+)$/', $name, $matches) !== 1) {
                continue;
            }

            $base = $matches[1];
            $suffix = $matches[2];

            if ($suffix === 'i') {
                $expanded[] = $base . '_f';
                $expanded[] = $base . '_s';
            } elseif ($suffix === 'f') {
                $expanded[] = $base . '_i';
                $expanded[] = $base . '_s';
            } elseif ($suffix === 's') {
                $expanded[] = $base . '_i';
                $expanded[] = $base . '_f';
            }
        }

        return array_values(array_unique($expanded));
    }

    private function logicalFieldFromSolr(string $solrField): string
    {
        foreach (FieldCatalog::catalog() as $logical => $meta) {
            if (($meta['solr'] ?? null) === $solrField) {
                return $logical;
            }
        }

        return $solrField;
    }

    private function humanizeFieldLabel(string $field): string
    {
        $label = preg_replace('/(_s|_txt|_i|_f|_b|_dt)$/', '', $field) ?? $field;
        $label = str_replace('_', ' ', $label);
        return ucwords($label);
    }

    private function frontendTypeFromColumn(string $name, string $solrField, string $type): string
    {
        if ($type === 'date') {
            return 'date';
        }

        if ($type === 'boolean') {
            return 'boolean';
        }

        if ($type === 'number') {
            return str_ends_with($solrField, '_i') || str_ends_with($name, '_i') ? 'integer' : 'float';
        }

        return 'text';
    }

    private function orderAndDeduplicateSchemaFields(array $fields, array $docsByField): array
    {
        $commonOrder = [
            'event_id', 'source_file', 'row_num', 'category', 'status', 'name',
            'description', 'search_text', 'amount', 'quantity', 'is_active',
            'event_date', 'ingested_at', 'error_reason', 'failed_at',
        ];

        $commonSet = array_fill_keys($commonOrder, true);
        $commonByName = [];
        $familyBuckets = [];
        $otherFields = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }

            if (isset($commonSet[$name])) {
                $commonByName[$name] = $field;
                continue;
            }

            if (preg_match('/^([A-Z0-9]+)_([^_]+)_(s|i|f|b|dt|txt)$/', $name, $m) === 1) {
                $prefix = $m[1];
                $base = strtoupper($m[2]);
                $key = $prefix . '|' . $base;
                $score = ((int) ($docsByField[$name] ?? 0)) * 100 + $this->schemaSuffixPreference($name);

                if (!isset($familyBuckets[$key]) || $score > $familyBuckets[$key]['score']) {
                    $familyBuckets[$key] = [
                        'score' => $score,
                        'prefix' => $prefix,
                        'base' => $base,
                        'field' => $field,
                    ];
                }
                continue;
            }

            $otherFields[] = $field;
        }

        $ordered = [];
        foreach ($commonOrder as $commonName) {
            if (isset($commonByName[$commonName])) {
                $ordered[] = $commonByName[$commonName];
            }
        }

        $coreBaseOrder = ['NAME', 'PRICE', 'SKU', 'URL'];
        $byPrefix = [];
        foreach ($familyBuckets as $entry) {
            $byPrefix[$entry['prefix']][] = $entry;
        }
        ksort($byPrefix);

        foreach ($byPrefix as $prefix => $entries) {
            $byBase = [];
            foreach ($entries as $entry) {
                $byBase[$entry['base']] = $entry;
            }

            foreach ($coreBaseOrder as $base) {
                if (isset($byBase[$base])) {
                    $ordered[] = $byBase[$base]['field'];
                    unset($byBase[$base]);
                }
            }

            ksort($byBase);
            foreach ($byBase as $entry) {
                $ordered[] = $entry['field'];
            }
        }

        usort($otherFields, static fn (array $a, array $b): int => strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? '')));
        foreach ($otherFields as $field) {
            $ordered[] = $field;
        }

        $unique = [];
        $seenNames = [];
        foreach ($ordered as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string) ($field['name'] ?? '');
            if ($name === '' || isset($seenNames[$name])) {
                continue;
            }
            $seenNames[$name] = true;
            $unique[] = $field;
        }

        $commonPriority = array_fill_keys($commonOrder, true);
        $bestByLabel = [];

        foreach ($unique as $field) {
            $name = (string) ($field['name'] ?? '');
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if ($name === '' || $label === '') {
                continue;
            }

            $score = ((int) ($docsByField[$name] ?? 0)) * 100 + $this->schemaSuffixPreference($name);
            if (isset($commonPriority[$name])) {
                $score += 100000;
            }

            if (!isset($bestByLabel[$label]) || $score > $bestByLabel[$label]['score']) {
                $bestByLabel[$label] = ['score' => $score, 'field' => $field];
            }
        }

        $dedupedByLabel = [];
        $seenLabel = [];
        foreach ($unique as $field) {
            $label = strtolower(trim((string) ($field['label'] ?? '')));
            if ($label === '' || isset($seenLabel[$label])) {
                continue;
            }

            if (isset($bestByLabel[$label])) {
                $dedupedByLabel[] = $bestByLabel[$label]['field'];
                $seenLabel[$label] = true;
            }
        }

        return $dedupedByLabel;
    }

    private function fetchLukeFieldDocs(): array
    {
        $luke = $this->solr->request('GET', $this->solrBaseUrl . '/admin/luke?wt=json&numTerms=0');
        $fieldMeta = $luke['fields'] ?? [];
        if (!is_array($fieldMeta)) {
            return [];
        }

        $docsByField = [];
        foreach ($fieldMeta as $fieldName => $meta) {
            if (!is_string($fieldName) || !is_array($meta)) {
                continue;
            }
            $docsByField[$fieldName] = (int) ($meta['docs'] ?? 0);
        }

        return $docsByField;
    }

    private function schemaSuffixPreference(string $fieldName): int
    {
        if (str_ends_with($fieldName, '_s')) {
            return 60;
        }
        if (str_ends_with($fieldName, '_txt')) {
            return 55;
        }
        if (str_ends_with($fieldName, '_f')) {
            return 50;
        }
        if (str_ends_with($fieldName, '_i')) {
            return 40;
        }
        if (str_ends_with($fieldName, '_dt')) {
            return 45;
        }
        if (str_ends_with($fieldName, '_b')) {
            return 35;
        }

        return 10;
    }

    private function discoverCsvFiles(string $inputPath): array
    {
        if (is_file($inputPath)) {
            return [$inputPath];
        }

        if (is_dir($inputPath)) {
            $files = glob(rtrim($inputPath, '/\\') . DIRECTORY_SEPARATOR . '*.csv');
            if ($files === false) {
                return [];
            }
            sort($files);
            return $files;
        }

        return [];
    }

    private function buildImportFieldMapFromHeaders(array $headers, array $sampleRows = []): array
    {
        $fieldMap = [];
        foreach ($headers as $index => $header) {
            $logical = $this->normalizeLogicalField((string) $header);
            $catalogCandidate = strtolower($logical);
            try {
                $resolved = FieldCatalog::resolve($catalogCandidate);
                $fieldName = $resolved['solr'];
            } catch (ApiException) {
                $sampleValues = [];
                foreach ($sampleRows as $row) {
                    if (!is_array($row) || !array_key_exists($index, $row)) {
                        continue;
                    }
                    $value = trim((string) $row[$index]);
                    if ($value === '') {
                        continue;
                    }
                    $sampleValues[] = $value;
                    if (count($sampleValues) >= 80) {
                        break;
                    }
                }

                $suffix = $this->inferSuffix($sampleValues);
                $fieldName = $logical . $suffix;
            }

            $fieldMap[$index] = ['field' => $fieldName];
        }

        return $fieldMap;
    }

    private function countByFilter(string $fq): int
    {
        $url = $this->solrBaseUrl . '/select?wt=json&q=' . rawurlencode('*:*') . '&fq=' . rawurlencode($fq) . '&rows=0';
        $result = $this->solr->request('GET', $url);
        return (int) ($result['response']['numFound'] ?? 0);
    }

    private function parseCsvRows(string $csvText): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new ApiException(500, 'CSV_STREAM_ERROR', 'Unable to initialize CSV reader');
        }

        fwrite($stream, $csvText);
        rewind($stream);

        $headers = fgetcsv($stream);
        if (!is_array($headers) || $headers === []) {
            fclose($stream);
            return [];
        }

        $normalizedHeaders = [];
        foreach ($headers as $idx => $header) {
            $value = trim((string) $header);
            $normalizedHeaders[$idx] = $value !== '' ? $value : 'column_' . ($idx + 1);
        }

        $rows = [];
        while (($line = fgetcsv($stream)) !== false) {
            if (!is_array($line)) {
                continue;
            }

            $row = [];
            foreach ($normalizedHeaders as $idx => $header) {
                $row[$header] = isset($line[$idx]) ? trim((string) $line[$idx]) : '';
            }
            $rows[] = $row;
        }

        fclose($stream);
        return $rows;
    }

    private function buildImportFieldMap(array $headers, array $rows): array
    {
        $map = [];
        foreach ($headers as $header) {
            $logical = $this->normalizeLogicalField($header);
            $catalogCandidate = strtolower($logical);

            try {
                $resolved = FieldCatalog::resolve($catalogCandidate);
                $map[$header] = ['field' => $resolved['solr']];
                continue;
            } catch (ApiException) {
                // Fallback for unknown dynamic fields.
            }

            $sampleValues = [];
            foreach ($rows as $row) {
                $value = trim((string) ($row[$header] ?? ''));
                if ($value === '') {
                    continue;
                }
                $sampleValues[] = $value;
                if (count($sampleValues) >= 50) {
                    break;
                }
            }

            $suffix = $this->inferSuffix($sampleValues);
            $map[$header] = ['field' => $logical . $suffix];
        }

        return $map;
    }

    private function normalizeLogicalField(string $value): string
    {
        $normalized = trim($value);
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            return 'field';
        }
        return $normalized;
    }

    private function inferSuffix(array $values): string
    {
        if ($values === []) {
            return '_s';
        }

        $allBool = true;
        $allInt = true;
        $allNumeric = true;
        $allDate = true;

        foreach ($values as $value) {
            $v = strtolower(trim((string) $value));
            $isBool = in_array($v, ['true', 'false', '1', '0', 'yes', 'no'], true);
            $isInt = preg_match('/^-?\d+$/', $v) === 1;
            $isNumeric = is_numeric($v);
            $isDate = strtotime($v) !== false;

            $allBool = $allBool && $isBool;
            $allInt = $allInt && $isInt;
            $allNumeric = $allNumeric && $isNumeric;
            $allDate = $allDate && $isDate;
        }

        if ($allBool) {
            return '_b';
        }
        if ($allInt) {
            return '_i';
        }
        if ($allNumeric) {
            return '_f';
        }
        if ($allDate) {
            return '_dt';
        }

        return '_s';
    }

    private function castForField(string $fieldName, string $rawValue): string|int|float|bool|null
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (str_ends_with($fieldName, '_i')) {
            return (int) $value;
        }

        if (str_ends_with($fieldName, '_f')) {
            return (float) $value;
        }

        if (str_ends_with($fieldName, '_b')) {
            $normalized = strtolower($value);
            return in_array($normalized, ['true', '1', 'yes'], true);
        }

        if (str_ends_with($fieldName, '_dt')) {
            $ts = strtotime($value);
            if ($ts === false) {
                return null;
            }
            return gmdate('Y-m-d\\TH:i:s\\Z', $ts);
        }

        return $value;
    }

    private function toCsv(array $rows, array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new ApiException(500, 'CSV_STREAM_ERROR', 'Unable to initialize CSV writer');
        }

        fputcsv($stream, $columns);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $record = [];
            foreach ($columns as $column) {
                $record[] = $row[$column] ?? '';
            }
            fputcsv($stream, $record);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return is_string($csv) ? $csv : '';
    }

    private function parseRange(mixed $range, string $label): array
    {
        if (!is_array($range) || !isset($range['from'], $range['to'])) {
            throw new ApiException(400, 'INVALID_COMPARE_RANGE', $label . ' range must include from/to');
        }

        $from = $this->parseDate((string) $range['from']);
        $to = $this->parseDate((string) $range['to']);
        if ($to->getTimestamp() < $from->getTimestamp()) {
            throw new ApiException(400, 'INVALID_COMPARE_RANGE_ORDER', $label . ' range to must be greater than or equal to from', ['range' => $range]);
        }

        return ['from' => $from, 'to' => $to];
    }

    private function deriveCompareRange(array $currentRange, string $mode, mixed $customPrevious): array
    {
        if ($mode === 'custom_previous') {
            return $this->parseRange($customPrevious, 'previous');
        }

        if ($mode === 'same_period_last_year') {
            return [
                'from' => $currentRange['from']->modify('-1 year'),
                'to' => $currentRange['to']->modify('-1 year'),
            ];
        }

        $spanSeconds = $currentRange['to']->getTimestamp() - $currentRange['from']->getTimestamp();
        $compareTo = $currentRange['from']->modify('-1 second');
        $compareFromTimestamp = $compareTo->getTimestamp() - $spanSeconds;
        $compareFrom = (new DateTimeImmutable('@' . $compareFromTimestamp))->setTimezone(new DateTimeZone('UTC'));

        return [
            'from' => $compareFrom,
            'to' => $compareTo,
        ];
    }

    private function buildDateScopedFilter(string $baseFq, string $solrDateField, array $range): string
    {
        return '(' . $baseFq . ') AND (' . $solrDateField . ':[' . $this->toSolrDateTime($range['from']) . ' TO ' . $this->toSolrDateTime($range['to']) . '])';
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $ts = strtotime($value);
        if ($ts === false) {
            throw new ApiException(400, 'INVALID_DATE', 'Date filter value must be parseable', ['value' => $value]);
        }

        return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));
    }

    private function toSolrDateTime(DateTimeImmutable $date): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp());
    }

    private function formatRange(array $range): array
    {
        return [
            'from' => $this->toSolrDateTime($range['from']),
            'to' => $this->toSolrDateTime($range['to']),
        ];
    }

    private function isControlledDynamic(string $name): bool
    {
        return str_ends_with($name, '_s')
            || str_ends_with($name, '_txt')
            || str_ends_with($name, '_i')
            || str_ends_with($name, '_f')
            || str_ends_with($name, '_b')
            || str_ends_with($name, '_dt');
    }
}
