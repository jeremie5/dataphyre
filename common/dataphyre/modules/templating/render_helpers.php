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

trait render_helpers {
	
	private static function parse_assets(string $template): string {
		preg_match_all('/{{asset "(.+?)"}}/', $template, $matches);
		foreach($matches[1] as $asset){
			$path="assets/".$asset;
			$versioned_path=$path.'?v='.filemtime($path);
			$template=str_replace("{{asset \"$asset\"}}", $versioned_path, $template);
		}
		return $template;
	}
	
	public static function apply_helpers(string $template): string {
		foreach(self::$helpers as $func=>$callback){
			preg_match_all("/{{".$func."\((.*?)\)}}/", $template, $matches, PREG_SET_ORDER);
			foreach($matches as $match){
				$args=array_map('trim', explode(',', $match[1]));
				$result=call_user_func_array($callback, $args);
				$template=str_replace($match[0], $result, $template);
			}
		}
		return $template;
	}
	
	private static function apply_pipelines(string $template, array $filters): string {
		preg_match_all('/{{(\w+)\s*\|\s*(\w+)(\s*\|\s*\w+)*}}/', $template, $matches, PREG_SET_ORDER);
		foreach($matches as $match){
			$value=$data[$match[1]] ?? '';
			$transformations=explode('|', $match[2]);
			foreach($transformations as $filter){
				if(isset($filters[$filter])){
					$value=call_user_func($filters[$filter], $value);
				}
			}
			$template=str_replace($match[0], htmlspecialchars($value), $template);
		}
		return $template;
	}
	
	public static function register_extension(string $name, callable $extension): void {
		self::$extensions[$name]=$extension;
	}
	
	public static function applyExtensions($template){
		foreach(self::$extensions as $name=>$extension){
			preg_match_all("/{{".$name."\((.*?)\)}}/", $template, $matches, PREG_SET_ORDER);
			foreach($matches as $match){
				$args=explode(',', $match[1]);
				$result=call_user_func_array($extension, $args);
				$template=str_replace($match[0], $result, $template);
			}
		}
		return $template;
	}
	
	private static function parseMarkdown(string $template): string {
		$parts=preg_split('/(```.*?```)/s', $markdownContent, -1, PREG_SPLIT_DELIM_CAPTURE);
		foreach($parts as &$part){
			if(preg_match('/^```(.*?)\n(.*?)```$/s', $part, $matches)){
				$code_language=$matches[1];
				$code=$matches[2];
				if(dp_module_present("datadoc")){
					$code=\dataphyre\datadoc\highlighter::retabulate_php($code);
					$code=\dataphyre\datadoc\highlighter::highlight_code($code, $code_language, [
						"show_lines"=>true, 
						"start_line"=>2
					]);
					$part=\dataphyre\datadoc\highlighter::linkify_php($code);
				}
				else
				{
					$part=$code;
				}
			}
			else
			{
				$part=preg_replace_callback('/`([^`]+)`/', function($matches)use($theme_classes){
					$code=$matches[1];
					return "<code class='".adapt($theme_classes)."'>$code</code>";
				}, $part);
				$part=nl2br($part);
				$part=preg_replace('/\#\#\#\#(.*?)\n/', '<h5>$1</h5>', $part);
				$part=preg_replace('/\#\#\#(.*?)\n/', '<h4>$1</h4>', $part);
				$part=preg_replace('/\#\#(.*?)\n/', '<h3>$1</h3>', $part);
				$part=preg_replace('/\#(.*?)\n/', '<h1>$1</h1>', $part);
				$part=preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $part);
				$part=preg_replace('/\[(.*?)\]\((.*?)\)/', '<a href="$2"><u>$1</u></a>', $part);
				$part=str_replace("#", '', $part);
				$part=str_replace("---", '<hr>', $part);
			}
		}
		return '<div>'.implode('', $parts).'</div>';
	}
	
    private static function applyGlobalContext(string $template){
        foreach(self::$global_context as $key=>$value){
            $template=str_replace("{{global.$key}}", htmlspecialchars($value), $template);
        }
        return $template;
    }
	
    public static function addToGlobalContext(string $key, mixed$value): void {
        self::$global_context[$key]=$value;
    }
	
}