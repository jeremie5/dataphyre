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

trait form_handling {
	
    private static function parse_form(string $template, array $data): string {
        preg_match_all('/{{form "(\w+)"}}(.*?){{endForm}}/s', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $form_name=$match[1];
            $form_content=self::parse_form_fields($match[2], $data[$form_name] ?? []);
            $template=str_replace($match[0], "<form name='$form_name'>$form_content</form>", $template);
        }
        return $template;
    }

    private static function parse_form_fields(array $template, array $data): string {
        preg_match_all('/{{field "(\w+)" type="(\w+)"(.*?)}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $field_name=$match[1];
            $type=$match[2];
            $extra_attrs=$match[3];
            $value=htmlspecialchars($data[$field_name] ?? '');
            $field_html="<input name='$field_name' type='$type' value='$value' $extra_attrs>";
            $template=str_replace($match[0], $field_html, $template);
        }
        return $template;
    }
	
}