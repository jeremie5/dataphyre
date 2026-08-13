<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\Reactor;
use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorEndpoint;
use Dataphyre\Reactor\ReactorEffects;
use Dataphyre\Reactor\ReactorFileSnapshotVersionStore;
use Dataphyre\Reactor\ReactorInMemorySnapshotVersionStore;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorRequest;
use Dataphyre\Reactor\ReactorSecurityContext;
use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorSnapshotVersionStore;
use Dataphyre\Reactor\ReactorTrace;
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
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', ['enabled'=>['core'=>true,'mvc'=>true,'reactor'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$dp_reactor_security_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_security_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_security_modules_root);
\dataphyre\autoloader::register_framework_modules(['core','mvc','reactor']);

/** @return array<string,mixed> */
function dp_reactor_secure_scope(string $tenant='tenant-a', string $principal='operator-a', string $session='session-a', string $audience='panel'): array {
	return ['tenant_id'=>$tenant,'principal_id'=>$principal,'session_id'=>$session,'audience'=>$audience,'correlation_id'=>'security-test'];
}

final class DpReactorTrackingFinalizeStore implements ReactorSnapshotVersionStore {
	private ReactorInMemorySnapshotVersionStore $inner;
	public bool $failFinalize=false;
	/** @var list<string> */
	public array $registered=[];
	/** @var list<string> */
	public array $revoked=[];

	public function __construct(){ $this->inner=new ReactorInMemorySnapshotVersionStore(); }
	public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		$result=$this->inner->register($snapshotId, $scopeHash, $component, $version, $expiresAt);
		if($result){ $this->registered[]=$snapshotId; }
		return $result;
	}
	public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string {
		return $this->inner->reserve($snapshotId, $scopeHash, $component, $expectedVersion, $reservationId, $reservationExpiresAt);
	}
	public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string {
		return $this->failFinalize ? self::UNAVAILABLE : $this->inner->finalize($snapshotId, $scopeHash, $component, $expectedVersion, $nextVersion, $nextExpiresAt, $reservationId);
	}
	public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool {
		return $this->inner->abort($snapshotId, $scopeHash, $component, $expectedVersion, $reservationId);
	}
	public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool {
		$this->revoked[]=$snapshotId;
		return $this->inner->revoke($snapshotId, $scopeHash, $component, $version);
	}
	public function manifest(): array { return array_replace($this->inner->manifest(), ['adapter'=>'tracking_finalize_failure']); }
}

final class DpReactorNonAtomicProductionStore implements ReactorSnapshotVersionStore {
	public int $registerCalls=0;
	public int $revokeCalls=0;
	public int $reachableEntries=0;
	public function register(string $snapshotId, string $scopeHash, string $component, int $version, int $expiresAt): bool {
		$this->registerCalls++;
		if($this->registerCalls>1){ return false; }
		$this->reachableEntries++;
		return true;
	}
	public function reserve(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId, int $reservationExpiresAt): string { return self::UNAVAILABLE; }
	public function finalize(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, int $nextVersion, int $nextExpiresAt, string $reservationId): string { return self::UNAVAILABLE; }
	public function abort(string $snapshotId, string $scopeHash, string $component, int $expectedVersion, string $reservationId): bool { return false; }
	public function revoke(string $snapshotId, string $scopeHash, string $component, int $version): bool { $this->revokeCalls++; return false; }
	public function manifest(): array {
		return ['adapter'=>'non_atomic_production_probe','atomic_compare_and_swap'=>true,'production_safe'=>true,'persists_component_state'=>false];
	}
}

final class DpReactorJsonSerializableStateProbe implements JsonSerializable {
	public static int $calls=0;
	public function jsonSerialize(): mixed { self::$calls++; return ['executed'=>true]; }
}

test('reactor transport denial is state blind and precedes component resolution hydration action and render', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$secret=str_repeat('s', 32);
	$scope=dp_reactor_secure_scope();
	$sideEffects=['loader'=>0,'hydrating'=>0,'hydrated'=>0,'action'=>0,'render'=>0];
	\dataphyre\reactor::$runtimeConfig=[
		'secret'=>$secret,
		'components'=>[
			'lazy_probe'=>static function(ReactorComponent $component)use(&$sideEffects): ReactorComponent {
				$sideEffects['loader']++;
				return $component->render('loaded');
			},
		],
	];
	$manager=new ReactorManager(new ReactorInMemorySnapshotVersionStore());
	$t->throws(static fn()=>$manager->snapshot('lazy_probe'), InvalidArgumentException::class);
	$t->throws(static fn()=>$manager->mount('lazy_probe'), InvalidArgumentException::class);
	$t->same(0, $sideEffects['loader']);
	$denied=$manager->dispatch(ReactorRequest::fromArray(['component'=>'lazy_probe'], $scope));
	$t->same(403, $denied->status());
	$t->same('transport_security_required', $denied->effects()['error']['code']);
	$t->same(0, $sideEffects['loader']);

	$component=ReactorComponent::make('side_effect_probe')
		->state(['count'=>0])
		->hydrating(static function(array $state)use(&$sideEffects): array { $sideEffects['hydrating']++; return $state; })
		->hydrated(static function(array $state)use(&$sideEffects): array { $sideEffects['hydrated']++; return $state; })
		->action('mutate', static function(array $state)use(&$sideEffects): array { $sideEffects['action']++; return ['count'=>1]; })
		->render(static function(array $state)use(&$sideEffects): string { $sideEffects['render']++; return '<b>secret render</b>'; });
	$manager->register($component)->name();
	$manager->withHostSecurityContext($scope);
	$manager->authorizeTransport(static fn(): bool=>true);
	$snapshot=$manager->snapshot('side_effect_probe');
	$sideEffects=['loader'=>0,'hydrating'=>0,'hydrated'=>0,'action'=>0,'render'=>0];
	$capturedEnvelope=[];
	$manager->authorizeTransport(static function(array $envelope, ReactorSecurityContext $context)use(&$capturedEnvelope): bool {
		$capturedEnvelope=$envelope;
		return false;
	});
	$marker='TOP_SECRET_PREHYDRATION_VALUE_42';
	$request=ReactorRequest::fromArray([
		'component'=>'side_effect_probe',
		'action'=>'mutate',
		'snapshot'=>$snapshot->jsonSerialize(),
		'state'=>['private_value'=>$marker],
		'params'=>['password'=>$marker],
		'security_context'=>dp_reactor_secure_scope('forged-tenant','forged-user','forged-session','forged-audience'),
		'headers'=>['x-reactor-tenant'=>'forged-tenant'],
	], $scope);
	$response=$manager->dispatch($request);
	$t->same(403, $response->status());
	$t->same('transport_denied', $response->effects()['error']['code']);
	$t->same(['loader'=>0,'hydrating'=>0,'hydrated'=>0,'action'=>0,'render'=>0], $sideEffects);
	$t->same(['private_value'], $capturedEnvelope['state_keys']);
	$t->same(['password'], $capturedEnvelope['param_keys']);
	$t->isTrue($capturedEnvelope['snapshot']['verified']);
	$t->same(0, $capturedEnvelope['snapshot']['version']);
	$t->notContains($marker, json_encode($capturedEnvelope, JSON_THROW_ON_ERROR));
	$t->notContains($marker, json_encode(ReactorTrace::events(), JSON_THROW_ON_ERROR));
	$t->same([], $response->state());
	$t->same('', $response->html());
	$manager->authorizeTransport(static fn(): bool=>true);
	$unknownEnvelope=$snapshot->jsonSerialize();
	$unknownEnvelope['unexpected']='reject-me';
	$strict=$manager->dispatch(ReactorRequest::fromArray(['component'=>'side_effect_probe','snapshot'=>$unknownEnvelope], $scope));
	$t->same(419, $strict->status());
	$t->same('snapshot_invalid', $strict->effects()['error']['code']);
	$t->same(['loader'=>0,'hydrating'=>0,'hydrated'=>0,'action'=>0,'render'=>0], $sideEffects);

	$spoofManager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->authorizeTransport(static fn(): bool=>true);
	$spoofManager->register($component);
	$spoofed=$spoofManager->dispatch([
		'component'=>'side_effect_probe',
		'security_context'=>$scope,
		'headers'=>['x-reactor-audience'=>'panel'],
	]);
	$t->same(403, $spoofed->status());
	$t->same('security_scope_required', $spoofed->effects()['error']['code']);
})->tag('reactor','security','pre-hydration','transport')->maxMillis(2000);

test('reactor scoped snapshots reject cross tenant principal session and audience replay before callbacks', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('q', 32)];
	$scope=dp_reactor_secure_scope();
	$callbacks=0;
	$manager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(array $envelope, ReactorSecurityContext $context): bool=>true);
	$manager->register(
		ReactorComponent::make('scoped_counter')
			->state(['count'=>0])
			->hydrating(static function(array $state)use(&$callbacks): array { $callbacks++; return $state; })
			->action('increment', static function(array $state)use(&$callbacks): array { $callbacks++; return ['count'=>(int)($state['count'] ?? 0)+1]; })
			->render(static function(array $state)use(&$callbacks): string { $callbacks++; return (string)($state['count'] ?? 0); })
	);
	$snapshot=$manager->snapshot('scoped_counter');
	$callbacks=0;
	$wrongScopes=[
		dp_reactor_secure_scope('tenant-b'),
		dp_reactor_secure_scope('tenant-a','operator-b'),
		dp_reactor_secure_scope('tenant-a','operator-a','session-b'),
		dp_reactor_secure_scope('tenant-a','operator-a','session-a','other-audience'),
	];
	foreach($wrongScopes as $wrong){
		$response=$manager->dispatch(ReactorRequest::fromArray(['component'=>'scoped_counter','action'=>'increment','snapshot'=>$snapshot->jsonSerialize()], $wrong));
		$t->same(419, $response->status());
		$t->same('snapshot_invalid', $response->effects()['error']['code']);
	}
	$t->same(0, $callbacks);

	$accepted=$manager->dispatch(ReactorRequest::fromArray(['component'=>'scoped_counter','action'=>'increment','snapshot'=>$snapshot->jsonSerialize()], $scope));
	$t->same(200, $accepted->status());
	$t->same(1, $accepted->state()['count']);
	$next=ReactorSnapshot::from($accepted->effects()['snapshot']);
	$t->same(1, $next?->version());
	$afterAccepted=$callbacks;
	$replay=$manager->dispatch(ReactorRequest::fromArray(['component'=>'scoped_counter','action'=>'increment','snapshot'=>$snapshot->jsonSerialize()], $scope));
	$t->same(409, $replay->status());
	$t->same('snapshot_stale', $replay->effects()['error']['code']);
	$t->same($afterAccepted, $callbacks);
	$advanced=$manager->dispatch(ReactorRequest::fromArray(['component'=>'scoped_counter','action'=>'increment','snapshot'=>$next?->jsonSerialize()], $scope));
	$t->same(200, $advanced->status());
	$t->same(2, $advanced->effects()['snapshot']['version']);
})->tag('reactor','security','scope','cas','replay')->maxMillis(2000);

test('reactor scope fingerprints resist low entropy disclosure and verify across retained key rotation', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$old=str_repeat('o', 32);
	$new=str_repeat('n', 32);
	$scope=['tenant_id'=>'1','principal_id'=>'2','session_id'=>'3','audience'=>'4'];
	\dataphyre\reactor::$runtimeConfig=['signing_keys'=>['old'=>$old],'current_signing_key'=>'old'];
	$context=ReactorSecurityContext::fromTrusted($scope);
	$tag=$context->scopeHash();
	$plainClaims=$context->scopeClaims();
	ksort($plainClaims);
	$plain=hash('sha256', json_encode($plainClaims, JSON_THROW_ON_ERROR));
	$t->notSame($plain, $tag);
	$snapshot=ReactorSnapshot::make('rotation_probe', ['ready'=>true], [], $scope);

	\dataphyre\reactor::$runtimeConfig=['signing_keys'=>['new'=>$new,'old'=>$old],'current_signing_key'=>'new'];
	$t->isTrue($snapshot->verify($scope));
	$t->isTrue(ReactorSigner::verifyScopeFingerprint($context->scopeClaims(), $tag));
	$t->notSame($tag, ReactorSecurityContext::fromTrusted($scope)->scopeHash());
	$t->isFalse($snapshot->verify(array_replace($scope, ['principal_id'=>'9'])));

	\dataphyre\reactor::$runtimeConfig=['signing_keys'=>['new'=>$new],'current_signing_key'=>'new'];
	$t->isFalse($snapshot->verify($scope));
	$t->isFalse(ReactorSigner::verifyScopeFingerprint($context->scopeClaims(), $tag));
})->tag('reactor','security','scope','signing','rotation')->maxMillis(1500);

test('reactor batch host context is resolved once and partial transport denial is isolated', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$scope=dp_reactor_secure_scope();
	$resolutions=0;
	$blockedCallbacks=0;
	\dataphyre\reactor::$runtimeConfig=[
		'secret'=>str_repeat('b', 32),
		'security_context_resolver'=>static function()use(&$resolutions,$scope): array { $resolutions++; return $scope; },
	];
	$manager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->authorizeTransport(
		static fn(array $envelope, ReactorSecurityContext $context): bool=>$envelope['component']==='allowed'
	);
	$manager->register(ReactorComponent::make('allowed')->render('allowed'));
	$manager->register(ReactorComponent::make('blocked')->hydrating(static function(array $state)use(&$blockedCallbacks): array { $blockedCallbacks++; return $state; })->render(static function()use(&$blockedCallbacks): string { $blockedCallbacks++; return 'blocked'; }));
	Reactor::reset($manager);
	$batch=ReactorEndpoint::handleBatch([
		['component'=>'allowed','security_context'=>dp_reactor_secure_scope('forged')],
		['component'=>'blocked','headers'=>['x-reactor-tenant'=>'forged']],
	]);
	$t->same(1, $resolutions);
	$t->isTrue($batch[0]['ok']);
	$t->isFalse($batch[1]['ok']);
	$t->same('transport_denied', $batch[1]['effects']['error']['code']);
	$t->same(0, $blockedCallbacks);
	$t->same([], $batch[1]['state']);
})->tag('reactor','security','batch','transport')->maxMillis(1500);

test('reactor aborted reservations let the same signed client snapshot retry after callback failure', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('r', 32)];
	$scope=dp_reactor_secure_scope();
	$calls=0;
	$manager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$manager->register(ReactorComponent::make('retry_probe')->state(['ok'=>false])->action('fail', static function()use(&$calls): never { $calls++; throw new RuntimeException('internal state must not leak'); })->render('retry'));
	$snapshot=$manager->snapshot('retry_probe');
	$first=$manager->dispatch(ReactorRequest::fromArray(['component'=>'retry_probe','action'=>'fail','snapshot'=>$snapshot->jsonSerialize()], $scope));
	$second=$manager->dispatch(ReactorRequest::fromArray(['component'=>'retry_probe','action'=>'fail','snapshot'=>$snapshot->jsonSerialize()], $scope));
	$t->same(500, $first->status());
	$t->same(500, $second->status());
	$t->same('reactor_request_failed', $first->effects()['error']['code']);
	$t->same(2, $calls);
	$t->notContains('internal state', json_encode([$first,$second,ReactorTrace::events()], JSON_THROW_ON_ERROR));
})->tag('reactor','security','reservation','failure')->maxMillis(1500);

test('reactor upload-only mutations require snapshots and remain state blind before callbacks', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('u', 32)];
	$scope=dp_reactor_secure_scope();
	$callbacks=0;
	$manager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$manager->register(
		ReactorComponent::make('upload_probe')
			->hydrating(static function(array $state)use(&$callbacks): array { $callbacks++; return $state; })
			->render(static function()use(&$callbacks): string { $callbacks++; return 'uploaded'; })
	);
	$unsigned=$manager->dispatch(ReactorRequest::fromArray([
		'component'=>'upload_probe',
		'uploads'=>['csv'=>['name'=>'orders.csv']],
	], $scope));
	$t->same(419, $unsigned->status());
	$t->same('snapshot_required', $unsigned->effects()['error']['code']);
	$t->same(0, $callbacks);

	$snapshot=$manager->snapshot('upload_probe');
	$callbacks=0;
	$captured=[];
	$manager->authorizeTransport(static function(array $envelope)use(&$captured): bool { $captured=$envelope; return false; });
	$denied=$manager->dispatch(ReactorRequest::fromArray([
		'component'=>'upload_probe',
		'snapshot'=>$snapshot->jsonSerialize(),
		'uploads'=>['csv'=>['name'=>'TOP_SECRET_UPLOAD_NAME.csv']],
	], $scope));
	$t->same(403, $denied->status());
	$t->same('transport_denied', $denied->effects()['error']['code']);
	$t->same(1, $captured['upload_count']);
	$t->isTrue($captured['mutation_requested']);
	$t->notContains('TOP_SECRET_UPLOAD_NAME', json_encode($captured, JSON_THROW_ON_ERROR));
	$t->same(0, $callbacks);
})->tag('reactor','security','pre-hydration','uploads')->maxMillis(1500);

test('reactor validates complete effect payloads before finalizing snapshot reservations', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('v', 32)];
	if(session_status()!==PHP_SESSION_ACTIVE){ @session_start(); }
	$t->same(PHP_SESSION_ACTIVE, session_status());
	$session=$t->globalMap('_SESSION')->putPath(['dataphyre_reactor','effect-validation-count'], 0);
	$scope=dp_reactor_secure_scope();
	$calls=0;
	$manager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$manager->register(
		ReactorComponent::make('effect_validation_probe')
			->state(['count'=>0])
			->session('count', 'effect-validation-count')
			->action('invalid_effect', static function(array $state, array $params, ReactorComponent $component, ReactorEffects $effects)use(&$calls): array {
				$calls++;
				$effects->toast("\xB1");
				return ['count'=>(int)($state['count'] ?? 0)+1];
			})
			->render('safe')
	);
	$snapshot=$manager->snapshot('effect_validation_probe');
	$request=static fn()=>ReactorRequest::fromArray([
		'component'=>'effect_validation_probe',
		'action'=>'invalid_effect',
		'snapshot'=>$snapshot->jsonSerialize(),
	], $scope);
	$first=$manager->dispatch($request());
	$second=$manager->dispatch($request());
	$t->same(500, $first->status());
	$t->same(500, $second->status());
	$t->same('reactor_request_failed', $first->effects()['error']['code']);
	$t->same(2, $calls);
	$t->same(0, $session->getPath(['dataphyre_reactor','effect-validation-count']));
})->tag('reactor','security','reservation','serialization')->maxMillis(1500);

test('reactor defers child snapshot and session commits until the parent CAS succeeds', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('c', 32)];
	if(session_status()!==PHP_SESSION_ACTIVE){ @session_start(); }
	$t->same(PHP_SESSION_ACTIVE, session_status());
	$session=$t->globalMap('_SESSION')->putPath(['dataphyre_reactor','parent-count'], 0);
	$scope=dp_reactor_secure_scope();
	$store=new DpReactorTrackingFinalizeStore();
	$manager=(new ReactorManager($store))->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$child=ReactorComponent::make('dispatch_commit_child')->render('child');
	$parent=ReactorComponent::make('dispatch_commit_parent')
		->state(['count'=>0])
		->session('count', 'parent-count')
		->child('body', $child)
		->action('increment', static fn(array $state): array=>['count'=>(int)($state['count'] ?? 0)+1])
		->render(ReactorComponent::childSlot('body'));
	$manager->register($parent);
	$snapshot=$manager->snapshot('dispatch_commit_parent');
	$store->registered=[];
	$store->revoked=[];
	$store->failFinalize=true;
	$request=static fn()=>ReactorRequest::fromArray([
		'component'=>'dispatch_commit_parent',
		'action'=>'increment',
		'snapshot'=>$snapshot->jsonSerialize(),
	], $scope);
	$failed=$manager->dispatch($request());
	$t->same(503, $failed->status());
	$t->same('snapshot_finalize_failed', $failed->effects()['error']['code']);
	$t->same(0, $session->getPath(['dataphyre_reactor','parent-count']));
	$t->same(1, count($store->registered));
	$t->same($store->registered, $store->revoked);

	$store->failFinalize=false;
	$store->registered=[];
	$store->revoked=[];
	$succeeded=$manager->dispatch($request());
	$t->same(200, $succeeded->status());
	$t->same(1, $succeeded->state()['count']);
	$t->same(1, $session->getPath(['dataphyre_reactor','parent-count']));
	$t->same([], $store->revoked);
})->tag('reactor','security','cas','session','nested-components')->maxMillis(2500);

test('reactor reports non-atomic mount rollback honestly when a production store cannot revoke', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('z', 32),'production'=>true];
	$scope=dp_reactor_secure_scope();
	$store=new DpReactorNonAtomicProductionStore();
	$manager=(new ReactorManager($store))->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$parent=ReactorComponent::make('non_atomic_parent')
		->child('body', ReactorComponent::make('non_atomic_child')->render('child'))
		->render(ReactorComponent::childSlot('body'));
	$manager->register($parent);
	$t->isFalse($manager->securityManifest()['mount_snapshot_commit_atomic']);
	$t->throws(static fn()=>$manager->mount('non_atomic_parent'), RuntimeException::class);
	$t->same(2, $store->registerCalls);
	$t->isTrue($store->revokeCalls>=1);
	$t->same(1, $store->reachableEntries);
})->tag('reactor','security','snapshot-store','rollback','production')->maxMillis(2000);

test('reactor nested dispatch failure rolls back child issuance to its enclosing mount savepoint', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('w', 32)];
	$scope=dp_reactor_secure_scope();
	$directory=$t->workspace('reactor-nested-dispatch-savepoint')->root();
	$manager=(new ReactorManager(new ReactorFileSnapshotVersionStore($directory)))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$inner=ReactorComponent::make('nested_dispatch_inner')
		->child('body', ReactorComponent::make('nested_dispatch_child')->render('child'))
		->render("\xB1".ReactorComponent::childSlot('body'));
	$manager->register($inner);
	$innerSnapshot=$manager->snapshot('nested_dispatch_inner');
	$innerResponse=null;
	$outer=ReactorComponent::make('nested_dispatch_outer')->render(static function()use($manager,$innerSnapshot,$scope,&$innerResponse): string {
		$innerResponse=$manager->dispatch(ReactorRequest::fromArray([
			'component'=>'nested_dispatch_inner',
			'snapshot'=>$innerSnapshot->jsonSerialize(),
		], $scope));
		return 'outer';
	});
	$manager->register($outer);
	$t->contains('outer', $manager->mount('nested_dispatch_outer'));
	$t->same(500, $innerResponse?->status());
	$snapshotIds=[];
	foreach(glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $generation){
		$snapshotIds[substr(basename($generation), 0, 32)]=true;
	}
	$t->same(2, count($snapshotIds));
})->tag('reactor','security','mount','nested-dispatch','savepoint')->maxMillis(2500);

test('reactor initial mount and snapshot issuance authorize before resolution and leave no orphan ledger entries', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$scope=dp_reactor_secure_scope();
	$root=$t->workspace('reactor-initial-authorization')->root();
	$loaderCalls=0;
	\dataphyre\reactor::$runtimeConfig=[
		'secret'=>str_repeat('i', 32),
		'components'=>[
			'lazy_initial_probe'=>static function(ReactorComponent $component)use(&$loaderCalls): ReactorComponent {
				$loaderCalls++;
				return $component->render('must-not-render');
			},
		],
	];
	$unauthorized=(new ReactorManager(new ReactorFileSnapshotVersionStore($root.DIRECTORY_SEPARATOR.'missing-policy')))
		->withHostSecurityContext($scope);
	$t->throws(static fn()=>$unauthorized->snapshot('lazy_initial_probe'), RuntimeException::class);
	$t->throws(static fn()=>$unauthorized->mount('lazy_initial_probe'), RuntimeException::class);
	$t->same(0, $loaderCalls);

	$ledger=$root.DIRECTORY_SEPARATOR.'domain-ledger';
	$transportEnvelopes=[];
	$domainChecks=0;
	$renders=0;
	$manager=(new ReactorManager(new ReactorFileSnapshotVersionStore($ledger)))
		->withHostSecurityContext($scope)
		->authorizeTransport(static function(array $envelope)use(&$transportEnvelopes): bool { $transportEnvelopes[]=$envelope; return true; });
	$manager->register(
		ReactorComponent::make('domain_denied_initial')
			->state(['private'=>'INITIAL_SECRET_MARKER'])
			->authorize(static function()use(&$domainChecks): bool { $domainChecks++; return false; })
			->render(static function()use(&$renders): string { $renders++; return 'must-not-render'; })
	);
	$t->throws(static fn()=>$manager->snapshot('domain_denied_initial'), RuntimeException::class);
	$t->throws(static fn()=>$manager->mount('domain_denied_initial'), RuntimeException::class);
	$t->same(2, $domainChecks);
	$t->same(0, $renders);
	$t->same([], glob($ledger.DIRECTORY_SEPARATOR.'*.json') ?: []);
	$t->same(['snapshot_issue','mount'], array_column($transportEnvelopes, 'operation'));
	$t->notContains('INITIAL_SECRET_MARKER', json_encode($transportEnvelopes, JSON_THROW_ON_ERROR));

	$treeLedger=$root.DIRECTORY_SEPARATOR.'tree-ledger';
	$tree=(new ReactorManager(new ReactorFileSnapshotVersionStore($treeLedger)))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$child=ReactorComponent::make('initial_child')->render('child');
	$parent=ReactorComponent::make('initial_parent')
		->child('body', $child)
		->render(ReactorComponent::childSlot('body'))
		->rendered(static function(): never { throw new RuntimeException('parent render failed'); });
	$tree->register($parent);
	$t->throws(static fn()=>$tree->mount('initial_parent'), RuntimeException::class);
	$t->same([], glob($treeLedger.DIRECTORY_SEPARATOR.'*.json') ?: []);

	$partialLedger=$root.DIRECTORY_SEPARATOR.'partial-ledger';
	$partial=(new ReactorManager(new ReactorFileSnapshotVersionStore($partialLedger, 1)))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$partialParent=ReactorComponent::make('partial_parent')
		->child('body', ReactorComponent::make('partial_child')->render('child'))
		->render(ReactorComponent::childSlot('body'));
	$partial->register($partialParent);
	$t->throws(static fn()=>$partial->mount('partial_parent'), RuntimeException::class);
	$t->same([], glob($partialLedger.DIRECTORY_SEPARATOR.'*.json') ?: []);

	$successLedger=$root.DIRECTORY_SEPARATOR.'success-ledger';
	$success=(new ReactorManager(new ReactorFileSnapshotVersionStore($successLedger)))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$success->register(ReactorComponent::make('initial_success')->render('ready'));
	$t->contains('data-dp-reactor', $success->mount('initial_success'));
	$t->same(1, count(glob($successLedger.DIRECTORY_SEPARATOR.'*.json') ?: []));
})->tag('reactor','security','mount','snapshot','orphan')->maxMillis(3000);

test('reactor production file snapshot stores require explicit shared-filesystem attestation', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('p', 32),'production'=>true];
	$scope=dp_reactor_secure_scope();
	$root=$t->workspace('reactor-production-store-attestation')->root();
	$component=ReactorComponent::make('production_probe')->render('ready');
	$unattested=(new ReactorManager(new ReactorFileSnapshotVersionStore($root.DIRECTORY_SEPARATOR.'unattested')))
		->withHostSecurityContext($scope)
		->authorizeTransport(static fn(): bool=>true);
	$unattested->register($component);
	$t->throws(static fn()=>$unattested->snapshot('production_probe'), RuntimeException::class);
	$t->same([], glob($root.DIRECTORY_SEPARATOR.'unattested'.DIRECTORY_SEPARATOR.'*.json') ?: []);

	$attestedStore=new ReactorFileSnapshotVersionStore($root.DIRECTORY_SEPARATOR.'attested', sharedFilesystemAttested:true);
	$t->isTrue($attestedStore->manifest()['production_safe']);
	$attested=(new ReactorManager($attestedStore))->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$attested->register(ReactorComponent::make('production_probe')->render('ready'));
	$t->same('production_probe', $attested->snapshot('production_probe')->component());
})->tag('reactor','security','snapshot-store','production','attestation')->maxMillis(2000);

test('reactor scoped snapshot security residual branches remain fail closed', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$scope=dp_reactor_secure_scope()+['role'=>'admin'];
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('x', 32)];
	$context=ReactorSecurityContext::fromTrusted($scope);
	$t->same('admin', $context->get('role'));
	$t->same('fallback', $context->get('missing', 'fallback'));
	$t->same($scope, $context->attributes());
	$t->throws(static fn()=>ReactorSecurityContext::fromTrusted(['audience'=>"invalid\nvalue"]), InvalidArgumentException::class);

	$snapshot=ReactorSnapshot::make('residual_probe', ['safe'=>true], ['safe'], $context);
	$t->same(['safe'], $snapshot->locked());
	$t->same(2, $snapshot->schemaVersion());
	$t->isTrue($snapshot->createdAt()>0);
	$t->isFalse($snapshot->verify(['scope_id'=>[]]));
	$t->isTrue(ReactorSnapshot::freshExpiry()>time());
	$invalidState=$snapshot->jsonSerialize();
	$invalidState['state']=['not_json'=>INF];
	$t->isFalse(ReactorSnapshot::from($invalidState)?->verify($context) ?? true);
	$unknownSchema=ReactorSnapshot::from(['schema_version'=>99]);
	$t->same(99, $unknownSchema?->schemaVersion());

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('x', 32),'max_payload_bytes'=>'invalid'];
	$t->same(null, ReactorSnapshot::from('{}'));
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('x', 32),'snapshot_max_age_seconds'=>'invalid'];
	$t->throws(static fn()=>ReactorSnapshot::freshExpiry(), UnexpectedValueException::class);
	\dataphyre\reactor::$runtimeConfig=['signing_keys'=>[]];
	$t->isFalse(ReactorSigner::verifyScopeFingerprint($context->scopeClaims(), str_repeat('a', 64)));
})->tag('reactor','security','snapshot','exact-coverage')->maxMillis(1500);

test('reactor snapshots accept only bounded deterministic JSON value trees without invoking objects', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('j', 32)];
	$scope=dp_reactor_secure_scope();
	DpReactorJsonSerializableStateProbe::$calls=0;
	$probe=new DpReactorJsonSerializableStateProbe();
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['object'=>$probe], [], $scope), InvalidArgumentException::class);
	$t->same(0, DpReactorJsonSerializableStateProbe::$calls);
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['object'=>new stdClass()], [], $scope), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['float'=>INF], [], $scope), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['utf8'=>"\xB1"], [], $scope), InvalidArgumentException::class);
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', [str_repeat('k', 257)=>true], [], $scope), InvalidArgumentException::class);
	$resource=fopen('php://memory', 'rb');
	try{ $t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['resource'=>$resource], [], $scope), InvalidArgumentException::class); }
	finally{ if(is_resource($resource)){ fclose($resource); } }

	$deep='leaf';
	for($depth=0;$depth<34;$depth++){ $deep=['nested'=>$deep]; }
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['deep'=>$deep], [], $scope), LengthException::class);
	$t->throws(static fn()=>ReactorSnapshot::make('tree_probe', ['nodes'=>array_fill(0, 10001, 0)], [], $scope), LengthException::class);

	$valid=ReactorSnapshot::make('tree_probe', ['values'=>[null,true,1,1.25,'valid', ['map'=>'value']]], [], $scope);
	$t->isTrue($valid->verify($scope));
	$now=time();
	$expiredPayload=[
		'schema_version'=>2,
		'snapshot_id'=>str_repeat('e', 32),
		'component'=>'tree_probe',
		'state'=>['safe'=>true],
		'locked'=>[],
		'scope_hash'=>ReactorSecurityContext::fromTrusted($scope)->scopeHash(),
		'version'=>0,
		'created_at'=>$now-1,
		'expires_at'=>$now,
	];
	$boundarySnapshot=ReactorSnapshot::from($expiredPayload+['signature'=>ReactorSigner::sign($expiredPayload)]);
	$t->isFalse($boundarySnapshot?->verify($scope) ?? true);
	$untrusted=$valid->jsonSerialize();
	$untrusted['state']=['object'=>$probe];
	$t->isFalse(ReactorSnapshot::from($untrusted)?->verify($scope) ?? true);
	$t->same(0, DpReactorJsonSerializableStateProbe::$calls);
})->tag('reactor','security','snapshot','state-tree','adversarial')->maxMillis(2000);

test('reactor manager residual security branches fail closed with stable outcomes', static function(Context $t): void {
	$previous=\dataphyre\reactor::$runtimeConfig;
	$t->defer(static function()use($previous): void { \dataphyre\reactor::$runtimeConfig=$previous; });
	$scope=dp_reactor_secure_scope();
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32),'snapshot_version_store'=>'invalid'];
	$t->throws(static fn()=>new ReactorManager(), UnexpectedValueException::class);
	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32)];
	$replacement=new ReactorInMemorySnapshotVersionStore();
	$t->same($replacement, (new ReactorManager())->useSnapshotVersionStore($replacement)->securityManifest()['version_store']['adapter']==='memory' ? $replacement : null);

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32),'security_context_resolver'=>static fn(): string=>'invalid'];
	$invalidContext=(new ReactorManager())->authorizeTransport(static fn(): bool=>true)->dispatch(['component'=>'missing']);
	$t->same(403, $invalidContext->status());
	$t->same('security_context_invalid', $invalidContext->effects()['error']['code']);

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32),'security_context_resolver'=>static fn(): array=>$scope];
	$throwing=(new ReactorManager())->authorizeTransport(static function(): never { throw new RuntimeException('policy unavailable'); });
	$transportFailure=$throwing->dispatch(['component'=>'missing']);
	$t->same(503, $transportFailure->status());
	$t->same('transport_authorization_unavailable', $transportFailure->effects()['error']['code']);

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32),'production'=>true];
	$production=(new ReactorManager())->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$production->register(ReactorComponent::make('production_dispatch_probe')->render('safe'));
	$productionFailure=$production->dispatch(['component'=>'production_dispatch_probe']);
	$t->same(503, $productionFailure->status());
	$t->same('snapshot_version_store_required', $productionFailure->effects()['error']['code']);

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32)];
	$initialLoaderCalls=0;
	\dataphyre\reactor::$runtimeConfig['components']=[
		'initial_policy_probe'=>static function(ReactorComponent $component)use(&$initialLoaderCalls): ReactorComponent { $initialLoaderCalls++; return $component->render('safe'); },
	];
	$initial=(new ReactorManager())->withHostSecurityContext($scope)->authorizeTransport(static function(): never { throw new RuntimeException('initial policy unavailable'); });
	$t->throws(static fn()=>$initial->snapshot('initial_policy_probe'), RuntimeException::class);
	$initial->authorizeTransport(static fn(): bool=>false);
	$t->throws(static fn()=>$initial->mount('initial_policy_probe'), RuntimeException::class);
	$t->same(0, $initialLoaderCalls);

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32)];
	$params=(new ReactorManager())->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$params->register(
		ReactorComponent::make('signed_param_denial')
			->requireSignedParams()
			->action('save', static fn(array $state): array=>$state)
			->render('safe')
	);
	$signedSnapshot=$params->snapshot('signed_param_denial');
	$signedDenied=$params->dispatch(ReactorRequest::fromArray([
		'component'=>'signed_param_denial','action'=>'save','params'=>['plain'=>1],'snapshot'=>$signedSnapshot->jsonSerialize(),
	], $scope));
	$t->same(419, $signedDenied->status());
	$params->register(
		ReactorComponent::make('locked_param_denial')
			->state(['id'=>7])
			->lockedParams(['id'=>'state:id'])
			->action('save', static fn(array $state): array=>$state)
			->render('safe')
	);
	$lockedSnapshot=$params->snapshot('locked_param_denial');
	$lockedDenied=$params->dispatch(ReactorRequest::fromArray([
		'component'=>'locked_param_denial','action'=>'save','params'=>['id'=>8],'snapshot'=>$lockedSnapshot->jsonSerialize(),
	], $scope));
	$t->same(419, $lockedDenied->status());

	\dataphyre\reactor::$runtimeConfig=['secret'=>str_repeat('m', 32),'snapshot_reservation_ttl_seconds'=>'invalid'];
	$ttl=(new ReactorManager())->withHostSecurityContext($scope)->authorizeTransport(static fn(): bool=>true);
	$ttl->register(ReactorComponent::make('ttl_probe')->action('save', static fn(array $state): array=>$state)->render('safe'));
	$ttlSnapshot=$ttl->snapshot('ttl_probe');
	$ttlFailure=$ttl->dispatch(ReactorRequest::fromArray(['component'=>'ttl_probe','action'=>'save','snapshot'=>$ttlSnapshot->jsonSerialize()], $scope));
	$t->same(500, $ttlFailure->status());
	$t->same('reactor_request_failed', $ttlFailure->effects()['error']['code']);
})->tag('reactor','security','manager','exact-coverage')->maxMillis(3000);
