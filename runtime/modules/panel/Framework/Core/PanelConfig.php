<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Resolves Panel runtime configuration for rendering, routing, tenancy, assets, plugins, and extension hooks.
 *
 * PanelConfig is the read-only boundary between bootstrap configuration, the
 * active `PanelContext`, and renderer-facing convenience methods. Values are
 * normalized before they reach layout builders so themes, navigation modes,
 * URLs, tenant propagation, and plugin hooks remain predictable even when
 * applications provide partial or legacy configuration keys.
 */
final class PanelConfig {

	/**
	 * Reads one Panel configuration value with context-first precedence.
	 *
	 * Active `PanelContext` values override the legacy `DP_PANEL_CFG` constant.
	 * Missing keys return the caller-provided default without mutating context or
	 * global configuration.
	 *
	 * @param string $key Configuration key.
	 * @param mixed $default Value returned when the key is not configured.
	 * @return mixed active context value, DP_PANEL_CFG value, or the caller default when absent.
	 */
	public static function config(string $key, mixed $default=null): mixed {
		if(PanelContext::has($key)){
			return PanelContext::config($key, $default);
		}
		if(defined('\DP_PANEL_CFG')){
			$config=\constant('\DP_PANEL_CFG');
			if(is_array($config) && array_key_exists($key, $config)){
				return $config[$key];
			}
		}
		return $default;
	}

	/**
	 * Returns the display label for the current Panel surface.
	 *
	 * The method accepts both `panel_label` and the older `title` key, trims the
	 * result, and falls back to a stable product label when configuration is
	 * blank.
	 *
	 * @return string Non-empty Panel label.
	 */
	public static function label(): string {
		$label=trim((string)self::config('panel_label', self::config('title', 'Dataphyre Panel')));
		return $label!=='' ? $label : 'Dataphyre Panel';
	}

	/**
	 * Returns the navigation label used for the Panel home entry.
	 *
	 * `home_label` takes precedence over the broader navigation label, allowing
	 * applications to brand the shell while keeping the home affordance concise.
	 *
	 * @return string Non-empty home navigation label.
	 */
	public static function homeLabel(): string {
		$label=trim((string)self::config('home_label', self::config('navigation_label', 'Panel')));
		return $label!=='' ? $label : 'Panel';
	}

	/**
	 * Resolves the active Panel theme object.
	 *
	 * Configuration may provide a `PanelTheme`, a `PanelThemePreset`, an array
	 * payload, or a theme name string. When no explicit theme is configured, the
	 * singleton manager supplies the runtime default.
	 *
	 * @return PanelTheme Active theme definition.
	 */
	public static function theme(): PanelTheme {
		$theme=self::config('theme');
		if($theme instanceof PanelTheme){
			return $theme;
		}
		if($theme instanceof PanelThemePreset){
			return $theme->toTheme();
		}
		if(is_array($theme)){
			return PanelTheme::fromArray($theme);
		}
		if(is_string($theme) && trim($theme)!==''){
			return PanelTheme::make($theme);
		}
		return PanelManager::instance()->theme();
	}

	/**
	 * Returns the Panel manager bound to the current context.
	 *
	 * Request-scoped managers are read from `__panel_manager`; otherwise the
	 * process-wide singleton is used. Renderers use this to avoid passing the
	 * manager through every helper call.
	 *
	 * @return PanelManager Active Panel manager.
	 */
	public static function manager(): PanelManager {
		$manager=PanelContext::config('__panel_manager');
		return $manager instanceof PanelManager ? $manager : PanelManager::instance();
	}

	/**
	 * Returns the brand name shown by Panel chrome.
	 *
	 * Theme brand metadata wins when present, then the general Panel label is
	 * used as a safe fallback.
	 *
	 * @return string Non-empty brand name.
	 */
	public static function brandName(): string {
		$brand=PanelConfig::theme()->brand();
		$name=trim((string)($brand['name'] ?? ''));
		return $name!=='' ? $name : self::label();
	}

	/**
	 * Returns the normalized query parameter used for global Panel search.
	 *
	 * Invalid or empty configured names fall back to `search` so URL generation
	 * and request parsing continue to agree.
	 *
	 * @return string Search query parameter name.
	 */
	public static function globalSearchParameter(): string {
		$name=Resource::normalizeName((string)self::config('global_search_parameter', 'search'));
		return $name!=='' ? $name : 'search';
	}

	/**
	 * Returns the normalized query parameter used to carry tenant identity.
	 *
	 * The value is shared by tenant detection and generated URLs, which keeps
	 * tenant propagation stable across resource, action, and upload routes.
	 *
	 * @return string Tenant query parameter name.
	 */
	public static function tenantParameter(): string {
		$name=Resource::normalizeName((string)self::config('tenant_parameter', 'tenant'));
		return $name!=='' ? $name : 'tenant';
	}

	/**
	 * Resolves the desktop navigation layout mode.
	 *
	 * Legacy `nav_layout` remains supported, but the returned value is restricted
	 * to renderer-known modes so shell composition never receives arbitrary
	 * class names or layout tokens.
	 *
	 * @return string One of `sidebar`, `horizontal`, or `none`.
	 */
	public static function navigationLayout(): string {
		$layout=Resource::normalizeName((string)self::config('navigation_layout', self::config('nav_layout', 'sidebar')));
		return in_array($layout, ['sidebar', 'horizontal', 'none'], true) ? $layout : 'sidebar';
	}

	/**
	 * Resolves the desktop navigation chrome mode.
	 *
	 * The mode controls how navigation sits relative to page content. Unknown
	 * values fall back to the floating chrome used by the default Panel shell.
	 *
	 * @return string One of `floating`, `docked`, `edge`, or `overlay`.
	 */
	public static function navigationMode(): string {
		$mode=Resource::normalizeName((string)self::config('navigation_mode', self::config('nav_mode', 'floating')));
		return in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating';
	}

	/**
	 * Resolves the header chrome mode.
	 *
	 * Header modes share the same bounded vocabulary as navigation and footer
	 * chrome so renderer branches can be kept symmetrical.
	 *
	 * @return string One of `floating`, `docked`, `edge`, or `overlay`.
	 */
	public static function headerMode(): string {
		$mode=Resource::normalizeName((string)self::config('header_mode', 'floating'));
		return in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating';
	}

	/**
	 * Resolves the footer chrome mode.
	 *
	 * Unknown configuration values fall back to floating chrome rather than
	 * leaking unrecognized mode names into layout renderers.
	 *
	 * @return string One of `floating`, `docked`, `edge`, or `overlay`.
	 */
	public static function footerMode(): string {
		$mode=Resource::normalizeName((string)self::config('footer_mode', 'floating'));
		return in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating';
	}

	/**
	 * Reports whether desktop navigation should remain sticky while scrolling.
	 *
	 * Several legacy aliases are accepted, but the public contract is a boolean
	 * consumed by shell layout renderers.
	 *
	 * @return bool Sticky navigation flag.
	 */
	public static function navigationSticky(): bool {
		return self::boolConfig(['navigation_sticky', 'nav_sticky', 'sticky_navigation', 'sticky_nav'], false);
	}

	/**
	 * Reports whether the Panel header should remain sticky while scrolling.
	 *
	 * The value is normalized through the shared boolean parser so string,
	 * numeric, and boolean configuration styles behave consistently.
	 *
	 * @return bool Sticky header flag.
	 */
	public static function headerSticky(): bool {
		return self::boolConfig(['header_sticky', 'sticky_header'], false);
	}

	/**
	 * Reports whether the Panel footer should remain sticky while scrolling.
	 *
	 * The flag is resolved independently from header and navigation stickiness
	 * so applications can tune each chrome region separately.
	 *
	 * @return bool Sticky footer flag.
	 */
	public static function footerSticky(): bool {
		return self::boolConfig(['footer_sticky', 'sticky_footer'], false);
	}

	/**
	 * Resolves the mobile navigation presentation mode.
	 *
	 * Common legacy words such as `hamburger`, `offcanvas`, and `disabled` are
	 * folded into the current renderer vocabulary, giving templates one stable
	 * set of modes to branch on.
	 *
	 * @return string One of `chips`, `drawer`, or `none`.
	 */
	public static function mobileNavigationMode(): string {
		$mode=Resource::normalizeName((string)self::config('mobile_navigation_mode', 'chips'));
		if(in_array($mode, ['offcanvas', 'off_canvas', 'hamburger', 'menu'], true)){
			$mode='drawer';
		}
		if(in_array($mode, ['hidden', 'disabled', 'off'], true)){
			$mode='none';
		}
		return in_array($mode, ['chips', 'drawer', 'none'], true) ? $mode : 'chips';
	}

	/**
	 * Resolves the mobile sidebar layout.
	 *
	 * Grid and two-column aliases are normalized to `split`; everything else
	 * falls back to the single-column mobile sidebar.
	 *
	 * @return string One of `single` or `split`.
	 */
	public static function mobileSidebarLayout(): string {
		$layout=Resource::normalizeName((string)self::config('mobile_sidebar_layout', self::config('sidebar_mobile_layout', 'single')));
		if(in_array($layout, ['two_column', 'two_columns', 'two-col', 'two-cols', 'compact_grid', 'grid'], true)){
			$layout='split';
		}
		return in_array($layout, ['single', 'split'], true) ? $layout : 'single';
	}

	/**
	 * Resolves sidebar transition settings for renderer CSS and data attributes.
	 *
	 * Boolean-like and legacy animation names are translated into a small mode
	 * set, duration is clamped to a safe browser-interaction range, and easing
	 * aliases are mapped to concrete CSS easing values.
	 *
	 * @return array{type:string,duration:int,easing:string} Sidebar animation configuration.
	 */
	public static function sidebarAnimation(): array {
		$type=Resource::normalizeName((string)self::config('sidebar_animation_type', self::config('sidebar_animation', 'none')));
		if(in_array($type, ['0', 'false', 'no', 'off', 'disabled'], true)){
			$type='none';
		}
		if(in_array($type, ['1', 'true', 'yes', 'on', 'enabled'], true)){
			$type='slide';
		}
		if(in_array($type, ['slidefade', 'slide_and_fade'], true)){
			$type='slide_fade';
		}
		if(in_array($type, ['zoom', 'pop'], true)){
			$type='scale';
		}
		$type=in_array($type, ['none', 'slide', 'fade', 'scale', 'slide_fade'], true) ? $type : 'none';
		$duration=(int)self::config('sidebar_animation_duration', self::config('sidebar_animation_duration_ms', 180));
		$duration=max(0, min(2000, $duration));
		$easing=Resource::normalizeName((string)self::config('sidebar_animation_easing', 'ease'));
		$easing=match($easing){
			'linear' => 'linear',
			'ease_in', 'in' => 'cubic-bezier(.4,0,1,1)',
			'ease_out', 'out' => 'cubic-bezier(0,0,.2,1)',
			'ease_in_out', 'in_out', 'standard' => 'cubic-bezier(.4,0,.2,1)',
			'snappy', 'swift' => 'cubic-bezier(.2,.8,.2,1)',
			default => 'ease',
		};
		return [
			'type'=>$type,
			'duration'=>$duration,
			'easing'=>$easing,
		];
	}

	/**
	 * Resolves the spacing density used around Panel content.
	 *
	 * This controls page-level layout rhythm rather than table density. Unknown
	 * values fall back to normal spacing.
	 *
	 * @return string One of `normal`, `compact`, or `flush`.
	 */
	public static function contentSpacing(): string {
		$spacing=Resource::normalizeName((string)self::config('content_spacing', 'normal'));
		return in_array($spacing, ['normal', 'compact', 'flush'], true) ? $spacing : 'normal';
	}

	/**
	 * Resolves the layout style for custom Panel pages.
	 *
	 * The legacy `plain` token is mapped to `flow`, while unrecognized values
	 * retain the carded default expected by existing page renderers.
	 *
	 * @return string One of `carded` or `flow`.
	 */
	public static function customPageLayout(): string {
		$layout=Resource::normalizeName((string)self::config('custom_page_layout', 'carded'));
		if($layout==='plain'){
			$layout='flow';
		}
		return in_array($layout, ['carded', 'flow'], true) ? $layout : 'carded';
	}

	/**
	 * Resolves how command bar footer metadata is arranged.
	 *
	 * The mode is consumed by action and command renderers when compacting
	 * buttons, status text, and secondary metadata at the bottom of a surface.
	 *
	 * @return string One of `stacked`, `inline`, or `meta`.
	 */
	public static function commandbarBottomMode(): string {
		$mode=Resource::normalizeName((string)self::config('commandbar_bottom_mode', 'stacked'));
		return in_array($mode, ['stacked', 'inline', 'meta'], true) ? $mode : 'stacked';
	}

	/**
	 * Resolves optional table header control presentation.
	 *
	 * Boolean-like enabled values map to compact controls. Disabled or unknown
	 * values keep controls out of the table header.
	 *
	 * @return string One of `none` or `compact`.
	 */
	public static function tableHeaderControlsMode(): string {
		$mode=Resource::normalizeName((string)self::config('table_header_controls', self::config('table_header_controls_mode', 'none')));
		if(in_array($mode, ['1', 'true', 'yes', 'on', 'enabled'], true)){
			return 'compact';
		}
		return in_array($mode, ['none', 'compact'], true) ? $mode : 'none';
	}

	/**
	 * Resolves when table pagination chrome should be visible.
	 *
	 * The returned token lets table renderers hide pagination for empty or
	 * single-page result sets without each renderer re-parsing configuration.
	 *
	 * @return string One of `always`, `hide_empty`, `hide_single`, or `hide_empty_or_single`.
	 */
	public static function tablePaginationVisibility(): string {
		$visibility=Resource::normalizeName((string)self::config('table_pagination_visibility', 'always'));
		return in_array($visibility, ['always', 'hide_empty', 'hide_single', 'hide_empty_or_single'], true) ? $visibility : 'always';
	}

	/**
	 * Resolves when modal chrome should expose an expand action.
	 *
	 * Boolean-like values, visibility aliases, and surface-specific aliases are
	 * normalized into the compact vocabulary used by modal renderers.
	 *
	 * @return string One of `always`, `never`, or `surface`.
	 */
	public static function modalExpandMode(): string {
		$mode=Resource::normalizeName((string)self::config('modal_expand_button', self::config('modal_expand_mode', 'always')));
		if(in_array($mode, ['0', 'false', 'no', 'off', 'disabled', 'hide', 'hidden'], true)){
			return 'never';
		}
		if(in_array($mode, ['1', 'true', 'yes', 'on', 'enabled', 'show'], true)){
			return 'always';
		}
		if(in_array($mode, ['surface_only', 'surfaces', 'record', 'records'], true)){
			return 'surface';
		}
		return in_array($mode, ['always', 'never', 'surface'], true) ? $mode : 'always';
	}

	/**
	 * Returns the modal header actions enabled for Panel chrome.
	 *
	 * Actions may be configured as an array or a delimited string. Aliases are
	 * normalized, duplicates collapse by action key, and unknown actions are
	 * discarded so renderers receive only supported chrome commands.
	 *
	 * @return list<string> Ordered action tokens from `open_full`, `copy_link`, `refresh`, and `expand`.
	 */
	public static function modalChromeActions(): array {
		$actions=self::config('modal_chrome_actions', self::config('modal_header_actions', ['open_full', 'copy_link', 'refresh', 'expand']));
		if(is_string($actions)){
			$actions=preg_split('/[\s,|]+/', $actions, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		}
		if(!is_array($actions)){
			$actions=[];
		}
		$normalized=[];
		foreach($actions as $action){
			$action=Resource::normalizeName((string)$action);
			$action=match($action){
				'open', 'open_page', 'open_full_page', 'full_page' => 'open_full',
				'copy', 'link', 'copy_url', 'copylink' => 'copy_link',
				default => $action,
			};
			if(in_array($action, ['open_full', 'copy_link', 'refresh', 'expand'], true)){
				$normalized[$action]=true;
			}
		}
		if($normalized===[]){
			return [];
		}
		return array_keys($normalized);
	}

	/**
	 * Reports whether table density controls should be rendered.
	 *
	 * The feature defaults on because density switching is part of the standard
	 * repeated-use table workflow.
	 *
	 * @return bool Table density control flag.
	 */
	public static function tableDensityControlsEnabled(): bool {
		return self::boolConfig(['table_density_controls', 'table_spacing_selector'], true);
	}

	/**
	 * Reports whether resource import workflows are enabled.
	 *
	 * The shared `resource_import_export` key can enable or disable imports and
	 * exports together, while `resource_imports` can override import behavior.
	 *
	 * @return bool Resource import feature flag.
	 */
	public static function resourceImportsEnabled(): bool {
		return self::boolConfig(['resource_imports', 'resource_import_export'], true);
	}

	/**
	 * Reports whether resource export workflows are enabled.
	 *
	 * The flag mirrors import resolution but uses `resource_exports` as the
	 * specific override for export UI and handlers.
	 *
	 * @return bool Resource export feature flag.
	 */
	public static function resourceExportsEnabled(): bool {
		return self::boolConfig(['resource_exports', 'resource_import_export'], true);
	}

	/**
	 * Reports whether the navigation search affordance is enabled.
	 *
	 * Feature flags are read from the structured `navigation_features` map and
	 * default to enabled for the standard Panel shell.
	 *
	 * @return bool Navigation search feature flag.
	 */
	public static function navigationSearchEnabled(): bool {
		return self::navigationFeatureEnabled('search', true);
	}

	/**
	 * Reports whether recent-item navigation is enabled.
	 *
	 * Recent navigation is a per-feature toggle so applications with strict
	 * privacy or compact navigation needs can disable it without replacing the
	 * shell.
	 *
	 * @return bool Recent navigation feature flag.
	 */
	public static function recentNavigationEnabled(): bool {
		return self::navigationFeatureEnabled('recent', true);
	}

	/**
	 * Reports whether users can pin navigation items.
	 *
	 * The value is consumed by navigation renderers and client-side state
	 * restoration helpers.
	 *
	 * @return bool Navigation pinning feature flag.
	 */
	public static function pinnedNavigationEnabled(): bool {
		return self::navigationFeatureEnabled('pinning', true);
	}

	/**
	 * Reports whether navigation groups can be collapsed.
	 *
	 * The flag controls both initial renderer affordances and the client-side
	 * collapse state behavior.
	 *
	 * @return bool Navigation collapse feature flag.
	 */
	public static function collapsibleNavigationEnabled(): bool {
		return self::navigationFeatureEnabled('collapse', true);
	}

	/**
	 * Reports whether opening one navigation group should close sibling groups.
	 *
	 * The behavior defaults off because persistent multi-section navigation is
	 * the less surprising desktop workflow.
	 *
	 * @return bool Exclusive navigation collapse flag.
	 */
	public static function exclusiveNavigationCollapseEnabled(): bool {
		return self::navigationFeatureEnabled('collapse_exclusive', false);
	}

	/**
	 * Reports whether the Panel home entry should appear in navigation.
	 *
	 * Several legacy aliases are accepted so applications can migrate config
	 * names without changing renderer behavior.
	 *
	 * @return bool Home navigation feature flag.
	 */
	public static function homeNavigationEnabled(): bool {
		return self::boolConfig(['home_navigation', 'navigation_home', 'home_nav_item'], true);
	}

	/**
	 * Builds a URL for a Panel asset.
	 *
	 * Asset names are reduced to a basename before optional custom URL building
	 * runs, preventing path traversal through asset helpers. If no builder
	 * returns a non-empty string, the URL is generated through Panel routing.
	 *
	 * @param string $asset Asset filename.
	 * @return string Panel asset URL.
	 */
	public static function assetUrl(string $asset='panel.css'): string {
		$asset=basename(str_replace('\\', '/', trim($asset)));
		$builder=self::config('asset_url_builder');
		if(is_callable($builder)){
			$url=$builder($asset);
			if(is_string($url) && trim($url)!==''){
				return trim($url);
			}
		}
		return self::url('__assets/'.ltrim($asset, '/'));
	}

	/**
	 * Builds a URL for Panel-managed upload assets.
	 *
	 * The path is appended beneath the reserved `__uploads` route and then
	 * passed through the same URL builder and tenant/theme propagation as other
	 * Panel links.
	 *
	 * @param string $path Optional upload path below the upload endpoint.
	 * @return string Panel upload URL.
	 */
	public static function uploadUrl(string $path=''): string {
		$configured=trim((string)self::config('upload_url', ''));
		if($configured!==''){
			return rtrim($configured, '/').($path!=='' ? '/'.ltrim($path, '/') : '');
		}
		return self::url('__uploads'.($path!=='' ? '/'.ltrim($path, '/') : ''));
	}

	/**
	 * Resolves a boolean configuration value across multiple aliases.
	 *
	 * The first configured key wins. Native booleans, numeric values, and common
	 * enabled/disabled strings are normalized; unrecognized values do not stop
	 * lookup of later aliases.
	 *
	 * @param list<string> $keys Configuration keys in precedence order.
	 * @param bool $default Value returned when no key resolves.
	 * @return bool Normalized boolean configuration.
	 */
	private static function boolConfig(array $keys, bool $default=false): bool {
		foreach($keys as $key){
			$value=self::config($key, null);
			if($value===null){
				continue;
			}
			if(is_bool($value)){
				return $value;
			}
			if(is_int($value) || is_float($value)){
				return $value!==0;
			}
			$value=Resource::normalizeName((string)$value);
			if(in_array($value, ['1', 'true', 'yes', 'on', 'enabled', 'sticky'], true)){
				return true;
			}
			if(in_array($value, ['0', 'false', 'no', 'off', 'disabled', 'static'], true)){
				return false;
			}
		}
		return $default;
	}

	/**
	 * Resolves a named navigation feature flag.
	 *
	 * Feature flags live under the `navigation_features` map. Missing or invalid
	 * maps return the feature's default to keep navigation behavior stable.
	 *
	 * @param string $feature Feature key inside `navigation_features`.
	 * @param bool $default Default feature state.
	 * @return bool Resolved feature state.
	 */
	private static function navigationFeatureEnabled(string $feature, bool $default): bool {
		$features=self::config('navigation_features', []);
		if(!is_array($features)){
			return $default;
		}
		if(array_key_exists($feature, $features)){
			return (bool)$features[$feature];
		}
		return $default;
	}

	/**
	 * Resolves the active tenant key for Panel requests and generated URLs.
	 *
	 * Tenant identity is read from the active request first, then a configured
	 * resolver, then static configuration, and finally request parameters. Empty
	 * scalar values collapse to null so tenant propagation never carries blanks.
	 *
	 * @return ?string Active tenant key, or null for unscoped Panel requests.
	 */
	public static function currentTenantKey(): ?string {
		$request=self::config('__panel_request');
		if($request instanceof PanelRequest && $request->tenantKey()!==null){
			return $request->tenantKey();
		}
		$resolver=self::config('tenant_resolver');
		if(is_callable($resolver)){
			$value=$resolver($request instanceof PanelRequest ? $request : null);
			return is_scalar($value) && trim((string)$value)!=='' ? trim((string)$value) : null;
		}
		$configured=self::config('tenant');
		if(is_scalar($configured) && trim((string)$configured)!==''){
			return trim((string)$configured);
		}
		$parameter=self::tenantParameter();
		$value=$_GET[$parameter] ?? $_POST[$parameter] ?? null;
		return is_scalar($value) && trim((string)$value)!=='' ? trim((string)$value) : null;
	}

	/**
	 * Returns Panel plugin configuration.
	 *
	 * Without an id the full plugin configuration map is returned. With an id,
	 * the normalized plugin key is used and only array payloads are exposed so
	 * plugin consumers receive predictable configuration shapes.
	 *
	 * @param ?string $id Optional plugin id.
	 * @return array<string,mixed> Plugin configuration map or one plugin payload.
	 */
	public static function pluginConfig(?string $id=null): array {
		$config=self::config('plugin_config', []);
		if(!is_array($config)){
			return [];
		}
		if($id===null){
			return $config;
		}
		$id=Resource::normalizeName($id);
		return is_array($config[$id] ?? null) ? $config[$id] : [];
	}

	/**
	 * Returns the configured Panel plugin ids.
	 *
	 * Values are string-cast, empty values are removed, and list order is
	 * preserved for plugin registration and rendering hooks.
	 *
	 * @return list<string> Configured plugin ids.
	 */
	public static function pluginIds(): array {
		$ids=self::config('plugin_ids', []);
		return is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
	}

	/**
	 * Renders configured extension output for a Panel hook.
	 *
	 * Hook names are normalized, wildcard renderers run before hook-specific
	 * renderers, and each callable receives context enriched with manager,
	 * tenant, and panel label. Renderer exceptions are traced and skipped so one
	 * extension cannot break the whole Panel surface.
	 *
	 * @param string $hook Hook name, with `:` aliases normalized to dotted form.
	 * @param array<string,mixed> $context Additional hook context.
	 * @return string Concatenated scalar or stringable renderer output.
	 */
	public static function renderHook(string $hook, array $context=[]): string {
		$hook=self::normalizeHookName($hook);
		if($hook===''){
			return '';
		}
		$renderers=self::renderHookRenderers($hook);
		if($renderers===[]){
			return '';
		}
		$context=array_replace([
			'hook'=>$hook,
			'manager'=>self::manager(),
			'tenant'=>self::currentTenantKey(),
			'panel_label'=>self::label(),
		], $context);
		$html='';
		foreach($renderers as $renderer){
			try{
				$output=is_callable($renderer)
					? self::callRenderHook($renderer, $hook, $context)
					: $renderer;
				if($output instanceof \Stringable || is_scalar($output) || $output===null){
					$html.=(string)$output;
				}
			}
			catch(\Throwable $exception){
				PanelTrace::record('render_hook.error', [
					'hook'=>$hook,
					'message'=>$exception->getMessage(),
				]);
			}
		}
		return $html;
	}

	/**
	 * Builds a Panel URL for a target path and query payload.
	 *
	 * Target fragments are translated into Panel query state, empty query values
	 * are removed, tenant and active theme preset values are propagated, and a
	 * custom URL builder may override the final string when configured.
	 *
	 * @param string $target Panel target such as `orders/edit/123`.
	 * @param array<string,mixed> $query Additional query values.
	 * @return string Generated Panel URL.
	 */
	public static function url(string $target='', array $query=[]): string {
		$target=trim($target, '/');
		$query=self::withThemePresetQuery(self::withTenantQuery(self::filterQuery($query)));
		$builder=self::config('url_builder');
		if(is_callable($builder)){
			$url=$builder($target, $query);
			if(is_string($url) && trim($url)!==''){
				return trim($url);
			}
		}
		return self::currentMountUrl(self::targetQuery($target, $query));
	}

	/**
	 * Builds a URL scoped to a Panel resource.
	 *
	 * Resource objects contribute their canonical name, while strings are
	 * normalized before optional path fragments are appended.
	 *
	 * @param Resource|string $resource Resource object or resource name.
	 * @param string $path Optional resource-local path.
	 * @param array<string,mixed> $query Additional query values.
	 * @return string Generated resource URL.
	 */
	public static function resourceUrl(Resource|string $resource, string $path='', array $query=[]): string {
		$name=$resource instanceof Resource ? $resource->name() : Resource::normalizeName($resource);
		$path=trim($path, '/');
		return self::url($name.($path!=='' ? '/'.$path : ''), $query);
	}

	/**
	 * Reports whether a URL is a local Panel path rather than an external URL.
	 *
	 * Newlines are stripped before checking. Protocol-relative and absolute URLs
	 * are rejected so redirect and link helpers can distinguish internal paths.
	 *
	 * @param string $url Candidate URL.
	 * @return bool True for non-empty local paths.
	 */
	public static function isPanelPath(string $url): bool {
		$url=trim(str_replace(["\r", "\n"], '', $url));
		return $url!=='' && !str_starts_with($url, '//') && !str_contains($url, '://');
	}

	/**
	 * Converts a slash-delimited Panel target into query-state fields.
	 *
	 * Empty targets clear resource operation state. Resource, operation, record,
	 * relation, and action segments are decoded or normalized according to their
	 * role so generated URLs match the request parser consumed by Panel pages.
	 *
	 * @param string $target Panel target path without leading or trailing slashes.
	 * @param array<string,mixed> $query Existing query payload.
	 * @return array<string,mixed> Query payload with target-derived state.
	 */
	private static function targetQuery(string $target, array $query): array {
		if($target===''){
			unset($query['resource'], $query['operation'], $query['record'], $query['relation'], $query['action']);
			return $query;
		}
		$segments=array_values(array_filter(explode('/', $target), static fn(string $segment): bool => $segment!==''));
		$resource=Resource::normalizeName((string)($segments[0] ?? ''));
		if($resource!==''){
			$query['resource']=$resource;
		}
		$operation=Resource::normalizeName((string)($segments[1] ?? ''));
		if($operation!==''){
			$query['operation']=$operation;
		}
		if(isset($segments[2]) && $segments[2]!==''){
			if($operation==='action'){
				$query['action']=Resource::normalizeName(rawurldecode((string)$segments[2]));
				if(isset($segments[3]) && $segments[3]!==''){
					$query['record']=rawurldecode((string)$segments[3]);
				}
			}
			elseif($operation==='relation'){
				$query['record']=rawurldecode((string)$segments[2]);
				if(isset($segments[3]) && $segments[3]!==''){
					$query['relation']=Resource::normalizeName(rawurldecode((string)$segments[3]));
				}
			}
			else {
				$query['record']=rawurldecode((string)$segments[2]);
			}
		}
		return $query;
	}

	/**
	 * Builds a URL against the current Panel mount path.
	 *
	 * The path comes from the current request URI or script name, while the
	 * query is filtered before serialization. This is the default routing path
	 * when applications do not provide a custom URL builder.
	 *
	 * @param array<string,mixed> $query Query payload.
	 * @return string Current mount URL with optional query string.
	 */
	private static function currentMountUrl(array $query): string {
		$path=(string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
		$path=$path!=='' ? $path : (string)($_SERVER['SCRIPT_NAME'] ?? '');
		$path=$path!=='' ? $path : '/';
		$query=self::filterQuery($query);
		return $query!==[] ? $path.'?'.http_build_query($query) : $path;
	}

	/**
	 * Removes empty values from nested Panel query payloads.
	 *
	 * Nulls and empty strings are dropped, nested arrays are recursively filtered,
	 * and non-empty scalar values are preserved. This prevents generated links
	 * from carrying stale blank operation state.
	 *
	 * @param array<string,mixed> $query Raw query payload.
	 * @return array<string,mixed> Filtered query payload.
	 */
	private static function filterQuery(array $query): array {
		$filtered=[];
		foreach($query as $key=>$value){
			if(is_array($value)){
				$value=self::filterQuery($value);
				if($value!==[]){
					$filtered[$key]=$value;
				}
				continue;
			}
			if($value!==null && (string)$value!==''){
				$filtered[$key]=$value;
			}
		}
		return $filtered;
	}

	/**
	 * Adds the active tenant key to a query payload when needed.
	 *
	 * Explicit tenant query values are respected. Otherwise the resolved tenant
	 * key is added under the configured tenant parameter so generated links stay
	 * within the active tenant scope.
	 *
	 * @param array<string,mixed> $query Query payload.
	 * @return array<string,mixed> Query payload with tenant state when available.
	 */
	private static function withTenantQuery(array $query): array {
		$parameter=self::tenantParameter();
		if(array_key_exists($parameter, $query)){
			return $query;
		}
		$tenant=self::currentTenantKey();
		if($tenant!==null && $tenant!==''){
			$query[$parameter]=$tenant;
		}
		return $query;
	}

	/**
	 * Adds active theme preset state to generated links when the selector is enabled.
	 *
	 * The selector parameter is configurable, explicit query values win, and the
	 * active preset is validated against configured selector options when such
	 * options exist.
	 *
	 * @param array<string,mixed> $query Query payload.
	 * @return array<string,mixed> Query payload with theme preset state when applicable.
	 */
	private static function withThemePresetQuery(array $query): array {
		if(!filter_var(self::config('theme_selector', false), FILTER_VALIDATE_BOOLEAN)){
			return $query;
		}
		$parameter=Resource::normalizeName((string)self::config('theme_selector_parameter', 'panel_theme'));
		$parameter=$parameter!=='' ? $parameter : 'panel_theme';
		if(array_key_exists($parameter, $query)){
			return $query;
		}
		$preset=self::activeThemePreset($parameter);
		if($preset!==''){
			$query[$parameter]=$preset;
		}
		return $query;
	}

	/**
	 * Resolves the active theme preset from request or cookie state.
	 *
	 * Query string state wins over the generic `preset` parameter and the Panel
	 * theme cookie. When configured presets are present, only known preset keys
	 * are allowed through to generated URLs.
	 *
	 * @param string $parameter Theme selector query parameter.
	 * @return string Normalized preset key, or an empty string when no preset is active.
	 */
	private static function activeThemePreset(string $parameter): string {
		$preset=Resource::normalizeName((string)($_GET[$parameter] ?? $_GET['preset'] ?? $_COOKIE['dataphyre_panel_theme_preset'] ?? ''));
		if($preset===''){
			return '';
		}
		$options=self::config('theme_selector_presets', []);
		if(!is_array($options) || $options===[]){
			return $preset;
		}
		$allowed=[];
		foreach(array_keys($options) as $option){
			$option=Resource::normalizeName((string)$option);
			if($option!==''){
				$allowed[$option]=true;
			}
		}
		return isset($allowed[$preset]) ? $preset : '';
	}

	/**
	 * Collects renderers configured for a normalized hook name.
	 *
	 * Wildcard renderers are returned first, followed by hook-specific renderers.
	 * List payloads are flattened one level; scalar strings and single callables
	 * are preserved for `renderHook()` to evaluate.
	 *
	 * @param string $hook Normalized hook name.
	 * @return list<mixed> Renderer definitions in execution order.
	 */
	private static function renderHookRenderers(string $hook): array {
		$config=self::config('render_hooks', []);
		if(!is_array($config)){
			return [];
		}
		$renderers=[];
		foreach(['*', $hook] as $name){
			if(!array_key_exists($name, $config)){
				continue;
			}
			$value=$config[$name];
			if(is_array($value) && array_is_list($value)){
				foreach($value as $renderer){
					$renderers[]=$renderer;
				}
			}
			else {
				$renderers[]=$value;
			}
		}
		return $renderers;
	}

	/**
	 * Invokes a render-hook callable with the arguments it declares.
	 *
	 * Renderers may accept context only, context plus hook, or context plus hook
	 * and manager. Variadic renderers receive the full argument set. Reflection
	 * failures fall back to the safest context-only call.
	 *
	 * @param callable $renderer Hook renderer.
	 * @param string $hook Normalized hook name.
	 * @param array<string,mixed> $context Hook context.
	 * @return mixed value produced by the render hook with context, hook name, and manager arguments.
	 */
	private static function callRenderHook(callable $renderer, string $hook, array $context): mixed {
		$args=[$context, $hook, self::manager()];
		try{
			if(is_array($renderer)){
				$reflection=new \ReflectionMethod($renderer[0], (string)$renderer[1]);
			}
			elseif(is_object($renderer) && !$renderer instanceof \Closure){
				$reflection=new \ReflectionMethod($renderer, '__invoke');
			}
			else {
				$reflection=new \ReflectionFunction(\Closure::fromCallable($renderer));
			}
			if($reflection->isVariadic()){
				return $renderer(...$args);
			}
			return $renderer(...array_slice($args, 0, $reflection->getNumberOfParameters()));
		}
		catch(\ReflectionException){
			return $renderer($context);
		}
	}

	/**
	 * Normalizes a render hook name into Panel's dotted key format.
	 *
	 * Colon separators are accepted for readability in configuration and then
	 * normalized through the same name sanitizer used by resources.
	 *
	 * @param string $hook Raw hook name.
	 * @return string Normalized hook key.
	 */
	private static function normalizeHookName(string $hook): string {
		return Resource::normalizeName(str_replace(':', '.', $hook));
	}
}
