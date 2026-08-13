<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY',[
		'enabled'=>['core'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

suite('Core runtime catalogs and tracing contracts')
	->contract('core.runtime-catalogs', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:core')
	->through('module-catalog', 'dialback-catalog', 'runtime-facade', 'trace')
	->isolation('case')
	->tag('core', 'runtime-catalogs')
	->group('framework-coverage');

/**
 * Runtime is a static facade. These deliberately tiny doubles keep its branch
 * tests focused on facade behavior instead of booting an application tree.
 */
function dp_core_runtime_catalogs_load_runtime_stubs(): void {
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre;

class Application {
	public static ?self $current=null;
	public static array $rootArguments=[];
	public static array $rootsResult=['/apps'];
	public static array $availableResult=['demo'];

	public function __construct(public string $id='demo'){}
	public static function current(?string $projectRoot=null): ?self {
		self::$rootArguments[]=['current',$projectRoot];
		return self::$current;
	}
	public static function roots(string $projectRoot): array {
		self::$rootArguments[]=['roots',$projectRoot];
		return self::$rootsResult;
	}
	public static function available(string $projectRoot): array {
		self::$rootArguments[]=['available',$projectRoot];
		return self::$availableResult;
	}
	public static function catalog(?string $projectRoot=null): ApplicationCatalog {
		self::$rootArguments[]=['catalog',$projectRoot];
		return new ApplicationCatalog($projectRoot);
	}
	public function toArray(): array { return ['id'=>$this->id]; }
}

class ApplicationCatalog {
	public function __construct(public ?string $projectRoot=null){}
}

class ModuleCatalog {
	public function __construct(public string $kind='all'){}
}

class Module {
	public static function catalog(): ModuleCatalog { return new ModuleCatalog('all'); }
	public static function enabledCatalog(): ModuleCatalog { return new ModuleCatalog('enabled'); }
	public static function disabledCatalog(): ModuleCatalog { return new ModuleCatalog('disabled'); }
}

class RuntimeState {
	public array $arguments;
	public function __construct(mixed ...$arguments){ $this->arguments=$arguments; }
}

class BootstrapPlan {
	public function __construct(public string $kind='current'){}
}

class BootstrapCatalog {
	public function __construct(public ?string $projectRoot=null){}
}

class Bootstrap {
	public static array $arguments=[];
	public static ?BootstrapPlan $currentResult=null;
	public static function current(?string $projectRoot=null): ?BootstrapPlan {
		self::$arguments[]=['current',$projectRoot];
		return self::$currentResult;
	}
	public static function catalog(?string $projectRoot=null): BootstrapCatalog {
		self::$arguments[]=['catalog',$projectRoot];
		return new BootstrapCatalog($projectRoot);
	}
}

class ClientAddress {
	public static ?self $currentResult=null;
	public function __construct(private string $value='198.51.100.8'){}
	public static function current(): self { return self::$currentResult ?? new self(); }
	public function ip(): string { return $this->value; }
}

class RuntimeTrace {
	public function __construct(
		public ?string $renderTraceId=null,
		public ?string $templateName=null,
		public ?array $manifest=null,
		public array $bindingTrace=[],
		public array $sqlTraces=[]
	){}
}
PHP);

	$root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules/core';
	require_once $root.'/Framework/Runtime.php';
}

test('module catalog normalizes filters counts caches iterates and serializes definitions',static function(Context $t): void {
	$root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules/core/Framework';
	require_once $root.'/ModuleDefinition.php';
	require_once $root.'/ModuleCatalog.php';

	$alpha=new \Dataphyre\ModuleDefinition('Alpha','1.0',true);
	$zulu=new \Dataphyre\ModuleDefinition('Zulu','2.0',false);
	$catalog=new \Dataphyre\ModuleCatalog([
		0=>$alpha,
		' ZULU '=>$zulu,
		'array-module'=>['module'=>'ArrayModule','enabled'=>true],
		'invalid'=>'ignored',
	]);

	$t->same(['alpha','array-module','zulu'],$catalog->names());
	$t->same([$alpha],array_values(array_filter($catalog->all(),static fn($entry): bool=>$entry===$alpha)));
	$t->same($alpha,$catalog->first());
	$t->same($alpha,$catalog->get(' ALPHA '));
	$t->same(null,$catalog->get(''));
	$t->same(null,$catalog->get('missing'));
	$t->isTrue($catalog->has('zulu'));
	$t->isFalse($catalog->has('missing'));
	$t->same(['alpha','array-module'],$catalog->enabledNames());
	$t->same(['zulu'],$catalog->disabledNames());
	$t->same(['alpha','array-module'],$catalog->enabledNames());
	$t->same(['zulu'],$catalog->disabledNames());
	$t->same(2,$catalog->enabledCount());
	$t->same(1,$catalog->disabledCount());
	$t->same(['enabled'=>2,'disabled'=>1],$catalog->enabledDisabledCounts());
	$t->same(['enabled'=>2,'disabled'=>1],$catalog->enabledDisabledCounts());

	$enabled=$catalog->enabled();
	$disabled=$catalog->disabled();
	$t->same(['alpha','array-module'],$enabled->names());
	$t->same(['zulu'],$disabled->names());
	$t->same(3,count($catalog));
	$t->same($catalog->all(),iterator_to_array($catalog->getIterator()));
	$payload=$catalog->toArray();
	$t->same(3,count($payload));
	$t->hasConsistentSerialization($catalog,$payload);

	$definitions=[
		'beta'=>['module'=>'beta','enabled'=>false],
		'alpha'=>['module'=>'alpha','enabled'=>true],
	];
	$first=\Dataphyre\ModuleCatalog::fromDefinitions($definitions);
	$cached=\Dataphyre\ModuleCatalog::fromDefinitions($definitions);
	$t->same(['alpha','beta'],$first->names());
	$t->same($first->names(),$cached->names());
	$t->same(null,(new \Dataphyre\ModuleCatalog())->first());
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('dialback catalog normalizes scopes selections caches iteration and diagnostics',static function(Context $t): void {
	$root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''),'/\\').'/modules/core/Framework';
	require_once $root.'/DialbackEvent.php';
	require_once $root.'/DialbackCatalog.php';

	$orders=new \Dataphyre\DialbackEvent('orders.created',[static fn(): string=>'created']);
	$catalog=new \Dataphyre\DialbackCatalog(null,[
		'zeta.event'=>[static fn(): string=>'zeta','invalid'],
		'orders.created'=>$orders,
		'orders.updated'=>[static fn(): string=>'updated'],
		'invalid'=>'ignored',
	]);

	$t->same(null,$catalog->prefix());
	$t->same(['orders.created','orders.updated','zeta.event'],$catalog->names());
	$t->same($orders,$catalog->first());
	$t->same($orders,$catalog->get(' orders.created '));
	$t->same(null,$catalog->get(''));
	$t->same(null,$catalog->get('missing'));
	$t->isTrue($catalog->has(' orders.updated '));
	$t->isFalse($catalog->has(''));
	$t->isFalse($catalog->has('missing'));
	$t->same(3,count($catalog));
	$t->same(3,$catalog->callbackCount());
	$t->same($catalog,$catalog->scope(null));
	$t->same($catalog,$catalog->scope('   '));

	$ordersScope=$catalog->scope(' orders. ');
	$t->same('orders.',$ordersScope->prefix());
	$t->same(['orders.created','orders.updated'],$ordersScope->names());
	$t->same($ordersScope,$catalog->scope('orders.'));
	$t->same([],$catalog->scope('missing.')->names());

	$requested=['zeta.event','',7,'orders.created','zeta.event','missing'];
	$only=$catalog->only($requested);
	$t->same(['orders.created','zeta.event'],$only->names());
	$t->same($only,$catalog->only($requested));
	$t->same(null,$only->prefix());
	$t->same($catalog->all(),iterator_to_array($catalog->getIterator()));
	$payload=$catalog->toArray();
	$t->same(3,$payload['callback_count']);
	$t->same(3,count($payload['entries']));
	$t->hasConsistentSerialization($catalog,$payload);
	$t->same(null,(new \Dataphyre\DialbackCatalog())->first());
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime facade exposes active application module bootstrap client and state surfaces',static function(Context $t): void {
	dp_core_runtime_catalogs_load_runtime_stubs();
	$runtime=$t->nonPublic(\dataphyre\runtime::class);
	$runtime->replacePropertyForTest('current_project_root','C:/runtime/project');
	\Dataphyre\Application::$current=new \Dataphyre\Application('storefront');
	\Dataphyre\Bootstrap::$currentResult=new \Dataphyre\BootstrapPlan('active');
	\Dataphyre\ClientAddress::$currentResult=new \Dataphyre\ClientAddress('203.0.113.9');

	$t->isTrue(\Dataphyre\Runtime::tracingEnabled());
	$t->same('C:/runtime/project',\Dataphyre\Runtime::projectRoot());
	$t->same('storefront',\Dataphyre\Runtime::applicationId());
	$t->isTrue(\Dataphyre\Runtime::hasApplication());
	$t->same(\Dataphyre\Application::$current,\Dataphyre\Runtime::application());
	$t->same(['id'=>'storefront'],\Dataphyre\Runtime::applicationDefinition());
	$t->same(['/apps'],\Dataphyre\Runtime::applicationRoots());
	$t->same(['demo'],\Dataphyre\Runtime::availableApplications());
	$t->same('C:/runtime/project',\Dataphyre\Runtime::applications()->projectRoot);
	$t->same('all',\Dataphyre\Runtime::modules()->kind);
	$t->same('enabled',\Dataphyre\Runtime::enabledModules()->kind);
	$t->same('disabled',\Dataphyre\Runtime::disabledModules()->kind);

	$state=\Dataphyre\Runtime::state();
	$t->instanceOf(\Dataphyre\RuntimeState::class,$state);
	$t->isTrue($state->arguments[0]);
	$t->same('C:/runtime/project',$state->arguments[1]);
	$t->same('storefront',$state->arguments[2]->id);
	$t->same(['/apps'],$state->arguments[3]);
	$t->instanceOf(\Dataphyre\ApplicationCatalog::class,$state->arguments[4]);
	$t->instanceOf(\Dataphyre\ModuleCatalog::class,$state->arguments[5]);

	$t->same(\Dataphyre\Bootstrap::$currentResult,\Dataphyre\Runtime::bootstrap());
	$t->same('C:/runtime/project',\Dataphyre\Runtime::bootstraps()->projectRoot);
	$t->same('203.0.113.9',\Dataphyre\Runtime::clientIp());
	$t->same(\Dataphyre\ClientAddress::$currentResult,\Dataphyre\Runtime::clientAddress());
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime facade covers legacy and absent project application fallbacks',static function(Context $t): void {
	define('Dataphyre\\ROOTPATH',['root'=>'C:/legacy/project///']);
	dp_core_runtime_catalogs_load_runtime_stubs();
	$runtime=$t->nonPublic(\dataphyre\runtime::class);
	$runtime->replacePropertyForTest('current_project_root',null);
	\Dataphyre\Application::$current=null;

	$t->same('C:/legacy/project',\Dataphyre\Runtime::projectRoot());
	$t->same(null,\Dataphyre\Runtime::applicationId());
	$t->isFalse(\Dataphyre\Runtime::hasApplication());
	$t->same(null,\Dataphyre\Runtime::applicationDefinition());
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime facade covers missing project root collection fallbacks',static function(Context $t): void {
	define('Dataphyre\\ROOTPATH',['root'=>'']);
	dp_core_runtime_catalogs_load_runtime_stubs();
	$runtime=$t->nonPublic(\dataphyre\runtime::class);
	$runtime->replacePropertyForTest('current_project_root',null);

	$t->same(null,\Dataphyre\Runtime::projectRoot());
	$t->same([],\Dataphyre\Runtime::applicationRoots());
	$t->same([],\Dataphyre\Runtime::availableApplications());
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime trace normalizes strings objects manifests bindings and no SQL backend',static function(Context $t): void {
	dp_core_runtime_catalogs_load_runtime_stubs();

	$existing=new \Dataphyre\RuntimeTrace('existing');
	$t->same($existing,\Dataphyre\Runtime::trace($existing));
	$t->same('trace-id',\Dataphyre\Runtime::trace(' trace-id ')->renderTraceId);
	$t->same(null,\Dataphyre\Runtime::trace('   ')->renderTraceId);
	$t->same('by-id',\Dataphyre\Runtime::traceById(' by-id ')->renderTraceId);

	$full=new class {
		public function renderTraceId(): ?string { return ' object-id '; }
		public function templateName(): mixed { return ' orders.tpl '; }
		public function bindingTrace(): mixed { return [['path'=>'orders']]; }
		public function hasManifest(): bool { return true; }
		public function manifest(): object {
			return new class { public function toArray(): mixed { return ['version'=>1]; } };
		}
	};
	$trace=\Dataphyre\Runtime::trace($full);
	$t->same('object-id',$trace->renderTraceId);
	$t->same('orders.tpl',$trace->templateName);
	$t->same([['path'=>'orders']],$trace->bindingTrace);
	$t->same(['version'=>1],$trace->manifest);
	$t->same([],$trace->sqlTraces);

	$noManifest=new class {
		public function renderTraceId(): ?string { return null; }
		public function templateName(): mixed { return 7; }
		public function bindingTrace(): mixed { return 'invalid'; }
		public function hasManifest(): bool { return false; }
		public function manifest(): object { return new \stdClass(); }
	};
	$trace=\Dataphyre\Runtime::trace($noManifest);
	$t->same(null,$trace->renderTraceId);
	$t->same(null,$trace->templateName);
	$t->same([],$trace->bindingTrace);
	$t->same(null,$trace->manifest);

	$badManifest=new class {
		public function bindingTrace(): array { return []; }
		public function hasManifest(): bool { return true; }
		public function manifest(): object {
			return new class { public function toArray(): mixed { return 'invalid'; } };
		}
	};
	$t->same(null,\Dataphyre\Runtime::trace($badManifest)->manifest);

	$fallbackManifest=new class {
		public function bindingTrace(): array { return []; }
		public function hasManifest(): bool { return true; }
		public function manifest(): object { return new \stdClass(); }
		public function toArray(): mixed { return ['fallback'=>true]; }
	};
	$t->same(['fallback'=>true],\Dataphyre\Runtime::trace($fallbackManifest)->manifest);

	$invalidFallback=new class {
		public function bindingTrace(): array { return []; }
		public function toArray(): mixed { return 'invalid'; }
	};
	$t->same(null,\Dataphyre\Runtime::trace($invalidFallback)->manifest);
	$t->same(null,\Dataphyre\Runtime::trace(new \stdClass())->manifest);
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime trace attaches normalized SQL traces and clamps trace limits',static function(Context $t): void {
	dp_core_runtime_catalogs_load_runtime_stubs();
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Database;
final class DB {
	public static mixed $response=[];
	public static array $calls=[];
	public static function recentTracesByContext(array $context,int $limit): mixed {
		self::$calls[]=[$context,$limit];
		return self::$response;
	}
}
PHP);

	\Dataphyre\Database\DB::$response=[['query'=>'select 1']];
	$trace=\Dataphyre\Runtime::trace(' sql-trace ',-20);
	$t->same([['query'=>'select 1']],$trace->sqlTraces);
	$t->same([[['render_trace_id'=>'sql-trace'],1]],\Dataphyre\Database\DB::$calls);

	\Dataphyre\Database\DB::$response='invalid';
	$t->same([],\Dataphyre\Runtime::trace('sql-trace',5)->sqlTraces);
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');

test('runtime tracing disabled returns an empty trace without inspecting the source',static function(Context $t): void {
	define('Dataphyre\\IS_PRODUCTION',true);
	dp_core_runtime_catalogs_load_runtime_stubs();

	$t->isFalse(\Dataphyre\Runtime::tracingEnabled());
	$trace=\Dataphyre\Runtime::trace(new class {
		public function renderTraceId(): string { throw new \RuntimeException('must not inspect'); }
	});
	$t->same(null,$trace->renderTraceId);
	$t->same(null,$trace->templateName);
	$t->same(null,$trace->manifest);
	$t->same([],$trace->bindingTrace);
	$t->same([],$trace->sqlTraces);
})->tag('core','runtime-catalogs','coverage')->group('framework-coverage');
