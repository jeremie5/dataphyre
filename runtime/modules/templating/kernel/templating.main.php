<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

\dp_module_required('templating', 'async');

require(__DIR__."/caching.php");
require(__DIR__."/debugging.php");
require(__DIR__."/seo_accessibility.php");
require(__DIR__."/component_management.php");
require(__DIR__."/conditional_parsing.php");
require(__DIR__."/event_system.php");
require(__DIR__."/form_handling.php");
require(__DIR__."/render_helpers.php");
require(__DIR__."/rendering.php");
require(__DIR__."/parsing.php");

class templating {

    use caching;
	/*
		private static function load_from_cache(string $template_file): ?string {
		private static function save_to_cache(string $template_content, string $template_file): string {
		private static function conditional_cache(string $template, array $data, string $condition): string {
		private static function parse_fragment_cache(string $template): string {
		private static function store_in_cache(string $cache_key, string $content, int $duration): void {
		private static function get_from_cache(string $cache_key) ?string {
	*/
    use debugging;
	/*
		private static function debug(string $template, array $data): string {
		private static function profile_render(string $template_file, array $data): string {
		private static function render_error_template(object $error): string {
		private static function debug_render(string $template_file, array $data): string {
		private static function render_performance_metrics(): void {
	*/
    use seo_accessibility;
	/*
		private static function parse_seo_tags(string $template, array $data): string {
	*/
    use component_management;
	/*
		private static function parse_components(string $template, array $data): string {
		private static function lazy_load_components(string $template, array $data): string {
	*/
    use conditional_parsing;
	/*
		private static function parse_loops(string $template, array $data): string {
		private static function parse_conditionals(string $template, array $data): string {
		private static function parse_inline_conditionals(string $template, array $data): string {
		private static function evaluate_condition(string $expression, array $data): mixed {
		private static function parse_advanced_conditionals(string $template, array $data): string {
	*/
    use event_system;
	/*
		private static $events=[ 'before_render'=>[], 'after_render'=>[], 'on_error'=>[] ];
		public static function register_event_hook(string $event, callable $callback): void {
		private static function trigger_event(string $event, ...$args): void {
		public static function enable_event_system(string $event, array $data): object {
		private static function enable_watch_mode(string $template_file): void {
	*/
    use form_handling;
	/*
		private static function parse_form(string $template, array $data): string {
		private static function parse_form_fields(array $template, array $data): string {
	*/
    use render_helpers;
	/*
		private static function parse_assets(string $template): string {
		public static function apply_helpers(string $template): string {
		private static function apply_pipelines(string $template, array $filters): string {
		public static function register_extension(string $name, callable $extension): void {
	*/
    use rendering;
	/*
		public static function render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
		public static function render_with_fallback(string $template_file, array $data=[], string $fallback_file='fallback.tpl'): string {
		public static function full_render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
		public static function async_render(string $template_file, array $data=[]): object {
	*/
    use parsing;
	/*
		private static function bind_data(string $template, array &$data): string {
		private static function handle_undefined_variables(string $template, array $data): string {
		private static function parse_lazy_load_components(string $template, array $data): string {
		private static function parse_slots(string $template, array $data, array $slots=[]): string {
		private static function parse_scoped_styles(string $template, string $component_name): string {
		private static function parse_dynamic_imports(string $template, array $data): string {
		private static function parse_attributes(string $template, array $data): string {
		private static function parse_layout_inheritance(string $template): string {
		private static function parse_php_blocks(string $template): string {
		private static function parse_debug(string $template, array $data): string {
		private static function parse_partials(string $template, array $data): string {
		private static function replace_placeholders(string $template, array $data): string {
		private static function parse_loop_controls(string $template, array $data): string {
	*/

    private static $cache_dir;
    private static $is_dev_mode=false;
	private static $initialized=false;
    private static $extensions=[];
    private static $global_context=[];
	private static $parent_context=[];
	private static $helpers=[];
	private static $tags=[];
	private static $filters=[];
	private static $preprocessing_hooks=[];
	private static $postprocessing_hooks=[];
	private static $dev_hooks=[];
	private static $prod_hooks=[];
	private static $debug_logs=[];
	private static $current_render_data=[];
	private static $template_stack=[];
	private static $inspection_enabled=false;
	private static $inspection_depth=0;
	private static $active_manifest=[];
	private static $compiled_plan_cache=[];
	private static $strict_mode=false;
	private static $template_contracts=[];
	private static $asset_policy=[];
	
	public function __construct(bool $is_dev_mode=false){
		self::init($is_dev_mode);
	}

	public static function init(bool $is_dev_mode=false, ?string $cache_dir=null, ?bool $strict_mode=null, ?array $asset_policy=null): void {
		self::$helpers=array_replace([
			'date_format'=>static function($date, $format){
				return date((string)$format, strtotime((string)$date));
			},
			'slugify'=>static function($text){
				return strtolower(trim((string)preg_replace('/\W+/', '-', trim((string)$text)), '-'));
			},
			'money'=>static function(mixed $value, mixed ...$args): string {
				return self::format_money_value($value, ...$args);
			},
		], self::$helpers);
		self::$filters=array_replace([
			'money'=>static function(mixed $value, mixed ...$args): string {
				return self::format_money_value($value, ...$args);
			},
		], self::$filters);
		self::$cache_dir=rtrim($cache_dir ?? ROOTPATH['dataphyre'].'cache/templating/', '/\\').'/';
		self::$is_dev_mode=$is_dev_mode;
		if($strict_mode!==null){
			self::$strict_mode=$strict_mode;
		}
		self::$asset_policy=self::normalize_asset_policy($asset_policy ?? self::$asset_policy);
		self::$initialized=true;
		if(!is_dir(self::$cache_dir)){
			@mkdir(self::$cache_dir, 0777, true);
		}
	}

	private static function ensure_initialized(): void {
		if(self::$initialized!==true){
			self::init();
		}
	}

	public static function state(): array {
		self::ensure_initialized();
		return [
			'is_dev_mode'=>self::$is_dev_mode,
			'cache_dir'=>self::$cache_dir,
			'global_context'=>self::$global_context,
			'strict_mode'=>self::$strict_mode,
			'template_contracts'=>self::$template_contracts,
			'asset_policy'=>self::$asset_policy,
		];
	}

	public static function inspect(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): array {
		self::ensure_initialized();
		return self::with_manifest_capture(
			static fn(): string => (string)self::full_render($template_file, $data, $theme_values, $slots),
			[
				'template_name'=>$template_file,
				'inline'=>false,
				'data_keys'=>array_keys($data),
				'theme_value_keys'=>array_keys($theme_values),
				'slot_names'=>array_keys($slots),
				'cache_strategy'=>'inspection_bypass',
			]
		);
	}

	public static function inspect_string(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): array {
		self::ensure_initialized();
		return self::with_manifest_capture(
			static fn(): string => self::render_string($template, $data, $theme_values, $slots, $template_name),
			[
				'template_name'=>$template_name,
				'inline'=>true,
				'data_keys'=>array_keys($data),
				'theme_value_keys'=>array_keys($theme_values),
				'slot_names'=>array_keys($slots),
				'cache_strategy'=>'inspection_bypass',
			]
		);
	}

	public static function plan(string $template_file): array {
		self::ensure_initialized();
		$template_file=self::normalize_template_reference($template_file);
		if(!is_file($template_file) || !is_readable($template_file)){
			throw new \RuntimeException("Template file not found: $template_file");
		}

		$source_mtime=(int)(filemtime($template_file) ?: 0);
		$registry_signature=self::plan_registry_signature();
		$memory_key='file:'.$template_file.':'.$source_mtime.':'.$registry_signature;
		if(isset(self::$compiled_plan_cache[$memory_key]) && is_array(self::$compiled_plan_cache[$memory_key])){
			return self::$compiled_plan_cache[$memory_key];
		}

		$cache_key=md5('file:'.$template_file.':'.$registry_signature);
		$cached=self::load_plan_from_cache($cache_key, $source_mtime);
		if(is_array($cached)){
			self::$compiled_plan_cache[$memory_key]=$cached;
			return $cached;
		}

		self::push_template_context($template_file);
		try{
			$direct_plan=self::compile_template_plan(
				self::load_template_file($template_file),
				$template_file,
				false,
				'file'
			);
			$visited=[$template_file=>true];
			$plan=self::expand_template_plan($direct_plan, $visited, 0);
		} finally {
			self::pop_template_context();
		}

		self::save_plan_to_cache($cache_key, $plan, $source_mtime);
		self::$compiled_plan_cache[$memory_key]=$plan;
		return $plan;
	}

	public static function asset_manifest(string $template_file): array {
		self::ensure_initialized();
		return self::build_asset_manifest_from_plan(self::plan($template_file));
	}

	public static function plan_string(string $template, string $template_name='inline.tpl'): array {
		self::ensure_initialized();
		$memory_key='inline:'.sha1($template_name."\0".$template."\0".self::plan_registry_signature());
		if(isset(self::$compiled_plan_cache[$memory_key]) && is_array(self::$compiled_plan_cache[$memory_key])){
			return self::$compiled_plan_cache[$memory_key];
		}

		self::push_template_context($template_name);
		try{
			$direct_plan=self::compile_template_plan($template, $template_name, true, 'memory');
			$visited=['inline:'.$template_name.':'.sha1($template)=>true];
			$plan=self::expand_template_plan($direct_plan, $visited, 0);
		} finally {
			self::pop_template_context();
		}

		self::$compiled_plan_cache[$memory_key]=$plan;
		return $plan;
	}

	public static function asset_manifest_string(string $template, string $template_name='inline.tpl'): array {
		self::ensure_initialized();
		return self::build_asset_manifest_from_plan(self::plan_string($template, $template_name));
	}

	public static function apply_state(array $overrides): void {
		self::ensure_initialized();
		if(array_key_exists('is_dev_mode', $overrides)){
			self::$is_dev_mode=(bool)$overrides['is_dev_mode'];
		}
		if(isset($overrides['cache_dir']) && is_string($overrides['cache_dir']) && trim($overrides['cache_dir'])!==''){
			self::$cache_dir=rtrim($overrides['cache_dir'], '/\\').'/';
			if(!is_dir(self::$cache_dir)){
				@mkdir(self::$cache_dir, 0777, true);
			}
		}
		if(isset($overrides['global_context']) && is_array($overrides['global_context'])){
			self::$global_context=$overrides['global_context'];
		}
		if(array_key_exists('strict_mode', $overrides)){
			self::$strict_mode=(bool)$overrides['strict_mode'];
		}
		if(isset($overrides['template_contracts']) && is_array($overrides['template_contracts'])){
			$contracts=[];
			foreach($overrides['template_contracts'] as $template_name=>$contract){
				if(!is_string($template_name) || !is_array($contract)){
					continue;
				}
				$contracts[self::normalize_template_reference($template_name)]=self::normalize_template_contract($contract);
			}
			self::$template_contracts=$contracts;
		}
		if(isset($overrides['asset_policy']) && is_array($overrides['asset_policy'])){
			self::$asset_policy=self::normalize_asset_policy($overrides['asset_policy']);
		}
	}

	public static function global_context(): array {
		self::ensure_initialized();
		return self::$global_context;
	}

	public static function clear_global_context(): void {
		self::$global_context=[];
	}

	public static function strict_mode(): bool {
		self::ensure_initialized();
		return self::$strict_mode;
	}

	public static function set_strict_mode(bool $strict_mode): void {
		self::$strict_mode=$strict_mode;
	}

	public static function asset_policy(): array {
		self::ensure_initialized();
		return self::$asset_policy;
	}

	public static function set_asset_policy(array $asset_policy): void {
		self::ensure_initialized();
		self::$asset_policy=self::normalize_asset_policy($asset_policy);
	}

	public static function register_template_contract(string $template_name, array $contract): void {
		self::ensure_initialized();
		self::$template_contracts[self::normalize_template_reference($template_name)]=self::normalize_template_contract($contract);
	}

	public static function template_contract(string $template_name): ?array {
		self::ensure_initialized();
		$normalized=self::normalize_template_reference($template_name);
		return self::$template_contracts[$normalized] ?? null;
	}

	public static function clear_template_contract(?string $template_name=null): void {
		self::ensure_initialized();
		if($template_name===null){
			self::$template_contracts=[];
			return;
		}
		unset(self::$template_contracts[self::normalize_template_reference($template_name)]);
	}

	public static function resolve_component_template(string $reference): ?string {
		self::ensure_initialized();
		return self::resolve_component_reference($reference);
	}

	public static function register_component_contract(string $reference, array $contract): void {
		self::ensure_initialized();
		$template=self::resolve_component_reference($reference);
		if($template===null){
			throw new \RuntimeException("Component not found: {$reference}");
		}
		self::register_template_contract($template, $contract);
	}

	public static function component_contract(string $reference): ?array {
		self::ensure_initialized();
		$template=self::resolve_component_reference($reference);
		return $template!==null ? self::template_contract($template) : null;
	}

	public static function clear_component_contract(string $reference): void {
		self::ensure_initialized();
		$template=self::resolve_component_reference($reference);
		if($template!==null){
			self::clear_template_contract($template);
		}
	}

	public static function register_preprocessing_hook(callable $hook): void {
		self::$preprocessing_hooks[]=$hook;
	}

	public static function register_postprocessing_hook(callable $hook): void {
		self::$postprocessing_hooks[]=$hook;
	}
	
	private static function load_template_file(string $filename): string {
		if(!is_file($filename) || !is_readable($filename)){
			throw new \RuntimeException("Template file not found: $filename");
		}
		return (string)file_get_contents($filename);
	}

	private static function bind_data(string $template, array $data): string {
		preg_match_all('/{{\s*(\w+(\.\w+)*)\s*}}/', $template, $matches);
		foreach($matches[1] as $full_path){
			$value=self::get_value_by_path($data, $full_path);
			$template=preg_replace(
				'/{{\s*'.preg_quote($full_path, '/').'\s*}}/',
				htmlspecialchars((string)($value ?? '[Undefined]'), ENT_QUOTES, 'UTF-8'),
				$template
			);
		}
		return $template;
	}
	
	private static function get_value_by_path(array|object|null $data, string $path): mixed {
		$segments=explode('.', $path);
		foreach($segments as $segment){
			if(is_array($data) && array_key_exists($segment, $data)){
				$data=$data[$segment];
			}
			elseif(is_object($data) && property_exists($data, $segment)){
				$data=$data->$segment;
			}
			else
			{
				return null;
			}
		}
		return $data;
	}

	private static function data_path_exists(array|object|null $data, string $path): bool {
		$segments=explode('.', $path);
		foreach($segments as $segment){
			if(is_array($data) && array_key_exists($segment, $data)){
				$data=$data[$segment];
				continue;
			}
			if(is_object($data) && property_exists($data, $segment)){
				$data=$data->$segment;
				continue;
			}
			return false;
		}
		return true;
	}
	
	private static function bind_if(string $variable, mixed $value, callable $condition): void {
		if($condition()){
			self::$global_context[$variable]=$value;
		}
	}
	
	private static function set_local(string $key, mixed $value): void {
		self::$global_context[$key]=$value;
	}
	
	private static function unset_local(string $key): void {
		unset(self::$global_context[$key]);
	}
		
	private static function with_context(array $data, callable $block): string {
		$previousContext=self::$global_context;
		self::$global_context=array_merge(self::$global_context, $data);
		$output=$block();
		self::$global_context=$previousContext;
		return $output;
	}
	
	private static function for_each_scoped(array $items, callable $callback): string {
		$output='';
		$total=count($items);
		foreach($items as $index=>$item){
			$scope=[
				'index'=>$index,
				'first'=>$index===0,
				'last'=>$index===$total-1,
				'item'=>$item
			];
			$output.=self::with_context($scope, fn()=>$callback($item, $scope));
		}
		return $output;
	}
	
	private static function get_scoped_value(string $path): mixed {
		return self::get_value_by_path(self::$global_context, $path) ?? self::get_value_by_path(self::$parent_context, $path);
	}

	private static function trim_whitespace(string $template): string {
		$template=preg_replace('/{%\s*-\s*/', '', $template);  // Trim start
		$template=preg_replace('/\s*-\s*%}/', '', $template);  // Trim end
		return $template;
	}

	private static function handle_undefined_variables(string $template, array $data): string {
		preg_match_all('/{{(\w+)}}/', $template, $matches);
		$reserved=['endslot', 'endblock', 'endif', 'else', 'endloop', 'break', 'continue'];
		foreach($matches[1] as $variable){
			if(in_array(strtolower((string)$variable), $reserved, true)){
				continue;
			}
			if(!isset($data[$variable])){
				self::record_manifest_value('undefined_variables', $variable);
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Undefined variable in template: $variable");
				$template=str_replace("{{".$variable."}}", '[Undefined]', $template);
			}
		}
		return $template;
	}

	public static function add_to_global_context(string $key, mixed $value): void {
		self::$global_context[$key]=$value;
	}

    private static function replace_nested_placeholders(string $template, string $prefix, array $data): string {
        foreach($data as $key=>$value){
            $full_key=$prefix.'.'.$key;
            if(is_array($value) || is_object($value)){
                $template=self::replace_nested_placeholders($template, $full_key, $value);
			}
			else
			{
				$template=str_replace(
					"{{".$full_key."}}",
					htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'),
					$template
				);
            }
        }
        return $template;
    }
	
	public static function register_tag(string $tag, callable $callback): void {
		self::$tags[$tag]=$callback;
	}

	private static function apply_tags(string $template, array $data): string {
		preg_match_all('/{{\s*(\w+)(.*?)\s*}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$tag=$match[1];
			$args=array_map('trim', explode(',', trim($match[2])));
			if(isset(self::$tags[$tag])){
				self::record_manifest_value('tags', $tag);
				$replacement=call_user_func_array(self::$tags[$tag], [$args, $data]);
				$template=str_replace($match[0], htmlspecialchars($replacement ?? ''), $template);
			}
		}
		return $template;
	}
	
	public static function register_filter(string $filter, callable $callback): void {
		self::$filters[$filter]=$callback;
	}

	private static function apply_filters(string $template, array $data): string {
		preg_match_all('/{{\s*([\w\.]+)\s*\|\s*([A-Za-z_]\w*(?:\((.*?)\))?)\s*}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$variable=trim($match[1]);
			$filter=self::parse_filter_invocation($match[2]);
			$filter_name=(string)($filter['name'] ?? '');
			$filter_args=is_array($filter['args'] ?? null) ? $filter['args'] : [];
			$value=self::get_value_by_path($data, $variable);
			if($filter_name!=='' && isset(self::$filters[$filter_name])){
				self::record_manifest_value('filters', $filter_name);
				$replacement=self::invoke_template_callable(self::$filters[$filter_name], array_merge([$value], $filter_args));
				$template=str_replace($match[0], htmlspecialchars((string)($replacement ?? ''), ENT_QUOTES, 'UTF-8'), $template);
			}
		}
		return $template;
	}
	
	private static function apply_preprocessing_hooks(string $template, array $data): string {
		foreach(self::$preprocessing_hooks as $hook){
			$template=call_user_func($hook, $template, $data);
		}
		return $template;
	}
	
	private static function apply_postprocessing_hooks(string $template, array $data): string {
		foreach(self::$postprocessing_hooks as $hook){
			$template=call_user_func($hook, $template, $data);
		}
		return $template;
	}

    public static function apply_functions(string $template, array $custom_functions=[]): string {
        foreach($custom_functions as $func=>$callback){
            preg_match_all("/{{".$func."\((.*?)\)}}/", $template, $matches, PREG_SET_ORDER);
            foreach($matches as $match){
                $args=explode(',', $match[1]);
                $args=array_map('trim', $args);
                $result=call_user_func_array($callback, $args);
                $template=str_replace($match[0], htmlspecialchars($result), $template);
            }
        }
        return $template;
    }
	
	private static function compose_components(string $template, array $data): string {
		return self::parse_components($template, $data);
	}
	
    public static function apply_transformations(string $template, array $custom_functions=[], array $filters=[]): string {
        $template=self::apply_functions($template, $custom_functions);
        $template=self::apply_filters($template, $filters);
        return $template;
    }

	private static function resolve_dependencies(string $template): string {
		preg_match_all('/{{ require(CSS|JS) "(.+?)" }}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$type=strtolower($match[1]);
			$file=$match[2];
			$descriptor=self::resolve_asset_descriptor($file, $type);
			$path=$descriptor['path'];
			$tag=self::asset_tag_from_descriptor($descriptor, $type);
			self::record_manifest_structured('dependencies', [
				'type'=>$type,
				'reference'=>$file,
				'path'=>$path,
				'preload_as'=>$descriptor['preload_as'],
				'exists'=>$descriptor['exists'],
			]);
			$template=str_replace($match[0], $tag, $template);
		}
		return $template;
	}

    private static function conditional_asset_import(string $template, array$data): string {
        preg_match_all('/{{loadCSS "(.+?)"( if(\w+))?}}/', $template, $matches);
        foreach($matches[1] as $index=>$asset){
            $condition=$matches[3][$index] ?? null;
            if(!$condition ||($condition && !empty($data[$condition]))){
                $descriptor=self::resolve_asset_descriptor($asset, 'css');
                self::record_manifest_structured('dependencies', [
                    'type'=>'css',
                    'reference'=>$asset,
                    'path'=>$descriptor['path'],
                    'preload_as'=>$descriptor['preload_as'],
                    'exists'=>$descriptor['exists'],
                    'condition'=>$condition,
                ]);
                $template=str_replace($matches[0][$index], self::asset_tag_from_descriptor($descriptor, 'css'), $template);
            }
        }
        preg_match_all('/{{loadJS "(.+?)"( if(\w+))?}}/', $template, $matches);
        foreach($matches[1] as $index=>$asset){
            $condition=$matches[3][$index] ?? null;
            if(!$condition ||($condition && !empty($data[$condition]))){
                $descriptor=self::resolve_asset_descriptor($asset, 'js');
                self::record_manifest_structured('dependencies', [
                    'type'=>'js',
                    'reference'=>$asset,
                    'path'=>$descriptor['path'],
                    'preload_as'=>$descriptor['preload_as'],
                    'exists'=>$descriptor['exists'],
                    'condition'=>$condition,
                ]);
                $template=str_replace($matches[0][$index], self::asset_tag_from_descriptor($descriptor, 'js'), $template);
            }
        }
        return $template;
    }

    private static function apply_conditional_hooks(string $template, array $data, string $environment='dev'): string {
        foreach(self::${$environment.'_hooks'} ?? [] as $hook){
            $template=call_user_func($hook, $template, $data);
        }
        return $template;
    }
	
	private static function generate_template_docs(string $template): string {
		preg_match_all('/{{(\w+)(\|\w+)*}}/', $template, $matches);
		$doc="Template Variables:\n";
		foreach($matches[1] as $variable){
			$doc.="- $variable\n";
		}
		return $doc;
	}

	private static function parseTranslations(string $template): string {
		preg_match_all('/{{\s*(?:trans|translate)\s*"(.+?)"\s*}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$translated=$match[1];
			if(class_exists('dataphyre\\localization')){
				$translated=\dataphyre\localization::locale($match[1], $match[1]);
			}
			self::record_manifest_structured('translations', [
				'key'=>$match[1],
				'resolved'=>$translated,
			]);
			$template=str_replace($match[0], htmlspecialchars((string)$translated, ENT_QUOTES, 'UTF-8'), $template);
		}
		return $template;
	}

	private static function push_template_context(string $template_name): void {
		self::$template_stack[]=self::normalize_template_reference($template_name);
	}

	private static function pop_template_context(): void {
		array_pop(self::$template_stack);
	}

	private static function current_template_reference(): ?string {
		$current=end(self::$template_stack);
		return $current===false ? null : $current;
	}

	private static function current_template_dir(): ?string {
		$current=self::current_template_reference();
		if($current===null){
			return null;
		}
		return is_file($current) ? dirname($current) : dirname($current);
	}

	private static function normalize_template_reference(string $reference): string {
		$reference=trim($reference);
		if($reference===''){
			return $reference;
		}
		$resolved=realpath($reference);
		return $resolved===false ? str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $reference) : $resolved;
	}

	private static function resolve_template_reference(string $reference): ?string {
		$reference=trim($reference);
		if($reference===''){
			return null;
		}

		$candidates=[];
		if(self::is_absolute_path($reference)){
			$candidates[]=$reference;
		}else{
			$current_dir=self::current_template_dir();
			if($current_dir!==null && $current_dir!=='.'){
				$candidates[]=$current_dir.DIRECTORY_SEPARATOR.$reference;
			}
			$candidates[]=$reference;
		}

		foreach(array_values(array_unique(array_map([self::class, 'normalize_template_reference'], $candidates))) as $candidate){
			if(is_file($candidate) && is_readable($candidate)){
				return $candidate;
			}
		}

		return null;
	}

	private static function resolve_component_reference(string $reference): ?string {
		$reference=trim($reference);
		if($reference===''){
			return null;
		}

		$references=[$reference];
		if(pathinfo($reference, PATHINFO_EXTENSION)===''){
			$references[]=$reference.'.tpl';
		}

		$candidates=[];
		foreach($references as $resolved_reference){
			if(self::is_absolute_path($resolved_reference)){
				$candidates[]=$resolved_reference;
				continue;
			}

			$current_dir=self::current_template_dir();
			if($current_dir!==null && $current_dir!=='.'){
				$candidates[]=$current_dir.DIRECTORY_SEPARATOR.$resolved_reference;
				$candidates[]=$current_dir.DIRECTORY_SEPARATOR.'components'.DIRECTORY_SEPARATOR.$resolved_reference;
			}
			$candidates[]=$resolved_reference;
			$candidates[]='components'.DIRECTORY_SEPARATOR.$resolved_reference;
		}

		foreach(array_values(array_unique(array_map([self::class, 'normalize_template_reference'], $candidates))) as $candidate){
			if(is_file($candidate) && is_readable($candidate)){
				return $candidate;
			}
		}

		return null;
	}

	private static function is_absolute_path(string $path): bool {
		return preg_match('/^(?:[A-Za-z]:[\/\\\\]|\/|\\\\\\\\)/', $path)===1;
	}

	private static function with_manifest_capture(callable $callback, array $root): array {
		$previous_enabled=self::$inspection_enabled;
		$previous_depth=self::$inspection_depth;
		$previous_manifest=self::$active_manifest;

		self::$inspection_enabled=true;
		self::$inspection_depth=0;
		self::$active_manifest=self::new_manifest($root);

		try{
			$content=(string)$callback();
			self::$active_manifest['content_length']=strlen($content);
			return [
				'content'=>$content,
				'manifest'=>self::finalize_manifest(),
			];
		} catch(\Throwable $e){
			self::record_manifest_structured('errors', [
				'message'=>$e->getMessage(),
				'type'=>$e::class,
			]);
			self::$active_manifest['failed']=true;
			self::$active_manifest['failure_message']=$e->getMessage();
			throw $e;
		} finally {
			self::$inspection_enabled=$previous_enabled;
			self::$inspection_depth=$previous_depth;
			self::$active_manifest=$previous_manifest;
		}
	}

	private static function new_manifest(array $root): array {
		return [
			'template_name'=>(string)($root['template_name'] ?? 'template.tpl'),
			'inline'=>(bool)($root['inline'] ?? false),
			'data_keys'=>array_values(array_unique(array_map('strval', $root['data_keys'] ?? []))),
			'theme_value_keys'=>array_values(array_unique(array_map('strval', $root['theme_value_keys'] ?? []))),
			'slot_names'=>array_values(array_unique(array_map('strval', $root['slot_names'] ?? []))),
			'cache_strategy'=>(string)($root['cache_strategy'] ?? 'runtime'),
			'cache_used'=>false,
			'strict_mode'=>self::$strict_mode,
			'asset_policy'=>self::$asset_policy,
			'templates'=>[],
			'partials'=>[],
			'components'=>[],
			'imports'=>[],
			'layouts'=>[],
			'assets'=>[],
			'dependencies'=>[],
			'translations'=>[],
			'undefined_variables'=>[],
			'missing_references'=>[],
			'tags'=>[],
			'filters'=>[],
			'helpers'=>[],
			'extensions'=>[],
			'bindings'=>[],
			'binding_errors'=>[],
			'binding_warnings'=>[],
			'contracts'=>[],
			'contract_violations'=>[],
			'errors'=>[],
			'started_at'=>microtime(true),
			'finished_at'=>null,
			'duration_ms'=>0.0,
			'content_length'=>0,
			'failed'=>false,
			'failure_message'=>null,
		];
	}

	private static function finalize_manifest(): array {
		self::$active_manifest['finished_at']=microtime(true);
		self::$active_manifest['duration_ms']=round(
			(self::$active_manifest['finished_at']-self::$active_manifest['started_at'])*1000,
			3
		);
		return self::$active_manifest;
	}

	private static function record_template_render(string $template_name, bool $inline=false): void {
		if(self::$inspection_enabled!==true){
			return;
		}
		$normalized=self::normalize_template_reference($template_name);
		self::record_manifest_structured('templates', [
			'template'=>$normalized,
			'inline'=>$inline,
			'depth'=>max(0, self::$inspection_depth-1),
		]);
	}

	private static function record_manifest_value(string $bucket, string $value): void {
		if(self::$inspection_enabled!==true || $value===''){
			return;
		}
		if(!isset(self::$active_manifest[$bucket]) || !is_array(self::$active_manifest[$bucket])){
			self::$active_manifest[$bucket]=[];
		}
		if(!in_array($value, self::$active_manifest[$bucket], true)){
			self::$active_manifest[$bucket][]=$value;
		}
	}

	private static function record_manifest_structured(string $bucket, array $value): void {
		if(self::$inspection_enabled!==true){
			return;
		}
		if(!isset(self::$active_manifest[$bucket]) || !is_array(self::$active_manifest[$bucket])){
			self::$active_manifest[$bucket]=[];
		}
		$signature=md5(json_encode($value));
		foreach(self::$active_manifest[$bucket] as $existing){
			if(is_array($existing) && md5(json_encode($existing))===$signature){
				return;
			}
		}
		self::$active_manifest[$bucket][]=$value;
	}

	private static function record_missing_reference(string $type, string $reference): void {
		self::record_manifest_structured('missing_references', [
			'type'=>$type,
			'reference'=>$reference,
		]);
	}

	private static function normalize_template_contract(array $contract): array {
		$normalize_keys=static function(array $keys): array {
			$normalized=[];
			foreach($keys as $key){
				if(!is_string($key)){
					continue;
				}
				$key=trim($key);
				if($key!=='' && !in_array($key, $normalized, true)){
					$normalized[]=$key;
				}
			}
			return $normalized;
		};

		return [
			'required'=>$normalize_keys(is_array($contract['required'] ?? null) ? $contract['required'] : []),
			'optional'=>$normalize_keys(is_array($contract['optional'] ?? null) ? $contract['optional'] : []),
			'required_slots'=>$normalize_keys(
				is_array($contract['required_slots'] ?? null)
					? $contract['required_slots']
					: (is_array($contract['slots'] ?? null) ? $contract['slots'] : [])
			),
			'optional_slots'=>$normalize_keys(is_array($contract['optional_slots'] ?? null) ? $contract['optional_slots'] : []),
			'defaults'=>self::normalize_contract_defaults(is_array($contract['defaults'] ?? null) ? $contract['defaults'] : []),
			'prop_types'=>self::normalize_contract_type_map(
				is_array($contract['prop_types'] ?? null)
					? $contract['prop_types']
					: (is_array($contract['types'] ?? null) ? $contract['types'] : [])
			),
			'allow_additional_data'=>array_key_exists('allow_additional_data', $contract) ? (bool)$contract['allow_additional_data'] : true,
			'allow_additional_slots'=>array_key_exists('allow_additional_slots', $contract) ? (bool)$contract['allow_additional_slots'] : true,
		];
	}

	private static function validate_template_contract(string $template_name, array $data, array $slots): array {
		$contract=self::template_contract($template_name);
		if($contract===null){
			return $data;
		}

		$data=self::apply_contract_defaults($data, $contract['defaults'] ?? []);

		self::record_manifest_structured('contracts', [
			'template'=>self::normalize_template_reference($template_name),
			'required'=>$contract['required'],
			'optional'=>$contract['optional'],
			'required_slots'=>$contract['required_slots'],
			'optional_slots'=>$contract['optional_slots'],
			'defaults'=>$contract['defaults'] ?? [],
			'prop_types'=>$contract['prop_types'] ?? [],
			'allow_additional_data'=>$contract['allow_additional_data'],
			'allow_additional_slots'=>$contract['allow_additional_slots'],
		]);

		$violations=[];
		foreach($contract['required'] as $required_key){
			if(!self::path_exists($data, $required_key)){
				$violations[]="Missing required data key: $required_key";
			}
		}

		foreach($contract['required_slots'] as $required_slot){
			if(!array_key_exists($required_slot, $slots)){
				$violations[]="Missing required slot: $required_slot";
			}
		}

		foreach(($contract['prop_types'] ?? []) as $prop_path=>$expected_type){
			if(!self::path_exists($data, $prop_path)){
				continue;
			}
			$value=self::get_value_by_path($data, $prop_path);
			if(!self::contract_value_matches_type($value, (string)$expected_type)){
				$violations[]="Invalid data type for {$prop_path}: expected {$expected_type}";
			}
		}

		if($contract['allow_additional_data']===false){
			$allowed_data_keys=self::top_level_contract_keys(array_merge(
				$contract['required'],
				$contract['optional'],
				array_keys($contract['defaults'] ?? []),
				array_keys($contract['prop_types'] ?? [])
			));
			foreach(array_keys($data) as $data_key){
				if(!in_array((string)$data_key, $allowed_data_keys, true)){
					$violations[]="Unexpected data key: $data_key";
				}
			}
		}

		if($contract['allow_additional_slots']===false){
			$allowed_slots=array_values(array_unique(array_merge($contract['required_slots'], $contract['optional_slots'])));
			foreach(array_keys($slots) as $slot_name){
				if(!in_array((string)$slot_name, $allowed_slots, true)){
					$violations[]="Unexpected slot: $slot_name";
				}
			}
		}

		foreach($violations as $violation){
			self::record_manifest_structured('contract_violations', [
				'template'=>self::normalize_template_reference($template_name),
				'message'=>$violation,
			]);
		}

		return $data;
	}

	private static function enforce_strict_mode(string $template_name): void {
		if(self::$strict_mode!==true){
			return;
		}

		$reasons=[];
		if(!empty(self::$active_manifest['contract_violations'])){
			$reasons[]='contract violations';
		}
		if(!empty(self::$active_manifest['missing_references'])){
			$reasons[]='missing references';
		}
		if(!empty(self::$active_manifest['undefined_variables'])){
			$reasons[]='undefined variables';
		}
		if(!empty(self::$active_manifest['errors'])){
			$reasons[]='render errors';
		}

		if($reasons===[]){
			return;
		}

		self::$active_manifest['failed']=true;
		self::$active_manifest['failure_message']="Strict templating violation in {$template_name}: ".implode(', ', $reasons);
		throw new \RuntimeException(self::$active_manifest['failure_message']);
	}

	private static function path_exists(array|object|null $data, string $path): bool {
		$segments=explode('.', $path);
		foreach($segments as $segment){
			if(is_array($data) && array_key_exists($segment, $data)){
				$data=$data[$segment];
				continue;
			}
			if(is_object($data) && property_exists($data, $segment)){
				$data=$data->$segment;
				continue;
			}
			return false;
		}
		return true;
	}

	private static function top_level_contract_keys(array $keys): array {
		$allowed=[];
		foreach($keys as $key){
			if(!is_string($key) || trim($key)===''){
				continue;
			}
			$top_level=strtok($key, '.');
			if($top_level!==false && !in_array($top_level, $allowed, true)){
				$allowed[]=$top_level;
			}
		}
		return $allowed;
	}

	private static function normalize_contract_defaults(array $defaults): array {
		$normalized=[];
		foreach($defaults as $key=>$value){
			if(!is_string($key)){
				continue;
			}
			$key=trim($key);
			if($key===''){
				continue;
			}
			$normalized[$key]=$value;
		}
		return $normalized;
	}

	private static function normalize_contract_type_map(array $types): array {
		$normalized=[];
		foreach($types as $key=>$type){
			if(!is_string($key) || !is_string($type)){
				continue;
			}
			$key=trim($key);
			$type=strtolower(trim($type));
			if($key==='' || $type===''){
				continue;
			}
			$normalized[$key]=$type;
		}
		return $normalized;
	}

	private static function apply_contract_defaults(array $data, array $defaults): array {
		foreach($defaults as $path=>$value){
			if(!is_string($path) || $path===''){
				continue;
			}
			if(self::path_exists($data, $path)){
				continue;
			}
			self::set_data_path_value($data, $path, $value);
		}
		return $data;
	}

	private static function set_data_path_value(array &$data, string $path, mixed $value): void {
		$segments=array_values(array_filter(array_map('trim', explode('.', $path)), static fn(string $segment): bool => $segment!==''));
		if($segments===[]){
			return;
		}
		self::assign_data_path_value($data, $segments, $value);
	}

	private static function assign_data_path_value(array|object &$current, array $segments, mixed $value): void {
		$segment=array_shift($segments);
		if(!is_string($segment) || $segment===''){
			return;
		}

		$is_last=$segments===[];
		if(is_array($current)){
			if($is_last){
				$current[$segment]=$value;
				return;
			}
			if(!array_key_exists($segment, $current) || (!is_array($current[$segment]) && !is_object($current[$segment]))){
				$current[$segment]=[];
			}
			self::assign_data_path_value($current[$segment], $segments, $value);
			return;
		}

		if(!is_object($current)){
			return;
		}
		if($is_last){
			$current->$segment=$value;
			return;
		}
		if(!property_exists($current, $segment) || (!is_array($current->$segment) && !is_object($current->$segment))){
			$current->$segment=[];
		}
		self::assign_data_path_value($current->$segment, $segments, $value);
	}

	private static function contract_value_matches_type(mixed $value, string $type): bool {
		$type=strtolower(trim($type));
		return match($type){
			'mixed' => true,
			'string' => is_string($value),
			'int', 'integer' => is_int($value),
			'float', 'double' => is_float($value),
			'numeric' => is_numeric($value),
			'bool', 'boolean' => is_bool($value),
			'array' => is_array($value),
			'list' => is_array($value) && array_is_list($value),
			'scalar' => is_scalar($value),
			'object' => is_object($value),
			'callable' => is_callable($value),
			default => true,
		};
	}

	private static function component_contract_summary(?string $template_name): ?array {
		if(!is_string($template_name) || $template_name===''){
			return null;
		}
		$contract=self::template_contract($template_name);
		return is_array($contract) ? [
			'required'=>$contract['required'] ?? [],
			'optional'=>$contract['optional'] ?? [],
			'required_slots'=>$contract['required_slots'] ?? [],
			'optional_slots'=>$contract['optional_slots'] ?? [],
			'defaults'=>$contract['defaults'] ?? [],
			'prop_types'=>$contract['prop_types'] ?? [],
			'allow_additional_data'=>$contract['allow_additional_data'] ?? true,
			'allow_additional_slots'=>$contract['allow_additional_slots'] ?? true,
		] : null;
	}

	private static function resolve_asset_descriptor(string $reference, string $hint='asset'): array {
		$reference=trim($reference);
		$hint=strtolower(trim($hint));
		$is_external=preg_match('/^(?:https?:)?\/\//i', $reference)===1;
		$normalized_reference=str_replace('\\', '/', $reference);
		$filesystem_path=null;
		$path=$normalized_reference;

		if($is_external){
			$path=$normalized_reference;
		}else{
			$path_candidate=$normalized_reference;
			$extension=(string)pathinfo($normalized_reference, PATHINFO_EXTENSION);
			if($hint==='css' && $extension===''){
				$path_candidate.='.css';
			}
			if($hint==='js' && $extension===''){
				$path_candidate.='.js';
			}
			if(!self::is_absolute_path($path_candidate) && !str_starts_with($path_candidate, '/')){
				$path_candidate='assets/'.$path_candidate;
			}
			$path_candidate=str_replace('\\', '/', $path_candidate);
			$filesystem_path=$path_candidate;
			$path=$path_candidate.(is_file($path_candidate) ? '?v='.(filemtime($path_candidate) ?: 0) : '');
		}

		$path_without_query=(string)parse_url($path, PHP_URL_PATH);
		$extension=strtolower((string)pathinfo($path_without_query!=='' ? $path_without_query : $path, PATHINFO_EXTENSION));
		$type=self::asset_type_from_extension($extension, $hint);
		return [
			'reference'=>$reference,
			'hint'=>$hint,
			'path'=>$path,
			'filesystem_path'=>$filesystem_path,
			'exists'=>$is_external ? true : ($filesystem_path!==null && is_file($filesystem_path)),
			'type'=>$type,
			'extension'=>$extension,
			'preload_as'=>self::asset_preload_type($type),
		];
	}

	private static function asset_type_from_extension(string $extension, string $hint='asset'): string {
		return match($extension){
			'css' => 'style',
			'js', 'mjs' => 'script',
			'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif', 'ico' => 'image',
			'woff', 'woff2', 'ttf', 'otf', 'eot' => 'font',
			default => match($hint){
				'css' => 'style',
				'js' => 'script',
				default => 'asset',
			},
		};
	}

	private static function asset_preload_type(string $type): ?string {
		return match($type){
			'style' => 'style',
			'script' => 'script',
			'image' => 'image',
			'font' => 'font',
			default => null,
		};
	}

	private static function asset_tag_from_descriptor(array $descriptor, string $fallback_hint='asset'): string {
		$type=$descriptor['type'] ?? self::asset_type_from_extension('', $fallback_hint);
		$path=(string)($descriptor['path'] ?? '');
		return match($type){
			'style' => "<link rel='stylesheet' href='{$path}'".self::stylesheet_attributes_from_descriptor($descriptor).">",
			'script' => "<script src='{$path}'".self::script_attributes_from_descriptor($descriptor)."></script>",
			default => $path,
		};
	}

	private static function compile_template_plan(string $template, string $template_name, bool $inline, string $cache_mode): array {
		$normalized_name=self::normalize_template_reference($template_name);
		$data_paths=[];
		$slot_names=[];
		$partials=[];
		$components=[];
		$imports=[];
		$layouts=[];
		$assets=[];
		$dependencies=[];
		$translations=[];
		$filters=[];
		$tags=[];
		$helpers=[];
		$extensions=[];

		if(preg_match_all('/{{\s*([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)\s*}}/', $template, $matches)){
			$data_paths=array_merge($data_paths, $matches[1]);
		}
		if(preg_match_all('/{{\s*([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)\s*\|\s*([^}]+)\s*}}/', $template, $pipeline_matches, PREG_SET_ORDER)){
			foreach($pipeline_matches as $match){
				$data_paths[]=$match[1];
				foreach(array_map('trim', explode('|', $match[2])) as $filter){
					if($filter!==''){
						$filters[]=$filter;
					}
				}
			}
		}
		if(preg_match_all('/{{\s*import\s+"([^"]+)"\s*if\s*([\w\.]+)\s*}}/', $template, $import_matches, PREG_SET_ORDER)){
			foreach($import_matches as $match){
				$data_paths[]=$match[2];
				$imports[]=[
					'reference'=>$match[1],
					'template'=>self::resolve_template_reference($match[1]),
					'condition'=>$match[2],
				];
			}
		}
		if(preg_match_all('/{{\s*include\s+"([^"]+\.tpl)"(?:\s+with\s+([\w\.]+))?\s*}}/', $template, $partial_matches, PREG_SET_ORDER)){
			foreach($partial_matches as $match){
				if(isset($match[2]) && $match[2]!==''){
					$data_paths[]=$match[2];
				}
				$partials[]=[
					'reference'=>$match[1],
					'template'=>self::resolve_template_reference($match[1]),
					'data_scope'=>$match[2] ?? null,
				];
			}
		}
		if(preg_match_all('/{{\s*component\s+[\"\']([^\"\']+)[\"\'](?:\s+props=([\w\.]+))?\s*}}/', $template, $component_matches, PREG_SET_ORDER)){
			foreach($component_matches as $match){
				if(isset($match[2]) && $match[2]!==''){
					$data_paths[]=$match[2];
				}
				$component_template=self::resolve_component_reference($match[1]);
				$components[]=[
					'reference'=>$match[1],
					'template'=>$component_template,
					'props_path'=>$match[2] ?? null,
					'contract'=>self::component_contract_summary($component_template),
				];
			}
		}
		if(preg_match_all('/{{slot\s*"(\w+)"}}/i', $template, $slot_matches)){
			$slot_names=array_merge($slot_names, $slot_matches[1]);
		}
		if(preg_match('/{{\s*extends\s*"([^"]+\.tpl)"\s*}}/', $template, $extends_match)){
			$layouts[]=[
				'reference'=>$extends_match[1],
				'template'=>self::resolve_template_reference($extends_match[1]),
				'style'=>'extends',
			];
		}
		if(preg_match('/{{ extend "(.+?)" }}/', $template, $extend_match)){
			$layouts[]=[
				'reference'=>$extend_match[1],
				'template'=>self::resolve_template_reference($extend_match[1]),
				'style'=>'extend',
			];
		}
		if(preg_match_all('/{{asset "(.+?)"}}/', $template, $asset_matches)){
			$assets=array_merge($assets, $asset_matches[1]);
		}
		if(preg_match_all('/{{ require(CSS|JS) "(.+?)" }}/', $template, $dependency_matches, PREG_SET_ORDER)){
			foreach($dependency_matches as $match){
				$dependencies[]=[
					'type'=>strtolower($match[1]),
					'reference'=>$match[2],
				];
			}
		}
		if(preg_match_all('/{{load(CSS|JS) "(.+?)"( if(\w+))?}}/', $template, $conditional_dependency_matches, PREG_SET_ORDER)){
			foreach($conditional_dependency_matches as $match){
				$dependencies[]=[
					'type'=>strtolower($match[1]),
					'reference'=>$match[2],
					'condition'=>$match[4] ?? null,
				];
			}
		}
		if(preg_match_all('/{{\s*(?:trans|translate)\s*"(.+?)"\s*}}/', $template, $translation_matches)){
			$translations=array_merge($translations, $translation_matches[1]);
		}

		foreach(array_keys(self::$helpers) as $helper_name){
			if(preg_match('/{{'.preg_quote($helper_name, '/').'\((.*?)\)}}/', $template)===1){
				$helpers[]=$helper_name;
			}
		}
		foreach(array_keys(self::$extensions) as $extension_name){
			if(preg_match('/{{'.preg_quote($extension_name, '/').'\((.*?)\)}}/', $template)===1){
				$extensions[]=$extension_name;
			}
		}
		foreach(array_keys(self::$tags) as $tag_name){
			if(preg_match('/{{\s*'.preg_quote($tag_name, '/').'(?:\s|}})/', $template)===1){
				$tags[]=$tag_name;
			}
		}

		$data_paths=self::unique_string_values($data_paths);
		$slot_names=self::unique_string_values($slot_names);
		$assets=self::unique_string_values($assets);
		$translations=self::unique_string_values($translations);
		$filters=self::unique_string_values($filters);
		$tags=self::unique_string_values($tags);
		$helpers=self::unique_string_values($helpers);
		$extensions=self::unique_string_values($extensions);

		return [
			'template_name'=>$normalized_name,
			'inline'=>$inline,
			'cache_mode'=>$cache_mode,
			'source_hash'=>sha1($template),
			'data_paths'=>$data_paths,
			'top_level_data_keys'=>self::top_level_contract_keys($data_paths),
			'slot_names'=>$slot_names,
			'partials'=>self::unique_structured_values($partials),
			'components'=>self::unique_structured_values($components),
			'imports'=>self::unique_structured_values($imports),
			'layouts'=>self::unique_structured_values($layouts),
			'assets'=>$assets,
			'dependencies'=>self::unique_structured_values($dependencies),
			'translations'=>$translations,
			'tags'=>$tags,
			'filters'=>$filters,
			'helpers'=>$helpers,
			'extensions'=>$extensions,
			'features'=>[
				'has_fragment_cache'=>preg_match('/{{cache\s+"[\w\-]+"\s+\d+}}/', $template)===1,
				'has_loops'=>preg_match('/{{loop\w+}}/', $template)===1,
				'has_conditionals'=>preg_match('/{{\s*(?:if|elseif)\b/', $template)===1,
				'has_php_blocks'=>preg_match('/{{php\s*(.*?)\s*}}/s', $template)===1,
				'has_forms'=>preg_match('/{{form\s+/', $template)===1,
				'has_markdown'=>preg_match('/```|`[^`]+`|^\#/m', $template)===1,
			],
			'suggested_contract'=>self::normalize_template_contract([
				'optional'=>self::top_level_contract_keys($data_paths),
				'optional_slots'=>$slot_names,
			]),
		];
	}

	private static function expand_template_plan(array $plan, array &$visited, int $depth): array {
		$node=[
			'template_name'=>$plan['template_name'],
			'inline'=>(bool)($plan['inline'] ?? false),
			'cache_mode'=>$plan['cache_mode'] ?? 'memory',
			'depth'=>$depth,
		];
		$graph_nodes=[$node];
		$graph_edges=[];
		$unresolved_references=[];
		$aggregate=[
			'data_paths'=>$plan['data_paths'] ?? [],
			'top_level_data_keys'=>$plan['top_level_data_keys'] ?? [],
			'slot_names'=>$plan['slot_names'] ?? [],
			'partials'=>$plan['partials'] ?? [],
			'components'=>$plan['components'] ?? [],
			'imports'=>$plan['imports'] ?? [],
			'layouts'=>$plan['layouts'] ?? [],
			'assets'=>$plan['assets'] ?? [],
			'dependencies'=>$plan['dependencies'] ?? [],
			'translations'=>$plan['translations'] ?? [],
			'tags'=>$plan['tags'] ?? [],
			'filters'=>$plan['filters'] ?? [],
			'helpers'=>$plan['helpers'] ?? [],
			'extensions'=>$plan['extensions'] ?? [],
		];

		foreach([
			'partials'=>'partial',
			'components'=>'component',
			'imports'=>'import',
			'layouts'=>'layout',
		] as $bucket=>$edge_type){
			foreach($plan[$bucket] ?? [] as $reference){
				if(!is_array($reference)){
					continue;
				}
				$target=$reference['template'] ?? null;
				$edge=[
					'from'=>$plan['template_name'],
					'to'=>$target,
					'type'=>$edge_type,
					'reference'=>$reference['reference'] ?? null,
				];
				foreach(['condition', 'data_scope', 'props_path', 'style'] as $extra_key){
					if(array_key_exists($extra_key, $reference)){
						$edge[$extra_key]=$reference[$extra_key];
					}
				}
				$graph_edges[]=$edge;

				if(!is_string($target) || $target==='' || !is_file($target) || !is_readable($target)){
					$unresolved_references[]=[
						'type'=>$edge_type,
						'reference'=>$reference['reference'] ?? null,
						'template'=>$target,
					];
					continue;
				}

				if(isset($visited[$target])){
					continue;
				}

				$visited[$target]=true;
				self::push_template_context($target);
				try{
					$child_plan=self::compile_template_plan(
						self::load_template_file($target),
						$target,
						false,
						'file_dependency'
					);
				} finally {
					self::pop_template_context();
				}

				$expanded_child=self::expand_template_plan($child_plan, $visited, $depth+1);
				$graph_nodes=array_merge($graph_nodes, $expanded_child['graph']['nodes'] ?? []);
				$graph_edges=array_merge($graph_edges, $expanded_child['graph']['edges'] ?? []);
				$unresolved_references=array_merge($unresolved_references, $expanded_child['unresolved_references'] ?? []);
				$aggregate=self::merge_plan_aggregate($aggregate, $expanded_child['aggregate'] ?? []);
			}
		}

		$graph_nodes=self::unique_structured_values($graph_nodes);
		$graph_edges=self::unique_structured_values($graph_edges);
		$unresolved_references=self::unique_structured_values($unresolved_references);
		$aggregate=self::normalize_plan_aggregate($aggregate);

		$plan['graph']=[
			'nodes'=>$graph_nodes,
			'edges'=>$graph_edges,
		];
		$plan['all_templates']=array_values(array_unique(array_map(
			static fn(array $node): string => (string)($node['template_name'] ?? ''),
			$graph_nodes
		)));
		$plan['unresolved_references']=$unresolved_references;
		$plan['aggregate']=$aggregate;
		$plan['suggested_contract']=self::normalize_template_contract([
			'optional'=>$aggregate['top_level_data_keys'],
			'optional_slots'=>$aggregate['slot_names'],
		]);
		$plan['asset_manifest']=self::build_asset_manifest_from_plan($plan);
		return $plan;
	}

	private static function merge_plan_aggregate(array $base, array $addition): array {
		foreach($addition as $key=>$value){
			if(!isset($base[$key])){
				$base[$key]=[];
			}
			if(!is_array($value)){
				continue;
			}
			$base[$key]=array_merge($base[$key], $value);
		}
		return $base;
	}

	private static function normalize_plan_aggregate(array $aggregate): array {
		foreach([
			'data_paths',
			'top_level_data_keys',
			'slot_names',
			'assets',
			'translations',
			'tags',
			'filters',
			'helpers',
			'extensions',
		] as $bucket){
			$aggregate[$bucket]=self::unique_string_values(is_array($aggregate[$bucket] ?? null) ? $aggregate[$bucket] : []);
		}

		foreach([
			'partials',
			'components',
			'imports',
			'layouts',
			'dependencies',
		] as $bucket){
			$aggregate[$bucket]=self::unique_structured_values(is_array($aggregate[$bucket] ?? null) ? $aggregate[$bucket] : []);
		}

		return $aggregate;
	}

	private static function unique_string_values(array $values): array {
		$unique=[];
		foreach($values as $value){
			if(!is_string($value)){
				continue;
			}
			$value=trim($value);
			if($value!=='' && !in_array($value, $unique, true)){
				$unique[]=$value;
			}
		}
		return $unique;
	}

	private static function unique_structured_values(array $values): array {
		$unique=[];
		$seen=[];
		foreach($values as $value){
			if(!is_array($value)){
				continue;
			}
			$signature=md5(json_encode($value));
			if(isset($seen[$signature])){
				continue;
			}
			$seen[$signature]=true;
			$unique[]=$value;
		}
		return $unique;
	}

	private static function plan_registry_signature(): string {
		return sha1(json_encode([
			'helpers'=>array_values(array_keys(self::$helpers)),
			'extensions'=>array_values(array_keys(self::$extensions)),
			'tags'=>array_values(array_keys(self::$tags)),
			'filters'=>array_values(array_keys(self::$filters)),
		]));
	}

	private static function render_cache_signature(): string {
		return sha1(json_encode([
			'registry'=>self::plan_registry_signature(),
			'asset_policy'=>self::$asset_policy,
		]));
	}

	private static function normalize_asset_policy(array $policy): array {
		$preload_definition=is_array($policy['preload'] ?? null) ? $policy['preload'] : [];
		$scripts_definition=is_array($policy['scripts'] ?? null) ? $policy['scripts'] : [];
		$styles_definition=is_array($policy['styles'] ?? null) ? $policy['styles'] : [];
		$fonts_definition=is_array($policy['fonts'] ?? null) ? $policy['fonts'] : [];
		return [
			'preload'=>[
				'styles'=>self::bool_or_default($preload_definition['styles'] ?? $preload_definition['style'] ?? null, true),
				'scripts'=>self::bool_or_default($preload_definition['scripts'] ?? $preload_definition['script'] ?? null, true),
				'images'=>self::bool_or_default($preload_definition['images'] ?? $preload_definition['image'] ?? null, true),
				'fonts'=>self::bool_or_default($preload_definition['fonts'] ?? $preload_definition['font'] ?? null, true),
			],
			'scripts'=>[
				'strategy'=>self::normalize_script_strategy((string)($scripts_definition['strategy'] ?? 'blocking')),
				'type'=>self::normalize_script_type((string)($scripts_definition['type'] ?? 'auto')),
			],
			'styles'=>[
				'media'=>trim((string)($styles_definition['media'] ?? 'all')) ?: 'all',
			],
			'fonts'=>[
				'crossorigin'=>self::normalize_font_crossorigin($fonts_definition['crossorigin'] ?? 'anonymous'),
			],
		];
	}

	private static function bool_or_default(mixed $value, bool $default): bool {
		return is_bool($value) ? $value : $default;
	}

	private static function normalize_script_strategy(string $strategy): string {
		return match(strtolower(trim($strategy))){
			'async' => 'async',
			'defer' => 'defer',
			default => 'blocking',
		};
	}

	private static function normalize_script_type(string $type): string {
		return match(strtolower(trim($type))){
			'classic' => 'classic',
			'module' => 'module',
			default => 'auto',
		};
	}

	private static function normalize_font_crossorigin(mixed $value): ?string {
		$value=is_string($value) ? strtolower(trim($value)) : $value;
		return match($value){
			'use-credentials' => 'use-credentials',
			'none', '', null, false => null,
			default => 'anonymous',
		};
	}

	private static function descriptor_should_preload(array $descriptor): bool {
		$type=(string)($descriptor['type'] ?? '');
		$preload=self::$asset_policy['preload'] ?? [];
		return match($type){
			'style' => (bool)($preload['styles'] ?? true),
			'script' => (bool)($preload['scripts'] ?? true),
			'image' => (bool)($preload['images'] ?? true),
			'font' => (bool)($preload['fonts'] ?? true),
			default => false,
		};
	}

	private static function descriptor_script_type(array $descriptor): ?string {
		$mode=(string)(self::$asset_policy['scripts']['type'] ?? 'auto');
		if($mode==='module'){
			return 'module';
		}
		if($mode==='classic'){
			return null;
		}
		return strtolower((string)($descriptor['extension'] ?? ''))==='mjs' ? 'module' : null;
	}

	private static function script_attributes_from_descriptor(array $descriptor): string {
		$attributes=[];
		$script_type=self::descriptor_script_type($descriptor);
		if($script_type!==null){
			$attributes[]="type='{$script_type}'";
		}
		$strategy=(string)(self::$asset_policy['scripts']['strategy'] ?? 'blocking');
		if($strategy==='async'){
			$attributes[]='async';
		}
		elseif($strategy==='defer' && $script_type!=='module'){
			$attributes[]='defer';
		}
		return $attributes===[] ? '' : ' '.implode(' ', $attributes);
	}

	private static function stylesheet_attributes_from_descriptor(array $descriptor): string {
		$media=trim((string)(self::$asset_policy['styles']['media'] ?? 'all'));
		if($media==='' || strtolower($media)==='all'){
			return '';
		}
		return " media='{$media}'";
	}

	private static function font_crossorigin_attribute(): string {
		$crossorigin=self::$asset_policy['fonts']['crossorigin'] ?? 'anonymous';
		return is_string($crossorigin) && $crossorigin!==''
			? " crossorigin='{$crossorigin}'"
			: '';
	}

	private static function preload_tag_from_descriptor(array $descriptor): string {
		$path=(string)($descriptor['path'] ?? '');
		$as=(string)($descriptor['preload_as'] ?? '');
		$crossorigin=($descriptor['type'] ?? null)==='font' ? self::font_crossorigin_attribute() : '';
		return "<link rel='preload' as='{$as}' href='{$path}'{$crossorigin}>";
	}

	private static function build_asset_manifest_from_plan(array $plan): array {
		$aggregate=is_array($plan['aggregate'] ?? null) ? $plan['aggregate'] : [];
		$asset_references=is_array($aggregate['assets'] ?? null) ? $aggregate['assets'] : (is_array($plan['assets'] ?? null) ? $plan['assets'] : []);
		$dependency_references=is_array($aggregate['dependencies'] ?? null) ? $aggregate['dependencies'] : (is_array($plan['dependencies'] ?? null) ? $plan['dependencies'] : []);
		$items=[];
		$missing=[];

		foreach($asset_references as $reference){
			if(!is_string($reference) || trim($reference)===''){
				continue;
			}
			$descriptor=self::resolve_asset_descriptor($reference, 'asset');
			$descriptor['origin']='asset';
			$items[]=$descriptor;
			if(($descriptor['exists'] ?? false)!==true){
				$missing[]=[
					'type'=>'asset',
					'reference'=>$reference,
					'path'=>$descriptor['path'] ?? null,
				];
			}
		}

		foreach($dependency_references as $dependency){
			if(!is_array($dependency)){
				continue;
			}
			$type=(string)($dependency['type'] ?? 'asset');
			$reference=(string)($dependency['reference'] ?? '');
			if($reference===''){
				continue;
			}
			$descriptor=self::resolve_asset_descriptor($reference, $type);
			$descriptor['origin']='dependency';
			if(isset($dependency['condition']) && is_string($dependency['condition']) && $dependency['condition']!==''){
				$descriptor['condition']=$dependency['condition'];
			}
			$items[]=$descriptor;
			if(($descriptor['exists'] ?? false)!==true){
				$missing[]=[
					'type'=>$type,
					'reference'=>$reference,
					'path'=>$descriptor['path'] ?? null,
				];
			}
		}

		$items=self::unique_asset_descriptors($items);
		$stylesheets=array_values(array_filter($items, static fn(array $item): bool => ($item['type'] ?? null)==='style'));
		$scripts=array_values(array_filter($items, static fn(array $item): bool => ($item['type'] ?? null)==='script'));
		$images=array_values(array_filter($items, static fn(array $item): bool => ($item['type'] ?? null)==='image'));
		$fonts=array_values(array_filter($items, static fn(array $item): bool => ($item['type'] ?? null)==='font'));
		$preloads=array_values(array_filter($items, static fn(array $item): bool => !empty($item['preload_as']) && self::descriptor_should_preload($item)));
		$stylesheet_tags=array_map(static fn(array $item): string => self::asset_tag_from_descriptor($item, 'css'), $stylesheets);
		$script_tags=array_map(static fn(array $item): string => self::asset_tag_from_descriptor($item, 'js'), $scripts);
		$preload_tags=array_map(static fn(array $item): string => self::preload_tag_from_descriptor($item), $preloads);
		$head_items=self::unique_asset_descriptors(array_merge($preloads, $stylesheets));
		$body_items=self::unique_asset_descriptors($scripts);
		$head_tags=array_values(array_merge($preload_tags, $stylesheet_tags));
		$body_tags=array_values($script_tags);
		$all_tags=array_values(array_merge($head_tags, $body_tags));

		return [
			'items'=>$items,
			'stylesheets'=>$stylesheets,
			'scripts'=>$scripts,
			'images'=>$images,
			'fonts'=>$fonts,
			'preloads'=>$preloads,
			'head_items'=>$head_items,
			'body_items'=>$body_items,
			'stylesheet_tags'=>$stylesheet_tags,
			'script_tags'=>$script_tags,
			'preload_tags'=>$preload_tags,
			'head_tags'=>$head_tags,
			'body_tags'=>$body_tags,
			'all_tags'=>$all_tags,
			'head_html'=>implode("\n", $head_tags),
			'body_html'=>implode("\n", $body_tags),
			'html'=>implode("\n", $all_tags),
			'policy'=>self::$asset_policy,
			'missing'=>self::unique_structured_values($missing),
			'signature'=>sha1(json_encode([
				'items'=>array_map(static fn(array $item): array => [
					'path'=>$item['path'] ?? null,
					'type'=>$item['type'] ?? null,
					'preload_as'=>$item['preload_as'] ?? null,
					'condition'=>$item['condition'] ?? null,
				], $items),
				'policy'=>self::$asset_policy,
			])),
		];
	}

	private static function unique_asset_descriptors(array $items): array {
		$unique=[];
		$seen=[];
		foreach($items as $item){
			if(!is_array($item)){
				continue;
			}
			$signature=md5(json_encode([
				'path'=>$item['path'] ?? null,
				'type'=>$item['type'] ?? null,
				'preload_as'=>$item['preload_as'] ?? null,
			]));
			if(isset($seen[$signature])){
				continue;
			}
			$seen[$signature]=true;
			$unique[]=$item;
		}
		return $unique;
	}

	// TODO: REMOVE, move to shopiro theme functions
    public static function adapt(array $values, ?bool $spacing=false): string {
        $user_theme_mode=defined('VISITOR_CTX') ? VISITOR_CTX->theme_mode : 'default';
        if(!empty($values[$user_theme_mode])){
            return $spacing ? " ".$values[$user_theme_mode]." " : $values[$user_theme_mode];
        }
        return '';
    }

}

templating::init();
