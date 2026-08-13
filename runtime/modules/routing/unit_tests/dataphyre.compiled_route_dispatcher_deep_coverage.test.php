<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(routing::class,false)){
		class routing {
			/** @var array<string,mixed> */
			public static array $bindings=[];
		}
	}

	if(!class_exists(core::class,false)){
		class core {
			/** @var list<string> */
			public static array $loaded=[];
			/** @var list<list<string>> */
			public static array $loaded_many=[];

			public static function reset(): void {
				self::$loaded=[];
				self::$loaded_many=[];
			}

			public static function load_framework_module(string $module): bool {
				self::$loaded[]=$module;
				return true;
			}

			/** @param list<string> $modules */
			public static function load_framework_modules(array $modules): array {
				self::$loaded_many[]=$modules;
				return $modules;
			}
		}
	}
}

namespace Dataphyre\Http {
	if(!class_exists(Request::class,false)){
		final class Request {
			/** @param array<string,mixed> $route_parameters */
			private function __construct(public array $route_parameters) {}

			/** @param array<string,mixed> $route_parameters */
			public static function capture(array $route_parameters=[]): self {
				return new self($route_parameters);
			}
		}
	}

	if(!class_exists(ResponseEmitter::class,false)){
		final class ResponseEmitter {
			/** @var list<mixed> */
			public static array $emitted=[];

			public static function reset(): void {
				self::$emitted=[];
			}

			public static function emit(mixed $response): void {
				self::$emitted[]=$response;
			}
		}
	}
}

namespace Dataphyre\Api {
	if(!class_exists(Api::class,false)){
		final class Api {
			public static mixed $authorization_response=null;
			public static mixed $execution_response=null;
			/** @var list<array{kind:string,route:array<string,mixed>,request:object}> */
			public static array $calls=[];

			public static function reset(): void {
				self::$authorization_response=null;
				self::$execution_response=null;
				self::$calls=[];
			}

			public static function authorizeCompiledRoute(array $route, object $request): mixed {
				self::$calls[]=['kind'=>'authorize','route'=>$route,'request'=>$request];
				return self::$authorization_response;
			}

			public static function executeCompiledRoute(array $route, object $request): mixed {
				self::$calls[]=['kind'=>'execute','route'=>$route,'request'=>$request];
				return self::$execution_response;
			}
		}
	}
}

namespace Dataphyre\RoutingCoverage {
	final class PassMiddleware {
		/** @var list<array{request:mixed,parameters:list<mixed>}> */
		public static array $calls=[];

		public function handle(mixed $request, callable $next, mixed ...$parameters): mixed {
			self::$calls[]=['request'=>$request,'parameters'=>$parameters];
			return $next($request);
		}
	}

	final class ReturnMiddleware {
		public static mixed $result=null;

		public function handle(mixed $request, callable $next, mixed ...$parameters): mixed {
			return self::$result;
		}
	}

	final class NoHandleMiddleware {}

	final class Controller {
		/** @var list<array{kind:string,request:mixed,route:array<string,mixed>}> */
		public static array $calls=[];

		public static function statik(mixed $request,array $route): object {
			self::$calls[]=['kind'=>'static','request'=>$request,'route'=>$route];
			return (object)['controller'=>'static'];
		}

		public function instance(mixed $request,array $route): object {
			self::$calls[]=['kind'=>'instance','request'=>$request,'route'=>$route];
			return (object)['controller'=>'instance'];
		}
	}

	final class CompiledRouteScenario {
		private const CHANNEL='routing.compiled-route';
		private \Dataphyre\Test\TestState $events;
		private \Dataphyre\Test\GlobalState $query;
		private \Dataphyre\Test\GlobalState $server;

		public function __construct(\Dataphyre\Test\Context $context) {
			$this->events=$context->state(self::CHANNEL, [
				'include_count'=>0,
				'bootstrap_count'=>0,
			]);
			$this->query=$context->globalMap('_GET');
			$this->server=$context->globalMap('_SERVER');
			$this->reset();
		}

		public function reset(): void {
			\dataphyre\core::reset();
			\dataphyre\routing::$bindings=[];
			\Dataphyre\Api\Api::reset();
			\Dataphyre\Http\ResponseEmitter::reset();
			PassMiddleware::$calls=[];
			ReturnMiddleware::$result=null;
			Controller::$calls=[];
			$this->events->replace(['include_count'=>0,'bootstrap_count'=>0]);
			$this->query->replace([]);
			$this->server->replace([]);
		}

		/** @param array<string,mixed> $query @param array<string,mixed> $server */
		public function environment(array $query=[], array $server=[]): void {
			$this->query->replace($query);
			$this->server->replace($server);
		}

		public function request(string $method, string $uri): void {
			$this->environment([], ['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$uri]);
		}

		public static function included(): void { self::active()->increment('include_count'); }
		public static function bootstrapped(): void { self::active()->increment('bootstrap_count'); }
		/** @param array<string,mixed> $parameters */
		public static function manifestParameters(array $parameters): void { self::active()->put('manifest_parameters',$parameters); }

		public function includeCount(): int { return (int)$this->events->get('include_count',0); }
		public function bootstrapCount(): int { return (int)$this->events->get('bootstrap_count',0); }
		/** @return array<string,mixed> */
		public function recordedManifestParameters(): array { return (array)$this->events->get('manifest_parameters',[]); }
		public function recordCallable(mixed $value): void { $this->events->put('callable',$value); }
		public function recordedCallable(): mixed { return $this->events->get('callable'); }

		private static function active(): \Dataphyre\Test\TestState {
			return \Dataphyre\Test\TestState::channel(self::CHANNEL);
		}
	}
}

namespace {
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/routing/kernel/compiled_route_dispatcher.php';

	use Dataphyre\Api\Api;
	use Dataphyre\Http\Request;
	use Dataphyre\Http\ResponseEmitter;
	use Dataphyre\RoutingCoverage\Controller;
	use Dataphyre\RoutingCoverage\CompiledRouteScenario;
	use Dataphyre\RoutingCoverage\NoHandleMiddleware;
	use Dataphyre\RoutingCoverage\PassMiddleware;
	use Dataphyre\RoutingCoverage\ReturnMiddleware;
	use Dataphyre\Test\Context;
	use dataphyre\routing\compiled_route_dispatcher;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	suite('Compiled route dispatcher execution contracts')
		->contract('routing.compiled-dispatcher', 1)
		->layer('integration')
		->risk('critical')
		->watches('module:routing')
		->through('route-match', 'domain-match', 'manifest', 'module-loader', 'middleware', 'handler', 'response-emitter')
		->isolation('case')
		->tag('routing', 'compiled-dispatcher')
		->group('framework-coverage');

	function dp_compiled_route_fixture(string $name): string {
		return __DIR__.'/fixtures/'.$name;
	}

	test('compiled route dispatcher matches exact regex domain default splat and normalized request surfaces',static function(Context $t): void {
		$scenario=new CompiledRouteScenario($t);
		$private=$t->nonPublic(compiled_route_dispatcher::class);
		$routes=[
			['exact_path'=>'/skip','methods'=>['GET']],
			['exact_path'=>'/items','methods'=>['POST']],
			['exact_path'=>'/items','methods'=>['get'],'exact_domain'=>'other.test'],
			['exact_path'=>'/items','methods'=>['get'],'exact_domain'=>'HTTPS://EXAMPLE.TEST:443/path','defaults'=>['mode'=>'exact']],
		];
		$exact=compiled_route_dispatcher::match_routes_for_request($routes,'GET','items/','Example.Test:443');
		$t->same('exact',$exact['parameters']['mode']);
		$t->same(null,compiled_route_dispatcher::match_routes_for_request([
			['exact_path'=>'/items','methods'=>['GET'],'domain'=>'required'],
		],'GET','/items',''));

		$regex_routes=[
			['methods'=>['ANY']],
			['methods'=>['GET'],'path_regex'=>'#([#'],
			['methods'=>['ANY'],'path_regex'=>'#^/users/(?<id>[^/]+)(?:/(?<optional>[^/]+))?$#','domain_regex'=>'#([#'],
			[
				'methods'=>['ANY'],
				'path_regex'=>'#^/files/(?<path>.*)$#',
				'domain_regex'=>'#^(?<tenant>[^.]+)\\.example\\.test$#',
				'defaults'=>['optional'=>'fallback','tenant'=>'default'],
				'splat_parameters'=>['path'],
			],
		];
		$regex=compiled_route_dispatcher::match_routes_for_request($regex_routes,'PATCH','/files/a//b%20c/','Blue.Example.Test');
		$t->same('blue',$regex['parameters']['tenant']);
		$t->same(['a','b c'],$regex['parameters']['path']);
		$t->same('fallback',$regex['parameters']['optional']);
		$t->same(null,compiled_route_dispatcher::match_routes_for_request($regex_routes,'PATCH','/none','blue.example.test'));

		$scenario->environment(['uri'=>'from-query/'],['REQUEST_METHOD'=>'head','HTTP_HOST'=>'[2001:DB8::1]']);
		$from_globals=$private->invokeWithArguments('match_route',[[
			['exact_path'=>'/from-query','methods'=>['HEAD'],'exact_domain'=>'[2001:db8::1]'],
		]]);
		$t->same('/from-query',$from_globals['exact_path']);
		$scenario->environment([],['REQUEST_URI'=>'/from-server/?q=1']);
		$t->same('/from-server',$private->invokeWithArguments('normalized_request_path'));
		$t->same('/',$private->invokeWithArguments('normalize_path',['////']));
		$t->same('host.test',$private->invokeWithArguments('normalize_host',[' https://HOST.TEST.:8443/path, ignored.test ']));
		$t->same('2001:db8::1',$private->invokeWithArguments('normalize_host',['[2001:db8::1]']));

		$private->invokeWithArguments('publish_parameters',[['id'=>9]]);
		$t->same(['id'=>9],\dataphyre\routing::$bindings);
		$t->same([],$private->invokeWithArguments('explode_splat_parameter',['///']));
		$t->same(['a','b c'],$private->invokeWithArguments('explode_splat_parameter',['/a//b%20c/']));
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');

	test('compiled route dispatcher loads manifests and dispatches every direct handler shape',static function(Context $t): void {
		$scenario=new CompiledRouteScenario($t);
		$private=$t->nonPublic(compiled_route_dispatcher::class);
		$scenario->request('GET','/compiled-file');
		$t->isTrue(compiled_route_dispatcher::dispatch_file(dp_compiled_route_fixture('compiled_route_manifest.php')));
		$t->same(['source'=>'manifest'],$scenario->recordedManifestParameters());
		$t->same(['source'=>'manifest'],\dataphyre\routing::$bindings);
		$t->throws(
			static fn()=>compiled_route_dispatcher::dispatch_file(dp_compiled_route_fixture('compiled_route_invalid_manifest.php')),
			RuntimeException::class
		);
		$t->isFalse(compiled_route_dispatcher::dispatch_manifest(['routes'=>[]]));

		$include=dp_compiled_route_fixture('compiled_route_include.php');
		$bootstrap=dp_compiled_route_fixture('compiled_route_bootstrap.php');
		$private->invokeWithArguments('dispatch_without_middleware',[$include,[]]);
		$t->same(1,$scenario->includeCount());
		$private->invokeWithArguments('dispatch_without_middleware',[
			static function(array $parameters,array $route)use($scenario): void {$scenario->recordCallable([$parameters,$route]);},
			['parameters'=>['id'=>1]],
		]);
		$t->same(1,$scenario->recordedCallable()[0]['id']);
		$private->invokeWithArguments('dispatch_without_middleware',[[
			'type'=>'include','target'=>$include,'bootstrap'=>$bootstrap,
		],[]]);
		$t->same(2,$scenario->includeCount());
		$t->same(1,$scenario->bootstrapCount());
		$private->invokeWithArguments('dispatch_without_middleware',[[
			'type'=>'callable','target'=>static function(array $parameters)use($scenario): void {$scenario->recordCallable($parameters);},
		],['parameters'=>['id'=>2]]]);
		$t->same(['id'=>2],$scenario->recordedCallable());

		$private->invokeWithArguments('dispatch_without_middleware',[[
			'type'=>'controller','class'=>Controller::class,'method'=>'statik','static'=>true,
		],['parameters'=>['id'=>3]]]);
		$t->same('static',Controller::$calls[0]['kind']);
		$t->same('static',ResponseEmitter::$emitted[0]->controller);
		$t->throws(static fn()=>$private->invokeWithArguments('dispatch_without_middleware',[null,[]]),RuntimeException::class);
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');

	test('compiled route dispatcher resolves aliases modules parameters bootstraps and middleware contracts',static function(Context $t): void {
		$scenario=new CompiledRouteScenario($t);
		$private=$t->nonPublic(compiled_route_dispatcher::class);
		$bootstrap=dp_compiled_route_fixture('compiled_route_bootstrap.php');
		$callable=static fn(mixed $request,callable $next): mixed=>$next($request);
		$t->throws(
			static fn()=>compiled_route_dispatcher::resolve_middleware_for_route(['alias'=>'missing']),
			RuntimeException::class
		);
		$callable_alias=compiled_route_dispatcher::resolve_middleware_for_route(
			['alias'=>'callback','parameters'=>['one']],
			[' callback '=>$callable]
		);
		$t->same($callable,$callable_alias['target']);
		$t->same(['one'],$callable_alias['parameters']);

		$class_alias=compiled_route_dispatcher::resolve_middleware_for_route(
			['alias'=>'guard','parameters'=>['route'],'modules'=>['HTTP']],
			['guard'=>['class'=>'\\'.PassMiddleware::class,'parameters'=>['alias'],'modules'=>['routing'],'bootstrap'=>$bootstrap]]
		);
		$t->same(PassMiddleware::class,$class_alias['class']);
		$t->same(['alias','route'],$class_alias['parameters']);
		$t->same(['routing','http'],$class_alias['modules']);
		$t->same(1,$scenario->bootstrapCount());

		$direct=compiled_route_dispatcher::resolve_middleware_for_route([
			'target'=>$callable,'parameters'=>'single','bootstrap'=>$bootstrap,
		]);
		$t->same(['single'],$direct['parameters']);
		$t->same($bootstrap,$direct['bootstrap']);
		$t->throws(static fn()=>compiled_route_dispatcher::resolve_middleware_for_route([]),RuntimeException::class);
		$scalar=compiled_route_dispatcher::resolve_middleware_for_route([
			'class'=>PassMiddleware::class,'modules'=>'ROUTING','parameters'=>'parameter',
		]);
		$t->same(['routing'],$scalar['modules']);
		$t->same(['parameter'],$scalar['parameters']);
		$empty_modules=compiled_route_dispatcher::resolve_middleware_for_route([
			'class'=>PassMiddleware::class,'modules'=>17,
		]);
		$t->same([],$empty_modules['modules']);

		$normalized=$private->invokeWithArguments('normalize_custom_middleware_aliases',[[''=>PassMiddleware::class,'empty'=>'','string'=>'\\'.PassMiddleware::class,'call'=>$callable,'array'=>['class'=>'\\'.PassMiddleware::class],55=>['class'=>PassMiddleware::class]]]);
		$t->same(PassMiddleware::class,$normalized['string']['class']);
		$t->hasKey('call',$normalized);
		$t->same(PassMiddleware::class,$normalized['array']['class']);
		$t->same([],$private->invokeWithArguments('infer_framework_modules_for_class',['Vendor\\Thing']));
		foreach([
			'Dataphyre\\Access\\Thing'=>'access','Dataphyre\\Permission\\Thing'=>'permission',
			'Dataphyre\\Api\\Thing'=>'api','Dataphyre\\Http\\Thing'=>'http',
			'Dataphyre\\Routing\\Thing'=>'routing','Dataphyre\\Database\\Thing'=>'sql',
		] as $class=>$module){
			$t->same([$module],$private->invokeWithArguments('infer_framework_modules_for_class',[$class]));
		}
		$t->producesStableResult(static fn()=>$private->invokeWithArguments('middleware_aliases'));
		$t->throws(static fn()=>$private->invokeWithArguments('resolve_route_middleware',[[null]]),RuntimeException::class);
		$t->count(1,$private->invokeWithArguments('resolve_route_middleware',[[['class'=>PassMiddleware::class]]]));

		$private->invokeWithArguments('bootstrap_middleware',[[ 'modules'=>[], 'bootstrap'=>null ]]);
		$private->invokeWithArguments('bootstrap_middleware',[[ 'modules'=>['routing'], 'bootstrap'=>$bootstrap ]]);
		$t->same(['routing'],\dataphyre\core::$loaded_many[array_key_last(\dataphyre\core::$loaded_many)]);
		$t->throws(static fn()=>$private->invokeWithArguments('instantiate_middleware',[[]]),RuntimeException::class);
		$t->throws(static fn()=>$private->invokeWithArguments('instantiate_middleware',[['class'=>NoHandleMiddleware::class]]),RuntimeException::class);
		$t->instanceOf(PassMiddleware::class,$private->invokeWithArguments('instantiate_middleware',[['class'=>PassMiddleware::class]]));
		$private->invokeWithArguments('bootstrap_target',[null]);
		$private->invokeWithArguments('bootstrap_target',['']);
		$private->invokeWithArguments('bootstrap_target',[$bootstrap]);
		$t->throws(static fn()=>$private->invokeWithArguments('bootstrap_target',['missing-bootstrap']),RuntimeException::class);
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');

	test('compiled route dispatcher executes api direct and middleware short circuit paths',static function(Context $t): void {
		$scenario=new CompiledRouteScenario($t);
		$private=$t->nonPublic(compiled_route_dispatcher::class);
		$route=['api'=>['execution'=>['operation'=>'show']],'parameters'=>['id'=>4]];
		$request=Request::capture($route['parameters']);
		$t->isFalse($private->invokeWithArguments('route_has_api_metadata',[['api'=>'invalid']]));
		$t->same(null,$private->invokeWithArguments('authorize_api_route',[[],$request]));
		Api::$authorization_response='not-object';
		$t->same(null,$private->invokeWithArguments('authorize_api_route',[$route,$request]));
		$authorization=(object)['kind'=>'authorization'];
		Api::$authorization_response=$authorization;
		$t->same($authorization,$private->invokeWithArguments('authorize_api_route',[$route,$request]));
		$t->same(null,$private->invokeWithArguments('execute_api_route',[['api'=>[]],$request]));
		Api::$execution_response='not-object';
		$t->same(null,$private->invokeWithArguments('execute_api_route',[$route,$request]));
		$execution=(object)['kind'=>'execution'];
		Api::$execution_response=$execution;
		$t->same($execution,$private->invokeWithArguments('execute_api_route',[$route,$request]));

		Api::$authorization_response=$authorization;
		$private->invokeWithArguments('dispatch_without_middleware',[static fn()=>null,$route]);
		$t->same($authorization,ResponseEmitter::$emitted[0]);
		ResponseEmitter::reset();
		Api::$authorization_response=null;
		Api::$execution_response=$execution;
		$private->invokeWithArguments('dispatch_without_middleware',[static fn()=>null,$route]);
		$t->same($execution,ResponseEmitter::$emitted[0]);

		ResponseEmitter::reset();
		Api::$authorization_response=$authorization;
		$private->invokeWithArguments('dispatch_with_middleware',[static fn()=>null,$route,[['class'=>PassMiddleware::class]]]);
		$t->same($authorization,ResponseEmitter::$emitted[0]);
		ResponseEmitter::reset();
		Api::$authorization_response=null;
		Api::$execution_response=null;
		$called=0;
		$private->invokeWithArguments('run_handler',[
			static function()use(&$called): void {$called++;},
			['parameters'=>['id'=>5],'middleware'=>[['class'=>PassMiddleware::class]]],
		]);
		$t->same(1,$called);
		$private->invokeWithArguments('dispatch_with_middleware',[
			static function()use(&$called): void {$called++;},
			['parameters'=>['id'=>5]],
			[['class'=>PassMiddleware::class,'parameters'=>['p']]],
		]);
		$t->same(2,$called);
		$t->same([],ResponseEmitter::$emitted);
		$t->same(['p'],PassMiddleware::$calls[array_key_last(PassMiddleware::$calls)]['parameters']);
		$callable_middleware_parameters=[];
		$private->invokeWithArguments('dispatch_with_middleware',[
			static function()use(&$called): void {$called++;},
			[],
			[['target'=>static function(mixed $request,callable $next,mixed ...$parameters)use(&$callable_middleware_parameters): mixed {
				$callable_middleware_parameters=$parameters;
				return $next($request);
			},'parameters'=>['callable-parameter']]],
		]);
		$t->same(['callable-parameter'],$callable_middleware_parameters);
		$t->same(3,$called);

		ReturnMiddleware::$result=(object)['kind'=>'middleware'];
		$private->invokeWithArguments('dispatch_with_middleware',[
			static fn()=>null,[],[['class'=>ReturnMiddleware::class]],
		]);
		$t->same('middleware',ResponseEmitter::$emitted[0]->kind);

		ResponseEmitter::reset();
		$private->invokeWithArguments('dispatch_with_middleware',[[
			'type'=>'controller','class'=>Controller::class,'method'=>'statik','static'=>true,
		],['parameters'=>['id'=>6]],[['class'=>PassMiddleware::class]]]);
		$t->same('static',ResponseEmitter::$emitted[0]->controller);
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');

	test('compiled route dispatcher shared handler controller and bootstrap helpers cover emit contracts',static function(Context $t): void {
		$scenario=new CompiledRouteScenario($t);
		$private=$t->nonPublic(compiled_route_dispatcher::class);
		$include=dp_compiled_route_fixture('compiled_route_include.php');
		$bootstrap=dp_compiled_route_fixture('compiled_route_bootstrap.php');
		$request=Request::capture(['id'=>7]);
		$route=['parameters'=>['id'=>7]];
		Api::$execution_response=(object)['kind'=>'api-terminal'];
		$api_result=$private->invokeWithArguments('dispatch_handler',[['type'=>'callable','target'=>static fn()=>null],['api'=>['execution'=>[]]],$request,false]);
		$t->isTrue($api_result['emit']);
		$t->same('api-terminal',$api_result['result']->kind);
		Api::$execution_response=null;

		$result=$private->invokeWithArguments('dispatch_handler',[$include,$route,null,true]);
		$t->isFalse($result['emit']);
		$result=$private->invokeWithArguments('dispatch_handler',[$include,$route,null,false]);
		$t->same(2,$scenario->includeCount());
		$called=0;
		$result=$private->invokeWithArguments('dispatch_handler',[static function()use(&$called): void {$called++;},$route,null,true]);
		$t->isFalse($result['emit']);
		$t->same(1,$called);
		$result=$private->invokeWithArguments('dispatch_handler',[[
			'type'=>'include','target'=>$include,'bootstrap'=>$bootstrap,
		],$route,null,true]);
		$t->isFalse($result['emit']);
		$result=$private->invokeWithArguments('dispatch_handler',[[
			'type'=>'include','target'=>$include,
		],$route,null,false]);
		$t->isFalse($result['emit']);
		$result=$private->invokeWithArguments('dispatch_handler',[[
			'type'=>'callable','target'=>static function()use(&$called): void {$called++;},'bootstrap'=>$bootstrap,
		],$route,null,true]);
		$t->isFalse($result['emit']);
		$result=$private->invokeWithArguments('dispatch_handler',[[
			'type'=>'callable','target'=>static function()use(&$called): void {$called++;},
		],$route,null,false]);
		$t->same(3,$called);

		$controller=['type'=>'controller','class'=>Controller::class,'method'=>'statik','static'=>true];
		$result=$private->invokeWithArguments('dispatch_handler',[$controller,$route,$request,true]);
		$t->isTrue($result['emit']);
		$t->same('static',$result['result']->controller);
		$instance=$private->invokeWithArguments('invoke_controller',[[
			'type'=>'controller','class'=>Controller::class,'method'=>'instance','static'=>false,
		],$route,$request,false]);
		$t->same('instance',$instance->controller);
		$t->throws(static fn()=>$private->invokeWithArguments('invoke_controller',[['type'=>'controller'],$route,$request]),RuntimeException::class);
		$t->throws(static fn()=>$private->invokeWithArguments('dispatch_handler',[null,$route]),RuntimeException::class);

		$private->invokeWithArguments('bootstrap_handler',['plain']);
		$private->invokeWithArguments('bootstrap_handler',[['bootstrap'=>null]]);
		$private->invokeWithArguments('require_handler_file',[$include]);
		$private->invokeWithArguments('ensure_core_framework_loader');
		$first=$private->invokeWithArguments('no_emit_sentinel');
		$t->same($first,$private->invokeWithArguments('no_emit_sentinel'));
	})->tag('routing','compiled-dispatcher','coverage')->group('framework-coverage');
}
