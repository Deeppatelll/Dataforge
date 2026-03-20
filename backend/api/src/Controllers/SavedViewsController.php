<?php

declare(strict_types=1);

namespace Reporting\Api\Controllers;

use Reporting\Api\Core\ApiException;
use Reporting\Api\Repositories\SavedViewRepository;

final class SavedViewsController
{
    public function __construct(private readonly SavedViewRepository $repository)
    {
    }

    public function list(string $userId): array
    {
        return [
            'status' => 'ok',
            'user_id' => $userId,
            'views' => $this->repository->all($userId),
        ];
    }

    public function create(string $userId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $definition = $payload['definition'] ?? null;
        if ($name === '' || !is_array($definition) || $this->isAssoc($definition) !== true) {
            throw new ApiException(400, 'INVALID_SAVED_VIEW', 'Saved view requires name and definition object');
        }

        $setDefault = ($payload['is_default'] ?? false) === true;
        $record = $this->repository->create($userId, $name, (string) ($payload['description'] ?? ''), $definition, $setDefault);
        return [
            'status' => 'ok',
            'view' => $record,
        ];
    }

    public function get(string $userId, string $id): array
    {
        $found = $this->repository->find($userId, $id);
        if ($found === null) {
            throw new ApiException(404, 'SAVED_VIEW_NOT_FOUND', 'Saved view not found', ['id' => $id]);
        }

        return ['status' => 'ok', 'view' => $found];
    }

    public function update(string $userId, string $id, array $payload): array
    {
        $updated = $this->repository->update($userId, $id, $payload);
        if ($updated === null) {
            throw new ApiException(404, 'SAVED_VIEW_NOT_FOUND', 'Saved view not found', ['id' => $id]);
        }

        return ['status' => 'ok', 'view' => $updated];
    }

    public function delete(string $userId, string $id): array
    {
        $deleted = $this->repository->delete($userId, $id);
        if (!$deleted) {
            throw new ApiException(404, 'SAVED_VIEW_NOT_FOUND', 'Saved view not found', ['id' => $id]);
        }

        return ['status' => 'ok', 'deleted' => true, 'id' => $id];
    }

    public function getDefault(string $userId): array
    {
        $found = $this->repository->getDefault($userId);
        if ($found === null) {
            return ['status' => 'ok', 'user_id' => $userId, 'view' => null];
        }

        return ['status' => 'ok', 'user_id' => $userId, 'view' => $found];
    }

    public function setDefault(string $userId, string $id): array
    {
        $updated = $this->repository->setDefault($userId, $id);
        if ($updated === null) {
            throw new ApiException(404, 'SAVED_VIEW_NOT_FOUND', 'Saved view not found', ['id' => $id]);
        }

        return ['status' => 'ok', 'user_id' => $userId, 'view' => $updated];
    }

    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
