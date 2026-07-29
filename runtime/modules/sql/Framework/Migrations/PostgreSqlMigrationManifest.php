<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use JsonException;
use RuntimeException;

/**
 * Checksummed, path-confined PostgreSQL migration manifest.
 */
final class PostgreSqlMigrationManifest {
	public const SOURCE_KEY_PATTERN='/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/D';

	private string $path;
	private string $publicPath;
	private string $sha256;
	private int $schemaVersion;
	private string $bootstrapCutoff;
	/** @var list<array<string,mixed>> */
	private array $entries;
	/** @var array<string,mixed> */
	private array $source;

	/** @param list<array<string,mixed>> $entries @param array<string,mixed> $source */
	private function __construct(
		string $path,
		string $publicPath,
		string $sha256,
		int $schemaVersion,
		string $bootstrapCutoff,
		array $entries,
		array $source
	) {
		$this->path=$path;
		$this->publicPath=$publicPath;
		$this->sha256=$sha256;
		$this->schemaVersion=$schemaVersion;
		$this->bootstrapCutoff=$bootstrapCutoff;
		$this->entries=$entries;
		$this->source=$source;
	}

	public static function load(string $databaseRoot, PostgreSqlMigrationProfile $profile): self {
		if(is_link($databaseRoot)){
			throw new RuntimeException('PostgreSQL migration database root may not be a symbolic link.');
		}
		$databaseRoot=realpath($databaseRoot);
		if($databaseRoot===false || !is_dir($databaseRoot)){
			throw new RuntimeException('PostgreSQL migration database root is unavailable.');
		}
		$directory=realpath($databaseRoot.'/postgresql');
		$manifestPath=$databaseRoot.'/postgresql/manifest.json';
		if(
			$directory===false
			|| is_link($databaseRoot.'/postgresql')
			|| is_link($manifestPath)
			|| !is_file($manifestPath)
			|| !is_readable($manifestPath)
		){
			throw new RuntimeException('PostgreSQL migration manifest is missing.');
		}
		$manifestBytes=(string)file_get_contents($manifestPath);
		try{
			$decoded=json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
		}catch(JsonException $exception){
			throw new RuntimeException('PostgreSQL migration manifest is invalid JSON.', 0, $exception);
		}
		$manifestKeys=is_array($decoded) ? array_keys($decoded) : [];
		sort($manifestKeys, SORT_STRING);
		if(
			!is_array($decoded)
			|| $manifestKeys!==['algorithm', 'bootstrap_cutoff', 'migrations', 'schema_version', 'source']
			|| ($decoded['schema_version'] ?? null)!==PostgreSqlMigrationProfile::MANIFEST_SCHEMA_VERSION
			|| ($decoded['algorithm'] ?? null)!=='sha256'
			|| ($decoded['bootstrap_cutoff'] ?? null)!==$profile->bootstrapCutoff()
			|| !is_array($decoded['source'] ?? null)
			|| array_is_list($decoded['source'])
			|| !is_array($decoded['migrations'] ?? null)
			|| !array_is_list($decoded['migrations'])
		){
			throw new RuntimeException('PostgreSQL migration manifest has an unsupported shape.');
		}
		foreach(array_keys($decoded['source']) as $sourceKey){
			if(
				!is_string($sourceKey)
				|| preg_match(self::SOURCE_KEY_PATTERN, $sourceKey)!==1
			){
				throw new RuntimeException(
					'PostgreSQL migration source provenance keys must be portable identifiers.'
				);
			}
		}

		$entries=[];
		$seenPaths=[];
		$cutoffSeen=false;
		foreach($decoded['migrations'] as $offset=>$entry){
			if(!is_array($entry)){
				throw new RuntimeException('PostgreSQL migration manifest contains a non-object entry.');
			}
			$entryKeys=array_keys($entry);
			sort($entryKeys, SORT_STRING);
			$down=$entry['down'] ?? null;
			$expectedKeys=$down===null
				? ['description', 'down', 'id', 'irreversible_reason', 'minimum_compatible_release', 'phase', 'up']
				: ['description', 'down', 'id', 'minimum_compatible_release', 'phase', 'up'];
			$id=$entry['id'] ?? null;
			$phase=$entry['phase'] ?? null;
			$description=$entry['description'] ?? null;
			$minimumCompatibleRelease=$entry['minimum_compatible_release'] ?? null;
			if(
				$entryKeys!==$expectedKeys
				|| !is_string($id)
				|| preg_match(PostgreSqlMigrationProfile::MIGRATION_ID_PATTERN, $id)!==1
				|| !str_starts_with($id, sprintf('%03d_', $offset+1))
				|| !is_string($phase)
				|| !in_array($phase, PostgreSqlMigrationProfile::PHASES, true)
				|| !is_string($description)
				|| trim($description)===''
			){
				throw new RuntimeException('PostgreSQL migration manifest entry identity is invalid.');
			}
			if($phase==='rolling_contract'){
				if(!PostgreSqlMigrationProfile::validVersion($minimumCompatibleRelease)){
					throw new RuntimeException(
						'Rolling contract migration requires an exact minimum compatible release: '.$id.'.'
					);
				}
			}elseif($minimumCompatibleRelease!==null){
				throw new RuntimeException(
					'Only rolling contract migrations may set a minimum compatible release: '.$id.'.'
				);
			}
			$up=self::sqlDirection($directory, $id, 'up', $entry['up'] ?? null);
			if($phase==='bootstrap' && $up['public_path']!==$id.'.sql'){
				throw new RuntimeException('Bootstrap migration must retain its stable legacy filename: '.$id.'.');
			}
			if($phase==='bootstrap' && $down!==null){
				throw new RuntimeException('Grandfathered bootstrap migration must remain irreversible: '.$id.'.');
			}
			if($phase!=='bootstrap' && $up['public_path']!==$id.'.up.sql'){
				throw new RuntimeException('Rolling migration must use its stable .up.sql filename: '.$id.'.');
			}
			$normalizedDown=null;
			$irreversibleReason=null;
			if($down===null){
				$irreversibleReason=$entry['irreversible_reason'] ?? null;
				if(!is_string($irreversibleReason) || trim($irreversibleReason)===''){
					throw new RuntimeException('Irreversible migration requires a reason: '.$id.'.');
				}
				$irreversibleReason=trim($irreversibleReason);
			}else{
				if(!is_array($down)){
					throw new RuntimeException('Migration down direction must be an object or null: '.$id.'.');
				}
				$downKeys=array_keys($down);
				sort($downKeys, SORT_STRING);
				$safety=$down['safety'] ?? null;
				if(
					$downKeys!==['path', 'safety', 'sha256']
					|| !is_string($safety)
					|| !in_array($safety, PostgreSqlMigrationProfile::DOWN_SAFETY, true)
				){
					throw new RuntimeException('Migration down safety contract is invalid: '.$id.'.');
				}
				$normalizedDown=self::sqlDirection(
					$directory,
					$id,
					'down',
					['path'=>$down['path'] ?? null, 'sha256'=>$down['sha256'] ?? null]
				);
				if($normalizedDown['public_path']!==$id.'.down.sql'){
					throw new RuntimeException('Migration down path must use its stable .down.sql filename: '.$id.'.');
				}
				$normalizedDown['safety']=$safety;
			}
			foreach([$up, $normalizedDown] as $direction){
				if($direction===null){
					continue;
				}
				$sqlPublicPath=$direction['public_path'];
				$seenPaths[$sqlPublicPath]=true;
			}
			if($cutoffSeen && $phase==='bootstrap'){
				throw new RuntimeException('Post-cutoff migration may not declare phase bootstrap: '.$id.'.');
			}
			if(!$cutoffSeen && $phase!=='bootstrap'){
				throw new RuntimeException('Pre-cutoff migration must declare phase bootstrap: '.$id.'.');
			}
			if($id===$profile->bootstrapCutoff()){
				$cutoffSeen=true;
			}
			$entries[]=[
				'id'=>$id,
				'phase'=>$phase,
				'up'=>$up,
				'down'=>$normalizedDown,
				'irreversible_reason'=>$irreversibleReason,
				'minimum_compatible_release'=>$minimumCompatibleRelease,
				'description'=>trim($description),
			];
		}
		if($entries===[] || !$cutoffSeen){
			throw new RuntimeException('PostgreSQL migration manifest has no complete bootstrap boundary.');
		}
		$bootstrapIds=$profile->bootstrapIds();
		if(
			$bootstrapIds!==[]
			&& array_slice(array_column($entries, 'id'), 0, count($bootstrapIds))!==$bootstrapIds
		){
			throw new RuntimeException('PostgreSQL migration bootstrap prefix does not match the profile.');
		}
		$files=glob($directory.'/*.sql') ?: [];
		$unlisted=[];
		foreach($files as $file){
			$name=basename((string)$file);
			if(!isset($seenPaths[$name])){
				$unlisted[]=$name;
			}
		}
		if($unlisted!==[]){
			sort($unlisted, SORT_STRING);
			throw new RuntimeException('Unlisted PostgreSQL migrations: '.implode(', ', $unlisted).'.');
		}
		return new self(
			$manifestPath,
			$profile->manifestPublicPath(),
			hash('sha256', $manifestBytes),
			PostgreSqlMigrationProfile::MANIFEST_SCHEMA_VERSION,
			$profile->bootstrapCutoff(),
			$entries,
			$decoded['source']
		);
	}

	public function path(): string { return $this->path; }
	public function publicPath(): string { return $this->publicPath; }
	public function sha256(): string { return $this->sha256; }
	public function schemaVersion(): int { return $this->schemaVersion; }
	public function bootstrapCutoff(): string { return $this->bootstrapCutoff; }
	/** @return list<array<string,mixed>> */
	public function entries(): array { return $this->entries; }
	/** @return array<string,mixed> */
	public function source(): array { return $this->source; }

	/** @return array<string,mixed> */
	public function publicSummary(): array {
		$phases=array_count_values(array_column($this->entries, 'phase'));
		ksort($phases, SORT_STRING);
		return [
			'path'=>$this->publicPath,
			'sha256'=>$this->sha256,
			'schema_version'=>$this->schemaVersion,
			'algorithm'=>'sha256',
			'bootstrap_cutoff'=>$this->bootstrapCutoff,
			'migration_count'=>count($this->entries),
			'phases'=>$phases,
			'source'=>$this->source,
		];
	}

	/** @return array{public_path:string,path:string,sql:string,sha256:string} */
	private static function sqlDirection(
		string $directory,
		string $id,
		string $direction,
		mixed $value
	): array {
		if(!is_array($value)){
			throw new RuntimeException('Migration '.$direction.' direction must be an object: '.$id.'.');
		}
		$keys=array_keys($value);
		sort($keys, SORT_STRING);
		if($keys!==['path', 'sha256']){
			throw new RuntimeException('Migration '.$direction.' direction keys are invalid: '.$id.'.');
		}
		$publicPath=$value['path'] ?? null;
		$checksum=$value['sha256'] ?? null;
		if(
			!is_string($publicPath)
			|| basename($publicPath)!==$publicPath
			|| preg_match('/^[0-9]{3}_[a-z0-9_]+(?:\.up|\.down)?\.sql$/D', $publicPath)!==1
			|| !is_string($checksum)
			|| preg_match('/^[a-f0-9]{64}$/D', $checksum)!==1
		){
			throw new RuntimeException('Migration '.$direction.' direction identity is invalid: '.$id.'.');
		}
		$unresolved=$directory.'/'.$publicPath;
		$path=realpath($unresolved);
		if(
			$path===false
			|| dirname($path)!==$directory
			|| is_link($unresolved)
			|| !is_file($path)
			|| !is_readable($path)
		){
			throw new RuntimeException(
				'Migration file is missing or outside the selected PostgreSQL component: '.$publicPath.'.'
			);
		}
		$sql=file_get_contents($path);
		if(!is_string($sql) || !hash_equals($checksum, hash('sha256', $sql))){
			throw new RuntimeException('Immutable migration checksum mismatch: '.$publicPath.'.');
		}
		foreach(self::sqlSafetyIssues($sql) as $issue){
			if($issue['code']==='transaction_control'){
				throw new RuntimeException(
					'Migration transaction control is owned by Dataphyre: '.$publicPath.'.'
				);
			}
			if($issue['code']==='transaction_incompatible_statement'){
				throw new RuntimeException(
					$issue['message'].' Migration: '.$publicPath.'.'
				);
			}
			if($issue['code']==='concurrent_index_operation'){
				throw new RuntimeException(
					'Concurrent index operations cannot run in the Dataphyre-owned transaction: '.
					$publicPath.'.'
				);
			}
			throw new RuntimeException(
				'psql meta-commands are not supported in migrations: '.$publicPath.'.'
			);
		}
		return [
			'public_path'=>$publicPath,
			'path'=>$path,
			'sql'=>$sql,
			'sha256'=>$checksum,
		];
	}

	/**
	 * Report SQL that cannot participate in the Dataphyre-owned transaction.
	 *
	 * @return list<array{code:string,message:string}>
	 */
	public static function sqlSafetyIssues(string $sql): array {
		$executableSql=self::executableSql($sql);
		$issues=[];
		$transactionControlPattern=
			'/(?:^|;)\s*(?:BEGIN|START\s+TRANSACTION|COMMIT|END|ABORT|ROLLBACK|'.
			'SAVEPOINT|RELEASE|PREPARE\s+TRANSACTION|SET\s+TRANSACTION|'.
			'SET\s+SESSION\s+CHARACTERISTICS\s+AS\s+TRANSACTION)\b/i';
		if(preg_match($transactionControlPattern, $executableSql)===1){
			$issues[]=[
				'code'=>'transaction_control',
				'message'=>'Transaction control is owned by the migration runner.',
			];
		}
		$transactionIncompatiblePattern=
			'/(?:^|;)\s*(?:'.
			'VACUUM\b|'.
			'CREATE\s+DATABASE\b|DROP\s+DATABASE\b|'.
			'ALTER\s+SYSTEM\b|'.
			'CREATE\s+TABLESPACE\b|DROP\s+TABLESPACE\b|'.
			'CLUSTER\b|CHECKPOINT\b|DISCARD\s+ALL\b|'.
			'REFRESH\s+MATERIALIZED\s+VIEW\s+CONCURRENTLY\b|'.
			'REINDEX(?:\s*\([^;]*\))?\s+(?:DATABASE|SYSTEM|TABLESPACE)\b'.
			')/i';
		if(preg_match($transactionIncompatiblePattern, $executableSql)===1){
			$issues[]=[
				'code'=>'transaction_incompatible_statement',
				'message'=>
					'PostgreSQL statement cannot run inside the Dataphyre-owned transaction.',
			];
		}
		if(
			preg_match('/\bCREATE\s+(?:UNIQUE\s+)?INDEX\s+CONCURRENTLY\b/i', $executableSql)===1
			|| preg_match('/\bDROP\s+INDEX\s+CONCURRENTLY\b/i', $executableSql)===1
			|| preg_match('/\bREINDEX\b[^;]*\bCONCURRENTLY\b/i', $executableSql)===1
		){
			$issues[]=[
				'code'=>'concurrent_index_operation',
				'message'=>'Concurrent index operations require an external autocommit protocol.',
			];
		}
		if(preg_match('/^\s*\\\\/m', $executableSql)===1){
			$issues[]=[
				'code'=>'psql_meta_command',
				'message'=>'psql meta-commands are not executable migration SQL.',
			];
		}
		return $issues;
	}

	/**
	 * Masks comments, literals, dollar-quoted bodies, and identifier contents
	 * while retaining executable PostgreSQL statement boundaries.
	 */
	private static function executableSql(string $sql): string {
		$code='';
		$length=strlen($sql);
		for($index=0; $index<$length;){
			if(substr($sql, $index, 2)==='--'){
				$end=strpos($sql, "\n", $index+2);
				if($end===false){
					$code.=str_repeat(' ', $length-$index);
					break;
				}
				$code.=str_repeat(' ', $end-$index)."\n";
				$index=$end+1;
				continue;
			}
			if(substr($sql, $index, 2)==='/*'){
				$start=$index;
				$depth=1;
				$index+=2;
				while($index<$length && $depth>0){
					if(substr($sql, $index, 2)==='/*'){
						$depth++;
						$index+=2;
						continue;
					}
					if(substr($sql, $index, 2)==='*/'){
						$depth--;
						$index+=2;
						continue;
					}
					$index++;
				}
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			$character=$sql[$index];
			if($character==="'"){
				$start=$index++;
					while($index<$length){
					if($sql[$index]==="'" && ($sql[$index+1] ?? null)==="'"){
						$index+=2;
						continue;
					}
					if($sql[$index]==="'"){
						$index++;
						break;
					}
					if($sql[$index]==='\\' && $index+1<$length){
						$index+=2;
						continue;
					}
					$index++;
				}
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			if(
				$character==='$'
				&& preg_match(
					'/^\$(?:[A-Za-z_][A-Za-z0-9_]*)?\$/',
					substr($sql, $index),
					$tag
				)===1
			){
				$start=$index;
				$index+=strlen($tag[0]);
				$end=strpos($sql, $tag[0], $index);
				$index=$end===false ? $length : $end+strlen($tag[0]);
				$masked=substr($sql, $start, $index-$start);
				$code.=(string)preg_replace('/[^\r\n]/', ' ', $masked);
				continue;
			}
			if($character==='"'){
				$code.='"';
				$index++;
				while($index<$length){
					if($sql[$index]==='"' && ($sql[$index+1] ?? null)==='"'){
						$code.='xx';
						$index+=2;
						continue;
					}
					if($sql[$index]==='"'){
						$code.='"';
						$index++;
						break;
					}
						$code.=ctype_space($sql[$index]) ? $sql[$index] : 'x';
						$index++;
					}
				}else{
					$code.=$character;
					$index++;
				}
			}
		return $code;
	}
}
