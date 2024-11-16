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

trait rendering {

    public static function render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
        $ext=pathinfo($template_file, PATHINFO_EXTENSION);
        if($ext==='md'){
            return self::parseMarkdown(file_get_contents($template_file));
        }
        $cached_template=self::load_from_cache($template_file);
        if($cached_template){
            return include $cached_template;
        }
        $template=file_get_contents($template_file);
        $template=self::replace_placeholders($template, $data);
        if(!empty($theme_values)){
            foreach($theme_values as $key => $value){
                $template=str_replace("{{" . $key . "}}", self::adapt($value), $template);
            }
        }
        $template=self::parse_slots($template, $data, $slots);
        $cache_file=self::save_to_cache($template, $template_file);
        return include $cache_file;
    }

	public static function render_with_fallback(string $template_file, array $data=[], string $fallback_file='fallback.tpl'): mixed {
		try {
			return self::full_render($template_file, $data);
		} catch(\Throwable $e){
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Error, loading fallback: $fallback_file");
			return self::full_render($fallback_file, $data);
		}
	}

    public static function full_render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
        try {
            self::trigger_event('before_render', $template_file, $data);
            $template=self::load_template_file($template_file);
            $template=self::resolve_dependencies($template);
            $template=self::parseInheritance($template);
            $template=self::parse_partials($template, $data);
            $template=self::compose_components($template, $data);
            $template=self::bind_data($template, $data);
			$template=self::apply_tags($template, $data);
			$template=self::apply_filters($template, $data);
            $template=self::parse_slots($template, $data, $slots);
            $template=self::parse_fragment_cache($template);
            $template=self::parse_php_blocks($template);
            $template=self::parse_assets($template);
            $template=self::apply_helpers($template);
            $template=self::applyExtensions($template);
            $template=self::parse_loops($template, $data);
            $template=self::parse_loop_controls($template, $data);
            $template=self::parse_advanced_conditionals($template, $data);
            $template=self::parse_inline_conditionals($template, $data);
            $template=self::parse_conditionals($template, $data);
            $template=self::parse_lazy_load_components($template, $data);
            $template=self::parse_dynamic_imports($template, $data);
            $template=self::parse_attributes($template, $data);
            $template=self::parseTranslations($template);
            $template=self::apply_pipelines($template, self::$helpers);
            $template=self::parse_scoped_styles($template, pathinfo($template_file, PATHINFO_FILENAME));
            $template=self::parse_seo_tags($template, $data);
            $template=self::parse_form($template, $data);
            $template=self::profile_render($template_file, $data);
            $template=self::handle_undefined_variables($template, $data);
            $template=self::parse_debug($template, $data);
            $template=self::trim_whitespace($template);
            $template=self::apply_postprocessing_hooks($template, $data);
            if(self::$is_dev_mode){
                $docs=self::generate_template_docs($template);
                file_put_contents(self::$cache_dir . 'docs/' . md5($template_file) . '.md', $docs);
            }
            self::trigger_event('after_render', $template_file, $data);
            return $template;
        } catch(Exception $e){
            self::trigger_event('on_error', $e, $template_file);
            return "An error occurred during rendering.";
        }
    }
    
    public static function async_render(string $template_file, array $data=[]): object {
        return new \dataphyre\async\promise(function($resolve, $reject) use($template_file, $data){
            try {
                $content=self::full_render($template_file, $data);
                $resolve(json_encode(['content'=>$content]));
            } catch(\Throwable $e){
                $reject(json_encode(['error'=>$e->getMessage()]));
            }
        });
    }
	
}