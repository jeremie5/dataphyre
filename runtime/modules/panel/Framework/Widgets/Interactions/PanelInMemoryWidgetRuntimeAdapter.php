<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/**
 * Deterministic in-process conformance adapter for the Panel widget contract.
 *
 * This adapter is useful for tests and single-process hosts. Its manifest is
 * explicit that state is not durable or multi-process safe; production Reactor
 * integration must use Reactor's scoped transport and snapshot store instead.
 */
final class PanelInMemoryWidgetRuntimeAdapter implements PanelWidgetRuntimeAdapter {
	private const CONTRACT_VERSION=1;
	/** @var array<string,array{initial:array<string,mixed>,actions:array<string,\Closure>,authorize:\Closure,refresh:?\Closure}> */
	private array $components=[];
	/** @var array<string,array<string,mixed>> */
	private array $sessions=[];
	/** @var array<string,array{fingerprint:string,result:PanelWidgetInteractionResult}> */
	private array $idempotency=[];
	/** @var array<string,string> */
	private array $signingKeys=[];
	private string $currentKeyId;
	private bool $persistentKeys;
	private \Closure $clock;
	private int $maxSessions;
	private int $ttlSeconds;

	/** @param array<string,string>|string|null $signingKeys */
	public function __construct(
		private readonly string $endpoint='/__dataphyre/panel/widgets',
		array|string|null $signingKeys=null,
		?string $currentKeyId=null,
		?callable $clock=null,
		int $maxSessions=1024,
		int $ttlSeconds=1800
	){
		PanelWidgetInteractionResult::success('memory', 'validation', PanelWidgetInteractionState::ready([]), $endpoint, 'validation', 'validation');
		$this->persistentKeys=$signingKeys!==null;
		if(is_string($signingKeys)){ $signingKeys=['default'=>$signingKeys]; }
		if($signingKeys===null){ $signingKeys=['ephemeral'=>random_bytes(32)]; }
		foreach($signingKeys as $id=>$key){
			$id=PanelWidgetInteractionValue::safeIdentifier((string)$id, 'Widget signing key id', 32);
			if(!is_string($key) || strlen($key)<32){ throw new \InvalidArgumentException('Widget signing keys must contain at least 32 bytes.'); }
			$this->signingKeys[$id]=$key;
		}
		$this->currentKeyId=$currentKeyId===null ? (string)array_key_first($this->signingKeys) : PanelWidgetInteractionValue::safeIdentifier($currentKeyId, 'Widget current signing key id', 32);
		if(!isset($this->signingKeys[$this->currentKeyId])){ throw new \InvalidArgumentException('Widget current signing key id is not configured.'); }
		if($maxSessions<1 || $maxSessions>4096){ throw new \InvalidArgumentException('In-memory widget session budgets must be between 1 and 4096.'); }
		if($ttlSeconds<60 || $ttlSeconds>86400){ throw new \InvalidArgumentException('In-memory widget session TTL must be between 60 seconds and 24 hours.'); }
		$this->clock=$clock===null ? static fn(): int=>time() : \Closure::fromCallable($clock);
		$this->maxSessions=$maxSessions;
		$this->ttlSeconds=$ttlSeconds;
	}

	public function name(): string { return 'memory'; }
	public function contractVersion(): int { return self::CONTRACT_VERSION; }

	/**
	 * Registers a trusted server-side component and its named handlers.
	 *
	 * @param array<string,mixed> $initialState
	 * Handler state transitions are adapter-owned. Handlers return data maps,
	 * not lifecycle state objects, and must provide their own external-effect
	 * idempotency because in-process replay cannot make side effects exactly once.
	 *
	 * @param array<string,callable(array<string,mixed>,array<string,mixed>,PanelWidgetInteractionContext):array> $actions
	 * @param callable(PanelWidgetInteractionDefinition,PanelWidgetInteractionContext,PanelWidgetInteractionRequest):bool $authorize
	 * @param null|callable(array<string,mixed>,PanelWidgetInteractionContext):array $refresh
	 */
	public function register(string $component, array $initialState, array $actions, callable $authorize, ?callable $refresh=null, bool $replace=false): self {
		$component=PanelWidgetInteractionValue::safeIdentifier($component, 'Widget runtime component', 96);
		if(isset($this->components[$component]) && !$replace){ throw new \LogicException('Widget runtime component already registered: '.$component); }
		if(!isset($this->components[$component]) && count($this->components)>=128){ throw new \LengthException('In-memory widget runtimes support at most 128 components.'); }
		$initialState=PanelWidgetInteractionValue::assertMap($initialState, 'widget initial state');
		if(count($actions)>32){ throw new \LengthException('Widget runtime components support at most 32 actions.'); }
		$normalized=[];
		foreach($actions as $name=>$handler){
			if(!is_string($name) || !is_callable($handler)){ throw new \InvalidArgumentException('Widget runtime action handlers require named callables.'); }
			$name=PanelWidgetInteractionValue::safeIdentifier($name, 'Widget runtime action', 64);
			$normalized[$name]=\Closure::fromCallable($handler);
		}
		ksort($normalized, SORT_STRING);
		$this->components[$component]=[
			'initial'=>$initialState,
			'actions'=>$normalized,
			'authorize'=>\Closure::fromCallable($authorize),
			'refresh'=>$refresh===null ? null : \Closure::fromCallable($refresh),
		];
		ksort($this->components, SORT_STRING);
		return $this;
	}

	public function unregister(string $component): self {
		$component=PanelWidgetInteractionValue::safeIdentifier($component, 'Widget runtime component', 96);
		unset($this->components[$component]);
		foreach($this->sessions as $id=>$session){ if(($session['component'] ?? null)===$component){ $this->dropSession($id, $session); } }
		return $this;
	}

	public function handle(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		$component=$this->components[$definition->component()] ?? null;
		if(!is_array($component)){
			return $this->failure($request, 'widget_component_unavailable', 'Interactive updates are unavailable.', 503, true);
		}
		if(!$this->authorized($component, $definition, $context, $request)){
			return $this->failure($request, 'widget_forbidden', 'This widget interaction is not available.', 403);
		}
		$this->prune();
		try{
			return $request->operation()==='mount'
				? $this->mount($component, $definition, $context, $request)
				: $this->resume($component, $definition, $context, $request);
		}
		catch(PanelWidgetInteractionException $failure){ return PanelWidgetInteractionResult::failure($this->name(), $request->islandId(), $failure); }
		catch(\Throwable){ return $this->failure($request, 'widget_runtime_failure', 'The widget could not be updated.', 500, true); }
	}

	public function manifest(): array {
		$components=[];
		foreach($this->components as $name=>$definition){ $components[$name]=array_keys($definition['actions']); }
		return [
			'type'=>'panel_widget_runtime_adapter',
			'name'=>$this->name(),
			'contract_version'=>self::CONTRACT_VERSION,
			'components'=>$components,
			'capabilities'=>[
				'authorization_before_state'=>true,
				'optimistic_cas'=>true,
				'idempotency'=>true,
				'handler_side_effects_exactly_once'=>false,
				'host_idempotency_required'=>true,
				'scope_bound_snapshots'=>true,
				'key_rotation'=>true,
				'durable'=>false,
				'multi_process'=>false,
				'production_reactor_bridge'=>false,
			],
			'signing_keys'=>['retained'=>count($this->signingKeys), 'persistent'=>$this->persistentKeys],
			'retention'=>['max_sessions'=>$this->maxSessions, 'ttl_seconds'=>$this->ttlSeconds, 'expired_pruning'=>'on_authorized_request'],
			'lifecycle'=>['unmount_delivery'=>'best_effort_keepalive', 'abrupt_disconnect_fallback'=>'ttl_expiration'],
			'handler_effects'=>['exactly_once'=>false, 'host_idempotency_required'=>true, 'idempotency_scope'=>'adapter_state_and_response_replay'],
		];
	}

	public function reset(): void { $this->sessions=[]; $this->idempotency=[]; }
	public function sessionCount(): int { return count($this->sessions); }

	/** @param array<string,mixed> $component */
	private function mount(array $component, PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		$scope=$context->scopeKey($this->signingKeys[$this->currentKeyId]);
		$mountKey=hash('sha256', $scope."\0".$request->islandId());
		$existing=$this->sessions[$mountKey] ?? null;
		$requestFingerprint=$request->fingerprint();
		$idempotencyKey=$mountKey."\0".$request->idempotencyKey();
		if(is_array($existing) && ($existing['mounted'] ?? false)!==true){
			if(is_string($existing['mount_idempotency_key'] ?? null)){ unset($this->idempotency[$existing['mount_idempotency_key']]); }
			unset($this->sessions[$mountKey]);
			$existing=null;
		}
		if(isset($this->idempotency[$idempotencyKey])){ return $this->replay($idempotencyKey, $requestFingerprint); }
		if(is_array($existing)){
			if(!hash_equals((string)$existing['definition_fingerprint'], $definition->fingerprint())){
				throw new PanelWidgetInteractionException('widget_definition_conflict', 'The widget definition changed and must be reloaded.', 409);
			}
			$result=$this->result($request, $existing, true, $context);
			$this->remember($idempotencyKey, $requestFingerprint, $result);
			return $result;
		}
		if(count($this->sessions)>=$this->maxSessions){ $this->evictOldest(); }
		$sessionId=self::randomId();
		$now=$this->now();
		$session=[
			'id'=>$sessionId,
			'key_id'=>$this->currentKeyId,
			'scope'=>$scope,
			'island_id'=>$request->islandId(),
			'component'=>$definition->component(),
			'definition_fingerprint'=>$definition->fingerprint(),
			'data'=>$component['initial'],
			'version'=>1,
			'mounted'=>true,
			'storage_key'=>$mountKey,
			'mount_idempotency_key'=>$idempotencyKey,
			'created_at'=>$now,
			'last_seen_at'=>$now,
		];
		$this->sessions[$mountKey]=$session;
		$result=$this->result($request, $session, false, $context);
		$this->remember($idempotencyKey, $requestFingerprint, $result);
		return $result;
	}

	/** @param array<string,mixed> $component */
	private function resume(array $component, PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		if(!$context->acceptsBindingTag($request->bindingTag())){
			throw new PanelWidgetInteractionException('widget_scope_mismatch', 'The widget session is no longer valid.', 409);
		}
		$claims=$this->verifySnapshot((string)$request->snapshot());
		$keyId=(string)$claims['kid'];
		$scope=$context->scopeKey($this->signingKeys[$keyId]);
		$storageKey=hash('sha256', $scope."\0".$request->islandId());
		$session=$this->sessions[$storageKey] ?? null;
		if(!is_array($session) || !hash_equals((string)$session['id'], (string)$claims['id']) || !hash_equals((string)$session['scope'], $scope) || $session['island_id']!==$request->islandId() || $session['component']!==$definition->component() || !hash_equals((string)$session['definition_fingerprint'], $definition->fingerprint())){
			throw new PanelWidgetInteractionException('widget_session_invalid', 'The widget session is no longer valid.', 409);
		}
		$idempotencyKey=$session['id']."\0".$request->idempotencyKey();
		$requestFingerprint=$request->fingerprint();
		if(isset($this->idempotency[$idempotencyKey])){ return $this->replay($idempotencyKey, $requestFingerprint); }
		if(($session['mounted'] ?? false)!==true){ throw new PanelWidgetInteractionException('widget_unmounted', 'The widget session has ended.', 409); }
		$session['last_seen_at']=$this->now();
		$expected=$request->expectedVersion();
		if($expected!==null && $expected!==(int)$session['version']){
			throw new PanelWidgetInteractionException('widget_version_conflict', 'The widget changed in another request. Refresh and try again.', 409, true);
		}
		if($request->operation()==='action'){
			$action=(string)$request->action();
			if(!$definition->allows($action) || !isset($component['actions'][$action])){
				throw new PanelWidgetInteractionException('widget_action_unavailable', 'This widget action is not available.', 404);
			}
			$session['data']=$this->nextData(($component['actions'][$action])($session['data'], $request->payload(), $context));
			$session['version']++;
		}
		elseif($request->operation()==='refresh'){
			if($definition->refreshMode()==='none'){ throw new PanelWidgetInteractionException('widget_refresh_unavailable', 'This widget cannot be refreshed.', 404); }
			if($component['refresh'] instanceof \Closure){ $session['data']=$this->nextData(($component['refresh'])($session['data'], $context)); }
			$session['version']++;
		}
		elseif($request->operation()==='unmount'){
			$session['version']++;
			$session['mounted']=false;
		}
		$this->sessions[$storageKey]=$session;
		$result=$this->result($request, $session, false, $context);
		$this->remember($idempotencyKey, $requestFingerprint, $result);
		return $result;
	}

	/** @param array<string,mixed> $component */
	private function authorized(array $component, PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): bool {
		try{ return ($component['authorize'])($definition, $context, $request)===true; }
		catch(\Throwable){ return false; }
	}

	/** @param array<string,mixed> $session */
	private function result(PanelWidgetInteractionRequest $request, array $session, bool $replayed, PanelWidgetInteractionContext $context): PanelWidgetInteractionResult {
		$state=($session['mounted'] ?? false)===true
			? PanelWidgetInteractionState::ready($session['data'], (int)$session['version'], $replayed ? 'Widget state restored.' : 'Widget updated.')
			: PanelWidgetInteractionState::make('unmounted', (int)$session['version'], [], null, 'Widget session ended.');
		return PanelWidgetInteractionResult::success($this->name(), $request->islandId(), $state, $this->endpoint, $this->snapshot((string)$session['id'], (string)$session['key_id']), $context->bindingTag(), $replayed);
	}

	private function failure(PanelWidgetInteractionRequest $request, string $code, string $message, int $status, bool $retryable=false): PanelWidgetInteractionResult {
		return PanelWidgetInteractionResult::failure($this->name(), $request->islandId(), new PanelWidgetInteractionException($code, $message, $status, $retryable));
	}

	private function nextData(mixed $value): array {
		if($value instanceof PanelWidgetInteractionState){ throw new \UnexpectedValueException('Widget handlers must return data maps; lifecycle states are adapter-owned.'); }
		if(!is_array($value)){ throw new \UnexpectedValueException('Widget handlers must return state maps.'); }
		return PanelWidgetInteractionValue::assertMap($value, 'widget handler state');
	}

	private function remember(string $key, string $fingerprint, PanelWidgetInteractionResult $result): void {
		if(count($this->idempotency)>=4096){ array_shift($this->idempotency); }
		$this->idempotency[$key]=['fingerprint'=>$fingerprint, 'result'=>$result];
	}

	private function replay(string $key, string $fingerprint): PanelWidgetInteractionResult {
		$record=$this->idempotency[$key];
		if(!hash_equals($record['fingerprint'], $fingerprint)){
			throw new PanelWidgetInteractionException('widget_idempotency_conflict', 'This widget request key was already used.', 409);
		}
		$result=$record['result'];
		if(!$result->successful()){ return $result; }
		return PanelWidgetInteractionResult::success($result->adapter(), $result->islandId(), $result->state(), (string)$result->endpoint(), (string)$result->snapshot(), (string)$result->bindingTag(), true);
	}

	private function snapshot(string $id, string $keyId): string {
		$payload=self::base64Url(json_encode(['v'=>1,'kid'=>$keyId,'id'=>$id], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
		return $payload.'.'.self::base64Url(hash_hmac('sha256', $payload, $this->signingKeys[$keyId], true));
	}

	/** @return array{v:int,kid:string,id:string} */
	private function verifySnapshot(string $snapshot): array {
		if(strlen($snapshot)>8192 || substr_count($snapshot, '.')!==1){ throw new PanelWidgetInteractionException('widget_snapshot_invalid', 'The widget session is no longer valid.', 409); }
		[$payload,$signature]=explode('.', $snapshot, 2);
		$decoded=self::base64UrlDecode($payload);
		try{ $claims=json_decode($decoded, true, 8, JSON_THROW_ON_ERROR); }catch(\Throwable){ $claims=null; }
		if(!is_array($claims) || array_keys($claims)!==['v','kid','id'] || $claims['v']!==1 || !is_string($claims['kid']) || !is_string($claims['id']) || !isset($this->signingKeys[$claims['kid']])){
			throw new PanelWidgetInteractionException('widget_snapshot_invalid', 'The widget session is no longer valid.', 409);
		}
		$expected=self::base64Url(hash_hmac('sha256', $payload, $this->signingKeys[$claims['kid']], true));
		if(!hash_equals($expected, $signature)){ throw new PanelWidgetInteractionException('widget_snapshot_invalid', 'The widget session is no longer valid.', 409); }
		return ['v'=>1,'kid'=>$claims['kid'],'id'=>PanelWidgetInteractionValue::boundedString($claims['id'], 'Widget session id', 64)];
	}

	private static function randomId(): string { return self::base64Url(random_bytes(24)); }
	private function now(): int {
		$value=($this->clock)();
		if(!is_int($value)){ throw new \UnexpectedValueException('Widget runtime clocks must return integer timestamps.'); }
		return $value;
	}

	private function prune(): void {
		$cutoff=$this->now()-$this->ttlSeconds;
		foreach($this->sessions as $key=>$session){
			if((int)($session['last_seen_at'] ?? 0)>=$cutoff){ continue; }
			$this->dropSession($key, $session);
		}
	}

	private function evictOldest(): void {
		$oldestKey=null;
		$oldest=PHP_INT_MAX;
		foreach($this->sessions as $key=>$session){
			$seen=(int)($session['last_seen_at'] ?? 0);
			if($seen<$oldest){ $oldest=$seen; $oldestKey=$key; }
		}
		if(is_string($oldestKey)){ $this->dropSession($oldestKey, $this->sessions[$oldestKey]); }
	}

	/** @param array<string,mixed> $session */
	private function dropSession(string $key, array $session): void {
		unset($this->sessions[$key]);
		$id=(string)($session['id'] ?? '');
		$mount=(string)($session['mount_idempotency_key'] ?? '');
		foreach(array_keys($this->idempotency) as $idempotencyKey){
			if($idempotencyKey===$mount || ($id!=='' && str_starts_with($idempotencyKey, $id."\0"))){ unset($this->idempotency[$idempotencyKey]); }
		}
	}
	private static function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
	private static function base64UrlDecode(string $value): string {
		if($value==='' || preg_match('/^[A-Za-z0-9_-]+$/', $value)!==1){ throw new PanelWidgetInteractionException('widget_snapshot_invalid', 'The widget session is no longer valid.', 409); }
		$decoded=base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4-strlen($value)%4)%4), true);
		if(!is_string($decoded)){ throw new PanelWidgetInteractionException('widget_snapshot_invalid', 'The widget session is no longer valid.', 409); }
		return $decoded;
	}
}
