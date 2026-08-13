<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Database\Migrations;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable application policy for Dataphyre's PostgreSQL migration engine.
 *
 * Migration mechanics belong to Dataphyre while application schema names,
 * grandfathered bootstrap identities, journals, locks, and release paths remain
 * explicit application-owned inputs.
 */
final class PostgreSqlMigrationProfile implements JsonSerializable {
	public const MANIFEST_SCHEMA_VERSION=3;
	public const PHASES=['bootstrap', 'rolling_expand', 'rolling_contract'];
	public const CHANGE_KINDS=['schema', 'data_only'];
	public const DOWN_SAFETY=['lossless', 'data_loss'];
	private const EVENT_FIXED_COLUMNS=[
		'event_id',
		'operation_id',
		'migration_id',
		'direction',
		'up_checksum_sha256',
		'down_checksum_sha256',
		'release_version',
		'occurred_at',
	];
	public const VERSION_PATTERN='/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:(?:0|[1-9][0-9]*)|(?:[0-9]*[A-Za-z-][0-9A-Za-z-]*))(?:\.(?:(?:0|[1-9][0-9]*)|(?:[0-9]*[A-Za-z-][0-9A-Za-z-]*)))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
	public const MIGRATION_ID_PATTERN='/^[0-9]{3}_[a-z0-9_]{1,124}$/D';

	private string $applicationId;
	private string $schema;
	private string $journalTable;
	private string $eventTable;
	private string $releaseDigestColumn;
	private string $advisoryLock;
	/** @var list<string> */
	private array $bootstrapIds;
	private string $bootstrapCutoff;
	private string $manifestPublicPath;
	private string $lockTimeout;
	private string $statementTimeout;

	/** @param array<string,mixed> $config */
	private function __construct(array $config) {
		$keys=array_keys($config);
		$allowed=[
			'application_id', 'schema', 'journal_table', 'event_table',
			'release_digest_column', 'advisory_lock', 'bootstrap_ids', 'bootstrap_cutoff',
			'manifest_public_path', 'lock_timeout', 'statement_timeout',
		];
		$unknown=array_values(array_diff($keys, $allowed));
		if($unknown!==[]){
			throw new InvalidArgumentException(
				'Unknown PostgreSQL migration profile keys: '.implode(', ', $unknown).'.'
			);
		}
		$this->applicationId=self::identifier(
			(string)($config['application_id'] ?? ''),
			'application id'
		);
		$this->schema=self::identifier((string)($config['schema'] ?? ''), 'schema');
		$this->journalTable=self::identifier(
			(string)($config['journal_table'] ?? 'schema_migrations'),
			'journal table'
		);
		$this->eventTable=self::identifier(
			(string)($config['event_table'] ?? 'schema_migration_events'),
			'event table'
		);
		if($this->journalTable===$this->eventTable){
			throw new InvalidArgumentException('Migration journal and event table must be distinct.');
		}
		$this->releaseDigestColumn=self::identifier(
			(string)($config['release_digest_column'] ?? 'release_sha256'),
			'release digest column'
		);
		if(in_array(strtolower($this->releaseDigestColumn), self::EVENT_FIXED_COLUMNS, true)){
			throw new InvalidArgumentException(
				'PostgreSQL migration release digest column conflicts with a fixed event column.'
			);
		}
		$this->advisoryLock=trim((string)($config['advisory_lock'] ?? ''));
		if(
			$this->advisoryLock===''
			|| strlen($this->advisoryLock)>191
			|| preg_match('/[\x00-\x1f\x7f]/', $this->advisoryLock)===1
		){
			throw new InvalidArgumentException('PostgreSQL migration advisory lock key is invalid.');
		}
		$bootstrapIds=$config['bootstrap_ids'] ?? [];
		if(!is_array($bootstrapIds) || !array_is_list($bootstrapIds)){
			throw new InvalidArgumentException('Migration bootstrap IDs must be a list.');
		}
		$this->bootstrapIds=[];
		foreach($bootstrapIds as $offset=>$id){
			$id=(string)$id;
			if(
				preg_match(self::MIGRATION_ID_PATTERN, $id)!==1
				|| !str_starts_with($id, sprintf('%03d_', $offset+1))
				|| in_array($id, $this->bootstrapIds, true)
			){
				throw new InvalidArgumentException('Migration bootstrap IDs are invalid or out of order.');
			}
			$this->bootstrapIds[]=$id;
		}
		$this->bootstrapCutoff=(string)($config['bootstrap_cutoff'] ?? '');
		if(preg_match(self::MIGRATION_ID_PATTERN, $this->bootstrapCutoff)!==1){
			throw new InvalidArgumentException('Migration bootstrap cutoff must be one stable migration ID.');
		}
		if(
			$this->bootstrapIds!==[]
			&& $this->bootstrapIds[array_key_last($this->bootstrapIds)]!==$this->bootstrapCutoff
		){
			throw new InvalidArgumentException('Migration bootstrap cutoff must be the final bootstrap ID.');
		}
		$this->manifestPublicPath=self::relativePath(
			(string)($config['manifest_public_path'] ?? 'database/postgresql/manifest.json')
		);
		$this->lockTimeout=self::timeout(
			(string)($config['lock_timeout'] ?? '5s'),
			'lock timeout'
		);
		$this->statementTimeout=self::timeout(
			(string)($config['statement_timeout'] ?? '120s'),
			'statement timeout'
		);
	}

	/** @param array<string,mixed> $config */
	public static function fromArray(array $config): self {
		return new self($config);
	}

	public function applicationId(): string { return $this->applicationId; }
	public function schema(): string { return $this->schema; }
	public function journalTable(): string { return $this->journalTable; }
	public function eventTable(): string { return $this->eventTable; }
	public function releaseDigestColumn(): string { return $this->releaseDigestColumn; }
	public function advisoryLock(): string { return $this->advisoryLock; }
	/** @return list<string> */
	public function bootstrapIds(): array { return $this->bootstrapIds; }
	public function bootstrapCutoff(): string { return $this->bootstrapCutoff; }
	public function manifestPublicPath(): string { return $this->manifestPublicPath; }
	public function lockTimeout(): string { return $this->lockTimeout; }
	public function statementTimeout(): string { return $this->statementTimeout; }

	public function journalQualified(): string {
		return $this->qualified($this->journalTable);
	}

	public function eventQualified(): string {
		return $this->qualified($this->eventTable);
	}

	public function journalRegclass(): string {
		return self::quoteIdentifier($this->schema).'.'.self::quoteIdentifier($this->journalTable);
	}

	public function eventRegclass(): string {
		return self::quoteIdentifier($this->schema).'.'.self::quoteIdentifier($this->eventTable);
	}

	public function qualified(string $object): string {
		$object=self::identifier($object, 'schema object');
		return self::quoteIdentifier($this->schema).'.'.self::quoteIdentifier($object);
	}

	public function constraintName(string $suffix): string {
		$suffix=self::identifier($suffix, 'constraint suffix');
		$base=$this->applicationId.'_'.$suffix;
		if(strlen($base)<=63){
			return $base;
		}
		return substr($base, 0, 54).'_'.substr(hash('sha256', $base), 0, 8);
	}

	public function lockEvidence(): string {
		return 'pg_advisory_xact_lock:'.$this->advisoryLock;
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'application_id'=>$this->applicationId,
			'schema'=>$this->schema,
			'journal_table'=>$this->journalTable,
			'event_table'=>$this->eventTable,
			'release_digest_column'=>$this->releaseDigestColumn,
			'advisory_lock'=>$this->advisoryLock,
			'bootstrap_ids'=>$this->bootstrapIds,
			'bootstrap_cutoff'=>$this->bootstrapCutoff,
			'manifest_public_path'=>$this->manifestPublicPath,
			'lock_timeout'=>$this->lockTimeout,
			'statement_timeout'=>$this->statementTimeout,
		];
	}

	public static function validVersion(?string $version): bool {
		return is_string($version) && preg_match(self::VERSION_PATTERN, $version)===1;
	}

	/**
	 * Compare two exact Semantic Versions without giving build metadata
	 * precedence. Returns -1, 0, or 1.
	 */
	public static function compareVersions(string $left, string $right): int {
		if(!self::validVersion($left) || !self::validVersion($right)){
			throw new InvalidArgumentException(
				'PostgreSQL migration version comparison requires exact semantic versions.'
			);
		}
		[$leftCore,$leftPrerelease]=self::versionPrecedenceComponents($left);
		[$rightCore,$rightPrerelease]=self::versionPrecedenceComponents($right);
		foreach([0, 1, 2] as $index){
			$comparison=self::compareNumericVersionPart(
				$leftCore[$index],
				$rightCore[$index]
			);
			if($comparison!==0){
				return $comparison;
			}
		}
		if($leftPrerelease===null || $rightPrerelease===null){
			return $leftPrerelease===null
				? ($rightPrerelease===null ? 0 : 1)
				: -1;
		}
		$shared=min(count($leftPrerelease), count($rightPrerelease));
		for($index=0; $index<$shared; $index++){
			$leftPart=$leftPrerelease[$index];
			$rightPart=$rightPrerelease[$index];
			$leftNumeric=preg_match('/^[0-9]+$/D', $leftPart)===1;
			$rightNumeric=preg_match('/^[0-9]+$/D', $rightPart)===1;
			if($leftNumeric && $rightNumeric){
				$comparison=self::compareNumericVersionPart($leftPart, $rightPart);
			}elseif($leftNumeric!==$rightNumeric){
				$comparison=$leftNumeric ? -1 : 1;
			}else{
				$comparison=strcmp($leftPart, $rightPart)<=>0;
			}
			if($comparison!==0){
				return $comparison;
			}
		}
		return count($leftPrerelease)<=>count($rightPrerelease);
	}

	/** @return array{0:list<string>,1:?list<string>} */
	private static function versionPrecedenceComponents(string $version): array {
		$withoutBuild=explode('+', $version, 2)[0];
		$parts=explode('-', $withoutBuild, 2);
		return [
			explode('.', $parts[0]),
			isset($parts[1]) ? explode('.', $parts[1]) : null,
		];
	}

	private static function compareNumericVersionPart(string $left, string $right): int {
		$lengthComparison=strlen($left)<=>strlen($right);
		return $lengthComparison!==0 ? $lengthComparison : strcmp($left, $right)<=>0;
	}

	private static function identifier(string $value, string $label): string {
		$value=trim($value);
		if(preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/D', $value)!==1){
			throw new InvalidArgumentException('PostgreSQL migration '.$label.' is invalid.');
		}
		return $value;
	}

	private static function quoteIdentifier(string $identifier): string {
		return '"'.str_replace('"', '""', $identifier).'"';
	}

	private static function relativePath(string $path): string {
		$path=str_replace('\\', '/', trim($path));
		if(
			$path===''
			|| str_starts_with($path, '/')
			|| preg_match('/^[A-Za-z]:\//D', $path)===1
			|| preg_match('/[\x00-\x1f\x7f]/', $path)===1
		){
			throw new InvalidArgumentException('Migration manifest public path must be relative.');
		}
		foreach(explode('/', $path) as $segment){
			if($segment==='' || $segment==='.' || $segment==='..'){
				throw new InvalidArgumentException('Migration manifest public path is unsafe.');
			}
		}
		return $path;
	}

	private static function timeout(string $value, string $label): string {
		$value=strtolower(trim($value));
		if(preg_match('/^(?:[1-9][0-9]{0,5})(?:ms|s|min)$/D', $value)!==1){
			throw new InvalidArgumentException('PostgreSQL migration '.$label.' is invalid.');
		}
		return $value;
	}
}
