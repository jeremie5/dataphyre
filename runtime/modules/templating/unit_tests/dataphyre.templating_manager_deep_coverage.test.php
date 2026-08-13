<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Templating\AssetPolicy;
use Dataphyre\Templating\BindingCacheIdentityProvider;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\BindingMetadataProvider;
use Dataphyre\Templating\BindingPersistentCacheProvider;
use Dataphyre\Templating\BindingResolution;
use Dataphyre\Templating\DataBinding;
use Dataphyre\Templating\SearchQueryBinding;
use Dataphyre\Templating\SqlQueryBinding;
use Dataphyre\Templating\TemplateContract;
use Dataphyre\Templating\TemplateView;
use Dataphyre\Templating\TemplatingManager;
use Dataphyre\Test\Context;
use Dataphyre\Test\NonPublicAccess;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',['enabled'=>['core'=>true,'templating'=>true],'disabled'=>[],'core_implicit'=>true]);
}
$dpTemplatingManagerModulesRoot=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dpTemplatingManagerModulesRoot.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dpTemplatingManagerModulesRoot);
\dataphyre\autoloader::register_framework_modules(['core','templating']);
require_once $dpTemplatingManagerModulesRoot.'/templating/unit_tests/templating_render_test_helpers.php';
require_once __DIR__.'/fixtures/templating_manager_probes.php';

final class DpTemplatingBareBinding implements DataBinding {
	public int $calls=0;
	public function __construct(private string $bindingName='bare',private mixed $resolved='bare-value',private bool $throws=false) {}
	public function name(): string { return $this->bindingName; }
	public function resolve(BindingContext $context): mixed {
		$this->calls++;
		if($this->throws){ throw new RuntimeException('binding failed'); }
		return $this->resolved;
	}
}

final class DpTemplatingFullBinding implements BindingMetadataProvider,BindingCacheIdentityProvider,BindingPersistentCacheProvider {
	public int $calls=0;
	public function __construct(
		private string $bindingName='full',
		private mixed $resolved='full-value',
		private mixed $identity=['id'=>1],
		private mixed $persistent=['ttl'=>60,'names'=>['group'],'identity'=>['id'=>1]],
		private array $bindingMetadata=[],
		private bool $throws=false,
	) {}
	public function name(): string { return $this->bindingName; }
	public function resolve(BindingContext $context): mixed {
		$this->calls++;
		if($this->throws){ throw new RuntimeException('full binding failed'); }
		return $this->resolved;
	}
	public function metadata(): array { return $this->bindingMetadata; }
	public function cacheIdentity(BindingContext $context): mixed { return $this->identity; }
	public function persistentCache(BindingContext $context): ?array { return is_array($this->persistent) ? $this->persistent : null; }
}

final class DpTemplatingToArray {
	public function __construct(private mixed $value) {}
	public function toArray(): mixed { return $this->value; }
}
final class DpTemplatingJson implements JsonSerializable {
	public function __construct(private mixed $value) {}
	public function jsonSerialize(): mixed { return $this->value; }
}
final class DpTemplatingStringable implements Stringable {
	public function __construct(private string $value='identity') {}
	public function __toString(): string { return $this->value; }
}
final class DpTemplatingExplosiveUnserialize {
	public function __serialize(): array { return []; }
	public function __unserialize(array $data): void { throw new RuntimeException('unserialize failure'); }
}

/** @return array{0:TempWorkspace,1:string,2:TemplatingManager,3:NonPublicAccess,4:TestState} */
function dp_templating_manager_scenario(Context $t,string $name): array {
	$state=$t->state('templating.manager',[
		'random_failure'=>false,
		'json_failures'=>0,
		'glob_failure'=>false,
		'database_trace_contexts'=>[],
	]);
	$workspace=$t->workspace('templating-manager-'.$name);
	$cache=rtrim($workspace->directory('cache'),'/\\').DIRECTORY_SEPARATOR;
	\dataphyre\templating::init(false,$cache,false,['scripts'=>['defer'=>true],'styles'=>['media'=>'all']]);
	TemplatingManager::flush();
	\Dataphyre\Runtime::$tracing=true;
	$manager=TemplatingManager::instance();
	return [$workspace,$cache,$manager,$t->nonPublic($manager),$state];
}

function dp_templating_manager_fixture_file(TempWorkspace $workspace,string $path,string $contents): string {
	$root=rtrim(str_replace('\\','/',$workspace->root()),'/').'/';
	$path=str_replace('\\','/',$path);
	if(!str_starts_with($path,$root)){
		throw new InvalidArgumentException('Templating manager fixture path is outside its workspace: '.$path);
	}
	return $workspace->file(substr($path,strlen($root)),$contents);
}

test('templating manager deep coverage closes query component async and state override public branches',static function(Context $t): void {
	[$workspace,$cache,$manager,$managerInternals,$state]=dp_templating_manager_scenario($t,'public-branches');
	$template=$workspace->file('cache/component.tpl','Hello {{name}}');
	$t->instanceOf(SqlQueryBinding::class,$manager->queryBindingInheritingIdentity(new \Dataphyre\Database\RepositoryQuery()));
	$t->instanceOf(SearchQueryBinding::class,$manager->searchBindingInheritingIdentity(new \Dataphyre\FulltextEngine\Query()));
	$t->instanceOf(TemplateView::class,$manager->component($template));
	$t->instanceOf(\dataphyre\async\promise::class,$manager->asyncRender($template,['name'=>'Async']));
	$inline=$manager->asyncRenderString('Inline {{name}}',['name'=>'Async']);
	$t->instanceOf(\dataphyre\async\promise::class,$inline);
	$t->same(null,$inline->reason);
	$t->notEmpty($inline->value);
	$state->put('json_failures',1);
	$rejected=$manager->asyncRenderString('Inline {{name}}',['name'=>'Async']);
	$t->notEmpty($rejected->reason);
	$filtered=$managerInternals->invokeWithArguments('filterStateOverrides',[[
		'is_dev_mode'=>1,'cache_dir'=>' '.$cache.' ','global_context'=>['a'=>1],'strict_mode'=>1,
		'asset_policy'=>AssetPolicy::fromArray(['scripts'=>['defer'=>true]]),
		'template_contracts'=>['one'=>TemplateContract::define(['name']),'two'=>['required'=>['id']],'bad'=>'invalid'],
		'binding_guardrails'=>['enabled'=>true],'unknown'=>'drop',
	]]);
	$t->same($cache,rtrim($filtered['cache_dir'],'/\\').DIRECTORY_SEPARATOR);
	$t->same([],$filtered['template_contracts']['bad']);
	$filteredArrayPolicy=$managerInternals->invokeWithArguments('filterStateOverrides',[['asset_policy'=>['styles'=>[]],'binding_guardrails'=>false,'cache_dir'=>' ','global_context'=>'bad','template_contracts'=>'bad']]);
	$t->hasKey('asset_policy',$filteredArrayPolicy);
	$t->isFalse($filteredArrayPolicy['binding_guardrails']);
})->tag('templating','manager','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('templating manager deep coverage normalizes render and persistent cache descriptors and identity values',static function(Context $t): void {
	[$workspace,$cache,$manager,$managerInternals]=dp_templating_manager_scenario($t,'cache-descriptors');
	$context=new BindingContext('deep.tpl',true,[],[],[],['cache_dir'=>$cache,'is_dev_mode'=>false],['render_trace_id'=>'trace']);
	$devContext=new BindingContext('deep.tpl',true,[],[],[],['cache_dir'=>$cache,'is_dev_mode'=>true]);
	$bare=new DpTemplatingBareBinding();
	$t->isFalse($managerInternals->invokeWithArguments('bindingCacheDescriptor',[$bare,$context])['cacheable']);
	foreach([null,' ',fopen('php://temp','r+')] as $identity){
		$binding=new DpTemplatingFullBinding(identity:$identity,persistent:null);
		$descriptor=$managerInternals->invokeWithArguments('bindingCacheDescriptor',[$binding,$context]);
		if($identity===null || $identity===' '){ $t->isFalse($descriptor['cacheable']); } else { $t->isTrue($descriptor['cacheable']); }
		if(is_resource($identity)){ fclose($identity); }
	}
	$render=$managerInternals->invokeWithArguments('bindingCacheDescriptor',[new DpTemplatingFullBinding(identity:['z'=>1,'a'=>2]),$context]);
	$t->isTrue($render['cacheable']);
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[$bare,$context,$render])['cacheable']);
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[new DpTemplatingFullBinding(persistent:['ttl'=>60]),$devContext,$render])['cacheable']);
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[new DpTemplatingFullBinding(persistent:null),$context,$render])['cacheable']);
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[new DpTemplatingFullBinding(persistent:['ttl'=>0,'identity'=>['id'=>1]]),$context,$render])['cacheable']);
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[new DpTemplatingFullBinding(identity:null,persistent:['ttl'=>60]),$context,[]])['cacheable']);
	$persistent=$managerInternals->invokeWithArguments('bindingPersistentCacheDescriptor',[new DpTemplatingFullBinding(persistent:['ttl'=>60,'names'=>' group ','identity'=>['id'=>1]]),$context,$render]);
	$t->isTrue($persistent['cacheable']);
	$t->same(['group'],$persistent['cache_names']);
	$t->hasKey('cache_key',$managerInternals->invokeWithArguments('bindingCacheMetadata',[$render,$persistent,'persistent','hit',['cache_layer'=>'persistent']]));
	$t->hasKey('cache_key',$managerInternals->invokeWithArguments('bindingCacheMetadata',[$render,[],'render','miss']));

	foreach([
		null,' ','key',true,4,4.5,['b'=>2,'a'=>1],new DpTemplatingToArray(['b'=>2,'a'=>1]),new DpTemplatingToArray('bad'),
		new DpTemplatingJson(['b'=>2,'a'=>1]),new DpTemplatingJson('scalar'),new DpTemplatingJson(new stdClass()),new DpTemplatingStringable(),new stdClass(),
	] as $identity){
		$managerInternals->invokeWithArguments('normalizeBindingCacheIdentity',[$identity]);
		$managerInternals->invokeWithArguments('normalizeBindingCacheValue',[$identity]);
	}
	$resource=fopen('php://temp','r+');
	$t->same('resource (stream)',$managerInternals->invokeWithArguments('normalizeBindingCacheValue',[$resource]));
	fclose($resource);
	$t->same(['a'=>1,'b'=>2],$managerInternals->invokeWithArguments('normalizeBindingCacheArray',[['b'=>2,'a'=>1]]));
	$t->same(['one','two'],$managerInternals->invokeWithArguments('normalizeBindingCacheNames',[[1,' one ','','two','one']]));
	$t->same(['one'],$managerInternals->invokeWithArguments('normalizeBindingCacheNames',[' one ']));
	$t->isFalse($managerInternals->invokeWithArguments('bindingPersistentCacheEnabled',[$devContext]));
	$t->isTrue($managerInternals->invokeWithArguments('bindingPersistentCacheEnabled',[$context]));
})->tag('templating','manager','deep-coverage')->group('framework-coverage');

test('templating manager deep coverage resolves nested skipped failed render cached and persistent bindings',static function(Context $t): void {
	[$workspace,$cache,$manager,$managerInternals,$state]=dp_templating_manager_scenario($t,'binding-resolution');
	$overrides=['cache_dir'=>$cache,'is_dev_mode'=>false];
	$sharedOne=new DpTemplatingFullBinding('shared-one','shared',['shared'=>1],null);
	$sharedTwo=new DpTemplatingFullBinding('shared-two','unused',['shared'=>1],null);
	$skipped=new DpTemplatingBareBinding('skipped',BindingResolution::skipped('fallback'));
	$failed=new DpTemplatingBareBinding('failed',null,true);
	$nested=new DpTemplatingBareBinding('nested',['child'=>new DpTemplatingBareBinding('child','child-value')]);
	$prepared=$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,[
		'one'=>$sharedOne,'two'=>$sharedTwo,'skip'=>$skipped,'fail'=>$failed,'nested'=>$nested,
	],[],[],$overrides]);
	$t->same('shared',$prepared['data']['one']);
	$t->same('shared',$prepared['data']['two']);
	$t->same('fallback',$prepared['data']['skip']);
	$t->same(null,$prepared['data']['fail']);
	$t->same('child-value',$prepared['data']['nested']['child']);
	$t->same(1,$sharedOne->calls);
	$t->same(0,$sharedTwo->calls);

	$persistent=new DpTemplatingFullBinding('persistent','stored',['persistent'=>1],['ttl'=>60,'names'=>['users'],'identity'=>['persistent'=>1]]);
	$first=$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,['value'=>$persistent],[],[],$overrides]);
	$second=$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,['value'=>$persistent],[],[],$overrides]);
	$t->same('stored',$first['data']['value']);
	$t->same('stored',$second['data']['value']);
	$t->same(1,$persistent->calls);

	$closureBinding=new DpTemplatingFullBinding('closure',static fn()=>true,['closure'=>1],['ttl'=>60,'names'=>['bad'],'identity'=>['closure'=>1]]);
	$closureResult=$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,['closure'=>$closureBinding],[],[],$overrides]);
	$t->instanceOf(Closure::class,$closureResult['data']['closure']);

	$sqlBinding=new DpTemplatingFullBinding('sql-traced','sql-value',['sql'=>1],null,['driver'=>'sql','query_fingerprint'=>'fingerprint']);
	$t->same('sql-value',$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,['sql'=>$sqlBinding],[],[],$overrides])['data']['sql']);
	$t->notEmpty($state->get('database_trace_contexts'));

	\Dataphyre\Runtime::$tracing=false;
	$plain=$managerInternals->invokeWithArguments('prepareBindingData',['deep.tpl',true,['plain'=>new DpTemplatingBareBinding('plain','value')],[],[],$overrides]);
	$t->same(null,$plain['render_trace_id']);
})->tag('templating','manager','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('templating manager deep coverage exercises persistent cache corruption storage indexing and clearing paths',static function(Context $t): void {
	[$workspace,$cache,$manager,$managerInternals,$state]=dp_templating_manager_scenario($t,'persistent-cache');
	$context=new BindingContext('cache.tpl',false,[],[],[],['cache_dir'=>$cache,'is_dev_mode'=>false]);
	$descriptor=['cacheable'=>true,'cache_key'=>'key-one','cache_ttl'=>60,'cache_names'=>['group']];
	$t->same(['hit'=>false],$managerInternals->invokeWithArguments('loadPersistentBindingValue',[['cacheable'=>false],$context]));
	$t->same(['hit'=>false],$managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context]));
	$root=$managerInternals->invokeWithArguments('bindingPersistentCacheRoot',[$context]);
	$item=$managerInternals->invokeWithArguments('bindingPersistentCacheItemFile',['key-one',$root]);
	dp_templating_manager_fixture_file($workspace,$item,'');
	$t->isFalse($managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['hit']);
	dp_templating_manager_fixture_file($workspace,$item,'not serialized');
	$t->isFalse($managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['hit']);
	dp_templating_manager_fixture_file($workspace,$item,serialize(new DpTemplatingExplosiveUnserialize()));
	$t->isFalse($managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['hit']);
	dp_templating_manager_fixture_file($workspace,$item,serialize('scalar'));
	$t->isFalse($managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['hit']);
	dp_templating_manager_fixture_file($workspace,$item,serialize(['expires_at'=>time()-1,'value'=>'old']));
	$t->isFalse($managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['hit']);
	dp_templating_manager_fixture_file($workspace,$item,serialize(['stored_at'=>10,'expires_at'=>time()+60,'value'=>'fresh']));
	$t->same('fresh',$managerInternals->invokeWithArguments('loadPersistentBindingValue',[$descriptor,$context])['value']);
	$t->same(null,$managerInternals->invokeWithArguments('storePersistentBindingValue',[['cacheable'=>false],$context,'value']));
	$t->same(null,$managerInternals->invokeWithArguments('storePersistentBindingValue',[$descriptor,$context,'stored']));
	$t->notEmpty($managerInternals->invokeWithArguments('storePersistentBindingValue',[$descriptor,$context,static fn()=>true]));
	$blocked=$workspace->file('cache/blocked','file');
	$blockedContext=new BindingContext('blocked.tpl',false,[],[],[],['cache_dir'=>$blocked,'is_dev_mode'=>false]);
	$t->same('Unable to write persistent binding cache.',$managerInternals->invokeWithArguments('storePersistentBindingValue',[$descriptor,$blockedContext,'value']));

	$namesDir=$root.'names'.DIRECTORY_SEPARATOR;
	$nameFile=$managerInternals->invokeWithArguments('bindingPersistentCacheNameFile',['group',$namesDir]);
	dp_templating_manager_fixture_file($workspace,$nameFile,'invalid');
	$managerInternals->invokeWithArguments('indexPersistentBindingCacheName',['group','key-one',$namesDir]);
	$managerInternals->invokeWithArguments('indexPersistentBindingCacheName',['group','key-one',$namesDir]);
	$t->isTrue(is_file($nameFile));
	dp_templating_manager_fixture_file($workspace,$nameFile,json_encode(['',2,'key-one']));
	$t->isTrue($manager->clearBindingCache('group')>=1);
	$t->isTrue($manager->clearBindingCache()>=0);

	$items=rtrim($workspace->directory('cache/manual/items'),'/\\').DIRECTORY_SEPARATOR;
	$names=rtrim($workspace->directory('cache/manual/names'),'/\\').DIRECTORY_SEPARATOR;
	$workspace->file('cache/manual/items/one.cache','x');
	$workspace->file('cache/manual/names/one.json','[]');
	$t->same(2,$managerInternals->invokeWithArguments('clearPersistentBindingCacheDirectories',[$items,$names]));
	$items=rtrim($workspace->directory('cache/manual/items'),'/\\').DIRECTORY_SEPARATOR;
	$state->put('glob_failure',true);
	$t->same(0,$managerInternals->invokeWithArguments('clearPersistentBindingCacheDirectories',[$items,$names]));
	$state->put('glob_failure',false);
})->tag('templating','manager','deep-coverage')->group('framework-coverage');

test('templating manager deep coverage covers binding manifests warnings planners traces and duplicate targets',static function(Context $t): void {
	[$workspace,$cache,$manager,$managerInternals,$state]=dp_templating_manager_scenario($t,'binding-manifests');
	$bindings=[
		['path'=>'users','binding'=>'users-one','ok'=>true,'skipped'=>false,'duration_ms'=>75,'type'=>'sql_query','driver'=>'sql','query_target_type'=>'table','query_target'=>'users','query_mode'=>'rows','query_fingerprint'=>'fp','query_identity_source'=>'execution_state','render_trace_id'=>'render-1','binding_trace_id'=>'render-1.b0001','trace'=>['path'=>'users']],
		['path'=>'unused','binding'=>'users-two','ok'=>true,'skipped'=>false,'duration_ms'=>1,'type'=>'sql_query','driver'=>'sql','query_target_type'=>'table','query_target'=>'users','query_mode'=>'count','query_fingerprint'=>'fp2','query_identity_source'=>'execution_state','render_trace_id'=>'render-1'],
		['path'=>'skip','binding'=>'skip','ok'=>true,'skipped'=>true,'type'=>'sql_query'],
		['path'=>'bad','binding'=>'bad','ok'=>false,'error'=>['message'=>'bad']],
	];
	$plan=['aggregate'=>['data_paths'=>['users.name','']]];
	$t->same([],$managerInternals->invokeWithArguments('bindingWarningsForPlan',[$plan,[],[]]));
	$t->same([],$managerInternals->invokeWithArguments('bindingWarningsForPlan',[$plan,$bindings,['binding_guardrails'=>false]]));
	$warnings=$managerInternals->invokeWithArguments('bindingWarningsForPlan',[$plan,$bindings,['binding_guardrails'=>['slow_ms'=>10]]]);
	$t->notEmpty($warnings);
	$t->notEmpty($managerInternals->invokeWithArguments('bindingPlannerForPlan',[$plan,$bindings]));
	$t->same([],$managerInternals->invokeWithArguments('bindingPlannerForPlan',[$plan,[['path'=>'users','binding'=>'no-fingerprint','ok'=>true,'skipped'=>false,'type'=>'sql_query','query_fingerprint'=>'','query_identity_source'=>'execution_state']]]));
	$t->same([],$managerInternals->invokeWithArguments('bindingPlannerForPlan',[$plan,[]]));
	$manifest=$managerInternals->invokeWithArguments('mergeBindingManifest',[[],$bindings,$warnings,[],'render-1']);
	$t->notEmpty($manifest['binding_trace']);
	$t->notEmpty($manifest['binding_errors']);
	$t->same('render-1',$managerInternals->invokeWithArguments('firstBindingRenderTraceId',[$bindings]));
	$t->same(null,$managerInternals->invokeWithArguments('firstBindingRenderTraceId',[[['render_trace_id'=>' ']]]));

	$t->same(null,$managerInternals->invokeWithArguments('bindingTraceIdentity',[[]]));
	$t->notEmpty($managerInternals->invokeWithArguments('bindingTraceIdentity',[['query_fingerprint'=>'fp','query_identity_requested'=>true,'query_identity_available'=>true]]));
	$t->same(null,$managerInternals->invokeWithArguments('traceString',[1]));
	$t->same(null,$managerInternals->invokeWithArguments('traceString',[' ']));
	$t->same('x',$managerInternals->invokeWithArguments('traceString',[' x ']));
	$t->notEmpty($managerInternals->invokeWithArguments('bindingTracePayload',[$bindings[0]+['cache_names'=>['users'],'query_cache_names'=>['users'],'cacheable'=>true,'persistent_cache'=>true,'cache_scope'=>'persistent','cache_state'=>'hit']]));
	$t->hasKey('trace',$managerInternals->invokeWithArguments('stitchBindingTrace',[$bindings[0]]));
	\Dataphyre\Runtime::$tracing=false;
	$t->isFalse(array_key_exists('render_trace_id',$managerInternals->invokeWithArguments('stitchBindingTrace',[$bindings[0]])));
	$t->same(null,$managerInternals->invokeWithArguments('mergeBindingManifest',[[],$bindings,[],[],null])['render_trace_id']);
	\Dataphyre\Runtime::$tracing=true;

	$traceState=['enabled'=>true,'sequence'=>0,'render_trace_id'=>'render'];
	$traceCall=$managerInternals->capture('nextBindingTraceId',traceState:$traceState);
	$t->same('render.b0001',$traceCall->result());
	$t->same(1,$traceCall->argument('traceState')['sequence']);
	$t->isTrue(str_starts_with($managerInternals->invokeWithArguments('newTraceId',[' ']),'trace_'));
	$state->put('random_failure',true);
	$t->isTrue(str_starts_with($managerInternals->invokeWithArguments('newTraceId',['fallback']),'fallback_'));
	$state->put('random_failure',false);

	$t->same(['users.name'],$managerInternals->invokeWithArguments('planDataPaths',[$plan]));
	$t->same(['x'],$managerInternals->invokeWithArguments('planDataPaths',[['data_paths'=>['x','']]]));
	$t->same([],$managerInternals->invokeWithArguments('planDataPaths',[['data_paths'=>'bad']]));
	$t->isTrue($managerInternals->invokeWithArguments('bindingPathIsUsed',['users',['users']]));
	$t->isTrue($managerInternals->invokeWithArguments('bindingPathIsUsed',['users',['users.name']]));
	$t->isTrue($managerInternals->invokeWithArguments('bindingPathIsUsed',['users.name',['users']]));
	$t->isFalse($managerInternals->invokeWithArguments('bindingPathIsUsed',['users',['','other']]));
	$duplicates=$managerInternals->invokeWithArguments('duplicateBindingTargets',[$bindings]);
	$t->notEmpty($duplicates);
	$t->same([],$managerInternals->invokeWithArguments('duplicateBindingTargets',[[['type'=>'','query_target'=>''],['type'=>'sql_query','query_target'=>'one']]]));

	$t->pathEquals('enabled',false,$managerInternals->invokeWithArguments('resolvedBindingGuardrails',[['binding_guardrails'=>false]]));
	$t->pathEquals('enabled',true,$managerInternals->invokeWithArguments('resolvedBindingGuardrails',[['binding_guardrails'=>true]]));
	$t->pathEquals('slow_ms',0.0,$managerInternals->invokeWithArguments('resolvedBindingGuardrails',[['binding_guardrails'=>['slow_ms'=>-1,'enabled'=>false,'warn_slow'=>false,'warn_unused'=>false,'warn_duplicate_targets'=>false]]]));
})->tag('templating','manager','deep-coverage')->group('framework-coverage');
