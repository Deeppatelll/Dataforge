<?php

declare(strict_types=1);

namespace Reporting\Api\Core;

final class HttpRequest
{
    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function path(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        return $path;
    }

    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiException(400, 'INVALID_JSON', 'Request body must be a valid JSON object');
        }

        return $decoded;
    }

    public static function queryParam(string $name, ?string $default = null): ?string
    {
        $value = $_GET[$name] ?? null;
        if (!is_string($value)) {
            return $default;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return $default;
        }

        return $trimmed;
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
