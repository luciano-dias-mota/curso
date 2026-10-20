<?php

declare(strict_types=1);

return [
    'experiences' => [
        'generic' => [
            'label' => 'Genérica',
            'description' => 'Experiência padrão da plataforma para cursos sem shell específico.',
            'entry_pattern' => '/curso/{id}',
            'asset_namespace' => 'generic',
            'layout' => 'layouts/courses/generic',
        ],

        'pmmt' => [
            'label' => 'Preparatório PMMT',
            'description' => 'Experiência legada preservada do Preparatório PMMT.',
            'entry_pattern' => '/curso/{id}/inicio',
            'asset_namespace' => 'pmmt',
            'layout' => 'layouts/student',
            'legacy_assets' => [
                'css' => [
                    '/assets/css/app.css',
                    '/assets/css/learning-flow.css',
                    '/assets/css/video-management.css',
                    '/assets/css/mockup-v3.css',
                    '/assets/css/simulation.css',
                ],
                'js' => [
                    '/assets/js/app.js',
                    '/assets/js/simulation.js',
                ],
            ],
        ],

        'postgresql' => [
            'label' => 'PostgreSQL',
            'description' => 'Experiência prática de banco de dados com laboratórios PGlite.',
            'entry_pattern' => '/curso/{id}',
            'asset_namespace' => 'postgresql',
            'layout' => 'layouts/courses/postgresql',
        ],
    ],
];
