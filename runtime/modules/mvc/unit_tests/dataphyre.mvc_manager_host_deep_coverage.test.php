<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcManager;
use Dataphyre\Mvc\SignedUrlMiddleware;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!class_exists('dataphyre\\mvc', false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class mvc {
	public static mixed $runtimeConfig=[];
	public static function config(string $key, mixed $default=null): mixed {
		return is_array(self::$runtimeConfig) && array_key_exists($key, self::$runtimeConfig)
			? self::$runtimeConfig[$key]
			: $default;
	}
}
PHP);
}

framework(['http', 'routing', 'mvc']);

test('mvc manager covers singleton configuration overlays registration routes and captured dispatch', static function(Context $t): void {
	$mvcConfig=$t->nonPublic(\dataphyre\mvc::class);
	$mvcConfig->replacePropertyForTest('runtimeConfig',[
			'default_app'=>'configured',
			'controllers'=>['namespace'=>'Coverage\\Controllers'],
			'models'=>['namespace'=>'Coverage\\Models', 'options'=>['global'=>true]],
			'views'=>['path'=>'C:/coverage/views'],
			'middleware'=>['custom'=>SignedUrlMiddleware::class],
			'global_middleware'=>[],
			'middleware_stack'=>['global-stack'],
			'middleware_groups'=>['web'=>['custom']],
			'providers'=>[],
			'model_bindings'=>['order'=>'Coverage\\Models\\Order'],
			'signed_url_secret'=>'manager-secret',
			'routes'=>[],
			'manifest_cache'=>true,
			'response_headers'=>['X-Global'=>'yes'],
			'not_found_handler'=>null,
			'error_handler'=>null,
			'apps'=>[
				'configured'=>[
					'models'=>['namespace'=>'Coverage\\NamedModels'],
					'middleware'=>['named'=>SignedUrlMiddleware::class],
					'middleware_stack'=>[],
					'response_headers'=>['X-App'=>'yes'],
				],
			],
	]);
	$t->cleanup(static function(): void { MvcManager::flush(); });

	MvcManager::flush();
		$manager=MvcManager::instance();
		$t->same($manager, MvcManager::instance());
		$configured=$manager->app('configured');
		$t->same($configured, $manager->app(' configured '));
		$t->same('configured', $configured->name());
		$t->same('Coverage\\NamedModels', $configured->config('models')['namespace']);
		$t->isTrue($configured->config('models')['options']['global']);
		$t->same([], $configured->config('middleware_stack'));
		$t->same('yes', $configured->config('response_headers')['X-Global']);
		$t->same('yes', $configured->config('response_headers')['X-App']);
		$t->contains('custom', array_keys($configured->config('middleware')));
		$t->contains('named', array_keys($configured->config('middleware')));
		$t->contains('signed', array_keys($configured->config('middleware')));

		$blankApp=$manager->app('   ');
		$t->same('default', $blankApp->name());
		$arrayApp=$manager->register('array-app', ['response_headers'=>['X-Array'=>'yes']]);
		$t->same('array-app', $arrayApp->name());
		$t->same('yes', $arrayApp->config('response_headers')['X-Array']);

		$objectDefault=new MvcApplication('object-default');
		$t->same($objectDefault, $manager->register('   ', $objectDefault));
		$t->same($configured, $manager->defaultApp());
		$runtimeConfig=$mvcConfig->readProperty('runtimeConfig');
		$runtimeConfig['default_app']=[];
		$mvcConfig->writeProperty('runtimeConfig',$runtimeConfig);
		$t->same($objectDefault, $manager->defaultApp());

		$manager->routes('configured')->get('/named', static fn(): string=>'named-response');
		$manager->routes()->get('/default', static fn(): string=>'default-response');
		$manager->routes()->get('/captured', static fn(): string=>'captured-response');
		$t->same('named-response', $manager->dispatch(Request::create('GET', '/named'), 'configured')->body);
		$t->same('default-response', $manager->dispatch(Request::create('GET', '/default'))->body);

		$t->globalMap('_GET')->clear();
		$t->globalMap('_POST')->clear();
		$t->globalMap('_COOKIE')->clear();
		$t->globalMap('_FILES')->clear();
		$t->globalMap('_SERVER')->replace(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/captured']);
		$t->same('captured-response', $manager->dispatch()->body);

		$managerInternals=$t->nonPublic(MvcManager::class);
		$t->same([],$managerInternals->invoke('mergeConfig',['list'=>['inherited']],['list'=>[]])['list']);
		$t->isTrue($managerInternals->invoke('isList',[]));
		$t->same('override', MvcManager::mergeMiddlewareDefaults(['signed'=>'override'])['signed']);

		MvcManager::flush();
		$t->isTrue(spl_object_id($manager)!==spl_object_id(MvcManager::instance()));
})->tag('mvc', 'model-binding', 'mvc-manager-host-exact')->group('framework-coverage');
