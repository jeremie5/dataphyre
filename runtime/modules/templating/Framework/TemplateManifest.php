<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable reader for template render, plan, and inspection manifests.
 *
 * The manifest exposes normalized accessors for template identity, cache behavior, data keys, slots, assets, tags, missing assets, timing, failures, and raw payloads so callers can consume render metadata deterministically.
 */
final class TemplateManifest {

	private ?AssetPolicy $assetPolicyObject=null;

	/** @var array<string,mixed>|null */
	private ?array $summaryPayload=null;

	/**
	 * Wraps a render or inspection payload in an immutable manifest reader.
	 *
	 * Missing manifest fields are normalized to empty strings, lists, nulls, or false so callers can parse incomplete render attempts safely.
	 *
	 * @param array<string,mixed> $payload Manifest payload produced by render, inspect, or planning paths.
	 */
	public function __construct(private array $payload){}

	/**
	 * Creates an immutable manifest reader from a render, plan, or inspection payload.
	 *
	 * @param array<string,mixed> $payload Manifest payload produced by render, inspect, or planning paths.
	 * @return self Manifest reader wrapping the supplied render, inspect, or planning payload.
	 */
	public static function fromArray(array $payload): self {
		return new self($payload);
	}

	/**
	 * Returns the rendered template name.
	 *
	 * @return string Template name, or the default template filename when absent.
	 */
	public function templateName(): string {
		return (string)($this->payload['template_name'] ?? 'template.tpl');
	}

	/**
	 * Reports whether the manifest came from an inline template string.
	 *
	 * @return bool True when the manifest marks the template source as inline.
	 */
	public function isInline(): bool {
		return (bool)($this->payload['inline'] ?? false);
	}

	/**
	 * Returns the cache strategy selected for the render or plan.
	 *
	 * @return string Cache strategy label recorded by the render or planning path.
	 */
	public function cacheStrategy(): string {
		return (string)($this->payload['cache_strategy'] ?? 'runtime');
	}

	/**
	 * Reports whether rendered content was served from cache.
	 *
	 * @return bool True when the render result came from the compiled template cache.
	 */
	public function cacheUsed(): bool {
		return (bool)($this->payload['cache_used'] ?? false);
	}

	/**
	 * Reports whether strict template validation was active.
	 *
	 * @return bool True when strict template validation was enabled for the render.
	 */
	public function strictMode(): bool {
		return (bool)($this->payload['strict_mode'] ?? false);
	}

	/**
	 * Returns the render trace identifier when the manifest carries one.
	 *
	 * @return ?string Non-empty render trace identifier, or null when the manifest does not carry one.
	 */
	public function renderTraceId(): ?string {
		$value=$this->payload['render_trace_id'] ?? null;
		return is_string($value) && $value!=='' ? $value : null;
	}

	/**
	 * Returns the asset policy represented by this manifest.
	 *
	 * @return AssetPolicy Asset policy reconstructed from the manifest, defaulting to an empty policy.
	 */
	public function assetPolicy(): AssetPolicy {
		return $this->assetPolicyObject ??= AssetPolicy::fromArray(
			is_array($this->payload['asset_policy'] ?? null) ? $this->payload['asset_policy'] : []
		);
	}

	/**
	 * Returns template data keys observed during render or inspection.
	 *
	 * @return list<string> Template data keys observed by render or inspection.
	 */
	public function dataKeys(): array {
		return $this->list('data_keys');
	}

	/**
	 * Returns theme value keys referenced by the render.
	 *
	 * @return list<string> Theme value keys referenced by the render payload.
	 */
	public function themeValueKeys(): array {
		return $this->list('theme_value_keys');
	}

	/**
	 * Returns slot names declared or resolved for the render.
	 *
	 * @return list<string> Slot names declared or resolved for the template render.
	 */
	public function slotNames(): array {
		return $this->list('slot_names');
	}

	/**
	 * Returns the recorded render duration.
	 *
	 * @return float Render duration in milliseconds, or 0.0 when absent.
	 */
	public function durationMs(): float {
		return (float)($this->payload['duration_ms'] ?? 0.0);
	}

	/**
	 * Returns the rendered content length.
	 *
	 * @return int Rendered content length in bytes, or zero when absent.
	 */
	public function contentLength(): int {
		return (int)($this->payload['content_length'] ?? 0);
	}

	/**
	 * Reports whether the render or inspection failed.
	 *
	 * @return bool True when the render or inspection recorded a failure.
	 */
	public function failed(): bool {
		return (bool)($this->payload['failed'] ?? false);
	}

	/**
	 * Returns the render failure message when one was recorded.
	 *
	 * @return ?string Non-empty failure message recorded by the render path, or null when absent.
	 */
	public function failureMessage(): ?string {
		$message=$this->payload['failure_message'] ?? null;
		return is_string($message) && $message!=='' ? $message : null;
	}

	/**
	 * Returns template files referenced by the manifest.
	 *
	 * @return list<string> Template files referenced by the render or inspection payload.
	 */
	public function templates(): array {
		return $this->list('templates');
	}

	/**
	 * Returns partial templates referenced by the manifest.
	 *
	 * @return list<string> Partial templates referenced by the render or inspection payload.
	 */
	public function partials(): array {
		return $this->list('partials');
	}

	/**
	 * Returns component templates referenced by the manifest.
	 *
	 * @return list<string> Component templates referenced by the render or inspection payload.
	 */
	public function components(): array {
		return $this->list('components');
	}

	/**
	 * Returns imported templates referenced by the manifest.
	 *
	 * @return list<string> Imported templates referenced by the render or inspection payload.
	 */
	public function imports(): array {
		return $this->list('imports');
	}

	/**
	 * Returns layout templates referenced by the manifest.
	 *
	 * @return list<string> Layout templates referenced by the render or inspection payload.
	 */
	public function layouts(): array {
		return $this->list('layouts');
	}

	/**
	 * Returns asset paths declared by the manifest.
	 *
	 * @return list<string> Asset paths declared by the template manifest.
	 */
	public function assets(): array {
		return $this->list('assets');
	}

	/**
	 * Returns asset or template dependencies recorded by the manifest.
	 *
	 * @return list<string> Asset or template dependencies recorded by the manifest.
	 */
	public function dependencies(): array {
		return $this->list('dependencies');
	}

	/**
	 * Returns translation keys referenced by the render.
	 *
	 * @return list<string> Translation keys referenced by the template render.
	 */
	public function translations(): array {
		return $this->list('translations');
	}

	/**
	 * Returns variables referenced by the template but missing from render data.
	 *
	 * @return list<string> Variable names referenced by the template but not supplied by render data.
	 */
	public function undefinedVariables(): array {
		return $this->list('undefined_variables');
	}

	/**
	 * Returns missing template, asset, or translation references.
	 *
	 * @return list<string> Template, asset, or translation references missing during inspection.
	 */
	public function missingReferences(): array {
		return $this->list('missing_references');
	}

	/**
	 * Returns template tags discovered during inspection.
	 *
	 * @return list<string> Template tags discovered during inspection.
	 */
	public function tags(): array {
		return $this->list('tags');
	}

	/**
	 * Returns template filters discovered during inspection.
	 *
	 * @return list<string> Template filters discovered during inspection.
	 */
	public function filters(): array {
		return $this->list('filters');
	}

	/**
	 * Returns helper names discovered during inspection.
	 *
	 * @return list<string> Helper names discovered during inspection.
	 */
	public function helpers(): array {
		return $this->list('helpers');
	}

	/**
	 * Returns extension names discovered during inspection.
	 *
	 * @return list<string> Extension names discovered during inspection.
	 */
	public function extensions(): array {
		return $this->list('extensions');
	}

	/**
	 * Returns template contract declarations recorded by the manifest.
	 *
	 * @return list<array<string,mixed>> Template contract declarations recorded by the manifest.
	 */
	public function contracts(): array {
		return $this->list('contracts');
	}

	/**
	 * Returns binding declarations recorded by the render planner.
	 *
	 * @return list<array<string,mixed>> Binding declarations recorded by the render planner.
	 */
	public function bindings(): array {
		return $this->list('bindings');
	}

	/**
	 * Returns binding resolution trace rows recorded during planning.
	 *
	 * @return list<array<string,mixed>> Binding resolution trace rows recorded during planning.
	 */
	public function bindingTrace(): array {
		return $this->list('binding_trace');
	}

	/**
	 * Returns binding errors recorded during render planning.
	 *
	 * @return list<array<string,mixed>> Binding errors recorded during render planning.
	 */
	public function bindingErrors(): array {
		return $this->list('binding_errors');
	}

	/**
	 * Returns binding warnings recorded during render planning.
	 *
	 * @return list<array<string,mixed>> Binding warnings recorded during render planning.
	 */
	public function bindingWarnings(): array {
		return $this->list('binding_warnings');
	}

	/**
	 * Returns planner diagnostics emitted for lazy binding resolution.
	 *
	 * @return list<array<string,mixed>> Planner diagnostics emitted for lazy binding resolution.
	 */
	public function bindingPlanner(): array {
		return $this->list('binding_planner');
	}

	/**
	 * Returns template contract violations recorded during render or inspection.
	 *
	 * @return list<array<string,mixed>> Template contract violations recorded during render or inspection.
	 */
	public function contractViolations(): array {
		return $this->list('contract_violations');
	}

	/**
	 * Returns render, planning, or inspection errors recorded by the manifest.
	 *
	 * @return list<array<string,mixed>|string> Render, planning, or inspection errors recorded by the manifest.
	 */
	public function errors(): array {
		return $this->list('errors');
	}

	/**
	 * Reports whether inspection found any missing references.
	 *
	 * @return bool True when missing template, asset, or translation references were recorded.
	 */
	public function hasMissingReferences(): bool {
		return $this->missingReferences()!==[];
	}

	/**
	 * Reports whether the manifest contains render, planning, or inspection errors.
	 *
	 * @return bool True when render, planning, or inspection errors were recorded.
	 */
	public function hasErrors(): bool {
		return $this->errors()!==[];
	}

	/**
	 * Reports whether template contract validation recorded violations.
	 *
	 * @return bool True when template contract violations were recorded.
	 */
	public function hasContractViolations(): bool {
		return $this->contractViolations()!==[];
	}

	/**
	 * Reports whether render planning recorded binding errors.
	 *
	 * @return bool True when binding resolution errors were recorded.
	 */
	public function hasBindingErrors(): bool {
		$bindingErrors=$this->payload['binding_errors'] ?? [];
		return is_array($bindingErrors) && $bindingErrors!==[];
	}

	/**
	 * Reports whether render planning recorded binding warnings.
	 *
	 * @return bool True when binding resolution warnings were recorded.
	 */
	public function hasBindingWarnings(): bool {
		$bindingWarnings=$this->payload['binding_warnings'] ?? [];
		return is_array($bindingWarnings) && $bindingWarnings!==[];
	}

	/**
	 * Reports whether planner diagnostics were recorded.
	 *
	 * @return bool True when binding planner diagnostics were recorded.
	 */
	public function hasBindingPlanner(): bool {
		return $this->bindingPlanner()!==[];
	}

	/**
	 * Builds diagnostic counters and key status fields from the manifest.
	 *
	 * @return array<string,mixed> Summary counters and key status fields derived from the manifest payload.
	 */
	public function summary(): array {
		if($this->summaryPayload!==null){
			return $this->summaryPayload;
		}
		$templates=$this->payload['templates'] ?? [];
		$partials=$this->payload['partials'] ?? [];
		$components=$this->payload['components'] ?? [];
		$assets=$this->payload['assets'] ?? [];
		$dependencies=$this->payload['dependencies'] ?? [];
		$translations=$this->payload['translations'] ?? [];
		$bindings=$this->payload['bindings'] ?? [];
		$bindingTrace=$this->payload['binding_trace'] ?? [];
		$bindingErrors=$this->payload['binding_errors'] ?? [];
		$bindingWarnings=$this->payload['binding_warnings'] ?? [];
		$bindingPlanner=$this->payload['binding_planner'] ?? [];
		$contractViolations=$this->payload['contract_violations'] ?? [];
		$undefinedVariables=$this->payload['undefined_variables'] ?? [];
		$missingReferences=$this->payload['missing_references'] ?? [];
		return $this->summaryPayload=[
			'template_name'=>$this->templateName(),
			'inline'=>$this->isInline(),
			'duration_ms'=>$this->durationMs(),
			'failed'=>$this->failed(),
			'strict_mode'=>$this->strictMode(),
			'render_trace_id'=>$this->renderTraceId(),
			'asset_policy'=>$this->assetPolicy()->summary(),
			'template_count'=>is_array($templates) ? count($templates) : 0,
			'partial_count'=>is_array($partials) ? count($partials) : 0,
			'component_count'=>is_array($components) ? count($components) : 0,
			'asset_count'=>(is_array($assets) ? count($assets) : 0)+(is_array($dependencies) ? count($dependencies) : 0),
			'translation_count'=>is_array($translations) ? count($translations) : 0,
			'binding_count'=>is_array($bindings) ? count($bindings) : 0,
			'binding_trace_count'=>is_array($bindingTrace) ? count($bindingTrace) : 0,
			'binding_error_count'=>is_array($bindingErrors) ? count($bindingErrors) : 0,
			'binding_warning_count'=>is_array($bindingWarnings) ? count($bindingWarnings) : 0,
			'binding_planner_count'=>is_array($bindingPlanner) ? count($bindingPlanner) : 0,
			'contract_violation_count'=>is_array($contractViolations) ? count($contractViolations) : 0,
			'undefined_variable_count'=>is_array($undefinedVariables) ? count($undefinedVariables) : 0,
			'missing_reference_count'=>is_array($missingReferences) ? count($missingReferences) : 0,
		];
	}

	/**
	 * Returns the manifest data supplied at construction time.
	 *
	 * @return array<string,mixed> Manifest data supplied at construction time.
	 */
	public function toArray(): array {
		return $this->payload;
	}

	/**
	 * Reads a list-valued manifest field with defensive shape normalization.
	 *
	 * Render manifests can be emitted during partial or failed render attempts, so
	 * malformed scalar fields collapse to an empty list instead of leaking raw
	 * payload shape differences into every accessor.
	 *
	 * @param string $key Manifest payload key expected to hold a list.
	 * @return array<int|string,mixed> Manifest list for the key, or an empty list when absent or malformed.
	 */
	private function list(string $key): array {
		$value=$this->payload[$key] ?? [];
		return is_array($value) ? $value : [];
	}
}
