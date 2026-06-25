<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Orchestrates template rendering, caching, strict inspection, and the compile pipeline.
 *
 * The rendering trait is the public entrypoint for file and string templates. It initializes the
 * templating runtime, chooses markdown/static-cache/strict-capture paths, invokes the full
 * compilation pipeline, and records errors/manifests/profiling data for diagnostics.
 */
trait rendering {

	/**
	 * Renders a template file through the runtime's cache-aware entrypoint.
	 *
	 * Markdown files bypass the normal template compiler and use markdown parsing. Empty data,
	 * theme, slots, and global context enable static cache reuse; strict mode bypasses runtime
	 * cache and captures a manifest for validation.
	 *
	 * @param string $template_file Template path or name.
	 * @param array<string, mixed> $data Render data.
	 * @param array<string, mixed> $theme_values Theme token replacements.
	 * @param array<string, mixed> $slots Slot content keyed by slot name.
	 * @return mixed rendered markdown, strict-mode captured content, static-cache content, or compiled template output.
	 */
	public static function render(string $template_file, array $data=[], array $theme_values=[], array $slots=[]): mixed {
		self::ensure_initialized();
		$ext=strtolower((string)pathinfo($template_file, PATHINFO_EXTENSION));
		if($ext==='md'){
			return self::parse_markdown(self::load_template_file($template_file));
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

	/**
	 * Renders a template file or falls back to another file when the primary is unreadable.
	 *
	 * @param string $template_file Preferred template file.
	 * @param array<string, mixed> $data Render data.
	 * @param string $fallback_file Fallback template file.
	 * @return mixed compiled primary template when readable, otherwise compiled fallback template content.
	 */
	public static function render_with_fallback(string $template_file, array $data=[], string $fallback_file='fallback.tpl'): mixed {
		if(is_file($template_file) && is_readable($template_file)){
			return self::full_render($template_file, $data);
		}
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Error, loading fallback: $fallback_file");
		return self::full_render($fallback_file, $data);
	}

	/**
	 * Loads a template file and runs the full compiler without static-cache lookup.
	 *
	 * @param string $template_file Template path or name.
	 * @param array<string, mixed> $data Render data.
	 * @param array<string, mixed> $theme_values Theme token replacements.
	 * @param array<string, mixed> $slots Slot content keyed by slot name.
	 * @return mixed compiled template output after loading the file and applying data, theme values, and slots.
	 */
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

	/**
	 * Renders an inline template string.
	 *
	 * Strict mode captures inline renders with a synthetic template name so contract inspection
	 * and diagnostics can distinguish inline templates from file templates.
	 *
	 * @param string $template Template source.
	 * @param array<string, mixed> $data Render data.
	 * @param array<string, mixed> $theme_values Theme token replacements.
	 * @param array<string, mixed> $slots Slot content keyed by slot name.
	 * @param string $template_name Diagnostic name for the inline template.
	 * @return string Rendered template content.
	 */
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

	/**
	 * Renders a template inside an async promise wrapper.
	 *
	 * The promise resolves JSON containing rendered content or rejects JSON containing an error
	 * message, matching the async module's string payload expectations.
	 *
	 * @param string $template_file Template path or name.
	 * @param array<string, mixed> $data Render data.
	 * @return object Async promise object.
	 */
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

	/**
	 * Runs the full ordered template compilation pipeline.
	 *
	 * The compiler sets current render data, records template context, fires lifecycle events,
	 * validates contracts, resolves dependencies/inheritance/partials/components/slots/data,
	 * applies filters/helpers/extensions/control flow/assets/translations/forms/debug handling,
	 * profiles the render, and restores the previous render context in a finally block.
	 *
	 * @param string $template Template source.
	 * @param string $template_name Template path or diagnostic name.
	 * @param array<string, mixed> $data Render data.
	 * @param array<string, mixed> $theme_values Theme token replacements.
	 * @param array<string, mixed> $slots Slot content keyed by slot name.
	 * @return string Rendered template content or rendered error template after failures.
	 */
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
			$template=self::parse_inheritance($template);
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
			$template=self::apply_extensions($template);
			$template=self::parse_loops($template, $template_data);
			$template=self::parse_loop_controls($template, $template_data);
			$template=self::parse_advanced_conditionals($template, $template_data);
			$template=self::parse_inline_conditionals($template, $template_data);
			$template=self::parse_conditionals($template, $template_data);
			$template=self::parse_lazy_load_components($template, $template_data);
			$template=self::parse_dynamic_imports($template, $template_data);
			$template=self::parse_attributes($template, $template_data);
			$template=self::parse_translations($template);
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

	/**
	 * Applies theme token replacements before template dependencies and bindings are resolved.
	 *
	 * Array values are adapted through the theme adapter, while scalar values are HTML-escaped
	 * before replacement.
	 *
	 * @param string $template Template source.
	 * @param array<string, mixed> $theme_values Theme token replacements.
	 * @return string Template source with theme tokens replaced.
	 */
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
