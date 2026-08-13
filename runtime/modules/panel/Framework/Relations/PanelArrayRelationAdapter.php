<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Fully functional in-memory relation adapter for arrays and deterministic tests. */
final class PanelArrayRelationAdapter implements PanelRelationAdapter, \JsonSerializable {
	/** @var array<string,array<string,mixed>> */
	private array $related=[];
	/** @var array<string,array<string,array{pivot:array<string,mixed>,position:int}>> */
	private array $links=[];
	/** @var array<string,int> */
	private array $versions=[];

	public function __construct(array $relatedRecords=[], array $links=[]) {
		foreach($relatedRecords as $key=>$record){
			if(!is_array($record)){ continue; }
			$id=(string)($record['id'] ?? $record['key'] ?? $key);
			if($id!==''){ $this->related[$id]=['id'=>$id]+$record; }
		}
		foreach($links as $parent=>$items){
			$position=0;
			foreach(is_array($items) ? $items : [] as $key=>$pivot){
				$id=is_int($key) && !is_array($pivot) ? (string)$pivot : (string)$key;
				$pivot=is_array($pivot) ? $pivot : [];
				if(isset($this->related[$id])){ $this->links[(string)$parent][$id]=['pivot'=>$pivot, 'position'=>$position++]; }
			}
		}
	}

	public function records(string|int $parentKey): array {
		$parent=(string)$parentKey;
		$links=$this->links[$parent] ?? [];
		uasort($links, static fn(array $left, array $right): int => $left['position']<=>$right['position']);
		$records=[];
		foreach($links as $id=>$link){
			if(isset($this->related[$id])){ $records[]=$this->related[$id]+['_pivot'=>$link['pivot'], '_position'=>$link['position']]; }
		}
		return $records;
	}

	public function available(string|int $parentKey): array {
		$linked=$this->links[(string)$parentKey] ?? [];
		return array_values(array_filter($this->related, static fn(array $record): bool => !isset($linked[(string)$record['id']])));
	}

	public function version(string|int $parentKey): int { return $this->versions[(string)$parentKey] ?? 0; }

	public function snapshot(string|int $parentKey): array {
		$parent=(string)$parentKey;
		return ['links'=>$this->links[$parent] ?? [], 'version'=>$this->version($parent)];
	}

	public function restore(string|int $parentKey, array $snapshot): void {
		$parent=(string)$parentKey;
		$this->links[$parent]=is_array($snapshot['links'] ?? null) ? $snapshot['links'] : [];
		$this->versions[$parent]=max($this->version($parent)+1, (int)($snapshot['version'] ?? 0)+1);
	}

	public function attach(string|int $parentKey, string|int $relatedKey, array $pivot=[]): void {
		$parent=(string)$parentKey; $related=(string)$relatedKey;
		if(!isset($this->related[$related])){ throw new \OutOfBoundsException('Related record does not exist: '.$related); }
		if(isset($this->links[$parent][$related])){ throw new \DomainException('Related record is already attached: '.$related); }
		$this->links[$parent][$related]=['pivot'=>$pivot, 'position'=>count($this->links[$parent] ?? [])];
		$this->bump($parent);
	}

	public function detach(string|int $parentKey, string|int $relatedKey): void {
		$parent=(string)$parentKey; $related=(string)$relatedKey;
		if(!isset($this->links[$parent][$related])){ throw new \OutOfBoundsException('Related record is not attached: '.$related); }
		unset($this->links[$parent][$related]);
		$this->normalizePositions($parent);
		$this->bump($parent);
	}

	public function updatePivot(string|int $parentKey, string|int $relatedKey, array $pivot): void {
		$parent=(string)$parentKey; $related=(string)$relatedKey;
		if(!isset($this->links[$parent][$related])){ throw new \OutOfBoundsException('Related record is not attached: '.$related); }
		$this->links[$parent][$related]['pivot']=array_replace($this->links[$parent][$related]['pivot'], $pivot);
		$this->bump($parent);
	}

	public function reorder(string|int $parentKey, array $orderedKeys): void {
		$parent=(string)$parentKey;
		$current=array_keys($this->links[$parent] ?? []);
		$ordered=array_values(array_unique(array_map('strval', $orderedKeys)));
		if(count($ordered)!==count($current) || array_diff($ordered, $current)!==[] || array_diff($current, $ordered)!==[]){
			throw new \DomainException('Reorder keys must exactly match the attached relation keys.');
		}
		foreach($ordered as $position=>$id){ $this->links[$parent][$id]['position']=$position; }
		$this->bump($parent);
	}

	public function jsonSerialize(): array { return ['related'=>$this->related, 'links'=>$this->links, 'versions'=>$this->versions]; }

	private function bump(string $parent): void { $this->versions[$parent]=$this->version($parent)+1; }
	private function normalizePositions(string $parent): void {
		$items=$this->links[$parent] ?? [];
		uasort($items, static fn(array $left, array $right): int => $left['position']<=>$right['position']);
		foreach(array_keys($items) as $position=>$id){ $this->links[$parent][$id]['position']=$position; }
	}
}
