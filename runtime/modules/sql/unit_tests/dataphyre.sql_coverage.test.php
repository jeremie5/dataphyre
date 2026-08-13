<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\QuerySpec;
use Dataphyre\Database\TableDefinition;
use Dataphyre\Database\TableSchema;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql']);

test('query specs cover comparisons groups conditions locking ordering and paging', static function(Context $t): void {
	$spec=(new QuerySpec())
		->whereEq('orders.tenant_id', 7)
		->whereNotEq('orders.status', 'deleted')
		->whereGt('orders.total_minor', 0)
		->whereGte('orders.created_at', '2026-01-01')
		->whereLt('orders.total_minor', 100000)
		->whereLte('orders.updated_at', '2026-12-31')
		->whereIn('orders.status', ['open', 'paid'])
		->whereNotIn('orders.channel', ['spam'])
		->whereLike('orders.name', '%ada%')
		->whereNotLike('orders.reference', 'test%')
		->whereBetween('orders.id', 1, 100)
		->whereSince('orders.created_at', '2026-01-01')
		->whereUntil('orders.created_at', '2026-12-31')
		->whereAfter('orders.updated_at', '2026-01-01')
		->whereBefore('orders.updated_at', '2027-01-01')
		->whereWithin('orders.total_minor', 100, 50000)
		->inLastMinutes('orders.updated_at', 30)
		->inLastHours('orders.updated_at', 12)
		->inLastDays('orders.updated_at', 7)
		->whereNull('orders.deleted_at')
		->whereNotNull('orders.name')
		->whereRaw('orders.flags & ? = ?', [1, 1])
		->whereAll(static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.currency', 'CAD')->whereEq('orders.country', 'CA'))
		->whereAny(static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.priority', 'high')->whereEq('orders.priority', 'urgent'))
		->when(true, static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.active', 1))
		->when(false, static fn(QuerySpec $query): QuerySpec=>$query, static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.fallback', 1))
		->unless(false, static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.visible', 1))
		->whenNotNull('value', static fn(QuerySpec $query, string $value): QuerySpec=>$query->whereEq('orders.marker', $value))
		->whenFilled(' ready ', static fn(QuerySpec $query, string $value): QuerySpec=>$query->whereEq('orders.note', trim($value)))
		->tap(static fn(QuerySpec $query): QuerySpec=>$query->whereEq('orders.tapped', 1))
		->requireWhereForWrite()
		->forUpdate()
		->orderBy('orders.created_at', 'desc')
		->orderByRaw('orders.id DESC')
		->orderByAsc('orders.name')
		->orderByDesc('orders.total_minor')
		->latest()
		->oldest('orders.updated_at')
		->groupBy(['orders.tenant_id', 'orders.status'])
		->groupByRaw('DATE(orders.created_at)')
		->havingRaw('COUNT(*) > ?', [1])
		->forPage(2, 25);

	$compiled=$spec->compile();
	$sql=is_array($compiled['params']) ? implode(' ', array_map('strval', $compiled['params'])) : (string)$compiled['params'];
	$t->isTrue($spec->hasWhere());
	$t->contains('WHERE', $sql);
	$t->contains('GROUP BY', $sql);
	$t->contains('HAVING', $sql);
	$t->contains('ORDER BY', $sql);
	$t->contains('LIMIT', $sql);
	$t->same(25, $spec->debugContext()['limit'] ?? null);
	$spec->assertScopedForWrite('orders', 'update');

	$unlocked=$spec->withoutLocking()->withoutOrdering()->withoutGrouping()->withoutPaging();
	$unlockedParams=$unlocked->compile()['params'];
	$unlockedSql=is_array($unlockedParams) ? implode(' ', array_map('strval', $unlockedParams)) : (string)$unlockedParams;
	$t->notContains('FOR UPDATE', $unlockedSql);
	$t->notContains('ORDER BY', $unlockedSql);
	$t->notContains('GROUP BY', $unlockedSql);
	$t->notContains('LIMIT', $unlockedSql);

	$t->same('*', QuerySpec::columns(''));
	$t->same(['id', 'name'], QuerySpec::columns([' id ', 'name', 'id', '']));
	$t->same(['id', 'name'], QuerySpec::columns([' id ', 'name', 'id', '']));
	$t->same('*', QuerySpec::columns([]));
	$t->throws(static fn()=>(new QuerySpec())->whereEq('orders;drop', 1), InvalidArgumentException::class);
	$t->throws(static fn()=>(new QuerySpec())->requireWhereForWrite()->assertScopedForWrite('orders', 'delete'), RuntimeException::class);

	$spec->clearLocking()->sharedLock()->lockRaw('LOCK IN SHARE MODE')->clearLocking();
	$spec->clearOrdering()->clearGrouping()->clearLimit()->clearOffset()->clearPaging()->allowUnscopedWrite();
	$t->same(false, $spec->writeRequiresWhere(true));
})->tag('sql', 'query-spec', 'coverage')->maxMillis(5000);

test('table definitions and schemas cover columns indexes projections casts and ddl', static function(Context $t): void {
	$t->same(['id'], TableDefinition::for('implicit_primary')->autoIncrement()->primaryColumns());
	$definition=TableDefinition::for('orders')
		->autoIncrement('id')
		->string('name', 120)->notNull()->default('')
		->text('description')->nullable()
		->longText('notes')
		->json('metadata')->cast('json')
		->integer('quantity')->cast('int')->default(0)
		->bigInt('total_minor')->cast('int')
		->unsignedBigInt('tenant_id')->cast('int')
		->float('weight')->cast('float')
		->boolean('active')->cast('bool')->default(true)
		->timestamp('created_at')->defaultCurrent()
		->datetime('updated_at')->defaultCurrent()->onUpdateCurrent()
		->uuid('public_id')
		->enum('status', ['draft', 'open', 'paid'])->default('draft')
		->legacyColumn('legacy_code', 'VARCHAR(40)')
		->column('expression_value', ['type'=>'INTEGER', 'nullable'=>true, 'default_sql'=>'42'])
		->casts(['public_id'=>'string'])
		->primary('id')
		->unique(['tenant_id', 'public_id'], 'orders_tenant_public_unique')
		->index(['tenant_id', 'status'], 'orders_tenant_status_index')
		->projection('listing', ['id', 'name', 'status', 'total_minor']);

	$schema=$definition->schema();
	$t->instanceOf(TableSchema::class, $schema);
	$t->same('orders', $definition->table());
	$t->same(['id'], $definition->primaryColumns());
	$t->same(['id', 'name', 'status', 'total_minor'], $schema->projection('listing'));
	$t->same([], $schema->columns([]));
	$t->same(['id', 'name'], $schema->columns(['id', 'name', 'id']));
	$t->throws(static fn()=>$schema->columns(['missing']), InvalidArgumentException::class);
	$t->same(['quantity'=>7, 'active'=>true, 'metadata'=>'{"source":"unit"}'], $schema->fields([
		'quantity'=>'7',
		'active'=>'1',
		'metadata'=>['source'=>'unit'],
	]));
	$t->throws(static fn()=>$schema->fields(['ignored'=>'value']), InvalidArgumentException::class);
	$t->same([
		'quantity'=>7,
		'weight'=>1.5,
		'active'=>false,
		'metadata'=>['source'=>'unit'],
	], $schema->castRow([
		'quantity'=>'7',
		'weight'=>'1.5',
		'active'=>'0',
		'metadata'=>'{"source":"unit"}',
	]));
	$t->count(2, $schema->castRows([
		['quantity'=>'1'],
		['quantity'=>'2'],
	]));
	$t->notEmpty($definition->columns());
	$t->notEmpty($definition->castMap());
	$t->notEmpty($definition->projections());
	$queries=$definition->createQueries();
	$t->notEmpty($queries);
	$t->contains('CREATE TABLE', json_encode($queries, JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>$definition->column('', 'TEXT'), InvalidArgumentException::class);
	$t->instanceOf(TableDefinition::class, $definition->enum('bad', []));
})->tag('sql', 'schema', 'definition', 'coverage')->maxMillis(5000);
