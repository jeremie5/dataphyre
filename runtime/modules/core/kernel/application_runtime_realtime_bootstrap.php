<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';

/** Loads application realtime registrations through its ordinary framework bootstrap. */
final class DataphyreApplicationRuntimeRealtimeBootstrap {
	/** @return array<string,array{authorize:callable,events:callable}> */
	public static function load(): array {
		$projectRoot=realpath((string)(getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: ''));
		$application=trim((string)(getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: ''));
		$environment=trim((string)(getenv('DATAPHYRE_RUNTIME_ENVIRONMENT') ?: ''));
		if($projectRoot===false || !is_dir($projectRoot)
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D', $application)!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)){
			throw new RuntimeException('Realtime application context is invalid.');
		}
		$runtimeRoot=dirname(__DIR__, 3);
		$bootstrap=$runtimeRoot.'/bootstrap.php';
		$realtime=__DIR__.'/realtime.php';
		require_once $realtime;
		$projectedServer=$_SERVER;
		$_SERVER=[
			'REQUEST_METHOD'=>'GET',
			'REQUEST_URI'=>'/dataphyre/runtime/realtime/bootstrap',
			'SCRIPT_FILENAME'=>$bootstrap,
			'SERVER_PROTOCOL'=>'HTTP/1.1',
			'SERVER_ADDR'=>'127.0.0.1',
			'SERVER_NAME'=>'127.0.0.1',
			'SERVER_PORT'=>'8080',
			'HTTP_HOST'=>'127.0.0.1',
			'REMOTE_ADDR'=>'127.0.0.1',
			'DATAPHYRE_PROJECT_ROOT'=>$projectRoot,
			'DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP'=>'1',
			'HTTP_X_DATAPHYRE_APPLICATION'=>$application,
			'HTTP_X_DATAPHYRE_ENVIRONMENT'=>$environment,
			'HTTP_X_TRAFFIC_SOURCE'=>'internal_traffic',
		]+$projectedServer;
		$_GET=[];
		$_POST=[];
		$_COOKIE=[];
		$_FILES=[];
		$_REQUEST=[];
		$bufferLevel=ob_get_level();
		ob_start();
		try{
			require $bootstrap;
		}finally{
			while(ob_get_level()>$bufferLevel){
				ob_end_clean();
			}
		}
		return \dataphyre\realtime::runtimeRoutes();
	}
}
