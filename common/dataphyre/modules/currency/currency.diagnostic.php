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
 
namespace dataphyre\currency;

\dataphyre\currency\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		// Runtime information
		dp_module_required('currency', 'sql');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'currency', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		// Check each required extension for module
		$required_extensions=[
			'json',
			'mbstring',
			'openssl',
			'simplexml',
			'pcre',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'currency', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		sql_query(
			$Q=[
				"mysql" => "
					CREATE TABLE IF NOT EXISTS `dataphyre.exchange_rates` (
						`id` VARCHAR(36) NOT NULL PRIMARY KEY,
						`data` LONGTEXT NOT NULL,
						`source` TEXT NOT NULL,
						`date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_date ON `dataphyre.exchange_rates` (`date`);
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_source ON `dataphyre.exchange_rates` (`source`);
				",
				"postgresql" => "
					CREATE TABLE IF NOT EXISTS \"dataphyre.exchange_rates\" (
						id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
						data TEXT NOT NULL,
						source TEXT NOT NULL,
						date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
					);
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_date ON \"dataphyre.exchange_rates\" (date);
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_source ON \"dataphyre.exchange_rates\" (source);
				",
				"sqlite" => "
					CREATE TABLE IF NOT EXISTS \"dataphyre.exchange_rates\" (
						id TEXT PRIMARY KEY,
						data TEXT NOT NULL,
						source TEXT NOT NULL,
						date TEXT NOT NULL DEFAULT (datetime('now'))
					);
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_date ON \"dataphyre.exchange_rates\" (date);
					CREATE INDEX IF NOT EXISTS idx_exchange_rates_source ON \"dataphyre.exchange_rates\" (source);
				"
			]
		);
        \dataphyre\dpanel::add_verbose($verbose);
    }

}