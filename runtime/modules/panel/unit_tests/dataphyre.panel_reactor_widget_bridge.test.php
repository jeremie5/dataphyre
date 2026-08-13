<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetBinding;
use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetController;
use Dataphyre\Panel\Bridges\Reactor\PanelReactorWidgetRuntimeAdapter;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelWidgetInteractionContext;
use Dataphyre\Panel\PanelWidgetInteractionDefinition;
use Dataphyre\Panel\PanelWidgetInteractionException;
use Dataphyre\Panel\PanelWidgetInteractionRequest;
use Dataphyre\Panel\PanelWidgetInteractionResult;
use Dataphyre\Panel\PanelWidgetInteractionState;
use Dataphyre\Panel\PanelWidgetRuntimeHttpAdapter;
use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorEffects;
use Dataphyre\Reactor\ReactorInMemorySnapshotVersionStore;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorResponse;
use Dataphyre\Reactor\ReactorSecurityContext;
use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorSnapshotVersionStore;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Panel Reactor widget production bridge')
	->framework(['panel','reactor'], ['functions'=>['tracelog']])
	->tag('panel','reactor','widget-runtime','security','deep-coverage')
	->group('framework-coverage');

final class DpPanelReactorWidgetStoreProbe implements ReactorSnapshotVersionStore {
	private ReactorInMemorySnapshotVersionStore $inner;
	public bool $throwRevoke=false;
	public bool $rotateSignerOnRegister=false;
	public ?Closure $afterRegister=null;
	public function __construct(){ $this->inner=new ReactorInMemorySnapshotVersionStore(); }
	public function register(string $snapshotId,string $scopeHash,string $component,int $version,int $expiresAt): bool {
		$result=$this->inner->register($snapshotId,$scopeHash,$component,$version,$expiresAt);
		if($result && $this->rotateSignerOnRegister && $this->afterRegister instanceof Closure){
			($this->afterRegister)();
		}
		return $result;
	}
	public function reserve(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,string $reservationId,int $reservationExpiresAt): string { return $this->inner->reserve($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId,$reservationExpiresAt); }
	public function finalize(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,int $nextVersion,int $nextExpiresAt,string $reservationId): string { return $this->inner->finalize($snapshotId,$scopeHash,$component,$expectedVersion,$nextVersion,$nextExpiresAt,$reservationId); }
	public function abort(string $snapshotId,string $scopeHash,string $component,int $expectedVersion,string $reservationId): bool { return $this->inner->abort($snapshotId,$scopeHash,$component,$expectedVersion,$reservationId); }
	public function revoke(string $snapshotId,string $scopeHash,string $component,int $version): bool {
		if($this->throwRevoke){ throw new RuntimeException('private store failure'); }
		return $this->inner->revoke($snapshotId,$scopeHash,$component,$version);
	}
	public function manifest(): array { return array_replace($this->inner->manifest(),['adapter'=>'panel_reactor_widget_probe']); }
}

final class DpPanelReactorHttpRouteProbe implements PanelWidgetRuntimeHttpAdapter {
	public bool $throwName=false;
	public bool $throwResolution=false;
	public function __construct(private readonly string $runtimeName,private readonly ?PanelWidgetInteractionDefinition $definition=null){}
	public function name(): string { if($this->throwName){ throw new RuntimeException('private identity failure'); } return $this->runtimeName; }
	public function contractVersion(): int { return 1; }
	public function definitionForHttpRoute(string $bindingKey,string $surface): ?PanelWidgetInteractionDefinition { if($this->throwResolution){ throw new RuntimeException('private resolver failure'); } return $this->definition; }
	public function handle(PanelWidgetInteractionDefinition $definition,PanelWidgetInteractionContext $context,PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult { return PanelWidgetInteractionResult::failure($this->runtimeName,$request->islandId(),new PanelWidgetInteractionException('unavailable','Unavailable.',503)); }
	public function manifest(): array { return ['type'=>'panel_widget_runtime_adapter','name'=>$this->runtimeName,'contract_version'=>1,'capabilities'=>['http_route_probe'=>true]]; }
	public function reset(): void {}
}

/** @return array<string,mixed> */
function dp_panel_reactor_widget_fixture(): array {
	$capture=(object)['transport'=>[],'actionParams'=>[]];
	$store=new ReactorInMemorySnapshotVersionStore();
	$manager=(new ReactorManager($store))->authorizeTransport(static function(array $envelope, ReactorSecurityContext $context)use($capture): bool {
		$capture->transport[]=['envelope'=>$envelope,'context'=>$context];
		return $context->get('panel_request') instanceof PanelRequest
			&& $context->get('panel_widget_context') instanceof PanelWidgetInteractionContext
			&& $context->get('panel_widget_request') instanceof PanelWidgetInteractionRequest;
	});
	$manager->register(
		ReactorComponent::make('orders.counter')
			->state(['count'=>1])
			->action('increase', static function(array $state,array $params,ReactorComponent $component,ReactorEffects $effects)use($capture): array {
				$capture->actionParams[]=$params;
				return ['count'=>(int)($state['count'] ?? 0)+(int)($params['by'] ?? 1)];
			})
			->render(static fn(array $state): string=>'<output>'.(int)($state['count'] ?? 0).'</output>')
	);
	$definition=PanelWidgetInteractionDefinition::make('reactor','order-counter')->action('increment','Increment')->refresh('manual');
	$binding=PanelReactorWidgetBinding::make('orders', $definition, 'orders.counter')->actions(['increment'=>'increase'])->surfaces(['dashboard']);
	$adapter=(new PanelReactorWidgetRuntimeAdapter($manager,$store))->bind($binding);
	$panel=PanelInstance::make('ops', ['widget_runtime_binding_keys'=>['v1'=>str_repeat('p',32)],'widget_runtime_current_key'=>'v1']);
	$panel->registerWidgetRuntimeAdapter($adapter);
	$controller=new PanelReactorWidgetController(
		$panel,
		static fn(string $origin,PanelRequest $request): bool=>$origin==='https://panel.example.test',
		static fn(PanelRequest $request,string $token,string $ability): bool=>$token==='csrf-ok' && $ability==='panel.widget.interact'
	);
	$renderRequest=PanelRequest::fromArray(['method'=>'GET','user'=>['id'=>'operator-1'],'tenant'=>'tenant-a','headers'=>['X-Correlation-Id'=>'widget-test']]);
	return compact('capture','store','manager','definition','binding','adapter','panel','controller','renderRequest');
}

/** @param array<string,mixed> $body @param array<string,mixed> $options */
function dp_panel_reactor_widget_http(array $body,array $options=[]): array {
	$raw=json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	$headers=array_replace([
		'Accept'=>'application/json',
		'Content-Type'=>'application/json; charset=utf-8',
		'Content-Length'=>(string)strlen($raw),
		'Origin'=>'https://panel.example.test',
		'X-Requested-With'=>'DataphyrePanelWidget',
		'X-CSRF-Token'=>'csrf-ok',
		'X-Correlation-Id'=>'widget-test',
	], is_array($options['headers'] ?? null) ? $options['headers'] : []);
	$request=PanelRequest::fromArray([
		'method'=>$options['method'] ?? 'POST',
		'headers'=>$headers,
		'query'=>$options['query'] ?? [],
		'files'=>$options['files'] ?? [],
		'user'=>array_key_exists('user',$options) ? $options['user'] : ['id'=>'operator-1'],
		'tenant'=>$options['tenant'] ?? 'tenant-a',
	]);
	return [$request,$raw];
}

/** @return array<string,mixed> */
function dp_panel_reactor_widget_payload(PanelWidgetInteractionResult $result,string $operation,string $idempotency,?int $expected=null,?string $action=null,array $payload=[]): array {
	$body=[
		'schema_version'=>1,
		'operation'=>$operation,
		'island_id'=>$result->islandId(),
		'idempotency_key'=>$idempotency,
		'snapshot'=>$result->snapshot(),
		'binding_tag'=>$result->bindingTag(),
	];
	if($expected!==null){ $body['expected_version']=$expected; }
	if($action!==null){ $body['action']=$action; $body['payload']=$payload; }
	return $body;
}

/** @return array<string,mixed> */
function dp_panel_reactor_widget_decode(\Dataphyre\Panel\PanelPageResult $result): array {
	return json_decode($result->content(), true, 16, JSON_THROW_ON_ERROR);
}

test('trusted binding contract closes component action and surface selection',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$binding=$fixture['binding'];
	$t->same('orders',$binding->routeKey());
	$t->same('orders.counter',$binding->reactorComponent());
	$t->same('increase',$binding->reactorAction('INCREMENT'));
	$t->same(null,$binding->reactorAction('missing'));
	$t->same(['increment'=>'increase'],$binding->actionMap());
	$t->same(['dashboard'],$binding->allowedSurfaces());
	$t->isTrue($binding->allowsSurface('dashboard'));
	$t->isFalse($binding->allowsSurface('admin'));
	$binding->assertComplete();
	$t->same('panel_reactor_widget_binding',$binding->manifest()['type']);
	$t->same($binding->manifest(),$binding->jsonSerialize());

	$wild=PanelReactorWidgetBinding::make('wild',$fixture['definition'],'orders.counter')->actions(['increment'=>'increase'])->surfaces('*');
	$t->isTrue($wild->allowsSurface('anything'));
	$t->throws(static fn()=>PanelReactorWidgetBinding::make('', $fixture['definition'],'orders.counter'),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReactorWidgetBinding::make('route', $fixture['definition'],'!!!'),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->actions(['increment']),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->actions(['increment'=>1]),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->actions(['increment'=>'!!!']),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->surfaces([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->surfaces(['dashboard'=>'value']),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->surfaces([1]),InvalidArgumentException::class);
	$t->throws(static fn()=>$binding->surfaces(['bad surface']),InvalidArgumentException::class);
	$t->throws(static fn()=>PanelReactorWidgetBinding::make('incomplete',$fixture['definition'],'orders.counter')->assertComplete(),LogicException::class);

	$missingDefinition=PanelWidgetInteractionDefinition::make('reactor','missing');
	$t->throws(static fn()=>(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],$fixture['store']))->bind(PanelReactorWidgetBinding::make('missing',$missingDefinition,'missing.component')),InvalidArgumentException::class);
	$wrongAdapter=PanelWidgetInteractionDefinition::make('memory','wrong');
	$t->throws(static fn()=>(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],$fixture['store']))->bind(PanelReactorWidgetBinding::make('wrong',$wrongAdapter,'orders.counter')),LogicException::class);
	$t->throws(static fn()=>(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],$fixture['store'],'https://evil.test/runtime')),InvalidArgumentException::class);
	$t->throws(static fn()=>(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],$fixture['store'],'//evil.test/runtime')),InvalidArgumentException::class);
	$t->throws(static fn()=>$fixture['adapter']->bind($binding),LogicException::class);
	$t->same(null,$fixture['adapter']->definitionForHttpRoute('../orders','dashboard'));
	$t->same(null,$fixture['adapter']->definitionForHttpRoute('orders','bad surface'));
	$t->same(null,$fixture['adapter']->definitionForHttpRoute('orders','admin'));
	$t->same($fixture['definition']->fingerprint(),$fixture['adapter']->definitionForHttpRoute('orders','dashboard')?->fingerprint());
	$t->same('reactor',$fixture['adapter']->name());
	$t->same(1,$fixture['adapter']->contractVersion());
	$t->same('panel_widget_runtime_adapter',$fixture['adapter']->manifest()['type']);
	$t->isTrue($fixture['adapter']->manifest()['capabilities']['production_reactor_bridge']);
	$t->isTrue($fixture['panel']->widgetRuntimeManifest()['capabilities']['reactor_bridge']);
	$fixture['adapter']->reset();
	$fixture['adapter']->unbind('missing')->unbind('orders');
	$t->same(null,$fixture['adapter']->definitionForHttpRoute('orders','dashboard'));
})->tag('contracts','adversarial');

test('reactor adapter maps mount hydrate action refresh stale CAS and exact unmount semantics',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$mounted=$fixture['panel']->widgetRuntime()->mount($fixture['definition'],$fixture['panel'],$fixture['renderRequest'],'dashboard','dpwi-reactor');
	$t->isTrue($mounted->successful());
	$t->same(1,$mounted->state()->version());
	$t->same(['count'=>1],$mounted->state()->data());
	$t->same('/panel/widgets/runtime/reactor/orders/dashboard',$mounted->endpoint());
	$t->same(1,count($fixture['capture']->transport));
	$t->isTrue($fixture['capture']->transport[0]['context']->get('panel_request') instanceof PanelRequest);
	$t->same('operator-1',$fixture['capture']->transport[0]['context']->scopeClaims()['principal_id']);
	$t->same('widget-test',$fixture['capture']->transport[0]['context']->correlationId());

	$action=dp_panel_reactor_widget_payload($mounted,'action','action-one',1,'increment',['by'=>2,'_panel_widget'=>['forged'=>true],'_reactor_signed'=>['signature'=>'forged']]);
	[$request,$raw]=dp_panel_reactor_widget_http($action);
	$actionResponse=$fixture['controller']->dispatch($request,$raw,'reactor','orders','dashboard');
	$actionBody=dp_panel_reactor_widget_decode($actionResponse);
	$t->same(200,$actionResponse->status());
	$t->same(2,$actionBody['state']['version']);
	$t->same(3,$actionBody['state']['data']['count']);
	$t->same('action-one',$fixture['capture']->actionParams[0]['_panel_widget']['idempotency_key']);
	$t->same('dashboard',$fixture['capture']->actionParams[0]['_panel_widget']['surface']);
	$t->isFalse(isset($fixture['capture']->actionParams[0]['_panel_widget']['forged']));
	$t->isFalse(isset($fixture['capture']->actionParams[0]['_reactor_signed']));

	$actionResult=PanelWidgetInteractionResult::success('reactor','dpwi-reactor',PanelWidgetInteractionState::ready($actionBody['state']['data'],$actionBody['state']['version']),$actionBody['endpoint'],$actionBody['snapshot'],$actionBody['binding_tag']);
	$hydrate=dp_panel_reactor_widget_payload($actionResult,'hydrate','hydrate-one');
	[$request,$raw]=dp_panel_reactor_widget_http($hydrate);
	$hydrateResponse=$fixture['controller']->dispatch($request,$raw,'reactor','orders','dashboard');
	$hydrateBody=dp_panel_reactor_widget_decode($hydrateResponse);
	$t->same(200,$hydrateResponse->status());
	$t->same(3,$hydrateBody['state']['version']);

	$hydrateResult=PanelWidgetInteractionResult::success('reactor','dpwi-reactor',PanelWidgetInteractionState::ready($hydrateBody['state']['data'],$hydrateBody['state']['version']),$hydrateBody['endpoint'],$hydrateBody['snapshot'],$hydrateBody['binding_tag']);
	$refresh=dp_panel_reactor_widget_payload($hydrateResult,'refresh','refresh-one',3);
	[$request,$raw]=dp_panel_reactor_widget_http($refresh);
	$refreshResponse=$fixture['controller']->dispatch($request,$raw,'reactor','orders','dashboard');
	$refreshBody=dp_panel_reactor_widget_decode($refreshResponse);
	$t->same(200,$refreshResponse->status());
	$t->same(4,$refreshBody['state']['version']);

	[$staleRequest,$staleRaw]=dp_panel_reactor_widget_http($action);
	$stale=$fixture['controller']->dispatch($staleRequest,$staleRaw,'reactor','orders','dashboard');
	$t->same(409,$stale->status());
	$t->same('widget_version_conflict',dp_panel_reactor_widget_decode($stale)['state']['error_code']);

	$refreshResult=PanelWidgetInteractionResult::success('reactor','dpwi-reactor',PanelWidgetInteractionState::ready($refreshBody['state']['data'],$refreshBody['state']['version']),$refreshBody['endpoint'],$refreshBody['snapshot'],$refreshBody['binding_tag']);
	$unmount=dp_panel_reactor_widget_payload($refreshResult,'unmount','unmount-one',4);
	[$request,$raw]=dp_panel_reactor_widget_http($unmount);
	$ended=$fixture['controller']->dispatch($request,$raw,'reactor','orders','dashboard');
	$t->same(200,$ended->status());
	$endedBody=dp_panel_reactor_widget_decode($ended);
	$t->same('unmounted',$endedBody['state']['status']);
	$t->same(4,$endedBody['state']['version']);
	$t->same(null,$endedBody['endpoint']);
	$t->same(null,$endedBody['snapshot']);
	$t->same(null,$endedBody['binding_tag']);
	$revokeEnvelope=$fixture['capture']->transport[array_key_last($fixture['capture']->transport)]['envelope'];
	$t->same('snapshot_revoke',$revokeEnvelope['operation']);
	$t->same([],$revokeEnvelope['state_keys']);
	$t->same([],$revokeEnvelope['param_keys']);
	$t->same(0,$revokeEnvelope['upload_count']);
	$t->notContains((string)$refreshResult->snapshot(),json_encode($revokeEnvelope,JSON_THROW_ON_ERROR));
	$endedAgain=$fixture['controller']->dispatch($request,$raw,'reactor','orders','dashboard');
	$t->same(409,$endedAgain->status());
})->tag('lifecycle','cas');

test('reactor adapter unmount remains fail closed until transport authorization immediately precedes revoke',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$mounted=$fixture['panel']->widgetRuntime()->mount($fixture['definition'],$fixture['panel'],$fixture['renderRequest'],'dashboard','dpwi-unmount-gate');
	$context=$fixture['panel']->widgetRuntime()->context($fixture['panel'],$fixture['renderRequest'],'dashboard');
	$unmount=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($mounted,'unmount','unmount-gate',1));

	$fixture['manager']->authorizeTransport(null);
	$missing=$fixture['adapter']->handle($fixture['definition'],$context,$unmount);
	$t->same('widget_forbidden',$missing->state()->errorCode());
	$fixture['manager']->authorizeTransport(static function(): never { throw new RuntimeException('private authorization failure'); });
	$unavailable=$fixture['adapter']->handle($fixture['definition'],$context,$unmount);
	$t->same('widget_reactor_unavailable',$unavailable->state()->errorCode());
	$t->isTrue($unavailable->retryable());
	$fixture['manager']->authorizeTransport(static fn(): bool=>false);
	$t->same('widget_forbidden',$fixture['adapter']->handle($fixture['definition'],$context,$unmount)->state()->errorCode());
	$fixture['manager']->authorizeTransport(static fn(array $envelope): bool=>$envelope['operation']==='snapshot_revoke');
	$revoked=$fixture['adapter']->handle($fixture['definition'],$context,$unmount);
	$t->isTrue($revoked->successful());
	$t->same(null,$revoked->snapshot());
	$t->same('widget_version_conflict',$fixture['adapter']->handle($fixture['definition'],$context,$unmount)->state()->errorCode());
})->tag('lifecycle','security','transport','replay');

test('adapter rejects scope component action refresh version and snapshot pivots with stable public errors',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$mounted=$fixture['panel']->widgetRuntime()->mount($fixture['definition'],$fixture['panel'],$fixture['renderRequest'],'dashboard','dpwi-guards');
	$context=$fixture['panel']->widgetRuntime()->context($fixture['panel'],$fixture['renderRequest'],'dashboard');

	$unknownAction=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($mounted,'action','unknown',1,'delete'));
	$t->same('widget_action_unavailable',$fixture['adapter']->handle($fixture['definition'],$context,$unknownAction)->state()->errorCode());
	$wrongVersion=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($mounted,'action','wrong-version',99,'increment'));
	$t->same('widget_version_conflict',$fixture['adapter']->handle($fixture['definition'],$context,$wrongVersion)->state()->errorCode());
	$invalid=PanelWidgetInteractionRequest::fromArray(array_replace(dp_panel_reactor_widget_payload($mounted,'action','invalid-snapshot',1,'increment'),['snapshot'=>'not-json']));
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($fixture['definition'],$context,$invalid)->state()->errorCode());

	$otherManager=(new ReactorManager(new ReactorInMemorySnapshotVersionStore()))->authorizeTransport(static fn(): bool=>true);
	$otherManager->register(ReactorComponent::make('other.component')->render('other'));
	$otherScope=ReactorSecurityContext::forAudience('other');
	$otherSnapshot=$otherManager->snapshot('other.component',[],$otherScope);
	$otherEncoded=json_encode($otherSnapshot->jsonSerialize(),JSON_THROW_ON_ERROR);
	$componentPivot=PanelWidgetInteractionRequest::fromArray(array_replace(dp_panel_reactor_widget_payload($mounted,'action','component-pivot',1,'increment'),['snapshot'=>$otherEncoded]));
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($fixture['definition'],$context,$componentPivot)->state()->errorCode());

	$otherContext=$fixture['panel']->widgetRuntime()->context($fixture['panel'],PanelRequest::fromArray(['method'=>'GET','user'=>['id'=>'operator-2'],'tenant'=>'tenant-a']),'dashboard');
	$scopePivot=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($mounted,'action','scope-pivot',1,'increment'));
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($fixture['definition'],$otherContext,$scopePivot)->state()->errorCode());
	$otherIsland=PanelWidgetInteractionRequest::fromArray(array_replace(dp_panel_reactor_widget_payload($mounted,'action','island-pivot',1,'increment'),['island_id'=>'dpwi-other-island']));
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($fixture['definition'],$context,$otherIsland)->state()->errorCode());
	$otherDefinition=PanelWidgetInteractionDefinition::make('reactor','order-counter-other')->action('increment','Increment')->refresh('manual');
	$otherBinding=PanelReactorWidgetBinding::make('orders-other',$otherDefinition,'orders.counter')->actions(['increment'=>'increase'])->surfaces('dashboard');
	$fixture['adapter']->bind($otherBinding);
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($otherDefinition,$context,$scopePivot)->state()->errorCode());

	$noneDefinition=PanelWidgetInteractionDefinition::make('reactor','no-refresh')->refresh('none');
	$noneBinding=PanelReactorWidgetBinding::make('no-refresh',$noneDefinition,'orders.counter')->surfaces('dashboard');
	$noneAdapter=(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],new ReactorInMemorySnapshotVersionStore(),'/runtime/reactor'))->bind($noneBinding);
	$noneMount=$noneAdapter->handle($noneDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-none','none-mount'));
	$noneRefresh=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($noneMount,'refresh','none-refresh',1));
	$t->same('widget_refresh_disabled',$noneAdapter->handle($noneDefinition,$context,$noneRefresh)->state()->errorCode());

	$missing=PanelWidgetInteractionDefinition::make('reactor','not-bound');
	$t->same('widget_binding_unavailable',$fixture['adapter']->handle($missing,$context,PanelWidgetInteractionRequest::mount('dpwi-missing','missing'))->state()->errorCode());
})->tag('security','adversarial');

test('controller validates the entire transport before adapter and binding resolution',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$body=['schema_version'=>1,'operation'=>'mount','island_id'=>'dpwi-http','idempotency_key'=>'mount-http'];
	[$valid,$raw]=dp_panel_reactor_widget_http($body);
	$t->throws(static fn()=>new PanelReactorWidgetController($fixture['panel'],null,null,100),InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelReactorWidgetController($fixture['panel'],null,null,1048577),InvalidArgumentException::class);

	$cases=[
		['widget_method_not_allowed',['method'=>'GET']],
		['widget_content_type_invalid',['headers'=>['Content-Type'=>'text/plain']]],
		['widget_accept_invalid',['headers'=>['Accept'=>'text/html']]],
		['widget_transport_header_invalid',['headers'=>['X-Requested-With'=>'XMLHttpRequest']]],
		['widget_content_encoding_invalid',['headers'=>['Content-Encoding'=>'gzip']]],
		['widget_transport_pollution',['query'=>['component'=>'orders.counter']]],
		['widget_transport_pollution',['files'=>['payload'=>['name'=>'x']]]],
		['widget_origin_invalid',['headers'=>['Origin'=>'null']]],
		['widget_origin_forbidden',['headers'=>['Origin'=>'https://evil.example.test']]],
		['widget_csrf_invalid',['headers'=>['X-CSRF-Token'=>'bad']]],
	];
	foreach($cases as [$code,$options]){
		[$request,$caseRaw]=dp_panel_reactor_widget_http($body,$options);
		$response=$fixture['controller']->dispatch($request,$caseRaw,'missing','missing','dashboard');
		$t->same($code,dp_panel_reactor_widget_decode($response)['code']);
	}
	foreach(['','application/json; q=0.9','application/json; charset=utf-8','application/*','*/*','text/html;q=0.4, application/json;q=1'] as $accept){
		[$request,$caseRaw]=dp_panel_reactor_widget_http($body,['headers'=>['Accept'=>$accept]]);
		$t->same('widget_route_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($request,$caseRaw,'missing','missing','dashboard'))['code']);
	}
	foreach(['application/json;q=0, */*;q=1','application/json;q=1.1','application/json;q=0.1234',str_repeat('a',2049),implode(',',array_fill(0,33,'application/json'))] as $accept){
		[$request,$caseRaw]=dp_panel_reactor_widget_http($body,['headers'=>['Accept'=>$accept]]);
		$t->same('widget_accept_invalid',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($request,$caseRaw,'missing','missing','dashboard'))['code']);
	}
	$csrfCalls=0;
	$boundedCsrf=new PanelReactorWidgetController($fixture['panel'],static fn(): bool=>true,static function()use(&$csrfCalls): bool { $csrfCalls++; return true; });
	[$oversizedCsrf,$oversizedCsrfRaw]=dp_panel_reactor_widget_http($body,['headers'=>['X-CSRF-Token'=>str_repeat('c',4097)]]);
	$t->same('widget_csrf_invalid',dp_panel_reactor_widget_decode($boundedCsrf->dispatch($oversizedCsrf,$oversizedCsrfRaw,'missing','missing','dashboard'))['code']);
	$t->same(0,$csrfCalls);

	$noOrigin=new PanelReactorWidgetController($fixture['panel'],null,static fn(): bool=>true);
	$t->same('widget_origin_validation_unavailable',dp_panel_reactor_widget_decode($noOrigin->dispatch($valid,$raw,'missing','missing','dashboard'))['code']);
	$throwOrigin=new PanelReactorWidgetController($fixture['panel'],static function(): never { throw new RuntimeException('private'); },static fn(): bool=>true);
	$t->same('widget_origin_validation_unavailable',dp_panel_reactor_widget_decode($throwOrigin->dispatch($valid,$raw,'missing','missing','dashboard'))['code']);
	$noCsrf=new PanelReactorWidgetController($fixture['panel'],static fn(): bool=>true,null);
	$t->same('widget_csrf_validation_unavailable',dp_panel_reactor_widget_decode($noCsrf->dispatch($valid,$raw,'missing','missing','dashboard'))['code']);
	$throwCsrf=new PanelReactorWidgetController($fixture['panel'],static fn(): bool=>true,static function(): never { throw new RuntimeException('private'); });
	$t->same('widget_csrf_validation_unavailable',dp_panel_reactor_widget_decode($throwCsrf->dispatch($valid,$raw,'missing','missing','dashboard'))['code']);

	[$wrongLength,$wrongRaw]=dp_panel_reactor_widget_http($body,['headers'=>['Content-Length'=>'999']]);
	$t->same('widget_content_length_invalid',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($wrongLength,$wrongRaw,'missing','missing','dashboard'))['code']);
	[$badLength,$badLengthRaw]=dp_panel_reactor_widget_http($body,['headers'=>['Content-Length'=>'01']]);
	$t->same('widget_content_length_invalid',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($badLength,$badLengthRaw,'missing','missing','dashboard'))['code']);
	$emptyRequest=PanelRequest::fromArray(['method'=>'POST','headers'=>array_replace($valid->headers(),['content-length'=>'0']) ,'user'=>['id'=>'operator-1'],'tenant'=>'tenant-a']);
	$t->same('widget_body_empty',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($emptyRequest,'','missing','missing','dashboard'))['code']);
	$smallController=new PanelReactorWidgetController($fixture['panel'],static fn(): bool=>true,static fn(): bool=>true,1024);
	$largeRaw='{"schema_version":1,"operation":"mount","island_id":"dpwi-http","idempotency_key":"'.str_repeat('x',1100).'"}';
	$largeRequest=PanelRequest::fromArray(['method'=>'POST','headers'=>array_replace($valid->headers(),['content-length'=>(string)strlen($largeRaw)]),'user'=>['id'=>'operator-1'],'tenant'=>'tenant-a']);
	$t->same('widget_body_too_large',dp_panel_reactor_widget_decode($smallController->dispatch($largeRequest,$largeRaw,'missing','missing','dashboard'))['code']);
	$badJson='{';
	$badRequest=PanelRequest::fromArray(['method'=>'POST','headers'=>array_replace($valid->headers(),['content-length'=>'1']),'user'=>['id'=>'operator-1'],'tenant'=>'tenant-a']);
	$t->same('widget_json_invalid',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($badRequest,$badJson,'missing','missing','dashboard'))['code']);
	$listRaw='[]';
	$listRequest=PanelRequest::fromArray(['method'=>'POST','headers'=>array_replace($valid->headers(),['content-length'=>'2']),'user'=>['id'=>'operator-1'],'tenant'=>'tenant-a']);
	$t->same('widget_json_object_required',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($listRequest,$listRaw,'missing','missing','dashboard'))['code']);
	$invalidBody=['schema_version'=>1,'operation'=>'mount','island_id'=>'dpwi-http','idempotency_key'=>'mount-http','component'=>'orders.counter'];
	[$invalidRequest,$invalidRaw]=dp_panel_reactor_widget_http($invalidBody);
	$t->same('widget_request_invalid',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($invalidRequest,$invalidRaw,'missing','missing','dashboard'))['code']);
	$t->same('widget_route_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'bad route','orders','dashboard'))['code']);
	$t->same('widget_route_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'missing','orders','dashboard'))['code']);
	$throwingRoute=new DpPanelReactorHttpRouteProbe('throwing-route');
	$throwingRoute->throwResolution=true;
	$fixture['panel']->registerWidgetRuntimeAdapter($throwingRoute);
	$t->same('widget_route_resolution_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'throwing-route','orders','dashboard'))['code']);
	$aliasedRoute=new DpPanelReactorHttpRouteProbe('canonical-route');
	$fixture['panel']->registerWidgetRuntimeAdapter($aliasedRoute,'aliased-route');
	$t->same('widget_route_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'aliased-route','orders','dashboard'))['code']);
	$throwingName=new DpPanelReactorHttpRouteProbe('throwing-name');
	$fixture['panel']->registerWidgetRuntimeAdapter($throwingName);
	$throwingName->throwName=true;
	$t->same('widget_route_resolution_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'throwing-name','orders','dashboard'))['code']);
	$confusedRoute=new DpPanelReactorHttpRouteProbe('confused-route',PanelWidgetInteractionDefinition::make('reactor','confused'));
	$fixture['panel']->registerWidgetRuntimeAdapter($confusedRoute);
	$t->same('widget_route_resolution_unavailable',dp_panel_reactor_widget_decode($fixture['controller']->dispatch($valid,$raw,'confused-route','orders','dashboard'))['code']);

	$throwingScope=PanelInstance::make('throwing-scope',[
		'widget_runtime_binding_key'=>str_repeat('q',32),
		'widget_runtime_scope_resolver'=>static function(): never { throw new RuntimeException('private scope failure'); },
	]);
	$throwingScope->registerWidgetRuntimeAdapter($fixture['adapter']);
	$scopeController=new PanelReactorWidgetController($throwingScope,static fn(): bool=>true,static fn(): bool=>true);
	$scopeFailure=$scopeController->dispatch($valid,$raw,'reactor','orders','dashboard');
	$t->same('widget_context_unavailable',dp_panel_reactor_widget_decode($scopeFailure)['state']['error_code']);
	$t->same('panel_reactor_widget_controller',$fixture['controller']->manifest()['type']);
	$t->same(65536,$fixture['controller']->manifest()['max_body_bytes']);
	$t->same(4096,$fixture['controller']->manifest()['max_csrf_token_bytes']);
	$t->same(2048,$fixture['controller']->manifest()['max_accept_bytes']);
	$t->same('no-store, private, max-age=0',$fixture['controller']->dispatch($valid,$raw,'missing','orders','dashboard')->headers()['Cache-Control']);
})->tag('controller','security','adversarial');

test('controller returns exact interaction failures for missing bindings scopes and binding tags',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$mount=['schema_version'=>1,'operation'=>'mount','island_id'=>'dpwi-controller','idempotency_key'=>'mount-controller'];
	[$request,$raw]=dp_panel_reactor_widget_http($mount);
	$missing=$fixture['controller']->dispatch($request,$raw,'reactor','missing','dashboard');
	$t->same(404,$missing->status());
	$t->same('panel_widget_interaction_result',dp_panel_reactor_widget_decode($missing)['type']);
	$t->same('widget_binding_unavailable',dp_panel_reactor_widget_decode($missing)['state']['error_code']);

	[$anonymous,$anonymousRaw]=dp_panel_reactor_widget_http($mount,['user'=>null]);
	$denied=$fixture['controller']->dispatch($anonymous,$anonymousRaw,'reactor','orders','dashboard');
	$t->same(403,$denied->status());
	$t->same('widget_scope_unavailable',dp_panel_reactor_widget_decode($denied)['state']['error_code']);

	$mounted=$fixture['panel']->widgetRuntime()->mount($fixture['definition'],$fixture['panel'],$fixture['renderRequest'],'dashboard','dpwi-scope');
	$action=dp_panel_reactor_widget_payload($mounted,'action','scope-controller',1,'increment');
	[$other,$otherRaw]=dp_panel_reactor_widget_http($action,['user'=>['id'=>'operator-2']]);
	$scope=$fixture['controller']->dispatch($other,$otherRaw,'reactor','orders','dashboard');
	$t->same(409,$scope->status());
	$t->same('widget_scope_mismatch',dp_panel_reactor_widget_decode($scope)['state']['error_code']);
})->tag('controller','scope');

test('adapter contains expiry storage response integrity public state and snapshot budget failures',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$context=$fixture['panel']->widgetRuntime()->context($fixture['panel'],$fixture['renderRequest'],'dashboard');
	$expiredEnvelope=[
		'schema_version'=>2,
		'snapshot_id'=>str_repeat('a',32),
		'component'=>'orders.counter',
		'state'=>[],
		'locked'=>[],
		'scope_hash'=>str_repeat('b',64),
		'version'=>0,
		'created_at'=>time()-100,
		'expires_at'=>time()-1,
		'signature'=>'invalid-but-shaped',
	];
	$expiredRequest=PanelWidgetInteractionRequest::fromArray([
		'operation'=>'action','island_id'=>'dpwi-expired','action'=>'increment','payload'=>[],'expected_version'=>1,
		'idempotency_key'=>'expired','snapshot'=>json_encode($expiredEnvelope,JSON_THROW_ON_ERROR),'binding_tag'=>$context->bindingTag(),
	]);
	$t->same('widget_snapshot_invalid',$fixture['adapter']->handle($fixture['definition'],$context,$expiredRequest)->state()->errorCode());
	$security=$t->nonPublic($fixture['adapter'])->invoke('securityContext',$fixture['binding'],$context,$expiredRequest);
	$expiredEnvelope['scope_hash']=$security->scopeHash();
	$expiredPayload=$expiredEnvelope;
	unset($expiredPayload['signature']);
	$expiredEnvelope['signature']=ReactorSigner::sign($expiredPayload);
	$authenticExpired=PanelWidgetInteractionRequest::fromArray(array_replace($expiredRequest->toArray(),['snapshot'=>json_encode($expiredEnvelope,JSON_THROW_ON_ERROR)]));
	$t->same('widget_snapshot_expired',$fixture['adapter']->handle($fixture['definition'],$context,$authenticExpired)->state()->errorCode());

	$fixture['manager']->register(ReactorComponent::make('public.invalid')->state(['secret'=>'hidden'])->render('invalid'));
	$invalidDefinition=PanelWidgetInteractionDefinition::make('reactor','public-invalid');
	$invalidBinding=PanelReactorWidgetBinding::make('public-invalid',$invalidDefinition,'public.invalid')->surfaces('dashboard');
	$fixture['adapter']->bind($invalidBinding);
	$invalidState=$fixture['adapter']->handle($invalidDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-invalid-state','invalid-state'));
	$t->same('widget_state_invalid',$invalidState->state()->errorCode());

	$fixture['manager']->register(ReactorComponent::make('snapshot.large')->state(['blob'=>str_repeat('x',8000)])->render('large'));
	$largeDefinition=PanelWidgetInteractionDefinition::make('reactor','snapshot-large');
	$largeBinding=PanelReactorWidgetBinding::make('snapshot-large',$largeDefinition,'snapshot.large')->surfaces('dashboard');
	$fixture['adapter']->bind($largeBinding);
	$large=$fixture['adapter']->handle($largeDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-large','large'));
	$t->same('widget_snapshot_too_large',$large->state()->errorCode());

	$throwStore=new DpPanelReactorWidgetStoreProbe();
	$throwManager=(new ReactorManager($throwStore))->authorizeTransport(static fn(): bool=>true);
	$throwManager->register(ReactorComponent::make('throw.revoke')->state(['ok'=>true])->render('throw'));
	$throwDefinition=PanelWidgetInteractionDefinition::make('reactor','throw-revoke');
	$throwBinding=PanelReactorWidgetBinding::make('throw-revoke',$throwDefinition,'throw.revoke')->surfaces('dashboard');
	$throwAdapter=(new PanelReactorWidgetRuntimeAdapter($throwManager,$throwStore,'/throw-runtime'))->bind($throwBinding);
	$throwMount=$throwAdapter->handle($throwDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-throw','throw-mount'));
	$t->isTrue($throwMount->successful());
	$throwStore->throwRevoke=true;
	$throwUnmount=PanelWidgetInteractionRequest::fromArray(dp_panel_reactor_widget_payload($throwMount,'unmount','throw-unmount',1));
	$throwResult=$throwAdapter->handle($throwDefinition,$context,$throwUnmount);
	$t->same('widget_reactor_unavailable',$throwResult->state()->errorCode());
	$t->isTrue($throwResult->retryable());

	$rotateStore=new DpPanelReactorWidgetStoreProbe();
	$rotateManager=(new ReactorManager($rotateStore))->authorizeTransport(static fn(): bool=>true);
	$rotateManager->register(ReactorComponent::make('rotate.signature')->state(['ok'=>true])->render('rotate'));
	$rotateDefinition=PanelWidgetInteractionDefinition::make('reactor','rotate-signature');
	$rotateBinding=PanelReactorWidgetBinding::make('rotate-signature',$rotateDefinition,'rotate.signature')->surfaces('dashboard');
	$rotateAdapter=(new PanelReactorWidgetRuntimeAdapter($rotateManager,$rotateStore,'/rotate-runtime'))->bind($rotateBinding);
	$rotateStore->afterRegister=static function()use($t): void {
		$t->nonPublic(ReactorSigner::class)->replacePropertyForTest('ephemeralSecret',str_repeat('z',32));
	};
	$rotateStore->rotateSignerOnRegister=true;
	$rotated=$rotateAdapter->handle($rotateDefinition,$context,PanelWidgetInteractionRequest::mount('dpwi-rotate','rotate-mount'));
	$t->same('widget_snapshot_invalid',$rotated->state()->errorCode());

	$longRoute='r'.str_repeat('a',95);
	$longSurface='s'.str_repeat('b',127);
	$longDefinition=PanelWidgetInteractionDefinition::make('reactor','long-endpoint');
	$longBinding=PanelReactorWidgetBinding::make($longRoute,$longDefinition,'orders.counter')->surfaces($longSurface);
	$longAdapter=(new PanelReactorWidgetRuntimeAdapter($fixture['manager'],$fixture['store'],'/'.str_repeat('p',1899)))->bind($longBinding);
	$longContext=$fixture['panel']->widgetRuntime()->context($fixture['panel'],$fixture['renderRequest'],$longSurface);
	$longResult=$longAdapter->handle($longDefinition,$longContext,PanelWidgetInteractionRequest::mount('dpwi-long-endpoint','long-endpoint'));
	$t->same('widget_reactor_unavailable',$longResult->state()->errorCode());
	$t->isTrue($longResult->retryable());
})->tag('adapter','faults','coverage');

test('stable Reactor error translation never exposes private messages',static function(Context $t): void {
	$fixture=dp_panel_reactor_widget_fixture();
	$request=PanelWidgetInteractionRequest::mount('dpwi-errors','error-map');
	$adapterInternals=$t->nonPublic($fixture['adapter']);
	$cases=[
		[ReactorResponse::error('private',409,['error'=>['code'=>'snapshot_stale']]),'widget_version_conflict',409,true],
		[ReactorResponse::error('private',419,['error'=>['code'=>'snapshot_expired']]),'widget_snapshot_expired',419,false],
		[ReactorResponse::error('private',419,['error'=>['code'=>'snapshot_invalid']]),'widget_snapshot_invalid',419,false],
		[ReactorResponse::error('private',403),'widget_forbidden',403,false],
		[ReactorResponse::error('private',404),'widget_component_unavailable',404,false],
		[ReactorResponse::error('private',422),'widget_action_rejected',422,false],
		[ReactorResponse::error('private',503),'widget_reactor_unavailable',503,true],
		[ReactorResponse::error('private',500),'widget_runtime_failure',500,true],
	];
	foreach($cases as [$reactor,$code,$status,$retryable]){
		$result=$adapterInternals->invoke('reactorFailure',$request,$reactor);
		$t->same($code,$result->state()->errorCode());
		$t->same($status,$result->httpStatus());
		$t->same($retryable,$result->retryable());
		$t->isFalse(str_contains((string)$result->state()->message(),'private'));
	}
})->tag('errors','security','coverage');
