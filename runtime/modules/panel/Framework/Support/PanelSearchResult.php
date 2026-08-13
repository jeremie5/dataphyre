<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable, engine-neutral global-search result. */
final class PanelSearchResult implements \JsonSerializable {

	/** @param array<string,mixed> $meta */
	private function __construct(
		private readonly string $provider,
		private readonly string $resourceLabel,
		private readonly string $sourceLabel,
		private readonly string $title,
		private readonly string $subtitle='',
		private readonly string $recordKey='',
		private readonly string $url='',
		private readonly string $icon='',
		private readonly float $score=0.0,
		private readonly string $dedupeKey='',
		private readonly array $meta=[]
	){}

	/**
	 * Normalizes a provider/resource row. Blank titles are rejected.
	 *
	 * @param array<string,mixed> $result
	 */
	public static function fromArray(array $result, string $provider='', string $providerLabel='', ?string $defaultIcon=null): ?self {
		$title=self::text((string)($result['title'] ?? $result['label'] ?? $result['name'] ?? ''));
		if($title===''){
			return null;
		}
		$provider=Resource::normalizeName((string)($result['provider'] ?? $result['source'] ?? $result['resource'] ?? $provider));
		$resourceLabel=self::text((string)($result['resource_label'] ?? $result['provider_label'] ?? $result['source_label'] ?? $providerLabel));
		$sourceLabel=self::text((string)($result['source_label'] ?? $result['provider_label'] ?? $result['resource_label'] ?? $providerLabel));
		$resourceLabel=$resourceLabel!=='' ? $resourceLabel : self::humanize($provider);
		$sourceLabel=$sourceLabel!=='' ? $sourceLabel : $resourceLabel;
		$score=is_numeric($result['score'] ?? null) ? (float)$result['score'] : 0.0;
		if(!is_finite($score)){
			$score=0.0;
		}
		return new self(
			$provider,
			$resourceLabel,
			$sourceLabel,
			$title,
			self::text((string)($result['subtitle'] ?? $result['description'] ?? '')),
			self::text((string)($result['record_key'] ?? $result['key'] ?? $result['id'] ?? '')),
			self::safeUrl((string)($result['url'] ?? $result['href'] ?? '')),
			self::text((string)($result['icon'] ?? $defaultIcon ?? '')),
			$score,
			self::text((string)($result['dedupe_key'] ?? '')),
			is_array($result['meta'] ?? null) ? PanelSearchSanitizer::map($result['meta']) : []
		);
	}

	/** @param array<string,mixed> $meta */
	public static function make(string $title, string $provider='', array $meta=[]): self {
		$title=self::text($title);
		if($title===''){
			throw new \InvalidArgumentException('Panel search results require a title.');
		}
		$provider=Resource::normalizeName($provider);
		$label=self::humanize($provider);
		return new self($provider, $label, $label, $title, meta:PanelSearchSanitizer::map($meta));
	}

	public function provider(): string { return $this->provider; }
	public function providerLabel(): string { return $this->sourceLabel; }
	public function resourceLabel(): string { return $this->resourceLabel; }
	public function sourceLabel(): string { return $this->sourceLabel; }
	public function title(): string { return $this->title; }
	public function subtitle(): string { return $this->subtitle; }
	public function recordKey(): string { return $this->recordKey; }
	public function url(): string { return $this->url; }
	public function icon(): string { return $this->icon; }
	public function score(): float { return $this->score; }
	public function meta(): array { return $this->meta; }

	public function withScore(float|int $score): self {
		$score=(float)$score;
		if(!is_finite($score)){
			$score=0.0;
		}
		return new self($this->provider, $this->resourceLabel, $this->sourceLabel, $this->title, $this->subtitle, $this->recordKey, $this->url, $this->icon, $score, $this->dedupeKey, $this->meta);
	}

	public function withDedupeKey(string $key): self {
		return new self($this->provider, $this->resourceLabel, $this->sourceLabel, $this->title, $this->subtitle, $this->recordKey, $this->url, $this->icon, $this->score, self::text($key), $this->meta);
	}

	public function forProvider(string $provider, string $label='', ?string $icon=null): self {
		$hadProvider=$this->provider!=='';
		$provider=Resource::normalizeName($provider);
		$label=self::text($label) ?: self::humanize($provider);
		$resourceLabel=$hadProvider && $this->resourceLabel!=='' ? $this->resourceLabel : $label;
		$sourceLabel=$hadProvider && $this->sourceLabel!=='' ? $this->sourceLabel : $label;
		return new self($provider, $resourceLabel, $sourceLabel, $this->title, $this->subtitle, $this->recordKey, $this->url, $this->icon!=='' ? $this->icon : self::text((string)$icon), $this->score, $this->dedupeKey, $this->meta);
	}

	/** Stable default identity used for cross-provider deduplication. */
	public function dedupeKey(): string {
		if($this->dedupeKey!==''){
			return $this->dedupeKey;
		}
		if($this->url!==''){
			return 'url:'.self::dedupeUrl($this->url);
		}
		if($this->recordKey!==''){
			return 'record:'.$this->provider.':'.$this->recordKey;
		}
		return 'text:'.$this->provider.':'.self::lower($this->title).'|'.self::lower($this->subtitle);
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		return [
			'resource'=>$this->provider,
			'resource_label'=>$this->resourceLabel,
			'source'=>$this->provider,
			'source_label'=>$this->sourceLabel,
			'provider'=>$this->provider,
			'provider_label'=>$this->sourceLabel,
			'title'=>$this->title,
			'subtitle'=>$this->subtitle,
			'record_key'=>$this->recordKey,
			'key'=>$this->recordKey,
			'url'=>$this->url,
			'icon'=>$this->icon,
			'score'=>$this->score,
			'dedupe_key'=>$this->dedupeKey(),
			'meta'=>$this->meta,
		];
	}

	public function jsonSerialize(): array { return $this->toArray(); }

	private static function humanize(string $value): string {
		$value=trim(str_replace(['_', '-', '.'], ' ', $value));
		return $value==='' ? 'Search' : ucwords($value);
	}

	private static function lower(string $value): string {
		return function_exists('mb_strtolower') ? mb_strtolower(trim($value), 'UTF-8') : strtolower(trim($value));
	}

	private static function safeUrl(string $url): string {
		$url=self::text(preg_replace('/[\x00-\x1F\x7F]+/', '', $url) ?? '');
		if($url===''){ return ''; }
		if(str_starts_with($url, '//') || str_contains($url, '\\')){ return ''; }
		$parts=parse_url($url);
		if($parts===false){ return ''; }
		$scheme=strtolower((string)($parts['scheme'] ?? ''));
		if($scheme!=='' && !in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)){ return ''; }
		if(isset($parts['user']) || isset($parts['pass'])){ return ''; }
		return $url;
	}

	/** Lowercase only scheme/host; paths and queries may be case-sensitive. */
	private static function dedupeUrl(string $url): string {
		$parts=parse_url($url);
		if($parts===false || !isset($parts['scheme'], $parts['host'])){ return $url; }
		$normalized=strtolower((string)$parts['scheme']).'://'.strtolower((string)$parts['host']);
		if(isset($parts['port'])){ $normalized.=':'.(int)$parts['port']; }
		$normalized.=(string)($parts['path'] ?? '');
		if(isset($parts['query'])){ $normalized.='?'.$parts['query']; }
		if(isset($parts['fragment'])){ $normalized.='#'.$parts['fragment']; }
		return $normalized;
	}

	private static function text(string $value): string {
		$value=PanelSearchSanitizer::value(trim($value));
		return is_string($value) ? $value : '';
	}
}
