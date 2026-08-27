<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Gdpr\Controller\Privacy;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Controller\RenderTrait;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use YiiRocks\Voyti\Helper\Views\MenuView;
use YiiRocks\Voyti\Model\Form\Settings\ConsentForm;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\FormModel\FormHydrator;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;
use Yiisoft\Json\Json;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Security\PasswordHasher;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;
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
        private AnonymizeUserService $anonymizeUserService,
        private GdprExportService $gdprExportService,
        private UrlGeneratorInterface $url,
        private ResponseFactoryInterface $responseFactory,
        private FormHydrator $formHydrator,
        private CurrentUser $currentUser,
        private VoytiConfig $config,
    ) {}

    public function anonymize(ServerRequestInterface $request): ResponseInterface
    {
        $form = new ConsentForm($this->translator, 'anonymize', 'voyti.view.anonymize.confirm_label', 'voyti-gdpr');

        if ($this->formHydrator->populateFromPostAndValidate($form, $request)) {
            /** @var User $user */
            $user = $this->currentUser->getIdentity();

            if ($this->passwordHasher->validate($form->password, $user->getPasswordHash())) {
                $this->anonymizeUserService->run($user);
                return $this->renderView('shared/message', [
                    'data' => [
                        'title' => $this->translator->translate('voyti.settings.personal_info_removed', category: 'voyti-gdpr'),
                        'homeUrl' => $this->url->generate($this->getHomeRoute()),
                    ],
                ]);
            }
        }

        return $this->renderView('privacy/anonymize', [
            'form' => $form,
            'data' => [
                'menu' => MenuView::account($this->config, $this->url, $this->translator()),
                'formSubmitUrl' => $this->url->generate('voyti/user-privacy-anonymize'),
            ],
        ]);
    }

    public function export(): ResponseInterface
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        $data = $this->gdprExportService->run($user);

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
}
