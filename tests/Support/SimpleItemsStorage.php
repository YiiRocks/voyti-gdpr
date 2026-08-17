<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\Permission;
use Yiisoft\Rbac\Role;

final class SimpleItemsStorage implements ItemsStorageInterface
{
    /** @var array<string, list<string>> */
    private array $children = [];
    /** @var array<string, Permission|Role> */
    private array $items = [];

    public function add(Permission|Role $item): void
    {
        $this->items[$item->getName()] = $item;
    }

    public function addChild(string $parentName, string $childName): void
    {
        $this->children[$parentName][] = $childName;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->children = [];
    }

    public function clearPermissions(): void
    {
        foreach ($this->items as $name => $item) {
            if ($item instanceof Permission) {
                unset($this->items[$name]);
            }
        }
    }

    public function clearRoles(): void
    {
        foreach ($this->items as $name => $item) {
            if ($item instanceof Role) {
                unset($this->items[$name]);
            }
        }
    }

    public function exists(string $name): bool
    {
        return isset($this->items[$name]);
    }

    public function get(string $name): Permission|Role|null
    {
        return $this->items[$name] ?? null;
    }

    public function getAll(): array
    {
        return $this->items;
    }

    public function getAllChildPermissions(string|array $names): array
    {
        $result = [];
        foreach ((array) $names as $name) {
            foreach ($this->getAllChildrenRecursive($name) as $childName => $child) {
                if ($child instanceof Permission) {
                    $result[$childName] = $child;
                }
            }
        }

        return $result;
    }

    public function getAllChildren(string|array $names): array
    {
        $result = [];
        foreach ((array) $names as $name) {
            foreach ($this->getAllChildrenRecursive($name) as $childName => $child) {
                $result[$childName] = $child;
            }
        }

        return $result;
    }

    public function getAllChildRoles(string|array $names): array
    {
        $result = [];
        foreach ((array) $names as $name) {
            foreach ($this->getAllChildrenRecursive($name) as $childName => $child) {
                if ($child instanceof Role) {
                    $result[$childName] = $child;
                }
            }
        }

        return $result;
    }

    public function getByNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            if (isset($this->items[$name])) {
                $result[$name] = $this->items[$name];
            }
        }

        return $result;
    }

    public function getDirectChildren(string $name): array
    {
        $result = [];
        if (isset($this->children[$name])) {
            foreach ($this->children[$name] as $childName) {
                if (isset($this->items[$childName])) {
                    $result[$childName] = $this->items[$childName];
                }
            }
        }

        return $result;
    }

    public function getHierarchy(string $name): array
    {
        $result = [];
        $this->buildHierarchy($name, $result);

        return $result;
    }

    public function getParents(string $name): array
    {
        $parents = [];
        foreach ($this->children as $parentName => $childNames) {
            if (in_array($name, $childNames, true) && isset($this->items[$parentName])) {
                $parents[$parentName] = $this->items[$parentName];
            }
        }

        return $parents;
    }

    public function getPermission(string $name): ?Permission
    {
        $item = $this->items[$name] ?? null;

        return $item instanceof Permission ? $item : null;
    }

    public function getPermissions(): array
    {
        $result = [];
        foreach ($this->items as $name => $item) {
            if ($item instanceof Permission) {
                $result[$name] = $item;
            }
        }

        return $result;
    }

    public function getPermissionsByNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $item = $this->items[$name] ?? null;
            if ($item instanceof Permission) {
                $result[$name] = $item;
            }
        }

        return $result;
    }

    public function getRole(string $name): ?Role
    {
        $item = $this->items[$name] ?? null;

        return $item instanceof Role ? $item : null;
    }

    public function getRoles(): array
    {
        $result = [];
        foreach ($this->items as $name => $item) {
            if ($item instanceof Role) {
                $result[$name] = $item;
            }
        }

        return $result;
    }

    public function getRolesByNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $item = $this->items[$name] ?? null;
            if ($item instanceof Role) {
                $result[$name] = $item;
            }
        }

        return $result;
    }

    public function hasChild(string $parentName, string $childName): bool
    {
        $children = $this->getAllChildrenRecursive($parentName);

        return isset($children[$childName]);
    }

    public function hasChildren(string $name): bool
    {
        return isset($this->children[$name]) && $this->children[$name] !== [];
    }

    public function hasDirectChild(string $parentName, string $childName): bool
    {
        return isset($this->children[$parentName]) && in_array($childName, $this->children[$parentName], true);
    }

    public function remove(string $name): void
    {
        unset($this->items[$name]);
        unset($this->children[$name]);
        foreach ($this->children as $parentName => $childNames) {
            $this->children[$parentName] = array_values(
                array_filter($childNames, static fn(string $c): bool => $c !== $name),
            );
        }
    }

    public function removeChild(string $parentName, string $childName): void
    {
        if (isset($this->children[$parentName])) {
            $this->children[$parentName] = array_values(
                array_filter(
                    $this->children[$parentName],
                    static fn(string $c): bool => $c !== $childName,
                ),
            );
        }
    }

    public function removeChildren(string $parentName): void
    {
        unset($this->children[$parentName]);
    }

    public function roleExists(string $name): bool
    {
        return $this->getRole($name) !== null;
    }

    public function update(string $name, Permission|Role $item): void
    {
        unset($this->items[$name]);
        $this->items[$item->getName()] = $item;

        if ($name !== $item->getName()) {
            foreach ($this->children as $parentName => $childNames) {
                $this->children[$parentName] = array_map(
                    static fn(string $c): string => $c === $name ? $item->getName() : $c,
                    $childNames,
                );
            }
            if (isset($this->children[$name])) {
                $this->children[$item->getName()] = $this->children[$name];
                unset($this->children[$name]);
            }
        }
    }

    private function buildHierarchy(string $name, array &$result): void
    {
        if (isset($this->items[$name]) && !isset($result[$name])) {
            $children = $this->getDirectChildren($name);
            $result[$name] = [
                'item' => $this->items[$name],
                'children' => $children,
            ];
            foreach ($children as $childName => $child) {
                $this->buildHierarchy($childName, $result);
            }
        }
    }

    private function collectChildren(string $name, array &$result): void
    {
        if (isset($this->children[$name])) {
            foreach ($this->children[$name] as $childName) {
                if (isset($this->items[$childName]) && !isset($result[$childName])) {
                    $result[$childName] = $this->items[$childName];
                    $this->collectChildren($childName, $result);
                }
            }
        }
    }

    private function getAllChildrenRecursive(string $name): array
    {
        $result = [];
        $this->collectChildren($name, $result);

        return $result;
    }
}
