<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** In-process pull subscription for PanelArrayDataSource. */
final class PanelArrayDataSubscription implements PanelDataSubscription {
	private bool $isClosed=false;
	public function __construct(private readonly PanelArrayDataSource $source, private int $afterSequence=0) {
		if($afterSequence<0){ throw new \InvalidArgumentException('Panel data subscription cursor cannot be negative.'); }
	}
	public function poll(int $limit=100): array {
		if($this->isClosed){ return []; }
		$changes=$this->source->changes($this->afterSequence, $limit);
		if($changes!==[]){ $this->afterSequence=$changes[array_key_last($changes)]->sequence(); }
		return $changes;
	}
	public function cursor(): int { return $this->afterSequence; }
	public function closed(): bool { return $this->isClosed; }
	public function close(): void { $this->isClosed=true; }
}
