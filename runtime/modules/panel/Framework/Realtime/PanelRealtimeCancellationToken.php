<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Mutable local cancellation token with an optional absolute Unix deadline. */
final class PanelRealtimeCancellationToken implements PanelRealtimeCancellation, \JsonSerializable {
	private bool $cancelled=false;
	private ?\Closure $probe;
	private ?\Closure $clock;

	public function __construct(private readonly ?int $deadline=null, ?callable $probe=null, ?callable $clock=null){
		if($deadline!==null && $deadline<0){ throw new \InvalidArgumentException('Panel realtime cancellation deadline cannot be negative.'); }
		$this->probe=$probe===null ? null : \Closure::fromCallable($probe);
		$this->clock=$clock===null ? null : \Closure::fromCallable($clock);
	}

	public function cancel(): void { $this->cancelled=true; }
	public function isCancellationRequested(): bool {
		if($this->cancelled){ return true; }
		if($this->deadline!==null && $this->now()>=$this->deadline){ return true; }
		if($this->probe!==null){ try{ return ($this->probe)()===true; }catch(\Throwable){ return true; } }
		return false;
	}
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_cancellation','version'=>1,'deadline_configured'=>$this->deadline!==null,'external_probe_configured'=>$this->probe!==null,'cancelled'=>$this->isCancellationRequested()]; }
	private function now(): int { $value=$this->clock===null ? time() : ($this->clock)(); if(!is_int($value) || $value<0){ throw new \UnexpectedValueException('Panel realtime cancellation clock must return a non-negative integer timestamp.'); } return $value; }
}
