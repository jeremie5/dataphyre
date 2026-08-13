<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded public window with stable records and only newly signed continuation intents. */
final class PanelDataSurfaceWindowResult implements \JsonSerializable, \Countable, \IteratorAggregate {
	/** @param list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> $records */
	public function __construct(
		private readonly string $definition,
		private readonly string $resource,
		private readonly PanelDataSurfaceType $surface,
		private readonly PanelDataSurfaceProjection $projection,
		private readonly array $records,
		private readonly PanelDataSurfaceRange $range,
		private readonly ?int $total,
		private readonly bool $hasBefore,
		private readonly ?bool $hasAfter,
		private readonly ?PanelDataSurfaceWindowIntent $previous,
		private readonly ?PanelDataSurfaceWindowIntent $next,
		private readonly ?PanelDataCanvasModel $canvas=null
	){
		if(!array_is_list($records) || count($records)>PanelDataSurfaceRange::MAX_FETCH){ throw new \LengthException('Panel DataSurface result record count is invalid.'); }
		if($total!==null && $total<0){ throw new \InvalidArgumentException('Panel DataSurface total cannot be negative.'); }
		$keys=[];
		foreach($records as $record){
			if(!is_array($record) || !isset($record['key'],$record['position'],$record['visible'],$record['data']) || !is_string($record['key']) || !is_int($record['position']) || !is_bool($record['visible']) || !is_array($record['data'])){ throw new \UnexpectedValueException('Panel DataSurface result records are malformed.'); }
			if(isset($keys[$record['key']])){ throw new \UnexpectedValueException("Panel DataSurface result contains duplicate stable key '{$record['key']}'."); }
			$keys[$record['key']]=true;
		}
		if(($surface->advanced())!==($canvas instanceof PanelDataCanvasModel)||($canvas instanceof PanelDataCanvasModel&&$canvas->surface()!==$surface)){throw new \InvalidArgumentException('Panel DataSurface advanced windows require a matching canvas model.');}
		PanelDataSurfaceGuard::assertJson($this->jsonSerialize());
	}

	public function definition(): string { return $this->definition; }
	public function resource(): string { return $this->resource; }
	public function surface(): PanelDataSurfaceType { return $this->surface; }
	public function projection(): PanelDataSurfaceProjection { return $this->projection; }
	/** @return list<array{key:string,position:int,visible:bool,data:array<string,mixed>}> */ public function records(): array { return $this->records; }
	public function range(): PanelDataSurfaceRange { return $this->range; }
	public function total(): ?int { return $this->total; }
	public function hasBefore(): bool { return $this->hasBefore; }
	public function hasAfter(): ?bool { return $this->hasAfter; }
	public function previousIntent(): ?PanelDataSurfaceWindowIntent { return $this->previous; }
	public function nextIntent(): ?PanelDataSurfaceWindowIntent { return $this->next; }
	public function canvas():?PanelDataCanvasModel{return$this->canvas;}
	public function count(): int { return count($this->records); }
	public function getIterator(): \Traversable { yield from $this->records; }

	public function jsonSerialize(): array {
		$visible=0; foreach($this->records as $record){ if($record['visible']){ $visible++; } }
		$payload=[
			'type'=>'panel_data_surface_window','version'=>$this->canvas instanceof PanelDataCanvasModel?2:1,'definition'=>$this->definition,
			'resource'=>$this->resource,'surface'=>$this->surface->value,'projection'=>$this->projection->jsonSerialize(),
			'records'=>$this->records,'window'=>$this->range->jsonSerialize(),
			'returned'=>count($this->records),'visible'=>$visible,'total'=>$this->total,'total_known'=>$this->total!==null,
			'has_before'=>$this->hasBefore,'has_after'=>$this->hasAfter,
			'previous_intent'=>$this->previous?->jsonSerialize(),'next_intent'=>$this->next?->jsonSerialize(),
		];
		if($this->canvas instanceof PanelDataCanvasModel){$payload['canvas']=$this->canvas->jsonSerialize();}
		return$payload;
	}
}
