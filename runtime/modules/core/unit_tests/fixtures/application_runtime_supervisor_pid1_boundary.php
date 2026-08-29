<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$kernel=realpath((string)($argv[1] ?? ''));
if(getmypid()!==1 || !is_string($kernel) || !is_dir($kernel) || !is_executable('/usr/bin/setpriv')) exit(64);
require_once $kernel.'/application_runtime_supervisor.php';

$pipes=[];
$process=proc_open([ // dataphyre-test-architecture: exempt[raw-process-control] reason="PID-one privilege proof must create and inspect the exact native child process."
	'/usr/bin/setpriv','--reuid=10001','--regid=10001','--groups=10001','--no-new-privs',
	'--inh-caps=-all','--ambient-caps=-all','--bounding-set=-all','--pdeathsig=SIGKILL',
	'/bin/sleep','5',
],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes,null,null,[
	'bypass_shell'=>true,'suppress_errors'=>true,
]);
if(!is_resource($process)) exit(70);
$status=proc_get_status($process);$pid=is_array($status) ? (int)($status['pid'] ?? 0) : 0;
$rejected=false;$message='';
try{
	dataphyre_runtime_pool_identity($pid,'1','web','127.0.0.1',8083);
}catch(RuntimeException $failure){$rejected=true;$message=$failure->getMessage();}
@posix_kill($pid,SIGTERM);proc_close($process);
echo json_encode(['rejected'=>$rejected,'message'=>$message],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($rejected && $message==='Runtime pool privilege boundary is invalid' ? 0 : 70);
