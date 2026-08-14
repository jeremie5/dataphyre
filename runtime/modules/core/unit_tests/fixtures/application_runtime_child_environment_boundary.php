<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)<2) exit(64);
require_once dirname(__DIR__,2).'/kernel/application_runtime_child_environment.php';

$mode=(string)$argv[1];
if($mode==='socket-pair'){
	if(!function_exists('posix_getrlimit') || !function_exists('posix_setrlimit') || !defined('POSIX_RLIMIT_NOFILE')) exit(69);
	$limits=posix_getrlimit();$soft=$limits['soft openfiles'] ?? null;$hard=$limits['hard openfiles'] ?? null;
	$descriptors=@scandir('/proc/self/fd');
	if(!is_int($soft) || !is_int($hard) || !is_array($descriptors)) exit(69);
	$open=array_map('intval',array_values(array_filter($descriptors,static fn(string $name): bool=>ctype_digit($name))));
	$newSoft=max($open)+1;
	if(!posix_setrlimit(POSIX_RLIMIT_NOFILE,$newSoft,$hard)) exit(69);
	$rejected=false;
	try{DataphyreApplicationRuntimeChildEnvironment::socketPair();}
	catch(RuntimeException $failure){$rejected=$failure->getMessage()==='Child environment socketpair is unavailable.';}
	finally{posix_setrlimit(POSIX_RLIMIT_NOFILE,$soft,$hard);}
	echo json_encode(['rejected'=>$rejected],JSON_THROW_ON_ERROR);exit($rejected ? 0 : 1);
}

if($mode==='ancestry'){
	try{DataphyreApplicationRuntimeChildEnvironment::target(getmypid(),posix_getppid());}
	catch(RuntimeException $failure){
		if($failure->getMessage()==='Child environment ancestry is invalid.'){
			echo 'ancestry-rejected';exit(0);
		}
		fwrite(STDERR,$failure->getMessage());exit(1);
	}
	fwrite(STDERR,'Deep ancestry was unexpectedly accepted.');exit(1);
}

if($mode==='wrap' && ($argc ?? 0)===7){
	$depth=(int)$argv[2];$coverageBootstrap=(string)$argv[3];$part=(string)$argv[4];
	$frameworkRoot=(string)$argv[5];$scanDirectory=(string)$argv[6];
	$command=$depth>0
		? [PHP_BINARY,__FILE__,'wrap',(string)($depth-1),$coverageBootstrap,$part,$frameworkRoot,$scanDirectory]
		: [PHP_BINARY,$coverageBootstrap,__FILE__,'ancestry'];
	$environment=getenv();$environment=is_array($environment) ? array_map('strval',$environment) : [];
	if($depth===0){
		$environment['DATAPHYRE_TEST_COVERAGE_PART']=$part;
		$environment['DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT']=$frameworkRoot;
		$environment['DATAPHYRE_TEST_COVERAGE_RESULT_ROOT']=$frameworkRoot;
		$environment['XDEBUG_MODE']='coverage';$environment['PHP_INI_SCAN_DIR']=$scanDirectory;
	}
	$pipes=[];$process=proc_open( // dataphyre-test-architecture: exempt[raw-process-control] reason="Deep Linux ancestry proof requires a deterministic chain of waiting direct parents."
		$command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$frameworkRoot,$environment,
		['bypass_shell'=>true,'suppress_errors'=>true],
	);
	if(!is_resource($process)) exit(69);
	$stdout=(string)stream_get_contents($pipes[1]);$stderr=(string)stream_get_contents($pipes[2]);
	fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
	echo $stdout;fwrite(STDERR,$stderr);exit($exit);
}

exit(64);
