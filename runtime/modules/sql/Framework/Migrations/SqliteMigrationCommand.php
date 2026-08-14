<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use Dataphyre\ApplicationEnvironmentIdentifier;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__,3).'/core/Framework/ApplicationEnvironmentIdentifier.php';

/**
 * Fixed, SQL-only release boundary for an application SQLite database.
 *
 * The host owns DATAPHYRE_APPLICATION_DATA_ROOT. Applications provide only a
 * strict profile, an ordered content-addressed manifest, and regular .sql
 * files. Application PHP is never booted and no command, script, callback,
 * database path, or shell fragment is accepted from the caller.
 */
final class SqliteMigrationCommand {
	public const CONTRACT='dataphyre.sqlite_migration_command.v1';
	public const EXIT_SUCCESS=0;
	public const EXIT_USAGE=64;
	public const EXIT_MANIFEST=65;
	public const EXIT_PROJECT=66;
	public const EXIT_DATABASE=69;
	public const EXIT_MIGRATION=70;
	public const EXIT_CONFIGURATION=78;

	private const MAX_TOKENS=250000;
	private const MAX_STATEMENTS=4096;

	/** @param list<string> $arguments @param array<string,mixed> $runtime */
	public static function main(array $arguments, array $runtime=[]): int {
		$writeOut=$runtime['write_out'] ?? static fn(string $value): int|false=>fwrite(STDOUT, $value);
		$writeError=$runtime['write_error'] ?? static fn(string $value): int|false=>fwrite(STDERR, $value);
		$sapi=(string)($runtime['sapi'] ?? PHP_SAPI);
		if(!in_array($sapi, ['cli','phpdbg'], true)){
			return self::failure($writeError, self::EXIT_USAGE, 'invalid_runtime', 'SQLite migrations are available only through the CLI.');
		}
		try{
			$options=self::options($arguments);
		}catch(Throwable){
			return self::failure($writeError, self::EXIT_USAGE, 'invalid_invocation', 'Use only the documented typed SQLite migration options.');
		}
		if($options['help']===true){
			self::writeJson($writeOut, [
				'contract'=>self::CONTRACT,
				'exit_status'=>self::EXIT_SUCCESS,
				'ok'=>true,
				'required_environment'=>[
					'DATAPHYRE_APPLICATION_DATA_ROOT',
					'DATAPHYRE_ENVIRONMENT (optional exact-match guard)',
				],
				'usage'=>self::usage(),
			]);
			return self::EXIT_SUCCESS;
		}

		$context=self::context($options);
		try{
			$projectRoot=self::projectRoot((string)$options['project_root']);
		}catch(Throwable){
			return self::failure($writeError, self::EXIT_PROJECT, 'project_unavailable', 'The selected project root is unavailable.', $context);
		}
		try{
			$profile=SqliteMigrationProfile::load($projectRoot, (string)$options['app']);
		}catch(Throwable){
			return self::failure($writeError, self::EXIT_CONFIGURATION, 'profile_invalid', 'The fixed SQLite migration profile is missing or invalid.', $context);
		}
		try{
			$manifest=SqliteMigrationManifest::load($projectRoot, $profile);
			foreach($manifest->entries() as $index=>$entry){
				if($index===0){
					self::validateSql($entry['sql'], $profile->journalTable());
				}else{
					self::validateRollingSql($entry['sql'], $profile->journalTable());
				}
			}
		}catch(Throwable){
			return self::failure($writeError, self::EXIT_MANIFEST, 'manifest_invalid', 'The immutable SQLite migration manifest or SQL is invalid.', $context);
		}
		try{
			[$dataRoot,$databasePath]=self::databasePath($profile, $options, $runtime);
		}catch(Throwable){
			return self::failure($writeError, self::EXIT_CONFIGURATION, 'application_data_root_invalid', 'The host-owned application data root is unavailable or invalid.', $context);
		}

		try{
			$result=self::run($profile, $manifest, $dataRoot, $databasePath, (bool)$options['dry_run'], $runtime);
		}catch(SqliteLegacyAdoptionRequired){
			return self::failure(
				$writeError,
				self::EXIT_MIGRATION,
				'legacy_database_requires_one_time_migration',
				'An existing SQLite database without the Dataphyre journal requires an explicit one-time data migration.',
				[...$context, 'manifest'=>self::manifestEvidence($manifest)]
			);
		}catch(SqliteDatabaseUnavailable){
			return self::failure($writeError, self::EXIT_DATABASE, 'database_unavailable', 'Dataphyre could not open the host-owned SQLite database.', $context);
		}catch(Throwable){
			return self::failure(
				$writeError,
				self::EXIT_MIGRATION,
				'migration_failed',
				'Dataphyre could not apply or verify the selected SQLite migrations.',
				[...$context, 'manifest'=>self::manifestEvidence($manifest)]
			);
		}

		self::writeJson($writeOut, [
			...$context,
			'contract'=>self::CONTRACT,
			'exit_status'=>self::EXIT_SUCCESS,
			'manifest'=>self::manifestEvidence($manifest),
			'ok'=>true,
			'result'=>$result,
		]);
		return self::EXIT_SUCCESS;
	}

	/** @param list<string> $arguments @return array<string,mixed> */
	private static function options(array $arguments): array {
		$options=['project_root'=>null, 'app'=>null, 'environment'=>null, 'dry_run'=>false, 'help'=>false];
		$names=['project-root'=>'project_root', 'app'=>'app', 'environment'=>'environment'];
		$seen=[];
		foreach(array_slice($arguments, 1) as $argument){
			$argument=(string)$argument;
			if($argument==='--help' || $argument==='-h'){
				if(isset($seen['help'])) throw new InvalidArgumentException('Duplicate help option.');
				$seen['help']=true;
				$options['help']=true;
				continue;
			}
			if($argument==='--dry-run'){
				if(isset($seen['dry-run'])) throw new InvalidArgumentException('Duplicate dry-run option.');
				$seen['dry-run']=true;
				$options['dry_run']=true;
				continue;
			}
			if(preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $match)!==1){
				throw new InvalidArgumentException('SQLite migration arguments must use --name=value.');
			}
			$name=$match[1];
			if(!isset($names[$name]) || isset($seen[$name])) throw new InvalidArgumentException('Unknown or duplicate SQLite migration option.');
			$value=trim($match[2]);
			if($value==='' || strlen($value)>4096 || preg_match('/[\x00-\x1f\x7f]/', $value)===1){
				throw new InvalidArgumentException('SQLite migration option value is invalid.');
			}
			$seen[$name]=true;
			$options[$names[$name]]=$value;
		}
		if($options['help']===true) return $options;
		foreach(['project_root','app','environment'] as $required){
			if(!is_string($options[$required]) || $options[$required]==='') throw new InvalidArgumentException('Required SQLite migration option is missing.');
		}
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/D', $options['app'])!==1
			|| !ApplicationEnvironmentIdentifier::valid($options['environment'])){
			throw new InvalidArgumentException('SQLite migration context is invalid.');
		}
		return $options;
	}

	private static function projectRoot(string $path): string {
		if(is_link($path)) throw new InvalidArgumentException('Project root cannot be a link.');
		$resolved=realpath($path);
		if($resolved===false || !is_dir($resolved) || !is_readable($resolved)) throw new InvalidArgumentException('Project root is unavailable.');
		return rtrim(str_replace('\\','/',$resolved), '/');
	}

	/** @param array<string,mixed> $options @param array<string,mixed> $runtime @return array{string,string} */
	private static function databasePath(SqliteMigrationProfile $profile, array $options, array $runtime): array {
		$environment=self::environment($runtime);
		$guard=$environment['DATAPHYRE_ENVIRONMENT'] ?? null;
		if(is_string($guard) && trim($guard)!=='' && !hash_equals((string)$options['environment'], trim($guard))){
			throw new InvalidArgumentException('Environment guard mismatch.');
		}
		$configured=$environment['DATAPHYRE_APPLICATION_DATA_ROOT'] ?? null;
		if(!is_string($configured) || trim($configured)==='') throw new InvalidArgumentException('Application data root is missing.');
		$configured=rtrim(str_replace('\\','/',trim($configured)), '/');
		if(!self::absolutePath($configured) || is_link($configured)) throw new InvalidArgumentException('Application data root is invalid.');
		$resolved=realpath($configured);
		if($resolved===false || !is_dir($resolved) || !is_readable($resolved)) throw new InvalidArgumentException('Application data root is unavailable.');
		$resolved=rtrim(str_replace('\\','/',$resolved), '/');
		if(!hash_equals($resolved, $configured)) throw new InvalidArgumentException('Application data root must be canonical.');
		if($options['dry_run']!==true && !is_writable($resolved)) throw new InvalidArgumentException('Application data root is not writable.');
		$databasePath=$resolved.'/'.$profile->databaseFile();
		if(file_exists($databasePath) || is_link($databasePath)){
			if(is_link($databasePath) || !is_file($databasePath) || !is_readable($databasePath)){
				throw new InvalidArgumentException('SQLite database file is invalid.');
			}
			$details=stat($databasePath);
			if(!is_array($details) || ($details['nlink'] ?? 0)!==1){
				throw new InvalidArgumentException('SQLite database file is invalid.');
			}
			if($options['dry_run']!==true && !is_writable($databasePath)) throw new InvalidArgumentException('SQLite database file is not writable.');
		}
		return [$resolved,$databasePath];
	}

	/** @param array<string,mixed> $runtime @return array<string,string> */
	private static function environment(array $runtime): array {
		$provided=$runtime['environment_values'] ?? null;
		if($provided!==null){
			if(!is_array($provided)) throw new InvalidArgumentException('Environment seam is invalid.');
			$values=[];
			foreach($provided as $key=>$value){
				if(!is_string($key) || !is_string($value)) throw new InvalidArgumentException('Environment seam contains invalid values.');
				$values[$key]=$value;
			}
			return $values;
		}
		$native=getenv();
		$values=[];
		foreach(is_array($native) ? $native : [] as $key=>$value){
			if(is_string($key) && is_string($value)) $values[$key]=$value;
		}
		return $values;
	}

	private static function absolutePath(string $path): bool {
		return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//D', $path)===1;
	}

	/** @param array<string,mixed> $runtime @return array<string,mixed> */
	private static function run(
		SqliteMigrationProfile $profile,
		SqliteMigrationManifest $manifest,
		string $dataRoot,
		string $databasePath,
		bool $dryRun,
		array $runtime
	): array {
		$exists=is_file($databasePath);
		$existingBytes=$exists ? filesize($databasePath) : 0;
		if(!is_int($existingBytes) || $existingBytes<0){
			throw new SqliteDatabaseUnavailable('SQLite database size is unavailable.');
		}
		$logicalFresh=!$exists || $existingBytes===0;
		if($logicalFresh && $dryRun){
			return self::resultEvidence($profile, [], array_column($manifest->entries(),'id'), true);
		}
		try{
			$factory=$runtime['pdo_factory'] ?? static fn(string $dsn,array $attributes): PDO=>new PDO($dsn,null,null,$attributes);
			$mode=$dryRun ? 'ro' : ($exists ? 'rw' : 'rwc');
			$dsn='sqlite:file:'.self::sqliteUriPath($databasePath).'?mode='.$mode;
			$pdo=$factory($dsn, [
				PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
				PDO::ATTR_TIMEOUT=>5,
			]);
			if(!$pdo instanceof PDO) throw new RuntimeException('SQLite connection factory returned an invalid value.');
			self::verifyOpenedDatabase($pdo,$dataRoot,$databasePath);
			$pdo->exec('PRAGMA foreign_keys = ON');
			$pdo->exec('PRAGMA busy_timeout = 5000');
		}catch(Throwable $error){
			throw new SqliteDatabaseUnavailable('SQLite database connection failed.', 0, $error);
		}
		if(!is_file($databasePath) || is_link($databasePath)){
			throw new SqliteDatabaseUnavailable('SQLite database file identity changed.');
		}
		$databaseDetails=stat($databasePath);
		if(!is_array($databaseDetails) || ($databaseDetails['nlink'] ?? 0)!==1
			|| dirname((string)realpath($databasePath))!==$dataRoot){
			throw new SqliteDatabaseUnavailable('SQLite database file identity changed.');
		}

		$journalExists=self::journalExists($pdo, $profile->journalTable());
		if($journalExists) self::assertJournalContract($pdo,$profile->journalTable());
		$applicationObjects=self::applicationObjectCount($pdo,$profile->journalTable());
		if(!$journalExists && !$logicalFresh){
			throw new SqliteLegacyAdoptionRequired('Existing initialized SQLite database has no Dataphyre journal.');
		}
		if(!$journalExists && $applicationObjects>0){
			throw new SqliteLegacyAdoptionRequired('Existing SQLite database has no Dataphyre journal.');
		}
		$journal=$journalExists ? self::readJournal($pdo, $profile->journalTable()) : [];
		if($journalExists && $journal===[] && $applicationObjects>0){
			throw new RuntimeException('Empty SQLite migration journal conflicts with an existing application schema.');
		}
		self::validateJournal($journal, $manifest);
		$pending=self::pendingEntries($journal, $manifest);
		if($dryRun){
			return self::resultEvidence($profile, array_column($journal,'id'), array_column($pending,'id'), true);
		}

		$transaction=false;
		$finalJournal=[];
		try{
			$pdo->exec('BEGIN IMMEDIATE');
			$transaction=true;
			$journalExists=self::journalExists($pdo,$profile->journalTable());
			if($journalExists) self::assertJournalContract($pdo,$profile->journalTable());
			$applicationObjects=self::applicationObjectCount($pdo,$profile->journalTable());
			if(!$journalExists && $applicationObjects>0){
				throw new SqliteLegacyAdoptionRequired('Existing SQLite database has no Dataphyre journal.');
			}
			if(!$journalExists) self::createJournal($pdo,$profile->journalTable());
			self::assertJournalContract($pdo,$profile->journalTable());
			$journal=self::readJournal($pdo, $profile->journalTable());
			if($journalExists && $journal===[] && $applicationObjects>0){
				throw new RuntimeException('Empty SQLite migration journal conflicts with an existing application schema.');
			}
			self::validateJournal($journal, $manifest);
			$pending=self::pendingEntries($journal, $manifest);
			$insert=$pdo->prepare('INSERT INTO '.self::quotedIdentifier($profile->journalTable()).' (migration_id, sha256, applied_at) VALUES (:id, :sha256, :applied_at)');
			foreach($pending as $entry){
				$pdo->exec($entry['sql']);
				$inserted=$insert->execute([':id'=>$entry['id'], ':sha256'=>$entry['sha256'], ':applied_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
				if($inserted!==true || $insert->rowCount()!==1){
					throw new RuntimeException('SQLite migration journal insert was not exact.');
				}
			}
			$foreignKeyFailure=$pdo->query('PRAGMA foreign_key_check')->fetch();
			if(is_array($foreignKeyFailure)) throw new RuntimeException('SQLite foreign key verification failed.');
			$integrity=$pdo->query('PRAGMA integrity_check(1)')->fetchColumn();
			if(!is_string($integrity) || !hash_equals('ok', strtolower($integrity))) throw new RuntimeException('SQLite integrity verification failed.');
			self::assertJournalContract($pdo,$profile->journalTable());
			$finalJournal=self::readJournal($pdo, $profile->journalTable());
			self::validateJournal($finalJournal, $manifest, true);
			$pdo->exec('COMMIT');
			$transaction=false;
		}catch(Throwable $error){
			if($transaction){
				try{$pdo->exec('ROLLBACK');}catch(Throwable){}
			}
			throw $error;
		}
		return self::resultEvidence(
			$profile,
			array_column($finalJournal,'id'),
			array_column(self::pendingEntries($finalJournal,$manifest),'id'),
			false,
		);
	}

	/** Proves PDO opened exactly the canonical host-selected main database file. */
	private static function verifyOpenedDatabase(PDO $pdo,string $dataRoot,string $databasePath): void {
		$rows=$pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
		if(!is_array($rows) || count($rows)!==1 || !is_array($rows[0] ?? null)
			|| (string)($rows[0]['name'] ?? '')!=='main'){
			throw new RuntimeException('SQLite opened database inventory is invalid.');
		}
		$opened=(string)($rows[0]['file'] ?? '');
		$openedReal=$opened!=='' ? realpath($opened) : false;
		$expectedReal=realpath($databasePath);
		if(!is_string($openedReal) || !is_string($expectedReal)
			|| !hash_equals(str_replace('\\','/',$expectedReal),str_replace('\\','/',$openedReal))
			|| !hash_equals($dataRoot,str_replace('\\','/',dirname($expectedReal)))
			|| !hash_equals(basename($databasePath),basename($expectedReal))){
			throw new RuntimeException('SQLite opened database identity does not match the host-selected path.');
		}
	}

	private static function journalExists(PDO $pdo, string $table): bool {
		$statement=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name");
		$statement->execute([':name'=>$table]);
		return $statement->fetchColumn()!==false;
	}

	private static function applicationObjectCount(PDO $pdo,string $journalTable): int {
		$statement=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' AND name <> :journal");
		$statement->execute([':journal'=>$journalTable]);
		return (int)$statement->fetchColumn();
	}

	private static function createJournal(PDO $pdo, string $table): void {
		$pdo->exec(self::journalSql($table));
	}

	private static function journalSql(string $table): string {
		return 'CREATE TABLE '.self::quotedIdentifier($table).' (migration_id TEXT PRIMARY KEY, sha256 TEXT NOT NULL CHECK(length(sha256)=64), applied_at TEXT NOT NULL)';
	}

	/** Proves the framework-owned journal has exactly the inert shape Dataphyre created. */
	private static function assertJournalContract(PDO $pdo,string $table): void {
		$statement=$pdo->prepare("SELECT type, tbl_name, sql FROM sqlite_master WHERE name=:name ORDER BY rowid ASC");
		$statement->execute([':name'=>$table]);
		$objects=$statement->fetchAll(PDO::FETCH_ASSOC);
		if(!is_array($objects) || count($objects)!==1 || !is_array($objects[0] ?? null)
			|| ($objects[0]['type'] ?? null)!=='table'
			|| ($objects[0]['tbl_name'] ?? null)!==$table
			|| !is_string($objects[0]['sql'] ?? null)
			|| !hash_equals(self::journalSql($table),$objects[0]['sql'])){
			throw new RuntimeException('SQLite migration journal schema drift detected.');
		}

		$columns=$pdo->query('PRAGMA table_xinfo('.self::quotedIdentifier($table).')')->fetchAll(PDO::FETCH_ASSOC);
		$expectedColumns=[
			['cid'=>0,'name'=>'migration_id','type'=>'TEXT','notnull'=>0,'dflt_value'=>null,'pk'=>1,'hidden'=>0],
			['cid'=>1,'name'=>'sha256','type'=>'TEXT','notnull'=>1,'dflt_value'=>null,'pk'=>0,'hidden'=>0],
			['cid'=>2,'name'=>'applied_at','type'=>'TEXT','notnull'=>1,'dflt_value'=>null,'pk'=>0,'hidden'=>0],
		];
		if($columns!==$expectedColumns){
			throw new RuntimeException('SQLite migration journal column drift detected.');
		}

		$indexes=$pdo->query('PRAGMA index_list('.self::quotedIdentifier($table).')')->fetchAll(PDO::FETCH_ASSOC);
		$expectedIndexes=[[
			'seq'=>0,
			'name'=>'sqlite_autoindex_'.$table.'_1',
			'unique'=>1,
			'origin'=>'pk',
			'partial'=>0,
		]];
		if($indexes!==$expectedIndexes){
			throw new RuntimeException('SQLite migration journal index drift detected.');
		}

		$trigger=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='trigger' AND tbl_name=:name LIMIT 1");
		$trigger->execute([':name'=>$table]);
		if($trigger->fetchColumn()!==false){
			throw new RuntimeException('SQLite migration journal trigger drift detected.');
		}
	}

	/** @return list<array{id:string,sha256:string}> */
	private static function readJournal(PDO $pdo, string $table): array {
		$rows=$pdo->query('SELECT migration_id, sha256 FROM '.self::quotedIdentifier($table).' ORDER BY rowid ASC')->fetchAll();
		$result=[];
		foreach(is_array($rows) ? $rows : [] as $row){
			$result[]=['id'=>(string)($row['migration_id'] ?? ''), 'sha256'=>strtolower((string)($row['sha256'] ?? ''))];
		}
		return $result;
	}

	/** @param list<array{id:string,sha256:string}> $journal */
	private static function validateJournal(array $journal, SqliteMigrationManifest $manifest,bool $complete=false): void {
		$entries=$manifest->entries();
		if($complete && count($journal)!==count($entries)){
			throw new RuntimeException('SQLite migration journal is incomplete.');
		}
		foreach($journal as $index=>$row){
			$expected=$entries[$index] ?? null;
			if(!is_array($expected)
				|| !hash_equals($expected['id'], $row['id'])
				|| !hash_equals($expected['sha256'], $row['sha256'])){
				throw new RuntimeException('SQLite migration journal identity drift detected.');
			}
		}
	}

	/** @param list<array{id:string,sha256:string}> $journal @return list<array{id:string,path:string,sha256:string,sql:string}> */
	private static function pendingEntries(array $journal, SqliteMigrationManifest $manifest): array {
		return array_values(array_slice($manifest->entries(), count($journal)));
	}

	private static function quotedIdentifier(string $value): string {return '"'.str_replace('"','""',$value).'"';}

	private static function sqliteUriPath(string $path): string {
		$normalized=str_replace('\\','/',$path);
		return implode('/', array_map(static fn(string $part): string=>rawurlencode($part), explode('/',$normalized)));
	}

	private static function validateSql(string $sql, string $journalTable): void {
		$tokens=self::sqlTokens($sql);
		$forbidden=array_fill_keys([
			'attach','detach','pragma','vacuum','load_extension','readfile','writefile',
			'fts3_tokenizer','commit','rollback','savepoint','release','temp','temporary',
		], true);
		$journal=strtolower($journalTable);
		foreach($tokens as $index=>$token){
			if($token===';') continue;
			if(isset($forbidden[$token]) || hash_equals($journal,$token) || hash_equals('literal:'.$journal,$token)){
				throw new InvalidArgumentException('SQLite migration uses a forbidden primitive.');
			}
			if($token==='create' && ($tokens[$index+1] ?? null)==='virtual' && ($tokens[$index+2] ?? null)==='table'){
				throw new InvalidArgumentException('SQLite virtual tables are not allowed in release migrations.');
			}
		}

		$allowed=array_fill_keys(['create','alter','drop','insert','update','delete','with','select','reindex','analyze'], true);
		$statement=[];
		$statementCount=0;
		$trigger=false;
		$triggerBody=false;
		$triggerEnded=false;
		$caseDepth=0;
		$finish=static function() use (&$statement,&$statementCount,&$trigger,&$triggerBody,&$triggerEnded,&$caseDepth,$allowed): void {
			if($statement===[]) return;
			if(!isset($allowed[$statement[0]])) throw new InvalidArgumentException('SQLite migration statement type is not allowed.');
			$statementCount++;
			if($statementCount>self::MAX_STATEMENTS) throw new InvalidArgumentException('SQLite migration contains too many statements.');
			$statement=[];
			$trigger=false;
			$triggerBody=false;
			$triggerEnded=false;
			$caseDepth=0;
		};
		foreach($tokens as $token){
			if($token===';'){
				if(!$trigger || $triggerEnded) $finish();
				continue;
			}
			$statement[]=$token;
			if(count($statement)>=2 && $statement[0]==='create'){
				$trigger=$statement[1]==='trigger'
					|| (in_array($statement[1], ['temp','temporary'], true) && ($statement[2] ?? null)==='trigger');
			}
			if($trigger){
				if($token==='begin') $triggerBody=true;
				elseif($triggerBody && $token==='case') $caseDepth++;
				elseif($triggerBody && $token==='end'){
					if($caseDepth>0) $caseDepth--;
					else $triggerEnded=true;
				}
			}
		}
		$finish();
		if($statementCount<1) throw new InvalidArgumentException('SQLite migration contains no statements.');
	}

	/** Allows only additive objects and nullable columns after the one bootstrap migration. */
	private static function validateRollingSql(string $sql,string $journalTable): void {
		self::validateSql($sql,$journalTable);
		$statements=[];
		$current=[];
		foreach(self::sqlTokens($sql) as $token){
			if($token===';'){
				if($current!==[]) {$statements[]=$current;$current=[];}
				continue;
			}
			$current[]=$token;
		}
		if($current!==[]) $statements[]=$current;
		if($statements===[] || count($statements)>self::MAX_STATEMENTS){
			throw new InvalidArgumentException('SQLite rolling migration statement count is invalid.');
		}
		foreach($statements as $statement){
			if(self::validRollingCreateTable($statement)
				|| self::validRollingCreateIndex($statement)
				|| self::validRollingAddColumn($statement)){
				continue;
			}
			throw new InvalidArgumentException('SQLite rolling migrations must be expand-only.');
		}
	}

	/** @param list<string> $tokens */
	private static function validRollingCreateTable(array $tokens): bool {
		if(($tokens[0] ?? null)!=='create' || ($tokens[1] ?? null)!=='table'
			|| !self::rollingIdentifier($tokens[2] ?? null) || ($tokens[3] ?? null)!=='('
			|| end($tokens)!==')') return false;
		foreach(['if','not','exists','as','select','trigger','view','virtual','temp','temporary'] as $forbidden){
			if(in_array($forbidden,$tokens,true)) return false;
		}
		$depth=0;
		foreach(array_slice($tokens,3) as $token){
			if($token==='(') $depth++;
			elseif($token===')') $depth--;
			if($depth<0) return false;
		}
		return $depth===0;
	}

	/** @param list<string> $tokens */
	private static function validRollingCreateIndex(array $tokens): bool {
		if(($tokens[0] ?? null)!=='create' || ($tokens[1] ?? null)!=='index'
			|| !self::rollingIdentifier($tokens[2] ?? null) || ($tokens[3] ?? null)!=='on'
			|| !self::rollingIdentifier($tokens[4] ?? null) || ($tokens[5] ?? null)!=='('
			|| end($tokens)!==')') return false;
		foreach(['unique','if','not','exists','where'] as $forbidden){
			if(in_array($forbidden,$tokens,true)) return false;
		}
		$columns=array_slice($tokens,6,-1);
		if($columns===[]) return false;
		$expectIdentifier=true;
		foreach($columns as $token){
			if($expectIdentifier){
				if(!self::rollingIdentifier($token)) return false;
			}else{
				if($token!==',') return false;
			}
			$expectIdentifier=!$expectIdentifier;
		}
		return $expectIdentifier===false;
	}

	/** @param list<string> $tokens */
	private static function validRollingAddColumn(array $tokens): bool {
		if(($tokens[0] ?? null)!=='alter' || ($tokens[1] ?? null)!=='table'
			|| !self::rollingIdentifier($tokens[2] ?? null) || ($tokens[3] ?? null)!=='add') return false;
		$offset=($tokens[4] ?? null)==='column' ? 5 : 4;
		if(!self::rollingIdentifier($tokens[$offset] ?? null)) return false;
		$type=$tokens[$offset+1] ?? null;
		if(!in_array($type,['integer','text','real','blob','numeric'],true)) return false;
		$tail=array_slice($tokens,$offset+2);
		return $tail===[] || $tail===['null'];
	}

	private static function rollingIdentifier(mixed $value): bool {
		return is_string($value) && preg_match('/^[a-z_][a-z0-9_$]{0,127}$/D',$value)===1;
	}

	/** @return list<string> */
	private static function sqlTokens(string $sql): array {
		$tokens=[];
		$tokenCount=0;
		$countToken=static function() use (&$tokenCount): void {
			$tokenCount++;
			if($tokenCount>self::MAX_TOKENS) throw new InvalidArgumentException('SQLite migration contains too many tokens.');
		};
		$length=strlen($sql);
		for($index=0;$index<$length;){
			$character=$sql[$index];
			if(ctype_space($character)){$index++;continue;}
			if($character==='-' && ($sql[$index+1] ?? '')==='-'){
				$countToken();
				$end=strpos($sql,"\n",$index+2);
				$index=$end===false ? $length : $end+1;
				continue;
			}
			if($character==='/' && ($sql[$index+1] ?? '')==='*'){
				$countToken();
				$end=strpos($sql,'*/',$index+2);
				if($end===false) throw new InvalidArgumentException('SQLite migration has an unterminated comment.');
				$index=$end+2;
				continue;
			}
			if(in_array($character, ["'",'"','`','['], true)){
				$quotedIdentifier=$character!=="'";
				$countToken();
				$closing=$character==='[' ? ']' : $character;
				$index++;
				$value='';
				$closed=false;
				while($index<$length){
					if($sql[$index]!==$closing){$value.=$sql[$index];$index++;continue;}
					if(($sql[$index+1] ?? '')===$closing){$value.=$closing;$index+=2;continue;}
					$index++;
					$closed=true;
					break;
				}
				if(!$closed) throw new InvalidArgumentException('SQLite migration has an unterminated quoted value.');
				if($value!=='') $tokens[]=($quotedIdentifier ? '' : 'literal:').strtolower($value);
				continue;
			}
			if(in_array($character,[';', '(', ')', ','],true)){
				$countToken();
				$tokens[]=$character;
				$index++;
				continue;
			}
			if(ctype_alpha($character) || $character==='_'){
				$countToken();
				$start=$index++;
				while($index<$length && (ctype_alnum($sql[$index]) || in_array($sql[$index], ['_','$'], true))) $index++;
				$tokens[]=strtolower(substr($sql,$start,$index-$start));
				continue;
			}
			if(ctype_digit($character)){
				$countToken();
				$start=$index++;
				while($index<$length && (ctype_alnum($sql[$index]) || in_array($sql[$index],['.','_','+','-'],true))) $index++;
				$tokens[]='number:'.strtolower(substr($sql,$start,$index-$start));
				continue;
			}
			$countToken();
			$index++;
		}
		return $tokens;
	}

	/** @return array<string,mixed> */
	private static function manifestEvidence(SqliteMigrationManifest $manifest): array {
		return ['algorithm'=>'sha256', 'migration_count'=>count($manifest->entries()), 'sha256'=>$manifest->sha256()];
	}

	/** @param list<string> $applied @param list<string> $pending @return array<string,mixed> */
	private static function resultEvidence(SqliteMigrationProfile $profile, array $applied, array $pending, bool $dryRun): array {
		return [
			'applied_migrations'=>$applied,
			'database_file'=>$profile->databaseFile(),
			'dry_run'=>$dryRun,
			'pending_migrations'=>$pending,
		];
	}

	/** @param array<string,mixed> $options @return array<string,mixed> */
	private static function context(array $options): array {
		return ['application'=>$options['app'], 'environment'=>$options['environment']];
	}

	/** @return list<string> */
	private static function usage(): array {
		return [
			'php runtime/modules/sql/kernel/sqlite_migrate.php --project-root=<project> --app=<id> --environment=<id> [--dry-run]',
			'Only database/sqlite/profile.json, database/sqlite/manifest.json, and checksummed regular .sql files are accepted.',
			'The host must mount and select DATAPHYRE_APPLICATION_DATA_ROOT.',
		];
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $evidence */
	private static function failure(callable $write, int $status, string $code, string $message, array $evidence=[]): int {
		self::writeJson($write, [
			...$evidence,
			'contract'=>self::CONTRACT,
			'error'=>['code'=>$code, 'message'=>$message],
			'exit_status'=>$status,
			'ok'=>false,
		]);
		return $status;
	}

	/** @param callable(string):mixed $write @param array<string,mixed> $payload */
	private static function writeJson(callable $write, array $payload): void {
		$write(json_encode($payload, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
	}
}

final class SqliteLegacyAdoptionRequired extends RuntimeException {}
final class SqliteDatabaseUnavailable extends RuntimeException {}
