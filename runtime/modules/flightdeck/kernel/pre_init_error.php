<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(defined('DATAPHYRE_FLIGHTDECK_PRE_INIT_ERROR_LOADED')){
	return;
}
define('DATAPHYRE_FLIGHTDECK_PRE_INIT_ERROR_LOADED', true);

$flightdeck_auth_file=__DIR__.'/auth.php';
$flightdeck_stack_snippets_file=__DIR__.'/stack_snippets.php';
if(is_file($flightdeck_auth_file)){
	require_once($flightdeck_auth_file);
}
if(is_file($flightdeck_stack_snippets_file)){
	require_once($flightdeck_stack_snippets_file);
}

/**
 * Renders Flightdeck diagnostics before the full Dataphyre runtime is available.
 *
 * This pre-init renderer is intentionally self-contained: it gates sensitive
 * bootstrap errors behind Flightdeck authentication, renders stack snippets when
 * possible, and links snippets back to Datadoc records when the database layer is
 * already usable. It avoids depending on framework services that may have failed
 * during bootstrap.
 */
final class dataphyre_flightdeck_pre_init_error {

	/**
	 * Attempts to render a gated bootstrap diagnostic page.
	 *
	 * The method returns false when the authentication helper is unavailable or
	 * Flightdeck is disabled so upstream error handlers can fall back to another
	 * response. A true return means a complete HTML response was emitted.
	 *
	 * @param ?string $message Human-readable bootstrap failure message.
	 * @param ?object $exception Throwable-like object carrying trace information.
	 * @return bool True when this renderer emitted a response.
	 */
	public static function render(?string $message, ?object $exception): bool {
		if(class_exists('dataphyre_flightdeck_auth', false)!==true){
			return false;
		}
		if(dataphyre_flightdeck_auth::enabled()!==true){
			return false;
		}
		if(dataphyre_flightdeck_auth::authenticated()!==true){
			self::render_login_gate($message);
			return true;
		}
		self::render_exception_page($message, $exception);
		return true;
	}

	/**
	 * Emits the authentication gate shown before diagnostic details are revealed.
	 *
	 * @param ?string $message Bootstrap message safe enough to show before login.
	 */
	private static function render_login_gate(?string $message): void {
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
		$error=dataphyre_flightdeck_auth::login_error();
		if(dataphyre_flightdeck_auth::auth_required()===false){
			$error='Flightdeck console password is not configured.';
		}
		if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST'){
			$password=(string)($_POST['password'] ?? '');
			if(dataphyre_flightdeck_auth::verify_csrf($_POST['csrf'] ?? null)!==true){
				$error='Invalid form token.';
			}
			elseif(dataphyre_flightdeck_auth::login($password)===true){
				http_response_code(302);
				header('Location: '.dataphyre_flightdeck_auth::current_uri());
				exit;
			}
			else
			{
				$error=$error ?? 'Invalid Flightdeck password.';
			}
		}
		echo self::html_start('Dataphyre Flightdeck Error Gate');
		echo '<main class="fd-shell fd-center"><section class="fd-card fd-login"><div class="fd-mark">Dataphyre Flightdeck</div><h1>Runtime diagnostic available</h1><p>Console access is required before diagnostic details are shown.</p>';
		if($message!==null){
			echo '<p class="fd-muted">'.self::e($message).'</p>';
		}
		if($error!==null){
			echo '<div class="fd-alert">'.self::e($error).'</div>';
		}
		$action='/dataphyre/login?'.http_build_query(['return'=>dataphyre_flightdeck_auth::current_uri()]);
		echo '<form method="post" action="'.self::e($action).'"><input type="hidden" name="csrf" value="'.self::e(dataphyre_flightdeck_auth::csrf_token()).'"><input type="password" name="password" placeholder="Flightdeck password" autofocus><button type="submit">View Diagnostic Report</button></form></section></main>';
		echo self::html_end();
	}

	/**
	 * Emits the authenticated bootstrap diagnostic report.
	 *
	 * @param ?string $message Bootstrap failure message.
	 * @param ?object $exception Throwable-like object carrying file, line, and trace details.
	 */
	private static function render_exception_page(?string $message, ?object $exception): void {
		http_response_code(500);
		header('Content-Type: text/html; charset=utf-8');
		$frames=array_slice(self::frames($exception), 0, 8);
		echo self::html_start('Dataphyre Diagnostic Report');
		echo '<main class="fd-shell"><nav class="fd-top"><div><span class="fd-mark">Dataphyre Flightdeck</span><h1>Bootstrap Diagnostic Report</h1></div><a href="/dataphyre">Open Console</a></nav>';
		echo '<section class="fd-grid">';
		echo '<article class="fd-card fd-span"><h2>'.self::e($message ?? 'Dataphyre has encountered a fatal bootstrap error.').'</h2>';
		if($exception!==null){
			echo '<div class="fd-exception">'.self::e(get_class($exception)).': '.self::e($exception->getMessage()).'</div>';
			echo '<p class="fd-muted">'.self::e($exception->getFile()).':'.self::e((string)$exception->getLine()).'</p>';
		}
		echo '</article>';
		echo '<article class="fd-card"><h3>Request</h3>'.self::definition_list([
			'Method'=>$_SERVER['REQUEST_METHOD'] ?? '',
			'URI'=>$_SERVER['REQUEST_URI'] ?? '',
			'App'=>defined('APP') ? APP : '',
			'Run Mode'=>defined('RUN_MODE') ? RUN_MODE : 'pre-init',
			'Production'=>defined('IS_PRODUCTION') && IS_PRODUCTION===true ? 'true' : 'false',
			'Request ID'=>defined('RQID') ? (string)RQID : '',
		]).'</article>';
		echo '<article class="fd-card"><h3>Runtime</h3>'.self::definition_list([
			'PHP'=>PHP_VERSION,
			'Memory'=>self::format_bytes(memory_get_usage(true)).' / '.self::format_bytes(memory_get_peak_usage(true)),
			'Time'=>gmdate('Y-m-d H:i:s T'),
			'Included Files'=>(string)count(get_included_files()),
		]).'</article>';
		echo '</section>';
		$diagnostics=self::render_smart_diagnostics($message, $exception, $frames);
		if($diagnostics!==''){
			echo '<section class="fd-card">'.$diagnostics.'</section>';
		}
		echo '<section class="fd-card"><h2>Code Snippets</h2>';
		if(empty($frames)){
			echo '<p class="fd-muted">No stack frames available.</p>';
		}
		else
		{
			echo self::render_stack_map($frames);
		}
		foreach($frames as $frame){
			echo self::render_snippet($frame, $frames);
		}
		echo '</section>';
		echo '<section class="fd-card"><h2>Trace</h2><pre class="fd-trace">'.self::e($exception?->getTraceAsString() ?? 'No exception trace available.').'</pre></section>';
		echo '<section class="fd-card"><h2>Retroactive Tracelog</h2>'.self::render_retroactive_tracelog().'</section>';
		echo '</main>';
		echo self::html_end();
	}

	/**
	 * Extracts stack frames from the exception or the optional snippet helper.
	 *
	 * @param ?object $exception Throwable-like object.
	 * @return array Stack frames with file, line, symbol, index, and kind metadata.
	 */
	private static function frames(?object $exception): array {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
			return dataphyre_flightdeck_stack_snippets::frames_from_exception($exception);
		}
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

	/**
	 * Builds a readable symbol label for a stack frame.
	 *
	 * @param array{function?:string,class?:string,type?:string,call?:string} $frame Raw stack frame from Throwable::getTrace() or snippet normalization.
	 * @return string Symbol label such as Class::method or function.
	 */
	private static function frame_symbol(array $frame): string {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
			return dataphyre_flightdeck_stack_snippets::frame_symbol($frame);
		}
		$function=(string)($frame['function'] ?? '');
		if($function===''){
			return 'unknown frame';
		}
		$class=(string)($frame['class'] ?? '');
		$type=(string)($frame['type'] ?? ($class!=='' ? '::' : ''));
		return $class!=='' ? $class.$type.$function : $function;
	}

	/**
	 * Delegates smart diagnostic rendering to the optional stack snippet helper.
	 *
	 * @param ?string $message Bootstrap failure message.
	 * @param ?object $exception Throwable-like object.
	 * @param array<int,array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string}> $frames Stack frames already extracted for the report.
	 * @return string Diagnostic HTML or an empty string when helper support is unavailable.
	 */
	private static function render_smart_diagnostics(?string $message, ?object $exception, array $frames): string {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)!==true){
			return '';
		}
		$text=trim((string)($message ?? '')."\n".($exception!==null ? get_class($exception).': '.$exception->getMessage() : ''));
		return dataphyre_flightdeck_stack_snippets::render_diagnostics($text, $frames);
	}

	/**
	 * Renders quick navigation links for the displayed stack frames.
	 *
	 * @param array<int,array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string}> $frames Stack frame payloads.
	 * @return string Stack map HTML.
	 */
	private static function render_stack_map(array $frames): string {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
			return dataphyre_flightdeck_stack_snippets::render_stack_map($frames);
		}
		$html='<div class="fd-stack-map" aria-label="Call stack reference map">';
		foreach($frames as $frame){
			$index=(int)($frame['index'] ?? 0);
			$symbol=(string)($frame['symbol'] ?? self::frame_symbol($frame));
			$label=$index===0 ? 'Origin' : 'Callsite';
			$html.='<a href="#fd-frame-'.$index.'" title="'.self::e($symbol).'"><span>#'.$index.'</span>'.self::e($label).'</a>';
		}
		return $html.'</div>';
	}

	/**
	 * Describes whether a snippet is the origin frame or a callsite.
	 *
	 * @param array{kind?:string,index?:int,file?:string,line?:int,symbol?:string} $frame Stack frame payload.
	 * @param string $symbol Readable frame symbol.
	 * @return string Badge HTML or an empty string for unknown frame kinds.
	 */
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

	/**
	 * Renders a source snippet for one stack frame.
	 *
	 * The renderer prefers Datadoc highlighting and linkification, then falls
	 * back to escaped preformatted source when Datadoc is not available during
	 * early bootstrap.
	 *
	 * @param array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string} $frame Stack frame payload.
	 * @param array<int,array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string}> $frames Complete frame list used for caller/callee links.
	 * @return string Source snippet HTML.
	 */
	private static function render_snippet(array $frame, array $frames=[]): string {
		if(class_exists('dataphyre_flightdeck_stack_snippets', false)){
			return dataphyre_flightdeck_stack_snippets::render_snippet($frame, $frames);
		}
		$file=(string)($frame['file'] ?? '');
		$line=(int)($frame['line'] ?? 0);
		$frame_index=(int)($frame['index'] ?? 0);
		$symbol=(string)($frame['symbol'] ?? self::frame_symbol($frame));
		if($file==='' || $line<=0 || !is_file($file) || !is_readable($file)){
			return '<div class="fd-snippet" id="fd-frame-'.$frame_index.'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file ?: 'Unknown file').'</h3>'.self::snippet_actions([], $frame, $frames).'</div><p class="fd-muted">Source unavailable.</p></div>';
		}
		$start=max(1, $line - 8);
		$lines=@file($file, FILE_IGNORE_NEW_LINES);
		if(!is_array($lines)){
			return '<div class="fd-snippet" id="fd-frame-'.$frame_index.'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file).'</h3>'.self::snippet_actions([], $frame, $frames).'</div><p class="fd-muted">Source unreadable.</p></div>';
		}
		$selected=array_slice($lines, $start - 1, 17, true);
		$selected=self::normalize_snippet_lines($selected);
		$code=implode("\n", $selected);
		$datadoc_context=self::datadoc_frame_context($file, $line);
		$highlighted=self::datadoc_highlight($code, $start, $line, $datadoc_context);
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
				$highlighted.='<span class="'.implode(' ', $classes).'"><b>'.str_pad((string)$current, 5, ' ', STR_PAD_LEFT).'</b> '.self::e($source_line).'</span>'."\n";
			}
			$highlighted.='</pre>';
		}
		return '<div class="fd-snippet" id="fd-frame-'.$frame_index.'"><div class="fd-snippet-head"><h3><span class="fd-frame-index">#'.$frame_index.'</span> '.self::e($file).':'.self::e((string)$line).'</h3>'.self::snippet_actions($datadoc_context, $frame, $frames).'</div>'.self::stack_reference_badge($frame, $symbol).$highlighted.'</div>';
	}

	/**
	 * Highlights a PHP snippet with Datadoc and linkifies known symbols.
	 *
	 * @param string $code Source code excerpt.
	 * @param int $start First source line represented in the excerpt.
	 * @param int $line Frame line to highlight.
	 * @param array{project?:string,namespace?:string,class?:string,function?:string,datadoc_url?:?string,project_url?:?string} $context Datadoc project, namespace, class, and function context.
	 * @return ?string Highlighted HTML, or null when Datadoc highlighter is unavailable.
	 */
	private static function datadoc_highlight(string $code, int $start, int $line, array $context=[]): ?string {
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
				'highlight_class'=>'fd-callsite-line',
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

	/**
	 * Removes shared indentation from a displayed snippet window.
	 *
	 * @param array<int,string> $lines Source lines keyed by their zero-based file index.
	 * @return array<int,string> Lines with the common leading whitespace removed.
	 */
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

	/**
	 * Locates the Datadoc project and nearest indexed record for a frame.
	 *
	 * Database access is best-effort because pre-init failures may happen before
	 * SQL is configured; errors are swallowed and represented as an empty context.
	 *
	 * @param string $file Absolute frame file path.
	 * @param int $line Frame line number.
	 * @return array{project:string,namespace:string,class:string,function:string,datadoc_url:?string,project_url:?string} Datadoc context with project, symbol, and URL fields.
	 */
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

	/**
	 * Builds contextual links for a source snippet.
	 *
	 * @param array{project?:string,namespace?:string,class?:string,function?:string,datadoc_url?:?string,project_url?:?string} $context Datadoc context returned by datadoc_frame_context().
	 * @param array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string} $frame Current stack frame.
	 * @param array<int,array{index?:int,file?:string,line?:int,function?:string,class?:string,type?:string,symbol?:string,kind?:string}> $frames Complete frame list.
	 * @return string Link toolbar HTML or an empty string.
	 */
	private static function snippet_actions(array $context, array $frame=[], array $frames=[]): string {
		$links=[];
		if(!empty($context['datadoc_url'])){
			$links[]='<a href="'.self::e((string)$context['datadoc_url']).'">Open DataDoc Record</a>';
		}
		elseif(!empty($context['project_url'])){
			$links[]='<a href="'.self::e((string)$context['project_url']).'">Open DataDoc Project</a>';
		}
		$frame_index=(int)($frame['index'] ?? -1);
		if($frame_index>0 && isset($frames[$frame_index - 1])){
			$callee=(string)($frames[$frame_index - 1]['symbol'] ?? self::frame_symbol($frames[$frame_index - 1]));
			$links[]='<a href="#fd-frame-'.($frame_index - 1).'" title="'.self::e($callee).'">Callee #'.($frame_index - 1).'</a>';
		}
		if($frame_index>=0 && isset($frames[$frame_index + 1])){
			$caller=(string)($frames[$frame_index + 1]['symbol'] ?? self::frame_symbol($frames[$frame_index + 1]));
			$links[]='<a href="#fd-frame-'.($frame_index + 1).'" title="'.self::e($caller).'">Caller #'.($frame_index + 1).'</a>';
		}
		if($links===[]){
			return '';
		}
		return '<div class="fd-snippet-actions">'.implode('', $links).'</div>';
	}

	/**
	 * Builds the Datadoc project URL for a project name.
	 *
	 * @param string $project Datadoc project name.
	 * @return string Project URL.
	 */
	private static function datadoc_project_url(string $project): string {
		return self::datadoc_base_url().'/'.rawurlencode($project);
	}

	/**
	 * Builds a Datadoc record URL from an indexed symbol row.
	 *
	 * @param string $project Datadoc project name.
	 * @param array{type?:string,namespace?:string,class?:string,function?:string,content?:string} $record Datadoc data row containing type and symbol fields.
	 * @return string Record URL.
	 */
	private static function datadoc_record_url(string $project, array $record): string {
		return self::datadoc_project_url($project).'/dynadoc?'.http_build_query([
			'type'=>(string)($record['type'] ?? ''),
			'namespace'=>(string)($record['namespace'] ?? ''),
			'class'=>(string)($record['class'] ?? ''),
			'function'=>(string)($record['function'] ?? ''),
			'content'=>(string)($record['content'] ?? ''),
		]);
	}

	/**
	 * Resolves the Datadoc base URL without requiring full framework boot.
	 *
	 * @return string Datadoc base URL.
	 */
	private static function datadoc_base_url(): string {
		if(class_exists('\dataphyre\core', false)){
			return rtrim(\dataphyre\core::url_self(), '/').'/dataphyre/datadoc';
		}
		return '/dataphyre/datadoc';
	}

	/**
	 * Renders the retroactive tracelog captured before Flightdeck initialized.
	 *
	 * @return string Tracelog HTML or an empty-state paragraph.
	 */
	private static function render_retroactive_tracelog(): string {
		$entries=$GLOBALS['retroactive_tracelog'] ?? [];
		if(!is_array($entries) || empty($entries)){
			return '<p class="fd-muted">No retroactive tracelog entries were captured.</p>';
		}
		$html='<div class="fd-log">';
		foreach(array_slice($entries, -40) as $entry){
			$file=$entry[0] ?? '';
			$line=$entry[1] ?? '';
			$class=$entry[2] ?? '';
			$function=$entry[3] ?? '';
			$text=$entry[4] ?? '';
			$type=$entry[5] ?? '';
			$html.='<div><span>'.self::e(basename((string)$file).':'.$line).'</span> <b>'.self::e(trim((string)$class.'::'.(string)$function, ':')).'</b> <em>'.self::e((string)$type).'</em><p>'.self::e((string)$text).'</p></div>';
		}
		return $html.'</div>';
	}

	/**
	 * Renders key-value diagnostics as a definition list.
	 *
	 * @param array<string,scalar|null> $items Diagnostic labels and scalar values rendered with HTML escaping.
	 * @return string Definition-list HTML.
	 */
	private static function definition_list(array $items): string {
		$html='<dl class="fd-dl">';
		foreach($items as $key=>$value){
			$html.='<dt>'.self::e((string)$key).'</dt><dd>'.self::e((string)$value).'</dd>';
		}
		return $html.'</dl>';
	}

	/**
	 * Builds the opening HTML document shell for pre-init pages.
	 *
	 * @param string $title Document title.
	 * @return string Opening HTML, head, and body markup.
	 */
	private static function html_start(string $title): string {
		return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.self::e($title).'</title><style>'.self::css().'</style></head><body>';
	}

	/**
	 * Builds the closing HTML document shell.
	 *
	 * @return string Closing body and html tags.
	 */
	private static function html_end(): string {
		return '</body></html>';
	}

	/**
	 * Provides the self-contained stylesheet for pre-init diagnostic pages.
	 *
	 * @return string CSS used by the login gate and diagnostic report.
	 */
	private static function css(): string {
		return ':root{--bg:#07111f;--panel:#f8fafc;--line:#cbd5e1;--text:#0f172a;--muted:#64748b;--accent:#0ea5e9;--danger:#dc2626;--ink:#e6f0ff}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top left,rgba(14,165,233,.25),transparent 32rem),linear-gradient(135deg,#07111f,#101827 54%,#162033);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.fd-shell{max-width:1500px;margin:0 auto;padding:28px}.fd-center{min-height:100vh;display:flex;align-items:center;justify-content:center}.fd-top{display:flex;align-items:center;justify-content:space-between;gap:18px;color:#fff;margin-bottom:20px}.fd-top h1{margin:.2rem 0 0;font-size:2.6rem}.fd-top a{color:#dff6ff;text-decoration:none;border:1px solid rgba(255,255,255,.25);border-radius:999px;padding:10px 14px}.fd-mark{text-transform:uppercase;letter-spacing:.16em;font-size:.75rem;color:#8bd3ff;font-weight:800}.fd-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px}.fd-span{grid-column:1/-1}.fd-card{background:rgba(248,250,252,.96);border:1px solid rgba(203,213,225,.75);box-shadow:0 24px 80px rgba(0,0,0,.28);border-radius:22px;padding:22px;margin-bottom:18px}.fd-card h1,.fd-card h2,.fd-card h3{margin-top:0}.fd-login{max-width:520px}.fd-login input{width:100%;border:1px solid var(--line);border-radius:14px;padding:13px 14px;margin:12px 0;font-size:1rem}.fd-login button{border:0;border-radius:14px;background:#0f172a;color:#fff;padding:13px 16px;font-weight:800;cursor:pointer;width:100%}.fd-alert{padding:12px 14px;border-radius:14px;background:#fee2e2;color:#991b1b;margin:12px 0}.fd-muted{color:var(--muted)}.fd-exception{font-size:1.25rem;font-weight:800;color:var(--danger);word-break:break-word}.fd-dl{display:grid;grid-template-columns:130px 1fr;gap:8px 14px}.fd-dl dt{color:var(--muted);font-weight:700}.fd-dl dd{margin:0;word-break:break-word}.fd-stack-map{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 4px}.fd-stack-map a{display:inline-flex;gap:7px;align-items:center;border:1px solid rgba(14,165,233,.25);border-radius:999px;padding:8px 11px;color:#075985;background:#e0f2fe;text-decoration:none;font-size:.84rem;font-weight:800}.fd-stack-map span{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:22px;border-radius:999px;background:#0f172a;color:#dff6ff}.fd-snippet{scroll-margin-top:20px;margin-top:18px;background:#030712;color:#f8fafc;border:1px solid rgba(125,211,252,.22);box-shadow:inset 0 0 0 1px rgba(255,255,255,.03);border-radius:18px;padding:14px}.fd-snippet-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.fd-snippet h3{font-size:.95rem;color:#cbd5e1;margin:0}.fd-frame-index{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:24px;margin-right:8px;border-radius:999px;background:rgba(14,165,233,.18);color:#dff6ff}.fd-snippet-meta{margin:0 0 12px;color:#bfdbfe}.fd-snippet-meta code{color:#fed7aa;background:rgba(249,115,22,.14);border-radius:8px;padding:2px 5px}.fd-snippet-actions{display:flex;gap:8px;flex-wrap:wrap}.fd-snippet-actions a{display:inline-flex;align-items:center;border:1px solid rgba(125,211,252,.28);border-radius:999px;padding:7px 10px;color:#dff6ff;text-decoration:none;background:rgba(14,165,233,.12);font-size:.82rem;font-weight:800}.fd-snippet .fd-muted{color:#94a3b8}.fd-snippet [id^=codeContainer]{background:transparent!important;color:#f8fafc!important;box-shadow:none!important}.fd-snippet .fd-callsite-line{display:inline-block;background:rgba(249,115,22,.20)!important;border-left:4px solid #fb923c!important;margin-left:-8px;padding-left:8px;width:100%}.fd-code,.fd-trace{background:#07111f;color:#dbeafe;border-radius:16px;padding:16px;overflow:auto;white-space:pre-wrap;line-height:1.55}.fd-code{margin:0}.fd-code span{display:block}.fd-code .fd-hit{background:rgba(14,165,233,.22);margin:0 -8px;padding:0 8px;border-left:4px solid #38bdf8}.fd-code .fd-callsite{background:rgba(249,115,22,.20);border-left-color:#fb923c}.fd-diagnostics h2{margin-top:0}.fd-diagnostic{border:1px solid #dbe3ee;background:#fff;border-radius:16px;padding:14px;margin-top:12px}.fd-diagnostic h3{margin:.1rem 0 .4rem;color:#0f172a}.fd-diagnostic p{color:#334155}.fd-diagnostic dl{display:grid;grid-template-columns:170px 1fr;gap:6px 12px}.fd-diagnostic dt{color:#64748b;font-weight:800}.fd-diagnostic dd{margin:0;word-break:break-word}.fd-log{display:grid;gap:10px}.fd-log div{border:1px solid #dbe3ee;background:#fff;border-radius:14px;padding:12px}.fd-log span{color:#64748b}.fd-log p{margin:.4rem 0 0}@media(max-width:900px){.fd-grid{grid-template-columns:1fr}.fd-shell{padding:16px}.fd-top{display:block}.fd-dl,.fd-diagnostic dl{grid-template-columns:1fr}}';
	}

	/**
	 * Formats byte counts for diagnostic memory summaries.
	 *
	 * @param int $bytes Raw byte count.
	 * @return string Human-readable size.
	 */
	private static function format_bytes(int $bytes): string {
		if($bytes>=1073741824){
			return round($bytes / 1073741824, 2).' GB';
		}
		if($bytes>=1048576){
			return round($bytes / 1048576, 2).' MB';
		}
		if($bytes>=1024){
			return round($bytes / 1024, 2).' KB';
		}
		return $bytes.' B';
	}

	/**
	 * Escapes diagnostic text for safe HTML output.
	 *
	 * @param string $value Raw diagnostic value.
	 * @return string UTF-8 safe escaped value.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
