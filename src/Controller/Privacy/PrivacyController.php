<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\Controller\Privacy;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Controller\RenderTrait;
use YiiRocks\Voyti\Gdpr\Event\Gdpr\GdprEvent;
use YiiRocks\Voyti\Helper\Views\VoytiCommonParametersInjection;
use YiiRocks\Voyti\Model\Form\Settings\ConsentForm;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserSessions;
use YiiRocks\Voyti\SocialAuth\Model\UserSocialAccount;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\FormModel\FormHydrator;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;
use Yiisoft\Json\Json;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Security\Random;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;
use Yiisoft\Yii\View\Renderer\CsrfViewInjection;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * Handles GDPR-related self-service actions: data export and account anonymization.
 */
final readonly class PrivacyController
{
    use RenderTrait;

    public function __construct(
        private TranslatorInterface $translator,
        private WebViewRenderer $viewRenderer,
        private PasswordHasher $passwordHasher,
        private EventDispatcherInterface $eventDispatcher,
        private UrlGeneratorInterface $url,
        private ResponseFactoryInterface $responseFactory,
        private FormHydrator $formHydrator,
        private CurrentUser $currentUser,
        private VoytiConfig $config,
        /** @psalm-var array{gdprExportProperties: list<string>, gdprAnonymizePrefix: string} */
        private array $gdprConfig,
    ) {}

    public function anonymize(ServerRequestInterface $request): ResponseInterface
    {
        $form = new ConsentForm($this->translator, 'anonymize', 'voyti.view.anonymize.confirm_label', 'voyti-gdpr');

        if ($this->formHydrator->populateFromPostAndValidate($form, $request)) {
            /** @var User $user */
            $user = $this->currentUser->getIdentity();

            if ($this->passwordHasher->validate($form->password, $user->getPasswordHash())) {
                $prefix = $this->gdprConfig['gdprAnonymizePrefix'] . ($user->getId() ?? '');
                $user->setEmail($prefix . '@example.com');
                $user->setUsername($prefix);
                $user->setAnonymized(true);
                $user->setBlockedAt(time());
                $user->setAuthKey(Random::string());
                $user->save();
                $this->eventDispatcher->dispatch(new GdprEvent($user));
                return $this->renderView('shared/message', [
                    'data' => [
                        'title' => $this->translator->translate('voyti.settings.personal_info_removed', category: 'voyti-gdpr'),
                        'homeUrl' => $this->url->generate($this->getHomeRoute()),
                    ],
                ]);
            }
        }

        return $this->viewRenderer
            ->withAddedInjections(CsrfViewInjection::class, VoytiCommonParametersInjection::class)
            ->withViewPath($this->resolveOwnViewPath())
            ->render('privacy/anonymize', [
                'form' => $form,
                'data' => [
                    'formSubmitUrl' => $this->url->generate('voyti/user-privacy-anonymize'),
                ],
            ]);
    }

    public function export(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        $values = array_map(
            static function (string $property) use ($user): mixed {
                return match ($property) {
                    'email' => $user->getEmail(),
                    'username' => $user->getUsername(),
                    'userProfile.public_email' => $user->getProfile()?->getPublicEmail(),
                    'userProfile.name' => $user->getProfile()?->getName(),
                    'userProfile.gravatar_email' => $user->getProfile()?->getGravatarEmail(),
                    'userProfile.location' => $user->getProfile()?->getLocation(),
                    'userProfile.website' => $user->getProfile()?->getWebsite(),
                    'userProfile.bio' => $user->getProfile()?->getBio(),
                    'userProfile.birthday' => $user->getProfile()?->getBirthday()?->format('Y-m-d'),
                    'userSessions' => array_map(
                        static fn(UserSessions $entry): array => [
                            'ip' => $entry->getIp(),
                            'user_agent' => $entry->getUserAgent(),
                            'created_at' => $entry->getCreatedAt(),
                            'updated_at' => $entry->getUpdatedAt(),
                        ],
                        UserSessions::findByUserId($user->getIdOrZero()),
                    ),
                    /**
                     * @infection-ignore-all Whether yiirocks/voyti-social-auth is installed is fixed
                     * for the whole test process (this package's own require-dev always has it
                     * present), so a mutant on this condition has no behavioural effect the suite can
                     * observe - the "not installed" branch can only be exercised by actually running
                     * without the optional package, not from within this test run.
                     */
                    'userSocialAccount' => class_exists(UserSocialAccount::class) ? array_map(
                        static fn(UserSocialAccount $account): array => [
                            'provider' => $account->getProvider(),
                            'username' => $account->getUsername(),
                            'email' => $account->getEmail(),
                            'created_at' => $account->getCreatedAt(),
                            'data' => $account->getDecodedData(),
                        ],
                        UserSocialAccount::findByUserId($user->getIdOrZero()),
                    ) : null,
                    default => null,
                };
            },
            $this->gdprConfig['gdprExportProperties'],
        );

        /** @var array<array-key, mixed> $data */
        $data = array_filter(
            array_combine($this->gdprConfig['gdprExportProperties'], $values),
            static fn(mixed $v): bool => $v !== null,
        );

        $json = Json::encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        $response = $this->responseFactory->createResponse(Status::OK)
            ->withHeader(Header::CONTENT_TYPE, 'application/json; charset=UTF-8')
            ->withHeader(Header::CONTENT_DISPOSITION, 'attachment; filename="user-data-export.json"');
        $response->getBody()->write($json);

        return $response;
    }

    private function getHomeRoute(): string
    {
        return 'home';
    }

    /**
     * RenderTrait::resolveViewPath() always resolves relative to core's own package root (a
     * trait's __DIR__ is fixed to the file it's physically defined in, regardless of which class
     * uses it), so it can never find this package's own bundled views. Mirrors the same
     * host-override-then-bundled-fallback logic, rooted at this package's own directory instead.
     */
    private function resolveOwnViewPath(): string
    {
        if ($this->config->viewPath !== null && is_file($this->config->viewPath . '/privacy/anonymize.php')) {
            return $this->config->viewPath;
        }

        return dirname(__DIR__, 3) . '/resources/views/' . $this->config->webTheme->value;
    }
}
