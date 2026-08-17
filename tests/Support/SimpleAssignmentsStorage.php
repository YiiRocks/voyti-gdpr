<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Yiisoft\Rbac\Assignment;
use Yiisoft\Rbac\AssignmentsStorageInterface;

final class SimpleAssignmentsStorage implements AssignmentsStorageInterface
{
    /** @var array<string, array<string, Assignment>> */
    private array $assignments = [];

    public function add(Assignment $assignment): void
    {
        $this->assignments[$assignment->getUserId()][$assignment->getItemName()] = $assignment;
    }

    public function clear(): void
    {
        $this->assignments = [];
    }

    public function exists(string $itemName, string $userId): bool
    {
        return isset($this->assignments[$userId][$itemName]);
    }

    public function filterUserItemNames(string $userId, array $itemNames): array
    {
        $userAssignments = $this->assignments[$userId] ?? [];

        return array_values(array_filter(
            $itemNames,
            static fn(string $name): bool => isset($userAssignments[$name]),
        ));
    }

    public function get(string $itemName, string $userId): ?Assignment
    {
        return $this->assignments[$userId][$itemName] ?? null;
    }

    public function getAll(): array
    {
        return $this->assignments;
    }

    public function getByItemNames(array $itemNames): array
    {
        $result = [];
        foreach ($this->assignments as $userId => $userAssignments) {
            foreach ($itemNames as $itemName) {
                if (isset($userAssignments[$itemName])) {
                    $result[] = $userAssignments[$itemName];
                }
            }
        }

        return $result;
    }

    public function getByUserId(string $userId): array
    {
        return $this->assignments[$userId] ?? [];
    }

    public function hasItem(string $name): bool
    {
        foreach ($this->assignments as $userId => $userAssignments) {
            if (isset($userAssignments[$name])) {
                return true;
            }
        }

        return false;
    }

    public function remove(string $itemName, string $userId): void
    {
        unset($this->assignments[$userId][$itemName]);
    }

    public function removeByItemName(string $itemName): void
    {
        foreach ($this->assignments as $userId => $userAssignments) {
            unset($this->assignments[$userId][$itemName]);
        }
    }

    public function removeByUserId(string $userId): void
    {
        unset($this->assignments[$userId]);
    }

    public function renameItem(string $oldName, string $newName): void
    {
        if ($oldName === $newName) {
            return;
        }

        foreach ($this->assignments as $userId => $userAssignments) {
            if (isset($userAssignments[$oldName])) {
                $assignment = $userAssignments[$oldName];
                $this->assignments[$userId][$newName] = new Assignment(
                    $assignment->getUserId(),
                    $newName,
                    $assignment->getCreatedAt(),
                );
                unset($this->assignments[$userId][$oldName]);
            }
        }
    }

    public function userHasItem(string $userId, array $itemNames): bool
    {
        $userAssignments = $this->assignments[$userId] ?? [];
        foreach ($itemNames as $itemName) {
            if (isset($userAssignments[$itemName])) {
                return true;
            }
        }

        return false;
    }
}
