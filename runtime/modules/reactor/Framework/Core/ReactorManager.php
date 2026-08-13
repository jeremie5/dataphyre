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
	private int $mountTransactionDepth=0;
	/** @var list<ReactorSnapshot> Uncommitted snapshots created by the current root mount. */
	private array $mountIssuedSnapshots=[];
	/** @var list<array{component:ReactorComponent,state:array<string,mixed>}> */
	private array $mountPendingSessionCommits=[];
	/** @var list<ReactorSecurityContext> Trusted context stack inherited by nested mounts. */
	private array $mountSecurityContexts=[];
	/** @var null|callable(array<string,mixed>,ReactorSecurityContext):(true|false) */
	private $transportAuthorizer=null;
	private ?ReactorSecurityContext $defaultSecurityContext=null;
	private ReactorSnapshotVersionStore $snapshotVersionStore;

	public function __construct(?ReactorSnapshotVersionStore $snapshotVersionStore=null) {
		$configured=Reactor::config('snapshot_version_store');
		if($snapshotVersionStore===null && $configured!==null && !$configured instanceof ReactorSnapshotVersionStore){
			throw new \UnexpectedValueException('Reactor snapshot_version_store must implement ReactorSnapshotVersionStore.');
		}
		$this->snapshotVersionStore=$snapshotVersionStore ?? ($configured instanceof ReactorSnapshotVersionStore ? $configured : new ReactorInMemorySnapshotVersionStore());
	}

	/** Installs the fail-closed host transport/envelope authorizer. */
	public function authorizeTransport(?callable $authorizer): self {
		$this->transportAuthorizer=$authorizer;
		return $this;
	}

	/** Installs trusted host scope inherited by mounts and explicit internal dispatch. */
	public function withHostSecurityContext(ReactorSecurityContext|array|null $context): self {
		$this->defaultSecurityContext=$context===null ? null : ReactorSecurityContext::fromTrusted($context);
		return $this;
	}

	/**
	 * Explicit host decision for server-internal/test transports.
	 *
	 * This does not permit unscoped snapshots: the named audience becomes the
	 * signed scope and the normal CAS ledger still applies.
	 */
	public function trustInternalTransport(string $audience='reactor:trusted-internal'): self {
		$this->defaultSecurityContext=ReactorSecurityContext::forAudience($audience);
		$this->transportAuthorizer=static fn(array $envelope, ReactorSecurityContext $context): bool=>true;
		return $this;
	}

	/** Replaces the atomic snapshot version ledger. */
	public function useSnapshotVersionStore(ReactorSnapshotVersionStore $store): self {
		$this->snapshotVersionStore=$store;
		return $this;
	}

	/** Secret-free transport/snapshot security capabilities for manifests. */
	public function securityManifest(): array {
		$configured=$this->transportAuthorizer!==null || is_callable(Reactor::config('transport_authorizer'));
		return [
			'transport_authorization'=>'fail_closed_pre_hydration',
			'transport_authorizer_configured'=>$configured,
			'pre_hydration_envelope_exposes_state'=>false,
			'snapshot_revocation'=>'authenticated_scope_bound_exact_version_revoke',
			'snapshot_revocation_transport_authorization'=>'fail_closed_immediately_before_revoke',
			'snapshot_revocation_resolves_or_renders_component'=>false,
			'snapshot_revocation_response_exposes_snapshot'=>false,
			'snapshot_revocation_idempotent_replay'=>false,
			'initial_issuance_authorization'=>'fail_closed_before_component_resolution',
			'initial_issuance_operations'=>['mount','snapshot_issue'],
			'domain_authorization_stage'=>'post_hydration',
			'host_context_from_request_payload'=>false,
			'upload_only_requests_are_mutations'=>true,
			'response_serialization_before_snapshot_commit'=>true,
			'mount_snapshot_commit'=>'deferred_after_complete_root_component_tree_render_with_best_effort_partial_rollback',
			'mount_snapshot_commit_atomic'=>false,
			'session_state_commit'=>'post_snapshot_commit_best_effort',
			'snapshot_and_session_state_atomic'=>false,
			'action_side_effect_exactly_once'=>false,
			'action_idempotency_required'=>true,
			'snapshot'=>ReactorSnapshot::manifest(),
			'version_store'=>$this->snapshotVersionStore->manifest(),
		];
	}

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
	public function snapshot(string $component, array $state=[], ReactorSecurityContext|array|null $securityContext=null): ReactorSnapshot {
		$context=$this->resolveSecurityContext($securityContext);
		$this->assertSnapshotStorePolicy();
		$normalized=ReactorName::normalize($component);
		$this->authorizeInitialOperation('snapshot_issue', $normalized, $state, $context);
		$component=$this->get($component) ?? $this->loadConfiguredComponent($component);
		if(!$component instanceof ReactorComponent){
			throw new \InvalidArgumentException('Unknown Reactor component.');
		}
		$initial=$component->initialState($state);
		$request=ReactorRequest::fromArray(['component'=>$component->name()], $context);
		$authorization=$component->authorizeRequest($initial, $request, null);
		if(($authorization['ok'] ?? false)!==true){ throw new \RuntimeException('Reactor component snapshot issuance is not authorized.', (int)($authorization['status'] ?? 403)); }
		$snapshot=$this->createSnapshot($component->name(), $initial, $component->lockedStateKeys(), $context);
		$this->registerSnapshot($snapshot);
		return $snapshot;
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
	public function mount(string $component, array $state=[], array $attributes=[], ReactorSecurityContext|array|null $securityContext=null): string {
		$context=$this->resolveSecurityContext($securityContext);
		$this->assertSnapshotStorePolicy();
		$normalized=ReactorName::normalize($component);
		$this->authorizeInitialOperation('mount', $normalized, $state, $context);
		$rootTransaction=$this->mountTransactionDepth===0;
		if($rootTransaction){
			$this->mountIssuedSnapshots=[];
			$this->mountPendingSessionCommits=[];
		}
		$this->mountTransactionDepth++;
		$component=$this->get($component) ?? $this->loadConfiguredComponent($component);
		if(!$component instanceof ReactorComponent){
			$this->mountTransactionDepth--;
			throw new \InvalidArgumentException('Unknown Reactor component.');
		}
		$this->mountSecurityContexts[]=$context;
		try{
			$effects=ReactorEffects::make();
			$state=$component->runLifecycle('hydrating', $state, ['stage'=>'mount'], $effects);
			$state=$component->initialState($state);
			$state=$component->runLifecycle('hydrated', $state, ['stage'=>'mount'], $effects);
			$request=ReactorRequest::fromArray(['component'=>$component->name()], $context);
			$authorization=$component->authorizeRequest($state, $request, null);
			if(($authorization['ok'] ?? false)!==true){ throw new \RuntimeException('Reactor component mount is not authorized.', (int)($authorization['status'] ?? 403)); }
			$state=$component->runLifecycle('dehydrating', $state, ['stage'=>'mount'], $effects);
			$dehydratedState=$component->dehydrateState($state);
			$dehydratedState=$component->runLifecycle('dehydrated', $dehydratedState, ['stage'=>'mount'], $effects);
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
			$snapshot=$this->createSnapshot($component->name(), $dehydratedState, $component->lockedStateKeys(), $context);
			$markup=ReactorView::mount($component->name(), $html, $snapshot, $attributes);
			$this->mountIssuedSnapshots[]=$snapshot;
			$this->mountPendingSessionCommits[]=['component'=>$component,'state'=>$dehydratedState];
			if($rootTransaction){
				$this->commitMountSnapshots();
				$this->finishMountSnapshotTransaction();
			}
			return $markup;
		}
		catch(\Throwable $error){
			if($rootTransaction){ $this->revokeMountSnapshots(); }
			throw $error;
		}
		finally{ array_pop($this->mountSecurityContexts);
			$this->mountTransactionDepth=max(0, $this->mountTransactionDepth-1);
			if($rootTransaction){
				$this->mountIssuedSnapshots=[];
				$this->mountPendingSessionCommits=[];
			}
		}
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
			$requestContext=$request?->securityContext();
			$inherited=$requestContext instanceof ReactorSecurityContext && $requestContext->isBound()
				? $requestContext
				: ($this->mountSecurityContexts!==[] ? $this->mountSecurityContexts[array_key_last($this->mountSecurityContexts)] : null);
			$markup=$this->mount($child->name(), $childState, $attributes, $inherited);
		}
		finally{
			$this->mountDepth=max(0, $this->mountDepth-1);
		}
		return $markup;
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
		try{ $request=$this->prepareRequest($request); }
		catch(\Throwable){
			return ReactorResponse::error('Reactor host security context is invalid.', 403, [
				'error'=>['code'=>'security_context_invalid','correlation_id'=>self::fallbackCorrelationId()],
			]);
		}
		$span=ReactorTrace::begin('request.dispatch', [
			'component'=>$request->component(),
			'action'=>$request->action(),
			'state_field_count'=>count($request->state()),
			'param_field_count'=>count($request->params()),
			'uploads'=>count($request->uploads()),
			'host_scope_bound'=>$request->securityContext()->isBound(),
		]);
		$issuanceTransactionStarted=false;
		$rootIssuanceTransaction=false;
		$issuanceCommitted=false;
		$issuanceSnapshotOffset=0;
		$issuanceSessionOffset=0;
		try{
			$context=$request->securityContext();
			$reservationId='';
			if(!$context->isBound()){
				return $this->securityDenial($request, $span, 'security_scope_required', 'A trusted Reactor host scope is required.', 403);
			}
			$snapshot=$request->snapshot();
			if($request->snapshotMalformed()){
				return $this->securityDenial($request, $span, 'snapshot_invalid', 'Component state could not be verified.', 419);
			}
			if($snapshot instanceof ReactorSnapshot && !$snapshot->verify($context)){
				return $this->securityDenial($request, $span, 'snapshot_invalid', 'Component state could not be verified.', 419);
			}
			if($snapshot instanceof ReactorSnapshot && $snapshot->component()!==$request->component()){
				return $this->securityDenial($request, $span, 'snapshot_component_mismatch', 'Component state could not be verified.', 419);
			}
			$mutationRequested=$request->action()!==null || $request->state()!==[] || $request->params()!==[] || $request->uploads()!==[];
			if(!$snapshot instanceof ReactorSnapshot && $mutationRequested && self::requiresSignedMutationSnapshot()){
				return $this->securityDenial($request, $span, 'snapshot_required', 'A signed component snapshot is required for this mutation.', 419);
			}
			if($this->productionRequiresSharedStore() && ($this->snapshotVersionStore->manifest()['production_safe'] ?? false)!==true){
				return $this->securityDenial($request, $span, 'snapshot_version_store_required', 'Reactor snapshot concurrency protection is unavailable.', 503);
			}
			$envelope=$this->transportEnvelope($request, $snapshot, $mutationRequested);
			$authorizer=$this->transportAuthorizer;
			if($authorizer===null){
				$configured=Reactor::config('transport_authorizer');
				$authorizer=is_callable($configured) ? $configured : null;
			}
			if($authorizer===null){
				return $this->securityDenial($request, $span, 'transport_security_required', 'Reactor transport authorization is not configured.', 403);
			}
			try{ $transportDecision=$authorizer($envelope, $context); }
			catch(\Throwable){
				return $this->securityDenial($request, $span, 'transport_authorization_unavailable', 'Reactor transport authorization is unavailable.', 503);
			}
			if($transportDecision!==true){
				return $this->securityDenial($request, $span, 'transport_denied', 'The Reactor transport request is not authorized.', 403);
			}
			if($snapshot instanceof ReactorSnapshot && !$snapshot->isLegacy()){
				$reservationId=bin2hex(random_bytes(16));
				$claim=$this->snapshotVersionStore->reserve(
					$snapshot->snapshotId(),
					$snapshot->scopeHash(),
					$snapshot->component(),
					$snapshot->version(),
					$reservationId,
					min(time()+self::reservationTtlSeconds(), $snapshot->expiresAt())
				);
				if($claim!==ReactorSnapshotVersionStore::CLAIMED){
					$expired=$claim===ReactorSnapshotVersionStore::EXPIRED;
					ReactorTrace::record('snapshot.cas_denied', [
						'component'=>$request->component(),
						'action'=>$request->action(),
						'claim_status'=>$claim,
					]);
					return $this->securityDenial(
						$request,
						$span,
						$expired ? 'snapshot_expired' : ($claim===ReactorSnapshotVersionStore::UNAVAILABLE ? 'snapshot_version_store_unavailable' : 'snapshot_stale'),
						$expired ? 'Component state has expired.' : ($claim===ReactorSnapshotVersionStore::UNAVAILABLE ? 'Reactor snapshot concurrency protection is unavailable.' : 'Component state is stale. Refresh and try again.'),
						$expired ? 419 : ($claim===ReactorSnapshotVersionStore::UNAVAILABLE ? 503 : 409)
					);
				}
			}
			ReactorTrace::record('transport.authorized', [
				'component'=>$request->component(),
				'action'=>$request->action(),
				'snapshot_version'=>$snapshot?->version(),
			]);
			$rootIssuanceTransaction=$this->mountTransactionDepth===0;
			if($rootIssuanceTransaction){
				$this->mountIssuedSnapshots=[];
				$this->mountPendingSessionCommits=[];
			}
			$issuanceSnapshotOffset=count($this->mountIssuedSnapshots);
			$issuanceSessionOffset=count($this->mountPendingSessionCommits);
			$this->mountTransactionDepth++;
			$issuanceTransactionStarted=true;
			$component=$this->get($request->component()) ?? $this->loadConfiguredComponent($request->component());
			if(!$component instanceof ReactorComponent){
				$this->abortReservation($snapshot, $reservationId);
				ReactorTrace::record('component.missing', ['component'=>$request->component()]);
				ReactorTrace::end($span, ['status'=>404]);
				return ReactorResponse::error('Component not found.', 404);
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
				$this->abortReservation($snapshot, $reservationId);
				ReactorTrace::record('authorization.denied', [
					'component'=>$component->name(),
					'action'=>$request->action(),
					'status'=>(int)$authorization['status'],
				]);
				ReactorTrace::end($span, ['status'=>(int)$authorization['status'],'domain_authorization'=>'denied']);
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
					$this->abortReservation($snapshot, $reservationId);
					ReactorTrace::record('authorization.signed_params_denied', [
						'component'=>$component->name(),
						'action'=>$request->action(),
						'status'=>(int)$signedParams['status'],
					]);
					ReactorTrace::end($span, ['status'=>(int)$signedParams['status'],'signed_params'=>'denied']);
					return ReactorResponse::error((string)$signedParams['message'], (int)$signedParams['status']);
				}
				$params=is_array($signedParams['params'] ?? null) ? $signedParams['params'] : $params;
				$paramAuthorization=$component->authorizeActionParams($state, $params, $request->action());
				if(($paramAuthorization['ok'] ?? false)!==true){
					$this->abortReservation($snapshot, $reservationId);
					ReactorTrace::record('authorization.params_denied', [
						'component'=>$component->name(),
						'action'=>$request->action(),
						'status'=>(int)$paramAuthorization['status'],
					]);
					ReactorTrace::end($span, ['status'=>(int)$paramAuthorization['status'],'param_authorization'=>'denied']);
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
			$dehydratedState=$component->dehydrateState($state);
			$dehydratedState=$component->runLifecycle('dehydrated', $dehydratedState, [
				'stage'=>'request',
				'action'=>$request->action(),
				'request'=>$request,
			], $effects);
			$effectPayload=$effects->all();
			if($snapshot instanceof ReactorSnapshot && !$snapshot->isLegacy()){
				$nextSnapshot=$snapshot->successor($dehydratedState, $component->lockedStateKeys());
			}
			else{
				$nextSnapshot=$this->createSnapshot($component->name(), $dehydratedState, $component->lockedStateKeys(), $context);
			}
			$response=ReactorResponse::ok($html, $dehydratedState, [
				'snapshot'=>$nextSnapshot->jsonSerialize(),
				'component'=>$component->name(),
				'action'=>$request->action(),
			]+$effectPayload);
			self::validateResponseEnvelope($response);
			if($rootIssuanceTransaction){ $this->commitMountSnapshots(); }
			if($snapshot instanceof ReactorSnapshot && !$snapshot->isLegacy()){
				$finalized=$this->snapshotVersionStore->finalize(
					$snapshot->snapshotId(),
					$snapshot->scopeHash(),
					$snapshot->component(),
					$snapshot->version(),
					$nextSnapshot->version(),
					$nextSnapshot->expiresAt(),
					$reservationId
				);
				if($finalized!==ReactorSnapshotVersionStore::CLAIMED){
					$this->abortReservation($snapshot, $reservationId);
					return $this->securityDenial(
						$request,
						$span,
						$finalized===ReactorSnapshotVersionStore::EXPIRED ? 'snapshot_expired' : 'snapshot_finalize_failed',
						$finalized===ReactorSnapshotVersionStore::EXPIRED ? 'Component state has expired.' : 'Reactor snapshot concurrency protection could not finalize the request.',
						$finalized===ReactorSnapshotVersionStore::EXPIRED ? 419 : 503
					);
				}
			}
			else{
				$this->registerSnapshot($nextSnapshot);
			}
			$issuanceCommitted=true;
			if($rootIssuanceTransaction){ $this->finishMountSnapshotTransaction(); }
			$component->commitSessionState($dehydratedState);
			try{
				ReactorTrace::record('response.ready', [
					'component'=>$component->name(),
					'action'=>$request->action(),
					'status'=>$response->status(),
					'effects'=>array_keys($effectPayload),
					'state_keys'=>array_keys($response->state()),
					'skip_render'=>$skipRender,
				]);
				ReactorTrace::end($span, ['status'=>$response->status()]);
			}
			catch(\Throwable){}
			return $response;
		}
		catch(\Throwable $exception){
			if(isset($snapshot, $reservationId)){ $this->abortReservation($snapshot, $reservationId); }
			try{ ReactorTrace::fail($span, $exception, ['public_code'=>'reactor_request_failed']); }
			catch(\Throwable){}
			return ReactorResponse::error('Reactor request failed.', 500, [
				'error'=>['code'=>'reactor_request_failed','correlation_id'=>$request->securityContext()->correlationId() ?: self::fallbackCorrelationId()],
			]);
		}
		finally{ if($issuanceTransactionStarted){
				if(!$issuanceCommitted){
					if($rootIssuanceTransaction){ $this->revokeMountSnapshots(); }
					else{
						$this->mountIssuedSnapshots=array_slice($this->mountIssuedSnapshots, 0, $issuanceSnapshotOffset);
						$this->mountPendingSessionCommits=array_slice($this->mountPendingSessionCommits, 0, $issuanceSessionOffset);
					}
				}
				$this->mountTransactionDepth=max(0, $this->mountTransactionDepth-1);
				if($rootIssuanceTransaction){
					$this->mountIssuedSnapshots=[];
					$this->mountPendingSessionCommits=[];
				}
			}
		}
	}

	/**
	 * Revokes one authentic, scope-bound snapshot version without resolving,
	 * hydrating, rendering, or invoking its component.
	 *
	 * Authenticity and scope are proven before expiry is classified or the
	 * version store is observed. The host transport policy then receives only a
	 * value-free verification envelope immediately before the exact ledger
	 * revoke. A replay is a stable conflict rather than an idempotent success.
	 */
	public function revokeSnapshot(ReactorSnapshot $snapshot, ReactorSecurityContext|array|null $securityContext=null): ReactorResponse {
		try{ $context=$this->resolveSecurityContext($securityContext); }
		catch(\Throwable){
			return self::snapshotRevokeError('security_context_invalid', 'Reactor host security context is invalid.', 403, null);
		}
		$correlationId=$context->correlationId() ?: self::fallbackCorrelationId();
		if($snapshot->isLegacy() || !$snapshot->verifyAuthenticity($context)){
			return self::snapshotRevokeError('snapshot_invalid', 'Component state could not be verified.', 419, $correlationId);
		}
		if($snapshot->expiresAt()<=time()){
			return self::snapshotRevokeError('snapshot_expired', 'Component state has expired.', 419, $correlationId);
		}
		try{
			if($this->productionRequiresSharedStore() && ($this->snapshotVersionStore->manifest()['production_safe'] ?? false)!==true){
				return self::snapshotRevokeError('snapshot_version_store_required', 'Reactor snapshot concurrency protection is unavailable.', 503, $correlationId);
			}
		}
		catch(\Throwable){
			return self::snapshotRevokeError('snapshot_version_store_unavailable', 'Reactor snapshot concurrency protection is unavailable.', 503, $correlationId);
		}

		$authorizer=$this->transportAuthorizer;
		if($authorizer===null){
			try{ $configured=Reactor::config('transport_authorizer'); }
			catch(\Throwable){ return self::snapshotRevokeError('transport_authorization_unavailable', 'Reactor transport authorization is unavailable.', 503, $correlationId); }
			$authorizer=is_callable($configured) ? $configured : null;
		}
		if($authorizer===null){
			return self::snapshotRevokeError('transport_security_required', 'Reactor transport authorization is not configured.', 403, $correlationId);
		}
		$envelope=[
			'schema_version'=>1,
			'operation'=>'snapshot_revoke',
			'component'=>$snapshot->component(),
			'action'=>null,
			'mutation_requested'=>true,
			'is_reactor_transport'=>false,
			'state_keys'=>[],
			'param_keys'=>[],
			'upload_count'=>0,
			'snapshot'=>$snapshot->verificationMetadata(),
			'host_scope'=>$context->publicMetadata(),
		];
		try{ $transportDecision=$authorizer($envelope, $context); }
		catch(\Throwable){
			return self::snapshotRevokeError('transport_authorization_unavailable', 'Reactor transport authorization is unavailable.', 503, $correlationId);
		}
		if($transportDecision!==true){
			return self::snapshotRevokeError('transport_denied', 'The Reactor transport request is not authorized.', 403, $correlationId);
		}
		try{
			$revoked=$this->snapshotVersionStore->revoke($snapshot->snapshotId(), $snapshot->scopeHash(), $snapshot->component(), $snapshot->version());
		}
		catch(\Throwable){
			return self::snapshotRevokeError('snapshot_version_store_unavailable', 'Reactor snapshot concurrency protection is unavailable.', 503, $correlationId);
		}
		if(!$revoked){
			return self::snapshotRevokeError('snapshot_stale', 'Component state is stale. Refresh and try again.', 409, $correlationId);
		}
		return ReactorResponse::ok('', [], ['snapshot_revoke'=>['revoked'=>true]]);
	}

	/** @param ReactorRequest|array<string,mixed>|null $request */
	private function prepareRequest(ReactorRequest|array|null $request): ReactorRequest {
		if($request instanceof ReactorRequest && $request->securityContext()->isBound()){ return $request; }
		$context=$this->defaultSecurityContext ?? $this->configuredHostSecurityContext();
		return ReactorRequest::from($request, $context);
	}

	private function resolveSecurityContext(ReactorSecurityContext|array|null $securityContext): ReactorSecurityContext {
		$context=$securityContext!==null
			? ReactorSecurityContext::fromTrusted($securityContext)
			: ($this->defaultSecurityContext ?? $this->configuredHostSecurityContext());
		if(!$context->isBound()){ throw new \InvalidArgumentException('Reactor snapshot issuance requires explicit trusted host scope.'); }
		return $context;
	}

	private function configuredHostSecurityContext(): ReactorSecurityContext {
		$resolver=Reactor::config('security_context_resolver');
		if(!is_callable($resolver)){ return ReactorSecurityContext::fromTrusted(); }
		$resolved=$resolver();
		if(!$resolved instanceof ReactorSecurityContext && !is_array($resolved)){
			throw new \UnexpectedValueException('Reactor security_context_resolver must return an array or ReactorSecurityContext.');
		}
		return ReactorSecurityContext::fromTrusted($resolved);
	}

	/** @param list<string> $locked */
	private function createSnapshot(string $component, array $state, array $locked, ReactorSecurityContext $context): ReactorSnapshot {
		$this->assertSnapshotStorePolicy();
		return ReactorSnapshot::make($component, $state, $locked, $context);
	}

	private function registerSnapshot(ReactorSnapshot $snapshot): void {
		if(!$this->snapshotVersionStore->register($snapshot->snapshotId(), $snapshot->scopeHash(), $snapshot->component(), $snapshot->version(), $snapshot->expiresAt())){
			throw new \RuntimeException('Reactor snapshot version registration failed.');
		}
	}

	private function authorizeInitialOperation(string $operation, string $component, array $state, ReactorSecurityContext $context): void {
		$authorizer=$this->transportAuthorizer;
		if($authorizer===null){
			$configured=Reactor::config('transport_authorizer');
			$authorizer=is_callable($configured) ? $configured : null;
		}
		if($authorizer===null){ throw new \RuntimeException('Reactor initial transport authorization is not configured.', 403); }
		$envelope=[
			'schema_version'=>1,
			'operation'=>$operation,
			'component'=>$component,
			'action'=>null,
			'mutation_requested'=>false,
			'is_reactor_transport'=>false,
			'state_keys'=>self::boundedKeys($state),
			'param_keys'=>[],
			'upload_count'=>0,
			'snapshot'=>['present'=>false,'verified'=>false,'schema_version'=>null,'scope_bound'=>false,'version'=>null,'created_at'=>null,'expires_at'=>null,'legacy'=>false],
			'host_scope'=>$context->publicMetadata(),
		];
		try{ $decision=$authorizer($envelope, $context); }
		catch(\Throwable){
			try{ ReactorTrace::record('security.initial_denied', ['operation'=>$operation,'component'=>$component,'code'=>'transport_authorization_unavailable']); } catch(\Throwable){}
			throw new \RuntimeException('Reactor initial transport authorization is unavailable.', 503);
		}
		if($decision!==true){
			try{ ReactorTrace::record('security.initial_denied', ['operation'=>$operation,'component'=>$component,'code'=>'transport_denied']); } catch(\Throwable){}
			throw new \RuntimeException('Reactor initial transport request is not authorized.', 403);
		}
	}

	private function revokeMountSnapshots(): void {
		foreach(array_reverse($this->mountIssuedSnapshots) as $snapshot){
			try{ $this->snapshotVersionStore->revoke($snapshot->snapshotId(), $snapshot->scopeHash(), $snapshot->component(), $snapshot->version()); }
			catch(\Throwable){}
		}
		$this->mountIssuedSnapshots=[];
		$this->mountPendingSessionCommits=[];
	}

	/**
	 * Publishes every snapshot only after its complete root component tree has
	 * rendered. Stores expose only single-entry registration, so rollback after a
	 * partial failure is necessarily best effort and is reported honestly by the
	 * security manifest.
	 */
	private function commitMountSnapshots(): void {
		$registered=[];
		try{
			foreach($this->mountIssuedSnapshots as $snapshot){
				$this->registerSnapshot($snapshot);
				$registered[]=$snapshot;
			}
		}
		catch(\Throwable $error){
			foreach(array_reverse($registered) as $snapshot){
				try{ $this->snapshotVersionStore->revoke($snapshot->snapshotId(), $snapshot->scopeHash(), $snapshot->component(), $snapshot->version()); }
				catch(\Throwable){}
			}
			throw $error;
		}
	}

	/** Applies deferred session bindings after the snapshot ledger commit. */
	private function finishMountSnapshotTransaction(): void {
		$this->mountIssuedSnapshots=[];
		foreach($this->mountPendingSessionCommits as $commit){
			$commit['component']->commitSessionState($commit['state']);
		}
		$this->mountPendingSessionCommits=[];
	}

	private function assertSnapshotStorePolicy(): void {
		if($this->productionRequiresSharedStore() && ($this->snapshotVersionStore->manifest()['production_safe'] ?? false)!==true){
			throw new \RuntimeException('Production Reactor snapshots require a production-safe atomic version store.');
		}
	}

	private function productionRequiresSharedStore(): bool {
		return (ReactorSigner::manifest()['production'] ?? false)===true;
	}

	/** @return array<string,mixed> State values are deliberately absent. */
	private function transportEnvelope(ReactorRequest $request, ?ReactorSnapshot $snapshot, bool $mutationRequested): array {
		return [
			'schema_version'=>1,
			'component'=>$request->component(),
			'action'=>$request->action(),
			'mutation_requested'=>$mutationRequested,
			'is_reactor_transport'=>$request->isReactorRequest(),
			'state_keys'=>self::boundedKeys($request->state()),
			'param_keys'=>self::boundedKeys($request->params()),
			'upload_count'=>count($request->uploads()),
			'snapshot'=>$snapshot?->verificationMetadata() ?? [
				'present'=>false,'verified'=>false,'schema_version'=>null,'scope_bound'=>false,'version'=>null,'created_at'=>null,'expires_at'=>null,'legacy'=>false,
			],
			'host_scope'=>$request->securityContext()->publicMetadata(),
		];
	}

	/** @return list<string> */
	private static function boundedKeys(array $values): array {
		$keys=[];
		foreach(array_slice(array_keys($values), 0, 100) as $key){
			$key=(string)$key;
			if(strlen($key)>128){ $key=substr($key, 0, 128); }
			$keys[]=$key;
		}
		return $keys;
	}

	private function securityDenial(ReactorRequest $request, string $span, string $code, string $message, int $status): ReactorResponse {
		$correlationId=$request->securityContext()->correlationId() ?: self::fallbackCorrelationId();
		try{
			ReactorTrace::record('security.denied', [
				'component'=>$request->component(),
				'action'=>$request->action(),
				'code'=>$code,
				'status'=>$status,
				'correlation_id'=>$correlationId,
			]);
			ReactorTrace::end($span, ['status'=>$status,'security_code'=>$code]);
		}
		catch(\Throwable){}
		return ReactorResponse::error($message, $status, [
			'error'=>['code'=>$code,'correlation_id'=>$correlationId],
		]);
	}

	private static function fallbackCorrelationId(): string {
		try{ return 'rrq_'.bin2hex(random_bytes(8)); }
		catch(\Throwable){ return 'rrq_'.str_replace('.', '', uniqid('', true)); }
	}

	private static function snapshotRevokeError(string $code, string $message, int $status, ?string $correlationId): ReactorResponse {
		return ReactorResponse::error($message, $status, [
			'error'=>['code'=>$code,'correlation_id'=>$correlationId ?: self::fallbackCorrelationId()],
		]);
	}

	private static function validateResponseEnvelope(ReactorResponse $response): void {
		json_encode($response, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
	}

	private function abortReservation(?ReactorSnapshot $snapshot, string $reservationId): void {
		if(!$snapshot instanceof ReactorSnapshot || $snapshot->isLegacy() || $reservationId===''){ return; }
		try{ $this->snapshotVersionStore->abort($snapshot->snapshotId(), $snapshot->scopeHash(), $snapshot->component(), $snapshot->version(), $reservationId); }
		catch(\Throwable){}
	}

	private static function reservationTtlSeconds(): int {
		$configured=Reactor::config('snapshot_reservation_ttl_seconds', 120);
		if(!is_int($configured) || $configured<5 || $configured>300){
			throw new \UnexpectedValueException('Reactor snapshot_reservation_ttl_seconds must be an integer from 5 to 300.');
		}
		return $configured;
	}

	/** Production mutations always require a verified state snapshot; local compatibility is explicit. */
	private static function requiresSignedMutationSnapshot(): bool {
		$production=(ReactorSigner::manifest()['production'] ?? false)===true;
		if($production){ return true; }
		$configured=Reactor::config('require_signed_mutation_snapshots', true);
		if(!is_bool($configured)){ throw new \UnexpectedValueException('Reactor require_signed_mutation_snapshots must be boolean.'); }
		return $configured;
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
