<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once \dirname(__DIR__).'/Framework/ApplicationEnvironmentIdentifier.php';
require_once \dirname(__DIR__).'/Framework/InternalApplicationBootstrapOnly.php';

/** Loads application realtime registrations through its ordinary framework bootstrap. */
final class DataphyreApplicationRuntimeRealtimeBootstrap {
	private static function swallowPreflightOutput(string $chunk): string {return '';}

	/** Re-attests the process-lifetime preflight output slot immediately before evidence. */
	public static function assertPreflightOutputBoundary(): void {
		self::assertPreflightCaller();
		\Dataphyre\InternalApplicationBootstrapOnly::context();
		if(!self::preservePreflightOutputBoundaryUnchecked()){
			throw new \RuntimeException('Realtime preflight output boundary was removed.');
		}
	}

	/** Best-effort repair used by the fixed entrypoint's terminal failure path. */
	public static function preservePreflightOutputBoundary(): bool {
		self::assertPreflightCaller();
		return self::preservePreflightOutputBoundaryUnchecked();
	}

	private static function preservePreflightOutputBoundaryUnchecked(): bool {
		$handler=self::class.'::swallowPreflightOutput';
		try{
			while(\ob_get_level()>1){if(!@\ob_end_clean()) break;}
			$handlers=\ob_list_handlers();
			if(\ob_get_level()===1 && ($handlers[0] ?? null)===$handler) return true;
			while(\ob_get_level()>0){if(!@\ob_end_clean()) break;}
			if(\ob_get_level()===0) @\ob_start([self::class,'swallowPreflightOutput']);
		}catch(\Throwable){}
		return false;
	}

	private static function assertPreflightCaller(): void {
		$expected=\realpath(__DIR__.'/application_release_preflight_realtime.php') ?: '';
		$self=\realpath(__FILE__) ?: __FILE__;
		foreach(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS,8) as $frame){
			$file=\realpath((string)($frame['file'] ?? '')) ?: '';
			if($file!=='' && !\hash_equals($self,$file)){
				if(\hash_equals($expected,$file)) return;
				break;
			}
		}
		throw new \RuntimeException('Realtime preflight output caller is invalid.');
	}

	/** @return array<string,array{authorize:callable,events:callable}> */
	public static function load(): array {
		$projectRoot=\realpath((string)(\getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: ''));
		$application=\trim((string)(\getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: ''));
		$environment=\trim((string)(\getenv('DATAPHYRE_RUNTIME_ENVIRONMENT') ?: ''));
		if($projectRoot===false || !\is_dir($projectRoot)
			|| \preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D', $application)!==1
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid($environment)){
			throw new RuntimeException('Realtime application context is invalid.');
		}
		$runtimeRoot=\dirname(__DIR__, 3);
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
		$preflight=(string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')==='realtime-preflight';
		if($preflight){
			if(!\putenv('DATAPHYRE_ENVIRONMENT='.$environment)) throw new RuntimeException('Realtime environment projection failed.');
			$_ENV['DATAPHYRE_ENVIRONMENT']=$environment;$_SERVER['DATAPHYRE_ENVIRONMENT']=$environment;
		}
		$bufferLevel=\ob_get_level();
		if($preflight && $bufferLevel!==0) throw new RuntimeException('Realtime preflight output boundary is unavailable.');
		\ob_start([self::class,'swallowPreflightOutput']);
		$guardLevel=$bufferLevel+1;
		try{
			if($preflight){
				\Dataphyre\InternalApplicationBootstrapOnly::bootRealtimePreflight(
					$projectRoot,$application,$environment,$bootstrap,
					static function() use ($bootstrap): void {require $bootstrap;},
				);
			}else require $bootstrap;
		}finally{
			if($preflight){
				if(!self::preservePreflightOutputBoundaryUnchecked()){
					throw new RuntimeException('Realtime preflight output boundary was removed.');
				}
			}else while(\ob_get_level()>$bufferLevel){\ob_end_clean();}
		}
		return \dataphyre\realtime::runtimeRoutes();
	}
}
