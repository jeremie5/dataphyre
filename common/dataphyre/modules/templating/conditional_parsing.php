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

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait conditional_parsing {
	
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
	
    private static function parse_conditionals(string $template, array $data): string {
        preg_match_all('/{{if(\w+)}}(.*?){{endif}}/s', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $condition_content=isset($data[$match[1]]) && $data[$match[1]] ? $match[2] : '';
            $template=str_replace($match[0], $condition_content, $template);
        }
        return $template;
    }
	
	private static function parse_inline_conditionals(string $template, array $data): string {
		preg_match_all('/{{if(.+?)}}(.*?){{endif}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$condition=self::evaluate_condition($match[1], $data);
			$condition_content=$condition ? $match[2] : '';
			$template=str_replace($match[0], $condition_content, $template);
		}
		return $template;
	}
	
	private static function evaluate_condition(string $expression, array $data): mixed {
		foreach($data as $key=>$value){
			if(is_scalar($value)){
				$expression=str_replace($key, var_export($value, true), $expression);
			}
		}
		return eval("return $expression;");
	}
	
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