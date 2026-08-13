<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable trusted context passed to standalone-host policy callbacks.
 *
 * Serialization is intentionally metadata-only: route identity, asset names,
 * user values, and tenant values are represented by digests or presence flags
 * so traces and manifests cannot become an accidental data-export surface.
 */
final class PanelStandaloneHostContext implements \JsonSerializable {

	/**
	 * @param list<string> $segments
	 */
	public function __construct(
		private readonly \Dataphyre\Http\Request $request,
		private readonly PanelInstance $panel,
		private readonly string $routeKind,
		private readonly string $ability,
		private readonly string $prefix,
		private readonly array $segments,
		private readonly ?string $asset,
		private readonly string $method,
		private readonly bool $unsafe,
		private readonly mixed $user,
		private readonly ?string $tenant,
		private readonly string $requestId
	){}

	public function request(): \Dataphyre\Http\Request {
		return $this->request;
	}

	public function panel(): PanelInstance {
		return $this->panel;
	}

	public function routeKind(): string {
		return $this->routeKind;
	}

	public function ability(): string {
		return $this->ability;
	}

	public function prefix(): string {
		return $this->prefix;
	}

	/** @return list<string> */
	public function segments(): array {
		return $this->segments;
	}

	public function asset(): ?string {
		return $this->asset;
	}

	public function method(): string {
		return $this->method;
	}

	public function unsafe(): bool {
		return $this->unsafe;
	}

	public function user(): mixed {
		return $this->user;
	}

	public function tenant(): ?string {
		return $this->tenant;
	}

	public function requestId(): string {
		return $this->requestId;
	}

	public function withRequest(\Dataphyre\Http\Request $request): self {
		return new self(
			$request,
			$this->panel,
			$this->routeKind,
			$this->ability,
			$this->prefix,
			$this->segments,
			$this->asset,
			$this->method,
			$this->unsafe,
			$this->user,
			$this->tenant,
			$this->requestId,
		);
	}

	public function withUser(mixed $user): self {
		return new self(
			$this->request,
			$this->panel,
			$this->routeKind,
			$this->ability,
			$this->prefix,
			$this->segments,
			$this->asset,
			$this->method,
			$this->unsafe,
			$user,
			$this->tenant,
			$this->requestId,
		);
	}

	public function withTenant(?string $tenant): self {
		return new self(
			$this->request,
			$this->panel,
			$this->routeKind,
			$this->ability,
			$this->prefix,
			$this->segments,
			$this->asset,
			$this->method,
			$this->unsafe,
			$this->user,
			$tenant,
			$this->requestId,
		);
	}

	/** @return array<string,mixed> */
	public function jsonSerialize(): array {
		return [
			'type'=>'panel_standalone_host_context',
			'panel'=>$this->panel->name(),
			'route_kind'=>$this->routeKind,
			'ability'=>$this->ability,
			'prefix'=>$this->prefix,
			'method'=>$this->method,
			'unsafe'=>$this->unsafe,
			'segment_count'=>count($this->segments),
			'asset_present'=>$this->asset!==null,
			'user_present'=>$this->user!==null,
			'tenant_present'=>$this->tenant!==null,
			'route_digest'=>hash('sha256', implode("\0", [
				$this->prefix,
				$this->routeKind,
				$this->method,
				...$this->segments,
				$this->asset ?? '',
			])),
			'request_id'=>$this->requestId,
		];
	}
}
