<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/**
 * Browser regression test manifest for panel surfaces.
 *
 * The manifest describes which URL to open, viewport
 * constraints, scripted interactions, screenshot output, console failure
 * policy, required selectors, accessibility gates, result artifact format, and
 * test metadata for browser-based panel regression runs.
 */
final class PanelBrowserRegressionManifest implements \JsonSerializable {

	/** @var string Normalized manifest name used in reports and artifact paths. */
	private string $name;
	/** @var string Absolute or application-relative URL exercised by the browser runner. */
	private string $url;
	/** @var array{width: int, height: int, device_scale_factor: float, is_mobile: bool} Browser viewport configuration. */
	private array $viewport=[
		'width'=>1280,
		'height'=>720,
		'device_scale_factor'=>1,
		'is_mobile'=>false,
	];
	/** @var array<int, array<string, mixed>> Ordered browser actions executed before assertions. */
	private array $interactions=[];
	/** @var ?string Screenshot artifact path requested from the runner. */
	private ?string $screenshotPath=null;
	/** @var array{fail_on: array<int, string>, allow: array<int, string>, ignore: array<int, string>} Console message policy. */
	private array $consolePolicy=[
		'fail_on'=>['error'],
		'allow'=>[],
		'ignore'=>[],
	];
	/** @var array<int, array<string, mixed>> Selector assertions evaluated after interactions. */
	private array $expectedSelectors=[];
	/** @var array{enabled: bool, fail_on: array<int, string>, rules: array<string, mixed>} Accessibility audit configuration. */
	private array $accessibility=[
		'enabled'=>true,
		'fail_on'=>['critical', 'serious'],
		'rules'=>[],
	];
	/** @var array{format: string, path: ?string, include_console: bool, include_accessibility: bool, include_screenshot: bool} Regression result artifact configuration. */
	private array $result=[
		'format'=>'json',
		'path'=>null,
		'include_console'=>true,
		'include_accessibility'=>true,
		'include_screenshot'=>true,
	];
	/** @var array<string, mixed> Additional manifest metadata for reports and dashboards. */
	private array $meta=[];

	/**
	 * Creates a browser regression manifest and applies optional overrides.
	 *
	 * Empty URLs are rejected because the runner cannot infer a target surface.
	 * Names are normalized with a stable fallback so reports and artifacts always
	 * have an identifier.
	 *
	 * @param string $name Manifest name before panel resource normalization.
	 * @param string $url Browser target URL.
	 * @param array<string, mixed> $options Optional manifest configuration.
	 *
	 * @throws \InvalidArgumentException When the URL is blank.
	 */
	public function __construct(string $name, string $url, array $options=[]) {
		$this->name=Resource::normalizeName($name) ?: 'browser_regression';
		$this->url=trim($url);
		if($this->url===''){
			throw new \InvalidArgumentException('Browser regression URL cannot be empty.');
		}
		$this->apply($options);
	}

	/**
	 * Creates a browser regression manifest.
	 *
	 *
	 * @param string $name Manifest name before normalization.
	 * @param string $url Browser target URL.
	 * @param array<string, mixed> $options Optional manifest configuration.
	 * @return self Browser regression manifest.
	 */
	public static function make(string $name, string $url, array $options=[]): self {
		return new self($name, $url, $options);
	}

	/**
	 * Hydrates a manifest from a browser-runner manifest array.
	 *
	 * The array may include the complete manifest data. `type`, `name`, and
	 * `url` are consumed as identity fields; all remaining keys are treated as
	 * options and normalized through the same constructor path.
	 *
	 * @param array<string, mixed> $manifest Serialized manifest payload.
	 * @return self Hydrated browser regression manifest.
	 */
	public static function fromArray(array $manifest): self {
		$name=(string)($manifest['name'] ?? 'browser_regression');
		$url=(string)($manifest['url'] ?? '');
		$options=$manifest;
		unset($options['type'], $options['name'], $options['url']);
		return new self($name, $url, $options);
	}

	/**
	 * Returns the normalized manifest name.
	 *
	 * @return string Manifest name used in reports and artifact organization.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Returns the browser target URL.
	 *
	 * @return string URL opened by the browser regression runner.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Sets the browser viewport configuration.
	 *
	 * Width and height are clamped to positive values. Additional options may
	 * include device scale factor and mobile emulation flags.
	 *
	 * @param int $width Viewport width in CSS pixels.
	 * @param int $height Viewport height in CSS pixels.
	 * @param array<string, mixed> $options Additional viewport options.
	 * @return self This manifest with viewport configuration updated.
	 */
	public function viewport(int $width, int $height, array $options=[]): self {
		$this->viewport=$this->normalizeViewport(array_replace($options, [
			'width'=>$width,
			'height'=>$height,
		]));
		return $this;
	}

	/**
	 * Appends one browser interaction to the manifest.
	 *
	 * Blank interaction types are ignored. Supported option fields are preserved
	 * by normalization for the runner to interpret.
	 *
	 * @param string $type Browser action type, such as click, fill, press, wait, or goto.
	 * @param array<string, mixed> $options Action-specific options.
	 * @return self This manifest with the interaction appended when valid.
	 */
	public function interaction(string $type, array $options=[]): self {
		$type=trim($type);
		if($type===''){
			return $this;
		}
		$this->interactions[]=$this->normalizeInteraction(array_replace($options, ['type'=>$type]));
		return $this;
	}

	/**
	 * Replaces the interaction list from browser-runner interaction arrays.
	 *
	 * Invalid or blank interaction entries are skipped so manifests can be
	 * hydrated from partially generated data without breaking the whole test.
	 *
	 * @param array<int, mixed> $interactions Candidate interaction payloads.
	 * @return self This manifest with interactions replaced.
	 */
	public function interactions(array $interactions): self {
		$this->interactions=[];
		foreach($interactions as $interaction){
			if(is_array($interaction)){
				$normalized=$this->normalizeInteraction($interaction);
				if(($normalized['type'] ?? '')!==''){
					$this->interactions[]=$normalized;
				}
			}
		}
		return $this;
	}

	/**
	 * Sets the screenshot artifact path.
	 *
	 *
	 * @param string $path Screenshot path requested from the browser runner.
	 * @return self This manifest with screenshot output configured.
	 */
	public function screenshot(string $path): self {
		$path=trim($path);
		$this->screenshotPath=$path!=='' ? $path : null;
		return $this;
	}

	/**
	 * Sets the console-message failure policy.
	 *
	 * The policy declares console levels that fail a run, allowed messages, and
	 * ignore patterns. Each list is normalized to unique non-empty strings.
	 *
	 * @param array<string, mixed> $policy Console policy payload.
	 * @return self This manifest with console policy updated.
	 */
	public function consolePolicy(array $policy): self {
		$this->consolePolicy=$this->normalizeConsolePolicy($policy);
		return $this;
	}

	/**
	 * Appends a selector assertion to the manifest.
	 *
	 * Blank selectors are ignored. Options can define state, expected count,
	 * timeout, and optional text matching for the browser assertion.
	 *
	 * @param string $selector CSS selector or runner-supported selector expression.
	 * @param array<string, mixed> $options Selector assertion options.
	 * @return self This manifest with selector expectation appended when valid.
	 */
	public function expectSelector(string $selector, array $options=[]): self {
		$selector=trim($selector);
		if($selector===''){
			return $this;
		}
		$this->expectedSelectors[]=$this->normalizeExpectedSelector(array_replace($options, ['selector'=>$selector]));
		return $this;
	}

	/**
	 * Replaces selector assertions from compact or structured definitions.
	 *
	 * Accepts numeric string lists, selector-to-options maps, and full selector
	 * payload arrays.
	 *
	 * @param array<int|string, mixed> $selectors Candidate selector definitions.
	 * @return self This manifest with selector expectations replaced.
	 */
	public function expectedSelectors(array $selectors): self {
		$this->expectedSelectors=[];
		foreach($selectors as $selector=>$options){
			if(is_int($selector) && is_string($options)){
				$this->expectSelector($options);
				continue;
			}
			if(is_string($selector) && is_array($options)){
				$this->expectSelector($selector, $options);
				continue;
			}
			if(is_array($options)){
				$normalized=$this->normalizeExpectedSelector($options);
				if(($normalized['selector'] ?? '')!==''){
					$this->expectedSelectors[]=$normalized;
				}
			}
		}
		return $this;
	}

	/**
	 * Sets the accessibility audit gate.
	 *
	 * Boolean input only toggles the audit. Array input can set enabled state,
	 * failing impact levels, and runner-specific rule overrides.
	 *
	 * @param array<string, mixed>|bool $accessibility Accessibility configuration or enabled flag.
	 * @return self This manifest with accessibility configuration updated.
	 */
	public function accessibility(array|bool $accessibility): self {
		if(is_bool($accessibility)){
			$this->accessibility['enabled']=$accessibility;
			return $this;
		}
		$this->accessibility=$this->normalizeAccessibility($accessibility);
		return $this;
	}

	/**
	 * Sets the regression result artifact.
	 *
	 * Supported formats are json, junit, and tap. Unknown formats fall back to
	 * json so automation output remains predictable.
	 *
	 * @param string $path Result artifact path.
	 * @param string $format Result format requested from the runner.
	 * @param array<string, mixed> $options Include flags and other result options.
	 * @return self This manifest with result output configured.
	 */
	public function result(string $path, string $format='json', array $options=[]): self {
		$this->result=$this->normalizeResult(array_replace($options, [
			'path'=>$path,
			'format'=>$format,
		]));
		return $this;
	}

	/**
	 * Adds metadata to the manifest.
	 *
	 * Array input shallow-merges metadata. String input writes a single key when
	 * non-blank.
	 *
	 * @param array<string, mixed>|string $key Metadata map or single metadata key.
	 * @param mixed $value Metadata value used with a string key.
	 * @return self This manifest with metadata updated.
	 */
	public function meta(array|string $key, mixed $value=null): self {
		if(is_array($key)){
			$this->meta=array_replace($this->meta, $key);
			return $this;
		}
		$key=trim($key);
		if($key!==''){
			$this->meta[$key]=$value;
		}
		return $this;
	}

	/**
	 * Serializes the complete browser regression manifest.
	 *
	 * The payload is intentionally runner-neutral and JSON-safe so it can be
	 * written to disk, embedded in regression reports, or loaded by browser runners.
	 *
	 * @return array<string, mixed> Browser regression manifest payload.
	 */
	public function toArray(): array {
		return [
			'type'=>'panel_browser_regression_manifest',
			'version'=>1,
			'name'=>$this->name,
			'url'=>$this->url,
			'viewport'=>$this->viewport,
			'interactions'=>$this->interactions,
			'screenshot_path'=>$this->screenshotPath,
			'console_policy'=>$this->consolePolicy,
			'expected_selectors'=>$this->expectedSelectors,
			'accessibility'=>$this->accessibility,
			'result'=>$this->result,
			'meta'=>$this->meta,
		];
	}

	/**
	 * Exposes the manifest payload to json_encode().
	 *
	 * @return array<string, mixed> Browser regression manifest payload.
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	/**
	 * Applies serialized option keys to the manifest.
	 *
	 * This mirrors the public mutators so constructor and array hydration share
	 * the same normalization rules.
	 *
	 * @param array<string, mixed> $options Serialized manifest options.
	 * @return void
	 */
	private function apply(array $options): void {
		if(isset($options['viewport']) && is_array($options['viewport'])){
			$this->viewport=$this->normalizeViewport($options['viewport']);
		}
		if(isset($options['interactions']) && is_array($options['interactions'])){
			$this->interactions($options['interactions']);
		}
		if(isset($options['screenshot_path'])){
			$this->screenshot((string)$options['screenshot_path']);
		}
		elseif(isset($options['screenshot'])){
			$this->screenshot((string)$options['screenshot']);
		}
		if(isset($options['console_policy']) && is_array($options['console_policy'])){
			$this->consolePolicy($options['console_policy']);
		}
		if(isset($options['expected_selectors']) && is_array($options['expected_selectors'])){
			$this->expectedSelectors($options['expected_selectors']);
		}
		if(isset($options['accessibility'])){
			$this->accessibility(is_array($options['accessibility']) || is_bool($options['accessibility']) ? $options['accessibility'] : []);
		}
		if(isset($options['result']) && is_array($options['result'])){
			$this->result=$this->normalizeResult($options['result']);
		}
		if(isset($options['meta']) && is_array($options['meta'])){
			$this->meta=$options['meta'];
		}
	}

	/**
	 * Normalizes viewport configuration for the browser runner.
	 *
	 * @param array<string, mixed> $viewport Candidate viewport payload.
	 * @return array{width: int, height: int, device_scale_factor: float, is_mobile: bool} Normalized viewport payload.
	 */
	private function normalizeViewport(array $viewport): array {
		return [
			'width'=>max(1, (int)($viewport['width'] ?? 1280)),
			'height'=>max(1, (int)($viewport['height'] ?? 720)),
			'device_scale_factor'=>max(0.1, (float)($viewport['device_scale_factor'] ?? $viewport['deviceScaleFactor'] ?? 1)),
			'is_mobile'=>(bool)($viewport['is_mobile'] ?? $viewport['isMobile'] ?? false),
		];
	}

	/**
	 * Normalizes one browser interaction payload.
	 *
	 * @param array<string, mixed> $interaction Candidate interaction payload.
	 * @return array<string, mixed> Normalized interaction payload.
	 */
	private function normalizeInteraction(array $interaction): array {
		$type=trim((string)($interaction['type'] ?? ''));
		$normalized=['type'=>$type];
		foreach(['selector', 'text', 'value', 'key', 'url', 'path'] as $field){
			if(isset($interaction[$field]) && trim((string)$interaction[$field])!==''){
				$normalized[$field]=(string)$interaction[$field];
			}
		}
		foreach(['timeout_ms', 'delay_ms'] as $field){
			if(isset($interaction[$field])){
				$normalized[$field]=max(0, (int)$interaction[$field]);
			}
		}
		if(isset($interaction['options']) && is_array($interaction['options'])){
			$normalized['options']=$interaction['options'];
		}
		return $normalized;
	}

	/**
	 * Normalizes console failure, allow, and ignore lists.
	 *
	 * @param array<string, mixed> $policy Candidate console policy payload.
	 * @return array{fail_on: array<int, string>, allow: array<int, string>, ignore: array<int, string>} Normalized console policy.
	 */
	private function normalizeConsolePolicy(array $policy): array {
		return [
			'fail_on'=>$this->stringList($policy['fail_on'] ?? ['error']),
			'allow'=>$this->stringList($policy['allow'] ?? []),
			'ignore'=>$this->stringList($policy['ignore'] ?? []),
		];
	}

	/**
	 * Normalizes one selector assertion payload.
	 *
	 * @param array<string, mixed> $selector Candidate selector expectation.
	 * @return array{selector: string, state: string, count: ?int, timeout_ms: int, text?: string} Normalized selector expectation.
	 */
	private function normalizeExpectedSelector(array $selector): array {
		$normalized=[
			'selector'=>trim((string)($selector['selector'] ?? '')),
			'state'=>(string)($selector['state'] ?? 'visible'),
			'count'=>isset($selector['count']) ? max(0, (int)$selector['count']) : null,
			'timeout_ms'=>max(0, (int)($selector['timeout_ms'] ?? 5000)),
		];
		if(isset($selector['text']) && trim((string)$selector['text'])!==''){
			$normalized['text']=(string)$selector['text'];
		}
		return $normalized;
	}

	/**
	 * Normalizes accessibility audit configuration.
	 *
	 * @param array<string, mixed> $accessibility Candidate accessibility payload.
	 * @return array{enabled: bool, fail_on: array<int, string>, rules: array<string, mixed>} Normalized accessibility configuration.
	 */
	private function normalizeAccessibility(array $accessibility): array {
		return [
			'enabled'=>(bool)($accessibility['enabled'] ?? true),
			'fail_on'=>$this->stringList($accessibility['fail_on'] ?? ['critical', 'serious']),
			'rules'=>is_array($accessibility['rules'] ?? null) ? $accessibility['rules'] : [],
		];
	}

	/**
	 * Normalizes result artifact configuration.
	 *
	 * @param array<string, mixed> $result Candidate result payload.
	 * @return array{format: string, path: ?string, include_console: bool, include_accessibility: bool, include_screenshot: bool} Normalized result configuration.
	 */
	private function normalizeResult(array $result): array {
		$format=strtolower(trim((string)($result['format'] ?? 'json')));
		if(!in_array($format, ['json', 'junit', 'tap'], true)){
			$format='json';
		}
		return [
			'format'=>$format,
			'path'=>isset($result['path']) && trim((string)$result['path'])!=='' ? (string)$result['path'] : null,
			'include_console'=>(bool)($result['include_console'] ?? true),
			'include_accessibility'=>(bool)($result['include_accessibility'] ?? true),
			'include_screenshot'=>(bool)($result['include_screenshot'] ?? true),
		];
	}

	/**
	 * Converts scalar or array input into a unique string list.
	 *
	 * @param mixed $value Candidate string or list.
	 * @return array<int, string> Unique non-empty strings.
	 */
	private function stringList(mixed $value): array {
		if(is_string($value)){
			$value=[$value];
		}
		if(!is_array($value)){
			return [];
		}
		$items=[];
		foreach($value as $item){
			$item=trim((string)$item);
			if($item!==''){
				$items[]=$item;
			}
		}
		return array_values(array_unique($items));
	}
}
