<?php

declare(strict_types=1);

use YiiRocks\Voyti\Gdpr\Controller\Privacy\PrivacyController;
use Yiisoft\Translator\CategorySource;
use Yiisoft\Translator\Message\Php\MessageSource;
use Yiisoft\Translator\SimpleMessageFormatter;

/** @var array $params */

return [
    PrivacyController::class => [
        'class' => PrivacyController::class,
        '__construct()' => [
            'gdprConfig' => [
                'gdprExportProperties' => $params['yiirocks/voyti']['gdpr']['gdprExportProperties'] ?? [],
                'gdprAnonymizePrefix' => $params['yiirocks/voyti']['gdpr']['gdprAnonymizePrefix'] ?? 'GDPR',
            ],
        ],
    ],

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
