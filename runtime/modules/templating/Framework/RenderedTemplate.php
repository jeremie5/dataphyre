<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class RenderedTemplate {

	public function __construct(
		private string $content,
		private string $template_name,
		private array $data=[],
		private array $theme_values=[],
		private array $slots=[],
		private bool $inline=false,
		private ?string $render_trace_id=null,
		private ?TemplateManifest $manifest=null,
		private ?AssetManifest $asset_manifest=null,
		private array $bindings=[],
		private array $binding_warnings=[],
		private array $binding_planner=[]
	){}

	public function content(): string {
		return $this->content;
	}

	public function templateName(): string {
		return $this->template_name;
	}

	public function data(): array {
		return $this->data;
	}

	public function themeValues(): array {
		return $this->theme_values;
	}

	public function slots(): array {
		return $this->slots;
	}

	public function isInline(): bool {
		return $this->inline;
	}

	public function renderTraceId(): ?string {
		if($this->render_trace_id!==null && trim($this->render_trace_id)!==''){
			return $this->render_trace_id;
		}
		return $this->manifest?->renderTraceId();
	}

	public function hasManifest(): bool {
		return $this->manifest!==null;
	}

	public function manifest(): ?TemplateManifest {
		return $this->manifest;
	}

	public function hasAssetManifest(): bool {
		return $this->asset_manifest!==null;
	}

	public function assetManifest(): AssetManifest {
		return $this->asset_manifest ??= AssetManifest::fromArray([]);
	}

	public function headTags(): array {
		return $this->assetManifest()->headTags();
	}

	public function bodyTags(): array {
		return $this->assetManifest()->bodyTags();
	}

	public function headHtml(): string {
		return $this->assetManifest()->headHtml();
	}

	public function bodyHtml(): string {
		return $this->assetManifest()->bodyHtml();
	}

	public function assetHtml(): string {
		return $this->assetManifest()->html();
	}

	public function hasBindings(): bool {
		return $this->bindings!==[];
	}

	public function bindings(): array {
		return $this->bindings;
	}

	public function bindingTrace(): array {
		if($this->manifest!==null){
			return $this->manifest->bindingTrace();
		}
		return array_values(array_filter(array_map(
			static fn(array $binding): array => is_array($binding['trace'] ?? null) ? $binding['trace'] : [],
			$this->bindings
		), static fn(array $trace): bool => $trace!==[]));
	}

	public function bindingErrors(): array {
		return array_values(array_filter($this->bindings, static fn(array $binding): bool => ($binding['ok'] ?? true)!==true));
	}

	public function hasBindingErrors(): bool {
		return $this->bindingErrors()!==[];
	}

	public function bindingWarnings(): array {
		return $this->binding_warnings;
	}

	public function hasBindingWarnings(): bool {
		return $this->bindingWarnings()!==[];
	}

	public function bindingPlanner(): array {
		if($this->manifest!==null){
			return $this->manifest->bindingPlanner();
		}
		return $this->binding_planner;
	}

	public function hasBindingPlanner(): bool {
		return $this->bindingPlanner()!==[];
	}

	public function __toString(): string {
		return $this->content;
	}
}
