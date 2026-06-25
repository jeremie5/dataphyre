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
 * Adds the template renderer's legacy loop and conditional expansion passes.
 *
 * The trait operates on already-loaded template source and caller-provided render
 * data only. It does not execute PHP, call services, escape selected branch
 * markup, or mutate shared renderer state; callers are responsible for ordering
 * these regex passes with placeholder replacement and output escaping.
 */
trait conditional_parsing {

	/**
	 * Expands simple loop blocks from array-backed render data.
	 *
	 * Loop names are limited by the directive grammar to word characters and the
	 * body is evaluated once per array item using that item as the local
	 * placeholder scope. Each item is expected to be an array because a zero-based
	 * `loop.index` key is injected before replacement. Missing, scalar, or object
	 * loop collections collapse the block to an empty string, but scalar entries
	 * inside an otherwise valid collection are not normalized by this legacy pass.
	 *
	 * @param string $template Template source containing `{{loopName}}...{{endloop}}` blocks.
	 * @param array<string, mixed> $data Render data keyed by loop name.
	 * @return string Template source with simple loop blocks replaced.
	 */
    private static function parse_loops(string $template, array $data): string {
        preg_match_all('/{{loop(\w+)}}(.*?){{endloop}}/s', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $loop_content='';
            if(isset($data[$match[1]]) && is_array($data[$match[1]])){
                $index=0;
                foreach($data[$match[1]] as $item){
                    $item['loop.index']=$index++;
                    $loop_content.=self::replace_placeholders($match[2], $item);
                }
            }
            $template=str_replace($match[0], $loop_content, $template);
        }
        return $template;
    }

	/**
	 * Resolves legacy truthy conditional blocks.
	 *
	 * This compatibility pass supports only bare word data keys and simple PHP
	 * truthiness. It is intended to run after advanced and inline conditionals, so
	 * any remaining `{{ifName}}...{{endif}}` block is treated as a shorthand rather
	 * than the expression language handled by evaluate_condition(). Missing keys
	 * fail closed by removing the block body.
	 *
	 * @param string $template Template source containing legacy conditional blocks.
	 * @param array<string, mixed> $data Render data keyed by condition name.
	 * @return string Template source with truthy blocks retained and falsy blocks removed.
	 */
    private static function parse_conditionals(string $template, array $data): string {
        preg_match_all('/{{if(\w+)}}(.*?){{endif}}/s', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $condition_content=isset($data[$match[1]]) && $data[$match[1]] ? $match[2] : '';
            $template=str_replace($match[0], $condition_content, $template);
        }
        return $template;
    }

	/**
	 * Resolves inline expression conditional blocks without else branches.
	 *
	 * Expressions are evaluated by the bounded condition evaluator rather than PHP
	 * itself. This keeps template conditionals inside the data/render boundary while
	 * supporting dotted data paths, literals, boolean operators, and comparisons.
	 * Branch contents are inserted verbatim because escaping belongs to the template
	 * authoring and placeholder-rendering layers.
	 *
	 * @param string $template Template source containing expression conditionals.
	 * @param array<string, mixed> $data Render data used by expression lookup.
	 * @return string Template source with passing blocks retained and failing blocks removed.
	 */
	private static function parse_inline_conditionals(string $template, array $data): string {
		preg_match_all('/{{if(.+?)}}(.*?){{endif}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$condition=self::evaluate_condition($match[1], $data);
			$condition_content=$condition ? $match[2] : '';
			$template=str_replace($match[0], $condition_content, $template);
		}
		return $template;
	}

	/**
	 * Evaluates the template conditional expression language.
	 *
	 * The grammar is intentionally small: `||` splits disjunctions, `&&` splits
	 * conjunctions, and atoms are delegated to evaluate_condition_atom(). Empty
	 * expressions are false. Parentheses do not provide full parser precedence here;
	 * only whole-atom wrapping is normalized by the atom parser, so template authors
	 * should keep mixed operator expressions explicit and simple.
	 *
	 * @param string $expression Conditional expression from a template directive.
	 * @param array<string, mixed> $data Render data available for token resolution.
	 * @return bool True when any OR branch has all AND atoms passing.
	 */
	private static function evaluate_condition(string $expression, array $data): bool {
		$expression=trim($expression);
		if($expression===''){
			return false;
		}
		foreach(preg_split('/\s*\|\|\s*/', $expression) ?: [] as $or_part){
			$and_result=true;
			foreach(preg_split('/\s*&&\s*/', (string)$or_part) ?: [] as $and_part){
				if(self::evaluate_condition_atom((string)$and_part, $data)!==true){
					$and_result=false;
					break;
				}
			}
			if($and_result===true){
				return true;
			}
		}
		return false;
	}

	/**
	 * Evaluates one atomic conditional expression.
	 *
	 * Atoms may be negated, wrapped in outer parentheses, compared with PHP loose or
	 * strict comparison operators, or treated as truthy data/literal values. Values
	 * are resolved through condition_value(), so missing data paths become null
	 * instead of throwing during template rendering. No atom can invoke functions or
	 * traverse object properties, which keeps template conditions inside the render
	 * data security boundary.
	 *
	 * @param string $expression Atomic expression text.
	 * @param array<string, mixed> $data Render data available for token resolution.
	 * @return bool Boolean outcome for the atom.
	 */
	private static function evaluate_condition_atom(string $expression, array $data): bool {
		$expression=trim($expression);
		while(str_starts_with($expression, '(') && str_ends_with($expression, ')')){
			$expression=trim(substr($expression, 1, -1));
		}
		if($expression===''){
			return false;
		}
		if(str_starts_with($expression, '!')){
			return !self::evaluate_condition_atom(substr($expression, 1), $data);
		}
		if(preg_match('/^(.+?)\s*(===|!==|==|!=|>=|<=|>|<)\s*(.+)$/', $expression, $match)){
			$left=self::condition_value($match[1], $data);
			$right=self::condition_value($match[3], $data);
			return match($match[2]){
				'==='=>$left===$right,
				'!==' =>$left!==$right,
				'==' =>$left==$right,
				'!=' =>$left!=$right,
				'>=' =>$left>=$right,
				'<=' =>$left<=$right,
				'>' =>$left>$right,
				'<' =>$left<$right,
				default=>false,
			};
		}
		return (bool)self::condition_value($expression, $data);
	}

	/**
	 * Converts a conditional token into a scalar, null, or data value.
	 *
	 * Quoted strings are unescaped, booleans and null are recognized
	 * case-insensitively, numeric tokens become int or float values, and all other
	 * tokens are treated as direct or dotted data paths. No function calls,
	 * arbitrary PHP, object traversal, or service lookups are permitted in template
	 * conditions.
	 *
	 * @param string $token Token from the conditional expression language.
	 * @param array<string, mixed> $data Render data available for token resolution.
	 * @return mixed Parsed literal, direct data value, dotted-path value, or null when unresolved.
	 */
	private static function condition_value(string $token, array $data): mixed {
		$token=trim($token);
		if($token===''){
			return null;
		}
		if((str_starts_with($token, "'") && str_ends_with($token, "'")) || (str_starts_with($token, '"') && str_ends_with($token, '"'))){
			return stripcslashes(substr($token, 1, -1));
		}
		$lower=strtolower($token);
		if($lower==='true'){
			return true;
		}
		if($lower==='false'){
			return false;
		}
		if($lower==='null'){
			return null;
		}
		if(is_numeric($token)){
			return str_contains($token, '.') ? (float)$token : (int)$token;
		}
		return self::data_value($data, $token);
	}

	/**
	 * Resolves a direct or dotted data path against render data.
	 *
	 * Exact key lookup wins before dotted traversal so data can contain literal
	 * dotted keys. Traversal only descends through arrays; missing segments and
	 * non-array intermediates resolve to null, which lets conditional rendering fail
	 * closed without surfacing template warnings or exposing object internals.
	 *
	 * @param array<string, mixed> $data Render data tree.
	 * @param string $path Direct key or dot-delimited data path.
	 * @return mixed Resolved value, or null when the path is absent.
	 */
	private static function data_value(array $data, string $path): mixed {
		$path=trim($path);
		if(array_key_exists($path, $data)){
			return $data[$path];
		}
		$value=$data;
		foreach(explode('.', $path) as $segment){
			if(is_array($value) && array_key_exists($segment, $value)){
				$value=$value[$segment];
				continue;
			}
			return null;
		}
		return $value;
	}

	/**
	 * Resolves expression conditionals with optional elseif and else branches.
	 *
	 * This is the richer conditional block pass in the render pipeline. It evaluates
	 * the first `if`, then a single optional `elseif`, then an optional `else`;
	 * branch bodies are inserted as already-authored template markup and are not
	 * escaped at this stage. Nested conditionals and multiple elseif branches are
	 * outside this regex pass's contract and must be handled before or after this
	 * pass by the caller's pipeline. Missing optional captures are treated as empty
	 * branch bodies so malformed or partial blocks fail by removing unmatched markup.
	 *
	 * @param string $template Template source containing advanced conditional blocks.
	 * @param array<string, mixed> $data Render data used by expression lookup.
	 * @return string Template source with advanced conditional blocks replaced by the selected branch.
	 */
	private static function parse_advanced_conditionals(string $template, array $data): string {
		preg_match_all('/{{if(.+?)}}(.*?)({{elseif(.+?)}}(.*?))?({{else}}(.*?))?{{endif}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$condition=self::evaluate_condition($match[1], $data);
			if($condition){
				$content=$match[2];
			} elseif(!empty($match[4]) && self::evaluate_condition($match[4], $data)){
				$content=$match[5];
			} else {
				$content=$match[7] ?? '';
			}
			$template=str_replace($match[0], $content, $template);
		}
		return $template;
	}

}
