<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded broker read with an explicit scanned cursor, head, and replay reset state. */
final class PanelRealtimeReadResult implements \JsonSerializable {
	private const MAXIMUM_EVENTS=1000;
	private const MAXIMUM_WIRE_BYTES=4194304;
	/** @var list<PanelRealtimeEvent> */ private array $events;
	private int $wireBytes=0;

	/** @param list<PanelRealtimeEvent> $events */
	public function __construct(
		private readonly int $afterSequence,
		array $events,
		private readonly int $cursor,
		private readonly int $head,
		private readonly int $earliest,
		private readonly bool $hasMore=false,
		private readonly ?string $resetReason=null
	){
		if($afterSequence<0 || $cursor<0 || $head<0 || $earliest<1 || $earliest>$head+1){ throw new \InvalidArgumentException('Panel realtime broker cursor bounds are invalid.'); }
		if($resetReason!==null && !in_array($resetReason, ['retention_gap','source_reset'], true)){ throw new \InvalidArgumentException('Panel realtime broker reset reason is invalid.'); }
		if($resetReason===null && ($cursor<$afterSequence || $head<$cursor)){ throw new \InvalidArgumentException('Panel realtime broker progress cannot move behind the requested cursor.'); }
		if($resetReason!==null && ($events!==[] || $cursor!==$head || $hasMore)){ throw new \InvalidArgumentException('Panel realtime reset results must be empty and positioned at the current head.'); }
		if(count($events)>self::MAXIMUM_EVENTS){ throw new \LengthException('Panel realtime broker result exceeds its event count bound.'); }
		$previous=$afterSequence;
		foreach($events as $event){
			if(!$event instanceof PanelRealtimeEvent || $event->sequence()<=$previous || $event->sequence()>$cursor){ throw new \InvalidArgumentException('Panel realtime broker events must be ordered within the scanned cursor.'); }
			$previous=$event->sequence(); $this->wireBytes+=$event->wireBytes(); if($this->wireBytes>self::MAXIMUM_WIRE_BYTES){ throw new \LengthException('Panel realtime broker result exceeds its aggregate byte bound.'); }
		}
		$this->events=array_values($events);
	}

	/** @return list<PanelRealtimeEvent> */ public function events(): array { return $this->events; }
	public function cursor(): int { return $this->cursor; }
	public function head(): int { return $this->head; }
	public function earliest(): int { return $this->earliest; }
	public function hasMore(): bool { return $this->hasMore; }
	public function resetReason(): ?string { return $this->resetReason; }
	public function wireBytes(): int { return $this->wireBytes; }
	public function lag(): int { return max(0, $this->head-$this->afterSequence); }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_read','version'=>1,'after_sequence'=>$this->afterSequence,'cursor'=>$this->cursor,'head'=>$this->head,'earliest'=>$this->earliest,'returned'=>count($this->events),'wire_bytes'=>$this->wireBytes,'maximum_events'=>self::MAXIMUM_EVENTS,'maximum_wire_bytes'=>self::MAXIMUM_WIRE_BYTES,'has_more'=>$this->hasMore,'reset_reason'=>$this->resetReason,'events'=>$this->events]; }
}
