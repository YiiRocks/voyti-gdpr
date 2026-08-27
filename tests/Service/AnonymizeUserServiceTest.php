<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use YiiRocks\Voyti\Gdpr\Event\Gdpr\GdprEvent;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\Gdpr\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Gdpr\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Gdpr\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Model\User;

#[AllowMockObjectsWithoutExpectations]
final class AnonymizeUserServiceTest extends DatabaseTestCase
{
    use UserFactoryTrait;

    public function testRunDispatchesGdprEvent(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            confirmedAt: time(),
        );

        $dispatcher = new EventCaptureDispatcher();
        $service = new AnonymizeUserService($dispatcher, 'GDPR');
        $service->run($user);

        $gdprEvent = $dispatcher->getEvent(GdprEvent::class);
        $this->assertInstanceOf(GdprEvent::class, $gdprEvent);
        $this->assertTrue($gdprEvent->getUser()->isAnonymized());
    }

    public function testRunMasksUserDataAndBlocksAccount(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            confirmedAt: time(),
        );
        $userId = $user->getIdOrZero();
        $originalAuthKey = $user->getAuthKey();

        $service = new AnonymizeUserService(new EventCaptureDispatcher(), 'GDPR');
        $service->run($user);

        $updated = $this->getUserAfterAnonymization($userId);
        $this->assertTrue($updated->isAnonymized());
        $this->assertNotNull($updated->getBlockedAt());
        $this->assertSame("GDPR{$userId}@example.com", $updated->getEmail());
        $this->assertSame("GDPR{$userId}", $updated->getUsername());
        $this->assertNotSame($originalAuthKey, $updated->getAuthKey());
    }

    public function testRunUsesCustomPrefix(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            confirmedAt: time(),
        );
        $userId = $user->getIdOrZero();

        $service = new AnonymizeUserService(new EventCaptureDispatcher(), 'REDACTED');
        $service->run($user);

        $updated = $this->getUserAfterAnonymization($userId);
        $this->assertSame("REDACTED{$userId}@example.com", $updated->getEmail());
        $this->assertSame("REDACTED{$userId}", $updated->getUsername());
    }

    private function getUserAfterAnonymization(int $userId): User
    {
        $user = User::findById($userId);
        $this->assertNotNull($user);

        return $user;
    }
}
