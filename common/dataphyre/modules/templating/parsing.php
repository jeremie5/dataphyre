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

trait parsing {

    private static function bind_data(string $template, array &$data): string {
        preg_match_all('/{{bind(\w+)}}/', $template, $matches);
        foreach($matches[1] as $var){
            if(isset($data[$var])){
                $template=str_replace("{{bind $var}}", htmlspecialchars($data[$var]), $template);
            }
        }
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

	private static function parse_lazy_load_components(string $template, array $data): string {
		preg_match_all('/{{lazyLoadComponent\s*\'(\w+)\'}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$component_name=$match[1];
			$placeholder="<div class='lazy-component' data-component='{$component_name}'>Loading...</div>";
			$template=str_replace($match[0], $placeholder, $template);
		}
		return $template;
	}

	private static function parse_slots(string $template, array $data, array $slots=[]): string {
		preg_match_all('/{{slot\s*"(\w+)"}}(.*?){{endslot}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$slotName=$match[1];
			$defaultContent=$match[2];
			$slotContent=$slots[$slotName] ?? $defaultContent;
			$template=str_replace($match[0], $slotContent, $template);
		}
		return $template;
	}

    private static function parse_scoped_styles(string $template, string $component_name): string {
        preg_match_all('/<style scoped>(.*?)<\/style>/s', $template, $matches);
        foreach($matches[1] as $style){
            $scopedStyle=str_replace('.', ".{$component_name} ", $style);
            $template=str_replace("<style scoped>$style</style>", $scopedStyle, $template);
        }
        return $template;
    }

    private static function parse_dynamic_imports(string $template, array $data): string {
        preg_match_all('/{{import(\w+)\s*if\s*(\w+)}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            if(!empty($data[$match[2]])){
                $template=str_replace($match[0], self::full_render($match[1].'.tpl', $data), $template);
            } else {
                $template=str_replace($match[0], '', $template);
            }
        }
        return $template;
    }
	
	private static function parse_attributes(string $template, array $data): string {
		preg_match_all('/{{addClass "(.+?)"}}/', $template, $matches);
		foreach($matches[1] as $class){
			$template=str_replace("{{addClass \"$class\"}}", "class=\"$class\"", $template);
		}
		return $template;
	}
	
    private static function parse_layout_inheritance(string $template): string {
        if(preg_match('/{{extends(\w+\.tpl)}}/', $template, $match)){
            $base_template_file=$match[1];
            if(file_exists($base_template_file)){
                $base_template=file_get_contents($base_template_file);
                $template=str_replace($match[0], '', $template);
                preg_match_all('/{{block(\w+)}}(.*?){{endblock}}/s', $template, $blocks, PREG_SET_ORDER);
                foreach($blocks as $block){
                    $block_tag="{{block ".$block[1]."}}";
                    $base_template=str_replace($block_tag, $block[2], $base_template);
                }
                return $base_template;
            }
        }
        return $template;
    }
	
	private static function parse_php_blocks(string $template): string {
		preg_match_all('/{{php\s*(.*?)\s*}}/s', $template, $matches);
		foreach($matches[1] as $code){
			ob_start();
			eval($code);
			$result=ob_get_clean();
			$template=str_replace("{{php $code}}", $result, $template);
		}
		return $template;
	}

	private static function parse_debug(string $template, array $data): string {
		preg_match_all('/{{debug(\w+)}}/', $template, $matches);
		foreach($matches[1] as $var_name){
			$output=isset($data[$var_name]) ? print_r($data[$var_name], true) : 'undefined';
			$template=str_replace("{{debug $var_name}}", '<pre>'.htmlspecialchars($output).'</pre>', $template);
		}
		return $template;
	}
	
	private static function parseInheritance(string $template): string {
		if(preg_match('/{{ extend "(.+?)" }}/', $template, $match)){
			$baseTemplate=self::load_template_file($match[1]);
			preg_match_all('/{{ block "(.+?)" }}(.*?){{ endblock }}/s', $template, $childBlocks, PREG_SET_ORDER);
			foreach($childBlocks as $childBlock){
				$baseTemplate=str_replace("{{ block_content \"{$childBlock[1]}\" }}", $childBlock[2], $baseTemplate);
			}
			return $baseTemplate;
		}
		return $template;
	}
	
	private static function parse_partials(string $template, array $data): string {
		preg_match_all('/{{include "(\w+\.tpl)"(?: with(\w+))?}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$partial_file=$match[1];
			$partial_data=$data[$match[2]] ?? $data;
			if(file_exists($partial_file)){
				$partial_content=self::full_render($partial_file, $partial_data);
				$template=str_replace($match[0], $partial_content, $template);
			} else {
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Partial template not found: $partial_file");
			}
		}
		return $template;
	}

    private static function replace_placeholders(string $template, array $data): string {
        foreach($data as $key=>$value){
            if(is_array($value) || is_object($value)){
                $template=self::replace_nested_placeholders($template, $key, $value);
            } else {
                $template=str_replace("{{".$key."}}", htmlspecialchars($value), $template);
            }
        }
        return $template;
    }

	private static function parse_loop_controls(string $template, array $data): string {
		preg_match_all('/{{loop(\w+)}}(.*?){{endloop}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$loop_content='';
			if(isset($data[$match[1]]) && is_array($data[$match[1]])){
				foreach($data[$match[1]] as $item){
					$content=$match[2];
					if(strpos($content, '{{break}}') !== false){
						$loop_content.=strstr($content, '{{break}}', true);
						break;
					}
					if(strpos($content, '{{continue}}') !== false){
						$loop_content.=strstr($content, '{{continue}}', true);
						continue;
					}
					$loop_content.=self::replace_placeholders($content, $item);
				}
			}
			$template=str_replace($match[0], $loop_content, $template);
		}
		return $template;
	}

}
