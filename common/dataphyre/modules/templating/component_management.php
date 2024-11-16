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

trait component_management {

    private static function parse_components(string $template, array $data): string {
        preg_match_all('/{{component "(\w+)"(?: props=(\w+))?}}/', $template, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $component_name = $match[1];
            $component_data = $data[$match[2]] ?? [];
            $component_content = self::full_render("components/{$component_name}.tpl", $component_data);
            $scoped_component_content = self::parse_scoped_styles($component_content, $component_name);
            $template = str_replace($match[0], $scoped_component_content, $template);
        }
        return $template;
    }

    private static function lazy_load_components(string $template, array $data): string {
        preg_match_all('/{{lazyComponent "(\w+)"}}/', $template, $matches);
        foreach ($matches[1] as $component) {
            $placeholder = "<div data-lazy='$component' class='lazy-placeholder'></div>";
            $template = str_replace("{{lazyComponent \"$component\"}}", $placeholder, $template);
        }
        return $template;
    }

}