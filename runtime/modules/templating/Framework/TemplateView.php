<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable render request for a file-backed or inline Dataphyre template.
 *
 * TemplateView accumulates data, lazy bindings, theme values, slots, contracts, asset policy, fallback rules, and render overrides before handing the request to TemplatingManager. Every fluent mutator returns a clone, so a configured view can be safely reused as a base for related render variants.
 */
final class TemplateView {

	/**
	 * Captures the render target and initial template context.
	 *
	 * File-backed views identify a template by name or path. Inline views carry source text and use templateName only as the diagnostic and policy key. Data and binding arrays provide template variables, theme values feed styling helpers, slots provide layout regions, overrides tune manager behavior, and fallbackTemplate is consulted only for non-inline views.
	 *
	 * @param TemplatingManager $manager Manager that owns planning, rendering, inspection, binding, and async execution.
	 * @param string $templateName Template file, logical template name, or inline diagnostic name.
	 * @param ?string $source Inline template source when inline is true.
	 * @param bool $inline True when source should be rendered directly instead of resolving a template file.
	 * @param array<string,mixed> $data Template variables and binding placeholders.
	 * @param array<string,mixed> $themeValues Theme variables supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @param array<string,mixed> $overrides Per-render manager overrides such as strict mode, contracts, and policy settings.
	 * @param ?string $fallbackTemplate Fallback template file for non-inline render and inspect paths.
	 */
	public function __construct(
		private TemplatingManager $manager,
		private string $templateName,
		private ?string $source=null,
		private bool $inline=false,
		private array $data=[],
		private array $themeValues=[],
		private array $slots=[],
		private array $overrides=[],
		private ?string $fallbackTemplate=null
	){}

	/**
	 * Replaces the complete template data bag for the cloned view.
	 *
	 * @param array<string,mixed> $data Template variables and binding placeholders.
	 * @return self Cloned view with the new data bag.
	 */
	public function withData(array $data): self {
		$clone=clone $this;
		$clone->data=$data;
		return $clone;
	}

	/**
	 * Replaces component props, using the same storage as template data.
	 *
	 * @param array<string,mixed> $props Component props exposed as template variables.
	 * @return self Cloned view with the new props/data bag.
	 */
	public function withProps(array $props): self {
		return $this->withData($props);
	}

	/**
	 * Returns a clone with additional top-level template data.
	 *
	 * Values replace existing keys with array_replace() semantics. Nested arrays are not deep-merged here; callers that need a nested binding should use withBinding() or withBindings().
	 *
	 * @param array<string,mixed> $data Top-level variables to overlay onto the current data bag.
	 * @return self Cloned view with merged data.
	 */
	public function mergeData(array $data): self {
		$clone=clone $this;
		$clone->data=array_replace($clone->data, $data);
		return $clone;
	}

	/**
	 * Returns a clone with additional top-level component props.
	 *
	 * Props are an alias for template data in this view layer, so this method delegates to mergeData() and keeps identical replacement semantics.
	 *
	 * @param array<string,mixed> $props Top-level props to overlay onto the current data bag.
	 * @return self Cloned view with merged props.
	 */
	public function mergeProps(array $props): self {
		return $this->mergeData($props);
	}

	/**
	 * Adds a lazy binding at a dotted template data path.
	 *
	 * @param string $path Dotted template data path receiving the binding.
	 * @param DataBinding|callable $binding Binding object or callable resolver.
	 * @return self Cloned view with the binding stored in the data bag.
	 */
	public function withBinding(string $path, DataBinding|callable $binding): self {
		$clone=clone $this;
		self::setArrayValueByPath($clone->data, $path, self::normalizeBinding($binding, $path));
		return $clone;
	}

	/**
	 * Adds multiple lazy bindings keyed by dotted template data path.
	 *
	 * Blank or non-string paths are ignored, preserving existing data for invalid entries.
	 *
	 * @param array<string,DataBinding|callable> $bindings Bindings keyed by template data path.
	 * @return self Cloned view with valid bindings stored in the data bag.
	 */
	public function withBindings(array $bindings): self {
		$clone=clone $this;
		foreach($bindings as $path=>$binding){
			if(!is_string($path) || trim($path)===''){
				continue;
			}
			self::setArrayValueByPath($clone->data, $path, self::normalizeBinding($binding, $path));
		}
		return $clone;
	}

	/**
	 * Adds a SQL query binding at a dotted template data path.
	 *
	 * @param string $path Dotted template data path receiving query results.
	 * @param object $query Query object consumed lazily during render.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @return self Cloned view with the query binding stored in the data bag.
	 */
	public function withQuery(string $path, object $query, string $mode='records', array $options=[]): self {
		return $this->withBinding($path, $this->manager->queryBinding($query, $mode, $options));
	}

	/**
	 * Adds a SQL query binding that inherits cache identity from the render context.
	 *
	 * @param string $path Dotted template data path receiving query results.
	 * @param object $query Query object consumed lazily during render.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @return self Cloned view with the identity-inheriting query binding.
	 */
	public function withQueryIdentity(string $path, object $query, string $mode='records', array $options=[]): self {
		return $this->withBinding($path, $this->manager->queryBindingInheritingIdentity($query, $mode, $options));
	}

	/**
	 * Adds a search query binding at a dotted template data path.
	 *
	 * @param string $path Dotted template data path receiving search results.
	 * @param object $query Search query object consumed lazily during render.
	 * @param string $mode Binding projection mode such as results, records, row, or count.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @return self Cloned view with the search binding stored in the data bag.
	 */
	public function withSearch(string $path, object $query, string $mode='results', array $options=[]): self {
		return $this->withBinding($path, $this->manager->searchBinding($query, $mode, $options));
	}

	/**
	 * Adds a search query binding that inherits cache identity from the render context.
	 *
	 * @param string $path Dotted template data path receiving search results.
	 * @param object $query Search query object consumed lazily during render.
	 * @param string $mode Binding projection mode such as results, records, row, or count.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @return self Cloned view with the identity-inheriting search binding.
	 */
	public function withSearchIdentity(string $path, object $query, string $mode='results', array $options=[]): self {
		return $this->withBinding($path, $this->manager->searchBindingInheritingIdentity($query, $mode, $options));
	}

	/**
	 * Adds a conditional binding that resolves only when the condition passes.
	 *
	 * @param string $path Dotted template data path receiving the binding result.
	 * @param DataBinding|callable $binding Binding object or callable resolver.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param mixed $default Value used when the condition fails.
	 * @return self Cloned view with the conditional binding stored in the data bag.
	 */
	public function withBindingWhen(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return $this->withBinding($path, $this->manager->whenBinding($binding, $condition, $default));
	}

	/**
	 * Adds a conditional binding that resolves unless the condition passes.
	 *
	 * @param string $path Dotted template data path receiving the binding result.
	 * @param DataBinding|callable $binding Binding object or callable resolver.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param mixed $default Value used when the condition passes.
	 * @return self Cloned view with the conditional binding stored in the data bag.
	 */
	public function withBindingUnless(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return $this->withBinding($path, $this->manager->unlessBinding($binding, $condition, $default));
	}

	/**
	 * Adds a SQL query binding that resolves only when the condition passes.
	 *
	 * @param string $path Dotted template data path receiving query results.
	 * @param object $query Query object consumed lazily during render.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @param mixed $default Value used when the condition fails.
	 * @return self Cloned view with the conditional query binding.
	 */
	public function withQueryWhen(
		string $path,
		object $query,
		bool|callable $condition,
		string $mode='records',
		array $options=[],
		mixed $default=null
	): self {
		return $this->withBinding(
			$path,
			$this->manager->whenBinding($this->manager->queryBinding($query, $mode, $options), $condition, $default)
		);
	}

	/**
	 * Adds a SQL query binding that resolves unless the condition passes.
	 *
	 * @param string $path Dotted template data path receiving query results.
	 * @param object $query Query object consumed lazily during render.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @param mixed $default Value used when the condition passes.
	 * @return self Cloned view with the conditional query binding.
	 */
	public function withQueryUnless(
		string $path,
		object $query,
		bool|callable $condition,
		string $mode='records',
		array $options=[],
		mixed $default=null
	): self {
		return $this->withBinding(
			$path,
			$this->manager->unlessBinding($this->manager->queryBinding($query, $mode, $options), $condition, $default)
		);
	}

	/**
	 * Adds a search query binding that resolves only when the condition passes.
	 *
	 * @param string $path Dotted template data path receiving search results.
	 * @param object $query Search query object consumed lazily during render.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param string $mode Binding projection mode such as results, records, row, or count.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @param mixed $default Value used when the condition fails.
	 * @return self Cloned view with the conditional search binding.
	 */
	public function withSearchWhen(
		string $path,
		object $query,
		bool|callable $condition,
		string $mode='results',
		array $options=[],
		mixed $default=null
	): self {
		return $this->withBinding(
			$path,
			$this->manager->whenBinding($this->manager->searchBinding($query, $mode, $options), $condition, $default)
		);
	}

	/**
	 * Adds a search query binding that resolves unless the condition passes.
	 *
	 * @param string $path Dotted template data path receiving search results.
	 * @param object $query Search query object consumed lazily during render.
	 * @param bool|callable $condition Static condition or resolver evaluated by the binding.
	 * @param string $mode Binding projection mode such as results, records, row, or count.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @param mixed $default Value used when the condition passes.
	 * @return self Cloned view with the conditional search binding.
	 */
	public function withSearchUnless(
		string $path,
		object $query,
		bool|callable $condition,
		string $mode='results',
		array $options=[],
		mixed $default=null
	): self {
		return $this->withBinding(
			$path,
			$this->manager->unlessBinding($this->manager->searchBinding($query, $mode, $options), $condition, $default)
		);
	}

	/**
	 * Replaces the complete theme value map for the cloned view.
	 *
	 * @param array<string,mixed> $themeValues Theme variables supplied to render helpers.
	 * @return self Cloned view with the new theme value map.
	 */
	public function withThemeValues(array $themeValues): self {
		$clone=clone $this;
		$clone->themeValues=$themeValues;
		return $clone;
	}

	/**
	 * Returns a clone with additional theme values.
	 *
	 * Theme values use shallow replacement so a caller can override specific token names for one render without mutating the base view.
	 *
	 * @param array<string,mixed> $themeValues Theme token values to overlay.
	 * @return self Cloned view with merged theme values.
	 */
	public function mergeThemeValues(array $themeValues): self {
		$clone=clone $this;
		$clone->themeValues=array_replace($clone->themeValues, $themeValues);
		return $clone;
	}

	/**
	 * Replaces the complete named slot map for the cloned view.
	 *
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @return self Cloned view with the new slot map.
	 */
	public function withSlots(array $slots): self {
		$clone=clone $this;
		$clone->slots=$slots;
		return $clone;
	}

	/**
	 * Returns a clone with one named slot set to string content.
	 *
	 * Slot values are cast to strings before rendering, making layout composition deterministic and preventing arbitrary objects from leaking into the manager slot map.
	 *
	 * @param string $slot Slot name expected by the template or layout.
	 * @param mixed $content Content rendered into the slot.
	 * @return self Cloned view with the slot value set.
	 */
	public function slot(string $slot, mixed $content): self {
		$clone=clone $this;
		$clone->slots[$slot]=(string)$content;
		return $clone;
	}

	/**
	 * Sets the fallback template used when a file-backed view target is missing.
	 *
	 * @param string $templateFile Fallback template file for render, plan, and inspect paths.
	 * @return self Cloned view with fallback resolution configured.
	 */
	public function withFallback(string $templateFile): self {
		$clone=clone $this;
		$clone->fallbackTemplate=$templateFile;
		return $clone;
	}

	/**
	 * Returns a clone with strict mode enabled or disabled for this render.
	 *
	 * Strict mode is stored as a render override and interpreted by TemplatingManager during planning, inspection, or rendering. It is useful for catching missing variables, contract drift, or unsafe template assumptions in diagnostics.
	 *
	 * @param bool $strictMode True to enable strict template handling for this view.
	 * @return self Cloned view with strict-mode override updated.
	 */
	public function strict(bool $strictMode=true): self {
		$clone=clone $this;
		$clone->overrides['strict_mode']=$strictMode;
		return $clone;
	}

	/**
	 * Adds a template contract override for this view's template name.
	 *
	 * @param array<string,mixed>|TemplateContract $contract Contract definition or immutable contract object.
	 * @return self Cloned view with a per-render contract override.
	 */
	public function withContract(array|TemplateContract $contract): self {
		$clone=clone $this;
		$clone->overrides['template_contracts']=array_replace(
			$clone->overrides['template_contracts'] ?? [],
			[$clone->templateName=>$contract instanceof TemplateContract ? $contract->toArray() : $contract]
		);
		return $clone;
	}

	/**
	 * Adds a component contract override for this view.
	 *
	 * Component views are already resolved to a concrete template by the manager,
	 * so this method stores the contract under the view's active template name.
	 *
	 * @param array<string,mixed>|TemplateContract $contract Contract definition or immutable contract object.
	 * @return self Cloned view with a per-render contract override.
	 */
	public function withComponentContract(array|TemplateContract $contract): self {
		return $this->withContract($contract);
	}

	/**
	 * Adds an asset policy override for this view's planning and render paths.
	 *
	 * @param array<string,mixed>|AssetPolicy $assetPolicy Asset collection, validation, and manifest policy.
	 * @return self Cloned view with a per-render asset policy override.
	 */
	public function withAssetPolicy(array|AssetPolicy $assetPolicy): self {
		$clone=clone $this;
		$clone->overrides['asset_policy']=$assetPolicy instanceof AssetPolicy ? $assetPolicy->toArray() : $assetPolicy;
		return $clone;
	}

	/**
	 * Adds binding guardrail overrides for this view.
	 *
	 * @param array<string,mixed>|bool $bindingGuardrails Guardrail toggle or detailed warning thresholds.
	 * @return self Cloned view with binding guardrail policy updated.
	 */
	public function withBindingGuardrails(array|bool $bindingGuardrails): self {
		$clone=clone $this;
		$clone->overrides['binding_guardrails']=$bindingGuardrails;
		return $clone;
	}

	/**
	 * Renders the configured file-backed or inline template view.
	 *
	 * Rendering delegates to the manager branch that matches the view type: inline source, file-backed template, or file-backed template with fallback. The data, theme values, slots, and overrides accumulated on the view are passed unchanged to preserve fluent builder semantics.
	 *
	 * @return RenderedTemplate Render result containing generated content and render metadata.
	 */
	public function render(): RenderedTemplate {
		if($this->fallbackTemplate!==null && $this->inline===false){
			return $this->manager->renderWithFallback(
				$this->templateName,
				$this->fallbackTemplate,
				$this->data,
				$this->themeValues,
				$this->slots,
				$this->overrides
			);
		}

		if($this->inline===true){
			return $this->manager->renderString(
				$this->source ?? '',
				$this->data,
				$this->themeValues,
				$this->slots,
				$this->templateName,
				$this->overrides
			);
		}

		return $this->manager->render(
			$this->templateName,
			$this->data,
			$this->themeValues,
			$this->slots,
			$this->overrides
		);
	}

	/**
	 * Builds the template execution plan without rendering content.
	 *
	 * Planning follows the same inline and fallback resolution rules as render(), but returns the manager's plan object so callers can inspect dependencies, assets, contracts, and policy decisions before execution.
	 *
	 * @return TemplatePlan Planned template metadata for the selected source.
	 */
	public function plan(): TemplatePlan {
		if($this->fallbackTemplate!==null && $this->inline===false){
			if(is_file($this->templateName) && is_readable($this->templateName)){
				return $this->manager->plan($this->templateName, $this->overrides);
			}
			return $this->manager->plan($this->fallbackTemplate, $this->overrides);
		}

		if($this->inline===true){
			return $this->manager->planString(
				$this->source ?? '',
				$this->templateName,
				$this->overrides
			);
		}

		return $this->manager->plan($this->templateName, $this->overrides);
	}

	/**
	 * Builds the asset manifest for this view's selected template plan.
	 *
	 * The manifest is derived from the template plan, so it reflects the same fallback, inline, override, and contract decisions that a render would use.
	 *
	 * @return AssetManifest Asset dependencies required by this view.
	 */
	public function assetManifest(): AssetManifest {
		return $this->plan()->assetManifest();
	}

	/**
	 * Renders the head asset HTML for this view's planned asset manifest.
	 *
	 *
	 * @return string HTML tags intended for the document head.
	 */
	public function headHtml(): string {
		return $this->assetManifest()->headHtml();
	}

	/**
	 * Renders the body asset HTML for this view's planned asset manifest.
	 *
	 *
	 * @return string HTML tags intended for the end of the document body.
	 */
	public function bodyHtml(): string {
		return $this->assetManifest()->bodyHtml();
	}

	/**
	 * Runs the manager inspection path for this view without normal render dispatch.
	 *
	 * Inspection mirrors fallback and inline resolution while preserving data, theme values, slots, and overrides. It is intended for diagnostics where callers need the rendered inspection payload rather than the usual production render path.
	 *
	 * @return RenderedTemplate Inspection result produced by the manager.
	 */
	public function inspect(): RenderedTemplate {
		if($this->fallbackTemplate!==null && $this->inline===false){
			if(is_file($this->templateName) && is_readable($this->templateName)){
				return $this->manager->inspect(
					$this->templateName,
					$this->data,
					$this->themeValues,
					$this->slots,
					$this->overrides
				);
			}
			return $this->manager->inspect(
				$this->fallbackTemplate,
				$this->data,
				$this->themeValues,
				$this->slots,
				$this->overrides
			);
		}

		if($this->inline===true){
			return $this->manager->inspectString(
				$this->source ?? '',
				$this->data,
				$this->themeValues,
				$this->slots,
				$this->templateName,
				$this->overrides
			);
		}

		return $this->manager->inspect(
			$this->templateName,
			$this->data,
			$this->themeValues,
			$this->slots,
			$this->overrides
		);
	}

	/**
	 * Renders the view and returns only the template content string.
	 *
	 *
	 * @return string Rendered template content.
	 */
	public function content(): string {
		return $this->render()->content();
	}

	/**
	 * Dispatches asynchronous rendering for this view.
	 *
	 * Async rendering delegates to the manager's async render branch. Inline views carry source, data, theme values, slots, template name, and overrides; file-backed views pass the template name, data, and overrides to the file async path.
	 *
	 * @return object Manager-specific async render handle or promise-like object.
	 */
	public function async(): object {
		if($this->inline===true){
			return $this->manager->asyncRenderString(
				$this->source ?? '',
				$this->data,
				$this->themeValues,
				$this->slots,
				$this->templateName,
				$this->overrides
			);
		}

		return $this->manager->asyncRender(
			$this->templateName,
			$this->data,
			$this->overrides
		);
	}

	/**
	 * Normalizes callable bindings into DataBinding objects for manager evaluation.
	 *
	 * Existing DataBinding instances are preserved. Raw callables are wrapped with the target path so diagnostics and guardrails can report which template variable produced the binding.
	 *
	 * @param DataBinding|callable $binding Binding object or callable binding factory.
	 * @param string $path Dotted template data path receiving the binding.
	 * @return DataBinding Binding object stored in the view data bag.
	 */
	private static function normalizeBinding(DataBinding|callable $binding, string $path): DataBinding {
		return $binding instanceof DataBinding ? $binding : CallableBinding::make($binding, $path);
	}

	/**
	 * Writes a value into a nested array using a dotted template data path.
	 *
	 * Empty path segments are ignored. Missing intermediate arrays are created, and scalar intermediates are replaced with arrays so binding paths always produce a usable template data tree.
	 *
	 * @param array<string,mixed> $target Data array being updated by reference.
	 * @param string $path Dotted path such as user.profile.name.
	 * @param mixed $value Value stored at the deepest path segment.
	 * @return void Target data is mutated in place.
	 */
	private static function setArrayValueByPath(array &$target, string $path, mixed $value): void {
		$segments=array_values(array_filter(array_map('trim', explode('.', $path)), static fn(string $segment): bool => $segment!==''));
		if($segments===[]){
			return;
		}
		$current=&$target;
		foreach($segments as $index=>$segment){
			$isLast=$index===count($segments)-1;
			if($isLast){
				$current[$segment]=$value;
				return;
			}
			if(!isset($current[$segment]) || !is_array($current[$segment])){
				$current[$segment]=[];
			}
			$current=&$current[$segment];
		}
	}
}
