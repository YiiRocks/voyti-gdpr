<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Yiisoft\Session\SessionInterface;

final class FakeSession implements SessionInterface
{
    private bool $active = false;
    private array $cookieParameters = [];
    private array $data = [];
    private string $id = '';
    private string $name = 'PHPSESSID';

    public function all(): array
    {
        return $this->data;
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function close(): void
    {
        $this->active = false;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->active = false;
    }

    public function discard(): void
    {
        $this->active = false;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getCookieParameters(): array
    {
        return $this->cookieParameters;
    }

    public function getId(): ?string
    {
        return $this->active ? $this->id : null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function open(): void
    {
        $this->active = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        unset($this->data[$key]);

        return $value;
    }

    public function regenerateId(): void
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function setId(string $sessionId): void
    {
        $this->id = $sessionId;
    }
}
