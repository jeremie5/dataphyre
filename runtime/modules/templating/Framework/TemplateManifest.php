<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplateManifest {

	public function __construct(private array $payload){}

	public static function fromArray(array $payload): self {
		return new self($payload);
	}

	public function templateName(): string {
		return (string)($this->payload['template_name'] ?? 'template.tpl');
	}

	public function isInline(): bool {
		return (bool)($this->payload['inline'] ?? false);
	}

	public function cacheStrategy(): string {
		return (string)($this->payload['cache_strategy'] ?? 'runtime');
	}

	public function cacheUsed(): bool {
		return (bool)($this->payload['cache_used'] ?? false);
	}

	public function strictMode(): bool {
		return (bool)($this->payload['strict_mode'] ?? false);
	}

	public function renderTraceId(): ?string {
		$value=$this->payload['render_trace_id'] ?? null;
		return is_string($value) && $value!=='' ? $value : null;
	}

	public function assetPolicy(): AssetPolicy {
		return AssetPolicy::fromArray(
			is_array($this->payload['asset_policy'] ?? null) ? $this->payload['asset_policy'] : []
		);
	}

	public function dataKeys(): array {
		return $this->list('data_keys');
	}

	public function themeValueKeys(): array {
		return $this->list('theme_value_keys');
	}

	public function slotNames(): array {
		return $this->list('slot_names');
	}

	public function durationMs(): float {
		return (float)($this->payload['duration_ms'] ?? 0.0);
	}

	public function contentLength(): int {
		return (int)($this->payload['content_length'] ?? 0);
	}

	public function failed(): bool {
		return (bool)($this->payload['failed'] ?? false);
	}

	public function failureMessage(): ?string {
		$message=$this->payload['failure_message'] ?? null;
		return is_string($message) && $message!=='' ? $message : null;
	}

	public function templates(): array {
		return $this->list('templates');
	}

	public function partials(): array {
		return $this->list('partials');
	}

	public function components(): array {
		return $this->list('components');
	}

	public function imports(): array {
		return $this->list('imports');
	}

	public function layouts(): array {
		return $this->list('layouts');
	}

	public function assets(): array {
		return $this->list('assets');
	}

	public function dependencies(): array {
		return $this->list('dependencies');
	}

	public function translations(): array {
		return $this->list('translations');
	}

	public function undefinedVariables(): array {
		return $this->list('undefined_variables');
	}

	public function missingReferences(): array {
		return $this->list('missing_references');
	}

	public function tags(): array {
		return $this->list('tags');
	}

	public function filters(): array {
		return $this->list('filters');
	}

	public function helpers(): array {
		return $this->list('helpers');
	}

	public function extensions(): array {
		return $this->list('extensions');
	}

	public function contracts(): array {
		return $this->list('contracts');
	}

	public function bindings(): array {
		return $this->list('bindings');
	}

	public function bindingTrace(): array {
		return $this->list('binding_trace');
	}

	public function bindingErrors(): array {
		return $this->list('binding_errors');
	}

	public function bindingWarnings(): array {
		return $this->list('binding_warnings');
	}

	public function bindingPlanner(): array {
		return $this->list('binding_planner');
	}

	public function contractViolations(): array {
		return $this->list('contract_violations');
	}

	public function errors(): array {
		return $this->list('errors');
	}

	public function hasMissingReferences(): bool {
		return $this->missingReferences()!==[];
	}

	public function hasErrors(): bool {
		return $this->errors()!==[];
	}

	public function hasContractViolations(): bool {
		return $this->contractViolations()!==[];
	}

	public function hasBindingErrors(): bool {
		return $this->bindingErrors()!==[];
	}

	public function hasBindingWarnings(): bool {
		return $this->bindingWarnings()!==[];
	}

	public function hasBindingPlanner(): bool {
		return $this->bindingPlanner()!==[];
	}

	public function summary(): array {
		return [
			'template_name'=>$this->templateName(),
			'inline'=>$this->isInline(),
			'duration_ms'=>$this->durationMs(),
			'failed'=>$this->failed(),
			'strict_mode'=>$this->strictMode(),
			'render_trace_id'=>$this->renderTraceId(),
			'asset_policy'=>$this->assetPolicy()->summary(),
			'template_count'=>count($this->templates()),
			'partial_count'=>count($this->partials()),
			'component_count'=>count($this->components()),
			'asset_count'=>count($this->assets())+count($this->dependencies()),
			'translation_count'=>count($this->translations()),
			'binding_count'=>count($this->bindings()),
			'binding_trace_count'=>count($this->bindingTrace()),
			'binding_error_count'=>count($this->bindingErrors()),
			'binding_warning_count'=>count($this->bindingWarnings()),
			'binding_planner_count'=>count($this->bindingPlanner()),
			'contract_violation_count'=>count($this->contractViolations()),
			'undefined_variable_count'=>count($this->undefinedVariables()),
			'missing_reference_count'=>count($this->missingReferences()),
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
