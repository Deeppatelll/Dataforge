<?php

declare(strict_types=1);

namespace Reporting\Api\Controllers;

final class HealthController
{
    public function __construct(private readonly string $solrBaseUrl)
    {
    }

    public function health(): array
    {
        return [
            'status' => 'ok',
            'service' => 'dataforge-php-api',
            'timestamp' => gmdate('c'),
            'solr_base_url' => $this->solrBaseUrl,
        ];
    }
}
