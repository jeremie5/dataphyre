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
 
namespace dataphyre\access;

\dataphyre\access\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		// Runtime information
		dp_module_required('access', 'sql');
		dp_module_required('access', 'firewall');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'access', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for module
		$required_extensions=[
			'session',
			'filter',
			'hash',
			'openssl',
			'json',
			'pcre',
			'mbstring',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'access', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		// Check if custom cookie name is respected
		if(isset($GLOBALS['configurations']['dataphyre']['access']['sessions_cookie_name'])){
			$expected = '__Secure-' . $GLOBALS['configurations']['dataphyre']['access']['sessions_cookie_name'];
			if(\dataphyre\access::get_session_cookie_name() !== $expected){
				$verbose[]=['module'=>'access', 'error'=>'Session cookie name does not match configuration.', 'time'=>time()];
			}
		}
		// Check if a session is active and has expected structure (only if active)
		if(session_status() === PHP_SESSION_ACTIVE){
			if(isset($_SESSION['dp_access'])){
				if(!isset($_SESSION['dp_access']['dpid']) || !is_string($_SESSION['dp_access']['dpid'])){
					$verbose[]=['module'=>'access', 'error'=>'dp_access entry in session missing or malformed (dpid).', 'time'=>time()];
				}
				if(!isset($_SESSION['dp_access']['userid'])){
					$verbose[]=['module'=>'access', 'error'=>'dp_access entry in session missing userid.', 'time'=>time()];
				}
			}
		}
		// Check DPID constant structure if defined
		if(defined('DPID') && is_string(DPID)){
			if(!preg_match('/^DPID_[A-Za-z0-9\-_]{43}_[a-f0-9]{8}$/', DPID)){
				$verbose[]=['module'=>'access', 'error'=>'DPID constant is defined but does not match expected format.', 'time'=>time()];
			}
		}
		// Check if session table name is set
		if(empty(config('dataphyre/access/sessions_table_name'))){
			$verbose[]=['module'=>'access', 'error'=>'Missing session table name in configuration.', 'time'=>time()];
		}
		// Create table if session table name is set
		if(!empty($table=config('dataphyre/access/sessions_table_name'))){
			sql_query(
				$Q=[
					"mysql"=>"
						CREATE TABLE IF NOT EXISTS `$table` (
							`id` VARCHAR(64) PRIMARY KEY,
							`userid` BIGINT UNSIGNED NOT NULL,
							`useragent` TEXT NOT NULL,
							`ipaddress` TEXT NOT NULL,
							`keepalive` BOOLEAN NOT NULL DEFAULT FALSE,
							`active` BOOLEAN NOT NULL DEFAULT TRUE,
							`date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
						CREATE INDEX `idx_{$table}_userid_active` ON `$table` (`userid`, `active`);
						CREATE INDEX `idx_{$table}_full_lookup` ON `$table` (`id`, `userid`, `useragent`(255), `ipaddress`, `active`);
						CREATE INDEX `idx_{$table}_date` ON `$table` (`date`);
					",
					"postgresql" => "
						CREATE TABLE IF NOT EXISTS \"{$table}\" (
							id TEXT PRIMARY KEY,
							userid BIGINT NOT NULL,
							useragent TEXT NOT NULL,
							ipaddress TEXT NOT NULL,
							keepalive BOOLEAN NOT NULL DEFAULT FALSE,
							active BOOLEAN NOT NULL DEFAULT TRUE,
							date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
						);
						CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_userid_active ON \"{$table}\" (userid, active);
						CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_full_lookup ON \"{$table}\" (id, userid, useragent, ipaddress, active);
						CREATE INDEX IF NOT EXISTS idx_" . str_replace('.', '_', $table) . "_date ON \"{$table}\" (date);
					",
					"sqlite"=> "
						CREATE TABLE IF NOT EXISTS \"$table\" (
							id TEXT PRIMARY KEY,
							userid INTEGER NOT NULL,
							useragent TEXT NOT NULL,
							ipaddress TEXT NOT NULL,
							keepalive BOOLEAN NOT NULL DEFAULT 0,
							active BOOLEAN NOT NULL DEFAULT 1,
							date TEXT NOT NULL DEFAULT (datetime('now'))
						);
						CREATE INDEX IF NOT EXISTS idx_${table}_userid_active ON \"$table\" (userid, active);
						CREATE INDEX IF NOT EXISTS idx_${table}_full_lookup ON \"$table\" (id, userid, useragent, ipaddress, active);
						CREATE INDEX IF NOT EXISTS idx_${table}_date ON \"$table\" (date);
					"
				]
			);
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}