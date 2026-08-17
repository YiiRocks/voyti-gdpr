<?php

declare(strict_types=1);

use YiiRocks\Voyti\Gdpr\Controller\Privacy\PrivacyController;
use YiiRocks\Voyti\Middleware\RequireLoginMiddleware;
use YiiRocks\Voyti\VoytiRoutes;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

/** @var array $params */

$voytiParams = $params['yiirocks/voyti'] ?? [];

return [
    Group::create()
        ->middleware(...VoytiRoutes::webMiddleware($voytiParams))
        ->routes(
            Group::create('settings/')
                ->middleware(RequireLoginMiddleware::class)
                ->routes(
                    Route::get('privacy/export')->name('voyti/user-privacy-export')->action([PrivacyController::class, 'export']),
                    Route::methods(['GET', 'POST'], 'privacy/anonymize')->name('voyti/user-privacy-anonymize')->action([PrivacyController::class, 'anonymize']),
                ),
        ),
];
