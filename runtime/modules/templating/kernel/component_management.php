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
 * Defines Templating kernel trait responsibilities for component management.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait component_management {

    /**
     * Renders component tags and records their planning metadata.
     *
     * Component references are resolved through the templating resolver, optional
     * props paths are read from render data, object props are converted to arrays,
     * missing components are recorded as unresolved references, and successful
     * renders are passed through scoped-style parsing before replacement.
     *
     * @param string $template Template source containing component tags.
     * @param array<string,mixed> $data Render data used for component props.
     * @return string Template source with component tags replaced by rendered markup.
     */
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

    /**
     * Replaces lazy component tags with inert client-side placeholders.
     *
     * The component reference is HTML-escaped into data-lazy so later JavaScript can
     * hydrate it without executing component rendering during the current template
     * pass. No props are resolved at this stage.
     *
     * @param string $template Template source containing lazyComponent tags.
     * @param array<string,mixed> $data Render data reserved for signature compatibility.
     * @return string Template source with lazy component placeholders.
     */
    private static function lazy_load_components(string $template, array $data): string {
        preg_match_all('/{{\s*lazyComponent\s+"([^"]+)"\s*}}/', $template, $matches);
        foreach($matches[1] as $component){
            $placeholder="<div data-lazy='".htmlspecialchars($component, ENT_QUOTES, 'UTF-8')."' class='lazy-placeholder'></div>";
            $template=str_replace("{{lazyComponent \"$component\"}}", $placeholder, $template);
        }
        return $template;
    }

}
