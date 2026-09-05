<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Real PostgreSQL storage adapter and private IPC for broker integration tests. */
function tracelog(mixed ...$arguments): void {}
function pre_init_error(?string $message=null): never { throw new RuntimeException($message ?? 'Key unavailable.'); }
function dp_token_fixture_database(): PDO {
	static $connection=null;
	return $connection ??= new PDO((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'postgres',null,[
		PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
	]);
}
function dp_token_fixture_state(): object {
	static $state=null;
	return $state ??= (object)['pause_update'=>false,'select_count'=>0];
}
function sql_insert(string $table,array $fields,mixed ...$unused): bool {
	$statement=dp_token_fixture_database()->prepare('INSERT INTO '.$table.' ('.implode(',',array_keys($fields)).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
	return $statement->execute(array_values($fields));
}
function sql_select(string $columns,string $table,string $where,array $values,mixed ...$unused): array|false {
	dp_token_fixture_state()->select_count++;
	$statement=dp_token_fixture_database()->prepare('SELECT '.$columns.' FROM '.$table.' '.$where);
	$statement->execute($values);
	return $statement->fetch(PDO::FETCH_ASSOC);
}
function sql_update(string $table,array $fields,string $where,array $values,mixed ...$unused): int {
	if(dp_token_fixture_state()->pause_update){
		echo '{"stage":"before_update"}'."\n";fflush(STDOUT);
		if(trim((string)fgets(STDIN))!=='continue') throw new RuntimeException('Update barrier unavailable.');
	}
	$assignments=implode(',',array_map(static fn(string $name): string=>$name.'=?',array_keys($fields)));
	$statement=dp_token_fixture_database()->prepare('UPDATE '.$table.' SET '.$assignments.' '.$where);
	$statement->execute([...array_values($fields),...$values]);
	return $statement->rowCount();
}

try{
	$table=(string)getenv('DATAPHYRE_TEST_TOKEN_TABLE');
	if(preg_match('/^public\.dp_access_probe_[a-f0-9]{24}$/D',$table)!==1) throw new RuntimeException('Invalid fixture table.');
	define('DP_ACCESS_CFG',['identity'=>['tokens_table'=>$table]]);
	define('DP_CORE_CFG',['private_key'=>json_decode((string)getenv('DATAPHYRE_TEST_TOKEN_KEYS'),true,8,JSON_THROW_ON_ERROR)]);
	if(getenv('DATAPHYRE_TEST_NO_KEY_API')!=='1'){
		require_once dirname(__DIR__,3).'/core/kernel/helper_functions.php';
	}
	require_once dirname(__DIR__,2).'/Framework/AccessTokenBroker.php';
	$broker=\Dataphyre\Access\AccessTokenBroker::instance();
	echo json_encode(['ready'=>true,'pid'=>getmypid()],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
	while(($line=fgets(STDIN))!==false){
		$request=json_decode($line,true,16,JSON_THROW_ON_ERROR);
		dp_token_fixture_state()->pause_update=($request['pause_update'] ?? false)===true;
		try{
			$response=match($request['operation']){
				'create'=>$broker->create($request['type'],42,' User@Example.test ',['fixture'=>true],$request['ttl'] ?? 3600),
				'find'=>$broker->find($request['type'],$request['token']),
				'consume'=>$broker->consume($request['type'],$request['token']),
				'stats'=>['select_count'=>dp_token_fixture_state()->select_count],
				default=>throw new RuntimeException('Unknown operation.'),
			};
		}catch(RuntimeException){$response=['key_rejected'=>true];}
		echo json_encode(['result'=>$response],JSON_THROW_ON_ERROR)."\n";fflush(STDOUT);
	}
}catch(Throwable $failure){fwrite(STDERR,get_class($failure)."\n");exit(78);}
