<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Immutable per-call facade over TemplatingManager with state override support.
 *
 * TemplatingContext lets callers render, inspect, plan, load components, configure contracts,
 * build data bindings, and clear binding caches while carrying temporary state overrides such as
 * dev mode, strict mode, cache directory, asset policy, guardrails, and global context.
 */
final class TemplatingContext {

	/**
	 * Creates a context bound to a manager and an override bag.
	 *
	 * @param TemplatingManager $manager Manager that performs rendering, planning, binding, and cache work.
	 * @param array<string, mixed> $overrides Temporary state overrides applied by this context.
	 */
	public function __construct(
		private TemplatingManager $manager,
		private array $overrides=[]
	){}

	/**
	 * Resolves the effective templating state for this context.
	 *
	 * @return TemplatingState Manager state after applying context overrides.
	 */
	public function state(): TemplatingState {
		return $this->manager->state($this->overrides);
	}

	/**
	 * Returns a context with development mode overridden.
	 *
	 * @param bool $isDevMode Whether template compilation should behave as development mode.
	 * @return self Cloned context with override applied.
	 */
	public function withDevMode(bool $isDevMode): self {
		$clone=clone $this;
		$clone->overrides['is_dev_mode']=$isDevMode;
		return $clone;
	}

	/**
	 * Returns a context with a custom compiled-template cache directory.
	 *
	 * @param string $cacheDir Cache directory path.
	 * @return self Cloned context with override applied.
	 */
	public function withCacheDir(string $cacheDir): self {
		$clone=clone $this;
		$clone->overrides['cache_dir']=$cacheDir;
		return $clone;
	}

	/**
	 * Returns a context with strict template contract mode overridden.
	 *
	 * @param bool $strictMode Whether missing/invalid contract values should be treated strictly.
	 * @return self Cloned context with override applied.
	 */
	public function withStrictMode(bool $strictMode): self {
		$clone=clone $this;
		$clone->overrides['strict_mode']=$strictMode;
		return $clone;
	}

	/**
	 * Returns a context with an asset policy override.
	 *
	 * @param array|AssetPolicy $assetPolicy Asset collection, validation, and manifest policy.
	 * @return self Cloned context with override applied.
	 */
	public function withAssetPolicy(array|AssetPolicy $assetPolicy): self {
		$clone=clone $this;
		$clone->overrides['asset_policy']=$assetPolicy instanceof AssetPolicy ? $assetPolicy->toArray() : $assetPolicy;
		return $clone;
	}

	/**
	 * Returns a context with data-binding guardrail settings.
	 *
	 * @param array|bool $bindingGuardrails Guardrail toggle or detailed guardrail configuration.
	 * @return self Cloned context with override applied.
	 */
	public function withBindingGuardrails(array|bool $bindingGuardrails): self {
		$clone=clone $this;
		$clone->overrides['binding_guardrails']=$bindingGuardrails;
		return $clone;
	}

	/**
	 * Returns a context with additional global template variables.
	 *
	 * New globals replace existing override keys while preserving unrelated global context.
	 *
	 * @param array<string, mixed> $globals Global context values available to rendered templates.
	 * @return self Cloned context with merged globals.
	 */
	public function withGlobals(array $globals): self {
		$clone=clone $this;
		$clone->overrides['global_context']=array_replace($clone->overrides['global_context'] ?? [], $globals);
		return $clone;
	}

	/**
	 * Returns a context with one additional global template variable.
	 *
	 * @param string $key Global variable name.
	 * @param mixed $value Global variable value.
	 * @return self Cloned context with the global value merged.
	 */
	public function withGlobal(string $key, mixed $value): self {
		return $this->withGlobals([$key=>$value]);
	}

	/**
	 * Returns a context with an explicit contract for a template file.
	 *
	 * @param string $templateFile Template path used as the contract key.
	 * @param array|TemplateContract $contract Contract definition or value object.
	 * @return self Cloned context with the template contract override.
	 */
	public function withTemplateContract(string $templateFile, array|TemplateContract $contract): self {
		$clone=clone $this;
		$clone->overrides['template_contracts']=array_replace(
			$clone->overrides['template_contracts'] ?? [],
			[$templateFile=>$contract instanceof TemplateContract ? $contract->toArray() : $contract]
		);
		return $clone;
	}

	/**
	 * Returns a context with an explicit contract for a component reference.
	 *
	 * The component reference is resolved to its backing template before the contract is stored.
	 *
	 * @param string $reference Component reference.
	 * @param array|TemplateContract $contract Contract definition or value object.
	 * @return self Cloned context with the resolved component template contract.
	 *
	 * @throws \RuntimeException When the component reference cannot be resolved.
	 */
	public function withComponentContract(string $reference, array|TemplateContract $contract): self {
		$template=$this->manager->resolveComponentTemplate($reference);
		if($template===null){
			throw new \RuntimeException("Component not found: {$reference}");
		}
		return $this->withTemplateContract($template, $contract);
	}

	/**
	 * Creates a view object for a template file using this context's overrides.
	 *
	 * @param string $templateFile Template file path.
	 * @return TemplateView View configured with effective context state.
	 */
	public function template(string $templateFile): TemplateView {
		return $this->manager->template($templateFile, $this->overrides);
	}

	/**
	 * Creates a view object for a registered component reference.
	 *
	 * @param string $reference Component reference.
	 * @return TemplateView Component view configured with effective context state.
	 */
	public function component(string $reference): TemplateView {
		return $this->manager->component($reference, $this->overrides);
	}

	/**
	 * Creates a view object from inline template source.
	 *
	 * @param string $template Template source.
	 * @param string $templateName Synthetic template name used for plans, cache keys, and diagnostics.
	 * @return TemplateView Inline view configured with effective context state.
	 */
	public function source(string $template, string $templateName='inline.tpl'): TemplateView {
		return $this->manager->source($template, $templateName, $this->overrides);
	}

	/**
	 * Wraps a callable resolver as a template data binding.
	 *
	 * @param callable $resolver Resolver invoked by the binding pipeline.
	 * @param ?string $name Optional binding name for diagnostics and traces.
	 * @return CallableBinding Callable data binding.
	 */
	public function binding(callable $resolver, ?string $name=null): CallableBinding {
		return $this->manager->binding($resolver, $name);
	}

	/**
	 * Wraps a binding with identity-based request/render caching.
	 *
	 * @param DataBinding|callable $binding Binding or callable resolver to cache.
	 * @param string|array|callable $identity Cache identity value or resolver.
	 * @param ?string $name Optional binding name.
	 * @return CachedBinding Cached binding wrapper.
	 */
	public function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return $this->manager->cachedBinding($binding, $identity, $name);
	}

	/**
	 * Wraps a binding with persistent remembered caching.
	 *
	 * @param DataBinding|callable $binding Binding or callable resolver to remember.
	 * @param string|array|callable|null $identity Cache identity value or resolver.
	 * @param int $ttl Time-to-live in seconds.
	 * @param array|string $names Cache name tags used for invalidation.
	 * @param ?string $name Optional binding name.
	 * @return RememberedBinding Persistent cache binding wrapper.
	 */
	public function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return $this->manager->rememberBinding($binding, $identity, $ttl, $names, $name);
	}

	/**
	 * Resolves a binding only when a condition passes.
	 *
	 * @param DataBinding|callable $binding Binding or callable resolver.
	 * @param bool|callable $condition Static condition or condition resolver.
	 * @param mixed $default Value returned when the condition fails.
	 * @return ConditionalBinding Conditional binding wrapper.
	 */
	public function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return $this->manager->whenBinding($binding, $condition, $default);
	}

	/**
	 * Resolves a binding unless a condition passes.
	 *
	 * @param DataBinding|callable $binding Binding or callable resolver.
	 * @param bool|callable $condition Static condition or condition resolver.
	 * @param mixed $default Value returned when the condition passes.
	 * @return ConditionalBinding Conditional binding wrapper.
	 */
	public function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return $this->manager->unlessBinding($binding, $condition, $default);
	}

	/**
	 * Creates a SQL query binding for template data.
	 *
	 * @param object $query SQL query object resolved lazily when template data is materialized.
	 * @param string $mode Result mode such as records.
	 * @param array<string, mixed> $options Binding options forwarded to the query binding.
	 * @return SqlQueryBinding SQL query binding.
	 */
	public function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->manager->queryBinding($query, $mode, $options);
	}

	/**
	 * Creates a SQL query binding that inherits cache identity from its binding context.
	 *
	 * @param object $query SQL query object resolved lazily with inherited cache identity.
	 * @param string $mode Result mode such as records.
	 * @param array<string, mixed> $options Binding options forwarded to the query binding.
	 * @return SqlQueryBinding SQL query binding with inherited identity behavior.
	 */
	public function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->manager->queryBindingInheritingIdentity($query, $mode, $options);
	}

	/**
	 * Creates a search query binding for template data.
	 *
	 * @param object $query Search query object resolved lazily when template data is materialized.
	 * @param string $mode Result mode such as results.
	 * @param array<string, mixed> $options Binding options forwarded to the query binding.
	 * @return SearchQueryBinding Search query binding.
	 */
	public function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->manager->searchBinding($query, $mode, $options);
	}

	/**
	 * Creates a search query binding that inherits cache identity from its binding context.
	 *
	 * @param object $query Search query object resolved lazily with inherited cache identity.
	 * @param string $mode Result mode such as results.
	 * @param array<string, mixed> $options Binding options forwarded to the query binding.
	 * @return SearchQueryBinding Search query binding with inherited identity behavior.
	 */
	public function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->manager->searchBindingInheritingIdentity($query, $mode, $options);
	}

	/**
	 * Renders a template file with data, theme values, slots, and this context's overrides.
	 *
	 * @param string $templateFile Template file path.
	 * @param array<string, mixed> $data Template data.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @return RenderedTemplate Render result with content, diagnostics, and asset information.
	 */
	public function render(string $templateFile, array $data=[], array $themeValues=[], array $slots=[]): RenderedTemplate {
		return $this->manager->render($templateFile, $data, $themeValues, $slots, $this->overrides);
	}

	/**
	 * Builds a render plan for a template file without rendering output.
	 *
	 * @param string $templateFile Template file path.
	 * @return TemplatePlan Parsed template plan.
	 */
	public function plan(string $templateFile): TemplatePlan {
		return $this->manager->plan($templateFile, $this->overrides);
	}

	/**
	 * Builds the asset manifest declared by a template file.
	 *
	 * @param string $templateFile Template file path.
	 * @return AssetManifest Asset manifest for the template plan.
	 */
	public function assetManifest(string $templateFile): AssetManifest {
		return $this->manager->assetManifest($templateFile, $this->overrides);
	}

	/**
	 * Renders a template file in inspection mode.
	 *
	 * Inspection keeps render diagnostics useful for tooling while applying the same data, theme,
	 * slot, and context override inputs as render().
	 *
	 * @param string $templateFile Template file path.
	 * @param array<string, mixed> $data Template data.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @return RenderedTemplate Inspection render result.
	 */
	public function inspect(string $templateFile, array $data=[], array $themeValues=[], array $slots=[]): RenderedTemplate {
		return $this->manager->inspect($templateFile, $data, $themeValues, $slots, $this->overrides);
	}

	/**
	 * Renders inline template source.
	 *
	 * @param string $template Template source.
	 * @param array<string, mixed> $data Template data.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param string $templateName Synthetic template name for plans, cache keys, and diagnostics.
	 * @return RenderedTemplate Inline render result.
	 */
	public function renderString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl'
	): RenderedTemplate {
		return $this->manager->renderString($template, $data, $themeValues, $slots, $templateName, $this->overrides);
	}

	/**
	 * Renders inline template source in inspection mode.
	 *
	 * @param string $template Template source.
	 * @param array<string, mixed> $data Template data.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param string $templateName Synthetic template name for plans, cache keys, and diagnostics.
	 * @return RenderedTemplate Inline inspection result.
	 */
	public function inspectString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl'
	): RenderedTemplate {
		return $this->manager->inspectString($template, $data, $themeValues, $slots, $templateName, $this->overrides);
	}

	/**
	 * Builds a render plan for inline template source.
	 *
	 * @param string $template Template source.
	 * @param string $templateName Synthetic template name for diagnostics.
	 * @return TemplatePlan Parsed inline template plan.
	 */
	public function planString(string $template, string $templateName='inline.tpl'): TemplatePlan {
		return $this->manager->planString($template, $templateName, $this->overrides);
	}

	/**
	 * Builds the asset manifest declared by inline template source.
	 *
	 * @param string $template Template source.
	 * @param string $templateName Synthetic template name for diagnostics.
	 * @return AssetManifest Asset manifest for the inline template plan.
	 */
	public function assetManifestString(string $template, string $templateName='inline.tpl'): AssetManifest {
		return $this->manager->assetManifestString($template, $templateName, $this->overrides);
	}

	/**
	 * Dispatches an asynchronous render for a template file.
	 *
	 * @param string $templateFile Template file path.
	 * @param array<string, mixed> $data Template data.
	 * @return object Async render handle returned by the manager.
	 */
	public function asyncRender(string $templateFile, array $data=[]): object {
		return $this->manager->asyncRender($templateFile, $data, $this->overrides);
	}

	/**
	 * Clears remembered binding cache entries within this context's state overrides.
	 *
	 * @param string ...$names Optional binding cache name tags to clear.
	 * @return int Number of cache entries removed.
	 */
	public function clearBindingCache(string ...$names): int {
		return $this->manager->withStateOverrides(
			$this->overrides,
			fn(): int => $this->manager->clearBindingCache(...$names)
		);
	}
}
