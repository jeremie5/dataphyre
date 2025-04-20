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
 
namespace dataphyre\time_machine;

\dataphyre\time_machine\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		// Runtime information
		dp_module_required('time_machine', 'sql');
		dp_module_required('time_machine', 'access');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'time_machine', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for module
		$required_extensions=[
			'json',
			'date',
			'standard',
			'session',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'time_machine', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		sql_query(
			$Q = [
				"mysql" => "
					CREATE TABLE IF NOT EXISTS `dataphyre`.`user_changes` (
						`changeid` VARCHAR(36) NOT NULL PRIMARY KEY,
						`type` VARCHAR(64) NOT NULL,
						`rollback_type` VARCHAR(64) NOT NULL,
						`can_rollback` BOOLEAN DEFAULT FALSE,
						`userid` INT NOT NULL,
						`data` LONGTEXT NOT NULL,
						`executor` LONGTEXT NOT NULL,
						`time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
						`rollback` BOOLEAN DEFAULT FALSE,
						`rollback_by` INT DEFAULT NULL,
						`rollback_time` TIMESTAMP NULL DEFAULT NULL
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
					CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON `dataphyre`.`user_changes` (`userid`);
					CREATE INDEX IF NOT EXISTS idx_user_changes_time ON `dataphyre`.`user_changes` (`time`);
					CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON `dataphyre`.`user_changes` (`rollback`);
				",
				"postgresql" => "
					CREATE EXTENSION IF NOT EXISTS \"pgcrypto\";
					CREATE TABLE IF NOT EXISTS dataphyre.user_changes (
						changeid UUID PRIMARY KEY DEFAULT gen_random_uuid(),
						type VARCHAR(64) NOT NULL,
						rollback_type VARCHAR(64) NOT NULL,
						can_rollback BOOLEAN DEFAULT FALSE,
						userid INTEGER NOT NULL,
						data TEXT NOT NULL,
						executor TEXT NOT NULL,
						time TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
						rollback BOOLEAN DEFAULT FALSE,
						rollback_by INTEGER DEFAULT NULL,
						rollback_time TIMESTAMPTZ DEFAULT NULL
					);
					CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON dataphyre.user_changes (userid);
					CREATE INDEX IF NOT EXISTS idx_user_changes_time ON dataphyre.user_changes (time);
					CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON dataphyre.user_changes (rollback);
				",
				"sqlite" => "
					CREATE TABLE IF NOT EXISTS dataphyre_user_changes (
						changeid TEXT PRIMARY KEY,
						type TEXT NOT NULL,
						rollback_type TEXT NOT NULL,
						can_rollback INTEGER DEFAULT 0,
						userid INTEGER NOT NULL,
						data TEXT NOT NULL,
						executor TEXT NOT NULL,
						time TEXT NOT NULL DEFAULT (datetime('now')),
						rollback INTEGER DEFAULT 0,
						rollback_by INTEGER DEFAULT NULL,
						rollback_time TEXT DEFAULT NULL
					);
					CREATE INDEX IF NOT EXISTS idx_user_changes_userid ON dataphyre_user_changes (userid);
					CREATE INDEX IF NOT EXISTS idx_user_changes_time ON dataphyre_user_changes (time);
					CREATE INDEX IF NOT EXISTS idx_user_changes_rollback ON dataphyre_user_changes (rollback);
				"
			]
		);
        \dataphyre\dpanel::add_verbose($verbose);
    }

}