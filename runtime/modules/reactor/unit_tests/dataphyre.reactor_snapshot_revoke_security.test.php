<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorInMemorySnapshotVersionStore;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorSecurityContext;
use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorSnapshotVersionStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\reactor', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class reactor {
	public static mixed $runtimeConfig=[];
	public static function config(string $key, mixed $default=null): mixed {
		return is_array(self::$runtimeConfig) && array_key_exists($key, self::$runtimeConfig) ? self::$runtimeConfig[$key] : $default;
	}
}
PHP);
}
if(!class_exists('Dataphyre\\Reactor\\Reactor', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Reactor;
final class Reactor {
	public static bool $throwTransportConfig=false;
	public static function config(string $key, mixed $default=null): mixed {
		if(self::$throwTransportConfig && $key==='transport_authorizer'){ throw new \RuntimeException('private config failure'); }
		return \dataphyre\reactor::config($key,$default);
	}
}
PHP);
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', ['enabled'=>['core'=>true,'mvc'=>true,'reactor'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$dp_reactor_revoke_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_revoke_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_revoke_modules_root);
\dataphyre\autoloader::register_framework_modules(['core','mvc','reactor']);

final class DpReactorSnapshotRevokeStoreProbe implements ReactorSnapshotVersionStore {
	private ReactorInMemorySnapshotVersionStore $inner;
	public int $manifestCalls=0;
	public int $revokeCalls=0;
	public bool $throwManifest=false;
	public bool $throwRevoke=false;
	public bool $productionSafe=false;
	public function __construct(){ $this->inner=new ReactorInMemorySnapshotVersionStore(); }
	public function register(string $snapshotId,string $scopeHash,string $component,int $version,int $expiresAt): bool { return $this->inner->register($snapshotId,$scopeHash,$component,$version,$expiresAt); }
	public function reserve(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,string $reservationId,int $reservationExpiresAt): string { return $this->inner->reserve($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId,$reservationExpiresAt); }
	public function finalize(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,int $nextVersion,int $nextExpiresAt,string $reservationId): string { return $this->inner->finalize($snapshotId,$scopeHash,$component,$expectedVersion,$nextVersion,$nextExpiresAt,$reservationId); }
	public function abort(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,string $reservationId): bool { return $this->inner->abort($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId); }
	public function revoke(string $snapshotId,string $scopeHash,string $component,int $version): bool {
		$this->revokeCalls++;
		if($this->throwRevoke){ throw new RuntimeException('private ledger failure'); }
		return $this->inner->revoke($snapshotId,$scopeHash,$component,$version);
	}
	public function manifest(): array {
		$this->manifestCalls++;
		if($this->throwManifest){ throw new RuntimeException('private manifest failure'); }
		return array_replace($this->inner->manifest(),['adapter'=>'snapshot_revoke_probe','production_safe'=>$this->productionSafe]);
	}
}

/** @return array<string,string> */
function dp_reactor_snapshot_revoke_scope(string $principal='operator-a'): array {
	return ['scope_id'=>'panel-widget-v2:scope-a','audience'=>'panel-widget:ops:dashboard','principal_id'=>$principal,'correlation_id'=>'revoke-test'];
}

function dp_reactor_snapshot_revoke_register(DpReactorSnapshotRevokeStoreProbe $store,array $scope,string $marker='TOP_SECRET_REVOKE_VALUE'): ReactorSnapshot {
	$snapshot=ReactorSnapshot::make('unregistered.revoke.probe',['private'=>$marker],['private'],$scope);
	if(!$store->register($snapshot->snapshotId(),$snapshot->scopeHash(),$snapshot->component(),$snapshot->version(),$snapshot->expiresAt())){ throw new RuntimeException('fixture registration failed'); }
	return $snapshot;
}

/** Creates an authentic expired v2 envelope without observing the version store. */
function dp_reactor_snapshot_revoke_expired(array $scope): ReactorSnapshot {
	$now=time();
	$payload=[
		'schema_version'=>2,
		'snapshot_id'=>str_repeat('e',32),
		'component'=>'unregistered.revoke.probe',
		'state'=>['private'=>'EXPIRED_SECRET_VALUE'],
		'locked'=>[],
		'scope_hash'=>ReactorSecurityContext::fromTrusted($scope)->scopeHash(),
		'version'=>0,
		'created_at'=>$now-2,
		'expires_at'=>$now-1,
	];
	$snapshot=ReactorSnapshot::from($payload+['signature'=>ReactorSigner::sign($payload)]);
	if(!$snapshot instanceof ReactorSnapshot){ throw new RuntimeException('expired fixture creation failed'); }
	return $snapshot;
}

test('snapshot revoke proves signature and bound scope before expiry or ledger outcomes',static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('r',32)];
	$scope=dp_reactor_snapshot_revoke_scope();
	$store=new DpReactorSnapshotRevokeStoreProbe();
	$manager=(new ReactorManager($store))->authorizeTransport(static fn(): bool=>true);
	$snapshot=dp_reactor_snapshot_revoke_register($store,$scope);
	$baselineManifest=$store->manifestCalls;
	$baselineRevoke=$store->revokeCalls;

	$forged=$snapshot->jsonSerialize();
	$forged['expires_at']=time()-1;
	$forged['signature']='forged';
	$forgedSnapshot=ReactorSnapshot::from($forged);
	$t->isTrue($forgedSnapshot instanceof ReactorSnapshot);
	$forgedResponse=$manager->revokeSnapshot($forgedSnapshot,$scope);
	$t->same(419,$forgedResponse->status());
	$t->same('snapshot_invalid',$forgedResponse->effects()['error']['code']);
	$t->same($baselineManifest,$store->manifestCalls);
	$t->same($baselineRevoke,$store->revokeCalls);

	$crossScope=$manager->revokeSnapshot($snapshot,dp_reactor_snapshot_revoke_scope('operator-b'));
	$t->same(419,$crossScope->status());
	$t->same('snapshot_invalid',$crossScope->effects()['error']['code']);
	$t->same($baselineManifest,$store->manifestCalls);
	$t->same($baselineRevoke,$store->revokeCalls);

	$expired=$manager->revokeSnapshot(dp_reactor_snapshot_revoke_expired($scope),$scope);
	$t->same(419,$expired->status());
	$t->same('snapshot_expired',$expired->effects()['error']['code']);
	$t->same($baselineManifest,$store->manifestCalls);
	$t->same($baselineRevoke,$store->revokeCalls);

	$unbound=$manager->revokeSnapshot($snapshot,[]);
	$t->same(403,$unbound->status());
	$t->same('security_context_invalid',$unbound->effects()['error']['code']);
	$t->same($baselineManifest,$store->manifestCalls);
	$t->same($baselineRevoke,$store->revokeCalls);
	$t->isTrue($snapshot->verifyAuthenticity($scope));
	$t->isFalse($snapshot->verifyAuthenticity(dp_reactor_snapshot_revoke_scope('operator-b')));
})->tag('reactor','security','snapshot','revoke','oracle');

test('snapshot revoke transport gate is value free fail closed and immediately guards one exact revoke',static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('r',32)];
	$scope=dp_reactor_snapshot_revoke_scope();
	$store=new DpReactorSnapshotRevokeStoreProbe();
	$manager=new ReactorManager($store);
	$snapshot=dp_reactor_snapshot_revoke_register($store,$scope);

	$missing=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(403,$missing->status());
	$t->same('transport_security_required',$missing->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);
	\Dataphyre\Reactor\Reactor::$throwTransportConfig=true;
	$configFailure=$manager->revokeSnapshot($snapshot,$scope);
	\Dataphyre\Reactor\Reactor::$throwTransportConfig=false;
	$t->same(503,$configFailure->status());
	$t->same('transport_authorization_unavailable',$configFailure->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);
	$manager->authorizeTransport(static function(): never { throw new RuntimeException('private policy failure'); });
	$unavailable=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(503,$unavailable->status());
	$t->same('transport_authorization_unavailable',$unavailable->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);
	$manager->authorizeTransport(static fn(): bool=>false);
	$denied=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(403,$denied->status());
	$t->same('transport_denied',$denied->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);

	$captured=[];
	$manager->authorizeTransport(static function(array $envelope,ReactorSecurityContext $context)use(&$captured): bool { $captured=$envelope; return $context->correlationId()==='revoke-test'; });
	$accepted=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(200,$accepted->status());
	$t->same(1,$store->revokeCalls);
	$t->same('snapshot_revoke',$captured['operation']);
	$t->same('unregistered.revoke.probe',$captured['component']);
	$t->same(true,$captured['mutation_requested']);
	$t->same([],$captured['state_keys']);
	$t->same([],$captured['param_keys']);
	$t->same(0,$captured['upload_count']);
	$t->same(['revoked'=>true],$accepted->effects()['snapshot_revoke']);
	$capturedJson=json_encode($captured,JSON_THROW_ON_ERROR);
	$responseJson=json_encode($accepted,JSON_THROW_ON_ERROR);
	foreach(['TOP_SECRET_REVOKE_VALUE',$snapshot->snapshotId(),$snapshot->scopeHash(),(string)$snapshot->jsonSerialize()['signature']] as $private){
		$t->notContains($private,$capturedJson);
		$t->notContains($private,$responseJson);
	}
	$replayed=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(409,$replayed->status());
	$t->same('snapshot_stale',$replayed->effects()['error']['code']);
	$missingSnapshot=ReactorSnapshot::make('unregistered.revoke.probe',['private'=>'MISSING_SECRET'],[],$scope);
	$missingLedger=$manager->revokeSnapshot($missingSnapshot,$scope);
	$t->same(409,$missingLedger->status());
	$t->same($replayed->message(),$missingLedger->message());
	$t->same($replayed->effects()['error']['code'],$missingLedger->effects()['error']['code']);
	$t->same(3,$store->revokeCalls);
})->tag('reactor','security','snapshot','revoke','transport','replay');

test('snapshot revoke preserves production store policy and contains ledger faults',static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('r',32),'production'=>true];
	$scope=dp_reactor_snapshot_revoke_scope();
	$store=new DpReactorSnapshotRevokeStoreProbe();
	$manager=(new ReactorManager($store))->authorizeTransport(static fn(): bool=>true);
	$snapshot=dp_reactor_snapshot_revoke_register($store,$scope);
	$unsafe=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(503,$unsafe->status());
	$t->same('snapshot_version_store_required',$unsafe->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);
	$store->productionSafe=true;
	$store->throwManifest=true;
	$manifestFailure=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(503,$manifestFailure->status());
	$t->same('snapshot_version_store_unavailable',$manifestFailure->effects()['error']['code']);
	$t->same(0,$store->revokeCalls);
	$store->throwManifest=false;
	$store->throwRevoke=true;
	$revokeFailure=$manager->revokeSnapshot($snapshot,$scope);
	$t->same(503,$revokeFailure->status());
	$t->same('snapshot_version_store_unavailable',$revokeFailure->effects()['error']['code']);
	$t->same(1,$store->revokeCalls);
	$t->isFalse(str_contains(json_encode($revokeFailure,JSON_THROW_ON_ERROR),'private ledger failure'));
	$manifest=$manager->securityManifest();
	$t->same('authenticated_scope_bound_exact_version_revoke',$manifest['snapshot_revocation']);
	$t->isFalse($manifest['snapshot_revocation_resolves_or_renders_component']);
	$t->isFalse($manifest['snapshot_revocation_response_exposes_snapshot']);
	$t->isFalse($manifest['snapshot_revocation_idempotent_replay']);
})->tag('reactor','security','snapshot','revoke','production','faults');
