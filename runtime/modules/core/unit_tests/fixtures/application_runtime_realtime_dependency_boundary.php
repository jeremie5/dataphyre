<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
$mode=(string)($argv[2] ?? 'missing');
if(!is_string($kernel)) exit(64);
require_once $kernel.'/application_runtime_realtime_server.php';
require_once dirname($kernel,3).'/modules/testing/tooling/bootstrap.php';

$method=(new \Dataphyre\Test\Context('realtime dependency boundary'))
	->nonPublic(DataphyreApplicationRuntimeRealtimeServer::class);
$fast=$method->invoke('runBoundedCallback',static fn(): string=>'fast',[],1.0);
$slowRejected=false;
if($mode==='missing'){
	try{$method->invoke('runBoundedCallback',static function(): string {usleep(20000);return 'slow';},[],0.001);}
	catch(RuntimeException){$slowRejected=true;}
}
putenv('DATAPHYRE_RUNTIME_POOL=realtime');
putenv('DATAPHYRE_RUNTIME_REALTIME_HOST=0.0.0.0');
putenv('DATAPHYRE_RUNTIME_REALTIME_PORT=8080');
putenv('DATAPHYRE_RUNTIME_WEB_HOST=127.0.0.1');
putenv('DATAPHYRE_RUNTIME_WEB_PORT=8083');
$main=$mode==='missing' ? DataphyreApplicationRuntimeRealtimeServer::main() : null;
echo json_encode(compact('fast','slowRejected','main'),JSON_THROW_ON_ERROR),"\n";
