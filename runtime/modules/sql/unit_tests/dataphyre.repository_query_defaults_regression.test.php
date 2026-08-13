<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\Relation;
use Dataphyre\Database\RepositoryQuery;
use Dataphyre\Database\TableRepository;
use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\test;

require_once __DIR__.'/sql_framework_test_helpers.php';
require_once __DIR__.'/../Framework/ExecutionTrace.php';
require_once __DIR__.'/../Framework/DB.php';
require_once __DIR__.'/../Framework/Concerns/TransformsRows.php';
require_once __DIR__.'/../Framework/RepositoryQuery.php';
require_once __DIR__.'/../Framework/Relation.php';

function dp_repository_defaults_state(): TestState {
	return TestState::channel('sql.repository-query-defaults');
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		$state=dp_repository_defaults_state();
		$state->append('select_calls', $arguments);
		$table=(string)($arguments[1] ?? '');
		$rows=$state->get('rows_by_table', [])[$table] ?? [];
		$callback=$arguments[7] ?? null;
		$associative=(bool)($arguments[4] ?? false);
		$result=$associative ? $rows : ($rows[0] ?? null);
		if(is_callable($callback)){
			$callback($result);
			return null;
		}
		return $result;
	}
}

if(!function_exists('sql_update')){
	function sql_update(mixed ...$arguments): mixed {
		$state=dp_repository_defaults_state();
		$state->append('update_calls', $arguments);
		$callback=$arguments[6] ?? null;
		if(is_callable($callback)){
			$callback(1);
			return null;
		}
		return 1;
	}
}

if(!class_exists('dataphyre\\sql', false)){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre; final class sql { public static array $observers=[]; public static function add_observer(callable $observer): void { self::$observers[]=$observer; } public static function clear_last_query_error(): void {} public static function hydrate_missing_structure_from_definition(string $table): bool { return false; } public static function invalidate_cache(string|array $target): bool { return true; } }');
}

final class DpRepositoryDefaultsParent extends TableRepository {
	protected static function table(): string {
		return 'repository_default_parents';
	}

	protected static function spec(): QuerySpec {
		return (new QuerySpec())
			->whereEq('tenant_id', 42)
			->whereNull('deleted_at');
	}

	protected static function defaultReadCaching(): array {
		return [true, 'repository.parents'];
	}

	protected static function defaultWriteInvalidation(): bool|array|null {
		return ['repository.parents'];
	}

	protected static function requireWriteWhere(): bool {
		return true;
	}

	public static function children(): Relation {
		return self::hasMany(DpRepositoryDefaultsChild::class, 'parent_id', 'id');
	}
}

final class DpRepositoryDefaultsChild extends TableRepository {
	protected static function table(): string {
		return 'repository_default_children';
	}

	protected static function spec(): QuerySpec {
		return (new QuerySpec())
			->whereEq('tenant_id', 42)
			->whereEq('visibility', 'public');
	}

	protected static function defaultReadCaching(): array {
		return [true, 'repository.children'];
	}
}

/** @return array<int,mixed> */
function dp_repository_defaults_last_call(string $key): array {
	$calls=dp_repository_defaults_state()->get($key, []);
	return is_array($calls) && $calls!==[] ? $calls[array_key_last($calls)] : [];
}

function dp_repository_defaults_scenario(Context $t): TestState {
	return $t->state('sql.repository-query-defaults', [
		'select_calls'=>[],
		'update_calls'=>[],
		'rows_by_table'=>[
			'repository_default_parents'=>[
				['id'=>1, 'tenant_id'=>42, 'deleted_at'=>null, 'name'=>'Parent'],
			],
			'repository_default_children'=>[
				['id'=>10, 'tenant_id'=>42, 'parent_id'=>11, 'visibility'=>'public'],
			],
		],
	]);
}

test('repository query starts from the repository spec including relation-generated queries', static function(Context $t): void {
	dp_repository_defaults_scenario($t);
	$compiled=DpRepositoryDefaultsParent::query()->whereEq('id', 9)->compile(false);
	$t->contains('tenant_id = ?', (string)$compiled['params']);
	$t->contains('deleted_at IS NULL', (string)$compiled['params']);
	$t->contains('id = ?', (string)$compiled['params']);
	$t->same([42, 9], $compiled['vars']);

	DpRepositoryDefaultsParent::relationNamed('children')->eager([['id'=>11]]);
	$relationCall=dp_repository_defaults_last_call('select_calls');
	$t->same('repository_default_children', $relationCall[1] ?? null);
	$t->contains('tenant_id = ?', (string)($relationCall[2] ?? ''));
	$t->contains('visibility = ?', (string)($relationCall[2] ?? ''));
	$t->contains('parent_id IN (?)', (string)($relationCall[2] ?? ''));
	$t->same([42, 'public', 11], $relationCall[3] ?? null);
})->tag('sql', 'repository-query', 'scope', 'regression')->group('framework-regression');

test('repository query defers read cache policy to the repository while preserving explicit overrides', static function(Context $t): void {
	dp_repository_defaults_scenario($t);

	$query=DpRepositoryDefaultsParent::query();
	$state=$query->executionState();
	$t->same([true, 'repository.parents'], $state['caching'] ?? null);
	$t->same(['repository.parents'], $state['clear_cache_on_write'] ?? null);

	$query->get();
	$t->same([true, 'repository.parents'], dp_repository_defaults_last_call('select_calls')[5] ?? null);
	RepositoryQuery::fromExecutionState($state)->get();
	$t->same([true, 'repository.parents'], dp_repository_defaults_last_call('select_calls')[5] ?? null);

	DpRepositoryDefaultsParent::query()->cache(['explicit.read'])->get();
	$t->same(['explicit.read'], dp_repository_defaults_last_call('select_calls')[5] ?? null);

	DpRepositoryDefaultsParent::query()->withoutCaching()->get();
	$t->same(false, dp_repository_defaults_last_call('select_calls')[5] ?? null);

	$mergedNames=DpRepositoryDefaultsParent::query()
		->cacheName('repository.extra')
		->cacheNames('repository.audit', 'repository.extra')
		->executionState();
	$t->same(
		[true, 'repository.parents', 'repository.extra', 'repository.audit'],
		$mergedNames['caching'] ?? null
	);

	$queuedRows=null;
	DpRepositoryDefaultsParent::query()->queueGet(static function(array $rows)use(&$queuedRows): void {
		$queuedRows=$rows;
	}, 'repository-defaults');
	$queuedCall=dp_repository_defaults_last_call('select_calls');
	$t->same([true, 'repository.parents'], $queuedCall[5] ?? null);
	$t->same('repository-defaults', $queuedCall[6] ?? null);
	$t->same(1, count($queuedRows ?? []));
	DpRepositoryDefaultsParent::query()
		->cache(['queued.read'])
		->queueGet(static fn(array $rows): int=>count($rows), 'repository-overrides');
	$queuedOverride=dp_repository_defaults_last_call('select_calls');
	$t->same(['queued.read'], $queuedOverride[5] ?? null);
	$t->same('repository-overrides', $queuedOverride[6] ?? null);
})->tag('sql', 'repository-query', 'cache', 'queue', 'regression')->group('framework-regression');

test('repository query defers write invalidation and execution-state restoration preserves overrides', static function(Context $t): void {
	dp_repository_defaults_scenario($t);

	DpRepositoryDefaultsParent::query()->update(['name'=>'Default']);
	$defaultCall=dp_repository_defaults_last_call('update_calls');
	$t->contains('tenant_id = ?', (string)($defaultCall[2] ?? ''));
	$t->contains('deleted_at IS NULL', (string)($defaultCall[2] ?? ''));
	$t->same([42], $defaultCall[3] ?? null);
	$t->same(['repository.parents'], $defaultCall[4] ?? null);

	$mergedNames=DpRepositoryDefaultsParent::query()
		->invalidateCacheName('repository.extra')
		->invalidateCacheNames('repository.audit', 'repository.extra')
		->executionState();
	$t->same(
		['repository.parents', 'repository.extra', 'repository.audit'],
		$mergedNames['clear_cache_on_write'] ?? null
	);

	\Dataphyre\Database\DB::clearTraceBuffer();
	\Dataphyre\Database\DB::enableGuardrails();
	$guarded=DpRepositoryDefaultsParent::query()->cacheName('repository.extra');
	$guarded->update(['name'=>'Default invalidation']);
	$t->same([], \Dataphyre\Database\DB::recentTraces());
	$guarded->withoutInvalidation()->update(['name'=>'Explicitly uninvalidated']);
	$t->same('guardrail_warning', \Dataphyre\Database\DB::lastTrace()?->event());
	\Dataphyre\Database\DB::disableGuardrails();
	\Dataphyre\Database\DB::clearTraceBuffer();

	$explicit=DpRepositoryDefaultsParent::query()
		->cache(['explicit.read'])
		->invalidateOnWrite(['explicit.write']);
	$explicitState=$explicit->executionState();
	$t->same(['explicit.read'], $explicitState['caching'] ?? null);
	$t->same(['explicit.write'], $explicitState['clear_cache_on_write'] ?? null);
	$restored=RepositoryQuery::fromExecutionState($explicitState);
	$restored->get();
	$t->same(['explicit.read'], dp_repository_defaults_last_call('select_calls')[5] ?? null);
	$restored->update(['name'=>'Explicit']);
	$t->same(['explicit.write'], dp_repository_defaults_last_call('update_calls')[4] ?? null);

	$disabledState=DpRepositoryDefaultsParent::query()
		->withoutCaching()
		->withoutInvalidation()
		->executionState();
	$t->same(false, $disabledState['caching'] ?? null);
	$t->same(false, $disabledState['clear_cache_on_write'] ?? null);
	$disabled=RepositoryQuery::fromExecutionState($disabledState);
	$disabled->get();
	$t->same(false, dp_repository_defaults_last_call('select_calls')[5] ?? null);
	$disabled->update(['name'=>'Disabled']);
	$t->same(false, dp_repository_defaults_last_call('update_calls')[4] ?? null);

	$queuedResult=null;
	DpRepositoryDefaultsParent::query()->queueUpdate(
		['name'=>'Queued'],
		static function(mixed $result)use(&$queuedResult): void {
			$queuedResult=$result;
		},
		'repository-defaults'
	);
	$queuedCall=dp_repository_defaults_last_call('update_calls');
	$t->same(['repository.parents'], $queuedCall[4] ?? null);
	$t->same('repository-defaults', $queuedCall[5] ?? null);
	$t->same(1, $queuedResult);
	DpRepositoryDefaultsParent::query()
		->invalidateOnWrite(['queued.write'])
		->queueUpdate(['name'=>'Queued override'], static fn(mixed $result): mixed=>$result, 'repository-overrides');
	$queuedOverride=dp_repository_defaults_last_call('update_calls');
	$t->same(['queued.write'], $queuedOverride[4] ?? null);
	$t->same('repository-overrides', $queuedOverride[5] ?? null);
})->tag('sql', 'repository-query', 'invalidation', 'queue', 'regression')->group('framework-regression');
