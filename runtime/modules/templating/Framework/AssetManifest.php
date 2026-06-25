<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Read-only wrapper around compiled template asset discovery output.
 *
 * AssetManifest exposes manifest data as focused lists, rendered tag
 * groups, HTML fragments, policy metadata, missing-asset diagnostics, and summary
 * counts used by RenderedTemplate and serialized examples.
 */
final class AssetManifest {

	/** @var array<string, mixed>|null */
	private ?array $summaryPayload=null;

	/**
	 * Stores a compiled template asset manifest payload.
	 *
	 * The payload is kept as provided by the templating pipeline so callers can round
	 * trip it through caches while using typed accessors for common asset groups.
	 *
	 * @param array<string, mixed> $payload Manifest payload produced by asset discovery/rendering.
	 */
	public function __construct(private array $payload){}

	/**
	 * Builds an asset manifest from cached or rendered manifest data.
	 *
	 * @param array<string, mixed> $payload Manifest data from cache, render state, or tests.
	 * @return self Manifest wrapper for typed asset access.
	 */
	public static function fromArray(array $payload): self {
		return new self($payload);
	}

	/**
	 * Returns all asset entries discovered for the rendered template.
	 *
	 * @return array<int|string, mixed> Raw manifest item entries.
	 */
	public function items(): array {
		return $this->list('items');
	}

	/**
	 * Returns stylesheet asset entries.
	 *
	 * @return array<int|string, mixed> Stylesheet descriptors or paths in manifest order.
	 */
	public function stylesheets(): array {
		return $this->list('stylesheets');
	}

	/**
	 * Returns script asset entries.
	 *
	 * @return array<int|string, mixed> Script descriptors or paths in manifest order.
	 */
	public function scripts(): array {
		return $this->list('scripts');
	}

	/**
	 * Returns image asset entries referenced by the template.
	 *
	 * @return array<int|string, mixed> Image descriptors or paths in manifest order.
	 */
	public function images(): array {
		return $this->list('images');
	}

	/**
	 * Returns font asset entries referenced by the template.
	 *
	 * @return array<int|string, mixed> Font descriptors or paths in manifest order.
	 */
	public function fonts(): array {
		return $this->list('fonts');
	}

	/**
	 * Returns assets selected for preload hints.
	 *
	 * @return array<int|string, mixed> Preload descriptors in manifest order.
	 */
	public function preloads(): array {
		return $this->list('preloads');
	}

	/**
	 * Returns asset entries assigned to document head placement.
	 *
	 * @return array<int|string, mixed> Head asset descriptors in render order.
	 */
	public function headItems(): array {
		return $this->list('head_items');
	}

	/**
	 * Returns asset entries assigned to document body placement.
	 *
	 * @return array<int|string, mixed> Body asset descriptors in render order.
	 */
	public function bodyItems(): array {
		return $this->list('body_items');
	}

	/**
	 * Returns assets that could not be resolved by the templating pipeline.
	 *
	 * @return array<int|string, mixed> Missing asset descriptors for diagnostics.
	 */
	public function missing(): array {
		return $this->list('missing');
	}

	/**
	 * Returns rendered stylesheet tags.
	 *
	 * @return array<int, string> Link/style tags ready for head output.
	 */
	public function stylesheetTags(): array {
		return $this->list('stylesheet_tags');
	}

	/**
	 * Returns rendered script tags.
	 *
	 * @return array<int, string> Script tags ready for body or configured placement output.
	 */
	public function scriptTags(): array {
		return $this->list('script_tags');
	}

	/**
	 * Returns rendered preload tags.
	 *
	 * @return array<int, string> Link preload tags ready for head output.
	 */
	public function preloadTags(): array {
		return $this->list('preload_tags');
	}

	/**
	 * Returns all rendered tags assigned to the document head.
	 *
	 * @return array<int, string> Head tags in final render order.
	 */
	public function headTags(): array {
		return $this->list('head_tags');
	}

	/**
	 * Returns all rendered tags assigned to the document body.
	 *
	 * @return array<int, string> Body tags in final render order.
	 */
	public function bodyTags(): array {
		return $this->list('body_tags');
	}

	/**
	 * Returns every rendered asset tag in manifest order.
	 *
	 * @return array<int, string> Combined rendered asset tags.
	 */
	public function allTags(): array {
		return $this->list('all_tags');
	}

	/**
	 * Returns complete head asset markup.
	 *
	 * Pre-rendered head_html from the payload wins; otherwise head tags are joined
	 * with newlines so callers get deterministic markup from either payload shape.
	 *
	 * @return string Markup intended for the document head.
	 */
	public function headHtml(): string {
		$html=$this->payload['head_html'] ?? null;
		if($html!==null){
			return (string)$html;
		}
		$tags=$this->payload['head_tags'] ?? [];
		return is_array($tags) ? implode("\n", $tags) : '';
	}

	/**
	 * Returns complete body asset markup.
	 *
	 * Pre-rendered body_html from the payload wins; otherwise body tags are joined
	 * with newlines.
	 *
	 * @return string Markup intended near the end of the document body.
	 */
	public function bodyHtml(): string {
		$html=$this->payload['body_html'] ?? null;
		if($html!==null){
			return (string)$html;
		}
		$tags=$this->payload['body_tags'] ?? [];
		return is_array($tags) ? implode("\n", $tags) : '';
	}

	/**
	 * Returns complete asset markup for callers that do not split head/body output.
	 *
	 * Pre-rendered html from the payload wins; otherwise all rendered tags are joined
	 * with newlines.
	 *
	 * @return string Combined asset markup.
	 */
	public function html(): string {
		$html=$this->payload['html'] ?? null;
		if($html!==null){
			return (string)$html;
		}
		$tags=$this->payload['all_tags'] ?? [];
		return is_array($tags) ? implode("\n", $tags) : '';
	}

	/**
	 * Returns the asset policy embedded in the manifest.
	 *
	 * Missing or invalid policy payloads resolve to an empty AssetPolicy, keeping
	 * policy access safe for cached or older manifests.
	 *
	 * @return AssetPolicy Manifest policy wrapper.
	 */
	public function policy(): AssetPolicy {
		return AssetPolicy::fromArray(
			is_array($this->payload['policy'] ?? null) ? $this->payload['policy'] : []
		);
	}

	/**
	 * Returns the manifest signature.
	 *
	 * The signature identifies the asset discovery result for cache comparison,
	 * debugging, and rendered-template diagnostics.
	 *
	 * @return string Signature string, or an empty string when unavailable.
	 */
	public function signature(): string {
		return (string)($this->payload['signature'] ?? '');
	}

	/**
	 * Reports whether the manifest contains unresolved assets.
	 *
	 * @return bool True when missing() contains at least one entry.
	 */
	public function hasMissingAssets(): bool {
		$missing=$this->payload['missing'] ?? [];
		return is_array($missing) && $missing!==[];
	}

	/**
	 * Summarizes manifest counts, policy summary, and signature.
	 *
	 * @return array{item_count:int, stylesheet_count:int, script_count:int, image_count:int, font_count:int, preload_count:int, head_count:int, body_count:int, missing_count:int, policy:array<string, mixed>, signature:string} Diagnostic manifest summary.
	 */
	public function summary(): array {
		if($this->summaryPayload!==null){
			return $this->summaryPayload;
		}
		$items=$this->payload['items'] ?? [];
		$stylesheets=$this->payload['stylesheets'] ?? [];
		$scripts=$this->payload['scripts'] ?? [];
		$images=$this->payload['images'] ?? [];
		$fonts=$this->payload['fonts'] ?? [];
		$preloads=$this->payload['preloads'] ?? [];
		$headItems=$this->payload['head_items'] ?? [];
		$bodyItems=$this->payload['body_items'] ?? [];
		$missing=$this->payload['missing'] ?? [];
		$policy=$this->payload['policy'] ?? [];
		return $this->summaryPayload=[
			'item_count'=>is_array($items) ? count($items) : 0,
			'stylesheet_count'=>is_array($stylesheets) ? count($stylesheets) : 0,
			'script_count'=>is_array($scripts) ? count($scripts) : 0,
			'image_count'=>is_array($images) ? count($images) : 0,
			'font_count'=>is_array($fonts) ? count($fonts) : 0,
			'preload_count'=>is_array($preloads) ? count($preloads) : 0,
			'head_count'=>is_array($headItems) ? count($headItems) : 0,
			'body_count'=>is_array($bodyItems) ? count($bodyItems) : 0,
			'missing_count'=>is_array($missing) ? count($missing) : 0,
			'policy'=>AssetPolicy::fromArray(is_array($policy) ? $policy : [])->summary(),
			'signature'=>(string)($this->payload['signature'] ?? ''),
		];
	}

	/**
	 * Returns the original manifest data.
	 *
	 * @return array<string, mixed> Manifest data for cache storage or JSON-compatible diagnostics.
	 */
	public function toArray(): array {
		return $this->payload;
	}

	/**
	 * Reads an array-valued manifest section.
	 *
	 * Non-array sections are treated as empty lists so accessors can tolerate older
	 * or partially populated manifest payloads.
	 *
	 * @param string $key Manifest section key.
	 * @return array<int|string, mixed> Section list or an empty array.
	 */
	private function list(string $key): array {
		$value=$this->payload[$key] ?? [];
		return is_array($value) ? $value : [];
	}
}
