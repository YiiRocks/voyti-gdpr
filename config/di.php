<?php

declare(strict_types=1);

use Psr\EventDispatcher\EventDispatcherInterface;
use YiiRocks\Voyti\Gdpr\Controller\Privacy\PrivacyController;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\SimpleMessageFormatter;

/** @var array $params */

return [
    PrivacyController::class => PrivacyController::class,

    AnonymizeUserService::class => fn(
        EventDispatcherInterface $eventDispatcher,
    ) => new AnonymizeUserService(
        $eventDispatcher,
        $params['yiirocks/voyti']['gdpr']['gdprAnonymizePrefix'] ?? 'GDPR',
    ),

    GdprExportService::class => new GdprExportService(
        $params['yiirocks/voyti']['gdpr']['gdprExportProperties'] ?? [],
    ),

    // Translation category source for this package's own message files.
    'yiirocks/voyti-gdpr.translator' => [
        'definition' => static fn() => new CategorySource(
            'voyti-gdpr',
            new MessageSource(dirname(__DIR__) . '/resources/messages'),
            new SimpleMessageFormatter(),
        ),
        'tags' => ['translation.categorySource'],
    ],
];
