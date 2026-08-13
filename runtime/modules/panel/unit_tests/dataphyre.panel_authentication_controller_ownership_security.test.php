<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelAuthenticationAccess;
use Dataphyre\Panel\PanelAuthenticationCipher;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelLocalOneTimeChallengeAdapter;
use Dataphyre\Panel\PanelMemoryAuthenticationStore;
use Dataphyre\Panel\PanelPlatformController;
use Dataphyre\Panel\PanelSecurityAuditTrail;
use Dataphyre\Panel\PanelTotp;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{PanelAuthenticationManager,PanelLocalOneTimeChallengeAdapter} */
function dp_panel_authentication_controller_ownership_manager():array{
	$adapter=new PanelLocalOneTimeChallengeAdapter('controller-ownership-challenge-key!');
	return [new PanelAuthenticationManager(
		new PanelMemoryAuthenticationStore(),
		new PanelAuthenticationCipher('controller-ownership-encryption!'),
		$adapter,
		'controller-ownership-pepper-key!'
	),$adapter];
}

/** @return array<string,mixed> */
function dp_panel_authentication_controller_payload(string $content):array{return json_decode($content,true,512,JSON_THROW_ON_ERROR);}

test('authentication controller treats foreign and missing object ids identically inside a self-service scope',static function(Context $t):void{
	[$manager]=dp_panel_authentication_controller_ownership_manager();$bob=$manager->scoped(PanelAuthenticationAccess::self('bob'));$timestamp=1234567890;$secret=PanelTotp::base32Encode('12345678901234567890');
	$bob->provisionTotp('Bob factor',['id'=>'factor-bob','secret'=>$secret,'now'=>$timestamp]);$t->isTrue($bob->confirmTotp('factor-bob',PanelTotp::at($secret,$timestamp),$timestamp)->verified());
	$challenge=$bob->beginChallenge('Bob approval','email',['id'=>'challenge-bob','recipient'=>'bob@example.test','now'=>$timestamp]);
	$device=$bob->trustDevice('Bob laptop','bob-fingerprint',['id'=>'device-bob','now'=>$timestamp]);$session=$bob->createSession(['id'=>'session-bob','device_id'=>$device->device()->id(),'now'=>$timestamp]);
	$subjects=[];$controller=(new PanelPlatformController())->csrf(static fn():bool=>true)->authorize(static function(string $ability,mixed $user,array $subject)use(&$subjects):bool{$subjects[$ability][]=$subject;return true;});
	$post=static fn(string $operation,string $id):array=>['method'=>'POST','input'=>['operation'=>$operation,'id'=>$id],'user'=>['id'=>'alice']];
	foreach(['confirm_totp','verify_challenge']as$operation){$foreign=dp_panel_authentication_controller_payload($controller->authenticate($manager,$post($operation,$operation==='confirm_totp'?'factor-bob':'challenge-bob'))->content());$missing=dp_panel_authentication_controller_payload($controller->authenticate($manager,$post($operation,'missing'))->content());$t->same($missing['verified'],$foreign['verified']);$t->same($missing['reason'],$foreign['reason']);}
	foreach(['disable_totp'=>'factor-bob','cancel_challenge'=>'challenge-bob','revoke_device'=>'device-bob','revoke_session'=>'session-bob']as$operation=>$id){$foreign=dp_panel_authentication_controller_payload($controller->authenticate($manager,$post($operation,$id))->content());$missing=dp_panel_authentication_controller_payload($controller->authenticate($manager,$post($operation,'missing'))->content());$t->same($missing['ok'],$foreign['ok']);$t->isFalse($foreign['ok']);}
	$t->isTrue($manager->factors('bob')[0]['enabled']);$t->same('pending',$bob->challenge($challenge->id())?->status());$t->notNull($bob->verifyTrustedDevice('device-bob',$device->token(),'bob-fingerprint',$timestamp+1));$t->notNull($bob->authenticateSession('session-bob',$session->token(),$timestamp+1));
	$deviceSubject=array_values(array_filter($subjects['authentication.revoke_device']??[],static fn(array $subject):bool=>($subject['id']??null)==='device-bob'))[0]??[];$t->same('alice',$deviceSubject['target']??null);$t->same('bob',$deviceSubject['owner']??null);$t->same('device-bob',$deviceSubject['id']??null);
})->tag('panel','authentication','controller','ownership','idor','non-enumeration')->maxMillis(3000);

test('cross-user authentication mutations require and audit an independent elevation decision',static function(Context $t):void{
	[$manager]=dp_panel_authentication_controller_ownership_manager();$alice=$manager->scoped(PanelAuthenticationAccess::self('alice'));$bob=$manager->scoped(PanelAuthenticationAccess::self('bob'));$aliceSession=$alice->createSession(['id'=>'session-alice','now'=>2000]);$bobSession=$bob->createSession(['id'=>'session-bob','now'=>2000]);
	$deniedAudit=new PanelSecurityAuditTrail();$deniedAbilities=[];$denied=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary($deniedAudit)->authorize(static function(string $ability)use(&$deniedAbilities):bool{$deniedAbilities[]=$ability;return$ability!==PanelAuthenticationAccess::CROSS_USER_ABILITY;});
	$request=['method'=>'POST','input'=>['operation'=>'revoke_session','user_id'=>'bob','id'=>'session-bob'],'user'=>['id'=>'alice']];$denial=$denied->authenticate($manager,$request);$t->same(403,$denial->status());$t->notNull($bob->authenticateSession('session-bob',$bobSession->token(),2001));$t->same(['authentication.revoke_session',PanelAuthenticationAccess::CROSS_USER_ABILITY],$deniedAbilities);
	$deniedEvents=$deniedAudit->events('mutation.authorization');$t->same(2,count($deniedEvents));$t->same('bob',$deniedEvents[0]['subject']['subject']['target']);$t->same('bob',$deniedEvents[0]['subject']['subject']['owner']);$t->same('session-bob',$deniedEvents[0]['subject']['subject']['id']);$t->same(PanelAuthenticationAccess::CROSS_USER_ABILITY,$deniedEvents[1]['subject']['ability']);

	$allowedAudit=new PanelSecurityAuditTrail();$allowedAbilities=[];$allowed=(new PanelPlatformController())->csrf(static fn():bool=>true)->securityBoundary($allowedAudit)->authorize(static function(string $ability)use(&$allowedAbilities):bool{$allowedAbilities[]=$ability;return true;});$result=dp_panel_authentication_controller_payload($allowed->authenticate($manager,$request)->content());$t->isTrue($result['ok']);$t->same(null,$bob->authenticateSession('session-bob',$bobSession->token(),2002));$t->notNull($alice->authenticateSession('session-alice',$aliceSession->token(),2002));$t->same(['authentication.revoke_session',PanelAuthenticationAccess::CROSS_USER_ABILITY],$allowedAbilities);$t->same(2,count($allowedAudit->events('mutation.authorization')));
})->tag('panel','authentication','controller','cross-user','audit','authorization')->maxMillis(3000);

test('authentication inventory pages are owner-bound and cross-user pages preserve their target in actions',static function(Context $t):void{
	[$manager]=dp_panel_authentication_controller_ownership_manager();$bob=$manager->scoped(PanelAuthenticationAccess::self('bob'));$session=$bob->createSession(['id'=>'session-bob','now'=>3000]);
	$deniedAudit=new PanelSecurityAuditTrail();$deniedAbilities=[];$denied=(new PanelPlatformController())->securityBoundary($deniedAudit)->authorize(static function(string $ability)use(&$deniedAbilities):bool{$deniedAbilities[]=$ability;return$ability!==PanelAuthenticationAccess::CROSS_USER_ABILITY;});
	$bobPage=$denied->authentication($manager,'bob',[],['method'=>'GET','user'=>['id'=>'alice']]);$ghostPage=$denied->authentication($manager,'ghost',[],['method'=>'GET','user'=>['id'=>'alice']]);$t->same(403,$bobPage->status());$t->same(403,$ghostPage->status());$t->notContains('session-bob',$bobPage->content());$t->same(['authentication.view',PanelAuthenticationAccess::CROSS_USER_ABILITY,'authentication.view',PanelAuthenticationAccess::CROSS_USER_ABILITY],$deniedAbilities);
	$events=$deniedAudit->events('read.authorization');$t->same(4,count($events));$t->same('bob',$events[0]['subject']['subject']['target']);$t->same('bob',$events[0]['subject']['subject']['owner']);$t->same(null,$events[0]['subject']['subject']['id']);

	$allowedAbilities=[];$allowed=(new PanelPlatformController())->authorize(static function(string $ability)use(&$allowedAbilities):bool{$allowedAbilities[]=$ability;return true;});$page=$allowed->authentication($manager,'bob',['action_url'=>'/authentication'],['method'=>'GET','user'=>['id'=>'admin']]);$t->same(200,$page->status());$t->contains('session-bob',$page->content());$t->contains('name="user_id" value="bob"',$page->content());$t->isTrue($page->data()['authentication_access']['elevated']);$t->same('admin',$page->data()['authentication_access']['actor_id']);$t->same('bob',$page->data()['authentication_access']['target_user_id']);$t->same(['authentication.view',PanelAuthenticationAccess::CROSS_USER_ABILITY],$allowedAbilities);
	$selfAbilities=[];$self=(new PanelPlatformController())->authorize(static function(string $ability)use(&$selfAbilities):bool{$selfAbilities[]=$ability;return true;});$selfPage=$self->authentication($manager,'bob',[],['method'=>'GET','user'=>['id'=>'bob']]);$t->same(200,$selfPage->status());$t->same(['authentication.view'],$selfAbilities);$t->notNull($bob->authenticateSession('session-bob',$session->token(),3001));
})->tag('panel','authentication','page','ownership','idor','cross-user')->maxMillis(3000);
