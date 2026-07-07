<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\datadoc;

/**
 * Static PHP tokenizer used by Datadoc indexing.
 *
 * The tokenizer reads source text without executing project code, pairs each discovered symbol
 * with the closest preceding PHPDoc block, and returns the compact record shape consumed by
 * `datadoc_data`. It intentionally favors stable indexing over full PHP semantic analysis:
 * scripts embedded in mixed PHP/HTML files are skipped, symbol bodies are captured by brace
 * balance, and PHPDoc tags are preserved as strings or repeated-tag lists.
 */
class tokenizer{

	/**
	 * Parses a raw PHPDoc block into Datadoc description and tag fields.
	 *
	 * Freeform prose before the first tag becomes `description`. Every `@tag` payload is stored
	 * under its tag name; repeated tags such as `@param` are promoted to a list so Datadoc does
	 * not lose overload, parameter, or example metadata during indexing.
	 *
	 * @param string $phpdoc Raw `/** ... *\/` block text.
	 * @return array{description:string,tags:array<string,string|list<string>>}
	 */
	protected static function parse_phpdoc(string $phpdoc): array {
		$tags=[];
		$current_type='';
		$current_index=null;
		$description=[];
		$lines=explode("\n", $phpdoc);
		foreach($lines as $phpdoc_line){
			$comment_line=trim($phpdoc_line);
			$comment_line=preg_replace('#^/\*\*#', '', $comment_line);
			$comment_line=preg_replace('#\*/$#', '', $comment_line);
			$comment_line=preg_replace('#^\*\s?#', '', trim((string)$comment_line));
			$comment_line=trim((string)$comment_line);
			if($comment_line===''){
				continue;
			}
			if(preg_match('/@(\w+)\s*(.*)/', $comment_line, $matches)){
				$current_type=$matches[1];
				$current_index=null;
				$value=trim($matches[2]);
				if(isset($tags[$current_type])){
					if(!is_array($tags[$current_type]) || array_is_list($tags[$current_type])===false){
						$tags[$current_type]=[$tags[$current_type]];
					}
					$tags[$current_type][]=$value;
					$current_index=count($tags[$current_type])-1;
				}
				else
				{
					$tags[$current_type]=$value;
				}
				continue;
			}
			if($current_type!==''){
				if(is_array($tags[$current_type])){
					$index=$current_index ?? (count($tags[$current_type])-1);
					$tags[$current_type][$index]=trim(((string)($tags[$current_type][$index] ?? ''))."\n".$comment_line);
				}
				else
				{
					$tags[$current_type]=trim(((string)($tags[$current_type] ?? ''))."\n".$comment_line);
				}
				continue;
			}
			$description[]=$comment_line;
		}
		return [
			'description'=>implode("\n", $description),
			'tags'=>$tags
		];
	}

	/**
	 * Tracks whether the current line is part of a multi-line quoted string.
	 *
	 * Datadoc's tokenizer is intentionally lightweight and line-oriented. This
	 * guard prevents SQL DDL strings and similar embedded text from being
	 * mistaken for PHP declarations such as `class TEXT`.
	 */
	private static function line_is_multiline_string(?string &$quote, string $line): bool {
		$started_in_string=$quote!==null;
		$length=strlen($line);
		for($i=0; $i<$length; $i++){
			$char=$line[$i];
			if($char==='\\'){
				$i++;
				continue;
			}
			if($quote!==null){
				if($char===$quote){
					$quote=null;
				}
				continue;
			}
			if($char==='\'' || $char==='"'){
				$quote=$char;
			}
		}
		return $started_in_string || $quote!==null;
	}

	/**
	 * Tokenizes a PHP source file for Datadoc indexing.
	 *
	 * Reads the file line by line, skips embedded `<script>` blocks, captures namespace, class,
	 * function, and variable declarations, and attaches the most recent PHPDoc block to the next
	 * symbol. Captured `content` is the declaration/body slice Datadoc uses for highlighting and
	 * contextual display rather than a normalized AST.
	 *
	 * @param non-empty-string $filename PHP source file to inspect without executing it.
	 * @return list<array{type:string,namespace:string,class:string,function:string,content:string,line:int,phpdoc:array{description:string,tags:array<string,string|list<string>>}}>|false
	 */
	public static function tokenize($filename){
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(!file_exists($filename)){
			return false;
		}
		$tokens=[];
		$current_namespace='';
		$current_class='';
		$current_function='';
		$current_phpdoc=['description'=>'', 'tags'=>[]];
		$inside_phpdoc=false;
		$temp_phpdoc_string='';
		$current_line=0;
		$inside_script=false;
		$active_namespace=null;
		$active_class=null;
		$active_function=null;
		$multiline_string_quote=null;
		$file=fopen($filename, "r");
		if(!$file){
			return false;
		}
		while(($line=fgets($file)) !== false){
			$current_line++;
			$trimmed_line=trim($line);
			if(preg_match('/<script[^>]*>/i', $trimmed_line)){
				$inside_script=true;
			}
			if($inside_script){
				if(preg_match('/<\/script>/i', $trimmed_line)){
					$inside_script=false;
				}
				continue;
			}

			if($inside_phpdoc){
				$temp_phpdoc_string.=$line;
				if(str_contains($trimmed_line, '*/')){
					$inside_phpdoc=false;
					$current_phpdoc=self::parse_phpdoc($temp_phpdoc_string);
					$temp_phpdoc_string='';
				}
				continue;
			}
			if(str_contains($trimmed_line, '/**')){
				$inside_phpdoc=true;
				$temp_phpdoc_string=$line;
				if(str_contains($trimmed_line, '*/')){
					$inside_phpdoc=false;
					$current_phpdoc=self::parse_phpdoc($temp_phpdoc_string);
					$temp_phpdoc_string='';
				}
				continue;
			}

			foreach(['namespace', 'class', 'function'] as $active_key){
				$active_var='active_'.$active_key;
				if($$active_var===null){
					continue;
				}
				$$active_var['content'].=$line;
				$$active_var['balance']+=substr_count($line, '{')-substr_count($line, '}');
				$tokens[$$active_var['index']]['content']=$$active_var['content'];
				if($$active_var['balance']<=0 && $$active_var['close_on_zero']===true){
					if($active_key==='class'){
						$current_class='';
					}
					if($active_key==='function'){
						$current_function='';
					}
					if($active_key==='namespace' && $$active_var['reset_namespace']===true){
						$current_namespace='';
					}
					$$active_var=null;
				}
			}

			if(self::line_is_multiline_string($multiline_string_quote, $line)){
				continue;
			}

			if(preg_match('/^namespace\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*)/', $trimmed_line, $matches)){
				$current_namespace=$matches[1];
				$tokens[]=[
					'type'=>'namespace',
					'namespace'=>$current_namespace,
					'class'=>'',
					'function'=>'',
					'content'=>$line,
					'line'=>$current_line,
					'phpdoc'=>$current_phpdoc
				];
				$index=array_key_last($tokens);
				$brace_balance=substr_count($line, '{')-substr_count($line, '}');
				$active_namespace=[
					'index'=>$index,
					'content'=>$line,
					'balance'=>$brace_balance,
					'close_on_zero'=>str_contains($line, '{'),
					'reset_namespace'=>str_contains($line, '{')
				];
				if($active_namespace['close_on_zero']===false){
					$active_namespace=null;
				}
				elseif($active_namespace['balance']<=0){
					$current_namespace='';
					$active_namespace=null;
				}
				$current_phpdoc=['description'=>'', 'tags'=>[]];
			}

			if(preg_match('/^(?:abstract\s+|final\s+)?class\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $trimmed_line, $matches)){
				$current_class=$matches[1];
				$tokens[]=[
					'type'=>'class',
					'class'=>$current_class,
					'function'=>'',
					'content'=>$line,
					'namespace'=>$current_namespace,
					'line'=>$current_line,
					'phpdoc'=>$current_phpdoc
				];
				$index=array_key_last($tokens);
				$brace_balance=substr_count($line, '{')-substr_count($line, '}');
				$active_class=[
					'index'=>$index,
					'content'=>$line,
					'balance'=>$brace_balance,
					'close_on_zero'=>true
				];
				if(!str_contains($line, '{')){
					$active_class['balance']=1;
				}
				elseif($active_class['balance']<=0){
					$current_class='';
					$active_class=null;
				}
				$current_phpdoc=['description'=>'', 'tags'=>[]];
			}

			if(preg_match('/^(?:public|private|protected)?\s*(?:final\s+)?(?:static\s+)?function\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $trimmed_line, $matches)){
				$current_function=$matches[1];
				$tokens[]=[
					'type'=>'function',
					'content'=>$line,
					'function'=>$current_function,
					'namespace'=>$current_namespace,
					'class'=>$current_class,
					'line'=>$current_line,
					'phpdoc'=>$current_phpdoc
				];
				$index=array_key_last($tokens);
				$brace_balance=substr_count($line, '{')-substr_count($line, '}');
				$active_function=[
					'index'=>$index,
					'content'=>$line,
					'balance'=>$brace_balance,
					'close_on_zero'=>true
				];
				if(!str_contains($line, '{')){
					if(str_ends_with($trimmed_line, ';')){
						$current_function='';
						$active_function=null;
					}
					else
					{
						$active_function['balance']=1;
					}
				}
				elseif($active_function['balance']<=0){
					$current_function='';
					$active_function=null;
				}
				$current_phpdoc=['description'=>'', 'tags'=>[]];
			}

			if(preg_match('/^\s*(?:public|private|protected)?\s*(?:static\s+)?\$([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/', $trimmed_line, $matches)){
				$tokens[]=[
					'type'=>'variable',
					'content'=>$matches[1],
					'namespace'=>$current_namespace,
					'class'=>$current_class,
					'function'=>$current_function,
					'line'=>$current_line,
					'phpdoc'=>$current_phpdoc
				];
				$current_phpdoc=['description'=>'', 'tags'=>[]];
			}

			if(preg_match('/tracelog\((.*)\)/', $trimmed_line, $matches) && !str_contains($matches[1], "function_call_with_test")){
				$tokens[]=[
					'type'=>'tracelog',
					'content'=>$matches[1],
					'namespace'=>$current_namespace,
					'class'=>$current_class,
					'function'=>$current_function,
					'line'=>$current_line
				];
			}
		}
		fclose($file);
		return $tokens;
	}
	
}
