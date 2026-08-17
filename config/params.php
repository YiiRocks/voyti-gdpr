<?php

declare(strict_types=1);

return [
    // Contribute links to core's Privacy settings hub (same cross-package pattern
    // yiirocks/voyti-2fa uses for its own account-menu link), so core needs no knowledge of
    // this package's routes. Also makes core's Privacy hub itself reachable - it's shown
    // whenever privacyMenuItems is non-empty or allowAccountDelete is true.
    'yiirocks/voyti' => [
        'privacyMenuItems' => [
            [
                'label' => 'voyti.view.privacy.export_data',
                'category' => 'voyti-gdpr',
                'route' => 'voyti/user-privacy-export',
            ],
            [
                'label' => 'voyti.view.privacy.anonymize_data',
                'category' => 'voyti-gdpr',
                'route' => 'voyti/user-privacy-anonymize',
            ],
        ],
    ],

    // This package's own configuration.
    'yiirocks/voyti-gdpr' => [
        'gdprExportProperties' => [
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
        ],
        'gdprAnonymizePrefix' => 'GDPR',
    ],
];
