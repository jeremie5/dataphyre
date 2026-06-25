<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Value object for rendered template output, inputs, assets, and diagnostics.
 *
 * RenderedTemplate preserves the final HTML/text output with template identity,
 * render data, theme values, slots, inline status, render trace IDs, optional
 * manifests, asset tags, binding records, binding warnings, and binding planner
 * details. It gives callers a safe string cast while keeping render diagnostics
 * available through typed accessors.
 */
final class RenderedTemplate {

	/** @var array<int, array<string, mixed>>|null */
	private ?array $bindingTracePayload=null;

	/** @var array<int, array<string, mixed>>|null */
	private ?array $bindingErrorPayload=null;

	private ?bool $hasBindingErrorPayload=null;

	/**
	 * Captures rendered output together with render inputs and diagnostics.
	 *
	 * The object preserves the final content, template identity, render data, theme
	 * values, slot payloads, optional manifest objects, asset manifest, binding trace
	 * records, warnings, and planner details produced by the templating runtime.
	 *
	 * @param string $content Final rendered template output.
	 * @param string $templateName Template file name or inline display name.
	 * @param array<string,mixed> $data Render data supplied to the template.
	 * @param array<string,mixed> $themeValues Theme values supplied to the template.
	 * @param array<string,mixed> $slots Slot content keyed by slot name.
	 * @param bool $inline Whether the template was rendered from inline source.
	 * @param string|null $renderTraceId Optional render trace identifier.
	 * @param TemplateManifest|null $manifest Optional render manifest.
	 * @param AssetManifest|null $assetManifest Optional asset manifest.
	 * @param array<int,array<string,mixed>> $bindings Binding records produced during render.
	 * @param array<int,array<string,mixed>> $bindingWarnings Binding warning records.
	 * @param array<int,array<string,mixed>> $bindingPlanner Binding planner payload.
	 */
	public function __construct(
		private string $content,
		private string $templateName,
		private array $data=[],
		private array $themeValues=[],
		private array $slots=[],
		private bool $inline=false,
		private ?string $renderTraceId=null,
		private ?TemplateManifest $manifest=null,
		private ?AssetManifest $assetManifest=null,
		private array $bindings=[],
		private array $bindingWarnings=[],
		private array $bindingPlanner=[]
	){}

	/**
	 * Returns the final rendered output.
	 *
	 * @return string Rendered template content.
	 */
	public function content(): string {
		return $this->content;
	}

	/**
	 * Returns the template identity associated with the render.
	 *
	 * File renders use the template file reference; inline renders use the supplied
	 * inline template name.
	 *
	 * @return string Template name or reference.
	 */
	public function templateName(): string {
		return $this->templateName;
	}

	/**
	 * Returns the render data supplied to the template.
	 *
	 * @return array<string,mixed> Data values supplied to the render pipeline.
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns theme values supplied during rendering.
	 *
	 * @return array<string,mixed> Theme token values supplied to the render pipeline.
	 */
	public function themeValues(): array {
		return $this->themeValues;
	}

	/**
	 * Returns slot content supplied during rendering.
	 *
	 * @return array Slot content keyed by slot name.
	 */
	public function slots(): array {
		return $this->slots;
	}

	/**
	 * Indicates whether the output came from inline template source.
	 *
	 * @return bool True for inline renders.
	 */
	public function isInline(): bool {
		return $this->inline;
	}

	/**
	 * Returns the render trace identifier for this output.
	 *
	 * An explicit constructor value wins. When absent, the identifier is read from the
	 * attached TemplateManifest if one exists.
	 *
	 * @return string|null Non-empty render trace identifier.
	 */
	public function renderTraceId(): ?string {
		if($this->renderTraceId!==null && trim($this->renderTraceId)!==''){
			return $this->renderTraceId;
		}
		return $this->manifest?->renderTraceId();
	}

	/**
	 * Indicates whether a render manifest was attached.
	 *
	 * @return bool True when manifest() can return a TemplateManifest.
	 */
	public function hasManifest(): bool {
		return $this->manifest!==null;
	}

	/**
	 * Returns the render manifest attached to this output.
	 *
	 * @return TemplateManifest|null Render manifest or null when not captured.
	 */
	public function manifest(): ?TemplateManifest {
		return $this->manifest;
	}

	/**
	 * Indicates whether an asset manifest was explicitly attached.
	 *
	 * Calling assetManifest() creates an empty manifest when none exists, so this
	 * method distinguishes captured asset data from lazy fallback creation.
	 *
	 * @return bool True when asset data was attached at construction time.
	 */
	public function hasAssetManifest(): bool {
		return $this->assetManifest!==null;
	}

	/**
	 * Returns the asset manifest for this render.
	 *
	 * Missing asset data is normalized to an empty AssetManifest and cached on the
	 * object so callers can safely read tags and HTML without null checks.
	 *
	 * @return AssetManifest Asset manifest for the rendered output.
	 */
	public function assetManifest(): AssetManifest {
		return $this->assetManifest ??= AssetManifest::fromArray([]);
	}

	/**
	 * Returns asset tags intended for document head placement.
	 *
	 * @return array<int, string> Head asset tags.
	 */
	public function headTags(): array {
		return $this->assetManifest()->headTags();
	}

	/**
	 * Returns asset tags intended for body placement.
	 *
	 * @return array<int, string> Body asset tags.
	 */
	public function bodyTags(): array {
		return $this->assetManifest()->bodyTags();
	}

	/**
	 * Returns head asset tags joined as HTML.
	 *
	 * @return string Head asset HTML.
	 */
	public function headHtml(): string {
		return $this->assetManifest()->headHtml();
	}

	/**
	 * Returns body asset tags joined as HTML.
	 *
	 * @return string Body asset HTML.
	 */
	public function bodyHtml(): string {
		return $this->assetManifest()->bodyHtml();
	}

	/**
	 * Returns all asset tags joined as HTML.
	 *
	 * @return string Combined asset HTML.
	 */
	public function assetHtml(): string {
		return $this->assetManifest()->html();
	}

	/**
	 * Indicates whether binding records were captured for this render.
	 *
	 * @return bool True when bindings() is non-empty.
	 */
	public function hasBindings(): bool {
		return $this->bindings!==[];
	}

	/**
	 * Returns raw binding records produced during rendering.
	 *
	 * Binding records may include path, value, ok/error state, and trace metadata
	 * depending on the renderer path.
	 *
	 * @return array<int, array<string, mixed>> Binding records.
	 */
	public function bindings(): array {
		return $this->bindings;
	}

	/**
	 * Returns binding trace records for the render.
	 *
	 * Manifest trace data is preferred. Without a manifest, non-empty trace arrays are
	 * extracted from individual binding records.
	 *
	 * @return array<int, array<string, mixed>> Binding trace entries.
	 */
	public function bindingTrace(): array {
		if($this->manifest!==null){
			return $this->manifest->bindingTrace();
		}
		if($this->bindingTracePayload!==null){
			return $this->bindingTracePayload;
		}
		$traces=[];
		foreach($this->bindings as $binding){
			$trace=$binding['trace'] ?? null;
			if(is_array($trace) && $trace!==[]){
				$traces[]=$trace;
			}
		}
		return $this->bindingTracePayload=$traces;
	}

	/**
	 * Returns binding records that did not complete successfully.
	 *
	 * Any binding whose ok flag is not true is treated as an error record.
	 *
	 * @return array<int, array<string, mixed>> Failed binding records.
	 */
	public function bindingErrors(): array {
		if($this->bindingErrorPayload!==null){
			return $this->bindingErrorPayload;
		}
		$errors=[];
		foreach($this->bindings as $binding){
			if(($binding['ok'] ?? true)!==true){
				$errors[]=$binding;
			}
		}
		$this->hasBindingErrorPayload=$errors!==[];
		return $this->bindingErrorPayload=$errors;
	}

	/**
	 * Indicates whether any binding errors were captured.
	 *
	 * @return bool True when bindingErrors() is non-empty.
	 */
	public function hasBindingErrors(): bool {
		if($this->hasBindingErrorPayload!==null){
			return $this->hasBindingErrorPayload;
		}
		foreach($this->bindings as $binding){
			if(($binding['ok'] ?? true)!==true){
				return $this->hasBindingErrorPayload=true;
			}
		}
		return $this->hasBindingErrorPayload=false;
	}

	/**
	 * Returns non-fatal binding warnings captured during rendering.
	 *
	 * @return array<int, array<string, mixed>> Binding warning records.
	 */
	public function bindingWarnings(): array {
		return $this->bindingWarnings;
	}

	/**
	 * Indicates whether any binding warnings were captured.
	 *
	 * @return bool True when bindingWarnings() is non-empty.
	 */
	public function hasBindingWarnings(): bool {
		return $this->bindingWarnings!==[];
	}

	/**
	 * Returns binding planner data for this render.
	 *
	 * Manifest planner data is preferred when available; otherwise the constructor
	 * planner data is returned.
	 *
	 * @return array<string, mixed> Binding planner data.
	 */
	public function bindingPlanner(): array {
		if($this->manifest!==null){
			return $this->manifest->bindingPlanner();
		}
		return $this->bindingPlanner;
	}

	/**
	 * Indicates whether binding planner details are available.
	 *
	 * @return bool True when bindingPlanner() is non-empty.
	 */
	public function hasBindingPlanner(): bool {
		return $this->bindingPlanner()!==[];
	}

	/**
	 * Casts the rendered template to its final output string.
	 *
	 * @return string Rendered template content.
	 */
	public function __toString(): string {
		return $this->content;
	}
}
