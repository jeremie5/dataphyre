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
 * Defines Templating kernel trait responsibilities for seo accessibility.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait seo_accessibility {

    /**
     * Expands legacy SEO and accessible-image template tags into HTML.
     *
     * SEO tags read content from render data by tag name. Accessible image tags
     * emit src and alt attributes from the template token itself. This legacy path
     * performs direct string replacement and preserves the historical trust model:
     * callers are responsible for supplying safe template literals and meta values.
     *
     * @param string $template Template source containing SEO or accessible image tags.
     * @param array<string,mixed> $data Render data used for meta tag content.
     * @return string Template source with SEO/accessibility tags expanded.
     */
    private static function parse_seo_tags(string $template, array $data): string {
        preg_match_all('/{{seo "(\w+)"}}/', $template, $matches);
        foreach($matches[1] as $meta_tag){
            $content=$data[$meta_tag] ?? '';
            $template=str_replace("{{seo \"$meta_tag\"}}", "<meta name='$meta_tag' content='$content'>", $template);
        }
        preg_match_all('/{{accessibleImage "(.+?)" alt="(.+?)"}}/', $template, $matches, PREG_SET_ORDER);
        foreach($matches as $match){
            $image_path=$match[1];
            $alt_text=$match[2];
            $template=str_replace($match[0], "<img src='$image_path' alt='$alt_text'>", $template);
        }
        return $template;
    }
	
}
