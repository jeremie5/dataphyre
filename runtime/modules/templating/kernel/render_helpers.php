<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

/**
 * Supplies helper, filter, asset, markdown, and formatting passes for Dataphyre templates.
 *
 * The trait is mixed into the templating runtime and assumes access to the renderer's current
 * data, manifest recording helpers, registered helper/filter registries, and asset resolution
 * services. Each pass transforms template markup into escaped output while recording discovered
 * dependencies for manifests and diagnostics.
 */
trait render_helpers {
	
	/**
	 * Replaces `{{asset "..."}}` markers with versioned asset paths.
	 *
	 * Asset references are resolved through the owning renderer so paths, preloads, and existence
	 * checks remain consistent with the template manifest.
	 *
	 * @param string $template Template source containing asset directives.
	 * @return string Template source with asset directives replaced by resolved paths.
	 */
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
	
	/**
	 * Executes registered helper calls embedded in template markup.
	 *
	 * Helper expressions use `{{helperName(arg, ...)}}` syntax. Arguments may be quoted
	 * literals, booleans, nulls, numbers, or paths into the current render data.
	 *
	 * @param string $template Template source containing helper expressions.
	 * @return string Template source with helper expressions replaced by callback results.
	 */
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

	/**
	 * Registers a callable helper available to subsequent template renders.
	 *
	 * @param string $name Helper name used in template expressions.
	 * @param callable $helper Callable invoked with parsed template arguments.
	 * @return void
	 */
	public static function register_helper(string $name, callable $helper): void {
		self::$helpers[$name]=$helper;
	}
	
	/**
	 * Applies pipe-style filters to escaped variable output.
	 *
	 * Pipeline expressions use `{{ path | filter | filter(arg) }}` syntax. The source value is
	 * read from current render data, transformed by registered filters, then HTML-escaped.
	 *
	 * @param string $template Template source containing pipeline expressions.
	 * @param array<string, callable> $filters Filter callables keyed by template filter name.
	 * @return string Template source with pipeline expressions rendered as escaped text.
	 */
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
	
	/**
	 * Registers a callable extension available to subsequent template renders.
	 *
	 * Extensions share the same invocation syntax as helpers but are kept in a separate registry
	 * so renderer integrations can expose a distinct extension namespace.
	 *
	 * @param string $name Extension name used in template expressions.
	 * @param callable $extension Callable invoked with parsed template arguments.
	 * @return void
	 */
	public static function register_extension(string $name, callable $extension): void {
		self::$extensions[$name]=$extension;
	}
	
	/**
	 * Executes registered extension calls embedded in template markup.
	 *
	 * @param string $template Template source containing extension expressions.
	 * @return string Template source with extension expressions replaced by callback results.
	 */
	public static function apply_extensions($template){
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
	
	/**
	 * Converts Dataphyre's lightweight markdown subset into HTML.
	 *
	 * Fenced code blocks are delegated to Datadoc highlighting when available; otherwise they are
	 * escaped into plain `<pre><code>` blocks. Inline markdown is intentionally small and focused
	 * on headings, strong text, links, rules, inline code, and line breaks.
	 *
	 * @param string $template Markdown-flavored template content.
	 * @return string HTML fragment wrapped in a container div.
	 */
	private static function parse_markdown(string $template): string {
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
	
	/**
	 * Replaces global context placeholders with escaped values.
	 *
	 * @param string $template Template source containing `{{global.key}}` placeholders.
	 * @return string Template source with global context placeholders replaced.
	 */
    private static function apply_global_context(string $template){
        foreach(self::$global_context as $key=>$value){
            $template=str_replace("{{global.$key}}", htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $template);
        }
        return $template;
    }
	
    /**
     * Adds a value to the renderer-wide global context map.
     *
     * @param string $key Placeholder key used as `{{global.key}}`.
     * @param mixed $value Scalar-like value rendered through HTML escaping.
     * @return void
     */
    public static function add_to_global_context(string $key, mixed$value): void {
        self::$global_context[$key]=$value;
    }

	/**
	 * Parses a comma-separated template argument list into runtime values.
	 *
	 * @param string $argument_string Raw argument text between helper or extension parentheses.
	 * @return array<int, mixed> Parsed argument values.
	 */
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

	/**
	 * Splits an argument list while preserving quoted commas and escape sequences.
	 *
	 * @param string $argument_string Raw argument text.
	 * @return array<int, string> Individual unresolved argument expressions.
	 */
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

	/**
	 * Converts one template argument expression into a PHP value.
	 *
	 * Quoted strings are unescaped, booleans and null are recognized case-insensitively, numeric
	 * values are converted, and remaining identifiers are resolved from render data when a path
	 * exists.
	 *
	 * @param string $argument Raw argument expression.
	 * @return mixed parsed string, number, boolean, null literal, render-data lookup value, or raw argument fallback.
	 */
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

	/**
	 * Resolves numeric and data-path arguments that were not recognized as simple literals.
	 *
	 * @param string $argument Raw non-literal argument expression.
	 * @return mixed Numeric value, render-data value, or original text when no path exists.
	 */
	private static function resolve_non_literal_template_argument(string $argument): mixed {
		if(is_numeric($argument)){
			return str_contains($argument, '.') ? (float)$argument : (int)$argument;
		}
		return self::data_path_exists(self::$current_render_data, $argument)
			? self::get_value_by_path(self::$current_render_data, $argument)
			: $argument;
	}

	/**
	 * Parses one filter invocation from a pipeline expression.
	 *
	 * @param string $expression Filter name with optional parenthesized arguments.
	 * @return array{name:string, args:array<int, mixed>} Parsed filter name and arguments.
	 */
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

	/**
	 * Invokes a template callable with only the arguments it can accept.
	 *
	 * Variadic callables receive the full argument list; fixed-arity callables receive a sliced
	 * argument list so templates can remain forgiving when extra context is supplied.
	 *
	 * @param callable $callable Helper, extension, or filter callable.
	 * @param array<int, mixed> $arguments Parsed template arguments.
	 * @return mixed value returned by the helper callable after argument arity is matched.
	 */
	private static function invoke_template_callable(callable $callable, array $arguments): mixed {
		$reflection=self::reflect_template_callable($callable);
		if($reflection->isVariadic()){
			return call_user_func_array($callable, $arguments);
		}
		return call_user_func_array($callable, array_slice($arguments, 0, $reflection->getNumberOfParameters()));
	}

	/**
	 * Produces reflection metadata for any callable shape supported by the renderer.
	 *
	 * @param callable $callable Closure, function name, static method, array callable, or invokable object.
	 * @return \ReflectionFunctionAbstract Reflection object used for arity-aware invocation.
	 */
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

	/**
	 * Formats a money-like value for template output.
	 *
	 * The formatter accepts Dataphyre money objects, generic objects with `format()`, numeric
	 * amounts routed through the currency module, and scalar fallbacks. Optional arguments can
	 * select original/base/display currency behavior, an explicit currency, and free-label
	 * display.
	 *
	 * @param mixed $value Money object, numeric amount, scalar fallback, or null.
	 * @param mixed ...$args Variant, currency, and show-free options from template filters.
	 * @return string Formatted money text or an empty string for unsupported values.
	 */
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

	/**
	 * Normalizes money filter arguments into variant, currency, and show-free options.
	 *
	 * @param array<int, mixed> $arguments Raw parsed filter arguments.
	 * @return array{0:?string, 1:?string, 2:bool} Variant, currency target, and show-free flag.
	 */
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

	/**
	 * Selects the money subject variant before formatting.
	 *
	 * @param mixed $value Original value passed to the money formatter.
	 * @param ?string $variant Explicit `base` or `original` selector.
	 * @return mixed Selected money object/value to format.
	 */
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

	/**
	 * Normalizes a requested money currency target.
	 *
	 * @param ?string $currency Raw currency argument from template syntax.
	 * @return ?string `display`, `base`, uppercase currency code, or null when omitted.
	 */
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
