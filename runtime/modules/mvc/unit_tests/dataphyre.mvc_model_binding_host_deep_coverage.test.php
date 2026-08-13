<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Mvc\Model;
use Dataphyre\Mvc\MvcApplication;
use Dataphyre\Mvc\MvcHost;
use Dataphyre\Mvc\MvcManager;
use Dataphyre\Mvc\RouteModelBinder;
use Dataphyre\Mvc\RouteModelNotFoundException;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http', 'routing', 'mvc']);

final class DpMvcBinderResolvedModel extends Model {
	public static function resolveRouteBinding(mixed $value, string $key='id'): mixed {
		return match($value){
			'model'=>new self([$key=>$value, 'source'=>'model']),
			'array'=>[$key=>$value, 'source'=>'array'],
			default=>null,
		};
	}
	public static function routeKeyName(): string {
		return ' slug ';
	}
	public function action(): void {}
}

final class DpMvcBinderFindModel extends Model {
	public static function find(mixed $id, string $key='id'): ?array {
		return $id==='found' ? [$key=>$id, 'source'=>'find'] : null;
	}
	public static function routeKeyName(): string {
		return '  ';
	}
	public function action(): void {}
}

final class DpMvcBinderPlainModel extends Model {
	public static function find(mixed $id, string $key='id'): ?array {
		return [$key=>$id, 'source'=>'plain'];
	}
}

final class DpMvcBinderCallable {
	public function method(DpMvcBinderResolvedModel $item): void {}
	public function __invoke(DpMvcBinderFindModel $item): void {}
}

if(!class_exists('Coverage\\Models\\ShortModel', false)){
	class_alias(DpMvcBinderResolvedModel::class, 'Coverage\\Models\\ShortModel');
}

test('mvc route model binder resolves aliases models arrays existing values and unique typed injection', static function(Context $t): void {
	$app=new MvcApplication('binding', [
		'models'=>['namespace'=>'Coverage\\Models'],
		'model_bindings'=>[
			'item'=>['model'=>'ShortModel', 'param'=>'slug'],
			'found'=>DpMvcBinderFindModel::class,
		],
	]);
	$callable=static function(
		DpMvcBinderResolvedModel $item,
		DpMvcBinderFindModel $found,
		DpMvcBinderPlainModel $missing,
		string $builtin,
		$untyped,
		stdClass $ordinary
	): void {};
	$result=RouteModelBinder::resolveForCallable($callable, $app, [
		'slug'=>'array',
		'found'=>'found',
		'builtin'=>'text',
	]);
	$t->instanceOf(DpMvcBinderResolvedModel::class, $result['parameters']['slug']);
	$t->same($result['parameters']['slug'], $result['parameters']['item']);
	$t->instanceOf(DpMvcBinderFindModel::class, $result['parameters']['found']);
	$t->same('array', $result['models']['item']->get('slug'));
	$t->same('found', $result['models']['found']->get('id'));
	$t->same($result['models']['item'], $result['typed_values'][DpMvcBinderResolvedModel::class]);
	$t->same($result['models']['found'], $result['typed_values'][DpMvcBinderFindModel::class]);
	$t->isFalse(array_key_exists('missing', $result['models']));

	$existing=new DpMvcBinderResolvedModel(['slug'=>'existing']);
	$existingResult=RouteModelBinder::resolveForCallable(
		static function(DpMvcBinderResolvedModel $item): void {},
		new MvcApplication('existing'),
		['item'=>$existing]
	);
	$t->same($existing, $existingResult['models']['item']);

	$duplicates=RouteModelBinder::resolveForCallable(
		static function(DpMvcBinderResolvedModel $first, DpMvcBinderResolvedModel $second): void {},
		new MvcApplication('duplicates'),
		['one'=>'model', 'two'=>'array'],
		[
			'first'=>['model'=>DpMvcBinderResolvedModel::class, 'parameter'=>'one'],
			'second'=>['model'=>DpMvcBinderResolvedModel::class, 'param'=>'two'],
		]
	);
	$t->same([], $duplicates['typed_values']);
	$t->instanceOf(DpMvcBinderResolvedModel::class, $duplicates['models']['first']);
	$t->instanceOf(DpMvcBinderResolvedModel::class, $duplicates['models']['second']);

	$typeBinding=RouteModelBinder::resolveForCallable(
		static function(DpMvcBinderResolvedModel $byType): void {},
		new MvcApplication('type-binding'),
		['typed'=>'model'],
		[DpMvcBinderResolvedModel::class=>['model'=>DpMvcBinderResolvedModel::class, 'param'=>'typed']]
	);
	$t->instanceOf(DpMvcBinderResolvedModel::class, $typeBinding['models']['byType']);
	$t->throws(
		static fn()=>RouteModelBinder::resolveForCallable(
			static function(DpMvcBinderResolvedModel $item): void {},
			new MvcApplication('invalid-binding'),
			['item'=>'one'],
			['item'=>['model'=>stdClass::class]]
		),
		RuntimeException::class
	);
})->tag('mvc', 'model-binding', 'deep-coverage')->group('framework-coverage');

test('mvc route model binder private normalizers cover lookup outcomes keys callable shapes and binding declarations', static function(Context $t): void {
	$app=new MvcApplication('private-binding', ['models'=>['namespace'=>'Coverage\\Models']]);
	$binderInternals=$t->nonPublic(RouteModelBinder::class);
	$model=new DpMvcBinderResolvedModel(['slug'=>'ready']);
	$t->same($model, $binderInternals->invoke('resolveModel', DpMvcBinderResolvedModel::class, 'item', $model, 'slug'));
	$t->instanceOf(DpMvcBinderResolvedModel::class, $binderInternals->invoke('resolveModel', DpMvcBinderResolvedModel::class, 'item', 'model', 'slug'));
	$t->instanceOf(DpMvcBinderResolvedModel::class, $binderInternals->invoke('resolveModel', DpMvcBinderResolvedModel::class, 'item', 'array', 'slug'));
	$t->instanceOf(DpMvcBinderFindModel::class, $binderInternals->invoke('resolveModel', DpMvcBinderFindModel::class, 'item', 'found', 'id'));
	try{
		$binderInternals->invoke('resolveModel', DpMvcBinderFindModel::class, 'item', 'missing', 'uuid');
		$t->fail('Missing route model should throw.');
	}
	catch(RouteModelNotFoundException $exception){
		$t->same(DpMvcBinderFindModel::class, $exception->modelClass());
		$t->same('item', $exception->parameter());
		$t->same('missing', $exception->value());
		$t->same('uuid', $exception->key());
	}

	$normalized=$binderInternals->invoke('normalizeBindings', [
		['param'=>'first', 'model'=>DpMvcBinderResolvedModel::class],
		['parameter'=>'second', 'model'=>DpMvcBinderFindModel::class],
		['name'=>'third', 'model'=>DpMvcBinderPlainModel::class],
		['model'=>DpMvcBinderPlainModel::class],
		''=>DpMvcBinderPlainModel::class,
		'\\trimmed\\'=>DpMvcBinderPlainModel::class,
	]);
	$t->same(['first', 'second', 'third', 'trimmed'], array_keys($normalized));
	$t->same(['model'=>DpMvcBinderPlainModel::class], $normalized['trimmed']);
	$t->same('', $binderInternals->invoke('modelClass', null, $app));
	$t->same(DpMvcBinderResolvedModel::class, $binderInternals->invoke('modelClass', DpMvcBinderResolvedModel::class, $app));
	$t->same('Coverage\\Models\\ShortModel', $binderInternals->invoke('modelClass', 'ShortModel', $app));
	$t->same('UnknownModel', $binderInternals->invoke('modelClass', 'UnknownModel', new MvcApplication('no-namespace')));
	$t->same('slug', $binderInternals->invoke('routeKeyName', DpMvcBinderResolvedModel::class));
	$t->same('id', $binderInternals->invoke('routeKeyName', DpMvcBinderFindModel::class));
	$t->same('id', $binderInternals->invoke('routeKeyName', DpMvcBinderPlainModel::class));
	$t->same([], $binderInternals->invoke('typedValues', [
		'one'=>new DpMvcBinderResolvedModel(),
		'two'=>new DpMvcBinderResolvedModel(),
	]));

	$callable=new DpMvcBinderCallable();
	$t->instanceOf(ReflectionFunctionAbstract::class, $binderInternals->invoke('reflect', [$callable, 'method']));
	$t->instanceOf(ReflectionFunctionAbstract::class, $binderInternals->invoke('reflect', $callable));
	$t->instanceOf(ReflectionFunction::class, $binderInternals->invoke('reflect', static function(): void {}));
})->tag('mvc', 'model-binding', 'deep-coverage')->group('framework-coverage');

test('mvc host dispatches and emits while manager recursive configuration helpers cover nested and list overlays', static function(Context $t): void {
	$manager=new MvcManager();
	$app=new MvcApplication('host');
	$app->routes()->get('/host', static fn(): string=>'host-body');
	$manager->register('host', $app);
	$host=new MvcHost($manager, 'host');
	$dispatched=$host->dispatch(Request::create('GET', '/host'));
	$t->same('host-body', $dispatched->body);
	$emission=$t->captureOutput(static fn()=>$host->emit(Request::create('GET', '/host')));
	$emitted=$emission->result();
	$output=$emission->output();
	$t->same('host-body', $emitted->body);
	$t->same('host-body', $output);

	$managerInternals=$t->nonPublic(MvcManager::class);
	$merged=$managerInternals->invoke('mergeConfig', [
		'nested'=>['left'=>1, 'shared'=>['a'=>1]],
		'list'=>['base'],
	], [
		'nested'=>['right'=>2, 'shared'=>['b'=>2]],
		'list'=>['override'],
	]);
	$t->same(['left'=>1, 'shared'=>['a'=>1, 'b'=>2], 'right'=>2], $merged['nested']);
	$t->same(['override'], $merged['list']);
	$t->isTrue($managerInternals->invoke('isList', ['a', 'b']));
	$t->isFalse($managerInternals->invoke('isList', ['name'=>'value']));
	$t->contains('auth', array_keys(MvcManager::mergeMiddlewareDefaults('invalid')));
})->tag('mvc', 'model-binding', 'deep-coverage')->group('framework-coverage');
