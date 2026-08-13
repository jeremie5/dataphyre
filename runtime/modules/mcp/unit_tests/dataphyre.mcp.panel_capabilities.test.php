<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Dataphyre
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mcp\Panel\PanelCapabilityCatalog;
use Dataphyre\Mcp\Panel\PanelCapabilitySource;
use Dataphyre\Mcp\Panel\SourcePanelCapabilityIndex;
use Dataphyre\Mcp\Testing\McpPanelFixture;
use Dataphyre\Mcp\Testing\McpPanelInspectionHarness;
use Dataphyre\Mcp\Testing\McpPanelProbe;
use Dataphyre\Mcp\Testing\McpScenario;
use Dataphyre\Mcp\Testing\McpKernelHarness;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/testing/McpPanelTestKit.php';
require_once dirname(__DIR__).'/testing/McpTestKit.php';

suite('MCP Panel capability intelligence')
	->tag('mcp','panel','capability','coverage')
	->group('framework-coverage')
	->contract('mcp.panel.capability-intelligence',1)
	->layer('system')
	->risk('critical')
	->watches('module:mcp','module:panel')
	->through('source index','semantic catalog','smart partials','dependency graph','recipes','integration plans','verification plans','MCP protocol')
	->maxMillis(180000);

test('the live index accounts for every current Panel contract family without loading Panel',static function(Context $t): void {
	$snapshot=McpPanelProbe::liveSnapshot($t);
	$t->same('dataphyre_panel_capability_source',$snapshot['snapshot_type']);
	$t->same('not_executed',$snapshot['execution']);
	$t->same('literal_php_array_tokens_contract_tokens_and_bounded_markdown',$snapshot['source_strategy']);
	$t->same(25,$snapshot['counts']['domains']);
	$t->greaterThan(882,$snapshot['counts']['framework_files']);
	$t->greaterThan(21,$snapshot['counts']['documents']);
	$t->greaterThan(244,$snapshot['counts']['tests']);
	$t->greaterThan(649,$snapshot['counts']['contracts']);
	$t->same([], $snapshot['diagnostics']['platform_parse_failures']);
	$t->same([], $snapshot['diagnostics']['missing_feature_sources']);
	$t->same(false,$snapshot['diagnostics']['framework_truncated']);

	$names=McpPanelProbe::names($snapshot['domains']);
	foreach(['operations_os','data','realtime','studio','media','packages','platform'] as $name){$t->contains($name,$names);}
	$realtime=McpPanelProbe::domain($snapshot,'realtime');
	foreach(['pdo_adapter','redis_streams_adapter','phpredis_transport','predis_transport','conformance'] as $feature){$t->same(true,McpPanelProbe::feature($realtime,$feature)['source_present']);}
	$media=McpPanelProbe::domain($snapshot,'media');
	foreach(['manager','dataphyre_storage_disk','snapshot_store','pdo_snapshot_store'] as $feature){$t->same(true,McpPanelProbe::feature($media,$feature)['source_present']);}
});

test('the tiny fixture proves literal parsing, support discovery, caching, and execution-free test indexing',static function(Context $t): void {
	$fixture=new McpPanelFixture($t);
	$source=$fixture->source();
	$first=$source->snapshot();
	$second=$source->snapshot();
	$t->same($first['inventory_fingerprint'],$second['inventory_fingerprint']);
	$t->same(2,$first['counts']['domains']);
	$t->same(5,$first['counts']['framework_files']);
	$t->same(2,$first['counts']['documents']);
	$t->same(2,$first['counts']['tests']);
	$t->same(false,$fixture->markerExists());
	$alpha=McpPanelProbe::domain($first,'alpha');
	$t->same(['engine'],$alpha['required_features']);
	$t->same('Dataphyre\\Panel\\AlphaContract',$alpha['services'][0]['expected']);
	$t->same('runtime/modules/panel/testing/AlphaConformance.php',McpPanelProbe::feature($alpha,'conformance')['source_paths'][0]);
	$t->same('Dataphyre\\Panel\\PanelPlatformManifest',McpPanelProbe::feature($alpha,'manifest')['class']);
	$t->same(true,McpPanelProbe::feature($alpha,'callback')['source_present']);

	$bounded=$fixture->source(1)->snapshot();
	$t->same(true,$bounded['diagnostics']['framework_truncated']);
	$t->same(true,$bounded['diagnostics']['test_inventory_truncated']);
	$t->throws(static fn()=>new SourcePanelCapabilityIndex(dirname($fixture->root())),InvalidArgumentException::class);
	$t->same(null,$t->nonPublic($source)->invoke('nextMeaningful',[[T_WHITESPACE,' ',1]],0));

	$inspection=new McpPanelInspectionHarness($fixture->root());
	$t->same('dataphyre_panel_capability_catalog',$inspection->invoke('catalog',['limit'=>5])['catalog_type']);
	$t->same('alpha',$inspection->invoke('describe',['id'=>'panel:domain:alpha','view'=>'all','max_items'=>2])['capability']['overview']['name']);
	$t->greaterThan(0,$inspection->invoke('graph',['roots'=>['alpha'],'depth'=>1])['counts']['nodes']);
	$t->same('application',$inspection->invoke('recipe',['task'=>'build alpha CRUD','domains'=>['alpha']])['mode']);
	$t->same('custom',$inspection->invoke('integration',['domains'=>['alpha'],'provider'=>'custom','topology'=>'host_managed'])['provider']);
	$t->same('focused',$inspection->invoke('verification',['domains'=>['alpha'],'claim'=>'focused'])['claim']);
	$t->same('dataphyre_panel_capability_index',$inspection->invoke('resource')['resource_type']);
	$t->throws(static fn()=>$inspection->invoke('unknown'),InvalidArgumentException::class);

	$kernel=(new McpKernelHarness($t))->useRepositoryRoot(dirname($fixture->root()));
	$resource=$kernel->invoke('read_resource',['uri'=>'dataphyre://panel']);
	$t->same('application/json',$resource['contents'][0]['mimeType']);
	$t->same('dataphyre_panel_capability_index',json_decode($resource['contents'][0]['text'],true,512,JSON_THROW_ON_ERROR)['resource_type']);
	$calls=[
		'dataphyre_panel_capability_catalog'=>['kinds'=>['platform_domain'],'limit'=>5],
		'dataphyre_panel_capability_describe'=>['id'=>'panel:domain:alpha','view'=>'overview','max_items'=>2],
		'dataphyre_panel_surface_graph'=>['roots'=>['alpha'],'depth'=>1],
		'dataphyre_panel_recipe_plan'=>['task'=>'build alpha CRUD','domains'=>['alpha']],
		'dataphyre_panel_integration_plan'=>['domains'=>['alpha'],'provider'=>'custom','topology'=>'host_managed'],
		'dataphyre_panel_verification_plan'=>['domains'=>['alpha'],'claim'=>'focused'],
	];
	foreach($calls as $tool=>$arguments){
		$response=$kernel->invoke('call_tool',['name'=>$tool,'arguments'=>$arguments]);
		$t->same('text',$response['content'][0]['type'],$tool);
		$t->isTrue(is_array(json_decode($response['content'][0]['text'],true)),$tool);
	}
});

test('catalog smart partials are complete, bounded, and self-describing',static function(Context $t): void {
	$catalog=McpPanelProbe::semanticCatalog();
	$all=$catalog->catalog(['limit'=>200]);
	$t->same(25,$all['kind_summary']['platform_domain']);
	$t->greaterThan(29,$all['kind_summary']['framework_area']);
	$t->same([], $all['diagnostics']['missing_profiles']);
	$t->same([], $all['diagnostics']['stale_profiles']);
	foreach(array_filter($all['records'],static fn(array $record): bool=>$record['kind']==='platform_domain') as $record){
		foreach(['id','name','label','category','summary','feature_count','evidence_counts','describe_with'] as $key){$t->hasKey($key,$record);}
	}

	$partial=$catalog->catalog(['kinds'=>['platform_domain'],'categories'=>['data'],'query'=>'stream','offset'=>0,'limit'=>1]);
	$t->same(1,$partial['counts']['returned']);
	$t->same('realtime',$partial['records'][0]['name']);
	$t->same(false,$partial['pagination']['has_more']);
	$areaPage=$catalog->catalog(['kinds'=>['framework_area'],'offset'=>1,'limit'=>2]);
	$t->same(2,$areaPage['counts']['returned']);
	$t->same(true,$areaPage['pagination']['has_more']);
	$t->same(3,$areaPage['pagination']['next_offset']);

	foreach(PanelCapabilityCatalog::VIEWS as $view){
		$descriptor=$catalog->describe('panel:domain:realtime',$view,3);
		$t->same('found',$descriptor['status']);
		$t->same($view,$descriptor['view']);
		$t->same('realtime',$descriptor['capability']['overview']['name']);
		$t->same(false,$descriptor['safety']['eval_used']);
	}
	$area=$catalog->describe('panel:area:rendering','all',2);
	$t->same('framework_area',$area['capability']['overview']['kind']);
	$t->same(2,count($area['capability']['overview']['sample_files']));
	$rank=$t->nonPublic($catalog);
	$rankedRecord=['name'=>'realtime','label'=>'Live Events','id'=>'panel:domain:realtime','aliases'=>['streaming'],'summary'=>'Bounded event delivery'];
	$t->same(100,$rank->invoke('searchScore',$rankedRecord,'realtime'));
	$t->same(100,$rank->invoke('searchScore',$rankedRecord,'panel:domain:realtime'));
	$t->same(90,$rank->invoke('searchScore',$rankedRecord,'live events'));
	$t->same(80,$rank->invoke('searchScore',$rankedRecord,'streaming'));
	$t->same(60,$rank->invoke('searchScore',$rankedRecord,'real'));
	$t->same(20,$rank->invoke('searchScore',$rankedRecord,'delivery'));
	$t->same(10,$rank->invoke('searchScore',$rankedRecord,'unrelated'));

	$t->throws(static fn()=>$catalog->catalog(['kinds'=>['unknown']]),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->describe('','overview'),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->describe('missing','overview'),OutOfBoundsException::class);
	$t->throws(static fn()=>$catalog->describe('realtime','everything'),InvalidArgumentException::class);
});

test('new source domains degrade honestly instead of disappearing from planning',static function(Context $t): void {
	$source=new class implements PanelCapabilitySource {
		public function snapshot(): array {return [
			'source_strategy'=>'fixture','inventory_fingerprint'=>'future','counts'=>['domains'=>1,'areas'=>0,'framework_files'=>0,'documents'=>0,'tests'=>0,'indexed_tests'=>0,'contracts'=>0],
			'domains'=>[['id'=>'panel:domain:future_runtime','kind'=>'platform_domain','name'=>'future_runtime','prefix'=>'future','required_features'=>[],'feature_count'=>0,'required_feature_count'=>0,'service_count'=>0,'features'=>[],'services'=>[],'framework_areas'=>[]]],
			'areas'=>[],'documents'=>[],'tests'=>[],'contracts'=>[],'declarations'=>[],'diagnostics'=>[],
		];}
	};
	$catalog=new PanelCapabilityCatalog($source);
	$index=$catalog->catalog(['limit'=>200]);
	$t->same(['future_runtime'],$index['diagnostics']['missing_profiles']);
	$t->contains('operations_os',$index['diagnostics']['stale_profiles']);
	$record=McpPanelProbe::record($index,'panel:domain:future_runtime');
	$t->same('unclassified',$record['category']);
	$t->contains('awaiting explicit MCP semantics',$record['summary']);
	$t->same(1,$catalog->graph(['roots'=>['future_runtime']])['counts']['nodes']);
	$t->same(['future_runtime'],$catalog->recipe(['task'=>'use future runtime','domains'=>['future_runtime']])['selection']['domains']);
});

test('the dependency graph validates every current semantic edge and direction',static function(Context $t): void {
	$catalog=McpPanelProbe::semanticCatalog();
	$domains=McpPanelProbe::names(array_filter($catalog->catalog(['kinds'=>['platform_domain'],'limit'=>200])['records'],static fn(array $record): bool=>$record['kind']==='platform_domain'));
	foreach($domains as $domain){$t->greaterThan(0,$catalog->graph(['roots'=>[$domain],'depth'=>1,'direction'=>'dependencies'])['counts']['nodes']);}
	$both=$catalog->graph(['roots'=>['realtime'],'depth'=>2,'direction'=>'both']);
	$t->greaterThan(1,$both['counts']['nodes']);
	$t->greaterThan(0,$both['counts']['edges']);
	$t->same(1,$catalog->graph(['roots'=>['realtime'],'depth'=>0])['counts']['nodes']);
	$t->greaterThan(1,$catalog->graph(['roots'=>['realtime'],'direction'=>'dependents'])['counts']['nodes']);
	$t->throws(static fn()=>$catalog->graph([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->graph(['roots'=>['missing']]),OutOfBoundsException::class);
	$t->throws(static fn()=>$catalog->graph(['roots'=>['realtime'],'direction'=>'sideways']),InvalidArgumentException::class);
});

test('recipes infer the narrowest Panel construction lane and preserve explicit selections',static function(Context $t): void {
	$catalog=McpPanelProbe::semanticCatalog();
	$t->contains(
		'No routes, SQL, storage, Redis, HTTP, browser, package, migration, worker, or external-effect operation is performed.',
		$t->nonPublic($catalog)->invoke('negativeGuarantees')
	);
	$cases=[
		'studio'=>['task'=>'build Studio visual editor collaboration','domain'=>'studio'],
		'realtime'=>['task'=>'add Redis realtime stream transport','domain'=>'realtime'],
		'migration'=>['task'=>'roll out a restart safe migration','domain'=>'migrations'],
		'operations'=>['task'=>'run leased distributed operations','domain'=>'distributed_operations'],
		'adapter'=>['task'=>'build a custom data provider adapter','domain'=>'data'],
		'platform'=>['task'=>'compose the Panel platform services','domain'=>'platform'],
		'application'=>['task'=>'build a media CRUD resource form table relation widget dashboard schema tests','domain'=>'media'],
	];
	foreach($cases as $mode=>$case){
		$plan=$catalog->recipe(['task'=>$case['task']]);
		$t->same($mode,$plan['mode']);
		$t->contains($case['domain'],$plan['selection']['domains']);
		$t->same(5,count($plan['steps']));
		$t->same('dry_run_plan_only',$plan['write_policy']);
	}
	$explicit=$catalog->recipe(['task'=>'compose explicit domains','domains'=>['security','data','realtime','studio','media','platform','packages'],'mode'=>'platform']);
	$t->same(6,count($explicit['selection']['domains']));
	$t->same('explicit_then_validated',$explicit['selection']['selection_strategy']);
	$areas=$catalog->recipe(['task'=>'render navigation menu schema editor test','domains'=>['media'],'mode'=>'application']);
	foreach(['Rendering','Navigation','Schemas','Editors','Testing'] as $area){$t->contains($area,$areas['selection']['framework_areas']);}
	$t->throws(static fn()=>$catalog->recipe([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->recipe(['task'=>'x','mode'=>'impossible']),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->recipe(['task'=>'x','domains'=>['missing']]),OutOfBoundsException::class);
});

test('provider and topology plans expose honest host ownership for every integration family',static function(Context $t): void {
	$catalog=McpPanelProbe::semanticCatalog();
	$cases=[
		['realtime','callback','host_managed'],['realtime','pdo','shared_sql'],['realtime','redis','distributed'],
		['media','dataphyre_storage','host_managed'],['media','filesystem','local'],['media','memory','local'],['data','custom','host_managed'],['platform','auto','auto'],
	];
	foreach($cases as [$domain,$provider,$topology]){
		$plan=$catalog->integration(['domains'=>[$domain],'provider'=>$provider,'topology'=>$topology,'max_items'=>2]);
		$t->same($provider,$plan['provider']);
		$t->same($topology,$plan['topology']);
		$t->same(true,$plan['domain_plans'][0]['activation']['explicit_initialization']);
		$t->same(false,$plan['domain_plans'][0]['secret_boundary']['serialized_by_panel']);
	}
	$t->same(true,$catalog->integration(['domains'=>['realtime'],'topology'=>'distributed'])['domain_plans'][0]['persistence']['distributed_authority_must_be_single_and_declared']);
	$t->throws(static fn()=>$catalog->integration([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->integration(['domains'=>['realtime'],'provider'=>'magic']),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->integration(['domains'=>['realtime'],'topology'=>'everywhere']),InvalidArgumentException::class);
});

test('verification plans turn domains and changed paths into proof without mistaking plans for evidence',static function(Context $t): void {
	$catalog=McpPanelProbe::semanticCatalog();
	foreach(['focused'=>1,'exact'=>2,'browser'=>2,'release'=>4] as $claim=>$minimumCommands){
		$plan=$catalog->verification(['changed_paths'=>['runtime/modules/panel/Framework/Realtime/PanelRedisRealtimeAdapter.php'],'claim'=>$claim,'max_items'=>3]);
		$t->same(['realtime'],$plan['domains']);
		$t->greaterThan($minimumCommands-1,count($plan['commands']));
		$t->same('not_executed',$plan['execution']);
		$t->greaterThan(20,strlen($plan['claim_boundary']));
	}
	$task=$catalog->verification(['task'=>'verify Studio collaboration browser lifecycle','claim'=>'browser']);
	$t->contains('studio',$task['domains']);
	$t->same(true,$task['selected']['browser_required']);
	$t->throws(static fn()=>$catalog->verification([]),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->verification(['domains'=>['realtime'],'claim'=>'wishful']),InvalidArgumentException::class);
});

test('malformed platform manifests fail closed with named diagnostics',static function(Context $t): void {
	$fixture=new McpPanelFixture($t);
	$cases=[
		'empty'=>'<?php $catalogue=[];',
		'unsupported constant'=>'<?php $catalogue=[SOME_CONSTANT=>[]];',
		'non literal key'=>'<?php $catalogue=[true=>[]];',
		'unclosed array'=>'<?php $catalogue=[\'alpha\'=>[\'prefix\'=>\'alpha\'];',
		'non string concatenation'=>'<?php $catalogue=[\'alpha\'=>[\'prefix\'=>\'alpha\'.1]];',
		'invalid unary minus'=>'<?php $catalogue=[\'alpha\'=>[\'prefix\'=>-\'alpha\']];',
		'missing separator'=>'<?php $catalogue=[\'alpha\'=>[\'prefix\'=>\'alpha\' \'required\'=>[]]];',
	];
	foreach($cases as $contract=>$source){
		$fixture->manifest($source);
		$diagnostics=$fixture->source()->snapshot()['diagnostics'];
		$t->greaterThan(0,count($diagnostics['platform_parse_failures']));
		$t->same(false,$diagnostics['platform_catalogue_parsed'],$contract);
	}
	unlink($fixture->path('runtime/modules/panel/Framework/Platform/PanelPlatformManifest.php'));
	$missing=$fixture->source()->snapshot()['diagnostics'];
	$t->same(false,$missing['platform_manifest_present']);
	$t->contains('platform_manifest_missing',$missing['platform_parse_failures']);
});

test('the public MCP protocol exposes Panel discovery, planning, resources, prompts, and skills together',static function(Context $t): void {
	$requests=[
		McpScenario::request('resource','resources/read',['uri'=>'dataphyre://panel']),
		McpScenario::request('catalog','tools/call',['name'=>'dataphyre_panel_capability_catalog','arguments'=>['kinds'=>['platform_domain'],'query'=>'realtime','limit'=>5]]),
		McpScenario::request('describe','tools/call',['name'=>'dataphyre_panel_capability_describe','arguments'=>['id'=>'panel:domain:realtime','view'=>'all','max_items'=>3]]),
		McpScenario::request('graph','tools/call',['name'=>'dataphyre_panel_surface_graph','arguments'=>['roots'=>['realtime'],'direction'=>'both','depth'=>1]]),
		McpScenario::request('recipe','tools/call',['name'=>'dataphyre_panel_recipe_plan','arguments'=>['task'=>'build a Redis realtime adapter','mode'=>'auto']]),
		McpScenario::request('integration','tools/call',['name'=>'dataphyre_panel_integration_plan','arguments'=>['domains'=>['realtime'],'provider'=>'redis','topology'=>'distributed']]),
		McpScenario::request('verification','tools/call',['name'=>'dataphyre_panel_verification_plan','arguments'=>['domains'=>['realtime'],'claim'=>'exact']]),
		McpScenario::request('manifest','tools/call',['name'=>'dataphyre_mcp_manifest_export','arguments'=>['include_schemas'=>false]]),
		McpScenario::request('readiness','tools/call',['name'=>'dataphyre_mcp_readiness_report','arguments'=>[]]),
		McpScenario::request('skills','tools/call',['name'=>'dataphyre_mcp_skill_catalog','arguments'=>['names'=>['dataphyre-panel-builder']]]),
	];
	foreach(['dataphyre_panel_workflow','dataphyre_panel_platform_workflow','dataphyre_panel_operations_workflow','dataphyre_panel_studio_workflow','dataphyre_panel_realtime_workflow','dataphyre_panel_adapter_workflow'] as $prompt){$requests[]=McpScenario::request('prompt:'.$prompt,'prompts/get',['name'=>$prompt]);}
	$transcript=(new McpScenario($t))->usingOrdinaryPhpForSourceIntrospection()->exchange($requests,180000);
	$resource=json_decode($transcript->result('resource')['contents'][0]['text'],true,512,JSON_THROW_ON_ERROR);
	$t->same('dataphyre_panel_capability_index',$resource['resource_type']);
	$t->same(25,$resource['counts']['domains']);
	$t->same('realtime',$transcript->toolPayload('catalog')['records'][0]['name']);
	$t->same('found',$transcript->toolPayload('describe')['status']);
	$t->greaterThan(1,$transcript->toolPayload('graph')['counts']['nodes']);
	$t->same('realtime',$transcript->toolPayload('recipe')['mode']);
	$t->same('redis',$transcript->toolPayload('integration')['provider']);
	$t->same('exact',$transcript->toolPayload('verification')['claim']);
	$manifest=$transcript->toolPayload('manifest');
	$t->same('2.2.0',$manifest['version']);
	$t->same(6,$manifest['tool_groups']['panel_intelligence']['count']);
	$t->same(true,$transcript->toolPayload('readiness')['agentic_capability_coverage']['panel_capability_intelligence']['ready']);
	$t->same('dataphyre-panel-builder',$transcript->toolPayload('skills')['skills'][0]['name']);
	foreach(array_slice($requests,10) as $request){$t->contains('dataphyre_panel_', $transcript->result($request['id'])['messages'][0]['content']['text']);}
});
