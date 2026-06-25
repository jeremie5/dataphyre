<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Stateful coordinator for Dataphyre templating contexts, bindings, rendering, and diagnostics.
 *
 * The manager owns process-local globals, asset policy, strict mode, contracts, component mapping, binding cache identity, persistent binding cache files, trace stitching, and render/inspect/plan workflows.
 */
final class TemplatingManager {

	private const DEFAULT_BINDING_GUARDRAILS=[
		'enabled'=>true,
		'warn_slow'=>true,
		'slow_ms'=>50.0,
		'warn_unused'=>true,
		'warn_duplicate_targets'=>true,
	];

	private static ?self $instance=null;

	/**
	 * Returns the process-local templating manager used by all facade calls.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return self Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Clears the process-local templating manager so following calls rebuild state from configuration.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return void The in-memory state is cleared in place.
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param callable $resolver Binding resolver invoked when template data is materialized.
	 * @param ?string $name Optional binding name used in traces, cache keys, and diagnostics.
	 * @return CallableBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function binding(callable $resolver, ?string $name=null): CallableBinding {
		return CallableBinding::make($resolver, $name);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param DataBinding|callable $binding Existing binding object or resolver callback to wrap.
	 * @param string|array|callable $identity Cache identity value, array, or callback used to key binding results.
	 * @param ?string $name Optional binding name used in traces, cache keys, and diagnostics.
	 * @return CachedBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return CachedBinding::make($binding, $identity, $name);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param DataBinding|callable $binding Existing binding object or resolver callback to wrap.
	 * @param string|array|callable|null $identity Cache identity value, array, or callback used to key binding results.
	 * @param int $ttl Persistent cache lifetime in seconds.
	 * @param array|string $names Cache namespace names used for grouped invalidation.
	 * @param ?string $name Optional binding name used in traces, cache keys, and diagnostics.
	 * @return RememberedBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return RememberedBinding::make($binding, $identity, $ttl, $names, $name);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param DataBinding|callable $binding Existing binding object or resolver callback to wrap.
	 * @param bool|callable $condition Boolean or callback deciding whether the binding should resolve.
	 * @param mixed $default Value returned when the condition prevents binding resolution.
	 * @return ConditionalBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return ConditionalBinding::when($binding, $condition, $default);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param DataBinding|callable $binding Existing binding object or resolver callback to wrap.
	 * @param bool|callable $condition Boolean or callback deciding whether the binding should resolve.
	 * @param mixed $default Value returned when the condition prevents binding resolution.
	 * @return ConditionalBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return ConditionalBinding::unless($binding, $condition, $default);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string, mixed> $options Query binding options such as hydration mode flags, identity hints, or execution metadata.
	 * @return SqlQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return SqlQueryBinding::make($query, $mode, $options);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string, mixed> $options Query binding options such as hydration mode flags, identity hints, or execution metadata.
	 * @return SqlQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->queryBinding($query, $mode, $options)->inheritIdentity();
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string, mixed> $options Query binding options such as hydration mode flags, identity hints, or execution metadata.
	 * @return SearchQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return SearchQueryBinding::make($query, $mode, $options);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string, mixed> $options Query binding options such as hydration mode flags, identity hints, or execution metadata.
	 * @return SearchQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->searchBinding($query, $mode, $options)->inheritIdentity();
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplatingState Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function state(array $overrides=[]): TemplatingState {
		return TemplatingState::fromArray(array_replace(
			\dataphyre\templating::state(),
			$this->filterStateOverrides($overrides)
		));
	}

	/**
	 * Builds a templating context with optional dev mode, cache, global data, strictness, and asset policy overrides.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param ?bool $isDevMode Is dev mode.
	 * @param ?string $cacheDir Cache dir.
	 * @param ?array $globalContext Global context.
	 * @param ?bool $strictMode Whether unresolved data and contract mismatches should fail strictly.
	 * @param array|AssetPolicy|null $assetPolicy Asset loading policy definition or immutable policy object.
	 * @return TemplatingContext Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function context(
		?bool $isDevMode=null,
		?string $cacheDir=null,
		?array $globalContext=null,
		?bool $strictMode=null,
		array|AssetPolicy|null $assetPolicy=null
	): TemplatingContext {
		return new TemplatingContext($this, array_filter([
			'is_dev_mode'=>$isDevMode,
			'cache_dir'=>$cacheDir,
			'global_context'=>$globalContext,
			'strict_mode'=>$strictMode,
			'asset_policy'=>$assetPolicy instanceof AssetPolicy ? $assetPolicy->toArray() : $assetPolicy,
		], static fn(mixed $value): bool => $value!==null));
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function template(string $templateFile, array $overrides=[]): TemplateView {
		return new TemplateView($this, $templateFile, null, false, [], [], [], $overrides);
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function component(string $reference, array $overrides=[]): TemplateView {
		$template=$this->resolveComponentTemplate($reference);
		if($template===null){
			throw new \RuntimeException("Component not found: {$reference}");
		}
		return new TemplateView($this, $template, null, false, [], [], [], $overrides);
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function source(string $template, string $templateName='inline.tpl', array $overrides=[]): TemplateView {
		return new TemplateView($this, $templateName, $template, true, [], [], [], $overrides);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function render(
		string $templateFile,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($templateFile, $data, $themeValues, $slots, $overrides): array {
			$prepared=$this->prepareBindingData($templateFile, false, $data, $themeValues, $slots, $overrides);
			$plan=\dataphyre\templating::plan($templateFile);
			$bindingPlanner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'content'=>(string)\dataphyre\templating::render($templateFile, $prepared['data'], $themeValues, $slots),
				'asset_manifest'=>\dataphyre\templating::asset_manifest($templateFile),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$bindingPlanner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			$templateFile,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$themeValues,
			$slots,
			false,
			is_string($result['render_trace_id'] ?? null) ? $result['render_trace_id'] : null,
			null,
			AssetManifest::fromArray($result['asset_manifest'] ?? []),
			is_array($result['bindings'] ?? null) ? $result['bindings'] : [],
			is_array($result['binding_warnings'] ?? null) ? $result['binding_warnings'] : [],
			is_array($result['binding_planner'] ?? null) ? $result['binding_planner'] : []
		);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplatePlan Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function plan(string $templateFile, array $overrides=[]): TemplatePlan {
		$plan=$this->withStateOverrides($overrides, static function() use($templateFile): array {
			return \dataphyre\templating::plan($templateFile);
		});
		return TemplatePlan::fromArray($plan);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return AssetManifest Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function assetManifest(string $templateFile, array $overrides=[]): AssetManifest {
		$manifest=$this->withStateOverrides($overrides, static function() use($templateFile): array {
			return \dataphyre\templating::asset_manifest($templateFile);
		});
		return AssetManifest::fromArray($manifest);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function inspect(
		string $templateFile,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($templateFile, $data, $themeValues, $slots, $overrides): array {
			$prepared=$this->prepareBindingData($templateFile, false, $data, $themeValues, $slots, $overrides);
			$plan=\dataphyre\templating::plan($templateFile);
			$inspection=\dataphyre\templating::inspect($templateFile, $prepared['data'], $themeValues, $slots);
			$bindingWarnings=$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides);
			$bindingPlanner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			if(is_array($inspection['manifest'] ?? null)){
				$inspection['manifest']=$this->mergeBindingManifest($inspection['manifest'], $prepared['bindings'], $bindingWarnings, $bindingPlanner, $prepared['render_trace_id']);
			}
			return [
				'inspection'=>$inspection,
				'asset_manifest'=>\dataphyre\templating::asset_manifest($templateFile),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$bindingWarnings,
				'binding_planner'=>$bindingPlanner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		$inspection=is_array($result['inspection'] ?? null) ? $result['inspection'] : [];
		return new RenderedTemplate(
			(string)($inspection['content'] ?? ''),
			$templateFile,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$themeValues,
			$slots,
			false,
			is_string($result['render_trace_id'] ?? null) ? $result['render_trace_id'] : null,
			TemplateManifest::fromArray($inspection['manifest'] ?? []),
			AssetManifest::fromArray($result['asset_manifest'] ?? []),
			is_array($result['bindings'] ?? null) ? $result['bindings'] : [],
			is_array($result['binding_warnings'] ?? null) ? $result['binding_warnings'] : [],
			is_array($result['binding_planner'] ?? null) ? $result['binding_planner'] : []
		);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function renderString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl',
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template, $data, $themeValues, $slots, $templateName, $overrides): array {
			$prepared=$this->prepareBindingData($templateName, true, $data, $themeValues, $slots, $overrides);
			$plan=\dataphyre\templating::plan_string($template, $templateName);
			$bindingPlanner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'content'=>\dataphyre\templating::render_string($template, $prepared['data'], $themeValues, $slots, $templateName),
				'asset_manifest'=>\dataphyre\templating::asset_manifest_string($template, $templateName),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$bindingPlanner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			$templateName,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$themeValues,
			$slots,
			true,
			is_string($result['render_trace_id'] ?? null) ? $result['render_trace_id'] : null,
			null,
			AssetManifest::fromArray($result['asset_manifest'] ?? []),
			is_array($result['bindings'] ?? null) ? $result['bindings'] : [],
			is_array($result['binding_warnings'] ?? null) ? $result['binding_warnings'] : [],
			is_array($result['binding_planner'] ?? null) ? $result['binding_planner'] : []
		);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function inspectString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl',
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template, $data, $themeValues, $slots, $templateName, $overrides): array {
			$prepared=$this->prepareBindingData($templateName, true, $data, $themeValues, $slots, $overrides);
			$plan=\dataphyre\templating::plan_string($template, $templateName);
			$inspection=\dataphyre\templating::inspect_string($template, $prepared['data'], $themeValues, $slots, $templateName);
			$bindingWarnings=$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides);
			$bindingPlanner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			if(is_array($inspection['manifest'] ?? null)){
				$inspection['manifest']=$this->mergeBindingManifest($inspection['manifest'], $prepared['bindings'], $bindingWarnings, $bindingPlanner, $prepared['render_trace_id']);
			}
			return [
				'inspection'=>$inspection,
				'asset_manifest'=>\dataphyre\templating::asset_manifest_string($template, $templateName),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$bindingWarnings,
				'binding_planner'=>$bindingPlanner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		$inspection=is_array($result['inspection'] ?? null) ? $result['inspection'] : [];
		return new RenderedTemplate(
			(string)($inspection['content'] ?? ''),
			$templateName,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$themeValues,
			$slots,
			true,
			is_string($result['render_trace_id'] ?? null) ? $result['render_trace_id'] : null,
			TemplateManifest::fromArray($inspection['manifest'] ?? []),
			AssetManifest::fromArray($result['asset_manifest'] ?? []),
			is_array($result['bindings'] ?? null) ? $result['bindings'] : [],
			is_array($result['binding_warnings'] ?? null) ? $result['binding_warnings'] : [],
			is_array($result['binding_planner'] ?? null) ? $result['binding_planner'] : []
		);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return TemplatePlan Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function planString(string $template, string $templateName='inline.tpl', array $overrides=[]): TemplatePlan {
		$plan=$this->withStateOverrides($overrides, static function() use($template, $templateName): array {
			return \dataphyre\templating::plan_string($template, $templateName);
		});
		return TemplatePlan::fromArray($plan);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return AssetManifest Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function assetManifestString(string $template, string $templateName='inline.tpl', array $overrides=[]): AssetManifest {
		$manifest=$this->withStateOverrides($overrides, static function() use($template, $templateName): array {
			return \dataphyre\templating::asset_manifest_string($template, $templateName);
		});
		return AssetManifest::fromArray($manifest);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param string $fallbackTemplate Fallback template.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function renderWithFallback(
		string $templateFile,
		string $fallbackTemplate,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($templateFile, $fallbackTemplate, $data, $themeValues, $slots, $overrides): array {
			$selectedTemplate=(is_file($templateFile) && is_readable($templateFile)) ? $templateFile : $fallbackTemplate;
			$prepared=$this->prepareBindingData($selectedTemplate, false, $data, $themeValues, $slots, $overrides);
			$plan=\dataphyre\templating::plan($selectedTemplate);
			$bindingPlanner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'selected_template'=>$selectedTemplate,
				'content'=>\dataphyre\templating::render($selectedTemplate, $prepared['data'], $themeValues, $slots),
				'asset_manifest'=>\dataphyre\templating::asset_manifest($selectedTemplate),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$bindingPlanner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			(string)($result['selected_template'] ?? $templateFile),
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$themeValues,
			$slots,
			false,
			is_string($result['render_trace_id'] ?? null) ? $result['render_trace_id'] : null,
			null,
			AssetManifest::fromArray($result['asset_manifest'] ?? []),
			is_array($result['bindings'] ?? null) ? $result['bindings'] : [],
			is_array($result['binding_warnings'] ?? null) ? $result['binding_warnings'] : [],
			is_array($result['binding_planner'] ?? null) ? $result['binding_planner'] : []
		);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return object Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function asyncRender(string $templateFile, array $data=[], array $overrides=[]): object {
		return $this->withStateOverrides($overrides, function() use($templateFile, $data, $overrides): object {
			$prepared=$this->prepareBindingData($templateFile, false, $data, [], [], $overrides);
			return \dataphyre\templating::async_render($templateFile, $prepared['data']);
		});
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param array<string, mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string, mixed> $themeValues Theme value map.
	 * @param array<string, mixed> $slots Named slot content.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @return object Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function asyncRenderString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl',
		array $overrides=[]
	): object {
		return $this->withStateOverrides($overrides, function() use($template, $data, $themeValues, $slots, $templateName, $overrides): object {
			$prepared=$this->prepareBindingData($templateName, true, $data, $themeValues, $slots, $overrides);
			return new \dataphyre\async\promise(static function($resolve, $reject) use($template, $prepared, $themeValues, $slots, $templateName): void {
				try{
					$resolve(json_encode([
						'content'=>\dataphyre\templating::render_string($template, $prepared['data'], $themeValues, $slots, $templateName),
					]));
				}catch(\Throwable $e){
					$reject(json_encode(['error'=>$e->getMessage()]));
				}
			});
		});
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $tag Template tag name handled by the callback.
	 * @param callable $callback Parser/render callback registered with the templating kernel.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerTag(string $tag, callable $callback): void {
		\dataphyre\templating::register_tag($tag, $callback);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $filter Template filter name handled by the callback.
	 * @param callable $callback Filter callback registered with the templating kernel.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerFilter(string $filter, callable $callback): void {
		\dataphyre\templating::register_filter($filter, $callback);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $name Extension or helper name registered in the templating kernel.
	 * @param callable $extension Extension callback registered with the templating kernel.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerExtension(string $name, callable $extension): void {
		\dataphyre\templating::register_extension($name, $extension);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $name Extension or helper name registered in the templating kernel.
	 * @param callable $helper Helper callback registered with the templating kernel.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerHelper(string $name, callable $helper): void {
		\dataphyre\templating::register_helper($name, $helper);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $event Templating event name emitted by the render pipeline.
	 * @param callable $callback Event callback invoked by the templating render pipeline.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerEventHook(string $event, callable $callback): void {
		\dataphyre\templating::register_event_hook($event, $callback);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param callable $hook Pre- or post-processing hook invoked during template rendering.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerPreprocessingHook(callable $hook): void {
		\dataphyre\templating::register_preprocessing_hook($hook);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param callable $hook Pre- or post-processing hook invoked during template rendering.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerPostprocessingHook(callable $hook): void {
		\dataphyre\templating::register_postprocessing_hook($hook);
	}

	/**
	 * Adds a value to the process-wide template context for future renders.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $key Global context key or dot-path accepted by the templating runtime.
	 * @param mixed $value Value made available to templates until the global context is cleared.
	 * @return void The manager mutates shared render state and returns no payload.
	 */
	public function addGlobal(string $key, mixed $value): void {
		\dataphyre\templating::add_to_global_context($key, $value);
	}

	/**
	 * Returns the current process-wide template context snapshot.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return array<string, mixed> Global values that will be merged into new render contexts.
	 */
	public function globals(): array {
		return \dataphyre\templating::global_context();
	}

	/**
	 * Clears all process-wide template context values.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return void Future renders start without previously registered global values.
	 */
	public function clearGlobals(): void {
		\dataphyre\templating::clear_global_context();
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string ...$names Cache namespace names used for grouped invalidation.
	 * @return int Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function clearBindingCache(string ...$names): int {
		$cacheDir=$this->bindingPersistentCacheRoot();
		$itemsDir=$cacheDir.'items'.DIRECTORY_SEPARATOR;
		$namesDir=$cacheDir.'names'.DIRECTORY_SEPARATOR;
		if($names===[]){
			return $this->clearPersistentBindingCacheDirectories($itemsDir, $namesDir);
		}

		$deleted=0;
		foreach($this->normalizeBindingCacheNames($names) as $name){
			$nameFile=$this->bindingPersistentCacheNameFile($name, $namesDir);
			if(!is_file($nameFile)){
				continue;
			}
			$payload=@file_get_contents($nameFile);
			$keys=json_decode(is_string($payload) ? $payload : '[]', true);
			if(is_array($keys)){
				foreach($keys as $key){
					if(!is_string($key) || $key===''){
						continue;
					}
					$itemFile=$itemsDir.$key.'.cache';
					if(is_file($itemFile) && @unlink($itemFile)){
						$deleted++;
					}
				}
			}
			@unlink($nameFile);
		}
		return $deleted;
	}

	/**
	 * Reads or replaces the default asset policy used by render and planning operations.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return AssetPolicy Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function assetPolicy(): AssetPolicy {
		return AssetPolicy::fromArray(\dataphyre\templating::asset_policy());
	}

	/**
	 * Reads or replaces the default asset policy used by render and planning operations.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param array|AssetPolicy $assetPolicy Asset loading policy definition or immutable policy object.
	 * @return void The manager option is replaced for subsequent operations.
	 */
	public function setAssetPolicy(array|AssetPolicy $assetPolicy): void {
		\dataphyre\templating::set_asset_policy(
			$assetPolicy instanceof AssetPolicy ? $assetPolicy->toArray() : $assetPolicy
		);
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param bool $strictMode Whether unresolved data and contract mismatches should fail strictly.
	 * @return void The manager option is replaced for subsequent operations.
	 */
	public function setStrictMode(bool $strictMode): void {
		\dataphyre\templating::set_strict_mode($strictMode);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array|TemplateContract $contract Template contract definition describing expected data and slots.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerContract(string $templateFile, array|TemplateContract $contract): void {
		\dataphyre\templating::register_template_contract(
			$templateFile,
			$contract instanceof TemplateContract ? $contract->toArray() : $contract
		);
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return ?string Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function resolveComponentTemplate(string $reference): ?string {
		return \dataphyre\templating::resolve_component_template($reference);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @param array|TemplateContract $contract Template contract definition describing expected data and slots.
	 * @return void Registration mutates the process-local manager or registry and returns no value.
	 */
	public function registerComponentContract(string $reference, array|TemplateContract $contract): void {
		\dataphyre\templating::register_component_contract(
			$reference,
			$contract instanceof TemplateContract ? $contract->toArray() : $contract
		);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @return ?TemplateContract Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function contract(string $templateFile): ?TemplateContract {
		$contract=\dataphyre\templating::template_contract($templateFile);
		return is_array($contract) ? TemplateContract::fromArray($contract) : null;
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return ?TemplateContract Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function componentContract(string $reference): ?TemplateContract {
		$contract=\dataphyre\templating::component_contract($reference);
		return is_array($contract) ? TemplateContract::fromArray($contract) : null;
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param ?string $templateFile Template file path resolved by the templating kernel.
	 * @return void The in-memory state is cleared in place.
	 */
	public function clearContract(?string $templateFile=null): void {
		\dataphyre\templating::clear_template_contract($templateFile);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return void The in-memory state is cleared in place.
	 */
	public function clearComponentContract(string $reference): void {
		\dataphyre\templating::clear_component_contract($reference);
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param array<string, mixed> $overrides Runtime state overrides merged with module defaults for this operation.
	 * @param callable $callback Event callback invoked by the templating render pipeline.
	 * @return mixed Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public function withStateOverrides(array $overrides, callable $callback): mixed {
		$overrides=$this->filterStateOverrides($overrides);
		if($overrides===[]){
			return $callback();
		}

		$originalState=\dataphyre\templating::state();
		if(isset($overrides['template_contracts']) && is_array($overrides['template_contracts'])){
			$overrides['template_contracts']=array_replace(
				is_array($originalState['template_contracts'] ?? null) ? $originalState['template_contracts'] : [],
				$overrides['template_contracts']
			);
		}
		\dataphyre\templating::apply_state($overrides);
		try{
			return $callback();
		} finally {
			\dataphyre\templating::apply_state($originalState);
		}
	}

	/**
	 * Normalizes temporary state overrides accepted by facade-level operations.
	 *
	 * Unknown keys are dropped so render calls cannot leak arbitrary values into
	 * the global templating state snapshot restored by withStateOverrides().
	 *
	 * @param array<string, mixed> $overrides Candidate state values supplied by render, inspect, or manager callers.
	 * @return array<string, mixed> Whitelisted overrides converted to the shapes expected by the kernel.
	 */
	private function filterStateOverrides(array $overrides): array {
		$filtered=[];
		foreach($overrides as $key=>$value){
			switch($key){
				case 'is_dev_mode':
					$filtered[$key]=(bool)$value;
					break;
				case 'cache_dir':
					if(is_string($value) && trim($value)!==''){
						$filtered[$key]=trim($value);
					}
					break;
				case 'global_context':
					if(is_array($value)){
						$filtered[$key]=$value;
					}
					break;
				case 'strict_mode':
					$filtered[$key]=(bool)$value;
					break;
				case 'asset_policy':
					if($value instanceof AssetPolicy){
						$filtered[$key]=$value->toArray();
					}
					elseif(is_array($value)){
						$filtered[$key]=$value;
					}
					break;
				case 'template_contracts':
					if(is_array($value)){
						$filtered[$key]=array_map(
							static fn(mixed $contract): array => $contract instanceof TemplateContract ? $contract->toArray() : (is_array($contract) ? $contract : []),
							$value
						);
					}
					break;
				case 'binding_guardrails':
					if(is_bool($value) || is_array($value)){
						$filtered[$key]=$value;
					}
					break;
			}
		}
		return $filtered;
	}

	/**
	 * Resolves DataBinding instances before a render or inspection pass.
	 *
	 * The returned binding list is also the source for render manifests, warnings,
	 * planner suggestions, trace payloads, and cache diagnostics.
	 *
	 * @param string $templateName Logical template name used for binding context and trace correlation.
	 * @param bool $inline Whether the source came from an inline template string.
	 * @param array<string, mixed> $data User data containing scalar values or DataBinding instances.
	 * @param array<string, mixed> $themeValues Theme values available to binding resolvers.
	 * @param array<string, mixed> $slots Slot data available to binding resolvers.
	 * @param array<string, mixed> $overrides Runtime state overrides active for this pass.
	 * @return array{data: array<string, mixed>, bindings: array<int, array<string, mixed>>, render_trace_id: ?string}
	 */
	private function prepareBindingData(
		string $templateName,
		bool $inline,
		array $data,
		array $themeValues=[],
		array $slots=[],
		array $overrides=[]
	): array {
		$tracingEnabled=$this->tracingEnabled()===true;
		$renderTraceId=$tracingEnabled ? $this->newTraceId('tpl') : null;
		$context=new BindingContext(
			$templateName,
			$inline,
			$data,
			$themeValues,
			$slots,
			$this->filterStateOverrides($overrides),
			$tracingEnabled && $renderTraceId!==null ? ['render_trace_id'=>$renderTraceId] : []
		);
		$bindings=[];
		$bindingCache=[];
		$traceState=[
			'enabled'=>$tracingEnabled,
			'render_trace_id'=>$renderTraceId,
			'sequence'=>0,
		];
		$resolved=$this->resolveBindingValue($data, '', $context, $bindings, $bindingCache, $traceState);
		return [
			'data'=>is_array($resolved) ? $resolved : $data,
			'bindings'=>$bindings,
			'render_trace_id'=>$renderTraceId,
		];
	}

	/**
	 * Recursively resolves binding values while recording cache, trace, and error metadata.
	 *
	 * Binding failures are captured as manifest entries and resolve to null so a
	 * single failed binding does not abort the entire render path.
	 *
	 * @param mixed $value Scalar, array, or DataBinding value to resolve.
	 * @param string $path Dot-path for the current data node.
	 * @param BindingContext $context Render context passed to binding resolvers.
	 * @param array<int, array<string, mixed>> $bindings Collected binding manifest entries.
	 * @param array<string, array{value: mixed}> $bindingCache Per-render cache keyed by normalized binding identity.
	 * @param array<string, mixed> $traceState Mutable render trace sequence state.
	 * @return mixed scalar, array, or null value after nested bindings, render cache hits, persistent cache reads, and captured failures are applied.
	 */
	private function resolveBindingValue(mixed $value, string $path, BindingContext $context, array &$bindings, array &$bindingCache, array &$traceState): mixed {
		if($value instanceof DataBinding){
			$tracingEnabled=($traceState['enabled'] ?? $this->tracingEnabled())===true;
			$started=microtime(true);
			$metadata=$value instanceof BindingMetadataProvider ? $value->metadata() : [];
			$renderCache=$this->bindingCacheDescriptor($value, $context);
			$persistentCache=$this->bindingPersistentCacheDescriptor($value, $context, $renderCache);
			$bindingTraceId=$tracingEnabled ? $this->nextBindingTraceId($traceState) : null;
			$bindingContext=$tracingEnabled
				? $context->withTraceContext([
					'binding_trace_id'=>$bindingTraceId,
					'binding_path'=>$path,
					'binding_name'=>$value->name(),
				])
				: $context;
			if(($renderCache['cacheable'] ?? false)===true && array_key_exists((string)$renderCache['cache_key'], $bindingCache)){
				$resolved=$bindingCache[(string)$renderCache['cache_key']]['value'] ?? null;
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$bindingContext->renderTraceId(),
					'binding_trace_id'=>$bindingContext->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>false,
					'reused'=>true,
					'result_type'=>get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($renderCache, $persistentCache, 'render', 'hit', [
					'cache_layer'=>'render',
				])));
				return $resolved;
			}
			$persistentHit=$this->loadPersistentBindingValue($persistentCache, $context);
			if(($persistentHit['hit'] ?? false)===true){
				$resolved=$persistentHit['value'] ?? null;
				if(($renderCache['cacheable'] ?? false)===true){
					$bindingCache[(string)$renderCache['cache_key']]=[
						'value'=>$resolved,
					];
				}
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$bindingContext->renderTraceId(),
					'binding_trace_id'=>$bindingContext->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>false,
					'reused'=>false,
					'result_type'=>get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($renderCache, $persistentCache, 'persistent', 'hit', [
					'cache_layer'=>'persistent',
				])));
				return $resolved;
			}
			try{
				$resolved=$this->resolveBindingWithTraceContext($value, $bindingContext, $metadata);
				$skipped=false;
				if($resolved instanceof BindingResolution){
					$skipped=$resolved->isSkipped();
					$resolved=$resolved->result();
				}
				if($skipped!==true){
					$resolved=$this->resolveBindingValue($resolved, $path, $bindingContext, $bindings, $bindingCache, $traceState);
				}
				if(($renderCache['cacheable'] ?? false)===true && $skipped!==true){
					$bindingCache[(string)$renderCache['cache_key']]=[
						'value'=>$resolved,
					];
				}
				$cacheScope='none';
				$cacheState='bypass';
				$cacheLayer='none';
				$cacheStoreError=null;
				if($skipped!==true && ($persistentCache['cacheable'] ?? false)===true){
					$cacheStoreError=$this->storePersistentBindingValue($persistentCache, $context, $resolved);
					if($cacheStoreError===null){
						$cacheScope='persistent';
						$cacheState='store';
						$cacheLayer='persistent';
					}
					elseif(($renderCache['cacheable'] ?? false)===true){
						$cacheScope='render';
						$cacheState='miss';
						$cacheLayer='render';
					}
				}
				elseif(($renderCache['cacheable'] ?? false)===true && $skipped!==true){
					$cacheScope='render';
					$cacheState='miss';
					$cacheLayer='render';
				}
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$bindingContext->renderTraceId(),
					'binding_trace_id'=>$bindingContext->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>$skipped,
					'reused'=>false,
					'result_type'=>$skipped
						? ($resolved===null ? 'skipped' : 'skipped('.get_debug_type($resolved).')')
						: get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($renderCache, $persistentCache, $cacheScope, $cacheState, [
					'cache_layer'=>$cacheLayer,
					'cache_store_error'=>$cacheStoreError,
				])));
				if($skipped===true){
					$bindings[array_key_last($bindings)]['cache_state']='bypass';
					$bindings[array_key_last($bindings)]['cache_scope']='none';
					$bindings[array_key_last($bindings)]['cache_layer']='none';
					$bindings[array_key_last($bindings)]=$this->stitchBindingTrace($bindings[array_key_last($bindings)]);
				}
				return $resolved;
			}catch(\Throwable $e){
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$bindingContext->renderTraceId(),
					'binding_trace_id'=>$bindingContext->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>false,
					'reused'=>false,
					'result_type'=>'error',
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
					'error'=>[
						'type'=>$e::class,
						'message'=>$e->getMessage(),
					],
				], $metadata, $this->bindingCacheMetadata($renderCache, $persistentCache, 'none', 'bypass', [
					'cache_layer'=>'none',
				])));
				return null;
			}
		}

		if(is_array($value)){
			$resolved=[];
			foreach($value as $key=>$item){
				$itemPath=$this->bindingPath($path, $key);
				$resolved[$key]=$this->resolveBindingValue($item, $itemPath, $context, $bindings, $bindingCache, $traceState);
			}
			return $resolved;
		}

		return $value;
	}

	/**
	 * Appends a segment to a binding data path.
	 *
	 * @param string $parent Existing dot-path or an empty root path.
	 * @param string|int $segment Array key or list index being traversed.
	 * @return string Dot-path used in binding manifests and guardrail checks.
	 */
	private function bindingPath(string $parent, string|int $segment): string {
		$segment=(string)$segment;
		return $parent==='' ? $segment : $parent.'.'.$segment;
	}

	/**
	 * Injects binding diagnostics into a template asset or inspection manifest.
	 *
	 * @param array<string, mixed> $manifest Existing manifest produced by the templating kernel.
	 * @param array<int, array<string, mixed>> $bindings Binding entries captured during data preparation.
	 * @param array<int, array<string, mixed>> $bindingWarnings Guardrail warnings associated with this plan.
	 * @param array<int, array<string, mixed>> $bindingPlanner Optimization suggestions for query-backed bindings.
	 * @param ?string $renderTraceId Trace id assigned to the render pass.
	 * @return array<string, mixed> Manifest enriched with binding status, errors, warnings, planner hints, and traces.
	 */
	private function mergeBindingManifest(
		array $manifest,
		array $bindings,
		array $bindingWarnings=[],
		array $bindingPlanner=[],
		?string $renderTraceId=null
	): array {
		$manifest['bindings']=$bindings;
		$manifest['render_trace_id']=$this->tracingEnabled()===true
			? ($renderTraceId ?? $this->firstBindingRenderTraceId($bindings))
			: null;
		$manifest['binding_trace']=$this->tracingEnabled()===true
			? array_values(array_filter(array_map(
				static fn(array $binding): array => is_array($binding['trace'] ?? null) ? $binding['trace'] : [],
				$bindings
			), static fn(array $trace): bool => $trace!==[]))
			: [];
		$manifest['binding_errors']=array_values(array_filter($bindings, static fn(array $binding): bool => ($binding['ok'] ?? true)!==true));
		$manifest['binding_warnings']=$bindingWarnings;
		$manifest['binding_planner']=$bindingPlanner;
		return $manifest;
	}

	/**
	 * Produces guardrail warnings for resolved bindings against the template plan.
	 *
	 * @param array<string, mixed> $plan Parsed template plan containing aggregate data paths.
	 * @param array<int, array<string, mixed>> $bindings Binding manifest entries from the current render.
	 * @param array<string, mixed> $overrides Runtime overrides that may tune or disable guardrails.
	 * @return array<int, array<string, mixed>> Slow, unused, or duplicate target warnings.
	 */
	private function bindingWarningsForPlan(array $plan, array $bindings, array $overrides=[]): array {
		$guardrails=$this->resolvedBindingGuardrails($overrides);
		if(($guardrails['enabled'] ?? true)!==true || $bindings===[]){
			return [];
		}

		$warnings=[];
		$executed=array_values(array_filter($bindings, static fn(array $binding): bool => ($binding['ok'] ?? false)===true && ($binding['skipped'] ?? false)!==true));
		$dataPaths=$this->planDataPaths($plan);

		if(($guardrails['warn_slow'] ?? true)===true){
			$slowMs=(float)($guardrails['slow_ms'] ?? self::DEFAULT_BINDING_GUARDRAILS['slow_ms']);
			foreach($executed as $binding){
				$duration=(float)($binding['duration_ms'] ?? 0.0);
				if($duration < $slowMs){
					continue;
				}
				$warnings[]=[
					'type'=>'slow_binding',
					'path'=>(string)($binding['path'] ?? ''),
					'binding'=>(string)($binding['binding'] ?? 'binding'),
					'duration_ms'=>$duration,
					'threshold_ms'=>$slowMs,
					'message'=>"Binding '".((string)($binding['path'] ?? '') ?: (string)($binding['binding'] ?? 'binding'))."' took {$duration}ms to resolve.",
				];
			}
		}

		if(($guardrails['warn_unused'] ?? true)===true){
			foreach($executed as $binding){
				$path=trim((string)($binding['path'] ?? ''));
				if($path==='' || $this->bindingPathIsUsed($path, $dataPaths)){
					continue;
				}
				$warnings[]=[
					'type'=>'unused_binding',
					'path'=>$path,
					'binding'=>(string)($binding['binding'] ?? 'binding'),
					'message'=>"Binding path '{$path}' is not referenced by the template plan.",
				];
			}
		}

		if(($guardrails['warn_duplicate_targets'] ?? true)===true){
			foreach($this->duplicateBindingTargets($executed) as $warning){
				$warnings[]=$warning;
			}
		}

		return $warnings;
	}

	/**
	 * Suggests stronger cache identity strategies for query-backed bindings.
	 *
	 * @param array<string, mixed> $plan Parsed template plan containing data-path usage.
	 * @param array<int, array<string, mixed>> $bindings Binding manifest entries from the current render.
	 * @return array<int, array<string, mixed>> Planner suggestions safe to expose in render and debug manifests.
	 */
	private function bindingPlannerForPlan(array $plan, array $bindings): array {
		if($bindings===[]){
			return [];
		}

		$suggestions=[];
		$dataPaths=$this->planDataPaths($plan);
		foreach($bindings as $binding){
			if(($binding['ok'] ?? false)!==true || ($binding['skipped'] ?? false)===true){
				continue;
			}
			$type=(string)($binding['type'] ?? '');
			if(!in_array($type, ['sql_query', 'search_query'], true)){
				continue;
			}
			$path=trim((string)($binding['path'] ?? ''));
			if($path!=='' && !$this->bindingPathIsUsed($path, $dataPaths)){
				continue;
			}
			$queryFingerprint=trim((string)($binding['query_fingerprint'] ?? ''));
			$queryIdentitySource=trim((string)($binding['query_identity_source'] ?? 'execution_state'));
			if($queryFingerprint==='' || $queryIdentitySource==='fingerprint'){
				continue;
			}
			$suggestions[]=[
				'type'=>'inherit_query_identity',
				'driver'=>$type,
				'path'=>$path,
				'binding'=>(string)($binding['binding'] ?? 'binding'),
				'target_type'=>(string)($binding['query_target_type'] ?? ''),
				'target'=>(string)($binding['query_target'] ?? ''),
				'query_fingerprint'=>$queryFingerprint,
				'message'=>"Binding '".($path!=='' ? $path : (string)($binding['binding'] ?? 'binding'))."' can inherit its source query fingerprint explicitly for stronger cache and reuse alignment.",
			];
		}
		return $suggestions;
	}

	/**
	 * Builds the per-render cache descriptor for a binding.
	 *
	 * @param DataBinding $binding Binding that may expose a render cache identity.
	 * @param BindingContext $context Context used when asking the binding for identity data.
	 * @return array{cacheable: bool, cache_scope: string, cache_state: string, cache_key: ?string, cache_identity: ?array}
	 */
	private function bindingCacheDescriptor(DataBinding $binding, BindingContext $context): array {
		if(!$binding instanceof BindingCacheIdentityProvider){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
			];
		}

		$identity=$binding->cacheIdentity($context);
		$normalized=$this->normalizeBindingCacheIdentity($identity);
		if($normalized===null){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
			];
		}

		return [
			'cacheable'=>true,
			'cache_scope'=>'render',
			'cache_state'=>'miss',
			'cache_key'=>sha1(json_encode($normalized)),
			'cache_identity'=>$normalized,
		];
	}

	/**
	 * Builds the cross-request persistent cache descriptor for a binding.
	 *
	 * Persistent cache is disabled in development mode and requires an explicit
	 * TTL plus a stable identity from the binding or its render cache descriptor.
	 *
	 * @param DataBinding $binding Binding that may expose persistent cache settings.
	 * @param BindingContext $context Context carrying overrides and template identity.
	 * @param array<string, mixed> $renderCache Descriptor used as fallback identity source.
	 * @return array{cacheable: bool, cache_scope: string, cache_state: string, cache_key: ?string, cache_identity: ?array, cache_ttl: ?int, cache_names: array<int, string>}
	 */
	private function bindingPersistentCacheDescriptor(DataBinding $binding, BindingContext $context, array $renderCache=[]): array {
		if($this->bindingPersistentCacheEnabled($context)!==true || !$binding instanceof BindingPersistentCacheProvider){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
				'cache_ttl'=>null,
				'cache_names'=>[],
			];
		}

		$config=$binding->persistentCache($context);
		if(!is_array($config)){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
				'cache_ttl'=>null,
				'cache_names'=>[],
			];
		}

		$ttl=max(1, (int)($config['ttl'] ?? 0));
		if($ttl < 1){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
				'cache_ttl'=>null,
				'cache_names'=>[],
			];
		}

		$identity=$config['identity'] ?? ($renderCache['cache_identity'] ?? null);
		$normalized=$this->normalizeBindingCacheIdentity($identity);
		if($normalized===null){
			return [
				'cacheable'=>false,
				'cache_scope'=>'none',
				'cache_state'=>'bypass',
				'cache_key'=>null,
				'cache_identity'=>null,
				'cache_ttl'=>null,
				'cache_names'=>[],
			];
		}

		return [
			'cacheable'=>true,
			'cache_scope'=>'persistent',
			'cache_state'=>'miss',
			'cache_key'=>sha1(json_encode($normalized)),
			'cache_identity'=>$normalized,
			'cache_ttl'=>$ttl,
			'cache_names'=>$this->normalizeBindingCacheNames($config['names'] ?? []),
		];
	}

	/**
	 * Flattens render and persistent cache descriptors into a binding manifest payload.
	 *
	 * @param array<string, mixed> $renderCache Per-render cache descriptor.
	 * @param array<string, mixed> $persistentCache Persistent cache descriptor.
	 * @param string $scope Effective cache scope recorded for this resolution.
	 * @param string $state Hit, miss, store, or bypass state recorded for this resolution.
	 * @param array<string, mixed> $extra Additional cache fields such as layer or store error.
	 * @return array<string, mixed> Cache metadata embedded into the binding entry.
	 */
	private function bindingCacheMetadata(
		array $renderCache,
		array $persistentCache,
		string $scope,
		string $state,
		array $extra=[]
	): array {
		$descriptor=$scope==='persistent' && ($persistentCache['cacheable'] ?? false)===true
			? $persistentCache
			: ((($renderCache['cacheable'] ?? false)===true) ? $renderCache : $persistentCache);

		return array_replace([
			'cacheable'=>(($renderCache['cacheable'] ?? false)===true) || (($persistentCache['cacheable'] ?? false)===true),
			'persistent_cache'=>(($persistentCache['cacheable'] ?? false)===true),
			'cache_scope'=>$scope,
			'cache_state'=>$state,
			'cache_key'=>$descriptor['cache_key'] ?? null,
			'cache_identity'=>$descriptor['cache_identity'] ?? null,
			'cache_names'=>$persistentCache['cache_names'] ?? [],
			'cache_ttl'=>$persistentCache['cache_ttl'] ?? null,
		], $extra);
	}

	/**
	 * Adds or removes trace payload fields according to runtime tracing policy.
	 *
	 * @param array<string, mixed> $binding Binding manifest entry being finalized.
	 * @return array<string, mixed> Binding entry with trace payload when tracing is enabled.
	 */
	private function stitchBindingTrace(array $binding): array {
		if($this->tracingEnabled()!==true){
			unset($binding['render_trace_id'], $binding['binding_trace_id'], $binding['trace']);
			return $binding;
		}
		$binding['trace']=$this->bindingTracePayload($binding);
		return $binding;
	}

	/**
	 * Converts a binding manifest entry into a compact trace payload.
	 *
	 * @param array<string, mixed> $binding Binding entry containing identity, cache, dependency, and status fields.
	 * @return array<string, mixed> Trace structure suitable for debugbar output and binding manifests.
	 */
	private function bindingTracePayload(array $binding): array {
		$bindingCacheNames=$this->normalizeBindingCacheNames($binding['cache_names'] ?? []);
		$queryCacheNames=$this->normalizeBindingCacheNames($binding['query_cache_names'] ?? []);
		$sqlInvalidationNames=array_values(array_intersect($bindingCacheNames, $queryCacheNames));
		return array_filter([
			'correlation'=>array_filter([
				'render_trace_id'=>$this->traceString($binding['render_trace_id'] ?? null),
				'binding_trace_id'=>$this->traceString($binding['binding_trace_id'] ?? null),
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'path'=>$this->traceString($binding['path'] ?? null),
			'binding'=>$this->traceString($binding['binding'] ?? null),
			'source'=>array_filter([
				'driver'=>$this->traceString($binding['driver'] ?? null),
				'type'=>$this->traceString($binding['type'] ?? null),
				'mode'=>$this->traceString($binding['query_mode'] ?? null),
				'target_type'=>$this->traceString($binding['query_target_type'] ?? null),
				'target'=>$this->traceString($binding['query_target'] ?? null),
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'identity'=>$this->bindingTraceIdentity($binding),
			'status'=>[
				'ok'=>($binding['ok'] ?? false)===true,
				'skipped'=>($binding['skipped'] ?? false)===true,
				'reused'=>($binding['reused'] ?? false)===true,
				'result_type'=>$this->traceString($binding['result_type'] ?? null),
				'duration_ms'=>round((float)($binding['duration_ms'] ?? 0.0), 3),
			],
			'cache'=>array_filter([
				'cacheable'=>($binding['cacheable'] ?? false)===true,
				'persistent'=>($binding['persistent_cache'] ?? false)===true,
				'scope'=>$this->traceString($binding['cache_scope'] ?? null),
				'state'=>$this->traceString($binding['cache_state'] ?? null),
				'layer'=>$this->traceString($binding['cache_layer'] ?? null),
				'key'=>$this->traceString($binding['cache_key'] ?? null),
				'ttl'=>isset($binding['cache_ttl']) ? (int)$binding['cache_ttl'] : null,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'dependencies'=>array_filter([
				'binding_cache_names'=>$bindingCacheNames,
				'query_cache_names'=>$queryCacheNames,
				'sql_invalidation_names'=>$sqlInvalidationNames,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'error'=>is_array($binding['error'] ?? null) ? $binding['error'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Extracts query identity details for trace correlation.
	 *
	 * @param array<string, mixed> $binding Binding entry that may include query fingerprint metadata.
	 * @return ?array<string, mixed> Identity payload, or null when the binding has no query identity context.
	 */
	private function bindingTraceIdentity(array $binding): ?array {
		$queryFingerprint=$this->traceString($binding['query_fingerprint'] ?? null);
		$queryIdentityMode=$this->traceString($binding['query_identity_mode'] ?? null);
		$queryIdentitySource=$this->traceString($binding['query_identity_source'] ?? null);
		$queryIdentityRequested=($binding['query_identity_requested'] ?? false)===true;
		$queryIdentityAvailable=($binding['query_identity_available'] ?? false)===true;
		if(
			$queryFingerprint===null
			&& $queryIdentityMode===null
			&& $queryIdentitySource===null
			&& $queryIdentityRequested!==true
			&& $queryIdentityAvailable!==true
		){
			return null;
		}
		return array_filter([
			'query_fingerprint'=>$queryFingerprint,
			'mode'=>$queryIdentityMode,
			'source'=>$queryIdentitySource,
			'requested'=>$queryIdentityRequested,
			'available'=>$queryIdentityAvailable,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	/**
	 * Normalizes optional trace strings.
	 *
	 * @param mixed $value Candidate trace value.
	 * @return ?string Trimmed non-empty string, or null when the value should be omitted.
	 */
	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	/**
	 * Resolves a binding while propagating trace context into supported database calls.
	 *
	 * @param DataBinding $binding Binding resolver being executed.
	 * @param BindingContext $context Current render and binding trace context.
	 * @param array<string, mixed> $metadata Binding metadata used to enrich database trace correlation.
	 * @return mixed binding resolver output, optionally executed inside SQL trace context for query correlation.
	 */
	private function resolveBindingWithTraceContext(DataBinding $binding, BindingContext $context, array $metadata): mixed {
		if($this->tracingEnabled()!==true){
			return $binding->resolve($context);
		}
		if(
			($metadata['driver'] ?? null)==='sql'
			&& class_exists('Dataphyre\\Database\\DB', false)
			&& method_exists('Dataphyre\\Database\\DB', 'withTraceContext')
		){
			$traceContext=$context->traceContext();
			return \Dataphyre\Database\DB::withTraceContext([
				'render_trace_id'=>$context->renderTraceId(),
				'binding_trace_id'=>$context->bindingTraceId(),
				'template_name'=>$context->templateName(),
				'binding_name'=>$binding->name(),
				'binding_path'=>is_string($traceContext['binding_path'] ?? null) ? $traceContext['binding_path'] : null,
				'query_fingerprint'=>$this->traceString($metadata['query_fingerprint'] ?? null),
				'query_identity_mode'=>$this->traceString($metadata['query_identity_mode'] ?? null),
				'query_identity_source'=>$this->traceString($metadata['query_identity_source'] ?? null),
				'query_target_type'=>$this->traceString($metadata['query_target_type'] ?? null),
				'query_target'=>$this->traceString($metadata['query_target'] ?? null),
				'query_mode'=>$this->traceString($metadata['query_mode'] ?? null),
			], fn() => $binding->resolve($context));
		}
		return $binding->resolve($context);
	}

	/**
	 * Allocates the next binding trace id within a render trace.
	 *
	 * @param array<string, mixed> $traceState Mutable render trace sequence state.
	 * @return string Stable per-binding trace id for the current render.
	 */
	private function nextBindingTraceId(array &$traceState): string {
		$traceState['sequence']=(int)($traceState['sequence'] ?? 0)+1;
		$renderTraceId=is_string($traceState['render_trace_id'] ?? null) ? $traceState['render_trace_id'] : $this->newTraceId('tpl');
		$traceState['render_trace_id']=$renderTraceId;
		return $renderTraceId.'.b'.str_pad((string)$traceState['sequence'], 4, '0', STR_PAD_LEFT);
	}

	/**
	 * Finds the first render trace id already recorded in binding entries.
	 *
	 * @param array<int, array<string, mixed>> $bindings Binding entries captured during a render.
	 * @return ?string Render trace id, or null when tracing was disabled or absent.
	 */
	private function firstBindingRenderTraceId(array $bindings): ?string {
		foreach($bindings as $binding){
			$renderTraceId=$this->traceString($binding['render_trace_id'] ?? null);
			if($renderTraceId!==null){
				return $renderTraceId;
			}
		}
		return null;
	}

	/**
	 * Creates a short trace identifier for render and binding correlation.
	 *
	 * @param string $prefix Human-readable trace namespace prefix.
	 * @return string Prefix plus random bytes, with uniqid fallback when secure randomness is unavailable.
	 */
	private function newTraceId(string $prefix='trace'): string {
		$prefix=trim($prefix);
		if($prefix===''){
			$prefix='trace';
		}
		try{
			return $prefix.'_'.bin2hex(random_bytes(6));
		}catch(\Throwable){
			return $prefix.'_'.str_replace('.', '', uniqid('', true));
		}
	}

	/**
	 * Determines whether templating should include trace payloads.
	 *
	 * @return bool Runtime tracing flag when available, otherwise enabled outside production.
	 */
	private function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

	/**
	 * Converts binding cache identity input into a deterministic array.
	 *
	 * @param mixed $identity Identity returned by a binding cache provider.
	 * @return ?array<string, mixed> Normalized identity, or null when identity is empty.
	 */
	private function normalizeBindingCacheIdentity(mixed $identity): ?array {
		if($identity===null){
			return null;
		}
		if(is_string($identity)){
			$identity=trim($identity);
			return $identity!=='' ? ['key'=>$identity] : null;
		}
		if(is_scalar($identity)){
			return ['value'=>$identity];
		}
		if(is_array($identity)){
			return $this->normalizeBindingCacheArray($identity);
		}
		if(is_object($identity)){
			return ['value'=>$this->normalizeBindingCacheValue($identity)];
		}
		return ['value_type'=>get_debug_type($identity)];
	}

	/**
	 * Recursively normalizes and sorts identity arrays for stable hashing.
	 *
	 * @param array<mixed> $identity Identity array with arbitrary keys and values.
	 * @return array<string, mixed> Sorted identity array with normalized values.
	 */
	private function normalizeBindingCacheArray(array $identity): array {
		$normalized=[];
		ksort($identity);
		foreach($identity as $key=>$value){
			$normalized[(string)$key]=$this->normalizeBindingCacheValue($value);
		}
		return $normalized;
	}

	/**
	 * Converts cache identity values into scalar, array, or class-stamped shapes.
	 *
	 * @param mixed $value Identity value that may include arrays, scalars, or objects.
	 * @return mixed scalar, null, sorted array, or class-stamped object representation suitable for JSON hashing.
	 */
	private function normalizeBindingCacheValue(mixed $value): mixed {
		if(is_array($value)){
			return $this->normalizeBindingCacheArray($value);
		}
		if(is_scalar($value) || $value===null){
			return $value;
		}
		if(is_object($value)){
			if(method_exists($value, 'toArray')){
				$resolved=$value->toArray();
				if(is_array($resolved)){
					return [
						'object'=>$value::class,
						'value'=>$this->normalizeBindingCacheArray($resolved),
					];
				}
			}
			if($value instanceof \JsonSerializable){
				$resolved=$value->jsonSerialize();
				if(is_array($resolved)){
					return [
						'object'=>$value::class,
						'value'=>$this->normalizeBindingCacheArray($resolved),
					];
				}
				if(is_scalar($resolved) || $resolved===null){
					return [
						'object'=>$value::class,
						'value'=>$resolved,
					];
				}
			}
			if(method_exists($value, '__toString')){
				return [
					'object'=>$value::class,
					'value'=>(string)$value,
				];
			}
			return ['object'=>$value::class];
		}
		return get_debug_type($value);
	}

	/**
	 * Loads a persistent binding value from disk when the descriptor is valid and fresh.
	 *
	 * Corrupt or expired entries are removed so subsequent renders can repopulate
	 * cache without repeatedly decoding bad payloads.
	 *
	 * @param array<string, mixed> $descriptor Persistent cache descriptor.
	 * @param BindingContext $context Render context used to resolve the cache root.
	 * @return array{hit: bool, value?: mixed, stored_at?: int}
	 */
	private function loadPersistentBindingValue(array $descriptor, BindingContext $context): array {
		if(($descriptor['cacheable'] ?? false)!==true){
			return ['hit'=>false];
		}
		$file=$this->bindingPersistentCacheItemFile((string)($descriptor['cache_key'] ?? ''), $this->bindingPersistentCacheRoot($context));
		if(!is_file($file)){
			return ['hit'=>false];
		}
		$payload=@file_get_contents($file);
		if(!is_string($payload) || $payload===''){
			return ['hit'=>false];
		}
		try{
			$decoded=@unserialize($payload);
		}catch(\Throwable){
			@unlink($file);
			return ['hit'=>false];
		}
		if(!is_array($decoded)){
			@unlink($file);
			return ['hit'=>false];
		}
		if((int)($decoded['expires_at'] ?? 0) < time()){
			@unlink($file);
			return ['hit'=>false];
		}
		return [
			'hit'=>true,
			'value'=>$decoded['value'] ?? null,
			'stored_at'=>(int)($decoded['stored_at'] ?? 0),
		];
	}

	/**
	 * Stores a resolved binding value in the persistent cache.
	 *
	 * @param array<string, mixed> $descriptor Persistent cache descriptor.
	 * @param BindingContext $context Render context used to resolve the cache root.
	 * @param mixed $value Resolved binding value to serialize.
	 * @return ?string Error message when serialization or writing fails, otherwise null.
	 */
	private function storePersistentBindingValue(array $descriptor, BindingContext $context, mixed $value): ?string {
		if(($descriptor['cacheable'] ?? false)!==true){
			return null;
		}
		$root=$this->bindingPersistentCacheRoot($context);
		$itemsDir=$root.'items'.DIRECTORY_SEPARATOR;
		$namesDir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($itemsDir)){
			@mkdir($itemsDir, 0777, true);
		}
		if(!is_dir($namesDir)){
			@mkdir($namesDir, 0777, true);
		}
		try{
			$payload=serialize([
				'stored_at'=>time(),
				'expires_at'=>time()+max(1, (int)($descriptor['cache_ttl'] ?? 300)),
				'names'=>$descriptor['cache_names'] ?? [],
				'value'=>$value,
			]);
		}catch(\Throwable $e){
			return $e->getMessage();
		}
		$file=$this->bindingPersistentCacheItemFile((string)($descriptor['cache_key'] ?? ''), $root);
		if(@file_put_contents($file, $payload, LOCK_EX)===false){
			return 'Unable to write persistent binding cache.';
		}
		foreach($descriptor['cache_names'] ?? [] as $name){
			$this->indexPersistentBindingCacheName($name, (string)($descriptor['cache_key'] ?? ''), $namesDir);
		}
		return null;
	}

	/**
	 * Resolves the root directory for persistent binding cache files.
	 *
	 * @param ?BindingContext $context Optional context whose cache_dir override takes precedence.
	 * @return string Directory path ending in the binding cache namespace separator.
	 */
	private function bindingPersistentCacheRoot(?BindingContext $context=null): string {
		$overrides=$context?->overrides() ?? [];
		$cacheDir=$overrides['cache_dir'] ?? null;
		if(!is_string($cacheDir) || trim($cacheDir)===''){
			$state=\dataphyre\templating::state();
			$cacheDir=(string)($state['cache_dir'] ?? '');
		}
		return rtrim($cacheDir, '/\\').DIRECTORY_SEPARATOR.'bindings'.DIRECTORY_SEPARATOR;
	}

	/**
	 * Builds the item cache filename for a persistent binding key.
	 *
	 * @param string $key SHA-1 cache key generated from normalized identity.
	 * @param string $root Persistent binding cache root.
	 * @return string Absolute or configured-relative cache item path.
	 */
	private function bindingPersistentCacheItemFile(string $key, string $root): string {
		return $root.'items'.DIRECTORY_SEPARATOR.$key.'.cache';
	}

	/**
	 * Builds the name index filename for grouped persistent cache invalidation.
	 *
	 * @param string $name Cache group name supplied by a binding.
	 * @param string $namesDir Directory containing name index files.
	 * @return string JSON index filename for the cache group.
	 */
	private function bindingPersistentCacheNameFile(string $name, string $namesDir): string {
		return $namesDir.sha1($name).'.json';
	}

	/**
	 * Records a cache key under a persistent cache group name.
	 *
	 * @param string $name Cache group name.
	 * @param string $key Persistent binding cache key.
	 * @param string $namesDir Directory containing group index JSON files.
	 * @return void The JSON index is updated best-effort.
	 */
	private function indexPersistentBindingCacheName(string $name, string $key, string $namesDir): void {
		$file=$this->bindingPersistentCacheNameFile($name, $namesDir);
		$existing=@file_get_contents($file);
		$keys=json_decode(is_string($existing) ? $existing : '[]', true);
		$keys=is_array($keys) ? $keys : [];
		$keys[]=$key;
		$keys=array_values(array_unique(array_filter($keys, static fn(mixed $value): bool => is_string($value) && $value!=='')));
		@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
	}

	/**
	 * Clears persistent binding cache item and name-index directories.
	 *
	 * @param string $itemsDir Directory containing serialized binding values.
	 * @param string $namesDir Directory containing group index files.
	 * @return int Number of cache files deleted.
	 */
	private function clearPersistentBindingCacheDirectories(string $itemsDir, string $namesDir): int {
		$deleted=0;
		foreach([$itemsDir, $namesDir] as $dir){
			if(!is_dir($dir)){
				continue;
			}
			$files=glob($dir.'*');
			if(!is_array($files)){
				continue;
			}
			foreach($files as $file){
				if(is_file($file) && @unlink($file)){
					$deleted++;
				}
			}
			@rmdir($dir);
		}
		$root=dirname(rtrim($itemsDir, '/\\')).DIRECTORY_SEPARATOR;
		@rmdir($root);
		return $deleted;
	}

	/**
	 * Normalizes persistent cache group names.
	 *
	 * @param array<int, mixed>|string $names Cache group names from binding configuration.
	 * @return array<int, string> Unique non-empty group names.
	 */
	private function normalizeBindingCacheNames(array|string $names): array {
		$names=is_array($names) ? $names : [$names];
		$normalized=[];
		foreach($names as $name){
			if(!is_string($name)){
				continue;
			}
			$name=trim($name);
			if($name===''){
				continue;
			}
			$normalized[$name]=true;
		}
		return array_keys($normalized);
	}

	/**
	 * Determines whether persistent binding cache may be used for this render.
	 *
	 * @param BindingContext $context Render context carrying per-call state overrides.
	 * @return bool False in development mode, true otherwise.
	 */
	private function bindingPersistentCacheEnabled(BindingContext $context): bool {
		$state=$context->overrides();
		if(array_key_exists('is_dev_mode', $state)){
			return (bool)$state['is_dev_mode']!==true;
		}
		$current=\dataphyre\templating::state();
		return (bool)($current['is_dev_mode'] ?? false)!==true;
	}

	/**
	 * Resolves binding guardrail configuration for warnings and planner output.
	 *
	 * @param array<string, mixed> $overrides Runtime overrides that may disable or tune guardrails.
	 * @return array{enabled: bool, warn_slow: bool, slow_ms: float, warn_unused: bool, warn_duplicate_targets: bool}
	 */
	private function resolvedBindingGuardrails(array $overrides): array {
		$guardrails=$overrides['binding_guardrails'] ?? self::DEFAULT_BINDING_GUARDRAILS;
		if($guardrails===false){
			return array_replace(self::DEFAULT_BINDING_GUARDRAILS, ['enabled'=>false]);
		}
		if($guardrails===true || !is_array($guardrails)){
			return self::DEFAULT_BINDING_GUARDRAILS;
		}

		return [
			'enabled'=>array_key_exists('enabled', $guardrails) ? (bool)$guardrails['enabled'] : true,
			'warn_slow'=>array_key_exists('warn_slow', $guardrails) ? (bool)$guardrails['warn_slow'] : true,
			'slow_ms'=>max(0.0, (float)($guardrails['slow_ms'] ?? self::DEFAULT_BINDING_GUARDRAILS['slow_ms'])),
			'warn_unused'=>array_key_exists('warn_unused', $guardrails) ? (bool)$guardrails['warn_unused'] : true,
			'warn_duplicate_targets'=>array_key_exists('warn_duplicate_targets', $guardrails) ? (bool)$guardrails['warn_duplicate_targets'] : true,
		];
	}

	/**
	 * Extracts data paths referenced by a parsed template plan.
	 *
	 * @param array<string, mixed> $plan Plan structure returned by the templating parser.
	 * @return array<int, string> Non-empty data paths used by guardrail checks.
	 */
	private function planDataPaths(array $plan): array {
		$aggregate=$plan['aggregate'] ?? [];
		if(is_array($aggregate) && is_array($aggregate['data_paths'] ?? null)){
			return array_values(array_filter(array_map('strval', $aggregate['data_paths']), static fn(string $value): bool => trim($value)!==''));
		}
		$dataPaths=$plan['data_paths'] ?? [];
		return is_array($dataPaths)
			? array_values(array_filter(array_map('strval', $dataPaths), static fn(string $value): bool => trim($value)!==''))
			: [];
	}

	/**
	 * Checks whether a binding path overlaps any data path used by the template.
	 *
	 * @param string $bindingPath Binding dot-path from the manifest.
	 * @param array<int, string> $dataPaths Template data paths extracted from the plan.
	 * @return bool True when the binding path or one of its descendants is referenced.
	 */
	private function bindingPathIsUsed(string $bindingPath, array $dataPaths): bool {
		foreach($dataPaths as $dataPath){
			$dataPath=trim((string)$dataPath);
			if($dataPath===''){
				continue;
			}
			if($dataPath===$bindingPath){
				return true;
			}
			if(str_starts_with($dataPath, $bindingPath.'.') || str_starts_with($bindingPath, $dataPath.'.')){
				return true;
			}
		}
		return false;
	}

	/**
	 * Groups query-backed bindings that target the same source more than once.
	 *
	 * @param array<int, array<string, mixed>> $bindings Executed binding entries from the current render.
	 * @return array<int, array<string, mixed>> Duplicate target warnings for guardrail output.
	 */
	private function duplicateBindingTargets(array $bindings): array {
		$groups=[];
		foreach($bindings as $binding){
			$type=(string)($binding['type'] ?? '');
			$target=(string)($binding['query_target'] ?? '');
			$targetType=(string)($binding['query_target_type'] ?? '');
			if($type==='' || $target===''){
				continue;
			}
			$key=$type.'|'.$targetType.'|'.$target;
			$groups[$key][]=$binding;
		}

		$warnings=[];
		foreach($groups as $group){
			if(count($group)<2){
				continue;
			}
			$first=$group[0];
			$target=(string)($first['query_target'] ?? '');
			$targetType=(string)($first['query_target_type'] ?? 'target');
			$type=(string)($first['type'] ?? 'binding');
			$paths=array_values(array_unique(array_filter(array_map(
				static fn(array $binding): string => trim((string)($binding['path'] ?? '')),
				$group
			), static fn(string $path): bool => $path!=='')));
			$modes=array_values(array_unique(array_filter(array_map(
				static fn(array $binding): string => trim((string)($binding['query_mode'] ?? '')),
				$group
			), static fn(string $mode): bool => $mode!=='')));
			$warnings[]=[
				'type'=>'duplicate_binding_target',
				'driver'=>$type,
				'target_type'=>$targetType,
				'target'=>$target,
				'paths'=>$paths,
				'modes'=>$modes,
				'binding_count'=>count($group),
				'message'=>"Multiple bindings target the same {$targetType} '{$target}'.",
			];
		}

		return $warnings;
	}
}
