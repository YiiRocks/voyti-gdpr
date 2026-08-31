<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Controller\Privacy;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use YiiRocks\Voyti\Gdpr\Controller\Privacy\PrivacyController;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use YiiRocks\Voyti\Gdpr\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Gdpr\tests\Support\TestContainerTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Gdpr\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Gdpr\tests\Support\VoytiConfigFactory;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserProfile;
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

    public static function anonymizePasswordProvider(): iterable
    {
        yield 'correct password anonymizes account' => [true];
        yield 'wrong password leaves account untouched' => [false];
    }

    public static function anonymizeViewResolutionProvider(): iterable
    {
        // Binary variant: a host override path with a matching template file must win, while an
        // override path that exists but lacks privacy/anonymize.php must fall back to the shared
        // views package's bundled template rather than erroring.
        yield 'host override view exists' => [true, 'CUSTOM_ANONYMIZE_TEMPLATE'];
        yield 'host override view missing falls back to bundled' => [false, 'Anonymize Account'];
    }

    #[DataProvider('anonymizeViewResolutionProvider')]
    public function testAnonymizeViewResolution(bool $hostOverrideViewExists, string $expectedContent): void
    {
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash('secret123'),
            confirmedAt: time(),
        );
        $this->currentUser->login($user);

        $customViewPath = sys_get_temp_dir() . '/voyti-gdpr-test-' . uniqid();
        if ($hostOverrideViewExists) {
            mkdir($customViewPath . '/privacy', 0777, true);
            file_put_contents($customViewPath . '/privacy/anonymize.php', 'CUSTOM_ANONYMIZE_TEMPLATE');
        } else {
            mkdir($customViewPath);
        }

        try {
            $config = VoytiConfigFactory::create(viewPath: $customViewPath);
            $controller = $this->createController([VoytiConfig::class => $config]);

            $html = (string) $controller->anonymize(new ServerRequest('GET', '/'))->getBody();

            self::assertStringContainsString($expectedContent, $html);
        } finally {
            if ($hostOverrideViewExists) {
                unlink($customViewPath . '/privacy/anonymize.php');
                rmdir($customViewPath . '/privacy');
            }
            rmdir($customViewPath);
        }
    }

    #[DataProvider('anonymizePasswordProvider')]
    public function testAnonymizeWithPassword(bool $isCorrectPassword): void
    {
        $originalPassword = 'secret123';
        $user = $this->createUser(
            email: 'user@example.com',
            username: 'originaluser',
            passwordHash: $this->passwordHasher->hash($originalPassword),
            confirmedAt: time(),
        );
        $userId = $user->getIdOrZero();
        $this->currentUser->login($user);

        $request = (new ServerRequest('POST', '/'))->withParsedBody([
            'anonymize' => ['password' => $isCorrectPassword ? $originalPassword : 'wrongpassword'],
        ]);

        $controller = $this->createController();
        $response = $controller->anonymize($request);

        $this->assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();

        $updated = User::findById($userId);
        $this->assertNotNull($updated);

        if ($isCorrectPassword) {
            // Renders the success message, not the form or a redirect. Detailed masking/event
            // behaviour belongs to AnonymizeUserServiceTest - here we only confirm the controller
            // actually delegates to the service rather than skipping it.
            self::assertStringContainsString('Your account has been anonymized', $html);
            $this->assertTrue($updated->isAnonymized());
            $this->assertNotNull($updated->getBlockedAt());
        } else {
            self::assertStringContainsString('anonymize', $html);
            self::assertStringContainsString('Incorrect password', $html);
            self::assertMatchesRegularExpression(
                '#id="anonymize-password">\s*<div>Incorrect password</div>#',
                $html,
            );
            $this->assertFalse($updated->isAnonymized());
        }
    }

    public function testExportReturnsJsonResponseWithHeadersAndUnescapedEncoding(): void
    {
        $user = $this->createUser(
            email: 'test@example.com',
            username: 'testuser',
            confirmedAt: time(),
        );

        $profile = new UserProfile();
        $profile->setUserId($user->getIdOrZero());
        $profile->setWebsite('https://example.com');
        $profile->setBio('A café bio');
        $profile->save();

        $this->currentUser->login($user);

        // Property extraction/omission rules (missing profile, unrecognized properties, etc.) are
        // covered by GdprExportServiceTest - here we only confirm the controller wires the service's
        // result into the HTTP response with the expected headers and JSON encoding flags.
        $controller = $this->createController([
            GdprExportService::class => new GdprExportService(['email', 'userProfile.website', 'userProfile.bio']),
        ]);
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
        self::assertSame('https://example.com', $data['userProfile.website']);
        self::assertSame('A café bio', $data['userProfile.bio']);
    }

    private function createController(array $overrides = []): PrivacyController
    {
        return $this->getTestContainer(array_merge([
            CurrentUser::class => $this->currentUser,
        ], $overrides))->get(PrivacyController::class);
    }
}
