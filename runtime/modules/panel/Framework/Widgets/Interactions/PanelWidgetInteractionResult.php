<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Trusted adapter output consumed by server rendering and the lifecycle client. */
final class PanelWidgetInteractionResult implements \JsonSerializable {
	private function __construct(
		private readonly string $adapter,
		private readonly string $islandId,
		private readonly PanelWidgetInteractionState $state,
		private readonly ?string $endpoint,
		private readonly ?string $snapshot,
		private readonly ?string $bindingTag,
		private readonly bool $replayed,
		private readonly bool $retryable,
		private readonly int $httpStatus=200
	){}

	public static function success(string $adapter, string $islandId, PanelWidgetInteractionState $state, ?string $endpoint, ?string $snapshot, ?string $bindingTag, bool $replayed=false): self {
		if(!$state->successful() && $state->status()!=='unmounted'){
			throw new \InvalidArgumentException('Successful widget results require ready or unmounted state.');
		}
		$unmounted=$state->status()==='unmounted';
		if(!$unmounted && ($endpoint===null || $snapshot===null || $bindingTag===null)){
			throw new \InvalidArgumentException('Ready widget results require an endpoint, snapshot, and binding tag.');
		}
		return new self(
			PanelWidgetInteractionValue::safeIdentifier($adapter, 'Widget adapter', 64),
			PanelWidgetInteractionValue::safeIdentifier($islandId, 'Widget island id', 96),
			$state,
			$unmounted ? null : self::normalizeEndpoint((string)$endpoint),
			$unmounted ? null : PanelWidgetInteractionValue::boundedString((string)$snapshot, 'Widget snapshot', 8192),
			$unmounted ? null : PanelWidgetInteractionValue::boundedString((string)$bindingTag, 'Widget binding tag', 160),
			$replayed,
			false
		);
	}

	public static function failure(string $adapter, string $islandId, PanelWidgetInteractionException $failure): self {
		return new self(
			PanelWidgetInteractionValue::safeIdentifier($adapter, 'Widget adapter', 64),
			PanelWidgetInteractionValue::safeIdentifier($islandId, 'Widget island id', 96),
			PanelWidgetInteractionState::make('error', 0, [], $failure->publicCode(), $failure->publicMessage()),
			null,
			null,
			null,
			false,
			$failure->retryable(),
			$failure->httpStatus()
		);
	}

	public function adapter(): string { return $this->adapter; }
	public function islandId(): string { return $this->islandId; }
	public function state(): PanelWidgetInteractionState { return $this->state; }
	public function endpoint(): ?string { return $this->endpoint; }
	public function snapshot(): ?string { return $this->snapshot; }
	public function bindingTag(): ?string { return $this->bindingTag; }
	public function replayed(): bool { return $this->replayed; }
	public function retryable(): bool { return $this->retryable; }
	public function httpStatus(): int { return $this->httpStatus; }
	public function successful(): bool { return $this->httpStatus>=200 && $this->httpStatus<300 && in_array($this->state->status(), ['ready','unmounted'], true); }

	public function toArray(): array {
		return [
			'type'=>'panel_widget_interaction_result',
			'schema_version'=>1,
			'adapter'=>$this->adapter,
			'island_id'=>$this->islandId,
			'state'=>$this->state->toArray(),
			'endpoint'=>$this->endpoint,
			'snapshot'=>$this->snapshot,
			'binding_tag'=>$this->bindingTag,
			'replayed'=>$this->replayed,
			'retryable'=>$this->retryable,
			'http_status'=>$this->httpStatus,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private static function normalizeEndpoint(string $endpoint): string {
		$endpoint=PanelWidgetInteractionValue::boundedString($endpoint, 'Widget runtime endpoint', 2048);
		if(!str_starts_with($endpoint, '/') || str_starts_with($endpoint, '//') || str_contains($endpoint, "\\") || str_contains($endpoint, "\r") || str_contains($endpoint, "\n")){
			throw new \InvalidArgumentException('Widget runtime endpoints must be same-origin absolute paths.');
		}
		return $endpoint;
	}
}
