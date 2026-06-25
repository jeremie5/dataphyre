<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

/**
 * Static facade for the Dataphyre templating manager.
 *
 * The facade keeps application code compact while preserving typed access to contexts, views, bindings, render artifacts, inspection plans, contracts, assets, globals, hooks, and design-token adaptation.
 */
final class Templating {

	/**
	 * Returns the process-local templating manager used by all facade calls.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return TemplatingManager Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function manager(): TemplatingManager {
		return TemplatingManager::instance();
	}

	/**
	 * Clears the process-local templating manager so following calls rebuild state from configuration.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return void The in-memory state is cleared in place.
	 */
	public static function flush(): void {
		TemplatingManager::flush();
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return TemplatingState Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function state(): TemplatingState {
		return self::manager()->state();
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
	public static function context(
		?bool $isDevMode=null,
		?string $cacheDir=null,
		?array $globalContext=null,
		?bool $strictMode=null,
		array|AssetPolicy|null $assetPolicy=null
	): TemplatingContext {
		return self::manager()->context($isDevMode, $cacheDir, $globalContext, $strictMode, $assetPolicy);
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function template(string $templateFile): TemplateView {
		return self::manager()->template($templateFile);
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function component(string $reference): TemplateView {
		return self::manager()->component($reference);
	}

	/**
	 * Creates a template view for a file, component reference, or inline source.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @return TemplateView Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function source(string $template, string $templateName='inline.tpl'): TemplateView {
		return self::manager()->source($template, $templateName);
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
	public static function binding(callable $resolver, ?string $name=null): CallableBinding {
		return self::manager()->binding($resolver, $name);
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
	public static function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return self::manager()->cachedBinding($binding, $identity, $name);
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
	public static function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return self::manager()->rememberBinding($binding, $identity, $ttl, $names, $name);
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
	public static function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return self::manager()->whenBinding($binding, $condition, $default);
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
	public static function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return self::manager()->unlessBinding($binding, $condition, $default);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @return SqlQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return self::manager()->queryBinding($query, $mode, $options);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Query binding options such as identity hints or execution metadata.
	 * @return SqlQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return self::manager()->queryBindingInheritingIdentity($query, $mode, $options);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @return SearchQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return self::manager()->searchBinding($query, $mode, $options);
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param object $query SQL/search query object consumed lazily by the binding.
	 * @param string $mode Binding projection mode such as records, row, count, or results.
	 * @param array<string,mixed> $options Search binding options such as identity hints or execution metadata.
	 * @return SearchQueryBinding Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return self::manager()->searchBindingInheritingIdentity($query, $mode, $options);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string,mixed> $themeValues Theme values supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function render(
		string $templateFile,
		array $data=[],
		array $themeValues=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->render($templateFile, $data, $themeValues, $slots);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @return TemplatePlan Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function plan(string $templateFile): TemplatePlan {
		return self::manager()->plan($templateFile);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @return AssetManifest Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function assetManifest(string $templateFile): AssetManifest {
		return self::manager()->assetManifest($templateFile);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string,mixed> $themeValues Theme values supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function inspect(
		string $templateFile,
		array $data=[],
		array $themeValues=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->inspect($templateFile, $data, $themeValues, $slots);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string,mixed> $themeValues Theme values supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function renderString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl'
	): RenderedTemplate {
		return self::manager()->renderString($template, $data, $themeValues, $slots, $templateName);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string,mixed> $themeValues Theme values supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function inspectString(
		string $template,
		array $data=[],
		array $themeValues=[],
		array $slots=[],
		string $templateName='inline.tpl'
	): RenderedTemplate {
		return self::manager()->inspectString($template, $data, $themeValues, $slots, $templateName);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @return TemplatePlan Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function planString(string $template, string $templateName='inline.tpl'): TemplatePlan {
		return self::manager()->planString($template, $templateName);
	}

	/**
	 * Builds non-rendering diagnostics for template dependencies, assets, bindings, and contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $template Inline template source.
	 * @param string $templateName Synthetic name used in traces, cache keys, and diagnostics for inline templates.
	 * @return AssetManifest Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function assetManifestString(string $template, string $templateName='inline.tpl'): AssetManifest {
		return self::manager()->assetManifestString($template, $templateName);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param string $fallbackTemplate Fallback template.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @param array<string,mixed> $themeValues Theme values supplied to render helpers.
	 * @param array<string,mixed> $slots Named slot content passed into layouts or components.
	 * @return RenderedTemplate Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function renderWithFallback(
		string $templateFile,
		string $fallbackTemplate,
		array $data=[],
		array $themeValues=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->renderWithFallback($templateFile, $fallbackTemplate, $data, $themeValues, $slots);
	}

	/**
	 * Renders a template source or file with data, overrides, contracts, bindings, and trace metadata.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @param array<string,mixed> $data Template data made available to bindings and placeholders.
	 * @return object Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function asyncRender(string $templateFile, array $data=[]): object {
		return self::manager()->asyncRender($templateFile, $data);
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
	public static function registerTag(string $tag, callable $callback): void {
		self::manager()->registerTag($tag, $callback);
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
	public static function registerFilter(string $filter, callable $callback): void {
		self::manager()->registerFilter($filter, $callback);
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
	public static function registerExtension(string $name, callable $extension): void {
		self::manager()->registerExtension($name, $extension);
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
	public static function registerHelper(string $name, callable $helper): void {
		self::manager()->registerHelper($name, $helper);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $event Templating event name emitted by the render pipeline.
	 * @param callable $callback Event callback invoked by the templating render pipeline.
	 * @return void Event hook registration mutates the process-local templating registry.
	 */
	public static function on(string $event, callable $callback): void {
		self::manager()->registerEventHook($event, $callback);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param callable $hook Pre- or post-processing hook invoked during template rendering.
	 * @return void Event hook registration mutates the process-local templating registry.
	 */
	public static function before(callable $hook): void {
		self::manager()->registerPreprocessingHook($hook);
	}

	/**
	 * Registers templating extensions, hooks, helpers, tags, filters, events, or contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param callable $hook Pre- or post-processing hook invoked during template rendering.
	 * @return void Event hook registration mutates the process-local templating registry.
	 */
	public static function after(callable $hook): void {
		self::manager()->registerPostprocessingHook($hook);
	}

	/**
	 * Adds a value to the process-wide template context for future renders.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $key Global context key or dot-path accepted by the templating runtime.
	 * @param mixed $value Value made available to templates until globals are cleared.
	 * @return void The shared manager state is updated in place.
	 */
	public static function addGlobal(string $key, mixed $value): void {
		self::manager()->addGlobal($key, $value);
	}

	/**
	 * Returns the current process-wide template context snapshot.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return array<string, mixed> Global values merged into new render contexts.
	 */
	public static function globals(): array {
		return self::manager()->globals();
	}

	/**
	 * Clears all process-wide template context values.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return void Future renders start without previously registered global values.
	 */
	public static function clearGlobals(): void {
		self::manager()->clearGlobals();
	}

	/**
	 * Creates, wraps, resolves, caches, traces, or validates a template data binding.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string ...$names Cache namespace names used for grouped invalidation.
	 * @return int Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function clearBindingCache(string ...$names): int {
		return self::manager()->clearBindingCache(...$names);
	}

	/**
	 * Reads or replaces the default asset policy used by render and planning operations.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @return AssetPolicy Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function assetPolicy(): AssetPolicy {
		return self::manager()->assetPolicy();
	}

	/**
	 * Reads or replaces the default asset policy used by render and planning operations.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param array|AssetPolicy $assetPolicy Asset loading policy definition or immutable policy object.
	 * @return void The manager option is replaced for subsequent operations.
	 */
	public static function setAssetPolicy(array|AssetPolicy $assetPolicy): void {
		self::manager()->setAssetPolicy($assetPolicy);
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param bool $strictMode Whether unresolved data and contract mismatches should fail strictly.
	 * @return void The manager option is replaced for subsequent operations.
	 */
	public static function setStrictMode(bool $strictMode): void {
		self::manager()->setStrictMode($strictMode);
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
	public static function registerContract(string $templateFile, array|TemplateContract $contract): void {
		self::manager()->registerContract($templateFile, $contract);
	}

	/**
	 * Coordinates templating state, data binding, rendering, assets, or diagnostics.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return ?string Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function resolveComponentTemplate(string $reference): ?string {
		return self::manager()->resolveComponentTemplate($reference);
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
	public static function registerComponentContract(string $reference, array|TemplateContract $contract): void {
		self::manager()->registerComponentContract($reference, $contract);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $templateFile Template file path resolved by the templating kernel.
	 * @return ?TemplateContract Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function contract(string $templateFile): ?TemplateContract {
		return self::manager()->contract($templateFile);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return ?TemplateContract Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function componentContract(string $reference): ?TemplateContract {
		return self::manager()->componentContract($reference);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param ?string $templateFile Template file path resolved by the templating kernel.
	 * @return void The in-memory state is cleared in place.
	 */
	public static function clearContract(?string $templateFile=null): void {
		self::manager()->clearContract($templateFile);
	}

	/**
	 * Registers, resolves, or clears template and component contracts.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param string $reference Component reference resolved through component mapping.
	 * @return void The in-memory state is cleared in place.
	 */
	public static function clearComponentContract(string $reference): void {
		self::manager()->clearComponentContract($reference);
	}

	/**
	 * Converts design token arrays into CSS custom property declarations.
	 *
	 * The public facade delegates to TemplatingManager; manager internals keep cache identity, persistent cache, trace ids, guardrails, and binding warnings consistent across render and inspect paths.
	 *
	 * @param array<string,scalar|null> $values Token map converted into CSS custom property declarations.
	 * @param bool $spacing Whether output CSS should include indentation and line breaks.
	 * @return string Templating value object, binding, render artifact, or manager result for the requested operation.
	 */
	public static function adapt(array $values, bool $spacing=false): string {
		return \dataphyre\templating::adapt($values, $spacing);
	}
}
