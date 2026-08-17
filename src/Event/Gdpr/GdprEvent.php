<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\Event\Gdpr;

use YiiRocks\Voyti\Model\User;

/**
 * Dispatched after a user's account is anonymized for GDPR compliance, carrying the anonymized `User`.
 */
final readonly class GdprEvent
{
    public function __construct(
        private User $user,
    ) {}

    public function getUser(): User
    {
        return $this->user;
    }
}
