<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use YiiRocks\Voyti\Gdpr\Event\Gdpr\GdprEvent;
use YiiRocks\Voyti\Model\User;
use Yiisoft\Security\Random;

/**
 * Anonymizes a user account for GDPR compliance: masks email and username, blocks the account,
 * rotates the auth key, and dispatches {@see GdprEvent}.
 */
final readonly class AnonymizeUserService
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private string $anonymizePrefix,
    ) {}

    public function run(User $user): void
    {
        $prefix = $this->anonymizePrefix . ($user->getId() ?? '');
        $user->setEmail($prefix . '@example.com');
        $user->setUsername($prefix);
        $user->setAnonymized(true);
        $user->setBlockedAt(time());
        $user->setAuthKey(Random::string());
        $user->save();
        $this->eventDispatcher->dispatch(new GdprEvent($user));
    }
}
