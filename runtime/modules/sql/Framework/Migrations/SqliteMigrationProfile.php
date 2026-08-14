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
use JsonException;

/**
 * Fixed application-owned metadata for a Dataphyre-managed SQLite file.
 *
 * The database filename is deliberately a basename. The host selects the
 * writable data root through DATAPHYRE_APPLICATION_DATA_ROOT; an application
 * cannot redirect the migration command elsewhere on the filesystem.
 */
final class SqliteMigrationProfile {
	private const MAX_BYTES=65536;
	private const MANIFEST_PATH='database/sqlite/manifest.json';

	private function __construct(
		private readonly string $applicationId,
		private readonly string $databaseFile,
		private readonly string $journalTable,
	){}

	public static function load(string $projectRoot, string $applicationId): self {
		$path=$projectRoot.'/database/sqlite/profile.json';
		[$document,$profileBytes]=self::readJsonObject($path);
		foreach(['format','application_id','database_file','journal_table'] as $field){
			if(self::jsonKeyCount($profileBytes, $field)!==1){
				throw new InvalidArgumentException('SQLite migration profile contains duplicate or missing fields.');
			}
		}
		if(!self::hasExactFields($document, [
			'format', 'application_id', 'database_file', 'journal_table',
		])){
			throw new InvalidArgumentException('SQLite migration profile fields are invalid.');
		}
		$declaredApplication=$document['application_id'] ?? null;
		$databaseFile=$document['database_file'] ?? null;
		$journalTable=$document['journal_table'] ?? null;
		if(
			($document['format'] ?? null)!==1
			|| !is_string($declaredApplication)
			|| !hash_equals($applicationId, $declaredApplication)
			|| !is_string($databaseFile)
			|| preg_match('/^[a-z0-9][a-z0-9._-]{0,119}\.sqlite$/D', $databaseFile)!==1
			|| !is_string($journalTable)
			|| preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $journalTable)!==1
			|| str_starts_with($journalTable, 'sqlite_')
		){
			throw new InvalidArgumentException('SQLite migration profile values are invalid.');
		}
		return new self($declaredApplication, $databaseFile, $journalTable);
	}

	public function applicationId(): string {return $this->applicationId;}
	public function databaseFile(): string {return $this->databaseFile;}
	public function journalTable(): string {return $this->journalTable;}
	public function manifestPublicPath(): string {return self::MANIFEST_PATH;}

	/** @return array{array<string,mixed>,string} */
	private static function readJsonObject(string $path): array {
		if(is_link($path) || !is_file($path) || !is_readable($path)){
			throw new InvalidArgumentException('SQLite migration profile is unavailable.');
		}
		$details=stat($path);
		$permissions=fileperms($path);
		if(!is_array($details) || ($details['nlink'] ?? 0)!==1
			|| !is_int($permissions) || ($permissions & 0444)===0){
			throw new InvalidArgumentException('SQLite migration profile is unavailable.');
		}
		$size=filesize($path);
		if(!is_int($size) || $size<2 || $size>self::MAX_BYTES){
			throw new InvalidArgumentException('SQLite migration profile size is invalid.');
		}
		$bytes=file_get_contents($path);
		if(!is_string($bytes) || strlen($bytes)!==$size){
			throw new InvalidArgumentException('SQLite migration profile cannot be read.');
		}
		try{
			$document=json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
		}catch(JsonException $error){
			throw new InvalidArgumentException('SQLite migration profile JSON is invalid.', 0, $error);
		}
		if(!is_array($document) || array_is_list($document)){
			throw new InvalidArgumentException('SQLite migration profile must be an object.');
		}
		return [$document,$bytes];
	}

	private static function jsonKeyCount(string $bytes, string $key): int {
		$count=preg_match_all('/"'.preg_quote($key,'/').'"\s*:/D', $bytes);
		return is_int($count) ? $count : 0;
	}

	/** @param array<string,mixed> $document @param list<string> $fields */
	private static function hasExactFields(array $document, array $fields): bool {
		$actual=array_keys($document);
		sort($actual, SORT_STRING);
		sort($fields, SORT_STRING);
		return $actual===$fields;
	}
}
