<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Strict Server-Sent Events framing without any HTTP framework dependency. */
final class PanelRealtimeSseEncoder implements \JsonSerializable {
	/** @param array<string,mixed> $data */
	public function event(string $event, array $data, ?string $id=null): string {
		$normalized=PanelRealtimeGuard::identifier($event, 'SSE event name', 96); if($normalized!==$event){ throw new \InvalidArgumentException('Panel realtime SSE event names must use canonical lowercase identifiers.'); } $event=$normalized;
		if($id!==null && ($id==='' || strlen($id)>4096 || preg_match('/[\x00\r\n]/', $id)===1)){ throw new \InvalidArgumentException('Panel realtime SSE event id is invalid.'); }
		PanelRealtimeGuard::assertJson($data, 196608);
		$json=json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
		$output=$id===null ? '' : 'id: '.$id."\n";
		return $output.'event: '.$event."\n".'data: '.$json."\n\n";
	}

	public function retry(int $milliseconds): string {
		if($milliseconds<250 || $milliseconds>60000){ throw new \InvalidArgumentException('Panel realtime SSE retry is outside its safe bound.'); }
		return 'retry: '.$milliseconds."\n\n";
	}

	public function heartbeat(int $timestamp): string {
		if($timestamp<0){ throw new \InvalidArgumentException('Panel realtime heartbeat timestamp cannot be negative.'); }
		return ': heartbeat '.$timestamp."\n\n";
	}

	public function jsonSerialize(): array { return ['type'=>'panel_realtime_sse_encoder','version'=>1,'utf8_json'=>true,'named_events'=>true,'signed_event_ids'=>true,'multiline_id_rejected'=>true]; }
}
