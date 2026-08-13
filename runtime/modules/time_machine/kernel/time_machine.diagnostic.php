<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\time_machine;

/** Collects Time Machine dependency and journal-storage diagnostics. */
class diagnostic {
	/** @param array<string,mixed> $observations @return list<array<string,mixed>> */
	public static function tests(array $observations=[]): array {
		$required=is_callable($observations['module_required'] ?? null) ? $observations['module_required'] : '\\dp_module_required';
		$extensionLoaded=is_callable($observations['extension_loaded'] ?? null) ? $observations['extension_loaded'] : 'extension_loaded';
		$clock=is_callable($observations['clock'] ?? null) ? $observations['clock'] : 'time';
		$phpVersion=(string)($observations['php_version'] ?? PHP_VERSION);
		$sqlQuery=array_key_exists('sql_query', $observations)
			? $observations['sql_query']
			: (\function_exists('sql_query') ? '\\sql_query' : null);
		$verbose=[];
		$required('time_machine', 'sql');
		$required('time_machine', 'access');
		if(version_compare($phpVersion, $minimum='8.1.0')<0){
			$verbose[]=['module'=>'time_machine', 'error'=>'PHP version '.$minimum.' or higher is required.', 'time'=>(int)$clock()];
		}
		foreach(['json','date','standard','session'] as $extension){
			if(!$extensionLoaded($extension)){
				$verbose[]=['module'=>'time_machine', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>(int)$clock()];
			}
		}
		if(!is_callable($sqlQuery)){
			$verbose[]=[
				'module'=>'time_machine',
				'level'=>'warning',
				'message'=>'SQL-backed Time Machine table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>(int)$clock(),
			];
		}else{
			$sqlQuery([
				'mysql'=>self::mysqlSchema(),
				'postgresql'=>self::postgresqlSchema(),
				'sqlite'=>self::sqliteSchema(),
			]);
		}
		if(array_key_exists('publish', $observations)){
			if(is_callable($observations['publish'])){
				$observations['publish']($verbose);
			}
		}elseif(class_exists('\\dataphyre\\dpanel')){
			\dataphyre\dpanel::add_verbose($verbose);
		}
		return $verbose;
	}

	private static function mysqlSchema(): string {
		return 'CREATE TABLE IF NOT EXISTS `dataphyre`.`user_changes` (`changeid` VARCHAR(36) NOT NULL PRIMARY KEY, `type` VARCHAR(64) NOT NULL, `rollback_type` VARCHAR(64) NOT NULL, `can_rollback` BOOLEAN DEFAULT FALSE, `userid` INT NOT NULL, `data` LONGTEXT NOT NULL, `executor` LONGTEXT NOT NULL, `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, `rollback` BOOLEAN DEFAULT FALSE, `rollback_by` INT DEFAULT NULL, `rollback_time` TIMESTAMP NULL DEFAULT NULL); CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON `dataphyre`.`user_changes` (`userid`); CREATE INDEX IF NOT EXISTS idx_user_changes_time ON `dataphyre`.`user_changes` (`time`); CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON `dataphyre`.`user_changes` (`rollback`);';
	}

	private static function postgresqlSchema(): string {
		return 'CREATE EXTENSION IF NOT EXISTS "pgcrypto"; CREATE TABLE IF NOT EXISTS dataphyre.user_changes (changeid UUID PRIMARY KEY DEFAULT gen_random_uuid(), type VARCHAR(64) NOT NULL, rollback_type VARCHAR(64) NOT NULL, can_rollback BOOLEAN DEFAULT FALSE, userid INTEGER NOT NULL, data TEXT NOT NULL, executor TEXT NOT NULL, time TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP, rollback BOOLEAN DEFAULT FALSE, rollback_by INTEGER DEFAULT NULL, rollback_time TIMESTAMPTZ DEFAULT NULL); CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON dataphyre.user_changes (userid); CREATE INDEX IF NOT EXISTS idx_user_changes_time ON dataphyre.user_changes (time); CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON dataphyre.user_changes (rollback);';
	}

	private static function sqliteSchema(): string {
		return 'CREATE TABLE IF NOT EXISTS dataphyre_user_changes (changeid TEXT PRIMARY KEY, type TEXT NOT NULL, rollback_type TEXT NOT NULL, can_rollback INTEGER DEFAULT 0, userid INTEGER NOT NULL, data TEXT NOT NULL, executor TEXT NOT NULL, time TEXT NOT NULL DEFAULT (datetime(\'now\')), rollback INTEGER DEFAULT 0, rollback_by INTEGER DEFAULT NULL, rollback_time TEXT DEFAULT NULL); CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON dataphyre_user_changes (userid); CREATE INDEX IF NOT EXISTS idx_user_changes_time ON dataphyre_user_changes (time); CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON dataphyre_user_changes (rollback);';
	}
}

if(!defined('DATAPHYRE_TIME_MACHINE_DIAGNOSTIC_NO_DISPATCH')){
	diagnostic::tests();
}
