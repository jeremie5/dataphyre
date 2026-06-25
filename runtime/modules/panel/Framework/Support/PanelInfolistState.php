<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable presentation state for a panel infolist view.
 *
 * Infolists describe read-only record details for operator surfaces. The state
 * keeps normalized entries, section groupings, schema metadata, and record
 * context together so renderers and diagnostics can inspect what will be
 * visible without re-running the resource builder.
 */
final class PanelInfolistState implements \JsonSerializable {

	/**
	 * Captures the complete infolist state emitted by a panel resource.
	 *
	 * Entries and sections are stored as already-normalized renderer payloads.
	 * The constructor does not filter visibility, allowing diagnostics to inspect
	 * hidden entries while rendering helpers expose visible subsets.
	 *
	 * @param array<int, array<string, mixed>> $entries Flat infolist entries in resource order.
	 * @param array<string, array<int, array<string, mixed>>> $sections Entries grouped by section key or label.
	 * @param array<string, mixed> $schema Declarative schema used to build the infolist.
	 * @param array<string, mixed> $meta Record and renderer metadata attached to the state.
	 */
	public function __construct(
		private readonly array $entries=[],
		private readonly array $sections=[],
		private readonly array $schema=[],
		private readonly array $meta=[]
	){}

	/**
	 * Creates an infolist state from normalized renderer arrays.
	 *
	 *
	 * @param array<int, array<string, mixed>> $entries Flat infolist entries in resource order.
	 * @param array<string, array<int, array<string, mixed>>> $sections Entries grouped by section key or label.
	 * @param array<string, mixed> $schema Declarative schema used to build the infolist.
	 * @param array<string, mixed> $meta Record and renderer metadata attached to the state.
	 * @return self Captured infolist state value.
	 */
	public static function make(array $entries=[], array $sections=[], array $schema=[], array $meta=[]): self {
		return new self($entries, $sections, $schema, $meta);
	}

	/**
	 * Returns every entry captured for the infolist.
	 *
	 * Hidden entries remain present so diagnostics can explain
	 * resource output that the renderer may choose not to display.
	 *
	 * @return array<int, array<string, mixed>> Flat entry payloads in resource order.
	 */
	public function entries(): array {
		return $this->entries;
	}

	/**
	 * Returns section groupings captured for the infolist.
	 *
	 * Section arrays preserve hidden entries; use {@see visibleSections()} for
	 * renderer-ready groups.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Entries grouped by section key or label.
	 */
	public function sections(): array {
		return $this->sections;
	}

	/**
	 * Returns the declarative schema used to build this infolist.
	 *
	 * @return array<string, mixed> Schema payload for documentation, diagnostics, or renderer tooling.
	 */
	public function schema(): array {
		return $this->schema;
	}

	/**
	 * Returns record and renderer metadata attached to this state.
	 *
	 * Metadata commonly includes record identity under `record.key` and
	 * `record.title`, plus any resource-specific context needed by panel
	 * renderers.
	 *
	 * @return array<string, mixed> Infolist metadata payload.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Resolves a single entry by normalized entry name.
	 *
	 * The requested name and each entry name are normalized through
	 * {@see Resource::normalizeName()} so label-like names and internal names
	 * compare consistently.
	 *
	 * @param string $name Entry name to locate.
	 * @return ?array<string, mixed> Matching entry payload, or null when absent.
	 */
	public function entry(string $name): ?array {
		$name=Resource::normalizeName($name);
		foreach($this->entries as $entry){
			if(is_array($entry) && Resource::normalizeName((string)($entry['name'] ?? ''))===$name){
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Returns flat entries that should be rendered to operators.
	 *
	 * Entries are visible by default. Only entries with an explicit `visible`
	 * value other than true are removed from this renderer-facing list.
	 *
	 * @return array<int, array<string, mixed>> Visible entry payloads in resource order.
	 */
	public function visibleEntries(): array {
		return array_values(array_filter($this->entries, static fn(array $entry): bool => ($entry['visible'] ?? true)===true));
	}

	/**
	 * Returns section groups after filtering hidden entries.
	 *
	 * Empty sections are omitted after visibility filtering so renderers do not
	 * need to suppress section chrome separately.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Visible entries grouped by non-empty section.
	 */
	public function visibleSections(): array {
		$visible=[];
		foreach($this->sections as $section=>$entries){
			$entries=array_values(array_filter(is_array($entries) ? $entries : [], static fn(array $entry): bool => ($entry['visible'] ?? true)===true));
			if($entries!==[]){
				$visible[$section]=$entries;
			}
		}
		return $visible;
	}

	/**
	 * Returns the record key attached to this infolist state.
	 *
	 * @return string Record key from metadata, or an empty string when unavailable.
	 */
	public function recordKey(): string {
		return (string)($this->meta['record']['key'] ?? '');
	}

	/**
	 * Returns the display title attached to this infolist state.
	 *
	 * @return string Record title from metadata, or an empty string when unavailable.
	 */
	public function recordTitle(): string {
		return (string)($this->meta['record']['title'] ?? '');
	}

	/**
	 * Serializes renderer-ready infolist state for panel responses.
	 *
	 * The JSON payload includes visible entries and non-empty visible sections,
	 * while schema and metadata remain available so clients can render labels,
	 * formatting, and record context consistently.
	 *
	 * @return array{entry_count: int, visible_entry_count: int, sections: array<string, array<int, string>>, entries: array<int, array<string, mixed>>, schema: array<string, mixed>, meta: array<string, mixed>} Renderer payload.
	 */
	public function jsonSerialize(): array {
		return [
			'entry_count'=>count($this->entries),
			'visible_entry_count'=>count($this->visibleEntries()),
			'sections'=>array_map(static fn(array $entries): array => array_map(static fn(array $entry): string => (string)($entry['name'] ?? ''), $entries), $this->visibleSections()),
			'entries'=>$this->visibleEntries(),
			'schema'=>$this->schema,
			'meta'=>$this->meta,
		];
	}
}
