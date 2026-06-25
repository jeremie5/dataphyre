<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable display-entry builder for panel infolists.
 *
 * InfolistEntry wraps a Field and forces read-only entry metadata so the same
 * field rendering pipeline can describe resource details without exposing form
 * mutation behavior. Every fluent modifier returns a cloned entry with a cloned
 * Field, preserving builder immutability for shared resource definitions.
 */
final class InfolistEntry {
	use PanelExtensible;

	private Field $field;

	/**
	 * Wraps a field as a read-only infolist entry.
	 *
	 * @param Field $field Field definition to normalize for entry rendering.
	 */
	private function __construct(Field $field) {
		$this->field=$field->readonly()->meta(['entry'=>true]);
	}

	/**
	 * Creates an infolist entry from a field name and display type.
	 *
	 * @param string $name Field/data path rendered by the entry.
	 * @param string $type Field display type, such as text, badge, image, or date.
	 * @return self Configured read-only infolist entry.
	 */
	public static function make(string $name, string $type='text'): self {
		return self::configured(new self(Field::make($name, $type)));
	}

	/**
	 * Normalizes supported entry definitions into an InfolistEntry.
	 *
	 * Existing entries are returned unchanged. Field instances and array payloads are
	 * wrapped as read-only entries; strings are treated as field names.
	 *
	 * @param Field|array<string, mixed>|string|self $entry Entry definition.
	 * @param string|null $type Display type applied when the definition does not provide one.
	 * @return self Normalized infolist entry.
	 */
	public static function from(Field|array|string|self $entry, ?string $type=null): self {
		if($entry instanceof self){
			return $entry;
		}
		if($entry instanceof Field){
			return new self($entry);
		}
		if(is_array($entry)){
			return new self(Field::fromArray(array_replace(['type'=>$type ?? 'text'], $entry)));
		}
		return self::make((string)$entry, $type ?? 'text');
	}

	/**
	 * Sets the operator-facing entry label.
	 *
	 * @param string $label Label rendered beside or above the value.
	 * @return self Cloned entry with updated label.
	 */
	public function label(string $label): self {
		return $this->withField($this->field->label($label));
	}

	/**
	 * Sets the display renderer type.
	 *
	 * @param string $type Field renderer type used by panel rendering.
	 * @return self Cloned entry with updated type.
	 */
	public function type(string $type): self {
		return $this->withField($this->field->type($type));
	}

	/**
	 * Assigns the entry to an infolist section.
	 *
	 * @param string $section Section identifier or heading.
	 * @return self Cloned entry with section metadata.
	 */
	public function section(string $section): self {
		return $this->withField($this->field->section($section));
	}

	/**
	 * Sets the icon shown with the entry.
	 *
	 * @param string $icon Icon name understood by the panel renderer.
	 * @return self Cloned entry with icon metadata.
	 */
	public function icon(string $icon): self {
		return $this->withField($this->field->icon($icon));
	}

	/**
	 * Renders the value as a badge with optional tone rules.
	 *
	 * @param array<string, mixed>|string $tones Static tone or value-to-tone map.
	 * @return self Cloned entry configured for badge display.
	 */
	public function badge(array|string $tones=[]): self {
		return $this->withField($this->field->badge($tones));
	}

	/**
	 * Controls whether the rendered value exposes copy-to-clipboard behavior.
	 *
	 * @param bool $copyable Whether the entry value should be copyable.
	 * @return self Cloned entry with copy behavior metadata.
	 */
	public function copyable(bool $copyable=true): self {
		return $this->withField($this->field->copyable($copyable));
	}

	/**
	 * Sets text rendered before the entry value.
	 *
	 * @param string $prefix Value prefix.
	 * @return self Cloned entry with prefix metadata.
	 */
	public function prefix(string $prefix): self {
		return $this->withField($this->field->prefix($prefix));
	}

	/**
	 * Sets text rendered after the entry value.
	 *
	 * @param string $suffix Value suffix.
	 * @return self Cloned entry with suffix metadata.
	 */
	public function suffix(string $suffix): self {
		return $this->withField($this->field->suffix($suffix));
	}

	/**
	 * Sets the fallback label rendered for empty values.
	 *
	 * @param string $label Empty-state text.
	 * @return self Cloned entry with empty-value label.
	 */
	public function emptyLabel(string $label): self {
		return $this->withField($this->field->emptyLabel($label));
	}

	/**
	 * Sets helper text rendered with the entry.
	 *
	 * @param string $description Operator-facing description.
	 * @return self Cloned entry with description metadata.
	 */
	public function description(string $description): self {
		return $this->withField($this->field->description($description));
	}

	/**
	 * Controls whether the value is rendered as trusted HTML.
	 *
	 * @param bool $html Whether the renderer may output the value as HTML.
	 * @return self Cloned entry with HTML rendering metadata.
	 */
	public function html(bool $html=true): self {
		return $this->withField($this->field->html($html));
	}

	/**
	 * Sets static option labels for enumerated values.
	 *
	 * @param array<string|int, mixed> $options Value-to-label option map.
	 * @return self Cloned entry with static options.
	 */
	public function options(array $options): self {
		return $this->withField($this->field->options($options));
	}

	/**
	 * Sets a lazy option provider for enumerated values.
	 *
	 * @param callable $callback Callback invoked by the panel renderer to resolve options.
	 * @return self Cloned entry with dynamic option provider.
	 */
	public function optionsUsing(callable $callback): self {
		return $this->withField($this->field->optionsUsing($callback));
	}

	/**
	 * Sets a display-value transformer.
	 *
	 * @param callable $callback Callback invoked to transform the raw value for display.
	 * @return self Cloned entry with display callback.
	 */
	public function displayUsing(callable $callback): self {
		return $this->withField($this->field->displayUsing($callback));
	}

	/**
	 * Sets a runtime visibility predicate.
	 *
	 * @param callable $callback Callback invoked by the renderer/resource context.
	 * @return self Cloned entry with visibility callback.
	 */
	public function visibleUsing(callable $callback): self {
		return $this->withField($this->field->visibleUsing($callback));
	}

	/**
	 * Limits the entry to specific panel operations.
	 *
	 * @param array|string ...$operations Operation names or lists such as view, edit, create.
	 * @return self Cloned entry with visible operation constraints.
	 */
	public function visibleOn(array|string ...$operations): self {
		return $this->withField($this->field->visibleOn(...$operations));
	}

	/**
	 * Hides the entry on specific panel operations.
	 *
	 * @param array|string ...$operations Operation names or lists such as view, edit, create.
	 * @return self Cloned entry with hidden operation constraints.
	 */
	public function hiddenOn(array|string ...$operations): self {
		return $this->withField($this->field->hiddenOn(...$operations));
	}

	/**
	 * Sets responsive grid column span metadata.
	 *
	 * @param int|string|array<string, mixed> $span Span value or breakpoint map.
	 * @return self Cloned entry with grid span metadata.
	 */
	public function columnSpan(int|string|array $span): self {
		return $this->withField($this->field->columnSpan($span));
	}

	/**
	 * Sets responsive grid column-start metadata.
	 *
	 * @param int|string|array<string, mixed> $start Start value or breakpoint map.
	 * @return self Cloned entry with grid start metadata.
	 */
	public function columnStart(int|string|array $start): self {
		return $this->withField($this->field->columnStart($start));
	}

	/**
	 * Sets responsive grid row span metadata.
	 *
	 * @param int|string|array<string, mixed> $span Row span value or breakpoint map.
	 * @return self Cloned entry with row span metadata.
	 */
	public function rowSpan(int|string|array $span): self {
		return $this->withField($this->field->rowSpan($span));
	}

	/**
	 * Expands the entry across the full available grid width.
	 *
	 * @return self Cloned entry with full-width layout metadata.
	 */
	public function fullWidth(): self {
		return $this->withField($this->field->fullWidth());
	}

	/**
	 * Merges arbitrary renderer metadata into the entry field.
	 *
	 * @param array<string, mixed> $meta Metadata consumed by panel renderers.
	 * @return self Cloned entry with merged metadata.
	 */
	public function meta(array $meta): self {
		return $this->withField($this->field->meta($meta));
	}

	/**
	 * Returns the underlying read-only field definition.
	 *
	 * @return Field Field carrying entry metadata for panel rendering.
	 */
	public function field(): Field {
		return $this->field;
	}

	/**
	 * Serializes the entry for panel manifests and render payloads.
	 *
	 * @return array<string, mixed> Field payload with kind set to entry.
	 */
	public function toArray(): array {
		return array_replace($this->field->toArray(), ['kind'=>'entry']);
	}

	/**
	 * Forwards unknown fluent calls to the wrapped Field when supported.
	 *
	 * Field-returning calls are rewrapped as entries so read-only entry metadata is
	 * preserved across dynamic extensions.
	 *
	 * @param string $method Field method name.
	 * @param array<int, mixed> $arguments Arguments passed to the Field method.
	 * @return mixed Rewrapped entry for Field results, otherwise the raw Field method result.
	 */
	public function __call(string $method, array $arguments): mixed {
		if(!method_exists($this->field, $method)){
			throw new \BadMethodCallException('Unknown infolist entry method '.$method.'.');
		}
		$result=$this->field->{$method}(...$arguments);
		return $result instanceof Field ? $this->withField($result) : $result;
	}

	/**
	 * Clones the entry with a read-only field carrying entry metadata.
	 *
	 * @param Field $field Field definition returned by a fluent modifier.
	 * @return self Cloned entry wrapping the normalized field.
	 */
	private function withField(Field $field): self {
		$clone=clone $this;
		$clone->field=$field->readonly()->meta(['entry'=>true]);
		return $clone;
	}
}
