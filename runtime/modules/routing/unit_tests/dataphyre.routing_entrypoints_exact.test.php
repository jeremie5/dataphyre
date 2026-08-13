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

require_once __DIR__.'/routing_entrypoint_test_helpers.php';

suite('Routing entrypoint and legacy matcher contract')
	->contract('routing.entrypoints', 1)
	->layer('integration')
	->risk('high')
	->watches('module:routing')
	->through('bootstrap', 'exact-routes', 'parameters', 'formats', 'not-found', 'compiler', 'project-roots')
	->isolation('case')
	->tag('routing', 'entrypoints', 'exact-coverage')
	->group('framework-coverage');

test('routing bootstrap loads each configured layer before delegating terminal handling', static function(Context $t): void {
	$scenario=DpLegacyRoutingScenario::open($t);
	$common=$scenario->configurationRoot('common', ['not_found_errorpage'=>'common-404']);
	$project=$scenario->configurationRoot('project', ['not_found_errorpage'=>'project-404']);
	$result=\dataphyre\routing_bootstrap(true, [
		'roots'=>['common_dataphyre'=>$common, 'dataphyre'=>$project],
		'not_found'=>static fn(array $runtime): string=>'terminal',
	]);
	$t->isTrue($result['loaded']);
	$t->count(2, $result['paths']);
	$t->same('terminal', $result['not_found']);
	$t->same('http://example.test/project-404', $scenario->currentNotFound(['HTTP_HOST'=>'example.test'])['location']);

	$errors=[];
	$empty=\dataphyre\routing_bootstrap(true, [
		'roots'=>['common_dataphyre'=>$scenario->emptyRoot('empty')],
		'error'=>static function(string $message) use (&$errors): void { $errors[]=$message; },
		'not_found'=>static fn(array $runtime): string=>'missing-terminal',
	]);
	$t->isFalse($empty['loaded']);
	$t->contains('No routes available', $errors[0]);
	$t->same('missing-terminal', $empty['not_found']);
	$t->throws(static fn()=>\dataphyre\routing_bootstrap(true, [
		'roots'=>[], 'error'=>'invalid', 'not_found'=>static fn(): null=>null,
	]), LogicException::class);
	$t->throws(static fn()=>\dataphyre\routing_bootstrap(true, [
		'roots'=>['dataphyre'=>$project], 'not_found'=>'invalid',
	]), LogicException::class);
	$defaultRoots=\dataphyre\routing_bootstrap(true, [
		'error'=>static fn(string $message): null=>null,
		'not_found'=>static fn(array $runtime): string=>'default-roots-terminal',
	]);
	$t->same('default-roots-terminal', $defaultRoots['not_found']);
	$t->same(['loaded'=>false,'paths'=>[],'not_found'=>null], \dataphyre\routing_bootstrap(false));
});

test('exact route matching normalizes roots files request sources and diagnostic snapshots', static function(Context $t): void {
	$scenario=DpLegacyRoutingScenario::open($t);
	$t->isTrue($scenario->route('/', '/'));
	$view=$scenario->view('about');
	$duplicatedSeparator=str_replace('/views/', '/views//', $view);
	$t->same($view, $scenario->route('/about/', '/about', $duplicatedSeparator));
	$t->isFalse($scenario->route('/missing', '/about'));
	$t->isFalse($scenario->verboseNonMatches(false)->route('/still-missing', '/about'));
	$t->same('/query-path', $scenario->currentRequestUri(['uri'=>' /query-path '], ['REQUEST_URI'=>'/server-path?x=1']));
	$t->same('/server-path', $scenario->currentRequestUri([], ['REQUEST_URI'=>'/server-path?x=1']));

	$snapshot=\dataphyre\routing::debug_snapshot(['REQUEST_URI'=>'/about?x=1','REQUEST_METHOD'=>'POST']);
	$t->hasPathValues([
		'request_path'=>'/about',
		'method'=>'POST',
		'matched_route'=>'/about',
		'matched_file'=>$view,
	], $snapshot);
	$t->isTrue(is_float($snapshot['matched_at']));
});

test('greedy discarded alternative missing and shape-mismatched parameters retain explicit evidence', static function(Context $t): void {
	$scenario=DpLegacyRoutingScenario::open($t);
	$t->isTrue($scenario->route('/files/{...segments}', '/files/2026/reports/final'));
	$t->same(['2026','reports','final'], $scenario->binding('segments'));
	$t->isTrue($scenario->route('/files/{...void}', '/files/private/discarded'));
	$t->same(null, $scenario->binding('void'));
	$t->isTrue($scenario->route('/status/{draft|published|status}', '/status/draft'));
	$t->same('draft', $scenario->binding('status'));
	$t->isFalse($scenario->route('/status/{draft|published|status}', '/status/archived'));
	$t->isFalse($scenario->route('/users/{id}', '/users'));
	$t->isFalse($scenario->route('/users/{id}/edit', '/accounts/42/edit'));
	$t->isFalse($scenario->verboseNonMatches(false)->route('/status/{draft|published|status}', '/status/archived'));
});

test('formatted route vocabulary captures every supported scalar identifier kind', static function(Context $t): void {
	$scenario=DpLegacyRoutingScenario::open($t);
	$t->same('AB12', $scenario->formatted('starts_with_and_length_is,AB,4,id', 'AB12', 'id'));
	$t->same('AB12', $scenario->formatted('character_at_position_is,2,B,id', 'AB12', 'id'));
	$t->same('AB-anything', $scenario->formatted('starts_with,AB,unused,id', 'AB-anything', 'id'));
	$t->same('anything-ZZ', $scenario->formatted('ends_with,ZZ,unused,id', 'anything-ZZ', 'id'));
	$t->same('ABZZ', $scenario->formatted('ends_with_and_length_is,ZZ,4,id', 'ABZZ', 'id'));
	$t->same('four', $scenario->formatted('length_is,unused,4,id', 'four', 'id'));
	$t->same(42, $scenario->formatted('is_integer,id', '42', 'id'));
	$t->same('42.5', $scenario->formatted('is_numeric,value', '42.5', 'value'));
	$t->same('word', $scenario->formatted('is_string,value', 'word', 'value'));
	$payload=$scenario->formatted('is_urlcoded_json,payload', rawurlencode('{"ok":true}'), 'payload');
	$t->instanceOf(stdClass::class, $payload);
	$t->same(true, $payload->ok);
	$hash=md5('routing');
	$t->same($hash, $scenario->formatted('is_md5,hash', $hash, 'hash'));
	$uuid='123e4567-e89b-12d3-a456-426614174000';
	$t->same($uuid, $scenario->formatted('is_uuid,id', $uuid, 'id'));
	$t->same('draft', $scenario->formatted('is_either,status,draft,published', 'draft', 'status'));
	$t->same('article', $scenario->formatted('slug', 'article', 'slug'));
	$t->isTrue($scenario->rejectsFormatted('is_integer,id', 'not-an-integer'));
});

test('not-found policy returns inspectable redirect and inline response envelopes', static function(Context $t): void {
	$scenario=DpLegacyRoutingScenario::open($t);
	$forwarded=$scenario->notFound('errors/not-found', [
		'HTTP_X_FORWARDED_PROTO'=>'https','HTTP_HOST'=>'secure.example.test',
	]);
	$t->hasPathValues([
		'status'=>302,
		'location'=>'https://secure.example.test/errors/not-found',
		'body'=>'',
	], $forwarded);
	$nativeTls=$scenario->notFound('/missing', ['HTTPS'=>'on','SERVER_NAME'=>'native.example.test']);
	$t->same('https://native.example.test/missing', $nativeTls['location']);
	$plain=$scenario->notFound('/missing', ['HTTP_HOST'=>'plain.example.test']);
	$t->same('http://plain.example.test/missing', $plain['location']);
	$inline=$scenario->notFound('', []);
	$t->same(404, $inline['status']);
	$t->contains("doesn't exist", $inline['body']);
	$t->throws(static fn()=>\dataphyre\routing::not_found(['server'=>[], 'emit'=>'invalid']), LogicException::class);
});

test('compiler entrypoint handles help web missing success failure and process boundaries', static function(Context $t): void {
	$scenario=DpRouteCompilerScenario::open($t);
	$help=$scenario->dispatch(['--help']);
	$t->same(0, $help->result());
	$t->contains('Usage: php', $help->output());
	$t->same([0], $scenario->terminations());
	$t->same(null, dp_route_compiler_entrypoint(['compile_app_routes.php'], false));
	$t->same(2, $scenario->run([], ['sapi'=>'apache2handler'])->result());
	$t->same(1, $scenario->run([])->result());
	$t->contains('Run with --help', $scenario->errors()[0]);
	$t->throws(static fn()=>dp_route_compiler_run(['compile_app_routes.php'], ['error'=>'invalid']), LogicException::class);

	$bootstrap=$t->spy()->willReturn(null);
	$compile=$t->spy()->willReturn('/cache/shop.routes.php');
	$success=$scenario->run(['shop'], [
		'runtime_root'=>'/runtime',
		'package_root'=>'/package',
		'project_root'=>'/project',
		'bootstrap'=>$bootstrap,
		'compile'=>$compile,
	]);
	$t->same(0, $success->result());
	$t->contains('/cache/shop.routes.php', $success->output());
	$bootstrap->assertCalledWith($t, ['/runtime']);
	$compile->assertCalledWith($t, ['/project','shop']);

	$failure=$scenario->run(['shop'], [
		'bootstrap'=>$t->spy()->willReturn(null),
		'compile'=>$t->spy()->willThrow(new RuntimeException('compiler failed')),
	]);
	$t->same(2, $failure->result());
	$t->contains('compiler failed', $scenario->errors()[1]);
	$t->throws(static fn()=>dp_route_compiler_run(['compile_app_routes.php','shop'], [
		'bootstrap'=>'invalid','compile'=>'invalid','error'=>'invalid',
	]), LogicException::class);
	$t->throws(static fn()=>dp_route_compiler_entrypoint(
		['compile_app_routes.php','--help'], true, ['terminate'=>'invalid']
	), LogicException::class);
});

test('project-root resolution recognizes configured embedded legacy standalone and missing layouts', static function(Context $t): void {
	$scenario=DpRouteCompilerScenario::open($t);
	$configured=$scenario->directory('configured');
	$t->same($configured, resolve_project_root('/ignored', $configured));
	$missingConfigured=$scenario->path('configured-missing/');
	$t->same(rtrim($missingConfigured, '/'), resolve_project_root('/ignored', $missingConfigured));

	$embeddedPackage=$scenario->directory('embedded/common/dataphyre');
	$t->same($scenario->path('embedded'), resolve_project_root($embeddedPackage, false));
	$legacyPackage=$scenario->directory('legacy/dataphyre');
	$scenario->directory('legacy/applications');
	$t->same($scenario->path('legacy'), resolve_project_root($legacyPackage, false));
	$standalone=$scenario->directory('standalone');
	$t->same($standalone, resolve_project_root($standalone, false));
	$missing=$scenario->path('standalone-missing');
	$t->same($missing, resolve_project_root($missing, false));
});

test('compiler runtime bootstrap exposes the same module surface used by direct CLI execution', static function(Context $t): void {
	$runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	dp_route_compiler_bootstrap($runtime);
	$t->isTrue(class_exists(\Dataphyre\Routing\Tools\CompileApplicationRoutes::class));
});
