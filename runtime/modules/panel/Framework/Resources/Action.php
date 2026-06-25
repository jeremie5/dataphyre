<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Fluent definition for a Panel action.
 *
 * Actions describe buttons, menu items, bulk operations, confirmation modals, form payloads, authorization, visibility, lifecycle hooks, and execution handlers.
 */
final class Action {
	use PanelExtensible;

	private string $name;
	private string|\Closure $label;
	private string|\Closure|null $icon=null;
	private string|\Closure $tone='neutral';
	private string $style='solid';
	private string $size='md';
	private bool $iconOnly=false;
	private string|\Closure|null $description=null;
	private string|int|float|\Closure|null $badge=null;
	private string|\Closure $badgeTone='neutral';
	private string|\Closure|null $tooltip=null;
	/** @var array<int, string> */
	private array $keyBindings=[];
	/** @var array<int, array<string, mixed>|\Closure> */
	private array $extraAttributes=[];
	private bool $requiresConfirmation=false;
	private bool $modal=false;
	private ?string $modalHeading=null;
	private ?string $modalDescription=null;
	private ?string $modalSubmitLabel=null;
	private ?string $modalCancelLabel=null;
	private string $modalWidth='md';
	private mixed $modalContent=null;
	private bool $bulk=false;
	private bool $allowEmptySelection=false;
	private ?string $successMessage=null;
	private ?string $redirectTo=null;
	private ?\Closure $handler=null;
	private ?\Closure $authorizer=null;
	private ?\Closure $visibleResolver=null;
	private ?\Closure $hiddenResolver=null;
	private ?\Closure $disabledResolver=null;
	private string|\Closure|null $disabledReason=null;
	private ?\Closure $dataMutator=null;
	private ?\Closure $beforeValidateHandler=null;
	private ?\Closure $afterValidateHandler=null;
	/** @var array<int, \Closure> */
	private array $beforeHooks=[];
	/** @var array<int, \Closure> */
	private array $afterHooks=[];
	/** @var array<int, \Closure> */
	private array $failureHooks=[];
	private ResourceForm $form;
	/** @var array<string,mixed> */
	private array $meta=[];

	/**
	 * Creates an action with normalized identity and default form state.
	 *
	 * The constructor is private so actions pass through make() and configured()
	 * before use, keeping extension hooks and manifest defaults consistently
	 * applied.
	 *
	 * @param string $name Raw action name.
	 */
	private function __construct(string $name) {
		$this->name=self::normalizeName($name);
		$this->label=self::humanize($this->name);
		$this->form=ResourceForm::make();
	}

	/**
	 * Builds a Panel action definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param string $name Normalized manifest object name.
	 * @return self Configured action definition with normalized name and default form state.
	 */
	public static function make(string $name): self {
		return self::configured(new self($name));
	}

	/**
	 * Builds a Panel action definition from fluent input or array configuration.
	 *
	 * The builder normalizes names, labels, callbacks, renderer metadata, and manifest options before export.
	 *
	 * @param array<string,mixed> $definition Array manifest/configuration definition.
	 * @return self Action definition hydrated from manifest/configuration data.
	 */
	public static function fromArray(array $definition): self {
		$action=self::make((string)($definition['name'] ?? ''));
		if(isset($definition['label']) && (is_string($definition['label']) || is_callable($definition['label']))){
			$action=$action->label($definition['label']);
		}
		if(isset($definition['icon']) && (is_string($definition['icon']) || is_callable($definition['icon']))){
			$action=$action->icon($definition['icon']);
		}
		if(isset($definition['tone']) && (is_string($definition['tone']) || is_callable($definition['tone']))){
			$action=$action->tone($definition['tone']);
		}
		if(isset($definition['style']) && is_string($definition['style'])){
			$action=$action->style($definition['style']);
		}
		elseif(isset($definition['variant']) && is_string($definition['variant'])){
			$action=$action->variant($definition['variant']);
		}
		if(isset($definition['size']) && is_string($definition['size'])){
			$action=$action->size($definition['size']);
		}
		if(!empty($definition['icon_only'])){
			$action=$action->iconOnly();
		}
		if(array_key_exists('description', $definition) && (is_string($definition['description']) || is_callable($definition['description']) || $definition['description']===null)){
			$action=$action->description($definition['description']);
		}
		elseif(array_key_exists('help', $definition) && (is_string($definition['help']) || is_callable($definition['help']) || $definition['help']===null)){
			$action=$action->description($definition['help']);
		}
		if(array_key_exists('badge', $definition) && (is_string($definition['badge']) || is_int($definition['badge']) || is_float($definition['badge']) || is_callable($definition['badge']) || $definition['badge']===null)){
			$action=$action->badge($definition['badge']);
		}
		if(isset($definition['badge_tone']) && (is_string($definition['badge_tone']) || is_callable($definition['badge_tone']))){
			$action=$action->badgeTone($definition['badge_tone']);
		}
		if(array_key_exists('tooltip', $definition) && (is_string($definition['tooltip']) || is_callable($definition['tooltip']) || $definition['tooltip']===null)){
			$action=$action->tooltip($definition['tooltip']);
		}
		if(isset($definition['key_bindings']) && is_array($definition['key_bindings'])){
			$action=$action->keyBindings($definition['key_bindings']);
		}
		elseif(isset($definition['key_binding']) && is_string($definition['key_binding'])){
			$action=$action->keyBinding($definition['key_binding']);
		}
		if(isset($definition['extra_attributes']) && (is_array($definition['extra_attributes']) || is_callable($definition['extra_attributes']))){
			$action=$action->extraAttributes($definition['extra_attributes']);
		}
		elseif(isset($definition['attributes']) && (is_array($definition['attributes']) || is_callable($definition['attributes']))){
			$action=$action->extraAttributes($definition['attributes']);
		}
		if(!empty($definition['requires_confirmation'])){
			$action=$action->requiresConfirmation();
		}
		if(!empty($definition['modal'])){
			$action=$action->modal();
		}
		if(isset($definition['modal_heading']) && is_string($definition['modal_heading'])){
			$action=$action->modalHeading($definition['modal_heading']);
		}
		if(isset($definition['modal_description']) && is_string($definition['modal_description'])){
			$action=$action->modalDescription($definition['modal_description']);
		}
		if(isset($definition['modal_submit_label']) && is_string($definition['modal_submit_label'])){
			$action=$action->modalSubmitLabel($definition['modal_submit_label']);
		}
		if(isset($definition['modal_cancel_label']) && is_string($definition['modal_cancel_label'])){
			$action=$action->modalCancelLabel($definition['modal_cancel_label']);
		}
		if(isset($definition['modal_width']) && is_string($definition['modal_width'])){
			$action=$action->modalWidth($definition['modal_width']);
		}
		if(!empty($definition['modal_back'])){
			$action=$action->modalBack();
		}
		if(isset($definition['modal_stack']) && is_string($definition['modal_stack'])){
			$action=$action->modalStack($definition['modal_stack']);
		}
		if(array_key_exists('modal_content', $definition) && (is_string($definition['modal_content']) || is_callable($definition['modal_content']) || is_array($definition['modal_content']))){
			$action=$action->modalContent($definition['modal_content']);
		}
		if(!empty($definition['bulk'])){
			$action=$action->bulk();
		}
		if(!empty($definition['allow_empty_selection'])){
			$action=$action->allowEmptySelection();
		}
		if(isset($definition['success_message']) && is_string($definition['success_message'])){
			$action=$action->successMessage($definition['success_message']);
		}
		if(isset($definition['redirect_to']) && is_string($definition['redirect_to'])){
			$action=$action->redirectTo($definition['redirect_to']);
		}
		if(isset($definition['effects']) && is_array($definition['effects'])){
			$action=$action->effects($definition['effects']);
		}
		elseif(isset($definition['action_effects']) && is_array($definition['action_effects'])){
			$action=$action->effects($definition['action_effects']);
		}
		if(array_key_exists('visible', $definition) && (is_bool($definition['visible']) || is_callable($definition['visible']))){
			$action=$action->visible($definition['visible']);
		}
		if(array_key_exists('hidden', $definition) && (is_bool($definition['hidden']) || is_callable($definition['hidden']))){
			$action=$action->hidden($definition['hidden']);
		}
		if(array_key_exists('disabled', $definition)){
			$disabled=$definition['disabled'];
			if(is_bool($disabled) || is_callable($disabled)){
				$reason=(isset($definition['disabled_reason']) && (is_string($definition['disabled_reason']) || is_callable($definition['disabled_reason']))) ? $definition['disabled_reason'] : null;
				$action=$action->disabled($disabled, $reason);
			}
		}
		elseif(isset($definition['disabled_reason']) && (is_string($definition['disabled_reason']) || is_callable($definition['disabled_reason']))){
			$action=$action->disabledReason($definition['disabled_reason']);
		}
		if(isset($definition['fields']) && is_array($definition['fields'])){
			$schema=Schema::from($definition['fields']['schema'] ?? $definition['fields']);
			$action=$schema instanceof Schema ? $action->schema($schema) : $action->fields($definition['fields']);
		}
		if(isset($definition['schema'])){
			$schema=Schema::from($definition['schema']);
			if($schema instanceof Schema){
				$action=$action->schema($schema);
			}
		}
		if(isset($definition['form_sections']) && is_array($definition['form_sections'])){
			$action=$action->formSections($definition['form_sections']);
		}
		if(isset($definition['meta']) && is_array($definition['meta'])){
			$action=$action->meta($definition['meta']);
		}
		foreach([
			'mutate_data'=>'mutateDataUsing',
			'mutate_form_data'=>'mutateFormDataUsing',
			'before_validate'=>'beforeValidateUsing',
			'after_validate'=>'afterValidateUsing',
			'before'=>'before',
			'before_action'=>'beforeActionUsing',
			'after'=>'after',
			'after_action'=>'afterActionUsing',
			'failure'=>'failure',
		] as $key=>$method){
			if(isset($definition[$key]) && is_callable($definition[$key])){
				$action=$action->{$method}($definition[$key]);
			}
		}
		return $action;
	}

	/**
	 * Updates the name metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return string Normalized action name used by manifests, permissions, and command dispatch.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Updates the label metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable $label Static label or callback resolved at render time.
	 * @return self Cloned action definition with updated label metadata.
	 */
	public function label(string|callable $label): self {
		$clone=clone $this;
		$clone->label=is_string($label) ? trim($label) : \Closure::fromCallable($label);
		return $clone;
	}

	/**
	 * Updates the icon metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable|null $icon Static icon, callback, or null to omit the icon.
	 * @return self Cloned action definition with updated icon metadata.
	 */
	public function icon(string|callable|null $icon): self {
		$clone=clone $this;
		$clone->icon=is_string($icon) || $icon===null ? ($icon===null ? null : (trim($icon) ?: null)) : \Closure::fromCallable($icon);
		return $clone;
	}

	/**
	 * Updates the tone metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable $tone Static tone token or callback resolved at render time.
	 * @return self Cloned action definition with updated tone metadata.
	 */
	public function tone(string|callable $tone): self {
		$clone=clone $this;
		if(!is_string($tone)){
			$clone->tone=\Closure::fromCallable($tone);
			return $clone;
		}
		$tone=strtolower(trim($tone));
		$clone->tone=self::normalizeTone($tone);
		return $clone;
	}

	/**
	 * Updates the style metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $style Button style or variant name normalized for renderers.
	 * @return self Cloned action definition with updated style metadata.
	 */
	public function style(string $style): self {
		$style=self::normalizeName($style);
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
	 * Updates the variant metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $style Button style or variant name normalized for renderers.
	 * @return self Cloned action definition with updated variant metadata.
	 */
	public function variant(string $style): self {
		return $this->style($style);
	}

	/**
	 * Updates the outlined metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated outlined metadata.
	 */
	public function outlined(bool $enabled=true): self {
		return $enabled ? $this->style('outline') : $this->style('solid');
	}

	/**
	 * Updates the outline metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated outline metadata.
	 */
	public function outline(bool $enabled=true): self {
		return $this->outlined($enabled);
	}

	/**
	 * Updates the ghost metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated ghost metadata.
	 */
	public function ghost(bool $enabled=true): self {
		return $enabled ? $this->style('ghost') : $this->style('solid');
	}

	/**
	 * Updates the subtle metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated subtle metadata.
	 */
	public function subtle(bool $enabled=true): self {
		return $this->ghost($enabled);
	}

	/**
	 * Updates the link metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated link metadata.
	 */
	public function link(bool $enabled=true): self {
		return $enabled ? $this->style('link') : $this->style('solid');
	}

	/**
	 * Updates the size metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $size Button size token consumed by renderers.
	 * @return self Cloned action definition with updated size metadata.
	 */
	public function size(string $size): self {
		$size=self::normalizeName($size);
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
	 * Updates the compact metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated compact metadata.
	 */
	public function compact(bool $enabled=true): self {
		return $enabled ? $this->size('sm') : $this->size('md');
	}

	/**
	 * Updates the large metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated large metadata.
	 */
	public function large(bool $enabled=true): self {
		return $enabled ? $this->size('lg') : $this->size('md');
	}

	/**
	 * Updates the icon only metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated icon only metadata.
	 */
	public function iconOnly(bool $enabled=true): self {
		$clone=clone $this;
		$clone->iconOnly=$enabled;
		return $clone;
	}

	/**
	 * Updates the icon button metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated icon button metadata.
	 */
	public function iconButton(bool $enabled=true): self {
		return $this->iconOnly($enabled);
	}

	/**
	 * Updates the description metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable|null $description Static description, dynamic resolver, or null to omit action help text.
	 * @return self Cloned action definition with updated description metadata.
	 */
	public function description(string|callable|null $description): self {
		$clone=clone $this;
		$clone->description=is_string($description) || $description===null ? ($description===null ? null : trim($description)) : \Closure::fromCallable($description);
		return $clone;
	}

	/**
	 * Updates the description using metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $resolver Callback resolved at render time for the action description.
	 * @return self Cloned action definition with updated description using metadata.
	 */
	public function descriptionUsing(callable $resolver): self {
		return $this->description($resolver);
	}

	/**
	 * Updates the help metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable|null $description Static help text, dynamic resolver, or null to omit action help text.
	 * @return self Cloned action definition with updated help metadata.
	 */
	public function help(string|callable|null $description): self {
		return $this->description($description);
	}

	/**
	 * Updates the badge metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|int|float|callable|null $badge Static badge value, dynamic resolver, or null to omit the badge.
	 * @return self Cloned action definition with updated badge metadata.
	 */
	public function badge(string|int|float|callable|null $badge): self {
		$clone=clone $this;
		$clone->badge=is_callable($badge) && !is_string($badge) ? \Closure::fromCallable($badge) : $badge;
		return $clone;
	}

	/**
	 * Updates the badge using metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $resolver Callback resolved at render time for the action badge value.
	 * @return self Cloned action definition with updated badge using metadata.
	 */
	public function badgeUsing(callable $resolver): self {
		return $this->badge($resolver);
	}

	/**
	 * Updates the badge tone metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable $tone Static tone token or callback resolved at render time.
	 * @return self Cloned action definition with updated badge tone metadata.
	 */
	public function badgeTone(string|callable $tone): self {
		$clone=clone $this;
		$clone->badgeTone=is_string($tone) ? self::normalizeTone($tone) : \Closure::fromCallable($tone);
		return $clone;
	}

	/**
	 * Updates the tooltip metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|callable|null $tooltip Static tooltip, dynamic resolver, or null to omit the tooltip.
	 * @return self Cloned action definition with updated tooltip metadata.
	 */
	public function tooltip(string|callable|null $tooltip): self {
		$clone=clone $this;
		$clone->tooltip=is_string($tooltip) || $tooltip===null ? ($tooltip===null ? null : trim($tooltip)) : \Closure::fromCallable($tooltip);
		return $clone;
	}

	/**
	 * Updates the tooltip using metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $resolver Callback resolved at render time for the action tooltip.
	 * @return self Cloned action definition with updated tooltip using metadata.
	 */
	public function tooltipUsing(callable $resolver): self {
		return $this->tooltip($resolver);
	}

	/**
	 * Updates the key bindings metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array|string $bindings Keyboard shortcut or list of shortcut strings consumed by the renderer.
	 * @return self Cloned action definition with updated key bindings metadata.
	 */
	public function keyBindings(array|string $bindings): self {
		$clone=clone $this;
		$values=is_array($bindings) ? $bindings : [$bindings];
		$normalized=[];
		foreach($values as $binding){
			if(!is_scalar($binding)){
				continue;
			}
			$binding=self::normalizeKeyBinding((string)$binding);
			if($binding!=='' && !in_array($binding, $normalized, true)){
				$normalized[]=$binding;
			}
		}
		$clone->keyBindings=$normalized;
		return $clone;
	}

	/**
	 * Updates the key binding metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $binding Keyboard shortcut binding string.
	 * @return self Cloned action definition with updated key binding metadata.
	 */
	public function keyBinding(string $binding): self {
		return $this->keyBindings([$binding]);
	}

	/**
	 * Updates the extra attributes metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array|callable $attributes Static attributes or resolver returning action button attributes.
	 * @param bool $merge Whether to append to existing attribute layers.
	 * @return self Cloned action definition with updated extra attributes metadata.
	 */
	public function extraAttributes(array|callable $attributes, bool $merge=true): self {
		$clone=clone $this;
		if(!$merge){
			$clone->extraAttributes=[];
		}
		$clone->extraAttributes[]=is_array($attributes) ? self::normalizeExtraAttributes($attributes) : \Closure::fromCallable($attributes);
		return $clone;
	}

	/**
	 * Updates the attributes metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array|callable $attributes Static attributes or resolver returning action button attributes.
	 * @param bool $merge Whether to append to existing attribute layers.
	 * @return self Cloned action definition with updated attributes metadata.
	 */
	public function attributes(array|callable $attributes, bool $merge=true): self {
		return $this->extraAttributes($attributes, $merge);
	}

	/**
	 * Updates the attribute metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned action definition with updated attribute metadata.
	 */
	public function attribute(string $name, mixed $value=true): self {
		return $this->extraAttributes([$name=>$value]);
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned action definition with updated data metadata.
	 */
	public function data(string $name, mixed $value=true): self {
		return $this->attribute('data-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the aria metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param mixed $value Manifest value or resolver input.
	 * @return self Cloned action definition with updated aria metadata.
	 */
	public function aria(string $name, mixed $value=true): self {
		return $this->attribute('aria-'.self::normalizeAttributeSegment($name), $value);
	}

	/**
	 * Updates the requires confirmation metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $required Whether the action must show a confirmation step before execution.
	 * @return self Cloned action definition with updated requires confirmation metadata.
	 */
	public function requiresConfirmation(bool $required=true): self {
		$clone=clone $this;
		$clone->requiresConfirmation=$required;
		return $clone;
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $message User-facing notification or confirmation message.
	 * @return self Cloned action definition with updated confirmation metadata.
	 */
	public function confirmation(string $message): self {
		return $this->requiresConfirmation()->meta(['confirmation'=>trim($message)]);
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param bool|string $modal Modal.
	 * @param ?string $description Description.
	 * @param string $width Modal width token consumed by renderers.
	 * @return self Cloned action definition with updated modal metadata.
	 */
	public function modal(bool|string $modal=true, ?string $description=null, string $width='md'): self {
		$clone=clone $this;
		if(is_string($modal)){
			$clone->modal=true;
			$clone->modalHeading=trim($modal) ?: null;
			if($description!==null){
				$clone->modalDescription=trim($description) ?: null;
			}
			$width=self::normalizeName($width);
			$clone->modalWidth=in_array($width, ['xs', 'sm', 'md', 'lg', 'xl', 'full'], true) ? $width : 'md';
			return $clone;
		}
		$clone->modal=$modal;
		return $clone;
	}

	/**
	 * Updates the slide over metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool|string $enabled Enabled.
	 * @param ?string $description Description.
	 * @param string $width Modal width token consumed by renderers.
	 * @return self Cloned action definition with updated slide over metadata.
	 */
	public function slideOver(bool|string $enabled=true, ?string $description=null, string $width='lg'): self {
		if(is_string($enabled)){
			return $this->modal($enabled, $description, $width)->meta(['modal_style'=>'slide_over']);
		}
		return $this->modal($enabled)->meta(['modal_style'=>'slide_over']);
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $width Modal width token consumed by renderers.
	 * @return self Cloned action definition with updated modal size metadata.
	 */
	public function modalSize(string $width): self {
		return $this->modalWidth($width);
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $heading Modal heading text.
	 * @return self Cloned action definition with updated modal heading metadata.
	 */
	public function modalHeading(string $heading): self {
		$clone=clone $this;
		$clone->modalHeading=trim($heading) ?: null;
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $description User-facing action or modal description.
	 * @return self Cloned action definition with updated modal description metadata.
	 */
	public function modalDescription(string $description): self {
		$clone=clone $this;
		$clone->modalDescription=trim($description) ?: null;
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $label User-facing button or modal label.
	 * @return self Cloned action definition with updated modal submit label metadata.
	 */
	public function modalSubmitLabel(string $label): self {
		$clone=clone $this;
		$clone->modalSubmitLabel=trim($label) ?: null;
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $label User-facing button or modal label.
	 * @return self Cloned action definition with updated modal cancel label metadata.
	 */
	public function modalCancelLabel(string $label): self {
		$clone=clone $this;
		$clone->modalCancelLabel=trim($label) ?: null;
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string $width Modal width token consumed by renderers.
	 * @return self Cloned action definition with updated modal width metadata.
	 */
	public function modalWidth(string $width): self {
		$width=self::normalizeName($width);
		$clone=clone $this;
		$clone->modalWidth=in_array($width, ['xs', 'sm', 'md', 'lg', 'xl', 'full'], true) ? $width : 'md';
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string|array|callable $content Content.
	 * @return self Cloned action definition with updated modal content metadata.
	 */
	public function modalContent(string|array|callable $content): self {
		$clone=clone $this;
		$clone->modalContent=is_callable($content) ? \Closure::fromCallable($content) : $content;
		return $clone->modal();
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated modal back metadata.
	 */
	public function modalBack(bool $enabled=true): self {
		return $enabled ? $this->modalStack('push') : $this->modalStack('replace');
	}

	/**
	 * Updates the preserve modal history metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated preserve modal history metadata.
	 */
	public function preserveModalHistory(bool $enabled=true): self {
		return $this->modalBack($enabled);
	}

	/**
	 * Configures confirmation and modal behavior for this action.
	 *
	 * Modal metadata controls headings, descriptions, submit/cancel labels, width, content, and confirmation requirements.
	 *
	 * @param string|bool $strategy Strategy.
	 * @return self Cloned action definition with updated modal stack metadata.
	 */
	public function modalStack(string|bool $strategy='push'): self {
		if(is_bool($strategy)){
			return $this->modalBack($strategy);
		}
		$strategy=self::normalizeName($strategy);
		if(!in_array($strategy, ['push', 'replace', 'clear'], true)){
			$strategy='push';
		}
		return $this->meta([
			'modal_stack'=>$strategy,
			'modal_back'=>$strategy==='push',
		]);
	}

	/**
	 * Updates the stacked modal metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $enabled Whether the style flag should be applied.
	 * @return self Cloned action definition with updated stacked modal metadata.
	 */
	public function stackedModal(bool $enabled=true): self {
		return $this->modalStack($enabled ? 'push' : 'replace');
	}

	/**
	 * Updates the replace modal metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated replace modal metadata.
	 */
	public function replaceModal(): self {
		return $this->modalStack('replace');
	}

	/**
	 * Updates the clear modal stack metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated clear modal stack metadata.
	 */
	public function clearModalStack(): self {
		return $this->modalStack('clear');
	}

	/**
	 * Updates the info modal metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|array|callable $content Content.
	 * @param ?string $heading Heading.
	 * @param ?string $description Description.
	 * @param string $width Modal width token consumed by renderers.
	 * @return self Cloned action definition with updated info modal metadata.
	 */
	public function infoModal(string|array|callable $content, ?string $heading=null, ?string $description=null, string $width='md'): self {
		$fallbackHeading=is_string($this->label) ? $this->label : self::humanize($this->name);
		$action=$this->modalContent($content)->modal($heading ?? $fallbackHeading, $description, $width);
		return $action->tone('info')->meta(['modal_role'=>'content']);
	}

	/**
	 * Updates the bulk metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $bulk Whether the action operates on a selected record set.
	 * @return self Cloned action definition with updated bulk metadata.
	 */
	public function bulk(bool $bulk=true): self {
		$clone=clone $this;
		$clone->bulk=$bulk;
		return $clone;
	}

	/**
	 * Updates the allow empty selection metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $allowed Whether a bulk action may run with no selected records.
	 * @return self Cloned action definition with updated allow empty selection metadata.
	 */
	public function allowEmptySelection(bool $allowed=true): self {
		$clone=clone $this;
		$clone->allowEmptySelection=$allowed;
		return $clone;
	}

	/**
	 * Updates the success message metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $message User-facing notification or confirmation message.
	 * @return self Cloned action definition with updated success message metadata.
	 */
	public function successMessage(string $message): self {
		$clone=clone $this;
		$clone->successMessage=trim($message) ?: null;
		return $clone;
	}

	/**
	 * Updates the redirect to metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param ?string $url Url.
	 * @return self Cloned action definition with updated redirect to metadata.
	 */
	public function redirectTo(?string $url): self {
		$clone=clone $this;
		$url=trim((string)$url);
		$clone->redirectTo=$url!=='' ? $url : null;
		return $clone;
	}

	/**
	 * Updates the effects metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array{close_modal?:bool,refresh?:string|array<int,mixed>,event?:string|array<string,mixed>|array<int,mixed>,events?:string|array<string,mixed>|array<int,mixed>,dispatch?:string|array<string,mixed>|array<int,mixed>,browser_event?:string|array<string,mixed>|array<int,mixed>,browser_events?:string|array<string,mixed>|array<int,mixed>} $effects Renderer/runtime side effects to merge into action metadata.
	 * @param bool $merge Whether to merge with existing effects instead of replacing them.
	 * @return self Cloned action definition with updated effects metadata.
	 */
	public function effects(array $effects, bool $merge=true): self {
		$clone=clone $this;
		$current=is_array($clone->meta['effects'] ?? null) ? $clone->meta['effects'] : [];
		$clone->meta['effects']=$merge ? self::mergeEffects($current, $effects) : self::normalizeEffects($effects);
		return $clone;
	}

	/**
	 * Updates the refresh metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string|array $targets Targets.
	 * @return self Cloned action definition with updated refresh metadata.
	 */
	public function refresh(string|array $targets='panel'): self {
		return $this->effects([
			'refresh'=>self::normalizeEffectTargets($targets),
		]);
	}

	/**
	 * Updates the refresh panel metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated refresh panel metadata.
	 */
	public function refreshPanel(): self {
		return $this->refresh('panel');
	}

	/**
	 * Updates the refresh table metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param ?string $table Table.
	 * @return self Cloned action definition with updated refresh table metadata.
	 */
	public function refreshTable(?string $table=null): self {
		return $this->refresh($table===null || trim($table)==='' ? 'table' : 'table:'.trim($table));
	}

	/**
	 * Updates the refresh widgets metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated refresh widgets metadata.
	 */
	public function refreshWidgets(): self {
		return $this->refresh('widgets');
	}

	/**
	 * Updates the refresh navigation metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated refresh navigation metadata.
	 */
	public function refreshNavigation(): self {
		return $this->refresh('navigation');
	}

	/**
	 * Updates the without refresh metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated without refresh metadata.
	 */
	public function withoutRefresh(): self {
		return $this->effects([
			'refresh'=>[],
		], false);
	}

	/**
	 * Updates the close modal metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool $close Whether the renderer should close the action modal after completion.
	 * @return self Cloned action definition with updated close modal metadata.
	 */
	public function closeModal(bool $close=true): self {
		return $this->effects([
			'close_modal'=>$close,
		]);
	}

	/**
	 * Updates the keep modal open metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return self Cloned action definition with updated keep modal open metadata.
	 */
	public function keepModalOpen(): self {
		return $this->closeModal(false);
	}

	/**
	 * Updates the dispatch browser event metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param string $name Normalized manifest object name.
	 * @param array<string,mixed> $detail Browser event detail payload.
	 * @return self Cloned action definition with updated dispatch browser event metadata.
	 */
	public function dispatchBrowserEvent(string $name, array $detail=[]): self {
		$name=trim($name);
		if($name===''){
			return $this;
		}
		return $this->effects([
			'events'=>[
				[
					'name'=>$name,
					'detail'=>$detail,
				],
			],
		]);
	}

	/**
	 * Updates the handle metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $handler Lifecycle callback invoked before submitted action data is validated.
	 * @return self Cloned action definition with updated handle metadata.
	 */
	public function handle(callable $handler): self {
		$clone=clone $this;
		$clone->handler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Updates the mutate data using metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $mutator Callback that normalizes submitted action data before validation or execution.
	 * @return self Cloned action definition with updated mutate data using metadata.
	 */
	public function mutateDataUsing(callable $mutator): self {
		$clone=clone $this;
		$clone->dataMutator=\Closure::fromCallable($mutator);
		return $clone;
	}

	/**
	 * Updates the mutate form data using metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param callable $mutator Callback that normalizes submitted action data before validation or execution.
	 * @return self Cloned action definition with updated mutate form data using metadata.
	 */
	public function mutateFormDataUsing(callable $mutator): self {
		return $this->mutateDataUsing($mutator);
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $handler Lifecycle callback invoked after submitted action data is validated.
	 * @return self Cloned action definition with updated before validate using metadata.
	 */
	public function beforeValidateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->beforeValidateHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $handler Callback that executes the action with record, data, request, resource, and action context.
	 * @return self Cloned action definition with updated after validate using metadata.
	 */
	public function afterValidateUsing(callable $handler): self {
		$clone=clone $this;
		$clone->afterValidateHandler=\Closure::fromCallable($handler);
		return $clone;
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $hook Lifecycle callback invoked before the action handler executes.
	 * @return self Cloned action definition with updated before metadata.
	 */
	public function before(callable $hook): self {
		$clone=clone $this;
		$clone->beforeHooks[]=\Closure::fromCallable($hook);
		return $clone;
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $hook Lifecycle callback invoked before the action handler executes.
	 * @return self Cloned action definition with updated before action using metadata.
	 */
	public function beforeActionUsing(callable $hook): self {
		return $this->before($hook);
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $hook Lifecycle callback invoked after the action handler returns.
	 * @return self Cloned action definition with updated after metadata.
	 */
	public function after(callable $hook): self {
		$clone=clone $this;
		$clone->afterHooks[]=\Closure::fromCallable($hook);
		return $clone;
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $hook Lifecycle callback invoked after the action handler returns.
	 * @return self Cloned action definition with updated after action using metadata.
	 */
	public function afterActionUsing(callable $hook): self {
		return $this->after($hook);
	}

	/**
	 * Configures action execution callbacks.
	 *
	 * Callbacks run around validation and handler execution so actions can mutate data, report success, redirect, or handle failure.
	 *
	 * @param callable $hook Lifecycle callback invoked when validation or action execution fails.
	 * @return self Cloned action definition with updated failure metadata.
	 */
	public function failure(callable $hook): self {
		$clone=clone $this;
		$clone->failureHooks[]=\Closure::fromCallable($hook);
		return $clone;
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param callable $authorizer Callback that decides whether the action is allowed for the current record and request context.
	 * @return self Cloned action definition with updated authorize metadata.
	 */
	public function authorize(callable $authorizer): self {
		$clone=clone $this;
		$clone->authorizer=\Closure::fromCallable($authorizer);
		return $clone;
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param bool|callable $condition Static flag or callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated visible metadata.
	 */
	public function visible(bool|callable $condition=true): self {
		$clone=clone $this;
		$clone->visibleResolver=is_callable($condition)
			? \Closure::fromCallable($condition)
			: static fn(): bool => (bool)$condition;
		return $clone;
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param callable $condition Callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated visible using metadata.
	 */
	public function visibleUsing(callable $condition): self {
		return $this->visible($condition);
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param bool|callable $condition Static flag or callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated hidden metadata.
	 */
	public function hidden(bool|callable $condition=true): self {
		$clone=clone $this;
		$clone->hiddenResolver=is_callable($condition)
			? \Closure::fromCallable($condition)
			: static fn(): bool => (bool)$condition;
		return $clone;
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param callable $condition Callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated hidden using metadata.
	 */
	public function hiddenUsing(callable $condition): self {
		return $this->hidden($condition);
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param bool|callable $condition Static flag or callback evaluated against the current record and request context.
	 * @param string|callable|null $reason Static or dynamic explanation shown when the action is disabled.
	 * @return self Cloned action definition with updated disabled metadata.
	 */
	public function disabled(bool|callable $condition=true, string|callable|null $reason=null): self {
		$clone=clone $this;
		$clone->disabledResolver=is_callable($condition)
			? \Closure::fromCallable($condition)
			: static fn(): bool => (bool)$condition;
		if($reason!==null){
			$clone->disabledReason=is_callable($reason) ? \Closure::fromCallable($reason) : (trim($reason) ?: null);
		}
		return $clone;
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param callable $condition Callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated disabled using metadata.
	 */
	public function disabledUsing(callable $condition): self {
		return $this->disabled($condition);
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param string|callable|null $reason Static or dynamic explanation shown when the action is disabled.
	 * @return self Cloned action definition with updated disabled reason metadata.
	 */
	public function disabledReason(string|callable|null $reason): self {
		$clone=clone $this;
		$clone->disabledReason=is_callable($reason) ? \Closure::fromCallable($reason) : ($reason===null ? null : (trim($reason) ?: null));
		return $clone;
	}

	/**
	 * Updates the enabled metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param bool|callable $condition Static flag or callback evaluated against the current record and request context.
	 * @return self Cloned action definition with updated enabled metadata.
	 */
	public function enabled(bool|callable $condition=true): self {
		if(is_callable($condition)){
			return $this->disabled(static fn(mixed $record=null, mixed $user=null, ?Resource $resource=null, ?Action $action=null, mixed $request=null): bool => !((bool)PanelUtilityResolver::evaluate(\Closure::fromCallable($condition), [
				'record'=>$record,
				'user'=>$user,
				'resource'=>$resource,
				'action'=>$action,
				'request'=>$request,
			], ['record', 'user', 'resource', 'action', 'request'])));
		}
		return $this->disabled(!$condition);
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param array<int,Field|array<string,mixed>|string> $fields Form fields attached to the action.
	 * @return self Cloned action definition with updated fields metadata.
	 */
	public function fields(array $fields): self {
		$clone=clone $this;
		$clone->form=$clone->form->fields($fields);
		return $clone;
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param Field|array|string $field Field.
	 * @param ?string $type Type.
	 * @return self Cloned action definition with updated field metadata.
	 */
	public function field(Field|array|string $field, ?string $type=null): self {
		$clone=clone $this;
		$clone->form=$clone->form->field($field, $type);
		return $clone;
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param array<int,FormSection|array<string,mixed>|string> $sections Form sections attached to the action.
	 * @return self Cloned action definition with updated form sections metadata.
	 */
	public function formSections(array $sections): self {
		$clone=clone $this;
		$clone->form=$clone->form->sections($sections);
		return $clone;
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param FormSection|array|string $section Section.
	 * @param ?array $fields Fields.
	 * @return self Cloned action definition with updated form section metadata.
	 */
	public function formSection(FormSection|array|string $section, ?array $fields=null): self {
		$clone=clone $this;
		$clone->form=$clone->form->section($section, $fields);
		return $clone;
	}

	/**
	 * Configures action form and submitted data behavior.
	 *
	 * Form metadata describes fields, data mutation, validation hooks, and payload handed to the action handler.
	 *
	 * @param ?ResourceForm $form Replacement action form, or null to read the current form.
	 * @return ResourceForm|self Action form when read, or a cloned action with updated form configuration.
	 */
	public function form(?ResourceForm $form=null): ResourceForm|self {
		if($form===null){
			return $this->form;
		}
		$clone=clone $this;
		$clone->form=$form;
		return $clone;
	}

	/**
	 * Updates the schema metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param Schema $schema Form schema assigned to the action form.
	 * @return self Cloned action definition with updated schema metadata.
	 */
	public function schema(Schema $schema): self {
		$clone=clone $this;
		$clone->form=$clone->form->schema($schema);
		return $clone;
	}

	/**
	 * Reports whether fields state is configured.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return bool True when fields state is configured.
	 */
	public function hasFields(): bool {
		return $this->form->fieldsList()!==[];
	}

	/**
	 * Reports whether modal content state is configured.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return bool True when modal content state is configured.
	 */
	public function hasModalContent(): bool {
		return $this->modalContent!==null;
	}

	/**
	 * Resolves the modal content value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return mixed Resolved modal content, or the configured static modal content.
	 */
	public function resolveModalContent(mixed $record=null, mixed $request=null, ?Resource $resource=null): mixed {
		if($this->modalContent instanceof \Closure){
			return $this->invokeCallback($this->modalContent, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action']);
		}
		return $this->modalContent;
	}

	/**
	 * Resolves the label value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return string Resolved display label, falling back to the humanized action name.
	 */
	public function resolveLabel(mixed $record=null, mixed $request=null, ?Resource $resource=null): string {
		$label=$this->label instanceof \Closure
			? $this->invokeCallback($this->label, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->label;
		if(is_scalar($label) || $label===null){
			$label=trim((string)$label);
			return $label!=='' ? $label : self::humanize($this->name);
		}
		return self::humanize($this->name);
	}

	/**
	 * Resolves the icon value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return ?string Resolved icon name, or null when no non-empty icon is configured.
	 */
	public function resolveIcon(mixed $record=null, mixed $request=null, ?Resource $resource=null): ?string {
		$icon=$this->icon instanceof \Closure
			? $this->invokeCallback($this->icon, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->icon;
		if(is_scalar($icon) || $icon===null){
			$icon=trim((string)$icon);
			return $icon!=='' ? $icon : null;
		}
		return null;
	}

	/**
	 * Resolves the tone value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return string Normalized action tone.
	 */
	public function resolveTone(mixed $record=null, mixed $request=null, ?Resource $resource=null): string {
		$tone=$this->tone instanceof \Closure
			? $this->invokeCallback($this->tone, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->tone;
		return self::normalizeTone(is_scalar($tone) ? (string)$tone : 'neutral');
	}

	/**
	 * Resolves the badge value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return ?string Resolved badge text, or null when absent.
	 */
	public function resolveBadge(mixed $record=null, mixed $request=null, ?Resource $resource=null): ?string {
		$badge=$this->badge instanceof \Closure
			? $this->invokeCallback($this->badge, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->badge;
		if($badge===null || $badge===false){
			return null;
		}
		if(is_scalar($badge) || $badge instanceof \Stringable){
			$badge=trim((string)$badge);
			return $badge!=='' ? $badge : null;
		}
		return null;
	}

	/**
	 * Resolves the badge tone value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return string Normalized badge tone.
	 */
	public function resolveBadgeTone(mixed $record=null, mixed $request=null, ?Resource $resource=null): string {
		$tone=$this->badgeTone instanceof \Closure
			? $this->invokeCallback($this->badgeTone, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->badgeTone;
		return self::normalizeTone(is_scalar($tone) ? (string)$tone : 'neutral');
	}

	/**
	 * Resolves the tooltip value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return ?string Resolved tooltip text, or null when absent.
	 */
	public function resolveTooltip(mixed $record=null, mixed $request=null, ?Resource $resource=null): ?string {
		$tooltip=$this->tooltip instanceof \Closure
			? $this->invokeCallback($this->tooltip, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->tooltip;
		if($tooltip===null || $tooltip===false){
			return null;
		}
		if(is_scalar($tooltip) || $tooltip instanceof \Stringable){
			$tooltip=trim((string)$tooltip);
			return $tooltip!=='' ? $tooltip : null;
		}
		return null;
	}

	/**
	 * Resolves the description value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return ?string Resolved description text, or null when absent.
	 */
	public function resolveDescription(mixed $record=null, mixed $request=null, ?Resource $resource=null): ?string {
		$description=$this->description instanceof \Closure
			? $this->invokeCallback($this->description, [
				'record'=>$record,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'request', 'resource', 'action'])
			: $this->description;
		if($description===null || $description===false){
			return null;
		}
		if(is_scalar($description) || $description instanceof \Stringable){
			$description=trim((string)$description);
			return $description!=='' ? $description : null;
		}
		return null;
	}

	/**
	 * Resolves the extra attributes value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return array<string,string> HTML-safe action attributes merged from static and callback sources.
	 */
	public function resolveExtraAttributes(mixed $record=null, mixed $request=null, ?Resource $resource=null): array {
		$attributes=[];
		foreach($this->extraAttributes as $set){
			$resolved=$set instanceof \Closure
				? $this->invokeCallback($set, [
					'record'=>$record,
					'request'=>$request,
					'resource'=>$resource,
					'action'=>$this,
				], ['record', 'request', 'resource', 'action'])
				: $set;
			if(is_array($resolved)){
				$attributes=array_replace($attributes, self::normalizeExtraAttributes($resolved));
			}
		}
		return $attributes;
	}

	/**
	 * Resolves the resolved meta value for rendering.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param ?PanelRequest $request Panel request used to resolve user, resource, and table state.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return array<string,mixed> Renderer metadata with dynamic label, icon, tone, badge, tooltip, description, key bindings, attributes, and modal defaults.
	 */
	public function resolvedMeta(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null): array {
		$meta=$this->toArray();
		$meta['label']=$this->resolveLabel($record, $request, $resource);
		$meta['icon']=$this->resolveIcon($record, $request, $resource);
		$meta['tone']=$this->resolveTone($record, $request, $resource);
		$meta['badge']=$this->resolveBadge($record, $request, $resource);
		$meta['badge_tone']=$this->resolveBadgeTone($record, $request, $resource);
		$meta['tooltip']=$this->resolveTooltip($record, $request, $resource);
		$meta['description']=$this->resolveDescription($record, $request, $resource);
		$meta['key_bindings']=$this->keyBindings;
		$meta['extra_attributes']=$this->resolveExtraAttributes($record, $request, $resource);
		if(($meta['modal_heading'] ?? null)===null){
			$meta['modal_heading']=$meta['label'];
		}
		if(($meta['modal_submit_label'] ?? null)===null){
			$meta['modal_submit_label']=$meta['label'];
		}
		return $meta;
	}

	/**
	 * Evaluates the can condition for the current context.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $user Current user or actor passed to authorization callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return bool True when permission bridge and action authorizer allow the current context.
	 */
	public function can(mixed $record=null, mixed $user=null, ?Resource $resource=null): bool {
		if(self::permissionAllows($this->name, $resource, $user, $record)===false){
			return false;
		}
		if($this->authorizer!==null){
			return (bool)($this->authorizer)($record, $user, $resource, $this);
		}
		return true;
	}

	/**
	 * Evaluates the is visible condition for the current context.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $user Current user or actor passed to authorization callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @return bool True when hidden and visible callbacks allow the current context.
	 */
	public function isVisible(mixed $record=null, mixed $user=null, ?Resource $resource=null, mixed $request=null): bool {
		$context=[
			'record'=>$record,
			'user'=>$user,
			'resource'=>$resource,
			'action'=>$this,
			'request'=>$request,
		];
		if($this->hiddenResolver!==null && (bool)$this->invokeCallback($this->hiddenResolver, $context, ['record', 'user', 'resource', 'action', 'request'])){
			return false;
		}
		if($this->visibleResolver!==null){
			return (bool)$this->invokeCallback($this->visibleResolver, $context, ['record', 'user', 'resource', 'action', 'request']);
		}
		return true;
	}

	/**
	 * Evaluates the is disabled condition for the current context.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $user Current user or actor passed to authorization callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @return bool True when the disabled callback marks the action unavailable.
	 */
	public function isDisabled(mixed $record=null, mixed $user=null, ?Resource $resource=null, mixed $request=null): bool {
		if($this->disabledResolver===null){
			return false;
		}
		return (bool)$this->invokeCallback($this->disabledResolver, [
			'record'=>$record,
			'user'=>$user,
			'resource'=>$resource,
			'action'=>$this,
			'request'=>$request,
		], ['record', 'user', 'resource', 'action', 'request']);
	}

	/**
	 * Configures action availability rules.
	 *
	 * Availability callbacks decide whether the action is visible, hidden, disabled, or authorized for the current record/request context.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $user Current user or actor passed to authorization callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @return ?string Resolved disabled reason, default reason, or null when the action is enabled.
	 */
	public function disabledReasonFor(mixed $record=null, mixed $user=null, ?Resource $resource=null, mixed $request=null): ?string {
		if($this->disabledReason===null){
			return $this->isDisabled($record, $user, $resource, $request) ? 'This action is not available right now.' : null;
		}
		$reason=$this->disabledReason instanceof \Closure
			? $this->invokeCallback($this->disabledReason, [
				'record'=>$record,
				'user'=>$user,
				'resource'=>$resource,
				'action'=>$this,
				'request'=>$request,
			], ['record', 'user', 'resource', 'action', 'request'])
			: $this->disabledReason;
		if(is_bool($reason)){
			$reason=$reason ? 'This action is not available right now.' : '';
		}
		if(is_scalar($reason) || $reason===null){
			$reason=trim((string)$reason);
			return $reason!=='' ? $reason : null;
		}
		return null;
	}

	/**
	 * Builds the action state object used by renderers and command responses.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param ?PanelRequest $request Panel request used to resolve user, resource, and table state.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param string $mode Normalized action mode such as action, bulk, table, or row.
	 * @param ?PanelFormState $formState Precomputed form state, or null to derive it from the action form.
	 * @param array<string,mixed> $data Submitted or prefilled action form data.
	 * @param mixed $result Current handler or lifecycle result.
	 * @param ?PanelLifecycleResult $lifecycle Lifecycle result already produced for the action state.
	 * @param array<string,mixed> $meta Additional state metadata layered over derived record/request values.
	 * @return PanelActionState Action state for rendering or command responses.
	 */
	public function state(
		mixed $record=null,
		?PanelRequest $request=null,
		?Resource $resource=null,
		string $mode='action',
		?PanelFormState $formState=null,
		array $data=[],
		mixed $result=null,
		?PanelLifecycleResult $lifecycle=null,
		array $meta=[]
	): PanelActionState {
		$mode=Resource::normalizeName($mode) ?: 'action';
		if($formState===null && $this->hasFields()){
			$formState=$this->form->state($record, $request, $mode, $data);
		}
		$selectedCount=null;
		$recordKey=null;
		$recordTitle=null;
		if(is_array($record) && $this->bulk){
			$selectedCount=count($record);
		}
		elseif($record!==null && $resource instanceof Resource){
			$recordKey=$resource->recordKey($record);
			$recordTitle=$resource->recordTitle($record);
		}
		$meta=array_replace([
			'stage'=>'ready',
			'record_key'=>$recordKey,
			'record_title'=>$recordTitle,
			'selected_count'=>$selectedCount,
			'authorized'=>$this->can($record, $request?->user(), $resource),
			'visible'=>$this->isVisible($record, $request?->user(), $resource, $request),
			'disabled'=>$this->isDisabled($record, $request?->user(), $resource, $request),
			'disabled_reason'=>$this->disabledReasonFor($record, $request?->user(), $resource, $request),
			'request'=>$request?->toArray(),
			'resource'=>$resource?->name(),
		], $meta);
		return new PanelActionState($this->resolvedMeta($record, $request, $resource), $mode, $formState, $data, $result, $lifecycle, $meta);
	}

	/**
	 * Serializes the action manifest for renderers and clients.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param ?PanelRequest $request Panel request used to resolve user, resource, and table state.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param string $mode Normalized action mode such as action, bulk, table, or row.
	 * @param array<string,mixed> $meta Extra manifest metadata for the requested action state.
	 * @return array<string,mixed> Serialized action manifest for renderers and clients.
	 */
	public function manifest(mixed $record=null, ?PanelRequest $request=null, ?Resource $resource=null, string $mode='action', array $meta=[]): array {
		return ActionManifest::from($this, $record, $request, $resource, $mode, $meta)->toArray();
	}

	/**
	 * Checks the optional Panel permission bridge for an action permission.
	 *
	 * When no resource is available or the bridge is not configured, actions remain
	 * locally allowed and resource-specific can()/visibility rules decide behavior.
	 * Configured bridges receive a normalized resource/action permission name plus
	 * record context.
	 *
	 * @param string $action Action name being checked.
	 * @param ?Resource $resource Owning resource, when available.
	 * @param mixed $user Current user or actor.
	 * @param mixed $record Record targeted by the action.
	 * @return bool Whether the bridge allows the action.
	 */
	private static function permissionAllows(string $action, ?Resource $resource=null, mixed $user=null, mixed $record=null): bool {
		if(!$resource instanceof Resource || !PanelPermissionBridge::configured()){
			return true;
		}
		return PanelPermissionBridge::allows(PanelPermissionBridge::actionName($resource->name(), $action), $user, [
			'resource'=>$resource->name(),
			'action'=>Resource::normalizeName($action),
			'record'=>$record,
		]);
	}

	/**
	 * Executes the action handler with lifecycle hooks and failure handling.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param array<string,mixed> $data Submitted action form data.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @param bool $runLifecycle Whether mutate/before/after hooks should wrap handler execution.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @return mixed Handler result, lifecycle halt, or failure hook result.
	 */
	public function execute(mixed $record=null, array $data=[], ?Resource $resource=null, bool $runLifecycle=true, mixed $request=null): mixed {
		if($this->handler===null){
			throw new \LogicException("Panel action '{$this->name}' has no handler.");
		}
		if($runLifecycle){
			$data=$this->mutateFormData($data, $record, $request, $resource);
			$before=$this->runBeforeAction($data, $record, $request, $resource);
			if($before!==null){
				return $before;
			}
		}
		try{
			$result=$this->invokeCallback($this->handler, [
				'record'=>$record,
				'data'=>$data,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'data', 'request', 'resource', 'action']);
		}
		catch(\Throwable $exception){
			foreach($this->failureHooks as $hook){
				$result=$this->invokeCallback($hook, [
					'exception'=>$exception,
					'error'=>$exception,
					'record'=>$record,
					'data'=>$data,
					'request'=>$request,
					'resource'=>$resource,
					'action'=>$this,
				], ['exception', 'record', 'data', 'request', 'resource', 'action']);
				if($result!==null){
					return $result;
				}
			}
			throw $exception;
		}
		if($runLifecycle){
			$result=$this->runAfterAction($result, $data, $record, $request, $resource);
		}
		return $result;
	}

	/**
	 * Runs the configured form-data mutator for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array<string,mixed> $data Submitted action form data.
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return array<string,mixed> Mutated form data, or the original data when the mutator returns a non-array value.
	 */
	public function mutateFormData(array $data, mixed $record=null, mixed $request=null, ?Resource $resource=null): array {
		if($this->dataMutator===null){
			return $data;
		}
		$mutated=$this->invokeCallback($this->dataMutator, [
			'data'=>$data,
			'record'=>$record,
			'request'=>$request,
			'resource'=>$resource,
			'action'=>$this,
		], ['data', 'record', 'resource', 'action']);
		return is_array($mutated) ? $mutated : $data;
	}

	/**
	 * Runs the before validate lifecycle step.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return ?PanelLifecycleResult Lifecycle halt result, or null to continue validation.
	 */
	public function runBeforeValidate(mixed $record=null, mixed $request=null, ?Resource $resource=null): ?PanelLifecycleResult {
		if($this->beforeValidateHandler===null){
			return null;
		}
		$result=$this->invokeCallback($this->beforeValidateHandler, [
			'record'=>$record,
			'request'=>$request,
			'resource'=>$resource,
			'action'=>$this,
		], ['record', 'request', 'resource', 'action']);
		return $this->normalizeLifecycleResult($result);
	}

	/**
	 * Runs the after validate lifecycle step.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param PanelFormState $state Validated action form state.
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return PanelFormState|PanelLifecycleResult Mutated form state or lifecycle halt result.
	 */
	public function runAfterValidate(PanelFormState $state, mixed $record=null, mixed $request=null, ?Resource $resource=null): PanelFormState|PanelLifecycleResult {
		if($this->afterValidateHandler===null){
			return $state;
		}
		$result=$this->invokeCallback($this->afterValidateHandler, [
			'state'=>$state,
			'record'=>$record,
			'request'=>$request,
			'resource'=>$resource,
			'action'=>$this,
		], ['state', 'record', 'request', 'resource', 'action']);
		return $result instanceof PanelFormState ? $result : ($this->normalizeLifecycleResult($result) ?? $state);
	}

	/**
	 * Runs the before action lifecycle step.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array<string,mixed> $data Submitted action form data.
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return mixed Lifecycle halt, custom before-hook result, or null to continue execution.
	 */
	public function runBeforeAction(array $data, mixed $record=null, mixed $request=null, ?Resource $resource=null): mixed {
		foreach($this->beforeHooks as $hook){
			$result=$this->invokeCallback($hook, [
				'record'=>$record,
				'data'=>$data,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['record', 'data', 'resource', 'action']);
			$lifecycle=$this->normalizeLifecycleResult($result);
			if($lifecycle instanceof PanelLifecycleResult){
				return $lifecycle;
			}
			if($result!==null && $result!==true){
				return $result;
			}
		}
		return null;
	}

	/**
	 * Runs the after action lifecycle step.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param mixed $result Current handler or lifecycle result.
	 * @param array<string,mixed> $data Submitted action form data.
	 * @param mixed $record Record or selected records targeted by the action.
	 * @param mixed $request Panel request or request-like context passed to callbacks.
	 * @param ?Resource $resource Owning resource, when the action is resource-scoped.
	 * @return mixed action result after after-hooks apply replacements or halt signals.
	 */
	public function runAfterAction(mixed $result, array $data, mixed $record=null, mixed $request=null, ?Resource $resource=null): mixed {
		foreach($this->afterHooks as $hook){
			$next=$this->invokeCallback($hook, [
				'result'=>$result,
				'record'=>$record,
				'data'=>$data,
				'request'=>$request,
				'resource'=>$resource,
				'action'=>$this,
			], ['result', 'record', 'data', 'resource', 'action']);
			if($next!==null){
				$result=$this->normalizeLifecycleResult($next) ?? $next;
			}
		}
		return $result;
	}

	/**
	 * Updates the meta metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 *
	 * @param array<string,mixed> $meta Action metadata merged into the serialized manifest.
	 * @return self Cloned action definition with updated meta metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->meta=array_replace($clone->meta, $meta);
		return $clone;
	}

	/**
	 * Updates the to array metadata for this action.
	 *
	 * Action metadata drives buttons, menu items, bulk operations, confirmation flows, and command execution.
	 * @return array{name:string,label:string,label_dynamic:bool,icon:?string,icon_dynamic:bool,tone:string,tone_dynamic:bool,style:string,size:string,icon_only:bool,description:?string,description_dynamic:bool,badge:?string,badge_dynamic:bool,badge_tone:string,badge_tone_dynamic:bool,tooltip:?string,tooltip_dynamic:bool,key_bindings:array<int,string>,extra_attributes:array<string,mixed>,extra_attributes_dynamic:bool,requires_confirmation:bool,modal:bool,modal_heading:?string,modal_description:?string,modal_submit_label:?string,modal_cancel_label:?string,modal_width:string,has_modal_content:bool,modal_stack:string,bulk:bool,allow_empty_selection:bool,success_message:?string,redirect_to:?string,effects:array<string,mixed>,fields:array<string,mixed>,has_handler:bool,authorizes:bool,has_visibility:bool,disables:bool,disabled_reason:?string,disabled_reason_dynamic:bool,mutates_data:bool,mutates_form_data:bool,lifecycle:array{before_validate:bool,after_validate:bool,before_action:int,after_action:int},before_hooks:int,after_hooks:int,failure_hooks:int,meta:array<string,mixed>} Serialized action definition.
	 */
	public function toArray(): array {
		return [
			'name'=>$this->name,
			'label'=>is_string($this->label) ? $this->label : self::humanize($this->name),
			'label_dynamic'=>$this->label instanceof \Closure,
			'icon'=>is_string($this->icon) ? $this->icon : null,
			'icon_dynamic'=>$this->icon instanceof \Closure,
			'tone'=>is_string($this->tone) ? $this->tone : 'neutral',
			'tone_dynamic'=>$this->tone instanceof \Closure,
			'style'=>$this->style,
			'size'=>$this->size,
			'icon_only'=>$this->iconOnly,
			'description'=>is_string($this->description) ? $this->description : null,
			'description_dynamic'=>$this->description instanceof \Closure,
			'badge'=>is_scalar($this->badge) ? (string)$this->badge : null,
			'badge_dynamic'=>$this->badge instanceof \Closure,
			'badge_tone'=>is_string($this->badgeTone) ? $this->badgeTone : 'neutral',
			'badge_tone_dynamic'=>$this->badgeTone instanceof \Closure,
			'tooltip'=>is_string($this->tooltip) ? $this->tooltip : null,
			'tooltip_dynamic'=>$this->tooltip instanceof \Closure,
			'key_bindings'=>$this->keyBindings,
			'extra_attributes'=>$this->staticExtraAttributes(),
			'extra_attributes_dynamic'=>$this->hasDynamicExtraAttributes(),
			'requires_confirmation'=>$this->requiresConfirmation,
			'modal'=>$this->modal,
			'modal_heading'=>$this->modalHeading,
			'modal_description'=>$this->modalDescription,
			'modal_submit_label'=>$this->modalSubmitLabel,
			'modal_cancel_label'=>$this->modalCancelLabel,
			'modal_width'=>$this->modalWidth,
			'has_modal_content'=>$this->modalContent!==null,
			'modal_stack'=>is_string($this->meta['modal_stack'] ?? null) ? $this->meta['modal_stack'] : (($this->meta['modal_back'] ?? false)===true ? 'push' : 'replace'),
			'bulk'=>$this->bulk,
			'allow_empty_selection'=>$this->allowEmptySelection,
			'success_message'=>$this->successMessage,
			'redirect_to'=>$this->redirectTo,
			'effects'=>is_array($this->meta['effects'] ?? null) ? self::normalizeEffects($this->meta['effects']) : [],
			'fields'=>$this->form->toArray(),
			'has_handler'=>$this->handler!==null,
			'authorizes'=>$this->authorizer!==null,
			'has_visibility'=>$this->visibleResolver!==null || $this->hiddenResolver!==null,
			'disables'=>$this->disabledResolver!==null,
			'disabled_reason'=>is_string($this->disabledReason) ? $this->disabledReason : null,
			'disabled_reason_dynamic'=>$this->disabledReason instanceof \Closure,
			'mutates_data'=>$this->dataMutator!==null,
			'mutates_form_data'=>$this->dataMutator!==null,
			'lifecycle'=>[
				'before_validate'=>$this->beforeValidateHandler!==null,
				'after_validate'=>$this->afterValidateHandler!==null,
				'before_action'=>count($this->beforeHooks),
				'after_action'=>count($this->afterHooks),
			],
			'before_hooks'=>count($this->beforeHooks),
			'after_hooks'=>count($this->afterHooks),
			'failure_hooks'=>count($this->failureHooks),
			'meta'=>$this->meta,
		];
	}

	/**
	 * Evaluates an action callback with named and positional context.
	 *
	 * PanelUtilityResolver matches callback parameters by name when possible and
	 * falls back to the supplied positional order for compact action closures.
	 *
	 * @param \Closure $callback Action callback or lifecycle hook.
	 * @param array<string,mixed> $values Named evaluation context.
	 * @param array<int,string> $positionOrder Preferred positional parameter order.
	 * @return mixed callback value after named or positional panel utilities are injected.
	 */
	private function invokeCallback(\Closure $callback, array $values, array $positionOrder=[]): mixed {
		return PanelUtilityResolver::evaluate($callback, $values, $positionOrder);
	}

	/**
	 * Converts lifecycle hook return values into an action halt result.
	 *
	 * Hooks may return an existing PanelLifecycleResult, false, or an array with
	 * halt/halted metadata. Other values remain regular hook results for the
	 * caller to interpret.
	 *
	 * @param mixed $result Raw lifecycle hook result.
	 * @return ?PanelLifecycleResult Halt result, or null when the value is not a halt signal.
	 */
	private function normalizeLifecycleResult(mixed $result): ?PanelLifecycleResult {
		if($result instanceof PanelLifecycleResult){
			return $result;
		}
		if($result===false){
			return PanelLifecycleResult::halt('The action was stopped.');
		}
		if(is_array($result) && (($result['halt'] ?? false)===true || ($result['halted'] ?? false)===true)){
			$notifications=[];
			foreach(['notification', 'notifications'] as $key){
				if(!array_key_exists($key, $result)){
					continue;
				}
				$items=is_array($result[$key]) && array_is_list($result[$key]) ? $result[$key] : [$result[$key]];
				$notifications=array_merge($notifications, $items);
			}
			return PanelLifecycleResult::halt((string)($result['message'] ?? 'The action was stopped.'), $notifications, (int)($result['status'] ?? 422), $result);
		}
		return null;
	}

	/**
	 * Normalizes action-facing manifest keys.
	 *
	 * Names are lower-cased, unsupported characters collapse to underscores, and
	 * leading/trailing separators are removed for stable lookup and HTML metadata.
	 *
	 * @param string $name Raw manifest key.
	 * @return string Normalized key.
	 */
	private static function normalizeName(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.-]+/', '_', $name) ?? '';
		return trim($name, '_.-');
	}

	/**
	 * Merges action effect declarations without losing existing refresh/events.
	 *
	 * close_modal is overwritten by the newest value, refresh targets are unioned,
	 * and browser events are appended in declaration order.
	 *
	 * @param array<string,mixed> $current Existing normalized or raw effects.
	 * @param array<string,mixed> $next Effects being added.
	 * @return array<string,mixed> Normalized merged effects.
	 */
	private static function mergeEffects(array $current, array $next): array {
		$current=self::normalizeEffects($current);
		$next=self::normalizeEffects($next);
		if(array_key_exists('close_modal', $next)){
			$current['close_modal']=$next['close_modal'];
		}
		if(array_key_exists('refresh', $next)){
			$current['refresh']=array_values(array_unique(array_merge($current['refresh'] ?? [], $next['refresh'])));
		}
		if(array_key_exists('events', $next)){
			$current['events']=array_merge($current['events'] ?? [], $next['events']);
		}
		return self::normalizeEffects($current);
	}

	/**
	 * Normalizes action effects into the renderer/runtime contract.
	 *
	 * Supported effects include close_modal, refresh targets, and browser event
	 * dispatches. Event definitions may be strings or arrays with name/event and
	 * detail/data payloads.
	 *
	 * @param array<string,mixed> $effects Raw effect declaration.
	 * @return array<string,mixed> Normalized effect payload.
	 */
	private static function normalizeEffects(array $effects): array {
		$normalized=[];
		if(array_key_exists('close_modal', $effects)){
			$normalized['close_modal']=(bool)$effects['close_modal'];
		}
		if(array_key_exists('refresh', $effects)){
			$normalized['refresh']=self::normalizeEffectTargets($effects['refresh']);
		}
		foreach(['event', 'events', 'dispatch', 'browser_event', 'browser_events'] as $key){
			if(!array_key_exists($key, $effects)){
				continue;
			}
			$events=is_array($effects[$key]) && array_is_list($effects[$key]) ? $effects[$key] : [$effects[$key]];
			foreach($events as $event){
				if(is_string($event)){
					$name=trim($event);
					$detail=[];
				}
				elseif(is_array($event)){
					$name=trim((string)($event['name'] ?? $event['event'] ?? ''));
					$detail=is_array($event['detail'] ?? null) ? $event['detail'] : (is_array($event['data'] ?? null) ? $event['data'] : []);
				}
				else{
					continue;
				}
				if($name!==''){
					$normalized['events'][]=[
						'name'=>$name,
						'detail'=>$detail,
					];
				}
			}
		}
		if(isset($normalized['events'])){
			$normalized['events']=array_values($normalized['events']);
		}
		return $normalized;
	}

	/**
	 * Normalizes action refresh target identifiers.
	 *
	 * String targets may be comma or whitespace separated; array targets are
	 * filtered to scalars. Unsupported characters collapse to underscores and
	 * duplicate targets are removed.
	 *
	 * @param string|array<int,mixed> $targets Raw refresh target declaration.
	 * @return array<int,string> Normalized unique target identifiers.
	 */
	private static function normalizeEffectTargets(string|array $targets): array {
		$values=is_array($targets) ? $targets : preg_split('/[\s,]+/', $targets);
		$normalized=[];
		foreach($values ?: [] as $target){
			if(!is_scalar($target)){
				continue;
			}
			$target=strtolower(trim((string)$target));
			$target=preg_replace('/[^a-z0-9_:.\\-]+/', '_', $target) ?? '';
			$target=trim($target, '_');
			if($target!=='' && !in_array($target, $normalized, true)){
				$normalized[]=$target;
			}
		}
		return $normalized;
	}

	/**
	 * Builds a display label from an action key or status token.
	 *
	 * Common separators become spaces and each word is title-cased, giving unnamed
	 * actions a readable default label.
	 *
	 * @param string $value Machine key.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? '' : ucwords($value);
	}

	/**
	 * Restricts action tone values to the supported design tokens.
	 *
	 * Unknown tones fall back to neutral so manifests cannot leak arbitrary style
	 * classes into renderers.
	 *
	 * @param string $tone Raw tone value.
	 * @return string Supported tone token.
	 */
	private static function normalizeTone(string $tone): string {
		$tone=strtolower(trim($tone));
		return in_array($tone, ['neutral', 'primary', 'success', 'warning', 'danger', 'info'], true) ? $tone : 'neutral';
	}

	/**
	 * Normalizes a keyboard shortcut into Panel's keybinding format.
	 *
	 * Modifier aliases such as command/cmd/control/option are canonicalized,
	 * modifiers are ordered deterministically, and bindings without a final key are
	 * discarded.
	 *
	 * @param string $binding Raw shortcut text.
	 * @return string Normalized shortcut, or an empty string.
	 */
	private static function normalizeKeyBinding(string $binding): string {
		$binding=strtolower(trim($binding));
		$binding=str_replace(['+', ' '], '+', $binding);
		$parts=array_values(array_filter(explode('+', $binding), static fn(string $part): bool => $part!==''));
		if($parts===[]){
			return '';
		}
		$aliases=[
			'cmd'=>'mod',
			'command'=>'mod',
			'option'=>'alt',
			'control'=>'ctrl',
			'ctl'=>'ctrl',
			'esc'=>'escape',
			'return'=>'enter',
			'del'=>'delete',
			'plus'=>'=',
		];
		$modifiers=[];
		$key='';
		foreach($parts as $part){
			$part=$aliases[$part] ?? $part;
			if(in_array($part, ['mod', 'ctrl', 'meta', 'alt', 'shift'], true)){
				$modifiers[$part]=true;
				continue;
			}
			$key=$part;
		}
		if($key===''){
			return '';
		}
		$order=['mod', 'ctrl', 'meta', 'alt', 'shift'];
		$normalized=[];
		foreach($order as $modifier){
			if(isset($modifiers[$modifier])){
				$normalized[]=$modifier;
			}
		}
		$normalized[]=$key;
		return implode('+', $normalized);
	}

	/**
	 * Normalizes caller-supplied HTML attributes for an action element.
	 *
	 * Integer keys may declare boolean attributes, unsafe/internal attributes are
	 * rejected, and only scalar, stringable, boolean, or null values are retained.
	 *
	 * @param array<string|int,mixed> $attributes Raw extra attributes.
	 * @return array<string,mixed> Safe static attribute map.
	 */
	private static function normalizeExtraAttributes(array $attributes): array {
		$normalized=[];
		foreach($attributes as $name=>$value){
			if(is_int($name) && is_string($value)){
				$name=$value;
				$value=true;
			}
			if(!is_string($name)){
				continue;
			}
			$name=strtolower(trim($name));
			if(!self::isAllowedExtraAttribute($name)){
				continue;
			}
			if($value===null || $value===false){
				$normalized[$name]=$value;
				continue;
			}
			if($value===true || is_scalar($value) || $value instanceof \Stringable){
				$normalized[$name]=$value;
			}
		}
		return $normalized;
	}

	/**
	 * Collects static extra attributes from the action definition.
	 *
	 * Dynamic attribute resolvers are intentionally skipped here and represented in
	 * the manifest through extra_attributes_dynamic.
	 *
	 * @return array<string,mixed> Merged static attributes.
	 */
	private function staticExtraAttributes(): array {
		$attributes=[];
		foreach($this->extraAttributes as $set){
			if(is_array($set)){
				$attributes=array_replace($attributes, $set);
			}
		}
		return $attributes;
	}

	/**
	 * Reports whether the action has runtime-resolved extra attributes.
	 *
	 * Renderers use this flag to know when action state must be evaluated before
	 * final attributes can be produced.
	 *
	 * @return bool Whether any extra attribute set is a closure.
	 */
	private function hasDynamicExtraAttributes(): bool {
		foreach($this->extraAttributes as $set){
			if($set instanceof \Closure){
				return true;
			}
		}
		return false;
	}

	/**
	 * Enforces the extra-attribute safety boundary for action elements.
	 *
	 * Caller attributes may use class, safe data-* and aria-* names, or a small
	 * allowlist of generic HTML attributes. Internal data-dp-panel-* attributes and
	 * renderer-owned aria state are reserved.
	 *
	 * @param string $name Lowercase attribute name.
	 * @return bool Whether the attribute can be exposed.
	 */
	private static function isAllowedExtraAttribute(string $name): bool {
		if($name==='class'){
			return true;
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
	 * Normalizes one data-* or aria-* attribute suffix.
	 *
	 * Unsupported characters become hyphens so fluent helpers such as data() and
	 * aria() produce valid HTML attribute names.
	 *
	 * @param string $name Raw attribute suffix.
	 * @return string Normalized attribute suffix.
	 */
	private static function normalizeAttributeSegment(string $name): string {
		$name=strtolower(trim($name));
		$name=preg_replace('/[^a-z0-9_.:-]+/', '-', $name) ?? '';
		return trim($name, '-');
	}
}
