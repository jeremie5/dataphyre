<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Represents the command palette state for a Panel request.
 *
 * PanelCommandState normalizes command definitions, sorts them for display,
 * groups them by category, filters matches for the current query, and exposes
 * the resulting payload to Panel views, JSON diagnostics, and command manifests.
 */
final class PanelCommandState implements \JsonSerializable {

	/**
	 * Stores normalized command palette payloads.
	 *
	 * The constructor assumes callers already normalized commands and grouped
	 * state. Use make() when accepting raw command arrays from resources,
	 * packages, or request handlers.
	 *
	 * @param readonly array<int, array<string, mixed>> $commands All normalized commands.
	 * @param readonly array<int, array<string, mixed>> $groups Grouped command payloads.
	 * @param readonly array<int, array<string, mixed>> $matched Commands matching the active query.
	 * @param readonly string $query Active search query.
	 * @param readonly array<string, mixed> $meta Request and count metadata.
	 */
	public function __construct(
		private readonly array $commands=[],
		private readonly array $groups=[],
		private readonly array $matched=[],
		private readonly string $query='',
		private readonly array $meta=[]
	){}

	/**
	 * Builds command state from raw command definitions.
	 *
	 * The query is taken from the explicit argument, command_search request
	 * input, command request input, or an empty string. Invalid command entries
	 * are ignored, remaining commands are sorted by sort/group/label, and
	 * metadata is enriched with request and count information.
	 *
	 * @param array<int, mixed> $commands Raw command definitions.
	 * @param ?PanelRequest $request Current Panel request used for search input and metadata.
	 * @param ?string $query Explicit search query override.
	 * @param array<string, mixed> $meta Additional metadata to merge into the payload.
	 * @return self Normalized command palette state.
	 */
	public static function make(array $commands=[], ?PanelRequest $request=null, ?string $query=null, array $meta=[]): self {
		$query=trim((string)($query ?? $request?->query('command_search', $request?->query('command', '')) ?? ''));
		$commands=array_values(array_filter(array_map(static fn(mixed $command): ?array => is_array($command) ? self::normalizeCommand($command) : null, $commands)));
		usort($commands, static function(array $left, array $right): int {
			return [(int)($left['sort'] ?? 100), (string)($left['group'] ?? ''), (string)($left['label'] ?? '')] <=> [(int)($right['sort'] ?? 100), (string)($right['group'] ?? ''), (string)($right['label'] ?? '')];
		});
		$matched=$query!=='' ? self::matchCommands($commands, $query) : $commands;
		return new self($commands, self::groupCommands($commands), $matched, $query, array_replace([
			'request'=>$request?->toArray(),
			'command_count'=>count($commands),
			'group_count'=>count(self::groupCommands($commands)),
			'match_count'=>count($matched),
		], $meta));
	}

	/**
	 * Returns every normalized command.
	 *
	 * @return array<int, array<string, mixed>> Sorted command definitions.
	 */
	public function commands(): array {
		return $this->commands;
	}

	/**
	 * Returns commands grouped for palette rendering.
	 *
	 * @return array<int, array{label:string,count:int,commands:array<int,array<string,mixed>>}>
	 */
	public function groups(): array {
		return $this->groups;
	}

	/**
	 * Returns commands matching the active search query.
	 *
	 * @return array<int, array<string, mixed>> Matching commands, or all commands when query is empty.
	 */
	public function matched(): array {
		return $this->matched;
	}

	/**
	 * Returns the active command search query.
	 *
	 * @return string Trimmed command search text.
	 */
	public function query(): string {
		return $this->query;
	}

	/**
	 * Returns command palette metadata.
	 *
	 * @return array<string, mixed> Request, command_count, group_count, match_count, and caller metadata.
	 */
	public function meta(): array {
		return $this->meta;
	}

	/**
	 * Serializes the command state for Panel responses.
	 *
	 * @return array{commands:array,groups:array,matched:array,query:string,meta:array}
	 */
	public function jsonSerialize(): array {
		return [
			'commands'=>$this->commands,
			'groups'=>$this->groups,
			'matched'=>$this->matched,
			'query'=>$this->query,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Normalizes one raw command definition into the palette schema.
	 *
	 * @param array<string, mixed> $command Raw command definition.
	 * @return array<string, mixed> Normalized command payload.
	 */
	private static function normalizeCommand(array $command): array {
		$name=Resource::normalizeName((string)($command['name'] ?? ''));
		$label=trim((string)($command['label'] ?? ''));
		$group=trim((string)($command['group'] ?? $command['category'] ?? 'Commands')) ?: 'Commands';
		$url=trim((string)($command['url'] ?? $command['href'] ?? ''));
		$keywords=is_array($command['keywords'] ?? null) ? array_values(array_filter(array_map('strval', $command['keywords']))) : [];
		$meta=is_array($command['meta'] ?? null) ? $command['meta'] : [];
		return [
			'name'=>$name,
			'label'=>$label!=='' ? $label : ($name!=='' ? self::humanize($name) : 'Command'),
			'group'=>$group,
			'category'=>$group,
			'description'=>trim((string)($command['description'] ?? '')) ?: null,
			'icon'=>trim((string)($command['icon'] ?? '')) ?: null,
			'url'=>$url,
			'href'=>$url,
			'new_tab'=>($command['new_tab'] ?? false)===true,
			'sort'=>(int)($command['sort'] ?? 100),
			'keywords'=>$keywords,
			'source'=>Resource::normalizeName((string)($command['source'] ?? 'panel')) ?: 'panel',
			'tone'=>Resource::normalizeName((string)($command['tone'] ?? 'neutral')) ?: 'neutral',
			'client_action'=>Resource::normalizeName((string)($command['client_action'] ?? $meta['client_action'] ?? '')) ?: null,
			'meta'=>$meta,
		];
	}

	/**
	 * Groups normalized commands by display category.
	 *
	 * @param array<int, array<string, mixed>> $commands Normalized commands.
	 * @return array<int, array{label:string,count:int,commands:array<int,array<string,mixed>>}>
	 */
	private static function groupCommands(array $commands): array {
		$groups=[];
		foreach($commands as $command){
			$group=(string)($command['group'] ?? 'Commands');
			$groups[$group] ??=[
				'label'=>$group,
				'count'=>0,
				'commands'=>[],
			];
			$groups[$group]['count']++;
			$groups[$group]['commands'][]=$command;
		}
		$groups=array_values($groups);
		usort($groups, static function(array $left, array $right): int {
			$leftSort=100;
			$rightSort=100;
			foreach($left['commands'] ?? [] as $command){
				$leftSort=min($leftSort, (int)($command['sort'] ?? 100));
			}
			foreach($right['commands'] ?? [] as $command){
				$rightSort=min($rightSort, (int)($command['sort'] ?? 100));
			}
			return [$leftSort, (string)($left['label'] ?? '')] <=> [$rightSort, (string)($right['label'] ?? '')];
		});
		return $groups;
	}

	/**
	 * Filters commands by a lowercase text search over display fields.
	 *
	 * @param array<int, array<string, mixed>> $commands Normalized commands.
	 * @param string $query User-entered search query.
	 * @return array<int, array<string, mixed>> Matching commands.
	 */
	private static function matchCommands(array $commands, string $query): array {
		$query=strtolower(trim($query));
		if($query===''){
			return $commands;
		}
		$matches=[];
		foreach($commands as $command){
			$haystack=strtolower(implode(' ', array_filter([
				$command['label'] ?? '',
				$command['group'] ?? '',
				$command['description'] ?? '',
				implode(' ', $command['keywords'] ?? []),
				$command['source'] ?? '',
			])));
			if(str_contains($haystack, $query)){
				$matches[]=$command;
			}
		}
		return $matches;
	}

	/**
	 * Converts a command machine name into a display label.
	 *
	 * @param string $value Normalized command name.
	 * @return string Human-readable label.
	 */
	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Command' : ucwords($value);
	}
}
