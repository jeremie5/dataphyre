<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\datadoc;

class highlighter{
	
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
				$currentNamespace = $namespace;
				$currentClass = $class;
				$prefix = '';
				$postfix = '';
				$linkedContent = '';
				switch ($token) {
					case 'php_token_user_function':
						if($project===''){
							return $matches[0];
						}
						$originalContent = $content;  // Store the original content
						$currentNamespace = '';
						$currentClass = '';
						$functionName = '';
						$content = ltrim($content, '\\');  // Remove leading backslash if any
						// Split class and function if '::' is present
						if (strpos($content, '::') !== false) {
							list($classWithNamespace, $functionName) = explode('::', $content);
							$parts = explode('\\', $classWithNamespace);
							$currentClass = array_pop($parts);
							$currentNamespace = implode('\\', $parts);
						} else {
							$parts = explode('\\', $content);
							$functionName = array_pop($parts);
							$currentNamespace = implode('\\', $parts);
						}
						$url = $datadoc_base_url . "/dataphyre/datadoc/" . rawurlencode($project) . "/dynadoc?" . http_build_query([
							'type'=>'function',
							'namespace'=>$currentNamespace,
							'class'=>$currentClass,
							'function'=>$functionName
						]);
						$linkedContent = "<a href=\"{$url}\">{$originalContent}</a>";  // Use original content here
						// Replace the original span's content with the linked version, as needed
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
				if($linkedContent!==''){
					return $linkedContent;
				}
				if($url===''){
					return $matches[0];
				}
				return '<a class="'.$token.'" href="'.$url.'" rel="noreferrer" target="_blank" style="color:inherit;cursor:pointer; text-decoration: none;">' . $prefix. $content . $postfix . '</a>';
			}, $input);
		}
		return $input;
	}

	public static function highlight_code(string $content, string $language='php', array|bool $containerization=false, string $theme='npp_deep_black'){
		if($language==='php'){
			$content=highlighter::highlight_php($content, $containerization, $theme);
		}
		return $content;
	}
		
	public static function retabulate_php($code){
		$lines = explode("\n", $code);
		$retabulatedLines = [];
		$indentLevel = 0;
		foreach ($lines as $line) {
			$trimmedLine = trim($line);
			if ($trimmedLine === '') {
				$retabulatedLines[] = '';
				continue;
			}
			// Decrement indent level for closing braces
			if (str_contains($trimmedLine, '}')) {
				$indentLevel--;
			}
			// Apply current indentation
			$indentedLine = str_repeat("\t", $indentLevel).$trimmedLine;
			$retabulatedLines[] = $indentedLine;
			// Increment indent level for opening braces
			if (str_contains($trimmedLine, '{')) {
				$indentLevel++;
			}
		}
		return implode("\n", $retabulatedLines);
	}
		
	public static function highlight_php(string $content, bool|array $containerization=false, string $theme='npp_deep_black'){
		$tokens=token_get_all("<?php\r\n$content");
		$result="";
		$highlightid=rand(0,9999999999);
		$style='';
		$container_javascript='';
		$container_classes='container rounded shadow bg-dark p-3';
		$skip_php_tag=true;
		$result.='<div class="dp_highlight'.$highlightid.'">';
		if($theme==='npp_deep_black' || empty($theme)){
			$style='
				<style>
				.dp_highlight'.$highlightid.' .php_token_string_doublequote{ color:#FFFF00 !important;}
				.dp_highlight'.$highlightid.' .php_token_string_singlequote{ color:#FFFF80 !important;}
				.dp_highlight'.$highlightid.' .php_token_keywords{ color:#00FFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_builtin_function{ color:#00FFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_user_function{ color:#FFFFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_constant{ color:#00FFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_other{ color:#FFFFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_variable{ color:#FF8000 !important;}
				.dp_highlight'.$highlightid.' .php_token_integer{ color:#FF00FF !important;}
				.dp_highlight'.$highlightid.' .php_token_comment{ color:#00FF00 !important; font-style: italic; }
				.dp_highlight'.$highlightid.' .php_token_tag{ color:#99CC99 !important;}
				.dp_highlight'.$highlightid.' .php_token_operator{ color:#C0C0C0 !important;}
				.dp_highlight'.$highlightid.' .php_token_magic_constant{ color:#00FFFF !important;}
				.dp_highlight'.$highlightid.' .php_token_magic_constant{ color:#00FFFF !important;}
				.dp_highlight'.$highlightid.' .line-number { color: #aaa; margin-right: 10px !important;}
				</style>';
		}
		if(is_array($containerization)){
			if(($containerization['show_lines'] ?? false)===true){
				$startLine = isset($containerization['line_number_start'])
					? (int)$containerization['line_number_start']
					: (isset($containerization['start_line']) ? $containerization['start_line'] - 2 : 1);
				$highlightLine=(int)($containerization['highlight_line'] ?? 0);
				$highlightOffset=(int)($containerization['highlight_offset'] ?? -1);
				$highlightClass=preg_replace('/[^A-Za-z0-9_-]/', '', (string)($containerization['highlight_class'] ?? ''));
				$container_javascript= '
				<script data-datadoc-highlighter="1">
				(function(){
					const annotate = () => {
						const codeContainer = document.getElementById("codeContainer'.$highlightid.'");
						if(!codeContainer || codeContainer.dataset.lineNumbered==="1"){
							return;
						}
						codeContainer.dataset.lineNumbered="1";
						let line_number = parseInt("' . $startLine . '");
						const highlight_line = parseInt("' . $highlightLine . '");
						const highlight_offset = parseInt("' . $highlightOffset . '");
						const highlight_class = "' . $highlightClass . '";
						let content = codeContainer.innerHTML.split("<br>");
						while(content.length>0 && content[content.length - 1].trim().length===0){
							content.pop();
						}
						let newContent = content.map((line, offset) => {
							const current_line = line_number;
							let newLine = `<span class="line-number">${current_line}</span> ${line}`;
							if((highlight_line===current_line || highlight_offset===offset) && highlight_class!==""){
								newLine = `<span class="${highlight_class}" data-line="${current_line}">${newLine}</span>`;
							}
							line_number++;
							return newLine;
						}).join("<br>");
						codeContainer.innerHTML = newContent;
					};
					if(document.readyState==="loading"){
						window.addEventListener("DOMContentLoaded", annotate, {once:true});
					}
					else{
						annotate();
					}
				})();
				</script>';
			}
			$result.='<div id="codeContainer'.$highlightid.'" class="'.$container_classes.'" style="overflow-x:auto;white-space: nowrap;">';
		}
		$builtinFunctions=get_defined_functions()['internal'];
		$userFunctions=get_defined_functions()['user'];
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
						if (in_array($text, $builtinFunctions)) {
							// Built-in function
							$result.='<span class="php_token_builtin_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array($text, array("isset", "array", "return", "empty", "unset", "include", "include_once", "require", "require_once", "print", "echo", "array", "eval", "exit", "die"))) {
							// Built-in function
							$result.='<span class="php_token_builtin_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array($text, $userFunctions)) {
							// User-defined function
							$result.='<span class="php_token_user_function">'.htmlspecialchars($text).'</span>';
						} elseif (in_array(strtolower($text), $keywords)) {
							// Keywords like true, false, null
							$result.='<span class="php_token_constant">'.htmlspecialchars($text).'</span>';
						} elseif (ctype_upper(str_replace('_', '', $text))) {
							// UPPERCASE_CONSTANTS like STR_PAD_LEFT
							$result.='<span class="php_token_constant">'.htmlspecialchars($text).'</span>';
						} else {
							// Other strings
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
			$prefixText = strip_tags($matches[1] ?? '');
			$classOrNamespaceText = strip_tags($matches[2] ?? '');
			$functionNameText = strip_tags($matches[3]);
			return '<span class="php_token_user_function">' . htmlspecialchars($prefixText . $classOrNamespaceText . $functionNameText) . '</span>';
		}, $result);
		return $style.$container_javascript.nl2br(str_replace('	', '&emsp;', $result));
	}
	
}
