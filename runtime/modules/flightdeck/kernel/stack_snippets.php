<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
	return;
}

final class dataphyre_flightdeck_stack_snippets {

	public static function frames_from_exception(?object $exception): array {
		if($exception===null){
			return [];
		}
		$frames=[[
			'index'=>0,
			'file'=>$exception->getFile(),
			'line'=>$exception->getLine(),
			'function'=>'throw',
			'symbol'=>'throw',
			'kind'=>'origin',
		]];
		foreach($exception->getTrace() as $frame){
			if(!empty($frame['file']) && !empty($frame['line'])){
				$frame['index']=count($frames);
				$frame['symbol']=self::frame_symbol($frame);
				$frame['kind']='callsite';
				$frames[]=$frame;
			}
		}
		return $frames;
	}

	public static function frames_from_log_entry(string $entry): array {
		$text=html_entity_decode(strip_tags($entry), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		if($text===''){
			return [];
		}
		preg_match_all('/(?:^|[\r\n]|(?:Stack Trace|Trace):[ \t]*)[ \t]*#(?P<index>\d+)\s+(?P<file>[^\r\n]+?\.php)\((?P<line>\d+)\):\s*(?P<call>[^\r\n]+)/i', $text, $matches, PREG_SET_ORDER);
		$frames=[];
		foreach($matches as $match){
			$file=trim((string)$match['file']);
			$line=(int)$match['line'];
			if($file==='' || $line<=0){
				continue;
			}
			$call=trim((string)$match['call']);
			$frames[]=[
				'index'=>(int)$match['index'],
				'file'=>$file,
				'line'=>$line,
				'function'=>$call,
				'symbol'=>$call,
				'kind'=>'callsite',
			];
		}
		return $frames;
	}

	public static function frame_symbol(array $frame): string {
		$function=(string)($frame['function'] ?? $frame['call'] ?? '');
		if($function===''){
			return 'unknown frame';
		}
		$class=(string)($frame['class'] ?? '');
		$type=(string)($frame['type'] ?? ($class!=='' ? '::' : ''));
		return $class!=='' ? $class.$type.$function : $function;
	}

	public static function render_stack_map(array $frames, array $options=[]): string {
		$id_prefix=(string)($options['id_prefix'] ?? 'fd-frame-');
		$html='<div class="fd-stack-map" aria-label="Call stack reference map">';
		foreach($frames as $frame){
			$index=(int)($frame['index'] ?? 0);
			$symbol=(string)($frame['symbol'] ?? self::frame_symbol($frame));
			$label=$index===0 ? 'Origin' : 'Callsite';
			$html.='<a href="#'.self::e($id_prefix.$index).'" title="'.self::e($symbol).'"><span>#'.$index.'</span>'.self::e($label).'</a>';
		}
		return $html.'</div>';
	}

	public static function render_panel(array $frames, array $options=[]): string {
		if($frames===[]){
			return '';
		}
		$details_class=(string)($options['details_class'] ?? 'fd-log-stack');
		$summary=(string)($options['summary'] ?? 'Stack Trace Snippets');
		$limit=max(1, (int)($options['limit'] ?? 12));
		$html='<details class="'.self::e($details_class).'"><summary>'.self::e($summary).' <span>'.count($frames).' frame'.(count($frames)===1 ? '' : 's').'</span></summary>';
		if(!empty($options['diagnostic_text'])){
			$html.=self::render_diagnostics((string)$options['diagnostic_text'], $frames, [
				'compact'=>true,
				'title'=>'Smart Diagnostics',
			]);
		}
		if(($options['show_stack_map'] ?? false)===true){
			$html.=self::render_stack_map(array_slice($frames, 0, $limit), $options);
		}
		foreach(array_slice($frames, 0, $limit) as $frame){
			$html.=self::render_snippet($frame, $frames, $options);
		}
		return $html.'</details>';
	}

	public static function render_snippet(array $frame, array $frames=[], array $options=[]): string {
		$file=(string)($frame['file'] ?? '');
		$line=(int)($frame['line'] ?? 0);
		$frame_index=(int)($frame['index'] ?? 0);
		$id_prefix=(string)($options['id_prefix'] ?? 'fd-frame-');
		$snippet_class='fd-snippet'.(!empty($options['compact']) ? ' fd-snippet-compact' : '');
		if(!empty($options['class_suffix'])){
			$snippet_class.=' '.(string)$options['class_suffix'];
		}
		$symbol=(string)($frame['symbol'] ?? self::frame_symbol($frame));
		$actions=(($options['show_actions'] ?? true)===true);
		$meta=(($options['show_meta'] ?? true)===true);
		if($file==='' || $line<=0 || !is_file($file) || !is_readable($file)){
			return '<div class="'.self::e($snippet_class).'" id="'.self::e($id_prefix.$frame_index).'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file ?: 'Unknown file').'</h3>'.($actions ? self::snippet_actions([], $frame, $frames, $options) : '').'</div><p class="fd-muted">Source unavailable.</p></div>';
		}
		$context_lines=max(1, (int)($options['context_lines'] ?? 8));
		$start=max(1, $line - $context_lines);
		$lines=@file($file, FILE_IGNORE_NEW_LINES);
		if(!is_array($lines)){
			return '<div class="'.self::e($snippet_class).'" id="'.self::e($id_prefix.$frame_index).'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file).'</h3>'.($actions ? self::snippet_actions([], $frame, $frames, $options) : '').'</div><p class="fd-muted">Source unreadable.</p></div>';
		}
		$selected=array_slice($lines, $start - 1, ($context_lines * 2) + 1, true);
		$selected=self::normalize_snippet_lines($selected);
		$code=implode("\n", $selected);
		$datadoc_context=self::datadoc_frame_context($file, $line);
		$highlighted=self::datadoc_highlight($code, $start, $line, $datadoc_context, $options);
		if($highlighted===null){
			$highlighted='<pre class="fd-code">';
			foreach($selected as $source_index=>$source_line){
				$current=$source_index + 1;
				$classes=[];
				if($current===$line){
					$classes[]='fd-hit';
					if(($frame['kind'] ?? '')==='callsite'){
						$classes[]='fd-callsite';
					}
				}
				$highlighted.='<span class="'.self::e(implode(' ', $classes)).'"><b>'.str_pad((string)$current, 5, ' ', STR_PAD_LEFT).'</b> '.self::e((string)$source_line).'</span>'."\n";
			}
			$highlighted.='</pre>';
		}
		return '<div class="'.self::e($snippet_class).'" id="'.self::e($id_prefix.$frame_index).'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file).':'.self::e((string)$line).'</h3>'.($actions ? self::snippet_actions($datadoc_context, $frame, $frames, $options) : '').'</div>'.($meta ? self::stack_reference_badge($frame, $symbol) : '').$highlighted.'</div>';
	}

	public static function render_diagnostics(?string $message, array $frames=[], array $options=[]): string {
		$message=trim((string)$message);
		if($message===''){
			return '';
		}
		$items=self::diagnostic_items($message, $frames);
		if($items===[]){
			return '';
		}
		$class='fd-diagnostics'.(!empty($options['compact']) ? ' fd-diagnostics-compact' : '');
		$title=(string)($options['title'] ?? 'Smart Diagnostics');
		$html='<div class="'.self::e($class).'"><h2>'.self::e($title).'</h2>';
		foreach($items as $item){
			$html.='<article class="fd-diagnostic fd-diagnostic-'.self::e((string)($item['tone'] ?? 'info')).'"><h3>'.self::e((string)$item['title']).'</h3>';
			if(!empty($item['summary'])){
				$html.='<p>'.self::e((string)$item['summary']).'</p>';
			}
			if(!empty($item['rows']) && is_array($item['rows'])){
				$html.='<dl>';
				foreach($item['rows'] as $key=>$value){
					$html.='<dt>'.self::e((string)$key).'</dt><dd>'.self::e((string)$value).'</dd>';
				}
				$html.='</dl>';
			}
			if(!empty($item['suggestions']) && is_array($item['suggestions'])){
				$html.='<p><b>Suggestions</b> '.self::e(implode(', ', array_map('strval', $item['suggestions']))).'</p>';
			}
			$html.='</article>';
		}
		return $html.'</div>';
	}

	private static function stack_reference_badge(array $frame, string $symbol): string {
		$kind=(string)($frame['kind'] ?? '');
		if($kind==='origin'){
			return '<p class="fd-snippet-meta"><b>Origin</b> The exception surfaced here before the call stack unwound.</p>';
		}
		if($kind==='callsite'){
			return '<p class="fd-snippet-meta"><b>Callsite</b> This line references <code>'.self::e($symbol).'</code>.</p>';
		}
		return '';
	}

	private static function diagnostic_items(string $message, array $frames): array {
		$text=html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$items=[];
		foreach(self::type_error_argument_items($text) as $item){
			$items[]=$item;
		}
		foreach(self::include_path_items($text, $frames) as $item){
			$items[]=$item;
		}
		foreach(self::undefined_variable_items($text, $frames) as $item){
			$items[]=$item;
		}
		foreach(self::undefined_function_items($text) as $item){
			$items[]=$item;
		}
		foreach(self::undefined_method_items($text) as $item){
			$items[]=$item;
		}
		foreach(self::undefined_property_items($text) as $item){
			$items[]=$item;
		}
		foreach(self::undefined_constant_items($text) as $item){
			$items[]=$item;
		}
		foreach(self::missing_class_items($text) as $item){
			$items[]=$item;
		}
		return $items;
	}

	private static function include_path_items(string $text, array $frames): array {
		$paths=[];
		$patterns=[
			'/Failed opening (?:required|included) [\'"]([^\'"]+)[\'"]/i',
			'/(?:require|include)(?:_once)?\s*\(\s*([^\)]+?)\s*\): Failed to open stream/i',
		];
		foreach($patterns as $pattern){
			if(preg_match_all($pattern, $text, $matches)){
				foreach($matches[1] as $path){
					$path=trim((string)$path, " \t\n\r\0\x0B'\"");
					if($path!=='' && !str_contains($path, 'include_path=')){
						$paths[$path]=true;
					}
				}
			}
		}
		$items=[];
		foreach(array_slice(array_keys($paths), 0, 4) as $path){
			$analysis=self::analyze_include_path($path, $frames);
			$items[]=[
				'title'=>'Include path check',
				'tone'=>($analysis['exists'] ?? false) ? 'ok' : 'warning',
				'summary'=>($analysis['exists'] ?? false)
					? 'The requested include target exists on disk; inspect readability and the including line.'
					: 'The requested include target was not found at the checked locations.',
				'rows'=>$analysis['rows'],
			];
		}
		return $items;
	}

	private static function type_error_argument_items(string $text): array {
		if(!preg_match_all('/(?P<callable>[\\\\A-Za-z_][\\\\A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)?)\(\): Argument #(?P<number>\d+) \(\$(?P<parameter>[A-Za-z_][A-Za-z0-9_]*)\) must be of type (?P<expected>[^,]+), (?P<actual>[^,]+) given, called in (?P<file>.+?) on line (?P<line>\d+)/i', $text, $matches, PREG_SET_ORDER)){
			return [];
		}
		$items=[];
		foreach($matches as $match){
			$file=trim((string)$match['file']);
			$line=(int)$match['line'];
			$callable=(string)$match['callable'];
			$argument_number=(int)$match['number'];
			$expression=self::call_argument_expression($file, $line, $callable, $argument_number);
			$expression_analysis=$expression!=='' ? self::analyze_expression_value($expression) : [];
			$rows=[
				'Callable'=>$callable,
				'Argument'=>'#'.$argument_number.' $'.(string)$match['parameter'],
				'Expected type'=>trim((string)$match['expected']),
				'Actual type'=>trim((string)$match['actual']),
				'Callsite'=>$file.':'.$line,
				'Argument expression'=>$expression!=='' ? $expression : 'unresolved',
			];
			foreach($expression_analysis['rows'] ?? [] as $key=>$value){
				$rows[$key]=$value;
			}
			$items[]=[
				'title'=>'Argument type mismatch',
				'tone'=>'warning',
				'summary'=>$expression!=='' ? 'The callsite argument can be inspected directly; it appears to be the value that reached the typed parameter.' : 'The callsite was found in the error text, but the argument expression could not be extracted from source.',
				'rows'=>$rows,
				'suggestions'=>$expression_analysis['suggestions'] ?? [],
			];
		}
		return $items;
	}

	private static function call_argument_expression(string $file, int $line, string $callable, int $argument_number): string {
		if($file==='' || $line<=0 || !is_file($file) || !is_readable($file) || $argument_number<=0){
			return '';
		}
		$lines=@file($file, FILE_IGNORE_NEW_LINES);
		if(!is_array($lines) || !isset($lines[$line - 1])){
			return '';
		}
		$statement='';
		$total=count($lines);
		for($index=$line - 1; $index<min($total, $line + 8); $index++){
			$statement.=(string)$lines[$index]."\n";
			if(str_contains((string)$lines[$index], ';')){
				break;
			}
		}
		$callable_name=self::basename_callable($callable);
		$position=strpos($statement, $callable_name);
		if($position===false){
			return '';
		}
		$open=strpos($statement, '(', $position + strlen($callable_name));
		if($open===false){
			return '';
		}
		$arguments=self::split_call_arguments(substr($statement, $open + 1));
		return trim((string)($arguments[$argument_number - 1] ?? ''));
	}

	private static function split_call_arguments(string $input): array {
		$arguments=[];
		$current='';
		$paren=0;
		$bracket=0;
		$brace=0;
		$quote=null;
		$escaped=false;
		$length=strlen($input);
		for($index=0; $index<$length; $index++){
			$char=$input[$index];
			if($quote!==null){
				$current.=$char;
				if($escaped){
					$escaped=false;
				}
				elseif($char==='\\'){
					$escaped=true;
				}
				elseif($char===$quote){
					$quote=null;
				}
				continue;
			}
			if($char==='"' || $char==="'"){
				$quote=$char;
				$current.=$char;
				continue;
			}
			if($char==='('){
				$paren++;
				$current.=$char;
				continue;
			}
			if($char===')'){
				if($paren===0 && $bracket===0 && $brace===0){
					$arguments[]=$current;
					return $arguments;
				}
				$paren=max(0, $paren - 1);
				$current.=$char;
				continue;
			}
			if($char==='['){
				$bracket++;
				$current.=$char;
				continue;
			}
			if($char===']'){
				$bracket=max(0, $bracket - 1);
				$current.=$char;
				continue;
			}
			if($char==='{'){
				$brace++;
				$current.=$char;
				continue;
			}
			if($char==='}'){
				$brace=max(0, $brace - 1);
				$current.=$char;
				continue;
			}
			if($char===',' && $paren===0 && $bracket===0 && $brace===0){
				$arguments[]=$current;
				$current='';
				continue;
			}
			$current.=$char;
		}
		if(trim($current)!==''){
			$arguments[]=$current;
		}
		return $arguments;
	}

	private static function analyze_expression_value(string $expression): array {
		$expression=trim($expression);
		if($expression===''){
			return [];
		}
		if(strtolower($expression)==='null'){
			return ['rows'=>['Expression value'=>'literal null'], 'suggestions'=>[]];
		}
		if(preg_match('/^\\\\?dataphyre\\\\routing::\$bindings\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]$/', $expression, $match)){
			$key=(string)$match[1];
			$bindings=class_exists('\dataphyre\routing', false) ? \dataphyre\routing::$bindings : [];
			$rows=[
				'Runtime lookup'=>'\dataphyre\routing::$bindings',
				'Array key'=>$key,
			];
			$suggestions=[];
			if(is_array($bindings)){
				$exists=array_key_exists($key, $bindings);
				$keys=array_map('strval', array_keys($bindings));
				$suggestions=self::closest_names($key, $keys);
				$rows['Key exists']=self::yes_no($exists);
				$rows['Runtime value']=$exists ? self::value_summary($bindings[$key]) : 'missing key';
				$rows['Available keys']=$keys===[] ? 'none' : implode(', ', array_slice($keys, 0, 18));
			}
			else
			{
				$rows['Runtime value']='route bindings unavailable or not array';
			}
			return ['rows'=>$rows, 'suggestions'=>$suggestions];
		}
		if(preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]$/', $expression, $match)){
			$variable=(string)$match[1];
			$key=(string)$match[2];
			$value=$GLOBALS[$variable] ?? null;
			$rows=[
				'Runtime lookup'=>'$GLOBALS[\''.$variable.'\']',
			];
			$suggestions=[];
			if(is_array($value)){
				$exists=array_key_exists($key, $value);
				$keys=array_map('strval', array_keys($value));
				$suggestions=self::closest_names($key, $keys);
				$rows['Array key']=$key;
				$rows['Key exists']=self::yes_no($exists);
				$rows['Runtime value']=$exists ? self::value_summary($value[$key]) : 'missing key';
				$rows['Available keys']=$keys===[] ? 'none' : implode(', ', array_slice($keys, 0, 18));
			}
			else
			{
				$rows['Runtime value']='global variable unavailable or not array';
			}
			return ['rows'=>$rows, 'suggestions'=>$suggestions];
		}
		if(preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expression, $match)){
			$variable=(string)$match[1];
			$exists=array_key_exists($variable, $GLOBALS);
			return [
				'rows'=>[
					'Runtime lookup'=>'$GLOBALS[\''.$variable.'\']',
					'Global exists'=>self::yes_no($exists),
					'Runtime value'=>$exists ? self::value_summary($GLOBALS[$variable]) : 'unavailable',
				],
				'suggestions'=>[],
			];
		}
		return [];
	}

	private static function value_summary(mixed $value): string {
		if($value===null){
			return 'null';
		}
		if(is_bool($value)){
			return 'bool '.($value ? 'true' : 'false');
		}
		if(is_string($value)){
			$trimmed=substr($value, 0, 80);
			return 'string('.strlen($value).') "'.$trimmed.(strlen($value)>80 ? '...' : '').'"';
		}
		if(is_int($value) || is_float($value)){
			return gettype($value).' '.(string)$value;
		}
		if(is_array($value)){
			return 'array('.count($value).')';
		}
		if(is_object($value)){
			return 'object '.get_class($value);
		}
		return gettype($value);
	}

	private static function analyze_include_path(string $path, array $frames): array {
		$candidates=self::include_path_candidates($path, $frames);
		$resolved=$candidates[0] ?? $path;
		foreach($candidates as $candidate){
			if(file_exists($candidate)){
				$resolved=$candidate;
				break;
			}
		}
		$directory=dirname($resolved);
		return [
			'exists'=>file_exists($resolved),
			'rows'=>[
				'Requested'=>$path,
				'Resolved'=>$resolved,
				'Exists'=>self::yes_no(file_exists($resolved)),
				'Is file'=>self::yes_no(is_file($resolved)),
				'Readable'=>self::yes_no(is_readable($resolved)),
				'Writable'=>self::yes_no(is_writable($resolved)),
				'Directory'=>$directory,
				'Directory exists'=>self::yes_no(is_dir($directory)),
				'Directory readable'=>self::yes_no(is_readable($directory)),
				'Directory writable'=>self::yes_no(is_writable($directory)),
				'Checked candidates'=>implode(' | ', array_slice($candidates, 0, 5)),
			],
		];
	}

	private static function include_path_candidates(string $path, array $frames): array {
		$path=str_replace('\\', '/', $path);
		if(self::is_absolute_path($path) || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path)){
			return [$path];
		}
		$candidates=[];
		$source=self::source_frame($frames);
		if($source!==null){
			$candidates[]=rtrim(str_replace('\\', '/', dirname((string)$source['file'])), '/').'/'.$path;
		}
		$cwd=getcwd();
		if(is_string($cwd) && $cwd!==''){
			$candidates[]=rtrim(str_replace('\\', '/', $cwd), '/').'/'.$path;
		}
		foreach(explode(PATH_SEPARATOR, get_include_path()) as $include_path){
			if($include_path==='' || $include_path==='.') {
				continue;
			}
			$candidates[]=rtrim(str_replace('\\', '/', $include_path), '/').'/'.$path;
		}
		return array_values(array_unique($candidates));
	}

	private static function undefined_variable_items(string $text, array $frames): array {
		if(!preg_match_all('/Undefined variable \$([A-Za-z_][A-Za-z0-9_]*)/i', $text, $matches)){
			return [];
		}
		$items=[];
		$source=self::source_frame($frames);
		foreach(array_unique($matches[1]) as $variable){
			$candidates=$source!==null ? self::variables_near_frame((string)$source['file'], (int)$source['line']) : [];
			$candidates=array_values(array_filter($candidates, static fn($name)=>$name!==$variable));
			$suggestions=self::closest_names((string)$variable, $candidates);
			$rows=[
				'Missing variable'=>'$'.$variable,
				'Frame'=>$source!==null ? ((string)$source['file'].':'.(string)$source['line']) : 'unknown',
				'Variables seen nearby'=>$candidates===[] ? 'none' : implode(', ', array_map(static fn($name)=>'$'.$name, array_slice($candidates, 0, 18))),
			];
			$items[]=[
				'title'=>'Undefined variable',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>$suggestions===[] ? 'No close variable names were found in the nearby source scope.' : 'Nearby variables look similar to the missing name.',
				'rows'=>$rows,
				'suggestions'=>array_map(static fn($name)=>'$'.$name, $suggestions),
			];
		}
		return $items;
	}

	private static function undefined_function_items(string $text): array {
		if(!preg_match_all('/Call to undefined function ([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)\s*\(/i', $text, $matches)){
			return [];
		}
		$functions=get_defined_functions();
		$candidates=array_merge($functions['user'] ?? [], $functions['internal'] ?? []);
		$items=[];
		foreach(array_unique($matches[1]) as $function){
			$suggestions=self::closest_names(self::basename_symbol((string)$function), array_map([self::class, 'basename_symbol'], $candidates));
			$items[]=[
				'title'=>'Undefined function',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>'The function name was not loaded in this runtime.',
				'rows'=>['Missing function'=>(string)$function],
				'suggestions'=>$suggestions,
			];
		}
		return $items;
	}

	private static function undefined_method_items(string $text): array {
		if(!preg_match_all('/Call to undefined method ([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)\s*\(/i', $text, $matches, PREG_SET_ORDER)){
			return [];
		}
		$items=[];
		foreach($matches as $match){
			$class=(string)$match[1];
			$method=(string)$match[2];
			$candidates=[];
			if(class_exists($class) || interface_exists($class) || trait_exists($class)){
				try{
					$reflection=new \ReflectionClass($class);
					foreach($reflection->getMethods() as $candidate){
						$candidates[]=$candidate->getName();
					}
				}catch(\Throwable){
					$candidates=[];
				}
			}
			$suggestions=self::closest_names($method, $candidates);
			$items[]=[
				'title'=>'Undefined method',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>$candidates===[] ? 'The class was not reflectable or has no loaded method list.' : 'Loaded methods on the class were compared against the missing method.',
				'rows'=>[
					'Class'=>$class,
					'Missing method'=>$method,
					'Reflectable'=>self::yes_no($candidates!==[]),
				],
				'suggestions'=>$suggestions,
			];
		}
		return $items;
	}

	private static function undefined_property_items(string $text): array {
		if(!preg_match_all('/(?:Undefined property|Access to undeclared static property):?\s*([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)::?\$([A-Za-z_][A-Za-z0-9_]*)/i', $text, $matches, PREG_SET_ORDER)){
			return [];
		}
		$items=[];
		foreach($matches as $match){
			$class=(string)$match[1];
			$property=(string)$match[2];
			$candidates=[];
			if(class_exists($class)){
				try{
					$reflection=new \ReflectionClass($class);
					foreach($reflection->getProperties() as $candidate){
						$candidates[]=$candidate->getName();
					}
				}catch(\Throwable){
					$candidates=[];
				}
			}
			$suggestions=self::closest_names($property, $candidates);
			$items[]=[
				'title'=>'Undefined property',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>'Loaded class properties were compared against the missing property name.',
				'rows'=>['Class'=>$class, 'Missing property'=>'$'.$property],
				'suggestions'=>array_map(static fn($name)=>'$'.$name, $suggestions),
			];
		}
		return $items;
	}

	private static function undefined_constant_items(string $text): array {
		if(!preg_match_all('/Undefined constant [\'"]?([\\\\A-Za-z_][\\\\A-Za-z0-9_]*)[\'"]?/i', $text, $matches)){
			return [];
		}
		$constant_groups=get_defined_constants(true);
		$constants=array_keys($constant_groups['user'] ?? get_defined_constants());
		$items=[];
		foreach(array_unique($matches[1]) as $constant){
			$suggestions=self::closest_names(self::basename_symbol((string)$constant), array_map([self::class, 'basename_symbol'], $constants));
			$items[]=[
				'title'=>'Undefined constant',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>'Loaded constants were compared against the missing constant name.',
				'rows'=>['Missing constant'=>(string)$constant],
				'suggestions'=>$suggestions,
			];
		}
		return $items;
	}

	private static function missing_class_items(string $text): array {
		if(!preg_match_all('/Class [\'"]([^\'"]+)[\'"] not found/i', $text, $matches)){
			return [];
		}
		$classes=get_declared_classes();
		$items=[];
		foreach(array_unique($matches[1]) as $class){
			$suggestions=self::closest_names(self::basename_symbol((string)$class), array_map([self::class, 'basename_symbol'], $classes));
			$items[]=[
				'title'=>'Missing class',
				'tone'=>$suggestions===[] ? 'warning' : 'info',
				'summary'=>'Loaded class names were compared against the missing class.',
				'rows'=>['Missing class'=>(string)$class],
				'suggestions'=>$suggestions,
			];
		}
		return $items;
	}

	private static function source_frame(array $frames): ?array {
		foreach($frames as $frame){
			$file=(string)($frame['file'] ?? '');
			$line=(int)($frame['line'] ?? 0);
			if($file!=='' && $line>0 && is_file($file) && is_readable($file)){
				return $frame;
			}
		}
		return null;
	}

	private static function variables_near_frame(string $file, int $line): array {
		$lines=@file($file, FILE_IGNORE_NEW_LINES);
		if(!is_array($lines)){
			return [];
		}
		$start=max(0, $line - 140);
		for($index=$line - 1; $index>=max(0, $line - 220); $index--){
			if(isset($lines[$index]) && preg_match('/\bfunction\b|\bfn\s*\(|\bcatch\s*\(|\bforeach\s*\(/', (string)$lines[$index])){
				$start=$index;
				break;
			}
		}
		$end=min(count($lines), $line + 30);
		$scope=implode("\n", array_slice($lines, $start, max(0, $end - $start)));
		if(!preg_match_all('/\$([A-Za-z_][A-Za-z0-9_]*)/', $scope, $matches)){
			return [];
		}
		$ignored=array_flip(['GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV','this']);
		$names=[];
		foreach($matches[1] as $name){
			if(!isset($ignored[$name])){
				$names[$name]=true;
			}
		}
		return array_keys($names);
	}

	private static function closest_names(string $target, array $candidates, int $limit=5): array {
		$target=trim($target);
		if($target===''){
			return [];
		}
		$ranked=[];
		foreach(array_values(array_unique(array_filter(array_map('strval', $candidates)))) as $candidate){
			if($candidate==='' || strcasecmp($candidate, $target)===0){
				continue;
			}
			$short_target=substr(strtolower($target), 0, 120);
			$short_candidate=substr(strtolower($candidate), 0, 120);
			$distance=levenshtein($short_target, $short_candidate);
			similar_text($short_target, $short_candidate, $percent);
			if($distance<=max(2, (int)floor(strlen($short_target) / 2)) || $percent>=62){
				$ranked[]=['name'=>$candidate, 'distance'=>$distance, 'percent'=>$percent];
			}
		}
		usort($ranked, static function(array $a, array $b): int {
			if($a['distance']===$b['distance']){
				return $b['percent']<=>$a['percent'];
			}
			return $a['distance']<=>$b['distance'];
		});
		return array_slice(array_map(static fn($item)=>(string)$item['name'], $ranked), 0, $limit);
	}

	private static function basename_symbol(string $symbol): string {
		$symbol=trim($symbol, '\\');
		$parts=explode('\\', $symbol);
		return (string)end($parts);
	}

	private static function basename_callable(string $callable): string {
		$callable=trim($callable, '\\');
		if(str_contains($callable, '::')){
			$parts=explode('::', $callable);
			return (string)end($parts);
		}
		return self::basename_symbol($callable);
	}

	private static function is_absolute_path(string $path): bool {
		return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path)===1;
	}

	private static function yes_no(bool $value): string {
		return $value ? 'yes' : 'no';
	}

	private static function datadoc_highlight(string $code, int $start, int $line, array $context=[], array $options=[]): ?string {
		$highlighter=dirname(__DIR__, 2).'/datadoc/kernel/highlighter.php';
		if(!is_file($highlighter)){
			return null;
		}
		try{
			require_once($highlighter);
			if(!class_exists('\dataphyre\datadoc\highlighter', false)){
				return null;
			}
			$highlighted=\dataphyre\datadoc\highlighter::highlight_code($code, 'php', [
				'show_lines'=>true,
				'start_line'=>$start,
				'line_number_start'=>$start,
				'highlight_offset'=>$line - $start,
				'highlight_class'=>(string)($options['highlight_class'] ?? 'fd-callsite-line'),
			]);
			return \dataphyre\datadoc\highlighter::linkify_php(
				$highlighted,
				(string)($context['project'] ?? ''),
				(string)($context['namespace'] ?? ''),
				(string)($context['class'] ?? ''),
				(string)($context['function'] ?? '')
			);
		}catch(\Throwable){
			return null;
		}
	}

	private static function normalize_snippet_lines(array $lines): array {
		$common_prefix=null;
		foreach($lines as $line){
			if(trim((string)$line)===''){
				continue;
			}
			preg_match('/^[ \t]*/', (string)$line, $matches);
			$prefix=$matches[0] ?? '';
			if($common_prefix===null){
				$common_prefix=$prefix;
				continue;
			}
			$limit=min(strlen($common_prefix), strlen($prefix));
			$shared='';
			for($i=0; $i<$limit; $i++){
				if($common_prefix[$i]!==$prefix[$i]){
					break;
				}
				$shared.=$common_prefix[$i];
			}
			$common_prefix=$shared;
			if($common_prefix===''){
				break;
			}
		}
		if($common_prefix===null || $common_prefix===''){
			return $lines;
		}
		$prefix_length=strlen($common_prefix);
		foreach($lines as $index=>$line){
			$line=(string)$line;
			if(strncmp($line, $common_prefix, $prefix_length)===0){
				$lines[$index]=substr($line, $prefix_length);
			}
		}
		return $lines;
	}

	private static function datadoc_frame_context(string $file, int $line): array {
		$context=[
			'project'=>'',
			'namespace'=>'',
			'class'=>'',
			'function'=>'',
			'datadoc_url'=>null,
			'project_url'=>null,
		];
		if(function_exists('sql_select')!==true){
			return $context;
		}
		try{
			$normalized_file=str_replace('\\', '/', $file);
			$projects=sql_select(
				$S='*',
				$L='datadoc.projects',
				$P='ORDER BY title, name',
				$V=[],
				$F=true
			);
			if(!is_array($projects)){
				return $context;
			}
			$best_project=null;
			$best_length=-1;
			foreach($projects as $project){
				$project_path=(string)($project['path'] ?? '');
				$project_name=(string)($project['name'] ?? '');
				if($project_path==='' || $project_name===''){
					continue;
				}
				$normalized_project_path=rtrim(str_replace('\\', '/', $project_path), '/').'/';
				if(str_starts_with($normalized_file, $normalized_project_path) && strlen($normalized_project_path)>$best_length){
					$best_project=$project;
					$best_length=strlen($normalized_project_path);
				}
			}
			if(!is_array($best_project)){
				return $context;
			}
			$project_name=(string)$best_project['name'];
			$context['project']=$project_name;
			$context['project_url']=self::datadoc_project_url($project_name);
			$rows=sql_select(
				$S='*',
				$L='dataphyre.datadoc_data',
				$P='WHERE file=? AND project=?',
				$V=[$normalized_file, $project_name],
				$F=true
			);
			if(!is_array($rows)){
				return $context;
			}
			$best_record=null;
			$best_distance=PHP_INT_MAX;
			foreach($rows as $record){
				$record_line=(int)($record['line'] ?? 0);
				if($record_line<=0){
					continue;
				}
				$distance=$record_line>$line ? ($record_line - $line) + 1000000 : $line - $record_line;
				if($distance<$best_distance){
					$best_distance=$distance;
					$best_record=$record;
				}
			}
			if(is_array($best_record)){
				$context['namespace']=(string)($best_record['namespace'] ?? '');
				$context['class']=(string)($best_record['class'] ?? '');
				$context['function']=(string)($best_record['function'] ?? '');
				$context['datadoc_url']=self::datadoc_record_url($project_name, $best_record);
			}
		}catch(\Throwable){
			return $context;
		}
		return $context;
	}

	private static function snippet_actions(array $context, array $frame=[], array $frames=[], array $options=[]): string {
		$links=[];
		if(($options['show_datadoc_actions'] ?? true)===true){
			if(!empty($context['datadoc_url'])){
				$links[]='<a href="'.self::e((string)$context['datadoc_url']).'">Open DataDoc Record</a>';
			}
			elseif(!empty($context['project_url'])){
				$links[]='<a href="'.self::e((string)$context['project_url']).'">Open DataDoc Project</a>';
			}
		}
		if(($options['show_stack_links'] ?? true)===true){
			$frame_index=(int)($frame['index'] ?? -1);
			$id_prefix=(string)($options['id_prefix'] ?? 'fd-frame-');
			if($frame_index>0 && isset($frames[$frame_index - 1])){
				$callee=(string)($frames[$frame_index - 1]['symbol'] ?? self::frame_symbol($frames[$frame_index - 1]));
				$links[]='<a href="#'.self::e($id_prefix.($frame_index - 1)).'" title="'.self::e($callee).'">Callee #'.($frame_index - 1).'</a>';
			}
			if($frame_index>=0 && isset($frames[$frame_index + 1])){
				$caller=(string)($frames[$frame_index + 1]['symbol'] ?? self::frame_symbol($frames[$frame_index + 1]));
				$links[]='<a href="#'.self::e($id_prefix.($frame_index + 1)).'" title="'.self::e($caller).'">Caller #'.($frame_index + 1).'</a>';
			}
		}
		if($links===[]){
			return '';
		}
		return '<div class="fd-snippet-actions">'.implode('', $links).'</div>';
	}

	private static function datadoc_project_url(string $project): string {
		return self::datadoc_base_url().'/'.rawurlencode($project);
	}

	private static function datadoc_record_url(string $project, array $record): string {
		return self::datadoc_project_url($project).'/dynadoc?'.http_build_query([
			'type'=>(string)($record['type'] ?? ''),
			'namespace'=>(string)($record['namespace'] ?? ''),
			'class'=>(string)($record['class'] ?? ''),
			'function'=>(string)($record['function'] ?? ''),
			'content'=>(string)($record['content'] ?? ''),
		]);
	}

	private static function datadoc_base_url(): string {
		if(class_exists('\dataphyre\core', false)){
			return rtrim(\dataphyre\core::url_self(), '/').'/dataphyre/datadoc';
		}
		return '/dataphyre/datadoc';
	}

	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
