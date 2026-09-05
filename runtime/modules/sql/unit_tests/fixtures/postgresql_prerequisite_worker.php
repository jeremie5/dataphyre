<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

require_once dirname(__DIR__,2).'/kernel/postgresql_migrate.php';
use Dataphyre\Database\Migrations\PostgreSqlMigrationManifest;
use Dataphyre\Database\Migrations\PostgreSqlMigrationProfile;
use Dataphyre\Database\Migrations\PostgreSqlMigrationRunner;

try{
	$root=(string)($argv[1] ?? '');
	$pdo=new PDO((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
	$profile=PostgreSqlMigrationProfile::fromArray(json_decode((string)file_get_contents($root.'/postgresql/profile.json'),true,16,JSON_THROW_ON_ERROR));
	$manifest=PostgreSqlMigrationManifest::load($root,$profile);
	echo json_encode(['backend_pid'=>(int)$pdo->query('SELECT pg_backend_pid()')->fetchColumn()],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
	if(trim((string)fgets(STDIN))!=='run') throw new RuntimeException('Fixture execution barrier unavailable.');
	$result=(new PostgreSqlMigrationRunner($pdo,$profile))->applyFreshDatabase($manifest);
	echo json_encode(['ok'=>true,'migrations'=>$result['migrations']],JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $failure){
	echo json_encode(['ok'=>false,'error'=>get_class($failure),'message'=>substr($failure->getMessage(),0,500)],JSON_THROW_ON_ERROR)."\n";
	exit(78);
}
