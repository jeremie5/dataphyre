<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

return [
    'id' => '_Runtime$Probe',
    'root_directory' => __DIR__,
    'rootpath_file' => __DIR__ . '/rootpaths.php',
    'framework_bootstrap_file' => __DIR__ . '/framework_bootstrap.php',
    'options' => ['fallback_to_legacy_bootstrap' => false],
];
