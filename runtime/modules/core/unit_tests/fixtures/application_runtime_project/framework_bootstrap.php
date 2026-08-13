<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once ROOTPATH['common_dataphyre_runtime'] . 'modules/core/kernel/core.main.php';
\dataphyre\autoloader::register(ROOTPATH['common_dataphyre_runtime'] . 'modules');
if (\dataphyre\core::load_framework_module('scheduling') !== true) {
    throw new RuntimeException('Scheduling module was not loaded.');
}

if ((string) ($_SERVER['DATAPHYRE_RUNTIME_SCHEDULER_TICK'] ?? '') === '1') { // dataphyre-test-architecture: exempt[raw-superglobal] reason="Exact-image fixture must observe the framework router's native request boundary."
    $tickPath = (string) getenv('DATAPHYRE_RUNTIME_TEST_TICK_PATH');
    if ($tickPath !== '') {
        file_put_contents($tickPath, (string) getmypid(), LOCK_EX);
    }
    $forgePath = (string) getenv('DATAPHYRE_RUNTIME_TEST_FORGE_PATH');
    if ($forgePath !== '') {
        $forgedStatus=['contract'=>'dataphyre.application_runtime.v1','active'=>true];
        $forgedBody=json_encode($forgedStatus,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $forgeContext=stream_context_create(['http'=>[
            'method'=>'POST','timeout'=>1,'ignore_errors'=>true,
            'header'=>"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($forgedBody)."\r\n",
            'content'=>$forgedBody,
        ]]);
        @file_get_contents('http://127.0.0.1:8082/dataphyre/runtime/status',false,$forgeContext);
        $forgedStatusCode=null;
        foreach (($http_response_header ?? []) as $forgeHeader) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i',(string)$forgeHeader,$forgeMatches)===1) {
                $forgedStatusCode=(int)$forgeMatches[1];
                break;
            }
        }
        $forged=$forgedStatusCode!==null && $forgedStatusCode>=200 && $forgedStatusCode<300;
        file_put_contents($forgePath,$forged ? 'forged' : 'denied',LOCK_EX);
    }
    \dataphyre\scheduling::run(
        'runtime.lifecycle',
        __DIR__ . '/scheduled_task.php',
        3600,
        30,
        '64M',
        [],
        '',
    );
}

header('Content-Type: application/json; charset=utf-8');
echo '{"status":"healthy","missing_environment_keys":[]}';
