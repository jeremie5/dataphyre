<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$fixtureRoot = __DIR__;
$runtimeRoot = rtrim((string) getenv('DATAPHYRE_RUNTIME_TEST_FRAMEWORK_ROOT'), '/\\');
$stateRoot = rtrim((string) getenv('DATAPHYRE_RUNTIME_TEST_STATE_ROOT'), '/\\');
$runtimeRoot = $runtimeRoot!=='' ? $runtimeRoot : '/workspace/dataphyre/runtime';
$managedApplicationDataRoot=rtrim((string)getenv('DATAPHYRE_APPLICATION_DATA_ROOT'),'/\\');
$stateRoot = $stateRoot!==''
	? $stateRoot
	: ($managedApplicationDataRoot!=='' ? $managedApplicationDataRoot : '/var/lib/dataphyre/scheduler-state/application');
if ($runtimeRoot === '' || !is_file($runtimeRoot . '/bootstrap.php') || $stateRoot === '') {
    throw new RuntimeException('Runtime supervisor fixture roots are unavailable.');
}
$paths = [
    'root' => $fixtureRoot . '/',
    'views' => $fixtureRoot . '/views/',
    'backend' => $fixtureRoot . '/',
    'dataphyre' => $stateRoot . '/',
    'src' => $fixtureRoot . '/',
    'database' => $fixtureRoot . '/database/',
    'config' => $fixtureRoot . '/config/',
    'tmp' => $stateRoot . '/tmp/',
    'common_root' => $fixtureRoot . '/',
    'app_override_key' => $fixtureRoot . '/app_override_key',
    'common_dataphyre' => dirname($runtimeRoot) . '/',
    'common_dataphyre_runtime' => $runtimeRoot . '/',
    'app' => defined('APP') ? APP : '_Runtime$Probe',
    'applications' => dirname($fixtureRoot) . '/',
    'application_roots' => defined('DATAPHYRE_APPLICATION_ROOTS') ? DATAPHYRE_APPLICATION_ROOTS : [],
];
if (!defined('ROOTPATH')) {
    define('ROOTPATH', $paths);
}
unset($paths, $fixtureRoot, $runtimeRoot, $stateRoot, $managedApplicationDataRoot);
