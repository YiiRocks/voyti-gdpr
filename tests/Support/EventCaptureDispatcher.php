<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class EventCaptureDispatcher implements EventDispatcherInterface
{
    /** @var array<array-key, object> */
    private array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    public function getEvent(string $class): ?object
    {
        foreach ($this->events as $event) {
            if ($event::class === $class) {
                return $event;
            }
        }

        return null;
    }
}
