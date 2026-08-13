<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Bounded connection, replay, heartbeat, and backpressure policy. */
final class PanelRealtimeStreamOptions implements \JsonSerializable {
	public function __construct(
		private readonly int $batchEvents=100,
		private readonly int $batchBytes=262144,
		private readonly int $pendingEvents=1000,
		private readonly int $heartbeatSeconds=15,
		private readonly int $connectionSeconds=300,
		private readonly int $retryMilliseconds=1500,
		private readonly int $resumeTtlSeconds=300
	){
		if($batchEvents<1 || $batchEvents>1000){ throw new \InvalidArgumentException('Panel realtime batch event bound must be between 1 and 1000.'); }
		if($batchBytes<1024 || $batchBytes>2097152){ throw new \InvalidArgumentException('Panel realtime batch byte bound must be between 1024 and 2097152.'); }
		if($pendingEvents<$batchEvents || $pendingEvents>100000){ throw new \InvalidArgumentException('Panel realtime pending event bound must include one batch and not exceed 100000.'); }
		if($heartbeatSeconds<5 || $heartbeatSeconds>120 || $connectionSeconds<5 || $connectionSeconds>3600){ throw new \InvalidArgumentException('Panel realtime heartbeat or connection lifetime is outside its safe bound.'); }
		if($retryMilliseconds<250 || $retryMilliseconds>60000 || $resumeTtlSeconds<30 || $resumeTtlSeconds>3600){ throw new \InvalidArgumentException('Panel realtime retry or resume lifetime is outside its safe bound.'); }
	}
	public function batchEvents(): int { return $this->batchEvents; }
	public function batchBytes(): int { return $this->batchBytes; }
	public function pendingEvents(): int { return $this->pendingEvents; }
	public function heartbeatSeconds(): int { return $this->heartbeatSeconds; }
	public function connectionSeconds(): int { return $this->connectionSeconds; }
	public function retryMilliseconds(): int { return $this->retryMilliseconds; }
	public function resumeTtlSeconds(): int { return $this->resumeTtlSeconds; }
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_stream_options','version'=>1,'batch_events'=>$this->batchEvents,'batch_bytes'=>$this->batchBytes,'pending_events'=>$this->pendingEvents,'heartbeat_seconds'=>$this->heartbeatSeconds,'connection_seconds'=>$this->connectionSeconds,'retry_milliseconds'=>$this->retryMilliseconds,'resume_ttl_seconds'=>$this->resumeTtlSeconds]; }
}
