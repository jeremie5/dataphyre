<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class Templating {

	public static function manager(): TemplatingManager {
		return TemplatingManager::instance();
	}

	public static function flush(): void {
		TemplatingManager::flush();
	}

	public static function state(): TemplatingState {
		return self::manager()->state();
	}

	public static function context(
		?bool $is_dev_mode=null,
		?string $cache_dir=null,
		?array $global_context=null,
		?bool $strict_mode=null,
		array|AssetPolicy|null $asset_policy=null
	): TemplatingContext {
		return self::manager()->context($is_dev_mode, $cache_dir, $global_context, $strict_mode, $asset_policy);
	}

	public static function template(string $template_file): TemplateView {
		return self::manager()->template($template_file);
	}

	public static function component(string $reference): TemplateView {
		return self::manager()->component($reference);
	}

	public static function source(string $template, string $template_name='inline.tpl'): TemplateView {
		return self::manager()->source($template, $template_name);
	}

	public static function binding(callable $resolver, ?string $name=null): CallableBinding {
		return self::manager()->binding($resolver, $name);
	}

	public static function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return self::manager()->cachedBinding($binding, $identity, $name);
	}

	public static function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return self::manager()->rememberBinding($binding, $identity, $ttl, $names, $name);
	}

	public static function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return self::manager()->whenBinding($binding, $condition, $default);
	}

	public static function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return self::manager()->unlessBinding($binding, $condition, $default);
	}

	public static function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return self::manager()->queryBinding($query, $mode, $options);
	}

	public static function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return self::manager()->queryBindingInheritingIdentity($query, $mode, $options);
	}

	public static function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return self::manager()->searchBinding($query, $mode, $options);
	}

	public static function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return self::manager()->searchBindingInheritingIdentity($query, $mode, $options);
	}

	public static function render(
		string $template_file,
		array $data=[],
		array $theme_values=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->render($template_file, $data, $theme_values, $slots);
	}

	public static function plan(string $template_file): TemplatePlan {
		return self::manager()->plan($template_file);
	}

	public static function assetManifest(string $template_file): AssetManifest {
		return self::manager()->assetManifest($template_file);
	}

	public static function inspect(
		string $template_file,
		array $data=[],
		array $theme_values=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->inspect($template_file, $data, $theme_values, $slots);
	}

	public static function renderString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): RenderedTemplate {
		return self::manager()->renderString($template, $data, $theme_values, $slots, $template_name);
	}

	public static function inspectString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): RenderedTemplate {
		return self::manager()->inspectString($template, $data, $theme_values, $slots, $template_name);
	}

	public static function planString(string $template, string $template_name='inline.tpl'): TemplatePlan {
		return self::manager()->planString($template, $template_name);
	}

	public static function assetManifestString(string $template, string $template_name='inline.tpl'): AssetManifest {
		return self::manager()->assetManifestString($template, $template_name);
	}

	public static function renderWithFallback(
		string $template_file,
		string $fallback_template,
		array $data=[],
		array $theme_values=[],
		array $slots=[]
	): RenderedTemplate {
		return self::manager()->renderWithFallback($template_file, $fallback_template, $data, $theme_values, $slots);
	}

	public static function asyncRender(string $template_file, array $data=[]): object {
		return self::manager()->asyncRender($template_file, $data);
	}

	public static function registerTag(string $tag, callable $callback): void {
		self::manager()->registerTag($tag, $callback);
	}

	public static function registerFilter(string $filter, callable $callback): void {
		self::manager()->registerFilter($filter, $callback);
	}

	public static function registerExtension(string $name, callable $extension): void {
		self::manager()->registerExtension($name, $extension);
	}

	public static function registerHelper(string $name, callable $helper): void {
		self::manager()->registerHelper($name, $helper);
	}

	public static function on(string $event, callable $callback): void {
		self::manager()->registerEventHook($event, $callback);
	}

	public static function before(callable $hook): void {
		self::manager()->registerPreprocessingHook($hook);
	}

	public static function after(callable $hook): void {
		self::manager()->registerPostprocessingHook($hook);
	}

	public static function addGlobal(string $key, mixed $value): void {
		self::manager()->addGlobal($key, $value);
	}

	public static function globals(): array {
		return self::manager()->globals();
	}

	public static function clearGlobals(): void {
		self::manager()->clearGlobals();
	}

	public static function clearBindingCache(string ...$names): int {
		return self::manager()->clearBindingCache(...$names);
	}

	public static function assetPolicy(): AssetPolicy {
		return self::manager()->assetPolicy();
	}

	public static function setAssetPolicy(array|AssetPolicy $asset_policy): void {
		self::manager()->setAssetPolicy($asset_policy);
	}

	public static function setStrictMode(bool $strict_mode): void {
		self::manager()->setStrictMode($strict_mode);
	}

	public static function registerContract(string $template_file, array|TemplateContract $contract): void {
		self::manager()->registerContract($template_file, $contract);
	}

	public static function resolveComponentTemplate(string $reference): ?string {
		return self::manager()->resolveComponentTemplate($reference);
	}

	public static function registerComponentContract(string $reference, array|TemplateContract $contract): void {
		self::manager()->registerComponentContract($reference, $contract);
	}

	public static function contract(string $template_file): ?TemplateContract {
		return self::manager()->contract($template_file);
	}

	public static function componentContract(string $reference): ?TemplateContract {
		return self::manager()->componentContract($reference);
	}

	public static function clearContract(?string $template_file=null): void {
		self::manager()->clearContract($template_file);
	}

	public static function clearComponentContract(string $reference): void {
		self::manager()->clearComponentContract($reference);
	}

	public static function adapt(array $values, bool $spacing=false): string {
		return \dataphyre\templating::adapt($values, $spacing);
	}
}
