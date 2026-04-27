<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
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
		$reserved=['endslot', 'endblock', 'endif', 'else', 'endloop', 'break', 'continue'];
        foreach($matches[1] as $variable){
			if(in_array(strtolower((string)$variable), $reserved, true)){
				continue;
			}
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
		preg_match_all('/{{\s*slot\s+"([\w\-]+)"\s*}}(.*?){{\s*endslot\s*}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$slotName=$match[1];
			$defaultContent=$match[2];
			$slotContent=$slots[$slotName] ?? $defaultContent;
			$template=str_replace($match[0], $slotContent, $template);
		}
		return $template;
	}

    private static function parse_scoped_styles(string $template, string $component_name): string {
        $unique_id = 'comp_' . $component_name . '_' . uniqid();
        preg_match_all('/<style scoped>(.*?)<\/style>/s', $template, $matches);
        foreach ($matches[1] as $style) {
            $scoped_style = preg_replace('/(^|\s|\})\.(\w+)/', "$1.$unique_id-$2", $style);
            $template = str_replace("<style scoped>$style</style>", "<style>$scoped_style</style>", $template);
        }
        return "<div class='$unique_id'>$template</div>";
    }

    private static function parse_dynamic_imports(string $template, array $data): string {
        preg_match_all('/{{\s*import\s+"([^"]+)"\s*if\s*([\w\.]+)\s*}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $condition=(string)$match[2];
            $should_import=!empty(self::get_value_by_path($data, $condition));
            $import_path=$should_import ? self::resolve_template_reference($match[1]) : null;
            self::record_manifest_structured('imports', [
                'reference'=>$match[1],
                'template'=>$import_path,
                'condition'=>$condition,
                'included'=>$should_import && $import_path!==null,
            ]);
            if($should_import && $import_path===null){
                self::record_missing_reference('import', $match[1]);
            }
            if(!empty(self::get_value_by_path($data, $match[2]))){
                $template=str_replace($match[0], $import_path!==null ? self::full_render($import_path, $data) : '', $template);
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
        if(preg_match('/{{\s*extends\s*"([^"]+\.tpl)"\s*}}/', $template, $match)){
            $base_template_file=self::resolve_template_reference($match[1]);
            if($base_template_file!==null){
                self::record_manifest_structured('layouts', [
                    'reference'=>$match[1],
                    'template'=>$base_template_file,
                    'style'=>'extends',
                ]);
                $base_template=file_get_contents($base_template_file);
                $template=str_replace($match[0], '', $template);
                preg_match_all('/{{\s*block\s+(\w+)\s*}}(.*?){{\s*endblock\s*}}/s', $template, $blocks, PREG_SET_ORDER);
                foreach($blocks as $block){
                    $block_tag="{{block ".$block[1]."}}";
                    $base_template=str_replace($block_tag, $block[2], $base_template);
                }
                return $base_template;
            }
            self::record_missing_reference('layout', $match[1]);
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
			$base_template_path=self::resolve_template_reference($match[1]);
			if($base_template_path===null){
				self::record_missing_reference('layout', $match[1]);
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Base template not found: ".$match[1]);
				return $template;
			}
			self::record_manifest_structured('layouts', [
				'reference'=>$match[1],
				'template'=>$base_template_path,
				'style'=>'extend',
			]);
			$baseTemplate=self::load_template_file($base_template_path);
			preg_match_all('/{{ block "(.+?)" }}(.*?){{ endblock }}/s', $template, $childBlocks, PREG_SET_ORDER);
			foreach($childBlocks as $childBlock){
				$baseTemplate=str_replace("{{ block_content \"{$childBlock[1]}\" }}", $childBlock[2], $baseTemplate);
			}
			return $baseTemplate;
		}
		return $template;
	}
	
	private static function parse_partials(string $template, array $data): string {
		preg_match_all('/{{\s*include\s+"([^"]+\.tpl)"(?:\s+with\s+([\w\.]+))?\s*}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$partial_file=self::resolve_template_reference($match[1]);
			$partial_data=isset($match[2]) && $match[2]!=='' ? (self::get_value_by_path($data, $match[2]) ?? $data) : $data;
			if(!is_array($partial_data)){
				$partial_data=$data;
			}
			if($partial_file!==null){
				self::record_manifest_structured('partials', [
					'reference'=>$match[1],
					'template'=>$partial_file,
					'data_scope'=>$match[2] ?? null,
				]);
				$partial_content=self::full_render($partial_file, $partial_data);
				$template=str_replace($match[0], $partial_content, $template);
			} else {
				self::record_missing_reference('partial', $match[1]);
				tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Partial template not found: ".$match[1]);
				$template=str_replace($match[0], '', $template);
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
