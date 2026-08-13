<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\Reactor;
use Dataphyre\Reactor\ReactorClientAssets;
use Dataphyre\Reactor\ReactorComponent;
use Dataphyre\Reactor\ReactorManager;
use Dataphyre\Reactor\ReactorManifest;
use Dataphyre\Reactor\ReactorName;
use Dataphyre\Reactor\ReactorResponse;
use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Reactor\ReactorSnapshot;
use Dataphyre\Reactor\ReactorTestHarness;
use Dataphyre\Reactor\ReactorTrace;
use Dataphyre\Reactor\ReactorView;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!function_exists('tracelog')){
	function tracelog(...$arguments): void {}
}
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'mvc'=>true, 'reactor'=>true, 'templating'=>false],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_REACTOR_CFG')){
	define('DP_REACTOR_CFG', [
		'secret'=>'reactor-coverage-secret',
		'allow_unsigned_in_debug'=>true,
	]);
}

$dp_reactor_small_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_reactor_small_modules_root.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($dp_reactor_small_modules_root);
\dataphyre\autoloader::register_framework_modules(['core', 'mvc', 'reactor']);

suite('Reactor client, response, and facade contracts')
	->contract('reactor.small-framework', 1)
	->layer('integration')
	->risk('high')
	->watches('module:reactor')
	->through('client-assets', 'view', 'response', 'snapshot', 'signer', 'trace', 'facade', 'manifest')
	->isolation('case')
	->tag('reactor', 'small-framework')
	->group('framework-coverage');

test('reactor client assets and view cover known missing sanitized and attributed output', static function(Context $t): void {
	$script=ReactorClientAssets::script('https://example.test/reactor?x="one"');
	$t->contains('<script', $script);
	$t->contains('data-dp-reactor-endpoint=', $script);
	$t->contains('&quot;', $script);
	$t->contains('reactor.js?v=', ReactorClientAssets::assetUrl('../reactor.js'));
	$t->same('missing', ReactorClientAssets::assetVersion('missing.js'));
	$t->same(null, ReactorClientAssets::assetContent('bad name.js'));
	$content=ReactorClientAssets::assetContent('reactor.js');
	$t->same('application/javascript; charset=UTF-8', $content['content_type']);
	$t->contains('DataphyreReactor', $content['body']);
	$t->contains('<script', ReactorClientAssets::script());

	$snapshot=ReactorSnapshot::make('Example Component', ['count'=>1], [], ['audience'=>'reactor:small-test']);
	$mounted=ReactorView::mount('Example Component', '<p>Ready</p>', $snapshot, [
		'class'=>'root',
		'data-count'=>1,
		'aria-live'=>'polite',
		'disabled'=>true,
		'data-hidden'=>false,
		'bad name'=>'rejected',
	]);
	$t->contains('data-dp-reactor-component="example_component"', $mounted);
	$t->contains(' disabled', $mounted);
	$t->isFalse(str_contains($mounted, 'bad name'));
	$t->contains('<script', ReactorView::script('/reactor'));
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor response snapshot signer and name cover normalization serialization tampering and cache reset', static function(Context $t): void {
	$ok=ReactorResponse::ok('<p>OK</p>', ['count'=>1], [['type'=>'toast']]);
	$t->same('<p>OK</p>', $ok->html());
	$t->same(['count'=>1], $ok->state());
	$t->same([['type'=>'toast']], $ok->effects());
	$t->same(200, $ok->status());
	$t->same('', $ok->message());
	$t->isTrue($ok->jsonSerialize()['ok']);
	$error=ReactorResponse::error(' Failure ', 999, [['type'=>'cleanup']]);
	$t->same(599, $error->status());
	$t->same('Failure', $error->message());
	$t->isFalse($error->jsonSerialize()['ok']);
	$t->same(400, ReactorResponse::error('low', 1)->status());

	$scope=['audience'=>'reactor:small-test'];
	$snapshot=ReactorSnapshot::make(' Order Editor ', ['b'=>2, 'a'=>1], ['id', 'id', 2], $scope);
	$t->same('order_editor', $snapshot->component());
	$t->same(['b'=>2, 'a'=>1], $snapshot->state());
	$t->isTrue($snapshot->verify($scope));
	$payload=$snapshot->jsonSerialize();
	$t->isTrue(ReactorSnapshot::from(json_encode($payload))?->verify($scope)===true);
	$t->same(null, ReactorSnapshot::from('{invalid'));
	$t->same(null, ReactorSnapshot::from(new stdClass()));
	$malformed=ReactorSnapshot::from(['component'=>'x', 'state'=>'bad', 'locked'=>'bad']);
	$t->same(null, $malformed);
	$payload['state']['a']=99;
	$t->isFalse(ReactorSnapshot::from($payload)?->verify($scope) ?? true);

	$first=ReactorSigner::sign(['z'=>1, 'nested'=>['b'=>2, 'a'=>1]]);
	$second=ReactorSigner::sign(['nested'=>['a'=>1, 'b'=>2], 'z'=>1]);
	$t->same($first, $second);
	$t->isTrue(ReactorSigner::verify(['z'=>1, 'nested'=>['b'=>2, 'a'=>1]], $first));
	$t->isFalse(ReactorSigner::verify(['z'=>2], $first));
	$t->isTrue(ReactorSigner::verify([], ''));

	$t->same('name_with_spaces', ReactorName::normalize(' Name With Spaces '));
	$t->same('name_with_spaces', ReactorName::normalize(' Name With Spaces '));
	for($index=0; $index<132; $index++){
		ReactorName::normalize('cache-name-'.$index);
	}
	$t->same('after-reset', ReactorName::normalize('after-reset'));
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor trace covers known missing failed spans bounded history and safe object context', static function(Context $t): void {
	$span=ReactorTrace::begin(' Work Span ', ['snapshot'=>ReactorSnapshot::make('trace', [], [], ['audience'=>'reactor:small-test']), 'object'=>new stdClass()]);
	ReactorTrace::end($span, ['ok'=>true]);
	ReactorTrace::end('missing', ['reason'=>'gone']);
	$failed=ReactorTrace::begin('Failure Span');
	ReactorTrace::fail($failed, new RuntimeException('boom'));
	ReactorTrace::fail('missing-failure', new LogicException('missing'));
	for($index=0; $index<165; $index++){
		ReactorTrace::record('event.'.$index, ['index'=>$index]);
	}
	$deep=['value'=>'bottom'];
	for($depth=0; $depth<10; $depth++){ $deep=['child'=>$deep]; }
	$stream=fopen('php://memory', 'rb');
	$t->isTrue(is_resource($stream));
	$t->defer(static function()use($stream): void { if(is_resource($stream)){ fclose($stream); } });
	ReactorTrace::record('sensitive.event', [
		'password'=>'plain-secret',
		'nested'=>[
			'token'=>'nested-secret',
			'input'=>['code'=>'123456'],
			'safe'=>['code'=>'public-code'],
			'message'=>'password=message-secret Bearer bearer-secret',
		],
		'authorization'=>'Bearer header-secret',
		'url'=>'https://operator:url-secret@example.test/path',
		'private_key'=>'-----BEGIN PRIVATE'.' KEY----- hidden',
		'invalid_utf8'=>"bad\xB1text",
		'long'=>str_repeat('x', 2200),
		'list'=>range(1, 105),
		'deep'=>$deep,
		'nonfinite'=>INF,
		'stream'=>$stream,
		'object'=>new stdClass(),
		'throwable'=>new RuntimeException('token=throwable-secret'),
		'json_safe'=>new class implements JsonSerializable { public function jsonSerialize(): array { return ['password'=>'serialized-secret']; } },
		'json_fail'=>new class implements JsonSerializable { public function jsonSerialize(): mixed { throw new RuntimeException('password=serializer-secret'); } },
	]);
	$events=ReactorTrace::events();
	$t->same(160, count($events));
	$t->isTrue(is_array($events[0]['context']));
	$sensitive=$events[array_key_last($events)]['context'];
	$t->same('[REDACTED]', $sensitive['password']);
	$t->same('[REDACTED]', $sensitive['nested']['token']);
	$t->same('[REDACTED]', $sensitive['nested']['input']['code']);
	$t->same('public-code', $sensitive['nested']['safe']['code']);
	$t->same(5, $sensitive['list']['__truncated_items__']);
	$t->same('INF', $sensitive['nonfinite']);
	$t->same('stream', $sensitive['stream']['resource_type']);
	$t->same(stdClass::class, $sensitive['object']['class']);
	$t->same('failed', $sensitive['json_fail']['serialization']);
	$t->contains('...', $sensitive['long']);
	$sensitiveJson=json_encode($sensitive, JSON_THROW_ON_ERROR);
	foreach(['plain-secret','nested-secret','123456','message-secret','bearer-secret','header-secret','url-secret','hidden','throwable-secret','serialized-secret','serializer-secret'] as $secret){
		$t->notContains($secret, $sensitiveJson);
	}
	$summary=ReactorTrace::summary();
	$t->same(160, $summary['count']);
	$t->same([], $summary['active_spans']);
	$t->isTrue(count($summary['latest'])<=10);
})->tag('reactor', 'coverage')->group('framework-coverage');

test('reactor facade and manifest cover manager registration dispatch mount harness config and version fallback', static function(Context $t): void {
	$manager=Reactor::reset((new ReactorManager())->trustInternalTransport('reactor:facade-test'));
	$t->same($manager, Reactor::manager());
	$component=Reactor::component('facade-component', '<p>{{ count }}</p>')->state(['count'=>1]);
	$t->instanceOf(ReactorComponent::class, $component);
	Reactor::register($component);
	$t->instanceOf(ReactorComponent::class, Reactor::register(['name'=>'registered', 'render'=>'<p>Registered</p>']));
	$t->isTrue(is_array(Reactor::manifest()));
	$t->instanceOf(ReactorSnapshot::class, Reactor::snapshot('facade-component', ['count'=>2]));
	$t->contains('facade-component', Reactor::mount('facade-component', ['count'=>3], ['class'=>'mount']));
	$t->instanceOf(ReactorTestHarness::class, Reactor::test());
	$t->instanceOf(ReactorTestHarness::class, Reactor::test(new ReactorManager()));
	$t->instanceOf(ReactorResponse::class, Reactor::dispatch(['component'=>'missing', 'action'=>'noop']));
	$t->same('reactor-coverage-secret', Reactor::config('secret'));
	$t->same('fallback', Reactor::config('missing', 'fallback'));

	$manifest=ReactorManifest::manager($manager);
	$t->same('reactor', $manifest['module']);
	$t->same(2, $manifest['component_count']);
	try{
		ReactorManifest::useVersionFile($t->workspace('reactor-manifest')->path('missing-version'));
		$t->same('1.0.0', ReactorManifest::manager(new ReactorManager())['version']);
	}
	finally{
		ReactorManifest::useVersionFile(null);
	}
})->tag('reactor', 'coverage')->group('framework-coverage');
