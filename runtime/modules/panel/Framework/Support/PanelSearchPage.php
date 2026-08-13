<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable page returned by global-search coordinators and index adapters. */
final class PanelSearchPage implements \JsonSerializable, \Countable, \IteratorAggregate {
	private const MAX_RESULTS=200;
	private const MAX_DIAGNOSTICS=50;

	/**
	 * @param list<PanelSearchResult> $results
	 * @param list<array<string,mixed>> $diagnostics
	 * @param array<string,mixed> $meta
	 */
	private function __construct(
		private readonly array $results,
		private readonly string|array|null $nextCursor=null,
		private readonly bool $complete=true,
		private readonly bool $partial=false,
		private readonly array $diagnostics=[],
		private readonly array $meta=[]
	){}

	/**
	 * @param iterable<PanelSearchResult|array<string,mixed>> $results
	 * @param list<array<string,mixed>> $diagnostics
	 * @param array<string,mixed> $meta
	 */
	public static function make(iterable $results=[], string|array|null $nextCursor=null, bool $complete=true, bool $partial=false, array $diagnostics=[], array $meta=[]): self {
		$normalized=[];
		$inspected=0;
		$truncated=false;
		try{
			foreach($results as $result){
				if($inspected++>=self::MAX_RESULTS){ $truncated=true; break; }
				if(is_array($result)){
					$result=PanelSearchResult::fromArray($result);
				}
				if($result instanceof PanelSearchResult){
					$normalized[]=$result;
				}
			}
		}
		catch(\Throwable $exception){
			$complete=false;
			$partial=true;
			array_unshift($diagnostics, [
				'code'=>'page_result_error',
				'message'=>'Search page result iteration failed.',
				'severity'=>'error',
				'exception'=>$exception::class,
			]);
		}
		if($truncated){
			$complete=false;
			$partial=true;
			array_unshift($diagnostics, [
				'code'=>'page_result_budget_exhausted',
				'message'=>'Search page result budget was exhausted.',
				'severity'=>'warning',
			]);
		}
		$diagnostics=array_slice(array_values(array_filter($diagnostics, 'is_array')), 0, self::MAX_DIAGNOSTICS);
		$diagnostics=array_map(static fn(array $diagnostic): array=>PanelSearchSanitizer::map($diagnostic), $diagnostics);
		$cursor=PanelSearchSanitizer::cursor($nextCursor);
		return new self($normalized, $cursor, $complete, $partial, $diagnostics, PanelSearchSanitizer::map($meta));
	}

	/** @param array<string,mixed> $page */
	public static function fromArray(array $page): self {
		$cursor=$page['next_cursor'] ?? $page['cursor'] ?? null;
		if(!is_string($cursor) && !is_array($cursor)){
			$cursor=null;
		}
		return self::make(
			is_iterable($page['results'] ?? null) ? $page['results'] : (is_iterable($page['items'] ?? null) ? $page['items'] : []),
			$cursor,
			isset($page['complete']) ? (bool)$page['complete'] : $cursor===null,
			(bool)($page['partial'] ?? false),
			is_array($page['diagnostics'] ?? null) ? $page['diagnostics'] : [],
			is_array($page['meta'] ?? null) ? $page['meta'] : []
		);
	}

	/** @return list<PanelSearchResult> */
	public function results(): array { return $this->results; }
	public function nextCursor(): string|array|null { return $this->nextCursor; }
	public function isComplete(): bool { return $this->complete; }
	public function isPartial(): bool { return $this->partial; }
	/** @return list<array<string,mixed>> */
	public function diagnostics(): array { return $this->diagnostics; }
	/** @return array<string,mixed> */
	public function meta(): array { return $this->meta; }
	public function count(): int { return count($this->results); }
	public function getIterator(): \Traversable { return new \ArrayIterator($this->results); }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'results'=>array_map(static fn(PanelSearchResult $result): array=>$result->toArray(), $this->results),
			'result_count'=>count($this->results),
			'next_cursor'=>PanelSearchSanitizer::publicCursor($this->nextCursor),
			'complete'=>$this->complete,
			'partial'=>$this->partial,
			'diagnostics'=>$this->diagnostics,
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }
}
