<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\datadoc;

/**
 * HTML highlighter and linkifier for Datadoc PHP source views.
 *
 * Transforms PHP source into escaped token markup, injects Datadoc highlighter assets when needed, and links recognized symbols to Dynadoc or php.net references. Returned HTML is intended for trusted Datadoc UI rendering.
 */
class highlighter{

	/**
	 * Adds Datadoc and php.net links to highlighted PHP token markup.
	 *
	 * User functions and variables link back into the project Dynadoc route when a project key is supplied; built-ins, constants, comments, integers, and operators link to php.net reference pages.
	 */
	public static function linkify_php($input, $project, $namespace='', $class='', $function='') {
		$project=(string)$project;
		$datadoc_base_url=class_exists('\dataphyre\core', false)
			? rtrim(\dataphyre\core::url_self(), '/')
			: '';
		$patterns = [
			'php_token_user_function' => '/<span class="php_token_user_function">(\\\\?[\w\\\\]+(::[\w\\\\]+)?)<\/span>/',
			'php_token_variable' => '/<span class="php_token_variable">\$(\w+)<\/span>/',
			'php_token_magic_constant' => '/<span class="php_token_magic_constant">(__\w+__)<\/span>/',
			'php_token_constant' => '/<span class="php_token_constant">(\w+)<\/span>/',
			'php_token_operator' => '/<span class="php_token_operator">(\W+)<\/span>/',
			'php_token_comment' => '/<span class="php_token_comment">(.*?)<\/span>/s',
			'php_token_integer' => '/<span class="php_token_integer">([-+]?[0-9a-fA-FxX]+)<\/span>/',
			'php_token_builtin_function' => '/<span class="php_token_builtin_function">(\w+)<\/span>/'
		];
		foreach ($patterns as $token => $pattern) {
			$input = preg_replace_callback($pattern, function ($matches) use ($token, $project, $namespace, $class, $datadoc_base_url) {
				$content = $matches[1];
				$url = "";
				$current_namespace = $namespace;
				$current_class = $class;
				$prefix = '';
				$postfix = '';
				$linked_content = '';
				switch ($token) {
					case 'php_token_user_function':
						if($project===''){
							return $matches[0];
						}
						$original_content = $content;
						$current_namespace = '';
						$current_class = '';
						$function_name = '';
						$content = ltrim($content, '\\');
						if (strpos($content, '::') !== false) {
							list($class_with_namespace, $function_name) = explode('::', $content);
							if(in_array(strtolower($class_with_namespace), ['self', 'static'], true) && $class!==''){
								$current_class = $class;
								$current_namespace = $namespace;
							}
							else{
								$parts = explode('\\', $class_with_namespace);
								$current_class = array_pop($parts);
								$current_namespace = implode('\\', $parts);
							}
						} else {
							$parts = explode('\\', $content);
							$function_name = array_pop($parts);
							$current_namespace = implode('\\', $parts);
						}
						$url = $datadoc_base_url . "/dataphyre/datadoc/" . rawurlencode($project) . "/dynadoc?" . http_build_query([
							'type'=>'function',
							'namespace'=>$current_namespace,
							'class'=>$current_class,
							'function'=>$function_name
						]);
						$linked_content = '<a class="'.$token.'" href="'.$url.'" rel="noreferrer" style="color:inherit;cursor:pointer; text-decoration: none;">'.$original_content.'</a>';
						break;
					case 'php_token_variable':
						if($project===''){
							return $matches[0];
						}
						$url = $datadoc_base_url . "/dataphyre/datadoc/" . rawurlencode($project) . "/dynadoc?" . http_build_query([
							'type'=>'variable',
							'namespace'=>$namespace,
							'class'=>$class,
							'content'=>$content
						]);
						$prefix='$';
						break;
					case 'php_token_constant':
						if(strtolower($content)==='true' || strtolower($content)==='false'){
							$url = "https://www.php.net/manual/en/language.types.boolean.php";
						}
						elseif(strtolower($content)==='null'){
							$url = "https://www.php.net/manual/en/language.types.null.php";
						}
						break;
					case 'php_token_magic_constant':
						$url = "https://www.php.net/manual/en/language.constants.magic.php";
						break;
					case 'php_token_comment':
						$url = "https://www.php.net/manual/en/language.basic-syntax.comments.php";
						break;
					case 'php_token_integer':
						$url = "https://www.php.net/manual/en/language.types.integer.php";
						break;
					case 'php_token_operator':
						if(str_starts_with($content, '[') || str_ends_with($content, ']')){
							$url = "https://www.php.net/manual/en/language.types.array.php";
						}
						elseif($content==='||' || $content==='!' || $content==='&&' || strtolower($content)==='and' || strtolower($content)==='or' || strtolower($content)==='xor' || strtolower($content)==='not'){
							$url = "https://www.php.net/manual/en/language.operators.logical.php";
						}
						elseif($content==='==' || $content==='===' || $content==='!=' || strtolower($content)==='<>' || strtolower($content)==='!==' || strtolower($content)==='<' || strtolower($content)==='>' || strtolower($content)==='<=' || strtolower($content)==='>=' || strtolower($content)==='<=>'){
							$url = "https://www.php.net/manual/en/language.operators.comparison.php";
						}
						elseif($content==='='){
							$url = "https://www.php.net/manual/en/language.operators.assignment.php";
						}
						else
						{
							$url = "https://www.php.net/manual/en/language.operators.php";
						}
						break;
					case 'php_token_builtin_function':
						$url = "https://www.php.net/manual/en/function." . str_replace('_', '-', $content) . ".php";
						break;
				}
				if($linked_content!==''){
					return $linked_content;
				}
				if($url===''){
					return $matches[0];
				}
				return '<a class="'.$token.'" href="'.$url.'" rel="noreferrer" target="_blank" style="color:inherit;cursor:pointer; text-decoration: none;">' . $prefix. $content . $postfix . '</a>';
			}, $input);
		}
		return $input;
	}

	/**
	 * Highlights source code for a Datadoc renderer.
	 *
	 * Currently delegates PHP content to `highlight_php()` and returns other languages unchanged.
	 */
	public static function highlight_code(string $content, string $language='php', array|bool $containerization=false, string $theme='npp_deep_black'){
		if($language==='php'){
			$content=highlighter::highlight_php($content, $containerization, $theme);
		}
		return $content;
	}

	/**
	 * Normalizes PHP indentation for Datadoc display snippets.
	 *
	 * Applies simple brace-based tab indentation; it is a renderer aid, not a PHP formatter.
	 */
	public static function retabulate_php($code){
		$lines = explode("\n", $code);
		$retabulated_lines = [];
		$indent_level = 0;
		foreach ($lines as $line) {
			$trimmed_line = trim($line);
			if ($trimmed_line === '') {
				$retabulated_lines[] = '';
				continue;
			}
			if (str_contains($trimmed_line, '}')) {
				$indent_level--;
			}
			$indented_line = str_repeat("\t", $indent_level).$trimmed_line;
			$retabulated_lines[] = $indented_line;
			if (str_contains($trimmed_line, '{')) {
				$indent_level++;
			}
		}
		return implode("\n", $retabulated_lines);
	}

	/**
	 * Converts PHP source into escaped Datadoc token HTML.
	 *
	 * Uses PHP tokenizer output, wraps tokens in semantic CSS classes, optionally emits line-number container metadata, and includes Datadoc highlighter assets as needed.
	 */
	public static function highlight_php(string $content, bool|array $containerization=false, string $theme='npp_deep_black'){
		self::load_asset_support();
		$tokens=token_get_all("<?php\r\n$content");
		$result="";
		$highlightid=rand(0,9999999999);
		$style=self::highlighter_asset_tag('datadoc-highlighter.css', 'style');
		$container_javascript='';
		$container_classes='container rounded shadow bg-dark p-3';
		$container_attributes='';
		$skip_php_tag=true;
		$result.='<div class="dp-datadoc-highlight dp_highlight'.$highlightid.'">';
		if(is_array($containerization)){
			if(($containerization['show_lines'] ?? false)===true){
				$start_line = isset($containerization['line_number_start'])
					? (int)$containerization['line_number_start']
					: (isset($containerization['start_line']) ? $containerization['start_line'] - 2 : 1);
				$highlight_line=(int)($containerization['highlight_line'] ?? 0);
				$highlight_offset=(int)($containerization['highlight_offset'] ?? -1);
				$highlight_class=preg_replace('/[^A-Za-z0-9_-]/', '', (string)($containerization['highlight_class'] ?? ''));
				$container_attributes=' data-datadoc-code-container="1" data-datadoc-line-start="'.htmlspecialchars((string)$start_line, ENT_QUOTES, 'UTF-8').'" data-datadoc-highlight-line="'.htmlspecialchars((string)$highlight_line, ENT_QUOTES, 'UTF-8').'" data-datadoc-highlight-offset="'.htmlspecialchars((string)$highlight_offset, ENT_QUOTES, 'UTF-8').'" data-datadoc-highlight-class="'.htmlspecialchars($highlight_class, ENT_QUOTES, 'UTF-8').'"';
				$container_javascript=self::highlighter_asset_tag('datadoc-highlighter.js', 'script');
			}
			$result.='<div id="codeContainer'.$highlightid.'" class="'.$container_classes.'" style="overflow-x:auto;white-space: nowrap;"'.$container_attributes.'>';
		}
		$builtin_functions=get_defined_functions()['internal'];
		$user_functions=get_defined_functions()['user'];
		$keywords=['true', 'false', 'null', 'function', 'int', 'bool', 'string'];
		foreach($tokens as $token){
			if(is_array($token)){
				list($id, $text)=$token;
				if($skip_php_tag && $id===T_OPEN_TAG){
					$skip_php_tag=false;
					continue;
				}
				switch($id){
					case T_CONSTANT_ENCAPSED_STRING:
						if(str_starts_with($text, '"')){
							$result.='<span class="php_token_string_doublequote">'.htmlspecialchars($text).'</span>';
						}
						else
						{
							$result.='<span class="php_token_string_singlequote">'.htmlspecialchars($text).'</span>';
						}
						break;
					case T_CONTINUE:
					case T_PUBLIC:
					case T_PROTECTED:
					case T_PRIVATE:
					case T_STATIC:
					case T_SWITCH:
					case T_THROW:
					case T_TRY:
					case T_USE:
					case T_VAR:
					case T_WHILE:
					case T_INTERFACE:
					case T_GLOBAL:
					case T_FOREACH:
					case T_AS:
					case T_FOR:
					case T_FINAL:
					case T_ENDWHILE:
					case T_ENDSWITCH:
					case T_ENDIF:
					case T_ENDFOREACH:
					case T_ENDFOR:
					case T_ELSEIF:
					case T_ELSE:
					case T_IF:
					case T_ARRAY_CAST:
					case T_BOOL_CAST:
					case T_DOUBLE_CAST:
					case T_INT_CAST:
					case T_OBJECT_CAST:
					case T_STRING_CAST:
					case T_UNSET_CAST:
						$result.='<span class="php_token_keywords">'.htmlspecialchars($text).'</span>';
						break;
					case T_FUNCTION:
					case T_CALLABLE:
					case T_STRING:
					case T_ISSET:
					case T_UNSET:
					case T_EXIT:
					case T_EVAL:
					case T_ECHO:
					case T_ARRAY:
					case T_REQUIRE_ONCE:
					case T_REQUIRE:
					case T_INCLUDE_ONCE:
					case T_INCLUDE:
					case T_PRINT:
					case T_EMPTY:
					case T_RETURN:
						if (in_array($text, $builtin_functions)) {
							$result.='<span class="php_token_builtin_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array($text, array("isset", "array", "return", "empty", "unset", "include", "include_once", "require", "require_once", "print", "echo", "array", "eval", "exit", "die"))) {
							$result.='<span class="php_token_builtin_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array($text, $user_functions)) {
							$result.='<span class="php_token_user_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array(strtolower($text), $keywords)) {
							$result.='<span class="php_token_constant">'.htmlspecialchars($text).'</span>';
						} elseif (ctype_upper(str_replace('_', '', $text))) {
							$result.='<span class="php_token_constant">'.htmlspecialchars($text).'</span>';
						} else {
							$result.='<span class="php_token_other">'.htmlspecialchars($text).'</span>';
						}
						break;
					case T_VARIABLE:
					case T_NUM_STRING:
					case T_ENCAPSED_AND_WHITESPACE:
						$result.='<span class="php_token_variable">'.htmlspecialchars($text).'</span>';
						break;
					case T_LNUMBER:
					case T_DNUMBER:
						$result.='<span class="php_token_integer">'.htmlspecialchars($text).'</span>';
						break;
					case T_COMMENT:
					case T_DOC_COMMENT:
						$result.='<span class="php_token_comment">'.htmlspecialchars($text).'</span>';
						break;
					case T_OPEN_TAG:
					case T_CLOSE_TAG:
					case T_OPEN_TAG_WITH_ECHO:
						$result.='<span class="php_token_tag">'.htmlspecialchars($text).'</span>';
						break;
					case T_BOOLEAN_AND:
					case T_DOUBLE_ARROW:
					//case T_DOUBLE_COLON:
					case T_ELLIPSIS:
					case T_INC:
					case T_IS_EQUAL:
					case T_IS_GREATER_OR_EQUAL:
					case T_IS_IDENTICAL:
					case T_IS_NOT_EQUAL:
					case T_IS_NOT_IDENTICAL:
					case T_IS_SMALLER_OR_EQUAL:
					case T_MINUS_EQUAL:
					case T_MOD_EQUAL:
					case T_COALESCE:
					case T_COALESCE_EQUAL:
					case T_CONCAT_EQUAL:
					case T_MUL_EQUAL:
					case T_OR_EQUAL:
					case T_PLUS_EQUAL:
					case T_POW:
					case T_POW_EQUAL:
					case T_SL:
					case T_BOOLEAN_OR:
					case T_SL_EQUAL:
					case T_SPACESHIP:
					case T_SR:
					case T_SR_EQUAL:
						$result.='<span class="php_token_operator">'.htmlspecialchars($text).'</span>';
						break;
					case T_WHITESPACE:
						$result.=$text;
						break;
					default:
						if (preg_match('/\b(__[a-zA-Z0-9_]+__)\b/', htmlspecialchars($text))) {
							$result.='<span class="php_token_magic_constant">'.htmlspecialchars($text).'</span>';
						}
						else
						{
							$result.='<span class="php_token_other">'.htmlspecialchars($text).'</span>';
						}
						break;
				}
			}
			else
			{
				if (ctype_punct($token)) {
					$result.='<span class="php_token_operator">'.htmlspecialchars($token).'</span>';
				}
				else
				{
					$result.='<span class="php_token_other">'.htmlspecialchars($token).'</span>';
				}
			}
		}
		if(is_array($containerization)){
			$result.='</div>';
		}
		$result.='</div>';
		$regex = '/(<span class="php_token_other">\\\\?<\/span>)?((?:<span class="php_token_other">[\w\\\\]+<\/span><span class="php_token_other">::<\/span>)?)(<span class="php_token_other">\w+<\/span>)/';
		$result = preg_replace_callback($regex, function($matches) {
			$prefix_text = strip_tags($matches[1] ?? '');
			$class_or_namespace_text = strip_tags($matches[2] ?? '');
			$function_name_text = strip_tags($matches[3]);
			return '<span class="php_token_user_function">' . htmlspecialchars($prefix_text . $class_or_namespace_text . $function_name_text) . '</span>';
		}, $result);
		return $style.$container_javascript.nl2br(str_replace('	', '&emsp;', $result));
	}

	/**
	 * Loads Datadoc UI asset helpers on demand for embedded highlighter output.
	 *
	 * Flightdeck can render Datadoc source views without booting the standalone
	 * UI asset route first, so the highlighter lazily includes the shared asset
	 * support file before resolving CSS or JavaScript dependencies.
	 *
	 * @return void
	 */
	private static function load_asset_support(): void {
		if(!function_exists('\dataphyre_datadoc_ui_asset_url')){
			$asset_support=dirname(__DIR__).'/ui/assets_support.php';
			if(is_file($asset_support)){
				require_once($asset_support);
			}
		}
	}

	/**
	 * Builds the CSS or JavaScript tag required by a Datadoc source block.
	 *
	 * Asset content is inlined when available so Flightdeck-embedded Datadoc
	 * pages keep highlighting even when the standalone asset endpoint is not
	 * reachable. When only URLs are available, the tag falls back to the shared
	 * UI asset route and returns an empty string if the asset layer is absent.
	 *
	 * @param string $asset Datadoc UI asset filename.
	 * @param string $type Asset kind, either `style` or `script`.
	 *
	 * @return string HTML tag for the highlighter dependency, or an empty string.
	 */
	private static function highlighter_asset_tag(string $asset, string $type): string {
		self::load_asset_support();
		if(function_exists('\dataphyre_datadoc_ui_asset_content')){
			$content=\dataphyre_datadoc_ui_asset_content($asset);
			if(is_array($content) && isset($content['content'])){
				if($type==='script'){
					return '<script data-datadoc-highlighter="1">'.(string)$content['content'].'</script>';
				}
				return '<style data-datadoc-highlighter="1">'.(string)$content['content'].'</style>';
			}
		}
		if(function_exists('\dataphyre_datadoc_ui_asset_url')!==true){
			return '';
		}
		$url=\dataphyre_datadoc_ui_asset_url($asset);
		if($url===''){
			return '';
		}
		$escaped=htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		if($type==='script'){
			return '<script data-datadoc-highlighter="1" src="'.$escaped.'" defer></script>';
		}
		return '<link rel="stylesheet" href="'.$escaped.'">';
	}

}
