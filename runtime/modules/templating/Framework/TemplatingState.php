<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable snapshot of templating runtime configuration.
 *
 * `TemplatingState` captures the values the renderer needs to decide how strict
 * template evaluation should be, where compiled artifacts are cached, which
 * globals are visible to templates, which contracts apply to named templates,
 * and how assets may be resolved. The snapshot is built from arrays so kernel
 * configuration can cross into the framework layer without exposing mutable
 * global state.
 */
final class TemplatingState {

	/**
	 * Stores normalized templating state.
	 *
	 * The constructor is private so every instance flows through `fromArray()`,
	 * which supplies defaults and guards non-array payload sections before the
	 * state is used by renderers or diagnostics.
	 *
	 * @param bool $isDevMode True when template development behavior is enabled.
	 * @param string $cacheDir Directory used for compiled template artifacts.
	 * @param array<string,mixed> $globalContext Template globals available at render time.
	 * @param bool $strictMode True when templates should treat contract or context gaps strictly.
	 * @param array<string,array<string,mixed>> $templateContracts Raw contract payloads keyed by normalized template name.
	 * @param array<string,mixed> $assetPolicy Raw asset policy configuration.
	 */
	private function __construct(
		private bool $isDevMode,
		private string $cacheDir,
		private array $globalContext,
		private bool $strictMode,
		private array $templateContracts,
		private array $assetPolicy,
		private ?AssetPolicy $assetPolicyObject=null
	){}

	/**
	 * Creates a templating state snapshot from configuration data.
	 *
	 * Missing values receive safe defaults. Non-array sections for globals,
	 * contracts, and asset policy are ignored so partially loaded configuration
	 * cannot leak scalar values into places where renderers expect maps.
	 *
	 * @param array<string,mixed> $state Raw templating configuration state.
	 * @return self Normalized immutable state snapshot.
	 */
	public static function fromArray(array $state): self {
		return new self(
			(bool)($state['is_dev_mode'] ?? false),
			(string)($state['cache_dir'] ?? ''),
			is_array($state['global_context'] ?? null) ? $state['global_context'] : [],
			(bool)($state['strict_mode'] ?? false),
			is_array($state['template_contracts'] ?? null) ? $state['template_contracts'] : [],
			is_array($state['asset_policy'] ?? null) ? $state['asset_policy'] : []
		);
	}

	/**
	 * Reports whether template development mode is enabled.
	 *
	 * Development mode is a renderer policy flag. It does not itself clear caches
	 * or relax contracts; consumers decide how this state affects compilation,
	 * diagnostics, and cache reuse.
	 *
	 * @return bool True when development-mode rendering behavior should be used.
	 */
	public function isDevMode(): bool {
		return $this->isDevMode;
	}

	/**
	 * Returns the configured template cache directory.
	 *
	 * The path is returned exactly as configured after string coercion. Directory
	 * creation and writability checks belong to the compiler/cache layer.
	 *
	 * @return string Cache directory path, or an empty string when no cache was configured.
	 */
	public function cacheDir(): string {
		return $this->cacheDir;
	}

	/**
	 * Returns the template global context map.
	 *
	 * The returned array is the state snapshot's global payload. Values may be any
	 * renderer-supported type, including scalars, arrays, objects, and callables.
	 *
	 * @return array<string,mixed> Template globals keyed by context name.
	 */
	public function globalContext(): array {
		return $this->globalContext;
	}

	/**
	 * Reports whether strict template behavior is enabled.
	 *
	 * Strict mode is consumed by renderers and contract validators to decide
	 * whether missing context, undeclared slots, or contract mismatches should be
	 * treated as hard failures.
	 *
	 * @return bool True when strict templating semantics should be enforced.
	 */
	public function strictMode(): bool {
		return $this->strictMode;
	}

	/**
	 * Reports whether a global context key exists.
	 *
	 * The lookup uses `array_key_exists()` so globals explicitly set to null still
	 * count as present.
	 *
	 * @param string $key Global context key to check exactly.
	 * @return bool True when the global key is present in the snapshot.
	 */
	public function hasGlobal(string $key): bool {
		return array_key_exists($key, $this->globalContext);
	}

	/**
	 * Returns a global context value with a fallback for absent keys.
	 *
	 * Null-valued globals return the default because this accessor uses null
	 * coalescing. Use `hasGlobal()` when callers must distinguish a missing key
	 * from a present null value.
	 *
	 * @param string $key Global context key to read exactly.
	 * @param mixed $default Fallback returned when the key is missing or null.
	 * @return mixed global context value, or the caller fallback when the key is absent or null.
	 */
	public function global(string $key, mixed $default=null): mixed {
		return $this->globalContext[$key] ?? $default;
	}

	/**
	 * Returns raw template contract payloads keyed by normalized template name.
	 *
	 * Use `templateContract()` to receive a `TemplateContract` value object for a
	 * specific template. This accessor exposes the raw map for diagnostics
	 * and bulk inspection.
	 *
	 * @return array<string,array<string,mixed>> Template contract payloads.
	 */
	public function templateContracts(): array {
		return $this->templateContracts;
	}

	/**
	 * Returns the asset policy value object for this state.
	 *
	 * The value object is rebuilt from the stored array on each call so consumers
	 * receive a typed policy without mutating this state snapshot.
	 *
	 * @return AssetPolicy Typed asset resolution policy.
	 */
	public function assetPolicy(): AssetPolicy {
		return $this->assetPolicyObject ??= AssetPolicy::fromArray($this->assetPolicy);
	}

	/**
	 * Reports whether a template contract exists for a template name.
	 *
	 * Template names are normalized the same way as `templateContract()`, allowing
	 * absolute paths, existing files, and slash-normalized relative names to share
	 * lookup behavior.
	 *
	 * @param string $templateName Template name, path, or contract key to normalize.
	 * @return bool True when a contract payload exists for the normalized template name.
	 */
	public function hasTemplateContract(string $templateName): bool {
		return array_key_exists($this->normalizeTemplateName($templateName), $this->templateContracts);
	}

	/**
	 * Returns the typed contract for a template when one is configured.
	 *
	 * Missing contracts and non-array contract payloads return null. Valid payloads
	 * are converted through `TemplateContract::fromArray()` so consumers receive a
	 * typed contract object instead of raw configuration.
	 *
	 * @param string $templateName Template name, path, or contract key to normalize.
	 * @return ?TemplateContract Typed contract, or null when no usable contract exists.
	 */
	public function templateContract(string $templateName): ?TemplateContract {
		$templateName=$this->normalizeTemplateName($templateName);
		if(!isset($this->templateContracts[$templateName]) || !is_array($this->templateContracts[$templateName])){
			return null;
		}
		return TemplateContract::fromArray($this->templateContracts[$templateName]);
	}

	/**
	 * Returns normalized templating configuration state.
	 *
	 * The array mirrors the input shape accepted by `fromArray()` and is safe for
	 * traces and diagnostics that need templating runtime configuration without
	 * live renderer objects.
	 *
	 * @return array{is_dev_mode:bool,cache_dir:string,global_context:array<string,mixed>,strict_mode:bool,template_contracts:array<string,array<string,mixed>>,asset_policy:array<string,mixed>} Normalized templating state.
	 */
	public function toArray(): array {
		return [
			'is_dev_mode'=>$this->isDevMode,
			'cache_dir'=>$this->cacheDir,
			'global_context'=>$this->globalContext,
			'strict_mode'=>$this->strictMode,
			'template_contracts'=>$this->templateContracts,
			'asset_policy'=>$this->assetPolicy,
		];
	}

	/**
	 * Normalizes a template name for contract lookup.
	 *
	 * Existing filesystem paths resolve to their canonical realpath. Non-existing
	 * names are trimmed and rewritten to the platform directory separator so
	 * configured contract keys remain stable across slash styles.
	 *
	 * @param string $templateName Template path or logical template name.
	 * @return string Canonical realpath or normalized logical template name.
	 */
	private function normalizeTemplateName(string $templateName): string {
		$resolved=realpath($templateName);
		return $resolved===false
			? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($templateName))
			: $resolved;
	}
}
