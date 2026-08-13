<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use FlightdeckDatadocProbe\Facade as DatadocFacade;
use FlightdeckDatadocProbe\Sql as DatadocSql;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

/** Loads one deterministic DataDoc surface runtime for an isolated test case. */
function dp_flightdeck_datadoc_surface_boot(array $features=[]): void {
	$root=dirname(__DIR__, 4);
	if(!defined('ROOTPATH')){
		define('ROOTPATH', [
			'root'=>$root.'/','common'=>$root.'/','common_dataphyre'=>$root.'/',
			'common_dataphyre_runtime'=>$root.'/runtime/','dataphyre'=>$root.'/',
			'application_roots'=>[],
		]);
	}
	if(!defined('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST')){
		define('DATAPHYRE_FLIGHTDECK_ASSET_REQUEST', true);
	}
	require_once __DIR__.'/fixtures/flightdeck_view_templating_facade_probe.php';
	require_once __DIR__.'/fixtures/flightdeck_datadoc_facade_probe.php';
	DatadocFacade::reset();
	if(($features['sql'] ?? false)===true){
		require_once __DIR__.'/fixtures/flightdeck_datadoc_sql_probe.php';
		DatadocSql::reset();
	}
	if(($features['highlighter'] ?? false)===true){
		require_once __DIR__.'/fixtures/flightdeck_datadoc_highlighter_probe.php';
		\dataphyre\datadoc\highlighter::$throw=false;
		\dataphyre\datadoc\highlighter::$calls=[];
	}
	if(($features['auth'] ?? false)===true){
		require_once __DIR__.'/fixtures/flightdeck_datadoc_auth_probe.php';
		dataphyre_flightdeck_auth::$valid=true;
	}
	if(($features['assets'] ?? false)===true){
		require_once __DIR__.'/fixtures/flightdeck_datadoc_assets_probe.php';
	}
	require_once dirname(__DIR__).'/kernel/surfaces/datadoc.php';
}

suite('Flightdeck DataDoc surface exact behavior')
	->tag('flightdeck','datadoc','surface','routing','indexing','coverage')
	->group('framework-coverage')
	->contract('flightdeck.datadoc.surface.exact',1)
	->layer('integration')
	->risk('critical')
	->watches('module:flightdeck','module:datadoc')
	->through('closed routing','portable SQL seams','bounded indexing','path confinement','rich documentation rendering')
	->isolation('process');

test('assets routes URLs and forms publish portable escaped contracts',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['assets'=>true,'auth'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);

	$t->same([
		'root'=>[],
		'decoded'=>['project one','manudoc','guide intro'],
		'unmounted'=>['outside','path'],
	],$surface->invokeCases([
		'root'=>['method'=>'segments','arguments'=>['/dataphyre/datadoc?ignored=1']],
		'decoded'=>['method'=>'segments','arguments'=>['/dataphyre/datadoc/project%20one/manudoc/guide%20intro']],
		'unmounted'=>['method'=>'segments','arguments'=>['/outside/path']],
	]));
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/datadoc/from-server']);
	$t->same(['from-server'],$surface->invoke('segments'));

	$t->same('safe.css',$surface->invoke('asset_name','../safe.css'));
	$t->same('',$surface->invoke('asset_name','bad asset.css'));
	$t->contains('/dataphyre/flightdeck/assets/datadoc-surface.css?v=',dataphyre_flightdeck_datadoc_surface::asset_url('../datadoc-surface.css'));
	$t->matches('/^[a-f0-9]{16}$/',dataphyre_flightdeck_datadoc_surface::asset_version('datadoc-surface.css'));
	$t->same('missing',dataphyre_flightdeck_datadoc_surface::asset_version('missing.asset'));
	$t->contains('.fd-datadoc-layout',dataphyre_flightdeck_datadoc_surface::asset_content('datadoc-surface.css')['body']);
	$t->contains('window.datadocProbe',dataphyre_flightdeck_datadoc_surface::asset_content('datadoc-ui.js')['body']);
	$t->isNull(dataphyre_flightdeck_datadoc_surface::asset_content('unknown.js'));
	$t->contains('stylesheet',$surface->invoke('style_link'));
	$t->contains('.fd-scope-tree',$surface->invoke('style'));
	$t->contains('window.__fdDatadocScopeTree',$surface->invoke('scope_tree_script'));
	$t->contains('window.setTimeout',$surface->invoke('auto_batch_script'));

	$missing=$t->captureOutput(static fn()=>$surface->invoke('asset_response','missing.asset'));
	$t->same('DataDoc asset not found.',$missing->output());
	$t->same(404,http_response_code());
	$t->same('window.datadocProbe=true;',$t->captureOutput(
		static fn()=>$surface->invoke('asset_response','datadoc-ui.js'),
	)->output());

	$t->same([
		'project'=>'/dataphyre/datadoc/my%20docs',
		'suffix'=>'/dataphyre/datadoc/docs/settings',
		'manual'=>'/dataphyre/datadoc/docs/manudoc/guides/a%20b',
	],$surface->invokeCases([
		'project'=>['method'=>'project_url','arguments'=>['my docs']],
		'suffix'=>['method'=>'project_url','arguments'=>['docs','/settings']],
		'manual'=>['method'=>'manudoc_url','arguments'=>['docs','//guides//a b//']],
	]));
	$t->containsAll(['type=function','function=build'],$surface->invoke('dynadoc_url','docs',[
		'type'=>'function','namespace'=>'Acme','class'=>'Tool','function'=>'build',
	]));
	$t->contains('content=status',$surface->invoke('dynadoc_url','docs',['type'=>'variable','content'=>'status']));
	$t->containsAll(['function=','content=raw'],$surface->invoke('dynadoc_url','docs',['type'=>'other','content'=>'raw']));
	$t->notContains('function=',$surface->invoke('dynadoc_url','docs',['type'=>'class','class'=>'Tool']));

	$t->same('my_project_name',$surface->invoke('normalize_project_key',' --My Project.Name-- '));
	$t->same(80,strlen($surface->invoke('normalize_project_key',str_repeat('x',100))));
	$t->same('C:/project/src',$surface->invoke('normalize_path','C:\\project\\src\\'));
	$t->same('src/File.php',$surface->invoke('project_relative_path','/project/src/File.php','/project'));
	$t->same('project',$surface->invoke('project_relative_path','/project','/project'));
	$t->same('File.php',$surface->invoke('project_relative_path','/elsewhere/File.php','/project'));
	$t->same('&lt;unsafe&gt;',$surface->invoke('e','<unsafe>'));

	$t->containsAll(['fd_datadoc_index_action','refresh_project','Auto'],$surface->invoke(
		'index_action_form','refresh_project','Refresh',['project'=>'docs'],'fd-primary',true,
	));
	$t->containsAll(['fd_datadoc_action','sync_project'],$surface->invoke(
		'project_action_form','sync_project','Sync',[], 'fd-primary',false,
	));
	$t->containsAll(['field&lt;','action&lt;','name&lt;','value&lt;','label&lt;'],$surface->invoke(
		'batch_action_form','field<','action<','label<',['name<'=>'value<'],'class<',null,
	));
	$t->isTrue($surface->invoke('batch_action_supports_auto','sync_project'));
	$t->isFalse($surface->invoke('batch_action_supports_auto','create_project'));
	$t->contains('checked',$surface->invoke('auto_batch_toggle_html',true));
	$t->notContains('checked',$surface->invoke('auto_batch_toggle_html',false));
	$t->contains('valid-token',$surface->invoke('csrf_input'));
	$t->containsAll(['continue_index','data-fd-auto-batch="1"','cursor&lt;'],$surface->invoke(
		'continue_index_form','docs','/project','cursor<','fd_datadoc_action',true,
	));
	$t->notContains('data-fd-auto-batch',$surface->invoke(
		'continue_index_form','docs','/project','','fd_datadoc_action',false,
	));
	$t->same([250,20,25,4.0],[
		$surface->invoke('discovery_batch_limit'),$surface->invoke('sync_batch_limit'),
		$surface->invoke('stale_file_preview_limit'),$surface->invoke('sync_batch_seconds'),
	]);

	$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET']);
	$t->isTrue($surface->invoke('auto_batch_enabled'));
	$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'POST']);
	$t->globalMap('_POST')->replace(['auto_batch'=>'0']);
	$t->isFalse($surface->invoke('auto_batch_enabled'));
	$t->globalMap('_POST')->replace([]);
	$t->isTrue($surface->invoke('auto_batch_enabled'));
	dataphyre_flightdeck_datadoc_surface::dispatch_entrypoint(true);
	include dirname(__DIR__).'/kernel/surfaces/datadoc.php';
});

test('SQL readers express filters exclusions and failure semantics without raw transforms',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['sql'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);

	$error='stale';
	$missingProjects=$surface->capture('projects',error:$error,selector:null,sql_available:false);
	$t->same([],$missingProjects->result());
	$t->same('SQL helpers are not loaded.',$missingProjects->argument('error'));
	$select=static fn()=>[['name'=>'docs']];
	$loadedProjects=$surface->capture('projects',error:$error,selector:$select,sql_available:true);
	$t->same([['name'=>'docs']],$loadedProjects->result());
	$t->isNull($loadedProjects->argument('error'));
	$t->same([],$surface->capture('projects',error:$error,selector:static fn()=>false,sql_available:true)->result());
	$postgresFailure=$surface->capture(
		'projects',error:$error,selector:static fn()=>throw new RuntimeException('pg_connect missing'),sql_available:true,
	);
	$t->same([],$postgresFailure->result());
	$t->contains('PostgreSQL extension',$postgresFailure->argument('error'));
	$blankFailure=$surface->capture(
		'projects',error:$error,selector:static fn()=>throw new RuntimeException(''),sql_available:true,
	);
	$t->same([],$blankFailure->result());
	$t->same('Registered projects could not be loaded.',$blankFailure->argument('error'));
	$t->same('storage unavailable',$surface->invoke('project_storage_error',new RuntimeException(' storage unavailable ')));

	$t->same(0,$surface->invoke('count_rows','table','WHERE x=?',[1],null,false));
	$t->same(7,$surface->invoke('count_rows','table','WHERE x=?',[1],static fn()=>7,true));
	$t->same(0,$surface->invoke('count_rows','table','',[],static fn()=>'not-numeric',true));
	$t->same(0,$surface->invoke('count_rows','table','',[],static fn()=>throw new RuntimeException('count failed'),true));

	$vars=['docs'];
	$where=$surface->capture('append_index_path_exclusions',where:'WHERE project=?',field:'file',vars:$vars);
	$t->contains('file NOT LIKE ?',$where->result());
	$t->contains('%/unit_tests/%',$where->argument('vars'));
	$invalidVars=[];
	$invalid=$surface->capture('append_invalid_record_exclusions',where:'WHERE 1=1',vars:$invalidVars);
	$t->contains('NOT (type=? AND namespace=? AND class=?)',$invalid->result());
	$t->containsAll(['class','TEXT','VARCHAR'],$invalid->argument('vars'));
	$t->containsAll(['BIGINT','TEXT','VARCHAR'],$surface->invoke('invalid_dynamic_class_names'));
	$t->containsAll(['tracelog','sql_select','sql_count'],$surface->invoke('excluded_dynamic_record_functions'));
	$t->contains('%/unit_tests/%',$surface->invoke('excluded_index_path_patterns'));

	$default=$surface->invoke('dynamic_record_conditions','docs',['q'=>'','type'=>'','active'=>false]);
	$t->contains('NOT (type=? AND function IN (?, ?, ?, ?, ?, ?))',$default['conditions']);
	$t->contains('tracelog',$default['vars']);
	$filtered=$surface->invoke('dynamic_record_conditions','docs',['q'=>'Order','type'=>'class','active'=>true]);
	$t->containsAll(['type=?','(content LIKE ? OR function LIKE ? OR class LIKE ? OR namespace LIKE ? OR file LIKE ? OR phpdoc_description LIKE ?)'],$filtered['conditions']);
	$t->containsAll(['class','%Order%'],$filtered['vars']);
	$invalidType=$surface->invoke('dynamic_record_conditions','docs',['q'=>'','type'=>'invalid','active'=>true]);
	$t->notContains('type=?',$invalidType['conditions']);

	$t->globalMap('_GET')->replace(['q'=>str_repeat('q',140),'record_type'=>'invalid']);
	$filters=$surface->invoke('dynamic_record_filters');
	$t->same(120,strlen($filters['q']));
	$t->same('',$filters['type']);
	$t->isTrue($filters['active']);
	$t->globalMap('_GET')->replace(['record_type'=>'function']);
	$t->hasPathValues(['q'=>'','type'=>'function','active'=>true],$surface->invoke('dynamic_record_filters'));
	$t->containsRows([['value'=>'class','label'=>'Classes']],array_map(
		static fn(string $label,string $value): array=>['value'=>$value,'label'=>$label],
		$surface->invoke('dynamic_record_type_options'),array_keys($surface->invoke('dynamic_record_type_options')),
	));
	$t->containsAll(['selected','Clear'],$surface->invoke('dynamic_record_filter_form','docs',[
		'q'=>'Order','type'=>'class','active'=>true,
	]));
	$t->notContains('Clear',$surface->invoke('dynamic_record_filter_form','docs',['q'=>'','type'=>'','active'=>false]));
	$t->containsAll(['Showing 2 of 120','current filters','Narrow the search'],$surface->invoke(
		'dynamic_record_result_summary',2,120,80,['active'=>true],
	));

	$t->globalMap('_GET')->replace([]);
	$t->isFalse($surface->invoke('has_dynamic_record_selection'));
	$t->globalMap('_GET')->replace(['class'=>' Order ']);
	$t->isTrue($surface->invoke('has_dynamic_record_selection'));

	$t->same([],$surface->invoke('dynamic_records','docs',50,['q'=>'','type'=>'','active'=>false],null,false));
	$records=[['type'=>'class','class'=>'Order']];
	$t->same($records,$surface->invoke('dynamic_records','docs',0,['q'=>'','type'=>'','active'=>false],static fn()=> $records,true));
	$t->same([],$surface->invoke('dynamic_records','docs',5,['q'=>'','type'=>'','active'=>true],static fn()=>false,true));
	$t->same([],$surface->invoke('dynamic_records','docs',5,['q'=>'','type'=>'','active'=>true],static fn()=>throw new RuntimeException('select failed'),true));

	$t->globalMap('_GET')->replace(['namespace'=>'Acme','class'=>'Order','function'=>'build']);
	$t->same([],$surface->invoke('matching_dynamic_records','docs',null,false));
	$t->same($records,$surface->invoke('matching_dynamic_records','docs',static fn()=> $records,true));
	$t->same([],$surface->invoke('matching_dynamic_records','docs',static fn()=>false,true));
	$t->same([],$surface->invoke('matching_dynamic_records','docs',static fn()=>throw new RuntimeException('select failed'),true));
	$t->globalMap('_GET')->put('type','function');
	$surface->invoke('matching_dynamic_records','docs',static fn()=>[],true);

	$t->same('',$surface->invoke('last_indexed_file','docs',null,false));
	$t->same('/project/Z.php',$surface->invoke('last_indexed_file','docs',static fn()=>[['cursor'=>'/project/Z.php']],true));
	$t->same('',$surface->invoke('last_indexed_file','docs',static fn()=>['bad-row'],true));
	$t->same('',$surface->invoke('last_indexed_file','docs',static fn()=>throw new RuntimeException('select failed'),true));

	DatadocSql::willCount(3,4,5,6);
	$t->same(3,$surface->invoke('count_index_files','docs'));
	$t->same(4,$surface->invoke('count_index_files','docs',true));
	$t->same(5,$surface->invoke('count_index_records','docs'));
	$t->same(6,$surface->invoke('count_default_dynamic_records','docs'));
	$t->count(4,DatadocSql::calls('count'));
});

test('filesystem discovery and application confinement stay deterministic and traversal safe',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot();
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);
	$workspace=$t->workspace('flightdeck-datadoc-paths');
	$applications=$workspace->directory('applications');
	$appA=$workspace->directory('applications/Alpha App');
	$appB=$workspace->directory('applications/beta-app');
	$workspace->directory('applications/.hidden');
	$workspace->file('applications/not-an-app','file');
	$workspace->directory('applications/---');
	$source=$workspace->directory('dataphyre');

	$t->isNull($surface->invoke('dataphyre_source_root',[$workspace->path('missing')]));
	$t->same($surface->invoke('normalize_path',$source),$surface->invoke('dataphyre_source_root',[$source]));
	$t->same([$surface->invoke('normalize_path',$applications)],$surface->invoke('application_roots',[$applications,$applications]));
	$t->same([],$surface->invoke('application_roots',[$workspace->path('missing')]));
	$located=$surface->invoke('application_roots',null,static fn()=>[$applications],true);
	$t->contains($surface->invoke('normalize_path',$applications),$located);

	$t->isNull($surface->invoke('current_application_path',null,[$applications],false));
	$t->same($surface->invoke('normalize_path',$appA),$surface->invoke('current_application_path','Alpha App',[$applications],true));
	$t->isNull($surface->invoke('current_application_path','missing',[$applications],true));

	$apps=$surface->invoke('discovered_applications',[
		'dataphyre_root'=>$source,'application_roots'=>[$applications],
	]);
	$t->containsRows([
		['name'=>'dataphyre','project'=>'dataphyre','path'=>$surface->invoke('normalize_path',$source)],
		['name'=>'Alpha App','project'=>'alpha_app','path'=>$surface->invoke('normalize_path',$appA)],
		['name'=>'beta-app','project'=>'beta-app','path'=>$surface->invoke('normalize_path',$appB)],
	],$apps);
	$alpha=array_values(array_filter($apps,static fn(array $app): bool=>$app['project']==='alpha_app'))[0];
	$t->same($alpha,$surface->invoke('application_by_key',$alpha['key'],$apps));
	$t->isNull($surface->invoke('application_by_key','missing',$apps));

	$t->same([
		$surface->invoke('normalize_path',$applications),$surface->invoke('normalize_path',$source),
	],$surface->invoke('allowed_project_roots',[$applications,$source,$source]));
	$t->isNull($surface->invoke('validated_project_path','',[$applications]));
	$t->isNull($surface->invoke('validated_project_path',$workspace->path('missing'),[$applications]));
	$t->isNull($surface->invoke('validated_project_path',$source,[$applications]));
	$t->same($surface->invoke('normalize_path',$appB),$surface->invoke('validated_project_path',$appB,[$applications]));
	$t->same('',$surface->invoke('validated_project_cursor','',$appB));
	$t->same('',$surface->invoke('validated_project_cursor',$source.'/outside.php',$appB));
	$t->same($surface->invoke('normalize_path',$appB.'/File.php'),$surface->invoke('validated_project_cursor',$appB.'/File.php',$appB));

	$tree=$workspace->directory('source');
	$workspace->file('source/A.php','<?php');
	$workspace->file('source/B.txt','text');
	$workspace->directory('source/vendor');
	$workspace->file('source/vendor/Hidden.php','<?php');
	$workspace->directory('source/nested');
	$workspace->file('source/nested/B.php','<?php');
	$workspace->file('source/nested/C.php','<?php');
	$t->isNull($surface->invoke('next_discoverable_file_after',$workspace->path('missing'),''));
	$t->isNull($surface->invoke('next_discoverable_file_after_walk',$tree,'',static fn()=>false,static fn()=>false));
	$t->endsWith('/A.php',$surface->invoke('next_discoverable_file_after',$tree,'',null,static fn()=>false));
	$t->endsWith('/nested/B.php',$surface->invoke('next_discoverable_file_after',$tree,$tree.'/A.php',null,static fn()=>false));
	$t->endsWith('/nested/C.php',$surface->invoke('next_discoverable_file_after',$tree,$tree.'/A.php',null,
		static fn(string $file): bool=>str_ends_with($file,'B.php'),
	));
	$t->isNull($surface->invoke('next_discoverable_file_after',$tree,$tree.'/nested/C.php',null,static fn()=>false));
	$t->isTrue($surface->invoke('should_skip_discovery_directory','vendor'));
	$t->isFalse($surface->invoke('should_skip_discovery_directory','src'));
});

test('navigation records manuals and rich source rendering describe documentation semantics',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['highlighter'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);

	$nodes=[
		'ignored'=>'scalar',
		'intro'=>['type'=>'document','content'=>['titles'=>'Introduction'],'path'=>'guides/intro'],
		'category'=>['type'=>'category','children'=>[
			'setup'=>['type'=>'document','content'=>['title'=>'Setup','id'=>'guides/setup']],
		]],
		'legacy'=>[
			'legacy-doc'=>['type'=>'document','content'=>[]],
		],
	];
	$documents=[];
	$flattened=$surface->capture('flatten_manual_documents',nodes:$nodes,documents:$documents,limit:10);
	$t->containsRows([
		['title'=>'Introduction','path'=>'guides/intro'],
		['title'=>'Setup','path'=>'guides/setup'],
		['title'=>'legacy-doc','path'=>'legacy-doc'],
	],$flattened->argument('documents'));
	$limited=[];
	$limitedCapture=$surface->capture('flatten_manual_documents',nodes:$nodes,documents:$limited,limit:1);
	$t->count(1,$limitedCapture->argument('documents'));

	DatadocFacade::will('get_manudoc_structure',$nodes,new RuntimeException('manual storage failed'));
	$t->count(2,$surface->invoke('manual_documents','docs',2));
	$t->same([],$surface->invoke('manual_documents','docs',20));
	DatadocFacade::will('get_stale_files',['/project/A.php','/project/vendor/B.php'],new RuntimeException('stale failed'));
	DatadocFacade::will('should_exclude_index_file',false,true);
	$t->same(['/project/A.php'],$surface->invoke('stale_files','docs'));
	$t->same([],$surface->invoke('stale_files','docs'));

	$t->same([
		'namespace'=>'\\Acme\\Domain',
		'class'=>'\\Acme\\Domain\\Order',
		'method'=>'\\Acme\\Order::approve()',
		'function'=>'build()',
		'variable'=>'$status',
		'content'=>'raw record',
		'fallback'=>'record',
	],$surface->invokeCases([
		'namespace'=>['method'=>'record_label','arguments'=>[['type'=>'namespace','namespace'=>'Acme\\Domain']]],
		'class'=>['method'=>'record_label','arguments'=>[['type'=>'class','namespace'=>'Acme\\Domain','class'=>'Order']]],
		'method'=>['method'=>'record_label','arguments'=>[['type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve']]],
		'function'=>['method'=>'record_label','arguments'=>[['type'=>'function','function'=>'build']]],
		'variable'=>['method'=>'record_label','arguments'=>[['type'=>'variable','content'=>'$$status']]],
		'content'=>['method'=>'record_label','arguments'=>[['type'=>'record','content'=>'raw record']]],
		'fallback'=>['method'=>'record_label','arguments'=>[[]]],
	]));
	$row=$surface->invoke('record_row','docs',[
		'type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve',
		'file'=>'/project/src/Order.php','line'=>42,
	]);
	$t->count(4,$row);
	$t->containsAll(['Order.php:42','function=approve'],implode(' ',$row));
	$t->notContains(':',$surface->invoke('record_row','docs',['file'=>'/project/NoLine.php'])[2]);

	$t->contains('fd-datadoc-code',$surface->invoke('highlight_code',str_repeat('x',24001),[],true));
	$t->notContains('fd-datadoc-code',$surface->invoke('highlight_code','plain <code>',[],false,['available'=>false]));
	$linked=$surface->invoke('highlight_code','echo 1;',[
		'project'=>'docs','namespace'=>'Acme','class'=>'Order','function'=>'approve','line'=>17,
	],true);
	$t->containsAll(['fd-datadoc-code','<linked','data-project="docs"'],$linked);
	$t->hasPathValues(['show_lines'=>true,'start_line'=>17,'line_number_start'=>17],\dataphyre\datadoc\highlighter::$calls[0][3]);
	$t->contains('<linked',$surface->invoke('highlight_code','echo 2;',['project'=>'docs'],false));
	\dataphyre\datadoc\highlighter::$throw=true;
	$t->notContains('<linked',$surface->invoke('highlight_code','fallback',[],false));
	$t->contains('fallback',$surface->invoke('highlight_code','fallback',[],false,[
		'available'=>true,
		'highlight'=>static fn()=>throw new RuntimeException('custom highlighter failed'),
		'linkify'=>static fn(string $html): string=>$html,
	]));

	DatadocFacade::will('get_menu_branch',new RuntimeException('tree failed'),[],['root'=>true],['root'=>true]);
	$t->same('',$surface->invoke('scope_tree_html','docs','dynamic'));
	$t->same('',$surface->invoke('scope_tree_html','docs','dynamic'));
	DatadocFacade::will('render_procedural_menu_nodes','', '<a class="menu-item">Order</a>');
	$t->same('',$surface->invoke('scope_tree_html','docs','dynamic'));
	$tree=$surface->invoke('scope_tree_html','docs','dynamic',['','group:classes','class:Order']);
	$t->containsAll(['fd-scope-tree','data-datadoc-current-path','class:Order','Order'],$tree);

	$server=$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/datadoc/docs/dynadoc']);
	$get=$t->globalMap('_GET');
	$get->replace([]);
	$t->same([],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'variable','namespace'=>'Acme\\ Domain ','class'=>'Order']);
	$t->same(['group:namespaces','ns:Acme','ns:Domain','group:variables','scope:classes','class:Order'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve']);
	$t->same(['group:namespaces','ns:Acme','group:classes','class:Order'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'function','function'=>'build']);
	$t->same(['group:functions'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'class','namespace'=>'Acme','class'=>'Order']);
	$t->same(['group:namespaces','ns:Acme','group:classes','class:Order'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'namespace','namespace'=>'Acme\\Domain']);
	$t->same(['group:namespaces','ns:Acme','ns:Domain'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'class']);
	$t->same(['group:classes'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'namespace']);
	$t->same(['group:namespaces'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'other','namespace'=>'Acme','class'=>'Order','content'=>'record']);
	$t->same(['group:namespaces','ns:Acme','group:classes','class:Order'],$surface->invoke('current_dynamic_scope_path','docs'));
	$get->replace(['type'=>'other','content'=>'record']);
	$t->same([],$surface->invoke('current_dynamic_scope_path','docs'));
	$server->put('REQUEST_URI','/dataphyre/datadoc/other/dynadoc');
	$t->same([],$surface->invoke('current_dynamic_scope_path','docs'));

	DatadocFacade::will('get_manudoc_structure',$nodes);
	DatadocFacade::will('get_menu_branch',['root'=>true]);
	DatadocFacade::will('render_procedural_menu_nodes','<a class="menu-item">Order</a>');
	$server->put('REQUEST_URI','/dataphyre/datadoc/docs');
	$get->replace([]);
	$navigation=$surface->invoke('project_navigation',['name'=>'docs','title'=>'Documentation']);
	$t->containsAll(['Overview','Settings','Introduction','Browse records','window.__fdDatadocScopeTree'],$navigation);

	$t->contains('No applications discovered',$surface->invoke('application_management_card',[],[]));
	$apps=[['key'=>'one','name'=>'shop','title'=>'Shop App','project'=>'shop','path'=>'/apps/shop']];
	$t->containsAll(['Not registered','Start Index'],$surface->invoke('application_management_card',[],$apps));
	$t->containsAll(['Project exists','Open'],$surface->invoke('application_management_card',[
		'shop'=>['name'=>'shop'],
	],$apps));
	$t->containsAll(['Create Project','/apps/default'],$surface->invoke('project_create_card','/apps/default'));
});

test('index project dynamic and manual pages render every meaningful document state',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['sql'=>true,'highlighter'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);
	$project=['name'=>'docs','title'=>'Documentation','path'=>dirname(__DIR__,4)];
	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/datadoc/docs','REQUEST_METHOD'=>'GET']);
	$get=$t->globalMap('_GET')->replace([]);
	$t->globalMap('_POST')->replace([]);

	$t->containsAll(['DataDoc','Shell body','Documentation'],$t->captureOutput(
		static fn()=>$surface->invoke('render_project_shell',$project,'Shell Title','Kicker','Shell body','<button>Act</button>'),
	)->output());
	$t->containsAll(['Missing Project','&lt;missing&gt;'],$t->captureOutput(
		static fn()=>$surface->invoke('render_not_found','<missing>'),
	)->output());
	$t->containsAll(['Section Not Found','unknown'],$t->captureOutput(
		static fn()=>$surface->invoke('render_unknown_project_route',$project,'  '),
	)->output());
	$t->contains('reports',$t->captureOutput(
		static fn()=>$surface->invoke('render_unknown_project_route',$project,' reports '),
	)->output());

	DatadocSql::willSelect([]);
	$emptyIndex=$t->captureOutput(static fn()=>$surface->invoke('render_index'))->output();
	$t->containsAll(['No DataDoc projects yet','Create Project'],$emptyIndex);
	DatadocSql::willSelect([
		['name'=>'','title'=>'ignored'],
		['name'=>'docs','title'=>'Documentation','path'=>'/project/docs'],
	]);
	$index=$t->captureOutput(static fn()=>$surface->invoke('render_index'))->output();
	$t->containsAll(['Documentation','/project/docs','Open'],$index);
	DatadocSql::willSelect(new RuntimeException('project database failed'));
	$t->contains('project database failed',$t->captureOutput(static fn()=>$surface->invoke('render_index'))->output());

	DatadocSql::willCount(0,0,0,0,0,0,0,0,0,0);
	DatadocSql::willSelect([]);
	$t->containsAll(['Index Progress','No dynamic records','No stale files'],$t->captureOutput(
		static fn()=>$surface->invoke('render_project',$project),
	)->output());

	$records=[
		['project'=>'docs','type'=>'class','namespace'=>'Acme','class'=>'Order','file'=>'/project/src/Order.php','line'=>10],
		['project'=>'docs','type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve','file'=>'/project/src/Order.php','line'=>20],
	];
	$stale=[];
	for($index=0;$index<30;$index++){
		$stale[]='/project/src/Stale'.$index.'.php';
	}
	DatadocSql::willCount(50,100,30,100,50,30,200,50,30,200);
	DatadocSql::willSelect($records);
	DatadocFacade::will('get_stale_files',$stale);
	$richOverview=$t->captureOutput(static fn()=>$surface->invoke('render_project',$project))->output();
	$t->containsAll(['Order','approve','Showing 2 of 100','Showing 25 of 30 stale files','Run Next Batch'],$richOverview);

	DatadocSql::willCount(5,6,7);
	$t->containsAll(['Project Settings','Manual Docs Root'],$t->captureOutput(
		static fn()=>$surface->invoke('render_settings',$project),
	)->output());

	$t->contains('Choose a dynamic record',$t->captureOutput(
		static fn()=>$surface->invoke('render_dynadoc',$project),
	)->output());
	$get->replace(['type'=>'class','namespace'=>'Acme','class'=>'Missing']);
	DatadocSql::willSelect([]);
	$t->contains('Dynamic Record Not Found',$t->captureOutput(
		static fn()=>$surface->invoke('render_dynadoc',$project),
	)->output());
	$t->same(404,http_response_code());

	$primary=[
		'project'=>'docs','type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve',
		'file'=>'/project/src/Order.php','line'=>20,'phpdoc_description'=>'Approves an order.',
		'phpdoc_tags'=>json_encode([
			'author'=>['Jane Doe',['team'=>'Core']],
			'package'=>'Acme','subpackage'=>'Orders','version'=>'2.0',
			'example'=>['$order->approve();'],'warning'=>['Requires review.'],
		],JSON_UNESCAPED_SLASHES),
		'content'=>'public function approve(): void {}',
	];
	$updated=$primary;
	$updated['phpdoc_description']='Updated approval docs.';
	$sibling=['project'=>'docs','type'=>'variable','namespace'=>'Acme','class'=>'Order','content'=>'status','file'=>'/project/src/Order.php','line'=>''];
	$get->replace(['type'=>'function','namespace'=>'Acme','class'=>'Order','function'=>'approve']);
	DatadocSql::willSelect([$primary,$sibling],[$updated,$sibling]);
	DatadocFacade::will('sync_project_file_if_changed',['changed'=>true,'deleted'=>false,'error'=>'Refresh warning']);
	$dynamic=$t->captureOutput(static fn()=>$surface->invoke('render_dynadoc',$project))->output();
	$t->containsAll([
		'Updated approval docs.','Jane Doe','Core','Acme, Orders','2.0','Example','Warning',
		'fd-datadoc-code','Other Matches','Refresh warning',
	],$dynamic);
	$t->notContains('Approves an order.',$dynamic);

	$withoutTags=$primary;
	$withoutTags['phpdoc_tags']='not-json';
	$withoutTags['phpdoc_description']='0';
	$withoutTags['content']='';
	DatadocSql::willSelect([$withoutTags]);
	DatadocFacade::will('sync_project_file_if_changed',['changed'=>false,'deleted'=>false,'error'=>'']);
	$minimal=$t->captureOutput(static fn()=>$surface->invoke('render_dynadoc',$project))->output();
	$t->notContains('Description',$minimal);
	$t->notContains('Other Matches',$minimal);

	$get->replace([]);
	DatadocFacade::will('get_manudoc',
		null,
		['title'=>'Introduction','contents'=>'<p>Welcome</p>'],
		['titles'=>'Legacy Guide','content'=>['html'=>'<p>Legacy</p>']],
		['body'=>['section'=>'structured']],
	);
	$t->contains('Manual Document Not Found',$t->captureOutput(
		static fn()=>$surface->invoke('render_manudoc',$project,['missing']),
	)->output());
	$t->containsAll(['Introduction','Welcome'],$t->captureOutput(
		static fn()=>$surface->invoke('render_manudoc',$project,['guides','intro']),
	)->output());
	$t->containsAll(['Legacy Guide','Legacy'],$t->captureOutput(
		static fn()=>$surface->invoke('render_manudoc',$project,['legacy']),
	)->output());
	$t->containsAll(['structured','section'],$t->captureOutput(
		static fn()=>$surface->invoke('render_manudoc',$project,['structured']),
	)->output());
	$t->contains('Manual Document Not Found',$t->captureOutput(
		static fn()=>$surface->invoke('render_manudoc',$project,[]),
	)->output());

	$t->containsAll(['100%','index is current'],$surface->invoke('index_progress_card','docs',[
		'files'=>10,'stale'=>0,'records'=>20,'discovery_pending'=>false,'discovery_cursor'=>'',
	]));
	$t->contains('More PHP files are available',$surface->invoke('index_progress_card','docs',[
		'files'=>10,'stale'=>0,'records'=>20,'discovery_pending'=>true,'discovery_cursor'=>'/project/A.php',
	]));
	$t->contains('0%',$surface->invoke('index_progress_card','docs',[
		'files'=>0,'stale'=>0,'records'=>0,'discovery_pending'=>false,'discovery_cursor'=>'',
	]));
});

test('project creation and bounded batches expose closed mutation outcomes',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['sql'=>true,'auth'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);
	$workspace=$t->workspace('flightdeck-datadoc-actions');
	$projectPath=$workspace->directory('project');
	$workspace->file('project/A.php','<?php');
	$allowed=[$workspace->root()];
	$server=$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET']);
	$post=$t->globalMap('_POST')->replace([]);

	$t->containsAll(['sync_project','Run Next Batch'],$surface->invoke(
		'project_batch_action_from_progress',['stale'=>3],true,'docs',
	));
	$t->contains('fd_datadoc_action',$surface->invoke(
		'project_batch_action_from_progress',['stale'=>3],false,'docs',
	));
	$t->containsAll(['refresh_project','cursor'],$surface->invoke(
		'project_batch_action_from_progress',['stale'=>0,'discovery_pending'=>true,'discovery_cursor'=>'/project/A.php'],true,'docs',
	));
	$t->notContains('name="cursor"',$surface->invoke(
		'project_batch_action_from_progress',['stale'=>0,'discovery_pending'=>true,'discovery_cursor'=>''],false,'docs',
	));
	$t->containsAll(['Check for Updates','fd-secondary'],$surface->invoke(
		'project_batch_action_from_progress',['stale'=>0,'discovery_pending'=>false],true,'docs',
	));
	$t->contains('fd_datadoc_action',$surface->invoke(
		'project_batch_action_from_progress',['stale'=>0,'discovery_pending'=>false],false,'docs',
	));

	$t->containsAll(['Synced This Batch','Failed This Batch','Known Files'],$surface->invoke(
		'batch_summary_table',null,['synced'=>2,'failed'=>1],['files'=>5,'stale'=>2,'records'=>8],
	));
	$t->containsAll(['Discovery Registered','Discovery Cursor','no'],$surface->invoke(
		'batch_summary_table',['registered'=>3,'last_cursor'=>'/project/A.php','done'=>false],['synced'=>2],['files'=>5],
	));

	$t->contains('Project key is required',$surface->invoke(
		'create_project_from_input','---','',$projectPath,false,$allowed,
	));
	$t->contains('must be an existing directory',$surface->invoke(
		'create_project_from_input','docs','Docs',$workspace->path('missing'),false,$allowed,
	));
	DatadocFacade::will('create_project',true);
	$t->contains('project created',$surface->invoke(
		'create_project_from_input','My Docs','',$projectPath,false,$allowed,
	));
	$t->same('My Docs',DatadocFacade::calls('create_project')[0][1]);
	DatadocFacade::will('create_project',false,false);
	DatadocFacade::will('last_error','Storage denied','');
	$t->contains('Storage denied',$surface->invoke(
		'create_project_from_input','docs','Docs',$projectPath,false,$allowed,
	));
	$t->contains('project creation failed',$surface->invoke(
		'create_project_from_input','docs','Docs',$projectPath,false,$allowed,
	));
	DatadocFacade::will('create_project',true);
	DatadocFacade::will('discover_files_to_project',['error'=>'Discovery denied']);
	$t->contains('Discovery denied',$surface->invoke(
		'create_project_from_input','docs','Docs',$projectPath,true,$allowed,
	));

	$server->replace(['REQUEST_METHOD'=>'POST']);
	$post->replace(['auto_batch'=>'0']);
	DatadocFacade::will('discover_files_to_project',['error'=>'Batch discovery failed']);
	$t->contains('Batch discovery failed',$surface->invoke('run_index_batch','docs',$projectPath,'','fd_datadoc_action'));

	DatadocFacade::will('discover_files_to_project',[
		'registered'=>4,'last_cursor'=>$projectPath.'/A.php','done'=>false,'error'=>'',
	]);
	DatadocFacade::will('sync_project_batch',['synced'=>2,'failed'=>0,'stopped_by'=>'time','error'=>'']);
	DatadocSql::willCount(4,1,6);
	$unfinished=$surface->invoke('run_index_batch','docs',$projectPath,'','fd_datadoc_action');
	$t->containsAll(['4 file(s) queued','2 file(s) synced','continue_index'],$unfinished);
	$t->notContains('window.setTimeout',$unfinished);

	$post->put('auto_batch','1');
	DatadocFacade::will('discover_files_to_project',[
		'registered'=>1,'last_cursor'=>$projectPath.'/A.php','done'=>false,'error'=>'',
	]);
	DatadocFacade::will('sync_project_batch',['synced'=>1,'failed'=>0,'stopped_by'=>'batch limit','error'=>'']);
	DatadocSql::willCount(5,1,7);
	$t->contains('window.setTimeout',$surface->invoke('run_index_batch','docs',$projectPath,'','fd_datadoc_action'));

	DatadocFacade::will('discover_files_to_project',[
		'registered'=>0,'last_cursor'=>$projectPath.'/A.php','done'=>true,'error'=>'',
	]);
	DatadocFacade::will('sync_project_batch',['synced'=>1,'failed'=>1,'stopped_by'=>'time','error'=>'']);
	DatadocSql::willCount(5,2,7);
	$staleBatch=$surface->invoke('run_index_batch','docs',$projectPath,'','fd_datadoc_action');
	$t->containsAll(['sync_project','window.setTimeout'],$staleBatch);

	DatadocFacade::will('discover_files_to_project',[
		'registered'=>0,'last_cursor'=>$projectPath.'/A.php','done'=>true,'error'=>'',
	]);
	DatadocFacade::will('sync_project_batch',['synced'=>0,'failed'=>0,'stopped_by'=>'complete','error'=>'']);
	DatadocSql::willCount(5,0,7);
	$t->notContains('Run Next Batch',$surface->invoke('run_index_batch','docs',$projectPath,'','fd_datadoc_action'));

	DatadocFacade::will('sync_project_batch',['error'=>'Sync denied']);
	$t->contains('Sync denied',$surface->invoke('run_sync_batch','docs','fd_datadoc_action'));
	DatadocFacade::will('sync_project_batch',['synced'=>3,'failed'=>0,'stopped_by'=>'time','error'=>'']);
	DatadocSql::willCount(5,2,7);
	$t->containsAll(['3 file(s) synced','Run Next Batch','window.setTimeout'],$surface->invoke(
		'run_sync_batch','docs','fd_datadoc_action',
	));
	DatadocFacade::will('sync_project_batch',['synced'=>1,'failed'=>0,'stopped_by'=>'complete','error'=>'']);
	DatadocSql::willCount(5,0,7);
	$t->notContains('Run Next Batch',$surface->invoke('run_sync_batch','docs','fd_datadoc_action'));

	DatadocSql::willSelect([['cursor'=>'']]);
	$discovery=$surface->invoke('project_discovery_progress','unique-docs',$projectPath);
	$t->isTrue($discovery['pending']);
	$t->endsWith('/A.php',$discovery['next']);
	$t->same($discovery,$surface->invoke('project_discovery_progress','unique-docs',$projectPath));
});

test('index and project action routers validate tokens identities paths and action vocabularies',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['sql'=>true,'auth'=>true]);
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);
	$workspace=$t->workspace('flightdeck-datadoc-action-routing');
	$projectPath=$workspace->directory('project');
	$allowed=[$workspace->root()];
	$project=['name'=>'docs','title'=>'Documentation','path'=>$projectPath];
	DatadocFacade::$projects['docs']=$project;
	$app=[
		'key'=>'app-key','name'=>'shop','title'=>'Shop App','project'=>'shop','path'=>$projectPath,
	];
	$server=$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET']);
	$post=$t->globalMap('_POST')->replace([]);

	$t->same('',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$server->put('REQUEST_METHOD','POST');
	$t->same('',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->replace(['fd_datadoc_index_action'=>'create_project']);
	$t->contains('Invalid Flightdeck form token',$surface->invoke('handle_index_action',$allowed,[$app],false));
	$post->replace(['fd_datadoc_index_action'=>'unsupported','csrf'=>'valid-token']);
	$t->contains('Unsupported DataDoc action',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$t->contains('Unsupported DataDoc action',$surface->invoke('handle_index_action',$allowed,[$app]));

	$post->replace(['fd_datadoc_index_action'=>'sync_project','project'=>'missing']);
	$t->contains('Invalid DataDoc project synchronization',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->put('project','docs');
	DatadocFacade::will('sync_project_batch',['error'=>'Sync route stopped']);
	$t->contains('Sync route stopped',$surface->invoke('handle_index_action',$allowed,[$app],true));

	$post->replace(['fd_datadoc_index_action'=>'refresh_project','project'=>'missing']);
	$t->contains('Invalid DataDoc project refresh',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->put('project','docs')->put('cursor',$workspace->path('outside.php'));
	DatadocFacade::will('discover_files_to_project',['error'=>'Refresh route stopped']);
	$t->contains('Refresh route stopped',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$t->same('',DatadocFacade::calls('discover_files_to_project')[0][3]);

	$post->replace([
		'fd_datadoc_index_action'=>'continue_index','project'=>'docs','path'=>$workspace->path('missing'),
	]);
	$t->contains('Invalid DataDoc index continuation',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->put('path',$projectPath)->put('cursor',$projectPath.'/A.php');
	DatadocFacade::will('discover_files_to_project',['error'=>'Continuation stopped']);
	$t->contains('Continuation stopped',$surface->invoke('handle_index_action',$allowed,[$app],true));

	$post->replace(['fd_datadoc_index_action'=>'create_app_project','app_key'=>'missing']);
	$t->contains('Invalid application project',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->put('app_key','app-key');
	DatadocFacade::will('create_project',true);
	$t->contains('project created',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->put('fd_datadoc_index_action','create_app_project_index');
	DatadocFacade::will('create_project',true);
	DatadocFacade::will('discover_files_to_project',['error'=>'App indexing stopped']);
	$t->contains('App indexing stopped',$surface->invoke('handle_index_action',$allowed,[$app],true));

	$post->replace([
		'fd_datadoc_index_action'=>'create_project','project'=>'---','title'=>'','path'=>$projectPath,
	]);
	$t->contains('Project key is required',$surface->invoke('handle_index_action',$allowed,[$app],true));
	$post->replace([
		'fd_datadoc_index_action'=>'create_project','project'=>'custom-docs','title'=>'Custom Docs',
		'path'=>$projectPath,'index_now'=>'0',
	]);
	DatadocFacade::will('create_project',true);
	$t->contains('project created',$surface->invoke('handle_index_action',$allowed,[$app],true));

	$server->put('REQUEST_METHOD','GET');
	$post->replace([]);
	$t->same('',$surface->invoke('handle_project_action',$project,$allowed,true));
	$server->put('REQUEST_METHOD','POST');
	$post->replace(['fd_datadoc_action'=>'sync_project']);
	$t->contains('Invalid Flightdeck form token',$surface->invoke('handle_project_action',$project,$allowed,false));
	DatadocFacade::will('sync_project_batch',['error'=>'Project sync stopped']);
	$t->contains('Project sync stopped',$surface->invoke('handle_project_action',$project,$allowed,true));

	$invalidProject=$project;
	$invalidProject['path']=$workspace->path('missing');
	$post->replace(['fd_datadoc_action'=>'refresh_project']);
	$t->contains('Invalid DataDoc project refresh',$surface->invoke('handle_project_action',$invalidProject,$allowed,true));
	$post->put('cursor',$workspace->path('outside.php'));
	DatadocFacade::will('discover_files_to_project',['error'=>'Project refresh stopped']);
	$t->contains('Project refresh stopped',$surface->invoke('handle_project_action',$project,$allowed,true));

	$post->replace(['fd_datadoc_action'=>'continue_index','path'=>$workspace->path('missing')]);
	$t->contains('Invalid DataDoc index continuation',$surface->invoke('handle_project_action',$project,$allowed,true));
	$post->put('path',$projectPath)->put('cursor',$projectPath.'/A.php');
	DatadocFacade::will('discover_files_to_project',['error'=>'Project continuation stopped']);
	$t->contains('Project continuation stopped',$surface->invoke('handle_project_action',$project,$allowed,true));
	$post->replace(['fd_datadoc_action'=>'unsupported']);
	$t->contains('Unsupported DataDoc project action',$surface->invoke('handle_project_action',$project,$allowed,true));
	$post->put('csrf','valid-token');
	$t->contains('Unsupported DataDoc project action',$surface->invoke('handle_project_action',$project,$allowed));
});

test('HTTP dispatch maps every DataDoc route to its page partial asset or closed error',static function(Context $t): void {
	dp_flightdeck_datadoc_surface_boot(['sql'=>true,'assets'=>true]);
	$project=['name'=>'docs','title'=>'Documentation','path'=>''];
	DatadocFacade::$projects['docs']=$project;
	$server=$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/datadoc','REQUEST_METHOD'=>'GET']);
	$get=$t->globalMap('_GET')->replace([]);
	$t->globalMap('_POST')->replace([]);

	$t->contains('No DataDoc projects yet',$t->captureOutput(
		static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch(),
	)->output());
	$server->put('REQUEST_URI','/dataphyre/datadoc/assets/datadoc-ui.js');
	$t->same('window.datadocProbe=true;',$t->captureOutput(
		static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch(),
	)->output());

	$server->put('REQUEST_URI','/dataphyre/datadoc/dynadoc_menu_processor');
	DatadocFacade::$logged_in=false;
	$t->same('',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$t->same(403,http_response_code());
	DatadocFacade::$logged_in=true;
	$get->replace(['project'=>'missing','kind'=>'dynamic','path'=>'not-json']);
	$t->same('Invalid project.',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$t->same(404,http_response_code());
	$get->replace(['project'=>'docs','kind'=>'dynamic','path'=>'not-json']);
	DatadocFacade::will('get_menu_branch',[]);
	DatadocFacade::will('render_procedural_menu_nodes','');
	$t->same('',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$get->replace(['project'=>'docs','kind'=>' DYNAMIC ','path'=>'["group:classes"]']);
	DatadocFacade::will('get_menu_branch',['class:Order'=>[]]);
	DatadocFacade::will('render_procedural_menu_nodes','<a>Order</a>');
	$t->same('<a>Order</a>',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());

	$get->replace([]);
	$server->put('REQUEST_URI','/dataphyre/datadoc/missing');
	$t->contains('Missing Project',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$t->same(404,http_response_code());
	$server->put('REQUEST_URI','/dataphyre/datadoc/docs/settings');
	$t->contains('Project Settings',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$server->put('REQUEST_URI','/dataphyre/datadoc/docs/dynadoc');
	$t->contains('Choose a dynamic record',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$server->put('REQUEST_URI','/dataphyre/datadoc/docs/manudoc/guides/intro');
	$t->contains('Manual Document Not Found',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	foreach(['dashboard','overview'] as $route){
		$server->put('REQUEST_URI','/dataphyre/datadoc/docs/'.$route);
		$t->contains('Index Progress',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output(),$route);
	}
	$server->put('REQUEST_URI','/dataphyre/datadoc/docs/reports');
	$t->contains('Section Not Found',$t->captureOutput(static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch())->output());
	$t->same(404,http_response_code());
});

test('configured roots exclusions and the real entrypoint lifecycle remain portable',static function(Context $t): void {
	$workspace=$t->workspace('flightdeck-datadoc-configured-roots');
	$projectRoot=$workspace->directory('project-root');
	$applications=$workspace->directory('project-root/applications');
	$workspace->directory('project-root/applications/shop');
	if(!defined('DATAPHYRE_PROJECT_ROOT')){
		define('DATAPHYRE_PROJECT_ROOT',$projectRoot);
	}
	if(!defined('DATAPHYRE_DATADOC_EXCLUDED_INDEX_PATH_PATTERNS')){
		define('DATAPHYRE_DATADOC_EXCLUDED_INDEX_PATH_PATTERNS',[
			'%/generated/%','',42,'%/generated/%',
		]);
	}
	dp_flightdeck_datadoc_surface_boot();
	$surface=$t->nonPublic(dataphyre_flightdeck_datadoc_surface::class);
	$patterns=$surface->invoke('excluded_index_path_patterns');
	$t->contains('%/generated/%',$patterns);
	$t->same(1,count(array_keys($patterns,'%/generated/%',true)));
	$t->contains($surface->invoke('normalize_path',$applications),$surface->invoke('application_roots'));
	$t->contains($surface->invoke('normalize_path',$projectRoot),$surface->invoke('allowed_project_roots'));

	$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/dataphyre/datadoc','REQUEST_METHOD'=>'GET']);
	$t->globalMap('_GET')->replace([]);
	$t->globalMap('_POST')->replace([]);
	$t->contains('No DataDoc projects yet',$t->captureOutput(
		static fn()=>dataphyre_flightdeck_datadoc_surface::dispatch_entrypoint(false,null,static fn()=>null),
	)->output());

	$root=dirname(__DIR__,4);
	$payload=$t->processSucceeded($t->coveredPhpFixture(
		__DIR__.'/fixtures/flightdeck_datadoc_entrypoint_probe.php',
		[
			$root,
			dirname(__DIR__).'/kernel/surfaces/datadoc.php',
			__DIR__.'/fixtures/flightdeck_view_templating_facade_probe.php',
			__DIR__.'/fixtures/flightdeck_datadoc_facade_probe.php',
			__DIR__.'/fixtures/flightdeck_datadoc_highlighter_probe.php',
			__DIR__.'/fixtures/flightdeck_datadoc_assets_probe.php',
		],
		working_directory:$root,
		framework_root:$root,
	))->json();
	$t->hasPathValues([
		'unavailable'=>true,'facade_loaded'=>true,'bootstrapped'=>true,'repeated_silent'=>true,
	],$payload);
});
