<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Yiisoft\Auth\IdentityInterface;
use Yiisoft\Auth\IdentityRepositoryInterface;
use Yiisoft\User\CurrentUser;

/**
 * Builds a real CurrentUser for tests instead of mocking the final class. Passing an identity logs
 * it in (so getIdentity()/getId()/isGuest() report it); passing none leaves a guest.
 */
trait CurrentUserTrait
{
    protected function createCurrentUser(?IdentityInterface $identity = null): CurrentUser
    {
        $currentUser = new CurrentUser(
            $this->createStub(IdentityRepositoryInterface::class),
            new EventCaptureDispatcher(),
        );

        if ($identity !== null) {
            $currentUser->login($identity);
        }

        return $currentUser;
    }
}
