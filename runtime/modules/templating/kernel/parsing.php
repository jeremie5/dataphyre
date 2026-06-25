<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Defines Templating kernel trait responsibilities for parsing.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait parsing {

    /**
     * Replaces legacy bind tags with escaped render-data values.
     *
     * Only variables present in the render data are replaced; missing bind tokens
     * remain for later diagnostics or parser stages. Values are escaped before
     * interpolation because bind tags are direct HTML output.
     *
     * @param string $template Template source containing bind tags.
     * @param array<string,mixed> $data Render data passed by reference for parser compatibility.
     * @return string Template source with available bind tags expanded.
     */
    private static function bind_data(string $template, array &$data): string {
        preg_match_all('/{{bind(\w+)}}/', $template, $matches);
        foreach($matches[1] as $var){
            if(isset($data[$var])){
                $template=str_replace("{{bind $var}}", htmlspecialchars($data[$var]), $template);
            }
        }
        return $template;
    }

    /**
     * Replaces unresolved simple placeholders with a visible undefined marker.
     *
     * reserved control tokens are ignored so block, slot, condition, and
     * loop markers can pass through later parser stages. Real missing variables
     * are traced before being replaced to make template data-shape gaps visible.
     */
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

	/**
	 * Converts lazy component directives into client-addressable placeholders.
	 *
	 * the parser does not hydrate the component; it emits a stable
	 * lazy-component container with a component name data attribute so the browser
	 * runtime can request or mount the component later.
	 */
	private static function parse_lazy_load_components(string $template, array $data): string {
		preg_match_all('/{{lazyLoadComponent\s*\'(\w+)\'}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$component_name=$match[1];
			$placeholder="<div class='lazy-component' data-component='{$component_name}'>Loading...</div>";
			$template=str_replace($match[0], $placeholder, $template);
		}
		return $template;
	}

	/**
	 * Resolves slot blocks using caller-supplied content or default slot bodies.
	 *
	 * named slots are replaced in-place, default content remains when no
	 * override is provided, and no escaping is applied because slot content is
	 * already treated as rendered template markup.
	 */
	private static function parse_slots(string $template, array $data, array $slots=[]): string {
		preg_match_all('/{{\s*slot\s+"([\w\-]+)"\s*}}(.*?){{\s*endslot\s*}}/s', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$slot_name=$match[1];
			$default_content=$match[2];
			$slot_content=$slots[$slot_name] ?? $default_content;
			$template=str_replace($match[0], $slot_content, $template);
		}
		return $template;
	}

    /**
     * Rewrites scoped component styles under a generated component wrapper class.
     *
     * scoped CSS is namespaced with a per-render id and the template is
     * wrapped in that id class, limiting component style bleed without requiring a
     * browser-native scoped-style feature.
     */
    private static function parse_scoped_styles(string $template, string $component_name): string {
        $unique_id = 'comp_' . $component_name . '_' . uniqid();
        preg_match_all('/<style scoped>(.*?)<\/style>/s', $template, $matches);
        if($matches[1]===[]){
            return $template;
        }
        foreach ($matches[1] as $style) {
            $scoped_style = preg_replace('/(^|\s|\})\.(\w+)/', "$1.$unique_id-$2", $style);
            $template = str_replace("<style scoped>$style</style>", "<style>$scoped_style</style>", $template);
        }
        return "<div class='$unique_id'>$template</div>";
    }

    /**
     * Resolves conditional import directives and records manifest evidence.
     *
     * imports are included only when a dotted data condition is truthy,
     * references are resolved through the template reference resolver, missing
     * imports are recorded, and included templates render with the current data.
     */
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
	
	/**
	 * Converts compact attribute directives into HTML attributes.
	 *
	 * addClass directives are expanded into class attributes as a narrow
	 * syntax convenience; attribute values are not data-bound in this pass, so
	 * callers must provide trusted class names in templates.
	 */
	private static function parse_attributes(string $template, array $data): string {
		preg_match_all('/{{addClass "(.+?)"}}/', $template, $matches);
		foreach($matches[1] as $class){
			$template=str_replace("{{addClass \"$class\"}}", "class=\"$class\"", $template);
		}
		return $template;
	}
	
    /**
     * Applies the `extends` layout inheritance form.
     *
     * base templates are resolved through the reference resolver, layout
     * usage is recorded in the render manifest, missing references are tracked, and
     * child block bodies replace matching block markers in the base template.
     */
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
	
	/**
	 * Removes disabled inline PHP template blocks.
	 *
	 * executable PHP blocks are intentionally stripped and traced as a
	 * warning, preserving the template engine's data/render boundary and preventing
	 * templates from becoming arbitrary runtime code execution surfaces.
	 */
	private static function parse_php_blocks(string $template): string {
		if(preg_match('/{{php\s*(.*?)\s*}}/s', $template)===1){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Inline PHP template blocks are disabled.', 'warning');
		}
		return preg_replace('/{{php\s*(.*?)\s*}}/s', '', $template) ?? $template;
	}

	/**
	 * Expands debug directives into escaped preformatted data dumps.
	 *
	 * debug output is limited to requested variables, missing values render
	 * as undefined, and the printed payload is escaped before insertion to keep the
	 * diagnostic surface from becoming an HTML injection vector.
	 */
	private static function parse_debug(string $template, array $data): string {
		preg_match_all('/{{debug(\w+)}}/', $template, $matches);
		foreach($matches[1] as $var_name){
			$output=isset($data[$var_name]) ? print_r($data[$var_name], true) : 'undefined';
			$template=str_replace("{{debug $var_name}}", '<pre>'.htmlspecialchars($output).'</pre>', $template);
		}
		return $template;
	}
	
	/**
	 * Applies the legacy `extend` inheritance form.
	 *
	 * this alternate syntax resolves the base template, records layout
	 * usage, loads the base via the template loader, and replaces declared block
	 * placeholders while leaving missing layouts visible through tracelog and
	 * manifest missing-reference records.
	 */
	private static function parse_inheritance(string $template): string {
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
			$base_template=self::load_template_file($base_template_path);
			preg_match_all('/{{ block "(.+?)" }}(.*?){{ endblock }}/s', $template, $child_blocks, PREG_SET_ORDER);
			foreach($child_blocks as $child_block){
				$base_template=str_replace("{{ block_content \"{$child_block[1]}\" }}", $child_block[2], $base_template);
			}
			return $base_template;
		}
		return $template;
	}
	
	/**
	 * Renders include directives with optional scoped data.
	 *
	 * partial references are resolved before rendering, scoped data may be
	 * selected through a dotted path, non-array scoped data falls back to the full
	 * data set, and missing partials are recorded and removed from output.
	 */
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

    /**
     * Replaces scalar and nested placeholders with escaped data values.
     *
     * scalar replacements are HTML-escaped at the final placeholder pass,
     * while arrays and objects are delegated to nested placeholder handling so
     * dotted or grouped data structures can be rendered consistently.
     */
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

	/**
	 * Renders loop blocks with simple break and continue controls.
	 *
	 * loop blocks render only array-backed data, each item is passed
	 * through placeholder replacement, and inline break/continue markers truncate or
	 * skip loop content without executing arbitrary template code.
	 */
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
