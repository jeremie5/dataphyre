<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplatePlan {

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

	public function cacheMode(): string {
		return (string)($this->payload['cache_mode'] ?? 'memory');
	}

	public function sourceHash(): string {
		return (string)($this->payload['source_hash'] ?? '');
	}

	public function graph(): array {
		$graph=$this->payload['graph'] ?? [];
		return is_array($graph) ? $graph : [];
	}

	public function graphNodes(): array {
		$graph=$this->graph();
		$nodes=$graph['nodes'] ?? [];
		return is_array($nodes) ? $nodes : [];
	}

	public function graphEdges(): array {
		$graph=$this->graph();
		$edges=$graph['edges'] ?? [];
		return is_array($edges) ? $edges : [];
	}

	public function allTemplates(): array {
		return $this->list('all_templates');
	}

	public function unresolvedReferences(): array {
		return $this->list('unresolved_references');
	}

	public function aggregate(): array {
		$aggregate=$this->payload['aggregate'] ?? [];
		return is_array($aggregate) ? $aggregate : [];
	}

	public function assetManifest(): AssetManifest {
		return AssetManifest::fromArray(
			is_array($this->payload['asset_manifest'] ?? null) ? $this->payload['asset_manifest'] : []
		);
	}

	public function dataPaths(): array {
		return $this->list('data_paths');
	}

	public function topLevelDataKeys(): array {
		return $this->list('top_level_data_keys');
	}

	public function slotNames(): array {
		return $this->list('slot_names');
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

	public function features(): array {
		return $this->list('features');
	}

	public function suggestedContract(): TemplateContract {
		return TemplateContract::fromArray(
			is_array($this->payload['suggested_contract'] ?? null) ? $this->payload['suggested_contract'] : []
		);
	}

	public function summary(): array {
		$aggregate=$this->aggregate();
		return [
			'template_name'=>$this->templateName(),
			'inline'=>$this->isInline(),
			'cache_mode'=>$this->cacheMode(),
			'template_count'=>count($this->allTemplates()),
			'data_path_count'=>count(is_array($aggregate['data_paths'] ?? null) ? $aggregate['data_paths'] : $this->dataPaths()),
			'slot_count'=>count(is_array($aggregate['slot_names'] ?? null) ? $aggregate['slot_names'] : $this->slotNames()),
			'partial_count'=>count(is_array($aggregate['partials'] ?? null) ? $aggregate['partials'] : $this->partials()),
			'component_count'=>count(is_array($aggregate['components'] ?? null) ? $aggregate['components'] : $this->components()),
			'import_count'=>count(is_array($aggregate['imports'] ?? null) ? $aggregate['imports'] : $this->imports()),
			'layout_count'=>count(is_array($aggregate['layouts'] ?? null) ? $aggregate['layouts'] : $this->layouts()),
			'asset_count'=>count(is_array($aggregate['assets'] ?? null) ? $aggregate['assets'] : $this->assets())+count(is_array($aggregate['dependencies'] ?? null) ? $aggregate['dependencies'] : $this->dependencies()),
			'unresolved_reference_count'=>count($this->unresolvedReferences()),
			'missing_asset_count'=>count($this->assetManifest()->missing()),
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
