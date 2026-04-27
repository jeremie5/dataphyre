<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class AssetPolicy {

	private function __construct(private array $definition){}

	public static function fromArray(array $definition): self {
		return new self(self::normalize($definition));
	}

	public static function defaults(): self {
		return self::fromArray([]);
	}

	public function preload(string ...$types): self {
		$clone=clone $this;
		foreach($types as $type){
			$key=self::normalizePreloadType($type);
			if($key!==null){
				$clone->definition['preload'][$key]=true;
			}
		}
		return $clone;
	}

	public function withoutPreload(string ...$types): self {
		$clone=clone $this;
		foreach($types as $type){
			$key=self::normalizePreloadType($type);
			if($key!==null){
				$clone->definition['preload'][$key]=false;
			}
		}
		return $clone;
	}

	public function scriptStrategy(string $strategy): self {
		$clone=clone $this;
		$clone->definition['scripts']['strategy']=self::normalizeScriptStrategy($strategy);
		return $clone;
	}

	public function scriptBlocking(): self {
		return $this->scriptStrategy('blocking');
	}

	public function scriptDefer(): self {
		return $this->scriptStrategy('defer');
	}

	public function scriptAsync(): self {
		return $this->scriptStrategy('async');
	}

	public function scriptType(string $type): self {
		$clone=clone $this;
		$clone->definition['scripts']['type']=self::normalizeScriptType($type);
		return $clone;
	}

	public function autoScriptType(): self {
		return $this->scriptType('auto');
	}

	public function moduleScripts(): self {
		return $this->scriptType('module');
	}

	public function classicScripts(): self {
		return $this->scriptType('classic');
	}

	public function styleMedia(string $media): self {
		$clone=clone $this;
		$clone->definition['styles']['media']=trim($media) === '' ? 'all' : trim($media);
		return $clone;
	}

	public function fontCrossorigin(?string $value='anonymous'): self {
		$clone=clone $this;
		$clone->definition['fonts']['crossorigin']=self::normalizeFontCrossorigin($value);
		return $clone;
	}

	public function toArray(): array {
		return self::normalize($this->definition);
	}

	public function summary(): array {
		return [
			'preload'=>$this->definition['preload'] ?? [],
			'script_strategy'=>$this->definition['scripts']['strategy'] ?? 'blocking',
			'script_type'=>$this->definition['scripts']['type'] ?? 'auto',
			'style_media'=>$this->definition['styles']['media'] ?? 'all',
			'font_crossorigin'=>$this->definition['fonts']['crossorigin'] ?? 'anonymous',
		];
	}

	private static function normalize(array $definition): array {
		$preload_definition=is_array($definition['preload'] ?? null) ? $definition['preload'] : [];
		$preload=[
			'styles'=>self::boolOrDefault($preload_definition['styles'] ?? $preload_definition['style'] ?? null, true),
			'scripts'=>self::boolOrDefault($preload_definition['scripts'] ?? $preload_definition['script'] ?? null, true),
			'images'=>self::boolOrDefault($preload_definition['images'] ?? $preload_definition['image'] ?? null, true),
			'fonts'=>self::boolOrDefault($preload_definition['fonts'] ?? $preload_definition['font'] ?? null, true),
		];

		$scripts_definition=is_array($definition['scripts'] ?? null) ? $definition['scripts'] : [];
		$styles_definition=is_array($definition['styles'] ?? null) ? $definition['styles'] : [];
		$fonts_definition=is_array($definition['fonts'] ?? null) ? $definition['fonts'] : [];

		return [
			'preload'=>$preload,
			'scripts'=>[
				'strategy'=>self::normalizeScriptStrategy((string)($scripts_definition['strategy'] ?? 'blocking')),
				'type'=>self::normalizeScriptType((string)($scripts_definition['type'] ?? 'auto')),
			],
			'styles'=>[
				'media'=>trim((string)($styles_definition['media'] ?? 'all')) ?: 'all',
			],
			'fonts'=>[
				'crossorigin'=>self::normalizeFontCrossorigin($fonts_definition['crossorigin'] ?? 'anonymous'),
			],
		];
	}

	private static function normalizePreloadType(string $type): ?string {
		return match(strtolower(trim($type))){
			'style', 'styles', 'css' => 'styles',
			'script', 'scripts', 'js' => 'scripts',
			'image', 'images', 'img' => 'images',
			'font', 'fonts' => 'fonts',
			default => null,
		};
	}

	private static function normalizeScriptStrategy(string $strategy): string {
		return match(strtolower(trim($strategy))){
			'async' => 'async',
			'defer' => 'defer',
			default => 'blocking',
		};
	}

	private static function normalizeScriptType(string $type): string {
		return match(strtolower(trim($type))){
			'classic' => 'classic',
			'module' => 'module',
			default => 'auto',
		};
	}

	private static function normalizeFontCrossorigin(mixed $value): ?string {
		$value=is_string($value) ? strtolower(trim($value)) : $value;
		return match($value){
			'use-credentials' => 'use-credentials',
			'none', '', null, false => null,
			default => 'anonymous',
		};
	}

	private static function boolOrDefault(mixed $value, bool $default): bool {
		return is_bool($value) ? $value : $default;
	}
}
