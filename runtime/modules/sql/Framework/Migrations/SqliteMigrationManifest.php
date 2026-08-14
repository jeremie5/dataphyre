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

/** Loads an ordered, content-addressed list of declarative SQLite SQL files. */
final class SqliteMigrationManifest {
	private const MAX_MANIFEST_BYTES=4194304;
	private const MAX_MIGRATION_BYTES=2097152;
	private const MAX_TOTAL_MIGRATION_BYTES=8388608;
	private const MAX_MIGRATIONS=999;

	/** @param list<array{id:string,path:string,sha256:string,sql:string}> $entries */
	private function __construct(
		private readonly array $entries,
		private readonly string $sha256,
	){}

	public static function load(string $projectRoot, SqliteMigrationProfile $profile): self {
		$root=$projectRoot.'/database/sqlite';
		if(is_link($projectRoot.'/database') || is_link($root) || !is_dir($root) || !is_readable($root)){
			throw new InvalidArgumentException('SQLite migration directory is invalid.');
		}
		$manifestPath=$projectRoot.'/'.$profile->manifestPublicPath();
		$manifestBytes=self::readRegularFile($manifestPath, self::MAX_MANIFEST_BYTES, 'manifest');
		$document=self::decodeManifest($manifestBytes);
		if(!self::hasExactFields($document, ['schema_version','algorithm','migrations'])
			|| ($document['schema_version'] ?? null)!==1
			|| ($document['algorithm'] ?? null)!=='sha256'
			|| !is_array($document['migrations'] ?? null)
			|| !array_is_list($document['migrations'])
			|| $document['migrations']===[]
			|| count($document['migrations'])>self::MAX_MIGRATIONS
		){
			throw new InvalidArgumentException('SQLite migration manifest shape is invalid.');
		}
		foreach(['schema_version','algorithm','migrations'] as $field){
			if(self::jsonKeyCount($manifestBytes,$field)!==1){
				throw new InvalidArgumentException('SQLite migration manifest contains duplicate or missing fields.');
			}
		}
		$entryCount=count($document['migrations']);
		foreach(['id','path','sha256'] as $field){
			if(self::jsonKeyCount($manifestBytes,$field)!==$entryCount){
				throw new InvalidArgumentException('SQLite migration entries contain duplicate or missing fields.');
			}
		}

		$entries=[];
		$listed=[];
		$totalSqlBytes=0;
		foreach($document['migrations'] as $index=>$row){
			if(!is_array($row) || array_is_list($row)
				|| !self::hasExactFields($row, ['id','path','sha256'])){
				throw new InvalidArgumentException('SQLite migration entry is invalid.');
			}
			$id=$row['id'] ?? null;
			$path=$row['path'] ?? null;
			$sha256=$row['sha256'] ?? null;
			$prefix=str_pad((string)($index+1), 3, '0', STR_PAD_LEFT).'_';
			if(
				!is_string($id)
				|| !str_starts_with($id, $prefix)
				|| preg_match('/^[0-9]{3}_[a-z0-9][a-z0-9_]{0,95}$/D', $id)!==1
				|| !is_string($path)
				|| !hash_equals($id.'.sql', $path)
				|| !is_string($sha256)
				|| preg_match('/^[a-f0-9]{64}$/D', $sha256)!==1
				|| isset($listed[$path])
			){
				throw new InvalidArgumentException('SQLite migrations must be unique and contiguous.');
			}
			$sqlPath=$root.'/'.$path;
			$sql=self::readSql($sqlPath);
			$totalSqlBytes+=strlen($sql);
			if($totalSqlBytes>self::MAX_TOTAL_MIGRATION_BYTES){
				throw new InvalidArgumentException('SQLite migration SQL exceeds the aggregate size bound.');
			}
			if(!hash_equals($sha256, hash('sha256', $sql))){
				throw new InvalidArgumentException('SQLite migration checksum verification failed.');
			}
			$entries[]=['id'=>$id, 'path'=>$path, 'sha256'=>$sha256, 'sql'=>$sql];
			$listed[$path]=true;
		}

		$disk=[];
		$seenEntries=0;
		foreach(new \DirectoryIterator($root) as $item){
			if($item->isDot()) continue;
			$seenEntries++;
			if($seenEntries>2048) throw new InvalidArgumentException('SQLite migration directory contains too many entries.');
			$name=$item->getFilename();
			if(str_ends_with(strtolower($name),'.sql')){
				if($item->isLink() || !$item->isFile()){
					throw new InvalidArgumentException('SQLite migration SQL inventory contains a special file.');
				}
				$disk[]=$name;
			}
		}
		sort($disk, SORT_STRING);
		$expected=array_keys($listed);
		sort($expected, SORT_STRING);
		if($disk!==$expected){
			throw new InvalidArgumentException('Unlisted SQLite migration SQL files are not allowed.');
		}
		$manifestSha=hash('sha256',$manifestBytes);
		return new self($entries, $manifestSha);
	}

	/** @return list<array{id:string,path:string,sha256:string,sql:string}> */
	public function entries(): array {return $this->entries;}
	public function sha256(): string {return $this->sha256;}

	/** @return array<string,mixed> */
	private static function decodeManifest(string $bytes): array {
		try{
			$document=json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
		}catch(JsonException $error){
			throw new InvalidArgumentException('SQLite migration manifest JSON is invalid.', 0, $error);
		}
		if(!is_array($document) || array_is_list($document)){
			throw new InvalidArgumentException('SQLite migration manifest must be an object.');
		}
		return $document;
	}

	private static function readSql(string $path): string {
		$sql=self::readRegularFile($path, self::MAX_MIGRATION_BYTES, 'SQL');
		if(trim($sql)==='' || preg_match('//u', $sql)!==1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $sql)===1){
			throw new InvalidArgumentException('SQLite migration SQL encoding is invalid.');
		}
		return $sql;
	}

	private static function readRegularFile(string $path, int $maximum, string $kind): string {
		if(is_link($path) || !is_file($path) || !is_readable($path)){
			throw new InvalidArgumentException('SQLite migration '.$kind.' file is unavailable.');
		}
		$details=stat($path);
		$permissions=fileperms($path);
		if(!is_array($details) || ($details['nlink'] ?? 0)!==1
			|| !is_int($permissions) || ($permissions & 0444)===0){
			throw new InvalidArgumentException('SQLite migration '.$kind.' file is unavailable.');
		}
		$size=filesize($path);
		if(!is_int($size) || $size<1 || $size>$maximum){
			throw new InvalidArgumentException('SQLite migration '.$kind.' file size is invalid.');
		}
		$bytes=file_get_contents($path);
		if(!is_string($bytes) || strlen($bytes)!==$size){
			throw new InvalidArgumentException('SQLite migration '.$kind.' file cannot be read.');
		}
		return $bytes;
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
