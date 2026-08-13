<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Describes tenant scoping across Panel resources and search providers.
 *
 * The manifest is consumed by diagnostics and operator tooling to show which
 * resources are tenant-aware, how the current tenant was resolved, and whether
 * tenant propagation is active for links, forms, actions, and partial requests.
 */
final class TenantManifest {

	/**
	 * Stores the tenant manifest source and request context.
	 *
	 * @param PanelInstance|PanelManager|array<string, mixed>|null $source Panel source, manager, resource manifest data, or null for discovery.
	 * @param ?PanelRequest $request Request used to resolve the active tenant.
	 * @param array<string, mixed> $meta Caller metadata carried into the manifest.
	 */
	private function __construct(
		private readonly PanelInstance|PanelManager|array|null $source=null,
		private readonly ?PanelRequest $request=null,
		private readonly array $meta=[]
	){}

	/**
	 * Creates a tenant manifest descriptor from panel state or raw resource data.
	 *
	 * @param PanelInstance|PanelManager|array<string, mixed>|null $source Panel source, manager, resource manifest array, or null for global manifest discovery.
	 * @param ?PanelRequest $request Current panel request used to resolve active tenant.
	 * @param array<string, mixed> $meta Caller metadata included verbatim in the manifest.
	 * @return self Immutable manifest builder.
	 */
	public static function from(PanelInstance|PanelManager|array|null $source=null, ?PanelRequest $request=null, array $meta=[]): self {
		return new self($source, $request, $meta);
	}

	/**
	 * Builds the tenant-scoped resource summary for panel discovery.
	 */
	public function toArray(): array {
		$resources=$this->resourceManifests();
		$tenantResources=[];
		foreach($resources as $name=>$resource){
			$tenant=is_array($resource['tenant'] ?? null) ? $resource['tenant'] : [];
			if(($tenant['scoped'] ?? false)!==true){
				continue;
			}
			$tenantResources[(string)($resource['name'] ?? $name)]=[
				'name'=>(string)($resource['name'] ?? $name),
				'label'=>(string)($resource['plural_label'] ?? $resource['label'] ?? self::humanize((string)$name)),
				'field'=>(string)($tenant['field'] ?? 'tenant'),
				'required'=>($tenant['required'] ?? true)===true,
				'resolves'=>($tenant['resolves'] ?? false)===true,
				'custom_scope'=>($tenant['custom_scope'] ?? false)===true,
				'global_searchable'=>($resource['search']['global_searchable'] ?? false)===true,
				'queryable'=>($resource['data']['queryable'] ?? false)===true,
			];
		}
		$manager=$this->manager();
		$request=$this->request ?? PanelRequest::fromArray([]);
		$registry=$manager?->hasTenantRegistry()===true ? $manager->tenantRegistry() : null;
		$context=$registry instanceof PanelTenantRegistry ? $registry->context($request) : null;
		$current=$registry instanceof PanelTenantRegistry ? $context?->tenantKey() : $this->currentTenant();
		$switcher=$registry instanceof PanelTenantRegistry ? $registry->switcher($request) : [];
		$registryDescription=$registry?->describe() ?? [
			'tenant_count'=>0,
			'tenants'=>[],
			'visible_tenants'=>[],
			'membership_resolver'=>false,
			'authorization_resolver'=>false,
			'active_resolver'=>false,
			'persistence_callback'=>false,
			'entitlement_hook'=>false,
			'onboarding_steps'=>[],
			'implicit_io'=>false,
		];
		if($registry instanceof PanelTenantRegistry){
			$registryDescription['tenant_count']=count($switcher);
			$registryDescription['tenants']=$switcher;
			$registryDescription['visible_tenants']=$switcher;
		}
		$searchSource=$this->source instanceof PanelInstance || $this->source instanceof PanelManager ? $this->source : ['resources'=>$resources];
		$searchManifest=SearchManifest::from($searchSource, $this->request, null, 12, [
			'surface'=>'tenant_manifest',
		])->toArray();
		$tenantSearch=count(array_filter((array)($searchManifest['providers'] ?? []), static fn(array $provider): bool => ($provider['tenant_scoped'] ?? false)===true));
		$manifest=[
			'type'=>'tenant_manifest',
			'parameter'=>PanelConfig::tenantParameter(),
			'current'=>$current,
			'active'=>$context instanceof PanelTenantContext ? $context->isAuthorized() : ($current!==null && $current!==''),
			'context'=>[
				'code'=>$context?->code() ?? ($current!==null ? 'legacy' : 'tenant_unresolved'),
				'source'=>$context?->source() ?? ($current!==null ? 'legacy' : 'none'),
				'authorized'=>$context?->isAuthorized() ?? false,
			],
			'source'=>[
				'request_present'=>$this->request?->tenantKey()!==null,
				'configured_present'=>PanelConfig::currentTenantKey()!==null,
				'resolver'=>self::hasTenantResolver(),
				'registry'=>$registry instanceof PanelTenantRegistry,
			],
			'tenants'=>$switcher,
			'registry'=>$registryDescription,
			'resources'=>$tenantResources,
			'search'=>[
				'providers'=>$tenantSearch,
				'provider_names'=>array_values(array_keys(array_filter((array)($searchManifest['providers'] ?? []), static fn(array $provider): bool => ($provider['tenant_scoped'] ?? false)===true))),
			],
			'propagation'=>[
				'query_parameter'=>PanelConfig::tenantParameter(),
				'links'=>true,
				'forms'=>true,
				'actions'=>true,
				'exports'=>true,
				'imports'=>true,
				'global_search'=>true,
				'modals'=>true,
				'partial_requests'=>true,
			],
			'capabilities'=>[
				'lifecycle'=>[
					'manager_owned'=>$registry instanceof PanelTenantRegistry,
					'registered'=>(int)($registryDescription['tenant_count'] ?? 0),
					'visible'=>count($switcher),
					'explicit_membership'=>($registryDescription['membership_resolver'] ?? false)===true,
					'explicit_authorization'=>($registryDescription['authorization_resolver'] ?? false)===true,
					'active_resolution'=>($registryDescription['active_resolver'] ?? false)===true,
					'host_persistence'=>($registryDescription['persistence_callback'] ?? false)===true,
					'implicit_io'=>false,
				],
				'onboarding'=>[
					'steps'=>count((array)($registryDescription['onboarding_steps'] ?? [])),
					'idempotent'=>true,
					'compensating_rollback'=>true,
				],
				'storage'=>[
					'namespaced'=>true,
					'traversal_rejected'=>true,
					'link_aware_realpath'=>true,
				],
				'billing'=>[
					'entitlement_hook'=>($registryDescription['entitlement_hook'] ?? false)===true,
					'network_calls'=>false,
					'payment_operations'=>false,
				],
				'resources'=>[
					'total'=>count($tenantResources),
					'required'=>count(array_filter($tenantResources, static fn(array $resource): bool => ($resource['required'] ?? false)===true)),
					'optional'=>count(array_filter($tenantResources, static fn(array $resource): bool => ($resource['required'] ?? false)!==true)),
					'custom_resolvers'=>count(array_filter($tenantResources, static fn(array $resource): bool => ($resource['resolves'] ?? false)===true)),
					'custom_scopes'=>count(array_filter($tenantResources, static fn(array $resource): bool => ($resource['custom_scope'] ?? false)===true)),
				],
				'search'=>[
					'tenant_scoped_providers'=>$tenantSearch,
				],
				'request'=>[
					'active'=>$current!==null && $current!=='',
					'parameter'=>PanelConfig::tenantParameter(),
				],
			],
			'meta'=>PanelTenantSanitizer::map($this->meta),
		];
		PanelTrace::record('tenant.manifest.described', [
			'parameter'=>$manifest['parameter'],
			'active'=>$manifest['active'],
			'resources'=>count($tenantResources),
			'search_providers'=>$tenantSearch,
		]);
		return PanelManifestContract::stamp($manifest);
	}

	/**
	 * Resolves resource manifests from the configured source.
	 *
	 * @return array<string, array<string, mixed>> Resource manifests keyed by resource name.
	 */
	private function resourceManifests(): array {
		if($this->source instanceof PanelInstance || $this->source instanceof PanelManager){
			$resources=[];
			foreach($this->source->resources() as $name=>$resource){
				if($resource instanceof Resource){
					$manifest=$resource->resourceManifest($this->request, ['surface'=>'tenant_manifest']);
					$resources[(string)($manifest['name'] ?? $name)]=$manifest;
				}
			}
			return $resources;
		}
		if(is_array($this->source)){
			if(is_array($this->source['resources'] ?? null)){
				return array_filter($this->source['resources'], 'is_array');
			}
			return array_filter($this->source, 'is_array');
		}
		return PanelManifest::from(null, $this->request, ['surface'=>'tenant_manifest'])->toArray()['resources'] ?? [];
	}

	/**
	 * Resolves the current tenant from request state or panel configuration.
	 *
	 * @return ?string Active tenant key, or null when no tenant is selected.
	 */
	private function currentTenant(): ?string {
		$requestTenant=$this->request?->tenantKey();
		if($requestTenant!==null && trim($requestTenant)!==''){
			return PanelTenantSanitizer::tenantKey($requestTenant);
		}
		$configured=PanelConfig::currentTenantKey();
		return $configured!==null ? PanelTenantSanitizer::tenantKey($configured) : null;
	}

	private function manager(): ?PanelManager {
		if($this->source instanceof PanelManager){ return $this->source; }
		if($this->source instanceof PanelInstance){ return $this->source->manager(); }
		return null;
	}

	/**
	 * Reports whether a custom tenant resolver is configured.
	 *
	 * @return bool True when PanelConfig has a callable tenant_resolver.
	 */
	private static function hasTenantResolver(): bool {
		return is_callable(PanelConfig::config('tenant_resolver'));
	}

	/**
	 * Converts a resource key into a fallback tenant resource label.
	 *
	 * @param string $value Resource key.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Tenant resource' : ucwords($value);
	}
}
