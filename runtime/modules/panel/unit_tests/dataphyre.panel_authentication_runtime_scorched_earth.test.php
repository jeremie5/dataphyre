<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAuthenticationCipher;
use Dataphyre\Panel\PanelAuthenticationConflict;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelAuthenticationRecord;
use Dataphyre\Panel\PanelFilesystemAuthenticationStore;
use Dataphyre\Panel\PanelLocalOneTimeChallengeAdapter;
use Dataphyre\Panel\PanelMemoryAuthenticationStore;
use Dataphyre\Panel\PanelTotp;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{PanelAuthenticationManager,PanelLocalOneTimeChallengeAdapter} */
function dp_panel_auth_manager(?PanelMemoryAuthenticationStore $store=null): array {
	$adapter=new PanelLocalOneTimeChallengeAdapter('local-challenge-key-32-bytes!!!!');
	$manager=new PanelAuthenticationManager($store ?? new PanelMemoryAuthenticationStore(),new PanelAuthenticationCipher('encryption-key-32-bytes-long!!!!'),$adapter,'authentication-pepper-32-bytes!!!');
	return [$manager,$adapter];
}

test('panel totp matches every RFC 6238 SHA vector and bounded skew behavior',static function(Context $t):void{
	$secrets=[
		'sha1'=>PanelTotp::base32Encode('12345678901234567890'),
		'sha256'=>PanelTotp::base32Encode('12345678901234567890123456789012'),
		'sha512'=>PanelTotp::base32Encode('1234567890123456789012345678901234567890123456789012345678901234'),
	];
	$vectors=[
		59=>['94287082','46119246','90693936'],1111111109=>['07081804','68084774','25091201'],
		1111111111=>['14050471','67062674','99943326'],1234567890=>['89005924','91819424','93441116'],
		2000000000=>['69279037','90698825','38618901'],20000000000=>['65353130','77737706','47863826'],
	];
	foreach($vectors as$timestamp=>$expected){$index=0;foreach($secrets as$algorithm=>$secret){$t->same($expected[$index++],PanelTotp::at($secret,$timestamp,['algorithm'=>$algorithm,'digits'=>8,'period'=>30]));}}
	$secret=$secrets['sha1'];$code=PanelTotp::at($secret,1000);
	$t->isTrue(PanelTotp::verify($secret,$code,1030,['skew'=>1]));
	$t->isFalse(PanelTotp::verify($secret,$code,1060,['skew'=>1]));
	$t->isFalse(PanelTotp::verify($secret,'123',1000));
	$t->same('12345678901234567890',PanelTotp::base32Decode($secret));
	$t->throws(static fn()=>PanelTotp::at($secret,10,['algorithm'=>'md5']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTotp::at($secret,10,['digits'=>5]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelTotp::base32Decode('B'),InvalidArgumentException::class);
})->tag('panel','authentication','totp','rfc6238')->maxMillis(1000);

test('panel authentication cipher binds context authenticates ciphertext and never serializes keys',static function(Context $t):void{
	$cipher=new PanelAuthenticationCipher('this-is-a-strong-encryption-key!!');$encrypted=$cipher->encrypt('JBSWY3DPEHPK3PXP','factor:user-1');
	$t->same('JBSWY3DPEHPK3PXP',$cipher->decrypt($encrypted,'factor:user-1'));
	$t->throws(static fn()=>$cipher->decrypt($encrypted,'factor:user-2'),UnexpectedValueException::class);
	$t->throws(static fn()=>$cipher->decrypt(substr($encrypted,0,-1).'A','factor:user-1'),UnexpectedValueException::class);
	$t->throws(static fn()=>new PanelAuthenticationCipher('short'),InvalidArgumentException::class);
})->tag('panel','authentication','cipher')->maxMillis(1000);

test('panel authentication records reject raw credentials and redact durable cryptographic fields',static function(Context $t):void{
	$t->throws(static fn()=>PanelAuthenticationRecord::make('factors','one',['secret'=>'raw']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelAuthenticationRecord::make('sessions','one',['metadata'=>['token'=>'raw']]),InvalidArgumentException::class);
	$record=PanelAuthenticationRecord::make('sessions','safe',['user_id'=>'u1','token_hash'=>'abc','secret_ciphertext'=>'cipher','metadata'=>[]],100);
	$public=$record->jsonSerialize();
	$t->same('[redacted]',$public['data']['token_hash']);
	$t->same('[redacted]',$public['data']['secret_ciphertext']);
	$t->same('abc',$record->storagePayload()['data']['token_hash']);
	$t->same(101,$record->merge(['user_id'=>'u2'],101)->updatedAt());
})->tag('panel','authentication','secret-handling')->maxMillis(1000);

test('panel memory authentication store rolls back transactions and rejects stale revisions',static function(Context $t):void{
	$store=new PanelMemoryAuthenticationStore();$record=$store->create(PanelAuthenticationRecord::make('sessions','s1',['user_id'=>'u1'],100));
	$t->same(1,$record->revision());
	$updated=$store->save($record->merge(['state'=>'active'],101),1);$t->same(2,$updated->revision());
	$t->throws(static fn()=>$store->save($record,1),PanelAuthenticationConflict::class);
	try{$store->transaction(static function($tx):void{$tx->create(PanelAuthenticationRecord::make('sessions','rollback',['user_id'=>'u1']));throw new RuntimeException('rollback');});}catch(RuntimeException){}
	$t->same(null,$store->get('sessions','rollback'));
	$t->same(1,count($store->all('sessions',['user_id'=>'u1'])));
})->tag('panel','authentication','store','memory')->maxMillis(1000);

test('panel filesystem authentication store atomically persists snapshots and detects corruption',static function(Context $t):void{
	$directory=$t->tempDirectory('panel-auth-store');$store=new PanelFilesystemAuthenticationStore($directory);
	try{$store->transaction(static function($tx):void{$tx->create(PanelAuthenticationRecord::make('devices','d1',['user_id'=>'u1']));$tx->create(PanelAuthenticationRecord::make('sessions','s1',['user_id'=>'u1']));throw new RuntimeException('abort');});}catch(RuntimeException){}
	$t->same(0,$store->diagnostics()['records']);
	$stored=$store->transaction(static function($tx){$device=$tx->create(PanelAuthenticationRecord::make('devices','d1',['user_id'=>'u1','token_hash'=>'h']));$tx->create(PanelAuthenticationRecord::make('sessions','s1',['user_id'=>'u1']));return$device;});
	$t->same(1,$stored->revision());$t->same(2,$store->diagnostics()['records']);
	$reopened=new PanelFilesystemAuthenticationStore($directory);$t->same('u1',$reopened->get('devices','d1')->value('user_id'));
	$t->throws(static fn()=>$reopened->save($stored->merge(['x'=>1]),0),PanelAuthenticationConflict::class);
	$path=$directory.DIRECTORY_SEPARATOR.'authentication.store.json';$contents=(string)file_get_contents($path);file_put_contents($path,str_replace('"user_id": "u1"','"user_id": "tampered"',$contents));
	$t->throws(static fn()=>(new PanelFilesystemAuthenticationStore($directory))->all('devices'),UnexpectedValueException::class);
})->tag('panel','authentication','store','filesystem','atomic')->maxMillis(2000);

test('panel authentication manager provisions confirms and replay-protects totp and recovery codes',static function(Context $t):void{
	[$manager]=dp_panel_auth_manager();$secret=PanelTotp::base32Encode('12345678901234567890');$timestamp=1234567890;
	$enrollment=$manager->provisionTotp('u1','Primary',['id'=>'factor-1','secret'=>$secret,'issuer'=>'Shopiro','account'=>'operator@example.test','recovery_codes'=>6,'now'=>$timestamp]);
	$serialized=json_encode($enrollment,JSON_THROW_ON_ERROR);$t->notContains($secret,$serialized);foreach($enrollment->recoveryCodes()as$recovery){$t->notContains($recovery,$serialized);}
	$t->contains('otpauth://totp/',$enrollment->provisioningUri());
	$code=PanelTotp::at($secret,$timestamp);$t->isTrue($manager->confirmTotp('factor-1',$code,$timestamp)->verified());
	$t->isFalse($manager->verifyTotp('u1',$code,$timestamp)->verified());
	$next=PanelTotp::at($secret,$timestamp+30);$t->isTrue($manager->verifyTotp('u1',$next,$timestamp+30)->verified());
	$recovery=$enrollment->recoveryCodes()[0];$t->isTrue($manager->useRecoveryCode('u1',$recovery,$timestamp+31)->verified());
	$t->isFalse($manager->useRecoveryCode('u1',$recovery,$timestamp+32)->verified());
	$t->same(5,$manager->factors('u1')[0]['recovery_codes_remaining']);
})->tag('panel','authentication','totp','recovery','replay')->maxMillis(2000);

test('panel filesystem manager persists only ciphertext and hashes and consumes recovery codes across instances',static function(Context $t):void{
	$directory=$t->tempDirectory('panel-auth-manager-files');$key='filesystem-encryption-key-32bytes!';$pepper='filesystem-auth-pepper-32-bytes!!';$adapter=new PanelLocalOneTimeChallengeAdapter('filesystem-local-adapter-key!!!!');
	$manager=new PanelAuthenticationManager(new PanelFilesystemAuthenticationStore($directory),new PanelAuthenticationCipher($key),$adapter,$pepper);$secret=PanelTotp::base32Encode('12345678901234567890');$now=1234567890;
	$enrollment=$manager->provisionTotp('u1','Primary',['id'=>'file-factor','secret'=>$secret,'now'=>$now,'recovery_codes'=>4]);$manager->confirmTotp('file-factor',PanelTotp::at($secret,$now),$now);$recovery=$enrollment->recoveryCodes()[0];
	$challenge=$manager->beginChallenge('u1','secure export','email',['id'=>'file-challenge','recipient'=>'operator@example.test','now'=>$now]);$challengeCode=$adapter->codeFor($challenge->id());
	$snapshot=(string)file_get_contents($directory.DIRECTORY_SEPARATOR.'authentication.store.json');$t->notContains($secret,$snapshot);$t->notContains($recovery,$snapshot);$t->notContains($challengeCode,$snapshot);$t->contains('secret_ciphertext',$snapshot);$t->contains('code_hash',$snapshot);
	$second=new PanelAuthenticationManager(new PanelFilesystemAuthenticationStore($directory),new PanelAuthenticationCipher($key),new PanelLocalOneTimeChallengeAdapter('filesystem-local-adapter-key!!!!'),$pepper);
	$t->isTrue($second->useRecoveryCode('u1',$recovery,$now+1)->verified());$t->isFalse($manager->useRecoveryCode('u1',$recovery,$now+2)->verified());
})->tag('panel','authentication','filesystem','secret-handling','recovery')->maxMillis(3000);

test('panel email challenges enforce attempt lockout expiry success and replay prevention',static function(Context $t):void{
	[$manager,$adapter]=dp_panel_auth_manager();$locked=$manager->beginChallenge('u1','delete records','email',['id'=>'challenge-lock','recipient'=>'a@example.test','now'=>1000,'ttl_seconds'=>60,'max_attempts'=>2]);
	$actual=$adapter->codeFor($locked->id());$t->notNull($actual);$t->notContains($actual,json_encode($locked,JSON_THROW_ON_ERROR));
	$t->same('challenge_invalid',$manager->verifyChallenge($locked->id(),'000000',1001)->reason());
	$t->same('challenge_locked',$manager->verifyChallenge($locked->id(),'000001',1002)->reason());
	$t->same('challenge_locked',$manager->verifyChallenge($locked->id(),$actual,1003)->reason());
	$expired=$manager->beginChallenge('u1','export','email',['id'=>'challenge-expired','recipient'=>'a@example.test','now'=>2000,'ttl_seconds'=>30]);
	$t->same('challenge_expired',$manager->verifyChallenge($expired->id(),$adapter->codeFor($expired->id()),2030)->reason());
	$success=$manager->beginChallenge('u1','refund','email',['id'=>'challenge-ok','recipient'=>'a@example.test','now'=>3000]);$decision=$manager->verifyChallenge($success->id(),$adapter->codeFor($success->id()),3001);
	$t->isTrue($decision->verified());$t->same('challenge_replayed',$manager->verifyChallenge($success->id(),$adapter->codeFor($success->id()),3002)->reason());
	$cancelled=$manager->beginChallenge('u1','cancel me','email',['id'=>'challenge-cancel','recipient'=>'a@example.test','now'=>4000]);$t->isTrue($manager->cancelChallenge($cancelled->id(),4001));$t->same('challenge_cancelled',$manager->verifyChallenge($cancelled->id(),$adapter->codeFor($cancelled->id()),4002)->reason());
	$manager->beginChallenge('u1','prune me','email',['id'=>'challenge-prune','recipient'=>'a@example.test','now'=>5000,'ttl_seconds'=>30]);$t->same(1,$manager->expireChallenges(5030));
})->tag('panel','authentication','challenge','lockout','expiry','replay')->maxMillis(2000);

test('panel successful step-up atomically elevates its bound session',static function(Context $t):void{
	[$manager,$adapter]=dp_panel_auth_manager();$credential=$manager->createSession('u1',['id'=>'session-step','now'=>5000,'ttl_seconds'=>3600]);
	$t->same(1,$credential->session()->authenticationLevel());$t->notContains($credential->token(),json_encode($credential,JSON_THROW_ON_ERROR));
	$challenge=$manager->beginChallenge('u1','capture payment','email',['id'=>'step-up','recipient'=>'a@example.test','session_id'=>'session-step','required_level'=>3,'now'=>5001]);
	$t->throws(static fn()=>$manager->beginChallenge('u2','wrong binding','email',['recipient'=>'b@example.test','session_id'=>'session-step','now'=>5001]),InvalidArgumentException::class);
	$t->isTrue($manager->verifyChallenge($challenge->id(),$adapter->codeFor($challenge->id()),5002)->verified());
	$session=$manager->authenticateSession('session-step',$credential->token(),5003);$t->notNull($session);$t->same(3,$session->authenticationLevel());
	$t->same(5002,$session->jsonSerialize()['step_up_at']);
})->tag('panel','authentication','step-up','session')->maxMillis(2000);

test('panel trusted device verification and revocation cascade into bound sessions',static function(Context $t):void{
	[$manager]=dp_panel_auth_manager();$deviceCredential=$manager->trustDevice('u1','Work laptop','fingerprint-A',['id'=>'device-1','now'=>10000,'ttl_seconds'=>3600]);
	$t->notContains($deviceCredential->token(),json_encode($deviceCredential,JSON_THROW_ON_ERROR));
	$t->same(null,$manager->verifyTrustedDevice('device-1',$deviceCredential->token(),'fingerprint-B',10001));
	$t->notNull($manager->verifyTrustedDevice('device-1',$deviceCredential->token(),'fingerprint-A',10001));
	$t->throws(static fn()=>$manager->createSession('u2',['device_id'=>'device-1','now'=>10002]),InvalidArgumentException::class);
	$sessionCredential=$manager->createSession('u1',['id'=>'device-session','device_id'=>'device-1','now'=>10002,'ttl_seconds'=>3600]);
	$t->notNull($manager->authenticateSession('device-session',$sessionCredential->token(),10003));
	$t->isTrue($manager->revokeDevice('device-1',10004));
	$t->same(null,$manager->verifyTrustedDevice('device-1',$deviceCredential->token(),'fingerprint-A',10005));
	$t->same(null,$manager->authenticateSession('device-session',$sessionCredential->token(),10005));
	$t->isFalse($manager->devices('u1')[0]->active(10005));$t->notNull($manager->sessions('u1')[0]->revokedAt());
})->tag('panel','authentication','trusted-device','session','revocation')->maxMillis(2000);

test('panel session inventory supports expiry selective and account-wide revocation',static function(Context $t):void{
	[$manager]=dp_panel_auth_manager();$one=$manager->createSession('u1',['id'=>'s-one','now'=>20000,'ttl_seconds'=>60]);$two=$manager->createSession('u1',['id'=>'s-two','now'=>20000,'ttl_seconds'=>3600]);
	$t->same(null,$manager->authenticateSession('s-one',$one->token(),20060));
	$t->notNull($manager->authenticateSession('s-two',$two->token(),20060));
	$t->same(1,$manager->revokeAllSessions('u1','s-two',20061));
	$t->notNull($manager->authenticateSession('s-two',$two->token(),20062));
	$t->same(1,$manager->revokeAllSessions('u1',null,20063));
	$t->same(null,$manager->authenticateSession('s-two',$two->token(),20064));
	$t->same(2,count($manager->sessions('u1')));
})->tag('panel','authentication','session','inventory','revocation')->maxMillis(2000);
