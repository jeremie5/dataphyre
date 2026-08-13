<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Central registry and dispatcher for Dataphyre Panel operator interfaces.
 *
 * The manager owns registered resources, pages, widgets, navigation items,
 * commands, theme, authorization, global search, navigation state, command
 * state, and render/dispatch workflows for the active panel runtime.
 */
final class PanelManager {

	private static ?self $instance=null;

	/** @var array<string, Resource> */
	private array $resources=[];
	/** @var array<string, PanelPage> */
	private array $pages=[];
	/** @var array<string, Widget> */
	private array $widgets=[];
	/** @var array<string, NavigationItem> */
	private array $navigationItems=[];
	/** @var array<string, PanelCommand> */
	private array $commands=[];
	/** @var array<string, PanelSearchProvider> */
	private array $searchProviders=[];
	private ?PanelTenantRegistry $tenantRegistry=null;
	private ?PanelTheme $theme=null;
	private ?\Closure $authorizer=null;

	/**
	 * Returns the process-local panel manager singleton.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return self The same panel manager instance for fluent configuration.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the process-local manager so panel registries rebuild from configuration.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return void Runtime singleton, registry, validation, or context state is updated in place.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Captures the manager-owned contribution state used by plugin registration.
	 *
	 * This is an internal lifecycle primitive rather than a persistence format:
	 * object instances already registered before the checkpoint retain their
	 * identity, while mutable tenant/theme containers are cloned so additions or
	 * token mutations made by a failing plugin cannot leak through a rollback.
	 *
	 * @return array{
	 *     resources:array<string,Resource>,
	 *     pages:array<string,PanelPage>,
	 *     widgets:array<string,Widget>,
	 *     navigation_items:array<string,NavigationItem>,
	 *     commands:array<string,PanelCommand>,
	 *     search_providers:array<string,PanelSearchProvider>,
	 *     tenant_registry:?PanelTenantRegistry,
	 *     theme:?PanelTheme,
	 *     authorizer:?\Closure
	 * }
	 */
	public function contributionCheckpoint(): array {
		return [
			'resources'=>$this->resources,
			'pages'=>$this->pages,
			'widgets'=>$this->widgets,
			'navigation_items'=>$this->navigationItems,
			'commands'=>$this->commands,
			'search_providers'=>$this->searchProviders,
			'tenant_registry'=>$this->tenantRegistry instanceof PanelTenantRegistry ? clone $this->tenantRegistry : null,
			'theme'=>$this->theme instanceof PanelTheme ? clone $this->theme : null,
			'authorizer'=>$this->authorizer,
		];
	}

	/**
	 * Restores a contribution checkpoint without replacing this manager object.
	 *
	 * Keeping the manager identity stable is important because resources,
	 * providers, tenant registries, and host integrations may already hold it.
	 * The shape is validated before any state changes so malformed checkpoints
	 * cannot partially reset a live surface.
	 *
	 * @param array<string,mixed> $checkpoint Value returned by contributionCheckpoint().
	 */
	public function restoreContributionCheckpoint(array $checkpoint): self {
		$required=['resources','pages','widgets','navigation_items','commands','search_providers','tenant_registry','theme','authorizer'];
		foreach($required as $key){
			if(!array_key_exists($key, $checkpoint)){
				throw new \InvalidArgumentException('Invalid Panel manager contribution checkpoint.');
			}
		}
		if(!is_array($checkpoint['resources']) || !is_array($checkpoint['pages']) || !is_array($checkpoint['widgets']) || !is_array($checkpoint['navigation_items']) || !is_array($checkpoint['commands']) || !is_array($checkpoint['search_providers'])){
			throw new \InvalidArgumentException('Invalid Panel manager contribution checkpoint.');
		}
		if($checkpoint['tenant_registry']!==null && !$checkpoint['tenant_registry'] instanceof PanelTenantRegistry){
			throw new \InvalidArgumentException('Invalid Panel manager contribution checkpoint.');
		}
		if($checkpoint['theme']!==null && !$checkpoint['theme'] instanceof PanelTheme){
			throw new \InvalidArgumentException('Invalid Panel manager contribution checkpoint.');
		}
		if($checkpoint['authorizer']!==null && !$checkpoint['authorizer'] instanceof \Closure){
			throw new \InvalidArgumentException('Invalid Panel manager contribution checkpoint.');
		}

		$this->resources=$checkpoint['resources'];
		$this->pages=$checkpoint['pages'];
		$this->widgets=$checkpoint['widgets'];
		$this->navigationItems=$checkpoint['navigation_items'];
		$this->commands=$checkpoint['commands'];
		$this->searchProviders=$checkpoint['search_providers'];
		$this->tenantRegistry=$checkpoint['tenant_registry'];
		$this->theme=$checkpoint['theme'];
		$this->authorizer=$checkpoint['authorizer'];
		return $this;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param Resource|array $resource Resource object or array definition registered/rendered by the manager.
	 * @return Resource Panel manager object described by the native return type.
	 */
	public function register(Resource|array $resource): Resource {
		$resource=$resource instanceof Resource ? $resource : Resource::fromArray($resource);
		$name=$resource->name();
		if($name===''){
			throw new \InvalidArgumentException('Panel resources require a stable name.');
		}
		$this->resources[$name]=$resource;
		PanelTrace::record('resource.registered', [
			'resource'=>$resource,
		]);
		return $resource;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param list<Resource|array<string,mixed>> $resources Resource definitions registered as a batch.
	 * @return list<Resource> Registered resources in input order.
	 */
	public function registerMany(array $resources): array {
		$registered=[];
		foreach($resources as $resource){
			if($resource instanceof Resource || is_array($resource)){
				$registered[]=$this->register($resource);
			}
		}
		return $registered;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param PanelPage|array $page Panel page object(s) or array definition(s).
	 * @return PanelPage Panel manager object described by the native return type.
	 */
	public function registerPage(PanelPage|array $page): PanelPage {
		$page=$page instanceof PanelPage ? $page : PanelPage::fromArray($page);
		$name=$page->name();
		if($name===''){
			throw new \InvalidArgumentException('Panel pages require a stable name.');
		}
		$this->pages[$name]=$page;
		PanelTrace::record('page.registered', [
			'page'=>$page,
		]);
		return $page;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param list<PanelPage|array<string,mixed>> $pages Panel page object(s) or array definition(s).
	 * @return list<PanelPage> Registered pages in input order.
	 */
	public function registerPages(array $pages): array {
		$registered=[];
		foreach($pages as $page){
			if($page instanceof PanelPage || is_array($page)){
				$registered[]=$this->registerPage($page);
			}
		}
		return $registered;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param Widget|array $widget Widget object(s) or array definition(s).
	 * @return Widget Panel manager object described by the native return type.
	 */
	public function registerWidget(Widget|array $widget): Widget {
		$widget=$widget instanceof Widget ? $widget : Widget::fromArray($widget);
		$name=$widget->name();
		if($name===''){
			throw new \InvalidArgumentException('Panel widgets require a stable name.');
		}
		$this->widgets[$name]=$widget;
		PanelTrace::record('widget.registered', [
			'widget'=>$widget,
		]);
		return $widget;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param list<Widget|array<string,mixed>> $widgets Widget object(s) or array definition(s).
	 * @return list<Widget> Registered widgets in input order.
	 */
	public function registerWidgets(array $widgets): array {
		$registered=[];
		foreach($widgets as $widget){
			if($widget instanceof Widget || is_array($widget)){
				$registered[]=$this->registerWidget($widget);
			}
		}
		return $registered;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param NavigationItem|array $item Navigation item object(s) or array definition(s).
	 * @return NavigationItem Panel manager object described by the native return type.
	 */
	public function registerNavigationItem(NavigationItem|array $item): NavigationItem {
		$item=$item instanceof NavigationItem ? $item : NavigationItem::fromArray($item);
		$name=$item->name();
		if($name===''){
			throw new \InvalidArgumentException('Panel navigation items require a stable name.');
		}
		$this->navigationItems[$name]=$item;
		PanelTrace::record('navigation_item.registered', [
			'item'=>$item,
		]);
		return $item;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param list<NavigationItem|array<string,mixed>> $items Navigation item object(s) or array definition(s).
	 * @return list<NavigationItem> Registered navigation items in input order.
	 */
	public function registerNavigationItems(array $items): array {
		$registered=[];
		foreach($items as $item){
			if($item instanceof NavigationItem || is_array($item)){
				$registered[]=$this->registerNavigationItem($item);
			}
		}
		return $registered;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param PanelCommand|array $command Panel command object(s) or array definition(s).
	 * @return PanelCommand Panel manager object described by the native return type.
	 */
	public function registerCommand(PanelCommand|array $command): PanelCommand {
		$command=$command instanceof PanelCommand ? $command : PanelCommand::fromArray($command);
		$name=$command->name();
		if($name===''){
			throw new \InvalidArgumentException('Panel commands require a stable name.');
		}
		$this->commands[$name]=$command;
		PanelTrace::record('command.registered', [
			'command'=>$command,
		]);
		return $command;
	}

	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param list<PanelCommand|array<string,mixed>> $commands Panel command object(s) or array definition(s).
	 * @return list<PanelCommand> Registered commands in input order.
	 */
	public function registerCommands(array $commands): array {
		$registered=[];
		foreach($commands as $command){
			if($command instanceof PanelCommand || is_array($command)){
				$registered[]=$this->registerCommand($command);
			}
		}
		return $registered;
	}

	/** Registers one custom global-search provider. */
	public function registerSearchProvider(PanelSearchProvider|array $provider): PanelSearchProvider {
		$provider=$provider instanceof PanelSearchProvider ? $provider : PanelSearchProvider::fromArray($provider);
		if($provider->name()===''){
			throw new \InvalidArgumentException('Panel search providers require a stable name.');
		}
		$this->searchProviders[$provider->name()]=$provider;
		PanelTrace::record('search_provider.registered', ['provider'=>$provider->name()]);
		return $provider;
	}

	/**
	 * @param list<PanelSearchProvider|array<string,mixed>> $providers
	 * @return list<PanelSearchProvider>
	 */
	public function registerSearchProviders(array $providers): array {
		$registered=[];
		foreach($providers as $provider){
			if($provider instanceof PanelSearchProvider || is_array($provider)){
				$registered[]=$this->registerSearchProvider($provider);
			}
		}
		return $registered;
	}

	/** Returns the tenant registry owned exclusively by this manager. */
	public function tenantRegistry(): PanelTenantRegistry {
		return $this->tenantRegistry ??= new PanelTenantRegistry($this);
	}

	public function hasTenantRegistry(): bool {
		return $this->tenantRegistry instanceof PanelTenantRegistry && $this->tenantRegistry->all()!==[];
	}

	public function registerTenant(PanelTenant|array $tenant): PanelTenant {
		return $this->tenantRegistry()->register($tenant);
	}

	/** @param list<PanelTenant|array<string,mixed>> $tenants @return list<PanelTenant> */
	public function registerTenants(array $tenants): array { return $this->tenantRegistry()->registerMany($tenants); }

	public function tenant(string $name): ?PanelTenant { return $this->tenantRegistry?->tenant($name); }
	public function hasTenant(string $name): bool { return $this->tenant($name) instanceof PanelTenant; }
	/** @return array<string,PanelTenant> */
	public function tenants(): array { return $this->tenantRegistry?->all() ?? []; }

	public function tenantMembershipsUsing(callable $resolver): self { $this->tenantRegistry()->membershipsUsing($resolver); return $this; }
	public function tenantAuthorizationUsing(callable $resolver): self { $this->tenantRegistry()->authorizeUsing($resolver); return $this; }
	public function tenantActiveUsing(callable $resolver): self { $this->tenantRegistry()->activeUsing($resolver); return $this; }
	public function tenantPersistenceUsing(callable $resolver): self { $this->tenantRegistry()->persistUsing($resolver); return $this; }
	public function tenantEntitlementUsing(callable $resolver): self { $this->tenantRegistry()->entitlementUsing($resolver); return $this; }
	public function tenantOnboardingStep(string $name, callable $apply, ?callable $rollback=null): self { $this->tenantRegistry()->onboardingStep($name,$apply,$rollback); return $this; }

	/** @return array<string,PanelTenantMembership> */
	public function tenantMemberships(PanelRequest $request): array { return $this->tenantRegistry()->memberships($request); }
	public function tenantContext(PanelRequest $request): PanelTenantContext { return $this->tenantRegistry()->context($request); }
	public function switchTenant(string $tenant, PanelRequest $request): PanelTenantSwitchResult { return $this->tenantRegistry()->switch($tenant,$request); }
	/** @return list<array<string,mixed>> */
	public function tenantSwitcher(PanelRequest $request): array { return $this->tenantRegistry?->switcher($request) ?? []; }
	public function onboardTenant(PanelTenant|array $tenant, PanelRequest $request, string $idempotencyKey): PanelTenantOnboardingResult { return $this->tenantRegistry()->onboard($tenant,$request,$idempotencyKey); }
	/** @param string|list<string> $namespace */
	public function tenantStorageScope(string $tenant, string|array $namespace, PanelRequest $request): PanelTenantStorageScope { return $this->tenantRegistry()->storageScope($tenant,$namespace,$request); }

	/**
	 * Reads or replaces the active PanelTheme from theme objects, presets, arrays, or string names.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param PanelTheme|PanelThemePreset|array|string|null $theme Theme object, preset, array, string name, or null to read the current theme.
	 * @return PanelTheme Resolved active panel theme.
	 */
	public function theme(PanelTheme|PanelThemePreset|array|string|null $theme=null): PanelTheme {
		if($theme===null){
			return $this->theme ??= PanelTheme::make();
		}
		if($theme instanceof PanelThemePreset){
			$this->theme=$theme->toTheme();
		}
		elseif(is_string($theme)){
			$this->theme=PanelTheme::namedTheme($theme) ?? PanelTheme::make($theme);
		}
		elseif(is_array($theme)){
			$this->theme=PanelTheme::fromArray($theme);
		}
		else {
			$this->theme=$theme;
		}
		PanelTrace::record('theme.registered', [
			'theme'=>$this->theme->toArray(),
		]);
		return $this->theme;
	}

	/**
	 * Installs a manager-level authorization callback used by dispatch and render operations.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param callable $authorizer Callable used to authorize panel abilities.
	 * @return self The same panel manager instance for fluent configuration.
	 */
	public function authorize(callable $authorizer): self {
		$this->authorizer=\Closure::fromCallable($authorizer);
		PanelTrace::record('panel.authorizer_registered');
		return $this;
	}

	/**
	 * Looks up registered resources or pages by normalized name.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param string $name Resource, page, widget, command, header, or registry name after normalization.
	 * @return ?Resource Panel manager object described by the native return type.
	 */
	public function get(string $name): ?Resource {
		$name=Resource::normalizeName($name);
		return $this->resources[$name] ?? null;
	}

	/**
	 * Looks up registered resources or pages by normalized name.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param string $name Resource, page, widget, command, header, or registry name after normalization.
	 * @return ?PanelPage Panel manager object described by the native return type.
	 */
	public function getPage(string $name): ?PanelPage {
		$name=Resource::normalizeName($name);
		return $this->pages[$name] ?? null;
	}

	/**
	 * Looks up registered resources or pages by normalized name.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param string $name Resource, page, widget, command, header, or registry name after normalization.
	 * @return bool True when a registry entry exists or authorization permits access.
	 */
	public function has(string $name): bool {
		return $this->get($name) instanceof Resource;
	}

	/**
	 * Looks up registered resources or pages by normalized name.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param string $name Resource, page, widget, command, header, or registry name after normalization.
	 * @return bool True when a registry entry exists or authorization permits access.
	 */
	public function hasPage(string $name): bool {
		return $this->getPage($name) instanceof PanelPage;
	}

	/** @return array<string, Resource> */
	/**
	 * Returns normalized registry snapshots for rendering, manifests, and tests.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function resources(): array {
		return $this->resources;
	}

	/** Returns a custom provider by normalized name. */
	public function searchProvider(string $name): ?PanelSearchProvider {
		return $this->searchProviders[Resource::normalizeName($name)] ?? null;
	}

	public function hasSearchProvider(string $name): bool {
		return $this->searchProvider($name) instanceof PanelSearchProvider;
	}

	/**
	 * Returns registered custom providers, optionally filtered through request
	 * visibility and authorization without exposing denied provider identities.
	 *
	 * @return array<string,PanelSearchProvider>
	 */
	public function searchProviders(?PanelRequest $request=null, bool $visibleOnly=false): array {
		if(!$visibleOnly){ return $this->searchProviders; }
		$request ??= PanelRequest::fromArray([]);
		return array_filter($this->searchProviders, fn(PanelSearchProvider $provider): bool=>$this->allowsSearchProvider($provider, $request));
	}

	/** @return array<string,PanelSearchProvider> */
	public function registeredSearchProviders(): array { return $this->searchProviders; }

	/** @return array<string, PanelPage> */
	/**
	 * Returns normalized registry snapshots for rendering, manifests, and tests.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function pages(): array {
		return $this->pages;
	}

	/**
	 * Builds visible widget state payloads for the current request.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function widgetStates(?PanelRequest $request=null): array {
		$states=array_map(static fn(Widget $widget): PanelWidgetState => $widget->state($request, ['scope'=>'dashboard']), array_values($this->widgets));
		foreach($this->resources as $resource){
			if($resource->can('dashboard_widgets', null, $request?->user())===false){
				continue;
			}
			foreach($resource->dashboardStatusWidgets($request) as $widget){
				$states[]=PanelWidgetState::fromResolved($widget, [
					'scope'=>'dashboard',
					'source'=>'resource_status',
					'resource'=>$resource->name(),
					'request'=>$request?->toArray(),
				]);
			}
		}
		usort($states, static function(PanelWidgetState $left, PanelWidgetState $right): int {
			$leftWidget=$left->widget();
			$rightWidget=$right->widget();
			return [(int)($leftWidget['sort'] ?? 100), (string)($leftWidget['label'] ?? '')] <=> [(int)($rightWidget['sort'] ?? 100), (string)($rightWidget['label'] ?? '')];
		});
		PanelTrace::record('widgets.state', [
			'scope'=>'dashboard',
			'count'=>count($states),
			'widgets'=>$states,
		]);
		return $states;
	}

	/**
	 * Returns normalized registry snapshots for rendering, manifests, and tests.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function widgets(?PanelRequest $request=null): array {
		return array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize(), $this->widgetStates($request));
	}

	/** @return array<string, NavigationItem> */
	/**
	 * Returns normalized registry snapshots for rendering, manifests, and tests.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function navigationItems(): array {
		return $this->navigationItems;
	}

	/** @return array<string, PanelCommand> */
	/**
	 * Registers panel resources, pages, widgets, navigation items, or commands and records trace metadata.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function registeredCommands(): array {
		return $this->commands;
	}

	/**
	 * Searches authorized resource and custom providers through the bounded coordinator.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param string $query Search string or query array for panel navigation/commands/global search.
	 * @param PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @param int $limit Maximum number of search results returned.
	 * @return list<array<string,mixed>> Backward-compatible normalized search rows.
	 */
	public function globalSearch(string $query, PanelRequest $request, int $limit=12): array {
		return $this->globalSearchPage($query, $request, $limit)->toArray()['results'];
	}

	/** Executes deterministic, diagnostic global search and returns its page contract. */
	public function globalSearchPage(string $query, PanelRequest $request, int $limit=12, string|array|null $cursor=null): PanelSearchPage {
		$request=$this->resolvedTenantRequest($request);
		if(!$request instanceof PanelRequest){
			return PanelSearchPage::make(meta:['tenant_context'=>'denied','tenant_registry'=>true]);
		}
		return $this->searchCoordinator()->search($query, $request, $limit, $cursor);
	}

	public function searchCoordinator(): PanelSearchCoordinator {
		return new PanelSearchCoordinator($this);
	}

	/** Security boundary used by the coordinator for custom providers. */
	public function allowsSearchProvider(PanelSearchProvider $provider, PanelRequest $request): bool {
		$request=$this->resolvedTenantRequest($request);
		if(!$request instanceof PanelRequest){
			PanelTrace::record('global_search.provider_authorized', ['provider'=>$provider->name(), 'allowed'=>false, 'tenant_context'=>'denied']);
			return false;
		}
		if(!$this->canAccess('global_search', null, $request)){
			PanelTrace::record('global_search.provider_authorized', ['provider'=>$provider->name(), 'allowed'=>false]+self::tenantTraceContext($request->tenantKey()));
			return false;
		}
		if(!$provider->isVisible($request, $this)){
			PanelTrace::record('global_search.provider_hidden', ['provider'=>$provider->name()]);
			return false;
		}
		$allowed=$provider->isAuthorized($request, $this);
		PanelTrace::record('global_search.provider_authorized', ['provider'=>$provider->name(), 'allowed'=>$allowed]+self::tenantTraceContext($request->tenantKey()));
		return $allowed;
	}

	/** Security boundary used by the coordinator for resource-backed providers. */
	public function allowsSearchResource(Resource $resource, PanelRequest $request): bool {
		$request=$this->resolvedTenantRequest($request);
		if(!$request instanceof PanelRequest){
			PanelTrace::record('global_search.resource_authorized', ['resource'=>$resource->name(), 'allowed'=>false, 'tenant_context'=>'denied']);
			return false;
		}
		$allowed=$resource->isGlobalSearchable()
			&& $this->canAccess('global_search', $resource, $request)
			&& $resource->can('global_search', null, $request->user())!==false;
		PanelTrace::record('global_search.resource_authorized', ['resource'=>$resource->name(), 'allowed'=>$allowed]+self::tenantTraceContext($request->tenantKey()));
		return $allowed;
	}

	/**
	 * Builds navigation state from registered items, resources, pages, search, and request context.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @param array<string,mixed> $search Search payload attached to navigation state.
	 * @return PanelNavigationState Panel manager object described by the native return type.
	 */
	public function navigationState(?PanelRequest $request=null, array $search=[]): PanelNavigationState {
		$request ??= PanelRequest::fromArray([]);
		$switcher=$this->tenantSwitcher($request);
		$resolvedRequest=$this->resolvedTenantRequest($request);
		if(!$resolvedRequest instanceof PanelRequest){
			return PanelNavigationState::make([], $request->withTenant(null), $search, [
				'resources'=>count($this->resources),
				'pages'=>count($this->pages),
				'custom_items'=>count($this->navigationItems),
				'tenant_context'=>'denied',
				'tenant_switcher'=>$switcher,
			]);
		}
		$request=$resolvedRequest;
		$entries=[];
		foreach($this->resources as $resource){
			if($resource->isHiddenFromNavigation()){
				continue;
			}
			if($this->canAccess('view_any', $resource, $request ?? PanelRequest::fromArray([]))===false || $resource->can('view_any', null, $request?->user())===false){
				continue;
			}
			$entry=$resource->navigationEntry($request, $this);
			$entry['kind']='resource';
			$entries[]=$entry;
		}
		foreach($this->pages as $page){
			if($page->isHiddenFromNavigation()){
				continue;
			}
			if($page->can('view', $request?->user(), $request)===false){
				continue;
			}
			$entries[]=$page->navigationEntry($request, $this);
		}
		foreach($this->navigationItems as $item){
			if(!$item->isVisible($request, $this)){
				continue;
			}
			$entries[]=$item->navigationEntry($request, $this);
		}
		$state=PanelNavigationState::make($entries, $request, $search, [
			'resources'=>count($this->resources),
			'pages'=>count($this->pages),
			'custom_items'=>count($this->navigationItems),
			'tenant_switcher'=>$switcher,
		]);
		PanelTrace::record('navigation.state', [
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Builds navigation state from registered items, resources, pages, search, and request context.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function navigation(?PanelRequest $request=null): array {
		return $this->navigationState($request)->entries();
	}

	/**
	 * Builds command-palette entries from base commands, resources, and registered commands.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @param ?string $query Search string or query array for panel navigation/commands/global search.
	 * @return PanelCommandState Panel manager object described by the native return type.
	 */
	public function commandState(?PanelRequest $request=null, ?string $query=null): PanelCommandState {
		$request=$request ?? PanelRequest::fromArray([]);
		$resolvedRequest=$this->resolvedTenantRequest($request);
		$commands=$resolvedRequest instanceof PanelRequest ? $this->commandEntries($resolvedRequest) : [];
		$request=$resolvedRequest ?? $request->withTenant(null);
		$state=PanelCommandState::make($commands, $request, $query, [
			'registered_commands'=>count($this->commands),
			'resources'=>count($this->resources),
			'pages'=>count($this->pages),
			'navigation_items'=>count($this->navigationItems),
			'tenant_context'=>$resolvedRequest instanceof PanelRequest ? 'authorized' : 'denied',
		]);
		PanelTrace::record('commands.state', [
			'state'=>$state,
		]);
		return $state;
	}

	/**
	 * Builds command-palette entries from base commands, resources, and registered commands.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param ?PanelRequest $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @param ?string $query Search string or query array for panel navigation/commands/global search.
	 * @return array Registry snapshot, search results, command entries, navigation payload, manifest data, or record list.
	 */
	public function commands(?PanelRequest $request=null, ?string $query=null): array {
		return $this->commandState($request, $query)->commands();
	}

	/**
	 * Serializes the manager registry into a manifest payload.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @return array<string,mixed> Registry snapshot with resources, pages, widgets, commands, navigation, and theme data.
	 */
	public function describe(): array {
		return [
			'resources'=>array_map(
				static fn(Resource $resource): array => $resource->toArray(),
				array_values($this->resources)
			),
			'global_searchable_resources'=>array_values(array_map(
				static fn(Resource $resource): string => $resource->name(),
				array_filter($this->resources, static fn(Resource $resource): bool => $resource->isGlobalSearchable())
			)),
			'search_providers'=>array_map(
				static fn(PanelSearchProvider $provider): array=>$provider->toArray(),
				array_values($this->searchProviders)
			),
			'search_provider_count'=>count($this->searchProviders),
			'tenant_registry'=>$this->tenantRegistry?->describe() ?? [
				'tenant_count'=>0,
				'tenants'=>[],
				'visible_tenants'=>[],
				'implicit_io'=>false,
			],
			'tenant_count'=>count($this->tenantRegistry?->all() ?? []),
			'widgets'=>array_map(
				static fn(Widget $widget): array => $widget->toArray(),
				array_values($this->widgets)
			),
			'pages'=>array_map(
				static fn(PanelPage $page): array => $page->toArray(),
				array_values($this->pages)
			),
			'theme'=>$this->theme?->toArray(),
			'navigation_items'=>array_map(
				static fn(NavigationItem $item): array => $item->toArray(),
				array_values($this->navigationItems)
			),
			'navigation'=>$this->navigation(),
			'navigation_state'=>$this->navigationState()->jsonSerialize(),
			'commands'=>$this->commands(),
			'command_state'=>$this->commandState()->jsonSerialize(),
		];
	}

	/**
	 * Dispatches a panel request to resource/page operations with authorization and partial response handling.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param PanelRequest|array|null $request HTTP/API or Panel request carrying route, query, body, headers, cookies, and server data.
	 * @return PanelPageResult Rendered or dispatched panel result with status, data, headers, notifications, and HTML.
	 */
	public function dispatch(PanelRequest|array|null $request=null): PanelPageResult {
		$request=$request instanceof PanelRequest
			? $request
			: (is_array($request) ? PanelRequest::fromArray($request) : PanelRequest::capture());
		$resolvedTenantRequest=$this->resolvedTenantRequest($request);
		if(!$resolvedTenantRequest instanceof PanelRequest){
			PanelTrace::record('request.tenant_denied', ['has_tenant'=>$request->tenantKey()!==null]);
			return PanelPageResult::json(['error'=>'tenant_context_denied'], 403);
		}
		$request=$resolvedTenantRequest;
		$span=PanelTrace::begin('request.dispatch', [
			'request'=>$request,
		]);
		try{
			$resource=$request->resourceName()!==null ? $this->get($request->resourceName()) : null;
			$page=$request->resourceName()!==null ? $this->getPage($request->resourceName()) : null;
			if($resource instanceof Resource && $page instanceof PanelPage){
				$plainIndexRequest=$request->operation()==='index'
					&& $request->recordKey()===null
					&& $request->relationName()===null
					&& $request->actionName()===null;
				if(!$resource->isHiddenFromNavigation() || !$plainIndexRequest){
					$page=null;
				}
				else
				{
					$resource=null;
				}
			}
			$navigationBlocked=null;
			if(PanelNavigationIntentRuntime::privileged($request) && (is_string($request->input('return_to')) || is_string($request->query('return_to')))){
				$navigation=PanelNavigationIntentRuntime::resolve($request, true, true, self::navigationIntentExpected($request, $resource, $page));
				if($navigation->blocked()){
					$navigationBlocked=PanelPageResult::json([
						'error'=>'navigation_intent_rejected',
						'code'=>$navigation->verification()->code(),
					], 422);
				}
			}
			if($navigationBlocked instanceof PanelPageResult){
				$result=$navigationBlocked;
			}
			elseif($this->canAccess($request->operation(), $resource, $request)===false){
				$result=PanelRenderer::forbidden($resource, $request);
			}
			elseif($page instanceof PanelPage){
				if($page->can($request->operation(), $request->user(), $request)===false){
					$result=PanelRenderer::forbidden(null, $request);
				}
				elseif($request->operation()==='action'){
					$result=PanelRenderer::pageActionResult($page, $request, $request->actionName() ?? '', $this);
				}
				else
				{
					$result=PanelRenderer::customPage($page, $request, $this);
				}
			}
			elseif($resource===null){
				if($request->resourceName()!==null){
					$result=PanelRenderer::notFound($request);
				}
				elseif(($home=$this->configuredHomePage()) instanceof PanelPage){
					$result=$home->can($request->operation(), $request->user(), $request)
						? PanelRenderer::customPage($home, $request, $this)
						: PanelRenderer::forbidden(null, $request);
				}
				else{
					$result=PanelRenderer::dashboard($this, $request);
				}
			}
			else
			{
				$request=$this->requestWithResolvedResourceState($resource, $request);
				$operation=$request->operation();
				if(!self::operationUsesRecordPolicy($operation) && $resource->can($operation, null, $request->user())===false){
					$result=PanelRenderer::forbidden($resource, $request);
				}
				elseif(in_array($operation, ['export', 'bulk_export'], true) && !PanelConfig::resourceExportsEnabled()){
					$result=PanelRenderer::forbidden($resource, $request);
				}
				elseif(in_array($operation, ['import', 'import_template'], true) && !PanelConfig::resourceImportsEnabled()){
					$result=PanelRenderer::forbidden($resource, $request);
				}
				elseif($request->isPanelFieldStateRequest()){
					$result=PanelRenderer::fieldState($resource, $request, $this->findRecord($resource, $request));
				}
				elseif($request->isPanelFieldOptionsRequest()){
					$result=PanelRenderer::fieldOptions($resource, $request, $this->findRecord($resource, $request));
				}
				else
				{
					$result=match($operation){
						'create'=>PanelRenderer::form($resource, $request, null, 'create'),
						'store'=>PanelRenderer::saveResult($resource, $request, null, 'store'),
						'edit'=>PanelRenderer::form($resource, $request, $this->findRecord($resource, $request), 'edit'),
						'update'=>PanelRenderer::saveResult($resource, $request, $this->findRecord($resource, $request), 'update'),
						'inline_update'=>PanelRenderer::inlineUpdateResult($resource, $request, $this->findRecord($resource, $request)),
						'import'=>strtoupper($request->method())==='POST' ? PanelRenderer::importResult($resource, $request) : PanelRenderer::importForm($resource, $request),
						'import_template'=>PanelRenderer::importTemplateCsv($resource, $request),
						'board'=>PanelRenderer::statusBoard($resource, $request, ...$this->records($resource, $request, true)),
						'bulk_export'=>PanelRenderer::bulkExportCsv($resource, $request),
						'bulk_update'=>PanelRenderer::bulkUpdateResult($resource, $request),
						'transition'=>PanelRenderer::transitionResult($resource, $request, $this->findRecord($resource, $request)),
						'bulk_transition'=>PanelRenderer::bulkTransitionResult($resource, $request),
						'duplicate'=>PanelRenderer::duplicateResult($resource, $request, $this->findRecord($resource, $request)),
						'bulk_duplicate'=>PanelRenderer::bulkDuplicateResult($resource, $request),
						'restore'=>PanelRenderer::restoreResult($resource, $request, $this->findRecord($resource, $request)),
						'bulk_restore'=>PanelRenderer::bulkRestoreResult($resource, $request),
						'delete'=>PanelRenderer::deleteResult($resource, $request, $this->findRecord($resource, $request)),
						'bulk_delete'=>PanelRenderer::bulkDeleteResult($resource, $request),
						'force_delete'=>PanelRenderer::forceDeleteResult($resource, $request, $this->findRecord($resource, $request)),
						'bulk_force_delete'=>PanelRenderer::bulkForceDeleteResult($resource, $request),
						'show'=>PanelRenderer::show($resource, $request, $this->findRecord($resource, $request)),
						'approval'=>PanelRenderer::approvalResult($resource, $request, $this->findRecord($resource, $request)),
						'tag'=>PanelRenderer::tagResult($resource, $request, $this->findRecord($resource, $request)),
						'task'=>PanelRenderer::taskResult($resource, $request, $this->findRecord($resource, $request)),
						'note'=>PanelRenderer::noteResult($resource, $request, $this->findRecord($resource, $request)),
						'message'=>PanelRenderer::messageResult($resource, $request, $this->findRecord($resource, $request)),
						'attach'=>PanelRenderer::attachResult($resource, $request, $this->findRecord($resource, $request)),
						'relation'=>PanelRenderer::relation($resource, $request, $this->findRecord($resource, $request)),
						'action'=>PanelRenderer::actionResult($resource, $request, $request->actionName() ?? '', $this->findRecord($resource, $request)),
						'export'=>PanelRenderer::exportCsv($resource, $request, ...$this->records($resource, $request, true)),
						default=>PanelRenderer::index($resource, $request, ...$this->records($resource, $request)),
					};
				}
			}
			PanelTrace::end($span, [
				'resource'=>$request->resourceName(),
				'operation'=>$request->operation(),
				'result'=>$result,
			]);
			return $result;
		}
		catch(\Throwable $exception){
			PanelTrace::fail($span, $exception, [
				'resource'=>$request->resourceName(),
				'operation'=>$request->operation(),
			]);
			throw $exception;
		}
	}

	/** @return array<string,string> */
	private static function navigationIntentExpected(PanelRequest $request, ?Resource $resource=null, ?PanelPage $page=null): array {
		$expected=['operation'=>'return','outcome'=>'complete'];
		if($request->operation()!=='action' || $request->actionName()===null){ return $expected; }
		$action=$resource?->actionByName($request->actionName()) ?? $page?->actionByName($request->actionName());
		if(!$action instanceof Action){ return $expected; }
		$definition=$action->toArray();
		$intent=is_array($definition['navigation_intent'] ?? null) ? $definition['navigation_intent'] : [];
		if(($intent['enabled'] ?? true)!==true){ return $expected; }
		$expected['operation']=(string)($intent['operation'] ?? 'return');
		$expected['outcome']=(string)($intent['outcome'] ?? 'complete');
		if(is_string($intent['audience'] ?? null) && $intent['audience']!==''){ $expected['audience']=$intent['audience']; }
		return $expected;
	}

	/**
	 * Renders a resource operation into a PanelPageResult without a live HTTP cycle.
	 *
	 * PanelManager is the central runtime registry for operator UI: resources, pages, widgets, navigation, commands, theme, authorization, search, and dispatch all pass through this normalized surface.
	 *
	 * @param Resource|string|null $resource Resource object or array definition registered/rendered by the manager.
	 * @param string $operation Panel operation such as index, show, create, edit, store, update, or delete.
	 * @param array<string,mixed> $context Render context such as query, headers, resource, record, tenant, and modal/fragment flags.
	 * @return PanelPageResult Rendered or dispatched panel result with status, data, headers, notifications, and HTML.
	 */
	public function render(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		PanelTrace::record('request.render', [
			'resource'=>is_string($resource) ? $resource : $resource,
			'operation'=>$operation,
			'context_keys'=>array_keys($context),
		]);
		if($resource===null){
			$request=PanelRequest::fromArray($context);
			$request=$this->resolvedTenantRequest($request);
			if(!$request instanceof PanelRequest){ return PanelPageResult::json(['error'=>'tenant_context_denied'], 403); }
			if($this->canAccess($request->operation(), null, $request)===false){
				return PanelRenderer::forbidden(null, $request);
			}
			if(($home=$this->configuredHomePage()) instanceof PanelPage){
				return $home->can($request->operation(), $request->user(), $request)
					? PanelRenderer::customPage($home, $request, $this)
					: PanelRenderer::forbidden(null, $request);
			}
			return PanelRenderer::dashboard($this, $request);
		}
		$page=null;
		if(is_string($resource)){
			$page=$this->getPage($resource);
		}
		$resource=$resource instanceof Resource ? $resource : $this->get((string)$resource);
		if($resource===null){
			if($page instanceof PanelPage){
				$request=PanelRequest::fromArray(array_replace($context, [
					'resource'=>$page->name(),
					'operation'=>$operation,
				]));
				$request=$this->resolvedTenantRequest($request);
				if(!$request instanceof PanelRequest){ return PanelPageResult::json(['error'=>'tenant_context_denied'], 403); }
				if($this->canAccess($request->operation(), null, $request)===false || $page->can($request->operation(), $request->user(), $request)===false){
					return PanelRenderer::forbidden(null, $request);
				}
				if($request->operation()==='action'){
					return PanelRenderer::pageActionResult($page, $request, $request->actionName() ?? '', $this);
				}
				return PanelRenderer::customPage($page, $request, $this);
			}
			return PanelPageResult::html('Panel resource or page not found.', 404);
		}
		$request=PanelRequest::fromArray(array_replace($context, [
			'resource'=>$resource->name(),
			'operation'=>$operation,
		]));
		$request=$this->resolvedTenantRequest($request);
		if(!$request instanceof PanelRequest){ return PanelPageResult::json(['error'=>'tenant_context_denied'], 403); }
		if($this->canAccess($request->operation(), $resource, $request)===false){
			return PanelRenderer::forbidden($resource, $request);
		}
		$request=$this->requestWithResolvedResourceState($resource, $request);
		if(!self::operationUsesRecordPolicy($request->operation()) && $resource->can($request->operation(), null, $request->user())===false){
			return PanelRenderer::forbidden($resource, $request);
		}
		return match($request->operation()){
			'create'=>PanelRenderer::form($resource, $request, null, 'create'),
			'edit'=>PanelRenderer::form($resource, $request, $context['record'] ?? null, 'edit'),
			'import'=>strtoupper($request->method())==='POST' ? PanelRenderer::importResult($resource, $request) : PanelRenderer::importForm($resource, $request),
			'import_template'=>PanelRenderer::importTemplateCsv($resource, $request),
			'board'=>PanelRenderer::statusBoard($resource, $request, $context['records'] ?? []),
			'bulk_export'=>PanelRenderer::bulkExportCsv($resource, $request),
			'bulk_update'=>PanelRenderer::bulkUpdateResult($resource, $request),
			'transition'=>PanelRenderer::transitionResult($resource, $request, $context['record'] ?? null),
			'bulk_transition'=>PanelRenderer::bulkTransitionResult($resource, $request),
			'duplicate'=>PanelRenderer::duplicateResult($resource, $request, $context['record'] ?? null),
			'bulk_duplicate'=>PanelRenderer::bulkDuplicateResult($resource, $request),
			'restore'=>PanelRenderer::restoreResult($resource, $request, $context['record'] ?? null),
			'bulk_restore'=>PanelRenderer::bulkRestoreResult($resource, $request),
			'delete'=>PanelRenderer::deleteResult($resource, $request, $context['record'] ?? null),
			'bulk_delete'=>PanelRenderer::bulkDeleteResult($resource, $request),
			'force_delete'=>PanelRenderer::forceDeleteResult($resource, $request, $context['record'] ?? null),
			'bulk_force_delete'=>PanelRenderer::bulkForceDeleteResult($resource, $request),
			'show'=>PanelRenderer::show($resource, $request, $context['record'] ?? null),
			'approval'=>PanelRenderer::approvalResult($resource, $request, $context['record'] ?? null),
			'tag'=>PanelRenderer::tagResult($resource, $request, $context['record'] ?? null),
			'task'=>PanelRenderer::taskResult($resource, $request, $context['record'] ?? null),
			'note'=>PanelRenderer::noteResult($resource, $request, $context['record'] ?? null),
			'message'=>PanelRenderer::messageResult($resource, $request, $context['record'] ?? null),
			'attach'=>PanelRenderer::attachResult($resource, $request, $context['record'] ?? null),
			'relation'=>PanelRenderer::relation($resource, $request, $context['record'] ?? null),
			'export'=>PanelRenderer::exportCsv($resource, $request, $context['records'] ?? []),
			default=>PanelRenderer::index($resource, $request, $context['records'] ?? []),
		};
	}

	/**
	 * Resolves records for a resource index, board, or export operation.
	 *
	 * The resource request is first normalized through the selected table/view
	 * state so query builders see the same filters, sorting, pagination, and
	 * view selection the renderer will display. Array-backed resources are
	 * returned directly; query objects are called through the first supported
	 * pagination or collection method.
	 *
	 * @param Resource $resource Resource whose query should be evaluated.
	 * @param PanelRequest $request Current panel request.
	 * @param bool $preferAll Whether collection methods should be preferred over
	 *     pagination methods, as in export workflows.
	 *
	 * @return array{0: array, 1: ?int, 2: bool, 3?: array<string,mixed>}
	 *     Records, optional total count, whether the source query paginated the
	 *     result, and optional structured data-source metadata.
	 */
	private function records(Resource $resource, PanelRequest $request, bool $preferAll=false): array {
		$request=$this->requestWithResolvedResourceState($resource, $request);
		$query=$resource->makeQuery($request);
		if($query===null){
			return [[], null, false];
		}
		if($query instanceof PanelDataResult){
			return self::dataResultRecords($query);
		}
		if(is_array($query)){
			return [$query, null, false];
		}
		$methods=$preferAll
			? ['getRecords', 'get', 'paginateRecords', 'paginate']
			: ['paginateRecords', 'paginate', 'getRecords', 'get'];
		foreach($methods as $method){
			if(!method_exists($query, $method)){
				continue;
			}
			$result=$method==='paginateRecords' || $method==='paginate'
				? $query->{$method}($request->page(), $request->perPage($resource->resourceTable()->defaultPerPage()))
				: $query->{$method}();
			if($result instanceof PanelDataResult){
				return self::dataResultRecords($result);
			}
			$total=null;
			foreach(['total', 'totalRecords', 'count'] as $counter){
				if(is_object($result) && method_exists($result, $counter)){
					$total=(int)$result->{$counter}();
					break;
				}
			}
			if(is_object($result) && method_exists($result, 'items')){
				$result=$result->items();
			}
			$records=is_array($result) ? $result : [];
			return [$records, $total, $method==='paginateRecords' || $method==='paginate'];
		}
		return [[], null, false];
	}

	/**
	 * Converts a canonical data-source result into the renderer tuple without
	 * discarding its total, cursors, aggregates, inclusions, or adapter metadata.
	 *
	 * @return array{0:list<mixed>,1:?int,2:true,3:array<string,mixed>}
	 */
	private static function dataResultRecords(PanelDataResult $result): array {
		$page=$result->page();
		return [
			$result->items(),
			$page->total(),
			true,
			[
				'type'=>'panel_data_result',
				'source'=>$result->source(),
				'page'=>$page->jsonSerialize(),
				'aggregates'=>$result->aggregates(),
				'included'=>$result->included(),
				'metadata'=>$result->metadata(),
			],
		];
	}

	/**
	 * Builds the full command palette payload for one request.
	 *
	 * Commands are composed from static workspace actions, visible navigation
	 * entries, resource-specific workflows, and registered custom commands.
	 * Visibility and authorization checks are performed before entries reach the
	 * client manifest.
	 *
	 * @param PanelRequest $request Current panel request.
	 *
	 * @return list<array<string,mixed>> Command entries consumed by
	 *     PanelCommandState and the browser command palette.
	 */
	private function commandEntries(PanelRequest $request): array {
		$entries=$this->baseCommandEntries($request);
		foreach($this->navigationState($request)->allEntries() as $entry){
			$url=trim((string)($entry['url'] ?? ''));
			if($url===''){
				continue;
			}
			$entries[]=[
				'name'=>'nav_'.Resource::normalizeName((string)($entry['name'] ?? '')),
				'label'=>(string)($entry['label'] ?? 'Open'),
				'group'=>'Navigation',
				'description'=>(string)($entry['description'] ?? 'Open this workspace area'),
				'icon'=>(string)($entry['icon'] ?? 'link'),
				'url'=>$url,
				'new_tab'=>($entry['new_tab'] ?? false)===true,
				'sort'=>200+(int)($entry['sort'] ?? 100),
				'keywords'=>[(string)($entry['kind'] ?? ''), (string)($entry['group'] ?? '')],
				'source'=>'navigation',
				'meta'=>[
					'entry'=>$entry,
				],
			];
		}
		foreach($this->resources as $resource){
			$entries=array_merge($entries, $this->resourceCommandEntries($resource, $request));
		}
		foreach($this->commands as $command){
			if(!$command->isVisible($request, $this)){
				continue;
			}
			$entry=$command->toArray($request, $this);
			$entry['source']='registered';
			$entries[]=$entry;
		}
		return $entries;
	}

	/**
	 * Returns commands that exist independently of registered resources.
	 *
	 * These workspace commands form the baseline command palette contract:
	 * returning home, focusing global search, and opening keyboard help. They
	 * are always available for the panel shell and may be filtered client-side.
	 *
	 * @param PanelRequest $request Current panel request, reserved for future
	 *     request-aware workspace commands.
	 *
	 * @return list<array<string,mixed>> Base command descriptors.
	 */
	private function baseCommandEntries(PanelRequest $request): array {
		return [
			[
				'name'=>'dashboard',
				'label'=>PanelConfig::homeLabel(),
				'group'=>'Workspace',
				'description'=>'Return to the panel overview.',
				'icon'=>'layout-dashboard',
				'url'=>PanelConfig::url(),
				'sort'=>10,
				'keywords'=>['home', 'overview', 'dashboard'],
				'source'=>'panel',
			],
			[
				'name'=>'global_search',
				'label'=>'Search all records',
				'group'=>'Workspace',
				'description'=>'Focus global search when it is available.',
				'icon'=>'search',
				'sort'=>20,
				'keywords'=>['find', 'global', 'records'],
				'source'=>'panel',
				'client_action'=>'focus_global_search',
			],
			[
				'name'=>'keyboard_shortcuts',
				'label'=>'Show keyboard shortcuts',
				'group'=>'Help',
				'description'=>'Open the keyboard shortcut reference.',
				'icon'=>'keyboard',
				'sort'=>900,
				'keywords'=>['help', 'hotkeys'],
				'source'=>'panel',
				'client_action'=>'shortcuts',
			],
		];
	}

	/**
	 * Builds command entries for workflows exposed by one resource.
	 *
	 * Hidden resources, denied resources, and denied operations are omitted.
	 * Remaining entries expose create, import, and board workflows with stable
	 * names and URLs generated inside the active Panel configuration context.
	 *
	 * @param Resource $resource Resource contributing command entries.
	 * @param PanelRequest $request Current panel request.
	 *
	 * @return list<array<string,mixed>> Authorized resource command entries.
	 */
	private function resourceCommandEntries(Resource $resource, PanelRequest $request): array {
		if($resource->isHiddenFromNavigation()){
			return [];
		}
		if($this->canAccess('view_any', $resource, $request)===false || $resource->can('view_any', null, $request->user())===false){
			return [];
		}
		$name=$resource->name();
		$label=$resource->pluralLabel();
		$commands=[];
		if($this->canAccess('create', $resource, $request)!==false && $resource->can('create', null, $request->user())!==false){
			$commands[]=[
				'name'=>$name.'_create',
				'label'=>'Create '.$resource->label(),
				'group'=>'Resource actions',
				'description'=>'Open the '.$resource->label().' creation form.',
				'icon'=>'plus',
				'url'=>PanelConfig::url($name, ['operation'=>'create']),
				'sort'=>320,
				'keywords'=>[$name, $label, 'new'],
				'source'=>'resource',
			];
		}
		if(PanelConfig::resourceImportsEnabled() && $resource->canImport() && $this->canAccess('import', $resource, $request)!==false && $resource->can('import', null, $request->user())!==false){
			$commands[]=[
				'name'=>$name.'_import',
				'label'=>'Import '.$label,
				'group'=>'Resource actions',
				'description'=>'Open the '.$label.' import workflow.',
				'icon'=>'upload',
				'url'=>PanelConfig::url($name, ['operation'=>'import']),
				'sort'=>340,
				'keywords'=>[$name, $label, 'csv', 'upload'],
				'source'=>'resource',
			];
		}
		if($resource->statusViewNames()!==[]){
			$commands[]=[
				'name'=>$name.'_board',
				'label'=>$label.' board',
				'group'=>'Resource views',
				'description'=>'Open the status board for '.$label.'.',
				'icon'=>'kanban',
				'url'=>PanelConfig::url($name, ['operation'=>'board']),
				'sort'=>360,
				'keywords'=>[$name, $label, 'status', 'board'],
				'source'=>'resource',
			];
		}
		return $commands;
	}

	/**
	 * Locates the record targeted by the current resource request.
	 *
	 * Array-backed resources are searched by `id` or `key`; query-backed
	 * resources may expose `findRecord`, `find`, `firstRecord`, or `first`.
	 * A missing record key or query source returns `null` so callers can render
	 * normal not-found or empty-state flows.
	 *
	 * @param Resource $resource Resource owning the record.
	 * @param PanelRequest $request Current panel request.
	 *
	 * @return mixed Located record payload or `null`.
	 */
	private function findRecord(Resource $resource, PanelRequest $request): mixed {
		$request=$this->requestWithResolvedResourceState($resource, $request);
		$key=$request->recordKey();
		if($key===null || $key===''){
			return null;
		}
		$query=$resource->makeQuery($request);
		if($query===null){
			return null;
		}
		if(is_array($query)){
			foreach($query as $record){
				$id=is_array($record)
					? ($record['id'] ?? $record['key'] ?? null)
					: (is_object($record) ? ($record->id ?? $record->key ?? null) : null);
				if((string)$id===(string)$key){
					return $record;
				}
			}
			return null;
		}
		foreach(['findRecord', 'find', 'firstRecord', 'first'] as $method){
			if(!method_exists($query, $method)){
				continue;
			}
			return in_array($method, ['findRecord', 'find'], true)
				? $query->{$method}($key)
				: $query->{$method}();
		}
		return null;
	}

	/**
	 * Applies resource view state to requests that read record collections.
	 *
	 * Index and export operations need the selected table view resolved before
	 * records are queried. Mutating and record-specific operations keep the
	 * original request so action payloads are not rewritten unexpectedly.
	 *
	 * @param Resource $resource Resource that may resolve a view-aware request.
	 * @param PanelRequest $request Original panel request.
	 *
	 * @return PanelRequest Original or view-resolved request.
	 */
	private function requestWithResolvedResourceState(Resource $resource, PanelRequest $request): PanelRequest {
		if(!in_array($request->operation(), ['index', 'export'], true)){
			return $request;
		}
		return $resource->requestWithResolvedView($request);
	}

	/**
	 * Evaluates manager-level authorization for a panel ability.
	 *
	 * The installed callback receives the ability, resource, user, request, and
	 * manager. Exceptions are treated as denial and recorded to PanelTrace so a
	 * broken policy cannot accidentally grant access to protected operator UI.
	 *
	 * @param string $ability Ability or operation being checked.
	 * @param ?Resource $resource Resource in scope, or `null` for panel-wide checks.
	 * @param PanelRequest $request Current panel request.
	 *
	 * @return bool True when no authorizer exists or the authorizer allows access.
	 */
	/** Resolves registry-backed tenant context without mutating process-global state. */
	private function resolvedTenantRequest(PanelRequest $request): ?PanelRequest {
		if(!$this->hasTenantRegistry()){ return $request; }
		$context=$this->tenantRegistry()->context($request);
		return $context->isAuthorized() ? $context->request() : null;
	}

	/** Resolves the configured root page only from this manager's page registry. */
	private function configuredHomePage(): ?PanelPage {
		$name=PanelConfig::homePage();
		return $name!==null ? $this->getPage($name) : null;
	}

	/** @return array{has_tenant:bool,tenant_hash?:string,tenant_length?:int} */
	private static function tenantTraceContext(?string $tenant): array {
		if($tenant===null || $tenant===''){ return ['has_tenant'=>false]; }
		return ['has_tenant'=>true,'tenant_hash'=>hash('sha256',$tenant),'tenant_length'=>strlen($tenant)];
	}

	private function canAccess(string $ability, ?Resource $resource, PanelRequest $request): bool {
		$authorizer=$this->authorizer ?? self::configuredAuthorizer();
		if($authorizer===null){
			return true;
		}
		try{
			$allowed=(bool)$authorizer($ability, $resource, $request->user(), $request, $this);
		}
		catch(\Throwable $exception){
			PanelTrace::record('panel.authorizer_error', [
				'ability'=>$ability,
				'resource'=>$resource,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
		PanelTrace::record('panel.authorized', [
			'ability'=>$ability,
			'resource'=>$resource,
			'allowed'=>$allowed,
		]);
		return $allowed;
	}

	/**
	 * Identifies operations that require record-level policy evaluation.
	 *
	 * Collection operations such as index and export are guarded separately,
	 * while record workflows must let the resource policy inspect the resolved
	 * record before rendering or mutating data.
	 *
	 * @param string $operation Request operation name.
	 *
	 * @return bool True when the operation should use a record policy.
	 */
	private static function operationUsesRecordPolicy(string $operation): bool {
		return in_array(Resource::normalizeName($operation), ['show',
			'edit',
			'update',
			'inline_update',
			'delete',
			'force_delete',
			'duplicate',
			'restore',
			'transition',
			'action',
			'relation',
			'tag',
			'task',
			'note',
			'message',
			'attach',
			'approval',
		], true);
	}

	/**
	 * Resolves the configured panel authorizer from runtime configuration.
	 *
	 * A callable `authorize` setting is used directly, a boolean setting becomes
	 * a constant allow/deny closure, and permission configuration is bridged to
	 * the Permission module when that module is available. Missing optional
	 * permission support is traced and leaves the manager with no authorizer.
	 *
	 * @return ?\Closure Authorization callback, or `null` when access is open.
	 */
	private static function configuredAuthorizer(): ?\Closure {
		$authorizer=PanelConfig::config('authorize');
		if(is_callable($authorizer)){
			return \Closure::fromCallable($authorizer);
		}
		if(is_bool($authorizer)){
			return static fn(): bool => $authorizer;
		}
		$permission=PanelConfig::config('permission', PanelConfig::config('permissions', null));
		if($permission!==null && $permission!==false){
			$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
			if(!$loaded && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('permission')===true){
				$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
			}
			if($loaded){
				return \Dataphyre\Permission\PermissionPanel::authorizer(is_array($permission) ? $permission : []);
			}
			PanelTrace::record('panel.permission_authorizer_unavailable');
		}
		return null;
	}
}
