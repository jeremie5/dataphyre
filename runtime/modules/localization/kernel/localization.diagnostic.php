<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\localization;

\dataphyre\localization\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		$verbose=[];
		// Runtime information
		\dp_module_required('localization', 'sql');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'localization', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for module
		$required_extensions=[
			'json',
			'session',
			'hash',
			'date',
			'standard',
			'pcre',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'localization', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'localization',
				'level'=>'warning',
				'message'=>'SQL-backed Localization table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
		}
		else
		{
			\sql_query(
				$Q = [
					"mysql" => "
						CREATE TABLE IF NOT EXISTS `locales` (
							`id` VARCHAR(36) NOT NULL PRIMARY KEY,
							`lang` VARCHAR(10) NOT NULL,
							`name` TEXT NOT NULL,
							`string` TEXT NOT NULL,
							`type` ENUM('global', 'theme', 'local') NOT NULL,
							`theme` TEXT DEFAULT NULL,
							`path` TEXT DEFAULT NULL,
							`edit_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
						) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
						CREATE INDEX IF NOT EXISTS idx_locales_lang ON `locales` (`lang`);
						CREATE INDEX IF NOT EXISTS idx_locales_type ON `locales` (`type`);
						CREATE INDEX IF NOT EXISTS idx_locales_edit_time ON `locales` (`edit_time`);
					",
					"postgresql" => "
						CREATE EXTENSION IF NOT EXISTS \"pgcrypto\";
						CREATE TABLE IF NOT EXISTS \"locales\" (
							id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
							lang VARCHAR(10) NOT NULL,
							name TEXT NOT NULL,
							string TEXT NOT NULL,
							type TEXT CHECK (type IN ('global', 'theme', 'local')) NOT NULL,
							theme TEXT,
							path TEXT,
							edit_time TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
						);
						CREATE INDEX IF NOT EXISTS idx_locales_lang ON \"locales\" (lang);
						CREATE INDEX IF NOT EXISTS idx_locales_type ON \"locales\" (type);
						CREATE INDEX IF NOT EXISTS idx_locales_edit_time ON \"locales\" (edit_time);
					",
					"sqlite" => "
						CREATE TABLE IF NOT EXISTS \"locales\" (
							id TEXT PRIMARY KEY,
							lang TEXT NOT NULL,
							name TEXT NOT NULL,
							string TEXT NOT NULL,
							type TEXT NOT NULL CHECK (type IN ('global', 'theme', 'local')),
							theme TEXT,
							path TEXT,
							edit_time TEXT NOT NULL DEFAULT (datetime('now'))
						);
						CREATE INDEX IF NOT EXISTS idx_locales_lang ON \"locales\" (lang);
						CREATE INDEX IF NOT EXISTS idx_locales_type ON \"locales\" (type);
						CREATE INDEX IF NOT EXISTS idx_locales_edit_time ON \"locales\" (edit_time);
					"
				]
			);
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
