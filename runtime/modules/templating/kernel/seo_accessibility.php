<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait seo_accessibility {

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