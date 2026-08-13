<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Canonical data-source response and Panel page manifest. */
final class PanelDataResult implements \JsonSerializable, \Countable, \IteratorAggregate {

	/** @param list<mixed> $items @param array<string, mixed> $aggregates @param array<string, mixed> $included @param array<string, mixed> $metadata */
	public function __construct(
		private readonly array $items,
		private readonly PanelDataPage $page,
		private readonly string $source='data',
		private readonly array $aggregates=[],
		private readonly array $included=[],
		private readonly array $metadata=[],
		private readonly ?PanelDataQuery $query=null
	){
		if(!array_is_list($items)){ throw new \InvalidArgumentException('Panel data result items must be a list.'); }
		if(trim($source)===''){ throw new \InvalidArgumentException('Panel data result source cannot be blank.'); }
		self::assertMap($aggregates, 'aggregates'); self::assertMap($included, 'included'); self::assertMap($metadata, 'metadata');
	}

	/** @param array<string, mixed>|list<mixed>|\Traversable<mixed> $value */
	public static function normalize(array|\Traversable $value, PanelDataQuery $query, string $source='callback'): self {
		if($value instanceof \Traversable){ $value=iterator_to_array($value, false); }
		if(array_is_list($value)){
			$items=array_values($value);
			$page=new PanelDataPage($query->offsetValue(), $query->limitValue(), count($items), count($items));
			return new self($items, $page, $source, [], [], [], $query);
		}
		$items=is_array($value['items'] ?? null) ? array_values($value['items']) : [];
		$pageData=is_array($value['page'] ?? null) ? $value['page'] : [];
		$page=new PanelDataPage(
			max(0, (int)($pageData['offset'] ?? $query->offsetValue())),
			max(1, (int)($pageData['limit'] ?? $query->limitValue())),
			count($items),
			isset($pageData['total']) ? max(0, (int)$pageData['total']) : (isset($value['total']) ? max(0, (int)$value['total']) : null),
			isset($pageData['next_cursor']) ? (string)$pageData['next_cursor'] : null,
			isset($pageData['previous_cursor']) ? (string)$pageData['previous_cursor'] : null
		);
		return new self(
			$items, $page, (string)($value['source'] ?? $source),
			is_array($value['aggregates'] ?? null) ? $value['aggregates'] : [],
			is_array($value['included'] ?? null) ? $value['included'] : [],
			is_array($value['metadata'] ?? null) ? $value['metadata'] : [], $query
		);
	}

	/** @return list<mixed> */ public function items(): array { return $this->items; }
	public function page(): PanelDataPage { return $this->page; }
	public function source(): string { return $this->source; }
	/** @return array<string, mixed> */ public function aggregates(): array { return $this->aggregates; }
	/** @return array<string, mixed> */ public function included(): array { return $this->included; }
	/** @return array<string, mixed> */ public function metadata(): array { return $this->metadata; }
	public function querySpec(): ?PanelDataQuery { return $this->query; }
	public function count(): int { return count($this->items); }
	public function getIterator(): \Traversable { yield from $this->items; }

	/** @return array<string, mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_data_result', 'source'=>$this->source, 'items'=>$this->items,
			'page'=>$this->page->jsonSerialize(), 'aggregates'=>$this->aggregates,
			'included'=>$this->included, 'metadata'=>$this->metadata,
			'query'=>$this->query?->jsonSerialize(),
		];
	}

	private static function assertMap(array $value, string $label): void {
		if($value!==[] && array_is_list($value)){ throw new \InvalidArgumentException("Panel data result {$label} must be an object-like array."); }
	}
}
