<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplateView {

	public function __construct(
		private TemplatingManager $manager,
		private string $template_name,
		private ?string $source=null,
		private bool $inline=false,
		private array $data=[],
		private array $theme_values=[],
		private array $slots=[],
		private array $overrides=[],
		private ?string $fallback_template=null
	){}

	public function withData(array $data): self {
		$clone=clone $this;
		$clone->data=$data;
		return $clone;
	}

	public function withProps(array $props): self {
		return $this->withData($props);
	}

	public function mergeData(array $data): self {
		$clone=clone $this;
		$clone->data=array_replace($clone->data, $data);
		return $clone;
	}

	public function mergeProps(array $props): self {
		return $this->mergeData($props);
	}

	public function withBinding(string $path, DataBinding|callable $binding): self {
		$clone=clone $this;
		self::setArrayValueByPath($clone->data, $path, self::normalizeBinding($binding, $path));
		return $clone;
	}

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

	public function withQuery(string $path, object $query, string $mode='records', array $options=[]): self {
		return $this->withBinding($path, $this->manager->queryBinding($query, $mode, $options));
	}

	public function withQueryIdentity(string $path, object $query, string $mode='records', array $options=[]): self {
		return $this->withBinding($path, $this->manager->queryBindingInheritingIdentity($query, $mode, $options));
	}

	public function withSearch(string $path, object $query, string $mode='results', array $options=[]): self {
		return $this->withBinding($path, $this->manager->searchBinding($query, $mode, $options));
	}

	public function withSearchIdentity(string $path, object $query, string $mode='results', array $options=[]): self {
		return $this->withBinding($path, $this->manager->searchBindingInheritingIdentity($query, $mode, $options));
	}

	public function withBindingWhen(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return $this->withBinding($path, $this->manager->whenBinding($binding, $condition, $default));
	}

	public function withBindingUnless(string $path, DataBinding|callable $binding, bool|callable $condition, mixed $default=null): self {
		return $this->withBinding($path, $this->manager->unlessBinding($binding, $condition, $default));
	}

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

	public function withThemeValues(array $theme_values): self {
		$clone=clone $this;
		$clone->theme_values=$theme_values;
		return $clone;
	}

	public function mergeThemeValues(array $theme_values): self {
		$clone=clone $this;
		$clone->theme_values=array_replace($clone->theme_values, $theme_values);
		return $clone;
	}

	public function withSlots(array $slots): self {
		$clone=clone $this;
		$clone->slots=$slots;
		return $clone;
	}

	public function slot(string $slot, mixed $content): self {
		$clone=clone $this;
		$clone->slots[$slot]=(string)$content;
		return $clone;
	}

	public function withFallback(string $template_file): self {
		$clone=clone $this;
		$clone->fallback_template=$template_file;
		return $clone;
	}

	public function strict(bool $strict_mode=true): self {
		$clone=clone $this;
		$clone->overrides['strict_mode']=$strict_mode;
		return $clone;
	}

	public function withContract(array|TemplateContract $contract): self {
		$clone=clone $this;
		$clone->overrides['template_contracts']=array_replace(
			$clone->overrides['template_contracts'] ?? [],
			[$clone->template_name=>$contract instanceof TemplateContract ? $contract->toArray() : $contract]
		);
		return $clone;
	}

	public function withComponentContract(array|TemplateContract $contract): self {
		return $this->withContract($contract);
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

	public function render(): RenderedTemplate {
		if($this->fallback_template!==null && $this->inline===false){
			return $this->manager->renderWithFallback(
				$this->template_name,
				$this->fallback_template,
				$this->data,
				$this->theme_values,
				$this->slots,
				$this->overrides
			);
		}

		if($this->inline===true){
			return $this->manager->renderString(
				$this->source ?? '',
				$this->data,
				$this->theme_values,
				$this->slots,
				$this->template_name,
				$this->overrides
			);
		}

		return $this->manager->render(
			$this->template_name,
			$this->data,
			$this->theme_values,
			$this->slots,
			$this->overrides
		);
	}

	public function plan(): TemplatePlan {
		if($this->fallback_template!==null && $this->inline===false){
			if(is_file($this->template_name) && is_readable($this->template_name)){
				return $this->manager->plan($this->template_name, $this->overrides);
			}
			return $this->manager->plan($this->fallback_template, $this->overrides);
		}

		if($this->inline===true){
			return $this->manager->planString(
				$this->source ?? '',
				$this->template_name,
				$this->overrides
			);
		}

		return $this->manager->plan($this->template_name, $this->overrides);
	}

	public function assetManifest(): AssetManifest {
		return $this->plan()->assetManifest();
	}

	public function headHtml(): string {
		return $this->assetManifest()->headHtml();
	}

	public function bodyHtml(): string {
		return $this->assetManifest()->bodyHtml();
	}

	public function inspect(): RenderedTemplate {
		if($this->fallback_template!==null && $this->inline===false){
			if(is_file($this->template_name) && is_readable($this->template_name)){
				return $this->manager->inspect(
					$this->template_name,
					$this->data,
					$this->theme_values,
					$this->slots,
					$this->overrides
				);
			}
			return $this->manager->inspect(
				$this->fallback_template,
				$this->data,
				$this->theme_values,
				$this->slots,
				$this->overrides
			);
		}

		if($this->inline===true){
			return $this->manager->inspectString(
				$this->source ?? '',
				$this->data,
				$this->theme_values,
				$this->slots,
				$this->template_name,
				$this->overrides
			);
		}

		return $this->manager->inspect(
			$this->template_name,
			$this->data,
			$this->theme_values,
			$this->slots,
			$this->overrides
		);
	}

	public function content(): string {
		return $this->render()->content();
	}

	public function async(): object {
		if($this->inline===true){
			return $this->manager->asyncRenderString(
				$this->source ?? '',
				$this->data,
				$this->theme_values,
				$this->slots,
				$this->template_name,
				$this->overrides
			);
		}

		return $this->manager->asyncRender(
			$this->template_name,
			$this->data,
			$this->overrides
		);
	}

	private static function normalizeBinding(DataBinding|callable $binding, string $path): DataBinding {
		return $binding instanceof DataBinding ? $binding : CallableBinding::make($binding, $path);
	}

	private static function setArrayValueByPath(array &$target, string $path, mixed $value): void {
		$segments=array_values(array_filter(array_map('trim', explode('.', $path)), static fn(string $segment): bool => $segment!==''));
		if($segments===[]){
			return;
		}
		$current=&$target;
		foreach($segments as $index=>$segment){
			$is_last=$index===count($segments)-1;
			if($is_last){
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
