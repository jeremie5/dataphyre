<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait render_helpers {
	
	private static function parse_assets(string $template): string {
		preg_match_all('/{{asset "(.+?)"}}/', $template, $matches);
		foreach($matches[1] as $asset){
			$descriptor=self::resolve_asset_descriptor($asset, 'asset');
			$versioned_path=$descriptor['path'];
			self::record_manifest_structured('assets', [
				'type'=>$descriptor['type'],
				'reference'=>$asset,
				'path'=>$versioned_path,
				'preload_as'=>$descriptor['preload_as'],
				'exists'=>$descriptor['exists'],
			]);
			$template=str_replace("{{asset \"$asset\"}}", $versioned_path, $template);
		}
		return $template;
	}
	
	public static function apply_helpers(string $template): string {
		foreach(self::$helpers as $func=>$callback){
			preg_match_all("/{{".$func."\((.*?)\)}}/", $template, $matches, PREG_SET_ORDER);
			foreach($matches as $match){
				$args=self::parse_template_arguments($match[1]);
				self::record_manifest_value('helpers', $func);
				$result=self::invoke_template_callable($callback, $args);
				$template=str_replace($match[0], $result, $template);
			}
		}
		return $template;
	}

	public static function register_helper(string $name, callable $helper): void {
		self::$helpers[$name]=$helper;
	}
	
	private static function apply_pipelines(string $template, array $filters): string {
		return preg_replace_callback('/{{\s*([\w\.]+)\s*\|\s*([^}]+)\s*}}/', function(array $matches) use($filters): string {
			$value=self::get_value_by_path(self::$current_render_data, trim($matches[1])) ?? '';
			$transformations=array_map('trim', explode('|', trim($matches[2])));
			foreach($transformations as $filter_expression){
				$filter=self::parse_filter_invocation($filter_expression);
				$filter_name=(string)($filter['name'] ?? '');
				$filter_args=is_array($filter['args'] ?? null) ? $filter['args'] : [];
				if($filter_name!=='' && isset($filters[$filter_name])){
					self::record_manifest_value('filters', $filter_name);
					$value=self::invoke_template_callable($filters[$filter_name], array_merge([$value], $filter_args));
				}
			}
			return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
		}, $template) ?? $template;
	}
	
	public static function register_extension(string $name, callable $extension): void {
		self::$extensions[$name]=$extension;
	}
	
	public static function applyExtensions($template){
		foreach(self::$extensions as $name=>$extension){
			preg_match_all("/{{".$name."\((.*?)\)}}/", $template, $matches, PREG_SET_ORDER);
			foreach($matches as $match){
				$args=self::parse_template_arguments($match[1]);
				self::record_manifest_value('extensions', $name);
				$result=self::invoke_template_callable($extension, $args);
				$template=str_replace($match[0], $result, $template);
			}
		}
		return $template;
	}
	
	private static function parseMarkdown(string $template): string {
		$parts=preg_split('/(```.*?```)/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
		$theme_classes=[
			'light'=>'inline-code-light',
			'dark'=>'inline-code-dark',
		];
		foreach($parts as &$part){
			if(preg_match('/^```(.*?)\n(.*?)```$/s', $part, $matches)){
				$code_language=trim($matches[1]);
				$code=$matches[2];
				if(dp_module_present("datadoc")){
					$code=\dataphyre\datadoc\highlighter::retabulate_php($code);
					$code=\dataphyre\datadoc\highlighter::highlight_code($code, $code_language, [
						"show_lines"=>true, 
						"start_line"=>2
					]);
					$part=\dataphyre\datadoc\highlighter::linkify_php($code);
				}
				else
				{
					$part='<pre><code>'.htmlspecialchars($code, ENT_QUOTES, 'UTF-8').'</code></pre>';
				}
			}
			else
			{
				$part=preg_replace_callback('/`([^`]+)`/', static function($matches) use($theme_classes){
					$code=$matches[1];
					return "<code class='".\dataphyre\templating::adapt($theme_classes)."'>".htmlspecialchars($code, ENT_QUOTES, 'UTF-8')."</code>";
				}, $part);
				$part=preg_replace('/\#\#\#\#(.*?)\n/', '<h5>$1</h5>', $part);
				$part=preg_replace('/\#\#\#(.*?)\n/', '<h4>$1</h4>', $part);
				$part=preg_replace('/\#\#(.*?)\n/', '<h3>$1</h3>', $part);
				$part=preg_replace('/\#(.*?)\n/', '<h1>$1</h1>', $part);
				$part=preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $part);
				$part=preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2"><u>$1</u></a>', $part);
				$part=str_replace("#", '', $part);
				$part=str_replace("---", '<hr>', $part);
				$part=nl2br($part);
			}
		}
		return '<div>'.implode('', $parts).'</div>';
	}
	
    private static function applyGlobalContext(string $template){
        foreach(self::$global_context as $key=>$value){
            $template=str_replace("{{global.$key}}", htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $template);
        }
        return $template;
    }
	
    public static function addToGlobalContext(string $key, mixed$value): void {
        self::$global_context[$key]=$value;
    }

	private static function parse_template_arguments(string $argument_string): array {
		$argument_string=trim($argument_string);
		if($argument_string===''){
			return [];
		}
		$arguments=[];
		foreach(self::split_template_arguments($argument_string) as $argument){
			$arguments[]=self::resolve_template_argument($argument);
		}
		return $arguments;
	}

	private static function split_template_arguments(string $argument_string): array {
		$arguments=[];
		$current='';
		$quote=null;
		$length=strlen($argument_string);
		for($index=0; $index<$length; $index++){
			$character=$argument_string[$index];
			if($quote!==null){
				if($character==='\\' && $index+1<$length){
					$current.=$character.$argument_string[$index+1];
					$index++;
					continue;
				}
				if($character===$quote){
					$quote=null;
				}
				$current.=$character;
				continue;
			}
			if($character==="'" || $character==='"'){
				$quote=$character;
				$current.=$character;
				continue;
			}
			if($character===','){
				$arguments[]=trim($current);
				$current='';
				continue;
			}
			$current.=$character;
		}
		if(trim($current)!==''){
			$arguments[]=trim($current);
		}
		return $arguments;
	}

	private static function resolve_template_argument(string $argument): mixed {
		$argument=trim($argument);
		if($argument===''){
			return '';
		}
		$quote=$argument[0] ?? '';
		if(($quote==="'" || $quote==='"') && substr($argument, -1)===$quote){
			return stripcslashes(substr($argument, 1, -1));
		}
		$normalized=strtolower($argument);
		return match($normalized){
			'true' => true,
			'false' => false,
			'null' => null,
			default => self::resolve_non_literal_template_argument($argument),
		};
	}

	private static function resolve_non_literal_template_argument(string $argument): mixed {
		if(is_numeric($argument)){
			return str_contains($argument, '.') ? (float)$argument : (int)$argument;
		}
		return self::data_path_exists(self::$current_render_data, $argument)
			? self::get_value_by_path(self::$current_render_data, $argument)
			: $argument;
	}

	private static function parse_filter_invocation(string $expression): array {
		$expression=trim($expression);
		if($expression==='' || preg_match('/^([A-Za-z_]\w*)\s*(?:\((.*)\))?$/', $expression, $matches)!==1){
			return ['name'=>$expression, 'args'=>[]];
		}
		return [
			'name'=>$matches[1],
			'args'=>isset($matches[2]) ? self::parse_template_arguments($matches[2]) : [],
		];
	}

	private static function invoke_template_callable(callable $callable, array $arguments): mixed {
		$reflection=self::reflect_template_callable($callable);
		if($reflection->isVariadic()){
			return call_user_func_array($callable, $arguments);
		}
		return call_user_func_array($callable, array_slice($arguments, 0, $reflection->getNumberOfParameters()));
	}

	private static function reflect_template_callable(callable $callable): \ReflectionFunctionAbstract {
		if(is_array($callable)){
			return new \ReflectionMethod($callable[0], $callable[1]);
		}
		if(is_string($callable) && str_contains($callable, '::')){
			return new \ReflectionMethod($callable);
		}
		if(is_object($callable) && !$callable instanceof \Closure){
			return new \ReflectionMethod($callable, '__invoke');
		}
		return new \ReflectionFunction($callable);
	}

	private static function format_money_value(mixed $value, mixed ...$args): string {
		[$variant, $currency, $show_free]=self::normalize_money_arguments($args);
		$value=self::normalize_money_subject($value, $variant);
		if(is_object($value) && method_exists($value, 'convertedTo') && method_exists($value, 'currency') && method_exists($value, 'format')){
			$target_currency=self::normalize_money_currency_target($currency);
			if($target_currency==='display' && method_exists($value, 'inDisplayCurrency')){
				$value=$value->inDisplayCurrency();
			}
			elseif($target_currency==='base' && method_exists($value, 'inBaseCurrency')){
				$value=$value->inBaseCurrency();
			}
			elseif(is_string($target_currency) && $target_currency!=='' && mb_strtoupper($target_currency)!==mb_strtoupper((string)$value->currency())){
				$value=$value->convertedTo($target_currency);
			}
			return (string)$value->format($show_free);
		}
		if(is_object($value) && method_exists($value, 'format')){
			return (string)$value->format($show_free);
		}
		if((is_int($value) || is_float($value) || is_numeric($value) || $value===null) && class_exists(__NAMESPACE__.'\\currency', false)){
			$currency_argument=is_string($currency) && trim($currency)!=='' && !in_array(strtolower(trim($currency)), ['display', 'base', 'original'], true)
				? trim($currency)
				: null;
			return (string)\dataphyre\currency::formatter((float)$value, $show_free, $currency_argument);
		}
		if(is_scalar($value)){
			return (string)$value;
		}
		return '';
	}

	private static function normalize_money_arguments(array $arguments): array {
		$variant=null;
		if(isset($arguments[0]) && is_string($arguments[0])){
			$candidate=strtolower(trim($arguments[0]));
			if(in_array($candidate, ['original', 'base'], true)){
				$variant=$candidate;
				array_shift($arguments);
			}
		}
		$currency=null;
		$show_free=false;
		foreach($arguments as $argument){
			if(is_bool($argument)){
				$show_free=$argument;
				continue;
			}
			if($currency===null && (is_string($argument) || is_numeric($argument))){
				$currency=(string)$argument;
			}
		}
		return [$variant, $currency, $show_free];
	}

	private static function normalize_money_subject(mixed $value, ?string $variant): mixed {
		if($variant==='base' && is_object($value) && method_exists($value, 'base')){
			return $value->base();
		}
		if($variant==='original' && is_object($value) && method_exists($value, 'original')){
			return $value->original();
		}
		if($variant===null && is_object($value) && method_exists($value, 'original') && method_exists($value, 'base') && !method_exists($value, 'format')){
			return $value->original();
		}
		return $value;
	}

	private static function normalize_money_currency_target(?string $currency): ?string {
		if($currency===null){
			return null;
		}
		$currency=trim($currency);
		if($currency===''){
			return null;
		}
		$normalized=strtolower($currency);
		return in_array($normalized, ['display', 'base'], true) ? $normalized : mb_strtoupper($currency);
	}
	
}
