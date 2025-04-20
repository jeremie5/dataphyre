<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */
 
namespace dataphyre\localization;

\dataphyre\localization\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		// Runtime information
		dp_module_required('localization', 'sql');
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
			'pcre ',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'localization', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		sql_query(
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
        \dataphyre\dpanel::add_verbose($verbose);
    }

}