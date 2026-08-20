<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Controller\Privacy;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\EventDispatcher\EventDispatcherInterface;
use YiiRocks\Voyti\Gdpr\Controller\Privacy\PrivacyController;
use YiiRocks\Voyti\Gdpr\Event\Gdpr\GdprEvent;
use YiiRocks\Voyti\Gdpr\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Gdpr\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Gdpr\tests\Support\TestContainerTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Gdpr\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserProfile;
use YiiRocks\Voyti\Model\UserSessions;
use YiiRocks\Voyti\SocialAuth\Model\UserSocialAccount;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\Json\Json;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\User\CurrentUser;

#[AllowMockObjectsWithoutExpectations]
final class PrivacyControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use TestContainerTrait;
    use UserFactoryTrait;

    private CurrentUser $currentUser;
    private PasswordHasher $passwordHasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentUser = $this->createCurrentUser();
        $this->passwordHasher = TestPasswordHasherFactory::create();
    }

    public function testAnonymizeDispatchesGdprEvent(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash('secret123'),
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        $request = (new ServerRequest('POST', '/'))->withParsedBody([
            'anonymize' => ['password' => 'secret123'],
        ]);

        $dispatcher = new EventCaptureDispatcher();
        $controller = $this->createController([EventDispatcherInterface::class => $dispatcher]);
        $controller->anonymize($request);

        $gdprEvent = $dispatcher->getEvent(GdprEvent::class);
        $this->assertInstanceOf(GdprEvent::class, $gdprEvent);
        $this->assertTrue($gdprEvent->getUser()->isAnonymized());
    }

    public function testAnonymizeFallsBackToBundledViewWhenHostOverridePathHasNoMatchingFile(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash('secret123'),
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        // The configured host viewPath exists but has no privacy/anonymize.php in it, so
        // RenderTrait::resolveViewPath() must fall back to the shared views package's bundled template.
        $customViewPath = sys_get_temp_dir() . '/voyti-gdpr-test-' . uniqid();
        mkdir($customViewPath);

        try {
            $config = VoytiConfigFactory::create(viewPath: $customViewPath);
            $controller = $this->createController([VoytiConfig::class => $config]);

            $html = (string) $controller->anonymize(new ServerRequest('GET', '/'))->getBody();

            self::assertStringContainsString('Anonymize Account', $html);
        } finally {
            rmdir($customViewPath);
        }
    }

    public function testAnonymizeUsesHostOverrideViewPathWhenConfigured(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash('secret123'),
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        $customViewPath = sys_get_temp_dir() . '/voyti-gdpr-test-' . uniqid();
        mkdir($customViewPath . '/privacy', 0777, true);
        file_put_contents($customViewPath . '/privacy/anonymize.php', 'CUSTOM_ANONYMIZE_TEMPLATE');

        try {
            $config = VoytiConfigFactory::create(viewPath: $customViewPath);
            $controller = $this->createController([VoytiConfig::class => $config]);

            $html = (string) $controller->anonymize(new ServerRequest('GET', '/'))->getBody();

            self::assertSame('CUSTOM_ANONYMIZE_TEMPLATE', $html);
        } finally {
            unlink($customViewPath . '/privacy/anonymize.php');
            rmdir($customViewPath . '/privacy');
            rmdir($customViewPath);
        }
    }

    public function testAnonymizeWithCorrectPasswordBlocksAccountAndMasksData(): void
    {
        $originalPassword = 'secret123';
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash($originalPassword),
            confirmedAt: time(),
        );
        $userId = $user->getIdOrZero();
        $originalAuthKey = $user->getAuthKey();
        $this->currentUser->login($user);

        $request = (new ServerRequest('POST', '/'))->withParsedBody([
            'anonymize' => ['password' => $originalPassword],
        ]);

        $controller = $this->createController();
        $response = $controller->anonymize($request);

        $this->assertSame(200, $response->getStatusCode());
        // Renders the success message, not the form or a redirect.
        self::assertStringContainsString('Your account has been anonymized', (string) $response->getBody());

        // Verify user was anonymized: email/username get the exact "<prefix><id>" masking value,
        // not just a value that happens to contain the prefix somewhere, and the auth key is
        // rotated so any existing session/remember-me cookie is invalidated.
        $updated = User::findById($userId);
        $this->assertNotNull($updated);
        $this->assertTrue($updated->isAnonymized());
        $this->assertNotNull($updated->getBlockedAt());
        $this->assertSame("GDPR{$userId}@example.com", $updated->getEmail());
        $this->assertSame("GDPR{$userId}", $updated->getUsername());
        $this->assertNotSame($originalAuthKey, $updated->getAuthKey());
    }

    public function testAnonymizeWithWrongPasswordShowsFormAgain(): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash('secret123'),
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        $request = (new ServerRequest('POST', '/'))->withParsedBody([
            'anonymize' => ['password' => 'wrongpassword'],
        ]);

        $controller = $this->createController();
        $response = $controller->anonymize($request);

        $this->assertSame(200, $response->getStatusCode());

        $html = (string) $response->getBody();
        self::assertStringContainsString('anonymize', $html);

        // Verify user was NOT anonymized
        $updated = User::findById((int) $user->getId());
        $this->assertNotNull($updated);
        $this->assertFalse($updated->isAnonymized());
    }

    public function testExportOmitsProfileAndBirthdayFieldsWhenAbsent(): void
    {
        $user = $this->createUser(
            email: 'noprofile@example.com',
            username: 'noprofileuser',
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        // No UserProfile row at all: every userProfile.* field must be omitted, not exported as null.
        $controller = $this->createController();
        $response = $controller->export();
        $data = Json::decode((string) $response->getBody());

        self::assertArrayNotHasKey('userProfile.name', $data);
        self::assertArrayNotHasKey('userProfile.birthday', $data);

        // A profile that exists but has no birthday set: only that one field is omitted.
        $profile = new UserProfile();
        $profile->setUserId($user->getIdOrZero());
        $profile->setName('No Birthday');
        $profile->save();

        $controller = $this->createController();
        $response = $controller->export();
        $data = Json::decode((string) $response->getBody());

        self::assertSame('No Birthday', $data['userProfile.name']);
        self::assertArrayNotHasKey('userProfile.birthday', $data);
    }

    public function testExportOmitsPropertiesNotInGdprExportPropertiesList(): void
    {
        $user = $this->createUser(
            email: 'noextras@example.com',
            username: 'noextrasuser',
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        // An unrecognized property name (not one of the match arms) must be silently dropped,
        // not surfaced as null or as an error - hosts control this list via config, so a typo
        // there shouldn't leak a spurious null-valued key into the export.
        $controller = $this->createController([
            PrivacyController::class => [
                'class' => PrivacyController::class,
                '__construct()' => [
                    'gdprConfig' => [
                        'gdprExportProperties' => ['email', 'notARealProperty'],
                        'gdprAnonymizePrefix' => 'GDPR',
                    ],
                ],
            ],
        ]);
        $response = $controller->export();

        $data = Json::decode((string) $response->getBody());

        self::assertSame(['email' => 'noextras@example.com'], $data);
    }

    public function testExportReturnsJsonWithUserData(): void
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

        $this->currentUser->login($user);

        $controller = $this->createController();
        $response = $controller->export();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        $json = (string) $response->getBody();
        // Encoding flags: pretty-printed (newlines present), URL slashes left unescaped, and the
        // café's accented character kept literal rather than \u-escaped.
        self::assertStringContainsString("\n", $json);
        self::assertStringNotContainsString('\\/', $json);
        self::assertStringContainsString('café', $json);

        $data = Json::decode($json);

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

    private function createController(array $overrides = []): PrivacyController
    {
        return $this->getTestContainer(array_merge([
            CurrentUser::class => $this->currentUser,
        ], $overrides))->get(PrivacyController::class);
    }
}
