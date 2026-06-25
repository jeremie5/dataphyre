<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Composes rendered Panel page content into the shared shell.
 *
 * Shell rendering attaches navigation, breadcrumbs, notifications, theme data,
 * command state, and browser assets around page-specific HTML.
 */
trait PanelRendererShell {
	/**
	 * Builds a complete PanelPageResult around rendered page content.
	 *
	 * Page composition consumes flash notifications, derives breadcrumbs, injects
	 * surface guidance, resolves theme/navigation/command state, and prepares shell
	 * data before wrapping the content in the panel HTML chrome. Navigation and
	 * command failures are traced and fail soft so a page can still render.
	 *
	 * @param string $title Page title shown in the shell.
	 * @param string $content Already-rendered page body HTML.
	 * @param array<string,mixed> $data Renderer state such as request, resource, navigation, and theme hints.
	 * @param int $status HTTP-like page result status.
	 * @param list<array<string,mixed>|string> $notifications Additional notification payloads for this result.
	 * @return PanelPageResult Complete rendered page result.
	 */
	private static function page(string $title, string $content, array $data=[], int $status=200, array $notifications=[]): PanelPageResult {
		$notifications=array_merge(self::consumeFlashNotifications(), $notifications);
		$normalizedNotifications=self::notificationList($notifications);
		$breadcrumbs=self::breadcrumbs($title, $data);
		$data['breadcrumbs']=$breadcrumbs;
		$data['notifications']=$normalizedNotifications;
		$content=self::notificationsHtml($normalizedNotifications).self::surfaceGuidanceHtml($title, $data).$content;
		$theme=PanelConfig::theme();
		$data['theme']=$theme->toArray();
		$themeTokens=is_array($data['theme']['tokens'] ?? null) ? $data['theme']['tokens'] : [];
		$themeEffects=Resource::normalizeName((string)($themeTokens['theme_effects'] ?? $themeTokens['theme_effect'] ?? ''));
		$request=self::requestFromData($data);
		try{
			if(!is_array($data['navigation_state'] ?? null)){
				$navigationState=PanelConfig::manager()->navigationState($request);
				$data['navigation_state']=$navigationState->jsonSerialize();
				$data['navigation']=$navigationState->entries();
			}
		}
		catch(\Throwable $exception){
			PanelTrace::record('navigation.page_error', [
				'message'=>$exception->getMessage(),
			]);
			$navigationState=PanelNavigationState::make([], $request);
			$data['navigation_state']=$navigationState->jsonSerialize();
			$data['navigation']=[];
		}
		try{
			$commandState=PanelConfig::manager()->commandState($request);
			$data['command_state']=$commandState->jsonSerialize();
		}
		catch(\Throwable $exception){
			PanelTrace::record('commands.page_error', [
				'message'=>$exception->getMessage(),
			]);
			$commandState=PanelCommandState::make([], $request);
			$data['command_state']=$commandState->jsonSerialize();
		}
		$hookContext=self::pageHookContext($title, $data, $theme);
		$content=PanelConfig::renderHook('content.before', $hookContext).$content.PanelConfig::renderHook('content.after', $hookContext);
		$favicon=$theme->faviconUrl();
		$themeMode=self::themeMode($theme);
		$liveInterval=self::liveRefreshInterval($data);
		$navigationLayout=self::navigationLayout();
		$navigationMode=self::navigationMode($navigationLayout);
		$headerMode=self::headerMode();
		$footerMode=self::footerMode();
		$contentSpacing=PanelConfig::contentSpacing();
		$customPageLayout=PanelConfig::customPageLayout();
		$navigationSearch=PanelConfig::navigationSearchEnabled();
		$recentNavigation=PanelConfig::recentNavigationEnabled();
		$pinnedNavigation=PanelConfig::pinnedNavigationEnabled();
		$collapsibleNavigation=PanelConfig::collapsibleNavigationEnabled();
		$exclusiveNavigationCollapse=PanelConfig::exclusiveNavigationCollapseEnabled();
		$modalExpandMode=PanelConfig::modalExpandMode();
		$modalChromeActions=PanelConfig::modalChromeActions();
		$mobileNavigationMode=self::mobileNavigationMode($navigationLayout);
		$mobileSidebarLayout=PanelConfig::mobileSidebarLayout();
		$sidebarAnimation=PanelConfig::sidebarAnimation();
		$navigationSticky=self::navigationSticky($navigationLayout);
		$headerSticky=self::headerSticky();
		$footerSticky=self::footerSticky();
		$navigationChrome=self::navigationChromeHtml($data, $theme, $navigationLayout, $navigationMode);
		$pageWidth=self::pageWidthMode($navigationLayout, $navigationMode);
		$pageKind=Resource::normalizeName((string)($data['kind'] ?? 'page'));
		$pageKind=$pageKind!=='' ? $pageKind : 'page';
		$resourceName=is_array($data['resource'] ?? null) ? Resource::normalizeName((string)($data['resource']['name'] ?? '')) : '';
		$pageName=is_array($data['page'] ?? null) ? Resource::normalizeName((string)($data['page']['name'] ?? '')) : '';
		$mainClass='dp-panel dp-panel-kind-'.$pageKind.' dp-panel-page-width-'.$pageWidth.' dp-panel-header-mode-'.$headerMode.' dp-panel-footer-mode-'.$footerMode.' dp-panel-content-spacing-'.$contentSpacing.' dp-panel-custom-page-layout-'.$customPageLayout.($navigationChrome!=='' ? ' dp-panel-with-navigation dp-panel-nav-'.$navigationLayout.' dp-panel-nav-mode-'.$navigationMode : '');
		$mainClass.=($navigationSticky ? ' dp-panel-nav-sticky' : '').($headerSticky ? ' dp-panel-header-sticky' : '').($footerSticky ? ' dp-panel-footer-sticky' : '');
		if($navigationLayout==='sidebar' && $navigationChrome!==''){
			$mainClass.=' dp-panel-with-sidebar';
		}
		$mainStyle='--dp-panel-sidebar-animation-duration:'.((int)$sidebarAnimation['duration']).'ms;--dp-panel-sidebar-animation-easing:'.(string)$sidebarAnimation['easing'].';';
		$mainAttrs=' class="'.self::e($mainClass).'" style="'.self::e($mainStyle).'" data-dp-panel-kind="'.self::e($pageKind).'" data-dp-panel-navigation-layout="'.self::e($navigationLayout).'" data-dp-panel-navigation-mode="'.self::e($navigationMode).'" data-dp-panel-header-mode="'.self::e($headerMode).'" data-dp-panel-footer-mode="'.self::e($footerMode).'" data-dp-panel-content-spacing="'.self::e($contentSpacing).'" data-dp-panel-custom-page-layout="'.self::e($customPageLayout).'" data-dp-panel-navigation-search="'.($navigationSearch ? '1' : '0').'" data-dp-panel-recent-navigation="'.($recentNavigation ? '1' : '0').'" data-dp-panel-pinned-navigation="'.($pinnedNavigation ? '1' : '0').'" data-dp-panel-navigation-collapse="'.($collapsibleNavigation ? '1' : '0').'" data-dp-panel-navigation-collapse-exclusive="'.($exclusiveNavigationCollapse ? '1' : '0').'" data-dp-panel-modal-expand="'.self::e($modalExpandMode).'" data-dp-panel-modal-actions="'.self::e(implode(' ', $modalChromeActions)).'" data-dp-panel-sidebar-animation="'.self::e((string)$sidebarAnimation['type']).'" data-dp-panel-mobile-navigation="'.self::e($mobileNavigationMode).'" data-dp-panel-mobile-sidebar-layout="'.self::e($mobileSidebarLayout).'" data-dp-panel-page-width="'.self::e($pageWidth).'" data-dp-panel-production="'.((defined('IS_PRODUCTION') && IS_PRODUCTION===true) ? '1' : '0').'"';
		if($navigationSticky){
			$mainAttrs.=' data-dp-panel-navigation-sticky="1"';
		}
		if($headerSticky){
			$mainAttrs.=' data-dp-panel-header-sticky="1"';
		}
		if($footerSticky){
			$mainAttrs.=' data-dp-panel-footer-sticky="1"';
		}
		if($resourceName!==''){
			$mainAttrs.=' data-dp-panel-resource="'.self::e($resourceName).'"';
		}
		if($pageName!==''){
			$mainAttrs.=' data-dp-panel-page="'.self::e($pageName).'"';
		}
		if($liveInterval>0){
			$mainAttrs.=' data-dp-panel-live-interval="'.self::e((string)$liveInterval).'"';
		}
		if(self::updateFlashEnabled($data)){
			$mainAttrs.=' data-dp-panel-update-flash="1"';
		}
		if($normalizedNotifications!==[]){
			$mainAttrs.=' data-dp-panel-notifications="'.self::e(json_encode($normalizedNotifications, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]').'"';
		}
		$surfaceState=PanelSurfaceState::make($title, $status, $data, [
			'theme'=>$theme->name(),
			'theme_mode'=>$themeMode,
			'navigation_layout'=>$navigationLayout,
			'navigation_mode'=>$navigationMode,
			'header_mode'=>$headerMode,
			'footer_mode'=>$footerMode,
			'content_spacing'=>$contentSpacing,
			'custom_page_layout'=>$customPageLayout,
			'navigation_search'=>$navigationSearch,
			'recent_navigation'=>$recentNavigation,
			'pinned_navigation'=>$pinnedNavigation,
			'mobile_navigation_mode'=>$mobileNavigationMode,
			'mobile_sidebar_layout'=>$mobileSidebarLayout,
			'navigation_sticky'=>$navigationSticky,
			'header_sticky'=>$headerSticky,
			'footer_sticky'=>$footerSticky,
			'page_width'=>$pageWidth,
			'sidebar'=>$navigationLayout==='sidebar' && $navigationChrome!=='',
			'live_interval'=>$liveInterval,
			'update_flash'=>self::updateFlashEnabled($data),
			'brand'=>PanelConfig::brandName(),
		]);
		$data['surface_state']=$surfaceState->jsonSerialize();
		PanelTrace::record('surface.state', [
			'state'=>$surfaceState,
		]);
		$commandStateJson=json_encode($data['command_state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		$commandStateJson=str_replace(['</script', '<!--'], ['<\/script', '<\!--'], $commandStateJson);
		$surfaceStateJson=json_encode($data['surface_state'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		$surfaceStateJson=str_replace(['</script', '<!--'], ['<\/script', '<\!--'], $surfaceStateJson);
		$localizationJson=json_encode(self::panelClientTranslations(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
		$localizationJson=str_replace(['</script', '<!--'], ['<\/script', '<\!--'], $localizationJson);
		$documentLocale=str_replace('_', '-', PanelLocalization::from(PanelConfig::config('localization'))->locale());
		$documentLocale=trim($documentLocale)!=='' ? trim($documentLocale) : 'en';
		$headingTools=self::headingToolsHtml($theme, $themeMode, $liveInterval);
		$panelCssUrl=self::assetUrl('panel.css');
		$panelJsUrl=self::assetUrl('panel.js');
		$mobileNavigationToggle=$navigationLayout==='sidebar' && $navigationChrome!=='' && $mobileNavigationMode==='drawer'
			? '<button type="button" class="dp-panel-mobile-nav-toggle" data-dp-panel-mobile-nav-toggle aria-label="'.self::e(self::panelText('nav.open_navigation')).'" aria-expanded="false" aria-controls="dp-panel-sidebar-navigation"><span aria-hidden="true"></span><span aria-hidden="true"></span><span aria-hidden="true"></span></button>'
			: '';
		$header=$mobileNavigationToggle.PanelConfig::renderHook('header.before', $hookContext).self::breadcrumbsHtml($breadcrumbs).self::brandHtml($theme).'<div class="dp-panel-heading-row"><div><p>'.self::e(PanelConfig::label()).'</p><h1>'.self::e($title).'</h1></div>'.$headingTools.'</div>'.PanelConfig::renderHook('header.after', $hookContext);
		$footer=self::footerHtml($hookContext, $footerMode);
		$mobileNavigationBackdrop=$navigationLayout==='sidebar' && $navigationChrome!=='' && $mobileNavigationMode==='drawer'
			? '<button type="button" class="dp-panel-mobile-nav-backdrop" data-dp-panel-mobile-nav-backdrop aria-label="'.self::e(self::panelText('nav.close_navigation')).'"></button>'
			: '';
		$bodyClass=($navigationSticky || $headerSticky || $footerSticky) ? ' class="dp-panel-has-sticky-chrome"' : '';
		$html='<!doctype html><html lang="'.self::e($documentLocale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			.'<title>'.self::e($title).'</title>'.self::themeHeadScript($theme, $themeMode).($favicon!==null ? '<link rel="icon" href="'.self::e($favicon).'">' : '').'<style>'.$theme->styleVariables().'</style><link rel="stylesheet" href="'.self::e($panelCssUrl).'">'.self::themeCssAssets($theme).PanelConfig::renderHook('head.end', $hookContext).'</head><body'.$bodyClass.' data-dp-theme="'.self::e($theme->name()).'" data-dp-theme-mode="'.self::e($themeMode).'"'.($themeEffects!=='' ? ' data-dp-theme-effects="'.self::e($themeEffects).'"' : '').'>'
			.PanelConfig::renderHook('body.start', $hookContext).'<script type="application/json" data-dp-panel-command-state>'.$commandStateJson.'</script><script type="application/json" data-dp-panel-surface-state>'.$surfaceStateJson.'</script><script type="application/json" data-dp-panel-localization>'.$localizationJson.'</script><main'.$mainAttrs.'>'.($navigationLayout==='sidebar' ? $navigationChrome.$mobileNavigationBackdrop : '').'<div class="dp-panel-main-region"><header class="dp-panel-header" data-dp-panel-header data-dp-panel-header-mode="'.self::e($headerMode).'">'.$header.'</header>'.($navigationLayout==='horizontal' ? $navigationChrome : '').PanelConfig::renderHook('page.before', $hookContext).$content.PanelConfig::renderHook('page.after', $hookContext).$footer.'</div></main>'
			.'<script src="'.self::e($panelJsUrl).'" defer></script>'
			.PanelConfig::renderHook('body.end', $hookContext).'</body></html>';
		if(class_exists('\Dataphyre\Templating\Templating') && class_exists('\dataphyre\templating', false)){
			$html=\Dataphyre\Templating\Templating::renderString($html, [], [], [], 'dataphyre.panel.page.tpl')->content();
		}
		return PanelPageResult::html($html, $status, $data, $notifications);
	}

	/**
	 * Builds the shared hook context used by shell, header, navigation, content, and footer render hooks.
	 *
	 * the context carries the current page data, optional request, resource/page metadata, active
	 * tenant, theme object, and panel manager. Hook callbacks may render HTML, but this method only assembles context and
	 * does not execute navigation, command, or controller actions.
	 *
	 * @param string $title Current surface title.
	 * @param array<string, mixed> $data Page render data.
	 * @param PanelTheme $theme Active panel theme.
	 * @return array<string, mixed> Render-hook context payload.
	 */
	private static function pageHookContext(string $title, array $data, PanelTheme $theme): array {
		$request=self::requestFromData($data);
		return [
			'title'=>$title,
			'kind'=>(string)($data['kind'] ?? ''),
			'data'=>$data,
			'request'=>$request,
			'resource'=>is_array($data['resource'] ?? null) ? $data['resource'] : null,
			'page'=>is_array($data['page'] ?? null) ? $data['page'] : null,
			'theme'=>$theme,
			'tenant'=>$request instanceof PanelRequest ? $request->tenantKey() : PanelConfig::currentTenantKey(),
			'manager'=>PanelConfig::manager(),
		];
	}

	/**
	 * Resolves the active navigation layout from PanelConfig while honoring the legacy sidebar disable flag.
	 *
	 * the return value is a normalized layout token consumed by body classes, data attributes, and
	 * navigation renderer selection. No navigation entries are loaded here.
	 *
	 * @return string Navigation layout token such as sidebar, horizontal, or none.
	 */
	private static function navigationLayout(): string {
		if(PanelConfig::config('sidebar', true)===false){
			return 'none';
		}
		return PanelConfig::navigationLayout();
	}

	/**
	 * Resolves the navigation presentation mode for the selected layout.
	 *
	 * a disabled navigation layout always uses floating mode so downstream CSS/data attributes remain
	 * stable even when no chrome is rendered.
	 *
	 * @param string $navigationLayout Layout token from navigationLayout().
	 * @return string Navigation mode token.
	 */
	private static function navigationMode(string $navigationLayout): string {
		if($navigationLayout==='none'){
			return 'floating';
		}
		return PanelConfig::navigationMode();
	}

	/**
	 * Resolves the mobile navigation behavior paired with the desktop layout.
	 *
	 * disabled navigation yields none, sidebars default to drawer behavior, and horizontal navigation
	 * defaults to chip behavior unless config overrides it.
	 *
	 * @param string $navigationLayout Layout token from navigationLayout().
	 * @return string Mobile navigation mode token.
	 */
	private static function mobileNavigationMode(string $navigationLayout): string {
		if($navigationLayout==='none'){
			return 'none';
		}
		return PanelConfig::mobileNavigationMode($navigationLayout==='sidebar' ? 'drawer' : 'chips');
	}

	/**
	 * Reads the configured panel header mode.
	 *
	 * this helper centralizes the config lookup used by body classes, data attributes, and header
	 * markup so shell state stays internally consistent.
	 *
	 * @return string Header mode token.
	 */
	private static function headerMode(): string {
		return PanelConfig::headerMode();
	}

	/**
	 * Reads the configured panel footer mode.
	 *
	 * this helper centralizes the config lookup used by shell classes, data attributes, and footer
	 * rendering.
	 *
	 * @return string Footer mode token.
	 */
	private static function footerMode(): string {
		return PanelConfig::footerMode();
	}

	/**
	 * Determines whether navigation chrome should participate in sticky layout behavior.
	 *
	 * sticky navigation is disabled when no navigation chrome is present, even if the global config
	 * flag is enabled.
	 *
	 * @param string $navigationLayout Layout token from navigationLayout().
	 * @return bool Whether navigation sticky classes and data attributes should be emitted.
	 */
	private static function navigationSticky(string $navigationLayout): bool {
		return $navigationLayout!=='none' && PanelConfig::navigationSticky();
	}

	/**
	 * Determines whether the panel header should use sticky chrome behavior.
	 *
	 * wraps PanelConfig so shell class and body-state decisions use one boolean source.
	 *
	 * @return bool Whether header sticky classes and data attributes should be emitted.
	 */
	private static function headerSticky(): bool {
		return PanelConfig::headerSticky();
	}

	/**
	 * Determines whether the panel footer should use sticky chrome behavior.
	 *
	 * wraps PanelConfig so shell class and body-state decisions use one boolean source.
	 *
	 * @return bool Whether footer sticky classes and data attributes should be emitted.
	 */
	private static function footerSticky(): bool {
		return PanelConfig::footerSticky();
	}

	/**
	 * Renders footer chrome from configured content and footer hooks.
	 *
	 * callable footer config is invoked with the hook context and failures are traced instead of
	 * aborting the page render. Empty text-only content suppresses the footer wrapper.
	 *
	 * @param array<string, mixed> $hookContext Shared page hook context.
	 * @param string $mode Footer mode token.
	 * @return string Footer HTML or an empty string.
	 */
	private static function footerHtml(array $hookContext, string $mode): string {
		$content=PanelConfig::renderHook('footer.before', $hookContext);
		$configured=PanelConfig::config('footer_html', PanelConfig::config('footer', ''));
		if(is_callable($configured)){
			try{
				$configured=$configured($hookContext);
			}
			catch(\Throwable $exception){
				PanelTrace::record('footer.render_error', [
					'message'=>$exception->getMessage(),
				]);
				$configured='';
			}
		}
		$content.=is_scalar($configured) || $configured instanceof \Stringable ? (string)$configured : '';
		$content.=PanelConfig::renderHook('footer', $hookContext).PanelConfig::renderHook('footer.after', $hookContext);
		if(trim(strip_tags($content))==='' && !str_contains($content, '<')){
			return '';
		}
		return '<footer class="dp-panel-footer" data-dp-panel-footer data-dp-panel-footer-mode="'.self::e($mode).'">'.$content.'</footer>';
	}

	/**
	 * Resolves the page width mode from config and navigation chrome constraints.
	 *
	 * explicit page/content width config wins when recognized, overlay/edge navigation forces fluid
	 * layout, and remaining defaults preserve readable constrained content beside sidebar chrome.
	 *
	 * @param string $navigationLayout Active navigation layout.
	 * @param string $navigationMode Active navigation mode.
	 * @return string Page width token used in classes and data attributes.
	 */
	private static function pageWidthMode(string $navigationLayout, string $navigationMode='floating'): string {
		$config=Resource::normalizeName((string)PanelConfig::config('page_width', PanelConfig::config('content_width', '')));
		if(in_array($config, ['fluid', 'full', 'wide', 'constrained', 'compact'], true)){
			return $config==='full' || $config==='wide' ? 'fluid' : $config;
		}
		if(in_array($navigationMode, ['edge', 'overlay'], true)){
			return 'fluid';
		}
		return in_array($navigationLayout, ['horizontal', 'none'], true) ? 'fluid' : 'constrained';
	}

	/**
	 * Dispatches navigation chrome rendering to the layout-specific renderer.
	 *
	 * only sidebar and horizontal layouts render navigation HTML; unknown or disabled layouts produce
	 * an empty string so the shell can omit navigation classes safely.
	 *
	 * @param array<string, mixed> $data Page render data.
	 * @param PanelTheme $theme Active theme.
	 * @param string $layout Navigation layout token.
	 * @param string $mode Navigation mode token.
	 * @return string Navigation chrome HTML or an empty string.
	 */
	private static function navigationChromeHtml(array $data, PanelTheme $theme, string $layout, string $mode='floating'): string {
		return match($layout){
			'horizontal'=>self::horizontalNavigationHtml($data, $theme, $mode),
			'sidebar'=>self::sidebarHtml($data, $theme, $mode),
			default=>'',
		};
	}

	/**
	 * Renders sidebar navigation chrome with brand, search, active context, groups, and hook extension points.
	 *
	 * navigation state is read from supplied page data when available, otherwise from the manager; any
	 * navigation failure is traced and replaced with an empty state so page rendering continues. All dynamic labels,
	 * attributes, URLs, badges, and mode tokens are escaped before entering markup.
	 *
	 * @param array<string, mixed> $data Page render data with optional navigation_state and navigation entries.
	 * @param PanelTheme $theme Active theme for brand metadata.
	 * @param string $mode Navigation mode token.
	 * @return string Sidebar navigation HTML.
	 */
	private static function sidebarHtml(array $data, PanelTheme $theme, string $mode='floating'): string {
		$request=self::requestFromData($data);
		try{
			$navigationState=is_array($data['navigation_state'] ?? null)
				? PanelNavigationState::make(is_array($data['navigation'] ?? null) ? $data['navigation'] : [], $request)
				: PanelConfig::manager()->navigationState($request);
		}
		catch(\Throwable $exception){
			PanelTrace::record('navigation.sidebar_error', [
				'message'=>$exception->getMessage(),
			]);
			$navigationState=PanelNavigationState::make([], $request);
		}
		$brand=$theme->brand();
		$brandName=PanelConfig::brandName();
		$tagline=trim((string)PanelConfig::config('panel_tagline', ''));
		if($tagline===''){
			$tagline=trim((string)($brand['tagline'] ?? ''));
		}
		if($tagline===''){
			$tagline=PanelConfig::homeLabel();
		}
		$homeActive=((string)($data['kind'] ?? ''))==='dashboard';
		$homeUrl=PanelConfig::url();
		$active=$navigationState->active();
		$activeLabel=trim((string)($active['label'] ?? ''));
		$activeGroup='';
		foreach($navigationState->groups() as $group){
			if(!empty($group['active'])){
				$activeGroup=(string)($group['label'] ?? '');
				break;
			}
		}
		if($activeLabel===''){
			$activeLabel=$homeActive ? PanelConfig::homeLabel() : self::panelText('nav.workspace');
		}
		$home=PanelConfig::homeNavigationEnabled()
			? '<a class="dp-panel-sidebar-link'.($homeActive ? ' active' : '').'" href="'.self::e($homeUrl).'"'.($homeActive ? ' aria-current="page"' : '').'>'
				.'<span class="dp-panel-sidebar-icon" aria-hidden="true">'.self::e(self::navigationIconToken('layout-dashboard', PanelConfig::homeLabel())).'</span>'
				.'<span class="dp-panel-sidebar-copy"><strong>'.self::e(PanelConfig::homeLabel()).'</strong><small>'.self::e(self::panelText('nav.overview')).'</small></span>'
				.'</a>'
			: '';
		$sections='';
		foreach($navigationState->groups() as $group){
			$entries=is_array($group['entries'] ?? null) ? $group['entries'] : [];
			$links='';
			foreach($entries as $entry){
				$links.=self::sidebarNavigationEntryHtml($entry, $data);
			}
			if($links!==''){
				$groupLabel=(string)($group['label'] ?? self::panelText('nav.workspace'));
				$count=(int)($group['count'] ?? count($entries));
				$firstUrl=self::sidebarFirstEntryUrl($entries);
				$heading=$firstUrl!==''
					? '<a class="dp-panel-sidebar-group-link" data-dp-panel-sidebar-group-link href="'.self::e($firstUrl).'"><span>'.self::e($groupLabel).'</span><b>'.self::e((string)$count).'</b></a>'
					: self::e($groupLabel).'<span>'.self::e((string)$count).'</span>';
				$sections.='<section class="dp-panel-sidebar-group'.(!empty($group['active']) ? ' active' : '').'" data-dp-panel-sidebar-group="'.self::e($groupLabel).'"><h2 data-dp-panel-group-label="'.self::e($groupLabel).'">'.$heading.'</h2>'.$links.'</section>';
			}
		}
		$navigationContext=array_replace(self::pageHookContext((string)($data['title'] ?? $activeLabel), $data, $theme), [
			'navigation_state'=>$navigationState,
			'active_navigation'=>$active,
			'active_group'=>$activeGroup,
			'navigation_layout'=>'sidebar',
			'navigation_mode'=>$mode,
		]);
		$search=PanelConfig::navigationSearchEnabled()
			? '<div class="dp-panel-sidebar-search"><input type="search" placeholder="'.self::e(self::panelText('nav.find')).'" aria-label="'.self::e(self::panelText('nav.find_aria')).'" data-dp-panel-sidebar-search><span data-dp-panel-sidebar-search-count></span></div>'
			: '';
		return '<aside class="dp-panel-sidebar" id="dp-panel-sidebar-navigation" data-dp-panel-sidebar data-dp-panel-navigation-mode="'.self::e($mode).'" data-dp-panel-refresh-region="navigation" aria-label="'.self::e(self::panelText('nav.panel_navigation')).'">'
			.'<div class="dp-panel-sidebar-top">'
			.'<a class="dp-panel-sidebar-brand" href="'.self::e($homeUrl).'"><span>'.self::e(self::navigationIconToken('panel', $brandName)).'</span><strong>'.self::e($brandName).'</strong><small>'.self::e($tagline).'</small></a>'
			.'</div>'
			.$search
			.'<div class="dp-panel-sidebar-context"><span>'.self::e(self::panelText('nav.current')).'</span><strong>'.self::e($activeLabel).'</strong>'.($activeGroup!=='' ? '<small>'.self::e($activeGroup).'</small>' : '').'</div>'
			.PanelConfig::renderHook('navigation.sidebar.after_context', $navigationContext)
			.'<nav class="dp-panel-sidebar-nav">'.$home.$sections.'</nav>'
			.'</aside>';
	}

	/**
	 * Renders horizontal navigation chrome for top-level groups and nested entries.
	 *
	 * uses supplied navigation state when present or the manager state otherwise, emits home and group
	 * links with active markers, and escapes all labels, icons, URLs, and mode data attributes.
	 *
	 * @param array<string, mixed> $data Page render data.
	 * @param PanelTheme $theme Active theme, reserved for layout parity with sidebar rendering.
	 * @param string $mode Navigation mode token.
	 * @return string Horizontal navigation HTML.
	 */
	private static function horizontalNavigationHtml(array $data, PanelTheme $theme, string $mode='floating'): string {
		$request=self::requestFromData($data);
		$navigationState=is_array($data['navigation_state'] ?? null)
			? PanelNavigationState::make(is_array($data['navigation'] ?? null) ? $data['navigation'] : [], $request)
			: PanelConfig::manager()->navigationState($request);
		$homeActive=((string)($data['kind'] ?? ''))==='dashboard';
		$homeUrl=PanelConfig::url();
		$home=PanelConfig::homeNavigationEnabled()
			? '<a class="dp-panel-horizontal-link'.($homeActive ? ' active' : '').'" href="'.self::e($homeUrl).'"'.($homeActive ? ' aria-current="page"' : '').'><span>'.self::e(self::navigationIconToken('layout-dashboard', PanelConfig::homeLabel())).'</span><strong>'.self::e(PanelConfig::homeLabel()).'</strong></a>'
			: '';
		$groups='';
		foreach($navigationState->groups() as $group){
			$entries=is_array($group['entries'] ?? null) ? $group['entries'] : [];
			$links='';
			foreach($entries as $entry){
				$links.=self::horizontalNavigationLinkHtml($entry);
			}
			if($links===''){
				continue;
			}
			$label=(string)($group['label'] ?? self::panelText('nav.workspace'));
			$count=(int)($group['count'] ?? count($entries));
			$groups.='<details class="dp-panel-horizontal-group'.(!empty($group['active']) ? ' active' : '').'"><summary><span>'.self::e($label).'</span><b>'.self::e((string)$count).'</b></summary><div>'.$links.'</div></details>';
		}
		$brandName=PanelConfig::brandName();
		return '<nav class="dp-panel-horizontal-nav" data-dp-panel-horizontal-nav data-dp-panel-navigation-mode="'.self::e($mode).'" data-dp-panel-refresh-region="navigation" aria-label="Panel navigation">'
			.'<a class="dp-panel-horizontal-brand" href="'.self::e($homeUrl).'"><span>'.self::e(self::navigationIconToken('panel', $brandName)).'</span><strong>'.self::e($brandName).'</strong></a>'
			.'<div class="dp-panel-horizontal-track">'.$home.$groups.'</div>'
			.'</nav>';
	}

	/**
	 * Renders one horizontal navigation entry, including recursive submenu children.
	 *
	 * entries without a label or any navigable target are skipped; nested entries use details/summary
	 * markup, external tabs receive rel protection, and active state is represented through class and aria-current.
	 *
	 * @param array<string, mixed> $entry Navigation entry payload.
	 * @return string Link or submenu HTML.
	 */
	private static function horizontalNavigationLinkHtml(array $entry): string {
		$label=trim((string)($entry['label'] ?? ''));
		$url=trim((string)($entry['url'] ?? ''));
		$children=array_values(array_filter(is_array($entry['children'] ?? null) ? $entry['children'] : [], static fn(mixed $child): bool => is_array($child)));
		if($label==='' || ($url==='' && $children===[])){
			return '';
		}
		$description=trim((string)($entry['description'] ?? ''));
		$active=!empty($entry['active']);
		$treeActive=$active || !empty($entry['active_descendant']);
		$icon=self::navigationIconToken((string)($entry['icon'] ?? ''), $label);
		$target=!empty($entry['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
		$badge=$entry['badge'] ?? null;
		$badgeHtml=$badge!==null && trim((string)$badge)!=='' ? '<em>'.self::e((string)$badge).'</em>' : '';
		if($badgeHtml===''){
			$badgeHtml='<em>'.self::e((string)self::navigationChildrenCount($children)).'</em>';
		}
		if($children!==[]){
			$childHtml='';
			if($url!==''){
				$childHtml.='<a class="dp-panel-horizontal-item dp-panel-horizontal-item-parent'.($active ? ' active' : '').'" href="'.self::e($url).'"'.$target.($active ? ' aria-current="page"' : '').'><span>'.self::e($icon).'</span><strong>'.self::e(self::panelText('nav.open', ['label'=>$label])).'</strong>'.$badgeHtml.'</a>';
			}
			foreach($children as $child){
				$childHtml.=self::horizontalNavigationLinkHtml($child);
			}
			return '<details class="dp-panel-horizontal-submenu'.($treeActive ? ' active' : '').'"'.($treeActive ? ' open' : '').'><summary title="'.self::e($label).'"><span>'.self::e($icon).'</span><strong>'.self::e($label).'</strong>'.$badgeHtml.'</summary><div>'.$childHtml.'</div></details>';
		}
		return '<a class="dp-panel-horizontal-item'.($active ? ' active' : '').'" href="'.self::e($url).'"'.$target.($active ? ' aria-current="page"' : '').'><span>'.self::e($icon).'</span><strong>'.self::e($label).'</strong>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').$badgeHtml.'</a>';
	}

	/**
	 * Renders a sidebar navigation entry as either a leaf link or recursive submenu.
	 *
	 * submenu state reflects active descendants, parent links are rendered as explicit "open" child
	 * actions, depth is emitted for styling, and badges fall back to recursive child counts.
	 *
	 * @param array<string, mixed> $entry Navigation entry payload.
	 * @param array<string, mixed> $data Page render data used for active resource/page matching.
	 * @param int $depth Current submenu depth.
	 * @return string Sidebar entry HTML.
	 */
	private static function sidebarNavigationEntryHtml(array $entry, array $data, int $depth=0): string {
		$children=array_values(array_filter(is_array($entry['children'] ?? null) ? $entry['children'] : [], static fn(mixed $child): bool => is_array($child)));
		if($children===[]){
			return self::sidebarNavigationLinkHtml($entry, $data, $depth);
		}
		$label=trim((string)($entry['label'] ?? '')) ?: self::panelText('common.untitled');
		$description=trim((string)($entry['description'] ?? ''));
		$kind=Resource::normalizeName((string)($entry['kind'] ?? 'navigation_item'));
		$name=Resource::normalizeName((string)($entry['name'] ?? ''));
		$icon=self::navigationIconToken((string)($entry['icon'] ?? ''), $label);
		$active=self::sidebarNavigationActive($entry, $data);
		$treeActive=$active || !empty($entry['active_descendant']);
		$badge=$entry['badge'] ?? null;
		$badgeHtml=$badge!==null && trim((string)$badge)!=='' ? '<span class="dp-panel-sidebar-badge dp-panel-sidebar-badge-'.self::safeTone((string)($entry['badge_tone'] ?? 'neutral')).'">'.self::e((string)$badge).'</span>' : '';
		if($badgeHtml===''){
			$badgeHtml='<span class="dp-panel-sidebar-badge">'.self::e((string)self::navigationChildrenCount($children)).'</span>';
		}
		$childHtml='';
		foreach($children as $child){
			$childHtml.=self::sidebarNavigationEntryHtml($child, $data, $depth+1);
		}
		$open=$treeActive ? ' open' : '';
		$summary='<summary data-dp-panel-submenu-summary title="'.self::e($label).'" aria-label="'.self::e($label).'">'
			.'<span class="dp-panel-sidebar-icon" aria-hidden="true">'.self::e($icon).'</span>'
			.'<span class="dp-panel-sidebar-copy"><strong>'.self::e($label).'</strong>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').'</span>'
			.$badgeHtml
			.'<i aria-hidden="true"></i>'
			.'</summary>';
		$parentLink='';
		$url=trim((string)($entry['url'] ?? ''));
		if($url!==''){
			$parentLink=self::sidebarNavigationLinkHtml(array_replace($entry, [
				'label'=>self::panelText('nav.open', ['label'=>$label]),
				'description'=>'',
				'badge'=>null,
				'children'=>[],
			]), $data, $depth+1, ' dp-panel-sidebar-link-parent');
		}
		return '<details class="dp-panel-sidebar-submenu dp-panel-sidebar-submenu-depth-'.$depth.' dp-panel-sidebar-link-'.$kind.($treeActive ? ' active' : '').'" data-dp-panel-nav-name="'.self::e($name).'" data-dp-panel-submenu-depth="'.self::e((string)$depth).'"'.$open.'>'.$summary.'<div class="dp-panel-sidebar-submenu-items">'.$parentLink.$childHtml.'</div></details>';
	}

	/**
	 * Counts nested navigation children for badge fallback display.
	 *
	 * only array-shaped children count as navigation nodes; recursion follows each child children list
	 * and ignores malformed values.
	 *
	 * @param array<int, mixed> $children Child navigation entries.
	 * @return int Recursive child count.
	 */
	private static function navigationChildrenCount(array $children): int {
		$count=0;
		foreach($children as $child){
			if(!is_array($child)){
				continue;
			}
			$count++;
			$count+=self::navigationChildrenCount(is_array($child['children'] ?? null) ? $child['children'] : []);
		}
		return $count;
	}

	/**
	 * Finds the first navigable URL inside a sidebar navigation group.
	 *
	 * collapse-disabled sidebars use this URL for group headings so a top-level group label can
	 * navigate to its first visible page instead of becoming inert text.
	 *
	 * @param array<int, mixed> $entries Group entries.
	 * @return string First non-empty entry URL.
	 */
	private static function sidebarFirstEntryUrl(array $entries): string {
		foreach($entries as $entry){
			if(!is_array($entry)){
				continue;
			}
			$url=trim((string)($entry['url'] ?? ''));
			if($url!==''){
				return $url;
			}
			$childUrl=self::sidebarFirstEntryUrl(is_array($entry['children'] ?? null) ? $entry['children'] : []);
			if($childUrl!==''){
				return $childUrl;
			}
		}
		return '';
	}

	/**
	 * Renders a leaf sidebar navigation link with active state, badge, icon, and metadata attributes.
	 *
	 * invalid entries without labels or URLs are omitted, new-tab links receive noopener/noreferrer,
	 * and all entry-derived strings are escaped before becoming HTML attributes or text.
	 *
	 * @param array<string, mixed> $entry Navigation entry payload.
	 * @param array<string, mixed> $data Page render data used for active matching.
	 * @param int $depth Current navigation depth.
	 * @param string $extraClass Additional trusted renderer-owned class suffix.
	 * @return string Sidebar link HTML or empty string.
	 */
	private static function sidebarNavigationLinkHtml(array $entry, array $data, int $depth=0, string $extraClass=''): string {
		$label=trim((string)($entry['label'] ?? ''));
		$url=trim((string)($entry['url'] ?? ''));
		if($label==='' || $url===''){
			return '';
		}
		$description=trim((string)($entry['description'] ?? ''));
		$kind=Resource::normalizeName((string)($entry['kind'] ?? 'resource'));
		$name=Resource::normalizeName((string)($entry['name'] ?? ''));
		$icon=self::navigationIconToken((string)($entry['icon'] ?? ''), $label);
		$active=self::sidebarNavigationActive($entry, $data);
		$badge=$entry['badge'] ?? null;
		$badgeHtml='';
		if($badge!==null && trim((string)$badge)!==''){
			$tone=self::safeTone((string)($entry['badge_tone'] ?? 'neutral'));
			$badgeHtml='<span class="dp-panel-sidebar-badge dp-panel-sidebar-badge-'.$tone.'">'.self::e((string)$badge).'</span>';
		}
		$target=!empty($entry['new_tab']) ? ' target="_blank" rel="noopener noreferrer"' : '';
		$title=$label.($description!=='' ? ' - '.$description : '');
		return '<a class="dp-panel-sidebar-link dp-panel-sidebar-link-'.$kind.($active ? ' active' : '').$extraClass.'" href="'.self::e($url).'" title="'.self::e($title).'" data-dp-panel-nav-name="'.self::e($name).'" data-dp-panel-nav-kind="'.self::e($kind).'" data-dp-panel-nav-group="'.self::e((string)($entry['group'] ?? '')).'" data-dp-panel-nav-depth="'.self::e((string)$depth).'"'.$target.($active ? ' aria-current="page"' : '').'>'
			.'<span class="dp-panel-sidebar-icon" aria-hidden="true">'.self::e($icon).'</span>'
			.'<span class="dp-panel-sidebar-copy"><strong>'.self::e($label).'</strong>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').'</span>'
			.$badgeHtml
			.'</a>';
	}

	/**
	 * Determines whether a sidebar entry represents the current resource, page, descendant, or request URL.
	 *
	 * explicit active flags win, resource/page names compare through normalized names, and URL
	 * comparison strips volatile table/query state before matching against the current request URI.
	 *
	 * @param array<string, mixed> $entry Navigation entry payload.
	 * @param array<string, mixed> $data Page render data.
	 * @return bool Whether the entry should render as active.
	 */
	private static function sidebarNavigationActive(array $entry, array $data): bool {
		if(($entry['active'] ?? false)===true || ($entry['active_descendant'] ?? false)===true){
			return true;
		}
		$name=Resource::normalizeName((string)($entry['name'] ?? ''));
		$kind=Resource::normalizeName((string)($entry['kind'] ?? 'resource'));
		$resource=is_array($data['resource'] ?? null) ? $data['resource'] : null;
		$page=is_array($data['page'] ?? null) ? $data['page'] : null;
		if($kind==='resource' && $resource!==null && Resource::normalizeName((string)($resource['name'] ?? ''))===$name){
			return true;
		}
		if($kind==='page' && $page!==null && Resource::normalizeName((string)($page['name'] ?? ''))===$name){
			return true;
		}
		return self::normalizedNavigationUrl((string)($entry['url'] ?? ''))===self::normalizedNavigationUrl((string)($_SERVER['REQUEST_URI'] ?? ''));
	}

	/**
	 * Normalizes navigation URLs for active-state comparison.
	 *
	 * preserves path and stable query parameters while removing volatile table UI parameters such as
	 * paging, sorting, density, and format. The returned string is for comparison, not for user-facing output.
	 *
	 * @param string $url URL or request URI.
	 * @return string Normalized comparison URL.
	 */
	private static function normalizedNavigationUrl(string $url): string {
		$url=trim($url);
		if($url===''){
			return '';
		}
		$parts=parse_url($url);
		if(!is_array($parts)){
			return $url;
		}
		$path=(string)($parts['path'] ?? $url);
		$query=(string)($parts['query'] ?? '');
		if($query!==''){
			parse_str($query, $params);
			foreach(['page', 'per_page', 'sort', 'dir', 'density', 'format'] as $volatile){
				unset($params[$volatile]);
			}
			ksort($params);
			$query=http_build_query($params);
		}
		return rtrim($path, '/').($query!=='' ? '?'.$query : '');
	}

	/**
	 * Resolves compact text tokens used by navigation icon placeholders.
	 *
	 * known icon names map to stable two-letter tokens, while arbitrary labels fall back to initials
	 * extracted from alphanumeric words. The token is escaped by callers before rendering.
	 *
	 * @param string $icon Requested icon identifier.
	 * @param string $label Fallback label for initials.
	 * @return string Compact icon token.
	 */
	private static function navigationIconToken(string $icon, string $label): string {
		$icon=Resource::normalizeName($icon);
		$map=[
			'layout_dashboard'=>'HM',
			'home'=>'HM',
			'shopping_bag'=>'OR',
			'package'=>'PK',
			'boxes'=>'BX',
			'users'=>'US',
			'user'=>'US',
			'ticket'=>'TK',
			'message_circle'=>'MS',
			'activity'=>'AC',
			'file'=>'FI',
			'link'=>'LN',
			'panel'=>'DP',
		];
		if(isset($map[$icon])){
			return $map[$icon];
		}
		$letters=preg_replace('/[^a-z0-9 ]/i', '', $label);
		$words=array_values(array_filter(explode(' ', strtoupper((string)$letters)), static fn(string $word): bool => $word!==''));
		if(count($words)>=2){
			return substr($words[0], 0, 1).substr($words[1], 0, 1);
		}
		return substr(strtoupper((string)($words[0] ?? 'P')), 0, 2);
	}

	/**
	 * Rehydrates an optional PanelRequest from page render data.
	 *
	 * malformed request payloads are ignored and return null so shell rendering can continue without
	 * treating client-provided arrays as trusted request objects.
	 *
	 * @param array<string, mixed> $data Page render data.
	 * @return PanelRequest|null Rehydrated request or null.
	 */
	private static function requestFromData(array $data): ?PanelRequest {
		if(is_array($data['request'] ?? null)){
			try{
				return PanelRequest::fromArray($data['request']);
			}
			catch(\Throwable){
				return null;
			}
		}
		return null;
	}

	/**
	 * Resolves the live-refresh interval for surfaces that can safely refresh in place.
	 *
	 * live updates can be globally disabled, are limited to known refreshable page kinds, and clamp the
	 * configured interval between five seconds and five minutes.
	 *
	 * @param array<string, mixed> $data Page render data.
	 * @return int Refresh interval in milliseconds, or zero when disabled.
	 */
	private static function liveRefreshInterval(array $data): int {
		if(PanelConfig::config('live_updates', true)===false){
			return 0;
		}
		$kind=Resource::normalizeName((string)($data['kind'] ?? 'dashboard'));
		if(!in_array($kind, ['dashboard', 'index', 'board', 'show', 'relation'], true)){
			return 0;
		}
		$interval=(int)PanelConfig::config('live_update_interval_ms', 15000);
		return max(5000, min(300000, $interval));
	}

	/**
	 * Determines whether content update flash effects should be enabled for the current surface.
	 *
	 * page data overrides fall back through content_update_flashes and update_flashes config keys, then
	 * pass through FILTER_VALIDATE_BOOLEAN for consistent string/bool handling.
	 *
	 * @param array<string, mixed> $data Page render data.
	 * @return bool Whether update flash data attributes should be emitted.
	 */
	private static function updateFlashEnabled(array $data): bool {
		$value=$data['update_flash'] ?? $data['content_update_flashes'] ?? PanelConfig::config('content_update_flashes', PanelConfig::config('update_flashes', false));
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * Renders header tool controls for theme and live-update affordances.
	 *
	 * the current implementation intentionally returns no controls while preserving the extension
	 * point and signature used by shell rendering.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @param string $mode Active theme mode.
	 * @param int $liveInterval Resolved live-refresh interval.
	 * @return string Header tools HTML.
	 */
	private static function headingToolsHtml(PanelTheme $theme, string $mode, int $liveInterval): string {
		return '';
	}

	/**
	 * Renders the live-refresh control placeholder.
	 *
	 * this method currently emits no UI and exists as the single renderer-owned boundary for future
	 * live-refresh controls so shell markup does not grow ad hoc controls elsewhere.
	 *
	 * @param int $interval Refresh interval in milliseconds.
	 * @return string Live-refresh control HTML.
	 */
	private static function liveRefreshControlHtml(int $interval): string {
		return '';
	}

	/**
	 * Resolves the active theme mode from cookie state and theme defaults.
	 *
	 * dark-mode-disabled themes force light mode, cookie values are normalized and allow-listed, and
	 * invalid cookie data falls back to the theme default.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @return string Theme mode token: light, dark, or system.
	 */
	private static function themeMode(PanelTheme $theme): string {
		if(!$theme->darkModeEnabled()){
			return 'light';
		}
		$cookie=Resource::normalizeName((string)($_COOKIE['dataphyre_panel_theme_mode'] ?? ''));
		if(in_array($cookie, ['light', 'dark', 'system'], true)){
			return $cookie;
		}
		return $theme->mode();
	}

	/**
	 * Renders the early theme-mode script tag used to avoid first-paint mode flicker.
	 *
	 * the head script is emitted only when dark mode is enabled and carries the escaped mode token in a
	 * data attribute for the static asset to consume.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @param string $mode Resolved theme mode.
	 * @return string Script tag HTML or empty string.
	 */
	private static function themeHeadScript(PanelTheme $theme, string $mode): string {
		if(!$theme->darkModeEnabled()){
			return '';
		}
		$mode=self::e($mode);
		return '<script src="'.self::e(self::assetUrl('panel-head.js')).'" data-dp-panel-theme-mode="'.$mode.'"></script>';
	}

	/**
	 * Renders theme-mode toggle buttons for light, dark, and system preferences.
	 *
	 * controls are emitted only when the active theme enables toggling, and aria-pressed reflects the
	 * currently resolved mode for client-side synchronization.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @param string $mode Resolved theme mode.
	 * @return string Theme toggle HTML or empty string.
	 */
	private static function themeModeToggleHtml(PanelTheme $theme, string $mode): string {
		if(!$theme->modeToggleEnabled()){
			return '';
		}
		$html='';
		foreach(['light'=>self::panelText('theme.light'), 'dark'=>self::panelText('theme.dark'), 'system'=>self::panelText('theme.system')] as $value=>$label){
			$html.='<button type="button" data-dp-theme-mode-choice="'.self::e($value).'"'.($mode===$value ? ' aria-pressed="true"' : ' aria-pressed="false"').'>'.self::e($label).'</button>';
		}
		return '<div class="dp-panel-theme-toggle" role="group" aria-label="'.self::e(self::panelText('theme.mode')).'">'.$html.'</div>';
	}

	/**
	 * Renders the optional theme preset selector form.
	 *
	 * preset names are normalized, duplicate labels are ignored, existing query parameters are
	 * preserved through hidden inputs except theme selector keys, and the form opts out of AJAX navigation.
	 *
	 * @return string Theme preset selector HTML or empty string.
	 */
	private static function themePresetSelectorHtml(): string {
		if(!filter_var(PanelConfig::config('theme_selector', false), FILTER_VALIDATE_BOOLEAN)){
			return '';
		}
		$parameter=Resource::normalizeName((string)PanelConfig::config('theme_selector_parameter', 'panel_theme'));
		$parameter=$parameter!=='' ? $parameter : 'panel_theme';
		$options=PanelConfig::config('theme_selector_presets', [
			'flat_minima'=>'Flat Minima',
			'glass'=>'Glass',
			'brutalist'=>'Brutalist',
		]);
		if(!is_array($options) || $options===[]){
			return '';
		}
		$current=Resource::normalizeName((string)($_GET[$parameter] ?? $_GET['preset'] ?? $_COOKIE['dataphyre_panel_theme_preset'] ?? ''));
		$optionHtml='';
		$seenLabels=[];
		foreach($options as $value=>$label){
			$value=Resource::normalizeName((string)$value);
			$label=trim((string)$label);
			if($label===''){
				continue;
			}
			$dedupeKey=Resource::normalizeName($label);
			if($dedupeKey!=='' && isset($seenLabels[$dedupeKey])){
				continue;
			}
			if($dedupeKey!==''){
				$seenLabels[$dedupeKey]=true;
			}
			$optionHtml.='<option value="'.self::e($value).'"'.($current===$value ? ' selected' : '').'>'.self::e($label).'</option>';
		}
		if($optionHtml===''){
			return '';
		}
		$label=trim((string)PanelConfig::config('theme_selector_label', self::panelText('theme.selector'))) ?: self::panelText('theme.selector');
		$hidden=self::themePresetSelectorHiddenInputs($_GET, [$parameter=>true, 'preset'=>true, 'theme'=>true]);
		return '<form class="dp-panel-theme-select" method="get" data-dp-panel-theme-select data-dp-panel-theme-parameter="'.self::e($parameter).'" data-dp-panel-no-ajax="1">'
			.$hidden
			.'<label><span>'.self::e($label).'</span><select name="'.self::e($parameter).'" onchange="this.form.requestSubmit?this.form.requestSubmit():this.form.submit()">'
			.$optionHtml
			.'</select></label>'
			.'</form>';
	}

	/**
	 * Serializes preserved query parameters as hidden inputs for the theme preset selector.
	 *
	 * nested arrays are recursed into bracket notation, excluded root keys are skipped, and objects,
	 * resources, and null values are not serialized into the form.
	 *
	 * @param array<string|int, mixed> $query Query parameter tree.
	 * @param array<string, bool> $exclude Root-level query keys to omit.
	 * @param string $prefix Nested input prefix.
	 * @return string Hidden input HTML.
	 */
	private static function themePresetSelectorHiddenInputs(array $query, array $exclude, string $prefix=''): string {
		$html='';
		foreach($query as $key=>$value){
			if(!is_string($key) && !is_int($key)){
				continue;
			}
			$key=(string)$key;
			if($prefix==='' && isset($exclude[$key])){
				continue;
			}
			$name=$prefix==='' ? $key : $prefix.'['.$key.']';
			if(is_array($value)){
				$html.=self::themePresetSelectorHiddenInputs($value, [], $name);
				continue;
			}
			if(is_object($value) || is_resource($value) || $value===null){
				continue;
			}
			$html.='<input type="hidden" name="'.self::e($name).'" value="'.self::e((string)$value).'">';
		}
		return $html;
	}

	/**
	 * Renders the inline runtime script for theme mode controls when enabled.
	 *
	 * the script is suppressed unless the theme allows mode toggling, keeping client behavior aligned
	 * with server-rendered controls.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @return string JavaScript source or empty string.
	 */
	private static function themeModeScript(PanelTheme $theme): string {
		if(!$theme->modeToggleEnabled()){
			return '';
		}
		return self::themeModeRuntimeScript();
	}

	/**
	 * Returns the client runtime for theme mode and preset persistence controls.
	 *
	 * the script stores allow-listed mode/preset values in localStorage and SameSite cookies, updates
	 * aria-pressed state, and never reads or writes unrelated storage keys.
	 *
	 * @return string JavaScript source embedded by the shell when theme controls are enabled.
	 */
	private static function themeModeRuntimeScript(): string {
		return 'function dpPanelCurrentThemeMode(){var mode=document.documentElement.dataset.dpThemeMode||"system";try{mode=localStorage.getItem("dataphyre_panel_theme_mode")||mode;}catch(error){}return ["light","dark","system"].indexOf(mode)===-1?"system":mode;}function dpPanelSetThemeMode(mode){if(["light","dark","system"].indexOf(mode)===-1){mode="system";}document.documentElement.dataset.dpThemeMode=mode;if(document.body){document.body.dataset.dpThemeMode=mode;}try{localStorage.setItem("dataphyre_panel_theme_mode",mode);document.cookie="dataphyre_panel_theme_mode="+mode+"; path=/; max-age=31536000; SameSite=Lax";}catch(error){}document.querySelectorAll("[data-dp-theme-mode-choice]").forEach(function(button){button.setAttribute("aria-pressed",button.dataset.dpThemeModeChoice===mode?"true":"false");});}function dpPanelRefreshThemeModeControls(){dpPanelSetThemeMode(dpPanelCurrentThemeMode());}function dpPanelPersistThemePreset(value){value=(value||"").replace(/[^a-z0-9_\\-]/gi,"").toLowerCase();try{if(value){localStorage.setItem("dataphyre_panel_theme_preset",value);document.cookie="dataphyre_panel_theme_preset="+encodeURIComponent(value)+"; path=/; max-age=31536000; SameSite=Lax";}else{localStorage.removeItem("dataphyre_panel_theme_preset");document.cookie="dataphyre_panel_theme_preset=; path=/; max-age=0; SameSite=Lax";}}catch(error){}}function dpPanelRefreshThemePresetControls(){document.querySelectorAll("[data-dp-panel-theme-select] select").forEach(function(select){if(select.value){dpPanelPersistThemePreset(select.value);}});}document.addEventListener("change",function(event){var select=event.target&&event.target.closest&&event.target.closest("[data-dp-panel-theme-select] select");if(!select){return;}dpPanelPersistThemePreset(select.value||"");});document.addEventListener("click",function(event){var button=event.target.closest&&event.target.closest("[data-dp-theme-mode-choice]");if(!button){return;}event.preventDefault();dpPanelSetThemeMode(button.dataset.dpThemeModeChoice||"system");});document.addEventListener("DOMContentLoaded",function(){dpPanelRefreshThemeModeControls();dpPanelRefreshThemePresetControls();});';
	}

	/**
	 * Renders additional stylesheet assets declared by the active theme.
	 *
	 * asset URLs containing line breaks are rejected, attribute names are normalized, empty attribute
	 * values are skipped, and every emitted value is escaped.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @return string Link tag HTML.
	 */
	private static function themeCssAssets(PanelTheme $theme): string {
		$html='';
		foreach($theme->stylesheetAssets() as $asset){
			$url=trim((string)($asset['href'] ?? ''));
			if($url!=='' && !str_contains($url, "\n") && !str_contains($url, "\r")){
				$attributes=' rel="stylesheet" href="'.self::e($url).'"';
				foreach(is_array($asset['attributes'] ?? null) ? $asset['attributes'] : [] as $name=>$value){
					$name=Resource::normalizeName((string)$name);
					if($name!=='' && $value!==null && trim((string)$value)!==''){
						$attributes.=' '.$name.'="'.self::e((string)$value).'"';
					}
				}
				$html.='<link'.$attributes.'>';
			}
		}
		return $html;
	}

	/**
	 * Renders the optional theme brand logo block.
	 *
	 * brand metadata comes from the active theme, dark-mode logo support is additive, and logo height,
	 * source URLs, and alt text are escaped before entering image markup.
	 *
	 * @param PanelTheme $theme Active panel theme.
	 * @return string Brand logo HTML or empty string.
	 */
	private static function brandHtml(PanelTheme $theme): string {
		$brand=$theme->brand();
		$logo=trim((string)($brand['logo'] ?? ''));
		if($logo===''){
			return '';
		}
		$darkLogo=trim((string)($brand['dark_logo'] ?? ''));
		$height=trim((string)($brand['logo_height'] ?? ''));
		$style=$height!=='' ? ' style="height:'.self::e($height).'"' : '';
		return '<div class="dp-panel-brand">'.($darkLogo!=='' ? '<img class="dp-panel-brand-logo-dark" src="'.self::e($darkLogo).'" alt="'.self::e(PanelConfig::brandName()).'"'.$style.'>' : '').'<img class="dp-panel-brand-logo" src="'.self::e($logo).'" alt="'.self::e(PanelConfig::brandName()).'"'.$style.'></div>';
	}

	/**
	 * Renders the surface guidance placeholder.
	 *
	 * the current shell keeps guidance data available through surfaceGuidance() but emits no visible
	 * guidance chrome from this method, preserving a single future rendering hook.
	 *
	 * @param string $title Current surface title.
	 * @param array<string, mixed> $data Page render data.
	 * @return string Guidance HTML.
	 */
	private static function surfaceGuidanceHtml(string $title, array $data): string {
		return '';
	}

	/**
	 * Builds contextual guidance metadata for common panel surface kinds.
	 *
	 * guidance is derived from page kind, resource/page metadata, search state, import state, and
	 * counts already present in render data. It does not query resources or mutate panel state.
	 *
	 * @param string $title Current surface title.
	 * @param array<string, mixed> $data Page render data.
	 * @return array{tone: string, headline: string, message: string, details?: array<int, string>}|null Guidance payload or null when no guidance applies.
	 */
	private static function surfaceGuidance(string $title, array $data): ?array {
		$kind=(string)($data['kind'] ?? '');
		$resource=is_array($data['resource'] ?? null) ? $data['resource'] : null;
		$page=is_array($data['page'] ?? null) ? $data['page'] : null;
		$resourceLabel=(string)($resource['plural_label'] ?? $resource['label'] ?? $resource['name'] ?? 'records');
		$singleLabel=(string)($resource['label'] ?? $resource['name'] ?? 'record');
		if($kind==='dashboard'){
			$search=is_array($data['global_search'] ?? null) ? $data['global_search'] : [];
			$query=trim((string)($search['query'] ?? ''));
			if($query!==''){
				$count=count(is_array($search['results'] ?? null) ? $search['results'] : []);
				return [
					'tone'=>$count>0 ? 'success' : 'warning',
					'headline'=>$count>0 ? self::panelText('search.ready_headline') : self::panelText('search.empty_headline'),
					'message'=>$count>0 ? self::panelText('search.ready_message') : self::panelText('search.empty_message'),
					'details'=>[$count.' result'.($count===1 ? '' : 's'), 'Query: '.$query],
				];
			}
			return null;
		}
		if($kind==='custom_page'){
			$widgetCount=count(is_array($data['widgets'] ?? null) ? $data['widgets'] : []);
			$tableCount=count(is_array($data['tables'] ?? null) ? $data['tables'] : []);
			if($widgetCount===0 && $tableCount===0){
				return null;
			}
			return [
				'tone'=>'info',
				'headline'=>self::panelText('panel.custom_blocks_title'),
				'message'=>self::panelText('panel.custom_blocks_body'),
				'details'=>[$widgetCount.' '.self::panelText($widgetCount===1 ? 'panel.widget' : 'panel.widgets'), $tableCount.' '.self::panelText($tableCount===1 ? 'panel.table' : 'panel.tables')],
			];
		}
		if($kind==='index' && $resource!==null){
			$total=(int)($data['total_count'] ?? $data['record_count'] ?? 0);
			$view=trim((string)($data['active_view'] ?? ''));
			return [
				'tone'=>$total>0 ? 'primary' : 'info',
				'headline'=>$total>0 ? self::panelText('table.ready_title') : self::panelText('table.first_records_title'),
				'message'=>$total>0 ? self::panelText('table.ready_body') : self::panelText('table.first_records_body'),
				'details'=>[$total.' '.$resourceLabel, $view!=='' ? self::panelText('table.view_detail', ['view'=>$view]) : self::panelText('table.all_records')],
			];
		}
		if(in_array($kind, ['create', 'store'], true) && $resource!==null){
			return [
				'tone'=>'primary',
				'headline'=>self::panelText('form.create_title', ['resource'=>$singleLabel]),
				'message'=>self::panelText('form.create_body'),
			];
		}
		if(in_array($kind, ['edit', 'update'], true) && $resource!==null){
			return [
				'tone'=>'info',
				'headline'=>self::panelText('form.update_title', ['resource'=>$singleLabel]),
				'message'=>self::panelText('form.update_body'),
			];
		}
		if($kind==='show' && $resource!==null){
			return [
				'tone'=>'info',
				'headline'=>self::panelText('show.review_title'),
				'message'=>self::panelText('show.review_body'),
			];
		}
		if($kind==='import' && $resource!==null){
			return [
				'tone'=>'primary',
				'headline'=>self::panelText('import.guidance_title', ['resource'=>$resourceLabel]),
				'message'=>self::panelText('import.guidance_body'),
			];
		}
		if($kind==='import_preview' && $resource!==null){
			$invalid=(int)($data['invalid_count'] ?? 0);
			$rows=(int)($data['row_count'] ?? 0);
			return [
				'tone'=>$invalid>0 ? 'warning' : 'success',
				'headline'=>$invalid>0 ? self::panelText('import.resolve_title') : self::panelText('import.clean_title'),
				'message'=>$invalid>0 ? self::panelText('import.resolve_body') : self::panelText('import.clean_body'),
				'details'=>[$rows.' '.self::panelText($rows===1 ? 'import.row' : 'common.records'), $invalid.' '.self::panelText($invalid===1 ? 'action.issue' : 'action.issues')],
			];
		}
		if(str_contains($kind, 'action') || str_starts_with($kind, 'bulk_')){
			return [
				'tone'=>'info',
				'headline'=>self::panelText('action.context_title'),
				'message'=>self::panelText('action.context_body'),
			];
		}
		if($page!==null && $title!==''){
			return [
				'tone'=>'info',
				'headline'=>self::panelText('page.focused_workspace_title'),
				'message'=>self::panelText('page.focused_workspace_body'),
			];
		}
		return null;
	}

	/**
	 * Builds breadcrumb entries for dashboard, resource, relation, action, and custom page surfaces.
	 *
	 * breadcrumbs are derived from render metadata only, mark the terminal entry as current, and remove
	 * the current URL to prevent self-navigation from the active crumb.
	 *
	 * @param string $title Current surface title.
	 * @param array<string, mixed> $data Page render data.
	 * @return array<int, array{label: string, url: string|null, current?: bool}> Breadcrumb entries.
	 */
	private static function breadcrumbs(string $title, array $data=[]): array {
		$items=[['label'=>PanelConfig::homeLabel(), 'url'=>PanelConfig::url()]];
		$resource=is_array($data['resource'] ?? null) ? $data['resource'] : null;
		$page=is_array($data['page'] ?? null) ? $data['page'] : null;
		$kind=(string)($data['kind'] ?? '');
		if($resource!==null){
			$resourceLabel=(string)((in_array($kind, ['index'], true) ? ($resource['plural_label'] ?? null) : null) ?? $resource['label'] ?? $resource['name'] ?? 'Resource');
			$resourceUrl=(string)($resource['url'] ?? PanelConfig::url((string)($resource['name'] ?? '')));
			$items[]=['label'=>$resourceLabel, 'url'=>$resourceUrl];
			if(in_array($kind, ['index'], true)){
				return self::markLastBreadcrumb($items);
			}
			if($kind==='create' || $kind==='store'){
				$items[]=['label'=>self::panelText('table.create'), 'url'=>null];
				return self::markLastBreadcrumb($items);
			}
			if($kind==='edit' || $kind==='update'){
				$items[]=['label'=>self::panelText('common.edit'), 'url'=>null];
				return self::markLastBreadcrumb($items);
			}
			if($kind==='show'){
				$identity=is_array($data['record_identity'] ?? null) ? $data['record_identity'] : [];
				$items[]=['label'=>(string)($identity['title'] ?? $title), 'url'=>null];
				return self::markLastBreadcrumb($items);
			}
			if($kind==='relation'){
				$relation=is_array($data['relation'] ?? null) ? $data['relation'] : [];
				$items[]=['label'=>(string)($relation['label'] ?? $title), 'url'=>null];
				return self::markLastBreadcrumb($items);
			}
			if(in_array($kind, ['action', 'action_form', 'action_missing', 'action_empty_selection'], true)){
				$action=is_array($data['action'] ?? null) ? $data['action'] : [];
				$items[]=['label'=>(string)($action['label'] ?? $title), 'url'=>null];
				return self::markLastBreadcrumb($items);
			}
			$items[]=['label'=>$title, 'url'=>null];
			return self::markLastBreadcrumb($items);
		}
		if($page!==null){
			$pageLabel=(string)($page['label'] ?? $title);
			if($pageLabel===(string)($items[0]['label'] ?? '')){
				return self::markLastBreadcrumb($items);
			}
			$items[]=[
				'label'=>$pageLabel,
				'url'=>(string)($page['url'] ?? '') ?: null,
			];
			return self::markLastBreadcrumb($items);
		}
		if(!in_array($kind, ['dashboard'], true) && $title!==PanelConfig::homeLabel()){
			$items[]=['label'=>$title, 'url'=>null];
		}
		return self::markLastBreadcrumb($items);
	}

	/**
	 * Marks the final breadcrumb item as current and clears its URL.
	 *
	 * preserves prior crumb order and metadata while enforcing a single current item for accessible
	 * breadcrumb rendering.
	 *
	 * @param array<int, array<string, mixed>> $items Breadcrumb entries.
	 * @return array<int, array<string, mixed>> Breadcrumb entries with current marker applied.
	 */
	private static function markLastBreadcrumb(array $items): array {
		$last=array_key_last($items);
		foreach($items as $index=>$item){
			$items[$index]['current']=$index===$last;
			if($index===$last){
				$items[$index]['url']=null;
			}
		}
		return $items;
	}

	/**
	 * Renders breadcrumb entries as accessible navigation HTML.
	 *
	 * single-item trails are omitted, current or URL-less entries render as spans, linked entries render
	 * as anchors, and labels/URLs are escaped.
	 *
	 * @param array<int, array<string, mixed>> $items Breadcrumb entries.
	 * @return string Breadcrumb navigation HTML or empty string.
	 */
	private static function breadcrumbsHtml(array $items): string {
		if(count($items)<=1){
			return '';
		}
		$html='';
		foreach($items as $item){
			$label=self::e((string)($item['label'] ?? ''));
			if($label===''){
				continue;
			}
			$url=(string)($item['url'] ?? '');
			$current=($item['current'] ?? false)===true;
			$html.=$current || $url==='' ? '<span>'.$label.'</span>' : '<a href="'.self::e($url).'">'.$label.'</a>';
		}
		return $html!=='' ? '<nav class="dp-panel-breadcrumbs" aria-label="Breadcrumbs">'.$html.'</nav>' : '';
	}

	/**
	 * Reads a value from array or object records using direct properties or getter methods.
	 *
	 * arrays use key lookup, objects prefer public properties and then conventional getX methods, and
	 * the provided default is returned when no value can be resolved.
	 *
	 * @param mixed $record Array or object record.
	 * @param string $key Field key to resolve.
	 * @param mixed $default Fallback value.
	 * @return mixed array value, public property, getter result, or the caller fallback when unavailable.
	 */
	private static function recordValue(mixed $record, string $key, mixed $default=''): mixed {
		if(is_array($record)){
			return $record[$key] ?? $default;
		}
		if(is_object($record)){
			if(isset($record->{$key})){
				return $record->{$key};
			}
			$method='get'.str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key)));
			if(method_exists($record, $method)){
				return $record->{$method}();
			}
		}
		return $default;
	}

	/**
	 * Renders dashboard widget cards and chart widgets.
	 *
	 * malformed widget entries are skipped, widget URLs are allow-listed, tones are normalized, and all
	 * labels, values, descriptions, icons, and hrefs are escaped before output.
	 *
	 * @param array<int, mixed> $widgets Widget configuration entries.
	 * @return string Widget section HTML or empty string.
	 */
	private static function widgetsHtml(array $widgets): string {
		if($widgets===[]){
			return '';
		}
		$html='';
		foreach($widgets as $widget){
			if(!is_array($widget)){
				continue;
			}
			if(in_array(Resource::normalizeName((string)($widget['type'] ?? 'stat')), ['chart', 'trend'], true)){
				$html.=self::chartWidgetHtml($widget);
				continue;
			}
			$tone=self::safeTone((string)($widget['tone'] ?? 'neutral'));
			$label=self::e((string)($widget['label'] ?? ''));
			$value=self::e(self::stringValue($widget['value'] ?? ''));
			$description=trim((string)($widget['description'] ?? ''));
			$icon=trim((string)($widget['icon'] ?? ''));
			$url=trim((string)($widget['url'] ?? ''));
			$body=($icon!=='' ? '<span class="dp-panel-widget-icon" data-dp-panel-icon="'.self::e(Resource::normalizeName($icon)).'" aria-hidden="true"></span>' : '')
				.'<span class="dp-panel-widget-label">'.$label.'</span>'
				.'<strong>'.$value.'</strong>'
				.($description!=='' ? '<small>'.self::e($description).'</small>' : '');
			if($url!=='' && self::safeWidgetUrl($url)!==''){
				$html.='<a class="dp-panel-widget dp-panel-widget-'.$tone.'" href="'.self::e($url).'">'.$body.'</a>';
			}
			else {
				$html.='<article class="dp-panel-widget dp-panel-widget-'.$tone.'">'.$body.'</article>';
			}
		}
		return '<section class="dp-panel-widgets" data-dp-panel-refresh-region="widgets">'.$html.'</section>';
	}

	/**
	 * Renders one chart-style widget card.
	 *
	 * chart data is derived from the widget meta payload, optional links pass through safeWidgetUrl(),
	 * and the card remains valid as either an anchor or article depending on link eligibility.
	 *
	 * @param array<string, mixed> $widget Widget payload.
	 * @return string Chart widget HTML.
	 */
	private static function chartWidgetHtml(array $widget): string {
		$tone=self::safeTone((string)($widget['tone'] ?? 'primary'));
		$label=self::e((string)($widget['label'] ?? ''));
		$value=trim(self::stringValue($widget['value'] ?? ''));
		$description=trim((string)($widget['description'] ?? ''));
		$icon=trim((string)($widget['icon'] ?? ''));
		$url=trim((string)($widget['url'] ?? ''));
		$meta=is_array($widget['meta'] ?? null) ? $widget['meta'] : [];
		$chart=self::chartSvgHtml($meta, $tone, $label);
		$body=($icon!=='' ? '<span class="dp-panel-widget-icon" data-dp-panel-icon="'.self::e(Resource::normalizeName($icon)).'" aria-hidden="true"></span>' : '')
			.'<span class="dp-panel-widget-label">'.$label.'</span>'
			.($value!=='' ? '<strong>'.$value.'</strong>' : '')
			.($description!=='' ? '<small>'.self::e($description).'</small>' : '')
			.$chart;
		$class='dp-panel-widget dp-panel-widget-chart dp-panel-widget-'.$tone;
		if($url!=='' && self::safeWidgetUrl($url)!==''){
			return '<a class="'.$class.'" href="'.self::e($url).'">'.$body.'</a>';
		}
		return '<article class="'.$class.'">'.$body.'</article>';
	}

	/**
	 * Chooses and renders an SVG chart body from widget metadata.
	 *
	 * chart type and height are bounded, datasets are normalized before rendering, empty data produces
	 * an accessible empty chart state, and only supported chart types are emitted.
	 *
	 * @param array<string, mixed> $meta Chart metadata.
	 * @param string $tone Fallback tone for dataset styling.
	 * @param string $label Accessible chart label.
	 * @return string Chart HTML.
	 */
	private static function chartSvgHtml(array $meta, string $tone, string $label): string {
		$type=Resource::normalizeName((string)($meta['chart_type'] ?? $meta['type'] ?? 'line')) ?: 'line';
		$type=in_array($type, ['line', 'area', 'bar', 'donut', 'sparkline'], true) ? $type : 'line';
		$height=max(120, min(420, (int)($meta['height'] ?? ($type==='sparkline' ? 132 : 190))));
		[$labels, $datasets]=self::chartDatasets($meta, $tone);
		if($datasets===[]){
			return '<div class="dp-panel-chart dp-panel-chart-empty"><span>'.self::e(self::panelText('search.no_chart_data')).'</span></div>';
		}
		if($type==='donut'){
			return self::donutChartHtml($datasets[0], $height, $label);
		}
		return self::cartesianChartHtml($datasets, $labels, $type, $height, $label);
	}

	/**
	 * Normalizes chart labels and datasets from explicit datasets or simple data maps.
	 *
	 * dataset values are coerced to floats, empty datasets are skipped, fallback labels are generated
	 * when needed, and tones are normalized for CSS class safety.
	 *
	 * @param array<string, mixed> $meta Chart metadata.
	 * @param string $fallbackTone Tone used when datasets do not specify one.
	 * @return array{0: array<int, string>, 1: array<int, array{label: string, values: array<int, float>, tone: string}>} Labels and datasets.
	 */
	private static function chartDatasets(array $meta, string $fallbackTone): array {
		$labels=array_values(array_map('strval', is_array($meta['labels'] ?? null) ? $meta['labels'] : []));
		$datasets=[];
		if(isset($meta['datasets']) && is_array($meta['datasets'])){
			foreach($meta['datasets'] as $dataset){
				if(!is_array($dataset)){
					continue;
				}
				$values=self::chartValues($dataset['values'] ?? $dataset['data'] ?? []);
				if($values===[]){
					continue;
				}
				$datasets[]=[
					'label'=>(string)($dataset['label'] ?? ''),
					'values'=>$values,
					'tone'=>self::safeTone((string)($dataset['tone'] ?? $fallbackTone)),
				];
			}
		}
		if($datasets===[]){
			$data=$meta['data'] ?? [];
			if(is_array($data) && $data!==[]){
				[$dataLabels, $values]=self::chartLabelsAndValues($data);
				if($labels===[]){
					$labels=$dataLabels;
				}
				$datasets[]=[
					'label'=>(string)($meta['dataset_label'] ?? ''),
					'values'=>$values,
					'tone'=>$fallbackTone,
				];
			}
		}
		if($labels===[] && $datasets!==[]){
			$count=max(array_map(static fn(array $dataset): int => count($dataset['values']), $datasets));
			$labels=array_map(static fn(int $index): string => (string)($index+1), range(0, max(0, $count-1)));
		}
		return [$labels, $datasets];
	}

	/**
	 * Converts simple chart data entries into parallel labels and numeric values.
	 *
	 * array entries may expose label/name and value/total/count fields; scalar entries use their key
	 * or ordinal position as the label and coerce the value to float.
	 *
	 * @param array<string|int, mixed> $data Simple chart data.
	 * @return array{0: array<int, string>, 1: array<int, float>} Labels and values.
	 */
	private static function chartLabelsAndValues(array $data): array {
		$labels=[];
		$values=[];
		foreach($data as $key=>$entry){
			if(is_array($entry)){
				$labels[]=(string)($entry['label'] ?? $entry['name'] ?? $key);
				$values[]=(float)($entry['value'] ?? $entry['total'] ?? $entry['count'] ?? 0);
				continue;
			}
			$labels[]=is_string($key) ? $key : (string)(count($labels)+1);
			$values[]=(float)$entry;
		}
		return [$labels, $values];
	}

	/**
	 * Coerces a raw chart value list into floats.
	 *
	 * non-array input yields an empty dataset, and array entries may be scalars or arrays with a value
	 * key.
	 *
	 * @param mixed $values Raw chart values.
	 * @return array<int, float> Numeric values.
	 */
	private static function chartValues(mixed $values): array {
		if(!is_array($values)){
			return [];
		}
		$out=[];
		foreach($values as $value){
			$out[]=(float)(is_array($value) ? ($value['value'] ?? 0) : $value);
		}
		return $out;
	}

	/**
	 * Renders line, area, bar, or sparkline-style cartesian SVG charts.
	 *
	 * chart geometry is computed from bounded dimensions and normalized numeric datasets, labels are
	 * escaped, and generated SVG remains self-contained without client-side chart libraries.
	 *
	 * @param array<int, array{label?: string, values: array<int, float>, tone?: string}> $datasets Normalized datasets.
	 * @param array<int, string> $labels Axis labels.
	 * @param string $type Chart type token.
	 * @param int $height SVG height.
	 * @param string $label Accessible chart label.
	 * @return string Cartesian chart HTML.
	 */
	private static function cartesianChartHtml(array $datasets, array $labels, string $type, int $height, string $label): string {
		$width=720;
		$paddingLeft=42;
		$paddingRight=18;
		$paddingTop=16;
		$paddingBottom=34;
		$plotWidth=$width-$paddingLeft-$paddingRight;
		$plotHeight=$height-$paddingTop-$paddingBottom;
		$all=[];
		foreach($datasets as $dataset){
			$all=array_merge($all, $dataset['values']);
		}
		$min=min(0.0, ...$all);
		$max=max(1.0, ...$all);
		if($max===$min){
			$max=$min+1;
		}
		$grid='';
		for($i=0;$i<=3;$i++){
			$y=$paddingTop+($plotHeight/3*$i);
			$grid.='<line x1="'.$paddingLeft.'" y1="'.round($y, 2).'" x2="'.($width-$paddingRight).'" y2="'.round($y, 2).'" class="dp-panel-chart-grid"/>';
		}
		$series='';
		foreach($datasets as $index=>$dataset){
			$values=$dataset['values'];
			$count=count($values);
			if($count===0){
				continue;
			}
			$tone=self::safeTone((string)($dataset['tone'] ?? 'primary'));
			if($type==='bar'){
				$slot=$plotWidth/max(1, $count);
				$barWidth=max(6, min(34, ($slot*.68)/max(1, count($datasets))));
				foreach($values as $item=>$value){
					$x=$paddingLeft+($slot*$item)+(($slot-($barWidth*count($datasets)))/2)+($barWidth*$index);
					$y=$paddingTop+($max-$value)/($max-$min)*$plotHeight;
					$barHeight=max(1, ($paddingTop+$plotHeight)-$y);
					$series.='<rect class="dp-panel-chart-fill dp-panel-chart-'.$tone.'" x="'.round($x, 2).'" y="'.round($y, 2).'" width="'.round($barWidth, 2).'" height="'.round($barHeight, 2).'"><title>'.self::e(($labels[$item] ?? (string)($item+1)).': '.self::stringValue($value)).'</title></rect>';
				}
				continue;
			}
			$points=[];
			foreach($values as $item=>$value){
				$x=$paddingLeft+($count===1 ? $plotWidth/2 : ($plotWidth/($count-1))*$item);
				$y=$paddingTop+($max-$value)/($max-$min)*$plotHeight;
				$points[]=round($x, 2).','.round($y, 2);
			}
			if($type==='area'){
				$baseY=$paddingTop+$plotHeight;
				$path='M '.$points[0].' L '.implode(' L ', array_slice($points, 1)).' L '.explode(',', end($points))[0].','.$baseY.' L '.explode(',', $points[0])[0].','.$baseY.' Z';
				$series.='<path class="dp-panel-chart-area dp-panel-chart-'.$tone.'" d="'.$path.'"/>';
			}
			$series.='<polyline class="dp-panel-chart-line dp-panel-chart-'.$tone.'" points="'.implode(' ', $points).'"/>';
		}
		$axisLabels='';
		$labelCount=count($labels);
		if($labelCount>0){
			$indexes=array_unique([0, (int)floor(($labelCount-1)/2), $labelCount-1]);
			foreach($indexes as $index){
				$x=$paddingLeft+($labelCount===1 ? $plotWidth/2 : ($plotWidth/($labelCount-1))*$index);
				$axisLabels.='<text x="'.round($x, 2).'" y="'.($height-9).'" text-anchor="middle">'.self::e((string)($labels[$index] ?? '')).'</text>';
			}
		}
		$legend=self::chartLegendHtml($datasets);
		return '<div class="dp-panel-chart dp-panel-chart-'.$type.'" style="--dp-chart-height:'.$height.'px"><svg viewBox="0 0 '.$width.' '.$height.'" role="img" aria-label="'.self::e($label).' chart">'.$grid.$series.'<g class="dp-panel-chart-axis">'.$axisLabels.'</g></svg>'.$legend.'</div>';
	}

	/**
	 * Renders a donut chart from one normalized dataset.
	 *
	 * absolute values determine segment length, zero totals render the shared empty chart state, and
	 * segment titles expose escaped scalar values for accessibility.
	 *
	 * @param array{values: array<int, float>} $dataset Normalized dataset.
	 * @param int $height Chart height.
	 * @param string $label Accessible chart label.
	 * @return string Donut chart HTML.
	 */
	private static function donutChartHtml(array $dataset, int $height, string $label): string {
		$values=$dataset['values'];
		$total=array_sum(array_map('abs', $values));
		if($total<=0){
			return '<div class="dp-panel-chart dp-panel-chart-empty"><span>'.self::e(self::panelText('search.no_chart_data')).'</span></div>';
		}
		$circumference=100;
		$offset=25;
		$rings='';
		$tones=['primary', 'success', 'warning', 'danger', 'info', 'neutral'];
		foreach(array_values($values) as $index=>$value){
			$length=abs($value)/$total*$circumference;
			$tone=$tones[$index%count($tones)];
			$rings.='<circle class="dp-panel-chart-ring dp-panel-chart-'.$tone.'" cx="50" cy="50" r="15.915" pathLength="100" stroke-dasharray="'.round($length, 3).' '.round($circumference-$length, 3).'" stroke-dashoffset="'.round($offset, 3).'"><title>'.self::e(self::stringValue($value)).'</title></circle>';
			$offset-=$length;
		}
		return '<div class="dp-panel-chart dp-panel-chart-donut" style="--dp-chart-height:'.$height.'px"><svg viewBox="0 0 100 100" role="img" aria-label="'.self::e($label).' chart"><circle class="dp-panel-chart-ring-bg" cx="50" cy="50" r="15.915" pathLength="100"/>'.$rings.'<text x="50" y="50" text-anchor="middle">'.self::e(self::stringValue($total)).'</text></svg></div>';
	}

	/**
	 * Renders a legend for multi-dataset charts.
	 *
	 * legends are omitted for single datasets and empty labels, while tone classes are normalized and
	 * labels escaped.
	 *
	 * @param array<int, array<string, mixed>> $datasets Normalized datasets.
	 * @return string Legend HTML or empty string.
	 */
	private static function chartLegendHtml(array $datasets): string {
		if(count($datasets)<2){
			return '';
		}
		$html='';
		foreach($datasets as $dataset){
			$tone=self::safeTone((string)($dataset['tone'] ?? 'primary'));
			$label=trim((string)($dataset['label'] ?? ''));
			if($label===''){
				continue;
			}
			$html.='<span class="dp-panel-chart-legend-item dp-panel-chart-legend-'.$tone.'">'.self::e($label).'</span>';
		}
		return $html!=='' ? '<div class="dp-panel-chart-legend">'.$html.'</div>' : '';
	}

	/**
	 * Renders the dashboard global search form and bounded result list.
	 *
	 * result URLs are allow-listed, resource/title/subtitle text is escaped, eligible record links gain
	 * modal metadata, and empty result sets render a clearable empty state.
	 *
	 * @param string $query Current search query.
	 * @param array<int, mixed> $results Search result entries.
	 * @return string Global search HTML.
	 */
	private static function globalSearchHtml(string $query, array $results): string {
		$searchParameter=PanelConfig::globalSearchParameter();
		$form='<form class="dp-panel-global-search" method="get" action="'.self::e(PanelConfig::url()).'">'
			.'<input type="search" name="'.self::e($searchParameter).'" value="'.self::e($query).'" placeholder="'.self::e(self::panelText('search.workspace_placeholder')).'" aria-label="'.self::e(self::panelText('search.records_aria')).'" data-dp-panel-search-input data-dp-panel-global-search-input>'
			.'<button class="dp-panel-button" type="submit">'.self::e(self::panelText('common.search')).'</button>'
			.($query!=='' ? '<a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::url()).'">'.self::e(self::panelText('common.clear')).'</a>' : '')
			.'</form>';
		if($query===''){
			return $form;
		}
		$items='';
		foreach($results as $result){
			if(!is_array($result)){
				continue;
			}
			$url=self::safeWidgetUrl((string)($result['url'] ?? ''));
			$titleRaw=trim((string)($result['title'] ?? ''));
			$title=self::e($titleRaw);
			$subtitle=trim((string)($result['subtitle'] ?? ''));
			$resource=self::e((string)($result['resource_label'] ?? $result['resource'] ?? self::panelText('data.record')));
			$body='<span>'.$resource.'</span><strong>'.$title.'</strong>'.($subtitle!=='' ? '<small>'.self::e($subtitle).'</small>' : '');
			$modal='';
			if($url!=='' && trim((string)($result['record_key'] ?? ''))!==''){
				$modal=self::resourceModalAttributes('global_search_view', self::panelText('search.view_title', ['record'=>$titleRaw!=='' ? $titleRaw : self::panelText('common.record')]), self::panelText('search.view_description'), 'lg', 'dialog', true, self::panelText('record.open'), self::panelText('common.close'), 'info');
			}
			$items.=$url!==''
				? '<a class="dp-panel-search-result" href="'.self::e($url).'"'.$modal.'>'.$body.'</a>'
				: '<article class="dp-panel-search-result">'.$body.'</article>';
		}
		if($items===''){
			$items='<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('search.no_matches_title')).'</strong><span>'.self::e(self::panelText('search.no_matches_body')).'</span><a class="dp-panel-button dp-panel-button-secondary" href="'.self::e(PanelConfig::url()).'">'.self::e(self::panelText('search.clear')).'</a></div>';
		}
		return $form.'<section class="dp-panel-search-results"><header><h2>'.self::e(self::panelText('search.results')).'</h2><span>'.count($results).' '.self::e(self::panelText('search.result')).(count($results)===1 ? '' : 's').'</span></header>'.$items.'</section>';
	}

	/**
	 * Renders grouped navigation cards for dashboard-style navigation summaries.
	 *
	 * accepts either pre-grouped navigation state or raw entries, skips unsafe URLs, preserves active
	 * markers, and escapes labels, descriptions, badges, icons, and hrefs.
	 *
	 * @param array<int, mixed> $navigationOrGroups Navigation groups or entries.
	 * @return string Navigation group card HTML.
	 */
	private static function navigationGroupsHtml(array $navigationOrGroups): string {
		if($navigationOrGroups===[]){
			return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('panel.ready_title')).'</strong><span>'.self::e(self::panelText('panel.ready_body')).'</span></div>';
		}
		$first=$navigationOrGroups[array_key_first($navigationOrGroups)] ?? null;
		$groups=is_array($first) && array_key_exists('entries', $first)
			? array_values(array_filter($navigationOrGroups, static fn(mixed $group): bool => is_array($group)))
			: PanelNavigationState::make($navigationOrGroups)->groups();
		$html='';
		foreach($groups as $group){
			$items='';
			foreach(is_array($group['entries'] ?? null) ? $group['entries'] : [] as $entry){
				$description=trim((string)($entry['description'] ?? ''));
			$label=trim((string)($entry['label'] ?? '')) ?: self::panelText('common.untitled');
				$iconLabel=self::compactNavIcon((string)($entry['icon'] ?? ''), $label);
				$badge=$entry['badge'] ?? null;
				$badgeHtml=$badge!==null && $badge!=='' ? '<b class="dp-panel-nav-badge dp-panel-nav-badge-'.self::safeTone((string)($entry['badge_tone'] ?? 'neutral')).'">'.self::e(self::stringValue($badge)).'</b>' : '';
				$url=self::safeWidgetUrl((string)($entry['url'] ?? ''));
				$newTab=($entry['new_tab'] ?? false)===true;
				$target=$newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
				$active=!empty($entry['active']);
				$body='<i class="dp-panel-nav-icon" aria-hidden="true">'.self::e($iconLabel).'</i>'
					.'<span><strong>'.self::e($label).'</strong>'.$badgeHtml.'</span>'
					.'<small>'.self::e($description!=='' ? $description : self::panelText('search.open_workspace')).'</small>';
				$items.=$url!==''
					? '<a class="dp-panel-nav-card'.($active ? ' active' : '').'" href="'.self::e($url).'"'.$target.($active ? ' aria-current="page"' : '').'>'.$body.'</a>'
					: '<article class="dp-panel-nav-card'.($active ? ' active' : '').'">'.$body.'</article>';
			}
			$count=count(is_array($group['entries'] ?? null) ? $group['entries'] : []);
			$html.='<section class="dp-panel-nav-group">'
				.'<header><h2>'.self::e((string)$group['label']).'</h2><span>'.$count.' item'.($count===1 ? '' : 's').'</span></header>'
				.'<div class="dp-panel-grid">'.$items.'</div>'
				.'</section>';
		}
		return $html;
	}

	/**
	 * Builds a compact two-character icon fallback from an icon token or label.
	 *
	 * non-alphanumeric separators define word initials; otherwise the first two safe characters are
	 * used, with an asterisk fallback for empty input.
	 *
	 * @param string $icon Preferred icon token.
	 * @param string $label Fallback label.
	 * @return string Compact icon text.
	 */
	private static function compactNavIcon(string $icon, string $label): string {
		$source=trim($icon)!=='' ? trim($icon) : trim($label);
		$parts=array_values(array_filter(preg_split('/[^a-z0-9]+/i', $source) ?: [], static fn(string $part): bool => $part!==''));
		if(count($parts)>=2){
			return strtoupper(substr($parts[0], 0, 1).substr($parts[1], 0, 1));
		}
		$compact=strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $source) ?? '', 0, 2));
		return $compact!=='' ? $compact : '*';
	}

	/**
	 * Renders the composite label body for action buttons and links.
	 *
	 * action metadata may provide label, icon, badge, tone, description, and icon-only state; every
	 * visible value is escaped and badge tone is allow-listed before a class is emitted.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Action label HTML.
	 */
	private static function actionLabelHtml(array $meta): string {
		$label=trim((string)($meta['label'] ?? $meta['name'] ?? self::panelText('action.default_label'))) ?: self::panelText('action.default_label');
		$icon=trim((string)($meta['icon'] ?? ''));
		$iconHtml=$icon!=='' ? '<i class="dp-panel-action-icon" aria-hidden="true">'.self::e(self::compactNavIcon($icon, $label)).'</i>' : '';
		$badge=trim((string)($meta['badge'] ?? ''));
		$badgeTone=self::safeTone((string)($meta['badge_tone'] ?? 'neutral'));
		$badgeHtml=$badge!=='' ? '<small class="dp-panel-action-badge dp-panel-action-badge-'.$badgeTone.'">'.self::e($badge).'</small>' : '';
		$labelClass=($meta['icon_only'] ?? false)===true ? 'dp-panel-action-label dp-panel-sr-only' : 'dp-panel-action-label';
		$description=trim((string)($meta['description'] ?? ''));
		$descriptionHtml=$description!=='' ? '<small class="dp-panel-action-description">'.self::e($description).'</small>' : '';
		return $iconHtml.'<span class="dp-panel-action-copy"><span class="'.$labelClass.'">'.self::e($label).'</span>'.$descriptionHtml.'</span>'.$badgeHtml;
	}

	/**
	 * Renders a simple action label with an optional compact icon.
	 *
	 * empty labels fall back to the localized default action label, and icon text is derived through
	 * compactNavIcon() before escaping.
	 *
	 * @param string $label Action label.
	 * @param string $icon Optional icon token.
	 * @return string Action text HTML.
	 */
	private static function actionTextHtml(string $label, string $icon=''): string {
		$label=trim($label) ?: self::panelText('action.default_label');
		$iconHtml=trim($icon)!=='' ? '<i class="dp-panel-action-icon" aria-hidden="true">'.self::e(self::compactNavIcon($icon, $label)).'</i>' : '';
		return $iconHtml.'<span class="dp-panel-action-label">'.self::e($label).'</span>';
	}

	/**
	 * Builds tooltip attributes for action controls.
	 *
	 * explicit tooltip text and the primary key-binding label are combined into title and data
	 * attributes, with empty tooltips omitted and all text escaped.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Tooltip attribute HTML.
	 */
	private static function actionTooltipAttributes(array $meta): string {
		$tooltip=trim((string)($meta['tooltip'] ?? ''));
		$binding=self::actionKeyBindingLabel($meta);
		if($binding!==''){
			$tooltip=trim($tooltip!=='' ? $tooltip.' ('.$binding.')' : $binding);
		}
		if($tooltip===''){
			return '';
		}
		return ' title="'.self::e($tooltip).'" data-dp-panel-action-tooltip="'.self::e($tooltip).'"';
	}

	/**
	 * Builds data and aria attributes for action keyboard shortcuts.
	 *
	 * scalar non-empty bindings are JSON encoded for the client runtime and converted to aria
	 * keyshortcuts syntax for assistive technology.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Key-binding attribute HTML.
	 */
	private static function actionKeyBindingAttributes(array $meta): string {
		$bindings=$meta['key_bindings'] ?? [];
		if(!is_array($bindings) || $bindings===[]){
			return '';
		}
		$bindings=array_values(array_filter(array_map(static fn(mixed $binding): string => is_scalar($binding) ? trim((string)$binding) : '', $bindings), static fn(string $binding): bool => $binding!==''));
		if($bindings===[]){
			return '';
		}
		$json=json_encode($bindings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$aria=implode(' ', array_map([self::class, 'ariaKeyBinding'], $bindings));
		return ' data-dp-panel-key-bindings="'.self::e($json!==false ? $json : '[]').'" aria-keyshortcuts="'.self::e($aria).'"';
	}

	/**
	 * Serializes caller-provided action attributes through an allow-list.
	 *
	 * reserved panel data attributes and class are excluded, boolean true attributes are emitted
	 * without values, false/null are skipped, and scalar values are escaped.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Extra attribute HTML.
	 */
	private static function actionExtraAttributes(array $meta): string {
		$attributes=$meta['extra_attributes'] ?? [];
		if(!is_array($attributes) || $attributes===[]){
			return '';
		}
		$html='';
		foreach($attributes as $name=>$value){
			if(!is_string($name) || !self::isSafeActionExtraAttribute($name)){
				continue;
			}
			if($value===false || $value===null){
				continue;
			}
			if($value===true){
				$html.=' '.$name;
				continue;
			}
			if(is_scalar($value) || $value instanceof \Stringable){
				$html.=' '.$name.'="'.self::e((string)$value).'"';
			}
		}
		return $html;
	}

	/**
	 * Extracts safe custom classes for action controls from extra_attributes.
	 *
	 * only simple alphanumeric, underscore, and dash class tokens survive; unsafe or empty class data is
	 * discarded instead of escaped into a class list.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Leading-space class suffix.
	 */
	private static function actionExtraClass(array $meta): string {
		$attributes=$meta['extra_attributes'] ?? [];
		$class=is_array($attributes) ? trim((string)($attributes['class'] ?? '')) : '';
		if($class===''){
			return '';
		}
		$classes=array_filter(preg_split('/\s+/', $class) ?: [], static fn(string $value): bool => preg_match('/^[a-zA-Z0-9_-]+$/', $value)===1);
		return $classes!==[] ? ' '.implode(' ', $classes) : '';
	}

	/**
	 * Checks whether a custom action attribute is allowed through the renderer boundary.
	 *
	 * renderer-reserved classes, data-dp-panel attributes, disabled state, and keyshortcut state remain
	 * controlled by the framework; safe data/aria attributes and a small set of generic attributes may pass through.
	 *
	 * @param string $name Attribute name.
	 * @return bool Whether actionExtraAttributes() may emit the attribute.
	 */
	private static function isSafeActionExtraAttribute(string $name): bool {
		$name=strtolower(trim($name));
		if($name==='class'){
			return false;
		}
		if(str_starts_with($name, 'data-dp-panel-')){
			return false;
		}
		if(preg_match('/^data-[a-z0-9_.:-]+$/', $name)===1){
			return true;
		}
		if(preg_match('/^aria-[a-z0-9_.:-]+$/', $name)===1){
			return !in_array($name, ['aria-disabled', 'aria-keyshortcuts'], true);
		}
		return in_array($name, ['id', 'role', 'tabindex', 'download', 'target', 'rel'], true);
	}

	/**
	 * Serializes custom table column attributes through a column-specific allow-list.
	 *
	 * reserved class and panel data attributes are excluded, boolean and scalar attributes are handled
	 * consistently, and emitted scalar values are escaped.
	 *
	 * @param array<string, mixed> $attributes Column attribute metadata.
	 * @return string Extra attribute HTML.
	 */
	private static function columnExtraAttributes(array $attributes): string {
		if($attributes===[]){
			return '';
		}
		$html='';
		foreach($attributes as $name=>$value){
			if(!is_string($name) || !self::isSafeColumnExtraAttribute($name)){
				continue;
			}
			if($value===false || $value===null){
				continue;
			}
			if($value===true){
				$html.=' '.$name;
				continue;
			}
			if(is_scalar($value) || $value instanceof \Stringable){
				$html.=' '.$name.'="'.self::e((string)$value).'"';
			}
		}
		return $html;
	}

	/**
	 * Extracts safe custom class tokens for a table column cell.
	 *
	 * class data is tokenized and allow-listed before being returned as a complete class attribute.
	 *
	 * @param array<string, mixed> $attributes Column attribute metadata.
	 * @return string Class attribute HTML or empty string.
	 */
	private static function columnExtraClass(array $attributes): string {
		$class=trim((string)($attributes['class'] ?? ''));
		if($class===''){
			return '';
		}
		$classes=array_filter(preg_split('/\s+/', $class) ?: [], static fn(string $value): bool => preg_match('/^[a-zA-Z0-9_-]+$/', $value)===1);
		return $classes!==[] ? ' class="'.self::e(implode(' ', $classes)).'"' : '';
	}

	/**
	 * Checks whether a custom table column attribute is safe to emit.
	 *
	 * framework-managed class, aria-sort, and panel data attributes stay reserved, while safe data,
	 * aria, and table-structure attributes are allowed.
	 *
	 * @param string $name Attribute name.
	 * @return bool Whether columnExtraAttributes() may emit the attribute.
	 */
	private static function isSafeColumnExtraAttribute(string $name): bool {
		$name=strtolower(trim($name));
		if($name==='class'){
			return false;
		}
		if(str_starts_with($name, 'data-dp-panel-')){
			return false;
		}
		if(preg_match('/^data-[a-z0-9_.:-]+$/', $name)===1){
			return true;
		}
		if(preg_match('/^aria-[a-z0-9_.:-]+$/', $name)===1){
			return !in_array($name, ['aria-sort'], true);
		}
		return in_array($name, ['id', 'role', 'tabindex', 'headers', 'scope'], true);
	}

	/**
	 * Serializes custom table row attributes through a row-specific allow-list.
	 *
	 * reserved panel attributes are excluded, false/null values are skipped, boolean true attributes are
	 * emitted as valueless attributes, and scalar values are escaped.
	 *
	 * @param array<string, mixed> $attributes Row attribute metadata.
	 * @return string Extra attribute HTML.
	 */
	private static function tableRowExtraAttributes(array $attributes): string {
		if($attributes===[]){
			return '';
		}
		$html='';
		foreach($attributes as $name=>$value){
			if(!is_string($name) || !self::isSafeTableRowExtraAttribute($name)){
				continue;
			}
			if($value===false || $value===null){
				continue;
			}
			if($value===true){
				$html.=' '.$name;
				continue;
			}
			if(is_scalar($value) || $value instanceof \Stringable){
				$html.=' '.$name.'="'.self::e((string)$value).'"';
			}
		}
		return $html;
	}

	/**
	 * Extracts safe custom class tokens for a table row.
	 *
	 * only simple class tokens are preserved and returned as a complete class attribute; unsafe class
	 * fragments are discarded.
	 *
	 * @param array<string, mixed> $attributes Row attribute metadata.
	 * @return string Class attribute HTML or empty string.
	 */
	private static function tableRowExtraClass(array $attributes): string {
		$class=trim((string)($attributes['class'] ?? ''));
		if($class===''){
			return '';
		}
		$classes=array_filter(preg_split('/\s+/', $class) ?: [], static fn(string $value): bool => preg_match('/^[a-zA-Z0-9_-]+$/', $value)===1);
		return $classes!==[] ? ' class="'.self::e(implode(' ', $classes)).'"' : '';
	}

	/**
	 * Checks whether a custom row attribute is safe to emit.
	 *
	 * class, aria-label, and renderer-owned panel data attributes remain controlled by the framework;
	 * safe data/aria attributes and generic id/role attributes may pass through.
	 *
	 * @param string $name Attribute name.
	 * @return bool Whether tableRowExtraAttributes() may emit the attribute.
	 */
	private static function isSafeTableRowExtraAttribute(string $name): bool {
		$name=strtolower(trim($name));
		if($name==='class'){
			return false;
		}
		if(str_starts_with($name, 'data-dp-panel-')){
			return false;
		}
		if(preg_match('/^data-[a-z0-9_.:-]+$/', $name)===1){
			return true;
		}
		if(preg_match('/^aria-[a-z0-9_.:-]+$/', $name)===1){
			return !in_array($name, ['aria-label'], true);
		}
		return in_array($name, ['id', 'role'], true);
	}

	/**
	 * Reads the display label for the first configured action key binding.
	 *
	 * only scalar bindings are rendered, and only the first binding is used for concise tooltip text.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @return string Human-readable key-binding label.
	 */
	private static function actionKeyBindingLabel(array $meta): string {
		$bindings=$meta['key_bindings'] ?? [];
		if(!is_array($bindings) || $bindings===[]){
			return '';
		}
		$binding=reset($bindings);
		return is_scalar($binding) ? self::displayKeyBinding((string)$binding) : '';
	}

	/**
	 * Converts internal key-binding syntax into a compact display label.
	 *
	 * known modifier aliases are mapped to user-facing labels and unknown keys are uppercased without
	 * validating against a browser keyboard layout.
	 *
	 * @param string $binding Internal binding such as mod+k.
	 * @return string Display binding label.
	 */
	private static function displayKeyBinding(string $binding): string {
		$parts=array_values(array_filter(explode('+', strtolower(trim($binding)))));
		$labels=[];
		foreach($parts as $part){
			$labels[]=match($part){
				'mod'=>'Ctrl/Cmd',
				'ctrl'=>'Ctrl',
				'meta'=>'Cmd',
				'alt'=>'Alt',
				'shift'=>'Shift',
				'escape'=>'Esc',
				'enter'=>'Enter',
				'space'=>'Space',
				default=>strtoupper($part),
			};
		}
		return implode('+', $labels);
	}

	/**
	 * Converts internal key-binding syntax into aria-keyshortcuts syntax.
	 *
	 * modifier aliases use ARIA names, single-character keys are uppercased, and longer keys are title
	 * cased for assistive-technology metadata.
	 *
	 * @param string $binding Internal binding such as mod+k.
	 * @return string ARIA key binding label.
	 */
	private static function ariaKeyBinding(string $binding): string {
		$parts=array_values(array_filter(explode('+', strtolower(trim($binding)))));
		$labels=[];
		foreach($parts as $part){
			$labels[]=match($part){
				'mod'=>'Control',
				'ctrl'=>'Control',
				'meta'=>'Meta',
				'alt'=>'Alt',
				'shift'=>'Shift',
				'escape'=>'Escape',
				'space'=>'Space',
				default=>strlen($part)===1 ? strtoupper($part) : ucfirst($part),
			};
		}
		return implode('+', $labels);
	}

	/**
	 * Builds data attributes that let the client runtime open an action modal.
	 *
	 * modal state is enabled by explicit metadata, confirmation requirements, fields, or generated
	 * content; stack behavior and tone are normalized; all labels, descriptions, widths, styles, and embedded content are
	 * escaped before becoming data attributes.
	 *
	 * @param array<string, mixed> $meta Action metadata.
	 * @param bool $hasFields Whether the action has form fields.
	 * @param mixed $modalContent Optional generated modal content.
	 * @return string Modal data attribute HTML.
	 */
	private static function actionModalAttributes(array $meta, bool $hasFields=false, mixed $modalContent=null): string {
		$content=self::modalContentHtml($modalContent);
		$enabled=(bool)($meta['modal'] ?? false) || ($meta['requires_confirmation'] ?? false)===true || $hasFields || $content!==null;
		$tone=array_key_exists('tone', $meta) ? self::safeTone((string)$meta['tone']) : '';
		$attrs=$enabled ? ' data-dp-panel-action-modal="1"' : '';
		$attrs.=' data-dp-panel-action-name="'.self::e((string)($meta['name'] ?? '')).'"';
		$attrs.=' data-dp-panel-action-heading="'.self::e((string)($meta['modal_heading'] ?? $meta['label'] ?? '')).'"';
		$attrs.=' data-dp-panel-action-description="'.self::e((string)($meta['modal_description'] ?? '')).'"';
		$attrs.=' data-dp-panel-action-width="'.self::e((string)($meta['modal_width'] ?? 'md')).'"';
		$attrs.=' data-dp-panel-action-style="'.self::e((string)($meta['meta']['modal_style'] ?? 'dialog')).'"';
		$attrs.=' data-dp-panel-action-submit-label="'.self::e((string)($meta['modal_submit_label'] ?? $meta['label'] ?? self::panelText('common.run'))).'"';
		$attrs.=' data-dp-panel-action-cancel-label="'.self::e((string)($meta['modal_cancel_label'] ?? self::panelText('common.cancel'))).'"';
		$attrs.=' data-dp-panel-action-has-fields="'.($hasFields ? '1' : '0').'"';
		$attrs.=' data-dp-panel-action-has-content="'.($content!==null ? '1' : '0').'"';
		$attrs.=' data-dp-panel-action-has-handler="'.(($meta['has_handler'] ?? false)===true ? '1' : '0').'"';
		if($tone!==''){
			$attrs.=' data-dp-panel-action-tone="'.self::e($tone).'"';
		}
		$modalStack=Resource::normalizeName((string)($meta['modal_stack'] ?? $meta['meta']['modal_stack'] ?? ''));
		if(!in_array($modalStack, ['push', 'replace', 'clear'], true)){
			$modalBack=($meta['modal_back'] ?? $meta['meta']['modal_back'] ?? false)===true || (string)($meta['modal_back'] ?? $meta['meta']['modal_back'] ?? '')==='1';
			$modalStack=$modalBack ? 'push' : 'replace';
		}
		$attrs.=' data-dp-panel-modal-stack="'.self::e($modalStack).'"';
		if($modalStack==='push'){
			$attrs.=' data-dp-panel-modal-back="1"';
		}
		if($content!==null){
			$attrs.=' data-dp-panel-action-content="'.self::e($content).'"';
		}
		return $attrs;
	}

	/**
	 * Builds modal attributes for resource-oriented actions using the shared action modal contract.
	 *
	 * default submit/cancel labels are localized, field-backed modals push onto the modal stack, and
	 * optional tone values are normalized before delegation.
	 *
	 * @param string $name Modal action name.
	 * @param string $heading Modal heading.
	 * @param string $description Modal description.
	 * @param string $width Modal width token.
	 * @param string $style Modal style token.
	 * @param bool $hasFields Whether the modal contains fields.
	 * @param string $submitLabel Optional submit label.
	 * @param string $cancelLabel Optional cancel label.
	 * @param string $tone Optional modal tone.
	 * @return string Modal data attribute HTML.
	 */
	private static function resourceModalAttributes(string $name, string $heading, string $description='', string $width='lg', string $style='dialog', bool $hasFields=false, string $submitLabel='', string $cancelLabel='', string $tone=''): string {
		$submitLabel=$submitLabel!=='' ? $submitLabel : self::panelText('common.save');
		$cancelLabel=$cancelLabel!=='' ? $cancelLabel : self::panelText('common.cancel');
		$meta=[
			'name'=>$name,
			'label'=>$submitLabel,
			'modal'=>true,
			'modal_heading'=>$heading,
			'modal_description'=>$description,
			'modal_width'=>$width,
			'modal_submit_label'=>$submitLabel,
			'modal_cancel_label'=>$cancelLabel,
			'modal_stack'=>$hasFields ? 'push' : 'replace',
			'meta'=>[
				'modal_style'=>$style,
			],
		];
		if(trim($tone)!==''){
			$meta['tone']=self::safeTone($tone);
		}
		return self::actionModalAttributes($meta, $hasFields);
	}

	/**
	 * Builds modal attributes for read-only content dialogs.
	 *
	 * content dialogs use the shared action modal data contract with generated content and localized
	 * cancel text, without marking the modal as field-backed.
	 *
	 * @param string $name Modal action name.
	 * @param string $heading Modal heading and submit label.
	 * @param string $description Modal description.
	 * @param string $content Modal HTML or scalar content.
	 * @param string $width Modal width token.
	 * @param string $style Modal style token.
	 * @return string Modal data attribute HTML.
	 */
	private static function contentModalAttributes(string $name, string $heading, string $description, string $content, string $width='md', string $style='dialog'): string {
		return self::actionModalAttributes([
			'name'=>$name,
			'label'=>$heading,
			'modal'=>true,
			'modal_heading'=>$heading,
			'modal_description'=>$description,
			'modal_width'=>$width,
			'modal_submit_label'=>$heading,
			'modal_cancel_label'=>self::panelText('common.cancel'),
			'meta'=>[
				'modal_style'=>$style,
			],
		], false, $content);
	}

	/**
	 * Normalizes modal content into safe HTML payloads for data attributes.
	 *
	 * null content disables generated content, array content becomes escaped label/value fields, and
	 * scalar or stringable content flows through pageContentValue() before trimming.
	 *
	 * @param mixed $content Modal content payload.
	 * @return string|null Modal content HTML or null when no content should be attached.
	 */
	private static function modalContentHtml(mixed $content): ?string {
		if($content===null){
			return null;
		}
		if(is_array($content)){
			$items='';
			foreach($content as $key=>$value){
				$label=is_string($key) ? $key : (string)($value['label'] ?? '');
				$detail=is_array($value) ? ($value['value'] ?? $value['detail'] ?? '') : $value;
				if(trim((string)$label)==='' && trim((string)$detail)===''){
					continue;
				}
				$items.='<div class="dp-panel-show-field"><span>'.self::e((string)$label).'</span><strong>'.self::e(self::stringValue($detail)).'</strong></div>';
			}
			return $items!=='' ? '<div class="dp-panel-modal-generated"><div class="dp-panel-grid">'.$items.'</div></div>' : null;
		}
		$html=trim(self::pageContentValue($content));
		return $html!=='' ? $html : null;
	}

	/**
	 * Wraps custom page content in the configured custom-page layout shell.
	 *
	 * empty content renders a localized empty state, while non-empty content is inserted as trusted
	 * page content inside a layout wrapper with escaped layout data attributes.
	 *
	 * @param string $content Custom page body HTML.
	 * @return string Custom page shell HTML.
	 */
	private static function customPageShell(string $content): string {
		if(trim($content)===''){
			return '<div class="dp-panel-empty-state"><strong>'.self::e(self::panelText('page.ready_title')).'</strong><span>'.self::e(self::panelText('page.ready_body')).'</span></div>';
		}
		$layout=PanelConfig::customPageLayout();
		return '<section class="dp-panel-custom-page dp-panel-custom-page-layout-'.$layout.'" data-dp-panel-custom-page-layout="'.self::e($layout).'">'.$content.'</section>';
	}

	/**
	 * Converts arbitrary page content values into renderable HTML text.
	 *
	 * stringable and scalar values render directly, null renders as an empty string, and structured
	 * values are stringified and escaped inside a preformatted block.
	 *
	 * @param mixed $content Page content payload.
	 * @return string Renderable content string.
	 */
	private static function pageContentValue(mixed $content): string {
		if($content instanceof \Stringable){
			return (string)$content;
		}
		if(is_scalar($content) || $content===null){
			return (string)$content;
		}
		return '<pre>'.self::e(self::stringValue($content)).'</pre>';
	}

	/**
	 * Renders a table cell for a Column definition and record.
	 *
	 * column value, formatting, description, tooltip, copy state, icon, tone, and link metadata are
	 * resolved through the Column API; custom component renderers may override the primary content, and fallback renderers
	 * handle badge, URL, email, and text cells.
	 *
	 * @param Column $column Column definition.
	 * @param mixed $record Source record.
	 * @return string Table cell inner HTML.
	 */
	private static function cellHtml(Column $column, mixed $record): string {
		$meta=$column->toArray();
		$type=(string)($meta['type'] ?? 'text');
		$value=$column->resolveValue($record);
		$formatted=self::stringValue($column->formatValue($value, $record));
		$description=$column->resolveDescription($record, $value, $formatted);
		$tooltip=$column->resolveTooltip($record, $value, $formatted);
		$copyValue=($meta['copyable'] ?? false)===true ? $column->resolveCopyValue($record, $value, $formatted) : '';
		$copyMessage=trim((string)($meta['meta']['copy_message'] ?? self::panelText('common.copied')));
		$icon=$column->resolveIcon($record, $value, $formatted);
		$tone=self::safeTone($column->resolveColor($record, $value, $formatted));
		$linkUrl=$column->resolveLinkUrl($record, $value, $formatted);
		$linkNewTab=$column->resolveLinkNewTab($record, $value, $formatted);
		$custom=PanelComponentRegistry::renderColumnCell($type, $column, $record, $value, $formatted, $meta, ['renderer'=>__CLASS__]);
		if($custom!==null){
			$custom=self::linkedCellPrimaryHtml($custom, $linkUrl, $linkNewTab);
			return self::cellStackHtml($custom, $description, $copyValue, $copyMessage, $icon, $tone, $tooltip);
		}
		$primary=match($type){
			'badge'=>self::badgeCellHtml($formatted, $value, $meta),
			'url'=>self::linkCellHtml($formatted, self::hrefValue($value), $meta, $record),
			'email'=>self::linkCellHtml($formatted, self::emailHref($value), $meta, $record),
			default=>self::textCellHtml($formatted, $meta),
		};
		if(!in_array($type, ['url', 'email'], true)){
			$primary=self::linkedCellPrimaryHtml($primary, $linkUrl, $linkNewTab);
		}
		return self::cellStackHtml($primary, $description, $copyValue, $copyMessage, $icon, $tone, $tooltip);
	}

	/**
	 * Wraps primary cell content with optional description, copy button, icon, tone, and tooltip chrome.
	 *
	 * neutral cells without extra metadata return the primary HTML directly, while enhanced cells use
	 * normalized tone classes and escaped tooltip/copy metadata.
	 *
	 * @param string $primary Primary cell HTML.
	 * @param string $description Optional secondary description.
	 * @param string $copyValue Optional copy payload.
	 * @param string $copyMessage Optional copy confirmation text.
	 * @param string $icon Optional icon token.
	 * @param string $tone Tone token.
	 * @param string $tooltip Optional tooltip text.
	 * @return string Cell HTML.
	 */
	private static function cellStackHtml(string $primary, string $description='', string $copyValue='', string $copyMessage='', string $icon='', string $tone='neutral', string $tooltip=''): string {
		$description=trim($description);
		$copyValue=trim($copyValue);
		$icon=trim($icon);
		$tone=self::safeTone($tone);
		$tooltip=trim($tooltip);
		$tooltipAttr=$tooltip!=='' ? ' title="'.self::e($tooltip).'" aria-label="'.self::e($tooltip).'"' : '';
		if($description==='' && $copyValue==='' && $icon==='' && $tone==='neutral'){
			if($tooltip!==''){
				return '<span class="dp-panel-cell-tooltip"'.$tooltipAttr.'>'.$primary.'</span>';
			}
			return $primary;
		}
		$copy=$copyValue!=='' ? '<button type="button" class="dp-panel-entry-copy dp-panel-cell-copy" data-dp-panel-copy-entry="'.self::e($copyValue).'" data-dp-panel-copy-message="'.self::e($copyMessage!=='' ? $copyMessage : self::panelText('common.copied')).'" title="'.self::e(self::panelText('copy.value', [], 'Copy value')).'">'.self::e(self::panelText('common.copy')).'</button>' : '';
		$iconHtml=$icon!=='' ? '<i class="dp-panel-cell-icon" aria-hidden="true">'.self::e(self::compactNavIcon($icon, strip_tags($primary))).'</i>' : '';
		return '<span class="dp-panel-cell-stack dp-panel-cell-stack-'.$tone.($copy!=='' ? ' dp-panel-cell-stack-copyable' : '').($iconHtml!=='' ? ' dp-panel-cell-stack-iconic' : '').($tooltip!=='' ? ' dp-panel-cell-stack-tooltipped' : '').'"'.$tooltipAttr.'>'.$iconHtml.'<span class="dp-panel-cell-primary">'.$primary.'</span>'.($description!=='' ? '<small>'.self::e($description).'</small>' : '').$copy.'</span>';
	}

	/**
	 * Builds the responsive data-label attribute for table cells.
	 *
	 * column labels prefer explicit metadata and fall back to the caller label; empty labels emit no
	 * attribute.
	 *
	 * @param array<string, mixed> $meta Column metadata.
	 * @param string $fallback Fallback label.
	 * @return string data-label attribute HTML or empty string.
	 */
	private static function tableDataLabelAttr(array $meta, string $fallback=''): string {
		$label=trim((string)($meta['label'] ?? $fallback));
		return $label!=='' ? ' data-label="'.self::e($label).'"' : '';
	}

	/**
	 * Renders plain text cell content with optional truncation title.
	 *
	 * display text is truncated according to column metadata and both display and full title values are
	 * escaped.
	 *
	 * @param string $value Formatted text value.
	 * @param array<string, mixed> $meta Column metadata.
	 * @return string Text cell HTML.
	 */
	private static function textCellHtml(string $value, array $meta): string {
		$display=self::truncateCellValue($value, $meta);
		$title=$display!==$value ? ' title="'.self::e($value).'"' : '';
		return '<span'.$title.'>'.self::e($display).'</span>';
	}

	/**
	 * Renders badge cell content with metadata-driven tone mapping.
	 *
	 * badge tone is resolved from raw value and column metadata, display text may be truncated, and all
	 * text is escaped before output.
	 *
	 * @param string $formatted Formatted display value.
	 * @param mixed $raw Raw column value.
	 * @param array<string, mixed> $meta Column metadata.
	 * @return string Badge cell HTML.
	 */
	private static function badgeCellHtml(string $formatted, mixed $raw, array $meta): string {
		$tone=self::badgeTone($raw, $meta);
		$display=self::truncateCellValue($formatted, $meta);
		$title=$display!==$formatted ? ' title="'.self::e($formatted).'"' : '';
		return '<span class="dp-panel-badge dp-panel-badge-'.$tone.'"'.$title.'>'.self::e($display).'</span>';
	}

	/**
	 * Renders URL or email cell content as a link when an href is available.
	 *
	 * empty hrefs fall back to text rendering, optional label_column values are resolved from the
	 * record, display text is truncated, and href/title/label values are escaped.
	 *
	 * @param string $formatted Formatted display value.
	 * @param string $href Link href.
	 * @param array<string, mixed> $meta Column metadata.
	 * @param mixed $record Source record.
	 * @return string Link or text cell HTML.
	 */
	private static function linkCellHtml(string $formatted, string $href, array $meta, mixed $record): string {
		if($href===''){
			return self::textCellHtml($formatted, $meta);
		}
		$labelColumn=(string)($meta['meta']['label_column'] ?? '');
		if($labelColumn!==''){
			$label=self::stringValue(self::recordValue($record, $labelColumn, $formatted));
		}
		else {
			$label=$formatted;
		}
		$display=self::truncateCellValue($label, $meta);
		$title=$display!==$label ? ' title="'.self::e($label).'"' : '';
		return '<a class="dp-panel-cell-link" href="'.self::e($href).'"'.$title.'>'.self::e($display).'</a>';
	}

	/**
	 * Wraps primary cell HTML in an action link when the URL is allow-listed.
	 *
	 * only root-relative and http(s) URLs pass through safeWidgetUrl(); new-tab links receive
	 * noopener/noreferrer protection.
	 *
	 * @param string $primary Primary cell HTML.
	 * @param string $href Candidate link URL.
	 * @param bool $newTab Whether to open in a new tab.
	 * @return string Linked or original primary HTML.
	 */
	private static function linkedCellPrimaryHtml(string $primary, string $href, bool $newTab=false): string {
		$href=self::safeWidgetUrl($href);
		if($href===''){
			return $primary;
		}
		$target=$newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
		return '<a class="dp-panel-cell-link dp-panel-cell-action-link" href="'.self::e($href).'"'.$target.'>'.$primary.'</a>';
	}

	/**
	 * Converts a raw URL cell value into an href only when it is absolute http(s).
	 *
	 * empty, relative, script, mail, and other schemes are rejected for URL column href generation.
	 *
	 * @param mixed $value Raw URL value.
	 * @return string Safe href or empty string.
	 */
	private static function hrefValue(mixed $value): string {
		$href=trim(self::stringValue($value));
		if($href===''){
			return '';
		}
		if(preg_match('/^https?:\/\//i', $href)===1){
			return $href;
		}
		return '';
	}

	/**
	 * Converts a raw email value into a mailto href after validation.
	 *
	 * invalid or empty email values emit no href so the cell falls back to text rendering.
	 *
	 * @param mixed $value Raw email value.
	 * @return string mailto href or empty string.
	 */
	private static function emailHref(mixed $value): string {
		$email=trim(self::stringValue($value));
		if($email==='' || filter_var($email, FILTER_VALIDATE_EMAIL)===false){
			return '';
		}
		return 'mailto:'.$email;
	}

	/**
	 * Applies column-level truncation to a display value.
	 *
	 * truncate values below one disable truncation, limits of three or fewer return a hard substring,
	 * and longer limits reserve three characters for an ellipsis.
	 *
	 * @param string $value Display value.
	 * @param array<string, mixed> $meta Column metadata.
	 * @return string Possibly truncated value.
	 */
	private static function truncateCellValue(string $value, array $meta): string {
		$limit=(int)($meta['meta']['truncate'] ?? 0);
		if($limit<1 || strlen($value)<=$limit){
			return $value;
		}
		if($limit<=3){
			return substr($value, 0, $limit);
		}
		return rtrim(substr($value, 0, $limit-3)).'...';
	}

	/**
	 * Resolves a badge tone from raw value and column tone metadata.
	 *
	 * explicit value-to-tone maps override the default tone, and the final class token is restricted to
	 * the panel tone allow-list.
	 *
	 * @param mixed $value Raw badge value.
	 * @param array<string, mixed> $meta Column metadata.
	 * @return string Safe tone token.
	 */
	private static function badgeTone(mixed $value, array $meta): string {
		$allowed=['neutral', 'primary', 'success', 'warning', 'danger', 'info'];
		$raw=trim(self::stringValue($value));
		$tone=(string)($meta['meta']['tone'] ?? '');
		$tones=$meta['meta']['tones'] ?? [];
		if(is_array($tones) && array_key_exists($raw, $tones)){
			$tone=(string)$tones[$raw];
		}
		$tone=strtolower(trim($tone));
		return in_array($tone, $allowed, true) ? $tone : 'neutral';
	}

	/**
	 * Normalizes arbitrary tone text to the panel tone allow-list.
	 *
	 * only neutral, primary, success, warning, danger, and info are allowed as class suffixes; all
	 * other values collapse to neutral.
	 *
	 * @param string $tone Candidate tone.
	 * @return string Safe tone token.
	 */
	private static function safeTone(string $tone): string {
		$tone=strtolower(trim($tone));
		return in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
	}

	/**
	 * Allow-lists URLs used by widgets, search results, navigation cards, and cell action links.
	 *
	 * only root-relative and absolute http(s) URLs are emitted; empty values and other schemes are
	 * rejected so caller-provided links cannot introduce script or protocol surprises.
	 *
	 * @param string $url Candidate URL.
	 * @return string Safe URL or empty string.
	 */
	private static function safeWidgetUrl(string $url): string {
		$url=trim($url);
		if($url===''){
			return '';
		}
		if(str_starts_with($url, '/') || preg_match('/^https?:\/\//i', $url)===1){
			return $url;
		}
		return '';
	}
}
