<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes a complete panel surface for clients and diagnostics.
 *
 * The manifest composes resources, pages, widgets, navigation, commands, theme,
 * plugin, tenant, permission, route, and shell metadata into one stable payload.
 * It accepts live PanelInstance/PanelManager objects or array descriptions so the
 * same contract can be used during runtime, tests, and offline manifest exports.
 */
final class PanelManifest {

	/**
	 * Stores the panel source and manifest context.
	 *
	 * @param PanelInstance|PanelManager|array|null $panel Live panel source, description array, or null for the global Panel facade.
	 * @param ?PanelRequest $request Current request context.
	 * @param array<string,mixed> $meta Manifest metadata overrides and integration hints.
	 */
	private function __construct(
		private readonly PanelInstance|PanelManager|array|null $panel=null,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a manifest builder for a live panel or serialized panel description.
	 *
	 * @param PanelInstance|PanelManager|array<string,mixed>|null $panel Live panel source, description array, or null for the global Panel facade.
	 * @param ?PanelRequest $request Current request used by permission, route, and state-aware child manifests.
	 * @param array<string,mixed> $meta Additional context such as route mount, surface name, or label overrides.
	 * @return self New immutable manifest builder.
	 */
	public static function from(PanelInstance|PanelManager|array|null $panel=null, ?PanelRequest $request=null, array $meta=[]): self {
		return new self($panel, $request, $meta);
	}

	/**
	 * Materializes the complete panel manifest payload.
	 *
	 * The returned array is intentionally verbose so clients, diagnostics, and
	 * tooling can reason about available UI surfaces and security integration.
	 *
	 * @return array{type:string,schema_version:int,api_version:int,name:string,label:string,resources:array<string,array<string,mixed>>,pages:array<string,array<string,mixed>>,widgets:array<string,array<string,mixed>>,widget_runtime:array<string,mixed>,data_sources:array<string,mixed>,data_surfaces:array<string,mixed>,realtime:array<string,mixed>,agent_workflows:array<string,mixed>,studio_editor:array<string,mixed>,navigation:array<string,mixed>,commands:array<string,array<string,mixed>>,theme:array<string,mixed>,plugins:array<string,array<string,mixed>>,tenant:array<string,mixed>,permission:array<string,mixed>,platform:array<string,mixed>,search:array<string,mixed>,shell:array<string,mixed>,routes:array<string,mixed>,capabilities:array<string,array<string,mixed>>,meta:array<string,mixed>} Complete panel_manifest payload.
	 */
	public function toArray(): array {
		$description=$this->description();
		$resources=$this->resourceManifests($description);
		$pages=$this->pageManifests($description);
		$widgets=$this->widgetManifests($description);
		$widgetRuntime=$this->widgetRuntimeManifest($description);
		$dataSources=$this->dataSourceManifest($description);
		$dataSurfaces=$this->dataSurfaceManifest($description);
		$realtime=$this->realtimeManifest($description);
		$agentWorkflows=$this->agentWorkflowManifest($description);
		$studioEditor=$this->studioEditorManifest($description);
		$navigation=$this->navigationManifest($description);
		$commands=$this->commandManifests($description);
		$theme=$this->themeManifest($description);
		$plugins=$this->pluginManifests($description);
		$tenant=$this->tenantManifest($resources);
		$permission=$this->permissionManifest($resources);
		$platform=$this->platformManifest($description);
		$navigationIntents=$this->panel instanceof PanelInstance
			? $this->panel->navigationIntentManifest()
			: PanelConfig::navigationIntentManifest();
		$manifest=[
			'type'=>'panel_manifest',
			'name'=>$this->panel instanceof PanelInstance ? $this->panel->name() : (string)($description['name'] ?? $this->meta['name'] ?? 'default'),
			'label'=>(string)($description['label'] ?? $this->meta['label'] ?? $this->panelLabel()),
			'resources'=>$resources,
			'pages'=>$pages,
			'widgets'=>$widgets,
			'widget_runtime'=>$widgetRuntime,
			'data_sources'=>$dataSources,
			'data_surfaces'=>$dataSurfaces,
			'realtime'=>$realtime,
			'agent_workflows'=>$agentWorkflows,
			'studio_editor'=>$studioEditor,
			'navigation'=>$navigation,
			'commands'=>$commands,
			'theme'=>$theme,
			'plugins'=>$plugins,
			'tenant'=>$tenant,
			'permission'=>$permission,
			'navigation_intents'=>$navigationIntents,
			'platform'=>$platform,
			'search'=>$this->searchManifest($resources, $description),
			'shell'=>self::shellManifest($description, $navigation, $commands, $theme),
			'routes'=>$this->routeManifest(),
			'capabilities'=>self::capabilities($resources, $pages, $widgets, $navigation, $commands, $theme, $plugins, $tenant, $permission, $platform, $widgetRuntime, $dataSources,$dataSurfaces,$realtime,$agentWorkflows,$studioEditor),
			'meta'=>$this->meta,
		];
		PanelTrace::record('panel.manifest.described', [
			'name'=>$manifest['name'],
			'resources'=>count($resources),
			'pages'=>count($pages),
			'widgets'=>count($widgets),
			'data_sources'=>(int)($dataSources['count']??0),
			'data_surfaces'=>(int)($dataSurfaces['count']??0),
			'realtime_configured'=>($realtime['attachment']['configured']??false)===true,
			'agent_workflows_configured'=>($agentWorkflows['attachment']['configured']??false)===true,
			'commands'=>count($commands),
			'tenant_resources'=>(int)($tenant['capabilities']['resources']['total'] ?? 0),
			'permission_catalog'=>(int)($permission['catalog_count'] ?? 0),
			'platform_ready'=>(int)($platform['counts']['ready'] ?? 0),
		]);
		return PanelManifestContract::stamp($manifest);
	}

	/**
	 * Resolves the panel description from the configured source.
	 *
	 * @return array<string,mixed> Panel description array.
	 */
	private function description(): array {
		if(is_array($this->panel)){
			return $this->panel;
		}
		if($this->panel instanceof PanelInstance){
			return $this->panel->describe();
		}
		if($this->panel instanceof PanelManager){
			return $this->panel->describe();
		}
		return Panel::describe();
	}

	/**
	 * Builds resource manifests from live registrations or serialized descriptions.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,array<string,mixed>> Resource manifests keyed by resource name.
	 */
	private function resourceManifests(array $description): array {
		$resources=[];
		if($this->panel instanceof PanelInstance || $this->panel instanceof PanelManager){
			$registered=$this->panel->resources();
			foreach($registered as $name=>$resource){
				if($resource instanceof Resource){
					$manifest=$resource->resourceManifest($this->request, [
						'surface'=>'panel_manifest',
					]);
					$resources[(string)($manifest['name'] ?? $name)]=$manifest;
				}
			}
			return $resources;
		}
		foreach((array)($description['resources'] ?? []) as $index=>$resource){
			if(!is_array($resource)){
				continue;
			}
			$manifest=ResourceManifest::from($resource, $this->request, [
				'surface'=>'panel_manifest',
			])->toArray();
			$resources[(string)($manifest['name'] ?? 'resource_'.$index)]=$manifest;
		}
		return $resources;
	}

	/**
	 * Builds page manifests while preserving registered panel page names.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,array<string,mixed>> Page manifests keyed by page name.
	 */
	private function pageManifests(array $description): array {
		$pages=[];
		if($this->panel instanceof PanelInstance || $this->panel instanceof PanelManager){
			foreach($this->panel->pages() as $name=>$page){
				if($page instanceof PanelPage){
					$manifest=$page->pageManifest($this->request, $this->panel instanceof PanelManager ? $this->panel : $this->panel->manager(), [
						'surface'=>'panel_manifest',
					]);
					$pages[(string)($manifest['name'] ?? $name)]=$manifest;
				}
			}
			return $pages;
		}
		foreach((array)($description['pages'] ?? []) as $index=>$page){
			if(!is_array($page)){
				continue;
			}
			$manifest=PageManifest::from($page, $this->request, null, ['surface'=>'panel_manifest'])->toArray();
			$pages[(string)($manifest['name'] ?? 'page_'.$index)]=$manifest;
		}
		return $pages;
	}

	/**
	 * Builds dashboard widget manifests from the panel description.
	 *
	 * @param array<string,mixed> $description Panel description containing widget definitions.
	 * @return array<string,array<string,mixed>> Widget manifests keyed by widget name.
	 */
	private function widgetManifests(array $description): array {
		$widgets=[];
		foreach((array)($description['widgets'] ?? []) as $index=>$widget){
			if(!is_array($widget)){
				continue;
			}
			$manifest=WidgetManifest::from($widget, $this->request, [
				'surface'=>'panel_manifest',
				'scope'=>'dashboard',
			])->toArray();
			$widgets[(string)($manifest['name'] ?? 'widget_'.$index)]=$manifest;
		}
		return $widgets;
	}

	/**
	 * Describes navigation groups using live panel state when available.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,mixed> Navigation manifest payload.
	 */
	private function navigationManifest(array $description): array {
		return NavigationManifest::from(
			$this->panel instanceof PanelInstance || $this->panel instanceof PanelManager ? $this->panel : $description,
			$this->request,
			[],
			[
				'surface'=>'panel_manifest',
				'navigation_layout'=>(string)($this->meta['navigation_layout'] ?? 'sidebar'),
				'navigation_mode'=>(string)($this->meta['navigation_mode'] ?? 'floating'),
			]
		)->toArray();
	}

	/**
	 * Builds command manifests from registered commands or serialized definitions.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,array<string,mixed>> Command manifests keyed by command name.
	 */
	private function commandManifests(array $description): array {
		if($this->panel instanceof PanelInstance || $this->panel instanceof PanelManager){
			$commands=$this->panel->registeredCommands();
			if($commands===[]){
				$commands=$this->panel->commands($this->request);
			}
		}
		else {
			$commands=(array)($description['commands'] ?? []);
		}
		$rows=[];
		foreach($commands as $index=>$command){
			if(!$command instanceof PanelCommand && !is_array($command)){
				continue;
			}
			$manifest=CommandManifest::from(
				$command,
				$this->request,
				$this->panel instanceof PanelInstance ? $this->panel->manager() : ($this->panel instanceof PanelManager ? $this->panel : null),
				['surface'=>'panel_manifest']
			)->toArray();
			$rows[(string)($manifest['name'] ?? 'command_'.$index)]=$manifest;
		}
		return $rows;
	}

	/**
	 * Describes the active theme and its resolved capabilities.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,mixed> Theme manifest payload.
	 */
	private function themeManifest(array $description): array {
		$theme=$this->panel instanceof PanelInstance || $this->panel instanceof PanelManager
			? $this->panel->theme()
			: (is_array($description['theme'] ?? null) ? $description['theme'] : Panel::theme()->toArray());
		return ThemeManifest::from($theme, ['surface'=>'panel_manifest'])->toArray();
	}

	/**
	 * Builds plugin manifests for panel extensions.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,array<string,mixed>> Plugin manifests keyed by plugin id.
	 */
	private function pluginManifests(array $description): array {
		if($this->panel instanceof PanelInstance){
			return $this->panel->pluginManifests(['surface'=>'panel_manifest']);
		}
		$plugins=[];
		foreach((array)($description['plugins'] ?? []) as $index=>$plugin){
			if(!is_array($plugin)){
				continue;
			}
			$manifest=PluginManifest::from($plugin, [], ['surface'=>'panel_manifest'])->toArray();
			$plugins[(string)($manifest['id'] ?? 'plugin_'.$index)]=$manifest;
		}
		return $plugins;
	}

	/**
	 * Describes instance-owned production services without serializing values.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,mixed> Platform capability and attachment manifest.
	 */
	private function platformManifest(array $description): array {
		if($this->panel instanceof PanelInstance){
			return $this->panel->platformManifest();
		}
		if(is_array($description['platform'] ?? null)){
			$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($description['platform']),['max_depth'=>24,'max_items'=>1000,'max_string_bytes'=>4096]);
			if(is_array($safe)&&!array_is_list($safe)){return$safe;}
		}
		$manifest=PanelPlatformManifest::inspect()->jsonSerialize();
		$manifest['attachment']=[
			'configured'=>false,
			'revision'=>0,
			'service_revision'=>0,
			'lifecycle'=>['operation'=>'unconfigured','revision'=>0],
		];
		return $manifest;
	}

	/**
	 * Publishes the instance-owned widget adapter registry without serializing
	 * signing keys, live adapter objects, callbacks, or runtime sessions.
	 *
	 * @param array<string,mixed> $description Panel description fallback.
	 * @return array<string,mixed>
	 */
	private function widgetRuntimeManifest(array $description): array {
		if($this->panel instanceof PanelInstance){ return $this->panel->widgetRuntimeManifest(); }
		$candidate=$description['widget_runtime'] ?? null;
		if(is_array($candidate) && !array_is_list($candidate) && ($candidate['type'] ?? null)==='panel_widget_runtime_registry' && ($candidate['contract_version'] ?? null)===1){
			$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate), ['max_depth'=>16,'max_items'=>500,'max_string_bytes'=>4096]);
			if(is_array($safe) && !array_is_list($safe)){ return $safe; }
		}
		return [
			'type'=>'panel_widget_runtime_registry', 'contract_version'=>1,
			'revision'=>0, 'cleanup_failures'=>0, 'conflict_policy'=>'reject',
			'adapters'=>[], 'fingerprint'=>hash('sha256', '[]'),
			'capabilities'=>[
				'instance_scoped'=>true, 'trusted_host_scope'=>true,
				'rotating_binding_keys'=>true, 'persistent_binding_keys'=>false,
				'reactor_bridge'=>false,
			],
		];
	}

	/** Publishes only snapshotted registry metadata; adapter code is never called. @param array<string,mixed> $description @return array<string,mixed> */
	private function dataSourceManifest(array $description): array {
		if($this->panel instanceof PanelInstance){return$this->panel->dataSourceManifest();}
		$candidate=$description['data_sources']??null;
		if(is_array($candidate)&&!array_is_list($candidate)&&($candidate['type']??null)==='panel_data_source_registry'&&($candidate['contract_version']??null)===1){
			$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate),['max_depth'=>20,'max_items'=>2000,'max_string_bytes'=>4096]);
			$safe=self::redactDataSourceInfrastructure($safe);
			if(is_array($safe)&&!array_is_list($safe)){return$safe;}
		}
		return['type'=>'panel_data_source_registry','contract_version'=>1,'revision'=>0,'conflict_policy'=>'reject','count'=>0,'sources'=>[],'fingerprint'=>hash('sha256','[]'),'capabilities'=>['instance_scoped'=>true,'contributor_layers'=>true,'transactional_checkpoint'=>true,'registration_manifest_snapshot'=>true,'live_adapter_code_run_by_manifest'=>false,'secret_values_serialized'=>false],'attachment'=>['configured'=>false,'platform_attached'=>false,'service_state'=>'missing','platform_revision'=>0,'registry_revision'=>0,'lifecycle'=>['operation'=>'unconfigured','revision'=>0]]];
	}

	private static function redactDataSourceInfrastructure(mixed $value,?string $key=null): mixed {
		if($key!==null){
			$split=preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$key)??$key;
			$normalized=strtolower(trim(preg_replace('/[^a-z0-9]+/i','_',$split)??'','_'));
			if(preg_match('/(?:^|_)(?:endpoint|origin|base_url|url|uri|headers?|transport)(?:_|$)/D',$normalized)===1&&!is_bool($value)&&$value!==null){return PanelSensitiveDataSanitizer::REDACTED;}
		}
		if(!is_array($value)){return$value;}$out=[];foreach($value as$itemKey=>$item){$out[$itemKey]=self::redactDataSourceInfrastructure($item,is_string($itemKey)?$itemKey:null);}return$out;
	}

	/**
	 * Copies an offline manifest candidate without invoking JsonSerializable,
	 * Stringable, closures, adapters, or other object behavior.
	 */
	private static function passiveManifestSnapshot(mixed $value): mixed {
		$items=0;return self::passiveManifestValue($value,0,$items);
	}

	private static function passiveManifestValue(mixed $value,int $depth,int &$items): mixed {
		if($depth>32){return['type'=>'truncated','reason'=>'depth'];}
		if(is_array($value)){
			$out=[];
			foreach($value as$key=>$item){
				if(++$items>5000){$out['__truncated_items__']=true;break;}
				$out[$key]=self::passiveManifestValue($item,$depth+1,$items);
			}
			return$out;
		}
		if(is_object($value)){return['type'=>'object','serialization'=>'omitted'];}
		if(is_resource($value)){return['type'=>'resource','serialization'=>'omitted'];}
		if(is_float($value)&&!is_finite($value)){return(string)$value;}
		return$value;
	}

	/** Publishes only the secret-free DataSurface registry contract. @param array<string,mixed> $description @return array<string,mixed> */
	private function dataSurfaceManifest(array $description): array {
		if($this->panel instanceof PanelInstance){return$this->panel->dataSurfaceManifest();}
		$candidate=$description['data_surfaces']??null;
		if(is_array($candidate)&&!array_is_list($candidate)&&($candidate['type']??null)==='panel_data_surface_registry'&&($candidate['version']??null)===1){$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate),['max_depth'=>20,'max_items'=>2000,'max_string_bytes'=>4096]);if(is_array($safe)&&!array_is_list($safe)){return$safe;}}
		return['type'=>'panel_data_surface_registry','version'=>1,'count'=>0,'revision'=>0,'conflict_policy'=>'reject','authorization'=>'required','definitions'=>[],'fingerprint'=>hash('sha256','[]'),'capabilities'=>['instance_scoped'=>true,'contributor_layers'=>true,'transactional_checkpoint'=>true,'registration_manifest_snapshot'=>true,'live_adapter_code_run_by_manifest'=>false],'secrets_exposed'=>false,'attachment'=>['configured'=>false,'revision'=>0,'registry_revision'=>0,'lifecycle'=>['operation'=>'unconfigured','revision'=>0]]];
	}

	/** Publishes the host-integrated realtime contract without resolving a service factory. @param array<string,mixed> $description @return array<string,mixed> */
	private function realtimeManifest(array $description): array {
		if($this->panel instanceof PanelInstance){return$this->panel->realtimeManifest();}
		$candidate=$description['realtime']??null;
		if(is_array($candidate)&&!array_is_list($candidate)&&($candidate['type']??null)==='panel_realtime_integration'&&($candidate['version']??null)===1){
			$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate),['max_depth'=>24,'max_items'=>1000,'max_string_bytes'=>4096]);if(is_array($safe)&&!array_is_list($safe)){return$safe;}
		}
		return['type'=>'panel_realtime_integration','version'=>1,'endpoint'=>null,'client'=>PanelRealtimeClientAssets::manifest(),'integration'=>['routes_registered'=>false,'host_route_required'=>true,'host_authorization_required'=>true,'host_csrf_origin_rate_limit_required'=>true,'query_requires_opt_in'=>true,'credential_named_query_keys_rejected'=>true],'attachment'=>['configured'=>false,'platform_attached'=>false,'services'=>[],'platform_revision'=>0,'lifecycle'=>['operation'=>'unconfigured','revision'=>0]]];
	}

	/** Publishes the sealed agent-safe workflow contract without invoking a model, tool, policy, or service factory. @param array<string,mixed> $description @return array<string,mixed> */
	private function agentWorkflowManifest(array $description): array {
		if($this->panel instanceof PanelInstance){return$this->panel->agentWorkflowManifest();}
		$candidate=$description['agent_workflows']??null;
		if(is_array($candidate)&&!array_is_list($candidate)&&($candidate['type']??null)==='panel_agent_workflow_integration'&&($candidate['version']??null)===1){
			$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate),['max_depth'=>24,'max_items'=>1000,'max_string_bytes'=>4096]);if(is_array($safe)&&!array_is_list($safe)){return$safe;}
		}
		return['type'=>'panel_agent_workflow_integration','version'=>1,'runtime'=>null,'integration'=>['routes_registered'=>false,'model_client_registered'=>false,'identity_inferred'=>false,'host_authorization_required'=>true,'host_csrf_origin_rate_limit_required'=>true,'durable_store_host_required'=>true],'attachment'=>['configured'=>false,'platform_attached'=>false,'services'=>[],'platform_revision'=>0,'lifecycle'=>['operation'=>'unconfigured','revision'=>0]]];
	}

	/** @param array<string,mixed> $description @return array<string,mixed> */
	private function studioEditorManifest(array $description): array {
		if($this->panel instanceof PanelInstance){return$this->panel->studioEditorManifest();}
		$candidate=$description['studio_editor']??null;if(is_array($candidate)&&!array_is_list($candidate)&&($candidate['type']??null)==='panel_studio_editor'&&in_array($candidate['version']??null,[1,2,3],true)){$safe=PanelSensitiveDataSanitizer::sanitize(self::passiveManifestSnapshot($candidate),['max_depth'=>20,'max_items'=>2000,'max_string_bytes'=>4096]);if(is_array($safe)&&!array_is_list($safe)){return$safe;}}
		return PanelStudioEditor::manifest();
	}

	/**
	 * Describes global search capabilities across manifest resources.
	 *
	 * @param array<string,array<string,mixed>> $resources Resource manifests already materialized for this panel.
	 * @return array<string,mixed> Search manifest payload.
	 */
	private function searchManifest(array $resources, array $description=[]): array {
		$source=$this->panel instanceof PanelInstance || $this->panel instanceof PanelManager
			? $this->panel
			: [
				'resources'=>$resources,
				'search_providers'=>is_array($description['search_providers'] ?? null) ? $description['search_providers'] : [],
			];
		return SearchManifest::from($source, $this->request, null, 12, ['surface'=>'panel_manifest'])->toArray();
	}

	/**
	 * Describes tenant scoping for the panel and its resources.
	 *
	 * @param array<string,array<string,mixed>> $resources Resource manifests already materialized for this panel.
	 * @return array<string,mixed> Tenant manifest payload.
	 */
	private function tenantManifest(array $resources): array {
		$source=$this->panel instanceof PanelInstance || $this->panel instanceof PanelManager ? $this->panel : ['resources'=>$resources];
		return TenantManifest::from($source, $this->request, ['surface'=>'panel_manifest'])->toArray();
	}

	/**
	 * Describes permission-module availability and the panel permission catalog.
	 *
	 * Permission integration is optional. The manifest records whether the module
	 * can be loaded, the generated permission names, and an optional decision
	 * snapshot without forcing authorization checks unless explicitly requested.
	 *
	 * @param array<string,array<string,mixed>> $resources Resource manifests used to derive sample permission names.
	 * @return array<string,mixed> Permission manifest payload.
	 */
	private function permissionManifest(array $resources): array {
		$config=PanelConfig::config('permission', PanelConfig::config('permissions', null));
		$options=is_array($config) ? $config : [];
		$enabled=$config!==null && $config!==false;
		$available=class_exists('\Dataphyre\Permission\Permission');
		if(!$available && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('permission')===true){
			$available=class_exists('\Dataphyre\Permission\Permission');
		}
		$catalog=[];
		$source=$this->panel instanceof PanelInstance || $this->panel instanceof PanelManager ? $this->panel : null;
		if($available && $source!==null){
			try{
				$catalog=\Dataphyre\Permission\Permission::panel_catalog($source, $options);
			}
			catch(\Throwable $exception){
				PanelTrace::record('panel.permission_manifest_error', [
					'message'=>$exception->getMessage(),
				]);
			}
		}
		$permissions=array_values(array_filter(array_map(
			static fn(array $row): string => trim((string)($row['permission'] ?? '')),
			$catalog
		), static fn(string $permission): bool => $permission!==''));
		$byType=[];
		foreach($catalog as $row){
			$type=(string)($row['type'] ?? 'resource');
			$byType[$type]=($byType[$type] ?? 0)+1;
		}
		$resourceNames=array_keys($resources);
		$sampleResource=(string)($resourceNames[0] ?? 'orders');
		$prefix=(string)($options['permission_prefix'] ?? 'panel');
		$resourcePrefix=trim((string)($options['resource_prefix'] ?? ''), '.');
		$sampleBase=implode('.', array_values(array_filter([$prefix, $resourcePrefix, $sampleResource], static fn(string $part): bool => $part!=='')));
		$manifest=[
			'type'=>'panel_permission_manifest',
			'enabled'=>$enabled,
			'available'=>$available,
			'configured'=>$config!==null,
			'admin_resources_available'=>class_exists('\Dataphyre\Permission\PermissionPanel'),
			'prefix'=>$prefix,
			'resource_prefix'=>$resourcePrefix,
			'super_permission'=>(string)($options['super_permission'] ?? 'panel.*'),
			'allow_guest_pages'=>is_array($options['allow_guest_pages'] ?? null) ? array_values($options['allow_guest_pages']) : [],
			'catalog_count'=>count($catalog),
			'permissions'=>$permissions,
			'catalog'=>$catalog,
			'counts'=>[
				'resources'=>count($resourceNames),
				'permissions'=>count($permissions),
				'by_type'=>$byType,
			],
			'examples'=>[
				'view_any'=>$sampleBase.'.view_any',
				'view'=>$sampleBase.'.view',
				'update'=>$sampleBase.'.update',
				'action'=>$sampleBase.'.action.review',
				'relation'=>$sampleBase.'.relation.items.view',
			],
		];
		if($available && $permissions!==[] && ($options['manifest_decisions'] ?? $options['include_manifest_decisions'] ?? false)===true){
			$manifest['decision_snapshot']=$this->permissionDecisionSnapshot($permissions, $options);
		}
		else{
			$manifest['decision_snapshot']=[
				'included'=>false,
				'reason'=>$available ? 'disabled' : 'permission_unavailable',
			];
		}
		return $manifest;
	}

	/**
	 * Optionally captures current-user permission decisions for manifest tooling.
	 *
	 * Snapshot failures are converted into a payload instead of aborting manifest
	 * generation, because diagnostics should still render the catalog.
	 *
	 * @param array<int,string> $permissions Permission strings generated for this panel.
	 * @param array<string,mixed> $options Permission module configuration.
	 * @return array<string,mixed> Decision snapshot payload.
	 */
	private function permissionDecisionSnapshot(array $permissions, array $options): array {
		$context=$this->permissionDecisionContext();
		try{
			$snapshot=\Dataphyre\Permission\Permission::snapshot(
				$this->request?->user(),
				$permissions,
				$context,
				['include_explain'=>($options['manifest_decision_explain'] ?? false)===true]
			);
		}
		catch(\Throwable $exception){
			PanelTrace::record('panel.permission_snapshot_error', ['exception'=>$exception::class]);
			return [
				'included'=>false,
				'reason'=>'snapshot_error',
				'message'=>'Permission decision snapshot is unavailable.',
			];
		}
		return [
			'included'=>true,
			'context'=>$context,
			'subject_id'=>$snapshot['subject_id'] ?? null,
			'roles'=>is_array($snapshot['roles'] ?? null) ? array_values($snapshot['roles']) : [],
			'rules'=>is_array($snapshot['rules'] ?? null) ? array_values($snapshot['rules']) : [],
			'allowed'=>is_array($snapshot['allowed'] ?? null) ? array_values($snapshot['allowed']) : [],
			'denied'=>is_array($snapshot['denied'] ?? null) ? array_values($snapshot['denied']) : [],
			'decisions'=>is_array($snapshot['decisions'] ?? null) ? $snapshot['decisions'] : [],
			'counts'=>[
				'allowed'=>is_array($snapshot['allowed'] ?? null) ? count($snapshot['allowed']) : 0,
				'denied'=>is_array($snapshot['denied'] ?? null) ? count($snapshot['denied']) : 0,
				'total'=>count($permissions),
			],
			'explain'=>is_array($snapshot['explain'] ?? null) ? $snapshot['explain'] : null,
		];
	}

	/**
	 * Builds the authorization context used by optional permission snapshots.
	 *
	 * @return array Context keys shared with panel permission checks.
	 */
	private function permissionDecisionContext(): array {
		return [
			'panel'=>$this->panel instanceof PanelInstance ? ($this->panel->name() ?: 'default') : (string)($this->meta['name'] ?? 'default'),
			'tenant'=>$this->request?->tenant(),
			'resource'=>$this->request?->resourceName(),
			'operation'=>$this->request?->operation(),
			'record'=>$this->request?->recordKey(),
			'action'=>$this->request?->actionName(),
			'relation'=>$this->request?->relationName(),
		];
	}

	/**
	 * Describes the mounted route surface when a panel route prefix is configured.
	 *
	 * @return array<string,mixed> mounted panel route metadata including prefix, surface, and options.
	 */
	private function routeManifest(): array {
		$prefix=$this->meta['route_prefix'] ?? $this->meta['mount_prefix'] ?? $this->meta['panel_mount_prefix'] ?? PanelConfig::config('panel_mount_prefix');
		if(!is_string($prefix) || trim($prefix)===''){
			return [
				'type'=>'panel_route_manifest',
				'mounted'=>false,
				'prefix'=>'',
				'surface'=>$this->panel instanceof PanelInstance ? ($this->panel->name() ?: 'default') : (string)($this->meta['name'] ?? 'default'),
			];
		}
		$surface=$this->panel instanceof PanelInstance ? ($this->panel->name() ?: 'default') : (string)($this->meta['name'] ?? 'default');
		$options=is_array($this->meta['route_options'] ?? null) ? $this->meta['route_options'] : [];
		if(isset($this->meta['route_name']) && is_string($this->meta['route_name'])){
			$options['name']=$this->meta['route_name'];
		}
		$manifest=PanelRoute::manifest($prefix, $surface, $options);
		$manifest['mounted']=true;
		return $manifest;
	}

	/**
	 * Summarizes shell-level chrome, navigation, command, and theme state.
	 *
	 * @param array<string,mixed> $description Source panel description.
	 * @param array<string,mixed> $navigation Navigation manifest payload.
	 * @param array<string,array<string,mixed>> $commands Command manifests.
	 * @param array<string,mixed> $theme Theme manifest payload.
	 * @return array<string,mixed> Shell summary payload for clients.
	 */
	private static function shellManifest(array $description, array $navigation, array $commands, array $theme): array {
		return [
			'navigation'=>$navigation['counts'] ?? [],
			'commands'=>[
				'total'=>count($commands),
				'groups'=>count(array_unique(array_map(static fn(array $command): string => (string)($command['group'] ?? 'Commands'), $commands))),
				'lazy'=>count(array_filter($commands, static fn(array $command): bool => (bool)($command['visibility']['visible_lazy'] ?? $command['lazy']['visible'] ?? false) || (bool)($command['target']['url_lazy'] ?? $command['lazy']['url'] ?? false))),
			],
			'theme'=>[
				'name'=>$theme['active']['name'] ?? null,
				'brand'=>$theme['active']['brand'] ?? null,
				'capabilities'=>$theme['capabilities'] ?? [],
			],
			'chrome'=>self::chromeManifest(),
			'widgets'=>is_array($description['widgets'] ?? null) ? count($description['widgets']) : 0,
		];
	}

	/**
	 * Describes configured header, footer, and sticky chrome behavior.
	 *
	 * @return array{header_mode:string,footer_mode:string,navigation_sticky:bool,header_sticky:bool,footer_sticky:bool,footer_configured:bool} Chrome manifest payload.
	 */
	private static function chromeManifest(): array {
		$hooks=PanelConfig::config('render_hooks', []);
		$footerHooks=false;
		if(is_array($hooks)){
			foreach(['footer', 'footer.before', 'footer.after'] as $hook){
				if(array_key_exists($hook, $hooks)){
					$footerHooks=true;
					break;
				}
			}
		}
		$footer=PanelConfig::config('footer_html', PanelConfig::config('footer', ''));
		return [
			'header_mode'=>PanelConfig::headerMode(),
			'footer_mode'=>PanelConfig::footerMode(),
			'navigation_sticky'=>PanelConfig::navigationSticky(),
			'header_sticky'=>PanelConfig::headerSticky(),
			'footer_sticky'=>PanelConfig::footerSticky(),
			'footer_configured'=>$footerHooks || is_callable($footer) || (is_scalar($footer) && trim((string)$footer)!==''),
		];
	}

	/**
	 * Aggregates panel-wide capability counters from child manifests.
	 *
	 * @param array<string,array<string,mixed>> $resources Resource manifests.
	 * @param array<string,array<string,mixed>> $pages Page manifests.
	 * @param array<string,array<string,mixed>> $widgets Widget manifests.
	 * @param array<string,mixed> $navigation Navigation manifest payload.
	 * @param array<string,array<string,mixed>> $commands Command manifests.
	 * @param array<string,mixed> $theme Theme manifest payload.
	 * @param array<string,array<string,mixed>> $plugins Plugin manifests.
	 * @param array<string,mixed> $tenant Tenant manifest payload.
	 * @param array<string,mixed> $permission Permission manifest payload.
	 * @param array<string,mixed> $platform Platform manifest payload.
	 * @return array<string,array<string,mixed>> Capability summary used by shell clients.
	 */
	private static function capabilities(array $resources, array $pages, array $widgets, array $navigation, array $commands, array $theme, array $plugins, array $tenant, array $permission, array $platform=[], array $widgetRuntime=[],array $dataSources=[],array $dataSurfaces=[],array $realtime=[],array $agentWorkflows=[],array $studioEditor=[]): array {
		return [
			'resources'=>[
				'total'=>count($resources),
				'writable'=>count(array_filter($resources, static fn(array $resource): bool => ($resource['operations']['writes'] ?? false)===true)),
				'tenant_scoped'=>count(array_filter($resources, static fn(array $resource): bool => ($resource['tenant']['scoped'] ?? false)===true)),
				'global_searchable'=>count(array_filter($resources, static fn(array $resource): bool => ($resource['search']['global_searchable'] ?? false)===true)),
				'relations'=>array_sum(array_map(static fn(array $resource): int => (int)($resource['capabilities']['relations']['total'] ?? 0), $resources)),
			],
			'pages'=>[
				'total'=>count($pages),
				'custom_renderers'=>count(array_filter($pages, static fn(array $page): bool => ($page['rendering']['custom_renderer'] ?? false)===true)),
				'tables'=>array_sum(array_map(static fn(array $page): int => (int)($page['capabilities']['tables']['total'] ?? 0), $pages)),
			],
			'widgets'=>[
				'total'=>count($widgets),
				'lazy'=>count(array_filter($widgets, static fn(array $widget): bool => ($widget['data']['lazy'] ?? false)===true)),
				'charts'=>count(array_filter($widgets, static fn(array $widget): bool => ($widget['capabilities']['chart']['enabled'] ?? false)===true)),
			],
			'widget_runtime'=>[
				'adapters'=>count(is_array($widgetRuntime['adapters'] ?? null) ? $widgetRuntime['adapters'] : []),
				'instance_scoped'=>($widgetRuntime['capabilities']['instance_scoped'] ?? false)===true,
				'persistent_binding_keys'=>($widgetRuntime['capabilities']['persistent_binding_keys'] ?? false)===true,
				'reactor_bridge'=>($widgetRuntime['capabilities']['reactor_bridge'] ?? false)===true,
			],
			'data_sources'=>[
				'configured'=>($dataSources['attachment']['configured']??false)===true,
				'sources'=>(int)($dataSources['count']??0),
				'contributor_layers'=>($dataSources['capabilities']['contributor_layers']??false)===true,
				'transactional_checkpoint'=>($dataSources['capabilities']['transactional_checkpoint']??false)===true,
				'live_adapter_code_run_by_manifest'=>($dataSources['capabilities']['live_adapter_code_run_by_manifest']??true)===true,
			],
			'data_surfaces'=>[
				'configured'=>($dataSurfaces['attachment']['configured']??false)===true,
				'definitions'=>(int)($dataSurfaces['count']??0),
				'contributor_layers'=>($dataSurfaces['capabilities']['contributor_layers']??false)===true,
				'transactional_checkpoint'=>($dataSurfaces['capabilities']['transactional_checkpoint']??false)===true,
			],
			'realtime'=>[
				'configured'=>($realtime['attachment']['configured']??false)===true,
				'transport'=>(string)($realtime['endpoint']['transport']??$realtime['client']['transport']??'sse'),
				'host_route_required'=>($realtime['integration']['host_route_required']??true)===true,
				'host_authorization_required'=>($realtime['integration']['host_authorization_required']??true)===true,
				'routes_registered'=>($realtime['integration']['routes_registered']??false)===true,
			],
			'agent_workflows'=>[
				'configured'=>($agentWorkflows['attachment']['configured']??false)===true,
				'model_client_registered'=>($agentWorkflows['integration']['model_client_registered']??false)===true,
				'identity_inferred'=>($agentWorkflows['integration']['identity_inferred']??false)===true,
				'host_authorization_required'=>($agentWorkflows['integration']['host_authorization_required']??true)===true,
				'routes_registered'=>($agentWorkflows['integration']['routes_registered']??false)===true,
			],
			'studio_editor'=>[
				'available'=>($studioEditor['type']??null)==='panel_studio_editor'&&($studioEditor['version']??null)===1,
				'routes_registered'=>($studioEditor['integration']['routes_registered']??false)===true,
				'host_transport_required'=>($studioEditor['integration']['host_transport_required']??true)===true,
				'ssr_no_js'=>($studioEditor['renderer']['contracts']['ssr_no_js']??false)===true,
			],
			'navigation'=>$navigation['counts'] ?? [],
			'commands'=>[
				'total'=>count($commands),
				'groups'=>count(array_unique(array_map(static fn(array $command): string => (string)($command['group'] ?? 'Commands'), $commands))),
			],
			'theme'=>$theme['capabilities'] ?? [],
			'plugins'=>[
				'total'=>count($plugins),
			],
			'tenant'=>$tenant['capabilities'] ?? [],
			'permission'=>[
				'enabled'=>($permission['enabled'] ?? false)===true,
				'available'=>($permission['available'] ?? false)===true,
				'catalog_count'=>(int)($permission['catalog_count'] ?? 0),
				'resource_permissions'=>(int)($permission['counts']['by_type']['resource'] ?? 0),
				'action_permissions'=>(int)($permission['counts']['by_type']['action'] ?? 0),
				'relation_permissions'=>(int)($permission['counts']['by_type']['relation'] ?? 0),
			],
			'platform'=>[
				'configured'=>($platform['attachment']['configured'] ?? false)===true,
				'domains'=>(int)($platform['counts']['domains'] ?? 0),
				'configured_domains'=>(int)($platform['counts']['configured'] ?? 0),
				'ready_domains'=>(int)($platform['counts']['ready'] ?? 0),
				'services'=>(int)($platform['counts']['services'] ?? 0),
			],
		];
	}

	/**
	 * Normalizes legacy action arrays into compact action definition summaries.
	 *
	 * @param array<int,array<string,mixed>|mixed> $actions Action definition arrays.
	 * @return array<string,array<string,mixed>> Action summaries keyed by normalized action name.
	 */
	private static function actionDefinitions(array $actions): array {
		$rows=[];
		foreach($actions as $index=>$action){
			if(!is_array($action)){
				continue;
			}
			$name=(string)($action['name'] ?? 'action_'.$index);
			$rows[$name]=[
				'name'=>$name,
				'label'=>(string)($action['label'] ?? self::humanize($name)),
				'kind'=>(string)($action['type'] ?? $action['kind'] ?? 'action'),
				'tone'=>(string)($action['tone'] ?? 'neutral'),
				'modal'=>($action['modal'] ?? false)===true,
				'bulk'=>($action['bulk'] ?? false)===true,
				'fields'=>is_array($action['fields']['fields'] ?? null) ? count($action['fields']['fields']) : 0,
				'effects'=>is_array($action['effects'] ?? null) ? $action['effects'] : [],
			];
		}
		return $rows;
	}

	/**
	 * Normalizes legacy widget arrays into full widget manifests.
	 *
	 * @param array<int,array<string,mixed>|mixed> $widgets Widget definition arrays.
	 * @return array<string,array<string,mixed>> Widget manifests keyed by widget name.
	 */
	private static function widgetDefinitions(array $widgets): array {
		$rows=[];
		foreach($widgets as $index=>$widget){
			if(!is_array($widget)){
				continue;
			}
			$manifest=WidgetManifest::from($widget, null, [
				'surface'=>'panel_manifest',
			])->toArray();
			$rows[(string)($manifest['name'] ?? 'widget_'.$index)]=$manifest;
		}
		return $rows;
	}

	/**
	 * Resolves the human-readable panel label for manifest fallbacks.
	 *
	 * @return string Brand name, panel name, or generic Panel label.
	 */
	private function panelLabel(): string {
		if(!$this->panel instanceof PanelInstance){
			return 'Panel';
		}
		$theme=$this->panel->theme()->toArray();
		return (string)($theme['brand']['name'] ?? $this->panel->name() ?: 'Panel');
	}

	/**
	 * Converts machine names into display labels for manifest defaults.
	 *
	 * @param string $value Machine name.
	 * @return string Title-cased label or Panel when blank.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Panel' : ucwords($value);
	}
}
