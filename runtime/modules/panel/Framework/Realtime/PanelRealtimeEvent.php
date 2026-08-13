<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Stable broker-assigned event envelope. Identity scope remains outside the wire body. */
final class PanelRealtimeEvent implements \JsonSerializable {
	/** @param array<string,mixed> $metadata */
	public function __construct(
		private readonly int $sequence,
		private readonly string $streamKey,
		private readonly string $channel,
		private readonly string $topic,
		private readonly string $type,
		private readonly string $occurredAt,
		private readonly mixed $payload,
		private readonly array $metadata=[]
	){
		if($sequence<1){ throw new \InvalidArgumentException('Panel realtime event sequence must be positive.'); }
		PanelRealtimeGuard::digest($streamKey, 'stream key');
		if(PanelRealtimeGuard::identifier($channel, 'channel', 96)!==$channel || PanelRealtimeGuard::identifier($topic, 'topic', 96)!==$topic || PanelRealtimeGuard::identifier($type, 'event type', 96)!==$type){ throw new \InvalidArgumentException('Panel realtime event names must use canonical lowercase identifiers.'); }
		PanelRealtimeGuard::text($occurredAt, 'event timestamp', 64);
		try{ new \DateTimeImmutable($occurredAt); }catch(\Throwable){ throw new \InvalidArgumentException('Panel realtime event timestamp must be RFC3339 compatible.'); }
		if($metadata!==[] && array_is_list($metadata)){ throw new \InvalidArgumentException('Panel realtime event metadata must be an object-like map.'); }
		PanelRealtimeGuard::assertJson($payload, 131072); PanelRealtimeGuard::assertJson($metadata, 32768);
		PanelRealtimeGuard::assertJson($this->jsonSerialize(), 196608);
	}

	public function sequence(): int { return $this->sequence; }
	public function streamKey(): string { return $this->streamKey; }
	public function channel(): string { return $this->channel; }
	public function topic(): string { return $this->topic; }
	public function type(): string { return $this->type; }
	/** @return array<string,mixed> */ public function metadata(): array { return $this->metadata; }
	public function wireBytes(): int { return strlen(json_encode($this->jsonSerialize(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
	public function jsonSerialize(): array { return ['schema_version'=>1,'sequence'=>$this->sequence,'channel'=>$this->channel,'topic'=>$this->topic,'type'=>$this->type,'occurred_at'=>$this->occurredAt,'payload'=>$this->payload,'metadata'=>$this->metadata]; }
}
