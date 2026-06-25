<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Declarative Panel page definition for navigation, content, and page tools.
 *
 * A page owns navigation metadata, authorization, render callbacks, actions,
 * forms, widgets, tables, page manifests, and navigation entries consumed by
 * the Panel manager.
 */
final class PanelPage {
	use PanelExtensible;

	private string $name;
	private string $label;
	private ?string $url=null;
	private ?string $group=null;
	private ?string $navigationParent=null;
	private ?string $icon=null;
	private int $sort=100;
	private bool $hiddenFromNavigation=false;
	private ?string $navigationDescription=null;
	private mixed $navigationBadge=null;
	private ?\Closure $navigationBadgeResolver=null;
	private string $navigationBadgeTone='neutral';
	private mixed $content='';
	private ?\Closure $renderer=null;
	private ?\Closure $authorizer=null;
	/** @var array<string, Action|ActionGroup> */
	private array $actions=[];
	/** @var array<string, Widget> */
	private array $widgets=[];
	/** @var array<string, PageTable> */
	private array $tables=[];
	/** @var array<string, array<string, mixed>> */
	private array $forms=[];
	private array $meta=[];

	/**
	 * Creates a page with normalized identity and default human label.
	 *
	 * Construction is private so pages enter through make()/fromArray(),
	 * ensuring names follow Resource normalization and labels begin from a stable
	 * humanized form before fluent overrides are applied.
	 *
	 * @param string $name Raw page name.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a page definition from a raw page name.
	 *
	 * The name is normalized by the private constructor, then global Panel
	 * extension callbacks can apply shared defaults before the page is returned.
	 *
	 * @param string $name Raw page name normalized for routing, manifests, and navigation.
	 * @return self Page definition after global Panel extension defaults run.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Creates a page definition from manifest-style array data.
	 *
	 * Only recognized keys are applied. Nested actions, widgets, tables, forms, and
	 * metadata are delegated to their fluent helpers so array and code definitions
	 * share the same normalization rules.
	 *
	 * @param array<string,mixed> $definition Array definition used to hydrate a page/action/widget/table object.
	 * @return self Page definition hydrated from the recognized array keys.
	 */
	public static function fromArray(array $definition): self {
		$page=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label'])){
			$page=$page->label((string)$definition['label']);
		}
		foreach(['url', 'group', 'icon'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$page=$page->{$key}($definition[$key]);
			}
		}
		if(isset($definition['navigation_parent']) && is_string($definition['navigation_parent'])){
			$page=$page->navigationParent($definition['navigation_parent']);
		}
		if(isset($definition['folder']) && is_string($definition['folder'])){
			$page=$page->navigationParent($definition['folder']);
		}
		if(isset($definition['navigation_description']) && is_string($definition['navigation_description'])){
			$page=$page->navigationDescription($definition['navigation_description']);
		}
		if(array_key_exists('navigation_badge', $definition)){
			$page=$page->navigationBadge($definition['navigation_badge']);
		}
		if(isset($definition['navigation_badge_tone']) && is_string($definition['navigation_badge_tone'])){
			$page=$page->navigationBadgeTone($definition['navigation_badge_tone']);
		}
		if(isset($definition['sort'])){
			$page=$page->sort((int)$definition['sort']);
		}
		if(!empty($definition['hidden_from_navigation'])){
			$page=$page->hideFromNavigation();
		}
		if(array_key_exists('content', $definition)){
			$page=$page->content($definition['content']);
		}
		if(isset($definition['actions']) && is_array($definition['actions'])){
			$page=$page->actions($definition['actions']);
		}
		if(isset($definition['widgets']) && is_array($definition['widgets'])){
			$page=$page->widgets($definition['widgets']);
		}
		if(isset($definition['tables']) && is_array($definition['tables'])){
			$page=$page->tables($definition['tables']);
		}
		if(isset($definition['forms']) && is_array($definition['forms'])){
			$page=$page->forms($definition['forms']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$page=$page->meta($definition['meta']);
		}
		return $page;
	}

	/**
	 * Returns the normalized page identifier used for routes and manifests.
	 *
	 * The name is normalized at construction time and remains stable across cloned
	 * configuration changes.
	 *
	 * @return string Normalized page name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Reads or replaces the human-readable page label.
	 *
	 * Passing null reads the current label. Passing a string returns a cloned page
	 * with the trimmed label and leaves the original definition untouched.
	 *
	 * @param ?string $label Human-readable panel page label.
	 * @return string|self Current label when reading, or a new page definition with the label applied.
	 */
	public function label(?string $label=null): string|self {
		if($label===null){
			return $this->label;
		}
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Sets an explicit URL for this page.
	 *
	 * The path is normalized to a leading slash and no trailing slash. When unset,
	 * navigation and manifests derive the URL from PanelConfig and the page name.
	 *
	 * @param string $url Explicit page URL.
	 * @return self Cloned page definition with the explicit URL applied.
	 */
	public function url(string $url): self {
		$clone=clone $this;
		$url='/'.trim($url, '/');
		$clone->url=$url;
		return $clone;
	}

	/**
	 * Assigns this page to a navigation group.
	 *
	 * Blank group names clear the group so the page is listed at the default
	 * navigation level.
	 *
	 * @param string $group Navigation group name.
	 * @return self Cloned page definition with the navigation group applied or cleared.
	 */
	public function group(string $group): self {
		$clone=clone $this;
		$clone->group=trim($group) ?: null;
		return $clone;
	}

	/**
	 * Places this page under a navigation parent item.
	 *
	 * NavigationItem parents contribute their normalized name. String parents are
	 * normalized through Resource rules, and blank values clear the parent link.
	 *
	 * @param string|NavigationItem|null $parent Navigation parent item or normalized parent key.
	 * @return self Cloned page definition with the parent navigation key applied or cleared.
	 */
	public function navigationParent(string|NavigationItem|null $parent): self {
		$clone=clone $this;
		$clone->navigationParent=$parent instanceof NavigationItem ? $parent->name() : (is_string($parent) ? Resource::normalizeName($parent) : null);
		$clone->navigationParent=$clone->navigationParent!=='' ? $clone->navigationParent : null;
		return $clone;
	}

	/**
	 * Alias for navigationParent() kept for folder-style page definitions.
	 *
	 * The same normalization and blank-value clearing rules apply.
	 *
	 * @param string|NavigationItem|null $parent Navigation parent item or normalized parent key.
	 * @return self Cloned page definition with the folder-style parent applied.
	 */
	public function folder(string|NavigationItem|null $parent): self {
		return $this->navigationParent($parent);
	}

	/**
	 * Sets the icon name exposed to navigation renderers.
	 *
	 * Blank icon names clear the override. Renderers fall back to the standard file
	 * icon when no icon is configured.
	 *
	 * @param string $icon Navigation icon name.
	 * @return self Cloned page definition with the navigation icon applied or cleared.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Sets the navigation sort order for this page.
	 *
	 * Lower values appear earlier when navigation entries are sorted by managers or
	 * renderers.
	 *
	 * @param int $sort Navigation sort order.
	 * @return self Cloned page definition with the sort order applied.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Marks whether this page should be omitted from navigation lists.
	 *
	 * Hidden pages can still be addressed directly when a route or manager exposes
	 * them.
	 *
	 * @param bool $hidden Whether the page is omitted from navigation.
	 * @return self Cloned page definition with navigation visibility updated.
	 */
	public function hideFromNavigation(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hiddenFromNavigation=$hidden;
		return $clone;
	}

	/**
	 * Sets the short description shown on navigation cards.
	 *
	 * Blank descriptions clear the value. Escaping is owned by the renderer that
	 * inserts this text into HTML.
	 *
	 * @param string $description Navigation description text.
	 * @return self Cloned page definition with the navigation description applied or cleared.
	 */
	public function navigationDescription(string $description): self {
		$clone=clone $this;
		$clone->navigationDescription=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Sets a static or lazy navigation badge.
	 *
	 * Callable badges are stored as resolvers and evaluated by navigationEntry()
	 * with the current request, page, and manager. Static values are serialized as
	 * configured.
	 *
	 * @param mixed $badge Static or computed navigation badge value.
	 * @return self Cloned page definition with the static badge or badge resolver applied.
	 */
	public function navigationBadge(mixed $badge): self {
		$clone=clone $this;
		if(is_callable($badge)){
			$clone->navigationBadgeResolver=\Closure::fromCallable($badge);
			$clone->navigationBadge=null;
			return $clone;
		}
		$clone->navigationBadge=$badge;
		$clone->navigationBadgeResolver=null;
		return $clone;
	}

	/**
	 * Sets the resolver used for lazy navigation badges.
	 *
	 * Resolver exceptions are caught during navigation entry creation, traced, and
	 * converted to a null badge so navigation can still render.
	 *
	 * @param callable $resolver Callable invoked for lookup, render, authorization, or scoped override execution.
	 * @return self Cloned page definition with the lazy badge resolver applied.
	 */
	public function navigationBadgeUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->navigationBadgeResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Sets the visual tone for navigation badges.
	 *
	 * Unknown tones are normalized to neutral so renderers receive a bounded style
	 * token.
	 *
	 * @param string $tone Badge tone name.
	 * @return self Cloned page definition with a bounded badge tone.
	 */
	public function navigationBadgeTone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->navigationBadgeTone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Sets static content or a render callback for this page.
	 *
	 * Callable content becomes the page renderer and clears the static content
	 * field. Non-callable content is stored as-is and later returned by render().
	 *
	 * @param mixed $content Static content, view data, or renderer input for the page.
	 * @return self Cloned page definition with static content or a renderer callback applied.
	 */
	public function content(mixed $content): self {
		$clone=clone $this;
		if(is_callable($content)){
			$clone->renderer=\Closure::fromCallable($content);
			$clone->content='';
			return $clone;
		}
		$clone->content=$content;
		$clone->renderer=null;
		return $clone;
	}

	/**
	 * Sets the callback used to render page content.
	 *
	 * The callback is evaluated through PanelUtilityResolver with request, page, and
	 * manager context at render time.
	 *
	 * @param callable $renderer Callable invoked for lookup, render, authorization, or scoped override execution.
	 * @return self Cloned page definition with the renderer callback applied.
	 */
	public function renderUsing(callable $renderer): self {
		$clone=clone $this;
		$clone->renderer=\Closure::fromCallable($renderer);
		return $clone;
	}

	/**
	 * Sets the page-specific authorization callback.
	 *
	 * The callback is evaluated after the optional permission bridge. Returning a
	 * falsy value denies the requested ability for the current user/request context.
	 *
	 * @param callable $authorizer Callable invoked for lookup, render, authorization, or scoped override execution.
	 * @return self Cloned page definition with the authorizer callback applied.
	 */
	public function authorize(callable $authorizer): self {
		$clone=clone $this;
		$clone->authorizer=\Closure::fromCallable($authorizer);
		return $clone;
	}

	/**
	 * Appends multiple actions or action groups to the page.
	 *
	 * Each entry is normalized through action(), so strings, arrays, Action
	 * objects, and ActionGroup objects follow the same registration path.
	 *
	 * @param list<Action|ActionGroup|array<string,mixed>|string> $actions Definitions appended to the page manifest.
	 * @return self Cloned page definition with all supplied actions registered.
	 */
	public function actions(array $actions): self {
		$clone=clone $this;
		foreach($actions as $action){
			$clone=$clone->action($action);
		}
		return $clone;
	}

	/**
	 * Registers a single page action or action group.
	 *
	 * String actions are converted to Action objects, array definitions are
	 * hydrated as actions or groups, and entries with blank names are ignored.
	 *
	 * @param Action|ActionGroup|array|string $action Action definition, name, or object associated with the page.
	 * @return self Cloned page definition with the supplied action entry registered.
	 */
	public function action(Action|ActionGroup|array|string $action): self {
		$clone=clone $this;
		if(is_string($action)){
			$action=Action::make($action);
		}
		elseif(is_array($action)){
			$action=self::actionDefinition($action);
		}
		$name=$action->name();
		if($name!==''){
			$clone->actions[$name]=$action;
		}
		return $clone;
	}

	/**
	 * Registers an action group with optional child actions.
	 *
	 * Existing ActionGroup objects are used directly. Array definitions and string
	 * names are hydrated before the group is registered like any other action entry.
	 *
	 * @param ActionGroup|array|string $group Navigation group name.
	 * @param list<Action|array<string,mixed>|string> $actions Definitions appended to the page manifest.
	 * @return self Cloned page definition with the action group registered.
	 */
	public function actionGroup(ActionGroup|array|string $group, array $actions=[]): self {
		$group=$group instanceof ActionGroup ? $group : (is_array($group) ? ActionGroup::fromArray($group) : ActionGroup::make((string)$group)->actions($actions));
		return $this->action($group);
	}

	/**
	 * Returns registered page actions and action groups.
	 *
	 * The array is keyed by normalized action or group name in registration order.
	 *
	 * @return array<string, Action|ActionGroup> Registered page actions and action groups keyed by normalized action name.
	 */
	public function actionsList(): array {
		return $this->actions;
	}

	/**
	 * Finds an action by name across page actions and nested groups.
	 *
	 * The lookup normalizes the requested name and returns only concrete Action
	 * instances, not ActionGroup containers.
	 *
	 * @param string $name Action name to resolve from page actions or nested action groups.
	 * @return ?Action Resolved page action or action group object.
	 */
	public function actionByName(string $name): ?Action {
		$name=Resource::normalizeName($name);
		foreach($this->actions as $action){
			if($action instanceof Action && $action->name()===$name){
				return $action;
			}
			if($action instanceof ActionGroup && ($nested=$action->actionByName($name)) instanceof Action){
				return $nested;
			}
		}
		return null;
	}

	/**
	 * Appends multiple form scaffolds to the page.
	 *
	 * Definitions may be keyed by action name or include an action field. Each
	 * scaffold is normalized through form(), which also registers the backing action.
	 *
	 * @param list<array<string,mixed>> $forms Definitions appended to the page manifest.
	 * @return self Cloned page definition with all supplied form scaffolds registered.
	 */
	public function forms(array $forms): self {
		$clone=clone $this;
		foreach($forms as $key=>$form){
			$options=[];
			if(is_array($form) && isset($form['action'])){
				$options=$form;
				$form=$form['action'];
			}
			elseif(is_string($key) && is_array($form)){
				$options=$form;
				$form=$key;
			}
			$clone=$clone->form($form, $options);
		}
		return $clone;
	}

	/**
	 * Registers an embedded form scaffold for an action.
	 *
	 * The action is normalized, registered, and paired with renderer options such as
	 * placement, width, style, sort order, and action-button visibility.
	 *
	 * @param Action|array|string $action Action definition, name, or object associated with the page.
	 * @param array|string $options Placement, form, table, or render options.
	 * @return self Cloned page definition with the embedded form scaffold registered.
	 */
	public function form(Action|array|string $action, array|string $options=[]): self {
		$clone=clone $this;
		[$action, $options]=$clone->normalizeFormScaffold($action, $options, 'embedded');
		$name=$action->name();
		if($name!==''){
			$clone->actions[$name]=$action;
			$clone->forms[$name]=$options;
		}
		return $clone;
	}

	/**
	 * Alias for form() when the scaffold should remain embedded.
	 *
	 * The default placement remains embedded unless overridden in options.
	 *
	 * @param Action|array|string $action Action definition, name, or object associated with the page.
	 * @param array|string $options Placement, form, table, or render options.
	 * @return self Cloned page definition with the embedded form scaffold registered.
	 */
	public function embeddedForm(Action|array|string $action, array|string $options=[]): self {
		return $this->form($action, $options);
	}

	/**
	 * Registers an action scaffold rendered as its own page section.
	 *
	 * String options are treated as the form title. The placement option is forced
	 * to page before the scaffold is normalized.
	 *
	 * @param Action|array|string $action Action definition, name, or object associated with the page.
	 * @param array|string $options Placement, form, table, or render options.
	 * @return self Cloned page definition with the page-placement form scaffold registered.
	 */
	public function formPage(Action|array|string $action, array|string $options=[]): self {
		$options=is_string($options) ? ['title'=>$options] : $options;
		$options['placement']='page';
		return $this->form($action, $options);
	}

	/**
	 * Alias for formPage() used by primary-action page definitions.
	 *
	 * It preserves the same normalization and action registration behavior.
	 *
	 * @param Action|array|string $action Action definition, name, or object associated with the page.
	 * @param array|string $options Placement, form, table, or render options.
	 * @return self Cloned page definition with the primary form scaffold registered.
	 */
	public function primaryForm(Action|array|string $action, array|string $options=[]): self {
		return $this->formPage($action, $options);
	}

	/**
	 * Returns form scaffold definitions keyed by action name.
	 *
	 * These arrays are already normalized for placement, width, style, sort order,
	 * and action-button visibility.
	 *
	 * @return array<string, array<string,mixed>> Form scaffold definitions keyed by normalized form/action name.
	 */
	public function formsList(): array {
		return $this->forms;
	}

	/**
	 * Checks whether a form should expose its action button.
	 *
	 * Unknown form names default to true so standalone actions remain visible unless
	 * a form scaffold explicitly suppresses its button.
	 *
	 * @param string $name Form or action name whose action button visibility is being checked.
	 * @return bool True when the named form should expose its action button.
	 */
	public function shouldShowActionButton(string $name): bool {
		$name=Resource::normalizeName($name);
		if(!isset($this->forms[$name])){
			return true;
		}
		return ($this->forms[$name]['show_action'] ?? false)===true;
	}

	/**
	 * Appends multiple widgets to the page.
	 *
	 * Each entry is normalized through widget(), so strings, arrays, and Widget
	 * objects share the same registration rules.
	 *
	 * @param list<Widget|array<string,mixed>|string> $widgets Definitions appended to the page manifest.
	 * @return self Cloned page definition with all supplied widgets registered.
	 */
	public function widgets(array $widgets): self {
		$clone=clone $this;
		foreach($widgets as $widget){
			$clone=$clone->widget($widget);
		}
		return $clone;
	}

	/**
	 * Registers a single widget on the page.
	 *
	 * String widgets use the supplied default type, array definitions are hydrated
	 * through Widget::fromArray(), and blank widget names are ignored.
	 *
	 * @param Widget|array|string $widget Widget.
	 * @param string $type Locale definition type or panel/action type.
	 * @return self Cloned page definition with the supplied widget registered.
	 */
	public function widget(Widget|array|string $widget, string $type='stat'): self {
		$clone=clone $this;
		if(is_string($widget)){
			$widget=Widget::make($widget, $type);
		}
		elseif(is_array($widget)){
			$widget=Widget::fromArray($widget);
		}
		$name=$widget->name();
		if($name!==''){
			$clone->widgets[$name]=$widget;
		}
		return $clone;
	}

	/**
	 * Returns registered widgets keyed by normalized name.
	 *
	 * The objects are returned without resolving request-specific state.
	 *
	 * @return array<string, Widget> Registered page widgets keyed by normalized widget name.
	 */
	public function widgetsList(): array {
		return $this->widgets;
	}

	/**
	 * Resolves widget state for the current page/request context.
	 *
	 * Each widget receives page scope metadata, then the resulting states are sorted
	 * by widget sort value and label. A trace entry records the resolved state list
	 * for Panel diagnostics.
	 *
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @return list<PanelWidgetState> Sorted widget state objects for this page.
	 */
	public function widgetStates(?PanelRequest $request=null): array {
		$states=array_map(fn(Widget $widget): PanelWidgetState => $widget->state($request, [
			'scope'=>'page',
			'page'=>$this->name,
		]), array_values($this->widgets));
		usort($states, static function(PanelWidgetState $left, PanelWidgetState $right): int {
			$leftWidget=$left->widget();
			$rightWidget=$right->widget();
			return [(int)($leftWidget['sort'] ?? 100), (string)($leftWidget['label'] ?? '')] <=> [(int)($rightWidget['sort'] ?? 100), (string)($rightWidget['label'] ?? '')];
		});
		PanelTrace::record('widgets.state', [
			'scope'=>'page',
			'page'=>$this->name,
			'count'=>count($states),
			'widgets'=>$states,
		]);
		return $states;
	}

	/**
	 * Serializes resolved widget states for renderer manifests.
	 *
	 * The returned arrays come from PanelWidgetState::jsonSerialize() after the
	 * request-specific state has been sorted and traced.
	 *
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @return list<array<string,mixed>> Serialized widget state arrays for renderer manifests.
	 */
	public function resolvedWidgets(?PanelRequest $request=null): array {
		return array_map(static fn(PanelWidgetState $state): array => $state->jsonSerialize(), $this->widgetStates($request));
	}

	/**
	 * Appends multiple page tables.
	 *
	 * Each table definition is normalized through table(), preserving consistent
	 * handling for strings, arrays, and PageTable instances.
	 *
	 * @param list<PageTable|array<string,mixed>|string> $tables Definitions appended to the page manifest.
	 * @return self Cloned page definition with all supplied page tables registered.
	 */
	public function tables(array $tables): self {
		$clone=clone $this;
		foreach($tables as $table){
			$clone=$clone->table($table);
		}
		return $clone;
	}

	/**
	 * Registers a single page table.
	 *
	 * String values become PageTable instances, arrays are hydrated through
	 * PageTable::fromArray(), and blank table names are ignored.
	 *
	 * @param PageTable|array|string $table Table.
	 * @return self Cloned page definition with the supplied page table registered.
	 */
	public function table(PageTable|array|string $table): self {
		$clone=clone $this;
		if(is_string($table)){
			$table=PageTable::make($table);
		}
		elseif(is_array($table)){
			$table=PageTable::fromArray($table);
		}
		$name=$table->name();
		if($name!==''){
			$clone->tables[$name]=$table;
		}
		return $clone;
	}

	/**
	 * Returns registered page tables keyed by normalized name.
	 *
	 * The table objects are returned without resolving request filters, views,
	 * records, or summaries.
	 *
	 * @return array<string, PageTable> Registered page tables keyed by normalized table name.
	 */
	public function tablesList(): array {
		return $this->tables;
	}

	/**
	 * Builds the complete renderer manifest for this page.
	 *
	 * PageManifest owns the final manifest shape and combines request state,
	 * manager policy, and caller-supplied metadata with this page definition.
	 *
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @param ?PanelManager $manager Panel manager supplying runtime policy and registries.
	 * @param array<string,mixed> $meta Extra page manifest metadata.
	 * @return array<string,mixed> Complete page manifest consumed by Panel renderers.
	 */
	public function pageManifest(?PanelRequest $request=null, ?PanelManager $manager=null, array $meta=[]): array {
		return PageManifest::from($this, $request, $manager, $meta)->toArray();
	}

	/**
	 * Resolves page tables into renderer-ready table entries.
	 *
	 * Tables are sorted by configured sort value and label. Each table receives a
	 * request adjusted to its resolved view, loads records, resolves summaries, and
	 * returns both table metadata and runtime records for rendering.
	 *
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @return list<array<string,mixed>> Sorted table render entries with records, summaries, and request state.
	 */
	public function resolvedTables(?PanelRequest $request=null): array {
		$tables=array_values($this->tables);
		usort($tables, static function(PageTable $left, PageTable $right): int {
			$leftMeta=$left->toArray();
			$rightMeta=$right->toArray();
			return [(int)($leftMeta['sort'] ?? 100), (string)($leftMeta['label'] ?? '')] <=> [(int)($rightMeta['sort'] ?? 100), (string)($rightMeta['label'] ?? '')];
		});
		return array_map(function(PageTable $table) use ($request): array {
			$tableRequest=$request instanceof PanelRequest ? $table->requestWithResolvedView($request) : $request;
			$records=$table->resolvedRecords($tableRequest, $this);
			$summaryResource=Resource::make($table->name())->label((string)($table->toArray()['label'] ?? $table->name()));
			$summaries=[];
			foreach($table->summariesList() as $summary){
				$summaries[]=$summary->resolve($records, $summaryResource, $tableRequest instanceof PanelRequest ? $tableRequest : PanelRequest::fromArray([]));
			}
			return [
				'table'=>$table,
				'meta'=>$table->toArray(),
				'request'=>$tableRequest,
				'records'=>$records,
				'summaries'=>$summaries,
			];
		}, $tables);
	}

	/**
	 * Checks whether a user can perform a page ability.
	 *
	 * The optional permission bridge is checked first. If a page authorizer exists,
	 * it receives the ability, user, request, and page; otherwise permission-bridge
	 * success is enough to allow the ability.
	 *
	 * @param string $ability Authorization ability checked against page policy.
	 * @param mixed $user Optional user/principal supplied to authorization.
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @return bool True when page permission and authorizer callbacks allow the ability.
	 */
	public function can(string $ability, mixed $user=null, ?PanelRequest $request=null): bool {
		if($this->permissionAllows($ability, $user, $request)===false){
			return false;
		}
		if($this->authorizer!==null){
			return (bool)PanelUtilityResolver::evaluate($this->authorizer, [
				'ability'=>$ability,
				'user'=>$user,
				'authUser'=>$user,
				'request'=>$request,
				'page'=>$this,
			], ['ability', 'user', 'request', 'page']);
		}
		return true;
	}

	/**
	 * Merges page-level metadata used by renderers and manifests.
	 *
	 * Later metadata wins over earlier values. The array is kept as runtime page
	 * metadata and is not interpreted by PanelPage itself.
	 *
	 * @param array<string,mixed> $meta Extra page manifest metadata.
	 * @return self Cloned page definition with merged page metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Renders the page content for a request.
	 *
	 * Renderer callbacks run through PanelUtilityResolver with request, page, and
	 * manager context. Pages without callbacks return their stored static content.
	 *
	 * @param PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @param ?PanelManager $manager Panel manager supplying runtime policy and registries.
	 * @return mixed Renderer callback result, or the static page content when no renderer is configured.
	 */
	public function render(PanelRequest $request, ?PanelManager $manager=null): mixed {
		if($this->renderer!==null){
			return PanelUtilityResolver::evaluate($this->renderer, [
				'request'=>$request,
				'page'=>$this,
				'manager'=>$manager,
			], ['request', 'page', 'manager']);
		}
		return $this->content;
	}

	/**
	 * Reports whether this page is hidden from navigation.
	 *
	 * The flag affects navigation lists only; route exposure is controlled by the
	 * manager and surrounding panel setup.
	 *
	 * @return bool True when the page is explicitly hidden from navigation.
	 */
	public function isHiddenFromNavigation(): bool {
		return $this->hiddenFromNavigation;
	}

	/**
	 * Builds the navigation entry consumed by Panel managers and renderers.
	 *
	 * Lazy badge resolvers are evaluated here. Resolver failures are traced and
	 * converted to a null badge so one failing badge does not break navigation.
	 *
	 * @param ?PanelRequest $request Panel request used to resolve visibility, widgets, tables, authorization, and rendering.
	 * @param ?PanelManager $manager Panel manager supplying runtime policy and registries.
	 * @return array{name:string,label:string,group:?string,parent:?string,icon:string,url:string,sort:int,kind:string,description:?string,badge:mixed,badge_tone:string} Navigation entry consumed by the Panel manager.
	 */
	public function navigationEntry(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$badge=$this->navigationBadge;
		if($this->navigationBadgeResolver!==null){
			try{
				$badge=PanelUtilityResolver::evaluate($this->navigationBadgeResolver, [
					'request'=>$request,
					'page'=>$this,
					'manager'=>$manager,
				], ['request', 'page', 'manager']);
			}
			catch(\Throwable $exception){
				PanelTrace::record('navigation.badge_error', [
					'page'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$badge=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'group'=>$this->group,
			'parent'=>$this->navigationParent,
			'icon'=>$this->icon ?? 'file',
			'url'=>$this->url ?? PanelConfig::url($this->name),
			'sort'=>$this->sort,
			'kind'=>'page',
			'description'=>$this->navigationDescription,
			'badge'=>$badge,
			'badge_tone'=>$this->navigationBadgeTone,
		];
	}

	/**
	 * Serializes this page definition into a portable array.
	 *
	 * Callable renderer, authorizer, and lazy badge callbacks are represented as
	 * boolean capability flags because closures cannot be serialized into manifests.
	 *
	 * @return array<string,mixed> Serializable page definition including navigation, render, action, form, widget, table, and metadata state.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'url'=>$this->url ?? PanelConfig::url($this->name),
			'group'=>$this->group,
			'icon'=>$this->icon ?? 'file',
			'sort'=>$this->sort,
			'hidden_from_navigation'=>$this->hiddenFromNavigation,
			'navigation_description'=>$this->navigationDescription,
			'navigation_badge'=>$this->navigationBadgeResolver===null ? $this->navigationBadge : null,
			'navigation_badge_lazy'=>$this->navigationBadgeResolver!==null,
			'navigation_badge_tone'=>$this->navigationBadgeTone,
			'renders'=>$this->renderer!==null,
			'has_content'=>$this->content!=='' && $this->content!==null,
			'authorizes'=>$this->authorizer!==null,
			'actions'=>array_map(
				static fn(Action|ActionGroup $action): array => $action->toArray(),
				array_values($this->actions)
			),
			'widgets'=>array_map(
				static fn(Widget $widget): array => $widget->toArray(),
				array_values($this->widgets)
			),
			'tables'=>array_map(
				static fn(PageTable $table): array => $table->toArray(),
				array_values($this->tables)
			),
			'forms'=>array_values($this->forms),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes a page form scaffold from an action and placement options.
	 *
	 * String actions resolve to existing page actions when possible,
	 * arrays become Action definitions, placement/width/style are constrained to
	 * renderer-supported values, and show_action/sort metadata is made explicit for
	 * manifests and page rendering.
	 *
	 * @param Action|array|string $action Action object, action definition, or action name.
	 * @param array|string $options Form options or shorthand title.
	 * @param string $placement Default placement when options do not specify one.
	 * @return array{0: Action, 1: array<string, mixed>} Normalized action and form options.
	 */
	private function normalizeFormScaffold(Action|array|string $action, array|string $options, string $placement): array {
		$options=is_string($options) ? ['title'=>$options] : $options;
		if(is_string($action)){
			$name=Resource::normalizeName($action);
			$action=$this->actionByName($name) ?? Action::make($name);
		}
		elseif(is_array($action)){
			$action=self::actionDefinition($action);
		}
		$placement=Resource::normalizeName((string)($options['placement'] ?? $placement));
		$placement=in_array($placement, ['embedded', 'page'], true) ? $placement : 'embedded';
		$width=Resource::normalizeName((string)($options['width'] ?? ($placement==='page' ? 'md' : 'full')));
		$width=in_array($width, ['sm', 'md', 'lg', 'xl', 'full'], true) ? $width : ($placement==='page' ? 'md' : 'full');
		$style=Resource::normalizeName((string)($options['style'] ?? ($placement==='page' ? 'portal' : 'section')));
		$style=in_array($style, ['section', 'card', 'portal', 'plain'], true) ? $style : 'section';
		$options=array_replace($options, [
			'action'=>$action->name(),
			'placement'=>$placement,
			'width'=>$width,
			'style'=>$style,
			'show_action'=>($options['show_action'] ?? false)===true,
			'sort'=>(int)($options['sort'] ?? 100),
		]);
		return [$action, $options];
	}

	/**
	 * Checks page access through the optional Panel permission bridge.
	 *
	 * When no permission bridge is configured, PanelPage remains
	 * permissive for standalone/runtime use. Guest-allowed pages bypass named
	 * permission checks; other pages map the ability to a page permission and pass
	 * page/resource/operation/tenant context to the bridge.
	 *
	 * @param string $ability Page ability such as view, update, or custom action.
	 * @param mixed $user Optional authenticated principal.
	 * @param PanelRequest|null $request Current panel request for tenant context.
	 * @return bool True when permission policy allows the page ability.
	 */
	private function permissionAllows(string $ability, mixed $user=null, ?PanelRequest $request=null): bool {
		if(!PanelPermissionBridge::configured()){
			return true;
		}
		if(PanelPermissionBridge::allowsGuestPage($this->name)){
			return true;
		}
		$permission=PanelPermissionBridge::pageName($this->name, $ability);
		return PanelPermissionBridge::allows($permission, $user, [
			'page'=>$this->name,
			'resource'=>$this->name,
			'operation'=>Resource::normalizeName($ability) ?: 'view',
			'tenant'=>$request?->tenant(),
		]);
	}

	/**
	 * Converts a normalized identifier into a display label.
	 *
	 * @param string $value Normalized page/action/widget/table identifier.
	 * @return string Title-cased label, or an empty string for blank identifiers.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}

	/**
	 * Hydrates an action or action group from an array definition.
	 *
	 * Definitions with nested actions, or an explicit group/action_group
	 * type, become ActionGroup instances; all other definitions become Action
	 * instances. This preserves group manifests while accepting compact action
	 * arrays from page definitions.
	 *
	 * @param array<string, mixed> $definition Action or action group definition.
	 * @return Action|ActionGroup Hydrated page action object.
	 */
	private static function actionDefinition(array $definition): Action|ActionGroup {
		$type=Resource::normalizeName((string)($definition['type'] ?? $definition['kind'] ?? ''));
		if((isset($definition['actions']) && is_array($definition['actions'])) || in_array($type, ['group', 'action_group'], true)){
			return ActionGroup::fromArray($definition);
		}
		return Action::fromArray($definition);
	}
}
