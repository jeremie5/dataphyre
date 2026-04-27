<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class AssetManifest {

	public function __construct(private array $payload){}

	public static function fromArray(array $payload): self {
		return new self($payload);
	}

	public function items(): array {
		return $this->list('items');
	}

	public function stylesheets(): array {
		return $this->list('stylesheets');
	}

	public function scripts(): array {
		return $this->list('scripts');
	}

	public function images(): array {
		return $this->list('images');
	}

	public function fonts(): array {
		return $this->list('fonts');
	}

	public function preloads(): array {
		return $this->list('preloads');
	}

	public function headItems(): array {
		return $this->list('head_items');
	}

	public function bodyItems(): array {
		return $this->list('body_items');
	}

	public function missing(): array {
		return $this->list('missing');
	}

	public function stylesheetTags(): array {
		return $this->list('stylesheet_tags');
	}

	public function scriptTags(): array {
		return $this->list('script_tags');
	}

	public function preloadTags(): array {
		return $this->list('preload_tags');
	}

	public function headTags(): array {
		return $this->list('head_tags');
	}

	public function bodyTags(): array {
		return $this->list('body_tags');
	}

	public function allTags(): array {
		return $this->list('all_tags');
	}

	public function headHtml(): string {
		return (string)($this->payload['head_html'] ?? implode("\n", $this->headTags()));
	}

	public function bodyHtml(): string {
		return (string)($this->payload['body_html'] ?? implode("\n", $this->bodyTags()));
	}

	public function html(): string {
		return (string)($this->payload['html'] ?? implode("\n", $this->allTags()));
	}

	public function policy(): AssetPolicy {
		return AssetPolicy::fromArray(
			is_array($this->payload['policy'] ?? null) ? $this->payload['policy'] : []
		);
	}

	public function signature(): string {
		return (string)($this->payload['signature'] ?? '');
	}

	public function hasMissingAssets(): bool {
		return $this->missing()!==[];
	}

	public function summary(): array {
		return [
			'item_count'=>count($this->items()),
			'stylesheet_count'=>count($this->stylesheets()),
			'script_count'=>count($this->scripts()),
			'image_count'=>count($this->images()),
			'font_count'=>count($this->fonts()),
			'preload_count'=>count($this->preloads()),
			'head_count'=>count($this->headItems()),
			'body_count'=>count($this->bodyItems()),
			'missing_count'=>count($this->missing()),
			'policy'=>$this->policy()->summary(),
			'signature'=>$this->signature(),
		];
	}

	public function toArray(): array {
		return $this->payload;
	}

	private function list(string $key): array {
		$value=$this->payload[$key] ?? [];
		return is_array($value) ? $value : [];
	}
}
