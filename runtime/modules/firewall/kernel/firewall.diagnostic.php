<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\firewall;

/** Collects Firewall prerequisite and CAPTCHA-storage diagnostics. */
class diagnostic {
	/** @param array<string,mixed> $observations @return list<array<string,mixed>> */
	public static function tests(array $observations=[]): array {
		$required=is_callable($observations['module_required'] ?? null) ? $observations['module_required'] : '\\dp_module_required';
		$extensionLoaded=is_callable($observations['extension_loaded'] ?? null) ? $observations['extension_loaded'] : 'extension_loaded';
		$clock=is_callable($observations['clock'] ?? null) ? $observations['clock'] : 'time';
		$phpVersion=(string)($observations['php_version'] ?? PHP_VERSION);
		$sqlQuery=array_key_exists('sql_query', $observations)
			? $observations['sql_query']
			: (\function_exists('sql_query') ? '\\sql_query' : null);
		$verbose=[];

		$required('firewall', 'sql');
		$required('firewall', 'cache');
		if(version_compare($phpVersion, $minimum='8.1.0')<0){
			$verbose[]=['module'=>'firewall', 'error'=>'PHP version '.$minimum.' or higher is required.', 'time'=>(int)$clock()];
		}
		foreach(['session','pcre','date','hash','filter','standard'] as $extension){
			if(!$extensionLoaded($extension)){
				$verbose[]=['module'=>'firewall', 'error'=>"PHP extension '{$extension}' is not loaded.", 'time'=>(int)$clock()];
			}
		}
		if(!is_callable($sqlQuery)){
			$verbose[]=[
				'module'=>'firewall',
				'level'=>'warning',
				'message'=>'SQL-backed Firewall table checks were skipped because SQL helper functions are unavailable when module entrypoint execution is disabled.',
				'time'=>(int)$clock(),
			];
		}else{
			$sqlQuery([
				'mysql'=>self::mysqlSchema(),
				'postgresql'=>self::postgresqlSchema(),
				'sqlite'=>self::sqliteSchema(),
			]);
		}
		if(array_key_exists('publish', $observations)){
			if(is_callable($observations['publish'])){
				$observations['publish']($verbose);
			}
		}elseif(class_exists('\\dataphyre\\dpanel')){
			\dataphyre\dpanel::add_verbose($verbose);
		}
		return $verbose;
	}

	private static function mysqlSchema(): string {
		return 'CREATE TABLE IF NOT EXISTS `dataphyre.captcha_blocks` (`id` VARCHAR(36) NOT NULL PRIMARY KEY, `ip_address` TEXT NOT NULL, `expiry` TIMESTAMP NOT NULL, `reason` TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci; CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON `dataphyre.captcha_blocks` (`ip_address`); CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON `dataphyre.captcha_blocks` (`expiry`);';
	}

	private static function postgresqlSchema(): string {
		return 'CREATE EXTENSION IF NOT EXISTS "pgcrypto"; CREATE TABLE IF NOT EXISTS "dataphyre.captcha_blocks" (id UUID PRIMARY KEY DEFAULT gen_random_uuid(), ip_address TEXT NOT NULL, expiry TIMESTAMPTZ NOT NULL, reason TEXT NOT NULL); CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON "dataphyre.captcha_blocks" (ip_address); CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON "dataphyre.captcha_blocks" (expiry);';
	}

	private static function sqliteSchema(): string {
		return 'CREATE TABLE IF NOT EXISTS "dataphyre.captcha_blocks" (id TEXT PRIMARY KEY, ip_address TEXT NOT NULL, expiry TEXT NOT NULL, reason TEXT NOT NULL); CREATE INDEX IF NOT EXISTS idx_captcha_blocks_ip ON "dataphyre.captcha_blocks" (ip_address); CREATE INDEX IF NOT EXISTS idx_captcha_blocks_expiry ON "dataphyre.captcha_blocks" (expiry);';
	}
}

if(!defined('DATAPHYRE_FIREWALL_DIAGNOSTIC_NO_DISPATCH')){
	diagnostic::tests();
}
