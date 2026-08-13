<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Thin Predis executeRaw bridge; client construction and failover remain host-owned. */
final class PanelPredisRealtimeTransport implements PanelRedisRealtimeTransport {
	public function __construct(private readonly object $client){
		if(!is_callable([$client,'executeRaw'])){throw new \InvalidArgumentException('Panel Predis transport requires an executeRaw-capable client.');}
	}

	public function evaluate(string $script, array $keys, array $arguments=[]): mixed {
		PanelCallbackRedisRealtimeTransport::input($script,$keys,$arguments);
		return $this->client->executeRaw(['EVAL',$script,(string)count($keys),...$keys,...$arguments]);
	}

	public function jsonSerialize(): array {
		return ['type'=>'panel_predis_realtime_transport','version'=>1,'client'=>'predis','binary_safe_required'=>true,'fixed_scripts_only'=>true,'client_serialized'=>false,'connection_serialized'=>false,'credentials_serialized'=>false];
	}
}
