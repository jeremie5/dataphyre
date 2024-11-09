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
	
    private static function profile_render(string $template_file, array $data): string {
        $start_time=microtime(true);
        $output=self::full_render($template_file, $data);
        $render_time=microtime(true) - $start_time;
        tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, "Rendered $template_file in $render_time seconds.");
        return $output;
    }
	
	private static function render_error_template(object $error): string {
		$error_template="<div class='error'>Template Error: {$error->getMessage()}</div>";
		return $error_template;
	}
	
	private static function debug_render(string $template_file, array $data): string {
		self::$debug_logs=[];
		$output=self::full_render($template_file, $data);
		file_put_contents(self::$cache_dir.'/debug_logs.log', implode("\n", self::$debug_logs));
		return $output;
	}
	
	private static function render_performance_metrics(): void {
		$metrics=self::collectRenderMetrics();
		include 'performance_dashboard.tpl';
	}
	
}