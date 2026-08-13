<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Explicit host-run schema for the durable local-first fenced replay ledger. */
final class PanelLocalFirstReplaySchema {
	private function __construct(){}
	/** @return list<string> */public static function statements(string $driver,string $table='dataphyre_panel_local_first_replay'):array{$driver=strtolower(trim($driver));$table=self::table($table);return match($driver){
		'sqlite'=>["CREATE TABLE IF NOT EXISTS {$table} (credential_id TEXT NOT NULL, sequence INTEGER NOT NULL, request_digest TEXT NOT NULL, state TEXT NOT NULL CHECK (state IN ('pending','completed')), lease_token TEXT NULL, lease_expires_at INTEGER NOT NULL DEFAULT 0, response_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, PRIMARY KEY (credential_id, sequence))","CREATE INDEX IF NOT EXISTS {$table}_state_idx ON {$table} (credential_id, state, sequence)"],
		'pgsql'=>["CREATE TABLE IF NOT EXISTS {$table} (credential_id VARCHAR(64) NOT NULL, sequence BIGINT NOT NULL, request_digest CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL CHECK (state IN ('pending','completed')), lease_token CHAR(64) NULL, lease_expires_at BIGINT NOT NULL DEFAULT 0, response_json TEXT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL, PRIMARY KEY (credential_id, sequence))","CREATE INDEX IF NOT EXISTS {$table}_state_idx ON {$table} (credential_id, state, sequence)"],
		'mysql'=>["CREATE TABLE IF NOT EXISTS {$table} (credential_id VARCHAR(64) NOT NULL, sequence BIGINT UNSIGNED NOT NULL, request_digest CHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, lease_token CHAR(64) NULL, lease_expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0, response_json LONGTEXT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL, PRIMARY KEY (credential_id, sequence), INDEX {$table}_state_idx (credential_id, state, sequence), CHECK (state IN ('pending','completed'))) ENGINE=InnoDB"],
		default=>throw new \InvalidArgumentException('Local-first replay schema supports sqlite, pgsql, and mysql.'),
	};}
	public static function migrate(\PDO $pdo,string $table='dataphyre_panel_local_first_replay'):void{$driver=(string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);foreach(self::statements($driver,$table)as$sql){$pdo->exec($sql);}}
	public static function table(string $table):string{if(preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D',$table)!==1){throw new \InvalidArgumentException('Local-first replay table name is invalid.');}return$table;}
}
