<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Api\Endpoint;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;


framework(['api', 'routing']);
suite('API endpoint deep coverage')->sandboxesRootpath('api_cache');

final class DpApiEndpointCoverageTarget {
	public static function handle(): array {
		return ['ok'=>true];
	}
}

final class DpApiEndpointCoverageState {
	public function __construct(private mixed $state) {}

	public function executionState(): mixed {
		return $this->state;
	}
}

test('api endpoint normalizes every supported execution target shape and rejects malformed targets', static function(Context $t): void {
	$handler=static fn(): null=>null;
	$parameter=Endpoint::get('/parameters', $handler)
		->queryParameter('filter', [], ['examples'=>['active'=>['value'=>'yes']]])
		->compile()['api']['parameters'][0];
	$t->same(['active'=>['value'=>'yes']], $parameter['examples']);

	$callable=Endpoint::get('/callable')
		->execute(' endpoint.coverage ', ['bootstrap'=>' callable-bootstrap.php '])
		->compile()['api']['execution'];
	$t->same('callable', $callable['type']);
	$t->same('endpoint.coverage', $callable['reference']);
	$t->same('callable-bootstrap.php', $callable['bootstrap']);

	$classMethod=Endpoint::get('/class-method')
		->execute('\\'.DpApiEndpointCoverageTarget::class.':: handle ', ['bootstrap'=>' class-bootstrap.php '])
		->compile()['api']['execution'];
	$t->same('class_method', $classMethod['type']);
	$t->same(DpApiEndpointCoverageTarget::class, $classMethod['class']);
	$t->same('handle', $classMethod['method']);
	$t->isTrue($classMethod['static']);

	$tuple=Endpoint::get('/tuple')
		->execute(['\\'.DpApiEndpointCoverageTarget::class, ' handle '])
		->compile()['api']['execution'];
	$t->same(DpApiEndpointCoverageTarget::class, $tuple['class']);
	$t->same('handle', $tuple['method']);
	$t->isTrue($tuple['static']);

	$arrayClass=Endpoint::get('/array-class')
		->execute([
			'class'=>'\\'.DpApiEndpointCoverageTarget::class,
			'method'=>' handle ',
			'static'=>false,
			'bootstrap'=>' array-bootstrap.php ',
		], ['bootstrap'=>' ignored-bootstrap.php '])
		->compile()['api']['execution'];
	$t->same(DpApiEndpointCoverageTarget::class, $arrayClass['class']);
	$t->same('handle', $arrayClass['method']);
	$t->isFalse($arrayClass['static']);
	$t->same('array-bootstrap.php', $arrayClass['bootstrap']);

	$arrayReference=Endpoint::get('/array-reference')
		->execute(['reference'=>' endpoint.array ', 'bootstrap'=>' reference-bootstrap.php '])
		->compile()['api']['execution'];
	$t->same('callable', $arrayReference['type']);
	$t->same('endpoint.array', $arrayReference['reference']);
	$t->same('reference-bootstrap.php', $arrayReference['bootstrap']);

	$t->throws(static fn()=>Endpoint::get('/empty')->execute('   '), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad-class')->execute('Broken::   '), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad-tuple')->execute(['', 'handle']), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/bad-array')->execute(['class'=>'', 'method'=>'']), RuntimeException::class);
})->tag('api', 'coverage')->group('framework-coverage');

test('api endpoint validates identity query search cache and static metadata edge cases', static function(Context $t): void {
	$state=new DpApiEndpointCoverageState(['table'=>'coverage']);
	$withoutState=new stdClass();

	$t->throws(static fn()=>Endpoint::get('/query-path')->withQueryIdentity(' ', $state), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/search-path')->withSearchIdentity(' ', $state), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/query-state')->withQuery('rows', $withoutState), RuntimeException::class);
	$t->throws(static fn()=>Endpoint::get('/search-state')->withSearch('rows', $withoutState), RuntimeException::class);
	$t->throws(
		static fn()=>Endpoint::get('/query-value')->withQuery('rows', new DpApiEndpointCoverageState(new stdClass())),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>Endpoint::get('/search-value')->withSearch('rows', new DpApiEndpointCoverageState(new stdClass())),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>Endpoint::get('/binding-value')->withBinding('rows', 'endpoint.coverage', ['identity'=>new stdClass()]),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>Endpoint::get('/cache-value')->cache(30, ['nested'=>['invalid'=>new stdClass()]]),
		RuntimeException::class
	);
})->tag('api', 'coverage')->group('framework-coverage');

test('api endpoint defensive compilers filter corrupted private state and normalize cache names', static function(Context $t): void {
	$endpoint=Endpoint::get('/defensive', static fn(): null=>null);
	$private=$t->nonPublic($endpoint);

	$private->writeProperty('bindings',[
		['path'=>'', 'definition'=>['type'=>'callable']],
		['path'=>'missing-definition', 'definition'=>null],
		['path'=>'valid', 'definition'=>['type'=>'callable']],
	]);

	$private->writeProperty('lifecycle',[
		'before'=>['invalid', ['type'=>'callable', 'reference'=>'endpoint.before']],
		'after'=>[],
		'error'=>[],
	]);

	$compiled=$endpoint->compile()['api'];
	$t->same(1, count($compiled['bindings']));
	$t->same('valid', $compiled['bindings'][0]['path']);
	$t->same(1, count($compiled['lifecycle']['before']));

	$t->same([],$private->invoke('normalizeCacheNames',null));
	$t->same(['orders'],$private->invoke('normalizeCacheNames',[42, '', '  ', ' orders ', 'orders']));
})->tag('api', 'coverage')->group('framework-coverage');
