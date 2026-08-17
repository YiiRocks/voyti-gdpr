<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\tests\Support;

use Composer\InstalledVersions;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Cookies\CookieEncryptor;
use Yiisoft\Cookies\CookieSigner;
use Yiisoft\Csrf\CsrfTokenInterface;
use Yiisoft\Csrf\StubCsrfToken;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Mailer\MailerInterface;
use Yiisoft\Rbac\AssignmentsStorageInterface;
use Yiisoft\Rbac\ItemsStorageInterface;
use Yiisoft\Rbac\Manager;
use Yiisoft\Rbac\ManagerInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\Flash\Flash;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\SimpleMessageFormatter;
use Yiisoft\Translator\Translator;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\View\WebView;
use Yiisoft\Widget\WidgetFactory;
use Yiisoft\Yii\View\Renderer\InjectionContainer\InjectionContainer;
use Yiisoft\Yii\View\Renderer\InjectionContainer\InjectionContainerInterface;

/**
 * Builds a fresh PSR-11 DI container per test from core's real config/di.php merged with this
 * package's own config/di.php, with in-memory test fakes overlaid. Tests call
 * getTestContainer() to resolve services; per-test overrides are passed as an array merged on top.
 */
trait TestContainerTrait
{
    private static ?ContainerInterface $sharedTestContainer = null;

    /**
     * Build a fresh container with standard test fakes and optional per-test overrides.
     *
     * @param array<class-string, object|class-string|callable> $overrides Definitions merged on top of defaults.
     */
    protected function createTestContainer(array $overrides = []): ContainerInterface
    {
        $coreRoot = InstalledVersions::getInstallPath('yiirocks/voyti');
        $ownRoot = dirname(__DIR__, 2);

        $params = require $coreRoot . '/config/params.php';
        $diPath = $coreRoot . '/config/di.php';
        $definitions = (static function (array $params) use ($diPath): array {
            return require $diPath;
        })($params);

        $hydratorDiPath = InstalledVersions::getInstallPath('yiisoft/hydrator') . '/config/di.php';
        $definitions = array_merge(require $hydratorDiPath, $definitions);

        // Voyti binds no ValidatorInterface of its own - it comes from yiisoft/validator's own
        // config/di.php, which yiisoft/config auto-merges in for any host application. Replicate
        // that merge here so FormHydrator's real validation behavior matches production instead
        // of requiring every test to hand-mock ValidatorInterface.
        $validatorInstallPath = InstalledVersions::getInstallPath('yiisoft/validator');
        $validatorParams = require $validatorInstallPath . '/config/params.php';
        $validatorDiPath = $validatorInstallPath . '/config/di.php';
        $validatorDefinitions = (static function (array $params) use ($validatorDiPath): array {
            return require $validatorDiPath;
        })(array_merge($params, $validatorParams));
        /** @var CategorySource $validatorCategorySource */
        $validatorCategorySource = $validatorDefinitions['yii.validator.categorySource']['definition']();
        $definitions = array_merge($validatorDefinitions, $definitions);

        // This package's own config/di.php (PrivacyController's gdprConfig binding, plus the
        // translation.categorySource-tagged definition this trait bypasses in favor of hand-adding
        // the category to the Translator below).
        $ownParamsPath = $ownRoot . '/config/params.php';
        $ownParams = require $ownParamsPath;
        $mergedParams = array_merge_recursive($params, $ownParams);
        $ownDiPath = $ownRoot . '/config/di.php';
        $ownDefinitions = (static function (array $params) use ($ownDiPath): array {
            return require $ownDiPath;
        })($mergedParams);
        $definitions = array_merge($definitions, $ownDefinitions);

        $psr17Factory = new Psr17Factory();
        $session = new FakeSession();

        $definitions = array_merge($definitions, [
            // Real view stack so RenderTrait's WebViewRenderer renders the bundled templates instead
            // of being mocked (WebViewRenderer/WebView are final). Injections (CsrfViewInjection) are
            // resolved through the container via InjectionContainer; a stub CSRF token satisfies them.
            Aliases::class => new Aliases(),
            AssignmentsStorageInterface::class => new SimpleAssignmentsStorage(),
            CookieEncryptor::class => new CookieEncryptor('test-secret-key-0123456789abcdef'),
            CookieSigner::class => new CookieSigner('test-secret-key-0123456789abcdef'),
            CsrfTokenInterface::class => new StubCsrfToken('test-csrf-token'),
            CurrentRoute::class => new CurrentRoute(),
            InjectionContainerInterface::class => InjectionContainer::class,
            EventDispatcherInterface::class => new EventCaptureDispatcher(),
            FlashInterface::class => new Flash($session),
            ItemsStorageInterface::class => new SimpleItemsStorage(),
            LoggerInterface::class => new NullLogger(),
            MailerInterface::class => new MailCapture(),
            ManagerInterface::class => Manager::class,
            PsrClientInterface::class => new class implements PsrClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new RuntimeException('HTTP client not configured in tests');
                }
            },
            RequestFactoryInterface::class => $psr17Factory,
            ResponseFactoryInterface::class => $psr17Factory,
            SessionInterface::class => $session,
            StreamFactoryInterface::class => $psr17Factory,
            TranslatorInterface::class => (static function () use ($coreRoot, $ownRoot, $validatorCategorySource): TranslatorInterface {
                $translator = new Translator('en', null, 'voyti');
                $translator->addCategorySources(
                    new CategorySource(
                        'voyti',
                        new MessageSource($coreRoot . '/resources/messages'),
                        new SimpleMessageFormatter(),
                    ),
                    new CategorySource(
                        'voyti-gdpr',
                        new MessageSource($ownRoot . '/resources/messages'),
                        new SimpleMessageFormatter(),
                    ),
                    $validatorCategorySource,
                );

                return $translator;
            })(),
            UrlGeneratorInterface::class => new FakeUrlGenerator(),
            WebView::class => new WebView(),
        ]);

        $definitions = array_merge($definitions, $overrides);

        $container = new Container(ContainerConfig::create()->withDefinitions($definitions));

        // AuthChoice::widget() (and any other yiisoft/widget-based widget) resolves through
        // WidgetFactory's own static container reference rather than this one directly - in a
        // real application yiisoft/widget's own config/bootstrap.php wires this up via the
        // runner's bootstrap step. Replicate that here so widgets resolve the same way.
        WidgetFactory::initialize($container);

        return $container;
    }

    /**
     * Get or create the shared test container for this test method.
     *
     * When called with overrides, a fresh container is always built.
     * When called without overrides, the cached container is returned.
     *
     * @param array<class-string, object|class-string|callable> $overrides Definitions merged on top of defaults.
     */
    protected function getTestContainer(array $overrides = []): ContainerInterface
    {
        if (self::$sharedTestContainer === null || $overrides !== []) {
            self::$sharedTestContainer = $this->createTestContainer($overrides);
        }

        return self::$sharedTestContainer;
    }

    protected static function resetTestContainer(): void
    {
        self::$sharedTestContainer = null;
    }
}
