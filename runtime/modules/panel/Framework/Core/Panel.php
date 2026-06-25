<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Static facade for configuring and mounting Panel surfaces.
 *
 * The facade keeps application boot code terse while delegating stateful work to `PanelInstance`, `PanelRegistry`, package manifests, and MVC route helpers.
 */
final class Panel {

	/**
	 * Creates or resolves a Panel surface instance.
	 *
	 * Surface creation normalizes configuration before resources, pages, plugins, and render hooks are attached.
	 *
	 * @param ?string $name Surface, route, package, or registry name.
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelInstance.
	 */
	public static function make(?string $name=null, array $config=[]): PanelInstance {
		return PanelInstance::make($name, $config);
	}

	/**
	 * Creates or resolves a Panel surface instance.
	 *
	 * Surface creation normalizes configuration before resources, pages, plugins, and render hooks are attached.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelInstance.
	 */
	public static function surface(string $name='default', array $config=[]): PanelInstance {
		return PanelRegistry::surface($name, $config);
	}

	/**
	 * Coordinates the global Panel surface registry.
	 *
	 * Registry calls mutate or read process-local Panel surface state used by route mounting and rendering.
	 *
	 * @param PanelInstance $surface Panel surface instance or registry name.
	 * @param ?string $name Surface, route, package, or registry name.
	 * @return PanelInstance.
	 */
	public static function registerSurface(PanelInstance $surface, ?string $name=null): PanelInstance {
		return PanelRegistry::register($surface, $name);
	}

	/**
	 * Coordinates the global Panel surface registry.
	 *
	 * Registry calls mutate or read process-local Panel surface state used by route mounting and rendering.
	 * @return array.
	 */
	public static function surfaces(): array {
		return PanelRegistry::all();
	}

	/**
	 * Coordinates the global Panel surface registry.
	 *
	 * Registry calls mutate or read process-local Panel surface state used by route mounting and rendering.
	 * @return array.
	 */
	public static function bootConfigured(): array {
		return PanelRegistry::bootConfigured();
	}

	/**
	 * Creates or resolves a Panel surface instance.
	 *
	 * Surface creation normalizes configuration before resources, pages, plugins, and render hooks are attached.
	 *
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelInstance.
	 */
	public static function default(array $config=[]): PanelInstance {
		return PanelRegistry::surface('default', $config);
	}

	/**
	 * Creates or resolves a Panel surface instance.
	 *
	 * Surface creation normalizes configuration before resources, pages, plugins, and render hooks are attached.
	 *
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelInstance.
	 */
	public static function configure(array $config): PanelInstance {
		return PanelInstance::make(null, $config);
	}

	/**
	 * Registers a provider on the default Panel surface.
	 *
	 * Providers are delegated to the stateful surface so they can register resources, pages, hooks, plugins, or config during boot.
	 *
	 * @param PanelProvider|callable|string $provider Provider definition to register on the default surface.
	 * @return PanelInstance.
	 */
	public static function provide(PanelProvider|callable|string $provider): PanelInstance {
		return self::default()->provide($provider);
	}

	/**
	 * Registers one plugin on the default Panel surface.
	 *
	 * Plugin config is forwarded to the surface where plugin registration, configuration merge, and manifest synchronization occur.
	 *
	 * @param PanelPlugin|string $plugin Plugin definition or plugin list to normalize.
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelInstance.
	 */
	public static function plugin(PanelPlugin|string $plugin, array $config=[]): PanelInstance {
		return self::default()->plugin($plugin, $config);
	}

	/**
	 * Registers multiple plugins on the default Panel surface.
	 *
	 * Each plugin descriptor is normalized by the stateful surface using the same lifecycle as plugin().
	 *
	 * @param array<int|string, PanelPlugin|array<string, mixed>|string> $plugins Plugin definition or plugin list to normalize.
	 * @return PanelInstance.
	 */
	public static function plugins(array $plugins): PanelInstance {
		return self::default()->plugins($plugins);
	}

	/**
	 * Builds a serialized plugin manifest without registering it.
	 *
	 * This helper is side-effect-free: it normalizes the plugin definition and metadata into the manifest shape used by renderers and package tooling.
	 *
	 * @param PanelPlugin|array|string $plugin Plugin definition or plugin list to normalize.
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function pluginManifest(PanelPlugin|array|string $plugin, array $config=[], array $meta=[]): array {
		return PluginManifest::from($plugin, $config, $meta)->toArray();
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param PanelPlugin|PanelPackageManifest|array|string $package Package.
	 * @param array<string, mixed> $config Panel configuration overrides.
	 * @return PanelPackageManifest.
	 */
	public static function packageManifest(PanelPlugin|PanelPackageManifest|array|string $package, array $config=[]): PanelPackageManifest {
		return PanelPackageManifest::from($package, $config);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array<string, mixed>|string> $packages Package definitions.
	 * @param array<string, mixed> $runtime Runtime capability and version metadata.
	 * @return PanelCompatibilityMatrix.
	 */
	public static function compatibilityMatrix(array $packages=[], array $runtime=[]): PanelCompatibilityMatrix {
		return PanelCompatibilityMatrix::make($packages, $runtime);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param PanelPackageManifest|array|string $package Package.
	 * @param string $label Optional template label shown by package tooling.
	 * @return PanelPackageTemplate.
	 */
	public static function packageTemplate(PanelPackageManifest|array|string $package, string $label=''): PanelPackageTemplate {
		return PanelPackageTemplate::make($package, $label);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array<string, mixed>|string> $packages Package definitions.
	 * @param array<string, mixed> $runtime Runtime capability and version metadata.
	 * @return PanelPackageRepository.
	 */
	public static function packageRepository(array $packages=[], array $runtime=[]): PanelPackageRepository {
		return PanelPackageRepository::make($packages, $runtime);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param array<string, mixed> $policy Package trust policy options.
	 * @return PanelPackageTrustPolicy.
	 */
	public static function packageTrustPolicy(array $policy=[]): PanelPackageTrustPolicy {
		return PanelPackageTrustPolicy::make($policy);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param PanelPackageTemplate $template Template.
	 * @param string $targetPath Target filesystem path planned for installation.
	 * @param array<string, mixed> $options Package install planning options.
	 * @return PanelPackageInstallPlan.
	 */
	public static function packageInstallPlan(PanelPackageTemplate $template, string $targetPath='', array $options=[]): PanelPackageInstallPlan {
		return PanelPackageInstallPlan::make($template, $targetPath, $options);
	}

	/**
	 * Builds Panel package metadata for install, rollback, trust, or compatibility workflows.
	 *
	 * Package helpers normalize manifests so package tooling can inspect planned filesystem and runtime effects.
	 *
	 * @param PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan InstallPlan.
	 * @param array<string, mixed> $meta Metadata merged into the rollback payload.
	 * @return PanelPackageRollbackPlan.
	 */
	public static function packageRollbackPlan(PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan, array $meta=[]): PanelPackageRollbackPlan {
		return PanelPackageRollbackPlan::make($installPlan, $meta);
	}

	/**
	 * Registers one render hook on the default Panel surface.
	 *
	 * Hook renderers are stored on the stateful surface and later resolved by Panel rendering at the named hook point.
	 *
	 * @param string $hook Hook point name.
	 * @param callable|string $renderer Renderer.
	 * @return PanelInstance.
	 */
	public static function renderHook(string $hook, callable|string $renderer): PanelInstance {
		return self::default()->renderHook($hook, $renderer);
	}

	/**
	 * Registers multiple render hooks on the default Panel surface.
	 *
	 * Hook arrays are forwarded to the stateful surface, preserving keyed hook points and appended registration order.
	 *
	 * @param array<int|string, callable|string> $hooks Render hooks keyed by hook point or appended in registration order.
	 * @return PanelInstance.
	 */
	public static function renderHooks(array $hooks): PanelInstance {
		return self::default()->renderHooks($hooks);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param string $region Refresh region type.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function refreshRegion(string $key, string|\Stringable|callable $content, string $region='region', array $attributes=[]): string {
		return self::default()->refreshRegion($key, $content, $region, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function refreshIsland(string $key, string|\Stringable|callable $content, array $attributes=[]): string {
		return self::default()->refreshIsland($key, $content, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param int $intervalMs Polling interval in milliseconds.
	 * @param string $region Refresh region type.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function liveRefreshRegion(string $key, string|\Stringable|callable $content, int $intervalMs=15000, string $region='region', array $attributes=[]): string {
		return self::default()->liveRefreshRegion($key, $content, $intervalMs, $region, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param int $intervalMs Polling interval in milliseconds.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function liveRefreshIsland(string $key, string|\Stringable|callable $content, int $intervalMs=15000, array $attributes=[]): string {
		return self::default()->liveRefreshIsland($key, $content, $intervalMs, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param string|\Stringable|null $placeholder Placeholder.
	 * @param string $region Refresh region type.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function lazyRefreshRegion(string $key, string|\Stringable|callable $content, string|\Stringable|null $placeholder=null, string $region='region', array $attributes=[]): string {
		return self::default()->lazyRefreshRegion($key, $content, $placeholder, $region, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string|\Stringable|callable $content Renderable region content or content callback.
	 * @param string|\Stringable|null $placeholder Placeholder.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return string.
	 */
	public static function lazyRefreshIsland(string $key, string|\Stringable|callable $content, string|\Stringable|null $placeholder=null, array $attributes=[]): string {
		return self::default()->lazyRefreshIsland($key, $content, $placeholder, $attributes);
	}

	/**
	 * Renders a Panel refresh target or control fragment.
	 *
	 * Refresh helpers produce HTML annotated for Panel client-side replacement, polling, lazy loading, or manual refresh controls.
	 *
	 * @param string $key Stable refresh target key.
	 * @param string $region Refresh region type targeted by the controls.
	 * @param array<string, mixed> $options Refresh control labels and CSS overrides.
	 * @return string.
	 */
	public static function refreshControls(string $key, string $region='island', array $options=[]): string {
		return self::default()->refreshControls($key, $region, $options);
	}

	/**
	 * Sets the default surface navigation layout.
	 *
	 * Values are normalized by PanelInstance, which constrains the final manifest value to supported renderer layouts.
	 *
	 * @param string $layout Navigation layout token.
	 * @return PanelInstance.
	 */
	public static function navigationLayout(string $layout): PanelInstance {
		return self::default()->navigationLayout($layout);
	}

	/**
	 * Sets the default surface desktop navigation mode.
	 *
	 * Values are normalized by PanelInstance, which constrains the final manifest value to supported renderer modes.
	 *
	 * @param string $mode Desktop navigation mode token.
	 * @return PanelInstance.
	 */
	public static function navigationMode(string $mode): PanelInstance {
		return self::default()->navigationMode($mode);
	}

	/**
	 * Sets the default surface mobile navigation mode.
	 *
	 * Legacy mode aliases are normalized by PanelInstance before renderer metadata is stored.
	 *
	 * @param string $mode Mobile navigation mode token.
	 * @return PanelInstance.
	 */
	public static function mobileNavigationMode(string $mode): PanelInstance {
		return self::default()->mobileNavigationMode($mode);
	}

	/**
	 * Forwards `sidebarMobileMode()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $mode Mobile navigation mode token.
	 * @return PanelInstance.
	 */
	public static function sidebarMobileMode(string $mode): PanelInstance {
		return self::default()->sidebarMobileMode($mode);
	}

	/**
	 * Forwards `sidebarAnimation()` to the default Panel surface.
	 *
	 * @param string|bool $type Animation type.
	 * @param int $durationMs Duration in milliseconds.
	 * @param string $easing Easing preset.
	 * @return PanelInstance.
	 */
	public static function sidebarAnimation(string|bool $type='slide', int $durationMs=180, string $easing='ease'): PanelInstance {
		return self::default()->sidebarAnimation($type, $durationMs, $easing);
	}

	/**
	 * Configures whether Panel renders a generated home navigation item.
	 *
	 * @param bool $enabled Whether the default surface should include generated home navigation.
	 * @return PanelInstance.
	 */
	public static function homeNavigation(bool $enabled=true): PanelInstance {
		return self::default()->homeNavigation($enabled);
	}

	/**
	 * Configures Panel mobile sidebar layout.
	 *
	 * @param string $layout Mobile drawer layout token normalized by PanelInstance.
	 * @return PanelInstance.
	 */
	public static function mobileSidebarLayout(string $layout): PanelInstance {
		return self::default()->mobileSidebarLayout($layout);
	}

	/**
	 * Sets the default surface header mode.
	 *
	 * Values are normalized by PanelInstance before renderer metadata is stored.
	 *
	 * @param string $mode Header mode token.
	 * @return PanelInstance.
	 */
	public static function headerMode(string $mode): PanelInstance {
		return self::default()->headerMode($mode);
	}

	/**
	 * Sets the default surface footer mode.
	 *
	 * Values are normalized by PanelInstance before renderer metadata is stored.
	 *
	 * @param string $mode Footer mode token.
	 * @return PanelInstance.
	 */
	public static function footerMode(string $mode): PanelInstance {
		return self::default()->footerMode($mode);
	}

	/**
	 * Forwards `contentSpacing()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $spacing Content spacing token normalized by PanelInstance.
	 * @return PanelInstance.
	 */
	public static function contentSpacing(string $spacing): PanelInstance {
		return self::default()->contentSpacing($spacing);
	}

	/**
	 * Forwards `customPageLayout()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $layout Custom page layout token normalized by PanelInstance.
	 * @return PanelInstance.
	 */
	public static function customPageLayout(string $layout): PanelInstance {
		return self::default()->customPageLayout($layout);
	}

	/**
	 * Forwards `commandbarBottomMode()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $mode Command bar bottom layout token.
	 * @return PanelInstance.
	 */
	public static function commandbarBottomMode(string $mode): PanelInstance {
		return self::default()->commandbarBottomMode($mode);
	}

	/**
	 * Forwards `tableHeaderControls()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string|bool $mode Header controls mode or enable flag.
	 * @return PanelInstance.
	 */
	public static function tableHeaderControls(string|bool $mode='compact'): PanelInstance {
		return self::default()->tableHeaderControls($mode);
	}

	/**
	 * Forwards `tablePaginationVisibility()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $visibility Pagination visibility policy normalized by PanelInstance.
	 * @return PanelInstance.
	 */
	public static function tablePaginationVisibility(string $visibility): PanelInstance {
		return self::default()->tablePaginationVisibility($visibility);
	}

	/**
	 * Forwards `modalExpandButton()` to the default Panel surface.
	 *
	 * @param string|bool $mode Modal expand button policy or enable flag.
	 * @return PanelInstance.
	 */
	public static function modalExpandButton(string|bool $mode='always'): PanelInstance {
		return self::default()->modalExpandButton($mode);
	}

	/**
	 * Forwards `modalChromeActions()` to the default Panel surface.
	 *
	 * @param array|string $actions Modal chrome action list or named action policy.
	 * @return PanelInstance.
	 */
	public static function modalChromeActions(array|string $actions): PanelInstance {
		return self::default()->modalChromeActions($actions);
	}

	/**
	 * Toggles table density controls on the default surface.
	 *
	 * @param bool $enabled Whether table density controls should be visible.
	 * @return PanelInstance.
	 */
	public static function tableDensityControls(bool $enabled=true): PanelInstance {
		return self::default()->tableDensityControls($enabled);
	}

	/**
	 * Forwards `tableSpacingSelector()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param bool $enabled Whether table spacing selector controls should be visible.
	 * @return PanelInstance.
	 */
	public static function tableSpacingSelector(bool $enabled=true): PanelInstance {
		return self::default()->tableSpacingSelector($enabled);
	}

	/**
	 * Toggles resource import actions on the default surface.
	 *
	 * @param bool $enabled Whether resource import actions should be exposed.
	 * @return PanelInstance.
	 */
	public static function resourceImports(bool $enabled=true): PanelInstance {
		return self::default()->resourceImports($enabled);
	}

	/**
	 * Toggles resource export actions on the default surface.
	 *
	 * @param bool $enabled Whether resource export actions should be exposed.
	 * @return PanelInstance.
	 */
	public static function resourceExports(bool $enabled=true): PanelInstance {
		return self::default()->resourceExports($enabled);
	}

	/**
	 * Forwards `resourceImportExport()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param bool $enabled Whether both import and export actions should be exposed.
	 * @return PanelInstance.
	 */
	public static function resourceImportExport(bool $enabled=true): PanelInstance {
		return self::default()->resourceImportExport($enabled);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param array<string, bool|string|int> $features Navigation feature flags keyed by feature name.
	 * @return PanelInstance.
	 */
	public static function navigationFeatures(array $features): PanelInstance {
		return self::default()->navigationFeatures($features);
	}

	/**
	 * Toggles navigation search on the default surface.
	 *
	 * @param bool $enabled Whether navigation search should be exposed.
	 * @return PanelInstance.
	 */
	public static function navigationSearch(bool $enabled=true): PanelInstance {
		return self::default()->navigationSearch($enabled);
	}

	/**
	 * Toggles recent-navigation UI on the default surface.
	 *
	 * @param bool $enabled Whether recent-navigation UI should be exposed.
	 * @return PanelInstance.
	 */
	public static function recentNavigation(bool $enabled=true): PanelInstance {
		return self::default()->recentNavigation($enabled);
	}

	/**
	 * Toggles pinned-navigation UI on the default surface.
	 *
	 * @param bool $enabled Whether pinned-navigation UI should be exposed.
	 * @return PanelInstance.
	 */
	public static function pinnedNavigation(bool $enabled=true): PanelInstance {
		return self::default()->pinnedNavigation($enabled);
	}

	/**
	 * Toggles sticky navigation on the default surface.
	 *
	 *
	 * @param bool $sticky Whether navigation should remain sticky during scroll.
	 * @return PanelInstance.
	 */
	public static function stickyNavigation(bool $sticky=true): PanelInstance {
		return self::default()->stickyNavigation($sticky);
	}

	/**
	 * Forwards `stickyHeader()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param bool $sticky Whether the header should remain sticky during scroll.
	 * @return PanelInstance.
	 */
	public static function stickyHeader(bool $sticky=true): PanelInstance {
		return self::default()->stickyHeader($sticky);
	}

	/**
	 * Forwards `stickyFooter()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param bool $sticky Whether the footer should remain sticky during scroll.
	 * @return PanelInstance.
	 */
	public static function stickyFooter(bool $sticky=true): PanelInstance {
		return self::default()->stickyFooter($sticky);
	}

	/**
	 * Creates a Panel host for rendering a surface for a user context.
	 *
	 * Hosts bind the selected surface to request/user state before page dispatch and rendering.
	 *
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param mixed $user Authenticated user or subject payload for Panel authorization.
	 * @return PanelHost.
	 */
	public static function host(PanelInstance|string|null $surface=null, mixed $user=null): PanelHost {
		return PanelHost::surface($surface, $user);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param array<string, mixed> $options Panel route generation options.
	 * @return array.
	 */
	public static function routes(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return PanelRoute::routing($prefix, $surface, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param array<string, mixed> $options Panel route generation options.
	 * @return array.
	 */
	public static function mountedRoutes(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return PanelRoute::mountedRouting($prefix, $surface, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param array<string, mixed> $options Panel asset route generation options.
	 * @return array.
	 */
	public static function assetRoutes(string $prefix='/panel', array $options=[]): array {
		return PanelRoute::assetRouting($prefix, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param array<string, mixed> $options Panel upload route generation options.
	 * @return array.
	 */
	public static function uploadRoutes(string $prefix='/panel', array $options=[]): array {
		return PanelRoute::uploadRouting($prefix, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @return callable.
	 */
	public static function routeUrlBuilder(string $prefix='/panel'): callable {
		return PanelRoute::urlBuilder($prefix);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param string $asset Asset path or handle requested by the Panel renderer.
	 * @return string.
	 */
	public static function routeAssetUrl(string $prefix='/panel', string $asset='panel.css'): string {
		return PanelRoute::assetUrl($prefix, $asset);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @return string.
	 */
	public static function routeUploadUrl(string $prefix='/panel'): string {
		return PanelRoute::uploadUrl($prefix);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param array<string, mixed> $options Panel route manifest options.
	 * @return array.
	 */
	public static function routeManifest(string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return PanelRoute::manifest($prefix, $surface, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @return PanelInstance.
	 */
	public static function routeUrls(string $prefix='/panel'): PanelInstance {
		return self::default()->routeUrls($prefix);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Routes.
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param array<string, mixed> $options MVC route mounting options.
	 * @return array.
	 */
	public static function mvcRoutes(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return PanelRoute::mvc($routes, $prefix, $surface, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Routes.
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param PanelInstance|string|null $surface Panel surface instance or registry name.
	 * @param array<string, mixed> $options MVC route mounting options.
	 * @return array.
	 */
	public static function mvcMountedRoutes(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', PanelInstance|string|null $surface=null, array $options=[]): array {
		return PanelRoute::mvcMounted($routes, $prefix, $surface, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Routes.
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param array<string, mixed> $options MVC asset route mounting options.
	 * @return array.
	 */
	public static function mvcAssetRoutes(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', array $options=[]): array {
		return PanelRoute::mvcAssets($routes, $prefix, $options);
	}

	/**
	 * Builds or mounts Panel route definitions.
	 *
	 * Route helpers generate MVC-compatible route arrays, URLs, and manifests for panel pages, assets, and uploads.
	 *
	 * @param \Dataphyre\Mvc\RouteCollection $routes Routes.
	 * @param string $prefix Route prefix used when generating Panel endpoints.
	 * @param array<string, mixed> $options MVC upload route mounting options.
	 * @return array.
	 */
	public static function mvcUploadRoutes(\Dataphyre\Mvc\RouteCollection $routes, string $prefix='/panel', array $options=[]): array {
		return PanelRoute::mvcUploads($routes, $prefix, $options);
	}

	/**
	 * Forwards `manager()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return PanelManager.
	 */
	public static function manager(): PanelManager {
		return PanelManager::instance();
	}

	/**
	 * Forwards `test()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelInstance|PanelManager|null $panel Panel.
	 * @return PanelTestHarness.
	 */
	public static function test(PanelInstance|PanelManager|null $panel=null): PanelTestHarness {
		return PanelTestHarness::make($panel ?? self::manager());
	}

	/**
	 * Forwards `scaffold()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelInstance $panel Panel.
	 * @return PanelScaffolder.
	 */
	public static function scaffold(?PanelInstance $panel=null): PanelScaffolder {
		return PanelScaffolder::make($panel);
	}

	/**
	 * Forwards `dataJob()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelDataJob.
	 */
	public static function dataJob(string $type, string $name='job'): PanelDataJob {
		return PanelDataJob::make($type, $name);
	}

	/**
	 * Forwards `importJob()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelDataJob.
	 */
	public static function importJob(string $name='import'): PanelDataJob {
		return PanelDataJob::import($name);
	}

	/**
	 * Forwards `exportJob()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelDataJob.
	 */
	public static function exportJob(string $name='export'): PanelDataJob {
		return PanelDataJob::export($name);
	}

	/**
	 * Forwards `mediaLibrary()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int|string, PanelMediaCollection|array<string, mixed>|string> $collections Media collection definitions.
	 * @return PanelMediaLibrary.
	 */
	public static function mediaLibrary(array $collections=[]): PanelMediaLibrary {
		return PanelMediaLibrary::make($collections);
	}

	/**
	 * Forwards `mediaCollection()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelMediaCollection.
	 */
	public static function mediaCollection(string $name='default'): PanelMediaCollection {
		return PanelMediaCollection::make($name);
	}

	/**
	 * Forwards `mediaItem()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed> $file Uploaded file metadata or stored media descriptor.
	 * @param PanelMediaCollection|array|string|null $collection Collection.
	 * @param array<string, mixed> $attributes HTML attributes merged into the generated fragment.
	 * @return PanelMediaItem.
	 */
	public static function mediaItem(array $file, PanelMediaCollection|array|string|null $collection=null, array $attributes=[]): PanelMediaItem {
		return PanelMediaItem::from($file, $collection, $attributes);
	}

	/**
	 * Forwards `notificationInbox()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @return PanelNotificationInbox.
	 */
	public static function notificationInbox(array $notifications=[]): PanelNotificationInbox {
		return PanelNotificationInbox::make($notifications);
	}

	/**
	 * Forwards `notificationAdapter()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @param array<int, string> $channels Delivery channel names.
	 * @return PanelNotificationAdapter.
	 */
	public static function notificationAdapter(array $notifications=[], array $channels=['database']): PanelNotificationAdapter {
		return PanelInMemoryNotificationAdapter::make($notifications, $channels);
	}

	/**
	 * Forwards `notificationInboxUsing()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelNotificationAdapter $adapter Adapter.
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @return PanelNotificationInbox.
	 */
	public static function notificationInboxUsing(PanelNotificationAdapter $adapter, array $notifications=[]): PanelNotificationInbox {
		return PanelNotificationInbox::using($adapter, $notifications);
	}

	/**
	 * Forwards `inboxNotification()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelNotification|array|string $notification Notification.
	 * @param ?string $recipient Recipient.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return PanelInboxNotification.
	 */
	public static function inboxNotification(PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		return PanelInboxNotification::from($notification, $recipient, $meta);
	}

	/**
	 * Forwards `accessibilityAudit()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelPageResult|string $result Result.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return PanelAccessibilityAudit.
	 */
	public static function accessibilityAudit(PanelPageResult|string $result, array $meta=[]): PanelAccessibilityAudit {
		return PanelAccessibilityAudit::from($result, $meta);
	}

	/**
	 * Forwards `regressionSuite()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param PanelInstance|PanelManager|PanelTestHarness|null $panel Panel.
	 * @return PanelRegressionSuite.
	 */
	public static function regressionSuite(string $name='regression_suite', PanelInstance|PanelManager|PanelTestHarness|null $panel=null): PanelRegressionSuite {
		return PanelRegressionSuite::make($name, $panel ?? self::default());
	}

	/**
	 * Forwards `documentationCatalog()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, PanelDocumentationEntry|array<string, mixed>> $entries Documentation entries.
	 * @return PanelDocumentationCatalog.
	 */
	public static function documentationCatalog(array $entries=[]): PanelDocumentationCatalog {
		return PanelDocumentationCatalog::make($entries);
	}

	/**
	 * Forwards `documentationEntry()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $id Panel page, action, or navigation identifier.
	 * @param string $title Human-facing Panel title.
	 * @return PanelDocumentationEntry.
	 */
	public static function documentationEntry(string $id, string $title=''): PanelDocumentationEntry {
		return PanelDocumentationEntry::make($id, $title);
	}

	/**
	 * Forwards `localization()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelLocalization|array|null $localization Localization.
	 * @param ?string $locale Locale.
	 * @param ?string $fallbackLocale FallbackLocale.
	 * @return PanelLocalization|PanelInstance.
	 */
	public static function localization(PanelLocalization|array|null $localization=null, ?string $locale=null, ?string $fallbackLocale=null): PanelLocalization|PanelInstance {
		if($localization!==null){
			return self::default()->localization($localization, $locale, $fallbackLocale);
		}
		return self::default()->localization(null, $locale, $fallbackLocale);
	}

	/**
	 * Forwards `trans()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param array<string, scalar|\Stringable|null> $parameters Translation interpolation parameters.
	 * @param ?string $locale Locale.
	 * @param string|\Stringable|null $default Default.
	 * @param string $scope Translation or configuration scope name.
	 * @return string.
	 */
	public static function trans(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return self::default()->trans($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Forwards `t()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param array<string, scalar|\Stringable|null> $parameters Translation interpolation parameters.
	 * @param ?string $locale Locale.
	 * @param string|\Stringable|null $default Default.
	 * @param string $scope Translation or configuration scope name.
	 * @return string.
	 */
	public static function t(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return self::trans($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Forwards `flush()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return void.
	 */
	public static function flush(): void {
		PanelManager::flush();
		PanelRegistry::flush();
	}

	/**
	 * Forwards `evaluate()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param callable $callback Utility callback evaluated with named and positional Panel values.
	 * @param array<string, mixed> $values Named utility values available to the callback.
	 * @param array<int, string> $positionOrder Positional utility names mapped into callback arguments.
	 * @return mixed value produced by PanelUtilityResolver after dependency injection.
	 */
	public static function evaluate(callable $callback, array $values=[], array $positionOrder=[]): mixed {
		return PanelUtilityResolver::evaluate($callback, $values, $positionOrder);
	}

	/**
	 * Forwards `utility()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array<string, mixed> $values Named utility values.
	 * @param mixed $default Value returned when Panel configuration is absent.
	 * @return mixed.
	 */
	public static function utility(string $name, array $values, mixed $default=null): mixed {
		return PanelUtilityResolver::utility($name, $values, $default);
	}

	/**
	 * Forwards `resource()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?string $name Surface, route, package, or registry name.
	 * @return Resource.
	 */
	public static function resource(?string $name=null): Resource {
		return Resource::make($name);
	}

	/**
	 * Forwards `page()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelPage.
	 */
	public static function page(string $name): PanelPage {
		return PanelPage::make($name);
	}

	/**
	 * Forwards `theme()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelTheme|PanelThemePreset|array|string|null $theme Theme.
	 * @return PanelTheme.
	 */
	public static function theme(PanelTheme|PanelThemePreset|array|string|null $theme=null): PanelTheme {
		return self::manager()->theme($theme);
	}

	/**
	 * Forwards `palette()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $color Color token or CSS color value used by Panel theming.
	 * @return array.
	 */
	public static function palette(string $color): array {
		return PanelTheme::palette($color);
	}

	/**
	 * Forwards `themePreset()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string|array|PanelThemePreset $preset Preset.
	 * @return PanelThemePreset.
	 */
	public static function themePreset(string|array|PanelThemePreset $preset): PanelThemePreset {
		return PanelTheme::presetDefinition($preset);
	}

	/**
	 * Forwards `registerThemePreset()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelThemePreset|array $preset Preset.
	 * @return PanelThemePreset.
	 */
	public static function registerThemePreset(PanelThemePreset|array $preset): PanelThemePreset {
		return PanelTheme::register_preset($preset);
	}

	/**
	 * Forwards `registerTheme()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelTheme|array $theme Theme.
	 * @return PanelTheme.
	 */
	public static function registerTheme(PanelTheme|array $theme): PanelTheme {
		return PanelTheme::registerTheme($theme);
	}

	/**
	 * Forwards `namedTheme()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return ?PanelTheme.
	 */
	public static function namedTheme(string $name): ?PanelTheme {
		return PanelTheme::namedTheme($name);
	}

	/**
	 * Forwards `loadThemePresets()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string|array $paths Paths.
	 * @return PanelThemeLibrary.
	 */
	public static function loadThemePresets(string|array $paths): PanelThemeLibrary {
		return PanelTheme::loadPresets($paths);
	}

	/**
	 * Forwards `loadThemes()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string|array $paths Paths.
	 * @return PanelThemeLibrary.
	 */
	public static function loadThemes(string|array $paths): PanelThemeLibrary {
		return PanelTheme::loadThemes($paths);
	}

	/**
	 * Forwards `themeLibrary()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return PanelThemeLibrary.
	 */
	public static function themeLibrary(): PanelThemeLibrary {
		return PanelTheme::themeLibrary();
	}

	/**
	 * Forwards `themeDiagnostics()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function themeDiagnostics(): array {
		return PanelTheme::diagnostics();
	}

	/**
	 * Forwards `themePreview()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?string $name Surface, route, package, or registry name.
	 * @return array.
	 */
	public static function themePreview(?string $name=null): array {
		return $name===null ? self::theme()->preview() : PanelTheme::previewTheme($name);
	}

	/**
	 * Forwards `themePreviewHtml()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?string $name Surface, route, package, or registry name.
	 * @param array<string, mixed> $options Theme preview rendering options.
	 * @return string.
	 */
	public static function themePreviewHtml(?string $name=null, array $options=[]): string {
		return $name===null ? self::theme()->previewHtml($options) : PanelTheme::previewThemeHtml($name, $options);
	}

	/**
	 * Forwards `themeManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelTheme|array|string|null $theme Theme.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @param bool $includePreview Whether preview metadata should be included.
	 * @return array.
	 */
	public static function themeManifest(PanelTheme|array|string|null $theme=null, array $meta=[], bool $includePreview=false): array {
		return ThemeManifest::from($theme ?? self::theme(), $meta, $includePreview)->toArray();
	}

	/**
	 * Forwards `themeVariant()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array|\Closure $overrides Overrides.
	 * @return PanelTheme.
	 */
	public static function themeVariant(string $name, array|\Closure $overrides=[]): PanelTheme {
		return self::theme()->variant($name, $overrides);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return NavigationItem.
	 */
	public static function navigationItem(string $name): NavigationItem {
		return NavigationItem::make($name);
	}

	/**
	 * Forwards `nav()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return NavigationItem.
	 */
	public static function nav(string $name): NavigationItem {
		return self::navigationItem($name);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param PanelNavigationState|array|null $navigation Navigation.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $search Navigation search state.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function navigationManifest(PanelNavigationState|array|null $navigation=null, ?PanelRequest $request=null, array $search=[], array $meta=[]): array {
		return NavigationManifest::from($navigation, $request, $search, $meta)->toArray();
	}

	/**
	 * Forwards `command()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PanelCommand.
	 */
	public static function command(string $name): PanelCommand {
		return PanelCommand::make($name);
	}

	/**
	 * Forwards `commandManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelCommand|array|string $command Command.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function commandManifest(PanelCommand|array|string $command, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($command)){
			$command=self::manager()->registeredCommands()[Resource::normalizeName($command)] ?? $command;
		}
		return CommandManifest::from($command, $request, self::manager(), $meta)->toArray();
	}

	/**
	 * Forwards `field()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Field.
	 */
	public static function field(string $name, string $type='text'): Field {
		return Field::make($name, $type);
	}

	/**
	 * Forwards `entry()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return InfolistEntry.
	 */
	public static function entry(string $name, string $type='text'): InfolistEntry {
		return InfolistEntry::make($name, $type);
	}

	/**
	 * Forwards `textEntry()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return InfolistEntry.
	 */
	public static function textEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'text');
	}

	/**
	 * Forwards `badgeEntry()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array|string $tones Tones.
	 * @return InfolistEntry.
	 */
	public static function badgeEntry(string $name, array|string $tones=[]): InfolistEntry {
		return InfolistEntry::make($name, 'badge')->badge($tones);
	}

	/**
	 * Forwards `imageEntry()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return InfolistEntry.
	 */
	public static function imageEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'image');
	}

	/**
	 * Forwards `formSection()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return FormSection.
	 */
	public static function formSection(string $name): FormSection {
		return FormSection::make($name);
	}

	/**
	 * Forwards `section()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return FormSection.
	 */
	public static function section(string $name): FormSection {
		return self::formSection($name);
	}

	/**
	 * Forwards `schema()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, SchemaComponent|Field|FormSection|array<string, mixed>|string> $components Schema components.
	 * @return Schema.
	 */
	public static function schema(array $components=[]): Schema {
		return Schema::make($components);
	}

	/**
	 * Forwards `schemaLifecycle()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Schema|ResourceForm|array $schema Schema.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return SchemaLifecycle.
	 */
	public static function schemaLifecycle(Schema|ResourceForm|array $schema, array $meta=[]): SchemaLifecycle {
		if($schema instanceof Schema){
			return $schema->lifecycle($meta);
		}
		if($schema instanceof ResourceForm){
			return SchemaLifecycle::make($schema->fieldsList(), array_replace($schema->metadata(), $meta));
		}
		$resolved=Schema::from($schema) ?? Schema::make();
		return $resolved->lifecycle($meta);
	}

	/**
	 * Forwards `schemaManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Schema|ResourceForm|Infolist|array $schema Schema.
	 * @param ?string $operation Operation.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function schemaManifest(Schema|ResourceForm|Infolist|array $schema, ?string $operation=null, array $meta=[]): array {
		return SchemaManifest::from($schema, $operation, $meta)->toArray();
	}

	/**
	 * Forwards `infolist()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, InfolistEntry|SchemaComponent|array<string, mixed>|string> $components Infolist components.
	 * @return Infolist.
	 */
	public static function infolist(array $components=[]): Infolist {
		return Infolist::make($components);
	}

	/**
	 * Forwards `schemaComponent()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $kind Panel package or component kind.
	 * @param string $name Surface, route, package, or registry name.
	 * @return SchemaComponent.
	 */
	public static function schemaComponent(string $kind, string $name=''): SchemaComponent {
		return SchemaComponent::make($kind, $name);
	}

	/**
	 * Forwards `schemaSection()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param FormSection|array|string $section Section.
	 * @param array<int, Field|SchemaComponent|array<string, mixed>|string> $fields Section field definitions.
	 * @return SchemaComponent.
	 */
	public static function schemaSection(FormSection|array|string $section, array $fields=[]): SchemaComponent {
		return SchemaComponent::section($section, $fields);
	}

	/**
	 * Forwards `schemaTab()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array<int, SchemaComponent|Field|array<string, mixed>|string> $children Child components.
	 * @return SchemaComponent.
	 */
	public static function schemaTab(string $name, array $children=[]): SchemaComponent {
		return SchemaComponent::tab($name, $children);
	}

	/**
	 * Forwards `schemaStep()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array<int, SchemaComponent|Field|array<string, mixed>|string> $children Child components.
	 * @return SchemaComponent.
	 */
	public static function schemaStep(string $name, array $children=[]): SchemaComponent {
		return SchemaComponent::step($name, $children);
	}

	/**
	 * Forwards `column()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Column.
	 */
	public static function column(string $name, string $type='text'): Column {
		return Column::make($name, $type);
	}

	/**
	 * Forwards `pageTable()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return PageTable.
	 */
	public static function pageTable(string $name): PageTable {
		return PageTable::make($name);
	}

	/**
	 * Forwards `tableManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ResourceTable|PageTable|Resource|array $table Table.
	 * @param ?Resource $resource Resource.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function tableManifest(ResourceTable|PageTable|Resource|array $table, ?Resource $resource=null, ?PanelRequest $request=null, array $meta=[]): array {
		return TableManifest::from($table, $resource, $request, $meta)->toArray();
	}

	/**
	 * Forwards `resourceManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Resource|string|array $resource Resource.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function resourceManifest(Resource|string|array $resource, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($resource)){
			$resource=self::get($resource) ?? ['name'=>$resource];
		}
		return ResourceManifest::from($resource, $request, $meta)->toArray();
	}

	/**
	 * Forwards `pageManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelPage|string|array $page Page.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function pageManifest(PanelPage|string|array $page, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($page)){
			$page=self::getPage($page) ?? ['name'=>$page];
		}
		return PageManifest::from($page, $request, self::manager(), $meta)->toArray();
	}

	/**
	 * Forwards `pageFilter()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableFilter.
	 */
	public static function pageFilter(string $name, string $type='text'): TableFilter {
		return TableFilter::make($name, $type);
	}

	/**
	 * Forwards `filter()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableFilter.
	 */
	public static function filter(string $name, string $type='text'): TableFilter {
		return TableFilter::make($name, $type);
	}

	/**
	 * Forwards `view()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return TableView.
	 */
	public static function view(string $name): TableView {
		return TableView::make($name);
	}

	/**
	 * Forwards `summary()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableSummary.
	 */
	public static function summary(string $name, string $type='count'): TableSummary {
		return TableSummary::make($name, $type);
	}

	/**
	 * Forwards `group()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return TableGroup.
	 */
	public static function group(string $name): TableGroup {
		return TableGroup::make($name);
	}

	/**
	 * Forwards `tableGroup()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return TableGroup.
	 */
	public static function tableGroup(string $name): TableGroup {
		return TableGroup::make($name);
	}

	/**
	 * Forwards `action()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return Action.
	 */
	public static function action(string $name): Action {
		return Action::make($name);
	}

	/**
	 * Forwards `actionManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Action|ActionGroup|array|string $action Action.
	 * @param mixed $record Panel record or row payload supplied to resolvers.
	 * @param ?PanelRequest $request Request.
	 * @param ?Resource $resource Resource.
	 * @param string $mode Panel operation mode such as create, edit, view, or index.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function actionManifest(Action|ActionGroup|array|string $action, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $mode='action', array $meta=[]): array {
		if(is_array($action)){
			$action=isset($action['actions']) ? ActionGroup::fromArray($action) : Action::fromArray($action);
		}
		elseif(is_string($action)){
			$action=Action::make($action);
		}
		return ActionManifest::from($action, $record, $request, $resource, $mode, $meta)->toArray();
	}

	/**
	 * Forwards `actionGroup()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param array<int, Action|ActionGroup|array<string, mixed>|string> $actions Grouped action definitions.
	 * @return ActionGroup.
	 */
	public static function actionGroup(string $name, array $actions=[]): ActionGroup {
		return ActionGroup::make($name)->actions($actions);
	}

	/**
	 * Forwards `actionGroupSection()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $label Human-facing Panel label.
	 * @param string $description Human-facing Panel description text.
	 * @return array.
	 */
	public static function actionGroupSection(string $label, string $description=''): array {
		return ['type'=>'section', 'label'=>$label, 'description'=>$description];
	}

	/**
	 * Forwards `actionGroupDivider()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function actionGroupDivider(): array {
		return ['type'=>'divider'];
	}

	/**
	 * Forwards `relation()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return RelationManager.
	 */
	public static function relation(string $name): RelationManager {
		return RelationManager::make($name);
	}

	/**
	 * Forwards `relationManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param RelationManager|array $relation Relation.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function relationManifest(RelationManager|array $relation, ?PanelRequest $request=null, array $meta=[]): array {
		return RelationManifest::from($relation, $request, $meta)->toArray();
	}

	/**
	 * Forwards `widget()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Widget.
	 */
	public static function widget(string $name, string $type='stat'): Widget {
		return Widget::make($name, $type);
	}

	/**
	 * Forwards `widgetManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Widget|array $widget Widget.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @param bool $resolve Whether lazy Panel values should be resolved.
	 * @return array.
	 */
	public static function widgetManifest(Widget|array $widget, ?PanelRequest $request=null, array $meta=[], bool $resolve=false): array {
		return WidgetManifest::from($widget, $request, $meta, $resolve)->toArray();
	}

	/**
	 * Forwards `pageWidget()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Widget.
	 */
	public static function pageWidget(string $name, string $type='stat'): Widget {
		return self::widget($name, $type);
	}

	/**
	 * Forwards `stat()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @param mixed $value Panel configuration or manifest value.
	 * @return Widget.
	 */
	public static function stat(string $name, mixed $value=null): Widget {
		return Widget::make($name)->value($value);
	}

	/**
	 * Forwards `notify()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $message Notification or diagnostic message text.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @param ?string $title Title.
	 * @return PanelNotification.
	 */
	public static function notify(string $message, string $type='info', ?string $title=null): PanelNotification {
		return PanelNotification::make($message, $type, $title);
	}

	/**
	 * Forwards `register()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Resource|array $resource Resource.
	 * @return Resource.
	 */
	public static function register(Resource|array $resource): Resource {
		return self::manager()->register($resource);
	}

	/**
	 * Forwards `registerPage()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelPage|array $page Page.
	 * @return PanelPage.
	 */
	public static function registerPage(PanelPage|array $page): PanelPage {
		return self::manager()->registerPage($page);
	}

	/**
	 * Forwards `registerWidget()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Widget|array $widget Widget.
	 * @return Widget.
	 */
	public static function registerWidget(Widget|array $widget): Widget {
		return self::manager()->registerWidget($widget);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param NavigationItem|array $item Item.
	 * @return NavigationItem.
	 */
	public static function registerNavigationItem(NavigationItem|array $item): NavigationItem {
		return self::manager()->registerNavigationItem($item);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param array<int, NavigationItem|array<string, mixed>> $items Navigation item definitions.
	 * @return array.
	 */
	public static function registerNavigationItems(array $items): array {
		return self::manager()->registerNavigationItems($items);
	}

	/**
	 * Forwards `registerCommand()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelCommand|array $command Command.
	 * @return PanelCommand.
	 */
	public static function registerCommand(PanelCommand|array $command): PanelCommand {
		return self::manager()->registerCommand($command);
	}

	/**
	 * Forwards `registerCommands()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<int, PanelCommand|array<string, mixed>> $commands Command definitions.
	 * @return array.
	 */
	public static function registerCommands(array $commands): array {
		return self::manager()->registerCommands($commands);
	}

	/**
	 * Forwards `authorize()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param callable $authorizer Panel authorization callback registered on the manager.
	 * @return PanelManager Manager with the authorizer attached.
	 */
	public static function authorize(callable $authorizer): PanelManager {
		return self::manager()->authorize($authorizer);
	}

	/**
	 * Forwards `accessAuth()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed>|bool $options Access auth registration options, or false to disable it.
	 * @return PanelInstance.
	 */
	public static function accessAuth(array|bool $options=true): PanelInstance {
		return self::surface()->accessAuth($options);
	}

	/**
	 * Forwards `auth()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed>|bool $options Access auth registration options, or false to disable it.
	 * @return PanelInstance.
	 */
	public static function auth(array|bool $options=true): PanelInstance {
		return self::surface()->auth($options);
	}

	/**
	 * Forwards `accessPermissions()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed>|bool $options Permission registration options, or false to disable it.
	 * @return PanelInstance.
	 */
	public static function accessPermissions(array|bool $options=true): PanelInstance {
		return self::surface()->accessPermissions($options);
	}

	/**
	 * Forwards `permissions()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed>|bool $options Permission registration options, or false to disable it.
	 * @return PanelInstance.
	 */
	public static function permissions(array|bool $options=true): PanelInstance {
		return self::surface()->permissions($options);
	}

	/**
	 * Forwards `permissionAdmin()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param array<string, mixed>|bool $options Permission admin registration options, or false to skip it.
	 * @return PanelInstance.
	 */
	public static function permissionAdmin(array|bool $options=true): PanelInstance {
		return self::surface()->permissionAdmin($options);
	}

	/**
	 * Forwards `resources()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function resources(): array {
		return self::manager()->resources();
	}

	/**
	 * Forwards `pages()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function pages(): array {
		return self::manager()->pages();
	}

	/**
	 * Forwards `widgets()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @return array.
	 */
	public static function widgets(?PanelRequest $request=null): array {
		return self::manager()->widgets($request);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 * @return array.
	 */
	public static function navigationItems(): array {
		return self::manager()->navigationItems();
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param ?PanelRequest $request Request.
	 * @return array.
	 */
	public static function navigation(?PanelRequest $request=null): array {
		return self::manager()->navigation($request);
	}

	/**
	 * Configures Panel shell layout and navigation behavior.
	 *
	 * These fluent helpers write presentation options onto the default surface for the renderer to consume.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $search Navigation search state.
	 * @return PanelNavigationState.
	 */
	public static function navigationState(?PanelRequest $request=null, array $search=[]): PanelNavigationState {
		return self::manager()->navigationState($request, $search);
	}

	/**
	 * Forwards `registeredCommands()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function registeredCommands(): array {
		return self::manager()->registeredCommands();
	}

	/**
	 * Forwards `commands()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @return array.
	 */
	public static function commands(?PanelRequest $request=null, ?string $query=null): array {
		return self::manager()->commands($request, $query);
	}

	/**
	 * Forwards `commandState()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @return PanelCommandState.
	 */
	public static function commandState(?PanelRequest $request=null, ?string $query=null): PanelCommandState {
		return self::manager()->commandState($request, $query);
	}

	/**
	 * Forwards `globalSearch()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $query Search query text supplied by Panel.
	 * @param ?PanelRequest $request Request.
	 * @param int $limit Maximum number of Panel results to return.
	 * @return array.
	 */
	public static function globalSearch(string $query, ?PanelRequest $request=null, int $limit=12): array {
		return self::manager()->globalSearch($query, $request ?? PanelRequest::fromArray([]), $limit);
	}

	/**
	 * Forwards `searchManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @param int $limit Maximum number of Panel results to return.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function searchManifest(?PanelRequest $request=null, ?string $query=null, int $limit=12, array $meta=[]): array {
		return SearchManifest::from(self::manager(), $request, $query, $limit, $meta)->toArray();
	}

	/**
	 * Forwards `tenantManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function tenantManifest(?PanelRequest $request=null, array $meta=[]): array {
		return TenantManifest::from(self::manager(), $request, $meta)->toArray();
	}

	/**
	 * Forwards `get()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return ?Resource.
	 */
	public static function get(string $name): ?Resource {
		return self::manager()->get($name);
	}

	/**
	 * Forwards `has()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return bool.
	 */
	public static function has(string $name): bool {
		return self::manager()->has($name);
	}

	/**
	 * Forwards `getPage()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param string $name Surface, route, package, or registry name.
	 * @return ?PanelPage.
	 */
	public static function getPage(string $name): ?PanelPage {
		return self::manager()->getPage($name);
	}

	/**
	 * Forwards `describe()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function describe(): array {
		return self::manager()->describe();
	}

	/**
	 * Forwards `panelManifest()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public static function panelManifest(?PanelRequest $request=null, array $meta=[]): array {
		return PanelManifest::from(self::manager(), $request, $meta)->toArray();
	}

	/**
	 * Forwards `trace()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function trace(): array {
		return PanelTrace::events();
	}

	/**
	 * Forwards `traceSummary()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 * @return array.
	 */
	public static function traceSummary(): array {
		return PanelTrace::summary();
	}

	/**
	 * Forwards `dispatch()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param PanelRequest|array|null $request Request.
	 * @return PanelPageResult.
	 */
	public static function dispatch(PanelRequest|array|null $request=null): PanelPageResult {
		return self::manager()->dispatch($request);
	}

	/**
	 * Forwards `render()` to the appropriate Panel surface, registry, or manifest object.
	 *
	 * This facade keeps application boot code compact while preserving typed Panel Framework return values.
	 *
	 * @param Resource|string|null $resource Resource.
	 * @param string $operation Panel operation name such as create, edit, view, or delete.
	 * @param array<string, mixed> $context Render context passed to the panel manager.
	 * @return PanelPageResult.
	 */
	public static function render(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		return self::manager()->render($resource, $operation, $context);
	}
}
