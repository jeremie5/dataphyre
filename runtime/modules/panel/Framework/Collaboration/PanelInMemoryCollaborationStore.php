<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic process-local collaboration store. */
final class PanelInMemoryCollaborationStore implements PanelCollaborationStore, \JsonSerializable {
	/** @var array<string,mixed> */
	private array $state;
	/** @var array<int,array<string,mixed>> */
	private array $events=[];
	private int $cursor=0;
	private int $retention;

	public function __construct(int $eventRetention=512) {
		$this->state=PanelCollaborationStateEngine::initialState();
		$this->retention=max(8, $eventRetention);
	}
	public function state(): array { return $this->state; }
	public function transaction(callable $mutation, string $type, array $event=[]): mixed {
		$copy=$this->state;
		$result=$mutation($copy);
		PanelCollaborationStateEngine::assertReceiptAppendOnly($this->state, $copy);
		$this->state=$copy;
		$this->cursor++;
		$this->events[]=array_replace(PanelCollaborationStateEngine::sanitize($event), ['cursor'=>$this->cursor, 'type'=>$type, 'occurred_at'=>gmdate('c')]);
		if(count($this->events)>$this->retention){ $this->events=array_slice($this->events, -$this->retention); }
		return $result;
	}
	public function cursor(): int { return $this->cursor; }
	public function changesSince(int $cursor=0, int $limit=100): array {
		$cursor=max(0, $cursor); $limit=max(1, min(1000, $limit));
		$oldest=$this->events!==[] ? (int)$this->events[0]['cursor'] : 0;
		$reset=$cursor>0 && $oldest>0 && $cursor<$oldest-1;
		$changes=$reset ? [] : array_slice(array_values(array_filter($this->events, static fn(array $event): bool => (int)$event['cursor']>$cursor)), 0, $limit);
		return [
			'cursor'=>$changes!==[] ? (int)$changes[array_key_last($changes)]['cursor'] : $this->cursor,
			'oldest_cursor'=>$oldest,
			'reset_required'=>$reset,
			'changes'=>$changes,
			'snapshot'=>$reset ? PanelCollaborationStateEngine::publicState($this->state) : null,
		];
	}
	public function manifest(array $meta=[]): array {
		return [
			'type'=>'panel_collaboration_store', 'adapter'=>'memory', 'cursor'=>$this->cursor,
			'event_retention'=>$this->retention,
			'capabilities'=>['atomic'=>'process_local', 'ordered_cursor'=>true, 'stale_cursor_reset'=>true],
			'meta'=>PanelCollaborationStateEngine::sanitize($meta),
		];
	}
	public function jsonSerialize(): array { return $this->manifest(); }
}
