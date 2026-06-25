<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Runtime registry and dispatcher for Reactor components.
 *
 * The manager owns component registration, configured component lazy loading,
 * snapshot creation, mount rendering, child component mounting, request
 * authorization, model update detection, action execution, lifecycle hooks,
 * effects, and trace emission for one Reactor runtime context.
 */
final class ReactorManager {

	/**
	 * Registered components keyed by normalized component name.
	 *
	 * @var array<string, ReactorComponent>
	 */
	private array $components=[];
	/** @var int Current recursive mount depth used to prevent runaway child rendering. */
	private int $mountDepth=0;

	/**
	 * Creates a component builder without registering it.
	 *
	 * Supplying a renderer attaches it to the new component immediately. Call
	 * {@see register()} when the component should be available for snapshots,
	 * mounts, dispatches, or manifests.
	 *
	 * @param string $name Component name before normalization.
	 * @param callable|string|null $renderer Optional renderer callback or template reference.
	 * @return ReactorComponent Unregistered component definition.
	 */
	public function component(string $name, callable|string|null $renderer=null): ReactorComponent {
		$component=ReactorComponent::make($name);
		if($renderer!==null){
			$component->render($renderer);
		}
		return $component;
	}

	/**
	 * Registers a component in this manager.
	 *
	 * Array definitions are converted through {@see ReactorComponent::fromArray()}.
	 * Component names must normalize to a non-empty key. Later registrations with
	 * the same name replace earlier definitions.
	 *
	 * @param ReactorComponent|array<string, mixed> $component Component object or declarative component definition.
	 * @return ReactorComponent Registered component.
	 *
	 * @throws \InvalidArgumentException When the component has no stable name.
	 */
	public function register(ReactorComponent|array $component): ReactorComponent {
		$component=is_array($component) ? ReactorComponent::fromArray($component) : $component;
		$name=$component->name();
		if($name===''){
			throw new \InvalidArgumentException('Reactor components require a stable name.');
		}
		$this->components[$name]=$component;
		ReactorTrace::record('component.registered', ['component'=>$name]);
		return $component;
	}

	/**
	 * Indicates whether a component is registered in this manager.
	 *
	 * @param string $name Component name before normalization.
	 * @return bool True when the component exists in the local registry.
	 */
	public function has(string $name): bool {
		return isset($this->components[ReactorName::normalize($name)]);
	}

	/**
	 * Resolves a registered component by normalized name.
	 *
	 * @param string $name Component name before normalization.
	 * @return ?ReactorComponent Registered component, or null when absent.
	 */
	public function get(string $name): ?ReactorComponent {
		return $this->components[ReactorName::normalize($name)] ?? null;
	}

	/**
	 * Returns every component currently registered in the manager.
	 *
	 * @return array<string, ReactorComponent> Component registry keyed by normalized name.
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * Builds the client and documentation manifest for registered components.
	 *
	 * The manifest generator receives this manager so it can inspect component
	 * metadata while keeping serialization logic centralized.
	 *
	 * @return array<string, mixed> Reactor manifest payload.
	 */
	public function manifest(): array {
		ReactorTrace::record('manifest.generated', ['components'=>count($this->components)]);
		return ReactorManifest::manager($this);
	}

	/**
	 * Creates a signed state snapshot for a component.
	 *
	 * Registered components are resolved first, followed by configured component
	 * lazy loading. Initial state is merged through the component and locked state
	 * keys are embedded for later request enforcement.
	 *
	 * @param string $component Component name to snapshot.
	 * @param array<string, mixed> $state Initial state overrides.
	 * @return ReactorSnapshot Snapshot for the requested component.
	 *
	 * @throws \InvalidArgumentException When the component cannot be resolved.
	 */
	public function snapshot(string $component, array $state=[]): ReactorSnapshot {
		$component=$this->get($component) ?? $this->loadConfiguredComponent($component);
		if(!$component instanceof ReactorComponent){
			throw new \InvalidArgumentException('Unknown Reactor component.');
		}
		return ReactorSnapshot::make($component->name(), $component->initialState($state), $component->lockedStateKeys());
	}

	/**
	 * Renders server-side mount markup for a component.
	 *
	 * Mounting runs hydrating, hydrated, dehydrating, dehydrated, rendering, and
	 * rendered lifecycle hooks, creates a snapshot from dehydrated state, renders
	 * component HTML, injects listener/binding attributes when absent, and wraps
	 * everything through {@see ReactorView::mount()}.
	 *
	 * @param string $component Component name to mount.
	 * @param array<string, mixed> $state Initial state overrides.
	 * @param array<string, mixed> $attributes HTML attributes for the mount wrapper.
	 * @return string Mount markup containing rendered HTML and snapshot metadata.
	 *
	 * @throws \InvalidArgumentException When the component cannot be resolved.
	 */
	public function mount(string $component, array $state=[], array $attributes=[]): string {
		$component=$this->get($component) ?? $this->loadConfiguredComponent($component);
		if(!$component instanceof ReactorComponent){
			throw new \InvalidArgumentException('Unknown Reactor component.');
		}
		$effects=ReactorEffects::make();
		$state=$component->runLifecycle('hydrating', $state, ['stage'=>'mount'], $effects);
		$state=$component->initialState($state);
		$state=$component->runLifecycle('hydrated', $state, ['stage'=>'mount'], $effects);
		$state=$component->runLifecycle('dehydrating', $state, ['stage'=>'mount'], $effects);
		$dehydratedState=$component->dehydrate($state);
		$dehydratedState=$component->runLifecycle('dehydrated', $dehydratedState, ['stage'=>'mount'], $effects);
		$snapshot=ReactorSnapshot::make($component->name(), $dehydratedState, $component->lockedStateKeys());
		$state=$component->runLifecycle('rendering', $state, ['stage'=>'mount'], $effects);
		$html=$component->renderHtml($state, null, $this);
		$component->runLifecycle('rendered', $state, ['stage'=>'mount', 'html_length'=>strlen($html)], $effects);
		if($component->clientListeners()!==[] && !isset($attributes['data-dp-reactor-listeners'])){
			$attributes['data-dp-reactor-listeners']=json_encode($component->clientListeners(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		}
		foreach($component->clientBindings() as $type=>$bindings){
			$attribute='data-dp-reactor-'.$type;
			if(!isset($attributes[$attribute])){
				$attributes[$attribute]=json_encode($bindings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
			}
		}
		return ReactorView::mount($component->name(), $html, $snapshot, $attributes);
	}

	/**
	 * Mounts a child component inside a parent slot.
	 *
	 * Child definitions may reference an existing component, inline component
	 * array, component object, or configured component name. Recursive rendering
	 * is capped to protect requests from runaway child graphs; failures are
	 * traced and returned as HTML comments instead of throwing into templates.
	 *
	 * @param ReactorComponent $parent Parent component rendering the slot.
	 * @param string $slot Slot name used for trace and DOM metadata.
	 * @param array<string, mixed> $definition Child component definition.
	 * @param array<string, mixed> $parentState Parent state supplied to state callbacks.
	 * @param ?ReactorRequest $request Current Reactor request, when mounting during dispatch.
	 * @return string Child mount markup or diagnostic HTML comment.
	 */
	public function mountChild(ReactorComponent $parent, string $slot, array $definition, array $parentState=[], ?ReactorRequest $request=null): string {
		if($this->mountDepth>16){
			ReactorTrace::record('child.depth_exceeded', [
				'parent'=>$parent->name(),
				'slot'=>$slot,
			]);
			return '<!-- Reactor child depth exceeded: '.htmlspecialchars($slot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' -->';
		}
		$componentDefinition=$definition['component'] ?? '';
		if($componentDefinition instanceof ReactorComponent){
			$child=$this->register($componentDefinition);
		}
		elseif(is_array($componentDefinition)){
			$child=$this->register($componentDefinition);
		}
		else{
			$child=$this->get((string)$componentDefinition) ?? $this->loadConfiguredComponent((string)$componentDefinition);
		}
		if(!$child instanceof ReactorComponent){
			ReactorTrace::record('child.missing', [
				'parent'=>$parent->name(),
				'slot'=>$slot,
				'component'=>is_scalar($componentDefinition) ? (string)$componentDefinition : get_debug_type($componentDefinition),
			]);
			return '<!-- Reactor child missing: '.htmlspecialchars($slot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').' -->';
		}
		$stateDefinition=$definition['state'] ?? [];
		if(is_callable($stateDefinition)){
			$childState=$stateDefinition($parentState, $request, $parent, $slot);
			$childState=is_array($childState) ? $childState : [];
		}
		else{
			$childState=is_array($stateDefinition) ? $stateDefinition : [];
		}
		$attributes=is_array($definition['attributes'] ?? null) ? $definition['attributes'] : [];
		$attributes['data-dp-reactor-parent']=$parent->name();
		$attributes['data-dp-reactor-slot']=$slot;
		ReactorTrace::record('child.mount', [
			'parent'=>$parent->name(),
			'slot'=>$slot,
			'component'=>$child->name(),
			'state_keys'=>array_keys($childState),
		]);
		$this->mountDepth++;
		try{
			return $this->mount($child->name(), $childState, $attributes);
		}
		finally{
			$this->mountDepth=max(0, $this->mountDepth-1);
		}
	}

	/**
	 * Dispatches a Reactor request and returns a structured response.
	 *
	 * Dispatch verifies snapshots, resolves components, enforces locked state,
	 * runs hydration/model/action/render/dehydration lifecycles, authorizes
	 * request and action parameters, applies effects, emits trace spans, and
	 * converts runtime failures into Reactor error responses.
	 *
	 * @param ReactorRequest|array<string, mixed>|null $request Request object, array payload, or null for defaults.
	 * @return ReactorResponse Structured response containing HTML, state, metadata, effects, or error information.
	 */
	public function dispatch(ReactorRequest|array|null $request=null): ReactorResponse {
		$request=$request instanceof ReactorRequest ? $request : ReactorRequest::from($request);
		$span=ReactorTrace::begin('request.dispatch', [
			'component'=>$request->component(),
			'action'=>$request->action(),
			'state_keys'=>array_keys($request->state()),
			'param_keys'=>array_keys($request->params()),
			'uploads'=>count($request->uploads()),
		]);
		try{
			$component=$this->get($request->component()) ?? $this->loadConfiguredComponent($request->component());
			if(!$component instanceof ReactorComponent){
				ReactorTrace::record('component.missing', ['component'=>$request->component()]);
				return ReactorResponse::error('Component not found.', 404);
			}
			$snapshot=$request->snapshot();
			if($snapshot instanceof ReactorSnapshot && !$snapshot->verify()){
				ReactorTrace::record('snapshot.invalid_signature', ['component'=>$request->component()]);
				return ReactorResponse::error('Component state signature is invalid.', 419);
			}
			if($snapshot instanceof ReactorSnapshot && $snapshot->component()!==$component->name()){
				ReactorTrace::record('snapshot.component_mismatch', [
					'requested'=>$component->name(),
					'snapshot'=>$snapshot->component(),
				]);
				return ReactorResponse::error('Component state belongs to a different Reactor component.', 419);
			}
			$effects=ReactorEffects::make();
			$previousState=$snapshot instanceof ReactorSnapshot ? $snapshot->state() : [];
			$incomingState=$snapshot instanceof ReactorSnapshot
				? array_replace($snapshot->state(), $request->state())
				: $request->state();
			$state=$component->runLifecycle('hydrating', $incomingState, [
				'stage'=>'request',
				'request'=>$request,
				'previous_state'=>$previousState,
			], $effects);
			$state=$component->enforceLockedState($component->initialState($state), $previousState);
			$state=$component->runLifecycle('hydrated', $state, [
				'stage'=>'request',
				'request'=>$request,
				'previous_state'=>$previousState,
			], $effects);
			$authorization=$component->authorizeRequest($state, $request, $request->action());
			if(($authorization['ok'] ?? false)!==true){
				ReactorTrace::record('authorization.denied', [
					'component'=>$component->name(),
					'action'=>$request->action(),
					'status'=>(int)$authorization['status'],
				]);
				return ReactorResponse::error((string)$authorization['message'], (int)$authorization['status']);
			}
			$modelChanges=self::modelChanges($previousState, $request->state(), $request->params());
			if($modelChanges!==[]){
				ReactorTrace::record('model.changed', [
					'component'=>$component->name(),
					'fields'=>array_column($modelChanges, 'field'),
				]);
			}
			$state=$component->applyModelLifecycle($state, $modelChanges, $effects, $request);
			$state=$component->enforceLockedState($state, $previousState);
			if($modelChanges!==[] && $request->action()===null){
				$component->validateModelUpdates($state, $modelChanges, $effects);
			}
			if($request->action()!==null){
				$params=$request->params();
				$signedParams=$component->resolveSignedActionParams($params, $request->action());
				if(($signedParams['ok'] ?? false)!==true){
					ReactorTrace::record('authorization.signed_params_denied', [
						'component'=>$component->name(),
						'action'=>$request->action(),
						'status'=>(int)$signedParams['status'],
					]);
					return ReactorResponse::error((string)$signedParams['message'], (int)$signedParams['status']);
				}
				$params=is_array($signedParams['params'] ?? null) ? $signedParams['params'] : $params;
				$paramAuthorization=$component->authorizeActionParams($state, $params, $request->action());
				if(($paramAuthorization['ok'] ?? false)!==true){
					ReactorTrace::record('authorization.params_denied', [
						'component'=>$component->name(),
						'action'=>$request->action(),
						'status'=>(int)$paramAuthorization['status'],
					]);
					return ReactorResponse::error((string)$paramAuthorization['message'], (int)$paramAuthorization['status']);
				}
				$state=$component->runLifecycle('action_calling', $state, [
					'action'=>$request->action(),
					'params'=>$params,
					'request'=>$request,
				], $effects);
				$state=$component->callAction((string)$request->action(), $state, $params, $effects);
				$state=$component->enforceLockedState($state, $previousState);
				$state=$component->runLifecycle('action_called', $state, [
					'action'=>$request->action(),
					'params'=>$params,
					'request'=>$request,
				], $effects);
			}
			$effectPayload=$effects->all();
			$skipRender=($effectPayload['skip_render'] ?? false)===true;
			if($skipRender){
				$html='';
			}
			else{
				$state=$component->runLifecycle('rendering', $state, [
					'stage'=>'request',
					'action'=>$request->action(),
					'request'=>$request,
				], $effects);
				$html=$component->renderHtml($state, $request, $this);
				$state=$component->runLifecycle('rendered', $state, [
					'stage'=>'request',
					'action'=>$request->action(),
					'request'=>$request,
					'html_length'=>strlen($html),
				], $effects);
			}
			$state=$component->runLifecycle('dehydrating', $state, [
				'stage'=>'request',
				'action'=>$request->action(),
				'request'=>$request,
			], $effects);
			$dehydratedState=$component->dehydrate($state);
			$dehydratedState=$component->runLifecycle('dehydrated', $dehydratedState, [
				'stage'=>'request',
				'action'=>$request->action(),
				'request'=>$request,
			], $effects);
			$effectPayload=$effects->all();
			$response=ReactorResponse::ok($html, $dehydratedState, [
				'snapshot'=>ReactorSnapshot::make($component->name(), $dehydratedState, $component->lockedStateKeys())->jsonSerialize(),
				'component'=>$component->name(),
				'action'=>$request->action(),
			]+$effectPayload);
			ReactorTrace::record('response.ready', [
				'component'=>$component->name(),
				'action'=>$request->action(),
				'status'=>$response->status(),
				'effects'=>array_keys($effectPayload),
				'state_keys'=>array_keys($response->state()),
				'skip_render'=>$skipRender,
			]);
			ReactorTrace::end($span, ['status'=>$response->status()]);
			return $response;
		}
		catch(\Throwable $exception){
			ReactorTrace::fail($span, $exception);
			return ReactorResponse::error($exception->getMessage(), 500);
		}
	}

	/**
	 * Loads a component from Reactor configuration and registers it locally.
	 *
	 * Configured components may be component objects, arrays, or callables that
	 * receive a component builder. Unsupported definitions are ignored.
	 *
	 * @param string $name Component name to load.
	 * @return ?ReactorComponent Registered component, or null when no usable definition exists.
	 */
	private function loadConfiguredComponent(string $name): ?ReactorComponent {
		$name=ReactorName::normalize($name);
		$config=Reactor::config('components', []);
		if(!is_array($config) || !isset($config[$name])){
			return null;
		}
		$definition=$config[$name];
		if($definition instanceof ReactorComponent){
			return $this->register($definition);
		}
		if(is_array($definition)){
			$definition['name']=$definition['name'] ?? $name;
			return $this->register($definition);
		}
		if(is_callable($definition)){
			$component=ReactorComponent::make($name);
			$result=$definition($component);
			return $this->register($result instanceof ReactorComponent ? $result : $component);
		}
		return null;
	}

	/**
	 * Computes model field changes between previous and incoming state.
	 *
	 * When request metadata declares a specific model path, only that path is
	 * compared. Otherwise the incoming state is flattened and every changed leaf
	 * value is reported with old value, new value, and client event name.
	 *
	 * @param array<string, mixed> $previous Snapshot state before the request.
	 * @param array<string, mixed> $incoming Incoming state supplied by the request.
	 * @param array<string, mixed> $params Request parameters, including optional `_reactor` metadata.
	 * @return array<int, array{field: string, old: mixed, value: mixed, event: string}> Changed model fields.
	 */
	private static function modelChanges(array $previous, array $incoming, array $params): array {
		$meta=is_array($params['_reactor'] ?? null) ? $params['_reactor'] : [];
		$model=trim((string)($meta['model'] ?? ''));
		if($model!==''){
			$new=self::pathValue($incoming, $model);
			$old=self::pathValue($previous, $model);
			if($new!==$old){
				return [[
					'field'=>$model,
					'old'=>$old,
					'value'=>$new,
					'event'=>trim((string)($meta['event'] ?? '')),
				]];
			}
			return [];
		}
		$changes=[];
		foreach(self::flatten($incoming) as $field=>$value){
			$old=self::pathValue($previous, $field);
			if($value!==$old){
				$changes[]=[
					'field'=>$field,
					'old'=>$old,
					'value'=>$value,
					'event'=>trim((string)($meta['event'] ?? '')),
				];
			}
		}
		return $changes;
	}

	/**
	 * Reads a dot-path value from nested state.
	 *
	 * @param array<string, mixed> $state State array to inspect.
	 * @param string $path Dot-separated state path.
	 * @return mixed Resolved value, or null when any segment is missing.
	 */
	private static function pathValue(array $state, string $path): mixed {
		$value=$state;
		foreach(explode('.', $path) as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){
				return null;
			}
			$value=$value[$segment];
		}
		return $value;
	}

	/**
	 * Flattens nested state into dot-path leaf values.
	 *
	 * @param array<string, mixed> $state State array to flatten.
	 * @param string $prefix Prefix accumulated during recursion.
	 * @return array<string, mixed> Leaf values keyed by dot path.
	 */
	private static function flatten(array $state, string $prefix=''): array {
		$flat=[];
		foreach($state as $key=>$value){
			$path=$prefix==='' ? (string)$key : $prefix.'.'.$key;
			if(is_array($value)){
				$flat+=self::flatten($value, $path);
				continue;
			}
			$flat[$path]=$value;
		}
		return $flat;
	}
}
