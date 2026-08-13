<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Immutable execution context passed to production global-search adapters.
 *
 * The context carries the original request rather than reconstructing identity
 * from query text. Providers can therefore apply tenant, user, locale, cursor,
 * and index-specific rules without coupling Panel to an ORM or search engine.
 */
final class PanelSearchContext implements \JsonSerializable {

	/**
	 * @param array<string,mixed> $options Adapter-specific, non-secret options.
	 */
	private function __construct(
		private readonly string $query,
		private readonly PanelRequest $request,
		private readonly int $providerLimit,
		private readonly int $globalLimit,
		private readonly string|array|null $cursor=null,
		private readonly array $options=[]
	){}

	/** @param array<string,mixed> $options */
	public static function make(string $query, PanelRequest $request, int $providerLimit, int $globalLimit, string|array|null $cursor=null, array $options=[]): self {
		return new self(
			(string)PanelSearchSanitizer::value(trim($query)),
			$request,
			max(1, min(50, $providerLimit)),
			max(1, min(50, $globalLimit)),
			PanelSearchSanitizer::cursor($cursor),
			PanelSearchSanitizer::map($options)
		);
	}

	public function query(): string { return $this->query; }
	public function request(): PanelRequest { return $this->request; }
	public function user(): mixed { return $this->request->user(); }
	public function tenant(): ?string { return $this->request->tenantKey(); }
	public function providerLimit(): int { return $this->providerLimit; }
	public function globalLimit(): int { return $this->globalLimit; }
	public function cursor(): string|array|null { return $this->cursor; }

	public function option(string $name, mixed $default=null): mixed {
		return $this->options[$name] ?? $default;
	}

	/** @return array<string,mixed> */
	public function options(): array { return $this->options; }

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'query'=>$this->query,
			'provider_limit'=>$this->providerLimit,
			'global_limit'=>$this->globalLimit,
			'cursor'=>PanelSearchSanitizer::publicCursor($this->cursor),
			'tenant'=>$this->tenant(),
			'has_user'=>$this->user()!==null,
			'options'=>$this->options,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }
}
