<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Yiisoft\Security\PasswordHasher;

/**
 * Builds a `PasswordHasher` at the lowest valid bcrypt cost, so tests exercising real
 * hash/verify calls (login, backup codes, password history) don't pay the production cost
 * of 13, which makes the suite minutes slower without making any test more correct.
 */
final class TestPasswordHasherFactory
{
    public static function create(): PasswordHasher
    {
        return new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]);
    }
}
