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
 * Defines Templating kernel trait responsibilities for debugging.
 *
 * Templating kernel boundary: module configuration, runtime state, and Dataphyre service calls.
 */
trait debugging {
	
	/**
	 * Renders a template through the full compiler and converts failures to error markup.
	 *
	 * this helper is a diagnostic wrapper around full_render(). Exceptions
	 * are traced and converted through render_error_template() so debug callers get a
	 * visible template error instead of an uncaught runtime failure.
	 *
	 * @param string $template Template path or render target accepted by full_render().
	 * @param array<string, mixed> $data Render data for the template.
	 * @return string Rendered output or diagnostic error markup.
	 */
    private static function debug(string $template, array $data): string {
        try {
            $template=self::full_render($template, $data);
        } catch(\Throwable $e){
            tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Error rendering template: ".$e->getMessage());
            return self::render_error_template($e);
        }
        return $template;
    }
	
	/**
	 * Records elapsed render time for diagnostics.
	 *
	 * profiling is trace-only and does not mutate the rendered template or
	 * cache state. The caller supplies the start timestamp so the surrounding render
	 * pipeline controls lifecycle boundaries.
	 *
	 * @param string $template_file Template path or diagnostic name being rendered.
	 * @param float $start_time microtime(true) value captured before rendering.
	 * @return void
	 */
    private static function profile_render(string $template_file, float $start_time): void {
        $render_time=microtime(true) - $start_time;
        tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Rendered $template_file in $render_time seconds.");
    }
	
	/**
	 * Builds the minimal HTML error surface for failed template renders.
	 *
	 * the error object is expected to expose getMessage(), normally via
	 * Throwable. The returned fragment is intentionally small and local to the
	 * templating module so render failures can be surfaced even when normal
	 * component, asset, or layout rendering is unavailable.
	 *
	 * @param object $error Throwable-like object with a getMessage() method.
	 * @return string HTML fragment describing the template error.
	 */
	private static function render_error_template(object $error): string {
		$error_template="<div class='error'>Template Error: {$error->getMessage()}</div>";
		return $error_template;
	}
	
	/**
	 * Renders a template while capturing debug logs to the template cache directory.
	 *
	 * debug logs are reset per invocation, full_render() owns template
	 * compilation, and the collected log lines are persisted to `debug_logs.log`
	 * under the configured cache directory. Directory creation is best-effort.
	 *
	 * @param string $template_file Template path or name to render.
	 * @param array<string, mixed> $data Render data for the template.
	 * @return string Rendered template output.
	 */
	private static function debug_render(string $template_file, array $data): string {
		self::$debug_logs=[];
		$output=self::full_render($template_file, $data);
		if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
		file_put_contents(self::$cache_dir.'/debug_logs.log', implode("\n", self::$debug_logs));
		return $output;
	}
	
	/**
	 * Emits a trace marker for template performance diagnostics.
	 *
	 * this is currently an observability hook rather than a renderer. It
	 * records the request for metrics through tracelog and leaves aggregation or UI
	 * presentation to surrounding diagnostic tooling.
	 *
	 * @return void
	 */
	private static function render_performance_metrics(): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Template performance metrics requested.');
	}
	
}
