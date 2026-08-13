<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Thin phpredis EVAL bridge; connection setup and credentials remain host-owned. */
final class PanelPhpRedisRealtimeTransport implements PanelRedisRealtimeTransport {
	public function __construct(private readonly object $client){
		if(!is_callable([$client,'eval'])){throw new \InvalidArgumentException('Panel phpredis transport requires an eval-capable client.');}
	}

	public function evaluate(string $script, array $keys, array $arguments=[]): mixed {
		PanelCallbackRedisRealtimeTransport::input($script,$keys,$arguments);
		$result=$this->client->eval($script,[...$keys,...$arguments],count($keys));
		if($result===false){throw new \RuntimeException('Panel phpredis EVAL failed.');}
		return $result;
	}

	public function jsonSerialize(): array {
		return ['type'=>'panel_phpredis_realtime_transport','version'=>1,'client'=>'phpredis','binary_safe_required'=>true,'fixed_scripts_only'=>true,'client_serialized'=>false,'connection_serialized'=>false,'credentials_serialized'=>false];
	}
}
