<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Fixed managed-database identity query used by the one-shot launcher. */

if(PHP_SAPI!=='cli' || ($argc ?? 0)!==2 || preg_match('/^--purpose=([a-z][a-z0-9_]{0,31})$/D',(string)($argv[1] ?? ''),$match)!==1){
	exit(64);
}
$purpose=$match[1];
$prefix=$purpose==='primary' ? 'DATAPHYRE_DATABASE' : 'DATAPHYRE_DATABASE_'.strtoupper($purpose);
$marker=getenv('DATAPHYRE_DATABASE_BINDING_'.strtoupper($purpose).'_SHA256');
$dsn=getenv($prefix.'_DSN');$user=getenv($prefix.'_USER');$password=getenv($prefix.'_PASSWORD');
if(!is_string($marker) || preg_match('/^sha256:[a-f0-9]{64}$/D',$marker)!==1
	|| !is_string($dsn) || $dsn==='' || !is_string($user) || $user==='' || !is_string($password) || $password===''){
	exit(78);
}
try{
	$pdo=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
	$driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
	if($driver!=='pgsql') throw new RuntimeException('Managed database driver is invalid.');
	$row=$pdo->query('SELECT current_database() AS database_name, current_user AS role_name')->fetch(PDO::FETCH_ASSOC);
	if(!is_array($row) || !is_string($row['database_name'] ?? null) || $row['database_name']===''
		|| !is_string($row['role_name'] ?? null) || $row['role_name']==='') throw new RuntimeException('Managed database identity is invalid.');
	$identity='sha256:'.hash('sha256',"dataphyre.database_connection.v1\0{$marker}\0{$row['database_name']}\0{$row['role_name']}");
	echo json_encode([
		'contract'=>'dataphyre.database_connection_probe.v1',
		'purpose'=>$purpose,
		'binding_sha256'=>$marker,
		'connection_sha256'=>$identity,
		'connected'=>true,
		'identity_query'=>true,
	],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),"\n";
	exit(0);
}catch(Throwable){
	exit(69);
}
