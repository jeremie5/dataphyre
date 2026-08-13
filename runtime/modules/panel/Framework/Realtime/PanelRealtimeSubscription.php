<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable tenant and principal bound channel, topic, and scalar-filter subscription. */
final class PanelRealtimeSubscription implements \JsonSerializable {
	/** @var list<string> */ private array $topics;
	/** @var array<string,mixed> */ private array $filters;

	/** @param list<string> $topics @param array<string,mixed> $filters */
	private function __construct(private readonly PanelRealtimeContext $context, private readonly string $channel, array $topics, array $filters){
		if($topics===[] || count($topics)>32){ throw new \InvalidArgumentException('Panel realtime subscriptions require 1-32 topics.'); }
		$normalized=[];
		foreach($topics as $topic){ $normalized[]=PanelRealtimeGuard::identifier((string)$topic, 'topic', 96, true); }
		$this->topics=array_values(array_unique($normalized)); sort($this->topics, SORT_STRING);
		if(in_array('*', $this->topics, true) && count($this->topics)>1){ throw new \InvalidArgumentException('Panel realtime wildcard topic cannot be combined with named topics.'); }
		$this->filters=PanelRealtimeGuard::filters($filters);
	}

	/** @param list<string> $topics @param array<string,mixed> $filters */
	public static function fromTrusted(PanelRealtimeContext $context, string $channel, array $topics, array $filters=[]): self {
		return new self($context, PanelRealtimeGuard::identifier($channel, 'channel', 96), $topics, $filters);
	}

	public function context(): PanelRealtimeContext { return $this->context; }
	public function channel(): string { return $this->channel; }
	/** @return list<string> */ public function topics(): array { return $this->topics; }
	/** @return array<string,mixed> */ public function filters(): array { return $this->filters; }
	public function streamKey(): string { return $this->context->streamKey($this->channel); }
	public function bindingTag(string $key): string {
		$claims=['panel'=>$this->context->panel(),'tenant'=>$this->context->tenant(),'principal'=>$this->context->principal(),'channel'=>$this->channel,'topics'=>$this->topics,'filters'=>$this->filters];
		return PanelRealtimeGuard::encode(hash_hmac('sha256', "panel-realtime-subscription-v1\0".PanelRealtimeGuard::canonicalJson($claims), $key, true));
	}
	public function belongsTo(PanelRealtimeContext $context): bool {
		return hash_equals($this->context->panel(), $context->panel()) && hash_equals($this->context->tenant(), $context->tenant()) && hash_equals($this->context->principal(), $context->principal());
	}
	public function accepts(PanelRealtimeEvent $event): bool {
		if(!hash_equals($this->streamKey(), $event->streamKey()) || (!in_array('*', $this->topics, true) && !in_array($event->topic(), $this->topics, true))){ return false; }
		$metadata=$event->metadata();
		foreach($this->filters as $key=>$expected){ if(!array_key_exists($key, $metadata) || $metadata[$key]!==$expected){ return false; } }
		return true;
	}
	public function jsonSerialize(): array { return ['type'=>'panel_realtime_subscription','version'=>1,'panel'=>$this->context->panel(),'channel'=>$this->channel,'topics'=>$this->topics,'filter_names'=>array_keys($this->filters),'tenant_bound'=>true,'principal_bound'=>true,'filter_values_exposed'=>false]; }
}
