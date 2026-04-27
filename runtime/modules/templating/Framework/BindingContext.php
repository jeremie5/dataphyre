<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class BindingContext {

	public function __construct(
		private readonly string $template_name,
		private readonly bool $inline,
		private readonly array $data=[],
		private readonly array $theme_values=[],
		private readonly array $slots=[],
		private readonly array $overrides=[],
		private readonly array $trace_context=[]
	){}

	public function templateName(): string {
		return $this->template_name;
	}

	public function isInline(): bool {
		return $this->inline;
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

	public function overrides(): array {
		return $this->overrides;
	}

	public function traceContext(): array {
		return $this->trace_context;
	}

	public function renderTraceId(): ?string {
		$value=$this->trace_context['render_trace_id'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function bindingTraceId(): ?string {
		$value=$this->trace_context['binding_trace_id'] ?? null;
		return is_string($value) && trim($value)!=='' ? trim($value) : null;
	}

	public function withTraceContext(array $trace_context): self {
		return new self(
			$this->template_name,
			$this->inline,
			$this->data,
			$this->theme_values,
			$this->slots,
			$this->overrides,
			array_replace($this->trace_context, $trace_context)
		);
	}

	public function has(string $path): bool {
		return $this->read($this->data, $path, true)!==null;
	}

	public function get(string $path, mixed $default=null): mixed {
		$resolved=$this->read($this->data, $path, false);
		return $resolved['found'] === true ? $resolved['value'] : $default;
	}

	public function themeValue(string $path, mixed $default=null): mixed {
		$resolved=$this->read($this->theme_values, $path, false);
		return $resolved['found'] === true ? $resolved['value'] : $default;
	}

	public function slot(string $name, mixed $default=null): mixed {
		return array_key_exists($name, $this->slots) ? $this->slots[$name] : $default;
	}

	private function read(array $source, string $path, bool $sentinel_only): ?array {
		$path=trim($path);
		if($path===''){
			return ['found'=>false, 'value'=>null];
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
			return $sentinel_only ? null : ['found'=>false, 'value'=>null];
		}
		return ['found'=>true, 'value'=>$current];
	}
}
