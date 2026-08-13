<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Application runtime supervisor contract')
    ->contract('core.application-runtime-supervisor', 1)
    ->layer('integration')
    ->risk('critical')
    ->watches('module:core')
    ->through('pid-one', 'privilege-boundary', 'signed-cadence', 'status-probe')
    ->isolation('case')
    ->tag('core', 'runtime', 'supervisor', 'security', 'release')
    ->group('framework-coverage');

test('supervisor owns private status and signs cadence before privilege-dropped pools boot', static function(Context $t): void {
    $kernel=dirname(__DIR__) . '/kernel';
    $supervisor=(string)file_get_contents($kernel . '/application_runtime_supervisor.php');
    $launcher=(string)file_get_contents($kernel . '/application_runtime_pool_launcher.php');
    $router=(string)file_get_contents($kernel . '/application_runtime_router.php');
    $probe=(string)file_get_contents($kernel . '/application_runtime_status_probe.php');
    $protocol=(string)file_get_contents($kernel . '/application_runtime_tick_protocol.php');
    $fixture=(string)file_get_contents(__DIR__ . '/fixtures/application_runtime_project/framework_bootstrap.php');

    $t->contains("getmypid() !== 1", $supervisor);
    $t->contains("posix_geteuid() !== 0", $supervisor);
    $t->contains("DATAPHYRE_RUNTIME_STATUS_PORT',8082", $supervisor);
    $t->contains("stream_socket_server('tcp://'", $supervisor);
    $t->contains('sodium_crypto_sign_keypair()', $supervisor);
    $t->contains('sodium_crypto_sign_detached', $protocol);
    $t->contains('dataphyre.application_runtime_tick.v1', $protocol);
    foreach([
        'X-Dataphyre-Runtime-Tick-Timestamp',
        'X-Dataphyre-Runtime-Tick-Nonce',
        'X-Dataphyre-Runtime-Tick-Counter',
        'X-Dataphyre-Runtime-Tick-Signature',
    ] as $header) {
        $t->contains($header, $supervisor);
        $t->contains(strtoupper(str_replace('-', '_', $header)), $router);
    }
    $t->contains("unset($" . "childEnvironment['DATAPHYRE_RUNTIME_STATUS_HOST']", $supervisor);
    $t->isFalse(str_contains($supervisor, 'DATAPHYRE_RUNTIME_TICK_PRIVATE_KEY'));
    $t->isFalse(str_contains($router, '/dataphyre/runtime/status'));
    $t->contains('/dataphyre/runtime/tick/claim', $router);
    $t->contains('DataphyreApplicationRuntimeTickProtocol::consume', $supervisor);
    $t->contains('unset($pending[$counter])', $protocol);
    $obsoleteDescriptor=implode('_',['DATAPHYRE','RUNTIME','STATUS','FD']);
    $obsoleteStream=implode('', ['php://','fd/']);
    foreach([$supervisor,$router,$probe,$fixture] as $source) {
        $t->isFalse(str_contains($source,$obsoleteDescriptor));
        $t->isFalse(str_contains($source,$obsoleteStream));
    }
    $t->contains("'method'=>'POST'",$fixture);
    $t->contains('http://127.0.0.1:8082/dataphyre/runtime/status',$fixture);
    $t->contains("$" . "forgedStatusCode>=200",$fixture);
    $t->isFalse(str_contains($probe, "PHP_SAPI !== 'cli') {"));
    $t->contains("posix_initgroups('dataphyre',$" . 'gid)', $launcher);
    $t->contains('posix_setgid', $launcher);
    $t->contains('posix_setuid', $launcher);
    $t->contains('pcntl_exec', $launcher);
    $t->contains("putenv('PHP_CLI_SERVER_WORKERS=3')", $launcher);
    $t->contains("else putenv('PHP_CLI_SERVER_WORKERS')", $launcher);

    foreach(['supervisor_uid','supervisor_gid','supplementary_gids','cap_eff','no_new_privileges'] as $evidence) {
        $t->contains($evidence, $supervisor);
        $t->contains($evidence, $probe);
    }
    $t->contains("$" . "decoded['supervisor_pid'] === 1", $probe);
    $t->contains("($" . "value['supplementary_gids'] ?? null) === [10001]", $probe);
    $t->contains("($" . "value['cap_eff'] ?? null) === '0000000000000000'", $probe);
    $t->contains("($" . "value['no_new_privileges'] ?? null) === true", $probe);
})->tag('source-contract');

test('one supervisor-issued signed cadence claim is accepted exactly once', static function(Context $t): void {
    require_once dirname(__DIR__) . '/kernel/application_runtime_tick_protocol.php';
    $keypair=sodium_crypto_sign_keypair();
    $secretKey=sodium_crypto_sign_secretkey($keypair);
    $publicKey=sodium_crypto_sign_publickey($keypair);
    $tick=DataphyreApplicationRuntimeTickProtocol::issue(
        '_Runtime$Probe','preview-pr-123',7,$secretKey,1776073500,str_repeat('a',32),
    );
    $pending=['7'=>$tick];
    $t->isTrue(DataphyreApplicationRuntimeTickProtocol::verify($tick,$publicKey,1776073500));
    $t->isTrue(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tick,$publicKey,1776073500));
    $t->same([],$pending);
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tick,$publicKey,1776073500));
    $tampered=$tick;
    $tampered['counter']='8';
    $pending=['8'=>$tampered];
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::consume($pending,$tampered,$publicKey,1776073500));
    $t->same(['8'=>$tampered],$pending);
    $t->isFalse(DataphyreApplicationRuntimeTickProtocol::verify($tick,$publicKey,1776073531));
})->tag('signed-cadence','replay','negative');

test('scheduler router rejects unsigned tenant ticks before application bootstrap', static function(Context $t): void {
    $kernel=dirname(__DIR__) . '/kernel';
    $project=__DIR__ . '/fixtures/application_runtime_project';
    $script=<<<'PHP'
$_SERVER=[
    'REMOTE_ADDR'=>'127.0.0.1',
    'REQUEST_URI'=>'/dataphyre/runtime/tick',
    'REQUEST_METHOD'=>'POST',
];
ob_start();
require $argv[1];
$body=ob_get_clean();
echo json_encode(['status'=>http_response_code(),'body'=>$body],JSON_THROW_ON_ERROR);
PHP;
    $result=$t->phpProcess(['-r',$script,$kernel . '/application_runtime_router.php'], environment:[
        'DATAPHYRE_RUNTIME_POOL'=>'scheduler',
        'DATAPHYRE_RUNTIME_PROJECT_ROOT'=>$project,
        'DATAPHYRE_RUNTIME_APPLICATION'=>'_Runtime$Probe',
        'DATAPHYRE_RUNTIME_ENVIRONMENT'=>'preview-pr-123',
        'DATAPHYRE_RUNTIME_TICK_PUBLIC_KEY'=>str_repeat('A',43),
    ]);
    $t->processSucceeded($result);
    $t->same(['status'=>404,'body'=>''],$result->json());
})->tag('negative','signed-cadence');

test('status probe refuses noncanonical hosts and caller-selected arguments', static function(Context $t): void {
    $probe=dirname(__DIR__) . '/kernel/application_runtime_status_probe.php';
    $wrongHost=$t->phpProcess([$probe], environment:[
        'DATAPHYRE_RUNTIME_STATUS_HOST'=>'::1',
        'DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
    ]);
    $t->processFailed($wrongHost,64);
    $t->same('',$wrongHost->stdout());
    $t->same('',$wrongHost->stderr());

    $argument=$t->phpProcess([$probe,'--status-port=1'], environment:[
        'DATAPHYRE_RUNTIME_STATUS_HOST'=>'127.0.0.1',
        'DATAPHYRE_RUNTIME_STATUS_PORT'=>'8082',
    ]);
    $t->processFailed($argument,64);
    $t->same('',$argument->stdout());
    $t->same('',$argument->stderr());
})->tag('negative','probe');
