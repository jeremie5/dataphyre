<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable builder for one Panel navigation node and its nested child tree.
 *
 * Navigation items describe sidebar/menu entries, optional folder-only parents, badges, visibility callbacks, child nodes,
 * and manifest metadata. Runtime entry generation evaluates lazy badge and visibility resolvers against the current request
 * and manager while preserving a static toArray() representation for diagnostics.
 */
final class NavigationItem {
	use PanelExtensible;

	private string $name;
	private string $label;
	private ?string $url=null;
	private ?string $group=null;
	private ?string $parent=null;
	private ?string $icon=null;
	private ?string $description=null;
	private int $sort=100;
	private mixed $badge=null;
	private ?\Closure $badgeResolver=null;
	private string $badgeTone='neutral';
	private bool $newTab=false;
	private bool $hidden=false;
	private bool $folderOnly=false;
	private ?\Closure $visibilityResolver=null;
	/** @var list<NavigationItem> */
	private array $children=[];
	private array $meta=[];

	/**
	 * Seeds the item with a normalized name and humanized default label.
	 *
	 * @param string $name Raw navigation item name.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured navigation item.
	 *
	 * @param string $name Navigation item name.
	 * @return self New navigation item after Panel extension hooks run.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Builds a navigation item from a manifest-style definition array.
	 *
	 * Supported keys include name, label, url, group, parent, folder, icon, description, sort, badge, badge_tone, new_tab,
	 * folder_only, submenu, hidden, meta, and children.
	 *
	 * @param array<string, mixed> $definition Navigation definition.
	 * @return self Navigation item represented by the definition.
	 */
	public static function fromArray(array $definition): self {
		$item=self::make((string)($definition['name'] ?? ''));
		foreach(['label', 'url', 'group', 'parent', 'icon', 'description'] as $key){
			if(isset($definition[$key]) && is_string($definition[$key])){
				$item=$item->{$key}($definition[$key]);
			}
		}
		if(isset($definition['folder']) && is_string($definition['folder'])){
			$item=$item->parent($definition['folder']);
		}
		if(isset($definition['sort'])){
			$item=$item->sort((int)$definition['sort']);
		}
		if(array_key_exists('badge', $definition)){
			$item=$item->badge($definition['badge']);
		}
		if(isset($definition['badge_tone']) && is_string($definition['badge_tone'])){
			$item=$item->badgeTone($definition['badge_tone']);
		}
		if(!empty($definition['new_tab'])){
			$item=$item->newTab();
		}
		if(!empty($definition['folder_only']) || !empty($definition['submenu'])){
			$item=$item->folderOnly();
		}
		if(!empty($definition['hidden'])){
			$item=$item->hide();
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$item=$item->meta($definition['meta']);
		}
		if(isset($definition['children']) && is_array($definition['children'])){
			$item=$item->children($definition['children']);
		}
		return $item;
	}

	/**
	 * Returns the normalized navigation item name.
	 *
	 * @return string Stable item key.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns a copy with an operator-facing label.
	 *
	 * @param string $label Menu label text.
	 * @return self Cloned navigation item with updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label);
		return $clone;
	}

	/**
	 * Returns a copy with an explicit URL.
	 *
	 * Items without explicit URLs fall back to PanelConfig::url(name) during navigation entry generation unless they are
	 * folder-only items.
	 *
	 * @param string $url Destination URL.
	 * @return self Cloned navigation item with updated URL.
	 */
	public function url(string $url): self {
		$clone=clone $this;
		$clone->url=trim($url);
		return $clone;
	}

	/**
	 * Returns a copy assigned to a top-level navigation group.
	 *
	 * @param string $group Group label or key.
	 * @return self Cloned navigation item with updated group.
	 */
	public function group(string $group): self {
		$clone=clone $this;
		$clone->group=trim($group) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy assigned to a parent navigation item.
	 *
	 * Parent item objects are reduced to their normalized name so navigation trees remain serializable.
	 *
	 * @param string|self|null $parent Parent item name, item object, or null to clear.
	 * @return self Cloned navigation item with updated parent name.
	 */
	public function parent(string|self|null $parent): self {
		$clone=clone $this;
		$clone->parent=$parent instanceof self ? $parent->name() : (is_string($parent) ? Resource::normalizeName($parent) : null);
		$clone->parent=$clone->parent!=='' ? $clone->parent : null;
		return $clone;
	}

	/**
	 * Alias for parent() used by folder-style navigation declarations.
	 *
	 * @param string|self|null $parent Parent folder name or item.
	 * @return self Cloned navigation item with updated parent.
	 */
	public function folder(string|self|null $parent): self {
		return $this->parent($parent);
	}

	/**
	 * Returns a copy that behaves as a non-clickable folder.
	 *
	 * @param bool $folderOnly Whether the item should suppress its URL and act as a container.
	 * @return self Cloned navigation item with folder-only behavior updated.
	 */
	public function folderOnly(bool $folderOnly=true): self {
		$clone=clone $this;
		$clone->folderOnly=$folderOnly;
		return $clone;
	}

	/**
	 * Alias for folderOnly() used by submenu declarations.
	 *
	 * @param bool $folderOnly Whether the submenu should be non-clickable.
	 * @return self Cloned navigation item with submenu behavior updated.
	 */
	public function submenu(bool $folderOnly=true): self {
		return $this->folderOnly($folderOnly);
	}

	/**
	 * Returns a copy with an icon identifier.
	 *
	 * @param string $icon Icon key used by Panel renderers.
	 * @return self Cloned navigation item with updated icon.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with descriptive helper text.
	 *
	 * @param string $description Optional description shown by capable surfaces.
	 * @return self Cloned navigation item with updated description.
	 */
	public function description(string $description): self {
		$clone=clone $this;
		$clone->description=trim($description) ?: null;
		return $clone;
	}

	/**
	 * Returns a copy with a sort weight.
	 *
	 * @param int $sort Sort weight used by navigation ordering.
	 * @return self Cloned navigation item with updated sort weight.
	 */
	public function sort(int $sort): self {
		$clone=clone $this;
		$clone->sort=$sort;
		return $clone;
	}

	/**
	 * Returns a copy with either a static badge value or lazy badge resolver.
	 *
	 * Callable badges are evaluated only while building a runtime navigation entry, allowing request-aware counts without
	 * making the static navigation manifest execute application code.
	 *
	 * @param mixed $badge Static badge value or callable resolver.
	 * @return self Cloned navigation item with updated badge behavior.
	 */
	public function badge(mixed $badge): self {
		$clone=clone $this;
		if(is_callable($badge)){
			$clone->badgeResolver=\Closure::fromCallable($badge);
			$clone->badge=null;
			return $clone;
		}
		$clone->badge=$badge;
		$clone->badgeResolver=null;
		return $clone;
	}

	/**
	 * Returns a copy with a lazy request-aware badge resolver.
	 *
	 * @param callable $resolver Resolver called with PanelRequest, NavigationItem, and PanelManager.
	 * @return self Cloned navigation item with lazy badge behavior.
	 */
	public function badgeUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->badgeResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a copy with a normalized badge tone.
	 *
	 * Unsupported tones fall back to neutral to keep renderer class names bounded.
	 *
	 * @param string $tone Badge tone name.
	 * @return self Cloned navigation item with updated badge tone.
	 */
	public function badgeTone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->badgeTone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Returns a copy that marks the link for a new browser tab.
	 *
	 * @param bool $newTab Whether renderers should mark the link as external/new-tab.
	 * @return self Cloned navigation item with updated new-tab flag.
	 */
	public function newTab(bool $newTab=true): self {
		$clone=clone $this;
		$clone->newTab=$newTab;
		return $clone;
	}

	/**
	 * Returns a copy hidden from runtime navigation.
	 *
	 * Hidden items remain serializable in static definitions but isVisible() always rejects them.
	 *
	 * @param bool $hidden Whether the item should be hidden.
	 * @return self Cloned navigation item with updated hidden flag.
	 */
	public function hide(bool $hidden=true): self {
		$clone=clone $this;
		$clone->hidden=$hidden;
		return $clone;
	}

	/**
	 * Returns a copy with a request-aware visibility resolver.
	 *
	 * Resolver exceptions are traced and treated as not visible, keeping navigation generation fail-closed.
	 *
	 * @param callable $resolver Resolver called with PanelRequest, NavigationItem, and PanelManager.
	 * @return self Cloned navigation item with lazy visibility behavior.
	 */
	public function visibleUsing(callable $resolver): self {
		$clone=clone $this;
		$clone->visibilityResolver=\Closure::fromCallable($resolver);
		return $clone;
	}

	/**
	 * Returns a copy with one child item appended.
	 *
	 * Array children are converted through fromArray() so nested manifests and fluent builders share one contract.
	 *
	 * @param NavigationItem|array<string, mixed> $child Child item or child definition.
	 * @return self Cloned navigation item with the child appended.
	 */
	public function child(NavigationItem|array $child): self {
		$clone=clone $this;
		$child=$child instanceof NavigationItem ? $child : NavigationItem::fromArray($child);
		$clone->children[]=$child;
		return $clone;
	}

	/**
	 * Returns a copy with the child list replaced.
	 *
	 * Invalid child entries are ignored so generated navigation arrays can be passed directly.
	 *
	 * @param array<int|string, NavigationItem|array<string, mixed>|mixed> $children Child item definitions.
	 * @return self Cloned navigation item with replacement children.
	 */
	public function children(array $children): self {
		$clone=clone $this;
		$clone->children=[];
		foreach($children as $child){
			if($child instanceof NavigationItem || is_array($child)){
				$clone->children[]=$child instanceof NavigationItem ? $child : NavigationItem::fromArray($child);
			}
		}
		return $clone;
	}

	/**
	 * Returns a copy with merged metadata.
	 *
	 * @param array<string, mixed> $meta Metadata for renderers, manifests, or extensions.
	 * @return self Cloned navigation item with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Reports whether the item should appear for the current request.
	 *
	 * Hidden items fail immediately. Lazy visibility resolvers can inspect request, item, and manager context; exceptions are
	 * recorded to PanelTrace and treated as false so navigation rendering does not expose broken items.
	 *
	 * @param ?PanelRequest $request Current Panel request.
	 * @param ?PanelManager $manager Current Panel manager.
	 * @return bool True when the item is visible in this context.
	 */
	public function isVisible(?PanelRequest $request=null, ?PanelManager $manager=null): bool {
		if($this->hidden){
			return false;
		}
		if($this->visibilityResolver===null){
			return true;
		}
		try{
			return (bool)($this->visibilityResolver)($request, $this, $manager);
		}
		catch(\Throwable $exception){
			PanelTrace::record('navigation.visibility_error', [
				'item'=>$this->name,
				'message'=>$exception->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * Builds the runtime navigation entry consumed by Panel renderers.
	 *
	 * This evaluates lazy badges, filters invisible children, resolves default URLs/icons, and includes metadata needed by
	 * navigation manifests. Badge resolver failures are traced and converted to a null badge.
	 *
	 * @param ?PanelRequest $request Current Panel request.
	 * @param ?PanelManager $manager Current Panel manager.
	 * @return array<string, mixed> Runtime navigation entry.
	 */
	public function navigationEntry(?PanelRequest $request=null, ?PanelManager $manager=null): array {
		$badge=$this->badge;
		if($this->badgeResolver!==null){
			try{
				$badge=($this->badgeResolver)($request, $this, $manager);
			}
			catch(\Throwable $exception){
				PanelTrace::record('navigation.badge_error', [
					'item'=>$this->name,
					'message'=>$exception->getMessage(),
				]);
				$badge=null;
			}
		}
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'group'=>$this->group,
			'parent'=>$this->parent,
			'icon'=>$this->icon ?? 'link',
			'url'=>$this->folderOnly ? '' : ($this->url ?? PanelConfig::url($this->name)),
			'sort'=>$this->sort,
			'kind'=>'navigation_item',
			'description'=>$this->description,
			'badge'=>$badge,
			'badge_tone'=>$this->badgeTone,
			'new_tab'=>$this->newTab,
			'folder_only'=>$this->folderOnly,
			'children'=>array_values(array_map(
				static fn(NavigationItem $child): array => $child->navigationEntry($request, $manager),
				array_filter($this->children, static fn(NavigationItem $child): bool => $child->isVisible($request, $manager))
			)),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Exports the static navigation definition without executing lazy callbacks.
	 *
	 * @return array<string, mixed> Static navigation definition.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>$this->label,
			'url'=>$this->url,
			'group'=>$this->group,
			'parent'=>$this->parent,
			'icon'=>$this->icon ?? 'link',
			'description'=>$this->description,
			'sort'=>$this->sort,
			'badge'=>$this->badgeResolver===null ? $this->badge : null,
			'badge_lazy'=>$this->badgeResolver!==null,
			'badge_tone'=>$this->badgeTone,
			'new_tab'=>$this->newTab,
			'folder_only'=>$this->folderOnly,
			'hidden'=>$this->hidden,
			'visible_lazy'=>$this->visibilityResolver!==null,
			'children'=>array_map(static fn(NavigationItem $child): array => $child->toArray(), $this->children),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Builds a navigation manifest containing this item as the root entry.
	 *
	 * @param ?PanelRequest $request Current Panel request used for runtime entry evaluation.
	 * @param array<string, mixed> $meta Extra manifest metadata.
	 * @return array<string, mixed> Navigation manifest payload.
	 */
	public function manifest(?PanelRequest $request=null, array $meta=[]): array {
		return NavigationManifest::from(PanelNavigationState::make([$this->navigationEntry($request)], $request), $request, [], $meta)->toArray();
	}

	/**
	 * Converts normalized names into default human-readable labels.
	 *
	 * @param string $value Normalized name or slug.
	 * @return string Title-cased label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}
}
