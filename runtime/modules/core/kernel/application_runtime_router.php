<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$pool = getenv('DATAPHYRE_RUNTIME_POOL') ?: '';
$projectRoot = getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '';
$realProjectRoot = realpath($projectRoot);
$requestPath = rawurldecode((string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'));

if ($realProjectRoot === false || !is_dir($realProjectRoot) || strpos($requestPath, "\0") !== false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'runtime_configuration_invalid']);
    return;
}

$isLoopback = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
if ($pool === 'scheduler') {
    if (!$isLoopback) {
        http_response_code(404);
        return;
    }

    if ($requestPath === '/dataphyre/runtime/tick' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        require_once __DIR__ . '/application_runtime_tick_protocol.php';
        $timestamp=trim((string)($_SERVER['HTTP_X_DATAPHYRE_RUNTIME_TICK_TIMESTAMP'] ?? ''));
        $nonce=trim((string)($_SERVER['HTTP_X_DATAPHYRE_RUNTIME_TICK_NONCE'] ?? ''));
        $counter=trim((string)($_SERVER['HTTP_X_DATAPHYRE_RUNTIME_TICK_COUNTER'] ?? ''));
        $signature=trim((string)($_SERVER['HTTP_X_DATAPHYRE_RUNTIME_TICK_SIGNATURE'] ?? ''));
        $application=(string)(getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: '');
        $environment=(string)(getenv('DATAPHYRE_RUNTIME_ENVIRONMENT') ?: '');
        $publicKeyEncoded=trim((string)(getenv('DATAPHYRE_RUNTIME_TICK_PUBLIC_KEY') ?: ''));
        try {$publicKey=sodium_base642bin($publicKeyEncoded,SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING,'');}
        catch (Throwable) {$publicKey='';}
        $tickCandidate=[
            'timestamp'=>$timestamp,
            'nonce'=>$nonce,
            'application'=>$application,
            'environment'=>$environment,
            'counter'=>$counter,
            'signature'=>$signature,
        ];
        $valid=DataphyreApplicationRuntimeTickProtocol::verify($tickCandidate,$publicKey);
        if ($valid) {
            $claimBody=json_encode($tickCandidate,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $claimContext=stream_context_create(['http'=>[
                'method'=>'POST','timeout'=>2,'ignore_errors'=>true,
                'header'=>"Content-Type: application/json\r\nConnection: close\r\nContent-Length: ".strlen($claimBody)."\r\n",
                'content'=>$claimBody,
            ]]);
            $claimResponse=@file_get_contents('http://127.0.0.1:8082/dataphyre/runtime/tick/claim',false,$claimContext);
            $claimStatus=null;
            foreach (($http_response_header ?? []) as $claimHeader) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i',(string)$claimHeader,$claimMatches)===1) {
                    $claimStatus=(int)$claimMatches[1];
                    break;
                }
            }
            $claimDecoded=is_string($claimResponse) ? json_decode($claimResponse,true) : null;
            $valid=$claimStatus===200 && is_array($claimDecoded) && ($claimDecoded['ok'] ?? null)===true;
        }
        foreach([
            'HTTP_X_DATAPHYRE_RUNTIME_TICK_TIMESTAMP',
            'HTTP_X_DATAPHYRE_RUNTIME_TICK_NONCE',
            'HTTP_X_DATAPHYRE_RUNTIME_TICK_COUNTER',
            'HTTP_X_DATAPHYRE_RUNTIME_TICK_SIGNATURE',
        ] as $secretHeader) unset($_SERVER[$secretHeader]);
        unset(
            $timestamp,$nonce,$counter,$signature,$application,$environment,$publicKeyEncoded,$publicKey,
            $tickCandidate,$claimBody,$claimContext,$claimResponse,$claimStatus,$claimHeader,$claimMatches,$claimDecoded,
        );
        if (!$valid) {
            http_response_code(404);
            return;
        }
        $_SERVER['DATAPHYRE_RUNTIME_SCHEDULER_TICK'] = '1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';
        $_GET = ['uri' => 'health'];
        $_POST = [];
        $_REQUEST = $_GET;
    } elseif (preg_match('#^/dataphyre/scheduler/[A-Za-z0-9._-]{1,128}$#D',$requestPath)===1
        && ($_SERVER['REQUEST_METHOD'] ?? 'GET')==='GET') {
        // The common runtime validates the claim and signature before loading
        // scheduling or any tenant task file.
    } else {
        http_response_code(404);
        return;
    }
}

$publicRoot = $realProjectRoot . '/public';
if ($pool === 'web' && is_dir($publicRoot) && $requestPath !== '/') {
    $candidate = realpath($publicRoot . '/' . ltrim($requestPath, '/'));
    $prefix = rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($candidate !== false
        && strncmp($candidate, $prefix, strlen($prefix)) === 0
        && is_file($candidate)
        && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) !== 'php') {
        $mime = function_exists('mime_content_type') ? mime_content_type($candidate) : false;
        if (is_string($mime) && $mime !== '') {
            header('Content-Type: ' . $mime);
        }
        header('Content-Length: ' . (string) filesize($candidate));
        readfile($candidate);
        return;
    }
}

$_SERVER['DATAPHYRE_PROJECT_ROOT'] = $realProjectRoot;
$_SERVER['HTTP_X_DATAPHYRE_APPLICATION'] = (string) (getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: '');
$_SERVER['HTTP_X_TRAFFIC_SOURCE'] = 'internal_traffic';

if ($pool === 'scheduler') {
    ob_start();
    require dirname(__DIR__, 3) . '/bootstrap.php';
    ob_end_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    return;
}

require dirname(__DIR__, 3) . '/bootstrap.php';
