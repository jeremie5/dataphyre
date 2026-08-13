<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelInMemoryWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelWidgetInteractionContext;
use Dataphyre\Panel\PanelWidgetInteractionDefinition;
use Dataphyre\Panel\PanelWidgetInteractionException;
use Dataphyre\Panel\PanelWidgetInteractionRequest;
use Dataphyre\Panel\PanelWidgetInteractionResult;
use Dataphyre\Panel\PanelWidgetInteractionState;
use Dataphyre\Panel\PanelWidgetInteractionValue;
use Dataphyre\Panel\PanelWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelWidgetRuntimeHttpAdapter;
use Dataphyre\Panel\PanelWidgetRuntimeRegistry;
use Dataphyre\Panel\Widget;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel interactive widget lifecycle contracts')
	->framework(['panel'], ['functions'=>['tracelog']])
	->tag('panel','widget-runtime','security','deep-coverage')
	->group('framework-coverage');

/** @return array{panel:PanelInstance,adapter:PanelInMemoryWidgetRuntimeAdapter,definition:PanelWidgetInteractionDefinition,request:PanelRequest} */
function panel_widget_runtime_fixture(string $principal='operator-1', ?callable $clock=null, int $maxSessions=8, int $ttl=300): array {
	$key=str_repeat('k', 32);
	$adapter=new PanelInMemoryWidgetRuntimeAdapter('/panel/widgets/runtime', ['v1'=>str_repeat('s', 32)], 'v1', $clock, $maxSessions, $ttl);
	$adapter->register('counter', ['value'=>1], [
		'increment'=>static fn(array $state,array $payload): array=>['value'=>(int)$state['value']+(int)($payload['by'] ?? 1)],
	], static fn(PanelWidgetInteractionDefinition $definition,PanelWidgetInteractionContext $context,PanelWidgetInteractionRequest $request): bool=>$context->principal()!=='denied', static fn(array $state): array=>['value'=>(int)$state['value']+10]);
	$panel=PanelInstance::make('ops', [
		'widget_runtime_binding_keys'=>['v1'=>$key],
		'widget_runtime_current_key'=>'v1',
	]);
	$panel->registerWidgetRuntimeAdapter($adapter);
	$definition=PanelWidgetInteractionDefinition::make('memory','counter')->action('increment','Increment')->refresh('manual')->retryLimit(3);
	$request=PanelRequest::fromArray(['method'=>'GET','user'=>['id'=>$principal],'tenant'=>'tenant-a']);
	return compact('panel','adapter','definition','request');
}

/**
 * Small deterministic adapter double for registry boundary contracts.
 *
 * The double exposes only the five adapter protocol methods. Individual tests
 * provide a result factory when they need to prove exception containment or
 * result-identity validation without introducing executable application code.
 */
final class PanelWidgetRuntimeContractProbe implements PanelWidgetRuntimeAdapter {
	private ?Closure $handler;
	private int $resetCount=0;

	/** @param null|array<string,mixed> $manifestOverride */
	public function __construct(
		private readonly string $runtimeName='probe',
		private readonly ?array $manifestOverride=null,
		?callable $handler=null,
		private readonly int $version=1
	){ $this->handler=$handler===null ? null : Closure::fromCallable($handler); }

	public function name(): string { return $this->runtimeName; }
	public function contractVersion(): int { return $this->version; }
	public function handle(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		if($this->handler instanceof Closure){ return ($this->handler)($definition, $context, $request); }
		return PanelWidgetInteractionResult::success($this->runtimeName, $request->islandId(), PanelWidgetInteractionState::ready([]), '/probe', 'probe-snapshot', $context->bindingTag());
	}
	public function manifest(): array {
		return $this->manifestOverride ?? [
			'type'=>'panel_widget_runtime_adapter',
			'name'=>$this->runtimeName,
			'contract_version'=>$this->version,
			'capabilities'=>['probe'=>true],
		];
	}
	public function reset(): void { $this->resetCount++; }
	public function resetCount(): int { return $this->resetCount; }
}

/** Builds a mounted-session follow-up while keeping host-issued values visible. */
function panel_widget_runtime_followup(
	PanelWidgetInteractionResult $mounted,
	string $operation,
	string $idempotencyKey,
	?string $action=null,
	array $payload=[],
	?int $expectedVersion=null,
	?string $islandId=null,
	?string $snapshot=null,
	?string $bindingTag=null
): PanelWidgetInteractionRequest {
	$request=[
		'operation'=>$operation,
		'island_id'=>$islandId ?? $mounted->islandId(),
		'idempotency_key'=>$idempotencyKey,
		'snapshot'=>$snapshot ?? $mounted->snapshot(),
		'binding_tag'=>$bindingTag ?? $mounted->bindingTag(),
	];
	if($operation==='action'){
		$request['action']=$action;
		$request['payload']=$payload;
	}
	if(in_array($operation,['action','refresh','unmount'],true)){ $request['expected_version']=$expectedVersion; }
	return PanelWidgetInteractionRequest::fromArray($request);
}

/** @return array<string,mixed> */
function panel_widget_runtime_nested_value(int $depth): array {
	$value=['leaf'=>true];
	for($level=0;$level<$depth;$level++){ $value=['level_'.$level=>$value]; }
	return $value;
}

test('interactive definition and widget fluent APIs round trip without making static widgets interactive',static function(Context $t): void {
	$t->isTrue(interface_exists(PanelWidgetRuntimeHttpAdapter::class));
	$static=Widget::make('plain')->value(9)->toArray();
	$t->isFalse(array_key_exists('interaction',$static));

	$definition=PanelWidgetInteractionDefinition::make('memory','counter')
		->actions(['decrement'=>'Decrease','increment'=>'Increase'])
		->action('reset')
		->refresh('interval',5000)
		->retryLimit(5);
	$roundTrip=PanelWidgetInteractionDefinition::fromArray($definition->toArray());
	$t->same($definition->toArray(),$roundTrip->toArray());
	$t->same($definition->fingerprint(),$roundTrip->fingerprint());
	$t->isTrue($roundTrip->allows('increment'));
	$t->same('Reset',$roundTrip->namedActions()['reset']);

	$widget=Widget::make('live-total')->interactive($definition)->interactionAction('archive','Archive')->interactionRefresh('manual')->interactionRetryLimit(2);
	$t->isTrue($widget->isInteractive());
	$t->same('memory',$widget->interactionDefinition()?->adapter());
	$t->same($widget->toArray(),Widget::fromArray($widget->toArray())->toArray());
	$t->isFalse($widget->withoutInteraction()->isInteractive());
	$t->throws(static fn()=>Widget::make('bad')->interactionAction('go'),LogicException::class);
	$t->throws(static fn()=>Widget::make('bad')->interactionActions(['go'=>'Go']),LogicException::class);
	$t->throws(static fn()=>Widget::make('bad')->interactionRefresh(),LogicException::class);
	$t->throws(static fn()=>Widget::make('bad')->interactionRetryLimit(1),LogicException::class);
	$t->throws(static fn()=>Widget::fromArray(['name'=>'bad','interaction'=>'memory']),InvalidArgumentException::class);

	$manifest=$widget->meta(['api_token'=>'secret-value','dynamic'=>static fn(): int=>1])->manifest(null,['password'=>'another-secret']);
	$t->isTrue($manifest['interaction']['interactive']);
	$t->same('memory',$manifest['interaction']['runtime']['adapter']);
	$t->same('[redacted]',$manifest['meta']['api_token']);
	$t->same('[redacted]',$manifest['meta']['password']);
	$t->same(['dynamic'=>true],$manifest['meta']['dynamic']);
	$t->isFalse($manifest['capabilities']['interaction']['reactor_bridge']);
})->tag('contracts');

test('interaction definitions and public requests reject unknown executable sensitive and scope-selecting input',static function(Context $t): void {
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::fromArray(['adapter'=>'memory','component'=>'counter','class'=>'App\\Counter']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::fromArray(['adapter'=>'memory','component'=>'counter','actions'=>['go']]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::make('App\\Runtime','counter'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::make('memory','counter')->refresh('interval',1000),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::make('memory','counter')->retryLimit(6),InvalidArgumentException::class);

	$base=['operation'=>'action','island_id'=>'dpwi-one','action'=>'increment','payload'=>['by'=>2],'expected_version'=>1,'idempotency_key'=>'request-one','snapshot'=>'opaque','binding_tag'=>'v1.tag'];
	$request=PanelWidgetInteractionRequest::fromArray($base);
	$t->same($request->toArray(),PanelWidgetInteractionRequest::fromArray($request->toArray())->toArray());
	$t->same(64,strlen($request->fingerprint()));
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray($base+['scope_id'=>'tenant-a']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(array_replace($base,['payload'=>['password'=>'secret']])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(array_replace($base,['payload'=>['callback'=>static fn()=>true]])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(array_replace($base,['expected_version'=>null])),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-one','idempotency_key'=>'h']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'mount','island_id'=>'dpwi-one','action'=>'go','idempotency_key'=>'m']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'mount','island_id'=>'dpwi-one','payload'=>[],'idempotency_key'=>'m']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'mount','island_id'=>'dpwi-one','expected_version'=>1,'idempotency_key'=>'m']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-one','payload'=>[],'idempotency_key'=>'h','snapshot'=>'opaque','binding_tag'=>'v1.tag']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-one','expected_version'=>null,'idempotency_key'=>'h','snapshot'=>'opaque','binding_tag'=>'v1.tag']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionRequest::fromArray(['operation'=>'refresh','island_id'=>'dpwi-one','payload'=>[],'expected_version'=>1,'idempotency_key'=>'r','snapshot'=>'opaque','binding_tag'=>'v1.tag']),InvalidArgumentException::class);
	$t->same(['schema_version','operation','island_id','idempotency_key'],array_keys(PanelWidgetInteractionRequest::mount('dpwi-one','m')->toArray()));
})->tag('adversarial');

test('instance registries isolate adapters apply deterministic conflict policy and restore checkpoints',static function(Context $t): void {
	$left=panel_widget_runtime_fixture();
	$right=PanelInstance::make('other',['widget_runtime_binding_key'=>str_repeat('r',32)]);
	$t->isTrue($left['panel']->widgetRuntime()->has('memory'));
	$t->isFalse($right->widgetRuntime()->has('memory'));
	$t->isFalse($left['panel']->widgetRuntime()->fingerprint()===$right->widgetRuntime()->fingerprint());
	$t->isTrue($left['panel']->widgetRuntimeManifest()['capabilities']['instance_scoped']);
	$t->isTrue($left['panel']->widgetRuntimeManifest()['capabilities']['persistent_binding_keys']);
	$t->isFalse($left['panel']->widgetRuntimeManifest()['capabilities']['reactor_bridge']);
	$t->same('panel_widget_runtime_registry',$left['panel']->describe()['widget_runtime']['type']);

	$checkpoint=$left['panel']->widgetRuntime()->checkpoint();
	$left['panel']->widgetRuntime()->unregisterContributor('application');
	$t->isFalse($left['panel']->widgetRuntime()->has('memory'));
	$left['panel']->widgetRuntime()->restore($checkpoint);
	$t->isTrue($left['panel']->widgetRuntime()->has('memory'));
	$t->same('application',$left['panel']->widgetRuntime()->provenance()[0]['owner']);

	$replacement=new PanelInMemoryWidgetRuntimeAdapter('/replacement',str_repeat('z',32));
	$t->throws(static fn()=>$left['panel']->widgetRuntime()->register($replacement,'memory','plugin'),LogicException::class);
	$keep=new PanelWidgetRuntimeRegistry('keep_first',null,str_repeat('b',32));
	$t->isTrue($keep->register($left['adapter'],'runtime','one'));
	$t->isFalse($keep->register($replacement,'runtime','two'));
	$t->same('memory',$keep->adapter('runtime')?->name());
	$replace=new PanelWidgetRuntimeRegistry('replace',null,str_repeat('c',32));
	$replace->register($left['adapter'],'runtime','one');
	$replace->register($replacement,'runtime','two');
	$t->same($replacement,$replace->adapter('runtime'));
	$replace->unregisterContributor('two');
	$t->same($left['adapter'],$replace->adapter('runtime'));
	$replace->reset();
	$t->same([], $replace->manifest()['adapters']);
	$t->isTrue($left['panel']->widgetRuntime()->has('memory'));

	$unstable=new class implements PanelWidgetRuntimeAdapter {
		public int $manifestCalls=0;
		public int $resets=0;
		public bool $throwManifest=false;
		public function name(): string { return 'unstable'; }
		public function contractVersion(): int { return 1; }
		public function handle(PanelWidgetInteractionDefinition $definition,PanelWidgetInteractionContext $context,PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult { return PanelWidgetInteractionResult::failure($this->name(),$request->islandId(),new PanelWidgetInteractionException('unavailable','Unavailable.',503)); }
		public function manifest(): array { $this->manifestCalls++; if($this->throwManifest){ throw new RuntimeException('manifest changed'); } return ['type'=>'panel_widget_runtime_adapter','name'=>$this->name(),'contract_version'=>1]; }
		public function reset(): void { $this->resets++; }
	};
	$cached=new PanelWidgetRuntimeRegistry('reject',null,str_repeat('d',32));
	$cached->register($unstable,'first','one');
	$cached->register($unstable,'second','two');
	$t->same(2,$unstable->manifestCalls);
	$unstable->throwManifest=true;
	$t->same(64,strlen($cached->fingerprint()));
	$t->same('unstable',$cached->manifest()['adapters']['first']['manifest']['name']);
	$t->same(2,$unstable->manifestCalls);
	$cached->unregisterContributor('one');
	$t->same(0,$unstable->resets);
	$cached->unregisterContributor('two');
	$t->same(1,$unstable->resets);

	$hostileReset=new class implements PanelWidgetRuntimeAdapter {
		public function name(): string { return 'hostile-reset'; }
		public function contractVersion(): int { return 1; }
		public function handle(PanelWidgetInteractionDefinition $definition,PanelWidgetInteractionContext $context,PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult { return PanelWidgetInteractionResult::failure($this->name(),$request->islandId(),new PanelWidgetInteractionException('unavailable','Unavailable.',503)); }
		public function manifest(): array { return ['type'=>'panel_widget_runtime_adapter','name'=>$this->name(),'contract_version'=>1]; }
		public function reset(): void { throw new RuntimeException('hostile cleanup'); }
	};
	$cleanupSafe=new PanelWidgetRuntimeRegistry('reject',null,str_repeat('e',32));
	$cleanupSafe->register($hostileReset,owner:'hostile');
	$cleanupSafe->unregisterContributor('hostile');
	$t->isFalse($cleanupSafe->has('hostile-reset'));
	$t->same(1,$cleanupSafe->manifest()['cleanup_failures']);
})->tag('instance-isolation');

test('in-memory adapter mounts idempotently authorizes before state and enforces scope CAS idempotency snapshots and unmount',static function(Context $t): void {
	$fixture=panel_widget_runtime_fixture();
	$registry=$fixture['panel']->widgetRuntime();
	$context=$registry->context($fixture['panel'],$fixture['request'],'dashboard');
	$mount=PanelWidgetInteractionRequest::mount('dpwi-counter','mount-counter');
	$first=$registry->dispatch($fixture['definition'],$context,$mount);
	$replay=$registry->dispatch($fixture['definition'],$context,$mount);
	$t->isTrue($first->successful());
	$t->isTrue($replay->replayed());
	$alternateMount=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-counter','mount-counter-alternate'));
	$t->isTrue($alternateMount->replayed());
	$t->same($first->state()->data(),$alternateMount->state()->data());
	$t->same($first->state()->version(),$alternateMount->state()->version());
	$t->same('Widget state restored.',$alternateMount->state()->message());
	$t->same(1,$fixture['adapter']->sessionCount());
	$t->same(1,$first->state()->data()['value']);
	$t->same('/panel/widgets/runtime',$first->endpoint());

	$actionPayload=['operation'=>'action','island_id'=>'dpwi-counter','action'=>'increment','payload'=>['by'=>4],'expected_version'=>1,'idempotency_key'=>'increment-one','snapshot'=>$first->snapshot(),'binding_tag'=>$first->bindingTag()];
	$action=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray($actionPayload));
	$t->same(5,$action->state()->data()['value']);
	$t->same(2,$action->state()->version());
	$t->isTrue($registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray($actionPayload))->replayed());
	$idempotencyConflict=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(array_replace($actionPayload,['idempotency_key'=>'increment-one','payload'=>['by'=>8]])));
	$t->same('widget_idempotency_conflict',$idempotencyConflict->state()->errorCode());
	$conflict=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(array_replace($actionPayload,['idempotency_key'=>'stale','expected_version'=>1])));
	$t->same('widget_version_conflict',$conflict->state()->errorCode());
	$t->isTrue($conflict->retryable());

	$refresh=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'refresh','island_id'=>'dpwi-counter','expected_version'=>2,'idempotency_key'=>'refresh-one','snapshot'=>$action->snapshot(),'binding_tag'=>$action->bindingTag()]));
	$t->same(15,$refresh->state()->data()['value']);
	$t->same(3,$refresh->state()->version());
	$hydrate=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-counter','idempotency_key'=>'hydrate-one','snapshot'=>$refresh->snapshot(),'binding_tag'=>$refresh->bindingTag()]));
	$t->same(3,$hydrate->state()->version());

	$denied=panel_widget_runtime_fixture('denied');
	$deniedContext=$denied['panel']->widgetRuntime()->context($denied['panel'],$denied['request'],'dashboard');
	$deniedResult=$denied['panel']->widgetRuntime()->dispatch($denied['definition'],$deniedContext,PanelWidgetInteractionRequest::mount('dpwi-denied','mount-denied'));
	$t->same('widget_forbidden',$deniedResult->state()->errorCode());
	$t->same(0,$denied['adapter']->sessionCount());

	$otherContext=$registry->context($fixture['panel'],PanelRequest::fromArray(['user'=>['id'=>'operator-2'],'tenant'=>'tenant-a']),'dashboard');
	$scopeFailure=$registry->dispatch($fixture['definition'],$otherContext,PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-counter','idempotency_key'=>'scope','snapshot'=>$refresh->snapshot(),'binding_tag'=>$refresh->bindingTag()]));
	$t->same('widget_scope_mismatch',$scopeFailure->state()->errorCode());
	$tampered=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-counter','idempotency_key'=>'tampered','snapshot'=>$refresh->snapshot().'x','binding_tag'=>$refresh->bindingTag()]));
	$t->same('widget_snapshot_invalid',$tampered->state()->errorCode());

	$unmount=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'unmount','island_id'=>'dpwi-counter','expected_version'=>3,'idempotency_key'=>'unmount-one','snapshot'=>$refresh->snapshot(),'binding_tag'=>$refresh->bindingTag()]));
	$t->same('unmounted',$unmount->state()->status());
	$t->isTrue($unmount->successful());
	$remount=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-counter','mount-counter'));
	$t->isTrue($remount->successful());
	$t->isFalse($remount->snapshot()===$first->snapshot());

	$fixture['adapter']->unregister('counter')->register('counter',['value'=>1],[
		'increment'=>static fn(array $state,array $payload): array=>['value'=>(int)$state['value']+(int)($payload['by'] ?? 1)],
	],static fn(): bool=>true);
	$t->same(0,$fixture['adapter']->sessionCount());
	$fresh=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-counter','mount-counter'));
	$t->isFalse($fresh->replayed());
	$t->isFalse($fresh->snapshot()===$remount->snapshot());

	$fixture['adapter']->register('invalid-state',['value'=>1],[
		'break'=>static fn(): PanelWidgetInteractionState=>PanelWidgetInteractionState::make('error',1,[],'bad_state','Bad state.'),
	],static fn(): bool=>true);
	$invalidDefinition=PanelWidgetInteractionDefinition::make('memory','invalid-state')->action('break');
	$invalidMount=$registry->dispatch($invalidDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-invalid','mount-invalid'));
	$invalidAction=$registry->dispatch($invalidDefinition,$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'action','island_id'=>'dpwi-invalid','action'=>'break','payload'=>[],'expected_version'=>1,'idempotency_key'=>'break-one','snapshot'=>$invalidMount->snapshot(),'binding_tag'=>$invalidMount->bindingTag()]));
	$t->same('widget_runtime_failure',$invalidAction->state()->errorCode());
	$invalidHydrate=$registry->dispatch($invalidDefinition,$context,PanelWidgetInteractionRequest::fromArray(['operation'=>'hydrate','island_id'=>'dpwi-invalid','idempotency_key'=>'hydrate-invalid','snapshot'=>$invalidMount->snapshot(),'binding_tag'=>$invalidMount->bindingTag()]));
	$t->same(1,$invalidHydrate->state()->version());
})->tag('lifecycle');

test('renderer mounts only during explicit island rendering and preserves static fallback on missing runtime',static function(Context $t): void {
	$fixture=panel_widget_runtime_fixture();
	$widget=Widget::make('counter')->label('Counter')->value(99)->interactive($fixture['definition']);
	$fixture['panel']->registerWidget($widget);
	$widget->toArray();
	$widget->state($fixture['request']);
	$widget->manifest($fixture['request'],[],true);
	$t->same(0,$fixture['adapter']->sessionCount());
	$result=$fixture['panel']->dispatch($fixture['request']);
	$html=$result->content();
	$t->contains('data-dp-widget-island="1"',$html);
	$t->contains('data-dp-widget-endpoint="/panel/widgets/runtime"',$html);
	$t->contains('data-dp-widget-snapshot=',$html);
	$t->contains('data-dp-widget-binding=',$html);
	$t->contains('data-dp-widget-bind="value">99</strong>',$html);
	$t->contains('data-dp-widget-action="increment" hidden disabled',$html);
	$t->same(1,$fixture['adapter']->sessionCount());
	$again=$fixture['panel']->dispatch($fixture['request']);
	$t->same(1,$fixture['adapter']->sessionCount());
	$t->contains('data-dp-widget-endpoint=',$again->content());

	$missing=PanelInstance::make('missing',['widget_runtime_binding_key'=>str_repeat('m',32)]);
	$missing->registerWidget(Widget::make('counter')->value(99)->interactive('memory','counter'));
	$missingHtml=$missing->dispatch(PanelRequest::fromArray(['user'=>['id'=>'operator']]))->content();
	$t->contains('data-dp-widget-island="1"',$missingHtml);
	$t->contains('Interactive updates are unavailable.',$missingHtml);
	$t->notContains('data-dp-widget-endpoint=',$missingHtml);

	$staticPanel=PanelInstance::make('static');
	$staticPanel->registerWidget(Widget::make('plain')->value(1));
	$t->notContains('data-dp-widget-island=', $staticPanel->dispatch(PanelRequest::fromArray([]))->content());
})->tag('renderer');

test('in-memory retention is bounded expires inactive sessions and reports honest capabilities',static function(Context $t): void {
	$now=1000;
	$fixture=panel_widget_runtime_fixture('operator',static function()use(&$now): int{return $now;},1,60);
	$registry=$fixture['panel']->widgetRuntime();
	$context=$registry->context($fixture['panel'],$fixture['request'],'dashboard');
	$one=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-one','mount-one'));
	$t->isTrue($one->successful());
	$two=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-two','mount-two'));
	$t->isTrue($two->successful());
	$t->same(1,$fixture['adapter']->sessionCount());
	$now=1061;
	$three=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-three','mount-three'));
	$t->isTrue($three->successful());
	$t->same(1,$fixture['adapter']->sessionCount());
	$manifest=$fixture['adapter']->manifest();
	$t->isFalse($manifest['capabilities']['durable']);
	$t->isFalse($manifest['capabilities']['multi_process']);
	$t->isFalse($manifest['capabilities']['production_reactor_bridge']);
	$t->isFalse($manifest['capabilities']['handler_side_effects_exactly_once']);
	$t->isTrue($manifest['capabilities']['host_idempotency_required']);
	$t->same('ttl_expiration',$manifest['lifecycle']['abrupt_disconnect_fallback']);
	$t->same(1,$manifest['retention']['max_sessions']);
	$t->notContains(str_repeat('s',32),json_encode($manifest,JSON_THROW_ON_ERROR));
})->tag('retention');

test('public value objects expose stable errors states and same-origin adapter output only',static function(Context $t): void {
	$ready=PanelWidgetInteractionState::ready(['value'=>3],2,'Ready.');
	$t->isTrue($ready->successful());
	$t->same($ready->toArray(),$ready->jsonSerialize());
	$unavailable=PanelWidgetInteractionState::unavailable();
	$t->same('widget_unavailable',$unavailable->errorCode());
	$failure=new PanelWidgetInteractionException('widget_failed','Widget failed safely.',503,true);
	$t->same('widget_failed',$failure->publicCode());
	$t->same(503,$failure->httpStatus());
	$t->isTrue($failure->retryable());
	$result=PanelWidgetInteractionResult::success('memory','dpwi-one',$ready,'/widgets','snapshot','v1.binding');
	$t->isTrue($result->successful());
	$t->same($result->toArray(),$result->jsonSerialize());
	$unmounted=PanelWidgetInteractionResult::success('memory','dpwi-one',PanelWidgetInteractionState::make('unmounted',2),'/must-not-leak','must-not-leak','must-not-leak');
	$t->isTrue($unmounted->successful());
	$t->same(null,$unmounted->endpoint());
	$t->same(null,$unmounted->snapshot());
	$t->same(null,$unmounted->bindingTag());
	$t->throws(static fn()=>PanelWidgetInteractionResult::success('memory','dpwi-one',$ready,null,null,null),InvalidArgumentException::class);
	$failed=PanelWidgetInteractionResult::failure('memory','dpwi-one',$failure);
	$t->isFalse($failed->successful());
	$t->same('widget_failed',$failed->state()->errorCode());
	$t->throws(static fn()=>PanelWidgetInteractionResult::success('memory','dpwi-one',$ready,'https://evil.test/widgets','snapshot','v1.binding'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionState::make('error'),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelWidgetInteractionException('bad code','message'),InvalidArgumentException::class);
})->tag('value-objects');

test('serialized interaction contracts reject version type and public error boundary drift',static function(Context $t): void {
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::fromArray(['schema_version'=>2,'adapter'=>'memory','component'=>'counter']),UnexpectedValueException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::fromArray(['adapter'=>'memory','component'=>'counter','refresh'=>true]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::make('memory','counter')->actions(['go'=>7]),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionDefinition::make('memory','counter')->refresh('sometimes'),InvalidArgumentException::class);
	$definition=PanelWidgetInteractionDefinition::make('memory','counter')->action('retry');
	$t->same($definition->toArray(),$definition->jsonSerialize());

	$request=PanelWidgetInteractionRequest::mount('dpwi-contract','contract-mount');
	$t->same($request->toArray(),$request->jsonSerialize());
	$t->throws(static fn()=>new PanelWidgetInteractionException('widget_invalid',''),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelWidgetInteractionException('widget_invalid','Invalid status.',200),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionResult::success('memory','dpwi-contract',PanelWidgetInteractionState::unavailable(),'/widgets','snapshot','binding'),InvalidArgumentException::class);
	$failure=PanelWidgetInteractionResult::failure('memory','dpwi-contract',new PanelWidgetInteractionException('widget_failed','Failed safely.',503,true));
	$t->same(503,$failure->httpStatus());
})->tag('contracts','adversarial');

test('interaction JSON values enforce map byte depth collection and numeric safety limits',static function(Context $t): void {
	$t->throws(static fn()=>PanelWidgetInteractionValue::assertMap(['first','second']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionValue::assertMap(['message'=>str_repeat('x',32)],'small payload',16),LengthException::class);
	$t->throws(static fn()=>PanelWidgetInteractionValue::boundedString('','Widget label',20),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelWidgetInteractionValue::assertMap(panel_widget_runtime_nested_value(10)),LengthException::class);
	$t->throws(static fn()=>PanelWidgetInteractionValue::assertMap(['ratio'=>INF]),InvalidArgumentException::class);
	$t->same(['ratio'=>1.5],PanelWidgetInteractionValue::assertMap(['ratio'=>1.5]));
	$t->throws(static fn()=>PanelWidgetInteractionValue::assertMap(['items'=>array_fill(0,129,null)]),LengthException::class);
})->tag('value-objects','adversarial');

test('runtime registry contains adapter failures validates identities and derives trusted object principals',static function(Context $t): void {
	$key=str_repeat('r',32);
	$scope=['principal'=>'operator-7','tenant'=>'tenant-r','session'=>'session-r','attributes'=>['role'=>'operator']];
	$registry=new PanelWidgetRuntimeRegistry('reject',static fn(): array=>$scope,['v1'=>$key],'v1');
	$t->same('reject',$registry->conflictPolicy());
	$t->same(0,$registry->revision());
	$t->isTrue($registry->persistentBindingKeys());
	$registry->conflictPolicyUsing('keep_first')->scopeUsing(static fn(): array=>$scope);
	$t->same('keep_first',$registry->conflictPolicy());

	$panel=PanelInstance::make('ops');
	$request=PanelRequest::fromArray(['user'=>['id'=>'ignored'],'tenant'=>'ignored']);
	$context=$registry->context($panel,$request,'dashboard');
	$t->same('ops',$context->panel());
	$t->same('dashboard',$context->surface());
	$t->same('operator-7',$context->principal());
	$t->same('tenant-r',$context->tenant());
	$t->same('session-r',$context->session());
	$t->same($request,$context->request());
	$t->same(['role'=>'operator'],$context->attributes());
	$t->isFalse($context->acceptsBindingTag(null));
	$t->throws(static fn()=>$context->scopeKey('short'),InvalidArgumentException::class);

	$badManifest=new PanelWidgetRuntimeContractProbe('bad-manifest',['name'=>'different','contract_version'=>1]);
	$t->throws(static fn()=>$registry->register($badManifest),UnexpectedValueException::class);
	$throwing=new PanelWidgetRuntimeContractProbe('throwing',null,static function(): PanelWidgetInteractionResult { throw new RuntimeException('private adapter failure'); });
	$t->isTrue($registry->register($throwing));
	$thrown=$registry->dispatch(PanelWidgetInteractionDefinition::make('throwing','counter'),$context,PanelWidgetInteractionRequest::mount('dpwi-throwing','throwing-mount'));
	$t->same('widget_adapter_failure',$thrown->state()->errorCode());

	$violating=new PanelWidgetRuntimeContractProbe('violating',null,static fn(PanelWidgetInteractionDefinition $definition,PanelWidgetInteractionContext $host,PanelWidgetInteractionRequest $interaction): PanelWidgetInteractionResult=>PanelWidgetInteractionResult::success('other',$interaction->islandId(),PanelWidgetInteractionState::ready([]),'/probe','snapshot',$host->bindingTag()));
	$t->isTrue($registry->register($violating));
	$violation=$registry->dispatch(PanelWidgetInteractionDefinition::make('violating','counter'),$context,PanelWidgetInteractionRequest::mount('dpwi-violating','violating-mount'));
	$t->same('widget_adapter_contract_violation',$violation->state()->errorCode());
	$t->same($registry->manifest(),$registry->jsonSerialize());
	$t->throws(static fn()=>$registry->restore([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$registry->restore(['layers'=>['invalid'=>[['adapter'=>$throwing]]],'revision'=>0,'conflict_policy'=>'reject']),InvalidArgumentException::class);
	$registry->reset();
	$t->same(1,$throwing->resetCount());
	$t->same(1,$violating->resetCount());

	$scopeFailure=new PanelWidgetRuntimeRegistry('reject',static fn(): array=>[],['v1'=>$key],'v1');
	$scopeFailureResult=$scopeFailure->mount(PanelWidgetInteractionDefinition::make('missing','counter'),$panel,$request,'dashboard','dpwi-scope');
	$t->same('widget_scope_unavailable',$scopeFailureResult->state()->errorCode());
	$privateFailure=new PanelWidgetRuntimeRegistry('reject',static function(): array { throw new RuntimeException('private resolver failure'); },['v1'=>$key],'v1');
	$privateFailureResult=$privateFailure->mount(PanelWidgetInteractionDefinition::make('missing','counter'),$panel,$request,'dashboard','dpwi-private');
	$t->same('widget_context_unavailable',$privateFailureResult->state()->errorCode());

	$defaultScope=new PanelWidgetRuntimeRegistry('reject',null,['v1'=>$key],'v1');
	$methodUser=new class { public function getAuthIdentifier(): int { return 17; } };
	$t->same('17',$defaultScope->context($panel,PanelRequest::fromArray(['user'=>$methodUser]),'dashboard')->principal());
	$fallbackMethodUser=new class { public function getAuthIdentifier(): never { throw new RuntimeException('not available'); } public function getId(): int { return 22; } };
	$t->same('22',$defaultScope->context($panel,PanelRequest::fromArray(['user'=>$fallbackMethodUser]),'dashboard')->principal());
	$t->same('property-user',$defaultScope->context($panel,PanelRequest::fromArray(['user'=>(object)['id'=>'property-user']]),'dashboard')->principal());
	$t->throws(static fn()=>$defaultScope->context($panel,PanelRequest::fromArray(['user'=>new stdClass()]),'dashboard'),PanelWidgetInteractionException::class);
})->tag('instance-isolation','adversarial');

test('in-memory runtime rejects stale definitions unavailable actions malformed snapshots and unsafe handlers',static function(Context $t): void {
	$missing=panel_widget_runtime_fixture();
	$missing['adapter']->unregister('counter');
	$missingContext=$missing['panel']->widgetRuntime()->context($missing['panel'],$missing['request'],'dashboard');
	$missingResult=$missing['panel']->widgetRuntime()->dispatch($missing['definition'],$missingContext,PanelWidgetInteractionRequest::mount('dpwi-missing-component','missing-component'));
	$t->same('widget_component_unavailable',$missingResult->state()->errorCode());

	$fixture=panel_widget_runtime_fixture();
	$registry=$fixture['panel']->widgetRuntime();
	$context=$registry->context($fixture['panel'],$fixture['request'],'dashboard');
	$fixture['adapter']->register('authorization-error',[],[],static function(): bool { throw new RuntimeException('private authorization failure'); });
	$authorization=$registry->dispatch(PanelWidgetInteractionDefinition::make('memory','authorization-error'),$context,PanelWidgetInteractionRequest::mount('dpwi-authorization','authorization-mount'));
	$t->same('widget_forbidden',$authorization->state()->errorCode());

	$fixture['adapter']->register('invalid-handler',['value'=>1],['break'=>static fn(): string=>'not a state map'],static fn(): bool=>true);
	$invalidDefinition=PanelWidgetInteractionDefinition::make('memory','invalid-handler')->action('break');
	$invalidMount=$registry->dispatch($invalidDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-invalid-handler','invalid-handler-mount'));
	$invalidHandler=$registry->dispatch($invalidDefinition,$context,panel_widget_runtime_followup($invalidMount,'action','invalid-handler-action','break',[],1));
	$t->same('widget_runtime_failure',$invalidHandler->state()->errorCode());

	$mounted=$registry->dispatch($fixture['definition'],$context,PanelWidgetInteractionRequest::mount('dpwi-branch-contract','branch-contract-mount'));
	$changedDefinition=PanelWidgetInteractionDefinition::make('memory','counter')->action('increment','Changed label')->refresh('manual')->retryLimit(3);
	$definitionConflict=$registry->dispatch($changedDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-branch-contract','branch-contract-remount'));
	$t->same('widget_definition_conflict',$definitionConflict->state()->errorCode());
	$invalidSession=$registry->dispatch($fixture['definition'],$context,panel_widget_runtime_followup($mounted,'hydrate','other-island-hydrate',islandId:'dpwi-other-island'));
	$t->same('widget_session_invalid',$invalidSession->state()->errorCode());

	$unavailableDefinition=PanelWidgetInteractionDefinition::make('memory','counter')->action('missing');
	$unavailableMount=$registry->dispatch($unavailableDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-unavailable-action','unavailable-action-mount'));
	$unavailableAction=$registry->dispatch($unavailableDefinition,$context,panel_widget_runtime_followup($unavailableMount,'action','unavailable-action','missing',[],1));
	$t->same('widget_action_unavailable',$unavailableAction->state()->errorCode());

	$wrongBinding=$fixture['adapter']->handle($fixture['definition'],$context,panel_widget_runtime_followup($mounted,'hydrate','wrong-binding',bindingTag:'v1.wrong-binding'));
	$t->same('widget_scope_mismatch',$wrongBinding->state()->errorCode());
	$invalidClaims=rtrim(strtr(base64_encode(json_encode(['v'=>2,'kid'=>'v1','id'=>'invalid'],JSON_THROW_ON_ERROR)),'+/','-_'),'=').'.signature';
	$malformedSnapshot=$registry->dispatch($fixture['definition'],$context,panel_widget_runtime_followup($mounted,'hydrate','invalid-claims',snapshot:$invalidClaims));
	$t->same('widget_snapshot_invalid',$malformedSnapshot->state()->errorCode());
})->tag('lifecycle','adversarial');
