<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable render-time context for template binding resolution.
 *
 * BindingContext packages the template identity, inline/render mode, caller data,
 * theme values, slots, override payloads, and tracing correlation into one value
 * object. Template helpers can read nested data through dotted paths without
 * mutating the original render inputs.
 */
final class BindingContext {

	/**
	 * Creates a binding context for one template render.
	 *
	 * @param readonly string $templateName Template file/name being rendered.
	 * @param readonly bool $inline Whether the render came from an inline template source.
	 * @param readonly array<string, mixed> $data Caller-provided binding data.
	 * @param readonly array<string, mixed> $themeValues Theme tokens and resolved design values.
	 * @param readonly array<string, mixed> $slots Named slot content.
	 * @param readonly array<string, mixed> $overrides Binding or render overrides.
	 * @param readonly array<string, mixed> $traceContext Correlation fields for runtime tracing.
	 */
	public function __construct(
		private readonly string $templateName,
		private readonly bool $inline,
		private readonly array $data=[],
		private readonly array $themeValues=[],
		private readonly array $slots=[],
		private readonly array $overrides=[],
		private readonly array $traceContext=[]
	){}

	/**
	 * Returns the template name associated with this binding context.
	 *
	 * @return string Template name or inline template identifier.
	 */
	public function templateName(): string {
		return $this->templateName;
	}

	/**
	 * Reports whether the context belongs to an inline template render.
	 *
	 * @return bool True for inline template sources.
	 */
	public function isInline(): bool {
		return $this->inline;
	}

	/**
	 * Returns caller-provided binding data.
	 *
	 * @return array<string, mixed> Data available to get() and has().
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Returns resolved theme values available to the template.
	 *
	 * @return array<string, mixed> Theme value tree available to themeValue().
	 */
	public function themeValues(): array {
		return $this->themeValues;
	}

	/**
	 * Returns named slot content for the render.
	 *
	 * @return array<string, mixed> Slot values keyed by slot name.
	 */
	public function slots(): array {
		return $this->slots;
	}

	/**
	 * Returns render or binding overrides.
	 *
	 * @return array<string, mixed> Override payload supplied by the renderer.
	 */
	public function overrides(): array {
		return $this->overrides;
	}

	/**
	 * Returns runtime tracing correlation context.
	 *
	 * @return array<string, mixed> Trace context including render_trace_id and binding_trace_id when available.
	 */
	public function traceContext(): array {
		return $this->traceContext;
	}

	/**
	 * Returns the render-level trace id.
	 *
	 * @return string|null Non-empty render_trace_id from trace context.
	 */
	public function renderTraceId(): ?string {
		$value=$this->traceContext['render_trace_id'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Returns the binding-level trace id.
	 *
	 * @return string|null Non-empty binding_trace_id from trace context.
	 */
	public function bindingTraceId(): ?string {
		$value=$this->traceContext['binding_trace_id'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	/**
	 * Returns a copy with additional trace context merged in.
	 *
	 * Existing trace keys are overwritten by the supplied payload so downstream
	 * render phases can refine correlation without mutating the original context.
	 *
	 * @param array<string, mixed> $traceContext Trace fields to merge.
	 * @return self New context with merged trace context.
	 */
	public function withTraceContext(array $traceContext): self {
		return new self(
			$this->templateName,
			$this->inline,
			$this->data,
			$this->themeValues,
			$this->slots,
			$this->overrides,
			array_replace($this->traceContext, $traceContext)
		);
	}

	/**
	 * Checks whether a dotted data path exists.
	 *
	 * @param string $path Dot-separated path inside data().
	 * @return bool True when every segment exists, even if the final value is null.
	 */
	public function has(string $path): bool {
		return $this->exists($this->data, $path);
	}

	/**
	 * Resolves a dotted path from caller-provided bindings.
	 *
	 * Arrays and public object properties are traversed segment by segment. Missing
	 * segments return the provided default.
	 *
	 * @param string $path Dot-separated path inside data().
	 * @param mixed $default Value returned when the path is absent.
	 * @return mixed value found at the dotted data path, including null, or the caller default when any segment is missing.
	 */
	public function get(string $path, mixed $default=null): mixed {
		$trimmed=trim($path);
		if($trimmed!=='' && !str_contains($trimmed, '.')){
			return array_key_exists($trimmed, $this->data) ? $this->data[$trimmed] : $default;
		}
		$resolved=$this->read($this->data, $path, false);
		return $resolved['found'] === true ? $resolved['value'] : $default;
	}

	/**
	 * Reads a dotted path from theme values.
	 *
	 * @param string $path Dot-separated path inside themeValues().
	 * @param mixed $default Value returned when the path is absent.
	 * @return mixed value found at the dotted theme path, including null, or the caller default when any segment is missing.
	 */
	public function themeValue(string $path, mixed $default=null): mixed {
		$resolved=$this->read($this->themeValues, $path, false);
		return $resolved['found'] === true ? $resolved['value'] : $default;
	}

	/**
	 * Returns a named slot value.
	 *
	 * @param string $name Slot name.
	 * @param mixed $default Value returned when the slot is absent.
	 * @return mixed slot content keyed by exact name, including null, or the caller default when the slot is absent.
	 */
	public function slot(string $name, mixed $default=null): mixed {
		return array_key_exists($name, $this->slots) ? $this->slots[$name] : $default;
	}

	/**
	 * Resolves a dotted path against an array/object tree.
	 *
	 * @param array<string, mixed> $source Root source payload.
	 * @param string $path Dot-separated path to resolve.
	 * @param bool $sentinelOnly Whether missing paths should return null for fast existence checks.
	 * @return array{found: bool, value: mixed}|null Resolution payload, or null for sentinel misses.
	 */
	private function read(array $source, string $path, bool $sentinelOnly): ?array {
		$path=trim($path);
		if($path===''){
			return ['found'=>false, 'value'=>null];
		}
		if(str_contains($path, '.')===false){
			if(array_key_exists($path, $source)){
				return ['found'=>true, 'value'=>$source[$path]];
			}
			return $sentinelOnly ? null : ['found'=>false, 'value'=>null];
		}
		$segments=explode('.', $path);
		$current=$source;
		foreach($segments as $segment){
			if(is_array($current) && array_key_exists($segment, $current)){
				$current=$current[$segment];
				continue;
			}
			if(is_object($current) && property_exists($current, $segment)){
				$current=$current->$segment;
				continue;
			}
			return $sentinelOnly ? null : ['found'=>false, 'value'=>null];
		}
		return ['found'=>true, 'value'=>$current];
	}

	/**
	 * Checks dotted-path existence without materializing a read payload.
	 *
	 * @param array<string, mixed> $source Root source payload.
	 * @param string $path Dot-separated path to resolve.
	 * @return bool True when every segment exists.
	 */
	private function exists(array $source, string $path): bool {
		$path=trim($path);
		if($path===''){
			return false;
		}
		if(str_contains($path, '.')===false){
			return array_key_exists($path, $source);
		}
		$current=$source;
		foreach(explode('.', $path) as $segment){
			if(is_array($current) && array_key_exists($segment, $current)){
				$current=$current[$segment];
				continue;
			}
			if(is_object($current) && property_exists($current, $segment)){
				$current=$current->$segment;
				continue;
			}
			return false;
		}
		return true;
	}
}
