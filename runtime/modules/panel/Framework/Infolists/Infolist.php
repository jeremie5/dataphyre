<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable schema wrapper for read-only panel information layouts.
 *
 * Infolists reuse the panel Schema engine while marking every manifest with usage=infolist and converting InfolistEntry
 * definitions into schema fields. This lets resources describe read/detail views with the same layout primitives used by
 * forms: sections, groups, tabs, steps, responsive columns, metadata, and component manifests.
 *
 * Mutator methods clone the infolist and return the modified copy. Existing instances are safe to reuse as base layouts for
 * multiple resources or operations.
 */
final class Infolist {
	use PanelExtensible;

	/** @var Schema Underlying schema that stores infolist components and layout metadata. */
	private Schema $schema;

	/**
	 * Creates an infolist around a schema and stamps usage metadata.
	 *
	 * @param ?Schema $schema Existing schema to wrap, or null for an empty schema.
	 */
	private function __construct(?Schema $schema=null) {
		$this->schema=($schema ?? Schema::make())->meta(['usage'=>'infolist']);
	}

	/**
	 * Builds an infolist from an optional component list.
	 *
	 * PanelExtensible configuration hooks run before components are applied so application defaults can influence the base
	 * infolist.
	 *
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $components Initial entries or layout components.
	 * @return self Configured infolist.
	 */
	public static function make(array $components=[]): self {
		return self::configured(new self(Schema::make()))->components($components);
	}

	/**
	 * Converts a schema-like definition into an infolist.
	 *
	 * Existing infolists are returned unchanged. Other values are delegated to Schema::from(), then wrapped with infolist
	 * usage metadata when conversion succeeds.
	 *
	 * @param mixed $definition Infolist, schema, schema array, or schema-compatible definition.
	 * @return ?self Infolist instance, or null when the definition cannot be converted.
	 */
	public static function from(mixed $definition): ?self {
		if($definition instanceof self){
			return $definition;
		}
		$schema=Schema::from($definition);
		return $schema instanceof Schema ? new self($schema->meta(['usage'=>'infolist'])) : null;
	}

	/**
	 * Wraps an existing schema as an infolist.
	 *
	 * @param Schema $schema Schema to mark as an infolist.
	 * @return self Infolist backed by the supplied schema.
	 */
	public static function fromSchema(Schema $schema): self {
		return new self($schema->meta(['usage'=>'infolist']));
	}

	/**
	 * Replaces the infolist component tree.
	 *
	 * Existing responsive column metadata is preserved while entries and containers are rebuilt from the supplied list.
	 *
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $components Replacement components.
	 * @return self Cloned infolist with the replacement component tree.
	 */
	public function components(array $components): self {
		$clone=clone $this;
		$clone->schema=Schema::make()->columns($this->schema->responsiveColumns())->meta(array_replace($this->schema->metadata(), ['usage'=>'infolist']));
		foreach($components as $component){
			$clone=$clone->component($component);
		}
		return $clone;
	}

	/**
	 * Appends one entry or layout component to the infolist schema.
	 *
	 * InfolistEntry values are converted to fields before being passed to Schema. Array definitions marked as entry payloads
	 * follow the same conversion path.
	 *
	 * @param SchemaComponent|Field|FormSection|InfolistEntry|array|string $component Entry or schema component definition.
	 * @return self Cloned infolist with the component appended.
	 */
	public function component(SchemaComponent|Field|FormSection|InfolistEntry|array|string $component): self {
		$clone=clone $this;
		$clone->schema=$clone->schema->component($this->normalizeComponent($component));
		return $clone;
	}

	/**
	 * Appends an infolist entry.
	 *
	 * @param Field|InfolistEntry|array|string $entry Entry definition accepted by InfolistEntry::from().
	 * @param ?string $type Entry display type when creating from a name.
	 * @return self Cloned infolist with the entry appended.
	 */
	public function entry(Field|InfolistEntry|array|string $entry, ?string $type=null): self {
		return $this->component(InfolistEntry::from($entry, $type));
	}

	/**
	 * Creates a text entry definition without appending it.
	 *
	 * @param string $name Field name to read from the record.
	 * @return InfolistEntry Text entry builder.
	 */
	public function textEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'text');
	}

	/**
	 * Creates a badge entry definition without appending it.
	 *
	 * @param string $name Field name to read from the record.
	 * @param array|string $tones Badge tone map or static tone accepted by InfolistEntry::badge().
	 * @return InfolistEntry Badge entry builder.
	 */
	public function badgeEntry(string $name, array|string $tones=[]): InfolistEntry {
		return InfolistEntry::make($name, 'badge')->badge($tones);
	}

	/**
	 * Creates an image entry definition without appending it.
	 *
	 * @param string $name Field name containing image source data.
	 * @return InfolistEntry Image entry builder.
	 */
	public function imageEntry(string $name): InfolistEntry {
		return InfolistEntry::make($name, 'image');
	}

	/**
	 * Appends a section container with optional entries.
	 *
	 * @param FormSection|array|string $section Section definition accepted by Schema::section().
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $entries Entries nested inside the section.
	 * @return self Cloned infolist with the section appended.
	 */
	public function section(FormSection|array|string $section, array $entries=[]): self {
		$entries=array_map(fn(mixed $entry): SchemaComponent => $this->normalizeComponent($entry), $entries);
		$clone=clone $this;
		$clone->schema=$clone->schema->section($section, $entries);
		return $clone;
	}

	/**
	 * Appends a group container with optional entries.
	 *
	 * @param string $name Group name.
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $entries Entries nested inside the group.
	 * @return self Cloned infolist with the group appended.
	 */
	public function group(string $name, array $entries=[]): self {
		$entries=array_map(fn(mixed $entry): SchemaComponent => $this->normalizeComponent($entry), $entries);
		$clone=clone $this;
		$clone->schema=$clone->schema->group($name, $entries);
		return $clone;
	}

	/**
	 * Appends a tab container with optional entries.
	 *
	 * @param string $name Tab name.
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $entries Entries nested inside the tab.
	 * @return self Cloned infolist with the tab appended.
	 */
	public function tab(string $name, array $entries=[]): self {
		$entries=array_map(fn(mixed $entry): SchemaComponent => $this->normalizeComponent($entry), $entries);
		$clone=clone $this;
		$clone->schema=$clone->schema->tab($name, $entries);
		return $clone;
	}

	/**
	 * Appends a step container with optional entries.
	 *
	 * @param string $name Step name.
	 * @param array<int, SchemaComponent|Field|FormSection|InfolistEntry|array|string> $entries Entries nested inside the step.
	 * @return self Cloned infolist with the step appended.
	 */
	public function step(string $name, array $entries=[]): self {
		$entries=array_map(fn(mixed $entry): SchemaComponent => $this->normalizeComponent($entry), $entries);
		$clone=clone $this;
		$clone->schema=$clone->schema->step($name, $entries);
		return $clone;
	}

	/**
	 * Sets responsive column metadata for the infolist layout.
	 *
	 * @param int|array<string, int> $columns Column count or breakpoint map accepted by Schema::columns().
	 * @return self Cloned infolist with updated column metadata.
	 */
	public function columns(int|array $columns): self {
		$clone=clone $this;
		$clone->schema=$clone->schema->columns($columns);
		return $clone;
	}

	/**
	 * Merges schema metadata while preserving infolist usage.
	 *
	 * @param array<string, mixed> $meta Metadata merged into the underlying schema.
	 * @return self Cloned infolist with updated metadata.
	 */
	public function meta(array $meta): self {
		$clone=clone $this;
		$clone->schema=$clone->schema->meta(array_replace(['usage'=>'infolist'], $meta));
		return $clone;
	}

	/**
	 * Returns the underlying schema.
	 *
	 * @return Schema Schema carrying infolist components and metadata.
	 */
	public function schema(): Schema {
		return $this->schema;
	}

	/**
	 * Returns the flattened field/entry list from the schema.
	 *
	 * @return array<int, array<string, mixed>> Field manifests extracted from the infolist.
	 */
	public function fieldsList(): array {
		return $this->schema->fieldsList();
	}

	/**
	 * Returns the flattened section/container list from the schema.
	 *
	 * @return array<int, array<string, mixed>> Section manifests extracted from the infolist.
	 */
	public function sectionsList(): array {
		return $this->schema->sectionsList();
	}

	/**
	 * Serializes the infolist as an operation-aware schema manifest.
	 *
	 * @param ?string $operation Optional panel operation, such as show or view.
	 * @param array<string, mixed> $meta Additional metadata merged into the manifest.
	 * @return array<string, mixed> Infolist schema manifest.
	 */
	public function manifest(?string $operation=null, array $meta=[]): array {
		return $this->schema->manifest($operation, array_replace(['usage'=>'infolist'], $meta));
	}

	/**
	 * Serializes the infolist for diagnostics and JSON-like consumers.
	 *
	 * @return array<string, mixed> Schema array with kind=infolist.
	 */
	public function toArray(): array {
		return array_replace($this->schema->toArray(), ['kind'=>'infolist']);
	}

	/**
	 * Converts infolist-specific component inputs into schema components.
	 *
	 * @param SchemaComponent|Field|FormSection|InfolistEntry|array|string $component Component input.
	 * @return SchemaComponent Component accepted by the underlying schema.
	 */
	private function normalizeComponent(SchemaComponent|Field|FormSection|InfolistEntry|array|string $component): SchemaComponent {
		if($component instanceof InfolistEntry){
			return SchemaComponent::field($component->field());
		}
		if(is_array($component) && (($component['kind'] ?? null)==='entry' || ($component['entry'] ?? false)===true)){
			return SchemaComponent::field(InfolistEntry::from($component)->field());
		}
		$resolved=SchemaComponent::from($component);
		return $resolved instanceof SchemaComponent ? $resolved : SchemaComponent::field((string)$component);
	}
}
