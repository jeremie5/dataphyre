<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplatingContext {

	public function __construct(
		private TemplatingManager $manager,
		private array $overrides=[]
	){}

	public function state(): TemplatingState {
		return $this->manager->state($this->overrides);
	}

	public function withDevMode(bool $is_dev_mode): self {
		$clone=clone $this;
		$clone->overrides['is_dev_mode']=$is_dev_mode;
		return $clone;
	}

	public function withCacheDir(string $cache_dir): self {
		$clone=clone $this;
		$clone->overrides['cache_dir']=$cache_dir;
		return $clone;
	}

	public function withStrictMode(bool $strict_mode): self {
		$clone=clone $this;
		$clone->overrides['strict_mode']=$strict_mode;
		return $clone;
	}

	public function withAssetPolicy(array|AssetPolicy $asset_policy): self {
		$clone=clone $this;
		$clone->overrides['asset_policy']=$asset_policy instanceof AssetPolicy ? $asset_policy->toArray() : $asset_policy;
		return $clone;
	}

	public function withBindingGuardrails(array|bool $binding_guardrails): self {
		$clone=clone $this;
		$clone->overrides['binding_guardrails']=$binding_guardrails;
		return $clone;
	}

	public function withGlobals(array $globals): self {
		$clone=clone $this;
		$clone->overrides['global_context']=array_replace($clone->overrides['global_context'] ?? [], $globals);
		return $clone;
	}

	public function withGlobal(string $key, mixed $value): self {
		return $this->withGlobals([$key=>$value]);
	}

	public function withTemplateContract(string $template_file, array|TemplateContract $contract): self {
		$clone=clone $this;
		$clone->overrides['template_contracts']=array_replace(
			$clone->overrides['template_contracts'] ?? [],
			[$template_file=>$contract instanceof TemplateContract ? $contract->toArray() : $contract]
		);
		return $clone;
	}

	public function withComponentContract(string $reference, array|TemplateContract $contract): self {
		$template=$this->manager->resolveComponentTemplate($reference);
		if($template===null){
			throw new \RuntimeException("Component not found: {$reference}");
		}
		return $this->withTemplateContract($template, $contract);
	}

	public function template(string $template_file): TemplateView {
		return $this->manager->template($template_file, $this->overrides);
	}

	public function component(string $reference): TemplateView {
		return $this->manager->component($reference, $this->overrides);
	}

	public function source(string $template, string $template_name='inline.tpl'): TemplateView {
		return $this->manager->source($template, $template_name, $this->overrides);
	}

	public function binding(callable $resolver, ?string $name=null): CallableBinding {
		return $this->manager->binding($resolver, $name);
	}

	public function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return $this->manager->cachedBinding($binding, $identity, $name);
	}

	public function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return $this->manager->rememberBinding($binding, $identity, $ttl, $names, $name);
	}

	public function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return $this->manager->whenBinding($binding, $condition, $default);
	}

	public function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return $this->manager->unlessBinding($binding, $condition, $default);
	}

	public function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->manager->queryBinding($query, $mode, $options);
	}

	public function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->manager->queryBindingInheritingIdentity($query, $mode, $options);
	}

	public function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->manager->searchBinding($query, $mode, $options);
	}

	public function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->manager->searchBindingInheritingIdentity($query, $mode, $options);
	}

	public function render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): RenderedTemplate {
		return $this->manager->render($template_file, $data, $theme_values, $slots, $this->overrides);
	}

	public function plan(string $template_file): TemplatePlan {
		return $this->manager->plan($template_file, $this->overrides);
	}

	public function assetManifest(string $template_file): AssetManifest {
		return $this->manager->assetManifest($template_file, $this->overrides);
	}

	public function inspect(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): RenderedTemplate {
		return $this->manager->inspect($template_file, $data, $theme_values, $slots, $this->overrides);
	}

	public function renderString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): RenderedTemplate {
		return $this->manager->renderString($template, $data, $theme_values, $slots, $template_name, $this->overrides);
	}

	public function inspectString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): RenderedTemplate {
		return $this->manager->inspectString($template, $data, $theme_values, $slots, $template_name, $this->overrides);
	}

	public function planString(string $template, string $template_name='inline.tpl'): TemplatePlan {
		return $this->manager->planString($template, $template_name, $this->overrides);
	}

	public function assetManifestString(string $template, string $template_name='inline.tpl'): AssetManifest {
		return $this->manager->assetManifestString($template, $template_name, $this->overrides);
	}

	public function asyncRender(string $template_file, array $data=[]): object {
		return $this->manager->asyncRender($template_file, $data, $this->overrides);
	}

	public function clearBindingCache(string ...$names): int {
		return $this->manager->withStateOverrides(
			$this->overrides,
			fn(): int => $this->manager->clearBindingCache(...$names)
		);
	}
}
