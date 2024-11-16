<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

dp_module_required('templating', 'async');

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
		private static function parse_scoped_styles(string $template, string $component_name): string {
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
		private static $events=[ 'before_render' => [], 'after_render' => [], 'on_error' => [] ];
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
    private static $is_dev_mode;
    private static $extensions=[];
    private static $global_context=[];
	private static $helpers=[];
	
	public function __construct(bool $is_dev_mode=false){
		global $rootpath;
		self::$helpers=[
			'date_format'=>function($date, $format){ return date($format, strtotime($date)); },
			'slugify'=>function($text){ return strtolower(preg_replace('/\W+/', '-', trim($text))); }
		];
		self::$cache_dir=$rootpath['dataphyre'].'cache/templating/';
		self::$is_dev_mode=$is_dev_mode;
	}

    public static function adapt(array $values, bool $spacing=false): string {
        global $user_theme_mode;
        if(!empty($values[$user_theme_mode])){
            return $spacing ? " ".$values[$user_theme_mode]." " : $values[$user_theme_mode];
        }
        return '';
    }
	
	private static function load_template_file(string $filename): string {
		return file_get_contents($filename);
	}

	private static function bind_data(string $template, array $data): string {
		preg_match_all('/{{\s*(\w+(\.\w+)*)\s*}}/', $template, $matches);
		foreach ($matches[1] as $full_path) {
			$value = self::get_value_by_path($data, $full_path);
			$template = str_replace("{{ $full_path }}", htmlspecialchars($value ?? '[Undefined]'), $template);
		}
		return $template;
	}
	
	private static function get_value_by_path(array|object|null $data, string $path): mixed {
		$segments = explode('.', $path);
		foreach ($segments as $segment) {
			if (is_array($data) && array_key_exists($segment, $data)) {
				$data = $data[$segment];
			}
			elseif (is_object($data) && property_exists($data, $segment)) {
				$data = $data->$segment;
			}
			else
			{
				return null;
			}
		}
		return $data;
	}
	
	private static function bind_if(string $variable, mixed $value, callable $condition): void {
		if ($condition()) {
			self::$global_context[$variable] = $value;
		}
	}
	
	private static function set_local(string $key, mixed $value): void {
		self::$global_context[$key] = $value;
	}
	
	private static function unset_local(string $key): void {
		unset(self::$global_context[$key]);
	}
		
	private static function with_context(array $data, callable $block): string {
		$previousContext = self::$global_context;
		self::$global_context = array_merge(self::$global_context, $data);
		$output = $block();
		self::$global_context = $previousContext;
		return $output;
	}
	
	private static function for_each_scoped(array $items, callable $callback): string {
		$output = '';
		$total = count($items);
		foreach ($items as $index => $item) {
			$scope = [
				'index' => $index,
				'first' => $index === 0,
				'last' => $index === $total - 1,
				'item' => $item
			];
			$output .= self::with_context($scope, fn() => $callback($item, $scope));
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
		foreach($matches[1] as $variable){
			if(!isset($data[$variable])){
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
                $value=self::get_value_by_path($data, $full_key);
				$template=str_replace("{{".$full_key."}}", htmlspecialchars($value ?? ''), $template);
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

    public static function apply_filters(string $template, array $filters=[]): string {
        foreach($filters as $filter=>$callback){
            preg_match_all("/{{(\w+)\s*\|\s*".$filter."}}/", $template, $matches, PREG_SET_ORDER);
            foreach($matches as $match){
                $value=$match[1];
                if(isset($data[$value])){
                    $result=$callback($data[$value]);
                    $template=str_replace($match[0], htmlspecialchars($result), $template);
                }
            }
        }
        return $template;
    }
	
	private static function compose_components(string $template, array $data): string {
		preg_match_all('/{{component\s*\'(\w+)\'}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$component_name=$match[1];
			$component_content=self::load_component($component_name, $data);
			$template=str_replace($match[0], $component_content, $template);
		}
		return $template;
	}

	private static function load_component(string $component_name, array $data): string {
		$componentPath="components/{$component_name}.tpl";
		if(file_exists($componentPath)){
			$componentTemplate=file_get_contents($componentPath);
			return self::render($componentTemplate, $data);
		}
		return '';
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
			if($type==='css'){
				$path="assets/$file.css?v=" . filemtime("assets/$file.css");
				$tag="<link rel='stylesheet' href='$path'>";
			}
			else
			{
				$path="assets/$file.js?v=" . filemtime("assets/$file.js");
				$tag="<script src='$path'></script>";
			}
			$template=str_replace($match[0], $tag, $template);
		}
		return $template;
	}

    private static function conditional_asset_import(string $template, array$data): string {
        preg_match_all('/{{loadCSS "(.+?)"( if(\w+))?}}/', $template, $matches);
        foreach($matches[1] as $index=>$asset){
            $condition=$matches[3][$index] ?? null;
            if(!$condition ||($condition && !empty($data[$condition]))){
                $path="assets/$asset.css?v=".filemtime("assets/$asset.css");
                $template=str_replace($matches[0][$index], "<link rel='stylesheet' href='$path'>", $template);
            }
        }
        preg_match_all('/{{loadJS "(.+?)"( if(\w+))?}}/', $template, $matches);
        foreach($matches[1] as $index=>$asset){
            $condition=$matches[3][$index] ?? null;
            if(!$condition ||($condition && !empty($data[$condition]))){
                $path="assets/$asset.js?v=".filemtime("assets/$asset.js");
                $template=str_replace($matches[0][$index], "<script src='$path'></script>", $template);
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

}