<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==3) exit(64);
[$script,$kernel,$projectRoot]=$argv;
require_once $kernel.'/application_runtime_process_broker.php';
try{
	DataphyreApplicationRuntimeProcessBroker::spawn(
		['/bin/true'],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],
		$projectRoot,[],'one-shot',[],1000,
	);
	echo '{"rejected":false}';
}catch(Throwable $failure){
	echo json_encode(['rejected'=>true,'message'=>$failure->getMessage()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
}
