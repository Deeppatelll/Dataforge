<?php

declare(strict_types=1);

namespace Reporting\Api\Config;

final class AppConfig
{
    public function solrBaseUrl(): string
    {
        return rtrim($this->envString('SOLR_BASE_URL', 'http://solr:8983/solr/reportcore'), '/');
    }

    public function savedViewsPath(): string
    {
        return $this->envString('SAVED_VIEWS_PATH', '/tmp/dataforge-saved-views.json');
    }

    public function sampleDataPath(): string
    {
        return $this->envString('SAMPLE_DATA_PATH', '/app/sample-data');
    }

    private function envString(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }
}
