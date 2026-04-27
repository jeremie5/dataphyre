<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Templating;

final class TemplatingManager {

	private const DEFAULT_BINDING_GUARDRAILS=[
		'enabled'=>true,
		'warn_slow'=>true,
		'slow_ms'=>50.0,
		'warn_unused'=>true,
		'warn_duplicate_targets'=>true,
	];

	private static ?self $instance=null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function flush(): void {
		self::$instance=null;
	}

	public function binding(callable $resolver, ?string $name=null): CallableBinding {
		return CallableBinding::make($resolver, $name);
	}

	public function cachedBinding(DataBinding|callable $binding, string|array|callable $identity, ?string $name=null): CachedBinding {
		return CachedBinding::make($binding, $identity, $name);
	}

	public function rememberBinding(
		DataBinding|callable $binding,
		string|array|callable|null $identity=null,
		int $ttl=300,
		array|string $names=[],
		?string $name=null
	): RememberedBinding {
		return RememberedBinding::make($binding, $identity, $ttl, $names, $name);
	}

	public function whenBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return ConditionalBinding::when($binding, $condition, $default);
	}

	public function unlessBinding(DataBinding|callable $binding, bool|callable $condition, mixed $default=null): ConditionalBinding {
		return ConditionalBinding::unless($binding, $condition, $default);
	}

	public function queryBinding(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return SqlQueryBinding::make($query, $mode, $options);
	}

	public function queryBindingInheritingIdentity(object $query, string $mode='records', array $options=[]): SqlQueryBinding {
		return $this->queryBinding($query, $mode, $options)->inheritIdentity();
	}

	public function searchBinding(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return SearchQueryBinding::make($query, $mode, $options);
	}

	public function searchBindingInheritingIdentity(object $query, string $mode='results', array $options=[]): SearchQueryBinding {
		return $this->searchBinding($query, $mode, $options)->inheritIdentity();
	}

	public function state(array $overrides=[]): TemplatingState {
		return TemplatingState::fromArray(array_replace(
			\dataphyre\templating::state(),
			$this->filterStateOverrides($overrides)
		));
	}

	public function context(
		?bool $is_dev_mode=null,
		?string $cache_dir=null,
		?array $global_context=null,
		?bool $strict_mode=null,
		array|AssetPolicy|null $asset_policy=null
	): TemplatingContext {
		return new TemplatingContext($this, array_filter([
			'is_dev_mode'=>$is_dev_mode,
			'cache_dir'=>$cache_dir,
			'global_context'=>$global_context,
			'strict_mode'=>$strict_mode,
			'asset_policy'=>$asset_policy instanceof AssetPolicy ? $asset_policy->toArray() : $asset_policy,
		], static fn(mixed $value): bool => $value!==null));
	}

	public function template(string $template_file, array $overrides=[]): TemplateView {
		return new TemplateView($this, $template_file, null, false, [], [], [], $overrides);
	}

	public function component(string $reference, array $overrides=[]): TemplateView {
		$template=$this->resolveComponentTemplate($reference);
		if($template===null){
			throw new \RuntimeException("Component not found: {$reference}");
		}
		return new TemplateView($this, $template, null, false, [], [], [], $overrides);
	}

	public function source(string $template, string $template_name='inline.tpl', array $overrides=[]): TemplateView {
		return new TemplateView($this, $template_name, $template, true, [], [], [], $overrides);
	}

	public function render(
		string $template_file,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template_file, $data, $theme_values, $slots, $overrides): array {
			$prepared=$this->prepareBindingData($template_file, false, $data, $theme_values, $slots, $overrides);
			$plan=\dataphyre\templating::plan($template_file);
			$binding_planner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'content'=>(string)\dataphyre\templating::render($template_file, $prepared['data'], $theme_values, $slots),
				'asset_manifest'=>\dataphyre\templating::asset_manifest($template_file),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$binding_planner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			$template_file,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$theme_values,
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

	public function plan(string $template_file, array $overrides=[]): TemplatePlan {
		$plan=$this->withStateOverrides($overrides, static function() use($template_file): array {
			return \dataphyre\templating::plan($template_file);
		});
		return TemplatePlan::fromArray($plan);
	}

	public function assetManifest(string $template_file, array $overrides=[]): AssetManifest {
		$manifest=$this->withStateOverrides($overrides, static function() use($template_file): array {
			return \dataphyre\templating::asset_manifest($template_file);
		});
		return AssetManifest::fromArray($manifest);
	}

	public function inspect(
		string $template_file,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template_file, $data, $theme_values, $slots, $overrides): array {
			$prepared=$this->prepareBindingData($template_file, false, $data, $theme_values, $slots, $overrides);
			$plan=\dataphyre\templating::plan($template_file);
			$inspection=\dataphyre\templating::inspect($template_file, $prepared['data'], $theme_values, $slots);
			$binding_warnings=$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides);
			$binding_planner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			if(is_array($inspection['manifest'] ?? null)){
				$inspection['manifest']=$this->mergeBindingManifest($inspection['manifest'], $prepared['bindings'], $binding_warnings, $binding_planner, $prepared['render_trace_id']);
			}
			return [
				'inspection'=>$inspection,
				'asset_manifest'=>\dataphyre\templating::asset_manifest($template_file),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$binding_warnings,
				'binding_planner'=>$binding_planner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		$inspection=is_array($result['inspection'] ?? null) ? $result['inspection'] : [];
		return new RenderedTemplate(
			(string)($inspection['content'] ?? ''),
			$template_file,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$theme_values,
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

	public function renderString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl',
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template, $data, $theme_values, $slots, $template_name, $overrides): array {
			$prepared=$this->prepareBindingData($template_name, true, $data, $theme_values, $slots, $overrides);
			$plan=\dataphyre\templating::plan_string($template, $template_name);
			$binding_planner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'content'=>\dataphyre\templating::render_string($template, $prepared['data'], $theme_values, $slots, $template_name),
				'asset_manifest'=>\dataphyre\templating::asset_manifest_string($template, $template_name),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$binding_planner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			$template_name,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$theme_values,
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

	public function inspectString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl',
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template, $data, $theme_values, $slots, $template_name, $overrides): array {
			$prepared=$this->prepareBindingData($template_name, true, $data, $theme_values, $slots, $overrides);
			$plan=\dataphyre\templating::plan_string($template, $template_name);
			$inspection=\dataphyre\templating::inspect_string($template, $prepared['data'], $theme_values, $slots, $template_name);
			$binding_warnings=$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides);
			$binding_planner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			if(is_array($inspection['manifest'] ?? null)){
				$inspection['manifest']=$this->mergeBindingManifest($inspection['manifest'], $prepared['bindings'], $binding_warnings, $binding_planner, $prepared['render_trace_id']);
			}
			return [
				'inspection'=>$inspection,
				'asset_manifest'=>\dataphyre\templating::asset_manifest_string($template, $template_name),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$binding_warnings,
				'binding_planner'=>$binding_planner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		$inspection=is_array($result['inspection'] ?? null) ? $result['inspection'] : [];
		return new RenderedTemplate(
			(string)($inspection['content'] ?? ''),
			$template_name,
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$theme_values,
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

	public function planString(string $template, string $template_name='inline.tpl', array $overrides=[]): TemplatePlan {
		$plan=$this->withStateOverrides($overrides, static function() use($template, $template_name): array {
			return \dataphyre\templating::plan_string($template, $template_name);
		});
		return TemplatePlan::fromArray($plan);
	}

	public function assetManifestString(string $template, string $template_name='inline.tpl', array $overrides=[]): AssetManifest {
		$manifest=$this->withStateOverrides($overrides, static function() use($template, $template_name): array {
			return \dataphyre\templating::asset_manifest_string($template, $template_name);
		});
		return AssetManifest::fromArray($manifest);
	}

	public function renderWithFallback(
		string $template_file,
		string $fallback_template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		array $overrides=[]
	): RenderedTemplate {
		$result=$this->withStateOverrides($overrides, function() use($template_file, $fallback_template, $data, $theme_values, $slots, $overrides): array {
			$selected_template=(is_file($template_file) && is_readable($template_file)) ? $template_file : $fallback_template;
			$prepared=$this->prepareBindingData($selected_template, false, $data, $theme_values, $slots, $overrides);
			$plan=\dataphyre\templating::plan($selected_template);
			$binding_planner=$this->bindingPlannerForPlan($plan, $prepared['bindings']);
			return [
				'selected_template'=>$selected_template,
				'content'=>\dataphyre\templating::render($selected_template, $prepared['data'], $theme_values, $slots),
				'asset_manifest'=>\dataphyre\templating::asset_manifest($selected_template),
				'bindings'=>$prepared['bindings'],
				'binding_warnings'=>$this->bindingWarningsForPlan($plan, $prepared['bindings'], $overrides),
				'binding_planner'=>$binding_planner,
				'data'=>$prepared['data'],
				'render_trace_id'=>$prepared['render_trace_id'],
			];
		});
		return new RenderedTemplate(
			(string)($result['content'] ?? ''),
			(string)($result['selected_template'] ?? $template_file),
			is_array($result['data'] ?? null) ? $result['data'] : $data,
			$theme_values,
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

	public function asyncRender(string $template_file, array $data=[], array $overrides=[]): object {
		return $this->withStateOverrides($overrides, function() use($template_file, $data, $overrides): object {
			$prepared=$this->prepareBindingData($template_file, false, $data, [], [], $overrides);
			return \dataphyre\templating::async_render($template_file, $prepared['data']);
		});
	}

	public function asyncRenderString(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl',
		array $overrides=[]
	): object {
		return $this->withStateOverrides($overrides, function() use($template, $data, $theme_values, $slots, $template_name, $overrides): object {
			$prepared=$this->prepareBindingData($template_name, true, $data, $theme_values, $slots, $overrides);
			return new \dataphyre\async\promise(static function($resolve, $reject) use($template, $prepared, $theme_values, $slots, $template_name): void {
				try{
					$resolve(json_encode([
						'content'=>\dataphyre\templating::render_string($template, $prepared['data'], $theme_values, $slots, $template_name),
					]));
				}catch(\Throwable $e){
					$reject(json_encode(['error'=>$e->getMessage()]));
				}
			});
		});
	}

	public function registerTag(string $tag, callable $callback): void {
		\dataphyre\templating::register_tag($tag, $callback);
	}

	public function registerFilter(string $filter, callable $callback): void {
		\dataphyre\templating::register_filter($filter, $callback);
	}

	public function registerExtension(string $name, callable $extension): void {
		\dataphyre\templating::register_extension($name, $extension);
	}

	public function registerHelper(string $name, callable $helper): void {
		\dataphyre\templating::register_helper($name, $helper);
	}

	public function registerEventHook(string $event, callable $callback): void {
		\dataphyre\templating::register_event_hook($event, $callback);
	}

	public function registerPreprocessingHook(callable $hook): void {
		\dataphyre\templating::register_preprocessing_hook($hook);
	}

	public function registerPostprocessingHook(callable $hook): void {
		\dataphyre\templating::register_postprocessing_hook($hook);
	}

	public function addGlobal(string $key, mixed $value): void {
		\dataphyre\templating::add_to_global_context($key, $value);
	}

	public function globals(): array {
		return \dataphyre\templating::global_context();
	}

	public function clearGlobals(): void {
		\dataphyre\templating::clear_global_context();
	}

	public function clearBindingCache(string ...$names): int {
		$cache_dir=$this->bindingPersistentCacheRoot();
		$items_dir=$cache_dir.'items'.DIRECTORY_SEPARATOR;
		$names_dir=$cache_dir.'names'.DIRECTORY_SEPARATOR;
		if($names===[]){
			return $this->clearPersistentBindingCacheDirectories($items_dir, $names_dir);
		}

		$deleted=0;
		foreach($this->normalizeBindingCacheNames($names) as $name){
			$name_file=$this->bindingPersistentCacheNameFile($name, $names_dir);
			if(!is_file($name_file)){
				continue;
			}
			$payload=@file_get_contents($name_file);
			$keys=json_decode(is_string($payload) ? $payload : '[]', true);
			if(is_array($keys)){
				foreach($keys as $key){
					if(!is_string($key) || $key===''){
						continue;
					}
					$item_file=$items_dir.$key.'.cache';
					if(is_file($item_file) && @unlink($item_file)){
						$deleted++;
					}
				}
			}
			@unlink($name_file);
		}
		return $deleted;
	}

	public function assetPolicy(): AssetPolicy {
		return AssetPolicy::fromArray(\dataphyre\templating::asset_policy());
	}

	public function setAssetPolicy(array|AssetPolicy $asset_policy): void {
		\dataphyre\templating::set_asset_policy(
			$asset_policy instanceof AssetPolicy ? $asset_policy->toArray() : $asset_policy
		);
	}

	public function setStrictMode(bool $strict_mode): void {
		\dataphyre\templating::set_strict_mode($strict_mode);
	}

	public function registerContract(string $template_file, array|TemplateContract $contract): void {
		\dataphyre\templating::register_template_contract(
			$template_file,
			$contract instanceof TemplateContract ? $contract->toArray() : $contract
		);
	}

	public function resolveComponentTemplate(string $reference): ?string {
		return \dataphyre\templating::resolve_component_template($reference);
	}

	public function registerComponentContract(string $reference, array|TemplateContract $contract): void {
		\dataphyre\templating::register_component_contract(
			$reference,
			$contract instanceof TemplateContract ? $contract->toArray() : $contract
		);
	}

	public function contract(string $template_file): ?TemplateContract {
		$contract=\dataphyre\templating::template_contract($template_file);
		return is_array($contract) ? TemplateContract::fromArray($contract) : null;
	}

	public function componentContract(string $reference): ?TemplateContract {
		$contract=\dataphyre\templating::component_contract($reference);
		return is_array($contract) ? TemplateContract::fromArray($contract) : null;
	}

	public function clearContract(?string $template_file=null): void {
		\dataphyre\templating::clear_template_contract($template_file);
	}

	public function clearComponentContract(string $reference): void {
		\dataphyre\templating::clear_component_contract($reference);
	}

	public function withStateOverrides(array $overrides, callable $callback): mixed {
		$overrides=$this->filterStateOverrides($overrides);
		if($overrides===[]){
			return $callback();
		}

		$original_state=\dataphyre\templating::state();
		if(isset($overrides['template_contracts']) && is_array($overrides['template_contracts'])){
			$overrides['template_contracts']=array_replace(
				is_array($original_state['template_contracts'] ?? null) ? $original_state['template_contracts'] : [],
				$overrides['template_contracts']
			);
		}
		\dataphyre\templating::apply_state($overrides);
		try{
			return $callback();
		} finally {
			\dataphyre\templating::apply_state($original_state);
		}
	}

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

	private function prepareBindingData(
		string $template_name,
		bool $inline,
		array $data,
		array $theme_values=[],
		array $slots=[],
		array $overrides=[]
	): array {
		$tracing_enabled=$this->tracingEnabled()===true;
		$render_trace_id=$tracing_enabled ? $this->newTraceId('tpl') : null;
		$context=new BindingContext(
			$template_name,
			$inline,
			$data,
			$theme_values,
			$slots,
			$this->filterStateOverrides($overrides),
			$tracing_enabled && $render_trace_id!==null ? ['render_trace_id'=>$render_trace_id] : []
		);
		$bindings=[];
		$binding_cache=[];
		$trace_state=[
			'enabled'=>$tracing_enabled,
			'render_trace_id'=>$render_trace_id,
			'sequence'=>0,
		];
		$resolved=$this->resolveBindingValue($data, '', $context, $bindings, $binding_cache, $trace_state);
		return [
			'data'=>is_array($resolved) ? $resolved : $data,
			'bindings'=>$bindings,
			'render_trace_id'=>$render_trace_id,
		];
	}

	private function resolveBindingValue(mixed $value, string $path, BindingContext $context, array &$bindings, array &$binding_cache, array &$trace_state): mixed {
		if($value instanceof DataBinding){
			$tracing_enabled=($trace_state['enabled'] ?? $this->tracingEnabled())===true;
			$started=microtime(true);
			$metadata=$value instanceof BindingMetadataProvider ? $value->metadata() : [];
			$render_cache=$this->bindingCacheDescriptor($value, $context);
			$persistent_cache=$this->bindingPersistentCacheDescriptor($value, $context, $render_cache);
			$binding_trace_id=$tracing_enabled ? $this->nextBindingTraceId($trace_state) : null;
			$binding_context=$tracing_enabled
				? $context->withTraceContext([
					'binding_trace_id'=>$binding_trace_id,
					'binding_path'=>$path,
					'binding_name'=>$value->name(),
				])
				: $context;
			if(($render_cache['cacheable'] ?? false)===true && array_key_exists((string)$render_cache['cache_key'], $binding_cache)){
				$resolved=$binding_cache[(string)$render_cache['cache_key']]['value'] ?? null;
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$binding_context->renderTraceId(),
					'binding_trace_id'=>$binding_context->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>false,
					'reused'=>true,
					'result_type'=>get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($render_cache, $persistent_cache, 'render', 'hit', [
					'cache_layer'=>'render',
				])));
				return $resolved;
			}
			$persistent_hit=$this->loadPersistentBindingValue($persistent_cache, $context);
			if(($persistent_hit['hit'] ?? false)===true){
				$resolved=$persistent_hit['value'] ?? null;
				if(($render_cache['cacheable'] ?? false)===true){
					$binding_cache[(string)$render_cache['cache_key']]=[
						'value'=>$resolved,
					];
				}
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$binding_context->renderTraceId(),
					'binding_trace_id'=>$binding_context->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>false,
					'reused'=>false,
					'result_type'=>get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($render_cache, $persistent_cache, 'persistent', 'hit', [
					'cache_layer'=>'persistent',
				])));
				return $resolved;
			}
			try{
				$resolved=$this->resolveBindingWithTraceContext($value, $binding_context, $metadata);
				$skipped=false;
				if($resolved instanceof BindingResolution){
					$skipped=$resolved->isSkipped();
					$resolved=$resolved->result();
				}
				if($skipped!==true){
					$resolved=$this->resolveBindingValue($resolved, $path, $binding_context, $bindings, $binding_cache, $trace_state);
				}
				if(($render_cache['cacheable'] ?? false)===true && $skipped!==true){
					$binding_cache[(string)$render_cache['cache_key']]=[
						'value'=>$resolved,
					];
				}
				$cache_scope='none';
				$cache_state='bypass';
				$cache_layer='none';
				$cache_store_error=null;
				if($skipped!==true && ($persistent_cache['cacheable'] ?? false)===true){
					$cache_store_error=$this->storePersistentBindingValue($persistent_cache, $context, $resolved);
					if($cache_store_error===null){
						$cache_scope='persistent';
						$cache_state='store';
						$cache_layer='persistent';
					}
					elseif(($render_cache['cacheable'] ?? false)===true){
						$cache_scope='render';
						$cache_state='miss';
						$cache_layer='render';
					}
				}
				elseif(($render_cache['cacheable'] ?? false)===true && $skipped!==true){
					$cache_scope='render';
					$cache_state='miss';
					$cache_layer='render';
				}
				$bindings[]=$this->stitchBindingTrace(array_replace([
					'path'=>$path,
					'binding'=>$value->name(),
					'render_trace_id'=>$binding_context->renderTraceId(),
					'binding_trace_id'=>$binding_context->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>true,
					'skipped'=>$skipped,
					'reused'=>false,
					'result_type'=>$skipped
						? ($resolved===null ? 'skipped' : 'skipped('.get_debug_type($resolved).')')
						: get_debug_type($resolved),
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
				], $metadata, $this->bindingCacheMetadata($render_cache, $persistent_cache, $cache_scope, $cache_state, [
					'cache_layer'=>$cache_layer,
					'cache_store_error'=>$cache_store_error,
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
					'render_trace_id'=>$binding_context->renderTraceId(),
					'binding_trace_id'=>$binding_context->bindingTraceId(),
					'class'=>$value::class,
					'ok'=>false,
					'reused'=>false,
					'result_type'=>'error',
					'duration_ms'=>round((microtime(true)-$started)*1000, 3),
					'error'=>[
						'type'=>$e::class,
						'message'=>$e->getMessage(),
					],
				], $metadata, $this->bindingCacheMetadata($render_cache, $persistent_cache, 'none', 'bypass', [
					'cache_layer'=>'none',
				])));
				return null;
			}
		}

		if(is_array($value)){
			$resolved=[];
			foreach($value as $key=>$item){
				$item_path=$this->bindingPath($path, $key);
				$resolved[$key]=$this->resolveBindingValue($item, $item_path, $context, $bindings, $binding_cache, $trace_state);
			}
			return $resolved;
		}

		return $value;
	}

	private function bindingPath(string $parent, string|int $segment): string {
		$segment=(string)$segment;
		return $parent==='' ? $segment : $parent.'.'.$segment;
	}

	private function mergeBindingManifest(
		array $manifest,
		array $bindings,
		array $binding_warnings=[],
		array $binding_planner=[],
		?string $render_trace_id=null
	): array {
		$manifest['bindings']=$bindings;
		$manifest['render_trace_id']=$this->tracingEnabled()===true
			? ($render_trace_id ?? $this->firstBindingRenderTraceId($bindings))
			: null;
		$manifest['binding_trace']=$this->tracingEnabled()===true
			? array_values(array_filter(array_map(
				static fn(array $binding): array => is_array($binding['trace'] ?? null) ? $binding['trace'] : [],
				$bindings
			), static fn(array $trace): bool => $trace!==[]))
			: [];
		$manifest['binding_errors']=array_values(array_filter($bindings, static fn(array $binding): bool => ($binding['ok'] ?? true)!==true));
		$manifest['binding_warnings']=$binding_warnings;
		$manifest['binding_planner']=$binding_planner;
		return $manifest;
	}

	private function bindingWarningsForPlan(array $plan, array $bindings, array $overrides=[]): array {
		$guardrails=$this->resolvedBindingGuardrails($overrides);
		if(($guardrails['enabled'] ?? true)!==true || $bindings===[]){
			return [];
		}

		$warnings=[];
		$executed=array_values(array_filter($bindings, static fn(array $binding): bool => ($binding['ok'] ?? false)===true && ($binding['skipped'] ?? false)!==true));
		$data_paths=$this->planDataPaths($plan);

		if(($guardrails['warn_slow'] ?? true)===true){
			$slow_ms=(float)($guardrails['slow_ms'] ?? self::DEFAULT_BINDING_GUARDRAILS['slow_ms']);
			foreach($executed as $binding){
				$duration=(float)($binding['duration_ms'] ?? 0.0);
				if($duration < $slow_ms){
					continue;
				}
				$warnings[]=[
					'type'=>'slow_binding',
					'path'=>(string)($binding['path'] ?? ''),
					'binding'=>(string)($binding['binding'] ?? 'binding'),
					'duration_ms'=>$duration,
					'threshold_ms'=>$slow_ms,
					'message'=>"Binding '".((string)($binding['path'] ?? '') ?: (string)($binding['binding'] ?? 'binding'))."' took {$duration}ms to resolve.",
				];
			}
		}

		if(($guardrails['warn_unused'] ?? true)===true){
			foreach($executed as $binding){
				$path=trim((string)($binding['path'] ?? ''));
				if($path==='' || $this->bindingPathIsUsed($path, $data_paths)){
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

	private function bindingPlannerForPlan(array $plan, array $bindings): array {
		if($bindings===[]){
			return [];
		}

		$suggestions=[];
		$data_paths=$this->planDataPaths($plan);
		foreach($bindings as $binding){
			if(($binding['ok'] ?? false)!==true || ($binding['skipped'] ?? false)===true){
				continue;
			}
			$type=(string)($binding['type'] ?? '');
			if(!in_array($type, ['sql_query', 'search_query'], true)){
				continue;
			}
			$path=trim((string)($binding['path'] ?? ''));
			if($path!=='' && !$this->bindingPathIsUsed($path, $data_paths)){
				continue;
			}
			$query_fingerprint=trim((string)($binding['query_fingerprint'] ?? ''));
			$query_identity_source=trim((string)($binding['query_identity_source'] ?? 'execution_state'));
			if($query_fingerprint==='' || $query_identity_source==='fingerprint'){
				continue;
			}
			$suggestions[]=[
				'type'=>'inherit_query_identity',
				'driver'=>$type,
				'path'=>$path,
				'binding'=>(string)($binding['binding'] ?? 'binding'),
				'target_type'=>(string)($binding['query_target_type'] ?? ''),
				'target'=>(string)($binding['query_target'] ?? ''),
				'query_fingerprint'=>$query_fingerprint,
				'message'=>"Binding '".($path!=='' ? $path : (string)($binding['binding'] ?? 'binding'))."' can inherit its source query fingerprint explicitly for stronger cache and reuse alignment.",
			];
		}
		return $suggestions;
	}

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

	private function bindingPersistentCacheDescriptor(DataBinding $binding, BindingContext $context, array $render_cache=[]): array {
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

		$identity=$config['identity'] ?? ($render_cache['cache_identity'] ?? null);
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

	private function bindingCacheMetadata(
		array $render_cache,
		array $persistent_cache,
		string $scope,
		string $state,
		array $extra=[]
	): array {
		$descriptor=$scope==='persistent' && ($persistent_cache['cacheable'] ?? false)===true
			? $persistent_cache
			: ((($render_cache['cacheable'] ?? false)===true) ? $render_cache : $persistent_cache);

		return array_replace([
			'cacheable'=>(($render_cache['cacheable'] ?? false)===true) || (($persistent_cache['cacheable'] ?? false)===true),
			'persistent_cache'=>(($persistent_cache['cacheable'] ?? false)===true),
			'cache_scope'=>$scope,
			'cache_state'=>$state,
			'cache_key'=>$descriptor['cache_key'] ?? null,
			'cache_identity'=>$descriptor['cache_identity'] ?? null,
			'cache_names'=>$persistent_cache['cache_names'] ?? [],
			'cache_ttl'=>$persistent_cache['cache_ttl'] ?? null,
		], $extra);
	}

	private function stitchBindingTrace(array $binding): array {
		if($this->tracingEnabled()!==true){
			unset($binding['render_trace_id'], $binding['binding_trace_id'], $binding['trace']);
			return $binding;
		}
		$binding['trace']=$this->bindingTracePayload($binding);
		return $binding;
	}

	private function bindingTracePayload(array $binding): array {
		$binding_cache_names=$this->normalizeBindingCacheNames($binding['cache_names'] ?? []);
		$query_cache_names=$this->normalizeBindingCacheNames($binding['query_cache_names'] ?? []);
		$sql_invalidation_names=array_values(array_intersect($binding_cache_names, $query_cache_names));
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
				'binding_cache_names'=>$binding_cache_names,
				'query_cache_names'=>$query_cache_names,
				'sql_invalidation_names'=>$sql_invalidation_names,
			], static fn(mixed $value): bool => $value!==null && $value!==[]),
			'error'=>is_array($binding['error'] ?? null) ? $binding['error'] : null,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function bindingTraceIdentity(array $binding): ?array {
		$query_fingerprint=$this->traceString($binding['query_fingerprint'] ?? null);
		$query_identity_mode=$this->traceString($binding['query_identity_mode'] ?? null);
		$query_identity_source=$this->traceString($binding['query_identity_source'] ?? null);
		$query_identity_requested=($binding['query_identity_requested'] ?? false)===true;
		$query_identity_available=($binding['query_identity_available'] ?? false)===true;
		if(
			$query_fingerprint===null
			&& $query_identity_mode===null
			&& $query_identity_source===null
			&& $query_identity_requested!==true
			&& $query_identity_available!==true
		){
			return null;
		}
		return array_filter([
			'query_fingerprint'=>$query_fingerprint,
			'mode'=>$query_identity_mode,
			'source'=>$query_identity_source,
			'requested'=>$query_identity_requested,
			'available'=>$query_identity_available,
		], static fn(mixed $value): bool => $value!==null && $value!==[]);
	}

	private function traceString(mixed $value): ?string {
		if(!is_string($value)){
			return null;
		}
		$value=trim($value);
		return $value!=='' ? $value : null;
	}

	private function resolveBindingWithTraceContext(DataBinding $binding, BindingContext $context, array $metadata): mixed {
		if($this->tracingEnabled()!==true){
			return $binding->resolve($context);
		}
		if(
			($metadata['driver'] ?? null)==='sql'
			&& class_exists('Dataphyre\\Database\\DB', false)
			&& method_exists('Dataphyre\\Database\\DB', 'withTraceContext')
		){
			$trace_context=$context->traceContext();
			return \Dataphyre\Database\DB::withTraceContext([
				'render_trace_id'=>$context->renderTraceId(),
				'binding_trace_id'=>$context->bindingTraceId(),
				'template_name'=>$context->templateName(),
				'binding_name'=>$binding->name(),
				'binding_path'=>is_string($trace_context['binding_path'] ?? null) ? $trace_context['binding_path'] : null,
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

	private function nextBindingTraceId(array &$trace_state): string {
		$trace_state['sequence']=(int)($trace_state['sequence'] ?? 0)+1;
		$render_trace_id=is_string($trace_state['render_trace_id'] ?? null) ? $trace_state['render_trace_id'] : $this->newTraceId('tpl');
		$trace_state['render_trace_id']=$render_trace_id;
		return $render_trace_id.'.b'.str_pad((string)$trace_state['sequence'], 4, '0', STR_PAD_LEFT);
	}

	private function firstBindingRenderTraceId(array $bindings): ?string {
		foreach($bindings as $binding){
			$render_trace_id=$this->traceString($binding['render_trace_id'] ?? null);
			if($render_trace_id!==null){
				return $render_trace_id;
			}
		}
		return null;
	}

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

	private function tracingEnabled(): bool {
		if(class_exists('Dataphyre\\Runtime', false) && method_exists('Dataphyre\\Runtime', 'tracingEnabled')){
			return \Dataphyre\Runtime::tracingEnabled();
		}
		return !(defined('IS_PRODUCTION') && IS_PRODUCTION===true);
	}

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

	private function normalizeBindingCacheArray(array $identity): array {
		$normalized=[];
		ksort($identity);
		foreach($identity as $key=>$value){
			$normalized[(string)$key]=$this->normalizeBindingCacheValue($value);
		}
		return $normalized;
	}

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

	private function storePersistentBindingValue(array $descriptor, BindingContext $context, mixed $value): ?string {
		if(($descriptor['cacheable'] ?? false)!==true){
			return null;
		}
		$root=$this->bindingPersistentCacheRoot($context);
		$items_dir=$root.'items'.DIRECTORY_SEPARATOR;
		$names_dir=$root.'names'.DIRECTORY_SEPARATOR;
		if(!is_dir($items_dir)){
			@mkdir($items_dir, 0777, true);
		}
		if(!is_dir($names_dir)){
			@mkdir($names_dir, 0777, true);
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
			$this->indexPersistentBindingCacheName($name, (string)($descriptor['cache_key'] ?? ''), $names_dir);
		}
		return null;
	}

	private function bindingPersistentCacheRoot(?BindingContext $context=null): string {
		$overrides=$context?->overrides() ?? [];
		$cache_dir=$overrides['cache_dir'] ?? null;
		if(!is_string($cache_dir) || trim($cache_dir)===''){
			$state=\dataphyre\templating::state();
			$cache_dir=(string)($state['cache_dir'] ?? '');
		}
		return rtrim($cache_dir, '/\\').DIRECTORY_SEPARATOR.'bindings'.DIRECTORY_SEPARATOR;
	}

	private function bindingPersistentCacheItemFile(string $key, string $root): string {
		return $root.'items'.DIRECTORY_SEPARATOR.$key.'.cache';
	}

	private function bindingPersistentCacheNameFile(string $name, string $names_dir): string {
		return $names_dir.sha1($name).'.json';
	}

	private function indexPersistentBindingCacheName(string $name, string $key, string $names_dir): void {
		$file=$this->bindingPersistentCacheNameFile($name, $names_dir);
		$existing=@file_get_contents($file);
		$keys=json_decode(is_string($existing) ? $existing : '[]', true);
		$keys=is_array($keys) ? $keys : [];
		$keys[]=$key;
		$keys=array_values(array_unique(array_filter($keys, static fn(mixed $value): bool => is_string($value) && $value!=='')));
		@file_put_contents($file, json_encode($keys, JSON_PRETTY_PRINT), LOCK_EX);
	}

	private function clearPersistentBindingCacheDirectories(string $items_dir, string $names_dir): int {
		$deleted=0;
		foreach([$items_dir, $names_dir] as $dir){
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
		$root=dirname(rtrim($items_dir, '/\\')).DIRECTORY_SEPARATOR;
		@rmdir($root);
		return $deleted;
	}

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

	private function bindingPersistentCacheEnabled(BindingContext $context): bool {
		$state=$context->overrides();
		if(array_key_exists('is_dev_mode', $state)){
			return (bool)$state['is_dev_mode']!==true;
		}
		$current=\dataphyre\templating::state();
		return (bool)($current['is_dev_mode'] ?? false)!==true;
	}

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

	private function planDataPaths(array $plan): array {
		$aggregate=$plan['aggregate'] ?? [];
		if(is_array($aggregate) && is_array($aggregate['data_paths'] ?? null)){
			return array_values(array_filter(array_map('strval', $aggregate['data_paths']), static fn(string $value): bool => trim($value)!==''));
		}
		$data_paths=$plan['data_paths'] ?? [];
		return is_array($data_paths)
			? array_values(array_filter(array_map('strval', $data_paths), static fn(string $value): bool => trim($value)!==''))
			: [];
	}

	private function bindingPathIsUsed(string $binding_path, array $data_paths): bool {
		foreach($data_paths as $data_path){
			$data_path=trim((string)$data_path);
			if($data_path===''){
				continue;
			}
			if($data_path===$binding_path){
				return true;
			}
			if(str_starts_with($data_path, $binding_path.'.') || str_starts_with($binding_path, $data_path.'.')){
				return true;
			}
		}
		return false;
	}

	private function duplicateBindingTargets(array $bindings): array {
		$groups=[];
		foreach($bindings as $binding){
			$type=(string)($binding['type'] ?? '');
			$target=(string)($binding['query_target'] ?? '');
			$target_type=(string)($binding['query_target_type'] ?? '');
			if($type==='' || $target===''){
				continue;
			}
			$key=$type.'|'.$target_type.'|'.$target;
			$groups[$key][]=$binding;
		}

		$warnings=[];
		foreach($groups as $group){
			if(count($group)<2){
				continue;
			}
			$first=$group[0];
			$target=(string)($first['query_target'] ?? '');
			$target_type=(string)($first['query_target_type'] ?? 'target');
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
				'target_type'=>$target_type,
				'target'=>$target,
				'paths'=>$paths,
				'modes'=>$modes,
				'binding_count'=>count($group),
				'message'=>"Multiple bindings target the same {$target_type} '{$target}'.",
			];
		}

		return $warnings;
	}
}
