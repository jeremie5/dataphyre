<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

trait rendering {

	public static function render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
		self::ensure_initialized();
		$ext=strtolower((string)pathinfo($template_file, PATHINFO_EXTENSION));
		if($ext==='md'){
			return self::parseMarkdown(self::load_template_file($template_file));
		}
		if(self::$strict_mode===true && self::$inspection_enabled!==true){
			$result=self::with_manifest_capture(
				static fn(): string => (string)self::full_render($template_file, $data, $theme_values, $slots),
				[
					'template_name'=>$template_file,
					'inline'=>false,
					'data_keys'=>array_keys($data),
					'theme_value_keys'=>array_keys($theme_values),
					'slot_names'=>array_keys($slots),
					'cache_strategy'=>'strict_runtime_bypass',
				]
			);
			return $result['content'] ?? '';
		}
		$can_use_static_cache=($data===[] && $theme_values===[] && $slots===[] && self::$global_context===[]);
		if($can_use_static_cache){
			$cached_template=self::load_from_cache($template_file);
			if($cached_template!==null){
				return $cached_template;
			}
		}
		$template=self::full_render($template_file, $data, $theme_values, $slots);
		if($can_use_static_cache){
			self::save_to_cache((string)$template, $template_file);
		}
		return $template;
	}

	public static function render_with_fallback(string $template_file, array $data=[], string $fallback_file='fallback.tpl'): mixed {
		if(is_file($template_file) && is_readable($template_file)){
			return self::full_render($template_file, $data);
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Error, loading fallback: $fallback_file");
		return self::full_render($fallback_file, $data);
	}

	public static function full_render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
		self::ensure_initialized();
		return self::compile_template(
			self::load_template_file($template_file),
			$template_file,
			$data,
			$theme_values,
			$slots
		);
	}

	public static function render_string(
		string $template,
		array $data=[],
		array $theme_values=[],
		array $slots=[],
		string $template_name='inline.tpl'
	): string {
		self::ensure_initialized();
		if(self::$strict_mode===true && self::$inspection_enabled!==true){
			$result=self::with_manifest_capture(
				static fn(): string => self::compile_template($template, $template_name, $data, $theme_values, $slots),
				[
					'template_name'=>$template_name,
					'inline'=>true,
					'data_keys'=>array_keys($data),
					'theme_value_keys'=>array_keys($theme_values),
					'slot_names'=>array_keys($slots),
					'cache_strategy'=>'strict_runtime_bypass',
				]
			);
			return (string)($result['content'] ?? '');
		}
		return self::compile_template($template, $template_name, $data, $theme_values, $slots);
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

	private static function compile_template(
		string $template,
		string $template_name,
		array $data=[],
		array $theme_values=[],
		array $slots=[]
	): string {
		$template_data=array_replace(self::$global_context, $data);
		$previous_render_data=self::$current_render_data;
		self::$current_render_data=$template_data;
		self::$inspection_depth++;
		self::record_template_render($template_name, !is_file($template_name));
		self::push_template_context($template_name);
		$render_started_at=microtime(true);

		try {
			self::trigger_event('before_render', $template_name, $template_data);
			$template_data=self::validate_template_contract($template_name, $template_data, $slots);
			$template=self::apply_preprocessing_hooks($template, $template_data);
			$template=self::apply_theme_values($template, $theme_values);
			$template=self::resolve_dependencies($template);
			$template=self::parseInheritance($template);
			$template=self::parse_layout_inheritance($template);
			$template=self::parse_partials($template, $template_data);
			$template=self::compose_components($template, $template_data);
			$template=self::parse_slots($template, $template_data, $slots);
			$template=self::bind_data($template, $template_data);
			$template=self::apply_tags($template, $template_data);
			$template=self::apply_filters($template, $template_data);
			$template=self::parse_fragment_cache($template);
			$template=self::parse_php_blocks($template);
			$template=self::parse_assets($template);
			$template=self::apply_helpers($template);
			$template=self::applyExtensions($template);
			$template=self::parse_loops($template, $template_data);
			$template=self::parse_loop_controls($template, $template_data);
			$template=self::parse_advanced_conditionals($template, $template_data);
			$template=self::parse_inline_conditionals($template, $template_data);
			$template=self::parse_conditionals($template, $template_data);
			$template=self::parse_lazy_load_components($template, $template_data);
			$template=self::parse_dynamic_imports($template, $template_data);
			$template=self::parse_attributes($template, $template_data);
			$template=self::parseTranslations($template);
			$template=self::apply_pipelines($template, array_replace(self::$helpers, self::$filters));
			$template=self::parse_scoped_styles($template, pathinfo($template_name, PATHINFO_FILENAME) ?: 'template');
			$template=self::parse_seo_tags($template, $template_data);
			$template=self::parse_form($template, $template_data);
			$template=self::handle_undefined_variables($template, $template_data);
			$template=self::parse_debug($template, $template_data);
			$template=self::trim_whitespace($template);
			$template=self::apply_postprocessing_hooks($template, $template_data);
			if(self::$is_dev_mode){
				$docs_dir=self::$cache_dir.'docs/';
				if(!is_dir($docs_dir)){
					@mkdir($docs_dir, 0777, true);
				}
				file_put_contents($docs_dir.md5($template_name).'.md', self::generate_template_docs($template));
			}
			self::profile_render($template_name, $render_started_at);
			self::enforce_strict_mode($template_name);
			self::trigger_event('after_render', $template_name, $template_data, $template);
			return $template;
		} catch(\Throwable $e){
			self::record_manifest_structured('errors', [
				'template'=>$template_name,
				'message'=>$e->getMessage(),
				'type'=>$e::class,
			]);
			if(self::$inspection_enabled===true){
				self::$active_manifest['failed']=true;
				self::$active_manifest['failure_message'] ??= $e->getMessage();
			}
			self::trigger_event('on_error', $e, $template_name, $template_data);
			return self::render_error_template($e);
		} finally {
			self::$current_render_data=$previous_render_data;
			self::pop_template_context();
			self::$inspection_depth=max(0, self::$inspection_depth-1);
		}
	}

	private static function apply_theme_values(string $template, array $theme_values=[]): string {
		if($theme_values===[]){
			return $template;
		}
		foreach($theme_values as $key=>$value){
			if(is_array($value)){
				$template=str_replace("{{".$key."}}", self::adapt($value), $template);
				continue;
			}
			$template=str_replace("{{".$key."}}", htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $template);
		}
		return $template;
	}
}
