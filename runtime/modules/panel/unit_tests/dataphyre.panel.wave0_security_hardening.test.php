<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\AutomationRegistry;
use Dataphyre\Panel\PanelArrayRelationAdapter;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelErrorEnvelope;
use Dataphyre\Panel\PanelFilesystemOperationStore;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryPreferenceStore;
use Dataphyre\Panel\PanelManifestInspector;
use Dataphyre\Panel\PanelNotificationInbox;
use Dataphyre\Panel\PanelOperationControl;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelPlatform;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelRelationWorkspace;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelSecurityAuditTrail;
use Dataphyre\Panel\PanelSensitiveDataSanitizer;
use Dataphyre\Panel\PanelTrace;
use Dataphyre\Panel\PanelWorkspacePreferences;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

test('security sanitizer covers private key families credentials keys and UTF-8 boundaries',static function(Context $t):void{
	$payload=[
		'rsa'=>'-----BEGIN RSA PRIVATE'.' KEY-----\nRSA-SECRET',
		'ec'=>'-----BEGIN EC PRIVATE'.' KEY-----\nEC-SECRET',
		'openssh'=>'-----BEGIN OPENSSH PRIVATE'.' KEY-----\nSSH-SECRET',
		'pgp'=>'-----BEGIN PGP PRIVATE KEY BLOCK-----\nPGP-SECRET',
		'message'=>'token=token-secret secret=secret-value credential=credential-value',
		'multiword'=>'password=my very secret phrase safe=value',
		'dsn'=>'postgres://operator:database-password@example.test/panel',
		'Bearer key-secret'=>true,
		'Bearer collision-one'=>1,
		'Bearer collision-two'=>2,
	];
	$clean=PanelSensitiveDataSanitizer::sanitize($payload);
	$json=json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	foreach(['RSA-SECRET','EC-SECRET','SSH-SECRET','PGP-SECRET','token-secret','secret-value','credential-value','my very secret phrase','database-password','key-secret','collision-one','collision-two']as$secret){$t->notContains($secret,$json);}
	$t->same(count($payload),count($clean));
	$t->contains('Bearer [REDACTED]',$json);

	$multibyte=str_repeat("\u{20AC}",167);
	$bounded=PanelSensitiveDataSanitizer::sanitize($multibyte,['max_string_bytes'=>500]);
	$t->isTrue(preg_match('//u',$bounded)===1);
	$t->same(501,strlen($bounded));
	$t->same("\u{FFFD}1",PanelSensitiveDataSanitizer::normalizeUtf8("\xB1\x31"));
	$exception=PanelSensitiveDataSanitizer::sanitize(new RuntimeException('token=exception-token secret=exception secret'));
	$t->same(RuntimeException::class,$exception['exception']);
	$t->notContains('exception-token',$exception['message']);
	$t->notContains('exception secret',$exception['message']);

	$envelope=PanelErrorEnvelope::response('probe',500,$multibyte,new RuntimeException('token=exception-token secret=exception-secret'),true,[],'utf8-probe');
	$decoded=json_decode($envelope->content(),true,512,JSON_THROW_ON_ERROR);
	$t->same('utf8-probe',$decoded['correlation_id']);
	$t->same('probe',$decoded['error']['code']);
	$t->notContains('exception-token',$envelope->content());
	$t->notContains('exception-secret',$envelope->content());
})->tag('panel','security','sanitizer','utf8','adversarial')->maxMillis(1500);

test('trace re-sanitizes legacy session mirrors and keeps summaries JSON safe',static function(Context $t):void{
	if(session_status()!==PHP_SESSION_ACTIVE){session_start();}
	PanelTrace::flush();
	$t->globalMap('_SESSION')->put('dataphyre_panel_trace_recent',[
		['id'=>'legacy-1','event'=>'legacy.event','context'=>['token'=>'session-token','message'=>'secret=session-secret','Bearer key-secret'=>true]],
		'malformed-event',
	]);
	$events=PanelTrace::events();
	$json=json_encode($events,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	$t->same(1,count($events));
	foreach(['session-token','session-secret','key-secret']as$secret){$t->notContains($secret,$json);}
	PanelTrace::record('utf8.trace',['message'=>str_repeat("\u{20AC}",167)]);
	json_encode(PanelTrace::summary(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
	PanelTrace::flush();
})->tag('panel','security','trace','session','adversarial')->maxMillis(1000);

test('CSV formula defense treats invalid encodings as text',static function(Context $t):void{
	$renderer=$t->nonPublic(PanelRenderer::class);
	foreach(["\xA0=1+1","\xFF@SUM(1,1)","\xC3=CMD()"]as$value){
		$safe=$renderer->invoke('spreadsheetSafeCsvValue',$value);
		$t->same("'",$safe[0]);
		$t->isTrue(preg_match('//u',$safe)===1);
	}
	$t->same("'\u{00A0}=1+1",$renderer->invoke('spreadsheetSafeCsvValue',"\u{00A0}=1+1"));
	$t->same("'=cmd|' /C calc'!A0",$renderer->invoke('spreadsheetSafeCsvValue',"=cmd|' /C calc'!A0"));
})->tag('panel','security','csv','invalid-utf8','adversarial')->maxMillis(1000);

test('security audit supports keyed downgrade-resistant chains and safe UTF-8 records',static function(Context $t):void{
	$key=str_repeat('K',32);$file=$t->workspace('panel-keyed-security-audit')->path('audit.json');$trail=new PanelSecurityAuditTrail($file,$key);
	$trail->record('authorization.decision',['actor_id'=>'operator'],['message'=>str_repeat("\u{20AC}",1366),'Bearer key-secret'=>true]);
	$t->isTrue($trail->verify());
	$t->isTrue($trail->tamperEvident());
	$t->same('hmac-sha256-v1',$trail->integrityMode());
	$json=json_encode($trail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
	$t->notContains('key-secret',$json);

	$events=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$forged=$events[0];unset($forged['hash']);$forged['subject']['forged']=true;$forged['integrity']='checksum-sha256-v1';$forged['hash']=hash('sha256',json_encode($forged,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));file_put_contents($file,json_encode([$forged],JSON_THROW_ON_ERROR));
	$reopened=new PanelSecurityAuditTrail($file,$key);
	$t->isFalse($reopened->verify());
	$t->throws(static fn()=>$reopened->record('forged',['actor_id'=>'operator'],['ok'=>false]),RuntimeException::class);
	$t->throws(static fn()=>new PanelSecurityAuditTrail(null,'short'),InvalidArgumentException::class);
	$checksum=new PanelSecurityAuditTrail();$checksum->record('compatible',['actor_id'=>'operator'],['ok'=>true]);$t->isFalse($checksum->tamperEvident());$t->same('checksum-sha256-v1',$checksum->integrityMode());
})->tag('panel','security','audit','hmac','adversarial')->maxMillis(1500);

test('platform reads fail closed and custom policy cannot bypass tenant isolation',static function(Context $t):void{
	$store=new PanelFilesystemOperationStore($t->workspace('panel-read-boundary')->root());
	$relations=PanelRelationWorkspace::make('items','order-1',new PanelArrayRelationAdapter());
	$registry=new AutomationRegistry();$inbox=PanelNotificationInbox::make();$preferences=new PanelWorkspacePreferences(new PanelInMemoryPreferenceStore(),'operator');$collaboration=new PanelCollaborationManager(new PanelInMemoryCollaborationStore());$authentication=PanelAuthenticationManager::memory(str_repeat('E',32),str_repeat('P',24));$inspection=PanelManifestInspector::inspect(['type'=>'panel']);
	$denied=(new PanelPlatformController())->authorize(static fn():bool=>false);$request=['method'=>'GET','user'=>['id'=>'operator']];
	$reads=[
		$denied->operations($store,[],$request),$denied->relations($relations,[],$request),$denied->workflows([],[],$request),$denied->automation($registry,[],[],$request),$denied->notifications($inbox,[],$request),$denied->media([],[],$request),$denied->preferences($preferences,[],$request),$denied->collaboration($collaboration,[],$request),$denied->security([],[],$request),$denied->authentication($authentication,'operator',[],$request),$denied->developer($inspection,null,null,[],$request),
	];
	foreach($reads as$result){$t->same(403,$result->status());}
	$t->same(401,$denied->security([])->status());
	$allowed=(new PanelPlatformController())->authorize(static fn():bool=>true);
	$t->same(200,$allowed->security([],[],$request)->status());
	$t->same(200,(new PanelPlatformController())->securityBoundary()->security([],[],['method'=>'GET','user'=>['id'=>'operator','permissions'=>['security.view']]])->status());

	$notification=$inbox->add(['title'=>'Tenant boundary']);$tenantController=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary(new PanelSecurityAuditTrail())->authorize(static fn():bool=>true);
	$mismatch=['method'=>'POST','tenant'=>'tenant-b','input'=>['id'=>$notification->id(),'operation'=>'read'],'user'=>['id'=>'operator','tenant_id'=>'tenant-a']];
	$t->same(403,$tenantController->notify($inbox,$mismatch)->status());
	$t->same(1,$inbox->counts()['unread']);
	$t->same(403,$tenantController->security([],[],['method'=>'GET','tenant'=>'tenant-b','user'=>['id'=>'operator','tenant_id'=>'tenant-a']])->status());

	$control=new PanelOperationControl($store);$invalid=['method'=>'POST','input'=>['id'=>'','operation'=>'invalid']];
	$t->same(419,(new PanelPlatformController())->operate($control,$invalid)->status());
	$t->same(403,(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true)->operate($control,$invalid)->status());
	$t->same(422,(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static fn():bool=>true)->operate($control,$invalid+['user'=>['id'=>'operator']])->status());

	$platform=PanelPlatform::defaults([
		'state_root'=>$t->tempDirectory('panel-read-pages'),
		'authentication'=>['encryption_key'=>str_repeat('E',32),'pepper'=>str_repeat('P',32)],
		'media'=>['signing_key'=>str_repeat('M',32)],
		'security'=>['audit_key'=>str_repeat('A',32)],
		'platform'=>['csrf'=>static fn():bool=>true,'authorize'=>static fn():bool=>false],
	]);
	$page=$platform->pages(['domains'=>['security']])['platform_security'];$mounted=$page->render(PanelRequest::fromArray($request));
	$t->instanceOf(PanelPageResult::class,$mounted);$t->same(403,$mounted->status());
})->tag('panel','security','platform-controller','read-boundary','tenant','adversarial')->maxMillis(5000);
