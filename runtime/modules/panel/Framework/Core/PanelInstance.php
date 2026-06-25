<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Stateful Panel surface assembled during application boot.
 *
 * A surface owns configuration, plugins, resources, pages, localization, routes, renderer options, and helper factories for one mounted Panel experience.
 */
final class PanelInstance {

	private string $name;
	private PanelManager $manager;
	/** @var array<string, mixed> */
	private array $config=[];
	/** @var array<string, PanelPlugin> */
	private array $plugins=[];
	/** @var array<string, array<string, mixed>> */
	private array $pluginConfig=[];
	/** @var array<string, array<string, mixed>> */
	private array $pluginDescriptions=[];

	/**
	 * Creates a stateful Panel surface instance.
	 *
	 * A surface owns configuration, plugins, resources, pages, routes, and render options for one mounted Panel experience.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @param ?PanelManager $manager Manager.
	 * @param array<string, mixed> $config Configuration overrides merged into the surface state.
	 */
	public function __construct(?string $name=null, ?PanelManager $manager=null, array $config=[]) {
		$this->name=Resource::normalizeName((string)($name ?? ''));
		$this->manager=$manager ?? new PanelManager();
		$this->config($config);
		if($this->name!==''){
			$this->config['panel_name']=$this->name;
		}
	}

	/**
	 * Creates a stateful Panel surface instance.
	 *
	 * A surface owns configuration, plugins, resources, pages, routes, and render options for one mounted Panel experience.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @param array<string, mixed> $config Configuration overrides merged into the surface state.
	 * @return self.
	 */
	public static function make(?string $name=null, array $config=[]): self {
		return new self($name, null, $config);
	}

	/**
	 * Returns the normalized name of this Panel surface.
	 *
	 * The name is set at construction and is also copied into panel_name config when non-empty.
	 * @return string.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the manager that owns resources, pages, routes, and commands for this surface.
	 *
	 * @return PanelManager.
	 */
	public function manager(): PanelManager {
		return $this->manager;
	}

	/**
	 * Creates a test harness bound to this Panel surface.
	 *
	 * The harness receives the current surface state so tests can exercise configured resources, pages, commands, and rendering options.
	 * @return PanelTestHarness.
	 */
	public function test(): PanelTestHarness {
		return PanelTestHarness::make($this);
	}

	/**
	 * Creates a scaffolder bound to this Panel surface.
	 *
	 * The scaffolder can read current surface configuration while generating resources, pages, widgets, or package artifacts.
	 * @return PanelScaffolder.
	 */
	public function scaffold(): PanelScaffolder {
		return PanelScaffolder::make($this);
	}

	/**
	 * Creates a data job descriptor for imports, exports, or custom work.
	 *
	 *
	 * @param string $type Job type token stored on the descriptor.
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelDataJob.
	 */
	public function dataJob(string $type, string $name='job'): PanelDataJob {
		return PanelDataJob::make($type, $name);
	}

	/**
	 * Creates an import data job descriptor.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelDataJob.
	 */
	public function importJob(string $name='import'): PanelDataJob {
		return PanelDataJob::import($name);
	}

	/**
	 * Creates an export data job descriptor.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelDataJob.
	 */
	public function exportJob(string $name='export'): PanelDataJob {
		return PanelDataJob::export($name);
	}

	/**
	 * Builds Panel media-library helper objects.
	 *
	 * Media helpers normalize collections, file metadata, and item descriptors used by uploads and resource forms.
	 *
	 * @param array<int|string, PanelMediaCollection|array<string, mixed>|string> $collections Media collection definitions.
	 * @return PanelMediaLibrary.
	 */
	public function mediaLibrary(array $collections=[]): PanelMediaLibrary {
		return PanelMediaLibrary::make($collections);
	}

	/**
	 * Builds Panel media-library helper objects.
	 *
	 * Media helpers normalize collections, file metadata, and item descriptors used by uploads and resource forms.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelMediaCollection.
	 */
	public function mediaCollection(string $name='default'): PanelMediaCollection {
		return PanelMediaCollection::make($name);
	}

	/**
	 * Builds Panel media-library helper objects.
	 *
	 * Media helpers normalize collections, file metadata, and item descriptors used by uploads and resource forms.
	 *
	 * @param array<string, mixed> $file Uploaded file metadata or stored media descriptor.
	 * @param PanelMediaCollection|array|string|null $collection Collection.
	 * @param array<string, mixed> $attributes Additional media item attributes.
	 * @return PanelMediaItem.
	 */
	public function mediaItem(array $file, PanelMediaCollection|array|string|null $collection=null, array $attributes=[]): PanelMediaItem {
		return PanelMediaItem::from($file, $collection, $attributes);
	}

	/**
	 * Builds Panel notification and inbox helpers.
	 *
	 * Notification helpers normalize messages, recipients, channels, and inbox adapters for operator UI surfaces.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @return PanelNotificationInbox.
	 */
	public function notificationInbox(array $notifications=[]): PanelNotificationInbox {
		return PanelNotificationInbox::make($notifications);
	}

	/**
	 * Builds Panel notification and inbox helpers.
	 *
	 * Notification helpers normalize messages, recipients, channels, and inbox adapters for operator UI surfaces.
	 *
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @param array<int, string> $channels Delivery channel names.
	 * @return PanelNotificationAdapter.
	 */
	public function notificationAdapter(array $notifications=[], array $channels=['database']): PanelNotificationAdapter {
		return PanelInMemoryNotificationAdapter::make($notifications, $channels);
	}

	/**
	 * Builds Panel notification and inbox helpers.
	 *
	 * Notification helpers normalize messages, recipients, channels, and inbox adapters for operator UI surfaces.
	 *
	 * @param PanelNotificationAdapter $adapter Adapter.
	 * @param array<int, PanelInboxNotification|PanelNotification|array<string, mixed>|string> $notifications Notification descriptors.
	 * @return PanelNotificationInbox.
	 */
	public function notificationInboxUsing(PanelNotificationAdapter $adapter, array $notifications=[]): PanelNotificationInbox {
		return PanelNotificationInbox::using($adapter, $notifications);
	}

	/**
	 * Builds Panel notification and inbox helpers.
	 *
	 * Notification helpers normalize messages, recipients, channels, and inbox adapters for operator UI surfaces.
	 *
	 * @param PanelNotification|array|string $notification Notification.
	 * @param ?string $recipient Recipient.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return PanelInboxNotification.
	 */
	public function inboxNotification(PanelNotification|array|string $notification, ?string $recipient=null, array $meta=[]): PanelInboxNotification {
		return PanelInboxNotification::from($notification, $recipient, $meta);
	}

	/**
	 * Creates an accessibility audit from a rendered Panel page result.
	 *
	 * Metadata is preserved with the audit so test and regression tooling can report the surface, route, viewport, or scenario that produced the result.
	 *
	 * @param PanelPageResult|string $result Rendered page result or HTML content to inspect.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return PanelAccessibilityAudit.
	 */
	public function accessibilityAudit(PanelPageResult|string $result, array $meta=[]): PanelAccessibilityAudit {
		return PanelAccessibilityAudit::from($result, $meta);
	}

	/**
	 * Creates a regression suite bound to this Panel surface.
	 *
	 * The suite retains the surface reference so scenarios can use current resources, pages, and renderer settings.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelRegressionSuite.
	 */
	public function regressionSuite(string $name='regression_suite'): PanelRegressionSuite {
		return PanelRegressionSuite::make($name, $this);
	}

	/**
	 * Creates a documentation catalog from entry descriptors.
	 *
	 * Entries are normalized by PanelDocumentationCatalog and remain independent from runtime navigation until explicitly registered or rendered.
	 *
	 * @param array<int, PanelDocumentationEntry|array<string, mixed>> $entries Documentation entries.
	 * @return PanelDocumentationCatalog.
	 */
	public function documentationCatalog(array $entries=[]): PanelDocumentationCatalog {
		return PanelDocumentationCatalog::make($entries);
	}

	/**
	 * Creates a documentation entry descriptor.
	 *
	 *
	 * @param string $id Stable documentation entry identifier.
	 * @param string $title Human-readable documentation title.
	 * @return PanelDocumentationEntry.
	 */
	public function documentationEntry(string $id, string $title=''): PanelDocumentationEntry {
		return PanelDocumentationEntry::make($id, $title);
	}

	/**
	 * Configures localization for this Panel surface.
	 *
	 * Localization settings choose locale, fallback locale, translation catalogs, and runtime text lookup behavior.
	 *
	 * @param PanelLocalization|array|null $localization Localization.
	 * @param ?string $locale Locale.
	 * @param ?string $fallbackLocale FallbackLocale.
	 * @return PanelLocalization|self.
	 */
	public function localization(PanelLocalization|array|null $localization=null, ?string $locale=null, ?string $fallbackLocale=null): PanelLocalization|self {
		if($localization!==null){
			$instance=PanelLocalization::from($localization, $locale, $fallbackLocale);
			$this->config['localization']=$instance;
			$this->config['locale']=$instance->locale();
			$this->config['fallback_locale']=$instance->fallbackLocale();
			return $this;
		}
		$current=$this->config['localization'] ?? null;
		if($current instanceof PanelLocalization){
			if($locale!==null || $fallbackLocale!==null){
				$current=PanelLocalization::from($current, $locale, $fallbackLocale);
				$this->config['localization']=$current;
				$this->config['locale']=$current->locale();
				$this->config['fallback_locale']=$current->fallbackLocale();
			}
			return $current;
		}
		$config=is_array($current) ? $current : [];
		$configLocale=$locale ?? (is_scalar($this->config['locale'] ?? null) ? (string)$this->config['locale'] : null);
		$configFallback=$fallbackLocale ?? (is_scalar($this->config['fallback_locale'] ?? $this->config['fallbackLocale'] ?? null) ? (string)($this->config['fallback_locale'] ?? $this->config['fallbackLocale']) : null);
		$instance=PanelLocalization::from($config, $configLocale, $configFallback);
		$this->config['localization']=$instance;
		$this->config['locale']=$instance->locale();
		$this->config['fallback_locale']=$instance->fallbackLocale();
		return $instance;
	}

	/**
	 * Configures localization for this Panel surface.
	 *
	 * Localization settings choose locale, fallback locale, translation catalogs, and runtime text lookup behavior.
	 *
	 * @param string $locale Locale code applied to Panel labels and translations.
	 * @return self.
	 */
	public function locale(string $locale): self {
		$this->localization(null, $locale);
		return $this;
	}

	/**
	 * Sets the fallback locale used when the active locale lacks a translation.
	 *
	 * @param string $locale Fallback locale code.
	 * @return self.
	 */
	public function fallbackLocale(string $locale): self {
		$this->localization(null, null, $locale);
		return $this;
	}

	/**
	 * Configures localization for this Panel surface.
	 *
	 * Localization settings choose locale, fallback locale, translation catalogs, and runtime text lookup behavior.
	 *
	 * @param string $locale Locale code applied to Panel labels and translations.
	 * @param array<string, string|array<string, mixed>> $translations Translation strings or nested translation groups.
	 * @param string $scope Translation or configuration scope name.
	 * @return self.
	 */
	public function translations(string $locale, array $translations, string $scope=''): self {
		$this->localization()->add($locale, $translations, $scope);
		return $this;
	}

	/**
	 * Configures localization for this Panel surface.
	 *
	 * Localization settings choose locale, fallback locale, translation catalogs, and runtime text lookup behavior.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param array<string, scalar|\Stringable|null> $parameters Translation interpolation parameters.
	 * @param ?string $locale Locale.
	 * @param string|\Stringable|null $default Default.
	 * @param string $scope Translation or configuration scope name.
	 * @return string.
	 */
	public function trans(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return $this->localization()->translate($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Alias for trans().
	 *
	 * @param string $key Translation key.
	 * @param array<string, scalar|\Stringable|null> $parameters Translation interpolation parameters.
	 * @param ?string $locale Locale.
	 * @param string|\Stringable|null $default Default.
	 * @param string $scope Translation or configuration scope name.
	 * @return string.
	 */
	public function t(string $key, array $parameters=[], ?string $locale=null, string|\Stringable|null $default=null, string $scope=''): string {
		return $this->trans($key, $parameters, $locale, $default, $scope);
	}

	/**
	 * Merges configuration values into this surface.
	 *
	 * Array keys are string-cast and blank keys are ignored. Scalar-key calls store one value without cloning, so boot-time configuration mutates the current surface instance.
	 *
	 * @param array|string $key Configuration map or single configuration key.
	 * @param mixed $value Value stored when $key is a string.
	 * @return self.
	 */
	public function config(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			foreach($key as $name=>$configValue){
				$name=trim((string)$name);
				if($name!==''){
					$this->config[$name]=$configValue;
				}
			}
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->config[$key]=$value;
		}
		return $this;
	}

	/**
	 * Sets the human-readable label shown for this Panel surface.
	 *
	 * @param string $label Panel label stored in configuration.
	 * @return self.
	 */
	public function label(string $label): self {
		return $this->config('panel_label', $label);
	}

	/**
	 * Sets the label used for the generated home navigation item.
	 *
	 * @param string $label Home navigation label stored in configuration.
	 * @return self.
	 */
	public function homeLabel(string $label): self {
		return $this->config('home_label', $label);
	}

	/**
	 * Sets the request parameter used for global search queries.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return self.
	 */
	public function globalSearchParameter(string $name): self {
		return $this->config('global_search_parameter', $name);
	}

	/**
	 * Sets the request parameter used to carry tenant context.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return self.
	 */
	public function tenantParameter(string $name): self {
		return $this->config('tenant_parameter', $name);
	}

	/**
	 * Stores the current tenant value for this surface.
	 *
	 * Tenant-aware resources and pages can read this value through surface configuration unless a tenant resolver overrides it.
	 *
	 * @param string|int|null $tenant Tenant identifier or null to clear static tenant context.
	 * @return self.
	 */
	public function tenant(string|int|null $tenant): self {
		return $this->config('tenant', $tenant);
	}

	/**
	 * Registers a callback that resolves tenant context at runtime.
	 *
	 * The resolver lets request-aware code provide tenant identifiers without baking them into boot-time configuration.
	 *
	 * @param callable $resolver Callback that returns the active tenant context.
	 * @return self.
	 */
	public function tenantResolver(callable $resolver): self {
		return $this->config('tenant_resolver', $resolver);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Layout names are normalized; unsupported values fall back to sidebar so the shell manifest always has a known navigation layout.
	 *
	 * @param string $layout Navigation layout token.
	 * @return self.
	 */
	public function navigationLayout(string $layout): self {
		$layout=Resource::normalizeName($layout);
		return $this->config('navigation_layout', in_array($layout, ['sidebar', 'horizontal', 'none'], true) ? $layout : 'sidebar');
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Mode names are normalized; unsupported values fall back to floating so renderers receive a known desktop navigation mode.
	 *
	 * @param string $mode Desktop navigation mode token.
	 * @return self.
	 */
	public function navigationMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		return $this->config('navigation_mode', in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating');
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Legacy aliases such as offcanvas or hamburger normalize to drawer, while hidden/off aliases normalize to none.
	 *
	 * @param string $mode Mobile navigation mode token.
	 * @return self.
	 */
	public function mobileNavigationMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		if(in_array($mode, ['offcanvas', 'off_canvas', 'hamburger', 'menu'], true)){
			$mode='drawer';
		}
		if(in_array($mode, ['hidden', 'disabled', 'off'], true)){
			$mode='none';
		}
		return $this->config('mobile_navigation_mode', in_array($mode, ['chips', 'drawer', 'none'], true) ? $mode : 'chips');
	}

	/**
	 * Alias for mobileNavigationMode().
	 *
	 * @param string $mode Mobile navigation mode token.
	 * @return self.
	 */
	public function sidebarMobileMode(string $mode): self {
		return $this->mobileNavigationMode($mode);
	}

	/**
	 * Sets the mobile sidebar drawer layout for this Panel surface.
	 *
	 * Use `single` for a readable vertical drawer and `split` for the denser two-column navigation treatment.
	 *
	 * @param string $layout Mobile drawer layout token.
	 * @return self.
	 */
	public function mobileSidebarLayout(string $layout): self {
		$layout=Resource::normalizeName($layout);
		if(in_array($layout, ['two_column', 'two_columns', 'two-col', 'two-cols', 'compact_grid', 'grid'], true)){
			$layout='split';
		}
		return $this->config('mobile_sidebar_layout', in_array($layout, ['single', 'split'], true) ? $layout : 'single');
	}

	/**
	 * Configures optional sidebar/drawer animation for this Panel surface.
	 *
	 * Supported types are `none`, `slide`, `fade`, `scale`, and `slide_fade`; duration is clamped to 0-2000ms.
	 *
	 * @param string|bool $type Animation type.
	 * @param int $durationMs Duration in milliseconds.
	 * @param string $easing Easing preset.
	 * @return self.
	 */
	public function sidebarAnimation(string|bool $type='slide', int $durationMs=180, string $easing='ease'): self {
		if(is_bool($type)){
			$type=$type ? 'slide' : 'none';
		}
		$type=Resource::normalizeName($type);
		if(in_array($type, ['slidefade', 'slide_and_fade'], true)){
			$type='slide_fade';
		}
		if(in_array($type, ['zoom', 'pop'], true)){
			$type='scale';
		}
		$type=in_array($type, ['none', 'slide', 'fade', 'scale', 'slide_fade'], true) ? $type : 'none';
		$durationMs=max(0, min(2000, $durationMs));
		$easing=Resource::normalizeName($easing);
		$easing=in_array($easing, ['ease', 'linear', 'ease_in', 'ease_out', 'ease_in_out', 'standard', 'snappy', 'swift'], true) ? $easing : 'ease';
		return $this->config([
			'sidebar_animation_type'=>$type,
			'sidebar_animation_duration'=>$durationMs,
			'sidebar_animation_easing'=>$easing,
		]);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Mode names are normalized; unsupported values fall back to floating so renderers receive a known header mode.
	 *
	 * @param string $mode Header layout mode token.
	 * @return self.
	 */
	public function headerMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		return $this->config('header_mode', in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating');
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Mode names are normalized; unsupported values fall back to floating so renderers receive a known footer mode.
	 *
	 * @param string $mode Footer layout mode token.
	 * @return self.
	 */
	public function footerMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		return $this->config('footer_mode', in_array($mode, ['floating', 'docked', 'edge', 'overlay'], true) ? $mode : 'floating');
	}

	/**
	 * Sets the content spacing density for rendered pages.
	 *
	 * Unsupported spacing values fall back to normal.
	 *
	 * @param string $spacing Content spacing token.
	 * @return self.
	 */
	public function contentSpacing(string $spacing): self {
		$spacing=Resource::normalizeName($spacing);
		return $this->config('content_spacing', in_array($spacing, ['normal', 'compact', 'flush'], true) ? $spacing : 'normal');
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Custom page layout controls the default frame used for pages that do not provide their own layout; plain normalizes to flow.
	 *
	 * @param string $layout Custom page layout token.
	 * @return self.
	 */
	public function customPageLayout(string $layout): self {
		$layout=Resource::normalizeName($layout);
		if($layout==='plain'){
			$layout='flow';
		}
		return $this->config('custom_page_layout', in_array($layout, ['carded', 'flow'], true) ? $layout : 'carded');
	}

	/**
	 * Sets how bottom command bars arrange secondary metadata and actions.
	 *
	 * Unsupported modes fall back to stacked.
	 *
	 * @param string $mode Command bar bottom layout token.
	 * @return self.
	 */
	public function commandbarBottomMode(string $mode): self {
		$mode=Resource::normalizeName($mode);
		return $this->config('commandbar_bottom_mode', in_array($mode, ['stacked', 'inline', 'meta'], true) ? $mode : 'stacked');
	}

	/**
	 * Configures compact table header controls.
	 *
	 * Boolean input maps to compact or none; string input is normalized and restricted to known renderer modes.
	 *
	 * @param string|bool $mode Header-control mode or enable flag.
	 * @return self.
	 */
	public function tableHeaderControls(string|bool $mode='compact'): self {
		if(is_bool($mode)){
			return $this->config('table_header_controls', $mode ? 'compact' : 'none');
		}
		$mode=Resource::normalizeName($mode);
		return $this->config('table_header_controls', in_array($mode, ['none', 'compact'], true) ? $mode : 'none');
	}

	/**
	 * Configures table pagination visibility.
	 *
	 * Unsupported visibility values fall back to always so table renderers receive a known policy.
	 *
	 * @param string $visibility Pagination visibility policy.
	 * @return self.
	 */
	public function tablePaginationVisibility(string $visibility): self {
		$visibility=Resource::normalizeName($visibility);
		return $this->config('table_pagination_visibility', in_array($visibility, ['always', 'hide_empty', 'hide_single', 'hide_empty_or_single'], true) ? $visibility : 'always');
	}

	/**
	 * Configures when the modal expand control appears.
	 *
	 * Use `surface` for record/detail modals only, `always` for legacy behavior, or `never` for compact shells.
	 *
	 * @param string|bool $mode Mode.
	 * @return self.
	 */
	public function modalExpandButton(string|bool $mode='always'): self {
		if(is_bool($mode)){
			return $this->config('modal_expand_button', $mode ? 'always' : 'never');
		}
		$mode=Resource::normalizeName($mode);
		if(in_array($mode, ['surface_only', 'surfaces', 'record', 'records'], true)){
			$mode='surface';
		}
		return $this->config('modal_expand_button', in_array($mode, ['always', 'never', 'surface'], true) ? $mode : 'always');
	}

	/**
	 * Configures which secondary modal header actions are exposed.
	 *
	 * Use values like `open_full`, `copy_link`, `refresh`, and `expand`; pass an empty array for close-only modal chrome.
	 *
	 * @param array|string $actions Actions.
	 * @return self.
	 */
	public function modalChromeActions(array|string $actions): self {
		if(is_string($actions)){
			$actions=preg_split('/[\s,|]+/', $actions, -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
		return $this->config('modal_chrome_actions', array_keys($normalized));
	}

	/**
	 * Delegates the `table density controls` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function tableDensityControls(bool $enabled=true): self {
		return $this->config('table_density_controls', $enabled);
	}

	/**
	 * Delegates the `table spacing selector` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function tableSpacingSelector(bool $enabled=true): self {
		return $this->tableDensityControls($enabled);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function resourceImports(bool $enabled=true): self {
		return $this->config('resource_imports', $enabled);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function resourceExports(bool $enabled=true): self {
		return $this->config('resource_exports', $enabled);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function resourceImportExport(bool $enabled=true): self {
		return $this->resourceImports($enabled)->resourceExports($enabled);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param array<string, bool|string|int> $features Navigation feature flags keyed by feature name.
	 * @return self.
	 */
	public function navigationFeatures(array $features): self {
		$current=is_array($this->config['navigation_features'] ?? null) ? $this->config['navigation_features'] : [];
		foreach($features as $feature=>$enabled){
			$feature=Resource::normalizeName((string)$feature);
			if(in_array($feature, ['mobile', 'mobile_mode', 'mobile_navigation', 'sidebar_mobile', 'sidebar_mobile_mode'], true)){
				$this->mobileNavigationMode(is_bool($enabled) ? ($enabled ? 'chips' : 'none') : (string)$enabled);
				continue;
			}
			if($feature==='pins' || $feature==='pinned'){
				$feature='pinning';
			}
			if($feature==='collapsible' || $feature==='toggle'){
				$feature='collapse';
			}
			if(in_array($feature, ['accordion', 'exclusive', 'exclusive_collapse', 'collapse_exclusive', 'single_open'], true)){
				$feature='collapse_exclusive';
			}
			if(in_array($feature, ['search', 'recent', 'pinning', 'collapse', 'collapse_exclusive'], true)){
				$current[$feature]=(bool)$enabled;
			}
		}
		return $this->config('navigation_features', $current);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function navigationSearch(bool $enabled=true): self {
		return $this->navigationFeatures(['search'=>$enabled]);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function recentNavigation(bool $enabled=true): self {
		return $this->navigationFeatures(['recent'=>$enabled]);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function pinnedNavigation(bool $enabled=true): self {
		return $this->navigationFeatures(['pinning'=>$enabled]);
	}

	/**
	 * Configures whether Panel renders a generated home navigation item.
	 *
	 * Disable this when an application registers its own dashboard route in navigation.
	 *
	 * @param bool $enabled Whether this Panel feature should be enabled.
	 * @return self.
	 */
	public function homeNavigation(bool $enabled=true): self {
		return $this->config('home_navigation', $enabled);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param bool $sticky Whether the navigation region should remain sticky.
	 * @return self.
	 */
	public function stickyNavigation(bool $sticky=true): self {
		return $this->config('navigation_sticky', $sticky);
	}

	/**
	 * Delegates the `sticky header` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param bool $sticky Whether the navigation region should remain sticky.
	 * @return self.
	 */
	public function stickyHeader(bool $sticky=true): self {
		return $this->config('header_sticky', $sticky);
	}

	/**
	 * Delegates the `sticky footer` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param bool $sticky Whether the navigation region should remain sticky.
	 * @return self.
	 */
	public function stickyFooter(bool $sticky=true): self {
		return $this->config('footer_sticky', $sticky);
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param string $hook Panel render or lifecycle hook name.
	 * @param callable|string $renderer Renderer.
	 * @return self.
	 */
	public function renderHook(string $hook, callable|string $renderer): self {
		$hook=Resource::normalizeName(str_replace(':', '.', $hook));
		if($hook===''){
			return $this;
		}
		$hooks=is_array($this->config['render_hooks'] ?? null) ? $this->config['render_hooks'] : [];
		$current=$hooks[$hook] ?? [];
		if(!is_array($current) || !array_is_list($current)){
			$current=$current===[] ? [] : [$current];
		}
		$current[]=$renderer;
		$hooks[$hook]=$current;
		return $this->config('render_hooks', $hooks);
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param array<int|string, callable|string> $hooks Render hooks keyed by hook point or appended in registration order.
	 * @return self.
	 */
	public function renderHooks(array $hooks): self {
		foreach($hooks as $hook=>$renderers){
			if(!is_string($hook) && !is_int($hook)){
				continue;
			}
			if(is_array($renderers) && array_is_list($renderers)){
				foreach($renderers as $renderer){
					if(is_callable($renderer) || is_string($renderer)){
						$this->renderHook((string)$hook, $renderer);
					}
				}
				continue;
			}
			if(is_callable($renderers) || is_string($renderers)){
				$this->renderHook((string)$hook, $renderers);
			}
		}
		return $this;
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param callable|string|null $builder Builder.
	 * @return self.
	 */
	public function urlBuilder(callable|string|null $builder): self {
		return $this->config('url_builder', $builder);
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param string $prefix URL, route, or cache prefix applied to Panel output.
	 * @return self.
	 */
	public function routeUrls(string $prefix='/panel'): self {
		return $this
			->urlBuilder(PanelRoute::urlBuilder($prefix))
			->assetUrlBuilder(static fn(string $asset): string => PanelRoute::assetUrl($prefix, $asset))
			->uploadUrl(PanelRoute::uploadUrl($prefix));
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param callable|string|null $builder Builder.
	 * @return self.
	 */
	public function assetUrlBuilder(callable|string|null $builder): self {
		return $this->config('asset_url_builder', $builder);
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param string $url Absolute or relative URL stored in Panel metadata.
	 * @return self.
	 */
	public function uploadUrl(string $url): self {
		return $this->config('upload_url', $url);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @return Resource.
	 */
	public function resource(?string $name=null): Resource {
		return Resource::make($name);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelPage.
	 */
	public function page(string $name): PanelPage {
		return PanelPage::make($name);
	}

	/**
	 * Delegates the `theme` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelTheme|PanelThemePreset|array|string|null $theme Theme.
	 * @return PanelTheme.
	 */
	public function theme(PanelTheme|PanelThemePreset|array|string|null $theme=null): PanelTheme {
		if($theme===null){
			$current=$this->config['theme'] ?? null;
			if($current instanceof PanelTheme){
				return $current;
			}
			if(is_array($current)){
				$this->config['theme']=PanelTheme::fromArray($current);
				return $this->config['theme'];
			}
			if(is_string($current) && trim($current)!==''){
				$this->config['theme']=PanelTheme::namedTheme($current) ?? PanelTheme::make($current);
				return $this->config['theme'];
			}
			$this->config['theme']=PanelTheme::make($this->name!=='' ? $this->name : 'default');
			return $this->config['theme'];
		}
		$this->config['theme']=$theme instanceof PanelTheme
			? $theme
			: ($theme instanceof PanelThemePreset ? $theme->toTheme($this->name!=='' ? $this->name : 'default') : (is_array($theme) ? PanelTheme::fromArray($theme) : (PanelTheme::namedTheme($theme) ?? PanelTheme::make($theme))));
		return $this->config['theme'];
	}

	/**
	 * Delegates the `use theme` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelTheme|PanelThemePreset|array|string $theme Theme.
	 * @return self.
	 */
	public function useTheme(PanelTheme|PanelThemePreset|array|string $theme): self {
		$this->theme($theme);
		return $this;
	}

	/**
	 * Delegates the `palette` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $color Color token or CSS color value used by Panel theming.
	 * @return array.
	 */
	public function palette(string $color): array {
		return PanelTheme::palette($color);
	}

	/**
	 * Delegates the `theme preset` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string|array|PanelThemePreset $preset Preset.
	 * @return PanelThemePreset.
	 */
	public function themePreset(string|array|PanelThemePreset $preset): PanelThemePreset {
		return PanelTheme::presetDefinition($preset);
	}

	/**
	 * Delegates the `register theme preset` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelThemePreset|array $preset Preset.
	 * @return PanelThemePreset.
	 */
	public function registerThemePreset(PanelThemePreset|array $preset): PanelThemePreset {
		return PanelTheme::register_preset($preset);
	}

	/**
	 * Delegates the `register theme` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelTheme|array $theme Theme.
	 * @return PanelTheme.
	 */
	public function registerTheme(PanelTheme|array $theme): PanelTheme {
		return PanelTheme::registerTheme($theme);
	}

	/**
	 * Delegates the `named theme` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return ?PanelTheme.
	 */
	public function namedTheme(string $name): ?PanelTheme {
		return PanelTheme::namedTheme($name);
	}

	/**
	 * Delegates the `load theme presets` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string|array $paths Paths.
	 * @return PanelThemeLibrary.
	 */
	public function loadThemePresets(string|array $paths): PanelThemeLibrary {
		return PanelTheme::loadPresets($paths);
	}

	/**
	 * Delegates the `load themes` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string|array $paths Paths.
	 * @return PanelThemeLibrary.
	 */
	public function loadThemes(string|array $paths): PanelThemeLibrary {
		return PanelTheme::loadThemes($paths);
	}

	/**
	 * Delegates the `theme library` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 * @return PanelThemeLibrary.
	 */
	public function themeLibrary(): PanelThemeLibrary {
		return PanelTheme::themeLibrary();
	}

	/**
	 * Delegates the `theme diagnostics` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 * @return array.
	 */
	public function themeDiagnostics(): array {
		return PanelTheme::diagnostics();
	}

	/**
	 * Delegates the `theme preview` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @return array.
	 */
	public function themePreview(?string $name=null): array {
		return $name===null ? $this->theme()->preview() : PanelTheme::previewTheme($name);
	}

	/**
	 * Delegates the `theme preview html` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?string $name Normalized field, resource, surface, or helper name.
	 * @param array<string, mixed> $options Theme preview rendering options.
	 * @return string.
	 */
	public function themePreviewHtml(?string $name=null, array $options=[]): string {
		return $name===null ? $this->theme()->previewHtml($options) : PanelTheme::previewThemeHtml($name, $options);
	}

	/**
	 * Delegates the `theme manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelTheme|array|string|null $theme Theme.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @param bool $includePreview Whether preview metadata should be included.
	 * @return array.
	 */
	public function themeManifest(PanelTheme|array|string|null $theme=null, array $meta=[], bool $includePreview=false): array {
		return ThemeManifest::from($theme ?? $this->theme(), $meta, $includePreview)->toArray();
	}

	/**
	 * Delegates the `theme variant` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array|\Closure $overrides Overrides.
	 * @return PanelTheme.
	 */
	public function themeVariant(string $name, array|\Closure $overrides=[]): PanelTheme {
		return $this->theme()->variant($name, $overrides);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return NavigationItem.
	 */
	public function navigationItem(string $name): NavigationItem {
		return NavigationItem::make($name);
	}

	/**
	 * Delegates the `nav` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return NavigationItem.
	 */
	public function nav(string $name): NavigationItem {
		return $this->navigationItem($name);
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param PanelNavigationState|array|null $navigation Navigation.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $search Navigation search state.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function navigationManifest(PanelNavigationState|array|null $navigation=null, ?PanelRequest $request=null, array $search=[], array $meta=[]): array {
		return NavigationManifest::from($navigation ?? $this, $request, $search, $meta)->toArray();
	}

	/**
	 * Delegates the `command` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PanelCommand.
	 */
	public function command(string $name): PanelCommand {
		return PanelCommand::make($name);
	}

	/**
	 * Delegates the `command manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelCommand|array|string $command Command.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function commandManifest(PanelCommand|array|string $command, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($command)){
			$command=$this->manager->registeredCommands()[Resource::normalizeName($command)] ?? $command;
		}
		return CommandManifest::from($command, $request, $this->manager, $meta)->toArray();
	}

	/**
	 * Delegates the `field` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Field.
	 */
	public function field(string $name, string $type='text'): Field {
		return Field::make($name, $type);
	}

	/**
	 * Delegates the `entry` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return InfolistEntry.
	 */
	public function entry(string $name, string $type='text'): InfolistEntry {
		return InfolistEntry::make($name, $type);
	}

	/**
	 * Delegates the `text entry` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return InfolistEntry.
	 */
	public function textEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'text');
	}

	/**
	 * Delegates the `badge entry` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array|string $tones Tones.
	 * @return InfolistEntry.
	 */
	public function badgeEntry(string $name, array|string $tones=[]): InfolistEntry {
		return InfolistEntry::make($name, 'badge')->badge($tones);
	}

	/**
	 * Delegates the `image entry` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return InfolistEntry.
	 */
	public function imageEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'image');
	}

	/**
	 * Delegates the `form section` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return FormSection.
	 */
	public function formSection(string $name): FormSection {
		return FormSection::make($name);
	}

	/**
	 * Delegates the `section` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return FormSection.
	 */
	public function section(string $name): FormSection {
		return $this->formSection($name);
	}

	/**
	 * Delegates the `schema` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, SchemaComponent|Field|FormSection|array<string, mixed>|string> $components Schema components.
	 * @return Schema.
	 */
	public function schema(array $components=[]): Schema {
		return Schema::make($components);
	}

	/**
	 * Delegates the `schema lifecycle` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Schema|ResourceForm|array $schema Schema.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return SchemaLifecycle.
	 */
	public function schemaLifecycle(Schema|ResourceForm|array $schema, array $meta=[]): SchemaLifecycle {
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
	 * Delegates the `schema manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Schema|ResourceForm|Infolist|array $schema Schema.
	 * @param ?string $operation Operation.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function schemaManifest(Schema|ResourceForm|Infolist|array $schema, ?string $operation=null, array $meta=[]): array {
		return SchemaManifest::from($schema, $operation, $meta)->toArray();
	}

	/**
	 * Delegates the `infolist` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, InfolistEntry|SchemaComponent|array<string, mixed>|string> $components Infolist components.
	 * @return Infolist.
	 */
	public function infolist(array $components=[]): Infolist {
		return Infolist::make($components);
	}

	/**
	 * Delegates the `schema component` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $kind Panel package or component kind.
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return SchemaComponent.
	 */
	public function schemaComponent(string $kind, string $name=''): SchemaComponent {
		return SchemaComponent::make($kind, $name);
	}

	/**
	 * Delegates the `schema section` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param FormSection|array|string $section Section.
	 * @param array<int, Field|SchemaComponent|array<string, mixed>|string> $fields Section field definitions.
	 * @return SchemaComponent.
	 */
	public function schemaSection(FormSection|array|string $section, array $fields=[]): SchemaComponent {
		return SchemaComponent::section($section, $fields);
	}

	/**
	 * Delegates the `schema tab` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array<int, SchemaComponent|Field|array<string, mixed>|string> $children Child components.
	 * @return SchemaComponent.
	 */
	public function schemaTab(string $name, array $children=[]): SchemaComponent {
		return SchemaComponent::tab($name, $children);
	}

	/**
	 * Delegates the `schema step` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array<int, SchemaComponent|Field|array<string, mixed>|string> $children Child components.
	 * @return SchemaComponent.
	 */
	public function schemaStep(string $name, array $children=[]): SchemaComponent {
		return SchemaComponent::step($name, $children);
	}

	/**
	 * Delegates the `refresh region` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param string $region Panel live region or layout region identifier.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function refreshRegion(string $key, string|\Stringable|callable $content, string $region='region', array $attributes=[]): string {
		$key=Resource::normalizeName($key);
		if($key===''){
			return is_callable($content) ? (string)$content($this) : (string)$content;
		}
		$region=Resource::normalizeName($region) ?: 'region';
		$html=is_callable($content) ? (string)$content($this) : (string)$content;
		$interval=self::refreshIntervalFromAttributes($attributes);
		if($interval>0){
			$attributes['data-dp-panel-refresh-interval']=(string)$interval;
			$attributes['data-dp-panel-refresh-live']='1';
		}
		$attributes['class']=trim((string)($attributes['class'] ?? '').' dp-panel-refresh-region');
		$attributes['data-dp-panel-refresh-region']=$region;
		$attributes['data-dp-panel-refresh-key']=$key;
		return '<section'.self::htmlAttributes($attributes).'>'.$html.'</section>';
	}

	/**
	 * Delegates the `refresh island` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function refreshIsland(string $key, string|\Stringable|callable $content, array $attributes=[]): string {
		return $this->refreshRegion($key, $content, 'island', $attributes);
	}

	/**
	 * Delegates the `live refresh region` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param int $intervalMs Polling interval in milliseconds.
	 * @param string $region Panel live region or layout region identifier.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function liveRefreshRegion(string $key, string|\Stringable|callable $content, int $intervalMs=15000, string $region='region', array $attributes=[]): string {
		$attributes['data-dp-panel-refresh-interval']=(string)max(1000, $intervalMs);
		return $this->refreshRegion($key, $content, $region, $attributes);
	}

	/**
	 * Delegates the `live refresh island` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param int $intervalMs Polling interval in milliseconds.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function liveRefreshIsland(string $key, string|\Stringable|callable $content, int $intervalMs=15000, array $attributes=[]): string {
		return $this->liveRefreshRegion($key, $content, $intervalMs, 'island', $attributes);
	}

	/**
	 * Delegates the `lazy refresh region` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param string|\Stringable|null $placeholder Placeholder.
	 * @param string $region Panel live region or layout region identifier.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function lazyRefreshRegion(string $key, string|\Stringable|callable $content, string|\Stringable|null $placeholder=null, string $region='region', array $attributes=[]): string {
		$key=Resource::normalizeName($key);
		if($key===''){
			return is_callable($content) ? (string)$content($this) : (string)$content;
		}
		$region=Resource::normalizeName($region) ?: 'region';
		$target=$region.':'.$key;
		if(self::deferredRefreshRequested($target)){
			$attributes['data-dp-panel-refresh-lazy-loaded']='1';
			return $this->refreshRegion($key, $content, $region, $attributes);
		}
		self::applyLazyRefreshAttributes($attributes);
		$attributes['data-dp-panel-refresh-lazy']='1';
		$attributes['data-dp-panel-refresh-lazy-target']=$target;
		$placeholderHtml=$placeholder===null ? self::lazyRefreshPlaceholder($key, $target, (($attributes['data-dp-panel-refresh-manual'] ?? null)==='1')) : (string)$placeholder;
		return $this->refreshRegion($key, $placeholderHtml, $region, $attributes);
	}

	/**
	 * Delegates the `lazy refresh island` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string|\Stringable|callable $content Content.
	 * @param string|\Stringable|null $placeholder Placeholder.
	 * @param array<string, mixed> $attributes HTML attributes applied to the refresh wrapper.
	 * @return string.
	 */
	public function lazyRefreshIsland(string $key, string|\Stringable|callable $content, string|\Stringable|null $placeholder=null, array $attributes=[]): string {
		return $this->lazyRefreshRegion($key, $content, $placeholder, 'island', $attributes);
	}

	/**
	 * Delegates the `refresh controls` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $key Panel configuration or cache key.
	 * @param string $region Panel live region or layout region identifier.
	 * @param array<string, mixed> $options Refresh control labels and CSS overrides.
	 * @return string.
	 */
	public function refreshControls(string $key, string $region='island', array $options=[]): string {
		$key=Resource::normalizeName($key);
		$region=Resource::normalizeName($region) ?: 'region';
		if($key===''){
			return '';
		}
		$target=$region.':'.$key;
		$label=(string)($options['label'] ?? Panel::trans('refresh.live_island', [], null, 'Live island'));
		$status=(string)($options['status'] ?? Panel::trans('client.auto_refresh', [], null, 'Auto refresh'));
		$refreshLabel=(string)($options['refresh_label'] ?? Panel::trans('common.refresh', [], null, 'Refresh'));
		$pauseLabel=(string)($options['pause_label'] ?? Panel::trans('client.pause', [], null, 'Pause'));
		$resumeLabel=(string)($options['resume_label'] ?? Panel::trans('client.resume', [], null, 'Resume'));
		$class=trim('dp-panel-refresh-controls '.(string)($options['class'] ?? ''));
		$attributes=[
			'class'=>$class,
			'role'=>'group',
			'aria-label'=>$label,
			'data-dp-panel-refresh-controls'=>true,
			'data-dp-panel-refresh-target'=>$target,
			'data-dp-panel-refresh-status'=>$status,
			'data-dp-panel-refresh-pause-label'=>$pauseLabel,
			'data-dp-panel-refresh-resume-label'=>$resumeLabel,
		];
		return '<div'.self::htmlAttributes($attributes).'>'
			.'<span data-dp-panel-refresh-status>'.htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>'
			.'<button type="button" class="dp-panel-button dp-panel-button-secondary" data-dp-panel-refresh-now="'.htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" title="'.htmlspecialchars($refreshLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars($refreshLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</button>'
			.'<button type="button" class="dp-panel-button dp-panel-button-secondary" data-dp-panel-refresh-toggle="'.htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" aria-pressed="false">'.htmlspecialchars($pauseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</button>'
			.'</div>';
	}

	/**
	 * Checks whether the current request explicitly deferred a refresh target.
	 *
	 * `__panel_defer` accepts comma or whitespace separated target names. A
	 * value may match the full `region:key` target, the key portion alone, or
	 * `*` to defer every lazy region in the current render pass.
	 *
	 * @param string $target Refresh target identifier in `region:key` form.
	 *
	 * @return bool True when the target should render as a deferred placeholder.
	 */
	private static function deferredRefreshRequested(string $target): bool {
		$value=(string)($_GET['__panel_defer'] ?? '');
		if($value===''){
			return false;
		}
		$target=strtolower($target);
		$key=str_contains($target, ':') ? substr($target, strpos($target, ':')+1) : $target;
		foreach(preg_split('/[,\s]+/', strtolower($value)) ?: [] as $candidate){
			$candidate=trim($candidate);
			if($candidate==='' ){
				continue;
			}
			if($candidate==='*' || $candidate===$target || $candidate===$key){
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds the placeholder markup used before a lazy refresh region loads.
	 *
	 * The placeholder keeps panel layout stable while client refresh code fetches
	 * the real region. Manual mode adds a user-triggered load button for regions
	 * that should not be fetched automatically.
	 *
	 * @param string $key Human-readable region key used to derive the heading.
	 * @param string $target Refresh target identifier consumed by client scripts.
	 * @param bool $manual Whether to render a manual load control.
	 *
	 * @return string Escaped lazy-region placeholder HTML.
	 */
	private static function lazyRefreshPlaceholder(string $key, string $target, bool $manual=false): string {
		$label=ucwords(str_replace(['_', '-', '.'], ' ', $key));
		$button=$manual
			? '<button type="button" class="dp-panel-button dp-panel-button-secondary" data-dp-panel-refresh-now="'.htmlspecialchars($target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">'.htmlspecialchars(Panel::trans('common.load_section', [], null, 'Load section'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</button>'
			: '';
		return '<section class="dp-panel-card dp-panel-lazy-placeholder" aria-busy="true">'
			.'<div class="dp-panel-lazy-shimmer"></div>'
			.'<h2>'.htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h2>'
			.'<p>'.htmlspecialchars(Panel::trans('common.loading_section', [], null, 'Loading this section...'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>'
			.$button
			.'</section>';
	}

	/**
	 * Converts ergonomic lazy-refresh aliases into browser data attributes.
	 *
	 * Public panel builders can pass options such as `prefetch_on_hover`,
	 * `lazy_manual`, or `visible_margin`; this helper consumes those aliases and
	 * replaces them with the canonical `data-dp-panel-refresh-*` attributes used
	 * by the Panel client runtime.
	 *
	 * @param array<string,mixed> $attributes Mutable attribute map for a region.
	 *
	 * @return void
	 */
	private static function applyLazyRefreshAttributes(array &$attributes): void {
		foreach(['lazy_prefetch', 'prefetch', 'prefetch_on_hover', 'load_on_hover'] as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=$attributes[$key];
			unset($attributes[$key]);
			if($value!==false && $value!==null && $value!=='0'){
				$attributes['data-dp-panel-refresh-prefetch']='1';
			}
		}
		foreach(['lazy_prefetch_delay', 'prefetch_delay', 'hover_delay'] as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=max(0, (int)$attributes[$key]);
			unset($attributes[$key]);
			$attributes['data-dp-panel-refresh-prefetch-delay']=(string)$value;
		}
		foreach(['lazy_manual', 'manual', 'load_on_interaction'] as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=$attributes[$key];
			unset($attributes[$key]);
			if($value!==false && $value!==null && $value!=='0'){
				$attributes['data-dp-panel-refresh-manual']='1';
			}
		}
		foreach(['lazy_visible', 'visible', 'when_visible', 'load_when_visible'] as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=$attributes[$key];
			unset($attributes[$key]);
			if($value!==false && $value!==null && $value!=='0'){
				$attributes['data-dp-panel-refresh-visible']='1';
			}
		}
		foreach(['lazy_margin', 'visible_margin', 'load_margin'] as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=max(0, (int)$attributes[$key]);
			unset($attributes[$key]);
			if($value>0){
				$attributes['data-dp-panel-refresh-visible-margin']=(string)$value;
			}
		}
	}

	/**
	 * Extracts and normalizes a refresh interval from region attributes.
	 *
	 * Multiple builder-facing aliases are accepted and removed from the
	 * attribute map. Boolean `true` selects the default live interval, numeric
	 * values below 1000 are treated as seconds, and values with an `s` suffix are
	 * converted to milliseconds.
	 *
	 * @param array<string,mixed> $attributes Mutable attribute map for a region.
	 *
	 * @return int Refresh interval in milliseconds, or `0` when polling is off.
	 */
	private static function refreshIntervalFromAttributes(array &$attributes): int {
		$keys=['data-dp-panel-refresh-interval', 'refresh_interval', 'live_interval', 'interval_ms', 'poll_interval', 'poll'];
		foreach($keys as $key){
			if(!array_key_exists($key, $attributes)){
				continue;
			}
			$value=$attributes[$key];
			unset($attributes[$key]);
			if($value===true){
				return 15000;
			}
			if(is_string($value)&&str_ends_with($value, 's')){
				$value=(float)substr($value, 0, -1)*1000;
			}
			$interval=(int)$value;
			if($interval>0&&$interval<1000){
				$interval*=1000;
			}
			return max(0, $interval);
		}
		return 0;
	}

	/**
	 * Delegates the `column` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Column.
	 */
	public function column(string $name, string $type='text'): Column {
		return Column::make($name, $type);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return PageTable.
	 */
	public function pageTable(string $name): PageTable {
		return PageTable::make($name);
	}

	/**
	 * Delegates the `table manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ResourceTable|PageTable|Resource|array $table Table.
	 * @param ?Resource $resource Resource.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function tableManifest(ResourceTable|PageTable|Resource|array $table, ?Resource $resource=null, ?PanelRequest $request=null, array $meta=[]): array {
		return TableManifest::from($table, $resource, $request, $meta)->toArray();
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param Resource|string|array $resource Resource.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function resourceManifest(Resource|string|array $resource, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($resource)){
			$resource=$this->get($resource) ?? ['name'=>$resource];
		}
		return ResourceManifest::from($resource, $request, $meta)->toArray();
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param PanelPage|string|array $page Page.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function pageManifest(PanelPage|string|array $page, ?PanelRequest $request=null, array $meta=[]): array {
		if(is_string($page)){
			$page=$this->getPage($page) ?? ['name'=>$page];
		}
		return PageManifest::from($page, $request, $this->manager, $meta)->toArray();
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableFilter.
	 */
	public function pageFilter(string $name, string $type='text'): TableFilter {
		return TableFilter::make($name, $type);
	}

	/**
	 * Delegates the `filter` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableFilter.
	 */
	public function filter(string $name, string $type='text'): TableFilter {
		return TableFilter::make($name, $type);
	}

	/**
	 * Delegates the `view` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return TableView.
	 */
	public function view(string $name): TableView {
		return TableView::make($name);
	}

	/**
	 * Delegates the `summary` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return TableSummary.
	 */
	public function summary(string $name, string $type='count'): TableSummary {
		return TableSummary::make($name, $type);
	}

	/**
	 * Delegates the `table group` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return TableGroup.
	 */
	public function tableGroup(string $name): TableGroup {
		return TableGroup::make($name);
	}

	/**
	 * Delegates the `action` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return Action.
	 */
	public function action(string $name): Action {
		return Action::make($name);
	}

	/**
	 * Delegates the `action manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Action|ActionGroup|array|string $action Action.
	 * @param mixed $record Panel record or row payload supplied to resolvers.
	 * @param ?PanelRequest $request Request.
	 * @param ?Resource $resource Resource.
	 * @param string $mode Panel operation mode such as create, edit, view, or index.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function actionManifest(Action|ActionGroup|array|string $action, mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $mode='action', array $meta=[]): array {
		if(is_array($action)){
			$action=isset($action['actions']) ? ActionGroup::fromArray($action) : Action::fromArray($action);
		}
		elseif(is_string($action)){
			$action=Action::make($action);
		}
		return ActionManifest::from($action, $record, $request, $resource, $mode, $meta)->toArray();
	}

	/**
	 * Delegates the `action group` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param array<int, Action|ActionGroup|array<string, mixed>|string> $actions Grouped action definitions.
	 * @return ActionGroup.
	 */
	public function actionGroup(string $name, array $actions=[]): ActionGroup {
		return ActionGroup::make($name)->actions($actions);
	}

	/**
	 * Delegates the `action group section` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $label Human-facing Panel label.
	 * @param string $description Human-facing Panel description text.
	 * @return array.
	 */
	public function actionGroupSection(string $label, string $description=''): array {
		return ['type'=>'section', 'label'=>$label, 'description'=>$description];
	}

	/**
	 * Delegates the `action group divider` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 * @return array.
	 */
	public function actionGroupDivider(): array {
		return ['type'=>'divider'];
	}

	/**
	 * Delegates the `relation` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return RelationManager.
	 */
	public function relation(string $name): RelationManager {
		return RelationManager::make($name);
	}

	/**
	 * Delegates the `relation manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param RelationManager|array $relation Relation.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function relationManifest(RelationManager|array $relation, ?PanelRequest $request=null, array $meta=[]): array {
		return RelationManifest::from($relation, $request, $meta)->toArray();
	}

	/**
	 * Delegates the `widget` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Widget.
	 */
	public function widget(string $name, string $type='stat'): Widget {
		return Widget::make($name, $type);
	}

	/**
	 * Delegates the `widget manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Widget|array $widget Widget.
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @param bool $resolve Whether lazy Panel values should be resolved.
	 * @return array.
	 */
	public function widgetManifest(Widget|array $widget, ?PanelRequest $request=null, array $meta=[], bool $resolve=false): array {
		return WidgetManifest::from($widget, $request, $meta, $resolve)->toArray();
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @return Widget.
	 */
	public function pageWidget(string $name, string $type='stat'): Widget {
		return $this->widget($name, $type);
	}

	/**
	 * Delegates the `stat` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @param mixed $value Value stored in the field, resource, or manifest metadata.
	 * @return Widget.
	 */
	public function stat(string $name, mixed $value=null): Widget {
		return Widget::make($name)->value($value);
	}

	/**
	 * Delegates the `notify` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $message Notification or diagnostic message text.
	 * @param string $type Panel component, asset, plugin, or notification type.
	 * @param ?string $title Title.
	 * @return PanelNotification.
	 */
	public function notify(string $message, string $type='info', ?string $title=null): PanelNotification {
		return PanelNotification::make($message, $type, $title);
	}

	/**
	 * Delegates the `register` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Resource|array $resource Resource.
	 * @return Resource.
	 */
	public function register(Resource|array $resource): Resource {
		return $this->within(fn(): Resource => $this->manager->register($resource));
	}

	/**
	 * Delegates the `register many` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, Resource|array<string, mixed>> $resources Resource definitions.
	 * @return array.
	 */
	public function registerMany(array $resources): array {
		return $this->within(fn(): array => $this->manager->registerMany($resources));
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param PanelPage|array $page Page.
	 * @return PanelPage.
	 */
	public function registerPage(PanelPage|array $page): PanelPage {
		return $this->within(fn(): PanelPage => $this->manager->registerPage($page));
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param array<int, PanelPage|array<string, mixed>> $pages Page definitions.
	 * @return array.
	 */
	public function registerPages(array $pages): array {
		return $this->within(fn(): array => $this->manager->registerPages($pages));
	}

	/**
	 * Delegates the `register widget` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Widget|array $widget Widget.
	 * @return Widget.
	 */
	public function registerWidget(Widget|array $widget): Widget {
		return $this->within(fn(): Widget => $this->manager->registerWidget($widget));
	}

	/**
	 * Delegates the `register widgets` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, Widget|array<string, mixed>> $widgets Widget definitions.
	 * @return array.
	 */
	public function registerWidgets(array $widgets): array {
		return $this->within(fn(): array => $this->manager->registerWidgets($widgets));
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param NavigationItem|array $item Item.
	 * @return NavigationItem.
	 */
	public function registerNavigationItem(NavigationItem|array $item): NavigationItem {
		return $this->within(fn(): NavigationItem => $this->manager->registerNavigationItem($item));
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param array<int, NavigationItem|array<string, mixed>> $items Navigation item definitions.
	 * @return array.
	 */
	public function registerNavigationItems(array $items): array {
		return $this->within(fn(): array => $this->manager->registerNavigationItems($items));
	}

	/**
	 * Delegates the `register command` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelCommand|array $command Command.
	 * @return PanelCommand.
	 */
	public function registerCommand(PanelCommand|array $command): PanelCommand {
		return $this->within(fn(): PanelCommand => $this->manager->registerCommand($command));
	}

	/**
	 * Registers multiple command definitions on this Panel surface.
	 *
	 * Commands are added through the scoped manager so their shortcuts, metadata, and handlers attach to the current panel.
	 *
	 * @param array<int, PanelCommand|array<string, mixed>> $commands Command definitions.
	 * @return array.
	 */
	public function registerCommands(array $commands): array {
		return $this->within(fn(): array => $this->manager->registerCommands($commands));
	}

	/**
	 * Installs the authorization callback for this Panel surface.
	 *
	 * The callback is stored on the manager and consulted by panel requests before protected actions are exposed.
	 *
	 * @param callable $authorizer Callback that decides whether the current request may access the panel.
	 * @return self.
	 */
	public function authorize(callable $authorizer): self {
		$this->manager->authorize($authorizer);
		return $this;
	}

	/**
	 * Enables Access-module authentication integration for this Panel surface.
	 *
	 * A false option disables integration; array options are passed to the Access PanelAuth adapter when available.
	 *
	 * @param array|bool $options Additional options for the operation.
	 * @return self.
	 */
	public function accessAuth(array|bool $options=true): self {
		if($options===false){
			return $this->config('access_auth', false);
		}
		$loaded=class_exists('\Dataphyre\Access\PanelAuth');
		if(!$loaded && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('access')===true){
			$loaded=class_exists('\Dataphyre\Access\PanelAuth');
		}
		if(!$loaded){
			throw new \RuntimeException('Dataphyre Access framework is required for Panel auth.');
		}
		return \Dataphyre\Access\PanelAuth::register($this, is_array($options) ? $options : []);
	}

	/**
	 * Delegates the `auth` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array|bool $options Additional options for the operation.
	 * @return self.
	 */
	public function auth(array|bool $options=true): self {
		return $this->accessAuth($options);
	}

	/**
	 * Delegates the `access permissions` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array|bool $options Additional options for the operation.
	 * @return self.
	 */
	public function accessPermissions(array|bool $options=true): self {
		if($options===false){
			return $this->config('permission', false);
		}
		$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
		if(!$loaded && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('permission')===true){
			$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
		}
		if(!$loaded){
			throw new \RuntimeException('Dataphyre Permission framework is required for Panel permissions.');
		}
		return \Dataphyre\Permission\PermissionPanel::register($this, is_array($options) ? $options : []);
	}

	/**
	 * Delegates the `permissions` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array|bool $options Additional options for the operation.
	 * @return self.
	 */
	public function permissions(array|bool $options=true): self {
		return $this->accessPermissions($options);
	}

	/**
	 * Delegates the `permission admin` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array|bool $options Additional options for the operation.
	 * @return self.
	 */
	public function permissionAdmin(array|bool $options=true): self {
		if($options===false){
			return $this;
		}
		$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
		if(!$loaded && class_exists('\dataphyre\core') && \dataphyre\core::load_framework_module('permission')===true){
			$loaded=class_exists('\Dataphyre\Permission\PermissionPanel');
		}
		if(!$loaded){
			throw new \RuntimeException('Dataphyre Permission framework is required for Panel permission admin resources.');
		}
		return \Dataphyre\Permission\PermissionPanel::registerAdminResources($this, is_array($options) ? $options : []);
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param PanelProvider|callable|string $provider Provider.
	 * @return self.
	 */
	public function provide(PanelProvider|callable|string $provider): self {
		if(is_string($provider)){
			$provider=trim($provider);
			if($provider==='' || !class_exists($provider)){
				throw new \InvalidArgumentException('Panel provider class not found.');
			}
			$provider=new $provider();
		}
		if(!$provider instanceof PanelProvider && !is_callable($provider)){
			throw new \InvalidArgumentException('Panel providers must implement PanelProvider or be callable.');
		}
		return $this->within(function() use ($provider): self {
			if($provider instanceof PanelProvider){
				$result=$provider->panel($this);
			}
			else {
				$result=$provider($this);
			}
			if($result!==null && !$result instanceof self){
				throw new \UnexpectedValueException('Panel providers must return the panel instance or null.');
			}
			return $this;
		});
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param array<int, PanelProvider|callable|string> $providers Provider definitions.
	 * @return self.
	 */
	public function provideMany(array $providers): self {
		foreach($providers as $provider){
			if($provider instanceof PanelProvider || is_callable($provider) || is_string($provider)){
				$this->provide($provider);
			}
		}
		return $this;
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param PanelPlugin|string $plugin Plugin.
	 * @param array<string, mixed> $config Plugin configuration overrides.
	 * @return self.
	 */
	public function plugin(PanelPlugin|string $plugin, array $config=[]): self {
		$plugin=$this->resolvePlugin($plugin);
		$id=Resource::normalizeName($plugin->id());
		if($id===''){
			throw new \InvalidArgumentException('Panel plugin id cannot be empty.');
		}
		if(isset($this->plugins[$id])){
			$this->pluginConfig[$id]=array_replace($this->pluginConfig[$id] ?? [], $config);
			$this->syncPluginConfig();
			return $this;
		}
		$this->plugins[$id]=$plugin;
		$this->pluginConfig[$id]=$config;
		$this->pluginDescriptions[$id]=$this->describePlugin($plugin, $config);
		$this->syncPluginConfig();
		return $this->within(function() use ($plugin): self {
			$plugin->register($this);
			$plugin->boot($this);
			return $this;
		});
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param array<int|string, PanelPlugin|array<string, mixed>|string> $plugins Plugin definitions or keyed plugin configuration.
	 * @return self.
	 */
	public function plugins(array $plugins): self {
		foreach($plugins as $key=>$plugin){
			$config=[];
			if(is_array($plugin) && isset($plugin['plugin'])){
				$config=is_array($plugin['config'] ?? null) ? $plugin['config'] : [];
				$plugin=$plugin['plugin'];
			}
			elseif(is_string($key) && is_array($plugin)){
				$config=$plugin;
				$plugin=$key;
			}
			if($plugin instanceof PanelPlugin || is_string($plugin)){
				$this->plugin($plugin, $config);
			}
		}
		return $this;
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param string $id Panel element, action, or package identifier.
	 * @return bool.
	 */
	public function hasPlugin(string $id): bool {
		return isset($this->plugins[Resource::normalizeName($id)]);
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param ?string $id Id.
	 * @return array.
	 */
	public function pluginConfig(?string $id=null): array {
		if($id===null){
			return $this->pluginConfig;
		}
		return $this->pluginConfig[Resource::normalizeName($id)] ?? [];
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 * @return array.
	 */
	public function pluginIds(): array {
		return array_keys($this->plugins);
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param PanelPlugin|array|string $plugin Plugin.
	 * @param array<string, mixed> $config Plugin configuration overrides.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function pluginManifest(PanelPlugin|array|string $plugin, array $config=[], array $meta=[]): array {
		if(is_string($plugin)){
			$id=Resource::normalizeName($plugin);
			$plugin=$this->plugins[$id] ?? ($this->pluginDescriptions[$id] ?? $plugin);
			$config=$config!==[] ? $config : ($this->pluginConfig[$id] ?? []);
		}
		return PluginManifest::from($plugin, $config, $meta)->toArray();
	}

	/**
	 * Configures providers, plugins, or render hooks on this Panel surface.
	 *
	 * Plugins and hooks extend the surface before manifests are rendered or routes are mounted.
	 *
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function pluginManifests(array $meta=[]): array {
		$manifests=[];
		foreach($this->plugins as $id=>$plugin){
			$manifest=$this->pluginManifest($plugin, $this->pluginConfig[$id] ?? [], $meta);
			$manifests[(string)($manifest['id'] ?? $id)]=$manifest;
		}
		return $manifests;
	}

	/**
	 * Delegates the `package manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelPlugin|PanelPackageManifest|array|string $package Package.
	 * @param array<string, mixed> $config Package configuration overrides.
	 * @return PanelPackageManifest.
	 */
	public function packageManifest(PanelPlugin|PanelPackageManifest|array|string $package, array $config=[]): PanelPackageManifest {
		return PanelPackageManifest::from($package, $config);
	}

	/**
	 * Delegates the `compatibility matrix` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array<string, mixed>|string> $packages Package definitions.
	 * @param array<string, mixed> $runtime Runtime capability and version metadata.
	 * @return PanelCompatibilityMatrix.
	 */
	public function compatibilityMatrix(array $packages=[], array $runtime=[]): PanelCompatibilityMatrix {
		return PanelCompatibilityMatrix::make($packages, $runtime);
	}

	/**
	 * Delegates the `package template` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelPackageManifest|array|string $package Package.
	 * @param string $label Human-facing Panel label.
	 * @return PanelPackageTemplate.
	 */
	public function packageTemplate(PanelPackageManifest|array|string $package, string $label=''): PanelPackageTemplate {
		return PanelPackageTemplate::make($package, $label);
	}

	/**
	 * Delegates the `package repository` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<int, PanelPlugin|PanelPackageManifest|array<string, mixed>|string> $packages Package definitions.
	 * @param array<string, mixed> $runtime Runtime capability and version metadata.
	 * @return PanelPackageRepository.
	 */
	public function packageRepository(array $packages=[], array $runtime=[]): PanelPackageRepository {
		$repository=PanelPackageRepository::make($packages, $runtime);
		foreach($this->plugins as $id=>$plugin){
			$repository->register($plugin, $this->pluginConfig[$id] ?? [], 'registered_plugin:'.$id);
		}
		return $repository;
	}

	/**
	 * Delegates the `package trust policy` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param array<string, mixed> $policy Package trust policy options.
	 * @return PanelPackageTrustPolicy.
	 */
	public function packageTrustPolicy(array $policy=[]): PanelPackageTrustPolicy {
		return PanelPackageTrustPolicy::make($policy);
	}

	/**
	 * Delegates the `package install plan` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelPackageTemplate $template Template.
	 * @param string $targetPath Filesystem or route target path used by Panel tooling.
	 * @param array<string, mixed> $options Package install planning options.
	 * @return PanelPackageInstallPlan.
	 */
	public function packageInstallPlan(PanelPackageTemplate $template, string $targetPath='', array $options=[]): PanelPackageInstallPlan {
		return PanelPackageInstallPlan::make($template, $targetPath, $options);
	}

	/**
	 * Delegates the `package rollback plan` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan InstallPlan.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return PanelPackageRollbackPlan.
	 */
	public function packageRollbackPlan(PanelPackageInstallPlan|PanelPackageApplyResult|array $installPlan, array $meta=[]): PanelPackageRollbackPlan {
		return PanelPackageRollbackPlan::make($installPlan, $meta);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 * @return array.
	 */
	public function resources(): array {
		return $this->manager->resources();
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 * @return array.
	 */
	public function pages(): array {
		return $this->manager->pages();
	}

	/**
	 * Delegates the `widgets` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @return array.
	 */
	public function widgets(?PanelRequest $request=null): array {
		return $this->within(fn(): array => $this->manager->widgets($request));
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 * @return array.
	 */
	public function navigationItems(): array {
		return $this->manager->navigationItems();
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param ?PanelRequest $request Request.
	 * @return array.
	 */
	public function navigation(?PanelRequest $request=null): array {
		return $this->within(fn(): array => $this->manager->navigation($request));
	}

	/**
	 * Configures shell layout and navigation behavior for this surface.
	 *
	 * Renderer options control navigation mode, responsive shell behavior, sticky regions, content spacing, and command placement.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $search Navigation search state.
	 * @return PanelNavigationState.
	 */
	public function navigationState(?PanelRequest $request=null, array $search=[]): PanelNavigationState {
		return $this->within(fn(): PanelNavigationState => $this->manager->navigationState($request, $search));
	}

	/**
	 * Delegates the `registered commands` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 * @return array.
	 */
	public function registeredCommands(): array {
		return $this->manager->registeredCommands();
	}

	/**
	 * Delegates the `commands` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @return array.
	 */
	public function commands(?PanelRequest $request=null, ?string $query=null): array {
		return $this->within(fn(): array => $this->manager->commands($request, $query));
	}

	/**
	 * Delegates the `command state` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @return PanelCommandState.
	 */
	public function commandState(?PanelRequest $request=null, ?string $query=null): PanelCommandState {
		return $this->within(fn(): PanelCommandState => $this->manager->commandState($request, $query));
	}

	/**
	 * Delegates the `global search` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $query Search query text supplied by Panel.
	 * @param ?PanelRequest $request Request.
	 * @param int $limit Maximum number of Panel results to return.
	 * @return array.
	 */
	public function globalSearch(string $query, ?PanelRequest $request=null, int $limit=12): array {
		return $this->within(fn(): array => $this->manager->globalSearch($query, $request ?? PanelRequest::fromArray([]), $limit));
	}

	/**
	 * Delegates the `search manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param ?string $query Query.
	 * @param int $limit Maximum number of Panel results to return.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function searchManifest(?PanelRequest $request=null, ?string $query=null, int $limit=12, array $meta=[]): array {
		return $this->within(fn(): array => SearchManifest::from($this, $request, $query, $limit, $meta)->toArray());
	}

	/**
	 * Delegates the `tenant manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function tenantManifest(?PanelRequest $request=null, array $meta=[]): array {
		return $this->within(fn(): array => TenantManifest::from($this, $request, $meta)->toArray());
	}

	/**
	 * Delegates the `get` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return ?Resource.
	 */
	public function get(string $name): ?Resource {
		return $this->manager->get($name);
	}

	/**
	 * Registers or resolves Panel resources and pages on this surface.
	 *
	 * Surface manifests combine resources and pages into navigation, routing, rendering, and action metadata.
	 *
	 * @param string $name Normalized field, resource, surface, or helper name.
	 * @return ?PanelPage.
	 */
	public function getPage(string $name): ?PanelPage {
		return $this->manager->getPage($name);
	}

	/**
	 * Delegates the `describe` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 * @return array.
	 */
	public function describe(): array {
		return $this->within(function(): array {
			$description=$this->manager->describe();
			$description['plugins']=array_values($this->pluginDescriptions);
			return $description;
		});
	}

	/**
	 * Delegates the `panel manifest` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param ?PanelRequest $request Request.
	 * @param array<string, mixed> $meta Metadata merged into the manifest payload.
	 * @return array.
	 */
	public function panelManifest(?PanelRequest $request=null, array $meta=[]): array {
		return $this->within(fn(): array => PanelManifest::from($this, $request, $meta)->toArray());
	}

	/**
	 * Delegates the `dispatch` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param PanelRequest|array|null $request Request.
	 * @return PanelPageResult.
	 */
	public function dispatch(PanelRequest|array|null $request=null): PanelPageResult {
		return $this->within(fn(): PanelPageResult => $this->manager->dispatch($request));
	}

	/**
	 * Delegates the `render` helper through this Panel surface.
	 *
	 * The call runs in the scoped panel context so manager state, configuration, and helper factories stay bound to this instance.
	 *
	 * @param Resource|string|null $resource Resource.
	 * @param string $operation Panel operation name such as create, edit, view, or delete.
	 * @param array<string, mixed> $context Render context passed to the panel manager.
	 * @return PanelPageResult.
	 */
	public function render(Resource|string|null $resource=null, string $operation='index', array $context=[]): PanelPageResult {
		return $this->within(fn(): PanelPageResult => $this->manager->render($resource, $operation, $context));
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param string $target Panel navigation, action, or dispatch target.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated panel URL.
	 * @return string.
	 */
	public function url(string $target='', array $query=[]): string {
		return $this->within(fn(): string => PanelConfig::url($target, $query));
	}

	/**
	 * Configures Panel routing and URL generation.
	 *
	 * Route helpers generate MVC-compatible endpoints for pages, assets, uploads, and resource actions on this surface.
	 *
	 * @param Resource|string $resource Resource.
	 * @param string $path Panel asset, route, or filesystem path.
	 * @param array<string, mixed> $query Query-string parameters appended to the generated resource URL.
	 * @return string.
	 */
	public function resourceUrl(Resource|string $resource, string $path='', array $query=[]): string {
		return $this->within(fn(): string => PanelConfig::resourceUrl($resource, $path, $query));
	}

	/**
	 * Runs a callback inside this surface's Panel context.
	 *
	 * Surface configuration and the owning manager are installed for the
	 * duration of the callback so static helpers such as `PanelConfig` resolve
	 * URLs, resources, locale state, and plugins against the correct panel.
	 *
	 * @param callable $callback Work to execute within the panel context.
	 *
	 * @return mixed value returned by the callback while this panel configuration is active.
	 */
	private function within(callable $callback): mixed {
		return PanelContext::run(array_replace($this->config, ['__panel_manager'=>$this->manager]), $callback);
	}

	/**
	 * Resolves a plugin instance from an object or class name.
	 *
	 * Existing plugin instances are returned unchanged. Class names are
	 * instantiated and validated against the `PanelPlugin` contract before they
	 * can mutate surface configuration or contribute resources.
	 *
	 * @param PanelPlugin|string $plugin Plugin instance or loadable class name.
	 *
	 * @throws \InvalidArgumentException when the class is missing or invalid.
	 *
	 * @return PanelPlugin Resolved plugin instance.
	 */
	private function resolvePlugin(PanelPlugin|string $plugin): PanelPlugin {
		if($plugin instanceof PanelPlugin){
			return $plugin;
		}
		$class=trim($plugin);
		if($class==='' || !class_exists($class)){
			throw new \InvalidArgumentException('Panel plugin class not found.');
		}
		$instance=new $class();
		if(!$instance instanceof PanelPlugin){
			throw new \InvalidArgumentException('Panel plugins must implement PanelPlugin.');
		}
		return $instance;
	}

	/**
	 * Mirrors plugin registry state into the surface configuration array.
	 *
	 * Renderer, route, and command helpers read plugin ids and plugin-specific
	 * configuration from `$this->config`, so every plugin mutation calls this
	 * method to keep the public surface state consistent with the private maps.
	 *
	 * @return void
	 */
	private function syncPluginConfig(): void {
		$this->config['plugin_config']=$this->pluginConfig;
		$this->config['plugin_ids']=array_keys($this->plugins);
	}

	/**
	 * Serializes a safe subset of HTML attributes for Panel-generated markup.
	 *
	 * Only `class`, `id`, `role`, `aria-*`, and `data-*` attributes are emitted,
	 * and both names and scalar values are escaped. This keeps helper-generated
	 * controls extensible without letting arbitrary event handlers or unsafe
	 * attributes leak into rendered admin UI.
	 *
	 * @param array<string,mixed> $attributes Candidate attributes.
	 *
	 * @return string Escaped leading-space-prefixed attribute string.
	 */
	private static function htmlAttributes(array $attributes): string {
		$html='';
		foreach($attributes as $name=>$value){
			if(!is_string($name) || $value===false || $value===null){
				continue;
			}
			$name=strtolower(trim($name));
			if(preg_match('/^(class|id|role|aria-[a-z0-9_.:-]+|data-[a-z0-9_.:-]+)$/', $name)!==1){
				continue;
			}
			if($value===true){
				$html.=' '.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				continue;
			}
			if(is_scalar($value) || $value instanceof \Stringable){
				$html.=' '.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'="'.htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
			}
		}
		return $html;
	}

	/**
	 * Builds the manifest description stored for an installed plugin.
	 *
	 * The description captures stable plugin identity, implementation class,
	 * supplied configuration keys, and optional metadata exposed by conventional
	 * `label`, `version`, and `description` methods.
	 *
	 * @param PanelPlugin $plugin Installed plugin.
	 * @param array<string,mixed> $config Configuration passed to the plugin.
	 *
	 * @return array<string,mixed> Plugin manifest data for this surface.
	 */
	private function describePlugin(PanelPlugin $plugin, array $config): array {
		$id=Resource::normalizeName($plugin->id());
		$description=[
			'id'=>$id,
			'class'=>$plugin::class,
			'config_keys'=>array_keys($config),
		];
		foreach(['label', 'version', 'description'] as $method){
			if(method_exists($plugin, $method)){
				$value=$plugin->{$method}();
				if(is_scalar($value) && trim((string)$value)!==''){
					$description[$method]=trim((string)$value);
				}
			}
		}
		return $description;
	}
}
