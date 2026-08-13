<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace dataphyre\tracelog;

/** Verifies Tracelog prerequisites from explicit, independently testable observations. */
final class diagnostic {
	/** @param array<string,mixed> $runtime @return list<array<string,mixed>> */
	public static function tests(array $runtime=[]): array {
		$required=$runtime['require_module'] ?? 'dp_module_required';
		$publish=$runtime['publish'] ?? [\dataphyre\dpanel::class, 'add_verbose'];
		if(!is_callable($required) || !is_callable($publish)){
			throw new \LogicException('Tracelog diagnostic boundaries must be callable.');
		}
		$required('tracelog', 'sql');
		$verbose=[];
		$version=(string)($runtime['php_version'] ?? PHP_VERSION);
		$now=(int)($runtime['time'] ?? time());
		if(version_compare($version, '8.1.0', '<')){
			$verbose[]=['module'=>'tracelog','error'=>'PHP version 8.1.0 or higher is required.','time'=>$now];
		}
		$extensions=$runtime['extensions'] ?? ['session','json','date','pcre','standard','Reflection','Core','filesystem'];
		$loaded=$runtime['extension_loaded'] ?? 'extension_loaded';
		if(!is_callable($loaded)){
			throw new \LogicException('Tracelog extension observation must be callable.');
		}
		foreach(is_array($extensions) ? $extensions : [] as $extension){
			if(!$loaded((string)$extension)){
				$verbose[]=['module'=>'tracelog','error'=>"PHP extension '{$extension}' is not loaded.",'time'=>$now];
			}
		}
		$query=$runtime['sql_query'] ?? (\function_exists('sql_query') ? 'sql_query' : null);
		if(!is_callable($query)){
			$verbose[]=[
				'module'=>'tracelog',
				'level'=>'warning',
				'message'=>'SQL-backed Tracelog table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>$now,
			];
		}else{
			$query(self::schemaQueries());
		}
		$publish($verbose);
		return $verbose;
	}

	/** @return array{mysql:string,postgresql:string,sqlite:string} */
	public static function schemaQueries(): array {
		return [
			'mysql'=>'CREATE TABLE IF NOT EXISTS `dataphyre`.`tracelogs` (`rqid` VARCHAR(64) PRIMARY KEY NOT NULL, `log` LONGTEXT NOT NULL, `server` VARCHAR(64) DEFAULT NULL, `app` VARCHAR(64) DEFAULT NULL, `date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP); CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON `dataphyre`.`tracelogs` (`date`);',
			'postgresql'=>'CREATE TABLE IF NOT EXISTS dataphyre.tracelogs (rqid VARCHAR(64) PRIMARY KEY, log TEXT NOT NULL, server VARCHAR(64), app VARCHAR(64), date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP); CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON dataphyre.tracelogs (date);',
			'sqlite'=>"CREATE TABLE IF NOT EXISTS dataphyre_tracelogs (rqid TEXT PRIMARY KEY, log TEXT NOT NULL, server TEXT, app TEXT, date TEXT NOT NULL DEFAULT (datetime('now'))); CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON dataphyre_tracelogs (date);",
		];
	}

	/** @param array<string,mixed> $runtime */
	public static function bootstrap(?bool $dispatch=null, array $runtime=[]): array {
		$dispatch ??=!defined('DATAPHYRE_TRACELOG_DIAGNOSTIC_NO_DISPATCH');
		return $dispatch ? self::tests($runtime) : [];
	}
}

diagnostic::bootstrap();
