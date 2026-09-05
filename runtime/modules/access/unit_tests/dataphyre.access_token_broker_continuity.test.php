<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Access token key continuity and atomic consumption')
	->contract('access.token-continuity',1)->layer('integration')->risk('critical')
	->watches('module:access')->isolation('case')
	->through('postgresql','retained-keyring','legacy-token','concurrent-consumption')
	->tag('access','security','token')->group('framework-coverage');

function dp_access_token_postgres_available(): bool {
	return extension_loaded('pdo_pgsql') && str_starts_with((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'pgsql:');
}
function dp_access_token_database(): PDO {
	return new PDO((string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function dp_access_token_table(Context $t): array {
	$database=dp_access_token_database();
	$table='public.dp_access_probe_'.bin2hex(random_bytes(12));
	$database->exec('CREATE TABLE '.$table.' (id varchar(64) PRIMARY KEY,type varchar(64) NOT NULL,token_hash varchar(128) UNIQUE NOT NULL,user_id bigint,email varchar(255),metadata_json text,expires_at timestamp NOT NULL,used_at timestamp,created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)');
	$t->defer(static fn()=>$database->exec('DROP TABLE IF EXISTS '.$table));
	return [$database,$table];
}
function dp_access_token_worker(string $table,mixed $keys,bool $noKeyApi=false): array {
	$pipes=[];
	$process=proc_open([PHP_BINARY,__DIR__.'/fixtures/access_token_broker_database_worker.php'],[
		0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
	],$pipes,null,[
		'DATAPHYRE_TEST_POSTGRES_DSN'=>(string)getenv('DATAPHYRE_TEST_POSTGRES_DSN'),
		'DATAPHYRE_TEST_TOKEN_TABLE'=>$table,'DATAPHYRE_TEST_TOKEN_KEYS'=>json_encode($keys,JSON_THROW_ON_ERROR),
		'DATAPHYRE_TEST_NO_KEY_API'=>$noKeyApi ? '1' : '0',
	]);
	if(!is_resource($process)) throw new RuntimeException('Token worker unavailable.');
	stream_set_timeout($pipes[1],5);
	$worker=['process'=>$process,'pipes'=>$pipes];
	$worker['ready']=dp_access_token_reply($worker);
	return $worker;
}
function dp_access_token_reply(array $worker): mixed {
	$line=fgets($worker['pipes'][1]);
	if(!is_string($line)) throw new RuntimeException('Token worker response unavailable.');
	return json_decode($line,true,16,JSON_THROW_ON_ERROR);
}
function dp_access_token_send(array $worker,array $request): void {
	$line=json_encode($request,JSON_THROW_ON_ERROR)."\n";
	if(fwrite($worker['pipes'][0],$line)!==strlen($line)) throw new RuntimeException('Token worker request failed.');
	fflush($worker['pipes'][0]);
}
function dp_access_token_request(array $worker,array $request): mixed {
	dp_access_token_send($worker,$request);
	return dp_access_token_reply($worker)['result'];
}
function dp_access_token_close(array $worker): array {
	fclose($worker['pipes'][0]);
	$out=stream_get_contents($worker['pipes'][1]);$err=stream_get_contents($worker['pipes'][2]);
	fclose($worker['pipes'][1]);fclose($worker['pipes'][2]);
	return ['exit'=>proc_close($worker['process']),'stdout'=>$out,'stderr'=>$err];
}
function dp_access_token_legacy(PDO $database,string $table,string $type='password_reset'): string {
	$raw=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
	$statement=$database->prepare('INSERT INTO '.$table.' (id,type,token_hash,expires_at) VALUES (?,?,?,?)');
	$statement->execute(['atok_'.bin2hex(random_bytes(16)),$type,hash_hmac('sha256',$raw,'dataphyre-access-token'),date('Y-m-d H:i:s',time()+86400)]);
	return $raw;
}

test('new hashes use the active application key while retained keys and historical tokens remain readable',static function(Context $t): void {
	[$database,$table]=dp_access_token_table($t);$old=bin2hex(random_bytes(32));$new=bin2hex(random_bytes(32));$workers=[];
	try{
		$workers[]=dp_access_token_worker($table,[$old]);$workers[]=dp_access_token_worker($table,[$old,$new]);
		$workers[]=dp_access_token_worker($table,[bin2hex(random_bytes(32))]);
		$before=dp_access_token_request($workers[0],['operation'=>'create','type'=>'Password Reset']);
		$t->matches('/^[A-Za-z0-9_-]{43}$/D',$before['token']);$t->matches('/^atok_[a-f0-9]{32}$/D',$before['id']);
		$t->same(hash_hmac('sha256',$before['token'],$old),$before['token_hash']);
		$find=['operation'=>'find','type'=>'password_reset','token'=>$before['token']];
		$t->same($before['id'],dp_access_token_request($workers[1],$find)['id']);
		$t->same(null,dp_access_token_request($workers[2],$find));
		$t->same(null,dp_access_token_request($workers[1],[...$find,'type'=>'email_verification']));
		$after=dp_access_token_request($workers[1],['operation'=>'create','type'=>'Password Reset']);
		$t->same(hash_hmac('sha256',$after['token'],$new),$after['token_hash']);
		$t->isFalse(hash_equals(hash_hmac('sha256',$after['token'],'dataphyre-access-token'),$after['token_hash']));
		$t->same(null,dp_access_token_request($workers[0],[...$find,'token'=>$after['token']]));
		$legacy=dp_access_token_legacy($database,$table);
		$legacyRequest=['operation'=>'find','type'=>'password_reset','token'=>$legacy];
		$t->isTrue(is_array(dp_access_token_request($workers[1],$legacyRequest)));
		$t->same(null,dp_access_token_request($workers[1],[...$legacyRequest,'type'=>'invitation']));
		$t->same(null,dp_access_token_request($workers[1],[...$legacyRequest,'token'=>$legacy.'x']));
		$t->isTrue(is_array(dp_access_token_request($workers[1],[...$legacyRequest,'operation'=>'consume'])));
		$t->same(null,dp_access_token_request($workers[1],$legacyRequest));
		$expired=dp_access_token_legacy($database,$table);
		$database->prepare('UPDATE '.$table.' SET expires_at=? WHERE token_hash=?')->execute([date('Y-m-d H:i:s',time()-1),hash_hmac('sha256',$expired,'dataphyre-access-token')]);
		$t->same(null,dp_access_token_request($workers[1],[...$legacyRequest,'token'=>$expired]));
		$t->same(4,(int)$database->query('SELECT count(*) FROM '.$table)->fetchColumn());
	}finally{foreach($workers as $worker) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_access_token_close($worker));}
})->skipUnless(dp_access_token_postgres_available(),'Requires a disposable PostgreSQL database through DATAPHYRE_TEST_POSTGRES_DSN.');

test('two consumers that found the same token can claim it only once and cannot claim a token expired after lookup',static function(Context $t): void {
	[$database,$table]=dp_access_token_table($t);$key=bin2hex(random_bytes(32));$workers=[];
	try{
		$workers[]=dp_access_token_worker($table,[$key]);$workers[]=dp_access_token_worker($table,[$key]);
		$t->isFalse($workers[0]['ready']['pid']===$workers[1]['ready']['pid']);
		$created=dp_access_token_request($workers[0],['operation'=>'create','type'=>'password_reset']);
		$request=['operation'=>'consume','type'=>'password_reset','token'=>$created['token'],'pause_update'=>true];
		foreach($workers as $worker){dp_access_token_send($worker,$request);$t->same(['stage'=>'before_update'],dp_access_token_reply($worker));}
		foreach($workers as $worker){fwrite($worker['pipes'][0],"continue\n");fflush($worker['pipes'][0]);}
		$results=array_map(static fn(array $worker): mixed=>dp_access_token_reply($worker)['result'],$workers);
		$t->same(1,count(array_filter($results,'is_array')));$t->same(1,count(array_filter($results,static fn(mixed $row): bool=>$row===null)));
		$t->same(1,(int)$database->query('SELECT count(*) FROM '.$table.' WHERE used_at IS NOT NULL')->fetchColumn());
		$t->same(null,dp_access_token_request($workers[0],[...$request,'pause_update'=>false]));
		$expires=dp_access_token_request($workers[0],['operation'=>'create','type'=>'password_reset']);
		dp_access_token_send($workers[0],[...$request,'token'=>$expires['token']]);
		$t->same(['stage'=>'before_update'],dp_access_token_reply($workers[0]));
		$database->prepare('UPDATE '.$table.' SET expires_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-1),$expires['id']]);
		fwrite($workers[0]['pipes'][0],"continue\n");fflush($workers[0]['pipes'][0]);
		$t->same(null,dp_access_token_reply($workers[0])['result']);
		$t->same(1,(int)$database->query('SELECT count(*) FROM '.$table.' WHERE used_at IS NULL')->fetchColumn());
		$waits=dp_access_token_request($workers[0],['operation'=>'create','type'=>'password_reset']);
		$expiry=time()+1;
		$database->prepare('UPDATE '.$table.' SET expires_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',$expiry),$waits['id']]);
		dp_access_token_send($workers[0],[...$request,'token'=>$waits['token']]);
		$t->same(['stage'=>'before_update'],dp_access_token_reply($workers[0]));
		while(time()<=$expiry) usleep(20000);
		fwrite($workers[0]['pipes'][0],"continue\n");fflush($workers[0]['pipes'][0]);
		$t->same(null,dp_access_token_reply($workers[0])['result']);
	}finally{foreach($workers as $worker) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_access_token_close($worker));}
})->skipUnless(dp_access_token_postgres_available(),'Requires a disposable PostgreSQL database through DATAPHYRE_TEST_POSTGRES_DSN.');

test('missing invalid or unavailable application keys fail before issuing or looking up even historical tokens',static function(Context $t): void {
	[$database,$table]=dp_access_token_table($t);$legacy=dp_access_token_legacy($database,$table);$workers=[];
	try{
		foreach([[],null,[''],[7],''] as $keys){
			$worker=dp_access_token_worker($table,$keys);$workers[]=$worker;
			$t->same(['key_rejected'=>true],dp_access_token_request($worker,['operation'=>'create','type'=>'password_reset']));
			$t->same(['key_rejected'=>true],dp_access_token_request($worker,['operation'=>'find','type'=>'password_reset','token'=>$legacy]));
			$t->same(['select_count'=>0],dp_access_token_request($worker,['operation'=>'stats']));
		}
		$worker=dp_access_token_worker($table,['unused'],true);$workers[]=$worker;
		$t->same(['key_rejected'=>true],dp_access_token_request($worker,['operation'=>'create','type'=>'password_reset']));
		$t->same(['key_rejected'=>true],dp_access_token_request($worker,['operation'=>'find','type'=>'password_reset','token'=>$legacy]));
		$t->same(['select_count'=>0],dp_access_token_request($worker,['operation'=>'stats']));
		$t->same(1,(int)$database->query('SELECT count(*) FROM '.$table)->fetchColumn());
	}finally{foreach($workers as $worker) $t->same(['exit'=>0,'stdout'=>'','stderr'=>''],dp_access_token_close($worker));}
})->skipUnless(dp_access_token_postgres_available(),'Requires a disposable PostgreSQL database through DATAPHYRE_TEST_POSTGRES_DSN.');
