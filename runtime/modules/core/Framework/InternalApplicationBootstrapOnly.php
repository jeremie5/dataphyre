<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre;
use RuntimeException;
require_once __DIR__.'/ApplicationEnvironmentIdentifier.php';
final class InternalApplicationBootstrapOnly
{
	public const CONTRACT='dataphyre.internal_application_bootstrap_only.v1';
	public const MATERIALIZER='registered-table-materialization';
	public const PREFLIGHT='release-preflight-registration';
	private const STATE='DATAPHYRE_INTERNAL_APPLICATION_BOOTSTRAP_ONLY';
	private static ?string $privateKey=null;

	public static function bootMaterializer(string $projectRoot,string $application,string $environment,
		string $runtimeBootstrap,callable $bootstrap): void {
		self::assertCaller(self::sqlCommand());
		$identity=self::identity($projectRoot,$application,$environment,$runtimeBootstrap);
		$entrypoint=self::fixed(self::modulesRoot().'/sql/kernel/materialize_registered_tables.php');
		$pool=(string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '');
		$role=(string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '');
		if($pool==='' && $role===''){
			if(!\hash_equals($entrypoint,self::firstIncluded())) throw new RuntimeException('Materializer entrypoint is invalid.');
			$transport='direct-cli-entrypoint';
		}elseif($pool==='one-shot' && $role==='one-shot'){
			self::assertBrokeredMaterializer($identity,$entrypoint);
			$transport='brokered-one-shot';
		}else throw new RuntimeException('Materializer runtime role is invalid.');
		self::activate(['contract'=>self::CONTRACT,'purpose'=>self::MATERIALIZER,'transport'=>$transport,
			...$identity,'entrypoint'=>$entrypoint],$bootstrap);
	}

	public static function bootRealtimePreflight(string $projectRoot,string $application,string $environment,
		string $runtimeBootstrap,callable $bootstrap): void {
		self::assertCaller(self::realtimeBootstrap());
		$identity=self::identity($projectRoot,$application,$environment,$runtimeBootstrap);
		$entrypoint=self::preflightEntrypoint();
		self::assertPreflight($identity,$entrypoint);
		self::activate(['contract'=>self::CONTRACT,'purpose'=>self::PREFLIGHT,'transport'=>'fixed-preflight-entrypoint',
			...$identity,'entrypoint'=>$entrypoint],$bootstrap);
	}

	public static function sealOrdinaryRuntime(): void {
		self::assertCaller(self::runtimeBootstrap());
		if(!\defined(self::STATE)){
			if(self::$privateKey!==null || !\define(self::STATE,false)){
				throw new RuntimeException('Runtime bootstrap seal failed.');
			}
			return;
		}
		self::context();
	}

	public static function sealNonMaterializerOneShot(string $operation): void {
		self::assertCaller(self::fixed(\dirname(__DIR__).'/kernel/application_runtime_one_shot_worker.php'));
		if($operation==='' || \defined(self::STATE)
			|| self::$privateKey!==null || !\define(self::STATE,false)){
			throw new RuntimeException('One-shot runtime bootstrap seal failed.');
		}
	}

	public static function context(): ?array {
		if(!\defined(self::STATE)) throw new RuntimeException('Application bootstrap boundary is unsealed.');
		$context=\constant(self::STATE);
		if($context===false){
			if(self::$privateKey!==null){
				throw new RuntimeException('Application bootstrap boundary is inconsistent.');
			}
			return null;
		}
		return self::validatedContext();
	}

	public static function materializer(): bool {return (self::context()['purpose'] ?? null)===self::MATERIALIZER;}

	public static function privateKey(): string {
		self::assertCaller(self::fixed(\dirname(__DIR__).'/kernel/helper_functions.php'));
		if(self::context()===null || !\is_string(self::$privateKey) || \strlen(self::$privateKey)!==32){
			throw new RuntimeException('Bootstrap-only private key is unavailable.');
		}
		return self::$privateKey;
	}

	private static function activate(array $context,callable $bootstrap): void {
		self::assertCaller(match($context['purpose'] ?? null){
			self::MATERIALIZER=>self::sqlCommand(),self::PREFLIGHT=>self::realtimeBootstrap(),
			default=>throw new RuntimeException('Application bootstrap purpose is invalid.'),
		});
		if(\defined(self::STATE) || self::$privateKey!==null){
			throw new RuntimeException('Application bootstrap boundary is already sealed.');
		}
		self::assertImageIntegrity();
		self::$privateKey=\random_bytes(32);
		$context['preflight_state_root']=$context['purpose']===self::PREFLIGHT ? self::preflightStateRoot() : '';
		$context['preflight_state_root_handle']=null;
		if($context['purpose']===self::PREFLIGHT){
			$handle=@\fopen($context['preflight_state_root'],'r');
			if(!\is_resource($handle)) throw new RuntimeException('Release-preflight state root cannot be anchored.');
			$context['preflight_state_root_handle']=$handle;
			$context['preflight_state_root_identity_sha256']=self::preflightStateIdentity(
				$context['preflight_state_root'],$handle,
			);
		}else $context['preflight_state_root_identity_sha256']=\hash('sha256','');
		$context['transport_binding_sha256']=self::transportBinding($context);
		$context['private_key_sha256']=\hash('sha256',self::$privateKey);
		if(!\define(self::STATE,$context)) throw new RuntimeException('Application bootstrap activation failed.');
		self::validatedContext();
		$bootstrap();
		self::validatedContext();
	}

	/** @return array<string,mixed> */
	private static function validatedContext(): array {
		if(!\defined(self::STATE)) throw new RuntimeException('Application bootstrap boundary is unsealed.');
		$context=\constant(self::STATE);
		self::assertImageIntegrity();
		if(!\is_array($context) || \array_keys($context)!==[
			'contract','purpose','transport','project_root','application','environment','runtime_bootstrap','entrypoint',
			'preflight_state_root','preflight_state_root_handle','preflight_state_root_identity_sha256',
			'transport_binding_sha256','private_key_sha256',
		] || $context['contract']!==self::CONTRACT
			|| !\in_array($context['purpose'],[self::MATERIALIZER,self::PREFLIGHT],true)
			|| !\is_string($context['transport']) || !\is_string($context['project_root'])
			|| !\is_string($context['application']) || !\is_string($context['environment'])
			|| !\is_string($context['runtime_bootstrap']) || !\is_string($context['entrypoint'])
			|| !\is_string($context['preflight_state_root'])
			|| !self::sha256($context['preflight_state_root_identity_sha256'])
			|| !self::sha256($context['transport_binding_sha256']) || !self::sha256($context['private_key_sha256'])
			|| !\is_string(self::$privateKey) || \strlen(self::$privateKey)!==32
			|| !\hash_equals($context['private_key_sha256'],\hash('sha256',self::$privateKey))
			|| !\in_array(\PHP_SAPI,['cli','phpdbg'],true)
			|| !\hash_equals($context['runtime_bootstrap'],self::runtimeBootstrap())
			|| !\hash_equals($context['project_root'],self::projectRoot())
			|| !\hash_equals($context['application'],(string)($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'] ?? ''))
			|| !\hash_equals($context['environment'],(string)(\getenv('DATAPHYRE_ENVIRONMENT') ?: ''))
			|| !\hash_equals($context['environment'],(string)($_ENV['DATAPHYRE_ENVIRONMENT'] ?? ''))
			|| !\hash_equals($context['environment'],(string)($_SERVER['DATAPHYRE_ENVIRONMENT'] ?? ''))
			|| $_GET!==[] || $_POST!==[] || $_COOKIE!==[] || $_FILES!==[] || $_REQUEST!==[]
			|| (\defined('APP') && !\hash_equals($context['application'],(string)\APP))){
			throw new RuntimeException('Application bootstrap identity changed.');
		}
		if(!\hash_equals($context['transport_binding_sha256'],self::transportBinding($context))){
			throw new RuntimeException('Application bootstrap transport changed.');
		}
		if($context['purpose']===self::MATERIALIZER){
			if(!\hash_equals($context['preflight_state_root'],'')
				|| $context['preflight_state_root_handle']!==null
				|| !\hash_equals($context['preflight_state_root_identity_sha256'],\hash('sha256',''))
				|| !\in_array($context['transport'],['direct-cli-entrypoint','brokered-one-shot'],true)
				|| (string)($_SERVER['REQUEST_METHOD'] ?? '')!=='CLI'
				|| (string)($_SERVER['REQUEST_URI'] ?? '')!==''
				|| (string)($_SERVER['HTTP_X_TRAFFIC_SOURCE'] ?? '')!=='internal_traffic'
				|| !self::materializerRequestSurface()
				|| !self::exactEntrypoint($context['entrypoint'])
				|| ($context['transport']==='direct-cli-entrypoint'
					? ((string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!==''
						|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!==''
						|| !\hash_equals($context['entrypoint'],self::firstIncluded()))
					: ((string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='one-shot'
						|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='one-shot'))){
				throw new RuntimeException('Materializer execution identity changed.');
			}
			if($context['transport']==='brokered-one-shot') self::assertBrokeredMaterializer($context,$context['entrypoint']);
		}elseif($context['transport']==='fixed-preflight-entrypoint'){
			if(!\is_resource($context['preflight_state_root_handle'])
				|| !\hash_equals(
					$context['preflight_state_root_identity_sha256'],
					self::preflightStateIdentity($context['preflight_state_root'],$context['preflight_state_root_handle']),
				)
				|| !self::preflightRequestSurface()){
				throw new RuntimeException('Release-preflight request identity changed.');
			}
			self::assertPreflight($context,$context['entrypoint']);
		}else throw new RuntimeException('Release-preflight transport changed.');
		return $context;
	}

	/** @return array<string,mixed> */
	private static function assertBrokeredMaterializer(array $identity,string $entrypoint): array {
		if(!\class_exists('DataphyreApplicationRuntimeChildEnvironment',false)) throw new RuntimeException('Materializer attestation is unavailable.');
		$proof=\DataphyreApplicationRuntimeChildEnvironment::oneShotMaterializerBootstrapAttestation();
		if(!\is_array($proof) || \array_keys($proof)!==[
			'contract','role','operation','target','project_root_raw','project_root','application','environment','release_id','argv0','sapi',
		] || ($proof['contract'] ?? null)!=='dataphyre.one_shot_materializer_bootstrap.v1'
			|| ($proof['role'] ?? null)!=='one-shot' || ($proof['operation'] ?? null)!=='dataphyre_materialize_tables'
			|| !\hash_equals($entrypoint,(string)($proof['target'] ?? ''))
			|| !\hash_equals($entrypoint,(string)($proof['argv0'] ?? ''))
			|| !\hash_equals($identity['project_root'],(string)($proof['project_root_raw'] ?? ''))
			|| !\hash_equals($identity['project_root'],(string)($proof['project_root'] ?? ''))
			|| !\hash_equals($identity['application'],(string)($proof['application'] ?? ''))
			|| !\hash_equals($identity['environment'],(string)($proof['environment'] ?? ''))
			|| ($proof['sapi'] ?? null)!==\PHP_SAPI
			|| \preg_match('/^dep_[a-f0-9]{40}$/D',(string)($proof['release_id'] ?? ''))!==1){
			throw new RuntimeException('Materializer attestation does not match its application.');
		}
		return $proof;
	}

	private static function assertPreflight(array $identity,string $entrypoint): void {
		$proof=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT'] ?? null;
		$statePath=\is_array($proof) ? (string)($proof['state_root'] ?? '') : '';
		$stateRoot=\realpath($statePath);
		$statePresent=\is_string($stateRoot);
		if(!self::exactPreflightEntrypoint($identity,$entrypoint) || !\hash_equals($entrypoint,self::firstIncluded())
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '')!=='realtime-preflight'
			|| (string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: '')!=='realtime-preflight'
			|| (string)(\getenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE') ?: '')!=='record_only'
			|| (string)(\getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '')!==$identity['project_root']
			|| (string)(\getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: '')!==$identity['application']
			|| (string)(\getenv('DATAPHYRE_RUNTIME_ENVIRONMENT') ?: '')!==$identity['environment']
			|| !\hash_equals($identity['project_root'],self::processCwd())
			|| !\is_array($proof) || $statePath==='' || \is_link($statePath)
			|| !\hash_equals($statePath,(string)(\getenv('DATAPHYRE_SCHEDULER_STATE_ROOT') ?: ''))
			|| !\hash_equals($identity['project_root'],(string)($proof['project_root'] ?? ''))
			|| \preg_match('/^[a-f0-9]{64}$/D',(string)($proof['private_key'] ?? ''))!==1
			|| \preg_match('/^[a-f0-9]{64}$/D',(string)($proof['token'] ?? ''))!==1){
			throw new RuntimeException('Release-preflight bootstrap identity is invalid.');
		}
		if(\array_key_exists('preflight_state_root',$identity)
			&& !\hash_equals($identity['preflight_state_root'],$statePath)){
			throw new RuntimeException('Release-preflight state root changed.');
		}
		if($statePresent){
			if(!\hash_equals($statePath,$stateRoot)){
				throw new RuntimeException('Release-preflight state root changed.');
			}
			if(\array_key_exists('preflight_state_root_identity_sha256',$identity)
				&& !\hash_equals($identity['preflight_state_root_identity_sha256'],self::preflightStateIdentity(
					$statePath,$identity['preflight_state_root_handle'] ?? null,
				))){
				throw new RuntimeException('Release-preflight state root identity changed.');
			}
		}else{
			throw new RuntimeException('Release-preflight state root is unavailable.');
		}
	}

	private static function assertImageIntegrity(): void {
		if(\trim((string)\ini_get('auto_prepend_file'))!=='' || \trim((string)\ini_get('auto_append_file'))!==''){
			throw new RuntimeException('Application bootstrap image integrity is unavailable.');
		}
		foreach(['uopz','runkit7','ffi'] as $extension){
			if(\extension_loaded($extension)) throw new RuntimeException('Application bootstrap image integrity is unavailable.');
		}
	}

	/** @param array<string,mixed> $context */
	private static function transportBinding(array $context): string {
		$proof=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT'] ?? null;
		$privateKey=\is_array($proof) ? (string)($proof['private_key'] ?? '') : '';
		$token=\is_array($proof) ? (string)($proof['token'] ?? '') : '';
		$runtimeProject=(string)(\getenv('DATAPHYRE_RUNTIME_PROJECT_ROOT') ?: '');
		$brokerProof=[];
		if(($context['transport'] ?? null)==='brokered-one-shot'){
			$brokerProof=self::assertBrokeredMaterializer($context,(string)($context['entrypoint'] ?? ''));
		}
		$binding=[
			'contract'=>(string)($context['contract'] ?? ''),'purpose'=>(string)($context['purpose'] ?? ''),
			'transport'=>(string)($context['transport'] ?? ''),'project_root'=>(string)($context['project_root'] ?? ''),
			'application'=>(string)($context['application'] ?? ''),'environment'=>(string)($context['environment'] ?? ''),
			'runtime_bootstrap'=>(string)($context['runtime_bootstrap'] ?? ''),'entrypoint'=>(string)($context['entrypoint'] ?? ''),
			'preflight_state_root'=>(string)($context['preflight_state_root'] ?? ''),
			'preflight_state_root_identity_sha256'=>(string)($context['preflight_state_root_identity_sha256'] ?? ''),
			'preflight_state_root_handle_id'=>\is_resource($context['preflight_state_root_handle'] ?? null)
				? \get_resource_id($context['preflight_state_root_handle']) : 0,
			'pool'=>(string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: ''),'pool_role'=>(string)(\getenv('DATAPHYRE_RUNTIME_POOL_ROLE') ?: ''),
			'runtime_project_root_raw'=>$runtimeProject,'runtime_project_root_real'=>$runtimeProject==='' ? '' : (string)(\realpath($runtimeProject) ?: ''),
			'runtime_application'=>(string)(\getenv('DATAPHYRE_RUNTIME_APPLICATION') ?: ''),
			'framework_application'=>(string)(\getenv('DATAPHYRE_FRAMEWORK_APPLICATION') ?: ''),
			'runtime_environment'=>(string)(\getenv('DATAPHYRE_RUNTIME_ENVIRONMENT') ?: ''),
			'application_release'=>(string)(\getenv('DATAPHYRE_APPLICATION_RELEASE') ?: ''),
			'scheduler_activation_mode'=>(string)(\getenv('DATAPHYRE_SCHEDULER_ACTIVATION_MODE') ?: ''),
			'script_filename_raw'=>self::scriptFilenameRaw(),'script_filename_real'=>self::scriptFilename(),
			'server_argv0_raw'=>self::serverArgumentRaw(),'server_argv0_real'=>self::serverArgumentFilename(),
			'global_argv0_raw'=>self::globalArgumentRaw(),'global_argv0_real'=>self::globalArgumentFilename(),
			'first_included'=>self::firstIncluded(),
			'process_cwd_real'=>($context['purpose'] ?? null)===self::PREFLIGHT ? self::processCwd() : '',
			'request_surface_sha256'=>\hash('sha256',\json_encode(self::requestSurface(),\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR)),
			'preflight_state_root_raw'=>\is_array($proof) ? (string)($proof['state_root'] ?? '') : '',
			'preflight_project_root'=>\is_array($proof) ? (string)($proof['project_root'] ?? '') : '',
			'preflight_private_key_sha256'=>$privateKey==='' ? '' : \hash('sha256',$privateKey),
			'preflight_token_sha256'=>$token==='' ? '' : \hash('sha256',$token),
			'broker_attestation_sha256'=>$brokerProof===[] ? '' : \hash('sha256',\json_encode($brokerProof,\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR)),
		];
		return \hash('sha256',\json_encode($binding,\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR));
	}

	/** @return array<string,string|bool> */
	private static function requestSurface(): array {
		$surface=[];
		foreach([
			'REQUEST_METHOD','REQUEST_URI','HTTP_X_TRAFFIC_SOURCE','HTTP_X_DATAPHYRE_ENVIRONMENT',
			'DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP','SERVER_PROTOCOL','SERVER_ADDR','SERVER_NAME','SERVER_PORT','HTTP_HOST','REMOTE_ADDR',
		] as $name) $surface[$name]=(string)($_SERVER[$name] ?? '');
		$surface['GET_EMPTY']=$_GET===[];$surface['POST_EMPTY']=$_POST===[];$surface['COOKIE_EMPTY']=$_COOKIE===[];
		$surface['FILES_EMPTY']=$_FILES===[];$surface['REQUEST_EMPTY']=$_REQUEST===[];
		return $surface;
	}

	private static function materializerRequestSurface(): bool {
		return self::requestSurface()=== [
			'REQUEST_METHOD'=>'CLI','REQUEST_URI'=>'','HTTP_X_TRAFFIC_SOURCE'=>'internal_traffic',
			'HTTP_X_DATAPHYRE_ENVIRONMENT'=>'','DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP'=>'',
			'SERVER_PROTOCOL'=>'','SERVER_ADDR'=>'','SERVER_NAME'=>'','SERVER_PORT'=>'','HTTP_HOST'=>'','REMOTE_ADDR'=>'',
			'GET_EMPTY'=>true,'POST_EMPTY'=>true,'COOKIE_EMPTY'=>true,'FILES_EMPTY'=>true,'REQUEST_EMPTY'=>true,
		];
	}

	private static function preflightRequestSurface(): bool {
		return self::requestSurface()=== [
			'REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/dataphyre/runtime/realtime/bootstrap','HTTP_X_TRAFFIC_SOURCE'=>'internal_traffic',
			'HTTP_X_DATAPHYRE_ENVIRONMENT'=>(string)(\getenv('DATAPHYRE_ENVIRONMENT') ?: ''),
			'DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP'=>'1','SERVER_PROTOCOL'=>'HTTP/1.1','SERVER_ADDR'=>'127.0.0.1',
			'SERVER_NAME'=>'127.0.0.1','SERVER_PORT'=>'8080','HTTP_HOST'=>'127.0.0.1','REMOTE_ADDR'=>'127.0.0.1',
			'GET_EMPTY'=>true,'POST_EMPTY'=>true,'COOKIE_EMPTY'=>true,'FILES_EMPTY'=>true,'REQUEST_EMPTY'=>true,
		];
	}

	private static function preflightStateRoot(): string {
		$proof=$GLOBALS['DATAPHYRE_INTERNAL_APPLICATION_RELEASE_PREFLIGHT'] ?? null;
		$path=\is_array($proof) ? (string)($proof['state_root'] ?? '') : '';
		$real=\realpath($path);
		if(!\is_string($real) || !\hash_equals($path,$real) || \is_link($path) || !\is_dir($path)){
			throw new RuntimeException('Release-preflight state root is unavailable.');
		}
		return $real;
	}

	private static function preflightStateIdentity(string $path,mixed $handle): string {
		$stat=\lstat($path);$handleStat=\is_resource($handle) ? @\fstat($handle) : false;$real=\realpath($path);
		if(!\is_array($stat) || !\is_array($handleStat) || !\is_string($real)
			|| !\hash_equals($path,$real) || \is_link($path) || !\is_dir($path)
			|| (($stat['mode'] ?? 0)&0170000)!==0040000 || (($handleStat['mode'] ?? 0)&0170000)!==0040000
			|| ($stat['nlink'] ?? 0)<1 || ($handleStat['nlink'] ?? 0)<1){
			throw new RuntimeException('Release-preflight state root identity is unavailable.');
		}
		$identity=['resource_id'=>\get_resource_id($handle)];
		foreach(['dev','ino','mode','uid','gid'] as $field){
			if(!\is_int($stat[$field] ?? null) || !\is_int($handleStat[$field] ?? null)
				|| $stat[$field]!==$handleStat[$field]){
				throw new RuntimeException('Release-preflight state root identity is incomplete.');
			}
			$identity[$field]=$stat[$field];
		}
		return \hash('sha256',\json_encode($identity,\JSON_THROW_ON_ERROR));
	}

	private static function identity(string $projectRoot,string $application,string $environment,string $runtimeBootstrap): array {
		$projectRoot=\realpath($projectRoot) ?: '';$runtimeBootstrap=self::fixed($runtimeBootstrap);
		if($projectRoot==='' || \is_link($projectRoot) || !\is_dir($projectRoot)
			|| \preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$application)!==1
			|| !ApplicationEnvironmentIdentifier::valid($environment) || !\hash_equals(self::runtimeBootstrap(),$runtimeBootstrap)
			|| !\in_array(\PHP_SAPI,['cli','phpdbg'],true) || !\hash_equals($projectRoot,self::projectRoot())
			|| !\hash_equals($application,(string)($_SERVER['HTTP_X_DATAPHYRE_APPLICATION'] ?? ''))
			|| !\hash_equals($environment,(string)(\getenv('DATAPHYRE_ENVIRONMENT') ?: ''))){
			throw new RuntimeException('Bootstrap-only application identity is invalid.');
		}
		return ['project_root'=>$projectRoot,'application'=>$application,'environment'=>$environment,'runtime_bootstrap'=>$runtimeBootstrap];
	}

	private static function exactEntrypoint(string $expected): bool {
		foreach([self::scriptFilenameRaw(),self::serverArgumentRaw(),self::globalArgumentRaw()] as $path){
			if($path==='' || \is_link($path) || !\hash_equals($expected,(string)(\realpath($path) ?: ''))) return false;
		}
		return true;
	}

	private static function exactPreflightEntrypoint(array $identity,string $entrypoint): bool {
		if(!\hash_equals((string)($identity['runtime_bootstrap'] ?? ''),self::scriptFilename())) return false;
		foreach([self::serverArgumentRaw(),self::globalArgumentRaw()] as $path){
			if($path==='' || \is_link($path) || !\hash_equals($entrypoint,(string)(\realpath($path) ?: ''))) return false;
		}
		return true;
	}

	private static function assertCaller(string $expected): void {
		$self=\realpath(__FILE__) ?: __FILE__;
		foreach(\debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS,6) as $frame){
			$file=\realpath((string)($frame['file'] ?? '')) ?: '';
			if($file!=='' && !\hash_equals($self,$file)){
				if(\hash_equals($expected,$file)) return;
				break;
			}
		}
		throw new RuntimeException('Application bootstrap caller is invalid.');
	}

	private static function fixed(string $path): string {
		$real=\realpath($path);
		if(!\is_string($real) || \is_link($path) || !\is_file($real) || !\is_readable($real)){
			throw new RuntimeException('Framework bootstrap file is unavailable.');
		}
		return $real;
	}

	private static function sha256(mixed $value): bool {
		return \is_string($value) && \preg_match('/^[a-f0-9]{64}$/D',$value)===1;
	}

	private static function modulesRoot(): string {return \dirname(__DIR__,2);}
	private static function runtimeBootstrap(): string {return self::fixed(\dirname(__DIR__,3).'/bootstrap.php');}
	private static function sqlCommand(): string {return self::fixed(self::modulesRoot().'/sql/Framework/RegisteredTableMaterializationCommand.php');}
	private static function realtimeBootstrap(): string {return self::fixed(\dirname(__DIR__).'/kernel/application_runtime_realtime_bootstrap.php');}
	private static function preflightEntrypoint(): string {return self::fixed(\dirname(__DIR__).'/kernel/application_release_preflight_realtime.php');}
	private static function scriptFilenameRaw(): string {return (string)($_SERVER['SCRIPT_FILENAME'] ?? '');}
	private static function scriptFilename(): string {return \realpath(self::scriptFilenameRaw()) ?: '';}
	private static function serverArgumentRaw(): string {return (string)((\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [])[0] ?? '');}
	private static function serverArgumentFilename(): string {return \realpath(self::serverArgumentRaw()) ?: '';}
	private static function globalArgumentRaw(): string {return (string)((\is_array($GLOBALS['argv'] ?? null) ? $GLOBALS['argv'] : [])[0] ?? '');}
	private static function globalArgumentFilename(): string {return \realpath(self::globalArgumentRaw()) ?: '';}
	private static function firstIncluded(): string {$files=\get_included_files();return \realpath((string)($files[0] ?? '')) ?: '';}
	private static function projectRoot(): string {return \realpath((string)($_SERVER['DATAPHYRE_PROJECT_ROOT'] ?? '')) ?: '';}
	private static function processCwd(): string {$cwd=\getcwd();return \is_string($cwd) ? (\realpath($cwd) ?: '') : '';}
}
