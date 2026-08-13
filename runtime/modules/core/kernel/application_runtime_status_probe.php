<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argc ?? 0) !== 1) {
    exit(64);
}
$host = trim((string) (getenv('DATAPHYRE_RUNTIME_STATUS_HOST') ?: ''));
$portRaw = trim((string) (getenv('DATAPHYRE_RUNTIME_STATUS_PORT') ?: ''));
if ($host !== '127.0.0.1'
    || !preg_match('/^[0-9]+$/D', $portRaw)
    || ($port = (int) $portRaw) < 1
    || $port > 65535) {
    exit(64);
}

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 1.5,
        'ignore_errors' => true,
        'header' => "Connection: close\r\n",
    ],
]);
$payload = @file_get_contents('http://' . $host . ':' . $port . '/dataphyre/runtime/status', false, $context);
$statusCode = null;
foreach (($http_response_header ?? []) as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches)) {
        $statusCode = (int) $matches[1];
        break;
    }
}
if (!is_string($payload) || strlen($payload) > 4096 || $statusCode !== 200) {
    exit(69);
}
$decoded = json_decode($payload, true);
$validPool = static fn(mixed $value): bool => is_array($value)
    && array_keys($value) === ['running', 'pid', 'uid', 'gid', 'supplementary_gids', 'cap_eff', 'no_new_privileges']
    && ($value['running'] ?? null) === true
    && is_int($value['pid'] ?? null)
    && $value['pid'] > 1
    && $value['pid'] <= 2147483647
    && ($value['uid'] ?? null) === 10001
    && ($value['gid'] ?? null) === 10001
    && ($value['supplementary_gids'] ?? null) === [10001]
    && ($value['cap_eff'] ?? null) === '0000000000000000'
    && ($value['no_new_privileges'] ?? null) === true;
$cadence = is_array($decoded) ? ($decoded['cadence'] ?? null) : null;
$count = is_array($cadence) ? ($cadence['count'] ?? null) : null;
$lastAt = is_array($cadence) ? ($cadence['last_at'] ?? null) : null;
$lastResult = is_array($cadence) ? ($cadence['last_result'] ?? null) : null;
$valid = is_array($decoded)
    && array_keys($decoded) === ['contract', 'supervisor_pid', 'supervisor_uid', 'supervisor_gid', 'activation_mode', 'active', 'web', 'scheduler', 'cadence']
    && $decoded['contract'] === 'dataphyre.application_runtime.v1'
    && $decoded['supervisor_pid'] === 1
    && $decoded['supervisor_uid'] === 0
    && $decoded['supervisor_gid'] === 0
    && in_array($decoded['activation_mode'], ['active', 'signal'], true)
    && is_bool($decoded['active'])
    && $validPool($decoded['web'])
    && $validPool($decoded['scheduler'])
    && $decoded['web']['pid'] !== $decoded['scheduler']['pid']
    && is_array($cadence)
    && array_keys($cadence) === ['count', 'last_at', 'last_result']
    && is_int($count)
    && $count >= 0
    && $count <= PHP_INT_MAX
    && (($count === 0 && $lastAt === null && $lastResult === 'never')
        || ($count > 0
            && is_string($lastAt)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $lastAt) === 1
            && in_array($lastResult, ['ok', 'failed'], true)));
if (!$valid) {
    exit(65);
}
fwrite(STDOUT, json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
