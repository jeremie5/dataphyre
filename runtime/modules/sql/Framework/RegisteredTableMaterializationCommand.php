<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database;

require_once \dirname(__DIR__,2).'/core/Framework/ApplicationEnvironmentIdentifier.php';

/**
 * Fixed CLI boundary that materializes every table definition registered at boot.
 *
 * The command accepts only a project root, application id, and environment id.
 * It owns the runtime bootstrap and hydration method, emits bounded canonical JSON,
 * and never accepts a PHP file, callback, SQL statement, or shell command.
 */
final class RegisteredTableMaterializationCommand {
	public const CONTRACT='dataphyre.registered_table_materialization.v1';
	public const EXIT_SUCCESS=0;
	public const EXIT_USAGE=64;
	public const EXIT_PROJECT=66;
	public const EXIT_MATERIALIZATION=70;
	public const EXIT_CONFIGURATION=78;
	public const MAX_OUTPUT_BYTES=8192;
	private const MAX_REGISTERED_TABLES=1024;
	private const MAX_TABLE_BYTES=255;
	private const MAX_REPORTED_FAILURES=16;

	/** Returns bounded evidence from the same runtime registry used by materialization. */
	/** @return array{registered_count:int,table_set_sha256:string} */
	public static function registeredTableInventoryEvidence(): array {
		return self::inventoryEvidence(self::normalizeTables(self::registeredTables()));
	}

	/**
	 * Executes through native bootstrap/SQL functions or explicit test seams.
	 *
	 * @param list<string> $arguments Full PHP argument vector.
	 * @param array<string,mixed> $runtime Optional stream, environment, bootstrap, registry, hydration, and managed-purpose test seams.
	 */
	public static function main(array $arguments, array $runtime=[]): int {
		$rawOut=$runtime['write_out'] ?? static fn(string $value): int|false=>\fwrite(\STDOUT,$value);
		$rawError=$runtime['write_error'] ?? static fn(string $value): int|false=>\fwrite(\STDERR,$value);
		$terminalEvidencePending=false;
		$evidenceWriter=static function(callable $write,string $value) use (&$terminalEvidencePending): int {
			$written=$write($value);
			if(!\is_int($written) || $written!==\strlen($value)) throw new \RuntimeException('Canonical evidence write failed.');
			$terminalEvidencePending=false;
			return $written;
		};
		$writeOut=static fn(string $value): int=>$evidenceWriter($rawOut,$value);
		$writeError=static fn(string $value): int=>$evidenceWriter($rawError,$value);
		$sapi=(string)($runtime['sapi'] ?? \PHP_SAPI);
		if(!\in_array($sapi,['cli','phpdbg'],true)){
			return self::failure($writeError,self::EXIT_USAGE,'invalid_runtime',
				'Registered table materialization is available only through the CLI.');
		}
		try{$options=self::options($arguments);}
		catch(\Throwable){
			return self::failure($writeError,self::EXIT_USAGE,'invalid_invocation',
				'Use only the documented typed registered-table materialization options.');
		}
		if($options['help']===true){
			self::writeJson($writeOut,[
				'contract'=>self::CONTRACT,
				'contract_version'=>1,
				'exit_status'=>self::EXIT_SUCCESS,
				'ok'=>true,
				'required_environment'=>['DATAPHYRE_ENVIRONMENT (optional exact-match guard)','application database bindings'],
				'usage'=>self::usage(),
			]);
			return self::EXIT_SUCCESS;
		}

		$context=['application'=>$options['application'],'environment'=>$options['environment']];
		try{$projectRoot=self::projectRoot((string)$options['project_root']);}
		catch(\Throwable){
			return self::failure($writeError,self::EXIT_PROJECT,'project_unavailable',
				'The selected application project root is unavailable.',$context);
		}
		try{self::assertEnvironment((string)$options['environment'],$runtime);}
		catch(\Throwable){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'environment_mismatch',
				'The selected deployment environment does not match the process environment.',$context);
		}

		$defaultBootstrap=!\array_key_exists('bootstrap',$runtime);
		$previousCwd=null;
		if($defaultBootstrap){
			$previousCwd=\getcwd();
			if(!\is_string($previousCwd) || !@\chdir($projectRoot)){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
		}
		try{
		$outputGuardLevel=null;
		if($defaultBootstrap){
			try{$outputGuardLevel=self::installApplicationOutputBoundary();}
			catch(\Throwable){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
			$terminalEvidencePending=true;
			\register_shutdown_function(static function() use (&$terminalEvidencePending,$rawError,$context,$outputGuardLevel): void {
				if(!$terminalEvidencePending) return;
				self::preserveApplicationOutputBoundary((int)$outputGuardLevel);
				$terminalEvidencePending=false;
				try{
					self::failure($rawError,self::EXIT_MATERIALIZATION,'bootstrap_terminated',
						'The application bootstrap terminated before registered tables could be materialized.',$context);
				}catch(\Throwable){
					// STDERR is best effort; terminal failure status is not.
				}finally{
					\exit(self::EXIT_MATERIALIZATION);
				}
			});
		}
		try{
			$bootstrap=$runtime['bootstrap'] ?? [self::class,'bootstrapApplication'];
			if(!\is_callable($bootstrap)) throw new \RuntimeException('Bootstrap boundary is unavailable.');
			$bootstrap($projectRoot,(string)$options['application'],(string)$options['environment']);
			if($defaultBootstrap){
				if(!self::preserveApplicationOutputBoundary((int)$outputGuardLevel)){
					throw new \RuntimeException('Application output boundary changed during bootstrap.');
				}
				self::assertMaterializerContext($projectRoot);
			}
		}catch(\Throwable){
			if($defaultBootstrap) self::preserveApplicationOutputBoundary((int)$outputGuardLevel);
			return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
				'The application bootstrap could not register its SQL table definitions.',$context);
		}

		try{
			$registry=$runtime['registered_tables'] ?? [self::class,'registeredTables'];
			if(!\is_callable($registry)) throw new \RuntimeException('Registered table inventory is unavailable.');
			$tables=self::normalizeTables($registry());
		}catch(\Throwable){
			if($defaultBootstrap){
				if(!self::preserveApplicationOutputBoundary((int)$outputGuardLevel)){
					return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
						'The application bootstrap could not register its SQL table definitions.',$context);
				}
				try{self::assertMaterializerContext($projectRoot);}
				catch(\Throwable){
					return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
						'The application bootstrap could not register its SQL table definitions.',$context);
				}
			}
			return self::failure($writeError,self::EXIT_CONFIGURATION,'registered_table_inventory_invalid',
				'The registered SQL table inventory is unavailable or exceeds its fixed bound.',$context);
		}
		if($defaultBootstrap){
			if(!self::preserveApplicationOutputBoundary((int)$outputGuardLevel)){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
			try{self::assertMaterializerContext($projectRoot);}
			catch(\Throwable){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
		}

		$materialize=$runtime['materialize'] ?? static fn(string $table): bool=>\dataphyre\sql::hydrate_table_definition($table);
		if(!\is_callable($materialize)){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'materializer_unavailable',
				'The fixed SQL table materializer is unavailable.',[
					...$context,...self::inventoryEvidence($tables),
				]);
		}
		try{$databasePurpose=self::managedDatabasePurpose($runtime,$defaultBootstrap);}
		catch(\Throwable){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'managed_database_purpose_invalid',
				'The managed database purpose is unavailable or invalid.',[
					...$context,...self::inventoryEvidence($tables),
				]);
		}
		$materialized=0;$failed=[];$bootstrapContextFailed=false;
		$runMaterialization=static function() use (
			$tables,$materialize,$defaultBootstrap,$outputGuardLevel,$projectRoot,
			&$materialized,&$failed,&$bootstrapContextFailed,
		): void {
			foreach($tables as $table){
				try{$ok=$materialize($table)===true;}
				catch(\Throwable){$ok=false;}
				if($defaultBootstrap){
					if(!self::preserveApplicationOutputBoundary((int)$outputGuardLevel)){
						$bootstrapContextFailed=true;return;
					}
					try{self::assertMaterializerContext($projectRoot);}
					catch(\Throwable){$bootstrapContextFailed=true;return;}
				}
				if($ok){$materialized++;continue;}
				if(\count($failed)<self::MAX_REPORTED_FAILURES) $failed[]=$table;
			}
		};
		try{
			if($databasePurpose!==null && $databasePurpose!=='primary'){
				if(!\class_exists(DataEnvironment::class)) throw new \RuntimeException('Data environment authority is unavailable.');
				DataEnvironment::run($databasePurpose,static function(array $selected) use ($databasePurpose,$runMaterialization): void {
					$cluster=$selected['cluster'] ?? null;
					if(($selected['name'] ?? null)!==$databasePurpose || !\is_string($cluster) || \trim($cluster)===''){
						throw new \RuntimeException('Managed database purpose has no configured SQL cluster.');
					}
					$runMaterialization();
				});
			}else $runMaterialization();
		}catch(\Throwable){
			return self::failure($writeError,self::EXIT_CONFIGURATION,'managed_database_environment_unavailable',
				'The managed database purpose has no configured SQL data environment.',[
					...$context,...self::inventoryEvidence($tables),
				]);
		}
		if($bootstrapContextFailed){
			return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
				'The application bootstrap could not register its SQL table definitions.',$context);
		}
		if($materialized!==\count($tables)){
			return self::failure($writeError,self::EXIT_MATERIALIZATION,'table_materialization_failed',
				'One or more registered SQL table definitions could not be materialized.',[
					...$context,...self::inventoryEvidence($tables),
					'failed_count'=>\count($tables)-$materialized,
					'failed_tables'=>$failed,
					'materialized_count'=>$materialized,
				]);
		}
		if($defaultBootstrap){
			if(!self::preserveApplicationOutputBoundary((int)$outputGuardLevel)){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
			try{self::assertMaterializerContext($projectRoot);}
			catch(\Throwable){
				return self::failure($writeError,self::EXIT_MATERIALIZATION,'bootstrap_failed',
					'The application bootstrap could not register its SQL table definitions.',$context);
			}
		}
		self::writeJson($writeOut,[
			...$context,...self::inventoryEvidence($tables),
			'contract'=>self::CONTRACT,
			'contract_version'=>1,
			'exit_status'=>self::EXIT_SUCCESS,
			'materialized_count'=>$materialized,
			'ok'=>true,
		]);
		return self::EXIT_SUCCESS;
		}finally{
			if($defaultBootstrap && \is_string($previousCwd)) @\chdir($previousCwd);
		}
	}

	/** @param list<string> $arguments @return array{project_root:?string,application:?string,environment:?string,help:bool} */
	private static function options(array $arguments): array {
		$options=['project_root'=>null,'application'=>null,'environment'=>null,'help'=>false];
		$names=['project-root'=>'project_root','application'=>'application','environment'=>'environment'];
		$seen=[];
		foreach(\array_slice($arguments,1) as $argument){
			$argument=(string)$argument;
			if($argument==='--help' || $argument==='-h'){
				if(isset($seen['help'])) throw new \InvalidArgumentException('Duplicate help option.');
				$seen['help']=true;$options['help']=true;continue;
			}
			if(\preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D',$argument,$match)!==1
				|| !isset($names[$match[1]]) || isset($seen[$match[1]])){
				throw new \InvalidArgumentException('Unknown or duplicate registered-table materialization option.');
			}
			$value=\trim($match[2]);
			if($value==='' || \strlen($value)>4096 || \preg_match('/[\x00-\x1f\x7f]/',$value)===1){
				throw new \InvalidArgumentException('Registered-table materialization option value is invalid.');
			}
			$seen[$match[1]]=true;$options[$names[$match[1]]]=$value;
		}
		if($options['help']) return $options;
		foreach(['project_root','application','environment'] as $required){
			if(!\is_string($options[$required]) || $options[$required]===''){
				throw new \InvalidArgumentException('Required registered-table materialization option is missing.');
			}
		}
		if(!self::validApplication((string)$options['application'])
			|| !\Dataphyre\ApplicationEnvironmentIdentifier::valid((string)$options['environment'])){
			throw new \InvalidArgumentException('Registered-table materialization identity is invalid.');
		}
		return $options;
	}

	private static function validApplication(string $value): bool {
		return !\in_array($value,['.','..'],true)
			&& \preg_match('/^(?:[A-Za-z0-9][A-Za-z0-9._-]{0,127}|[A-Za-z_][A-Za-z0-9_$]{0,62})$/D',$value)===1;
	}

	private static function projectRoot(string $path): string {
		$resolved=\realpath($path);
		if(!\is_string($resolved) || !\is_dir($resolved) || !\is_readable($resolved) || \is_link($path)){
			throw new \InvalidArgumentException('Application project root is unavailable.');
		}
		return \rtrim(\str_replace('\\','/',$resolved),'/');
	}

	/** @param array<string,mixed> $runtime */
	private static function assertEnvironment(string $environment,array $runtime): void {
		$values=$runtime['environment_values'] ?? null;
		$configured=\is_array($values) ? ($values['DATAPHYRE_ENVIRONMENT'] ?? null) : \getenv('DATAPHYRE_ENVIRONMENT');
		if(\is_string($configured) && \trim($configured)!=='' && !\hash_equals($environment,\trim($configured))){
			throw new \InvalidArgumentException('Deployment environment does not match.');
		}
	}

	/** @param array<string,mixed> $runtime */
	private static function managedDatabasePurpose(array $runtime,bool $defaultBootstrap): ?string {
		if($defaultBootstrap){
			return \Dataphyre\InternalApplicationBootstrapOnly::materializerDatabasePurpose();
		}
		$purpose=$runtime['managed_database_purpose'] ?? null;
		if($purpose===null) return null;
		if(!\is_string($purpose) || \preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$purpose)!==1){
			throw new \InvalidArgumentException('Managed database purpose test seam is invalid.');
		}
		return $purpose;
	}

	/** Boots only the fixed Dataphyre runtime path for the selected application. */
	private static function bootstrapApplication(string $projectRoot,string $application,string $environment): void {
		$bootstrap=\dirname(__DIR__,3).'/bootstrap.php';
		require_once \dirname(__DIR__,2).'/core/Framework/InternalApplicationBootstrapOnly.php';
		$resolved=\realpath($bootstrap);
		if(!\is_string($resolved) || !\hash_equals($bootstrap,$resolved) || \is_link($bootstrap) || !\is_file($bootstrap)){
			throw new \RuntimeException('Runtime bootstrap is unavailable.');
		}
		if(!\putenv('DATAPHYRE_ENVIRONMENT='.$environment)) throw new \RuntimeException('Environment projection failed.');
		$_ENV['DATAPHYRE_ENVIRONMENT']=$environment;$_SERVER['DATAPHYRE_ENVIRONMENT']=$environment;
		$_SERVER['DATAPHYRE_PROJECT_ROOT']=$projectRoot;
		$_SERVER['HTTP_X_DATAPHYRE_APPLICATION']=$application;
		$_SERVER['HTTP_X_TRAFFIC_SOURCE']='internal_traffic';
		$_SERVER['REQUEST_METHOD']='CLI';
		$_SERVER['REQUEST_URI']='';
		$_SERVER['HTTP_X_DATAPHYRE_ENVIRONMENT']='';
		$_SERVER['DATAPHYRE_RUNTIME_REALTIME_BOOTSTRAP']='';
		$_SERVER['SERVER_PROTOCOL']='';$_SERVER['SERVER_ADDR']='';$_SERVER['SERVER_NAME']='';
		$_SERVER['SERVER_PORT']='';$_SERVER['HTTP_HOST']='';$_SERVER['REMOTE_ADDR']='';
		$_GET=[];$_POST=[];$_COOKIE=[];$_FILES=[];$_REQUEST=[];
		\Dataphyre\InternalApplicationBootstrapOnly::bootMaterializer(
			$projectRoot,$application,$environment,$resolved,
			static function() use ($resolved): void {require $resolved;},
		);
	}

	/** Installs one named output boundary that remains active through process shutdown. */
	private static function installApplicationOutputBoundary(): int {
		$guardLevel=\ob_get_level()+1;
		$guardHandler=self::class.'::swallowApplicationOutput';
		if(!\ob_start([self::class,'swallowApplicationOutput'])){
			throw new \RuntimeException('Application output boundary could not be installed.');
		}
		$handlers=\ob_list_handlers();
		if(($handlers[$guardLevel-1] ?? null)!==$guardHandler){
			@\ob_end_clean();
			throw new \RuntimeException('Application output boundary could not be verified.');
		}
		return $guardLevel;
	}

	/** Verifies the original guard slot and restores a safe top-level swallow on mutation. */
	private static function preserveApplicationOutputBoundary(int $guardLevel): bool {
		$guardHandler=self::class.'::swallowApplicationOutput';
		try{
			while(\ob_get_level()>$guardLevel){
				if(!@\ob_end_clean()) break;
			}
			$handlers=\ob_list_handlers();
			if(\ob_get_level()===$guardLevel
				&& ($handlers[$guardLevel-1] ?? null)===$guardHandler){
				return true;
			}
			if(\ob_get_level()===$guardLevel) @\ob_end_clean();
		}catch(\Throwable){
			// A hostile handler cannot prevent the terminal failure decision below.
		}
		try{\ob_start([self::class,'swallowApplicationOutput']);}
		catch(\Throwable){}
		return false;
	}

	/** Fixed handler identity keeps application output out of canonical release evidence. */
	private static function swallowApplicationOutput(string $chunk): string {
		return '';
	}

	/** Re-attests the immutable materializer seal after every application-owned callback. */
	private static function assertMaterializerContext(string $projectRoot): void {
		$current=\getcwd();
		$current=\is_string($current) ? \realpath($current) : false;
		if(!\is_string($current) || !\hash_equals($projectRoot,$current)){
			throw new \RuntimeException('Application project working directory changed.');
		}
		if(!\Dataphyre\InternalApplicationBootstrapOnly::materializer()){
			throw new \RuntimeException('Application bootstrap context is unavailable.');
		}
	}

	/** @return list<string> */
	private static function registeredTables(): array {
		if(!\class_exists('dataphyre\\sql',false)) return [];
		if(!\method_exists('dataphyre\\sql','registered_table_definitions')
			|| !\method_exists('dataphyre\\sql','hydrate_table_definition')){
			throw new \RuntimeException('SQL runtime is unavailable.');
		}
		return \dataphyre\sql::registered_table_definitions();
	}

	/** @return list<string> */
	private static function normalizeTables(mixed $tables): array {
		if(!\is_array($tables) || !\array_is_list($tables) || \count($tables)>self::MAX_REGISTERED_TABLES){
			throw new \InvalidArgumentException('Registered table inventory is invalid.');
		}
		$normalized=[];
		foreach($tables as $table){
			if(!\is_string($table) || $table==='' || \strlen($table)>self::MAX_TABLE_BYTES
				|| \preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/D',$table)!==1 || isset($normalized[$table])){
				throw new \InvalidArgumentException('Registered table location is invalid.');
			}
			$normalized[$table]=true;
		}
		$tables=\array_keys($normalized);\sort($tables,\SORT_STRING);
		return \array_values($tables);
	}

	/** @param list<string> $tables @return array{registered_count:int,table_set_sha256:string} */
	private static function inventoryEvidence(array $tables): array {
		return [
			'registered_count'=>\count($tables),
			'table_set_sha256'=>\hash('sha256',\json_encode($tables,\JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR)),
		];
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $context */
	private static function failure(callable $write,int $status,string $code,string $message,array $context=[]): int {
		self::writeJson($write,[
			...$context,
			'contract'=>self::CONTRACT,
			'contract_version'=>1,
			'error'=>['code'=>$code,'message'=>$message],
			'exit_status'=>$status,
			'ok'=>false,
		]);
		return $status;
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $payload */
	private static function writeJson(callable $write,array $payload): void {
		$json=\json_encode(self::canonicalize($payload),
			\JSON_THROW_ON_ERROR|\JSON_INVALID_UTF8_SUBSTITUTE|\JSON_UNESCAPED_SLASHES).\PHP_EOL;
		if(\strlen($json)>self::MAX_OUTPUT_BYTES){
			throw new \RuntimeException('Registered table materialization output exceeded its fixed bound.');
		}
		$write($json);
	}

	private static function canonicalize(mixed $value): mixed {
		if(!\is_array($value)) return $value;
		if(\array_is_list($value)) return \array_map(self::canonicalize(...),$value);
		\ksort($value,\SORT_STRING);
		foreach($value as $key=>$item) $value[$key]=self::canonicalize($item);
		return $value;
	}

	private static function usage(): string {
		return 'php runtime/modules/sql/kernel/materialize_registered_tables.php '.
			'--project-root=<project> --application=<id> --environment=<id>';
	}
}
