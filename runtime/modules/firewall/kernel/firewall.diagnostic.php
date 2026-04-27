<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\firewall;

\dataphyre\firewall\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		$verbose=[];
		// Runtime information
		\dp_module_required('firewall', 'sql');
		\dp_module_required('firewall', 'cache');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'firewall', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for module
		$required_extensions=[
			'session',
			'pcre',
			'date',
			'hash',
			'filter',
			'standard',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'firewall', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'firewall',
				'level'=>'warning',
				'message'=>'SQL-backed Firewall table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
		}
		else
		{
			\sql_query(
				$Q = [
					"mysql" => "
						CREATE TABLE IF NOT EXISTS `dataphyre.captcha_blocks` (
							`id` VARCHAR(36) NOT NULL PRIMARY KEY,
							`ip_address` TEXT NOT NULL,
							`expiry` TIMESTAMP NOT NULL,
							`reason` TEXT NOT NULL
						) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON `dataphyre.captcha_blocks` (`ip_address`);
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON `dataphyre.captcha_blocks` (`expiry`);
					",
					"postgresql" => "
						CREATE EXTENSION IF NOT EXISTS \"pgcrypto\";
						CREATE TABLE IF NOT EXISTS \"dataphyre.captcha_blocks\" (
							id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
							ip_address TEXT NOT NULL,
							expiry TIMESTAMPTZ NOT NULL,
							reason TEXT NOT NULL
						);
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON \"dataphyre.captcha_blocks\" (ip_address);
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON \"dataphyre.captcha_blocks\" (expiry);
					",
					"sqlite" => "
						CREATE TABLE IF NOT EXISTS \"dataphyre.captcha_blocks\" (
							id TEXT PRIMARY KEY,
							ip_address TEXT NOT NULL,
							expiry TEXT NOT NULL,
							reason TEXT NOT NULL
						);
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON \"dataphyre.captcha_blocks\" (ip_address);
						CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON \"dataphyre.captcha_blocks\" (expiry);
					"
				]
			);
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
