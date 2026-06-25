<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/**
 * Defines a Reactor component for client round-trips, rendering, and effects.
 *
 * The component stores default state, locked server-owned fields, signed action
 * parameters, validators, lifecycle hooks, client bindings, child slots, and
 * renderer metadata so hydration and serialized manifests describe the same contract.
 */
final class ReactorComponent implements \JsonSerializable {

	private string $name;
	/** @var array<string,mixed> */
	private array $state=[];
	/** @var array<string,bool> */
	private array $locked=[];
	/** @var array<string,array<string,mixed>> */
	private array $lockedParams=[];
	private bool $signedParamsRequired=false;
	private mixed $renderer=null;
	private ?\Closure $hydrate=null;
	private ?\Closure $dehydrate=null;
	private ?\Closure $authorize=null;
	private array|\Closure $rules=[];
	/** @var array<string,string> */
	private array $messages=[];
	private bool|array $validateActions=false;
	private bool|array $validateUpdates=false;
	/** @var array<string, callable> */
	private array $actions=[];
	/** @var array<string, callable> */
	private array $computed=[];
	/** @var array<string, list<callable>> */
	private array $updating=[];
	/** @var array<string, list<callable>> */
	private array $updated=[];
	/** @var array<string, list<callable>> */
	private array $lifecycle=[];
	/** @var array<string, string> */
	private array $listeners=[];
	/** @var array<string,array<string,mixed>> */
	private array $urlBindings=[];
	/** @var array<string,array<string,mixed>> */
	private array $persistBindings=[];
	/** @var array<string,array<string,mixed>> */
	private array $sessionBindings=[];
	/** @var array<string,array<string,mixed>> */
	private array $modelBindings=[];
	/** @var array<string,array<string,mixed>> */
	private array $children=[];

	/**
	 * Initializes the component with the normalized registry/client name.
	 *
	 * Construction stays private so component definitions always pass through
	 * make() or fromArray(), preserving the same normalization path for fluent
	 * PHP definitions and array-driven manifests.
	 *
	 * @param string $name Raw component name supplied by application code or a definition array.
	 */
	private function __construct(string $name) {
		$this->name=ReactorName::normalize($name);
	}

	/**
	 * Creates a component definition with normalized name, state, locks, hooks, bindings, children, and renderer metadata.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $name Component, action, computed property, event, or binding name after Reactor normalization.
	 * @return self The same component instance for fluent configuration.
	 */
	public static function make(string $name): self {
		return new self($name);
	}

	/**
	 * Creates a component definition with normalized name, state, locks, hooks, bindings, children, and renderer metadata.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $definition Array definition accepted by component factories and manifests.
	 * @return self The same component instance for fluent configuration.
	 */
	public static function fromArray(array $definition): self {
		$component=self::make((string)($definition['name'] ?? ''));
		if(is_array($definition['state'] ?? null)){
			$component->state($definition['state']);
		}
		if(is_array($definition['locked'] ?? null)){
			$component->locked($definition['locked']);
		}
		if(isset($definition['locked_params'])){
			$component->lockedParams($definition['locked_params']);
		}
		if(($definition['require_signed_params'] ?? false)===true){
			$component->requireSignedParams();
		}
		if(isset($definition['render'])){
			$component->render($definition['render']);
		}
		if(is_callable($definition['authorize'] ?? null)){
			$component->authorize($definition['authorize']);
		}
		if(is_array($definition['rules'] ?? null)){
			$component->rules(
				$definition['rules'],
				is_array($definition['messages'] ?? null) ? $definition['messages'] : [],
				$definition['validate_actions'] ?? true
			);
		}
		if(isset($definition['validate_updates'])){
			$component->validateOnUpdate($definition['validate_updates']);
		}
		foreach(is_array($definition['actions'] ?? null) ? $definition['actions'] : [] as $name=>$action){
			if(is_callable($action)){
				$component->action((string)$name, $action);
			}
		}
		foreach(is_array($definition['computed'] ?? null) ? $definition['computed'] : [] as $name=>$computed){
			if(is_callable($computed)){
				$component->computed((string)$name, $computed);
			}
		}
		foreach(is_array($definition['updating'] ?? null) ? $definition['updating'] : [] as $name=>$callback){
			if(is_callable($callback)){
				$component->updating((string)$name, $callback);
			}
		}
		foreach(is_array($definition['updated'] ?? null) ? $definition['updated'] : [] as $name=>$callback){
			if(is_callable($callback)){
				$component->updated((string)$name, $callback);
			}
		}
		foreach(is_array($definition['lifecycle'] ?? null) ? $definition['lifecycle'] : [] as $event=>$callback){
			if(is_callable($callback)){
				$component->lifecycle((string)$event, $callback);
			}
		}
		foreach(is_array($definition['listeners'] ?? null) ? $definition['listeners'] : [] as $event=>$action){
			if(is_string($action) || is_callable($action)){
				$component->listen((string)$event, $action);
			}
		}
		if(isset($definition['models'])){
			$component->models($definition['models']);
		}
		if(isset($definition['model'])){
			$component->models($definition['model']);
		}
		if(isset($definition['url'])){
			$component->url($definition['url']);
		}
		if(isset($definition['persist'])){
			$component->persist($definition['persist']);
		}
		if(isset($definition['session'])){
			$component->session($definition['session']);
		}
		if(is_array($definition['children'] ?? null)){
			$component->children($definition['children']);
		}
		return $component;
	}

	/**
	 * Returns the normalized component name used for registry lookup and client messages.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return string Normalized name, signed JSON payload, rendered HTML, slot marker, or stable scalar representation.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Merges default server-side state into the component definition.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @return self The same component instance for fluent configuration.
	 */
	public function state(array $state): self {
		$this->state=array_replace($this->state, $state);
		return $this;
	}

	/**
	 * Defines or reports server-authoritative state and action parameters that client requests cannot freely mutate.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $keys State keys that must remain server-authoritative across requests.
	 * @return self The same component instance for fluent configuration.
	 */
	public function locked(array|string $keys): self {
		$keys=is_array($keys) ? $keys : [$keys];
		foreach($keys as $key){
			$key=trim((string)$key);
			if($key!==''){
				$this->locked[$key]=true;
			}
		}
		return $this;
	}

	/**
	 * Defines or reports server-authoritative state and action parameters that client requests cannot freely mutate.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $params Action parameter payload, optionally signed and locked for trust verification.
	 * @return self The same component instance for fluent configuration.
	 */
	public function lockedParams(array|string $params): self {
		$params=is_array($params) ? $params : [$params];
		foreach($params as $param=>$expected){
			if(is_int($param)){
				$param=(string)$expected;
				$expected=null;
			}
			$param=self::normalizeFieldPath((string)$param);
			if($param===''){
				continue;
			}
			$this->lockedParams[$param]=self::normalizeLockedParamExpectation($param, $expected);
		}
		return $this;
	}

	/**
	 * Creates or resolves signed action-parameter payloads that bind params to component and action scope.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param bool $required Whether unsigned action parameters should be rejected.
	 * @return self The same component instance for fluent configuration.
	 */
	public function requireSignedParams(bool $required=true): self {
		$this->signedParamsRequired=$required;
		return $this;
	}

	/**
	 * Creates or resolves signed action-parameter payloads that bind params to component and action scope.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @param array<string,mixed> $params Action parameter payload, optionally signed and locked for trust verification.
	 * @return array{_reactor_signed:array{component:string,action:string,params:array<string,mixed>,signature:string}} Signed parameter envelope for client round-trips.
	 */
	public function signedParams(string $action, array $params): array {
		$action=ReactorName::normalize($action);
		$payload=[
			'component'=>$this->name,
			'action'=>$action,
			'params'=>$params,
		];
		return [
			'_reactor_signed'=>[
				'component'=>$this->name,
				'action'=>$action,
				'params'=>$params,
				'signature'=>ReactorSigner::sign($payload),
			],
		];
	}

	/**
	 * Creates or resolves signed action-parameter payloads that bind params to component and action scope.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @param array<string,mixed> $params Action parameter payload, optionally signed and locked for trust verification.
	 * @return string Normalized name, signed JSON payload, rendered HTML, slot marker, or stable scalar representation.
	 */
	public function signedParamsJson(string $action, array $params): string {
		return json_encode($this->signedParams($action, $params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
	}

	/**
	 * Registers or executes rendering while preserving component state, child slots, and request context.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable|string $renderer Template string or callable renderer used to produce component HTML.
	 * @return self The same component instance for fluent configuration.
	 */
	public function render(callable|string $renderer): self {
		$this->renderer=$renderer;
		return $this;
	}

	/**
	 * Registers or executes hydration and dehydration callbacks around request state.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function hydrate(callable $callback): self {
		$this->hydrate=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Registers or executes hydration and dehydration callbacks around request state.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function dehydrateUsing(callable $callback): self {
		$this->dehydrate=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Registers or executes authorization checks for component requests and action parameters.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function authorize(callable $callback): self {
		$this->authorize=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Configures or executes validation for actions and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|callable $rules Validation rules array or factory callback.
	 * @param array<string,string> $messages Validation message overrides passed to the validator.
	 * @param bool|array|string $actions Action name(s) that should trigger validation.
	 * @return self The same component instance for fluent configuration.
	 */
	public function rules(array|callable $rules, array $messages=[], bool|array|string $actions=true): self {
		$this->rules=is_callable($rules) ? \Closure::fromCallable($rules) : $rules;
		$this->messages=$messages;
		if(is_string($actions)){
			$actions=array_values(array_filter(array_map([ReactorName::class, 'normalize'], explode(',', $actions))));
		}
		$this->validateActions=is_array($actions)
			? array_fill_keys(array_map([ReactorName::class, 'normalize'], $actions), true)
			: $actions;
		return $this;
	}

	/**
	 * Configures or executes validation for actions and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param bool|array|string $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @return self The same component instance for fluent configuration.
	 */
	public function validateOnUpdate(bool|array|string $fields=true): self {
		if(is_string($fields)){
			$fields=array_values(array_filter(array_map([self::class, 'normalizeFieldPath'], explode(',', $fields))));
		}
		$this->validateUpdates=is_array($fields)
			? array_fill_keys(array_map([self::class, 'normalizeFieldPath'], $fields), true)
			: $fields;
		return $this;
	}

	/**
	 * Registers or invokes named component actions and merges their state/effect results.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $name Component, action, computed property, event, or binding name after Reactor normalization.
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function action(string $name, callable $callback): self {
		$name=ReactorName::normalize($name);
		if($name===''){
			throw new \InvalidArgumentException('Reactor action names cannot be empty.');
		}
		$this->actions[$name]=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Registers or applies computed state values before rendering and serialization.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $name Component, action, computed property, event, or binding name after Reactor normalization.
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function computed(string $name, callable $callback): self {
		$name=ReactorName::normalize($name);
		if($name===''){
			throw new \InvalidArgumentException('Reactor computed names cannot be empty.');
		}
		$this->computed[$name]=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Registers or executes model update hooks around incoming field changes.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string|callable $field Single model/state field path.
	 * @param ?callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function updating(string|callable $field, ?callable $callback=null): self {
		return $this->modelHook($this->updating, $field, $callback);
	}

	/**
	 * Registers or executes model update hooks around incoming field changes.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string|callable $field Single model/state field path.
	 * @param ?callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function updated(string|callable $field, ?callable $callback=null): self {
		return $this->modelHook($this->updated, $field, $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string|array $events Lifecycle event name(s) normalized before registration.
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function lifecycle(string|array $events, callable $callback): self {
		foreach(is_array($events) ? $events : [$events] as $event){
			$event=self::normalizeEventName((string)$event);
			if($event===''){
				throw new \InvalidArgumentException('Reactor lifecycle event names cannot be empty.');
			}
			$this->lifecycle[$event][]=\Closure::fromCallable($callback);
		}
		return $this;
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function hydrating(callable $callback): self {
		return $this->lifecycle('hydrating', $callback);
	}

	/**
	 * Registers or executes hydration and dehydration callbacks around request state.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function hydrated(callable $callback): self {
		return $this->lifecycle('hydrated', $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function actionCalling(callable $callback): self {
		return $this->lifecycle('action_calling', $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function actionCalled(callable $callback): self {
		return $this->lifecycle('action_called', $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function rendering(callable $callback): self {
		return $this->lifecycle('rendering', $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function rendered(callable $callback): self {
		return $this->lifecycle('rendered', $callback);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function dehydrating(callable $callback): self {
		return $this->lifecycle('dehydrating', $callback);
	}

	/**
	 * Registers or executes hydration and dehydration callbacks around request state.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param callable $callback Lifecycle, validation, authorization, action, computed, or hook callback.
	 * @return self The same component instance for fluent configuration.
	 */
	public function dehydrated(callable $callback): self {
		return $this->lifecycle('dehydrated', $callback);
	}

	/**
	 * Registers or exposes client event listeners that map browser events to component actions.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $event Client or lifecycle event name.
	 * @param string|callable $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @return self The same component instance for fluent configuration.
	 */
	public function listen(string $event, string|callable $action): self {
		$event=self::normalizeEventName($event);
		if($event===''){
			throw new \InvalidArgumentException('Reactor listener events cannot be empty.');
		}
		if(is_callable($action)){
			$actionName='listener_'.ReactorName::normalize($event);
			$this->action($actionName, $action);
		}
		else{
			$actionName=ReactorName::normalize($action);
			if($actionName===''){
				throw new \InvalidArgumentException('Reactor listener actions cannot be empty.');
			}
		}
		$this->listeners[$event]=$actionName;
		return $this;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @param string $history Browser history mode for URL-bound fields.
	 * @param ?string $as Query-string alias for URL-bound fields.
	 * @param bool $exceptEmpty Whether empty URL-bound fields are omitted from the query string.
	 * @return self The same component instance for fluent configuration.
	 */
	public function url(array|string $fields, string $history='replace', ?string $as=null, bool $exceptEmpty=true): self {
		foreach($this->normalizeFieldBindings($fields, [
			'history'=>$history,
			'as'=>$as,
			'except_empty'=>$exceptEmpty,
		]) as $field=>$binding){
			$binding['history']=in_array($binding['history'] ?? 'replace', ['push', 'replace'], true) ? $binding['history'] : 'replace';
			$binding['as']=self::normalizeQueryName((string)($binding['as'] ?? $field)) ?: $field;
			$binding['except_empty']=($binding['except_empty'] ?? true)!==false;
			$this->urlBindings[$field]=$binding;
		}
		return $this;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @param string $driver Persistence driver such as local or session storage.
	 * @param ?string $key Persistence or session key used to store component state.
	 * @return self The same component instance for fluent configuration.
	 */
	public function persist(array|string $fields, string $driver='local', ?string $key=null): self {
		foreach($this->normalizeFieldBindings($fields, [
			'driver'=>$driver,
			'key'=>$key,
		]) as $field=>$binding){
			$binding['driver']=in_array($binding['driver'] ?? 'local', ['local', 'session'], true) ? $binding['driver'] : 'local';
			$binding['key']=trim((string)($binding['key'] ?? '')) ?: $this->name.'.'.$field;
			$this->persistBindings[$field]=$binding;
		}
		return $this;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @param ?string $key Persistence or session key used to store component state.
	 * @return self The same component instance for fluent configuration.
	 */
	public function session(array|string $fields, ?string $key=null): self {
		foreach($this->normalizeFieldBindings($fields, [
			'key'=>$key,
		]) as $field=>$binding){
			$binding['key']=trim((string)($binding['key'] ?? '')) ?: $this->name.'.'.$field;
			$this->sessionBindings[$field]=$binding;
		}
		return $this;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array|string $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @param string $mode Client update mode: live, blur, change, or defer.
	 * @param int $debounceMs Client-side debounce in milliseconds for model updates.
	 * @return self The same component instance for fluent configuration.
	 */
	public function models(array|string $fields, string $mode='live', int $debounceMs=250): self {
		foreach($this->normalizeFieldBindings($fields, [
			'mode'=>$mode,
			'debounce_ms'=>$debounceMs,
		]) as $field=>$binding){
			$binding['mode']=in_array($binding['mode'] ?? 'live', ['live', 'blur', 'change', 'defer'], true) ? $binding['mode'] : 'live';
			$binding['debounce_ms']=max(0, min(5000, (int)($binding['debounce_ms'] ?? 250)));
			$this->modelBindings[$field]=$binding;
		}
		return $this;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $field Single model/state field path.
	 * @param string $mode Client update mode: live, blur, change, or defer.
	 * @param int $debounceMs Client-side debounce in milliseconds for model updates.
	 * @return self The same component instance for fluent configuration.
	 */
	public function model(string $field, string $mode='live', int $debounceMs=250): self {
		return $this->models([$field=>['mode'=>$mode, 'debounce_ms'=>$debounceMs]]);
	}

	/**
	 * Registers, normalizes, or renders nested child components and slot placeholders.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $slot Child component slot name.
	 * @param ReactorComponent|array|string $component Child component definition, component name, or ReactorComponent instance.
	 * @param array<string,mixed>|callable $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<string,mixed> $attributes HTML/client attributes attached to a child component definition.
	 * @return self The same component instance for fluent configuration.
	 */
	public function child(string $slot, ReactorComponent|array|string $component, array|callable $state=[], array $attributes=[]): self {
		$slot=self::normalizeSlotName($slot);
		if($slot===''){
			throw new \InvalidArgumentException('Reactor child slots require a stable name.');
		}
		$this->children[$slot]=[
			'slot'=>$slot,
			'component'=>$component,
			'state'=>$state,
			'attributes'=>$attributes,
		];
		return $this;
	}

	/**
	 * Registers, normalizes, or renders nested child components and slot placeholders.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string|int,ReactorComponent|array<string,mixed>|string> $children Child slot definitions keyed by slot or containing slot/name fields.
	 * @return self The same component instance for fluent configuration.
	 */
	public function children(array $children): self {
		foreach($children as $slot=>$definition){
			if(is_int($slot) && is_array($definition)){
				$slot=(string)($definition['slot'] ?? $definition['name'] ?? '');
			}
			if(is_array($definition)){
				$component=$definition['component'] ?? $definition['name'] ?? '';
				$state=$definition['state'] ?? [];
				$attributes=is_array($definition['attributes'] ?? null) ? $definition['attributes'] : [];
				$this->child((string)$slot, is_array($component) ? $component : (string)$component, is_callable($state) ? $state : (is_array($state) ? $state : []), $attributes);
				continue;
			}
			$this->child((string)$slot, (string)$definition);
		}
		return $this;
	}

	/**
	 * Registers, normalizes, or renders nested child components and slot placeholders.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $slot Child component slot name.
	 * @param string $fallback Fallback slot marker rendered when no child occupies the slot.
	 * @return string Normalized name, signed JSON payload, rendered HTML, slot marker, or stable scalar representation.
	 */
	public static function childSlot(string $slot, string $fallback=''): string {
		$slot=self::normalizeSlotName($slot);
		return '<div data-dp-reactor-child-slot="'.htmlspecialchars($slot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.$fallback.'</div>';
	}

	/**
	 * Builds the initial component state by merging defaults, session values, incoming state, and computed values.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $incoming Incoming request or session state merged over defaults before lock enforcement.
	 * @return array<string,mixed> Hydrated state after locked defaults, session values, and computed values are applied.
	 */
	public function initialState(array $incoming=[]): array {
		$state=array_replace($this->state, $incoming);
		$state=$this->hydrateSessionState($state, $incoming);
		foreach(array_keys($this->locked) as $key){
			if(self::hasPath($state, $key)){
				continue;
			}
			if(self::hasPath($this->state, $key)){
				self::setPath($state, $key, self::pathValue($this->state, $key));
			}
		}
		if($this->hydrate instanceof \Closure){
			$result=($this->hydrate)($state, $this);
			if(is_array($result)){
				$state=$result;
			}
		}
		return $this->applyComputed($state);
	}

	/**
	 * Defines or reports server-authoritative state and action parameters that client requests cannot freely mutate.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<string,mixed> $trusted Server-trusted state values allowed to replace locked fields.
	 * @return array<string,mixed> State with locked paths restored from trusted or default server values.
	 */
	public function enforceLockedState(array $state, array $trusted=[]): array {
		foreach(array_keys($this->locked) as $key){
			if(self::hasPath($trusted, $key)){
				self::setPath($state, $key, self::pathValue($trusted, $key));
				continue;
			}
			if(self::hasPath($this->state, $key)){
				self::setPath($state, $key, self::pathValue($this->state, $key));
			}
		}
		return $this->applyComputed($state);
	}

	/**
	 * Registers or executes hydration and dehydration callbacks around request state.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @return array<string,mixed> Dehydrated state after optional callback and session persistence.
	 */
	public function dehydrate(array $state): array {
		if($this->dehydrate instanceof \Closure){
			$result=($this->dehydrate)($state, $this);
			if(is_array($result)){
				$state=$result;
			}
		}
		$this->persistSessionState($state);
		return $state;
	}

	/**
	 * Defines or reports server-authoritative state and action parameters that client requests cannot freely mutate.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array<int,string> Locked state paths.
	 */
	public function lockedStateKeys(): array {
		return array_keys($this->locked);
	}

	/**
	 * Defines or reports server-authoritative state and action parameters that client requests cannot freely mutate.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array<int,string> Locked action parameter paths.
	 */
	public function lockedParamKeys(): array {
		return array_keys($this->lockedParams);
	}

	/**
	 * Registers or executes authorization checks for component requests and action parameters.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param ?ReactorRequest $request Reactor request carrying action, params, state, and transport metadata.
	 * @param ?string $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @return array{ok:bool,status:int,message:string} Authorization decision for the component request.
	 */
	public function authorizeRequest(array $state, ?ReactorRequest $request=null, ?string $action=null): array {
		if(!$this->authorize instanceof \Closure){
			return ['ok'=>true, 'status'=>200, 'message'=>''];
		}
		$result=($this->authorize)($state, $request, $this, $action);
		if($result===true || $result===null){
			return ['ok'=>true, 'status'=>200, 'message'=>''];
		}
		if(is_array($result)){
			return [
				'ok'=>($result['ok'] ?? false)===true,
				'status'=>(int)($result['status'] ?? 403),
				'message'=>trim((string)($result['message'] ?? 'This Reactor component request is not authorized.')),
			];
		}
		return [
			'ok'=>false,
			'status'=>403,
			'message'=>is_string($result) && trim($result)!=='' ? trim($result) : 'This Reactor component request is not authorized.',
		];
	}

	/**
	 * Registers or executes authorization checks for component requests and action parameters.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<string,mixed> $params Action parameter payload, optionally signed and locked for trust verification.
	 * @param ?string $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @return array{ok:bool,status:int,message:string} Authorization decision for locked action parameters.
	 */
	public function authorizeActionParams(array $state, array $params, ?string $action=null): array {
		if($this->lockedParams===[]){
			return ['ok'=>true, 'status'=>200, 'message'=>''];
		}
		foreach($this->lockedParams as $param=>$expectation){
			if(!self::hasPath($params, $param)){
				ReactorTrace::record('params.locked_missing', [
					'component'=>$this->name,
					'action'=>$action,
					'param'=>$param,
				]);
				return [
					'ok'=>false,
					'status'=>419,
					'message'=>'A locked Reactor action parameter is missing.',
				];
			}
			$actual=self::pathValue($params, $param);
			if(($expectation['source'] ?? '')==='state'){
				$statePath=(string)($expectation['path'] ?? '');
				if($statePath==='' || !self::hasPath($state, $statePath)){
					ReactorTrace::record('params.locked_unresolvable', [
						'component'=>$this->name,
						'action'=>$action,
						'param'=>$param,
						'state_path'=>$statePath,
					]);
					return [
						'ok'=>false,
						'status'=>419,
						'message'=>'A locked Reactor action parameter could not be verified.',
					];
				}
				$expected=self::pathValue($state, $statePath);
			}
			else{
				$expected=$expectation['value'] ?? null;
			}
			if(!self::valuesMatch($actual, $expected)){
				ReactorTrace::record('params.locked_failed', [
					'component'=>$this->name,
					'action'=>$action,
					'param'=>$param,
				]);
				return [
					'ok'=>false,
					'status'=>419,
					'message'=>'A locked Reactor action parameter was changed by the client.',
				];
			}
		}
		ReactorTrace::record('params.locked_passed', [
			'component'=>$this->name,
			'action'=>$action,
			'params'=>array_keys($this->lockedParams),
		]);
		return ['ok'=>true, 'status'=>200, 'message'=>''];
	}

	/**
	 * Creates or resolves signed action-parameter payloads that bind params to component and action scope.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $params Action parameter payload, optionally signed and locked for trust verification.
	 * @param ?string $action Action name used for validation, authorization, lifecycle, or signed-parameter scope.
	 * @return array{ok:bool,status:int,message:string,params:array<string,mixed>} Verified action parameters or a signature failure result.
	 */
	public function resolveSignedActionParams(array $params, ?string $action=null): array {
		$signed=is_array($params['_reactor_signed'] ?? null) ? $params['_reactor_signed'] : null;
		unset($params['_reactor_signed']);
		if($signed===null){
			if($this->signedParamsRequired){
				ReactorTrace::record('params.signature_missing', [
					'component'=>$this->name,
					'action'=>$action,
				]);
				return [
					'ok'=>false,
					'status'=>419,
					'message'=>'Signed Reactor action parameters are required.',
					'params'=>$params,
				];
			}
			return ['ok'=>true, 'status'=>200, 'message'=>'', 'params'=>$params];
		}
		$signedComponent=ReactorName::normalize((string)($signed['component'] ?? ''));
		$signedAction=ReactorName::normalize((string)($signed['action'] ?? ''));
		$signedParams=is_array($signed['params'] ?? null) ? $signed['params'] : [];
		$signature=(string)($signed['signature'] ?? '');
		$payload=[
			'component'=>$signedComponent,
			'action'=>$signedAction,
			'params'=>$signedParams,
		];
		if(
			$signedComponent!==$this->name
			|| $signedAction!==ReactorName::normalize((string)$action)
			|| !ReactorSigner::verify($payload, $signature)
		){
			ReactorTrace::record('params.signature_failed', [
				'component'=>$this->name,
				'action'=>$action,
				'signed_component'=>$signedComponent,
				'signed_action'=>$signedAction,
			]);
			return [
				'ok'=>false,
				'status'=>419,
				'message'=>'Signed Reactor action parameters are invalid.',
				'params'=>$params,
			];
		}
		ReactorTrace::record('params.signature_passed', [
			'component'=>$this->name,
			'action'=>$action,
			'params'=>array_keys($signedParams),
		]);
		return [
			'ok'=>true,
			'status'=>200,
			'message'=>'',
			'params'=>array_replace($params, $signedParams),
		];
	}

	/**
	 * Configures or executes validation for actions and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param ?ReactorEffects $effects Effect collector for redirects, emits, validation errors, and client instructions.
	 * @param ?array<int,string> $fields Model or state field path(s) bound to URL, storage, validation, or client updates.
	 * @return array<string,array<int,string>> Validation errors keyed by field path.
	 */
	public function validate(array $state, ?ReactorEffects $effects=null, ?array $fields=null): array {
		$rules=$this->validationRules($state, $fields);
		if($rules===[]){
			ReactorTrace::record('validation.skipped', ['component'=>$this->name]);
			return [];
		}
		$errors=ReactorValidator::validate($state, $rules, $this->messages);
		if($errors!==[] && $effects instanceof ReactorEffects){
			$effects->errors($errors);
		}
		ReactorTrace::record($errors===[] ? 'validation.passed' : 'validation.failed', [
			'component'=>$this->name,
			'fields'=>array_keys($rules),
			'errors'=>array_keys($errors),
		]);
		return $errors;
	}

	/**
	 * Configures or executes validation for actions and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<int,array<string,mixed>> $changes Model update changes received from the client.
	 * @param ReactorEffects $effects Effect collector for redirects, emits, validation errors, and client instructions.
	 * @return array<string,array<int,string>> Validation errors keyed by field path.
	 */
	public function validateModelUpdates(array $state, array $changes, ReactorEffects $effects): array {
		$fields=$this->modelUpdateValidationFields($changes);
		if($fields===[]){
			return [];
		}
		$rules=$this->validationRules($state, $fields);
		if($rules===[]){
			ReactorTrace::record('validation.update_skipped', ['component'=>$this->name, 'fields'=>$fields]);
			return [];
		}
		$errors=ReactorValidator::validate($state, $rules, $this->messages);
		$effects->errors($errors);
		ReactorTrace::record($errors===[] ? 'validation.update_passed' : 'validation.update_failed', [
			'component'=>$this->name,
			'fields'=>array_keys($rules),
			'errors'=>array_keys($errors),
		]);
		return $errors;
	}

	/**
	 * Registers or invokes named component actions and merges their state/effect results.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $name Component, action, computed property, event, or binding name after Reactor normalization.
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<string,mixed> $params Action parameter payload, optionally signed and locked for trust verification.
	 * @param ?ReactorEffects $effects Effect collector for redirects, emits, validation errors, and client instructions.
	 * @return array<string,mixed> State after action result merging and computed values.
	 */
	public function callAction(string $name, array $state, array $params=[], ?ReactorEffects $effects=null): array {
		$name=ReactorName::normalize($name);
		if(!isset($this->actions[$name])){
			throw new \InvalidArgumentException('Unknown Reactor action: '.$name);
		}
		$effects ??= ReactorEffects::make();
		if($this->shouldValidateAction($name) && $this->validate($state, $effects)!==[]){
			ReactorTrace::record('action.validation_failed', ['component'=>$this->name, 'action'=>$name]);
			return $this->applyComputed($state);
		}
		ReactorTrace::record('action.call', ['component'=>$this->name, 'action'=>$name]);
		$result=($this->actions[$name])($state, $params, $this, $effects);
		$state=$this->applyActionResult($state, $result, $effects);
		return $this->applyComputed($state);
	}

	/**
	 * Registers or executes model update hooks around incoming field changes.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<int,array<string,mixed>> $changes Model update changes received from the client.
	 * @param ReactorEffects $effects Effect collector for redirects, emits, validation errors, and client instructions.
	 * @param ?ReactorRequest $request Reactor request carrying action, params, state, and transport metadata.
	 * @return array<string,mixed> State after update hooks and computed values.
	 */
	public function applyModelLifecycle(array $state, array $changes, ReactorEffects $effects, ?ReactorRequest $request=null): array {
		if($changes===[]){
			return $state;
		}
		foreach($changes as $change){
			$state=$this->runModelHooks($this->updating, $state, $change, $effects, $request);
		}
		foreach($changes as $change){
			$state=$this->runModelHooks($this->updated, $state, $change, $effects, $request);
		}
		return $this->applyComputed($state);
	}

	/**
	 * Registers or runs lifecycle callbacks for hydration, actions, rendering, and dehydration.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param string $event Client or lifecycle event name.
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param array<string,mixed> $context Lifecycle context payload describing action, request, change, or render phase.
	 * @param ?ReactorEffects $effects Effect collector for redirects, emits, validation errors, and client instructions.
	 * @return array<string,mixed> State after lifecycle callbacks and computed values.
	 */
	public function runLifecycle(string $event, array $state, array $context=[], ?ReactorEffects $effects=null): array {
		$event=self::normalizeEventName($event);
		if($event==='' || empty($this->lifecycle[$event])){
			return $state;
		}
		$effects ??= ReactorEffects::make();
		foreach($this->lifecycle[$event] as $callback){
			ReactorTrace::record('lifecycle.'.$event, [
				'component'=>$this->name,
				'context'=>array_keys($context),
			]);
			$result=$callback($state, $context, $this, $effects);
			$state=$this->applyLifecycleResult($state, $result, $effects);
		}
		return $this->applyComputed($state);
	}

	/**
	 * Registers or executes rendering while preserving component state, child slots, and request context.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @param array<string,mixed> $state Component state map carried between hydration, actions, validation, rendering, and dehydration.
	 * @param ?ReactorRequest $request Reactor request carrying action, params, state, and transport metadata.
	 * @param ?ReactorManager $manager Optional Reactor manager used to resolve child components and rendering services.
	 * @return string Normalized name, signed JSON payload, rendered HTML, slot marker, or stable scalar representation.
	 */
	public function renderHtml(array $state, ?ReactorRequest $request=null, ?ReactorManager $manager=null): string {
		if(is_callable($this->renderer)){
			$result=($this->renderer)($state, $this, $request);
			return $this->renderChildSlots((string)$result, $state, $request, $manager);
		}
		if(is_string($this->renderer) && $this->renderer!==''){
			if(class_exists('\Dataphyre\Templating\Templating') && class_exists('\dataphyre\templating', false)){
				return $this->renderChildSlots(\Dataphyre\Templating\Templating::renderString($this->renderer, $state, [], [], 'dataphyre.reactor.component.tpl')->content(), $state, $request, $manager);
			}
			return $this->renderChildSlots($this->interpolate($this->renderer, $state), $state, $request, $manager);
		}
		return '';
	}

	/**
	 * Registers or exposes client event listeners that map browser events to component actions.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array<string,string> Browser event names mapped to action names.
	 */
	public function clientListeners(): array {
		return $this->listeners;
	}

	/**
	 * Configures or exposes client bindings for URL state, persistence, session state, and model updates.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array{models?:array<string,array<string,mixed>>,url?:array<string,array<string,mixed>>,persist?:array<string,array<string,mixed>>} Client binding metadata keyed by binding type.
	 */
	public function clientBindings(): array {
		return array_filter([
			'models'=>$this->modelBindings,
			'url'=>$this->urlBindings,
			'persist'=>$this->persistBindings,
		]);
	}

	/**
	 * Registers, normalizes, or renders nested child components and slot placeholders.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array<string,array{slot:string,component:string,state_resolver:bool,state_keys:array<int,string|int>,attributes:array<int,string|int>}> Child component definitions keyed by slot.
	 */
	public function childDefinitions(): array {
		$children=[];
		foreach($this->children as $slot=>$definition){
			$component=$definition['component'] ?? '';
			if($component instanceof ReactorComponent){
				$componentName=$component->name();
			}
			elseif(is_array($component)){
				$componentName=ReactorName::normalize((string)($component['name'] ?? ''));
			}
			else{
				$componentName=ReactorName::normalize((string)$component);
			}
			$children[$slot]=[
				'slot'=>$slot,
				'component'=>$componentName,
				'state_resolver'=>is_callable($definition['state'] ?? null),
				'state_keys'=>is_array($definition['state'] ?? null) ? array_keys($definition['state']) : [],
				'attributes'=>array_keys(is_array($definition['attributes'] ?? null) ? $definition['attributes'] : []),
			];
		}
		return $children;
	}

	/**
	 * Serializes the component definition for manifests and hydration payloads.
	 *
	 * ReactorComponent is both a definition object and the runtime contract for client round-trips: locked state, signed params, hooks, validators, effects, child slots, and render output must stay deterministic.
	 *
	 * @return array{name:string,state_keys:array<int,string|int>,locked:array<int,string>,locked_params:array<int,string>,signed_params_required:bool,actions:array<int,string>,computed:array<int,string>,authorized:bool,rules:array<int,string>,updating:array<int,string>,updated:array<int,string>,lifecycle:array<int,string>,listeners:array<string,string>,url:array<int,string>,persist:array<int,string>,session:array<int,string>,models:array<int,string>,children:array<string,array<string,mixed>>,validate_updates:bool|array<int,string>} Serialized component definition.
	 */
	public function jsonSerialize(): array {
		return [
			'name'=>$this->name,
			'state_keys'=>array_keys($this->state),
			'locked'=>array_keys($this->locked),
			'locked_params'=>array_keys($this->lockedParams),
			'signed_params_required'=>$this->signedParamsRequired,
			'actions'=>array_keys($this->actions),
			'computed'=>array_keys($this->computed),
			'authorized'=>$this->authorize instanceof \Closure,
			'rules'=>array_keys($this->validationRules($this->state)),
			'updating'=>array_keys($this->updating),
			'updated'=>array_keys($this->updated),
			'lifecycle'=>array_keys($this->lifecycle),
			'listeners'=>$this->listeners,
			'url'=>array_keys($this->urlBindings),
			'persist'=>array_keys($this->persistBindings),
			'session'=>array_keys($this->sessionBindings),
			'models'=>array_keys($this->modelBindings),
			'children'=>$this->childDefinitions(),
			'validate_updates'=>$this->validateUpdates===true ? true : ($this->validateUpdates===false ? false : array_keys($this->validateUpdates)),
		];
	}

	/**
	 * Merges session-backed fields into state when the client did not send them.
	 *
	 * Session bindings are server-owned persistence hints. Incoming payload values
	 * win for fields explicitly present in the request, while absent fields can be
	 * restored from the Reactor session bucket without marking the client trusted.
	 *
	 * @param array<string,mixed> $state Current component state after default/incoming merge.
	 * @param array<string,mixed> $incoming Client-provided state data.
	 * @return array<string,mixed> State with missing session-bound paths hydrated.
	 */
	private function hydrateSessionState(array $state, array $incoming): array {
		if($this->sessionBindings===[] || self::ensureSession()!==true){
			return $state;
		}
		$bucket=is_array($_SESSION['dataphyre_reactor'] ?? null) ? $_SESSION['dataphyre_reactor'] : [];
		foreach($this->sessionBindings as $field=>$binding){
			if(self::hasPath($incoming, $field)){
				continue;
			}
			$key=(string)($binding['key'] ?? '');
			if($key!=='' && array_key_exists($key, $bucket)){
				self::setPath($state, $field, $bucket[$key]);
				ReactorTrace::record('session.hydrated', ['component'=>$this->name, 'field'=>$field]);
			}
		}
		return $state;
	}

	/**
	 * Persists configured session-bound fields after dehydration.
	 *
	 * Only fields that exist in current state are written. The session bucket is
	 * namespaced to Reactor so component state does not collide with application
	 * session keys, and each write is traced for debugging persistence behavior.
	 *
	 * @param array<string,mixed> $state Component state after actions, hooks, and computed values.
	 * @return void
	 */
	private function persistSessionState(array $state): void {
		if($this->sessionBindings===[] || self::ensureSession()!==true){
			return;
		}
		if(!isset($_SESSION['dataphyre_reactor']) || !is_array($_SESSION['dataphyre_reactor'])){
			$_SESSION['dataphyre_reactor']=[];
		}
		foreach($this->sessionBindings as $field=>$binding){
			$key=(string)($binding['key'] ?? '');
			if($key==='' || !self::hasPath($state, $field)){
				continue;
			}
			$_SESSION['dataphyre_reactor'][$key]=self::pathValue($state, $field);
			ReactorTrace::record('session.persisted', ['component'=>$this->name, 'field'=>$field]);
		}
	}

	/**
	 * Replaces simple template placeholders with escaped state values.
	 *
	 * This fallback renderer supports dotted state paths inside `{{ }}` markers
	 * when the full Dataphyre templating module is unavailable. Missing paths
	 * render as empty strings and all scalar output is escaped for HTML.
	 *
	 * @param string $template Component template string.
	 * @param array<string,mixed> $state State available to the template.
	 * @return string Rendered HTML with escaped interpolated values.
	 */
	private function interpolate(string $template, array $state): string {
		return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', static function(array $match) use ($state): string {
			$value=$state;
			foreach(explode('.', $match[1]) as $key){
				if(!is_array($value) || !array_key_exists($key, $value)){
					return '';
				}
				$value=$value[$key];
			}
			return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}, $template) ?? $template;
	}

	/**
	 * Mounts configured child components into named slots.
	 *
	 * Child output can replace a text marker (`{{ reactor:slot }}`) or an element
	 * carrying `data-dp-reactor-child-slot`. If neither marker is present, the
	 * child is appended so a declared child is never silently dropped.
	 *
	 * @param string $html Parent component HTML.
	 * @param array<string,mixed> $state Parent state used by child state resolvers.
	 * @param ?ReactorRequest $request Current Reactor request context.
	 * @param ?ReactorManager $manager Manager responsible for mounting child components.
	 * @return string HTML with child slots rendered.
	 */
	private function renderChildSlots(string $html, array $state, ?ReactorRequest $request, ?ReactorManager $manager): string {
		if($this->children===[] || !$manager instanceof ReactorManager){
			return $html;
		}
		foreach($this->children as $slot=>$definition){
			$childHtml=$manager->mountChild($this, $slot, $definition, $state, $request);
			$placed=false;
			$marker='{{ reactor:'.$slot.' }}';
			if(str_contains($html, $marker)){
				$html=str_replace($marker, $childHtml, $html);
				$placed=true;
			}
			$pattern='/<([a-zA-Z][a-zA-Z0-9:_-]*)([^>]*)data-dp-reactor-child-slot=(["\'])'.preg_quote($slot, '/').'\\3([^>]*)>.*?<\\/\\1>/s';
			$replaced=preg_replace($pattern, $childHtml, $html, -1, $count);
			if(is_string($replaced) && $count>0){
				$html=$replaced;
				$placed=true;
			}
			if(!$placed){
				$html.=$childHtml;
			}
		}
		return $html;
	}

	/**
	 * Applies registered computed properties to the state snapshot.
	 *
	 * Computed callbacks run after hydration, action/lifecycle changes, and model
	 * hooks so serialized and rendered state expose derived values consistently.
	 * A computed value overwrites any existing state key with the same normalized
	 * name.
	 *
	 * @param array<string,mixed> $state Current component state.
	 * @return array<string,mixed> State with computed keys applied.
	 */
	private function applyComputed(array $state): array {
		foreach($this->computed as $name=>$callback){
			$state[$name]=$callback($state, $this);
		}
		return $state;
	}

	/**
	 * Merges an action callback result into state and effects.
	 *
	 * Action callbacks may return a ReactorEffects object, a plain state patch, or
	 * an envelope containing `state` and `effects`. Non-array, non-effects results
	 * are ignored so commands can signal side effects exclusively through the
	 * provided ReactorEffects collector.
	 *
	 * @param array<string,mixed> $state State before the action result.
	 * @param mixed $result Action callback return value.
	 * @param ReactorEffects $effects Effect collector to merge returned effects into.
	 * @return array<string,mixed> Updated state after applying the result contract.
	 */
	private function applyActionResult(array $state, mixed $result, ReactorEffects $effects): array {
		if($result instanceof ReactorEffects){
			$effects->merge($result->all());
			return $state;
		}
		if(!is_array($result)){
			return $state;
		}
		if(array_key_exists('effects', $result) || array_key_exists('state', $result)){
			if(is_array($result['effects'] ?? null)){
				$effects->merge($result['effects']);
			}
			return is_array($result['state'] ?? null) ? array_replace($state, $result['state']) : $state;
		}
		return array_replace($state, $result);
	}

	/**
	 * Merges a lifecycle callback result into state and effects.
	 *
	 * Lifecycle callbacks share the same result envelope as actions, allowing
	 * hooks to patch state or emit effects without mutating the collector directly.
	 * Unknown return shapes leave state unchanged.
	 *
	 * @param array<string,mixed> $state State before the lifecycle result.
	 * @param mixed $result Lifecycle callback return value.
	 * @param ReactorEffects $effects Effect collector to merge returned effects into.
	 * @return array<string,mixed> Updated state after applying the lifecycle contract.
	 */
	private function applyLifecycleResult(array $state, mixed $result, ReactorEffects $effects): array {
		if($result instanceof ReactorEffects){
			$effects->merge($result->all());
			return $state;
		}
		if(!is_array($result)){
			return $state;
		}
		if(array_key_exists('effects', $result) || array_key_exists('state', $result)){
			if(is_array($result['effects'] ?? null)){
				$effects->merge($result['effects']);
			}
			return is_array($result['state'] ?? null) ? array_replace($state, $result['state']) : $state;
		}
		return array_replace($state, $result);
	}

	/**
	 * Resolves the validation rule map for the current state and optional fields.
	 *
	 * Rule callbacks receive state and the component definition, then must return
	 * an array. Field-scoped validation filters the full rule set so model updates
	 * validate only the changed paths and their nested/parent rule relationships.
	 *
	 * @param array<string,mixed> $state Current component state.
	 * @param ?array<int,string> $fields Optional field paths to validate.
	 * @return array<string,mixed> Validation rules applicable to the request.
	 */
	private function validationRules(array $state, ?array $fields=null): array {
		if($this->rules instanceof \Closure){
			$rules=($this->rules)($state, $this);
			$rules=is_array($rules) ? $rules : [];
			return $fields===null ? $rules : self::filterRulesForFields($rules, $fields);
		}
		$rules=is_array($this->rules) ? $this->rules : [];
		return $fields===null ? $rules : self::filterRulesForFields($rules, $fields);
	}

	/**
	 * Determines whether an action should run full component validation.
	 *
	 * Validation can be enabled for every action or for an allow-list of normalized
	 * action names. Disabled validation lets command-style actions rely on their
	 * own guards while model updates still use validateOnUpdate().
	 *
	 * @param string $action Normalized action name.
	 * @return bool Whether action validation should execute before the callback.
	 */
	private function shouldValidateAction(string $action): bool {
		if($this->validateActions===true){
			return true;
		}
		return is_array($this->validateActions) && isset($this->validateActions[$action]);
	}

	/**
	 * Extracts the changed model fields that should trigger update validation.
	 *
	 * The validator can watch every model change or a configured field allow-list.
	 * Empty or invalid field paths are ignored to keep malformed client change
	 * records from expanding validation scope.
	 *
	 * @param array<int,array<string,mixed>> $changes Client model-change records.
	 * @return array<int,string> Normalized field paths requiring validation.
	 */
	private function modelUpdateValidationFields(array $changes): array {
		if($this->validateUpdates===false || $changes===[]){
			return [];
		}
		$fields=[];
		foreach($changes as $change){
			$field=self::normalizeFieldPath((string)($change['field'] ?? ''));
			if($field===''){
				continue;
			}
			if($this->validateUpdates===true || isset($this->validateUpdates[$field])){
				$fields[$field]=true;
			}
		}
		return array_keys($fields);
	}

	/**
	 * Filters validation rules to fields affected by a model update.
	 *
	 * Parent and child path relationships are included so updating `address.city`
	 * can trigger rules on `address` and updating `address` can trigger nested
	 * rules. Invalid requested fields produce an empty rule map.
	 *
	 * @param array<string,mixed> $rules Full validation rule map.
	 * @param array<int,string> $fields Changed or requested field paths.
	 * @return array<string,mixed> Rule subset relevant to the field paths.
	 */
	private static function filterRulesForFields(array $rules, array $fields): array {
		$fields=array_values(array_filter(array_map([self::class, 'normalizeFieldPath'], $fields)));
		if($fields===[]){
			return [];
		}
		$filtered=[];
		foreach($rules as $ruleField=>$ruleSet){
			$ruleField=self::normalizeFieldPath((string)$ruleField);
			foreach($fields as $field){
				if($ruleField===$field || str_starts_with($ruleField.'.', $field.'.') || str_starts_with($field.'.', $ruleField.'.')){
					$filtered[$ruleField]=$ruleSet;
					break;
				}
			}
		}
		return $filtered;
	}

	/**
	 * Registers an updating or updated model hook.
	 *
	 * Passing only a callable registers a wildcard hook for all fields. Named hooks
	 * are stored under the exact field key supplied by the definition so callers can
	 * use `*` deliberately without it being normalized away.
	 *
	 * @param array<string,list<callable>> $bucket Hook bucket being configured.
	 * @param string|callable $field Field name, wildcard, or callback shorthand.
	 * @param ?callable $callback Hook callback when the field is supplied separately.
	 * @return self The same component instance for fluent configuration.
	 */
	private function modelHook(array &$bucket, string|callable $field, ?callable $callback): self {
		if(is_callable($field) && $callback===null){
			$callback=$field;
			$field='*';
		}
		$field=trim((string)$field);
		if($field==='' || $callback===null){
			throw new \InvalidArgumentException('Reactor model hooks require a field and callback.');
		}
		$bucket[$field][]=\Closure::fromCallable($callback);
		return $this;
	}

	/**
	 * Runs matching model hooks for one client change record.
	 *
	 * Field-specific hooks execute before wildcard hooks. Hook results may return a
	 * state patch array, which is merged into current state before the next hook
	 * runs so later hooks see prior changes.
	 *
	 * @param array<string,list<callable>> $hooks Hook bucket to run.
	 * @param array<string,mixed> $state Current component state.
	 * @param array<string,mixed> $change Client change record containing field and value.
	 * @param ReactorEffects $effects Effect collector shared across hooks.
	 * @param ?ReactorRequest $request Current Reactor request context.
	 * @return array<string,mixed> State after hook patches.
	 */
	private function runModelHooks(array $hooks, array $state, array $change, ReactorEffects $effects, ?ReactorRequest $request): array {
		$field=(string)($change['field'] ?? '');
		foreach(array_merge($hooks[$field] ?? [], $hooks['*'] ?? []) as $callback){
			$result=$callback($change['value'] ?? null, $state, $change, $this, $effects, $request);
			if(is_array($result)){
				$state=array_replace($state, $result);
			}
		}
		return $state;
	}

	/**
	 * Normalizes a lifecycle event name for storage and dispatch.
	 *
	 * Event names allow alphanumeric, dot, colon, underscore, and dash separators
	 * so lifecycle phases and browser-style event namespaces can share one map.
	 *
	 * @param string $event Raw lifecycle event name.
	 * @return string Safe event name, or an empty string when invalid.
	 */
	private static function normalizeEventName(string $event): string {
		$event=trim($event);
		return preg_match('/^[a-zA-Z0-9_.:-]+$/', $event)===1 ? $event : '';
	}

	/**
	 * Normalizes a child slot name for placeholder matching.
	 *
	 * @param string $slot Raw child slot name.
	 * @return string Safe slot name, or an empty string when invalid.
	 */
	private static function normalizeSlotName(string $slot): string {
		$slot=trim($slot);
		return preg_match('/^[a-zA-Z0-9_.:-]+$/', $slot)===1 ? $slot : '';
	}

	/**
	 * Converts field binding declarations into normalized binding metadata.
	 *
	 * String declarations use defaults directly. Array declarations may map fields
	 * to config arrays, aliases, or positional field names, and invalid field paths
	 * are skipped so client binding manifests stay safe to serialize.
	 *
	 * @param array|string $fields Field declaration from url, persist, session, or model configuration.
	 * @param array<string,mixed> $defaults Default binding options for the binding type.
	 * @return array<string,array<string,mixed>> Binding metadata keyed by normalized field path.
	 */
	private function normalizeFieldBindings(array|string $fields, array $defaults): array {
		if(is_string($fields)){
			$fields=[$fields=>$defaults];
		}
		$bindings=[];
		foreach($fields as $field=>$config){
			if(is_int($field)){
				$field=(string)$config;
				$config=[];
			}
			elseif(is_string($config)){
				$config=['as'=>$config, 'key'=>$config];
			}
			$field=self::normalizeFieldPath((string)$field);
			if($field===''){
				continue;
			}
			$bindings[$field]=array_replace($defaults, is_array($config) ? $config : []);
		}
		return $bindings;
	}

	/**
	 * Normalizes one locked action-parameter expectation.
	 *
	 * Locked params can mirror a state path, explicitly reference `state:path`, or
	 * compare against a literal value. The normalized envelope lets authorization
	 * validate client-sent params without interpreting arbitrary structures later.
	 *
	 * @param string $param Normalized parameter path.
	 * @param mixed $expected Definition-provided expectation.
	 * @return array<string,mixed> Expectation envelope with source and path/value keys.
	 */
	private static function normalizeLockedParamExpectation(string $param, mixed $expected): array {
		if($expected===null){
			return ['source'=>'state', 'path'=>$param];
		}
		if(is_string($expected) && str_starts_with($expected, 'state:')){
			return ['source'=>'state', 'path'=>self::normalizeFieldPath(substr($expected, 6))];
		}
		if(is_array($expected) && ($expected['source'] ?? null)==='state'){
			return ['source'=>'state', 'path'=>self::normalizeFieldPath((string)($expected['path'] ?? $param))];
		}
		if(is_array($expected) && array_key_exists('value', $expected)){
			return ['source'=>'literal', 'value'=>$expected['value']];
		}
		return ['source'=>'literal', 'value'=>$expected];
	}

	/**
	 * Compares client and expected values using Reactor's stable-value semantics.
	 *
	 * Scalars compare by string value to tolerate transport coercion. Arrays and
	 * objects compare through stableValue() so key order does not matter for nested
	 * arrays.
	 *
	 * @param mixed $actual Client-provided value.
	 * @param mixed $expected Trusted expected value.
	 * @return bool Whether the values match the locked-parameter contract.
	 */
	private static function valuesMatch(mixed $actual, mixed $expected): bool {
		if(is_scalar($actual) && is_scalar($expected)){
			return (string)$actual===(string)$expected;
		}
		return self::stableValue($actual)===self::stableValue($expected);
	}

	/**
	 * Serializes a value deterministically for equality checks and signing support.
	 *
	 * Nested arrays are key-sorted recursively before JSON encoding. Serialization
	 * is used only as a fallback when JSON cannot represent the value.
	 *
	 * @param mixed $value Value to normalize.
	 * @return string Stable serialized representation.
	 */
	private static function stableValue(mixed $value): string {
		if(is_array($value)){
			ksort($value);
			foreach($value as $key=>$item){
				if(is_array($item)){
					$value[$key]=json_decode(self::stableValue($item), true);
				}
			}
		}
		$encoded=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		return is_string($encoded) ? $encoded : serialize($value);
	}

	/**
	 * Normalizes a state/model field path.
	 *
	 * Field paths are restricted to alphanumeric segments and lightweight
	 * separators because they flow into client binding metadata, URL query names,
	 * validation field maps, and dotted state traversal.
	 *
	 * @param string $field Raw field path.
	 * @return string Safe field path, or an empty string when invalid.
	 */
	private static function normalizeFieldPath(string $field): string {
		$field=trim($field);
		return preg_match('/^[a-zA-Z0-9_.:-]+$/', $field)===1 ? $field : '';
	}

	/**
	 * Normalizes a URL query binding name.
	 *
	 * @param string $name Raw query parameter name.
	 * @return string Safe query parameter name, or an empty string when invalid.
	 */
	private static function normalizeQueryName(string $name): string {
		$name=trim($name);
		return preg_match('/^[a-zA-Z0-9_.:-]+$/', $name)===1 ? $name : '';
	}

	/**
	 * Ensures a PHP session is available for Reactor session bindings.
	 *
	 * The method avoids starting a session after headers have been sent and records
	 * trace entries for unavailable session state so hydration/persistence failures
	 * can be diagnosed without throwing during component rendering.
	 *
	 * @return bool Whether session storage can be read and written.
	 */
	private static function ensureSession(): bool {
		if(session_status()===PHP_SESSION_ACTIVE){
			return true;
		}
		if(headers_sent()){
			ReactorTrace::record('session.unavailable', ['reason'=>'headers_sent']);
			return false;
		}
		try{
			return @session_start()===true || session_status()===PHP_SESSION_ACTIVE;
		}
		catch(\Throwable $exception){
			ReactorTrace::record('session.unavailable', ['reason'=>$exception->getMessage()]);
			return false;
		}
	}

	/**
	 * Checks whether a dotted path exists in an array state tree.
	 *
	 * Existence is based on array_key_exists(), so null values still count as
	 * present. Missing intermediate arrays stop traversal and return false.
	 *
	 * @param array<string,mixed> $state State tree to inspect.
	 * @param string $path Dotted field path.
	 * @return bool Whether the full path exists.
	 */
	private static function hasPath(array $state, string $path): bool {
		$value=$state;
		foreach(explode('.', $path) as $segment){
			if(!is_array($value) || !array_key_exists($segment, $value)){
				return false;
			}
			$value=$value[$segment];
		}
		return true;
	}

	/**
	 * Reads a dotted path from an array state tree.
	 *
	 * Null is returned for missing paths, matching the value used when optional
	 * locked params or session fields cannot be resolved.
	 *
	 * @param array<string,mixed> $state State tree to inspect.
	 * @param string $path Dotted field path.
	 * @return mixed Path value, or null when the path is unavailable.
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
	 * Writes a dotted path into an array state tree.
	 *
	 * Missing intermediate segments are created as arrays. Empty path segments are
	 * ignored before traversal so malformed paths cannot create blank keys.
	 *
	 * @param array<string,mixed> $state State tree mutated by reference.
	 * @param string $path Dotted field path.
	 * @param mixed $value Value to write.
	 * @return void
	 */
	private static function setPath(array &$state, string $path, mixed $value): void {
		$segments=array_values(array_filter(explode('.', $path), static fn(string $segment): bool => $segment!==''));
		$cursor=&$state;
		foreach($segments as $index=>$segment){
			if($index===count($segments)-1){
				$cursor[$segment]=$value;
				return;
			}
			if(!isset($cursor[$segment]) || !is_array($cursor[$segment])){
				$cursor[$segment]=[];
			}
			$cursor=&$cursor[$segment];
		}
	}
}
