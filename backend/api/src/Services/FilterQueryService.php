<?php

declare(strict_types=1);

namespace Reporting\Api\Services;

use Reporting\Api\Core\ApiException;
use Reporting\Api\Domain\FieldCatalog;

final class FilterQueryService
{
    public function buildBaseFilterQuery(array $payload): string
    {
        $filterRoot = $payload['filters'] ?? null;
        if (!is_array($filterRoot) || $filterRoot === []) {
            return '*:*';
        }

        return $this->buildFilterExpression($filterRoot);
    }

    public function parsePagination(array $payload): array
    {
        $page = max(1, (int) ($payload['page'] ?? 1));
        $pageSize = (int) ($payload['page_size'] ?? 50);
        if ($pageSize < 1) {
            $pageSize = 50;
        }
        if ($pageSize > 500) {
            $pageSize = 500;
        }

        return [
            'page' => $page,
            'page_size' => $pageSize,
            'start' => ($page - 1) * $pageSize,
        ];
    }

    public function parseSort(array $payload): string
    {
        $sort = $payload['sort'] ?? [];
        if (!is_array($sort) || $sort === []) {
            return 'ingested_at_dt desc';
        }

        $parts = [];
        foreach ($sort as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fieldName = (string) ($entry['field'] ?? '');
            $direction = strtolower((string) ($entry['direction'] ?? 'asc'));
            if ($fieldName === '') {
                continue;
            }

            $field = FieldCatalog::resolve($fieldName);
            if (($field['sortable'] ?? false) !== true) {
                throw new ApiException(400, 'INVALID_SORT_FIELD', 'Field is not sortable', ['field' => $fieldName]);
            }

            if ($direction !== 'asc' && $direction !== 'desc') {
                throw new ApiException(400, 'INVALID_SORT_DIRECTION', 'Sort direction must be asc or desc', ['direction' => $direction]);
            }

            $parts[] = $field['solr'] . ' ' . $direction;
        }

        if ($parts === []) {
            return 'ingested_at_dt desc';
        }

        return implode(',', $parts);
    }

    public function parseColumns(array $payload): array
    {
        $columns = $payload['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            $columns = ['event_id', 'category', 'status', 'name', 'amount', 'quantity', 'is_active', 'event_date', 'ingested_at'];
        }

        $solrFields = ['id'];
        foreach ($columns as $column) {
            if (!is_string($column) || trim($column) === '') {
                continue;
            }

            $field = FieldCatalog::resolve(trim($column));
            $solrFields[] = $field['solr'];
        }

        return array_values(array_unique($solrFields));
    }

    private function buildFilterExpression(array $node): string
    {
        $nodeType = strtolower((string) ($node['type'] ?? 'group'));
        if ($nodeType === 'rule') {
            return $this->buildRuleExpression($node);
        }

        $logic = strtoupper((string) ($node['logic'] ?? 'AND'));
        if ($logic !== 'AND' && $logic !== 'OR') {
            throw new ApiException(400, 'INVALID_FILTER_GROUP', 'Group logic must be AND or OR', ['group' => $node]);
        }

        $conditions = $node['conditions'] ?? [];
        if (!is_array($conditions) || $conditions === []) {
            return '*:*';
        }

        $parts = [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                throw new ApiException(400, 'INVALID_FILTER_GROUP', 'Each condition must be an object', ['group' => $node]);
            }

            $parts[] = '(' . $this->buildFilterExpression($condition) . ')';
        }

        return implode(' ' . $logic . ' ', $parts);
    }

    private function buildRuleExpression(array $rule): string
    {
        $fieldName = (string) ($rule['field'] ?? '');
        $operator = strtolower((string) ($rule['operator'] ?? ''));
        $value = $rule['value'] ?? null;
        if ($fieldName === '' || $operator === '') {
            throw new ApiException(400, 'INVALID_FILTER_RULE', 'Filter rule must include field and operator', ['rule' => $rule]);
        }

        $field = FieldCatalog::resolve($fieldName);
        $solrField = $field['solr'];
        $type = $field['type'];

        $scalar = function ($v): string {
            if (is_bool($v)) {
                return $v ? 'true' : 'false';
            }
            if (is_int($v) || is_float($v)) {
                return (string) $v;
            }
            return $this->solrEscapeTerm((string) $v);
        };

        if ($operator === 'exists') {
            return $solrField . ':[* TO *]';
        }
        if ($operator === 'not_exists') {
            return '-' . $solrField . ':[* TO *]';
        }

        if ($operator === 'in' || $operator === 'not_in') {
            if (!is_array($value) || $value === []) {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'IN operator requires non-empty array', ['rule' => $rule]);
            }

            $parts = [];
            foreach ($value as $item) {
                if ($type === 'date') {
                    $parts[] = '"' . $this->toSolrDate((string) $item) . '"';
                } else {
                    $parts[] = '"' . $scalar($item) . '"';
                }
            }

            $expr = $solrField . ':(' . implode(' OR ', $parts) . ')';
            return $operator === 'not_in' ? '-' . $expr : $expr;
        }

        if ($operator === 'between') {
            if (!is_array($value) || !isset($value['from'], $value['to'])) {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'BETWEEN operator requires {from,to}', ['rule' => $rule]);
            }

            if ($type === 'date') {
                return $solrField . ':['
                    . $this->toSolrDate((string) $value['from'])
                    . ' TO '
                    . $this->toSolrDate((string) $value['to'], true)
                    . ']';
            }

            return $solrField . ':[' . $scalar($value['from']) . ' TO ' . $scalar($value['to']) . ']';
        }

        if ($operator === 'gte' || $operator === 'lte') {
            if ($value === null || $value === '') {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'GTE/LTE requires scalar value', ['rule' => $rule]);
            }

            if ($type === 'date') {
                if ($operator === 'gte') {
                    return $solrField . ':[' . $this->toSolrDate((string) $value) . ' TO *]';
                }
                return $solrField . ':[* TO ' . $this->toSolrDate((string) $value, true) . ']';
            }

            if ($operator === 'gte') {
                return $solrField . ':[' . $scalar($value) . ' TO *]';
            }
            return $solrField . ':[* TO ' . $scalar($value) . ']';
        }

        if ($operator === 'contains') {
            $valueStr = trim((string) $value);
            if ($valueStr === '') {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'CONTAINS requires non-empty string', ['rule' => $rule]);
            }
            return $solrField . ':*' . $this->solrEscapeTerm($valueStr) . '*';
        }

        if ($operator === 'starts_with') {
            $valueStr = trim((string) $value);
            if ($valueStr === '') {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'STARTS_WITH requires non-empty string', ['rule' => $rule]);
            }
            return $solrField . ':' . $this->solrEscapeTerm($valueStr) . '*';
        }

        if ($operator === 'eq' || $operator === 'neq') {
            if ($value === null || $value === '') {
                throw new ApiException(400, 'INVALID_FILTER_VALUE', 'EQ/NEQ requires scalar value', ['rule' => $rule]);
            }
            $term = $type === 'date' ? $this->toSolrDate((string) $value) : $scalar($value);
            $expr = $solrField . ':"' . $term . '"';
            return $operator === 'neq' ? '-' . $expr : $expr;
        }

        throw new ApiException(400, 'UNSUPPORTED_OPERATOR', 'Operator is not supported', ['operator' => $operator]);
    }

    private function toSolrDate(string $value, bool $endOfDay = false): string
    {
        $normalized = trim($value);
        if ($endOfDay && preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
            $normalized .= ' 23:59:59';
        }

        $ts = strtotime($normalized);
        if ($ts === false) {
            throw new ApiException(400, 'INVALID_DATE', 'Date filter value must be parseable', ['value' => $value]);
        }

        return gmdate('Y-m-d\\TH:i:s\\Z', $ts);
    }

    private function solrEscapeTerm(string $value): string
    {
        $map = [
            '\\' => '\\\\',
            '+' => '\\+',
            '-' => '\\-',
            '&&' => '\\&&',
            '||' => '\\||',
            '!' => '\\!',
            '(' => '\\(',
            ')' => '\\)',
            '{' => '\\{',
            '}' => '\\}',
            '[' => '\\[',
            ']' => '\\]',
            '^' => '\\^',
            '"' => '\\"',
            '~' => '\\~',
            '*' => '\\*',
            '?' => '\\?',
            ':' => '\\:',
            '/' => '\\/',
        ];

        return strtr($value, $map);
    }
}
