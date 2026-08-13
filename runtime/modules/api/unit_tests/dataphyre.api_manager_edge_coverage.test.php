<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Api\ApiContext;
use Dataphyre\Api\ApiManager;
use Dataphyre\Api\Endpoint;
use Dataphyre\Api\SecurityScheme;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Test\Context;
use Dataphyre\Test\TempWorkspace;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('API manager edge coverage')->sandboxesRootpath('api_cache');

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>[
			'access'=>true,
			'api'=>true,
			'core'=>true,
			'fulltext_engine'=>true,
			'http'=>true,
			'routing'=>true,
			'sanitation'=>true,
			'sql'=>true,
			'templating'=>true,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

if(!class_exists('Dataphyre\\Access\\Auth',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Access;
final class Auth {
	public static function check(string $guard): bool {
		$passing=\Dataphyre\Test\TestState::channel('api.manager.edge')->get('auth_passing',['session']);
		return is_array($passing) && in_array($guard,$passing,true);
	}
	public static function shouldUse(string $guard): void {
		\Dataphyre\Test\TestState::channel('api.manager.edge')->put('auth_selected',$guard);
	}
}
PHP);
}

if(!class_exists('dataphyre\\sql',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class sql {
	public static function clear_last_query_error(): void {}
	public static function hydrate_missing_structure_from_definition(string $table): bool { return false; }
	public static function invalidate_cache(string $table): void {}
	public static function add_observer(callable $observer): void {}
}
PHP);
}

if(!function_exists('Dataphyre\\Api\\random_bytes')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Api;
function random_bytes(int $length): string {
	if(\Dataphyre\Test\TestState::channel('api.manager.edge')->get('random_bytes_failure',false)===true){
		throw new \RuntimeException('entropy unavailable');
	}
	return \random_bytes($length);
}
function api_dialback(string $event,mixed ...$arguments): mixed {
	$result=null;
	$callbacks=\Dataphyre\Test\TestState::channel('api.manager.edge')->get('dialback:'.$event,[]);
	foreach(is_array($callbacks) ? $callbacks : [] as $callback){
		$result=$callback(...$arguments);
	}
	return $result;
}
function class_exists(string $class,bool $autoload=true): bool {
	$class=ltrim($class,'\\');
	$missing=\Dataphyre\Test\TestState::channel('api.manager.edge')->get('missing_classes',[]);
	if(is_array($missing) && in_array($class,$missing,true)){
		return false;
	}
	return \class_exists($class,$autoload);
}
function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
	if(\Dataphyre\Test\TestState::channel('api.manager.edge')->get('write_failure',false)===true && str_ends_with($filename,'.cache')){
		return false;
	}
	return $context===null
		? \file_put_contents($filename,$data,$flags)
		: \file_put_contents($filename,$data,$flags,$context);
}
function glob(string $pattern,int $flags=0): array|false {
	if(\Dataphyre\Test\TestState::channel('api.manager.edge')->get('glob_failure',false)===true){
		return false;
	}
	return \glob($pattern,$flags);
}
function is_dir(string $path): bool {
	if(\Dataphyre\Test\TestState::channel('api.manager.edge')->get('force_missing_names_directory',false)===true
		&& str_ends_with(str_replace('\\','/',$path),'/names/')){
		return false;
	}
	return \is_dir($path);
}
PHP);
}

$dp_api_edge_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules';
require_once $dp_api_edge_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_api_edge_modules_root);
\dataphyre\autoloader::register_framework_modules([
	'access','api','core','fulltext_engine','http','routing','sanitation','sql','templating',
]);
if(!class_exists(\dataphyre\sanitation::class,false)){
	require_once $dp_api_edge_modules_root.'/sanitation/unit_tests/sanitation_test_helpers.php';
}

function dp_api_edge_scenario(Context $t): TestState {
	return $t->state('api.manager.edge',[
		'auth_passing'=>['session'],
		'auth_selected'=>null,
		'random_bytes_failure'=>false,
		'missing_classes'=>[],
		'write_failure'=>false,
		'glob_failure'=>false,
		'force_missing_names_directory'=>false,
		'target_calls'=>0,
	]);
}

/** @param array<string,mixed> $manifest */
function dp_api_edge_manifest(TempWorkspace $workspace,string $relative,array $manifest): string {
	return $workspace->file($relative,"<?php\nreturn ".var_export($manifest,true).";\n");
}

final class DpApiEdgeTarget {
	public static function handle(ApiContext $context): array {
		$calls=\Dataphyre\Test\TestState::channel('api.manager.edge')->increment('target_calls');
		return ['ok'=>true,'id'=>$context->parameters('id'),'calls'=>$calls];
	}
	public static function responseBefore(ApiContext $context): Response {
		return Response::json(['before'=>true],202);
	}
	public static function nullHook(ApiContext $context): null {
		return null;
	}
	public static function skippedBinding(ApiContext $context): \Dataphyre\Templating\BindingResolution {
		return \Dataphyre\Templating\BindingResolution::skipped(['skipped'=>true]);
	}
	public static function authorize(mixed $credentials): mixed {
		return match($credentials){
			'response' => Response::json(['denied'=>true],418),
			'true' => true,
			default => ['authorized'=>false,'status'=>403,'message'=>'Denied by edge resolver.'],
		};
	}
	public static function variadic(mixed ...$arguments): int { return count($arguments); }
	public static function zero(): string { return 'zero'; }
	public static function throwRuntime(ApiContext $context): never { throw new RuntimeException('edge runtime failure'); }
	public static function throwSanitization(ApiContext $context): never {
		$result=new \Dataphyre\Sanitation\SanitizationResult([],['name'=>'invalid'],[]);
		throw new \Dataphyre\Sanitation\SanitizationException($result,[],'edge validation failure');
	}
	public function instance(ApiContext $context): string { return 'instance'; }
}

final class DpApiEdgeBinding implements \Dataphyre\Templating\DataBinding {
	public int $calls=0;
	public function __construct(private readonly mixed $value){}
	public function name(): string { return 'edge.binding'; }
	public function resolve(\Dataphyre\Templating\BindingContext $context): mixed {
		$this->calls++;
		return $this->value;
	}
}

final class DpApiEdgeRepository extends \Dataphyre\Database\TableRepository {
	protected static function table(): string { return 'edge_rows'; }
}

final class DpApiEdgeSerializeFailure {
	public function __serialize(): array { throw new RuntimeException('serialize failed'); }
}

final class DpApiEdgeUnserializeFailure {
	public function __serialize(): array { return []; }
	public function __unserialize(array $data): void { throw new RuntimeException('unserialize failed'); }
}

final class DpApiEdgeCacheFixture {
	public static function writeRawEntry(string $file,string $contents): void {
		if(\file_put_contents($file,$contents)===false){
			throw new RuntimeException('Unable to write API cache fixture: '.$file);
		}
	}

	public static function writeSerializedEntry(string $file,mixed $value): void {
		self::writeRawEntry($file,serialize($value));
	}

	/** @param list<mixed> $keys */
	public static function writeNameIndex(string $file,array $keys): void {
		self::writeRawEntry($file,(string)json_encode($keys));
	}
}

test('api manager edge dispatch covers application routes authorization aliases and exceptions',static function(Context $t): void {
	$state=dp_api_edge_scenario($t);
	$manager=ApiManager::instance();
	$private=$t->nonPublic($manager);
	$workspace=$t->workspace('api-manager-edge');
	$root=$workspace->root().DIRECTORY_SEPARATOR;
	$applicationRoot=$workspace->directory('applications/edge-app');
	$manifestRelative='applications/edge-app/routes.compiled.php';
	$route=Endpoint::get('/edge/{id}')
		->alias('edge.show')
		->execute(DpApiEdgeTarget::class.'::handle')
		->compile();
	$manifestFile=dp_api_edge_manifest($workspace,$manifestRelative,['version'=>1,'metadata'=>[],'routes'=>[$route]]);
	$definition=new \dataphyre\application_definition('edge_app',$applicationRoot,null,null,$manifestFile);
	$runtime=$t->nonPublic(\dataphyre\runtime::class);
	$runtime
		->replacePropertyForTest('current_application_definition',$definition)
		->replacePropertyForTest('current_project_root',$root);

	$success=$manager->dispatch(['path'=>'/edge/7','method'=>'GET','body'=>['edge'=>true]]);
	$t->isTrue($success['ok']);
	$t->same(7,(int)$success['json']['id']);
	$trusted=$manager->dispatch(['path'=>'/edge/8'],[
		'trust_auth'=>true,
		'auth'=>['authorized'=>true,'scheme'=>'trusted'],
	]);
	$t->isTrue($trusted['ok']);

	$aliasBatch=$manager->dispatchBatch([
		'edge.show'=>['route'=>['id'=>9]],
	],['continue_on_error'=>false]);
	$t->isTrue($aliasBatch['ok']);
	$pathBatch=$manager->dispatchBatch([
		'/edge/10'=>[],
	],['continue_on_error'=>false]);
	$t->isTrue($pathBatch['ok']);
	$stopped=$manager->dispatchBatch([
		['path'=>'/missing'],
		['path'=>'/edge/11'],
	],['continue_on_error'=>false]);
	$t->same(1,$stopped['count']);

	$scheme=SecurityScheme::apiKey('edgeKey','X-Edge-Key','header',[
		'resolver'=>[DpApiEdgeTarget::class,'authorize'],
	]);
	$secured=Endpoint::get('/secure')
		->auth($scheme)
		->execute(DpApiEdgeTarget::class.'::handle')
		->compile();
	dp_api_edge_manifest($workspace,$manifestRelative,['version'=>1,'metadata'=>[],'routes'=>[$secured]]);
	$denied=$manager->dispatch(['path'=>'/secure']);
	$t->isFalse($denied['ok']);
	$t->same(401,$denied['status']);

	$throwing=Endpoint::get('/throws')->execute(DpApiEdgeTarget::class.'::throwRuntime')->compile();
	dp_api_edge_manifest($workspace,$manifestRelative,['version'=>1,'metadata'=>[],'routes'=>[$throwing]]);
	$hidden=$manager->dispatch(['path'=>'/throws']);
	$t->same('Internal API dispatch failed.',$hidden['json']['error']);
	$exposed=$manager->dispatch(['path'=>'/throws'],['expose_exceptions'=>true]);
	$t->same('edge runtime failure',$exposed['json']['error']);

	dp_api_edge_manifest($workspace,$manifestRelative,['version'=>1,'metadata'=>[],'routes'=>[$route]]);
	$t->same(1,count($manager->discoverApplication()));
	$t->hasPath(['paths','/edge/{id}','get'],$manager->openApiDocument());

	$definitionTemplate=[
		'method'=>'GET','path'=>null,'alias'=>'edge.show','profile'=>null,
		'query'=>[],'body'=>[],'route_parameters'=>[],'headers'=>[],'cookies'=>[],
		'server'=>[],'attributes'=>[],
	];
	$duplicateRoutes=[$route,$route];
	$dispatch=$private->capture('resolveManifestDispatch',routes:$duplicateRoutes,definition:$definitionTemplate);
	$t->same(409,$dispatch->result()['status']);

	$t->same(null,$private->invoke('matchManifestRoute',[
		['methods'=>['GET'],'path_regex'=>'#^/other$#','api'=>[]],
	],'GET','/edge'));
	$t->same([],$private->invoke('matchManifestRoutesByAlias',[
		$route,
	],'GET','edge.show','other-profile'));
	$t->same(['fixed'=>1],$private->invoke('resolveAliasRouteParameters',[
		'exact_path'=>'/fixed','api'=>[],
	],['route_parameters'=>['fixed'=>1]]));
	$t->same(['id'=>11],$private->invoke('resolveAliasRouteParameters',[
		'path_template'=>'/edge/{id}','api'=>[],
	],['route_parameters'=>['id'=>11],'query'=>[],'body'=>[]]));
	$t->same('/fixed',$private->invoke('interpolateRoutePathTemplate','/fixed',[]));
	$normalized=$private->invoke('normalizeInternalRequestDefinition',[
		'path'=>'/edge/12','query'=>['page'=>1],'body'=>['name'=>'Ada'],
	]);
	$t->same(1,$normalized['query']['page']);
	$t->same('Ada',$normalized['body']['name']);
	$synthetic=$private->invoke('internalRequestForRoute',['parameters'=>['id'=>12]],
		array_replace($normalized,[
			'headers'=>['X-Edge'=>'yes'],'cookies'=>['session'=>'edge'],
			'server'=>['SERVER_NAME'=>'edge.test'],'attributes'=>['edge'=>true],
		]),
		['inherit_headers'=>false,'inherit_cookies'=>false,'inherit_server'=>false]);
	$t->same(12,$synthetic->route('id'));
	$t->same('yes',$synthetic->header('x-edge'));
	$t->same('edge',$synthetic->cookie('session'));
	$t->same(true,$synthetic->attribute('edge'));

})->tag('api','manager','coverage')->group('framework-coverage')->maxMillis(15000);

test('api manager edge lifecycle callable and authorization branches are normalized',static function(Context $t): void {
	$state=dp_api_edge_scenario($t);
	$manager=ApiManager::instance();
	$private=$t->nonPublic($manager);
	$request=Request::create('GET','/edge');
	$route=['path_template'=>'/edge','api'=>['path'=>'/edge','operation_id'=>'edge.run']];
	$context=new ApiContext($request,$route);

	$t->same([],$private->invoke('normalizeLifecycle','invalid'));
	$t->same(null,$private->invoke('runLifecycleHooks','before',[], $context,$request,$route));
	$state->put('dialback:CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_RUN',[
		static fn(array $payload): Response=>Response::json(['dialback'=>'before'],207),
	]);
	$short=$private->invoke('runLifecycleHooks','before',[
		'before'=>[['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'nullHook','static'=>true]],
	],$context,$request,$route);
	$t->same(207,$short?->status);
	$state->forget('dialback:CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_RUN');

	$state->put('dialback:CALL_API_FRAMEWORK_LIFECYCLE_AFTER_RUN',[
		static fn(array $payload): Response=>Response::json(['dialback'=>'after-run'],208),
	]);
	$replaced=$private->invoke('runLifecycleHooks','before',[
		'before'=>[
			'invalid',
			['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'responseBefore','static'=>true],
		],
	],$context,$request,$route);
	$t->same(208,$replaced?->status);
	$completed=$private->invoke('runLifecycleHooks','before',[
		'before'=>[['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'nullHook','static'=>true]],
	],$context,$request,$route);
	$t->same(208,$completed?->status);
	$state->forget('dialback:CALL_API_FRAMEWORK_LIFECYCLE_AFTER_RUN');

	$t->throws(static fn()=>$private->invoke('invokeLifecycleHook','before',['type'=>'unknown'],$context,$request,$route,),RuntimeException::class);
	$state->put('dialback:CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_INVOKE',[
		static fn(array $payload): Response=>Response::json(['dialback'=>'invoke'],209),
	]);
	$hook=$private->invoke('invokeLifecycleHook','before',['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'nullHook','static'=>true],
		$context,$request,$route);
	$t->same(209,$hook?->status);
	$state->forget('dialback:CALL_API_FRAMEWORK_LIFECYCLE_BEFORE_INVOKE');

	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'class_method','class'=>'','method'=>'run']));
	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'class_method','class'=>'Missing\\Edge','method'=>'run','static'=>false]));
	$t->notEmpty($private->invoke('resolveExecutionCallable',['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'instance','static'=>false]));
	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'missing','static'=>false]));
	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'callable','reference'=>'']));
	$t->same('strlen',$private->invoke('resolveExecutionCallable',['type'=>'callable','reference'=>'strlen']));
	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'callable','reference'=>'missing_edge_callable']));
	$t->same(null,$private->invoke('resolveExecutionCallable',['type'=>'unknown']));
	$t->same(3,$private->invoke('invokeCallableWithArgs',[DpApiEdgeTarget::class,'variadic'],[1,2,3]));
	$t->same('zero',$private->invoke('invokeCallableWithArgs',[DpApiEdgeTarget::class,'zero'],[1,2]));
	$t->same(2,$private->invoke('invokeCallableWithArgs',static fn(mixed ...$values): int=>count($values),[1,2]));
	$t->throws(static fn()=>$private->invoke('invokeExecutionTarget',['type'=>'unknown'],$context,$request,$route,),RuntimeException::class);

	$invalidRequirements=['api'=>[
		'security'=>[null,[]],
		'security_schemes'=>['unused'=>[]],
	]];
	$t->instanceOf(Response::class,$manager->authorizeCompiledRoute($invalidRequirements,$request));
	$responseScheme=['api'=>[
		'security'=>[['edge'=>[]]],
		'security_schemes'=>['edge'=>[
			'runtime'=>['type'=>'callback','resolver'=>[DpApiEdgeTarget::class,'authorize']],
		]],
	]];
	$state->put('dialback:CALL_API_FRAMEWORK_AUTH_BEFORE_RESOLVER',[
		static fn(array $payload): Response=>Response::json(['denied'=>true],418),
	]);
	$response=$manager->authorizeCompiledRoute($responseScheme,$request);
	$t->same(418,$response?->status);
	$state->forget('dialback:CALL_API_FRAMEWORK_AUTH_BEFORE_RESOLVER');

	$state->put('auth_passing',['session']);
	$guard=$private->invoke('authorizeScheme','guarded',['runtime'=>[
		'type'=>'guard','guards'=>['','session'],
	]],$request,$route,[]);
	$t->isTrue($guard['authorized']);
	$t->same('session',$state->get('auth_selected'));
	$state->put('auth_passing',[]);
	$t->isFalse($private->invoke('authorizeScheme','guarded',['runtime'=>[
		'type'=>'guard','guards'=>'session',
	]],$request,$route,[])['authorized']);
	$state->put('missing_classes',['Dataphyre\\Access\\Auth']);
	$t->isFalse($private->invoke('authorizeScheme','guarded',['runtime'=>[
		'type'=>'guard','guards'=>['session'],
	]],$request,$route,[])['authorized']);
	$state->put('missing_classes',[]);

	$t->isFalse($private->invoke('authorizeScheme','bearer',['runtime'=>['type'=>'bearer']],$request,$route,[])['authorized']);
	$t->isFalse($private->invoke('authorizeScheme','basic',['runtime'=>['type'=>'basic']],$request,$route,[])['authorized']);
	$badBasic=Request::create('GET','/',[],[],[],[],['Authorization'=>'Basic !!!']);
	$t->isFalse($private->invoke('authorizeScheme','basic',['runtime'=>['type'=>'basic']],$badBasic,$route,[])['authorized']);
	$t->isFalse($private->invoke('authorizeScheme','key',['runtime'=>['type'=>'api_key','parameter'=>'X-Key']],$request,$route,[])['authorized']);
	$t->isFalse($private->invoke('authorizeWithResolver','missing',['resolver'=>'not_callable'],null,$request,$route,[])['authorized']);
	$t->isTrue($private->invoke('normalizeAuthorizationResult','edge',[],true)['authorized']);
	$t->same(418,$private->invoke('normalizeAuthorizationResult','edge',[],Response::json([],418))['response']->status);
	$t->isFalse($private->invoke('normalizeAuthorizationResult','edge',[],new stdClass())['authorized']);
	$t->same(null,$private->invoke('resolveCallableReference',null));
	$t->same('strlen',$private->invoke('resolveCallableReference','strlen'));
	$t->same(null,$private->invoke('resolveCallableReference',['missing','callable']));
	$t->same('/edge@edge.run',$private->invoke('apiRouteTraceLabel',$route));
	$t->same(null,$private->invoke('decodeJsonResponse',new Response('plain',200,['X-Test'=>'one','Content-Type'=>'text/plain']),));
	$t->same(null,$private->invoke('decodeJsonResponse',new Response('{}',200,[])));
})->tag('api','manager','coverage')->group('framework-coverage')->maxMillis(15000);

test('api manager edge traces responses bindings and cache descriptors cover boundary states',static function(Context $t): void {
	$state=dp_api_edge_scenario($t);
	$manager=ApiManager::instance();
	$private=$t->nonPublic($manager);
	$request=Request::create('GET','/edge',['page'=>2],['name'=>'Ada'],['session'=>'abc'],[],['X-Tenant'=>'42'],['id'=>7],[
		'dataphyre_api_auth'=>[
			'authorized'=>true,'scheme'=>'edge','guard'=>'session','identity'=>['id'=>7],
			'scopes'=>['read'],'context'=>['tenant'=>42],'meta'=>['source'=>'edge'],
		],
	]);
	$route=['path_template'=>'/edge/{id}','parameters'=>['id'=>7],'api'=>[
		'path'=>'/edge/{id}','operation_id'=>'edge.trace','profile'=>['name'=>'v1'],
	]];
	$context=new ApiContext($request,$route);
	$traceOptions=$private->invoke('normalizeTraceOptions',true);
	$t->isFalse($private->invoke('normalizeTraceOptions','disabled')['enabled']);
	$traceContext=$private->invoke('createApiTraceContext',$route,$request,$traceOptions);
	$payload=$private->invoke('buildApiTracePayload',$traceContext,$route,$request,$context,null,[],microtime(true)-0.01,
		array_replace($traceOptions,['include_sql'=>false]),[]);
	$t->same('edge',$payload['auth']['scheme']);
	$t->same('array',$payload['auth']['identity_type']);
	$t->same([],$private->invoke('recentSqlTracePayload',[],10));
	$t->isTrue(is_array($private->invoke('recentSqlTracePayload',$traceContext,1)));
	$t->same('direct',$private->invoke('executeWithTraceContext',[],static fn(): string=>'direct',$traceOptions,));

	$beforeRoute=Endpoint::get('/edge-before')
		->beforeExecute(DpApiEdgeTarget::class.'::responseBefore')
		->execute(DpApiEdgeTarget::class.'::handle')
		->compile();
	$t->same(202,ApiManager::instance()->executeCompiledRoute(
		$beforeRoute,Request::create('GET','/edge-before')
	)?->status);
	$sanitizationRoute=Endpoint::get('/edge-sanitation')
		->execute(DpApiEdgeTarget::class.'::throwSanitization')
		->compile();
	$sanitization=ApiManager::instance()->executeCompiledRoute(
		$sanitizationRoute,Request::create('GET','/edge-sanitation')
	);
	$t->same(422,$sanitization?->status);
	$t->contains('edge validation failure',$sanitization?->body ?? '');

	$t->same('json',$private->invoke('normalizeExecutionResponse',new class implements JsonSerializable { public function jsonSerialize(): mixed { return ['kind'=>'json']; } },
		null,$traceOptions,)->body!=='' ? 'json' : '');
	$jsonWithTrace=$private->invoke('normalizeExecutionResponse',new class implements JsonSerializable { public function jsonSerialize(): mixed { return ['kind'=>'json']; } },
		$payload,$traceOptions);
	$t->contains('edge.trace',$jsonWithTrace->body);
	$t->contains('edge.trace',$private->invoke('normalizeExecutionResponse','scalar',$payload,$traceOptions)->body);
	$t->same('plain',$private->invoke('applyTraceToResponse',new Response('plain',200,['Content-Type'=>'text/plain']),$payload,$traceOptions,)->body);
	$t->same('7',$private->invoke('applyTraceToResponse',new Response('7',200,['Content-Type'=>'application/json']),$payload,$traceOptions,)->body);
	$t->same('[1,2]',$private->invoke('applyTraceToResponse',new Response('[1,2]',200,['Content-Type'=>'application/json']),$payload,$traceOptions,)->body);

	$disabledStorage=$private->invoke('responseForEndpointCacheStorage',Response::json(['ok'=>true]),array_replace($traceOptions,['enabled'=>false]));
	$t->contains('ok',$disabledStorage->body);
	$t->same('plain',$private->invoke('responseForEndpointCacheStorage',new Response('plain',200,['Content-Type'=>'text/plain','X-Dataphyre-Api-Trace'=>'x']),$traceOptions,)->body);
	$t->same('[1,2]',$private->invoke('responseForEndpointCacheStorage',new Response('[1,2]',200,['Content-Type'=>'application/json']),$traceOptions,)->body);
	$t->isTrue($private->invoke('isJsonResponse',new Response('{}',200,['X-Test'=>'x','Content-Type'=>'application/json'])));
	$t->isFalse($private->invoke('isJsonResponse',new Response('',200,[])));
	$t->same([],$private->invoke('selectedRequestValues',['X-Test'=>'yes'],['missing']));
	$t->same([],$private->invoke('normalizeEndpointCacheNames',null));
	$t->same(['valid'],$private->invoke('normalizeEndpointCacheNames',[null,7,'valid']));

	$invalidDescriptor=$private->invoke('endpointCacheDescriptor',$route,$request,$context,[null,['path'=>'','definition'=>[]]],$traceContext,['names'=>['edge']]);
	$t->isTrue($invalidDescriptor['cacheable']);
	$bindingWithoutIdentity=[[
		'path'=>'one',
		'definition'=>[
			'type'=>'callable',
			'target'=>['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'handle','static'=>true],
		],
	]];
	$bypassed=$private->invoke('endpointCacheDescriptor',$route,$request,$context,$bindingWithoutIdentity,$traceContext,['names'=>['edge']]);
	$t->isFalse($bypassed['cacheable']);
	$t->contains('does not expose cache identity',$bypassed['reason']);
	$allowed=$private->invoke('endpointCacheDescriptor',$route,$request,$context,$bindingWithoutIdentity,$traceContext,[
			'names'=>['edge'],'allow_untracked_bindings'=>true,
		]);
	$t->isTrue($allowed['cacheable']);
	$trackedBindings=[[
		'path'=>'one',
		'definition'=>[
			'type'=>'callable',
			'target'=>['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'handle','static'=>true],
			'identity'=>['tenant'=>42],
		],
	]];
	$tracked=$private->invoke('endpointCacheDescriptor',$route,$request,$context,$trackedBindings,$traceContext,[
			'names'=>['edge'],'vary_headers'=>['X-Tenant'],'vary_cookies'=>['session'],'identity'=>['extra'=>true],
		]);
	$t->same(42,$tracked['identity']['bindings']['one']['tenant']);
	$t->same('42',$tracked['identity']['request']['headers']['X-Tenant']);
	$t->same('abc',$tracked['identity']['request']['cookies']['session']);
	$t->same('edge',$tracked['identity']['auth']['scheme']);
	$t->same('bypass',$private->invoke('provisionalEndpointCacheTrace',['enabled'=>true,'cacheable'=>false],)['state']);

	$bindings=[
		null,
		['path'=>'','definition'=>[]],
		['path'=>'first','definition'=>[
			'type'=>'callable','target'=>['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'skippedBinding','static'=>true],
			'identity'=>'shared',
		]],
		['path'=>'second','definition'=>[
			'type'=>'callable','target'=>['type'=>'class_method','class'=>DpApiEdgeTarget::class,'method'=>'skippedBinding','static'=>true],
			'identity'=>'shared',
		]],
	];
	$private->invoke('resolveRouteBindings',$context,$bindings,$traceContext);
	$t->same(['skipped'=>true],$context->binding('first'));
	$t->same(['skipped'=>true],$context->binding('second'));

	$sql=$private->invoke('sqlBindingFromDefinition','rows',[
		'query_class'=>'Dataphyre\\Database\\TableQuery','query_state'=>['table'=>'edge_rows'],
		'mode'=>'records','options'=>[],'inherit_query_identity'=>true,
	]);
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$sql);
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$private->invoke('bindingFromDefinition','rows',[
		'type'=>'sql_query','query_class'=>'Dataphyre\\Database\\TableQuery','query_state'=>['table'=>'edge_rows'],
	]));
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$private->invoke('sqlBindingFromDefinition','repository',[
		'query_class'=>'Dataphyre\\Database\\RepositoryQuery',
		'query_state'=>['repository_class'=>DpApiEdgeRepository::class],
	]));
	$t->throws(static fn()=>$private->invoke('sqlBindingFromDefinition','bad',[
		'query_class'=>'Missing\\Query','query_state'=>[],
	]),RuntimeException::class);
	$search=$private->invoke('searchBindingFromDefinition','results',[
		'query_class'=>'Dataphyre\\FulltextEngine\\Query','query_state'=>['index'=>'edge'],
		'mode'=>'results','options'=>[],'inherit_query_identity'=>true,
	]);
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$search);
	$t->instanceOf(\Dataphyre\Templating\DataBinding::class,$private->invoke('bindingFromDefinition','results',[
		'type'=>'search_query','query_class'=>'Dataphyre\\FulltextEngine\\Query','query_state'=>['index'=>'edge'],
	]));
	$t->throws(static fn()=>$private->invoke('searchBindingFromDefinition','bad',[
		'query_class'=>'Missing\\Search','query_state'=>[],
	]),RuntimeException::class);

	$simple=new DpApiEdgeBinding(['ok'=>true]);
	$bindingContext=$private->invoke('bindingContextForApi',$context,[],'simple',$traceContext,1);
	$value=$private->invoke('resolveApiBindingWithTraceContext',$simple,$bindingContext,['driver'=>'sql','query_fingerprint'=>'fingerprint'],$traceContext,'simple');
	$t->same(true,$value['ok']);
	$t->same(1,$simple->calls);

	$state->put('random_bytes_failure',true);
	$t->notEmpty($private->invoke('newTraceId'));
	$state->put('random_bytes_failure',false);
})->tag('api','manager','coverage')->group('framework-coverage')->maxMillis(15000);

test('api manager edge persistent cache handles corruption serialization writes indexes and cleanup',static function(Context $t): void {
	$state=dp_api_edge_scenario($t);
	$manager=ApiManager::instance();
	$private=$t->nonPublic($manager);
	$workspace=$t->workspace('api-edge-cache');
	$t->defer(static fn()=>$manager->clearEndpointCache());
	$manager->clearEndpointCache();
	$root=$private->invoke('endpointCacheRoot');
	$names=$root.'names'.DIRECTORY_SEPARATOR;
	$traceOptions=$private->invoke('normalizeTraceOptions',true);
	$private->invoke('storeEndpointCacheResponse',[
		'enabled'=>true,'cacheable'=>true,'key'=>'fixture-setup','ttl'=>60,'names'=>[],'store_errors'=>true,
	],Response::json(['fixture'=>true]),$traceOptions);

	$key='empty-'.bin2hex(random_bytes(4));
	$file=$private->invoke('endpointCacheItemFile',$key);
	DpApiEdgeCacheFixture::writeRawEntry($file,'');
	$t->isFalse($private->invoke('loadCachedEndpointResponse',['cacheable'=>true,'key'=>$key])['hit']);

	$key='throw-'.bin2hex(random_bytes(4));
	$file=$private->invoke('endpointCacheItemFile',$key);
	DpApiEdgeCacheFixture::writeSerializedEntry($file,new DpApiEdgeUnserializeFailure());
	$t->isFalse($private->invoke('loadCachedEndpointResponse',['cacheable'=>true,'key'=>$key])['hit']);
	$t->isFalse(is_file($file));

	$key='scalar-'.bin2hex(random_bytes(4));
	$file=$private->invoke('endpointCacheItemFile',$key);
	DpApiEdgeCacheFixture::writeSerializedEntry($file,'invalid');
	$t->isFalse($private->invoke('loadCachedEndpointResponse',['cacheable'=>true,'key'=>$key])['hit']);
	$t->isFalse(is_file($file));

	$key='expired-'.bin2hex(random_bytes(4));
	$file=$private->invoke('endpointCacheItemFile',$key);
	DpApiEdgeCacheFixture::writeSerializedEntry($file,['expires_at'=>time()-1,'response'=>[]]);
	$t->isFalse($private->invoke('loadCachedEndpointResponse',['cacheable'=>true,'key'=>$key])['hit']);
	$t->isFalse(is_file($file));

	$descriptor=[
		'enabled'=>true,'cacheable'=>true,'key'=>'serialize-'.bin2hex(random_bytes(4)),
		'ttl'=>60,'names'=>[],'store_errors'=>true,
	];
	$serializeFailure=$private->invoke('storeEndpointCacheResponse',$descriptor,new Response('body',200,['X-Failure'=>new DpApiEdgeSerializeFailure()]),$traceOptions);
	$t->contains('serialize',$serializeFailure['reason']);

	$state->put('write_failure',true);
	$writeFailure=$private->invoke('storeEndpointCacheResponse',array_replace($descriptor,['key'=>'write-'.bin2hex(random_bytes(4))]),
		Response::json(['ok'=>true]),$traceOptions);
	$state->put('write_failure',false);
	$t->contains('write',$writeFailure['reason']);

	$manager->clearEndpointCache();
	$state->put('force_missing_names_directory',true);
	$private->invoke('storeEndpointCacheResponse',[
		'enabled'=>true,'cacheable'=>true,'key'=>'edge-key','ttl'=>60,'names'=>['edge-index'],'store_errors'=>true,
	],Response::json(['fixture'=>true]),$traceOptions);
	$state->put('force_missing_names_directory',false);
	$nameFile=$private->invoke('endpointCacheNameFile','edge-index',$names);
	$t->isTrue(is_file($nameFile));
	DpApiEdgeCacheFixture::writeNameIndex($nameFile,[null,'','edge-key']);
	$t->same(1,$manager->clearEndpointCache('edge-index'));

	$tempItems=$workspace->directory('clear/items').DIRECTORY_SEPARATOR;
	$tempNames=$workspace->directory('clear/names').DIRECTORY_SEPARATOR;
	$state->put('glob_failure',true);
	$t->same(0,$private->invoke('clearPersistentCacheDirectories',$tempItems,$tempNames));
	$state->put('glob_failure',false);
	$workspace->file('clear/items/one','1');
	$workspace->file('clear/names/two','2');
	$t->same(2,$private->invoke('clearPersistentCacheDirectories',$tempItems,$tempNames));
	$missingRoot=$workspace->path('missing').DIRECTORY_SEPARATOR;
	$t->same(0,$private->invoke('clearPersistentCacheDirectories',$missingRoot.'items'.DIRECTORY_SEPARATOR,$missingRoot.'names'.DIRECTORY_SEPARATOR,));

	$bootstrapFile=$workspace->file('bootstrap/handler.php',"<?php\n");
	$private->invoke('bootstrapCompiledHandler',['bootstrap'=>$bootstrapFile]);
	$private->invoke('bootstrapExecutionTarget',['bootstrap'=>$bootstrapFile]);
	$private->invoke('bootstrapCompiledHandler',['bootstrap'=>'core']);
	$private->invoke('bootstrapExecutionTarget',['bootstrap'=>'core']);
	$t->throws(static fn()=>$private->invoke('bootstrapExecutionTarget',['bootstrap'=>'missing-edge-bootstrap'],),RuntimeException::class);
	$private->invoke('loadFrameworkModulesForExecutionTarget',[]);
	$private->invoke('loadFrameworkModulesForExecutionTarget',['class'=>'Dataphyre\\Api\\ApiManager']);
	$private->invoke('loadFrameworkModulesForExecutionTarget',['class'=>'App\\Edge']);
	$private->invoke('loadFrameworkModules',['api']);
	$private->invoke('loadAccessFramework');
	$manager->clearEndpointCache();
})->tag('api','manager','coverage')->group('framework-coverage')->maxMillis(15000);

test('api manager edge application manifests definitions options and server defaults are resolved',static function(Context $t): void {
	$state=dp_api_edge_scenario($t);
	$manager=ApiManager::instance();
	$private=$t->nonPublic($manager);
	$workspace=$t->workspace('api-manager-application');
	$root=$workspace->root().DIRECTORY_SEPARATOR;
	$app=$workspace->directory('applications/edge-app');
	$compiledRelative='applications/edge-app/compiled.php';
	$compiled=dp_api_edge_manifest($workspace,$compiledRelative,['version'=>2,'metadata'=>['kind'=>'compiled'],'routes'=>[]]);
	$routes=$workspace->file('applications/edge-app/routes.php',"<?php\nreturn [];\n");

	$compiledDefinition=new \dataphyre\application_definition('edge_app',$app,null,null,$compiled);
	$runtime=$t->nonPublic(\dataphyre\runtime::class);
	$runtime
		->replacePropertyForTest('current_application_definition',$compiledDefinition)
		->replacePropertyForTest('current_project_root',$root);
	$t->same(2,$private->invoke('applicationManifest')['version']);
	$t->same($compiledDefinition,$private->invoke('applicationDefinition',null));
	$t->same(rtrim($root,'/\\'),$private->invoke('projectRoot'));

	$workspace->file($compiledRelative,"<?php\nreturn 'invalid';\n");
	$t->same(1,$private->invoke('applicationManifest')['version']);
	$routesDefinition=new \dataphyre\application_definition('edge_routes',$app,null,$routes,null);
	$runtime->writeProperty('current_application_definition',$routesDefinition);
	$routesManifest=$private->invoke('applicationManifest');
	$t->same('edge_routes',$routesManifest['metadata']['application']);
	$emptyDefinition=new \dataphyre\application_definition('edge_empty',$app);
	$runtime->writeProperty('current_application_definition',$emptyDefinition);
	$t->same('edge_empty',$private->invoke('applicationManifest')['metadata']['application']);

	$resolved=$private->invoke('applicationDefinition','edge-app');
	$t->instanceOf(\dataphyre\application_definition::class,$resolved);
	$state->put('missing_classes',['Dataphyre\\Runtime']);
	$t->same(rtrim($root,'/\\'),$private->invoke('projectRoot'));
	$runtime
		->writeProperty('current_application_definition',null)
		->writeProperty('current_project_root',null);
	$t->same(rtrim((string)ROOTPATH['root'],'/\\'),$private->invoke('projectRoot'));
	$state->put('missing_classes',[]);
	$t->same(null,$private->invoke('applicationDefinition','missing-app'));
	$state->put('missing_classes',['dataphyre\\runtime']);
	$t->same(null,$private->invoke('applicationDefinition','unavailable-runtime'));
	$state->put('missing_classes',[]);

	$options=$private->invoke('openApiOptions',$compiledDefinition,[
		'version'=>'2.0.0','description'=>null,'servers'=>['https://edge.test'],
	]);
	$t->same('Edge App API',$options['title']);
	$t->same('2.0.0',$options['version']);
	$t->contains('edge_app',$options['description']);
	$t->same('Dataphyre API',$private->invoke('defaultTitle',null));
	$t->same('Edge App API',$private->invoke('defaultTitle',$compiledDefinition));

	$server=$t->globalMap('_SERVER');
	$server->put('HTTP_HOST','')->forget('HTTPS');
	$t->same([],$private->invoke('defaultServers',[]));
	$server->put('HTTP_HOST','edge.test')->put('HTTPS','on');
	$t->same('https://edge.test',$private->invoke('defaultServers',[])[0]['url']);
	$server->put('HTTPS','off');
	$t->same('http://edge.test',$private->invoke('defaultServers',[])[0]['url']);
})->tag('api','manager','coverage')->group('framework-coverage')->maxMillis(15000);
