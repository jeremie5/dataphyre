<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

return [
    'bootstrap' => [
        'app' => '_Runtime$Probe',
        'prevent_keyless_direct_access' => false,
        'allow_app_override' => false,
        'is_production' => false,
        'max_execution_time' => 30,
        'application_roots' => [__DIR__],
        'modules' => [
            'enabled' => ['core', 'scheduling'],
            'disabled' => ['flightdeck'],
        ],
        'flightdeck' => ['enabled' => false, 'debugbar' => ['enabled' => false]],
    ],
    'install' => [
        'shared' => ['directories' => [], 'files' => []],
        'app' => [
            'directories' => ['cache', 'cache/scheduling', 'config', 'config/static', 'logs'],
            'files' => [
                ['path' => 'config/static/dpvk', 'type' => 'generated_dpvk'],
                ['path' => 'cache/verified', 'type' => 'generated_verified'],
            ],
        ],
    ],
];
