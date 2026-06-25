<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Typed read model for template planning and dependency-analysis output.
 *
 * TemplatePlan wraps the raw analyzer payload and exposes stable accessors for
 * template identity, cache mode, dependency graph, discovered render-data paths,
 * slots, partials, components, imports, layouts, assets, localization keys,
 * extension usage, inferred contracts, and summary counts. It preserves unknown
 * analyzer fields through toArray() while normalizing known fields for consumers.
 */
final class TemplatePlan {

	private ?AssetManifest $assetManifestObject=null;
	private ?TemplateContract $suggestedContractObject=null;

	/** @var array<string, int|string|bool>|null */
	private ?array $summaryPayload=null;

	/**
	 * Stores the raw template analysis payload produced by the templating kernel.
	 *
	 * The object is intentionally tolerant of missing or malformed keys. Accessors
	 * normalize individual fields to stable scalar, array, or value-object results
	 * so diagnostics can consume incomplete analysis
	 * output safely.
	 *
	 * @param array<string,mixed> $payload Template planning payload from the kernel analyzer.
	 */
	public function __construct(private array $payload){}

	/**
	 * Creates a template plan from a raw analysis payload.
	 *
	 * No validation is performed at construction time; each accessor documents and
	 * normalizes the portion of the payload it exposes.
	 *
	 * @param array<string,mixed> $payload Template planning payload from the kernel analyzer.
	 * @return self Plan wrapper around the supplied payload.
	 */
	public static function fromArray(array $payload): self {
		return new self($payload);
	}

	/**
	 * Returns the template name associated with this plan.
	 *
	 * Missing template_name falls back to template.tpl so summaries always have a
	 * displayable template identifier.
	 *
	 * @return string Template name or fallback placeholder.
	 */
	public function templateName(): string {
		return (string)($this->payload['template_name'] ?? 'template.tpl');
	}

	/**
	 * Indicates whether the analyzed template came from inline source.
	 *
	 * Inline plans usually do not correspond to a durable template file and may have
	 * different cache and asset expectations.
	 *
	 * @return bool True when the payload marks the template as inline.
	 */
	public function isInline(): bool {
		return (bool)($this->payload['inline'] ?? false);
	}

	/**
	 * Returns the cache mode selected for the analyzed template.
	 *
	 * Missing cache_mode values default to memory, matching the framework's safe
	 * non-persistent planning behavior.
	 *
	 * @return string cache mode recorded by template analysis, defaulting to memory when absent.
	 */
	public function cacheMode(): string {
		return (string)($this->payload['cache_mode'] ?? 'memory');
	}

	/**
	 * Returns the source hash captured during template analysis.
	 *
	 * The hash is an opaque string suitable for cache keys, diagnostics, or change
	 * detection. Missing hashes return an empty string.
	 *
	 * @return string Opaque template source hash.
	 */
	public function sourceHash(): string {
		return (string)($this->payload['source_hash'] ?? '');
	}

	/**
	 * Returns analyzer graph data for template dependencies.
	 *
	 * The graph normally contains nodes and edges describing template relationships.
	 * Non-array graph data is normalized to an empty array.
	 *
	 * @return array<string, mixed> Dependency graph data with analyzer-defined keys.
	 */
	public function graph(): array {
		$graph=$this->payload['graph'] ?? [];
		return is_array($graph) ? $graph : [];
	}

	/**
	 * Returns the node list from the template dependency graph.
	 *
	 * Nodes describe templates, partials, layouts, imports, or related artifacts
	 * discovered during planning. Missing or malformed node data becomes an empty list.
	 *
	 * @return array<int, mixed> Graph node entries.
	 */
	public function graphNodes(): array {
		$graph=$this->graph();
		$nodes=$graph['nodes'] ?? [];
		return is_array($nodes) ? $nodes : [];
	}

	/**
	 * Returns the edge list from the template dependency graph.
	 *
	 * Edges describe relationships between graph nodes, such as extends, includes,
	 * imports, or asset dependencies. Missing or malformed edge data becomes empty.
	 *
	 * @return array<int, mixed> Graph edge entries.
	 */
	public function graphEdges(): array {
		$graph=$this->graph();
		$edges=$graph['edges'] ?? [];
		return is_array($edges) ? $edges : [];
	}

	/**
	 * Returns every template name observed while building the plan.
	 *
	 * This includes the root template and any reachable related templates reported
	 * in the all_templates payload list.
	 *
	 * @return array<int, string> Template names discovered by analysis.
	 */
	public function allTemplates(): array {
		return $this->list('all_templates');
	}

	/**
	 * Returns references the planner could not resolve to known templates or assets.
	 *
	 * The list helps diagnostics surface missing includes, layouts,
	 * components, imports, or other unresolved template references.
	 *
	 * @return array<int, mixed> Unresolved reference descriptors.
	 */
	public function unresolvedReferences(): array {
		return $this->list('unresolved_references');
	}

	/**
	 * Returns aggregate planning data emitted by the analyzer.
	 *
	 * Aggregate data may contain precomputed lists for data paths, slots, partials,
	 * components, imports, layouts, assets, and dependencies. Non-array aggregate
	 * data is normalized to an empty array.
	 *
	 * @return array<string, mixed> Aggregate analysis data.
	 */
	public function aggregate(): array {
		$aggregate=$this->payload['aggregate'] ?? [];
		return is_array($aggregate) ? $aggregate : [];
	}

	/**
	 * Returns the typed asset manifest associated with this plan.
	 *
	 * Missing or malformed asset_manifest data becomes an empty AssetManifest, giving
	 * callers a stable object for required, optional, and missing asset checks.
	 *
	 * @return AssetManifest Manifest wrapper for template asset requirements.
	 */
	public function assetManifest(): AssetManifest {
		return $this->assetManifestObject ??= AssetManifest::fromArray(
			is_array($this->payload['asset_manifest'] ?? null) ? $this->payload['asset_manifest'] : []
		);
	}

	/**
	 * Returns data paths referenced by the template.
	 *
	 * Paths describe nested values the template expects from its render data, such as
	 * customer.name or cart.items. The list is normalized from the data_paths key.
	 *
	 * @return array<int, string> Referenced render data paths.
	 */
	public function dataPaths(): array {
		return $this->list('data_paths');
	}

	/**
	 * Returns top-level render data keys referenced by the template.
	 *
	 * This is a coarse contract useful for controller integration and summaries
	 * when full nested data paths are too detailed.
	 *
	 * @return array<int, string> Top-level data keys.
	 */
	public function topLevelDataKeys(): array {
		return $this->list('top_level_data_keys');
	}

	/**
	 * Returns slot names declared or consumed by the template.
	 *
	 * Slots represent replaceable regions in layouts or components and are exposed as
	 * a normalized list from the slot_names payload key.
	 *
	 * @return array<int, string> Slot names discovered by analysis.
	 */
	public function slotNames(): array {
		return $this->list('slot_names');
	}

	/**
	 * Returns partial templates referenced by the analyzed template.
	 *
	 * The list is normalized from the partials payload key and may contain names,
	 * paths, or richer descriptors depending on analyzer output.
	 *
	 * @return array<int, mixed> Partial reference descriptors.
	 */
	public function partials(): array {
		return $this->list('partials');
	}

	/**
	 * Returns component references discovered in the template.
	 *
	 * Component entries identify reusable render units that the template depends on.
	 * The exact descriptor shape is preserved from analyzer output.
	 *
	 * @return array<int, mixed> Component reference descriptors.
	 */
	public function components(): array {
		return $this->list('components');
	}

	/**
	 * Returns import references discovered during planning.
	 *
	 * Imports can represent macros, helper templates, or external template resources
	 * depending on the templating backend.
	 *
	 * @return array<int, mixed> Import reference descriptors.
	 */
	public function imports(): array {
		return $this->list('imports');
	}

	/**
	 * Returns layout references associated with the template.
	 *
	 * Layout entries describe inherited or wrapping templates needed to render the
	 * root template completely.
	 *
	 * @return array<int, mixed> Layout reference descriptors.
	 */
	public function layouts(): array {
		return $this->list('layouts');
	}

	/**
	 * Returns assets referenced directly by the template plan.
	 *
	 * These are asset entries from the assets payload key; dependency assets may be
	 * exposed separately through dependencies() or assetManifest().
	 *
	 * @return array<int, mixed> Direct asset descriptors.
	 */
	public function assets(): array {
		return $this->list('assets');
	}

	/**
	 * Returns non-template dependencies captured by the planner.
	 *
	 * Dependencies may include scripts, styles, runtime helpers, packages, or other
	 * external resources needed by the rendered template.
	 *
	 * @return array<int, mixed> Dependency descriptors.
	 */
	public function dependencies(): array {
		return $this->list('dependencies');
	}

	/**
	 * Returns translation keys referenced by the template.
	 *
	 * The list supports localization audits for views that require specific
	 * language strings.
	 *
	 * @return array<int, mixed> Translation key or descriptor entries.
	 */
	public function translations(): array {
		return $this->list('translations');
	}

	/**
	 * Returns template tags detected during analysis.
	 *
	 * Tags describe language constructs used by the template and can drive feature
	 * compatibility or documentation summaries.
	 *
	 * @return array<int, mixed> Tag descriptors.
	 */
	public function tags(): array {
		return $this->list('tags');
	}

	/**
	 * Returns filters invoked by the template.
	 *
	 * Filter entries describe transformations applied in template expressions and
	 * help surface extension requirements.
	 *
	 * @return array<int, mixed> Filter descriptors.
	 */
	public function filters(): array {
		return $this->list('filters');
	}

	/**
	 * Returns helper calls referenced by the template.
	 *
	 * Helper entries identify runtime functions or services the template expects to
	 * be available during rendering.
	 *
	 * @return array<int, mixed> Helper descriptors.
	 */
	public function helpers(): array {
		return $this->list('helpers');
	}

	/**
	 * Returns templating extensions required by the plan.
	 *
	 * Extensions can describe custom syntax, filters, tags, or render hooks used by
	 * the template.
	 *
	 * @return array<int, mixed> Extension descriptors.
	 */
	public function extensions(): array {
		return $this->list('extensions');
	}

	/**
	 * Returns feature flags or capabilities detected in the template.
	 *
	 * Feature entries are analyzer-defined and are preserved so diagnostics can
	 * explain which rendering capabilities the template exercises.
	 *
	 * @return array<int, mixed> Feature descriptors.
	 */
	public function features(): array {
		return $this->list('features');
	}

	/**
	 * Returns the suggested render contract inferred from this plan.
	 *
	 * Missing or malformed suggested_contract data becomes an empty TemplateContract,
	 * allowing callers to inspect required data, slots, and assets through a stable
	 * value object.
	 *
	 * @return TemplateContract Inferred render contract for the template.
	 */
	public function suggestedContract(): TemplateContract {
		return $this->suggestedContractObject ??= TemplateContract::fromArray(
			is_array($this->payload['suggested_contract'] ?? null) ? $this->payload['suggested_contract'] : []
		);
	}

	/**
	 * Builds a compact summary of template planning counts and identity fields.
	 *
	 * Aggregate lists are preferred when present; otherwise the direct accessor lists
	 * are counted. The summary is intended for index views, diagnostics, and quick
	 * runtime inspection rather than lossless plan serialization.
	 *
	 * @return array<string, int|string|bool> Template identity and count summary.
	 */
	public function summary(): array {
		if($this->summaryPayload!==null){
			return $this->summaryPayload;
		}
		$aggregate=$this->aggregate();
		$allTemplates=$this->payload['all_templates'] ?? [];
		$unresolvedReferences=$this->payload['unresolved_references'] ?? [];
		$missingAssets=$this->assetManifest()->missing();
		return $this->summaryPayload=[
			'template_name'=>$this->templateName(),
			'inline'=>$this->isInline(),
			'cache_mode'=>$this->cacheMode(),
			'template_count'=>is_array($allTemplates) ? count($allTemplates) : 0,
			'data_path_count'=>count(is_array($aggregate['data_paths'] ?? null) ? $aggregate['data_paths'] : $this->dataPaths()),
			'slot_count'=>count(is_array($aggregate['slot_names'] ?? null) ? $aggregate['slot_names'] : $this->slotNames()),
			'partial_count'=>count(is_array($aggregate['partials'] ?? null) ? $aggregate['partials'] : $this->partials()),
			'component_count'=>count(is_array($aggregate['components'] ?? null) ? $aggregate['components'] : $this->components()),
			'import_count'=>count(is_array($aggregate['imports'] ?? null) ? $aggregate['imports'] : $this->imports()),
			'layout_count'=>count(is_array($aggregate['layouts'] ?? null) ? $aggregate['layouts'] : $this->layouts()),
			'asset_count'=>count(is_array($aggregate['assets'] ?? null) ? $aggregate['assets'] : $this->assets())+count(is_array($aggregate['dependencies'] ?? null) ? $aggregate['dependencies'] : $this->dependencies()),
			'unresolved_reference_count'=>is_array($unresolvedReferences) ? count($unresolvedReferences) : 0,
			'missing_asset_count'=>is_array($missingAssets) ? count($missingAssets) : 0,
		];
	}

	/**
	 * Returns the original template planning data.
	 *
	 * This preserves analyzer-specific fields that do not yet have first-class
	 * accessors on TemplatePlan.
	 *
	 * @return array<string,mixed> Analyzer data supplied at construction time.
	 */
	public function toArray(): array {
		return $this->payload;
	}

	/**
	 * Returns a list-valued analyzer section with malformed values normalized away.
	 *
	 * Accessors use this helper for analyzer-defined collections so callers get a
	 * stable array shape even when raw planning output is absent, scalar, or otherwise
	 * incomplete.
	 *
	 * @param string $key Payload key expected to contain a list or array.
	 * @return array<int|string,mixed> List payload, or an empty array when the key is not an array.
	 */
	private function list(string $key): array {
		$value=$this->payload[$key] ?? [];
		return is_array($value) ? $value : [];
	}
}
