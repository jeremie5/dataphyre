<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Interactive fixture: secrets arrive exclusively through the native broker. */
function tracelog(mixed ...$arguments): void {}
function pre_init_error(?string $message=null): never { throw new RuntimeException($message ?? 'Key unavailable.'); }
function dataphyre_internal_managed_runtime_bootstrap_context(): ?array {
	return DataphyreApplicationRuntimeChildEnvironment::managedBootstrapAttestation();
}
require_once dirname(__DIR__,2).'/kernel/application_runtime_child_environment.php';
try{
	$values=DataphyreApplicationRuntimeChildEnvironment::consumeInherited('realtime');
	define('ROOTPATH',['dataphyre'=>$values['DATAPHYRE_RUNTIME_PROJECT_ROOT'].'/']);
	define('DP_CORE_CFG',['private_key'=>json_decode($values['PROBE_KEYRING'],true,8,JSON_THROW_ON_ERROR)]);
	require_once dirname(__DIR__,2).'/kernel/helper_functions.php';
	require_once dirname(__DIR__,2).'/kernel/core_functions.php';
	$identity=DataphyreApplicationRuntimeChildEnvironment::processIdentity(getmypid());
	$guarded=false;
	try{DataphyreApplicationRuntimeChildEnvironment::managedBootstrapPrivateKeyForCore();}
	catch(RuntimeException){$guarded=true;}
	echo json_encode(['ready'=>true,'pid'=>getmypid(),'uid'=>$identity['uid'],
		'managed'=>dp_managed_runtime_bootstrap_context()['role']==='realtime','bootstrap_key_guarded'=>$guarded],JSON_THROW_ON_ERROR)."\n";
	fflush(STDOUT);
	while(($line=fgets(STDIN))!==false){
		$request=json_decode($line,true,16,JSON_THROW_ON_ERROR);
		try{
			$keys=dpvks();$active=count($keys)-1;
			if(($request['operation'] ?? '')==='write'){
				$plaintext=(string)$request['plaintext'];
				$response=['ok'=>true,'ciphertext'=>\dataphyre\core::encrypt_data($plaintext),
					'signature'=>hash_hmac('sha256',$plaintext,dpvk()),'slot'=>$active,
					'active_sha256'=>hash('sha256',dpvk())];
			}else{
				$slot=$request['slot'];$plaintext=\dataphyre\core::decrypt_data((string)$request['ciphertext']);
				$response=['ok'=>true,'plaintext'=>$plaintext,'verified'=>isset($keys[$slot])
					&& hash_equals((string)$request['signature'],hash_hmac('sha256',(string)$request['plaintext'],$keys[$slot]))];
			}
		}catch(RuntimeException){$response=['ok'=>false,'key_unavailable'=>true];}
		echo json_encode($response,JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
	}
}catch(Throwable $failure){fwrite(STDERR,get_class($failure)."\n");exit(78);}
