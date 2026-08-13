<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel\Bridges\Reactor;

use Dataphyre\Panel\PanelWidgetInteractionContext;
use Dataphyre\Panel\PanelWidgetInteractionDefinition;
use Dataphyre\Panel\PanelWidgetInteractionException;
use Dataphyre\Panel\PanelWidgetInteractionRequest;
use Dataphyre\Panel\PanelWidgetInteractionResult;
use Dataphyre\Panel\PanelWidgetInteractionState;
use Dataphyre\Panel\PanelWidgetInteractionValue;
use Dataphyre\Panel\PanelWidgetRuntimeHttpAdapter;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorRequest;
use Dataphyre\Reactor\ReactorResponse;
use Dataphyre\Reactor\ReactorSecurityContext;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorSnapshotVersionStore;

/**
 * Production Panel-to-Reactor widget lifecycle bridge.
 *
 * The bridge owns a closed host registration map. Public widget definitions,
 * route segments, and request bodies never select a Reactor component or an
 * unmapped Reactor action. Reactor retains signed scope binding, CAS, deferred
 * child issuance, and its own fail-closed transport authorization.
 */
final class PanelReactorWidgetRuntimeAdapter implements PanelWidgetRuntimeHttpAdapter {
	private const MAX_PANEL_SNAPSHOT_BYTES=8192;
	/** @var array<string,PanelReactorWidgetBinding> */
	private array $bindings=[];
	/** @var array<string,string> Definition fingerprint to route key. */
	private array $definitionRoutes=[];
	private readonly string $endpointPrefix;

	public function __construct(
		private readonly ReactorManager $manager,
		private readonly ReactorSnapshotVersionStore $versionStore,
		string $endpointPrefix='/panel/widgets/runtime/reactor'
	){
		$this->endpointPrefix=self::endpointPrefix($endpointPrefix);
		$this->manager->useSnapshotVersionStore($this->versionStore);
	}

	public function name(): string { return 'reactor'; }
	public function contractVersion(): int { return 1; }

	public function bind(PanelReactorWidgetBinding $binding): self {
		$binding->assertComplete();
		if($binding->definition()->adapter()!==$this->name()){ throw new \LogicException('Reactor widget definitions must use the reactor adapter alias.'); }
		$component=$this->manager->get($binding->reactorComponent());
		if($component===null || $component->name()!==$binding->reactorComponent()){ throw new \InvalidArgumentException('Reactor widget bindings require a component registered on this manager.'); }
		$route=$binding->routeKey();
		$fingerprint=$binding->definition()->fingerprint();
		if(isset($this->bindings[$route]) || isset($this->definitionRoutes[$fingerprint])){ throw new \LogicException('Reactor widget binding routes and definitions must be unique.'); }
		$this->bindings[$route]=$binding;
		$this->definitionRoutes[$fingerprint]=$route;
		ksort($this->bindings, SORT_STRING);
		ksort($this->definitionRoutes, SORT_STRING);
		return $this;
	}

	public function unbind(string $routeKey): self {
		$routeKey=PanelWidgetInteractionValue::safeIdentifier($routeKey, 'Reactor widget route binding', 96);
		$binding=$this->bindings[$routeKey] ?? null;
		if($binding instanceof PanelReactorWidgetBinding){
			unset($this->definitionRoutes[$binding->definition()->fingerprint()], $this->bindings[$routeKey]);
		}
		return $this;
	}

	public function definitionForHttpRoute(string $bindingKey, string $surface): ?PanelWidgetInteractionDefinition {
		try{
			$bindingKey=PanelWidgetInteractionValue::safeIdentifier($bindingKey, 'Reactor widget route binding', 96);
			$surface=PanelWidgetInteractionValue::safeIdentifier($surface, 'Reactor widget surface', 128);
		}
		catch(\Throwable){ return null; }
		$binding=$this->bindings[$bindingKey] ?? null;
		return $binding instanceof PanelReactorWidgetBinding && $binding->allowsSurface($surface) ? $binding->definition() : null;
	}

	public function handle(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		try{ return $this->handleTrusted($definition, $context, $request); }
		catch(\Throwable){ return $this->failure($request, 'widget_reactor_unavailable', 'Interactive updates are temporarily unavailable.', 503, true); }
	}

	public function manifest(): array {
		$bindings=[];
		foreach($this->bindings as $route=>$binding){ $bindings[$route]=$binding->manifest(); }
		$reactorSecurity=$this->manager->securityManifest();
		return [
			'type'=>'panel_widget_runtime_adapter',
			'name'=>$this->name(),
			'contract_version'=>$this->contractVersion(),
			'endpoint_prefix'=>$this->endpointPrefix,
			'bindings'=>$bindings,
			'capabilities'=>[
				'production_reactor_bridge'=>true,
				'trusted_component_registry'=>true,
				'trusted_explicit_action_map'=>true,
				'body_selects_component_or_runtime_class'=>false,
				'panel_request_bound_into_reactor_security_context'=>true,
				'scope_bound_signed_snapshots'=>true,
				'snapshot_scope_includes'=>['panel_claims','island_id','binding_route','definition_fingerprint','reactor_component'],
				'panel_snapshot_max_bytes'=>self::MAX_PANEL_SNAPSHOT_BYTES,
				'panel_version_mapping'=>'reactor_version_plus_one',
				'mount'=>'initial_render_only_dispatch',
				'hydrate'=>'signed_render_only_cas_rotation',
				'refresh'=>'signed_render_only_cas_rotation',
				'action'=>'signed_explicit_mapped_action_dispatch',
				'unmount'=>'authentic_scope_bound_fail_closed_transport_authorized_exact_version_revoke_without_successor_or_component_callbacks',
				'unmount_response_exposes_snapshot'=>false,
				'unmount_replay'=>'stable_version_conflict',
				'deferred_child_snapshot_issuance'=>'reactor_owned_commit_after_complete_root_render',
				'idempotency_metadata_path'=>'params._panel_widget.idempotency_key',
				'idempotent_replay'=>false,
				'action_side_effect_exactly_once'=>false,
				'host_business_action_idempotency_required'=>true,
			],
			'reactor_security'=>[
				'transport_gate'=>'fail_closed_pre_hydration',
				'transport_gate_configured'=>($reactorSecurity['transport_authorizer_configured'] ?? false)===true,
				'scope_bound_snapshots'=>($reactorSecurity['snapshot']['scope_bound'] ?? false)===true,
				'deferred_root_tree_issuance'=>($reactorSecurity['mount_snapshot_commit'] ?? null)==='deferred_after_complete_root_component_tree_render_with_best_effort_partial_rollback',
				'side_effect_delivery'=>'not_exactly_once',
			],
			'version_store'=>$this->versionStore->manifest(),
			'lifecycle_reset'=>'registry_detach_only; bindings and Reactor state are not revoked',
		];
	}

	/** Registry cleanup must not wipe host bindings or shared durable Reactor state. */
	public function reset(): void {}

	private function handleTrusted(PanelWidgetInteractionDefinition $definition, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): PanelWidgetInteractionResult {
		$route=$this->definitionRoutes[$definition->fingerprint()] ?? null;
		$binding=is_string($route) ? ($this->bindings[$route] ?? null) : null;
		if(!$binding instanceof PanelReactorWidgetBinding || !$binding->allowsSurface($context->surface())){
			return $this->failure($request, 'widget_binding_unavailable', 'This widget interaction is unavailable.', 404);
		}
		if($request->operation()==='action'){
			$action=(string)$request->action();
			if(!$definition->allows($action) || $binding->reactorAction($action)===null){
				return $this->failure($request, 'widget_action_unavailable', 'This widget action is unavailable.', 404);
			}
		}
		if($request->operation()==='refresh' && $definition->refreshMode()==='none'){
			return $this->failure($request, 'widget_refresh_disabled', 'This widget does not allow refresh requests.', 422);
		}

		$security=$this->securityContext($binding, $context, $request);
		if($request->operation()==='mount'){
			return $this->dispatchReactor($binding, $context, $request, $security, null);
		}

		$snapshot=$this->verifiedSnapshot($binding, $security, $request);
		if(!$snapshot instanceof ReactorSnapshot){ return $snapshot; }
		$panelVersion=$snapshot->version()+1;
		if($request->expectedVersion()!==null && $request->expectedVersion()!==$panelVersion){
			return $this->failure($request, 'widget_version_conflict', 'The widget changed before this request completed. Refresh and try again.', 409, true);
		}
		if($request->operation()==='unmount'){
			$response=$this->manager->revokeSnapshot($snapshot, $security);
			if($response->status()<200 || $response->status()>=300){ return $this->reactorFailure($request, $response); }
			return PanelWidgetInteractionResult::success(
				$this->name(),
				$request->islandId(),
				PanelWidgetInteractionState::make('unmounted', $panelVersion),
				null,
				null,
				null
			);
		}
		return $this->dispatchReactor($binding, $context, $request, $security, $snapshot);
	}

	/** @return ReactorSnapshot|PanelWidgetInteractionResult */
	private function verifiedSnapshot(PanelReactorWidgetBinding $binding, ReactorSecurityContext $security, PanelWidgetInteractionRequest $request): ReactorSnapshot|PanelWidgetInteractionResult {
		$snapshot=ReactorSnapshot::from($request->snapshot());
		if(!$snapshot instanceof ReactorSnapshot || $snapshot->isLegacy()){
			return $this->failure($request, 'widget_snapshot_invalid', 'The widget session is no longer valid.', 419);
		}
		if(!$snapshot->verifyAuthenticity($security) || $snapshot->component()!==$binding->reactorComponent()){
			return $this->failure($request, 'widget_snapshot_invalid', 'The widget session is no longer valid.', 419);
		}
		if($snapshot->expiresAt()<=time()){
			return $this->failure($request, 'widget_snapshot_expired', 'The widget session has expired. Refresh the page and try again.', 419);
		}
		return $snapshot;
	}

	private function dispatchReactor(PanelReactorWidgetBinding $binding, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request, ReactorSecurityContext $security, ?ReactorSnapshot $snapshot): PanelWidgetInteractionResult {
		$envelope=[
			'component'=>$binding->reactorComponent(),
			'headers'=>['x-dataphyre-reactor'=>'DataphyreReactor'],
		];
		if($snapshot instanceof ReactorSnapshot){ $envelope['snapshot']=$snapshot->jsonSerialize(); }
		if($request->operation()==='action'){
			$envelope['action']=$binding->reactorAction((string)$request->action());
			$params=$request->payload();
			unset($params['_reactor_signed'], $params['_panel_widget']);
			$envelope['params']=array_replace($params, [
				'_panel_widget'=>[
					'schema_version'=>1,
					'operation'=>'action',
					'island_id'=>$request->islandId(),
					'idempotency_key'=>$request->idempotencyKey(),
					'panel'=>$context->panel(),
					'surface'=>$context->surface(),
				],
			]);
		}
		$response=$this->manager->dispatch(ReactorRequest::fromArray($envelope, $security));
		if($response->status()<200 || $response->status()>=300){ return $this->reactorFailure($request, $response); }
		$next=ReactorSnapshot::from($response->effects()['snapshot'] ?? null);
		if(!$next instanceof ReactorSnapshot || $next->isLegacy() || $next->component()!==$binding->reactorComponent() || !$next->verify($security)){
			return $this->failure($request, 'widget_snapshot_invalid', 'The widget runtime returned an invalid session.', 503, true);
		}
		try{
			$state=PanelWidgetInteractionValue::assertMap($response->state(), 'Reactor widget public state');
			$encoded=json_encode($next->jsonSerialize(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
		}
		catch(\Throwable){ return $this->failure($request, 'widget_state_invalid', 'The widget runtime returned invalid public state.', 503, true); }
		if(strlen($encoded)>self::MAX_PANEL_SNAPSHOT_BYTES){
			return $this->failure($request, 'widget_snapshot_too_large', 'The widget session exceeds Panel transport limits.', 503);
		}
		return PanelWidgetInteractionResult::success(
			$this->name(),
			$request->islandId(),
			PanelWidgetInteractionState::ready($state, $next->version()+1),
			$this->endpoint($binding, $context),
			$encoded,
			$context->bindingTag()
		);
	}

	private function reactorFailure(PanelWidgetInteractionRequest $request, ReactorResponse $response): PanelWidgetInteractionResult {
		$reactorCode=is_string($response->effects()['error']['code'] ?? null) ? $response->effects()['error']['code'] : '';
		if($response->status()===409 || $reactorCode==='snapshot_stale'){
			return $this->failure($request, 'widget_version_conflict', 'The widget changed before this request completed. Refresh and try again.', 409, true);
		}
		if($response->status()===419){
			$expired=$reactorCode==='snapshot_expired';
			return $this->failure($request, $expired ? 'widget_snapshot_expired' : 'widget_snapshot_invalid', $expired ? 'The widget session has expired. Refresh the page and try again.' : 'The widget session is no longer valid.', 419);
		}
		if($response->status()===403){ return $this->failure($request, 'widget_forbidden', 'This widget interaction is not authorized.', 403); }
		if($response->status()===404){ return $this->failure($request, 'widget_component_unavailable', 'This widget is unavailable.', 404); }
		if($response->status()===422){ return $this->failure($request, 'widget_action_rejected', 'The widget could not apply this action.', 422); }
		if($response->status()===503){ return $this->failure($request, 'widget_reactor_unavailable', 'Interactive updates are temporarily unavailable.', 503, true); }
		return $this->failure($request, 'widget_runtime_failure', 'The widget could not be updated.', 500, true);
	}

	private function securityContext(PanelReactorWidgetBinding $binding, PanelWidgetInteractionContext $context, PanelWidgetInteractionRequest $request): ReactorSecurityContext {
		$scopeBinding=[
			'schema_version'=>2,
			'panel_claims'=>$context->claims(),
			'island_id'=>$request->islandId(),
			'binding_route'=>$binding->routeKey(),
			'definition_fingerprint'=>$binding->definition()->fingerprint(),
			'reactor_component'=>$binding->reactorComponent(),
		];
		$scopeId='panel-widget-v2:'.hash('sha256', PanelWidgetInteractionValue::canonical($scopeBinding));
		$correlation=(string)($context->request()->header('x-correlation-id', $context->request()->header('x-request-id', '')));
		$attributes=[
			'scope_id'=>$scopeId,
			'audience'=>'panel-widget:'.$context->panel().':'.$context->surface(),
			'principal_id'=>$context->principal(),
			'correlation_id'=>$correlation,
			'panel_request'=>$context->request(),
			'panel_widget_context'=>$context,
			'panel_widget_request'=>$request,
			'panel_widget_host_attributes'=>$context->attributes(),
		];
		if($context->tenant()!==null){ $attributes['tenant_id']=$context->tenant(); }
		if($context->session()!==null){ $attributes['session_id']=$context->session(); }
		return ReactorSecurityContext::fromTrusted($attributes);
	}

	private function endpoint(PanelReactorWidgetBinding $binding, PanelWidgetInteractionContext $context): string {
		return $this->endpointPrefix.'/'.rawurlencode($binding->routeKey()).'/'.rawurlencode($context->surface());
	}

	private function failure(PanelWidgetInteractionRequest $request, string $code, string $message, int $status, bool $retryable=false): PanelWidgetInteractionResult {
		return PanelWidgetInteractionResult::failure($this->name(), $request->islandId(), new PanelWidgetInteractionException($code, $message, $status, $retryable));
	}

	private static function endpointPrefix(string $endpoint): string {
		$endpoint=rtrim(PanelWidgetInteractionValue::boundedString($endpoint, 'Reactor widget endpoint prefix', 1900), '/');
		if(!str_starts_with($endpoint, '/') || str_starts_with($endpoint, '//') || str_contains($endpoint, "\\") || str_contains($endpoint, '?') || str_contains($endpoint, '#') || preg_match('/[\r\n]/', $endpoint)===1){
			throw new \InvalidArgumentException('Reactor widget endpoint prefixes must be same-origin absolute paths without query or fragment components.');
		}
		return $endpoint;
	}
}
