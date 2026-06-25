<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\tracelog;

\dataphyre\tracelog\diagnostic::tests();

/**
 * Verifies Tracelog runtime prerequisites and trace table availability.
 *
 * The diagnostic declares SQL as a required dependency, checks extensions used
 * by request trace capture, and creates the tracelog persistence table when SQL
 * helpers are available in the current entrypoint.
 */
class diagnostic{

	/**
	 * Collects Tracelog health findings and initializes SQL storage.
	 *
	 * Documentation tooling and embedded diagnostics may load this file without module
	 * entrypoints; in that case SQL table checks are reported as a warning rather
	 * than failing bootstrap.
	 *
	 * @return void Findings are appended to dpanel verbose output.
	 */
	public static function tests(): void {
		$verbose=[];
		\dp_module_required('tracelog', 'sql');
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'tracelog', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
		}
		$required_extensions=[
			'session',
			'json',
			'date',
			'pcre',
			'standard',
			'Reflection',
			'Core',
			'filesystem',
		];
		foreach($required_extensions as $extension){
			if(!extension_loaded($extension)){
				$verbose[]=['module'=>'tracelog', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'tracelog',
				'level'=>'warning',
				'message'=>'SQL-backed Tracelog table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
		}
		else
		{
			\sql_query(
				$Q = [
					"mysql" => "
						CREATE TABLE IF NOT EXISTS `dataphyre`.`tracelogs` (
							`rqid` VARCHAR(64) PRIMARY KEY NOT NULL,
							`log` LONGTEXT NOT NULL,
							`server` VARCHAR(64) DEFAULT NULL,
							`app` VARCHAR(32) DEFAULT NULL,
							`date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
						) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
						CREATE INDEX IF NOT EXISTS idx_tracelogs_rqid ON `dataphyre`.`tracelogs` (`rqid`);
						CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON `dataphyre`.`tracelogs` (`date`);
					",
					"postgresql" => "
						CREATE TABLE IF NOT EXISTS dataphyre.tracelogs (
							rqid VARCHAR(64) PRIMARY KEY,
							log TEXT NOT NULL,
							server VARCHAR(64),
							app VARCHAR(32),
							date TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
						);
						CREATE INDEX IF NOT EXISTS idx_tracelogs_rqid ON dataphyre.tracelogs (rqid);
						CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON dataphyre.tracelogs (date);
					",
					"sqlite" => "
						CREATE TABLE IF NOT EXISTS dataphyre_tracelogs (
							rqid TEXT PRIMARY KEY,
							log TEXT NOT NULL,
							server TEXT,
							app TEXT,
							date TEXT NOT NULL DEFAULT (datetime('now'))
						);
						CREATE INDEX IF NOT EXISTS idx_tracelogs_rqid ON dataphyre_tracelogs (rqid);
						CREATE INDEX IF NOT EXISTS idx_tracelogs_date ON dataphyre_tracelogs (date);
					"
				]
			);
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
