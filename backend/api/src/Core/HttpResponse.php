<?php

declare(strict_types=1);

namespace Reporting\Api\Core;

final class HttpResponse
{
    public static function nowIso(): string
    {
        return gmdate('c');
    }

    public static function send(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function success(array $payload, int $statusCode = 200): void
    {
        self::send($statusCode, $payload);
    }

    public static function error(ApiException $e): void
    {
        self::send($e->httpStatus(), [
            'status' => 'error',
            'error' => [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'details' => $e->details(),
                'timestamp' => self::nowIso(),
            ],
        ]);
    }
}
