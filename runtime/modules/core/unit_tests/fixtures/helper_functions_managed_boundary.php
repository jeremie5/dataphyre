<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

$mode=(string)($argv[1] ?? '');

function dataphyre_internal_managed_runtime_bootstrap_context(): array {
	return [
		'contract'=>'dataphyre.application_runtime_bootstrap.v1',
		'role'=>'web',
		'project_root'=>__DIR__,
		'sapi'=>'cli',
	];
}

define('DP_CORE_CFG',['private_key'=>$mode==='malformed-keyring' ? ['valid',''] : []]);

require_once (string)($argv[2] ?? '');

$result=false;
if($mode==='write-suppression'){
	dp_modcache_save_if_changed(['core'=>false]);
	$result=dp_write_module_config_defaults('managed', ['value'=>true])===false;
}elseif($mode==='missing-keyring' || $mode==='malformed-keyring'){
	try{
		dpvks();
	}catch(RuntimeException){
		$result=true;
	}
}

echo json_encode(['rejected_or_suppressed'=>$result], JSON_THROW_ON_ERROR);
