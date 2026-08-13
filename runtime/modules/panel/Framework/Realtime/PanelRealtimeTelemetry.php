<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cardinality-bounded telemetry. It records no payload, token, tenant, or principal values. */
final class PanelRealtimeTelemetry implements \JsonSerializable {
	private const COUNTERS=['connections_opened','connections_denied','intents_rejected','events_emitted','cursor_events_emitted','heartbeats_emitted','resets_emitted','cancellations','deadlines','broker_failures','bytes_emitted'];
	/** @var array<string,int> */ private array $counters=[];
	/** @var array<string,int> */ private array $resetReasons=[];

	public function __construct(){ foreach(self::COUNTERS as $counter){ $this->counters[$counter]=0; } }
	public function increment(string $counter, int $amount=1): void {
		if(!array_key_exists($counter, $this->counters) || $amount<0){ throw new \InvalidArgumentException('Panel realtime telemetry counter is invalid.'); }
		$this->counters[$counter]+=$amount;
	}
	public function reset(string $reason): void {
		if(!in_array($reason, ['retention_gap','source_reset','slow_consumer','event_too_large'], true)){ $reason='other'; }
		$this->increment('resets_emitted'); $this->resetReasons[$reason]=($this->resetReasons[$reason] ?? 0)+1;
	}
	/** @return array<string,int> */ public function counters(): array { return $this->counters; }
	public function jsonSerialize(): array { $reasons=$this->resetReasons; ksort($reasons,SORT_STRING); return ['type'=>'panel_realtime_telemetry','version'=>1,'counters'=>$this->counters,'reset_reasons'=>$reasons,'payloads_exposed'=>false,'tokens_exposed'=>false,'scope_values_exposed'=>false,'high_cardinality_labels'=>false]; }
}
