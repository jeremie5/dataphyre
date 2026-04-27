<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T="Loaded");

trait debugging {
	
    private static function debug(string $template, array $data): string {
        try {
            $template=self::full_render($template, $data);
        } catch(\Throwable $e){
            tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Error rendering template: ".$e->getMessage());
            return self::render_error_template($e);
        }
        return $template;
    }
	
    private static function profile_render(string $template_file, float $start_time): void {
        $render_time=microtime(true) - $start_time;
        tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Rendered $template_file in $render_time seconds.");
    }
	
	private static function render_error_template(object $error): string {
		$error_template="<div class='error'>Template Error: {$error->getMessage()}</div>";
		return $error_template;
	}
	
	private static function debug_render(string $template_file, array $data): string {
		self::$debug_logs=[];
		$output=self::full_render($template_file, $data);
		if(!is_dir(self::$cache_dir)) @mkdir(self::$cache_dir, 0777, true);
		file_put_contents(self::$cache_dir.'/debug_logs.log', implode("\n", self::$debug_logs));
		return $output;
	}
	
	private static function render_performance_metrics(): void {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Template performance metrics requested.');
	}
	
}
