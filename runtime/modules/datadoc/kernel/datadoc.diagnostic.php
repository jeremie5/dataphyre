<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\datadoc;

\dataphyre\datadoc\diagnostic::tests();

class diagnostic{

	public static function tests(): void {
		$verbose=[];
		// Runtime information
		\dp_module_required('datadoc', 'flightdeck');
		\dp_module_required('datadoc', 'sql');
		// Check for PHP version
		if(version_compare(PHP_VERSION, $ver='8.1.0') < 0){
			$verbose[]=['module'=>'datadoc', 'error'=>'PHP version '.$ver.' or higher is required.', 'time'=>time()];
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
				$verbose[]=['module'=>'datadoc', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>time()];
			}
		}
		if(!\function_exists('sql_query')){
			$verbose[]=[
				'module'=>'datadoc',
				'level'=>'warning',
				'message'=>'SQL-backed DataDoc table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>time(),
			];
		}
		else
		{
			\sql_query([
				"sqlite"=>'CREATE TABLE IF NOT EXISTS datadoc.projects(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					name TEXT UNIQUE,
					title TEXT,
					path TEXT
				)',
				"mysql"=>'CREATE TABLE IF NOT EXISTS datadoc.projects(
					id INT AUTO_INCREMENT PRIMARY KEY,
					name VARCHAR(255) UNIQUE,
					title VARCHAR(255),
					path TEXT
				)',
				"postgresql"=>'CREATE TABLE IF NOT EXISTS datadoc.projects(
					id SERIAL PRIMARY KEY,
					name TEXT UNIQUE,
					title TEXT,
					path TEXT
				)'
			]);
			\sql_query([
				"sqlite"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_data(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					time INTEGER,
					checksum TEXT,
					type TEXT,
					content TEXT,
					file TEXT,
					project TEXT,
					function TEXT,
					namespace TEXT,
					class TEXT,
					line INTEGER,
					phpdoc_description TEXT,
					phpdoc_tags TEXT,
					UNIQUE(checksum, project)
				)',
				"mysql"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_data(
					id INT AUTO_INCREMENT PRIMARY KEY,
					time BIGINT,
					checksum VARCHAR(255),
					type VARCHAR(255),
					content TEXT,
					file TEXT,
					project VARCHAR(255),
					function VARCHAR(255),
					namespace VARCHAR(255),
					class VARCHAR(255),
					line INT,
					phpdoc_description TEXT,
					phpdoc_tags TEXT,
					UNIQUE KEY uniq_datadoc_checksum_project (checksum, project)
				)',
				"postgresql"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_data(
					id SERIAL PRIMARY KEY,
					time BIGINT,
					checksum TEXT,
					type TEXT,
					content TEXT,
					file TEXT,
					project TEXT,
					function TEXT,
					namespace TEXT,
					class TEXT,
					line INTEGER,
					phpdoc_description TEXT,
					phpdoc_tags TEXT,
					UNIQUE(checksum, project)
				)'
			]);
			\sql_query([
				"sqlite"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_files(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					filepath TEXT,
					checksum TEXT,
					project TEXT,
					last_synced TIMESTAMP,
					is_stale INTEGER,
					UNIQUE(filepath, project)
				)',
				"mysql"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_files(
					id INT AUTO_INCREMENT PRIMARY KEY,
					filepath VARCHAR(1024),
					checksum VARCHAR(255),
					project VARCHAR(255),
					last_synced DATETIME,
					is_stale TINYINT(1),
					UNIQUE KEY uniq_datadoc_file_project (filepath(255), project)
				)',
				"postgresql"=>'CREATE TABLE IF NOT EXISTS dataphyre.datadoc_files(
					id SERIAL PRIMARY KEY,
					filepath TEXT,
					checksum TEXT,
					project TEXT,
					last_synced TIMESTAMP,
					is_stale BOOLEAN,
					UNIQUE(filepath, project)
				)'
			]);
		}
        \dataphyre\dpanel::add_verbose($verbose);
    }

}
