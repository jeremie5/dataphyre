<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable panel action group used to render dropdowns, split controls, and grouped action menus.
 *
 * An action group owns button chrome, dropdown sizing/alignment, the available Action instances,
 * the visible menu ordering, section headings, dividers, and arbitrary metadata consumed by panel
 * manifests. Mutators clone the group so resource definitions can be reused without shared state.
 */
final class ActionGroup {
	use PanelExtensible;

	private string $name;
	private string $label;
	private ?string $icon=null;
	private string $tone='neutral';
	private string $style='solid';
	private string $size='md';
	private bool $iconOnly=false;
	private string $dropdownWidth='md';
	private string $dropdownAlignment='end';
	/** @var array<string, Action> */
	private array $actions=[];
	/** @var list<array<string, mixed>> */
	private array $items=[];
	private array $meta=[];

	/**
	 * Creates a normalized action group with a human-readable default label.
	 *
	 * Construction is private so groups pass through make() and configuration hooks
	 * before being attached to resources or manifests.
	 *
	 * @param string $name Raw group name.
	 */
	private function __construct(string $name) {
		$this->name=Resource::normalizeName($name);
		$this->label=self::humanize($this->name);
	}

	/**
	 * Creates a configured action group with a normalized stable name.
	 *
	 * @param string $name Identifier used by manifests and action lookup.
	 * @return self New action group after PanelExtensible configuration hooks run.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Hydrates an action group from a manifest-style array definition.
	 *
	 * Recognized keys include `name`, `label`, `icon`, `tone`, `style`, `variant`, `size`,
	 * `icon_only`, `dropdown_width`, `dropdown_alignment`, `alignment`, `placement`, `actions`,
	 * `items`, and `meta`. Unknown keys are ignored.
	 *
	 * @param array<string,mixed> $definition Serialized action group definition.
	 * @return self Action group rebuilt from the supplied definition.
	 */
	public static function fromArray(array $definition): self {
		$group=self::make((string)($definition['name'] ?? $definition['label'] ?? 'actions'));
		if(isset($definition['label'])){
			$group=$group->label((string)$definition['label']);
		}
		if(isset($definition['icon']) && is_string($definition['icon'])){
			$group=$group->icon($definition['icon']);
		}
		if(isset($definition['tone']) && is_string($definition['tone'])){
			$group=$group->tone($definition['tone']);
		}
		if(isset($definition['style']) && is_string($definition['style'])){
			$group=$group->style($definition['style']);
		}
		elseif(isset($definition['variant']) && is_string($definition['variant'])){
			$group=$group->variant($definition['variant']);
		}
		if(isset($definition['size']) && is_string($definition['size'])){
			$group=$group->size($definition['size']);
		}
		if(!empty($definition['icon_only'])){
			$group=$group->iconOnly();
		}
		if(isset($definition['dropdown_width']) && is_string($definition['dropdown_width'])){
			$group=$group->dropdownWidth($definition['dropdown_width']);
		}
		if(isset($definition['dropdown_alignment']) && is_string($definition['dropdown_alignment'])){
			$group=$group->dropdownAlignment($definition['dropdown_alignment']);
		}
		elseif(isset($definition['alignment']) && is_string($definition['alignment'])){
			$group=$group->dropdownAlignment($definition['alignment']);
		}
		elseif(isset($definition['placement']) && is_string($definition['placement'])){
			$group=$group->dropdownAlignment($definition['placement']);
		}
		if(isset($definition['actions']) && is_array($definition['actions'])){
			$group=$group->actions($definition['actions']);
		}
		if(isset($definition['items']) && is_array($definition['items'])){
			$group=$group->menu($definition['items']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$group=$group->meta($definition['meta']);
		}
		return $group;
	}

	/**
	 * Returns the normalized action group name.
	 *
	 * @return string Stable lookup key for this group.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sets the visible button or dropdown label.
	 *
	 * Empty labels are ignored so the current label, including the constructor's humanized default,
	 * remains available to the manifest.
	 *
	 * @param string $label Human-readable group label.
	 * @return self Cloned group with the updated label.
	 */
	public function label(string $label): self {
		$clone=clone $this;
		$clone->label=trim($label) ?: $clone->label;
		return $clone;
	}

	/**
	 * Sets the optional icon token rendered beside or instead of the label.
	 *
	 * @param string $icon Icon name understood by the panel front end.
	 * @return self Cloned group with the icon token or null when empty.
	 */
	public function icon(string $icon): self {
		$clone=clone $this;
		$clone->icon=trim($icon) ?: null;
		return $clone;
	}

	/**
	 * Sets the semantic color tone for the group trigger.
	 *
	 * Accepted tones are `neutral`, `primary`, `success`, `warning`, and `danger`; anything else
	 * falls back to `neutral`.
	 *
	 * @param string $tone Requested semantic tone.
	 * @return self Cloned group with the normalized tone.
	 */
	public function tone(string $tone): self {
		$tone=Resource::normalizeName($tone);
		$clone=clone $this;
		$clone->tone=in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger'], true) ? $tone : 'neutral';
		return $clone;
	}

	/**
	 * Sets the visual style of the group trigger.
	 *
	 * `outline` and `outlined` normalize to `outline`; `ghost`, `subtle`, and `text` normalize
	 * to `ghost`; `link` is preserved; unsupported values fall back to `solid`.
	 *
	 * @param string $style Requested style or alias.
	 * @return self Cloned group with the normalized trigger style.
	 */
	public function style(string $style): self {
		$style=Resource::normalizeName($style);
		$style=match($style){
			'outline', 'outlined' => 'outline',
			'ghost', 'subtle', 'text' => 'ghost',
			'link' => 'link',
			default => 'solid',
		};
		$clone=clone $this;
		$clone->style=$style;
		return $clone;
	}

	/**
	 * Alias for style() for callers using component variant terminology.
	 *
	 * @param string $style Requested style or alias.
	 * @return self Cloned group with the normalized trigger style.
	 */
	public function variant(string $style): self {
		return $this->style($style);
	}

	/**
	 * Toggles the outlined trigger style.
	 *
	 * @param bool $enabled True for outline, false to restore solid.
	 * @return self Cloned group with the requested style state.
	 */
	public function outlined(bool $enabled=true): self {
		return $enabled ? $this->style('outline') : $this->style('solid');
	}

	/**
	 * Alias for outlined().
	 *
	 * @param bool $enabled True for outline, false to restore solid.
	 * @return self Cloned group with the requested style state.
	 */
	public function outline(bool $enabled=true): self {
		return $this->outlined($enabled);
	}

	/**
	 * Toggles the ghost trigger style.
	 *
	 * @param bool $enabled True for ghost, false to restore solid.
	 * @return self Cloned group with the requested style state.
	 */
	public function ghost(bool $enabled=true): self {
		return $enabled ? $this->style('ghost') : $this->style('solid');
	}

	/**
	 * Alias for ghost() for callers using subtle button terminology.
	 *
	 * @param bool $enabled True for ghost, false to restore solid.
	 * @return self Cloned group with the requested style state.
	 */
	public function subtle(bool $enabled=true): self {
		return $this->ghost($enabled);
	}

	/**
	 * Toggles the link trigger style.
	 *
	 * @param bool $enabled True for link, false to restore solid.
	 * @return self Cloned group with the requested style state.
	 */
	public function link(bool $enabled=true): self {
		return $enabled ? $this->style('link') : $this->style('solid');
	}

	/**
	 * Sets the trigger size.
	 *
	 * Accepted sizes are `xs`, `sm`, `md`, `lg`, and `xl`; `small` maps to `sm`, `large`
	 * maps to `lg`, and unsupported values fall back to `md`.
	 *
	 * @param string $size Requested size token or alias.
	 * @return self Cloned group with the normalized size.
	 */
	public function size(string $size): self {
		$size=Resource::normalizeName($size);
		$size=match($size){
			'xs', 'sm', 'md', 'lg', 'xl' => $size,
			'small' => 'sm',
			'large' => 'lg',
			default => 'md',
		};
		$clone=clone $this;
		$clone->size=$size;
		return $clone;
	}

	/**
	 * Toggles compact trigger sizing.
	 *
	 * @param bool $enabled True for `sm`, false to restore `md`.
	 * @return self Cloned group with the requested size state.
	 */
	public function compact(bool $enabled=true): self {
		return $enabled ? $this->size('sm') : $this->size('md');
	}

	/**
	 * Toggles large trigger sizing.
	 *
	 * @param bool $enabled True for `lg`, false to restore `md`.
	 * @return self Cloned group with the requested size state.
	 */
	public function large(bool $enabled=true): self {
		return $enabled ? $this->size('lg') : $this->size('md');
	}

	/**
	 * Marks the trigger as icon-only.
	 *
	 * The label remains in the manifest for accessible names and tooltips even when the visual
	 * treatment hides it.
	 *
	 * @param bool $enabled Whether the trigger should render without visible label text.
	 * @return self Cloned group with the icon-only flag.
	 */
	public function iconOnly(bool $enabled=true): self {
		$clone=clone $this;
		$clone->iconOnly=$enabled;
		return $clone;
	}

	/**
	 * Alias for iconOnly().
	 *
	 * @param bool $enabled Whether the trigger should render without visible label text.
	 * @return self Cloned group with the icon-only flag.
	 */
	public function iconButton(bool $enabled=true): self {
		return $this->iconOnly($enabled);
	}

	/**
	 * Sets the dropdown width token.
	 *
	 * Accepted widths are `xs`, `sm`, `md`, `lg`, `xl`, and `auto`; `small` maps to `sm`,
	 * `large` maps to `lg`, and unsupported values fall back to `md`.
	 *
	 * @param string $width Requested dropdown width token or alias.
	 * @return self Cloned group with the normalized dropdown width.
	 */
	public function dropdownWidth(string $width): self {
		$width=Resource::normalizeName($width);
		$width=match($width){
			'xs', 'sm', 'md', 'lg', 'xl' => $width,
			'auto' => 'auto',
			'small' => 'sm',
			'large' => 'lg',
			default => 'md',
		};
		$clone=clone $this;
		$clone->dropdownWidth=$width;
		return $clone;
	}

	/**
	 * Sets how the dropdown menu aligns to its trigger.
	 *
	 * `left`, `start`, and `before` map to `start`; `center` and `middle` map to `center`;
	 * `right`, `end`, and `after` map to `end`. Unsupported values fall back to `end`.
	 *
	 * @param string $alignment Requested dropdown alignment token or alias.
	 * @return self Cloned group with the normalized dropdown alignment.
	 */
	public function dropdownAlignment(string $alignment): self {
		$alignment=Resource::normalizeName($alignment);
		$alignment=match($alignment){
			'left', 'start', 'before' => 'start',
			'center', 'middle' => 'center',
			'right', 'end', 'after' => 'end',
			default => 'end',
		};
		$clone=clone $this;
		$clone->dropdownAlignment=$alignment;
		return $clone;
	}

	/**
	 * Aligns the dropdown menu to the trigger start edge.
	 *
	 * @return self Cloned group aligned to `start`.
	 */
	public function alignStart(): self {
		return $this->dropdownAlignment('start');
	}

	/**
	 * Centers the dropdown menu on the trigger.
	 *
	 * @return self Cloned group aligned to `center`.
	 */
	public function alignCenter(): self {
		return $this->dropdownAlignment('center');
	}

	/**
	 * Aligns the dropdown menu to the trigger end edge.
	 *
	 * @return self Cloned group aligned to `end`.
	 */
	public function alignEnd(): self {
		return $this->dropdownAlignment('end');
	}

	/**
	 * Adds multiple actions or menu marker definitions to the group.
	 *
	 * Items are processed through action(), so arrays may become Action instances, sections,
	 * headings, or dividers depending on their `type`.
	 *
	 * @param list<Action|array<string,mixed>|string> $actions List of Action instances, array definitions, or action name strings.
	 * @return self Cloned group containing all accepted actions and menu markers.
	 */
	public function actions(array $actions): self {
		$clone=clone $this;
		foreach($actions as $action){
			$clone=$clone->action($action);
		}
		return $clone;
	}

	/**
	 * Adds one action or menu marker to the group.
	 *
	 * Array definitions with `section` or `heading` type add a menu heading, `divider` or
	 * `separator` add a divider, and all other array/string inputs are normalized into Action
	 * instances. Named actions are indexed by normalized action name.
	 *
	 * @param Action|array|string $action Action object, serialized action definition, marker definition, or action name.
	 * @return self Cloned group with the accepted item added.
	 */
	public function action(Action|array|string $action): self {
		if(is_array($action)){
			$type=Resource::normalizeName((string)($action['type'] ?? ''));
			if(in_array($type, ['section', 'heading'], true)){
				return $this->section((string)($action['label'] ?? $action['name'] ?? ''), (string)($action['description'] ?? ''));
			}
			if(in_array($type, ['divider', 'separator'], true)){
				return $this->divider();
			}
		}
		$action=$action instanceof Action ? $action : (is_array($action) ? Action::fromArray($action) : Action::make((string)$action));
		$clone=clone $this;
		if($action->name()!==''){
			$clone->actions[$action->name()]=$action;
			$clone->items[]=['type'=>'action', 'name'=>$action->name()];
		}
		return $clone;
	}

	/**
	 * Adds a labeled section marker to the dropdown menu.
	 *
	 * Empty labels are ignored because unlabeled sections cannot be rendered meaningfully.
	 *
	 * @param string $label Section heading text.
	 * @param string $description Optional secondary text shown beneath or beside the heading.
	 * @return self Cloned group with the section marker appended.
	 */
	public function section(string $label, string $description=''): self {
		$label=trim($label);
		if($label===''){
			return $this;
		}
		$clone=clone $this;
		$clone->items[]=[
			'type'=>'section',
			'label'=>$label,
			'description'=>trim($description),
		];
		return $clone;
	}

	/**
	 * Alias for section().
	 *
	 * @param string $label Heading text.
	 * @param string $description Optional heading description.
	 * @return self Cloned group with the heading marker appended.
	 */
	public function heading(string $label, string $description=''): self {
		return $this->section($label, $description);
	}

	/**
	 * Adds a visual divider marker to the dropdown menu.
	 *
	 * @return self Cloned group with a divider marker appended.
	 */
	public function divider(): self {
		$clone=clone $this;
		$clone->items[]=['type'=>'divider'];
		return $clone;
	}

	/**
	 * Returns indexed Action objects owned by the group.
	 *
	 * @return array<string,Action> Actions keyed by normalized action name.
	 */
	public function actionsList(): array {
		return $this->actions;
	}

	/**
	 * Returns the menu ordering records used by the panel front end.
	 *
	 * If no explicit menu has been supplied, every registered action is exposed in insertion order.
	 *
	 * @return list<array<string,mixed>> Menu records with `type` and action, section, or divider fields.
	 */
	public function menuItems(): array {
		if($this->items!==[]){
			return $this->items;
		}
		return array_map(static fn(Action $action): array => [
			'type'=>'action',
			'name'=>$action->name(),
		], array_values($this->actions));
	}

	/**
	 * Replaces the visible dropdown menu ordering.
	 *
	 * Only supported item records are retained. Action records must reference actions already
	 * registered on the group; section and divider aliases are normalized for the manifest.
	 *
	 * @param list<array<string,mixed>> $items List of menu item records.
	 * @return self Cloned group with the validated menu ordering.
	 */
	public function menu(array $items): self {
		$clone=clone $this;
		$clone->items=[];
		foreach($items as $item){
			if(!is_array($item)){
				continue;
			}
			$type=Resource::normalizeName((string)($item['type'] ?? ''));
			if(in_array($type, ['section', 'heading'], true)){
				$clone->items[]=[
					'type'=>'section',
					'label'=>trim((string)($item['label'] ?? $item['name'] ?? '')),
					'description'=>trim((string)($item['description'] ?? '')),
				];
			}
			elseif(in_array($type, ['divider', 'separator'], true)){
				$clone->items[]=['type'=>'divider'];
			}
			elseif($type==='' || $type==='action'){
				$name=Resource::normalizeName((string)($item['name'] ?? ''));
				if($name!=='' && isset($clone->actions[$name])){
					$clone->items[]=['type'=>'action', 'name'=>$name];
				}
			}
		}
		return $clone;
	}

	/**
	 * Looks up a registered action by normalized name.
	 *
	 * @param string $name Action name or alias to normalize before lookup.
	 * @return ?Action Matching action, or null when the group does not contain it.
	 */
	public function actionByName(string $name): ?Action {
		$name=Resource::normalizeName($name);
		return $this->actions[$name] ?? null;
	}

	/**
	 * Merges arbitrary metadata into the action group manifest.
	 *
	 * Later calls replace values with the same keys while preserving unrelated metadata.
	 *
	 * @param array<string,mixed> $meta Metadata consumed by panel renderers or extensions.
	 * @return self Cloned group with merged metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Serializes the action group definition.
	 *
	 * @return array{name:string,label:string,icon:?string,tone:string,style:string,size:string,icon_only:bool,dropdown_width:string,dropdown_alignment:string,actions:list<array>,items:list<array<string,mixed>>,meta:array} Manifest-ready group payload.
	 */
	public function toArray(): array {
		return [
			'type'=>'action_group',
			'name'=>$this->name,
			'label'=>$this->label,
			'icon'=>$this->icon,
			'tone'=>$this->tone,
			'style'=>$this->style,
			'size'=>$this->size,
			'icon_only'=>$this->iconOnly,
			'dropdown_width'=>$this->dropdownWidth,
			'dropdown_alignment'=>$this->dropdownAlignment,
			'actions'=>array_map(static fn(Action $action): array => $action->toArray(), array_values($this->actions)),
			'items'=>$this->items,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Resolves the runtime manifest for this group in a resource/request context.
	 *
	 * ActionManifest applies per-record visibility, authorization, URL, form, and confirmation
	 * details for every action before the payload reaches the panel front end.
	 *
	 * @param mixed $record Optional resource record used to resolve action state.
	 * @param ?PanelRequest $request Current panel request, when available.
	 * @param ?Resource $resource Resource that owns the group.
	 * @param string $mode Manifest mode forwarded to ActionManifest.
	 * @param array<string,mixed> $meta Extra manifest metadata for this resolution.
	 * @return array<string,mixed> Runtime action group manifest.
	 */
	public function manifest(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $mode='action', array $meta=[]): array {
		return ActionManifest::from($this, $record, $request, $resource, $mode, $meta)->toArray();
	}

	/**
	 * Builds the fallback dropdown label from a group key.
	 *
	 * Empty names fall back to Actions, while separators in non-empty names become
	 * spaces and words are title-cased.
	 *
	 * @param string $value Normalized group key.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Actions' : ucwords($value);
	}
}
