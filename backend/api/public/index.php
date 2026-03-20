<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Reporting\Api\Config\AppConfig;
use Reporting\Api\Controllers\HealthController;
use Reporting\Api\Controllers\ReportingController;
use Reporting\Api\Controllers\SavedViewsController;
use Reporting\Api\Core\ApiException;
use Reporting\Api\Core\HttpRequest;
use Reporting\Api\Core\HttpResponse;
use Reporting\Api\Core\SolrClient;
use Reporting\Api\Repositories\SavedViewRepository;
use Reporting\Api\Services\FilterQueryService;

$config = new AppConfig();
$solrClient = new SolrClient();
$filterService = new FilterQueryService();
$reporting = new ReportingController($solrClient, $filterService, $config->solrBaseUrl(), $config->sampleDataPath());
$health = new HealthController($config->solrBaseUrl());
$savedViews = new SavedViewsController(new SavedViewRepository($config->savedViewsPath()));

$method = HttpRequest::method();
$path = HttpRequest::path();
$userId = HttpRequest::queryParam('user_id', HttpRequest::header('x-user-id') ?? 'guest');

if ($method === 'OPTIONS') {
    HttpResponse::success(['status' => 'ok'], 204);
    exit;
}

try {
    if ($path === '/health' && $method === 'GET') {
        HttpResponse::success($health->health());
        exit;
    }

    if ($path === '/api/health' && $method === 'GET') {
        HttpResponse::success($health->health());
        exit;
    }

    if ($path === '/api/schema' && $method === 'GET') {
        HttpResponse::success($reporting->schema());
        exit;
    }

    if ($path === '/api/produce' && $method === 'POST') {
        HttpResponse::success($reporting->produce(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/query' && $method === 'POST') {
        HttpResponse::success($reporting->query(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/facets' && $method === 'POST') {
        HttpResponse::success($reporting->facets(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/aggregation' && $method === 'POST') {
        HttpResponse::success($reporting->aggregation(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/compare' && $method === 'POST') {
        HttpResponse::success($reporting->compare(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/columns' && $method === 'GET') {
        HttpResponse::success($reporting->columns());
        exit;
    }

    if ($path === '/api/export' && $method === 'POST') {
        HttpResponse::success($reporting->export(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/import-csv' && $method === 'POST') {
        HttpResponse::success($reporting->importCsv(HttpRequest::jsonBody()));
        exit;
    }

    if ($path === '/api/saved-views') {
        if ($method === 'GET') {
            HttpResponse::success($savedViews->list((string) $userId));
            exit;
        }

        if ($method === 'POST') {
            HttpResponse::success($savedViews->create((string) $userId, HttpRequest::jsonBody()), 201);
            exit;
        }

        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for saved views collection', ['method' => $method]);
    }

    if ($path === '/api/views') {
        if ($method === 'GET') {
            $response = $savedViews->list((string) $userId);
            $views = $response['views'] ?? [];
            if (!is_array($views)) {
                $views = [];
            }

            $compatViews = [];
            foreach ($views as $view) {
                if (!is_array($view)) {
                    continue;
                }

                $definition = $view['definition'] ?? [];
                if (!is_array($definition)) {
                    $definition = [];
                }

                $compatViews[] = [
                    'id' => $view['id'] ?? null,
                    'name' => $view['name'] ?? 'Untitled',
                    'columns' => is_array($definition['columns'] ?? null) ? $definition['columns'] : [],
                    'filters' => is_array($definition['filters'] ?? null) ? $definition['filters'] : [],
                    'sort' => is_string($definition['sort'] ?? null) ? $definition['sort'] : 'score desc',
                    'created_at' => $view['created_at'] ?? gmdate('c'),
                    'updated_at' => $view['updated_at'] ?? gmdate('c'),
                    'is_default' => ($view['is_default'] ?? false) === true,
                ];
            }

            HttpResponse::success(['status' => 'ok', 'views' => $compatViews]);
            exit;
        }

        if ($method === 'POST') {
            $payload = HttpRequest::jsonBody();
            $createPayload = [
                'name' => (string) ($payload['name'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'definition' => [
                    'columns' => is_array($payload['columns'] ?? null) ? $payload['columns'] : [],
                    'filters' => is_array($payload['filters'] ?? null) ? $payload['filters'] : [],
                    'sort' => is_string($payload['sort'] ?? null) ? $payload['sort'] : 'score desc',
                ],
            ];
            HttpResponse::success($savedViews->create((string) $userId, $createPayload), 201);
            exit;
        }

        if ($method === 'DELETE') {
            $payload = HttpRequest::jsonBody();
            $id = trim((string) ($payload['id'] ?? ''));
            if ($id === '') {
                throw new ApiException(400, 'INVALID_SAVED_VIEW', 'id is required');
            }

            HttpResponse::success($savedViews->delete((string) $userId, $id));
            exit;
        }

        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for views', ['method' => $method]);
    }

    if ($path === '/api/saved-views/default') {
        if ($method === 'GET') {
            HttpResponse::success($savedViews->getDefault((string) $userId));
            exit;
        }

        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for saved views default endpoint', ['method' => $method]);
    }

    if (preg_match('#^/api/saved-views/([a-zA-Z0-9_\-]+)/default$#', $path, $matches) === 1) {
        $id = $matches[1];
        if ($method === 'PUT') {
            HttpResponse::success($savedViews->setDefault((string) $userId, $id));
            exit;
        }

        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for saved view default endpoint', ['method' => $method]);
    }

    if (preg_match('#^/api/saved-views/([a-zA-Z0-9_\-]+)$#', $path, $matches) === 1) {
        $id = $matches[1];
        if ($method === 'GET') {
            HttpResponse::success($savedViews->get((string) $userId, $id));
            exit;
        }

        if ($method === 'PUT') {
            HttpResponse::success($savedViews->update((string) $userId, $id, HttpRequest::jsonBody()));
            exit;
        }

        if ($method === 'DELETE') {
            HttpResponse::success($savedViews->delete((string) $userId, $id));
            exit;
        }

        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Method not allowed for saved view item', ['method' => $method]);
    }

    throw new ApiException(404, 'NOT_FOUND', 'Endpoint not found', ['path' => $path, 'method' => $method]);
} catch (ApiException $e) {
    HttpResponse::error($e);
    exit;
} catch (Throwable $e) {
    HttpResponse::error(new ApiException(500, 'INTERNAL_ERROR', 'Unexpected server error', ['message' => $e->getMessage()]));
    exit;
}
