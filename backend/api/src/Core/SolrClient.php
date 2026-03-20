<?php

declare(strict_types=1);

namespace Reporting\Api\Core;

final class SolrClient
{
    public function request(string $method, string $url, ?array $body = null): array
    {
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $opts = [
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new ApiException(502, 'SOLR_UNREACHABLE', 'Unable to connect to Solr', ['url' => $url]);
        }

        $statusCode = 0;
        $statusLine = $http_response_header[0] ?? 'HTTP/1.1 500';
        if (preg_match('/\s(\d{3})\s/', $statusLine, $matches) === 1) {
            $statusCode = (int) $matches[1];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new ApiException(502, 'SOLR_INVALID_RESPONSE', 'Solr returned non-JSON response', [
                'status_code' => $statusCode,
                'raw' => substr($response, 0, 1000),
            ]);
        }

        if ($statusCode >= 400) {
            throw new ApiException(502, 'SOLR_ERROR', 'Solr query failed', [
                'status_code' => $statusCode,
                'solr_error' => $decoded['error'] ?? null,
            ]);
        }

        return $decoded;
    }
}
