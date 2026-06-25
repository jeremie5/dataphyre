<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\currency;

\dataphyre\currency\diagnostic::tests();

/**
 * Runs Currency module diagnostics and table bootstrapping checks.
 *
 * Diagnostics validate PHP/runtime prerequisites and create the exchange-rate
 * persistence table when SQL helpers are available. Findings are reported to
 * Dpanel as verbose entries instead of throwing during diagnostic scans.
 */
class diagnostic{

	/**
	 * Executes Currency module diagnostic checks.
	 *
	 * The check requires SQL module availability, validates minimum PHP version
	 * and extensions, and conditionally issues portable table/index DDL for MySQL,
	 * PostgreSQL, and SQLite.
	 *
	 * @return void
	 */
	public static function tests(): void {
		$verbose=[];
		\dp_module_required('currency', 'sql');
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'currency', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
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
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'currency',
				'level'=>'warning',
				'message'=>'SQL-backed Currency table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
		}
		else
		{
			\sql_query(
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
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
