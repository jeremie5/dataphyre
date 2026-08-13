<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use dataphyre\datadoc\highlighter;
use dataphyre\datadoc\tokenizer;
use function Dataphyre\Test\test;

require_once __DIR__.'/datadoc_test_helpers.php';

test('Datadoc tokenizes declaration shapes and highlights every source-code token family', static function(Context $t): void {
	$t->defineSymbols('function datadoc_coverage_user_function(): void {}');
	$workspace=$t->workspace('datadoc-source-contracts');
	$fixture=$workspace->file('declarations.php', <<<'PHP'
<?php
/** One-line namespace. */
namespace Closed {}

namespace Acme\Docs {
	/**
	 * Combines values.
	 * @param string $first first value
	 * @param int $second second value
	 *   continued explanation
	 * @return string
	 */
	final class Inline {}

	abstract class Split
	{
		public function contract();

		public function expanded()
		{
			$ddl = "CREATE TABLE example (
				class TEXT
			)";
		}

		public function compact() {}
		public static $value;
	}
}
<script>class BrowserOnly {}</script>
PHP);

	$t->isFalse(tokenizer::tokenize($workspace->path('missing.php')));
	$tokens=tokenizer::tokenize($fixture);
	$t->type('array',$tokens);
	$types=array_count_values(array_column($tokens,'type'));
	$t->greaterThan(1,$types['namespace']);
	$t->greaterThan(1,$types['class']);
	$t->greaterThan(2,$types['function']);
	$t->greaterThan(0,$types['variable']);
	$t->isFalse(in_array('BrowserOnly',array_column($tokens,'class'),true));
	$t->isFalse(in_array('TEXT',array_column($tokens,'class'),true));
	$expanded=array_values(array_filter($tokens,static fn(array $token): bool=>($token['function'] ?? '')==='expanded'))[0];
	$t->contains('CREATE TABLE example',$expanded['content']);
	$inline=array_values(array_filter($tokens,static fn(array $token): bool=>($token['class'] ?? '')==='Inline'))[0];
	$t->same('Acme\Docs',$inline['namespace']);
	$parsed=$t->nonPublic(tokenizer::class)->invoke('parse_phpdoc',<<<'DOC'
/**
 * A parser contract.
 * @param string $first first
 * @param int $second second
 *   continued
 */
DOC);
	$t->same('A parser contract.',$parsed['description']);
	$t->same("int \$second second\ncontinued",$parsed['tags']['param'][1]);

	$source=<<<'PHP'
"double"; 'single';
public static function demo(int $value=1): void {
	global $state;
	// a comment
	if (true && isset($value)) {
		echo __FILE__;
		$value += 2.5;
		trim(' x ');
		datadoc_coverage_user_function();
		SOME_CONSTANT;
		CustomName;
	}
}
?>plain text<?php
PHP;
	$highlighted=highlighter::highlight_php($source,[
		'show_lines'=>true,'start_line'=>12,'highlight_line'=>15,'highlight_offset'=>3,'highlight_class'=>'focus<script>',
	]);
	foreach([
		'php_token_string_doublequote','php_token_string_singlequote','php_token_keywords','php_token_builtin_function',
		'php_token_user_function','php_token_constant','php_token_other','php_token_variable','php_token_integer',
		'php_token_comment','php_token_tag','php_token_operator','php_token_magic_constant','data-datadoc-line-start="10"',
		'data-datadoc-highlight-class="focusscript"','data-datadoc-highlighter="1"',
	] as $contract){
		$t->contains($contract,$highlighted);
	}
	$t->same('plain text',highlighter::highlight_code('plain text','text'));
	$t->contains('php_token_builtin_function',highlighter::highlight_code('trim(" value ");'));
	$t->same("if(true){\n\n\techo 'yes';\n}",highlighter::retabulate_php("if(true){\n\necho 'yes';\n}"));

	$linkified=highlighter::linkify_php(
		'<span class="php_token_magic_constant">__FILE__</span> '
		.'<span class="php_token_constant">null</span> <span class="php_token_constant">true</span> '
		.'<span class="php_token_operator">&&</span> <span class="php_token_operator">===</span> '
		.'<span class="php_token_operator">=</span> <span class="php_token_operator">+</span> '
		.'<span class="php_token_comment">comment</span> <span class="php_token_integer">0x2A</span> '
		.'<span class="php_token_builtin_function">str_replace</span> '
		.'<span class="php_token_user_function">self::build</span> <span class="php_token_variable">$order</span>',
		'docs','Acme\Docs','Builder'
	);
	foreach(['language.constants.magic.php','language.types.null.php','language.types.boolean.php','language.operators.logical.php','language.operators.comparison.php','language.operators.assignment.php','language.operators.php','language.basic-syntax.comments.php','language.types.integer.php','function.str-replace.php','class=Builder','function=build','content=order'] as $linkContract){
		$t->contains($linkContract,$linkified);
	}

	$t->same('datadoc-ui.css',dataphyre_datadoc_ui_asset_name('../DATADOC-UI.CSS'));
	$t->same('',dataphyre_datadoc_ui_asset_name('../unknown.exe'));
	$t->same('',dataphyre_datadoc_ui_asset_url('unknown.exe'));
	$t->same('missing',dataphyre_datadoc_ui_asset_version('unknown.exe'));
	foreach([
		'datadoc-ui.css'=>'text/css','datadoc-sidebar.css'=>'text/css','datadoc-sidebar.js'=>'application/javascript',
		'datadoc-highlighter.css'=>'text/css','datadoc-highlighter.js'=>'application/javascript',
	] as $asset=>$contentType){
		$content=dataphyre_datadoc_ui_asset_content($asset);
		$t->type('array',$content);
		$t->contains($contentType,$content['content_type']);
		$t->notEmpty($content['content']);
		$t->contains('/dataphyre/datadoc/assets/'.$asset,dataphyre_datadoc_ui_asset_url($asset));
		$t->same(16,strlen(dataphyre_datadoc_ui_asset_version($asset)));
	}
	$t->same(null,dataphyre_datadoc_ui_asset_content('missing'));
	$t->contains('.phyro-bold',dataphyre_datadoc_ui_base_css());
	$t->contains('.panel-group',dataphyre_datadoc_ui_sidebar_css());
	$t->contains('(function()',dataphyre_datadoc_ui_sidebar_js());
	$t->contains('.dp-datadoc-highlight',dataphyre_datadoc_highlighter_css());
	$t->contains('Datadoc helper for annotate',dataphyre_datadoc_highlighter_js());
})->tag('datadoc','coverage','tokenizer','highlighter','assets')->group('framework-coverage');

test('Datadoc facade names, menus, records, and file-index operations describe their intent', static function(Context $t): void {
	$t->defineSymbols(<<<'PHP'
final class DatadocSqlFixture {
	public static mixed $select=[];
	public static mixed $insert=true;
	public static mixed $update=true;
	public static mixed $delete=true;
	public static mixed $count=0;
	public static array $calls=[];
}
function sql_select(...$arguments): mixed { DatadocSqlFixture::$calls[]=['select',$arguments]; return DatadocSqlFixture::$select; }
function sql_insert(...$arguments): mixed { DatadocSqlFixture::$calls[]=['insert',$arguments]; return DatadocSqlFixture::$insert; }
function sql_update(...$arguments): mixed { DatadocSqlFixture::$calls[]=['update',$arguments]; return DatadocSqlFixture::$update; }
function sql_delete(...$arguments): mixed { DatadocSqlFixture::$calls[]=['delete',$arguments]; return DatadocSqlFixture::$delete; }
function sql_count(...$arguments): mixed { DatadocSqlFixture::$calls[]=['count',$arguments]; return DatadocSqlFixture::$count; }
PHP);
	dp_datadoc_unit_load_facade();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);

	$t->same('https://example.test/dataphyre/datadoc',\dataphyre\datadoc::index_url());
	$t->same('https://example.test/dataphyre/datadoc/my%20docs/dynadoc',$facade->invoke('project_url','my docs','/dynadoc'));
	$t->same('guides/a%20b',$facade->invoke('manual_path_to_route','//guides//a b//'));
	$t->same('guides/setup/intro',\dataphyre\datadoc::normalize_manual_path(['guides','setup\\intro']));
	$t->same('C:/project/src/File.php',$facade->invoke('normalize_filesystem_path','C:\\project////src\\File.php'));

	\DatadocSqlFixture::$select=[];
	$t->same(null,\dataphyre\datadoc::get_project('missing'));
	\DatadocSqlFixture::$select=[['id'=>1,'name'=>'docs','title'=>'Documentation']];
	$t->same('docs',\dataphyre\datadoc::get_project('docs')['name']);
	$t->isFalse(\dataphyre\datadoc::legacy_password_enabled());
	$t->isFalse(\dataphyre\datadoc::create_project('docs','Documentation','C:/project'));
	$t->contains('SQL module is not loaded',\dataphyre\datadoc::last_error());

	$project=['name'=>'docs'];
	$records=[
		['type'=>'variable','namespace'=>'Acme','class'=>'Order','content'=>'status'],
		['type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve'],
		['type'=>'namespace','namespace'=>'Acme'],
		['type'=>'class','namespace'=>'Acme','class'=>'Order'],
		['type'=>'tracelog','content'=>'raw <call>'],
	];
	foreach($records as $record){
		$rendered=\dataphyre\datadoc::dynadoc_output_record($project,$record);
		$t->contains($record['type']==='tracelog'?'raw &lt;call&gt;':'docs',$rendered);
	}
	$legacyTree=[];
	\dataphyre\datadoc::dynadoc_insert_data($legacyTree,['Acme','Order'],['id'=>1]);
	$t->same(1,$legacyTree['Acme']['Order'][0]['id']);
	$t->same(['Acme','Order'],$facade->invoke('normalize_menu_segments',[' Acme ','','Or/der','\\']));

	$captured=$facade->capture('dynadoc_insert_menu_node',tree:[],path:[
		['id'=>'ns:Acme','label_html'=>'Acme'],['id'=>'class:Order','label_html'=>'Order'],
	],record:['id'=>42,'type'=>'class','class'=>'Order']);
	$menuTree=$captured->argument('tree');
	$t->hasKey('ns:Acme',$menuTree);
	$t->hasKey('class:Order',$menuTree['ns:Acme']['children']);
	$t->same([],$facade->invoke('nested_menu_branch',$menuTree,['missing']));
	$t->hasKey('record:42',$facade->invoke('nested_menu_branch',$menuTree,['ns:Acme','class:Order']));
	$t->contains('guides/intro',$facade->invoke('manual_document_link_html','docs',['path'=>'guides/intro','title'=>'Intro']));

	\DatadocSqlFixture::$select=[
		['id'=>1,'type'=>'namespace','namespace'=>'Acme','class'=>'','function'=>'','content'=>'Acme'],
		['id'=>2,'type'=>'class','namespace'=>'Acme','class'=>'Order','function'=>'','content'=>'Order'],
		['id'=>3,'type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve','content'=>'approve'],
		['id'=>4,'type'=>'variable','namespace'=>'Acme','class'=>'Order','function'=>'approve','content'=>'status'],
		['id'=>5,'type'=>'tracelog','namespace'=>'','class'=>'','function'=>'','content'=>'ignored'],
		['id'=>6,'type'=>'class','namespace'=>'Acme\\\\Domain','class'=>'Invoice','function'=>'','content'=>'Invoice'],
	];
	$built=$facade->invoke('build_dynadoc_menu_tree','docs');
	$t->hasKey('group:namespaces',$built);
	$t->same([],$facade->invoke('nested_menu_branch',$built,['missing']));
	\DatadocSqlFixture::$select=false;
	$t->same([],$facade->invoke('build_dynadoc_menu_tree','none'));

	$t->hasPathValues([
		'group'=>'group:functions','bucket'=>'scope:classes','namespace'=>'Acme\Domain','class'=>'Order','function'=>'approve',
	],$facade->invoke('dynadoc_menu_scope',[
		'group:functions','scope:classes','ns:Acme','ns:Domain','class:Order','function:approve','ignored',
	]));
	[$menuWhere,$menuValues]=$facade->invoke('dynadoc_menu_where','docs',[
		'group'=>'group:variables','namespace'=>'Acme','class'=>'Order','function'=>'approve',
	],true);
	$t->contains('namespace = ?',$menuWhere);
	$t->contains('type = ?',$menuWhere);
	$t->same(['docs','Acme','Order','approve','variable'],$menuValues);
	foreach(['BIGINT','TEXT',' varchar ','JSONB','TIMESTAMP'] as $artifact){
		$t->isTrue($facade->invoke('is_dynadoc_menu_artifact_value',$artifact));
	}
	$t->isFalse($facade->invoke('is_dynadoc_menu_artifact_value','Order'));

	\DatadocSqlFixture::$count=1;
	$t->count(4,$facade->invoke('query_dynadoc_menu_branch','docs',[]));
	\DatadocSqlFixture::$select=[
		['namespace'=>'Acme\Domain'],['namespace'=>'Acme\Other'],['namespace'=>'TEXT'],['namespace'=>''],['namespace'=>'Acme\Domain'],
	];
	$namespaceRoot=$facade->invoke('query_dynadoc_menu_branch','docs',['group:namespaces']);
	$t->hasKey('ns:Acme',$namespaceRoot);
	$t->isFalse(isset($namespaceRoot['ns:TEXT']));
	\DatadocSqlFixture::$select=[['namespace'=>'Acme\Domain'],['namespace'=>'Acme\Other']];
	$namespaceNested=$facade->invoke('query_dynadoc_menu_branch','docs',['group:namespaces','ns:Acme']);
	$t->hasKey('group:classes',$namespaceNested);
	$t->hasKey('group:functions',$namespaceNested);
	$t->hasKey('ns:Domain',$namespaceNested);

	\DatadocSqlFixture::$select=[['class'=>'Order'],['class'=>'TEXT'],['class'=>'']];
	$classBranches=$facade->invoke('query_dynadoc_menu_branch','docs',['group:classes']);
	$t->hasKey('class:Order',$classBranches);
	$t->isFalse(isset($classBranches['class:TEXT']));
	\DatadocSqlFixture::$select=[['id'=>10,'type'=>'function','namespace'=>'','class'=>'','function'=>'global_helper']];
	$globalFunctions=$facade->invoke('query_dynadoc_menu_branch','docs',['group:functions']);
	$t->hasKey('record:10',$globalFunctions);
	\DatadocSqlFixture::$select=[['namespace'=>'Acme\Domain']];
	$functionNamespaces=$facade->invoke('query_dynadoc_menu_branch','docs',['group:functions','scope:namespaces']);
	$t->hasKey('ns:Acme',$functionNamespaces);
	\DatadocSqlFixture::$select=[['class'=>'Order']];
	$functionClasses=$facade->invoke('query_dynadoc_menu_branch','docs',['group:functions','ns:Acme','scope:classes']);
	$t->hasKey('class:Order',$functionClasses);
	\DatadocSqlFixture::$select=[
		['id'=>11,'type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve'],
		'invalid',
	];
	$methodLeaves=$facade->invoke('query_dynadoc_menu_branch','docs',['group:functions','ns:Acme','class:Order','function:approve']);
	$t->hasKey('record:11',$methodLeaves);
	\DatadocSqlFixture::$select=[
		['id'=>12,'type'=>'function','namespace'=>'','class'=>'','function'=>'global_helper'],
		['id'=>13,'type'=>'function','namespace'=>'TEXT','class'=>'','function'=>'artifact'],
		'invalid',
	];
	$globalFunctionLeaf=$facade->capture('append_dynadoc_leaf_records',nodes:[],project:'docs',scope:[
		'group'=>'group:functions','namespace'=>'','class'=>'','function'=>'global_helper',
	]);
	$t->hasKey('record:12',$globalFunctionLeaf->argument('nodes'));
	$t->isFalse(isset($globalFunctionLeaf->argument('nodes')['record:13']));
	\DatadocSqlFixture::$select=false;
	$t->same([],$facade->capture('append_dynadoc_leaf_records',nodes:[],project:'docs',scope:[
		'group'=>'group:variables','namespace'=>'Acme','class'=>'Order','function'=>'',
	])->argument('nodes'));

	\DatadocSqlFixture::$select=false;
	$t->same([],$facade->capture('append_dynadoc_namespace_nodes',nodes:[],project:'docs',scope:['namespace'=>''])->argument('nodes'));
	\DatadocSqlFixture::$select=[['namespace'=>'Acme']];
	$t->same([],$facade->capture('append_dynadoc_namespace_nodes',nodes:[],project:'docs',scope:['namespace'=>'Acme'])->argument('nodes'));
	\DatadocSqlFixture::$select=[['class'=>'Order']];
	$t->hasKey('class:Order',$facade->capture('append_dynadoc_class_scope_nodes',nodes:[],project:'docs',scope:['namespace'=>'Acme'])->argument('nodes'));
	$t->same([],$facade->capture('append_dynadoc_record_group_nodes',nodes:[],project:'docs',scope:['group'=>'group:classes','class'=>'Order','function'=>''])->argument('nodes'));
	$t->same([],$facade->capture('append_dynadoc_record_group_nodes',nodes:[],project:'docs',scope:['group'=>'group:functions','class'=>'','function'=>'approve'])->argument('nodes'));
	\DatadocSqlFixture::$select=[['id'=>14,'type'=>'function','namespace'=>'','class'=>'','function'=>'approve']];
	$t->hasKey('record:14',$facade->capture('append_dynadoc_function_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'','class'=>'','function'=>'approve','bucket'=>'scope:other',
	])->argument('nodes'));
	\DatadocSqlFixture::$select=[['id'=>15,'type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve']];
	$t->hasKey('record:15',$facade->capture('append_dynadoc_function_scope_nodes',nodes:[],project:'docs',scope:[
		'namespace'=>'Acme','class'=>'Order','function'=>'approve','bucket'=>'',
	])->argument('nodes'));

	\DatadocSqlFixture::$select=[['id'=>20,'type'=>'variable','namespace'=>'','class'=>'','function'=>'','content'=>'status']];
	$globalVariables=$facade->invoke('query_dynadoc_menu_branch','docs',['group:variables']);
	$t->hasKey('record:20',$globalVariables);
	\DatadocSqlFixture::$select=[['namespace'=>'Acme\Domain']];
	$variableNamespaces=$facade->invoke('query_dynadoc_menu_branch','docs',['group:variables','scope:namespaces']);
	$t->hasKey('ns:Acme',$variableNamespaces);
	\DatadocSqlFixture::$select=[['id'=>21,'type'=>'variable','namespace'=>'','class'=>'','function'=>'','content'=>'global_state']];
	$variableGlobals=$facade->invoke('query_dynadoc_menu_branch','docs',['group:variables','scope:globals']);
	$t->hasKey('record:21',$variableGlobals);
	\DatadocSqlFixture::$select=[['id'=>22,'type'=>'variable','namespace'=>'Acme','class'=>'Order','function'=>'','content'=>'status']];
	$namespacedVariables=$facade->invoke('query_dynadoc_menu_branch','docs',['group:variables','ns:Acme']);
	$t->hasKey('class:Order',$namespacedVariables);
	$t->hasKey('record:22',$namespacedVariables);

	\DatadocSqlFixture::$select=false;
	$t->same([],$facade->capture('append_dynadoc_record_leaves',nodes:[],where:'WHERE project=?',values:['docs'],order_by:'line')->argument('nodes'));
	\DatadocSqlFixture::$select=['invalid'];
	$t->same([],$facade->capture('append_dynadoc_record_leaves',nodes:[],where:'WHERE project=?',values:['docs'],order_by:'line')->argument('nodes'));
	\DatadocSqlFixture::$select=false;
	$t->same([],$facade->capture('append_dynadoc_distinct_branch_nodes',nodes:[],where:'WHERE project=?',values:['docs'],column:'class',prefix:'class:',label_template:'%s')->argument('nodes'));
	$t->same([],$facade->capture('append_dynadoc_leaf_records',nodes:[],project:'docs',scope:['group'=>'group:classes','namespace'=>'','class'=>'','function'=>''])->argument('nodes'));
	$t->same([],$facade->capture('append_dynadoc_leaf_records',nodes:[],project:'docs',scope:['group'=>'group:namespaces','namespace'=>'','class'=>'','function'=>''])->argument('nodes'));
	$t->same([],$facade->capture('append_dynadoc_leaf_records',nodes:[],project:'docs',scope:['group'=>'group:functions','namespace'=>'','class'=>'','function'=>''])->argument('nodes'));
	$where='WHERE project=?';
	$values=['docs'];
	$classFileExclusion=$facade->capture('append_dynadoc_class_file_exclusion',where:$where,values:$values,project:'docs');
	$t->contains('class_file.file',$classFileExclusion->argument('where'));
	$t->same(['docs','docs','class',''],$classFileExclusion->argument('values'));
	$classNameExclusion=$facade->capture('append_dynadoc_class_function_name_exclusion',where:$where,values:$values,project:'docs');
	$t->contains('method_row.function',$classNameExclusion->argument('where'));
	$t->same(['docs','docs','',''],$classNameExclusion->argument('values'));

	\DatadocSqlFixture::$count=0;
	\DatadocSqlFixture::$select=false;
	$t->same([],\dataphyre\datadoc::get_menu_branch('docs','unknown'));
	$t->same([],\dataphyre\datadoc::get_menu_branch('../docs','manual'));
	$t->same([],\dataphyre\datadoc::get_menu_branch('docs','dynamic',['missing']));
	$t->startsWith('collapseDataDoc',$facade->invoke('menu_collapse_id','docs','dynamic',['Acme']));
	$emptyMenu=$t->captureOutput(static fn()=>\dataphyre\datadoc::render_procedural_menu_nodes('docs','dynamic',[]))->output();
	$t->contains('No items',$emptyMenu);
	$renderedMenu=$t->captureOutput(static fn()=>\dataphyre\datadoc::render_procedural_menu_nodes('docs','dynamic',[
		['node_type'=>'branch','path_segment'=>'Acme','label_html'=>'<b>Acme</b>'],
		['node_type'=>'manual_document','record'=>['path'=>'guides/intro','title'=>'Intro']],
		['node_type'=>'record','record'=>['type'=>'class','namespace'=>'Acme','class'=>'Order']],
	]))->output();
	$t->contains('datadoc-lazy-branch',$renderedMenu);
	$t->contains('guides/intro',$renderedMenu);
	$t->contains('Order',$renderedMenu);
	$t->global('dynadoc_record')->replace(['id'=>42]);
	$t->globalMap('_GET')->replace(['namespace'=>'','class'=>'','type'=>'','function'=>'']);
	$expandedLegacy=$t->captureOutput(static fn()=>\dataphyre\datadoc::dynadoc_output_nested_structure(
		['name'=>'docs'],['Acme'=>['Order'=>['id'=>42,'type'=>'class','namespace'=>'Acme','class'=>'Order']]]
	))->output();
	$t->contains("aria-expanded='true'",$expandedLegacy);

	$t->global('project')->replace(['name'=>'docs']);
	$manual=$t->captureOutput(static fn()=>\dataphyre\datadoc::manudoc_output_record(['path'=>'guides/intro','title'=>'<Intro>']))->output();
	$t->contains('&lt;Intro&gt;',$manual);
	$t->contains('View Document',$manual);
	$manualTree=$t->captureOutput(static fn()=>\dataphyre\datadoc::manudoc_output_nested_structure_from_fs([
		'Guides'=>['type'=>'category','children'=>[
			['type'=>'document','content'=>['path'=>'guides/intro','title'=>'Intro']],
		]],
	]))->output();
	$t->contains('Guides',$manualTree);
	$t->contains('Intro',$manualTree);

	$t->isFalse($facade->invoke('should_skip_datadoc_directory','src'));
	foreach(['.git','vendor','node_modules','unit_tests','cache','tmp'] as $skippedDirectory){
		$t->isTrue($facade->invoke('should_skip_datadoc_directory',$skippedDirectory));
	}
	$t->isTrue(\dataphyre\datadoc::should_exclude_index_file('C:/project/unit_tests/example.php'));
	$t->isTrue(\dataphyre\datadoc::should_exclude_index_file('C:/project/dataphyre/runtime/modules/stripe/src/lib/Stripe.php'));
	$t->isFalse(\dataphyre\datadoc::should_exclude_index_file('C:/project/runtime/modules/core/kernel/core.php'));
	$t->contains('TEXT',$facade->invoke('invalid_dynamic_class_names'));
	\DatadocSqlFixture::$delete=1;
	$t->same(14,\dataphyre\datadoc::prune_excluded_project_files('docs'));
	$t->same(0,\dataphyre\datadoc::prune_excluded_project_files(''));

	$workspace=$t->workspace('datadoc-index-operations');
	$file=$workspace->file('src/Order.php',"<?php\nnamespace Acme;\nfinal class Order { public function approve(): bool { return true; } }\n");
	$workspace->file('src/readme.txt','not php');
	$workspace->file('unit_tests/Ignored.php','<?php class Ignored {}');
	\DatadocSqlFixture::$select=[];
	\DatadocSqlFixture::$insert=true;
	\DatadocSqlFixture::$update=true;
	\DatadocSqlFixture::$delete=true;
	$t->isFalse(\dataphyre\datadoc::register_file_to_project($workspace->path('missing.php'),'docs'));
	$t->isFalse(\dataphyre\datadoc::register_file_to_project($file,''));
	$t->isTrue(\dataphyre\datadoc::register_file_to_project($file,'docs'));
	$t->isTrue(\dataphyre\datadoc::sync_file($file,'docs'));
	$t->isTrue(\dataphyre\datadoc::add_file_to_project($file,'docs'));
	$t->isFalse(\dataphyre\datadoc::add_file_to_project($workspace->path('src/readme.txt'),'docs'));
	$t->isTrue(\dataphyre\datadoc::delete_file($file,'docs'));
	$t->isTrue(\dataphyre\datadoc::delete_file($workspace->path('src'),'docs'));
	\DatadocSqlFixture::$select=[['filepath'=>$file],['filepath'=>'missing.php']];
	$t->same([$file,'missing.php'],\dataphyre\datadoc::get_stale_files('docs'));
	\DatadocSqlFixture::$select=false;
	$t->isFalse(\dataphyre\datadoc::sync_all_files('docs'));
	\DatadocSqlFixture::$select=[];
	$t->isTrue(\dataphyre\datadoc::sync_all_files('docs'));
	$t->isFalse(\dataphyre\datadoc::sync_project_file('','docs'));
	$t->isFalse(\dataphyre\datadoc::sync_project_file($workspace->path('unit_tests/Ignored.php'),'docs'));
	$t->isTrue(\dataphyre\datadoc::sync_project_file($workspace->path('missing.php'),'docs'));
	$t->isTrue(\dataphyre\datadoc::sync_project_file($file,'docs'));
	$t->isTrue($facade->invoke('update_project_file_state',$file,'docs',md5_file($file),false));
	foreach([null,false,0,'0','f','false','',1,'1','true'] as $value){
		$expected=in_array($value,[1,'1','true'],true);
		$t->same($expected,$facade->invoke('database_bool',$value));
	}
	$t->isTrue(\dataphyre\datadoc::change_filepath('old.php','new.php'));
	$t->isFalse(\dataphyre\datadoc::sync_file($workspace->path('missing.php'),'docs'));
	$t->isFalse(\dataphyre\datadoc::sync_file($workspace->path('unit_tests/Ignored.php'),'docs'));

	\DatadocSqlFixture::$select=[['type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve']];
	$t->contains('<a href=',\dataphyre\datadoc::reference_functions('approve the order','docs','Order'));
	\DatadocSqlFixture::$select=false;
	$t->same('plain words',\dataphyre\datadoc::reference_functions('plain words','docs','Order'));

	$invalidDiscovery=\dataphyre\datadoc::discover_files_to_project($workspace->path('missing'),'docs');
	$t->same('Invalid DataDoc project discovery request.',$invalidDiscovery['error']);
	$storageUnavailable=\dataphyre\datadoc::discover_files_to_project($workspace->path('src'),'docs');
	$t->contains('SQL module is not loaded',$storageUnavailable['error']);
	$stats=['registered'=>0,'skipped'=>0,'failed'=>0,'scanned'=>0,'last_cursor'=>'','done'=>true];
	$walk=$facade->capture('discover_files_to_project_walk',dirpath:$workspace->path('src'),project:'docs',limit:1,after:'',stats:$stats);
	$t->isFalse($walk->result());
	$t->same(1,$walk->argument('stats')['scanned']);
})->tag('datadoc','coverage','facade','menus','indexing')->group('framework-coverage');

test('Datadoc storage preparation and project writes report each database outcome', static function(Context $t): void {
	$t->defineSymbols(<<<'PHP'
namespace {
	final class DatadocStorageFixture {
		public static array $queries=[];
		public static array $selects=[];
		public static array $inserts=[];
		public static array $updates=[];
		public static array $deletes=[];
		public static mixed $count=0;
		public static function take(string $property, mixed $fallback): mixed {
			if(self::${$property}===[]){ return $fallback; }
			return array_shift(self::${$property});
		}
	}
	function sql_query(...$arguments): mixed { return DatadocStorageFixture::take('queries',true); }
	function sql_select(...$arguments): mixed { return DatadocStorageFixture::take('selects',[]); }
	function sql_insert(...$arguments): mixed { return DatadocStorageFixture::take('inserts',true); }
	function sql_update(...$arguments): mixed { return DatadocStorageFixture::take('updates',true); }
	function sql_delete(...$arguments): mixed { return DatadocStorageFixture::take('deletes',true); }
	function sql_count(...$arguments): mixed { return DatadocStorageFixture::$count; }
}
namespace dataphyre {
	final class sql {
		public static array $hydrates=[];
		public static array $error=['message'=>'fixture storage failure'];
		public static function hydrate_table_definition(string $table): bool {
			return self::$hydrates===[] ? true : (bool)array_shift(self::$hydrates);
		}
		public static function last_query_error(): array { return self::$error; }
	}
}
PHP);
	dp_datadoc_unit_load_facade();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);

	\dataphyre\sql::$hydrates=[false];
	$t->isFalse($facade->invoke('ensure_index_storage'));
	$t->contains('fixture storage failure',\dataphyre\datadoc::last_error());
	\dataphyre\sql::$hydrates=[true,true];
	\DatadocStorageFixture::$queries=[false];
	$t->isFalse($facade->invoke('ensure_index_storage'));
	$t->contains('could not be repaired',\dataphyre\datadoc::last_error());
	\dataphyre\sql::$hydrates=[true,true];
	\DatadocStorageFixture::$queries=[true,true,true,true];
	$t->isTrue($facade->invoke('ensure_index_storage'));
	$t->isTrue($facade->invoke('ensure_index_storage'));

	\dataphyre\sql::$hydrates=[false];
	$t->isFalse(\dataphyre\datadoc::create_project('unhydrated','Unhydrated','C:/none'));
	$t->contains('project storage could not be prepared',\dataphyre\datadoc::last_error());
	$facade->writeProperty('index_storage_ready',false);
	\dataphyre\sql::$hydrates=[true,false];
	$t->isFalse(\dataphyre\datadoc::create_project('unindexed','Unindexed','C:/none'));
	$t->contains('index storage could not be prepared',strtolower(\dataphyre\datadoc::last_error()));
	$facade->writeProperty('index_storage_ready',true);

	\DatadocStorageFixture::$updates=[1];
	$t->isTrue(\dataphyre\datadoc::create_project('docs','Documentation','C:/docs'));
	\DatadocStorageFixture::$updates=[0];
	\DatadocStorageFixture::$inserts=[1];
	$t->isTrue(\dataphyre\datadoc::create_project('api','API','C:/api'));
	\DatadocStorageFixture::$updates=[0,false];
	\DatadocStorageFixture::$inserts=[false];
	$t->isFalse(\dataphyre\datadoc::create_project('broken','Broken','C:/broken'));
	$t->contains('fixture storage failure',\dataphyre\datadoc::last_error());
	\DatadocStorageFixture::$updates=[0,true];
	\DatadocStorageFixture::$inserts=[false];
	$t->isTrue(\dataphyre\datadoc::create_project('recovered','Recovered','C:/recovered'));

	$t->same('Invalid DataDoc project synchronization request.',\dataphyre\datadoc::sync_project_batch('')['error']);
	\DatadocStorageFixture::$selects=[false];
	$t->same('Unable to load pending DataDoc files.',\dataphyre\datadoc::sync_project_batch('docs')['error']);
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$count=3;
	$emptyBatch=\dataphyre\datadoc::sync_project_batch('docs');
	$t->hasPathValues(['processed'=>0,'remaining'=>3,'error'=>null],$emptyBatch);
	$facade->writeProperty('index_storage_ready',false);
	\dataphyre\sql::$hydrates=[false];
	$t->contains('fixture storage failure',\dataphyre\datadoc::sync_project_batch('docs')['error']);
	$facade->writeProperty('index_storage_ready',true);

	$t->same('Invalid DataDoc project file refresh request.',\dataphyre\datadoc::sync_project_file_if_changed('','docs')['error']);
	\DatadocStorageFixture::$deletes=[1,1];
	$removed=\dataphyre\datadoc::sync_project_file_if_changed('C:/missing.php','docs');
	$t->hasPathValues(['checked'=>true,'changed'=>true,'deleted'=>true],$removed);

	$workspace=$t->workspace('datadoc-storage-index');
	$live=$workspace->file('src/Live.php',"<?php\nnamespace Acme;\nfinal class Live { public function run(): bool { return true; } }\n");
	$text=$workspace->file('src/readme.txt','not php');
	$excluded=$workspace->file('unit_tests/Excluded.php','<?php final class Excluded {}');
	$stripeGenerated=$workspace->file('dataphyre/runtime/modules/stripe/src/lib/Generated.php','<?php final class Generated {}');
	$workspace->file('nested/deeper/First.php','<?php final class First {}');
	$workspace->file('nested/deeper/Second.php','<?php final class Second {}');
	$missing=$workspace->path('src/Missing.php');
	$checksum=(string)md5_file($live);

	\DatadocStorageFixture::$selects=[['checksum'=>$checksum,'last_synced'=>'2026-01-01 00:00:00','is_stale'=>false]];
	\DatadocStorageFixture::$updates=[true];
	$t->isTrue(\dataphyre\datadoc::register_file_to_project($live,'docs'));
	\DatadocStorageFixture::$selects=[['checksum'=>'old','last_synced'=>'2026-01-01 00:00:00','is_stale'=>'f']];
	\DatadocStorageFixture::$updates=[true];
	$t->isTrue(\dataphyre\datadoc::register_file_to_project($live,'docs'));
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$inserts=[false];
	\DatadocStorageFixture::$updates=[true];
	$t->isTrue(\dataphyre\datadoc::register_file_to_project($live,'docs'));
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$inserts=[false];
	\DatadocStorageFixture::$updates=[false];
	$t->isFalse(\dataphyre\datadoc::register_file_to_project($live,'docs'));

	\DatadocStorageFixture::$selects=[];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	\DatadocStorageFixture::$deletes=[];
	$t->isFalse(\dataphyre\datadoc::add_files_to_project($workspace->path('src'),'docs'));
	$discovery=\dataphyre\datadoc::discover_files_to_project($workspace->root(),'docs',10);
	$t->greaterThan(0,$discovery['registered']);
	$t->greaterThan(1,$discovery['skipped']);
	$t->isTrue($discovery['done']);
	$walkStats=['registered'=>0,'skipped'=>0,'failed'=>0,'scanned'=>0,'last_cursor'=>'','done'=>true];
	\DatadocStorageFixture::$selects=[];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$nestedWalk=$facade->capture('discover_files_to_project_walk',dirpath:$workspace->path('nested'),project:'docs',limit:1,after:'',stats:$walkStats);
	$t->isFalse($nestedWalk->result());
	$t->isFalse($nestedWalk->argument('stats')['done']);
	$afterWalk=$facade->capture('discover_files_to_project_walk',dirpath:$workspace->path('nested/deeper'),project:'docs',limit:10,after:$workspace->path('nested/deeper/Second.php'),stats:$walkStats);
	$t->isTrue($afterWalk->result());
	$t->same(0,$afterWalk->argument('stats')['scanned']);
	$excludedWalk=$facade->capture('discover_files_to_project_walk',dirpath:$workspace->path('dataphyre'),project:'docs',limit:10,after:'',stats:$walkStats);
	$t->isTrue($excludedWalk->result());
	$t->greaterThan(0,$excludedWalk->argument('stats')['skipped']);
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$inserts=[false];
	\DatadocStorageFixture::$updates=[false];
	$failedWalk=$facade->capture('discover_files_to_project_walk',dirpath:$workspace->path('src'),project:'docs',limit:10,after:'',stats:$walkStats);
	$t->greaterThan(0,$failedWalk->argument('stats')['failed']);

	\DatadocStorageFixture::$selects=[[
		['filepath'=>$live,'checksum'=>'old','is_stale'=>true],
		['filepath'=>$live,'checksum'=>$checksum,'is_stale'=>false],
		['filepath'=>$missing,'checksum'=>'missing','is_stale'=>true],
	]];
	\DatadocStorageFixture::$deletes=[];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$t->isTrue(\dataphyre\datadoc::sync_all_files('docs'));
	\DatadocStorageFixture::$selects=[[
		['filepath'=>$live,'checksum'=>'old','is_stale'=>true],
	]];
	\DatadocStorageFixture::$deletes=array_merge(array_fill(0,14,true),[false]);
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$t->isTrue(\dataphyre\datadoc::sync_all_files('docs'));

	\DatadocStorageFixture::$selects=[[
		['filepath'=>'','checksum'=>'','is_stale'=>true],
		['filepath'=>$missing,'checksum'=>'missing','is_stale'=>true],
		['filepath'=>$text,'checksum'=>(string)md5_file($text),'is_stale'=>true],
		['filepath'=>$excluded,'checksum'=>(string)md5_file($excluded),'is_stale'=>true],
		['filepath'=>$live,'checksum'=>'old','is_stale'=>true],
	]];
	\DatadocStorageFixture::$deletes=[];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	\DatadocStorageFixture::$count=2;
	$batch=\dataphyre\datadoc::sync_project_batch('docs',10,2.0);
	$t->hasPathValues(['processed'=>5,'synced'=>1,'skipped'=>3,'failed'=>1,'remaining'=>2],$batch);
	\DatadocStorageFixture::$selects=[[
		['filepath'=>$live,'checksum'=>'old','is_stale'=>true],
	]];
	\DatadocStorageFixture::$deletes=array_merge(array_fill(0,14,true),[false]);
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$failedBatch=\dataphyre\datadoc::sync_project_batch('docs',10,2.0);
	$t->same(1,$failedBatch['failed']);

	\DatadocStorageFixture::$selects=[['checksum'=>$checksum,'last_synced'=>'2026-01-01 00:00:00','is_stale'=>false]];
	$unchanged=\dataphyre\datadoc::sync_project_file_if_changed($live,'docs');
	$t->hasPathValues(['checked'=>true,'changed'=>false,'synced'=>false,'error'=>null],$unchanged);
	\DatadocStorageFixture::$selects=[['checksum'=>'old','last_synced'=>null,'is_stale'=>true]];
	\DatadocStorageFixture::$deletes=[];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$changed=\dataphyre\datadoc::sync_project_file_if_changed($live,'docs');
	$t->hasPathValues(['checked'=>true,'changed'=>true,'synced'=>true,'error'=>null],$changed);
	\DatadocStorageFixture::$selects=[[],[]];
	\DatadocStorageFixture::$inserts=[false];
	\DatadocStorageFixture::$updates=[false];
	$registrationFailed=\dataphyre\datadoc::sync_project_file_if_changed($live,'docs');
	$t->same('DataDoc file state could not be registered.',$registrationFailed['error']);
	\DatadocStorageFixture::$selects=[[],[]];
	\DatadocStorageFixture::$inserts=[true];
	\DatadocStorageFixture::$deletes=[];
	\DatadocStorageFixture::$updates=[];
	$registeredAndSynced=\dataphyre\datadoc::sync_project_file_if_changed($live,'docs');
	$t->hasPathValues(['checked'=>true,'changed'=>true,'synced'=>true,'error'=>null],$registeredAndSynced);
	$facade->writeProperty('index_storage_ready',false);
	\dataphyre\sql::$hydrates=[false];
	$t->contains('fixture storage failure',\dataphyre\datadoc::sync_project_file_if_changed($live,'docs')['error']);
	$facade->writeProperty('index_storage_ready',true);
	\DatadocStorageFixture::$selects=[['checksum'=>'old','last_synced'=>null,'is_stale'=>true]];
	\DatadocStorageFixture::$deletes=[false];
	\DatadocStorageFixture::$inserts=[];
	\DatadocStorageFixture::$updates=[];
	$syncFailed=\dataphyre\datadoc::sync_project_file_if_changed($live,'docs');
	$t->hasPathValues(['checked'=>true,'changed'=>true,'synced'=>false,'error'=>'DataDoc file synchronization failed.'],$syncFailed);
	\DatadocStorageFixture::$deletes=[true,true];
	$excludedRefresh=\dataphyre\datadoc::sync_project_file_if_changed($excluded,'docs');
	$t->hasPathValues(['checked'=>true,'changed'=>true,'deleted'=>true],$excludedRefresh);

	\DatadocStorageFixture::$deletes=[];
	\DatadocStorageFixture::$inserts=[false,false,false,false];
	$t->isFalse(\dataphyre\datadoc::sync_file($live,'docs'));
	\DatadocStorageFixture::$deletes=[true,false];
	\DatadocStorageFixture::$inserts=[];
	$t->isFalse(\dataphyre\datadoc::sync_file($live,'docs'));
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$inserts=[false];
	\DatadocStorageFixture::$updates=[false];
	$t->isFalse(\dataphyre\datadoc::add_file_to_project($live,'docs'));
	\DatadocStorageFixture::$selects=[[]];
	\DatadocStorageFixture::$inserts=[true];
	\DatadocStorageFixture::$deletes=[false];
	\DatadocStorageFixture::$updates=[];
	$t->isFalse(\dataphyre\datadoc::add_file_to_project($live,'docs'));
	\DatadocStorageFixture::$selects=[false];
	$t->same([],\dataphyre\datadoc::get_stale_files('docs'));
})->tag('datadoc','coverage','storage','projects')->group('framework-coverage');

test('Datadoc manual documents stay inside their project root and form a navigable tree', static function(Context $t): void {
	$workspace=$t->workspace('datadoc-manual-project');
	$workspace->file('doc/docs/manudocs/root.md.json',json_encode(['title'=>'Root','content'=>'Start here'],JSON_UNESCAPED_SLASHES));
	$workspace->file('doc/docs/manudocs/guides/intro.md.json',json_encode(['title'=>'Introduction','content'=>'Welcome'],JSON_UNESCAPED_SLASHES));
	$workspace->file('doc/docs/manudocs/guides/advanced.md.json',json_encode(['titles'=>'Advanced','content'=>'Details'],JSON_UNESCAPED_SLASHES));
	$workspace->file('doc/docs/manudocs/guides/malformed.md.json','not json');
	$workspace->file('doc/outside.md.json',json_encode(['title'=>'Outside'],JSON_UNESCAPED_SLASHES));
	$target=$workspace->file('manual_contract.php',<<<'PHP'
<?php
declare(strict_types=1);
$root=rtrim((string)$argv[1],'/\\').DIRECTORY_SEPARATOR;
$main=(string)$argv[2];
define('ROOTPATH',['dataphyre'=>$root,'common_dataphyre'=>$root,'common_dataphyre_runtime'=>$root]);
function tracelog(...$arguments): void {}
function dp_module_required(string $module,string $dependency): void {}
function dp_define_module_config(string $module,string $constant): void { if(!defined($constant)){ define($constant,[]); } }
function sql_define_table(string $name,string $file,string $key): void {}
final class DatadocManualCore { public static function url_self(): string { return 'https://example.test'; } }
class_alias(DatadocManualCore::class,'dataphyre\\core');
require $main;
final class DatadocManualProbe extends dataphyre\datadoc {
	public static function root(string $project): ?string { return parent::manual_project_root($project); }
	public static function file(string $project,string $path): ?string { return parent::manual_document_filepath($project,$path); }
}
$manualRoot=DatadocManualProbe::root('docs');
$rootBranch=dataphyre\datadoc::get_manudoc_branch('docs');
$guideBranch=dataphyre\datadoc::get_manudoc_branch('docs',['guides']);
$structure=dataphyre\datadoc::get_manudoc_structure('docs');
$result=[
	'root_is_string'=>is_string($manualRoot),
	'blocks_project_traversal'=>DatadocManualProbe::root('../docs')===null,
	'blocks_missing_project'=>DatadocManualProbe::root('missing')===null,
	'filepath_is_bounded'=>str_ends_with((string)DatadocManualProbe::file('docs','guides/intro'),'intro.md.json'),
	'blocks_invalid_paths'=>array_reduce(['','../outside','guides/../outside','guides/missing'],static fn(bool $ok,string $path): bool=>$ok && DatadocManualProbe::file('docs',$path)===null,true),
	'loads_valid'=>(dataphyre\datadoc::get_manudoc('docs','guides/intro')['title'] ?? '')==='Introduction',
	'rejects_malformed'=>dataphyre\datadoc::get_manudoc('docs','guides/malformed')===null,
	'rejects_missing'=>dataphyre\datadoc::get_manudoc('docs','missing')===null,
	'root_has_directory'=>isset($rootBranch['dir:guides']),
	'root_has_document'=>isset($rootBranch['doc:root']),
	'guide_has_documents'=>isset($guideBranch['doc:intro'],$guideBranch['doc:advanced']),
	'malformed_uses_filename'=>($guideBranch['doc:malformed']['record']['titles'] ?? '')==='malformed',
	'rejects_missing_branch'=>dataphyre\datadoc::get_manudoc_branch('docs',['missing'])===[],
	'rejects_invalid_project_branch'=>dataphyre\datadoc::get_manudoc_branch('../docs')===[],
	'structure_has_category'=>($structure['guides']['type'] ?? '')==='category',
	'structure_skips_malformed'=>count($structure['guides']['children'] ?? [])===2,
	'rejects_invalid_structure'=>dataphyre\datadoc::get_manudoc_structure('../docs')===[],
	'refuses_traversal_delete'=>dataphyre\datadoc::delete_manudoc('docs','../outside')===false,
	'refuses_missing_delete'=>dataphyre\datadoc::delete_manudoc('docs','guides/missing')===false,
	'deletes_valid'=>dataphyre\datadoc::delete_manudoc('docs','guides/advanced')===true,
];
echo json_encode($result,JSON_THROW_ON_ERROR);
PHP);
	$process=$t->coveredPhpProcess(
		[$target,$workspace->root(),dirname(__DIR__).'/kernel/datadoc.main.php'],
		'',
		$workspace->root(),
		[],
		20000,
		dirname(__DIR__,4)
	);
	$t->isTrue($process->succeeded(),$process->stderr());
	foreach($process->json() as $contract=>$satisfied){
		$t->isTrue($satisfied,$contract);
	}
	$t->isFalse(is_file($workspace->path('doc/docs/manudocs/guides/advanced.md.json')));

	dp_datadoc_unit_load_facade();
	$t->globalMap('_SESSION')->replace([]);
	$auth=\dataphyre\datadoc::auth_context();
	$t->hasKey('logged_in',$auth);
	$t->same($auth['logged_in'],\dataphyre\datadoc::logged_in());
	$t->type('boolean',\dataphyre\datadoc::login('not-a-real-password'));
	$t->type('boolean',\dataphyre\datadoc::logout());
})->tag('datadoc','coverage','manuals','filesystem','auth')->group('framework-coverage');

test('Datadoc delegates authentication to Flightdeck and caches the loaded boundary', static function(Context $t): void {
	$t->defineSymbols(<<<'PHP'
final class dataphyre_flightdeck_auth {
	public static bool $authenticated=true;
	public static int $logouts=0;
	public static function authenticated(): bool { return self::$authenticated; }
	public static function login(string $password): bool { return self::$authenticated=$password==='flightdeck'; }
	public static function logout(): void { self::$logouts++; self::$authenticated=false; }
}
PHP);
	dp_datadoc_unit_load_facade();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);
	$t->isTrue($facade->invoke('ensure_flightdeck_auth_loaded'));
	$t->isTrue($facade->invoke('ensure_flightdeck_auth_loaded'));
	$t->hasPathValues([
		'logged_in'=>true,'source'=>'flightdeck','auth_type'=>'flightdeck','can_logout'=>true,'label'=>'Flightdeck console',
	],\dataphyre\datadoc::auth_context());
	$t->isTrue(\dataphyre\datadoc::logged_in());
	$t->isTrue(\dataphyre\datadoc::logout());
	$t->same(1,\dataphyre_flightdeck_auth::$logouts);
	$t->isFalse(\dataphyre\datadoc::logged_in());
	$t->isTrue(\dataphyre\datadoc::login('flightdeck'));
	$t->isFalse(\dataphyre\datadoc::login('wrong'));
})->tag('datadoc','coverage','authentication')->group('framework-coverage');

test('Datadoc index exclusions accept configured authored-source boundaries', static function(Context $t): void {
	define('DATAPHYRE_DATADOC_EXCLUDED_INDEX_PATH_PATTERNS',[
		'%/generated-client/%','%/unit_tests/%','',42,
	]);
	$t->defineSymbols(<<<'PHP'
namespace dataphyre;
if(!class_exists(__NAMESPACE__.'\\dpanel',false)){
	final class dpanel {
		public static array $verbose=[];
		public static function add_verbose(array $verbose): void { self::$verbose=$verbose; }
	}
}
PHP);
	dp_datadoc_unit_load_facade();
	$facade=$t->nonPublic(\dataphyre\datadoc::class);
	$patterns=$facade->invoke('excluded_index_path_patterns');
	$t->contains('%/generated-client/%',$patterns);
	$t->same(count($patterns),count(array_unique($patterns)));
	$t->isTrue(\dataphyre\datadoc::should_exclude_index_file('C:/project/generated-client/Api.php'));
	if(!function_exists('sql_query')){
		require dirname(__DIR__).'/kernel/datadoc.diagnostic.php';
		$t->notEmpty(\dataphyre\dpanel::$verbose);
		$t->contains('SQL-backed DataDoc table checks were skipped',\dataphyre\dpanel::$verbose[array_key_last(\dataphyre\dpanel::$verbose)]['message']);
		\dataphyre\datadoc\diagnostic::tests('7.4.0',static fn(string $extension): bool=>false);
		$diagnostic=json_encode(\dataphyre\dpanel::$verbose,JSON_THROW_ON_ERROR);
		$t->contains('PHP version 8.1.0 or higher is required',$diagnostic);
		$t->contains("PHP extension 'session' is not loaded",$diagnostic);
	}
})->tag('datadoc','coverage','configuration','diagnostic')->group('framework-coverage');

test('Datadoc bootstrap discovers core helpers, loads both config layers, and guards re-entry', static function(Context $t): void {
	$workspace=$t->workspace('datadoc-bootstrap-contract');
	$workspace->file('common-runtime/modules/core/kernel/helper_functions.php',<<<'PHP'
<?php
function dp_module_required(string $module,string $dependency): void {}
function dp_define_module_config(string $module,string $constant): void { if(!defined($constant)){ define($constant,[]); } }
PHP);
	$workspace->file('common-runtime/modules/core/kernel/core.global.php',<<<'PHP'
<?php
namespace dataphyre;
final class core { public static function url_self(): string { return 'https://example.test'; } }
PHP);
	$workspace->file('common-runtime/modules/core/kernel/language_additions.php','<?php');
	$workspace->file('common-runtime/modules/core/kernel/core_functions.php','<?php');
	$workspace->file('common/config/datadoc.php','<?php define("DATADOC_COMMON_CONFIG_LOADED",true);');
	$workspace->file('application/config/datadoc.php','<?php define("DATADOC_APPLICATION_CONFIG_LOADED",true);');
	$target=$workspace->file('bootstrap_contract.php',<<<'PHP'
<?php
declare(strict_types=1);
$mode=(string)$argv[1];
$main=(string)$argv[2];
function tracelog(...$arguments): void {}
if($mode==='bootstrapping'){
	$dataphyre_datadoc_bootstrapping=true;
	include $main;
	echo json_encode(['guarded'=>!class_exists('dataphyre\\datadoc',false)],JSON_THROW_ON_ERROR);
	return;
}
define('ROOTPATH',[
	'common_dataphyre_runtime'=>rtrim((string)$argv[3],'/\\').DIRECTORY_SEPARATOR,
	'common_dataphyre'=>rtrim((string)$argv[4],'/\\').DIRECTORY_SEPARATOR,
	'dataphyre'=>rtrim((string)$argv[5],'/\\').DIRECTORY_SEPARATOR,
]);
include $main;
if($mode==='reentry'){
	include $main;
	echo json_encode(['guarded'=>class_exists('dataphyre\\datadoc',false)],JSON_THROW_ON_ERROR);
	return;
}
echo json_encode([
	'loaded'=>class_exists('dataphyre\\datadoc',false),
	'common_config'=>defined('DATADOC_COMMON_CONFIG_LOADED'),
	'application_config'=>defined('DATADOC_APPLICATION_CONFIG_LOADED'),
],JSON_THROW_ON_ERROR);
PHP);
	$main=dirname(__DIR__).'/kernel/datadoc.main.php';
	$loaded=$t->processSucceeded($t->coveredPhpProcess([
		$target,'load',$main,$workspace->path('common-runtime'),$workspace->path('common'),$workspace->path('application'),
	],'',$workspace->root(),[],20000,dirname(__DIR__,4)));
	$t->same(['loaded'=>true,'common_config'=>true,'application_config'=>true],$loaded->json());
	$guarded=$t->processSucceeded($t->coveredPhpProcess([
		$target,'bootstrapping',$main,$workspace->path('common-runtime'),$workspace->path('common'),$workspace->path('application'),
	],'',$workspace->root(),[],20000,dirname(__DIR__,4)));
	$t->same(['guarded'=>true],$guarded->json());
	$reentry=$t->processSucceeded($t->coveredPhpProcess([
		$target,'reentry',$main,$workspace->path('common-runtime'),$workspace->path('common'),$workspace->path('application'),
	],'',$workspace->root(),[],20000,dirname(__DIR__,4)));
	$t->same(['guarded'=>true],$reentry->json());
})->tag('datadoc','coverage','bootstrap','configuration')->group('framework-coverage');
