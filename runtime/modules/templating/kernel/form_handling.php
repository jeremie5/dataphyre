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
 * Defines Templating kernel trait responsibilities for form handling.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait form_handling {
	
    /**
     * Expands legacy form blocks into HTML form elements.
     *
     * Each {{form "name"}}...{{endForm}} block is replaced with a named form
     * containing parsed field tags. Field values are scoped to the matching data
     * key, and missing form data falls back to an empty array so rendering remains
     * best-effort for partial payloads.
     *
     * @param string $template Template source containing optional form blocks.
     * @param array<string,array<string,mixed>> $data Render data keyed by form name.
     * @return string Template source with legacy form blocks rendered as HTML.
     */
    private static function parse_form(string $template, array $data): string {
        preg_match_all('/{{form "(\w+)"}}(.*?){{endForm}}/s', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $form_name=$match[1];
            $form_content=self::parse_form_fields($match[2], $data[$form_name] ?? []);
            $template=str_replace($match[0], "<form name='$form_name'>$form_content</form>", $template);
        }
        return $template;
    }

    /**
     * Expands legacy field tags inside a form block.
     *
     * Field names and types are read from the tag, values are HTML-escaped before
     * interpolation, and extra attributes are preserved verbatim for compatibility
     * with older templates that already supplied trusted attribute fragments.
     *
     * @param string $template Form block content containing field tags.
     * @param array<string,mixed> $data Field values keyed by field name.
     * @return string Form content with field tags replaced by input elements.
     */
    private static function parse_form_fields(string $template, array $data): string {
        preg_match_all('/{{field "(\w+)" type="(\w+)"(.*?)}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $field_name=$match[1];
            $type=$match[2];
            $extra_attrs=$match[3];
            $value=htmlspecialchars((string)($data[$field_name] ?? ''), ENT_QUOTES, 'UTF-8');
            $field_html="<input name='$field_name' type='$type' value='$value' $extra_attrs>";
            $template=str_replace($match[0], $field_html, $template);
        }
        return $template;
    }
	
}
