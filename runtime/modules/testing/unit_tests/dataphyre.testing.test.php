<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test {
	if(!function_exists(__NAMESPACE__.'\\mkdir')){
	final class DpTestKitFailureSeams {
			public static function value(string $seam,mixed $default=null): mixed {
				$state=TestState::channelIfActive('testing.failures');
				return $state===null ? $default : $state->get($seam,$default);
			}
		}
		function mkdir(string $directory,int $permissions=0777,bool $recursive=false): bool {
			if(DpTestKitFailureSeams::value('mkdir',false)===true){ return false; }
			return \mkdir($directory,$permissions,$recursive);
		}
		function tempnam(string $directory,string $prefix): string|false { // dataphyre-test-architecture: exempt[unmanaged-temporary-file] reason="Namespace failure shim must replace the native temporary-file function."
			if(DpTestKitFailureSeams::value('tempnam',false)===true){ return false; }
			return \tempnam($directory,$prefix); // dataphyre-test-architecture: exempt[unmanaged-temporary-file] reason="Namespace failure shim delegates successful calls to the native function."
		}
		function file_put_contents(string $filename,mixed $data,int $flags=0,mixed $context=null): int|false {
			if(DpTestKitFailureSeams::value('file_put_contents',false)===true){ return false; }
			return $context===null
				? \file_put_contents($filename,$data,$flags)
				: \file_put_contents($filename,$data,$flags,$context);
		}
		function file_get_contents(string $filename,bool $use_include_path=false,mixed $context=null,int $offset=0,?int $length=null): string|false {
			if(DpTestKitFailureSeams::value('file_get_contents')===$filename){ return false; }
			if($length!==null){ return \file_get_contents($filename,$use_include_path,$context,$offset,$length); }
			return \file_get_contents($filename,$use_include_path,$context,$offset);
		}
		function unlink(string $filename,mixed $context=null): bool {
			if(DpTestKitFailureSeams::value('unlink')===$filename){ return false; }
			return $context===null ? \unlink($filename) : \unlink($filename,$context);
		}
		function scandir(string $directory,int $sorting_order=\SCANDIR_SORT_ASCENDING,mixed $context=null): array|false {
			if(DpTestKitFailureSeams::value('scandir')===$directory){ return false; }
			return $context===null ? \scandir($directory,$sorting_order) : \scandir($directory,$sorting_order,$context);
		}
		function rmdir(string $directory,mixed $context=null): bool {
			if(DpTestKitFailureSeams::value('rmdir')===$directory){ return false; }
			return $context===null ? \rmdir($directory) : \rmdir($directory,$context);
		}
		function define(string $constant_name,mixed $value): bool {
			if(DpTestKitFailureSeams::value('define')===$constant_name){ return false; }
			return \define($constant_name,$value);
		}
	}
}

namespace {

use Dataphyre\Test\Context;
use Dataphyre\Test\AssertionFailed;
use Dataphyre\Test\CaseDefinition;
use Dataphyre\Test\DeferredCleanupFailed;
use Dataphyre\Test\Framework;
use Dataphyre\Test\FakeHttpRequest;
use Dataphyre\Test\FakeHttpResponse;
use Dataphyre\Test\FailureCorpus;
use Dataphyre\Test\GlobalState;
use Dataphyre\Test\HttpResponseStub;
use Dataphyre\Test\SuiteDefinition;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\define_test_symbols;
use function Dataphyre\Test\fixture;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

final class DpTestKitGlobalContract {
	public static function has(string $name): bool {
		return array_key_exists($name,$GLOBALS); // dataphyre-test-architecture: exempt[raw-global-variable] reason="GlobalState contract adapter must observe native PHP global existence."
	}
	public static function value(string $name,mixed $default=null): mixed {
		return self::has($name) ? $GLOBALS[$name] : $default; // dataphyre-test-architecture: exempt[raw-global-variable] reason="GlobalState contract adapter must read native PHP global values."
	}
	public static function put(string $name,mixed $value): void {
		$GLOBALS[$name]=$value; // dataphyre-test-architecture: exempt[raw-global-variable] reason="GlobalState contract adapter must write native PHP global values."
	}
	public static function forget(string ...$names): void {
		foreach($names as $name){ unset($GLOBALS[$name]); } // dataphyre-test-architecture: exempt[raw-global-variable] reason="GlobalState contract adapter must remove native PHP global values."
	}
}

final class DpTestKitNonPublicFixture {
	private static string $staticValue='original';

	public function __construct(private string $value='instance') {}

	private function describe(string $suffix): string {
		return $this->value.'-'.$suffix;
	}

	private function append(string &$value, string $suffix): string {
		$value.=$suffix;
		return $value;
	}

	private function variadic(string ...$values): string {
		return implode('-', $values);
	}

	public function label(): string {
		return $this->value;
	}

	public function needsArgument(string $value): string {
		return $value;
	}
}

final class DpTestKitSerializableFixture implements JsonSerializable {
	public function toArray(): array {
		return ['name'=>'Dataphyre','meta'=>['version'=>1]];
	}

	public function jsonSerialize(): array {
		return $this->toArray();
	}
}

final class DpTestKitHiddenSerializationFixture implements JsonSerializable {
	private function toArray(): array {
		return ['hidden'=>true];
	}

	public function jsonSerialize(): array {
		return ['hidden'=>true];
	}
}

final class DpTestKitMagicPathFixture {
	public function __isset(string $property): bool {
		return $property==='virtual';
	}

	public function __get(string $property): mixed {
		return $property==='virtual' ? null : throw new RuntimeException('Unexpected virtual property: '.$property);
	}
}

suite('Dataphyre TestKit')
	->contract('testing.testkit-runtime', 1)
	->layer('unit')
	->risk('critical')
	->watches('module:testing')
	->through('context', 'managed-state', 'assertion')
	->isolation('case')
	->tag('testkit')
	->group('framework-coverage')
	->maxMillis(2000);

dataset('strict equality shapes', [
	'string'=>['dataphyre', 'dataphyre'],
	'integer'=>[42, 42],
	'array'=>[['tenant'=>'demo_tenant'], ['tenant'=>'demo_tenant']],
]);

fixture('temp_file', static function(Context $t): string {
	return $t->workspace('testkit-fixture')->path('sample.txt');
}, static function(): void {});

test('assertions accept dataset rows', static function(Context $t, mixed $actual, mixed $expected): void {
	$t->same($expected, $actual);
	$t->notNull($actual);
})->with('strict equality shapes')->tag('dataset', 'assertion');

test('fixtures are isolated per worker', static function(Context $t): void {
	$path=$t->fixture('temp_file');
	file_put_contents($path, 'ok');
	$t->same('ok', file_get_contents($path));
	$t->matches('/testkit-fixture-/', $path);
})->uses('temp_file')->tag('fixture')->maxMillis(1000);

test('throw assertions return the throwable', static function(Context $t): void {
	$throwable=$t->throws(static fn()=>throw new RuntimeException('expected'), RuntimeException::class);
	$t->same('expected', $throwable->getMessage());
})->tag('assertion');

test('expectation chains keep tests compact', static function(Context $t): void {
	$payload=['tenant'=>'demo_tenant', 'plan'=>'enterprise'];
	$t->expect($payload)
		->toHaveKey('tenant')
		->toHaveKeys(['tenant','plan'])
		->toHaveExactKeys(['tenant','plan'])
		->toHaveCount(2);
	$t->expect($payload['tenant'])->toBe('demo_tenant')->toContain('demo');
	$t->expect(strlen($payload['plan']))->toBeGreaterThan(5)->toBeLessThan(20);
	$calls=0;
	$stable=$t->producesStableResult(static function()use(&$calls): string {
		$calls++;
		return 'stable';
	});
	$t->same('stable',$stable);
	$t->same(2,$calls);
})->tag('expectation', 'assertion');

test('common fakes cover app service boundaries', static function(Context $t): void {
	$clock=$t->fakeClock('2026-01-01 00:00:00 UTC')->advance(60);
	$t->same(60, $clock->timestamp()-strtotime('2026-01-01 00:00:00 UTC'));

	$storage=$t->fakeStorage();
	$storage->put('tenant/logo.txt', 'logo');
	$t->same('logo', $storage->get('tenant/logo.txt'));
	$t->expect($storage->files('tenant'))->toHaveCount(1);

	$mailer=$t->fakeMailer();
	$mailer->send('ops@example.test', 'Ready', ['tenant'=>'demo_tenant']);
	$t->same(1, $mailer->count());
	$t->same('Ready', $mailer->last()['subject'] ?? null);

	$http=$t->fakeHttp();
	$http->respond('POST', 'https://example.test/hook', 202, ['ok'=>true]);
	$response=$http->request('POST', 'https://example.test/hook', ['id'=>42]);
	$t->same(202, $response['status']);
	$t->same(1, count($http->requests()));
})->tag('fakes', 'service-boundary');

test('portable PHP stubs define isolated symbols without eval', static function(Context $t): void {
	$failures=$t->state('testing.failures');
	$t->same(1, define_test_symbols('namespace Dataphyre\\PortableStub; function label(): string { return "portable"; }'));
	$t->same('portable', \Dataphyre\PortableStub\label());
	$t->same(1, $t->defineSymbols("\xEF\xBB\xBF<?php namespace Dataphyre\\PortableStub; final class Loaded { public const VALUE='loaded'; }"));
	$t->same('loaded', \Dataphyre\PortableStub\Loaded::VALUE);
	$t->throws(
		static fn()=>$t->defineSymbols('throw new \\RuntimeException("portable stub failure");'),
		RuntimeException::class
	);

	$stub=$t->tempFile("<?php return ['fixture'=>'loaded'];", 'portable-stub');
	$t->same(['fixture'=>'loaded'], $t->loadStub($stub));
	$t->same(true, $t->loadStub($stub));
	$t->same(true, \Dataphyre\Test\load_test_stub($stub));
	$t->throws(static fn()=>$t->loadStub($stub.'.missing'), RuntimeException::class);
	$t->throws(static fn()=>$t->defineSymbols('  '), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->defineSymbols('namespace Broken; function {'), InvalidArgumentException::class);
	$failures->put('tempnam',true);
	$t->throws(static fn()=>define_test_symbols('namespace Dataphyre\\PortableStub; function missing_temp(): void {}'), RuntimeException::class);
	$failures->put('tempnam',false)->put('file_put_contents',true);
	$t->throws(static fn()=>define_test_symbols('namespace Dataphyre\\PortableStub; function missing_write(): void {}'), RuntimeException::class);
	$failures->put('file_put_contents',false);
})->tag('portable-stubs', 'ergonomics');

test('PHP bootstrap probes describe constants loaded types and idempotency without raw transforms', static function(Context $t): void {
	$workspace=$t->workspace('testkit-php-bootstrap');
	$bootstrap=$workspace->file('Framework/Bootstrap.php', <<<'PHP'
<?php
namespace Dataphyre\Test\BootstrapFixture;
if(!defined('DATAPHYRE_TEST_BOOTSTRAP_PROBE')){
	define('DATAPHYRE_TEST_BOOTSTRAP_PROBE','ready');
}
if(!class_exists(PublishedType::class,false)){
	final class PublishedType {}
}
PHP);

	$probe=$t->phpBootstrap(' '.$bootstrap.' ')
		->defines('DATAPHYRE_TEST_BOOTSTRAP_PROBE','ready')
		->providesTypes(\Dataphyre\Test\BootstrapFixture\PublishedType::class)
		->reloadsSafely();

	$t->same(realpath($bootstrap),$probe->path());
	$t->same(2,$probe->loadCount());
	$t->throws(static fn()=>$t->phpBootstrap($workspace->path('missing.php')),InvalidArgumentException::class);
	$t->throws(static fn()=>$probe->defines('DATAPHYRE_TEST_BOOTSTRAP_MISSING'),AssertionFailed::class);
	$t->throws(static fn()=>$probe->providesTypes('Dataphyre\\Test\\BootstrapFixture\\MissingType'),AssertionFailed::class);
})->tag('bootstrap','ergonomics','portable-symbols');

test('temporary workspaces replace path assembly and handwritten cleanup', static function(Context $t): void {
	$failures=$t->state('testing.failures');
	$workspace=$t->workspace('testkit-workspace');
	$t->same($workspace->root(), $workspace->path());
	$t->same($workspace->root().'/fixtures/result.json', $workspace->path('fixtures//./cache/../result.json'));
	$t->same($workspace->root(), $workspace->path('folder/..'));
	$t->throws(static fn()=>$workspace->path('../outside'), InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path('/absolute'), InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path('C:/absolute'), InvalidArgumentException::class);
	$t->throws(static fn()=>$workspace->path("bad\0path"), InvalidArgumentException::class);

	$t->same($workspace->root().'/fixtures', $workspace->directory('fixtures'));
	$source=$workspace->file('fixtures/source.txt', 'portable fixture');
	$t->same('portable fixture', file_get_contents($source));
	$sourceMtime=filemtime($source);
	$t->same($source, $workspace->advanceMtime('fixtures/source.txt', 5));
	$t->greaterThan((int)$sourceMtime, (int)filemtime($source));
	$t->throws(static fn()=>$workspace->advanceMtime('fixtures/missing.txt'), RuntimeException::class);
	$containedParent=$t->tempDirectory('testkit-contained-parent');
	$contained=$t->workspaceIn($containedParent,'repo-relative-fixture');
	$t->startsWith($containedParent.'/repo-relative-fixture-',$contained->root());
	$t->same('contained',file_get_contents($contained->file('proof.txt','contained')));
	$t->throws(static fn()=>$t->workspaceIn($containedParent.'/missing','invalid-parent'),InvalidArgumentException::class);
	$copy=$workspace->copy($source, 'copies/copied.txt');
	$t->same('portable fixture', file_get_contents($copy));
	$t->same($workspace, $workspace->installCodeWorkerTooling());
	foreach(['bootstrap.php', 'PhpRuntime.php', 'TypeInventory.php', 'PathSemantics.php', 'CoverageLineNormalizer.php', 'code_worker.php'] as $toolingFile){
		$t->isTrue(is_file($workspace->path('runtime/modules/testing/tooling/'.$toolingFile)), $toolingFile);
	}
	foreach(['Context.php', 'Contracts/TestContext.php', 'AssertsValues.php'] as $testKitFile){
		$t->isTrue(is_file($workspace->path('runtime/modules/testing/tooling/TestKit/'.$testKitFile)), $testKitFile);
	}
	$t->throws(static fn()=>$workspace->copy($source.'.missing', 'copies/missing.txt'), RuntimeException::class);
	$failures->put('file_get_contents',$source);
	$t->throws(static fn()=>$workspace->copy($source, 'copies/unreadable.txt'), RuntimeException::class);
	$failures->forget('file_get_contents')->put('mkdir',true);
	$t->throws(static fn()=>$workspace->directory('cannot-create'), RuntimeException::class);
	$failures->put('mkdir',false)->put('file_put_contents',true);
	$t->throws(static fn()=>$workspace->file('fixtures/cannot-write.txt', 'no'), RuntimeException::class);
	$failures->put('file_put_contents',false);
})->tag('managed-cleanup', 'workspace', 'ergonomics');

test('failure corpus persistence reports directory and write boundaries precisely', static function(Context $t): void {
	$failures=$t->state('testing.failures');
	$workspace=$t->workspace('testkit-corpus-failures');

	$failures->put('mkdir', true);
	$t->throws(
		static fn() => FailureCorpus::open($workspace->path('missing/corpus.json'))->recordReplay('testing.corpus', 'token'),
		RuntimeException::class
	);

	$failures->put('mkdir', false);
	$directory=$workspace->directory('existing');
	$failures->put('file_put_contents', true);
	$t->throws(
		static fn() => FailureCorpus::open($directory.'/corpus.json')->recordReplay('testing.corpus', 'token'),
		RuntimeException::class
	);
	$failures->put('file_put_contents', false);
})->tag('managed-cleanup', 'failure-corpus', 'filesystem-boundaries');

test('HTTP stubs describe responses handlers and recorded requests semantically', static function(Context $t): void {
	$http=$t->fakeHttp()
		->respondJson('GET', 'https://example.test/catalog', ['items'=>[1,2]])
		->respondText('POST', 'https://example.test/token', 'accepted', 202)
		->respondForm('POST', 'https://example.test/form-response', ['token'=>'ready'])
		->respondFailure('GET', 'https://example.test/offline', 503, 'offline')
		->respondUsing('DELETE', 'https://example.test/item/1', static fn(FakeHttpRequest $request): FakeHttpResponse => FakeHttpResponse::text($request->method(), 204));

	$catalog=$http->get('https://example.test/catalog');
	$t->same(200, $catalog['status']);
	$t->same(['items'=>[1,2]], json_decode((string)$catalog['body'], true));
	$t->same('application/json', $catalog['headers']['content-type']);
	$t->same(202, $http->post('https://example.test/token')['status']);
	$t->same('DELETE', $http->delete('https://example.test/item/1')['body']);
	$t->same(404, $http->put('https://example.test/missing')['status']);
	$t->same('token=ready', $http->post('https://example.test/form-response')['body']);
	$t->same(503, $http->get('https://example.test/offline')['status']);
	$http
		->respondNext('GET', 'https://example.test/queue', FakeHttpResponse::text('first'))
		->respondNext('GET', 'https://example.test/queue', static fn(FakeHttpRequest $request): array=>HttpResponseStub::text($request->method().'-second'));
	$t->same('first', $http->get('https://example.test/queue')['body']);
	$t->same('GET-second', $http->get('https://example.test/queue')['body']);

	$http->respondJson('POST', 'https://example.test/form', ['ok'=>true]);
	$handler=$http->handler(static function(mixed $payload): array {
		parse_str((string)$payload, $decoded);
		return $decoded;
	});
	$handled=$handler('POST', 'https://example.test/form', 'tenant=demo', ['x-test'=>'yes'], ['ignored'=>true]);
	$t->same(200, $handled['status']);
	$formRequest=$http->lastRequest();
	$t->same('POST', $formRequest->method());
	$t->same('https://example.test/form', $formRequest->url());
	$t->same(['tenant'=>'demo'], $formRequest->body());
	$t->same(['tenant'=>'demo'], $formRequest->form());
	$t->same('demo', $formRequest->formValue('tenant'));
	$t->same('fallback', $formRequest->formValue('missing', 'fallback'));
	$t->same('yes', $formRequest->header('X-Test'));
	$t->same('missing', $formRequest->header('missing', 'missing'));
	$t->same([['ignored'=>true]], $formRequest->context());
	$t->same(404, ($http->handler())('GET', 'https://example.test/unconfigured')['status']);
	$t->same(count($http->requests()), count($http->requestObjects()));
	$t->throws(static fn()=>(new \Dataphyre\Test\FakeHttp())->lastRequest(), OutOfBoundsException::class);

	$http->post('https://example.test/json-request', '{"id":42}', ['content-type'=>'application/json']);
	$jsonRequest=$http->lastRequest();
	$t->same(['id'=>42], $jsonRequest->json());
	$t->same(42, $jsonRequest->jsonValue('id'));
	$t->same('fallback', $jsonRequest->jsonValue('missing', 'fallback'));
	$t->same([], (new FakeHttpRequest('POST', 'test://invalid', 'not-json'))->json());
	$t->same(['id'=>7], (new FakeHttpRequest('POST', 'test://array', ['id'=>7]))->json());
	$t->same(['id'=>7], (new FakeHttpRequest('POST', 'test://array', ['id'=>7]))->form());
	$stringFormRequest=new FakeHttpRequest('POST', 'test://form', 'token=ready&scope=write', ['x-test'=>'yes']);
	$t->same(['token'=>'ready','scope'=>'write'], $stringFormRequest->form());
	$t->same(['x-test'=>'yes'], $stringFormRequest->headers());
	$http->assertJsonRequested($t, 'POST', 'https://example.test/json-request', ['id'=>42]);
	$http->assertFormRequested($t, 'POST', 'https://example.test/form', ['tenant'=>'demo']);
	$http->assertHeaderSent($t, 'POST', 'https://example.test/json-request', 'Content-Type', 'application/json');
	$t->throws(static fn()=>$http->assertJsonRequested($t, 'POST', 'https://example.test/json-request', ['id'=>99]), \Dataphyre\Test\AssertionFailed::class);
	$t->throws(static fn()=>$http->assertFormRequested($t, 'POST', 'https://example.test/form', ['tenant'=>'other']), \Dataphyre\Test\AssertionFailed::class);
	$t->throws(static fn()=>$http->assertHeaderSent($t, 'POST', 'https://example.test/json-request', 'x-missing', 'value'), \Dataphyre\Test\AssertionFailed::class);

	$t->same(503, HttpResponseStub::failure(503, 'offline')['status']);
	$t->same('plain', HttpResponseStub::text('plain')['body']);
	$t->same('{"ok":true}', HttpResponseStub::json(['ok'=>true])['body']);
	$t->same('', FakeHttpResponse::empty()->toArray()['body']);
	$t->same('token=ready', FakeHttpResponse::form(['token'=>'ready'])->toArray()['body']);
	$http->respondUsing('GET', 'https://example.test/invalid', static fn(FakeHttpRequest $request): string=>'invalid');
	$t->throws(static fn()=>$http->get('https://example.test/invalid'), UnexpectedValueException::class);
	$http->respondUsing('GET', 'https://example.test/sparse', static fn(FakeHttpRequest $request): array=>['status'=>'201','headers'=>'invalid']);
	$sparse=$http->get('https://example.test/sparse');
	$t->same(201, $sparse['status']);
	$t->same([], $sparse['headers']);
})->tag('fakes', 'http', 'ergonomics');

test('SQL fake can reject unbound writes', static function(Context $t): void {
	$sql=$t->fakeSql()->rejectUnboundWrites();
	$sql->query('select * from products where id = ?', [42]);
	$t->throws(static fn()=>$sql->query('update products set price_minor = 100'), Dataphyre\Test\AssertionFailed::class);
	$sql->assertNoUnboundWrites($t);
})->tag('fakes', 'sql-safety');

test('framework bootstrap declares module intent and exposes readable paths', static function(Context $t): void {
	$dataphyreRoot=str_replace('\\', '/', rtrim((string)ROOTPATH['common_dataphyre'], '/\\'));
	$t->same($dataphyreRoot, dataphyre_path());
	$t->same($dataphyreRoot.'/runtime/modules', dataphyre_path('runtime//./cache/../modules'));
	$t->throws(static fn()=>dataphyre_path('../outside'), InvalidArgumentException::class);
	$t->throws(static fn()=>dataphyre_path('/absolute'), InvalidArgumentException::class);
	$t->throws(static fn()=>dataphyre_path('C:/absolute'), InvalidArgumentException::class);
	$t->throws(static fn()=>dataphyre_path("bad\0path"), InvalidArgumentException::class);

	$framework=framework(['core'], [
		'constants'=>['DP_TESTKIT_BOOTSTRAP_CONSTANT'=>'ready'],
		'functions'=>['Dataphyre\\TestFixture\\tracelog'],
	]);

	$t->hasPathValues([
		'enabled.core'=>true,
		'core_implicit'=>true,
	], constant('DATAPHYRE_MODULE_POLICY'));
	$t->same('ready', constant('DP_TESTKIT_BOOTSTRAP_CONSTANT'));
	$t->isTrue(is_file($framework->path('core/kernel/autoloader.php')));
	$t->isTrue(class_exists(\Dataphyre\Config::class));
	$t->same($framework->path(), $framework->modulesRoot());
	$t->same(['core'], $framework->modules());
	$t->same('Dataphyre TestKit', $t->suite());
	\Dataphyre\TestFixture\tracelog('self-describing bootstrap');

	$missingModules=$t->workspace('testkit-missing-modules')->path('unavailable');
	$t->throws(static fn()=>Framework::boot([], ['modules_root'=>$missingModules]), RuntimeException::class);
	$t->throws(static fn()=>Framework::boot([], ['functions'=>['invalid-function-name!']]), InvalidArgumentException::class);
	$t->throws(static fn()=>$framework->require('missing/test-bootstrap.php'), RuntimeException::class);
})->tag('framework-bootstrap', 'ergonomics');

test('testing stays a tooling-only module outside normal request boot', static function(Context $t): void {
	$framework=framework(['core', 'testing']);
	$testingRoot=$framework->path('testing');

	$t->isTrue(is_file($testingRoot.'/tooling/bootstrap.php'));
	$t->isTrue(is_file($testingRoot.'/tooling/TestKit/Contracts/TestContext.php'));
	$t->isTrue(is_file($testingRoot.'/tooling/code_worker.php'));
	$t->isFalse(is_dir($testingRoot.'/Framework'));
	$t->isFalse(is_file($testingRoot.'/framework.php'));
	$t->isFalse(is_file($testingRoot.'/kernel/testing.main.php'));
	$t->same(false, \dataphyre\module_registry::module_definition('testing'));
})->tag('framework-bootstrap', 'module-boundary');

test('framework bootstrap validates explicit policy and constant declarations', static function(Context $t): void {
	$framework=Framework::boot(['core'=>true,'panel'=>false], [
		'register'=>false,
		'enabled'=>['core'],
		'disabled'=>['missing'],
		'constants'=>[''=>'ignored','DP_TESTKIT_EXPLICIT_CONSTANT'=>'declared'],
		'functions'=>['strlen'],
		'before_modules'=>['core/kernel/autoloader.php'],
		'files'=>['core/kernel/autoloader.php'],
	]);

	$t->same(['core'], $framework->modules());
	$t->hasPathValues([
		'enabled.core'=>true,
		'disabled.missing'=>true,
		'core_implicit'=>true,
	], constant('DATAPHYRE_MODULE_POLICY'));
	$t->same('declared', constant('DP_TESTKIT_EXPLICIT_CONSTANT'));
	$sameDeclaration=Framework::boot('core', [
		'register'=>false,
		'disabled'=>['missing'],
		'constants'=>['DP_TESTKIT_EXPLICIT_CONSTANT'=>'declared'],
	]);
	$t->same(['core'], $sameDeclaration->modules());
	$t->throws(static fn()=>Framework::boot('panel', ['register'=>false]), RuntimeException::class);
	$t->throws(static fn()=>Framework::boot('core', [
		'register'=>false,
		'disabled'=>['missing'],
		'constants'=>['DP_TESTKIT_EXPLICIT_CONSTANT'=>'conflicting'],
	]), RuntimeException::class);
})->tag('framework-bootstrap', 'policy', 'ergonomics');

test('framework bootstrap reports missing and malformed bootstrap contracts', static function(Context $t): void {
	$modulesRoot=$t->tempDirectory('testkit-framework-root');
	mkdir($modulesRoot.'/core', 0775, true);
	mkdir($modulesRoot.'/core/kernel', 0775, true);

	$t->throwsLike(
		static fn()=>Framework::boot([], ['modules_root'=>$modulesRoot]),
		RuntimeException::class,
		'autoloader is unavailable'
	);
	file_put_contents($modulesRoot.'/core/kernel/autoloader.php', '<?php');
	$t->throwsLike(
		static fn()=>Framework::boot([], ['modules_root'=>$modulesRoot]),
		RuntimeException::class,
		'autoloader class did not load'
	);
})->tag('framework-bootstrap', 'diagnostics', 'ergonomics');

test('framework bootstrap rejects a malformed pre-existing policy', static function(Context $t): void {
	define('DATAPHYRE_MODULE_POLICY', 'malformed');
	$t->throwsLike(
		static fn()=>Framework::boot([], ['register'=>false]),
		RuntimeException::class,
		'conflict'
	);
})->tag('framework-bootstrap', 'policy', 'diagnostics');

test('framework bootstrap preflights failures before mutating retryable process state', static function(Context $t): void {
	$missingModules=$t->workspace('testkit-preflight-missing')->path('unavailable');
	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'constants'=>[
				' DP_TESTKIT_DUPLICATE_DECLARATION '=>'first',
				'DP_TESTKIT_DUPLICATE_DECLARATION'=>'second',
			],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DP_TESTKIT_DUPLICATE_DECLARATION'));
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));

	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'constants'=>['DATAPHYRE_MODULE_POLICY'=>[
				'enabled'=>['panel'=>true],
				'disabled'=>[],
				'core_implicit'=>true,
			]],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));

	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'constants'=>['DP_TESTKIT_INVALID_FUNCTION_SIDE_EFFECT'=>'must-not-exist'],
			'functions'=>['invalid-function-name!'],
		]),
		InvalidArgumentException::class
	);
	$t->isFalse(defined('DP_TESTKIT_INVALID_FUNCTION_SIDE_EFFECT'));
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));
	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'functions'=>['class'],
		]),
		InvalidArgumentException::class
	);
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));

	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'modules_root'=>$missingModules,
			'constants'=>['DP_TESTKIT_INVALID_PATH_SIDE_EFFECT'=>'must-not-exist'],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DP_TESTKIT_INVALID_PATH_SIDE_EFFECT'));
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));
	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'constants'=>['DP_TESTKIT_INVALID_FILE_SIDE_EFFECT'=>'must-not-exist'],
			'files'=>['core/kernel/not-a-test-bootstrap.php'],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DP_TESTKIT_INVALID_FILE_SIDE_EFFECT'));
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));

	$framework=Framework::boot('core', [
		'register'=>false,
		'constants'=>['DP_TESTKIT_RETRY_AFTER_PREFLIGHT'=>'ready'],
	]);
	$t->same(['core'], $framework->modules());
	$t->same('ready', constant('DP_TESTKIT_RETRY_AFTER_PREFLIGHT'));

	$t->throws(
		static fn()=>Framework::boot('panel', [
			'register'=>false,
			'constants'=>['DP_TESTKIT_POLICY_CONFLICT_SIDE_EFFECT'=>'must-not-exist'],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DP_TESTKIT_POLICY_CONFLICT_SIDE_EFFECT'));
})->tag('framework-bootstrap', 'preflight', 'diagnostics');

test('framework bootstrap reports failed global declarations without continuing', static function(Context $t): void {
	$failures=$t->state('testing.failures');
	$failures->put('define','DP_TESTKIT_FAILED_CUSTOM_DEFINE');
	$t->throws(
		static fn()=>Framework::boot('core', [
			'register'=>false,
			'constants'=>['DP_TESTKIT_FAILED_CUSTOM_DEFINE'=>'value'],
		]),
		RuntimeException::class
	);
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));

	$failures->put('define','DATAPHYRE_MODULE_POLICY');
	$t->throws(
		static fn()=>Framework::boot('core', ['register'=>false]),
		RuntimeException::class
	);
	$t->isFalse(defined('DATAPHYRE_MODULE_POLICY'));
})->tag('framework-bootstrap', 'preflight', 'diagnostics');

test('suite defaults make each test file describe its shared contract once', static function(Context $t): void {
	$defaults=(new SuiteDefinition('Readable contracts'))
		->framework('core', ['register'=>false])
		->uses('database')
		->tag('contract','unit')
		->group('framework-coverage')
		->maxMillis(750)
		->beforeEach(static function(): void {})
		->afterEach(static function(): void {});
	$case=new CaseDefinition('inherits defaults', static function(): void {});
	$defaults->applyTo($case);

	$t->hasAccessorValues([
		'suiteName'=>'Readable contracts',
		'fixtures'=>['database'],
		'tags'=>['contract','unit'],
		'groups'=>['framework-coverage'],
		'maxMillisValue'=>750,
	], $case);
})->tag('suite-defaults', 'ergonomics');

test('non-public access states exactly which internal seam is under test', static function(Context $t): void {
	$fixture=new DpTestKitNonPublicFixture('private');
	$internal=$t->nonPublic($fixture);

	$t->same('private-method', $internal->invoke('describe', 'method'));
	$t->same([
		'first'=>'private-one',
		'second'=>'private-two',
	],$internal->invokeCases([
		'first'=>['method'=>'describe','arguments'=>['one']],
		'second'=>['method'=>'describe','arguments'=>['two']],
	]));
	$t->throws(
		static fn()=>$internal->invokeCases(['invalid'=>['method'=>'','arguments'=>'not-a-list']]),
		InvalidArgumentException::class
	);
	$captured=$internal->capture('append', value: 'private', suffix: '-reference');
	$t->same('private-reference', $captured->result());
	$t->same('private-reference', $captured->argument('value'));
	$t->same(['value'=>'private-reference','suffix'=>'-reference'], $captured->arguments());
	$positional=$internal->capture('append', 'position', '-captured');
	$t->same('position-captured', $positional->argument(0));
	$variadic=$internal->capture('variadic', first: 'one', second: 'two');
	$t->same('one-two', $variadic->result());
	$t->same('two', $variadic->argument('values'));
	$t->throws(static fn()=>$captured->argument('missing'), OutOfBoundsException::class);
	$t->throws(static fn()=>$captured->argument(9), OutOfBoundsException::class);
	$t->throws(static fn()=>$internal->capture('describe', unknown: 'method'), Error::class);
	$t->same('private', $internal->readProperty('value'));
	$internal->writeProperty('value', 'changed');
	$t->same('changed-method', $internal->invoke('describe', 'method'));

	$static=$t->nonPublic(DpTestKitNonPublicFixture::class);
	$static->replacePropertyForTest('staticValue', 'temporary');
	$t->same('temporary', $static->readProperty('staticValue'));
	$t->runDeferred();
	$t->same('original', $static->readProperty('staticValue'));
	$t->instanceOf(DpTestKitNonPublicFixture::class, $internal->withoutConstructor());
})->tag('non-public-access', 'ergonomics');

test('temporary resources environment and globals restore themselves after the test', static function(Context $t): void {
	$cleanupOrder=[];
	$t->cleanup(static function()use(&$cleanupOrder): void { $cleanupOrder[]='first'; });
	$t->cleanup(static function()use(&$cleanupOrder): void { $cleanupOrder[]='second'; });

	$directory=$t->tempDirectory('testkit-managed');
	$file=$t->tempFile('managed contents', 'fixture', $directory);
	$previousEnvironment=getenv('DATAPHYRE_TESTKIT_MANAGED');
	$t->setEnvironmentForTest(['DATAPHYRE_TESTKIT_MANAGED'=>'active']);
	$t->setGlobalsForTest(['dataphyre_testkit_managed'=>['active'=>true]]);

	$t->same('managed contents', file_get_contents($file));
	$t->same('active', getenv('DATAPHYRE_TESTKIT_MANAGED'));
	$t->same(['active'=>true], DpTestKitGlobalContract::value('dataphyre_testkit_managed'));
	$t->runDeferred();

	$t->same(['second','first'], $cleanupOrder);
	$t->isFalse(file_exists($directory));
	$t->same($previousEnvironment, getenv('DATAPHYRE_TESTKIT_MANAGED'));
	$t->isFalse(DpTestKitGlobalContract::has('dataphyre_testkit_managed'));
})->tag('managed-cleanup', 'ergonomics');

test('managed global values and maps describe mutations and always restore state', static function(Context $t): void {
	$existingName='dp_testkit_global_map_existing';
	$missingName='dp_testkit_global_map_missing';
	$scalarName='dp_testkit_global_scalar';
	DpTestKitGlobalContract::put($existingName,['original'=>true]);
	DpTestKitGlobalContract::put($scalarName,'original');
	DpTestKitGlobalContract::forget($missingName);

	$existing=$t->globalMap($existingName);
	$t->isTrue($existing->exists());
	$existing->put('token', 'ready')->merge([
		'count'=>2,
		'queue'=>['first'],
		'scalar'=>'value',
		'number'=>1,
		'nullable'=>null,
		'forgotten'=>true,
	])->remove('original')->forget('forgotten');
	$t->isTrue($existing->has('token'));
	$t->same('ready', $existing->get('token'));
	$t->same('fallback', $existing->get('missing', 'fallback'));
	$t->isNull($existing->get('nullable', 'fallback'));
	$existing->append('queue', 'second');
	$t->same('first', $existing->shift('queue'));
	$t->same('second', $existing->shift('queue'));
	$t->same('empty', $existing->shift('queue', 'empty'));
	$t->same(3, $existing->increment('number', 2));
	$t->same(3.5, $existing->increment('number', 0.5));
	$existing->putPath(['nested','token'], 'scoped');
	$t->same('scoped', $existing->getPath(['nested','token']));
	$t->same('fallback', $existing->getPath(['nested','missing'], 'fallback'));
	$t->same('fallback', $existing->getPath(['scalar','missing'], 'fallback'));
	$t->throws(static fn()=>$existing->putPath(['scalar','nested'], true), UnexpectedValueException::class);
	$t->throws(static fn()=>$existing->getPath([]), InvalidArgumentException::class);
	$t->throws(static fn()=>$existing->putPath([''], true), InvalidArgumentException::class);
	$t->throws(static fn()=>$existing->append('scalar', 'x'), UnexpectedValueException::class);
	$t->throws(static fn()=>$existing->shift('scalar'), UnexpectedValueException::class);
	$t->throws(static fn()=>$existing->increment('scalar'), UnexpectedValueException::class);
	$existing->clear();
	$t->same([], $existing->value());

	$missing=$t->globalMap($missingName);
	$t->isTrue($missing->exists());
	$t->same([], $missing->map());
	$missing->put('created', true);

	$scalar=$t->global($scalarName);
	$t->isTrue($scalar->exists());
	$t->same('original', $scalar->value());
	$scalar->replace(null);
	$t->isNull($scalar->value('fallback'));
	$scalar->unsetValue();
	$t->isFalse($scalar->exists());
	$t->same('fallback', $scalar->value('fallback'));
	$scalar->replace('changed');
	$t->same('changed', $scalar->value());
	$t->throws(static fn()=>$scalar->map(), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->globalMap($scalarName), UnexpectedValueException::class);
	$t->throws(static fn()=>$t->global(''), InvalidArgumentException::class);
	$t->throws(static fn()=>$t->global('GLOBALS'), InvalidArgumentException::class);

	$scoped=$t->withGlobals([
		$scalarName=>'scoped',
		'dp_testkit_scoped_missing'=>'temporary',
	], static fn(): string => DpTestKitGlobalContract::value($scalarName).'-'.DpTestKitGlobalContract::value('dp_testkit_scoped_missing'));
	$t->same('scoped-temporary', $scoped);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));
	$t->isFalse(DpTestKitGlobalContract::has('dp_testkit_scoped_missing'));
	$t->throws(static fn()=>$t->withGlobals([$scalarName=>'throwing'], static fn()=>throw new LogicException('scoped failure')), LogicException::class);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));

	$managed=$t->withGlobal($scalarName, 'managed', static fn(GlobalState $state): array=>[
		$state->value(),
		DpTestKitGlobalContract::value($scalarName),
	]);
	$t->same(['managed','managed'], $managed);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));
	$absent=$t->withoutGlobal($scalarName, static fn(GlobalState $state): bool=>
		!$state->exists() && !DpTestKitGlobalContract::has($scalarName)
	);
	$t->isTrue($absent);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));
	$t->throws(static fn()=>$t->withGlobal(
		$scalarName,
		'throwing-managed',
		static fn(GlobalState $state)=>throw new LogicException((string)$state->value()),
	), LogicException::class);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));
	$t->throws(static fn()=>$t->withoutGlobal(
		$scalarName,
		static fn(GlobalState $state)=>throw new LogicException($state->exists() ? 'present' : 'absent'),
	), LogicException::class);
	$t->same('changed', DpTestKitGlobalContract::value($scalarName));

	$t->runDeferred();
	$t->same(['original'=>true], DpTestKitGlobalContract::value($existingName));
	$t->same('original', DpTestKitGlobalContract::value($scalarName));
	$t->isFalse(DpTestKitGlobalContract::has($missingName));
	DpTestKitGlobalContract::forget($existingName,$scalarName);
})->tag('managed-cleanup', 'globals', 'ergonomics');

test('process-local state replaces test-only globals with named channels', static function(Context $t): void {
	$t->throws(static fn()=>TestState::channel('missing.channel'), RuntimeException::class);
	$t->isNull(TestState::channelIfActive('missing.channel'));
	$t->throws(static fn()=>$t->state('invalid channel'), InvalidArgumentException::class);

	$state=$t->state('oauth.transport', [
		'queue'=>['first','second'],
		'calls'=>[],
		'counter'=>1,
		'scalar'=>'value',
		'nullable'=>null,
	]);
	$t->same($state->all(), TestState::channel('oauth.transport')->all());
	$t->same($state->all(), TestState::channelIfActive('oauth.transport')?->all());
	$t->isTrue($state->has('nullable'));
	$t->isFalse($state->has('missing'));
	$t->isNull($state->get('nullable', 'fallback'));
	$t->same('fallback', $state->get('missing', 'fallback'));
	$state->merge(['merged'=>true, 'counter'=>2]);
	$t->isTrue($state->get('merged'));
	$state->append('calls', ['url'=>'https://example.test']);
	$t->same([['url'=>'https://example.test']], $state->get('calls'));
	$t->same('first', $state->shift('queue'));
	$t->same('second', $state->shift('queue'));
	$t->same('empty', $state->shift('queue', 'empty'));
	$t->same(4, $state->increment('counter', 2));
	$state->put('ratio', 1.5);
	$t->same(2.0, $state->increment('ratio', 0.5));
	$state->put('temporary', true)->forget('temporary');
	$t->same(false, $state->get('temporary', false));
	$t->throws(static fn()=>$state->append('scalar', 'x'), UnexpectedValueException::class);
	$t->throws(static fn()=>$state->shift('scalar'), UnexpectedValueException::class);
	$t->throws(static fn()=>$state->increment('scalar'), UnexpectedValueException::class);

	$state->replace(['replaced'=>true]);
	$t->same(['replaced'=>true], $state->all());
	$state->clear();
	$t->same([], $state->all());
	$t->state('oauth.transport', ['nested'=>true]);
	$t->runDeferred();
	$t->throws(static fn()=>TestState::channel('oauth.transport'), RuntimeException::class);
	$t->isNull(TestState::channelIfActive('oauth.transport'));
})->tag('managed-state', 'fixtures', 'ergonomics');

test('managed environment and globals restore values that already existed', static function(Context $t): void {
	$environmentName='DATAPHYRE_TESTKIT_EXISTING';
	$globalName='dataphyre_testkit_existing';
	$previousEnvironment=getenv($environmentName);
	$environmentExisted=array_key_exists($environmentName, $_ENV); // dataphyre-test-architecture: exempt[raw-superglobal] reason="Environment cleanup contract must preserve native array existence."
	$previousEnvironmentArray=$_ENV[$environmentName] ?? null; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Environment cleanup contract must preserve the native array value."
	$globalExisted=DpTestKitGlobalContract::has($globalName);
	$previousGlobal=DpTestKitGlobalContract::value($globalName);

	try{
		putenv($environmentName.'=before');
		$_ENV[$environmentName]='before'; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Environment cleanup contract requires a pre-existing native array value."
		DpTestKitGlobalContract::put($globalName,'before');
		$t->setEnvironmentForTest([$environmentName=>null]);
		$t->setGlobalsForTest([$globalName=>'during']);

		$t->same(false, getenv($environmentName));
		$t->isFalse(array_key_exists($environmentName, $_ENV)); // dataphyre-test-architecture: exempt[raw-superglobal] reason="Environment cleanup contract verifies native array removal."
		$t->same('during', DpTestKitGlobalContract::value($globalName));
		$t->runDeferred();
		$t->same('before', getenv($environmentName));
		$t->same('before', $_ENV[$environmentName]); // dataphyre-test-architecture: exempt[raw-superglobal] reason="Environment cleanup contract verifies native array restoration."
		$t->same('before', DpTestKitGlobalContract::value($globalName));
	}finally{
		$previousEnvironment===false ? putenv($environmentName) : putenv($environmentName.'='.$previousEnvironment);
		if($environmentExisted){
			$_ENV[$environmentName]=$previousEnvironmentArray; // dataphyre-test-architecture: exempt[raw-superglobal] reason="Test-owned cleanup restores the pre-existing native array value."
		}else{
			unset($_ENV[$environmentName]); // dataphyre-test-architecture: exempt[raw-superglobal] reason="Test-owned cleanup restores native array non-existence."
		}
		if($globalExisted){
			DpTestKitGlobalContract::put($globalName,$previousGlobal);
		}else{
			DpTestKitGlobalContract::forget($globalName);
		}
	}
})->tag('managed-cleanup', 'environment', 'ergonomics');

test('runtime PHP INI overrides restore themselves and reject accidental host assumptions', static function(Context $t): void {
	$previous=(string)ini_get('memory_limit');
	$t->same($t,$t->phpIni(['memory_limit'=>-1,'display_errors'=>false]));
	$t->same('-1',ini_get('memory_limit'));
	$t->same('0',ini_get('display_errors'));
	$t->runDeferred();
	$t->same($previous,ini_get('memory_limit'));
	$t->throws(static fn()=>$t->phpIni([''=>'value']),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->phpIni(['memory_limit'=>[]]),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->phpIni(['dataphyre.unknown'=>'value']),InvalidArgumentException::class);
	$t->throws(static fn()=>$t->phpIni(['disable_functions'=>'strlen']),RuntimeException::class);
})->tag('managed-cleanup','php-ini','environment');

test('managed temporary resources report operating-system failures precisely', static function(Context $t): void {
	$failures=$t->state('testing.failures');
	$failures->put('mkdir',true);
	$t->throws(static fn()=>$t->tempDirectory('cannot-create'), RuntimeException::class);
	$failures->put('mkdir',false);

	$missingDirectory=$t->workspace('testkit-missing-directory')->path('unavailable');
	$t->throws(static fn()=>$t->tempFile('', 'missing-directory', $missingDirectory), RuntimeException::class);
	$failures->put('tempnam',true);
	$t->throws(static fn()=>$t->tempFile('', 'cannot-name'), RuntimeException::class);
	$failures->put('tempnam',false);

	$failures->put('file_put_contents',true);
	$t->throws(static fn()=>$t->tempFile('cannot write', 'cannot-write'), RuntimeException::class);
	$failures->put('file_put_contents',false);

	$unremovableFile=$t->tempFile('', 'cannot-remove');
	$failures->put('unlink',$unremovableFile);
	$t->throws(static fn()=>$t->runDeferred(), DeferredCleanupFailed::class);
	\unlink($unremovableFile);

	$alreadyRemoved=$t->tempFile('', 'already-removed');
	\unlink($alreadyRemoved);
	$t->runDeferred();

	$recursiveDirectory=$t->tempDirectory('recursive-remove');
	\file_put_contents($recursiveDirectory.'/child.txt', 'child');
	$t->runDeferred();
	$t->isFalse(file_exists($recursiveDirectory));

	$failures=$t->state('testing.failures');
	$unreadableDirectory=$t->tempDirectory('cannot-read');
	$failures->put('scandir',$unreadableDirectory);
	$t->throws(static fn()=>$t->runDeferred(), DeferredCleanupFailed::class);
	\rmdir($unreadableDirectory);

	$failures=$t->state('testing.failures');
	$unremovableDirectory=$t->tempDirectory('cannot-remove-directory');
	$failures->put('rmdir',$unremovableDirectory);
	$t->throws(static fn()=>$t->runDeferred(), DeferredCleanupFailed::class);
	\rmdir($unremovableDirectory);
})->tag('managed-cleanup', 'filesystem', 'diagnostics');

test('deferred cleanup drains nested callbacks and preserves original failures', static function(Context $t): void {
	$events=[];
	$t->cleanup(static function()use($t, &$events): void {
		$events[]='outer';
		$t->cleanup(static function()use(&$events): void { $events[]='nested'; });
		throw new LogicException('cleanup exploded');
	});
	$t->cleanup(static function()use(&$events): void { $events[]='first'; });

	$failure=$t->throws(static fn()=>$t->runDeferred(), DeferredCleanupFailed::class);
	$t->same(['first','outer','nested'], $events);
	$t->count(1, $failure->failures());
	$t->instanceOf(LogicException::class, $failure->getPrevious());
	$t->same('cleanup exploded', $failure->failures()[0]->getMessage());
})->tag('managed-cleanup', 'diagnostics', 'ergonomics');

test('structural and serialization assertions describe contracts without indexing noise', static function(Context $t): void {
	$value=new DpTestKitSerializableFixture();
	$payload=$value->toArray();

	$t->hasPathValues([
		'name'=>'Dataphyre',
		'meta.version'=>1,
	], $payload);
	$searchablePayload=['name'=>$payload['name'],'meta'=>[
		'version'=>$payload['meta']['version'],'tags'=>['framework','testing'],
	]];
	$t->pathsContain([
		'name'=>'phyre',
		'meta.tags'=>'framework',
	], $searchablePayload);
	$t->expect($payload)
		->toContainAll([$payload['name'], $payload['meta']])
		->toContainSubset(['meta'=>['version'=>1]])
		->toHavePathValues(['name'=>'Dataphyre']);
	$t->expect(['summary'=>'Dataphyre testing framework'])
		->toHavePathsContaining(['summary'=>'testing']);
	$rows=[
		['name'=>'orders','state'=>'ready','meta'=>['count'=>2]],
		(object)['name'=>'customers','state'=>'waiting'],
		['name'=>'orders','state'=>'ready','meta'=>['count'=>3]],
	];
	$t->containsRows([
		'customer'=>['name'=>'customers'],
		'first-order'=>['name'=>'orders','meta'=>['count'=>2]],
		'second-order'=>['name'=>'orders','meta'=>['count'=>3]],
	],$rows);
	$t->expect(new ArrayIterator($rows))->toContainRows([
		['name'=>'orders','state'=>'ready'],
		['name'=>'orders','state'=>'ready'],
	]);
	$t->containsAll(['Dataphyre','version'], json_encode($payload, JSON_THROW_ON_ERROR));
	$t->containsNone(['failure','exception'], json_encode($payload, JSON_THROW_ON_ERROR));
	$t->hasAccessorValues(['label'=>'accessor'], new DpTestKitNonPublicFixture('accessor'));
	$t->expect(new DpTestKitNonPublicFixture('expected'))->toHaveAccessorValues(['label'=>'expected']);
	$t->throws(
		static fn()=>$t->expect(['one'])->not()->toContainAll(['one','two']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect($payload)->not()->toContainSubset(['name'=>'Dataphyre']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect($rows)->not()->toContainRows([['name'=>'orders']]),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->containsRows([['name'=>'missing']],$rows),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->containsRows(['invalid'=>'not-a-row'],$rows),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->containsRows([],42),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect($payload)->not()->toHavePathValues(['name'=>'Dataphyre']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->pathsContain(['name'=>'missing'],$payload),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->pathsContain(['missing'=>'value'],$payload),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect($payload)->not()->toHavePathsContaining(['name'=>'Dataphyre']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect(new DpTestKitNonPublicFixture())->not()->toHaveAccessorValues(['label'=>'instance']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->expect('not an object')->toHaveAccessorValues(['label'=>'instance']),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->hasAccessorValues(['missing'=>'value'], new DpTestKitNonPublicFixture()),
		Dataphyre\Test\AssertionFailed::class
	);
	$t->throws(
		static fn()=>$t->hasAccessorValues(['needsArgument'=>'value'], new DpTestKitNonPublicFixture()),
		Dataphyre\Test\AssertionFailed::class
	);
	$assertionsBeforeSerialization=$t->assertions();
	$t->hasConsistentSerialization($value);
	$t->same($assertionsBeforeSerialization+1, $t->assertions());
	$t->hasConsistentSerialization($value, $payload);
	$t->throws(
		static fn()=>$t->hasConsistentSerialization(new DpTestKitHiddenSerializationFixture()),
		Dataphyre\Test\AssertionFailed::class
	);
})->tag('structural-assertions', 'ergonomics');

test('path-value maps distinguish public null object properties from missing paths', static function(Context $t): void {
	$payload=(object)['optional'=>null, 'nested'=>(object)['value'=>null]];
	$t->hasPathValues([
		'optional'=>null,
		'nested.value'=>null,
	], $payload);
	$t->hasPathValues(['virtual'=>null], new DpTestKitMagicPathFixture());
	$t->throws(
		static fn()=>$t->hasPathValues(['missing'=>null], $payload),
		Dataphyre\Test\AssertionFailed::class
	);
})->tag('structural-assertions', 'object-paths', 'regression');

}
