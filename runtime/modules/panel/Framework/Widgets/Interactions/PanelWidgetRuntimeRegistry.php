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
 * Instance-owned deterministic registry for interactive-widget adapters.
 *
 * The registry has no static fallback. Each PanelInstance owns one registry,
 * one host scope resolver, and one retained binding-key set. Adapter conflicts
 * are layered with provenance so plugin removal reveals the prior contribution.
 */
final class PanelWidgetRuntimeRegistry implements \JsonSerializable {
	private const POLICIES=['reject','keep_first','replace'];
	/** @var array<string,list<array{owner:string,adapter:PanelWidgetRuntimeAdapter,manifest:array<string,mixed>,meta:array<string,mixed>,revision:int}>> */
	private array $layers=[];
	/** @var array<string,string> */
	private array $bindingKeys=[];
	private string $currentKeyId;
	private bool $persistentKeys;
	private int $revision=0;
	private int $cleanupFailures=0;
	private ?\Closure $scopeResolver;

	/** @param array<string,string>|string|null $bindingKeys */
	public function __construct(
		private string $conflictPolicy='reject',
		?callable $scopeResolver=null,
		array|string|null $bindingKeys=null,
		?string $currentKeyId=null
	){
		$this->conflictPolicy=self::policy($conflictPolicy);
		$this->scopeResolver=$scopeResolver===null ? null : \Closure::fromCallable($scopeResolver);
		$this->persistentKeys=$bindingKeys!==null;
		if(is_string($bindingKeys)){ $bindingKeys=['default'=>$bindingKeys]; }
		if($bindingKeys===null){ $bindingKeys=['ephemeral'=>random_bytes(32)]; }
		if(count($bindingKeys)<1 || count($bindingKeys)>8){ throw new \InvalidArgumentException('Widget binding keyrings require 1-8 retained keys.'); }
		foreach($bindingKeys as $id=>$key){
			$id=PanelWidgetInteractionValue::safeIdentifier((string)$id, 'Widget binding key id', 32);
			if(!is_string($key) || strlen($key)<32){ throw new \InvalidArgumentException('Widget binding keys must contain at least 32 bytes.'); }
			$this->bindingKeys[$id]=$key;
		}
		$this->currentKeyId=$currentKeyId===null ? (string)array_key_first($this->bindingKeys) : PanelWidgetInteractionValue::safeIdentifier($currentKeyId, 'Widget current binding key id', 32);
		if(!isset($this->bindingKeys[$this->currentKeyId])){ throw new \InvalidArgumentException('Widget current binding key id is not configured.'); }
	}

	public function conflictPolicy(): string { return $this->conflictPolicy; }
	public function revision(): int { return $this->revision; }
	public function persistentBindingKeys(): bool { return $this->persistentKeys; }

	public function conflictPolicyUsing(string $policy): self { $this->conflictPolicy=self::policy($policy); return $this; }
	public function scopeUsing(?callable $resolver): self { $this->scopeResolver=$resolver===null ? null : \Closure::fromCallable($resolver); return $this; }

	/** @param array<string,mixed> $meta */
	public function register(PanelWidgetRuntimeAdapter $adapter, ?string $alias=null, string $owner='application', array $meta=[]): bool {
		$alias=PanelWidgetInteractionValue::safeIdentifier($alias ?? $adapter->name(), 'Widget runtime adapter alias', 64);
		$owner=PanelWidgetInteractionValue::safeIdentifier($owner, 'Widget runtime adapter owner', 64);
		if($adapter->contractVersion()!==1){ throw new \UnexpectedValueException('Unsupported widget runtime adapter contract version.'); }
		$manifest=PanelWidgetInteractionValue::assertMap($adapter->manifest(), 'widget runtime adapter manifest', 32768);
		if(($manifest['name'] ?? null)!==$adapter->name() || ($manifest['contract_version'] ?? null)!==$adapter->contractVersion()){
			throw new \UnexpectedValueException('Widget runtime adapter manifest identity is inconsistent.');
		}
		$meta=PanelWidgetInteractionValue::assertMap($meta, 'widget runtime adapter provenance', 8192);
		$layers=$this->layers[$alias] ?? [];
		$top=$layers[array_key_last($layers)] ?? null;
		if(is_array($top) && $top['owner']!==$owner){
			if($this->conflictPolicy==='reject'){ throw new \LogicException('Widget runtime adapter conflict for "'.$alias.'" between "'.$top['owner'].'" and "'.$owner.'".'); }
			if($this->conflictPolicy==='keep_first'){ return false; }
		}
		$this->revision++;
		$record=['owner'=>$owner,'adapter'=>$adapter,'manifest'=>$manifest,'meta'=>$meta,'revision'=>$this->revision];
		$replaced=null;
		if(is_array($top) && $top['owner']===$owner){ $replaced=$top['adapter']; $layers[array_key_last($layers)]=$record; }
		else{ $layers[]=$record; }
		$this->layers[$alias]=$layers;
		ksort($this->layers, SORT_STRING);
		if($replaced instanceof PanelWidgetRuntimeAdapter && $replaced!==$adapter){ $this->resetUnreferenced([$replaced]); }
		return true;
	}

	public function adapter(string $alias): ?PanelWidgetRuntimeAdapter {
		$alias=strtolower(trim($alias));
		$layers=$this->layers[$alias] ?? [];
		$record=$layers[array_key_last($layers)] ?? null;
		return is_array($record) && $record['adapter'] instanceof PanelWidgetRuntimeAdapter ? $record['adapter'] : null;
	}

	public function has(string $alias): bool { return $this->adapter($alias) instanceof PanelWidgetRuntimeAdapter; }

	public function unregisterContributor(string $owner): self {
		$owner=PanelWidgetInteractionValue::safeIdentifier($owner, 'Widget runtime adapter owner', 64);
		$removed=[];
		foreach(array_keys($this->layers) as $alias){
			foreach($this->layers[$alias] as $record){ if($record['owner']===$owner){ $removed[]=$record['adapter']; } }
			$this->layers[$alias]=array_values(array_filter($this->layers[$alias], static fn(array $record): bool=>$record['owner']!==$owner));
			if($this->layers[$alias]===[]){ unset($this->layers[$alias]); }
		}
		$this->revision++;
		$this->resetUnreferenced($removed);
		return $this;
	}

	/**
	 * Explicitly issues or replays the initial enhancement snapshot.
	 *
	 * This method is intentionally not called by Widget::toArray(), state(), or
	 * manifest(). Renderer entry points invoke it once per deterministic island.
	 */
	public function mount(PanelWidgetInteractionDefinition $definition, PanelInstance $panel, PanelRequest $request, string $surface, string $islandId): PanelWidgetInteractionResult {
		try{
			$context=$this->context($panel, $request, $surface);
			$idempotency='mount-'.substr(hash('sha256', $definition->fingerprint()."\0".$islandId."\0".$context->bindingTag()), 0, 48);
			return $this->dispatch($definition, $context, PanelWidgetInteractionRequest::mount($islandId, $idempotency));
		}
		catch(PanelWidgetInteractionException $failure){ return PanelWidgetInteractionResult::failure($definition->adapter(), $islandId, $failure); }
		catch(\Throwable){ return PanelWidgetInteractionResult::failure($definition->adapter(), $islandId, new PanelWidgetInteractionException('widget_context_unavailable', 'Interactive updates are unavailable.', 503, true)); }
	}

	public function dispatch(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		$adapter=$this->adapter($definition->adapter());
		if(!$adapter instanceof PanelWidgetRuntimeAdapter){
			return PanelWidgetInteractionResult::failure($definition->adapter(), $request->islandId(), new PanelWidgetInteractionException('widget_adapter_unavailable', 'Interactive updates are unavailable.', 503, true));
		}
		if($request->operation()!=='mount' && !$context->acceptsBindingTag($request->bindingTag())){
			return PanelWidgetInteractionResult::failure($definition->adapter(), $request->islandId(), new PanelWidgetInteractionException('widget_scope_mismatch', 'The widget session is no longer valid.', 409));
		}
		try{ $result=$adapter->handle($definition, $context, $request); }
		catch(\Throwable){ return PanelWidgetInteractionResult::failure($definition->adapter(), $request->islandId(), new PanelWidgetInteractionException('widget_adapter_failure', 'The widget could not be updated.', 500, true)); }
		if($result->adapter()!==$adapter->name() || $result->islandId()!==$request->islandId()){
			return PanelWidgetInteractionResult::failure($definition->adapter(), $request->islandId(), new PanelWidgetInteractionException('widget_adapter_contract_violation', 'Interactive updates are unavailable.', 503));
		}
		return $result;
	}

	public function context(PanelInstance $panel, PanelRequest $request, string $surface): PanelWidgetInteractionContext {
		$panelName=$panel->name();
		if($panelName===''){ throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require a named Panel surface.', 503); }
		$surface=PanelWidgetInteractionValue::safeIdentifier($surface, 'Widget surface scope', 128);
		$scope=$this->scopeResolver instanceof \Closure ? ($this->scopeResolver)($panel, $request, $surface) : self::defaultScope($request);
		if(!is_array($scope) || array_is_list($scope)){
			throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require an authenticated host scope.', 403);
		}
		$unknown=array_diff(array_keys($scope), ['principal','tenant','session','attributes']);
		if($unknown!==[]){ throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require a valid host scope.', 403); }
		$principal=self::scopeString($scope['principal'] ?? null, 'principal', true);
		$tenant=self::scopeString($scope['tenant'] ?? $request->tenantKey(), 'tenant', false);
		$session=self::scopeString($scope['session'] ?? null, 'session', false);
		$attributes=$scope['attributes'] ?? [];
		if(!is_array($attributes)){ throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require a valid host scope.', 403); }
		$claims=['panel'=>$panelName,'surface'=>$surface,'principal'=>$principal,'tenant'=>$tenant,'session'=>$session];
		$tags=[];
		foreach($this->bindingKeys as $id=>$key){ $tags[]=$id.'.'.self::base64Url(hash_hmac('sha256', PanelWidgetInteractionValue::canonical($claims), $key, true)); }
		$currentIndex=array_search($this->currentKeyId, array_keys($this->bindingKeys), true);
		$current=$tags[is_int($currentIndex) ? $currentIndex : 0];
		return PanelWidgetInteractionContext::trusted($panelName, $surface, $principal, $tenant, $session, $request, $current, $tags, $attributes);
	}

	/** @return array<string,mixed> Internal rollback checkpoint; never a public manifest. */
	public function checkpoint(): array { return ['layers'=>$this->layers,'revision'=>$this->revision,'conflict_policy'=>$this->conflictPolicy]; }

	/** @param array<string,mixed> $checkpoint */
	public function restore(array $checkpoint): self {
		if(array_keys($checkpoint)!==['layers','revision','conflict_policy'] || !is_array($checkpoint['layers']) || !is_int($checkpoint['revision']) || !is_string($checkpoint['conflict_policy'])){
			throw new \InvalidArgumentException('Invalid widget runtime registry checkpoint.');
		}
		foreach($checkpoint['layers'] as $alias=>$layers){
			if(!is_string($alias) || !is_array($layers)){ throw new \InvalidArgumentException('Invalid widget runtime registry checkpoint.'); }
			foreach($layers as $record){
				if(!is_array($record) || !(($record['adapter'] ?? null) instanceof PanelWidgetRuntimeAdapter) || !is_array($record['manifest'] ?? null)){
					throw new \InvalidArgumentException('Invalid widget runtime registry checkpoint.');
				}
			}
		}
		$removed=$this->adapters();
		$this->layers=$checkpoint['layers'];
		$this->revision=$checkpoint['revision'];
		$this->conflictPolicy=self::policy($checkpoint['conflict_policy']);
		$this->resetUnreferenced($removed);
		return $this;
	}

	/** Clears all registrations and adapter lifecycle state without touching another instance. */
	public function reset(): void {
		foreach($this->adapters() as $adapter){ $this->bestEffortReset($adapter); }
		$this->layers=[];
		$this->revision++;
	}

	/** @return list<array<string,mixed>> */
	public function provenance(): array {
		$records=[];
		foreach($this->layers as $alias=>$layers){
			foreach($layers as $record){ $records[]=['alias'=>$alias,'adapter'=>$record['adapter']->name(),'owner'=>$record['owner'],'revision'=>$record['revision'],'meta'=>$record['meta']]; }
		}
		usort($records, static fn(array $a,array $b): int=>[$a['alias'],$a['revision']]<=>[$b['alias'],$b['revision']]);
		return $records;
	}

	public function fingerprint(): string {
		$active=[];
		foreach($this->layers as $alias=>$layers){
			$record=$layers[array_key_last($layers)] ?? null;
			if(is_array($record)){ $active[$alias]=['owner'=>$record['owner'],'adapter'=>$record['manifest']]; }
		}
		return hash('sha256', PanelWidgetInteractionValue::canonical($active));
	}

	public function manifest(): array {
		$adapters=[];
		$reactorBridge=false;
		foreach($this->layers as $alias=>$layers){
			$record=$layers[array_key_last($layers)] ?? null;
			if(is_array($record)){
				$adapters[$alias]=['owner'=>$record['owner'],'manifest'=>$record['manifest']];
				if(($record['manifest']['capabilities']['production_reactor_bridge'] ?? false)===true){ $reactorBridge=true; }
			}
		}
		return [
			'type'=>'panel_widget_runtime_registry',
			'contract_version'=>1,
			'revision'=>$this->revision,
			'cleanup_failures'=>$this->cleanupFailures,
			'conflict_policy'=>$this->conflictPolicy,
			'adapters'=>$adapters,
			'fingerprint'=>$this->fingerprint(),
			'capabilities'=>[
				'instance_scoped'=>true,
				'trusted_host_scope'=>true,
				'rotating_binding_keys'=>true,
				'persistent_binding_keys'=>$this->persistentKeys,
				'reactor_bridge'=>$reactorBridge,
			],
		];
	}

	public function jsonSerialize(): array { return $this->manifest(); }

	/** @return list<PanelWidgetRuntimeAdapter> */
	private function adapters(): array {
		$adapters=[];
		foreach($this->layers as $layers){
			foreach($layers as $record){ $adapters[spl_object_id($record['adapter'])]=$record['adapter']; }
		}
		return array_values($adapters);
	}

	/** @param list<PanelWidgetRuntimeAdapter> $candidates */
	private function resetUnreferenced(array $candidates): void {
		$retained=[];
		foreach($this->adapters() as $adapter){ $retained[spl_object_id($adapter)]=true; }
		$reset=[];
		foreach($candidates as $adapter){
			$id=spl_object_id($adapter);
			if(isset($retained[$id]) || isset($reset[$id])){ continue; }
			$reset[$id]=true;
			$this->bestEffortReset($adapter);
		}
	}

	private function bestEffortReset(PanelWidgetRuntimeAdapter $adapter): void {
		try{ $adapter->reset(); }
		catch(\Throwable){ $this->cleanupFailures++; }
	}

	private static function policy(string $policy): string {
		$policy=strtolower(trim($policy));
		if(!in_array($policy, self::POLICIES, true)){ throw new \InvalidArgumentException('Widget runtime conflict policy must be reject, keep_first, or replace.'); }
		return $policy;
	}

	/** @return array<string,mixed> */
	private static function defaultScope(PanelRequest $request): array {
		$principal=self::principal($request->user());
		return ['principal'=>$principal,'tenant'=>$request->tenantKey(),'session'=>null,'attributes'=>[]];
	}

	private static function principal(mixed $user): ?string {
		if(is_string($user) || is_int($user)){ return trim((string)$user) ?: null; }
		if(is_array($user)){
			foreach(['id','user_id','principal_id','sub'] as $key){ if(is_string($user[$key] ?? null) || is_int($user[$key] ?? null)){ return trim((string)$user[$key]) ?: null; } }
		}
		if(is_object($user)){
			foreach(['getAuthIdentifier','getId','id'] as $method){
				if(method_exists($user, $method)){ try{ $value=$user->{$method}(); }catch(\Throwable){ continue; } if(is_string($value) || is_int($value)){ return trim((string)$value) ?: null; } }
			}
			foreach(['id','user_id'] as $property){ if(isset($user->{$property}) && (is_string($user->{$property}) || is_int($user->{$property}))){ return trim((string)$user->{$property}) ?: null; } }
		}
		return null;
	}

	private static function scopeString(mixed $value, string $label, bool $required): ?string {
		if($value===null && !$required){ return null; }
		if(!is_string($value) && !is_int($value)){ throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require a valid host '.$label.' scope.', 403); }
		$value=trim((string)$value);
		if($value==='' || strlen($value)>192 || preg_match('//u', $value)!==1){ throw new PanelWidgetInteractionException('widget_scope_unavailable', 'Interactive updates require a valid host '.$label.' scope.', 403); }
		return $value;
	}

	private static function base64Url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}
