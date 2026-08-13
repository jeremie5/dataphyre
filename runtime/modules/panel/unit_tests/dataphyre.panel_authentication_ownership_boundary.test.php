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
use Dataphyre\Panel\PanelAuthenticationDecision;
use Dataphyre\Panel\PanelAuthenticationManager;
use Dataphyre\Panel\PanelAuthenticationOwnershipViolation;
use Dataphyre\Panel\PanelAuthenticationRecord;
use Dataphyre\Panel\PanelLocalOneTimeChallengeAdapter;
use Dataphyre\Panel\PanelMemoryAuthenticationStore;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelSecurityDecision;
use Dataphyre\Panel\PanelTotp;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{PanelAuthenticationManager,PanelLocalOneTimeChallengeAdapter} */
function dp_panel_authentication_ownership_manager():array{
	$adapter=new PanelLocalOneTimeChallengeAdapter('ownership-challenge-key-32-bytes!');
	$manager=new PanelAuthenticationManager(
		new PanelMemoryAuthenticationStore(),
		new PanelAuthenticationCipher('ownership-encryption-key-32-bytes!'),
		$adapter,
		'ownership-pepper-key-32-bytes!!!!'
	);
	return [$manager,$adapter];
}

test('authentication access scopes make cross-user targeting an explicit elevated ability',static function(Context $t):void{
	$self=PanelAuthenticationAccess::self('alice');
	$t->same('alice',$self->actorId());
	$t->same('alice',$self->targetUserId());
	$t->isTrue($self->selfService());
	$t->isFalse($self->elevatedAccess());
	$t->same(null,$self->elevatedAbility());
	$t->isTrue($self->allows('alice'));
	$t->isFalse($self->allows('bob'));
	$t->same('panel_authentication_access',$self->jsonSerialize()['type']);
	$t->throws(static fn()=>PanelAuthenticationAccess::self(''),InvalidArgumentException::class);

	$unprivileged=PanelSecurityContext::make('alice',['permissions'=>['authentication.revoke_session']]);
	$t->throws(static fn()=>PanelAuthenticationAccess::forTarget($unprivileged,'bob'),PanelAuthenticationOwnershipViolation::class);
	$t->same('alice',PanelAuthenticationAccess::forTarget($unprivileged,'alice')->targetUserId());

	$privileged=PanelSecurityContext::make('admin',['permissions'=>[PanelAuthenticationAccess::CROSS_USER_ABILITY]]);
	$elevated=PanelAuthenticationAccess::forTarget($privileged,'bob');
	$t->same('admin',$elevated->actorId());
	$t->same('bob',$elevated->targetUserId());
	$t->isFalse($elevated->selfService());
	$t->isTrue($elevated->elevatedAccess());
	$t->same(PanelAuthenticationAccess::CROSS_USER_ABILITY,$elevated->elevatedAbility());

	$denied=new PanelSecurityDecision(false,PanelAuthenticationAccess::CROSS_USER_ABILITY);
	$wrongAbility=new PanelSecurityDecision(true,'authentication.revoke_session');
	$allowed=new PanelSecurityDecision(true,PanelAuthenticationAccess::CROSS_USER_ABILITY);
	$t->throws(static fn()=>PanelAuthenticationAccess::elevated('admin','bob',$denied),PanelAuthenticationOwnershipViolation::class);
	$t->throws(static fn()=>PanelAuthenticationAccess::elevated('admin','bob',$wrongAbility),PanelAuthenticationOwnershipViolation::class);
	$t->throws(static fn()=>PanelAuthenticationAccess::elevated('alice','alice',$allowed),PanelAuthenticationOwnershipViolation::class);
	$t->same('bob',PanelAuthenticationAccess::elevated('admin','bob',$allowed)->targetUserId());

	$owned=PanelAuthenticationRecord::make('sessions','owned',['user_id'=>'alice'],100);
	$orphan=PanelAuthenticationRecord::make('sessions','orphan',[],100);
	$t->same('alice',$owned->ownerId());
	$t->isTrue($owned->ownedBy('alice'));
	$t->isFalse($owned->ownedBy('bob'));
	$t->same(null,$orphan->ownerId());
	$t->isFalse($orphan->ownedBy('alice'));
})->tag('panel','authentication','ownership','authorization','idor')->maxMillis(2000);

test('owner-bound facade rejects cross-user factor challenge device and session identifiers without side effects',static function(Context $t):void{
	[$manager,$adapter]=dp_panel_authentication_ownership_manager();
	$alice=$manager->scoped(PanelAuthenticationAccess::self('alice'));
	$bob=$manager->scoped(PanelAuthenticationAccess::self('bob'));
	$timestamp=1234567890;
	$secret=PanelTotp::base32Encode('12345678901234567890');

	$aliceEnrollment=$alice->provisionTotp('Alice primary',['id'=>'factor-alice','secret'=>$secret,'now'=>$timestamp,'recovery_codes'=>4]);
	$bobEnrollment=$bob->provisionTotp('Bob primary',['id'=>'factor-bob','secret'=>$secret,'now'=>$timestamp,'recovery_codes'=>4]);
	$code=PanelTotp::at($secret,$timestamp);
	$t->same('factor_not_found',$alice->confirmTotp('factor-bob',$code,$timestamp)->reason());
	$t->isTrue($bob->confirmTotp('factor-bob',$code,$timestamp)->verified());
	$t->isTrue($alice->confirmTotp('factor-alice',$code,$timestamp)->verified());
	$t->isTrue($bob->verifyTotp(PanelTotp::at($secret,$timestamp+30),$timestamp+30)->verified());
	$t->isTrue($bob->useRecoveryCode($bobEnrollment->recoveryCodes()[0],$timestamp+31)->verified());
	$t->same(3,$bob->factors()[0]['recovery_codes_remaining']);
	$t->isFalse($alice->disableTotp('factor-bob',$timestamp+32));
	$t->isTrue($bob->disableTotp('factor-bob',$timestamp+32));
	$t->notEmpty($aliceEnrollment->recoveryCodes());

	$t->same('alice',$manager->ownerOf('factor','factor-alice'));
	$t->same('bob',$manager->ownerOf('factors','factor-bob'));
	$t->isTrue($manager->ownedBy('totp','factor-alice','alice'));
	$t->isFalse($manager->ownedBy('factor','factor-alice','bob'));
	$t->same(null,$manager->ownerOf('session',''));
	$t->isFalse($manager->ownedBy('session','','alice'));
	$t->throws(static fn()=>$manager->ownerOf('unknown','id'),InvalidArgumentException::class);

	$challenge=$bob->beginChallenge('Approve payout','email',['id'=>'challenge-bob','recipient'=>'bob@example.test','now'=>2000]);
	$challengeCode=(string)$adapter->codeFor($challenge->id());
	$t->same(null,$alice->challenge($challenge->id()));
	$t->same('challenge_not_found',$alice->verifyChallenge($challenge->id(),$challengeCode,2001)->reason());
	$t->same(0,$bob->challenge($challenge->id())?->attempts());
	$t->isFalse($alice->cancelChallenge($challenge->id(),2001));
	$t->isTrue($bob->verifyChallenge($challenge->id(),$challengeCode,2002)->verified());
	$cancelled=$bob->beginChallenge('Cancel payout','email',['id'=>'challenge-cancel-bob','recipient'=>'bob@example.test','now'=>2010]);
	$t->isTrue($bob->cancelChallenge($cancelled->id(),2011));

	$device=$bob->trustDevice('Bob laptop','bob-fingerprint',['id'=>'device-bob','now'=>3000,'ttl_seconds'=>3600]);
	$session=$bob->createSession(['id'=>'session-bob','device_id'=>'device-bob','now'=>3001,'ttl_seconds'=>3600]);
	$t->same(null,$alice->verifyTrustedDevice('device-bob',$device->token(),'bob-fingerprint',3002));
	$t->isFalse($alice->revokeDevice('device-bob',3002));
	$t->notNull($bob->verifyTrustedDevice('device-bob',$device->token(),'bob-fingerprint',3002));
	$t->same(null,$alice->authenticateSession('session-bob',$session->token(),3003));
	$t->isFalse($alice->revokeSession('session-bob',3003));
	$t->notNull($bob->authenticateSession('session-bob',$session->token(),3003));
	$manager->store()->create(PanelAuthenticationRecord::make('sessions','foreign-device-link',['user_id'=>'alice','device_id'=>'device-bob','revoked_at'=>null],3003));
	$t->isTrue($bob->revokeDevice('device-bob',3004));
	$t->same(null,$manager->store()->get('sessions','foreign-device-link')?->value('revoked_at'));
	$t->same(null,$bob->authenticateSession('session-bob',$session->token(),3005));

	$unbound=$bob->createSession(['id'=>'session-bob-unbound','now'=>3010,'ttl_seconds'=>3600]);
	$t->notNull($bob->authenticateSession('session-bob-unbound',$unbound->token(),3011));
	$t->isTrue($bob->revokeSession('session-bob-unbound',3012));
	$t->same(1,count($bob->devices()));
	$t->same(2,count($bob->sessions()));
	$t->same('bob',$manager->ownerOf('trusted_device','device-bob'));
	$t->same('bob',$manager->ownerOf('challenges','challenge-bob'));
	$t->same('bob',$manager->ownerOf('sessions','session-bob'));
})->tag('panel','authentication','ownership','idor','non-enumeration')->maxMillis(3000);

test('account-wide revocation stays on the bound target and elevated scopes retain explicit provenance',static function(Context $t):void{
	[$manager]=dp_panel_authentication_ownership_manager();
	$alice=$manager->scoped(PanelAuthenticationAccess::self('alice'));
	$bob=$manager->scoped(PanelAuthenticationAccess::self('bob'));

	$aliceDeviceOne=$alice->trustDevice('Alice laptop','alice-one',['id'=>'alice-device-one','now'=>4000]);
	$aliceDeviceTwo=$alice->trustDevice('Alice phone','alice-two',['id'=>'alice-device-two','now'=>4000]);
	$bobDevice=$bob->trustDevice('Bob laptop','bob-one',['id'=>'bob-device-one','now'=>4000]);
	$aliceBound=$alice->createSession(['id'=>'alice-bound','device_id'=>$aliceDeviceOne->device()->id(),'now'=>4001]);
	$bobBound=$bob->createSession(['id'=>'bob-bound','device_id'=>$bobDevice->device()->id(),'now'=>4001]);
	$storedAlice=$manager->store()->get('sessions','alice-bound');
	$t->notNull($storedAlice);
	$tamperedAlice=$manager->store()->save($storedAlice->merge(['device_id'=>'bob-device-one'],4001),$storedAlice->revision());
	$t->same(null,$alice->authenticateSession('alice-bound',$aliceBound->token(),4001));
	$manager->store()->save($tamperedAlice->merge(['device_id'=>'alice-device-one'],4001),$tamperedAlice->revision());
	$t->same(2,$alice->revokeAllDevices(4002));
	$t->same(null,$alice->authenticateSession('alice-bound',$aliceBound->token(),4003));
	$t->notNull($bob->verifyTrustedDevice('bob-device-one',$bobDevice->token(),'bob-one',4003));
	$t->notNull($bob->authenticateSession('bob-bound',$bobBound->token(),4003));
	$t->isFalse($aliceDeviceTwo->device()->id()==='');

	$aliceOne=$alice->createSession(['id'=>'alice-one','now'=>4010]);
	$aliceTwo=$alice->createSession(['id'=>'alice-two','now'=>4010]);
	$t->same(1,$alice->revokeAllSessions('alice-two',4011));
	$t->same(null,$alice->authenticateSession('alice-one',$aliceOne->token(),4012));
	$t->notNull($alice->authenticateSession('alice-two',$aliceTwo->token(),4012));
	$t->same(1,$alice->revokeAllSessions(null,4013));
	$t->notNull($bob->authenticateSession('bob-bound',$bobBound->token(),4014));

	$context=PanelSecurityContext::make('admin',['permissions'=>[PanelAuthenticationAccess::CROSS_USER_ABILITY]]);
	$access=PanelAuthenticationAccess::forTarget($context,'bob');
	$elevated=$manager->scoped($access);
	$t->same($access,$elevated->access());
	$t->same('bob',$elevated->targetUserId());
	$t->isTrue($elevated->jsonSerialize()['access']['elevated']);
	$t->isTrue($elevated->jsonSerialize()['capabilities']['owner_scopes']);
	$extraDevice=$elevated->trustDevice('Bob tablet','bob-two',['id'=>'bob-device-two','now'=>4020]);
	$extraSession=$elevated->createSession(['id'=>'bob-extra','device_id'=>$extraDevice->device()->id(),'now'=>4021]);
	$t->notNull($elevated->authenticateSession('bob-extra',$extraSession->token(),4022));
	$t->same(2,$elevated->revokeAllDevices(4023));
	$t->same(null,$elevated->authenticateSession('bob-extra',$extraSession->token(),4024));
	$t->same(0,$elevated->revokeAllSessions(null,4025));
	$t->same('panel_authentication_manager',$manager->jsonSerialize()['type']);
})->tag('panel','authentication','ownership','revoke-all','elevated')->maxMillis(3000);
