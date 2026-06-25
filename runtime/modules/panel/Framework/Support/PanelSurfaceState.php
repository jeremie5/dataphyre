<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Captures a compact, JSON-safe snapshot of a rendered panel surface.
 *
 * PanelSurfaceState is designed for diagnostics, examples, and operator tooling. It keeps response identity, request context, resource/page summaries, navigation and command facets, lightweight state summaries, chrome configuration, and generation metadata without embedding full form, table, widget, or search result structures.
 */
final class PanelSurfaceState implements \JsonSerializable {

	/**
	 * Stores a normalized panel surface snapshot.
	 *
	 * Constructor inputs are expected to be compacted already. Use make() when converting full render data because it clamps HTTP status, normalizes the surface kind, extracts safe state summaries, and records generation metadata.
	 *
	 * @param readonly string $title Surface title shown to the operator.
	 * @param readonly string $kind Normalized surface kind such as page or resource.
	 * @param readonly int $status HTTP-like status code for the surface response.
	 * @param readonly array $request Request summary attached to the render data.
	 * @param readonly array $resource Compact resource definition.
	 * @param readonly array $page Compact page definition.
	 * @param readonly array $breadcrumbs Breadcrumb entries for navigation context.
	 * @param readonly array $notifications Notification summaries visible on the surface.
	 * @param readonly array $navigation Compact navigation state.
	 * @param readonly array $commands Compact command palette state.
	 * @param readonly array $states Compact summaries of form/table/widget/search state.
	 * @param readonly array $chrome Chrome/layout configuration for the surface.
	 * @param readonly array $meta Generation and source metadata.
	 */
	public function __construct(
		private readonly string $title,
		private readonly string $kind,
		private readonly int $status,
		private readonly array $request=[],
		private readonly array $resource=[],
		private readonly array $page=[],
		private readonly array $breadcrumbs=[],
		private readonly array $notifications=[],
		private readonly array $navigation=[],
		private readonly array $commands=[],
		private readonly array $states=[],
		private readonly array $chrome=[],
		private readonly array $meta=[]
	){}

	/**
	 * Builds a surface snapshot from full panel render data.
	 *
	 * The factory reads only recognized render sections, compacts resource/page/navigation/command state, summarizes large runtime states, clamps status to the HTTP range, and records data keys plus a UTC generation timestamp for traceability.
	 *
	 * @param string $title Surface title.
	 * @param int $status HTTP-like status code; values are clamped to 100..599.
	 * @param array<string,mixed> $data Full panel render data.
	 * @param array<string,mixed> $chrome Chrome/layout configuration.
	 * @return self Compact surface state ready for serialization.
	 */
	public static function make(string $title, int $status, array $data=[], array $chrome=[]): self {
		$request=is_array($data['request'] ?? null) ? $data['request'] : [];
		$resource=is_array($data['resource'] ?? null) ? self::compactDefinition($data['resource']) : [];
		$page=is_array($data['page'] ?? null) ? self::compactDefinition($data['page']) : [];
		$navigation=is_array($data['navigation_state'] ?? null) ? self::compactNavigation($data['navigation_state']) : [];
		$commands=is_array($data['command_state'] ?? null) ? self::compactCommands($data['command_state']) : [];
		$states=self::stateSummary($data);
		return new self(
			$title,
			Resource::normalizeName((string)($data['kind'] ?? 'page')) ?: 'page',
			max(100, min(599, $status)),
			$request,
			$resource,
			$page,
			is_array($data['breadcrumbs'] ?? null) ? $data['breadcrumbs'] : [],
			is_array($data['notifications'] ?? null) ? $data['notifications'] : [],
			$navigation,
			$commands,
			$states,
			$chrome,
			[
				'data_keys'=>array_values(array_filter(array_keys($data), static fn(mixed $key): bool => is_string($key) && $key!=='surface_state')),
				'generated_at'=>gmdate('c'),
			]
		);
	}

	/**
	 * Returns the surface title.
	 *
	 *
	 * @return string Operator-facing surface title.
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Returns the normalized surface kind.
	 *
	 *
	 * @return string Normalized kind such as page, resource, table, or custom surface label.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Returns the clamped HTTP-like surface status.
	 *
	 *
	 * @return int Status code in the 100..599 range.
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Returns the request summary attached to the surface.
	 *
	 * The request summary is passed through from render data when it is already an array; otherwise it is omitted. Callers are responsible for excluding secrets and oversized request values before this state is serialized.
	 *
	 * @return array<string,mixed> Request summary.
	 */
	public function request(): array {
		return $this->request;
	}

	/**
	 * Returns compact navigation counts and active group state.
	 *
	 *
	 * @return array<string,mixed> Navigation summary.
	 */
	public function navigation(): array {
		return $this->navigation;
	}

	/**
	 * Provides compact command palette counts and query state.
	 *
	 *
	 * @return array<string,mixed> Command palette summary.
	 */
	public function commands(): array {
		return $this->commands;
	}

	/**
	 * Provides compact summaries of heavy panel runtime states.
	 *
	 * State summaries intentionally report shape, counts, selected scalar fields, and type information instead of embedding complete nested state objects.
	 *
	 * @return array<string,mixed> Summaries keyed by state section name.
	 */
	public function states(): array {
		return $this->states;
	}

	/**
	 * Provides chrome and layout configuration for the surface.
	 *
	 * Chrome data is retained as supplied by the renderer so diagnostics can inspect layout decisions. This class does not sanitize asset URLs, labels, or theme values.
	 *
	 * @return array<string,mixed> Chrome configuration data.
	 */
	public function chrome(): array {
		return $this->chrome;
	}

	/**
	 * Serializes the full compact panel surface snapshot.
	 *
	 * The serialized array is intentionally compact for diagnostics and generated examples: rich runtime objects have already been summarized, while navigation, commands, chrome, request context, and metadata remain inspectable.
	 *
	 * @return array<string,mixed> Compact surface state for diagnostics.
	 */
	public function jsonSerialize(): array {
		return [
			'title'=>$this->title,
			'kind'=>$this->kind,
			'status'=>$this->status,
			'request'=>$this->request,
			'resource'=>$this->resource,
			'page'=>$this->page,
			'breadcrumbs'=>$this->breadcrumbs,
			'notifications'=>$this->notifications,
			'navigation'=>$this->navigation,
			'commands'=>$this->commands,
			'states'=>$this->states,
			'chrome'=>$this->chrome,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Extracts scalar identity fields from resource or page definitions.
	 *
	 * Only simple scalar or null values are kept, preventing closures, objects, callbacks, and large nested configuration from leaking into surface snapshots.
	 *
	 * @param array<string,mixed> $definition Resource or page definition data.
	 * @return array<string,scalar|null> Compact definition fields.
	 */
	private static function compactDefinition(array $definition): array {
		$out=[];
		foreach(['name', 'label', 'plural_label', 'group', 'icon', 'description', 'url'] as $key){
			if(array_key_exists($key, $definition) && (is_scalar($definition[$key]) || $definition[$key]===null)){
				$out[$key]=$definition[$key];
			}
		}
		return $out;
	}

	/**
	 * Summarizes navigation state for diagnostics.
	 *
	 * Entry and group counts are preserved, active state is retained, and each array-shaped group is reduced to label/count/active data suitable for compact rendering. Malformed group entries are dropped.
	 *
	 * @param array<string,mixed> $state Full navigation state data.
	 * @return array<string,mixed> Compact navigation summary.
	 */
	private static function compactNavigation(array $state): array {
		$entries=is_array($state['entries'] ?? null) ? $state['entries'] : [];
		$groups=is_array($state['groups'] ?? null) ? $state['groups'] : [];
		$active=is_array($state['active'] ?? null) ? $state['active'] : [];
		return [
			'entry_count'=>count($entries),
			'group_count'=>count($groups),
			'active'=>$active,
			'groups'=>array_map(static fn(array $group): array => [
				'label'=>(string)($group['label'] ?? ''),
				'count'=>(int)($group['count'] ?? count(is_array($group['entries'] ?? null) ? $group['entries'] : [])),
				'active'=>($group['active'] ?? false)===true,
			], array_filter($groups, 'is_array')),
		];
	}

	/**
	 * Summarizes command palette state for diagnostics.
	 *
	 * Command, group, and match counts are preserved along with the current query and per-group labels/counts. Individual command definitions and callbacks are not embedded.
	 *
	 * @param array<string,mixed> $state Full command state data.
	 * @return array<string,mixed> Compact command summary.
	 */
	private static function compactCommands(array $state): array {
		$commands=is_array($state['commands'] ?? null) ? $state['commands'] : [];
		$groups=is_array($state['groups'] ?? null) ? $state['groups'] : [];
		$matched=is_array($state['matched'] ?? null) ? $state['matched'] : [];
		return [
			'command_count'=>count($commands),
			'group_count'=>count($groups),
			'match_count'=>count($matched),
			'query'=>(string)($state['query'] ?? ''),
			'groups'=>array_map(static fn(array $group): array => [
				'label'=>(string)($group['label'] ?? ''),
				'count'=>(int)($group['count'] ?? count(is_array($group['commands'] ?? null) ? $group['commands'] : [])),
			], array_filter($groups, 'is_array')),
		];
	}

	/**
	 * Builds compact summaries for known heavy panel state arrays.
	 *
	 * Only recognized state keys are inspected, keeping surface snapshots stable even when render data carries additional arbitrary values.
	 *
	 * @param array<string,mixed> $data Full panel render data.
	 * @return array<string,mixed> Compact summaries keyed by source state key.
	 */
	private static function stateSummary(array $data): array {
		$states=[];
		foreach([
			'form_state',
			'table_state',
			'infolist_state',
			'relation_state',
			'action_state',
			'widget_states',
			'widgets',
			'tables',
			'global_search',
		] as $key){
			if(!array_key_exists($key, $data)){
				continue;
			}
			$states[$key]=self::compactValue($data[$key]);
		}
		return $states;
	}

	/**
	 * Compacts one arbitrary value into a JSON-safe diagnostic summary.
	 *
	 * Lists become count summaries, maps expose their first keys and selected scalar counters, scalar values pass through, and objects/non-scalars collapse to type information. Map keys are not redacted, so callers should avoid passing sensitive key names in render state.
	 *
	 * @param mixed $value Value to summarize.
	 * @return mixed Compact scalar, list summary, map summary, or type summary.
	 */
	private static function compactValue(mixed $value): mixed {
		if(is_array($value)){
			if(array_is_list($value)){
				return [
					'type'=>'list',
					'count'=>count($value),
				];
			}
			$out=[
				'type'=>'map',
				'keys'=>array_slice(array_keys($value), 0, 20),
			];
			foreach(['valid', 'page', 'per_page', 'total', 'filtered_count', 'record_count', 'entry_count', 'visible_entry_count', 'query', 'result_count'] as $key){
				if(array_key_exists($key, $value) && (is_scalar($value[$key]) || $value[$key]===null)){
					$out[$key]=$value[$key];
				}
			}
			return $out;
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		return [
			'type'=>is_object($value) ? $value::class : gettype($value),
		];
	}
}
