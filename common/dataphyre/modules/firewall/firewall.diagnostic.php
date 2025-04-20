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
 
namespace dataphyre\firewall;

\dataphyre\firewall\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		// Runtime information
		dp_module_required('firewall', 'sql');
		dp_module_required('firewall', 'cache');
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
		sql_query(
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
        \dataphyre\dpanel::add_verbose($verbose);
    }

}