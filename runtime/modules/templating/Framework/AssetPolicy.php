<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable policy for template asset loading hints.
 *
 * The policy describes which asset classes should be preloaded, how scripts
 * should load, whether scripts should be emitted as module/classic/auto, which
 * media target styles use, and what crossorigin mode font assets require.
 * Builder methods clone before mutation so shared defaults can be safely
 * specialized per component or layout.
 */
final class AssetPolicy {

	/** @var array<string, mixed>|null */
	private static ?array $lastDefinitionInput=null;

	/** @var array<string, mixed>|null */
	private static ?array $lastDefinitionResult=null;

	/** @var array<string, mixed>|null */
	private static ?array $previousDefinitionInput=null;

	/** @var array<string, mixed>|null */
	private static ?array $previousDefinitionResult=null;

	/**
	 * Stores an already-normalized asset policy definition.
	 *
	 * @param array<string, mixed> $definition Normalized policy payload produced by `normalize()`.
	 */
	private function __construct(private array $definition){}

	/**
	 * Creates an asset policy from a raw definition map.
	 *
	 * Missing sections are filled with defaults. Preload aliases such as `css`,
	 * `js`, `img`, and singular asset names are normalized into canonical keys.
	 * Unknown script strategies, script types, or font crossorigin values fall
	 * back to conservative defaults.
	 *
	 * @param array<string, mixed> $definition Raw asset policy definition.
	 * @return self Immutable policy with normalized preload, script, style, and font settings.
	 */
	public static function fromArray(array $definition): self {
		if(self::$lastDefinitionInput!==null && $definition===self::$lastDefinitionInput){
			return new self(self::$lastDefinitionResult);
		}
		if(self::$previousDefinitionInput!==null && $definition===self::$previousDefinitionInput){
			return new self(self::$previousDefinitionResult);
		}
		$normalized=self::normalize($definition);
		self::$previousDefinitionInput=self::$lastDefinitionInput;
		self::$previousDefinitionResult=self::$lastDefinitionResult;
		self::$lastDefinitionInput=$definition;
		self::$lastDefinitionResult=$normalized;
		return new self($normalized);
	}

	/**
	 * Creates the default asset policy.
	 *
	 *
	 * @return self Policy with all asset classes preloaded, blocking auto scripts, all-media styles, and anonymous font CORS.
	 */
	public static function defaults(): self {
		return self::fromArray([]);
	}

	/**
	 * Enables preload hints for one or more asset classes.
	 *
	 * Accepted values include canonical names and aliases: styles/css,
	 * scripts/js, images/img, and fonts/font. Unknown values are ignored.
	 *
	 * @param string ...$types Asset classes to mark for preload.
	 * @return self New policy with the selected preload flags enabled.
	 */
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

	/**
	 * Disables preload hints for one or more asset classes.
	 *
	 * Unknown values are ignored, which keeps caller-provided policy toggles from
	 * creating arbitrary keys in the serialized policy.
	 *
	 * @param string ...$types Asset classes to exclude from preload hints.
	 * @return self New policy with the selected preload flags disabled.
	 */
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

	/**
	 * Sets the script loading strategy.
	 *
	 * Only `async` and `defer` are special; every other value normalizes to
	 * `blocking`, preserving deterministic script order by default.
	 *
	 * @param string $strategy Requested script loading strategy.
	 * @return self New policy with normalized script strategy.
	 */
	public function scriptStrategy(string $strategy): self {
		$clone=clone $this;
		$clone->definition['scripts']['strategy']=self::normalizeScriptStrategy($strategy);
		return $clone;
	}

	/**
	 * Uses blocking script loading.
	 *
	 *
	 * @return self New policy with script strategy set to `blocking`.
	 */
	public function scriptBlocking(): self {
		return $this->scriptStrategy('blocking');
	}

	/**
	 * Uses deferred script loading.
	 *
	 *
	 * @return self New policy with script strategy set to `defer`.
	 */
	public function scriptDefer(): self {
		return $this->scriptStrategy('defer');
	}

	/**
	 * Uses asynchronous script loading.
	 *
	 *
	 * @return self New policy with script strategy set to `async`.
	 */
	public function scriptAsync(): self {
		return $this->scriptStrategy('async');
	}

	/**
	 * Sets the script type emission policy.
	 *
	 * `module` and `classic` are preserved; all other values become `auto`,
	 * letting the renderer decide based on asset metadata.
	 *
	 * @param string $type Requested script type policy.
	 * @return self New policy with normalized script type.
	 */
	public function scriptType(string $type): self {
		$clone=clone $this;
		$clone->definition['scripts']['type']=self::normalizeScriptType($type);
		return $clone;
	}

	/**
	 * Lets the renderer infer script type from each asset.
	 *
	 *
	 * @return self New policy with script type set to `auto`.
	 */
	public function autoScriptType(): self {
		return $this->scriptType('auto');
	}

	/**
	 * Forces scripts to be emitted as JavaScript modules.
	 *
	 *
	 * @return self New policy with script type set to `module`.
	 */
	public function moduleScripts(): self {
		return $this->scriptType('module');
	}

	/**
	 * Forces scripts to be emitted as classic scripts.
	 *
	 *
	 * @return self New policy with script type set to `classic`.
	 */
	public function classicScripts(): self {
		return $this->scriptType('classic');
	}

	/**
	 * Sets the media attribute used for stylesheet assets.
	 *
	 * Blank media strings normalize to `all`, matching browser defaults while
	 * keeping the serialized policy explicit.
	 *
	 * @param string $media CSS media query or media target.
	 * @return self New policy with normalized stylesheet media.
	 */
	public function styleMedia(string $media): self {
		$clone=clone $this;
		$clone->definition['styles']['media']=trim($media) === '' ? 'all' : trim($media);
		return $clone;
	}

	/**
	 * Sets the crossorigin mode for font assets.
	 *
	 * `use-credentials` is preserved. `none`, an empty string, `null`, and
	 * `false` disable the attribute. Any other value becomes `anonymous`, the
	 * default that works for most font preloads.
	 *
	 * @param ?string $value Requested font crossorigin mode.
	 * @return self New policy with normalized font CORS mode.
	 */
	public function fontCrossorigin(?string $value='anonymous'): self {
		$clone=clone $this;
		$clone->definition['fonts']['crossorigin']=self::normalizeFontCrossorigin($value);
		return $clone;
	}

	/**
	 * Returns the normalized asset loading policy.
	 *
	 * @return array{preload:array{styles:bool, scripts:bool, images:bool, fonts:bool}, scripts:array{strategy:string, type:string}, styles:array{media:string}, fonts:array{crossorigin:?string}} Renderer asset loading policy.
	 */
	public function toArray(): array {
		if(($this->definition['fonts']['crossorigin'] ?? null)===null){
			$definition=$this->definition;
			$definition['fonts']['crossorigin']='anonymous';
			return $definition;
		}
		return $this->definition;
	}

	/**
	 * Returns a compact summary of the active asset policy.
	 *
	 * @return array{preload:array<string, bool>, script_strategy:string, script_type:string, style_media:string, font_crossorigin:?string} Human-readable policy summary.
	 */
	public function summary(): array {
		return [
			'preload'=>$this->definition['preload'] ?? [],
			'script_strategy'=>$this->definition['scripts']['strategy'] ?? 'blocking',
			'script_type'=>$this->definition['scripts']['type'] ?? 'auto',
			'style_media'=>$this->definition['styles']['media'] ?? 'all',
			'font_crossorigin'=>$this->definition['fonts']['crossorigin'] ?? 'anonymous',
		];
	}

	/**
	 * Normalizes a raw policy definition into the canonical payload shape.
	 *
	 * @param array<string, mixed> $definition Raw policy map.
	 * @return array<string, mixed> Canonical policy with preload, scripts, styles, and fonts sections.
	 */
	private static function normalize(array $definition): array {
		$preloadDefinition=is_array($definition['preload'] ?? null) ? $definition['preload'] : [];
		$preload=[
			'styles'=>self::boolOrDefault($preloadDefinition['styles'] ?? $preloadDefinition['style'] ?? null, true),
			'scripts'=>self::boolOrDefault($preloadDefinition['scripts'] ?? $preloadDefinition['script'] ?? null, true),
			'images'=>self::boolOrDefault($preloadDefinition['images'] ?? $preloadDefinition['image'] ?? null, true),
			'fonts'=>self::boolOrDefault($preloadDefinition['fonts'] ?? $preloadDefinition['font'] ?? null, true),
		];

		$scriptsDefinition=is_array($definition['scripts'] ?? null) ? $definition['scripts'] : [];
		$stylesDefinition=is_array($definition['styles'] ?? null) ? $definition['styles'] : [];
		$fontsDefinition=is_array($definition['fonts'] ?? null) ? $definition['fonts'] : [];

		return [
			'preload'=>$preload,
			'scripts'=>[
				'strategy'=>self::normalizeScriptStrategy((string)($scriptsDefinition['strategy'] ?? 'blocking')),
				'type'=>self::normalizeScriptType((string)($scriptsDefinition['type'] ?? 'auto')),
			],
			'styles'=>[
				'media'=>trim((string)($stylesDefinition['media'] ?? 'all')) ?: 'all',
			],
			'fonts'=>[
				'crossorigin'=>self::normalizeFontCrossorigin($fontsDefinition['crossorigin'] ?? 'anonymous'),
			],
		];
	}

	/**
	 * Normalizes preload aliases into canonical asset classes.
	 *
	 * @param string $type Candidate preload class.
	 * @return ?string One of `styles`, `scripts`, `images`, or `fonts`; `null` for unknown input.
	 */
	private static function normalizePreloadType(string $type): ?string {
		return match(strtolower(trim($type))){
			'style', 'styles', 'css' => 'styles',
			'script', 'scripts', 'js' => 'scripts',
			'image', 'images', 'img' => 'images',
			'font', 'fonts' => 'fonts',
			default => null,
		};
	}

	/**
	 * Normalizes script loading strategy names.
	 *
	 * @param string $strategy Candidate script loading strategy.
	 * @return string `async`, `defer`, or `blocking`.
	 */
	private static function normalizeScriptStrategy(string $strategy): string {
		return match(strtolower(trim($strategy))){
			'async' => 'async',
			'defer' => 'defer',
			default => 'blocking',
		};
	}

	/**
	 * Normalizes script type policy names.
	 *
	 * @param string $type Candidate script type policy.
	 * @return string `classic`, `module`, or `auto`.
	 */
	private static function normalizeScriptType(string $type): string {
		return match(strtolower(trim($type))){
			'classic' => 'classic',
			'module' => 'module',
			default => 'auto',
		};
	}

	/**
	 * Normalizes font crossorigin policy values.
	 *
	 * @param mixed $value Candidate CORS mode.
	 * @return ?string `use-credentials`, `anonymous`, or `null` when the attribute should be omitted.
	 */
	private static function normalizeFontCrossorigin(mixed $value): ?string {
		$value=is_string($value) ? strtolower(trim($value)) : $value;
		return match($value){
			'use-credentials' => 'use-credentials',
			'none', '', null, false => null,
			default => 'anonymous',
		};
	}

	/**
	 * Returns booleans unchanged and falls back for all other values.
	 *
	 * @param mixed $value Candidate boolean setting.
	 * @param bool $default Default value used for non-boolean input.
	 * @return bool Normalized boolean.
	 */
	private static function boolOrDefault(mixed $value, bool $default): bool {
		return is_bool($value) ? $value : $default;
	}
}
