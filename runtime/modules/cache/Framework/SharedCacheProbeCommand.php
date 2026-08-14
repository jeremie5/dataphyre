<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Cache;

use Dataphyre\ApplicationEnvironmentIdentifier;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__,2).'/core/Framework/ApplicationEnvironmentIdentifier.php';
require_once dirname(__DIR__).'/kernel/cache.main.php';

/** Fixed three-process proof for Dataphyre's optional shared cache backend. */
final class SharedCacheProbeCommand {
	public const CONTRACT='dataphyre.shared_cache_probe.v1';
	public const EXIT_SUCCESS=0;
	public const EXIT_USAGE=64;
	public const EXIT_DEPENDENCY=69;
	public const EXIT_CONFIGURATION=78;
	public const MAX_OUTPUT_BYTES=4096;
	public const TTL_SECONDS=120;
	private const MINIMUM_MEMCACHED_EXTENSION_VERSION='3.4.0';

	/** @param list<string> $arguments @param array<string,mixed> $runtime */
	public static function main(array $arguments,array $runtime=[]): int {
		$writeOut=$runtime['write_out'] ?? static fn(string $value): int|false=>fwrite(STDOUT,$value);
		$writeError=$runtime['write_error'] ?? static fn(string $value): int|false=>fwrite(STDERR,$value);
		if(!in_array((string)($runtime['sapi'] ?? PHP_SAPI),['cli','phpdbg'],true)){
			return self::failure($writeError,self::EXIT_USAGE,'invalid_runtime',
				'The shared cache probe is available only through the CLI.');
		}
		try{$options=self::options($arguments);}
		catch(Throwable){
			return self::failure($writeError,self::EXIT_USAGE,'invalid_invocation',
				'Use only the fixed shared cache probe phase and challenge options.');
		}
		try{
			$identity=self::identity($runtime);
			$probe=self::probeIdentity($identity,$options['challenge']);
			$extension=$runtime['extension_version'] ?? static fn(): string|false=>phpversion('memcached');
			$version=is_callable($extension) ? $extension() : false;
			if(!is_string($version) || version_compare($version,self::MINIMUM_MEMCACHED_EXTENSION_VERSION,'<')){
				return self::failure($writeError,self::EXIT_DEPENDENCY,'shared_cache_unavailable',
					'The fixed shared cache capability is unavailable.',self::context($options['phase'],$probe));
			}
			$declared=$runtime['declared_shared'] ?? [self::class,'declaredSharedBackend'];
			if(!is_callable($declared)) throw new RuntimeException('Shared cache declaration boundary is unavailable.');
			$sharedDeclaration=$declared($runtime)===true;
		}catch(Throwable){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'runtime_configuration_invalid',
				'The fixed shared cache probe runtime configuration is invalid.');
		}

		if($options['phase']==='detect'){
			self::writeJson($writeOut,[
				'backend'=>'memcached','contract'=>self::CONTRACT,'contract_version'=>1,
				'exit_status'=>self::EXIT_SUCCESS,'ok'=>true,'phase'=>'detect',
				'probe_sha256'=>$probe['probe_sha256'],'shared'=>$sharedDeclaration,
			]);
			return self::EXIT_SUCCESS;
		}
		if(!$sharedDeclaration){
			return self::failure($writeError,self::EXIT_DEPENDENCY,'shared_cache_unavailable',
				'The fixed shared cache capability is unavailable.',self::context($options['phase'],$probe));
		}

		$isShared=$runtime['is_shared'] ?? static fn(): bool=>\dataphyre\cache::isShared();
		$get=$runtime['get'] ?? static fn(string $key): mixed=>\dataphyre\cache::get($key);
		$set=$runtime['set'] ?? static fn(string $key,string $value,int $ttl): bool=>\dataphyre\cache::set($key,$value,$ttl);
		$delete=$runtime['delete'] ?? static fn(string $key): bool=>\dataphyre\cache::delete($key);
		if(!is_callable($isShared) || !is_callable($get) || !is_callable($set) || !is_callable($delete)){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'runtime_configuration_invalid',
				'The fixed shared cache probe runtime configuration is invalid.');
		}
		try{
			if($isShared()!==true) throw new RuntimeException('Shared cache is unavailable.');
			if($options['phase']==='write'){
				$stored=$set($probe['key'],$probe['value'],self::TTL_SECONDS)===true;
				if(!$stored || $isShared()!==true) throw new RuntimeException('Shared cache write failed.');
				self::writeJson($writeOut,[
					'backend'=>'memcached','contract'=>self::CONTRACT,'contract_version'=>1,
					'exit_status'=>self::EXIT_SUCCESS,'ok'=>true,'phase'=>'write',
					'probe_sha256'=>$probe['probe_sha256'],'shared'=>true,'stored'=>true,
					'ttl_seconds'=>self::TTL_SECONDS,
				]);
				return self::EXIT_SUCCESS;
			}
			$value=$get($probe['key']);
			$sharedAfterRead=$isShared()===true;
			$matched=is_string($value) && hash_equals($probe['value'],$value);
			$deleted=$delete($probe['key'])===true;
			$sharedAfterDelete=$isShared()===true;
			$missingAfterDelete=$get($probe['key'])===null;
			$sharedAfterMiss=$isShared()===true;
			if(!$sharedAfterRead || !$matched || !$deleted || !$sharedAfterDelete
				|| !$missingAfterDelete || !$sharedAfterMiss){
				throw new RuntimeException('Shared cache cross-process proof failed.');
			}
			self::writeJson($writeOut,[
				'backend'=>'memcached','contract'=>self::CONTRACT,'contract_version'=>1,
				'deleted'=>true,'exit_status'=>self::EXIT_SUCCESS,'matched'=>true,
				'missing_after_delete'=>true,'ok'=>true,'phase'=>'read-delete',
				'probe_sha256'=>$probe['probe_sha256'],'shared'=>true,
			]);
			return self::EXIT_SUCCESS;
		}catch(Throwable){
			return self::failure($writeError,self::EXIT_DEPENDENCY,'shared_cache_proof_failed',
				'The fixed cross-process shared cache proof failed.',self::context($options['phase'],$probe));
		}
	}

	/** @param list<string> $arguments @return array{phase:string,challenge:string} */
	private static function options(array $arguments): array {
		$options=['phase'=>null,'challenge'=>null];$seen=[];
		foreach(array_slice($arguments,1) as $argument){
			if(preg_match('/^--(phase|challenge)=(.*)$/D',(string)$argument,$match)!==1 || isset($seen[$match[1]])){
				throw new InvalidArgumentException('Invalid shared cache probe option.');
			}
			$seen[$match[1]]=true;$options[$match[1]]=$match[2];
		}
		if(!in_array($options['phase'],['detect','write','read-delete'],true)
			|| !is_string($options['challenge']) || preg_match('/^[a-f0-9]{64}$/D',$options['challenge'])!==1){
			throw new InvalidArgumentException('Shared cache probe options are invalid.');
		}
		return ['phase'=>$options['phase'],'challenge'=>$options['challenge']];
	}

	/** @param array<string,mixed> $runtime @return array{cloud_application:string,framework_application:string,environment:string,release_id:string} */
	private static function identity(array $runtime): array {
		$values=$runtime['environment_values'] ?? null;
		$read=static fn(string $name): mixed=>is_array($values) ? ($values[$name] ?? null) : getenv($name);
		$identity=[
			'cloud_application'=>$read('DATAPHYRE_APPLICATION_ID'),
			'framework_application'=>$read('DATAPHYRE_FRAMEWORK_APPLICATION'),
			'environment'=>$read('DATAPHYRE_ENVIRONMENT'),
			'release_id'=>$read('DATAPHYRE_APPLICATION_RELEASE'),
		];
		if(!is_string($identity['cloud_application'])
			|| preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D',$identity['cloud_application'])!==1
			|| !is_string($identity['framework_application'])
			|| preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$identity['framework_application'])!==1
			|| !is_string($identity['environment']) || !ApplicationEnvironmentIdentifier::valid($identity['environment'])
			|| !is_string($identity['release_id']) || preg_match('/^dep_[a-f0-9]{40}$/D',$identity['release_id'])!==1){
			throw new RuntimeException('Shared cache probe identity is invalid.');
		}
		return $identity;
	}

	/** @param array<string,mixed> $runtime */
	private static function declaredSharedBackend(array $runtime): bool {
		$values=$runtime['environment_values'] ?? null;
		$read=static fn(string $name): mixed=>is_array($values) ? ($values[$name] ?? null) : getenv($name);
		$host=$read('DATAPHYRE_CACHE_MEMCACHED_HOST');
		if(!is_string($host) || trim($host)==='') $host=$read('MEMCACHED_HOST');
		if((!is_string($host) || trim($host)==='') && defined('DP_CACHE_CFG') && is_array(DP_CACHE_CFG)){
			$config=is_array(DP_CACHE_CFG['memcached'] ?? null) ? DP_CACHE_CFG['memcached'] : DP_CACHE_CFG;
			$host=$config['host'] ?? null;
		}
		if(!is_string($host) || trim($host)==='') return false;
		$host=trim($host);
		if(strlen($host)>255 || preg_match('/[\x00-\x20\x7f]/D',$host)===1) throw new RuntimeException('Cache host is invalid.');
		$port=$read('DATAPHYRE_CACHE_MEMCACHED_PORT');
		if(!is_string($port) || trim($port)==='') $port=$read('MEMCACHED_PORT');
		if(is_string($port) && trim($port)!=='' && filter_var($port,FILTER_VALIDATE_INT,[
			'options'=>['min_range'=>1,'max_range'=>65535],
		])===false) throw new RuntimeException('Cache port is invalid.');
		return !in_array(strtolower($host),['127.0.0.1','localhost','::1'],true);
	}

	/** @param array<string,string> $identity @return array{key:string,value:string,probe_sha256:string} */
	private static function probeIdentity(array $identity,string $challenge): array {
		$encoded=json_encode([...$identity,'challenge'=>$challenge],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
		return [
			'key'=>'dataphyre:shared-cache-probe:v1:'.hash('sha256',"key\0".$encoded),
			'value'=>'sha256:'.hash('sha256',"value\0".$encoded),
			'probe_sha256'=>'sha256:'.hash('sha256',"evidence\0".$encoded),
		];
	}

	/** @param array{probe_sha256:string} $probe @return array{phase:string,probe_sha256:string} */
	private static function context(string $phase,array $probe): array {
		return ['phase'=>$phase,'probe_sha256'=>$probe['probe_sha256']];
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $context */
	private static function failure(callable $write,int $status,string $code,string $message,array $context=[]): int {
		self::writeJson($write,[...$context,'contract'=>self::CONTRACT,'contract_version'=>1,
			'error'=>['code'=>$code,'message'=>$message],'exit_status'=>$status,'ok'=>false]);
		return $status;
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $payload */
	private static function writeJson(callable $write,array $payload): void {
		$json=json_encode(self::canonicalize($payload),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
		if(strlen($json)>self::MAX_OUTPUT_BYTES) throw new RuntimeException('Shared cache probe output exceeded its fixed bound.');
		$write($json);
	}

	private static function canonicalize(mixed $value): mixed {
		if(!is_array($value)) return $value;
		if(array_is_list($value)) return array_map(self::canonicalize(...),$value);
		ksort($value,SORT_STRING);
		foreach($value as $key=>$item) $value[$key]=self::canonicalize($item);
		return $value;
	}
}
