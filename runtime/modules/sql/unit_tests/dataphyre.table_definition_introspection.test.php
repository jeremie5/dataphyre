<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql']);

test('table definitions expose normalized column metadata for schema-aware framework consumers', static function(Context $t): void {
	$definition=TableDefinition::for('tenant.orders')
		->autoIncrement('id')
		->string('reference',64)->notNull()->default('')
		->json('metadata')->default('{}')
		->timestamp('created_at')->defaultCurrent();

	$columns=$definition->columnDefinitions();
	$t->same(['id','reference','metadata','created_at'],array_keys($columns));
	$t->same('id',$columns['id']['name'] ?? null);
	$t->same(false,$columns['reference']['nullable'] ?? null);
	$t->same('',$columns['reference']['default'] ?? null);
	$t->same('json',$columns['metadata']['cast'] ?? null);
	$t->same(true,array_key_exists('default',$columns['created_at'] ?? []));
	$t->same(null,$columns['created_at']['default']);
	$t->notEmpty($columns['created_at']['default_sql'] ?? null);

	$columns['reference']['nullable']=true;
	$t->same(false,$definition->columnDefinitions()['reference']['nullable'] ?? null);
})->tag('sql','schema','definition','introspection','unit');
