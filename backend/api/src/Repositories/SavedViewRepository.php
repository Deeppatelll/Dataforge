<?php

declare(strict_types=1);

namespace Reporting\Api\Repositories;

use Reporting\Api\Core\ApiException;

final class SavedViewRepository
{
    public function __construct(private readonly string $path)
    {
    }

    public function all(string $userId): array
    {
        $bucket = $this->loadUserBucket($userId);
        $defaultViewId = $bucket['default_view_id'] ?? null;

        $views = [];
        foreach (($bucket['views'] ?? []) as $id => $record) {
            if (!is_array($record)) {
                continue;
            }
            $record['is_default'] = $defaultViewId !== null && $id === $defaultViewId;
            $views[] = $record;
        }

        usort($views, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

        return array_values($views);
    }

    public function find(string $userId, string $id): ?array
    {
        $bucket = $this->loadUserBucket($userId);
        $record = $bucket['views'][$id] ?? null;
        if (!is_array($record)) {
            return null;
        }
        $record['is_default'] = ($bucket['default_view_id'] ?? null) === $id;
        return $record;
    }

    public function create(string $userId, string $name, string $description, array $definition, bool $setDefault = false): array
    {
        $data = $this->loadData();
        $bucket = $this->loadUserBucketFromData($data, $userId);

        $id = 'view_' . bin2hex(random_bytes(8));
        $record = [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'definition' => $definition,
            'user_id' => $userId,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $bucket['views'][$id] = $record;
        if ($setDefault || ($bucket['default_view_id'] ?? null) === null) {
            $bucket['default_view_id'] = $id;
        }

        $data['users'][$userId] = $bucket;
        $this->persistData($data);

        $record['is_default'] = $bucket['default_view_id'] === $id;

        return $record;
    }

    public function update(string $userId, string $id, array $patch): ?array
    {
        $data = $this->loadData();
        $bucket = $this->loadUserBucketFromData($data, $userId);
        if (!isset($bucket['views'][$id]) || !is_array($bucket['views'][$id])) {
            return null;
        }

        if (isset($patch['name'])) {
            $name = trim((string) $patch['name']);
            if ($name === '') {
                throw new ApiException(400, 'INVALID_SAVED_VIEW', 'name cannot be empty');
            }
            $bucket['views'][$id]['name'] = $name;
        }
        if (isset($patch['description'])) {
            $bucket['views'][$id]['description'] = (string) $patch['description'];
        }
        if (isset($patch['definition'])) {
            if (!is_array($patch['definition']) || $this->isAssoc($patch['definition']) !== true) {
                throw new ApiException(400, 'INVALID_SAVED_VIEW', 'definition must be object');
            }
            $bucket['views'][$id]['definition'] = $patch['definition'];
        }

        if (($patch['is_default'] ?? false) === true) {
            $bucket['default_view_id'] = $id;
        }

        $bucket['views'][$id]['updated_at'] = gmdate('c');
        $data['users'][$userId] = $bucket;
        $this->persistData($data);

        $result = $bucket['views'][$id];
        $result['is_default'] = ($bucket['default_view_id'] ?? null) === $id;

        return $result;
    }

    public function delete(string $userId, string $id): bool
    {
        $data = $this->loadData();
        $bucket = $this->loadUserBucketFromData($data, $userId);
        if (!isset($bucket['views'][$id])) {
            return false;
        }

        unset($bucket['views'][$id]);

        if (($bucket['default_view_id'] ?? null) === $id) {
            $ids = array_keys($bucket['views']);
            $bucket['default_view_id'] = $ids[0] ?? null;
        }

        $data['users'][$userId] = $bucket;
        $this->persistData($data);
        return true;
    }

    public function getDefault(string $userId): ?array
    {
        $bucket = $this->loadUserBucket($userId);
        $defaultId = $bucket['default_view_id'] ?? null;
        if (!is_string($defaultId) || $defaultId === '') {
            return null;
        }

        $record = $bucket['views'][$defaultId] ?? null;
        if (!is_array($record)) {
            return null;
        }

        $record['is_default'] = true;
        return $record;
    }

    public function setDefault(string $userId, string $id): ?array
    {
        $data = $this->loadData();
        $bucket = $this->loadUserBucketFromData($data, $userId);
        if (!isset($bucket['views'][$id]) || !is_array($bucket['views'][$id])) {
            return null;
        }

        $bucket['default_view_id'] = $id;
        $bucket['views'][$id]['updated_at'] = gmdate('c');
        $data['users'][$userId] = $bucket;
        $this->persistData($data);

        $record = $bucket['views'][$id];
        $record['is_default'] = true;
        return $record;
    }

    private function loadData(): array
    {
        if (!is_file($this->path)) {
            return ['users' => []];
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return ['users' => []];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['users' => []];
        }

        if (isset($decoded['users']) && is_array($decoded['users'])) {
            return $decoded;
        }

        // Backward compatibility: old format stored all views in one flat map.
        $guestViews = [];
        foreach ($decoded as $id => $record) {
            if (is_string($id) && is_array($record)) {
                $guestViews[$id] = $record;
            }
        }
        $defaultId = array_key_first($guestViews);

        return [
            'users' => [
                'guest' => [
                    'default_view_id' => is_string($defaultId) ? $defaultId : null,
                    'views' => $guestViews,
                ],
            ],
        ];
    }

    private function loadUserBucket(string $userId): array
    {
        $data = $this->loadData();
        return $this->loadUserBucketFromData($data, $userId);
    }

    private function loadUserBucketFromData(array $data, string $userId): array
    {
        $users = $data['users'] ?? [];
        if (!is_array($users)) {
            $users = [];
        }

        $bucket = $users[$userId] ?? ['default_view_id' => null, 'views' => []];
        if (!is_array($bucket)) {
            $bucket = ['default_view_id' => null, 'views' => []];
        }

        if (!isset($bucket['views']) || !is_array($bucket['views'])) {
            $bucket['views'] = [];
        }

        if (!array_key_exists('default_view_id', $bucket)) {
            $bucket['default_view_id'] = null;
        }

        return $bucket;
    }

    private function persistData(array $data): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new ApiException(500, 'SAVE_FAILED', 'Failed to create directory for saved views', ['path' => $dir]);
        }

        $ok = file_put_contents($this->path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT));
        if ($ok === false) {
            throw new ApiException(500, 'SAVE_FAILED', 'Failed to persist saved views', ['path' => $this->path]);
        }
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
