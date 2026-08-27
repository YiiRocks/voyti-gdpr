<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use YiiRocks\Voyti\Gdpr\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Gdpr\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Model\UserProfile;
use YiiRocks\Voyti\Model\UserSessions;
use YiiRocks\Voyti\SocialAuth\Model\UserSocialAccount;
use Yiisoft\Json\Json;

#[AllowMockObjectsWithoutExpectations]
final class GdprExportServiceTest extends DatabaseTestCase
{
    use UserFactoryTrait;

    public function testRunHandlesMissingProfile(): void
    {
        $user = $this->createUser(
            email: 'noprofile@example.com',
            username: 'noprofileuser',
            confirmedAt: time(),
        );

        $service = new GdprExportService([
            'email',
            'userProfile.public_email',
            'userProfile.name',
            'userProfile.gravatar_email',
            'userProfile.location',
            'userProfile.website',
            'userProfile.bio',
            'userProfile.birthday',
        ]);

        $data = $service->run($user);

        self::assertSame('noprofile@example.com', $data['email']);
        self::assertArrayNotHasKey('userProfile.public_email', $data);
        self::assertArrayNotHasKey('userProfile.name', $data);
        self::assertArrayNotHasKey('userProfile.gravatar_email', $data);
        self::assertArrayNotHasKey('userProfile.location', $data);
        self::assertArrayNotHasKey('userProfile.website', $data);
        self::assertArrayNotHasKey('userProfile.bio', $data);
        self::assertArrayNotHasKey('userProfile.birthday', $data);

        $profile = new UserProfile();
        $profile->setUserId($user->getIdOrZero());
        $profile->setName('No Birthday');
        $profile->save();

        $data = $service->run($user);

        self::assertSame('No Birthday', $data['userProfile.name']);
        self::assertArrayNotHasKey('userProfile.birthday', $data);
    }

    public function testRunOmitsNullValues(): void
    {
        $user = $this->createUser(
            email: 'noextras@example.com',
            username: 'noextrasuser',
            confirmedAt: time(),
        );

        $service = new GdprExportService(['email', 'notARealProperty']);

        $data = $service->run($user);

        self::assertSame(['email' => 'noextras@example.com'], $data);
    }

    public function testRunReturnsExpectedData(): void
    {
        $user = $this->createUser(
            email: 'test@example.com',
            username: 'testuser',
            confirmedAt: time(),
        );
        $userId = $user->getIdOrZero();

        $profile = new UserProfile();
        $profile->setUserId($userId);
        $profile->setPublicEmail('public@example.com');
        $profile->setName('Test User');
        $profile->setGravatarEmail('gravatar@example.com');
        $profile->setLocation('Testville');
        $profile->setWebsite('https://example.com');
        $profile->setBio('A café bio');
        $profile->setBirthday(new DateTimeImmutable('1990-01-02'));
        $profile->save();

        $session = new UserSessions();
        $session->setUserId($userId);
        $session->setSessionId('session-abc');
        $session->setIp('203.0.113.1');
        $session->setUserAgent('TestAgent/1.0');
        $session->setCreatedAt(1000);
        $session->setUpdatedAt(2000);
        $session->save();

        $socialAccount = new UserSocialAccount();
        $socialAccount->setUserId($userId);
        $socialAccount->setProvider('github');
        $socialAccount->setClientId('client-1');
        $socialAccount->setUsername('gh-user');
        $socialAccount->setEmail('gh@example.com');
        $socialAccount->setData(Json::encode(['id' => 42]));
        $socialAccount->setCreatedAt(3000);
        $socialAccount->save();

        $service = new GdprExportService([
            'email',
            'username',
            'userProfile.public_email',
            'userProfile.name',
            'userProfile.gravatar_email',
            'userProfile.location',
            'userProfile.website',
            'userProfile.bio',
            'userProfile.birthday',
            'userSessions',
            'userSocialAccount',
        ]);

        $data = $service->run($user);

        self::assertSame('test@example.com', $data['email']);
        self::assertSame('testuser', $data['username']);
        self::assertSame('public@example.com', $data['userProfile.public_email']);
        self::assertSame('Test User', $data['userProfile.name']);
        self::assertSame('gravatar@example.com', $data['userProfile.gravatar_email']);
        self::assertSame('Testville', $data['userProfile.location']);
        self::assertSame('https://example.com', $data['userProfile.website']);
        self::assertSame('A café bio', $data['userProfile.bio']);
        self::assertSame('1990-01-02', $data['userProfile.birthday']);
        self::assertSame([
            [
                'ip' => '203.0.113.1',
                'user_agent' => 'TestAgent/1.0',
                'created_at' => 1000,
                'updated_at' => 2000,
            ],
        ], $data['userSessions']);
        self::assertSame([
            [
                'provider' => 'github',
                'username' => 'gh-user',
                'email' => 'gh@example.com',
                'created_at' => 3000,
                'data' => ['id' => 42],
            ],
        ], $data['userSocialAccount']);
    }
}
