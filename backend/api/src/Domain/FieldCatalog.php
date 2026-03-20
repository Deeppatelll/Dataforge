<?php

declare(strict_types=1);

namespace Reporting\Api\Domain;

use Reporting\Api\Core\ApiException;

final class FieldCatalog
{
    public static function catalog(): array
    {
        return [
            'event_id' => ['solr' => 'event_id_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'source_file' => ['solr' => 'source_file_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'row_num' => ['solr' => 'row_num_i', 'type' => 'number', 'filterable' => true, 'sortable' => true, 'facetable' => false],
            'category' => ['solr' => 'category_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'status' => ['solr' => 'status_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'name' => ['solr' => 'name_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'description' => ['solr' => 'description_txt', 'type' => 'text', 'filterable' => true, 'sortable' => false, 'facetable' => false],
            'search_text' => ['solr' => 'search_text_txt', 'type' => 'text', 'filterable' => true, 'sortable' => false, 'facetable' => false],
            'amount' => ['solr' => 'amount_f', 'type' => 'number', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'quantity' => ['solr' => 'quantity_i', 'type' => 'number', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'is_active' => ['solr' => 'is_active_b', 'type' => 'boolean', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'event_date' => ['solr' => 'event_date_dt', 'type' => 'date', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'ingested_at' => ['solr' => 'ingested_at_dt', 'type' => 'date', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'error_reason' => ['solr' => 'error_reason_s', 'type' => 'string', 'filterable' => true, 'sortable' => true, 'facetable' => true],
            'failed_at' => ['solr' => 'failed_at_dt', 'type' => 'date', 'filterable' => true, 'sortable' => true, 'facetable' => true],
        ];
    }

    public static function resolve(string $logicalField): array
    {
        $catalog = self::catalog();
        if (isset($catalog[$logicalField])) {
            return $catalog[$logicalField];
        }

        if (preg_match('/^[a-zA-Z0-9_]+$/', $logicalField) !== 1) {
            throw new ApiException(400, 'INVALID_FIELD', 'Field contains unsupported characters', ['field' => $logicalField]);
        }

        $suffixMap = [
            '_s' => 'string',
            '_txt' => 'text',
            '_i' => 'number',
            '_f' => 'number',
            '_b' => 'boolean',
            '_dt' => 'date',
        ];

        foreach ($suffixMap as $suffix => $type) {
            if (str_ends_with($logicalField, $suffix)) {
                return [
                    'solr' => $logicalField,
                    'type' => $type,
                    'filterable' => true,
                    'sortable' => $type !== 'text',
                    'facetable' => $type !== 'text',
                ];
            }
        }

        throw new ApiException(400, 'UNKNOWN_FIELD', 'Field not found in catalog and not a controlled dynamic field', ['field' => $logicalField]);
    }
}
