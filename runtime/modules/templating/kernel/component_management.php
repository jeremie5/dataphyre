<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait component_management {

    private static function parse_components(string $template, array $data): string {
        preg_match_all('/{{\s*component\s+[\"\']([^\"\']+)[\"\'](?:\s+props=([\w\.]+))?\s*}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $component_reference=$match[1];
            $component_path=self::resolve_component_reference($component_reference);
            if($component_path===null){
                self::record_missing_reference('component', $component_reference);
                tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Component not found: $component_reference");
                $template=str_replace($match[0], '', $template);
                continue;
            }
            $props_path=$match[2] ?? '';
            $component_data=$props_path!=='' ? self::get_value_by_path($data, $props_path) : [];
            if(is_object($component_data)){
                $component_data=method_exists($component_data, 'toArray')
                    ? $component_data->toArray()
                    : get_object_vars($component_data);
            }
            if(!is_array($component_data)){
                $component_data=[];
            }
            $component_name=pathinfo($component_path, PATHINFO_FILENAME);
            self::record_manifest_structured('components', [
                'reference'=>$component_reference,
                'template'=>$component_path,
                'props_path'=>$props_path!=='' ? $props_path : null,
                'contract'=>self::component_contract_summary($component_path),
            ]);
            $component_content=self::full_render($component_path, $component_data);
            $scoped_component_content=self::parse_scoped_styles($component_content, $component_name);
            $template=str_replace($match[0], $scoped_component_content, $template);
        }
        return $template;
    }

    private static function lazy_load_components(string $template, array $data): string {
        preg_match_all('/{{\s*lazyComponent\s+"([^"]+)"\s*}}/', $template, $matches);
        foreach($matches[1] as $component){
            $placeholder="<div data-lazy='".htmlspecialchars($component, ENT_QUOTES, 'UTF-8')."' class='lazy-placeholder'></div>";
            $template=str_replace("{{lazyComponent \"$component\"}}", $placeholder, $template);
        }
        return $template;
    }

}
