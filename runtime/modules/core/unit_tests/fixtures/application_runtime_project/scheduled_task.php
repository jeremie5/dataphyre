<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$heartbeatPath = (string) getenv('DATAPHYRE_RUNTIME_TEST_HEARTBEAT_PATH');
if ($heartbeatPath === '') {
    throw new RuntimeException('Runtime supervisor heartbeat path is missing.');
}
file_put_contents($heartbeatPath, json_encode([
    'pid' => getmypid(),
    'at' => gmdate('Y-m-d\\TH:i:s\\Z'),
], JSON_THROW_ON_ERROR), LOCK_EX);
