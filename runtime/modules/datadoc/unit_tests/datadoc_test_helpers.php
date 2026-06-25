<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(!function_exists('tracelog')){
	/**
	 * Unit-test helper for Datadoc tracelog coverage.
	 *
	 * @internal Datadoc unit-test surface.
	 */
	function tracelog(...$args): void {}
}

require_once dirname(__DIR__).'/kernel/tokenizer.php';
require_once dirname(__DIR__).'/kernel/highlighter.php';

/**
 * Loads the Datadoc facade with lightweight unit-test stubs.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_load_facade(): void {
	if(class_exists('\dataphyre\datadoc', false)){
		return;
	}
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'common_dataphyre_runtime'=>dirname(__DIR__, 2).'/',
			'common_dataphyre'=>dirname(__DIR__, 4).'/',
			'dataphyre'=>sys_get_temp_dir().'/',
		]);
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant): void {
			if(!defined($constant)){
				define($constant, []);
			}
		}
	}
	if(!function_exists('sql_define_table')){
		function sql_define_table(string $name, string $file, string $key): void {}
	}
	if(!class_exists('\dataphyre\core', false)){
		if(!defined('DP_DATADOC_UNIT_CORE_STUB_LOADED')){
			define('DP_DATADOC_UNIT_CORE_STUB_LOADED', true);
			class dp_datadoc_unit_core_stub {
				public static function url_self(): string {
					return 'https://example.test';
				}

				/**
				 * @param array<string,mixed> $updates Query string updates.
				 */
				public static function url_self_updated_querystring(array $updates): string {
					return 'https://example.test?'.http_build_query($updates);
				}
			}
		}
		class_alias('dp_datadoc_unit_core_stub', '\dataphyre\core');
	}
	require_once dirname(__DIR__).'/kernel/datadoc.main.php';
}

/**
 * Unit-test helper for Datadoc tokenize sample coverage.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_tokenize_sample(): array {
	$source=<<<'PHP'
<?php
namespace Example\Docs;

/**
 * Greets a person.
 * @param string $name
 */
final class Greeter {
	private $message;

	public static function hello(string $name): string {
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='hello');
		return "Hello ".$name;
	}
}
PHP;
	$file=tempnam(sys_get_temp_dir(), 'dp_datadoc_unit_');
	if(!is_string($file)){
		return [];
	}
	file_put_contents($file, $source);
	try{
		$tokens=\dataphyre\datadoc\tokenizer::tokenize($file);
	}
	finally{
		@unlink($file);
	}
	if(!is_array($tokens)){
		return [];
	}
	return array_map(static function(array $token): array {
		return [
			'type'=>(string)($token['type'] ?? ''),
			'namespace'=>(string)($token['namespace'] ?? ''),
			'class'=>(string)($token['class'] ?? ''),
			'function'=>(string)($token['function'] ?? ''),
			'line'=>(int)($token['line'] ?? 0),
			'phpdoc_description'=>(string)($token['phpdoc']['description'] ?? ''),
			'phpdoc_param'=>(string)($token['phpdoc']['tags']['param'] ?? ''),
		];
	}, $tokens);
}

/**
 * Unit-test helper for Datadoc retabulate php coverage.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_retabulate_php(): string {
	return \dataphyre\datadoc\highlighter::retabulate_php("if(true){\necho 'yes';\n}\n");
}

/**
 * Unit-test helper for Datadoc linkify php builtin coverage.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_linkify_php_builtin(): string {
	return \dataphyre\datadoc\highlighter::linkify_php(
		'<span class="php_token_builtin_function">trim</span>',
		''
	);
}

/**
 * Unit-test helper for Datadoc highlighter line-break annotation support.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_highlighter_break_split_summary_json(): string {
	require_once dirname(__DIR__).'/ui/assets_support.php';
	$js=dataphyre_datadoc_highlighter_js();
	$html=\dataphyre\datadoc\highlighter::highlight_code(
		"echo 'a';\necho 'b';",
		'php',
		['show_lines'=>true, 'line_number_start'=>10]
	);
	return json_encode([
		'js_splits_br_variants'=>str_contains($js, 'split(/<br\s*\/?>/i)'),
		'php_emits_xml_breaks'=>str_contains($html, '<br />'),
		'line_start_metadata'=>str_contains($html, 'data-datadoc-line-start="10"'),
		'loads_annotator_asset'=>str_contains($html, 'data-datadoc-highlighter="1"') && str_contains($html, 'Datadoc helper for annotate'),
	], JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for Datadoc tokenize bracketed namespace summary json coverage.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_tokenize_bracketed_namespace_summary_json(): string {
	$source=<<<'PHP'
<?php
namespace First\Area {
	/**
	 * Handles visible code.
	 * @return void
	 * continues here
	 */
	class Visible {
		public function run() {
			tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='run');
		}
	}
}
?>
<script>
class Ignored {
	public function ignored() {}
}
</script>
<?php
class GlobalThing {
	private $flag;
}
PHP;
	$file=tempnam(sys_get_temp_dir(), 'dp_datadoc_unit_');
	if(!is_string($file)){
		return '{}';
	}
	file_put_contents($file, $source);
	try{
		$tokens=\dataphyre\datadoc\tokenizer::tokenize($file);
	}
	finally{
		@unlink($file);
	}
	$summary=[];
	foreach(is_array($tokens) ? $tokens : [] as $token){
		$summary[]=[
			'type'=>(string)($token['type'] ?? ''),
			'namespace'=>(string)($token['namespace'] ?? ''),
			'class'=>(string)($token['class'] ?? ''),
			'function'=>(string)($token['function'] ?? ''),
			'line'=>(int)($token['line'] ?? 0),
			'description'=>(string)($token['phpdoc']['description'] ?? ''),
			'return'=>(string)($token['phpdoc']['tags']['return'] ?? ''),
		];
	}
	return json_encode($summary, JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for Datadoc linkify mixed tokens summary json coverage.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_linkify_mixed_tokens_summary_json(): string {
	$linked=\dataphyre\datadoc\highlighter::linkify_php(
		'<span class="php_token_constant">false</span> <span class="php_token_operator">[]</span> <span class="php_token_variable">$status</span> <span class="php_token_user_function">Acme\\Tools::build</span>',
		'docs',
		'Example\\Docs',
		'Greeter'
	);
	$unlinked=\dataphyre\datadoc\highlighter::linkify_php(
		'<span class="php_token_variable">$status</span> <span class="php_token_user_function">build</span>',
		''
	);
	return json_encode([
		'links_boolean_manual'=>str_contains($linked, 'language.types.boolean.php'),
		'links_array_manual'=>str_contains($linked, 'language.types.array.php'),
		'links_project_variable'=>str_contains($linked, 'type=variable') && str_contains($linked, 'content=status'),
		'links_project_user_function'=>str_contains($linked, '/dataphyre/datadoc/docs/dynadoc?') && str_contains($linked, 'class=Tools') && str_contains($linked, 'function=build'),
		'keeps_user_function_token_class'=>str_contains($linked, '<a class="php_token_user_function"'),
		'leaves_project_tokens_unlinked_without_project'=>$unlinked==='<span class="php_token_variable">$status</span> <span class="php_token_user_function">build</span>',
	], JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for legacy Dynadoc nested menu rendering.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_nested_dynadoc_menu_summary_json(): string {
	dp_datadoc_unit_load_facade();
	$previous_get=$_GET;
	$_GET=[
		'namespace'=>'Acme',
		'class'=>'Tools',
		'type'=>'function',
		'function'=>'build',
	];
	$warnings=[];
	set_error_handler(static function(int $severity, string $message) use (&$warnings): bool {
		$warnings[]=$message;
		return true;
	});
	$level=ob_get_level();
	ob_start();
	try{
		\dataphyre\datadoc::dynadoc_output_nested_structure(
			['name'=>'docs'],
			[
				'Acme'=>[
					'Tools'=>[
						'function'=>[
							'build'=>[
								'id'=>1,
								'type'=>'function',
								'namespace'=>'Acme',
								'class'=>'Tools',
								'function'=>'build',
							],
						],
					],
				],
			]
		);
		$html=(string)ob_get_clean();
	}
	finally{
		restore_error_handler();
		$_GET=$previous_get;
		while(ob_get_level()>$level){
			ob_end_clean();
		}
	}
	return json_encode([
		'has_warnings'=>$warnings!==[],
		'has_expanded_attribute'=>str_contains($html, "aria-expanded='"),
		'has_collapse_class'=>str_contains($html, "class='panel-collapse collapse"),
		'links_record'=>str_contains($html, '/dataphyre/datadoc/docs/dynadoc?') && str_contains($html, 'function=build'),
	], JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for Manudoc filesystem boundary enforcement.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_manudoc_boundary_summary_json(): string {
	dp_datadoc_unit_load_facade();
	$base=rtrim((string)ROOTPATH['dataphyre'], '/\\').'/doc/docs';
	$manual_root=$base.'/manudocs/guides';
	@mkdir($manual_root, 0777, true);
	file_put_contents($manual_root.'/intro.md.json', json_encode(['title'=>'Intro', 'content'=>'Welcome'], JSON_UNESCAPED_SLASHES));
	@mkdir($base, 0777, true);
	file_put_contents($base.'/outside.md.json', json_encode(['title'=>'Outside', 'content'=>'Nope'], JSON_UNESCAPED_SLASHES));
	try{
		$valid=\dataphyre\datadoc::get_manudoc('docs', 'guides/intro');
		$traversal=\dataphyre\datadoc::get_manudoc('docs', 'guides/../../outside');
		$deleted_traversal=\dataphyre\datadoc::delete_manudoc('docs', 'guides/../../outside');
		$project_traversal=\dataphyre\datadoc::get_manudoc('../docs', 'guides/intro');
		$project_branch=\dataphyre\datadoc::get_manudoc_branch('../docs');
		$project_structure=\dataphyre\datadoc::get_manudoc_structure('../docs');
		$outside_still_exists=is_file($base.'/outside.md.json');
		$deleted_valid=\dataphyre\datadoc::delete_manudoc('docs', 'guides/intro');
		$valid_removed=!is_file($manual_root.'/intro.md.json');
	}
	finally{
		@unlink($manual_root.'/intro.md.json');
		@unlink($base.'/outside.md.json');
		@rmdir($manual_root);
		@rmdir(dirname($manual_root));
		@rmdir($base.'/manudocs');
		@rmdir($base);
	}
	return json_encode([
		'loads_valid'=>is_array($valid) && ($valid['title'] ?? '')==='Intro',
		'blocks_traversal'=>$traversal===null,
		'refuses_traversal_delete'=>$deleted_traversal===false,
		'blocks_project_traversal'=>$project_traversal===null && $project_branch===[] && $project_structure===[],
		'keeps_outside_file'=>$outside_still_exists,
		'deletes_valid'=>$deleted_valid===true && $valid_removed,
	], JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for legacy Manudoc sidebar escaping.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_manudoc_sidebar_escaping_summary_json(): string {
	dp_datadoc_unit_load_facade();
	$previous_project=$GLOBALS['project'] ?? null;
	$GLOBALS['project']=['name'=>'docs'];
	$level=ob_get_level();
	ob_start();
	try{
		\dataphyre\datadoc::manudoc_output_nested_structure_from_fs([
			'<script>alert(1)</script>'=>[
				'type'=>'category',
				'children'=>[
					[
						'type'=>'document',
						'content'=>[
							'titles'=>'<img src=x onerror=alert(2)>',
							'id'=>'guide/intro',
							'path'=>'guide/intro',
						],
					],
				],
			],
		]);
		$html=(string)ob_get_clean();
	}
	finally{
		while(ob_get_level()>$level){
			ob_end_clean();
		}
		if($previous_project===null){
			unset($GLOBALS['project']);
		}
		else
		{
			$GLOBALS['project']=$previous_project;
		}
	}
	return json_encode([
		'escapes_category'=>str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'),
		'escapes_title'=>str_contains($html, '&lt;img src=x onerror=alert(2)&gt;'),
		'has_no_script_tag'=>!str_contains($html, '<script>'),
		'has_no_raw_img_tag'=>!str_contains($html, '<img '),
		'keeps_document_link'=>str_contains($html, '/dataphyre/datadoc/docs/manudoc/guide/intro'),
	], JSON_UNESCAPED_SLASHES);
}

/**
 * Unit-test helper for Datadoc repeated PHPDoc tag parsing.
 *
 * @internal Datadoc unit-test surface.
 */
function dp_datadoc_unit_repeated_phpdoc_tags_summary_json(): string {
	$source=<<<'PHP'
<?php
/**
 * Combines two values.
 *
 * @param string $left Left value.
 * @param string $right Right value.
 * @return string Combined value.
 */
function combine_values(string $left, string $right): string {
	return $left.$right;
}
PHP;
	$file=tempnam(sys_get_temp_dir(), 'dp_datadoc_unit_');
	if(!is_string($file)){
		return '{}';
	}
	file_put_contents($file, $source);
	try{
		$tokens=\dataphyre\datadoc\tokenizer::tokenize($file);
	}
	finally{
		@unlink($file);
	}
	foreach(is_array($tokens) ? $tokens : [] as $token){
		if(($token['function'] ?? '')==='combine_values'){
			return json_encode([
				'description'=>$token['phpdoc']['description'] ?? '',
				'param'=>$token['phpdoc']['tags']['param'] ?? null,
				'return'=>$token['phpdoc']['tags']['return'] ?? null,
			], JSON_UNESCAPED_SLASHES);
		}
	}
	return '{}';
}
