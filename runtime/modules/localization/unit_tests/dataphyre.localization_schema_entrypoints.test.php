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
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

$localization_schema_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
require_once $localization_schema_runtime.'/modules/sql/Framework/TableDefinition.php';
require_once $localization_schema_runtime.'/modules/localization/Framework/Bootstrap.php';
$localization_table_factory=require $localization_schema_runtime.'/modules/localization/kernel/localization.tables.php';

suite('Localization framework and schema entrypoints')
	->contract('localization.schema-entrypoints', 1)
	->layer('contract')
	->risk('medium')
	->watches('module:localization')
	->through('framework-bootstrap', 'table-definition')
	->isolation('case')
	->tag('localization', 'schema-entrypoints')
	->group('framework-coverage');

test('framework bootstrap marker and locale table factory describe the complete portable storage contract', static function(Context $t) use ($localization_table_factory): void {
	$t->isTrue(defined('DATAPHYRE_LOCALIZATION_FRAMEWORK_BOOTSTRAPPED'));
	$definition=$localization_table_factory('tenant.locales');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same('tenant.locales', $definition->table());
	$t->same(['id', 'lang', 'name', 'string', 'type', 'theme', 'path', 'edit_time'], $definition->columns());
	$t->same(['id'], $definition->primaryColumns());
	$t->same(['id'=>'int', 'edit_time'=>'datetime'], $definition->castMap());
});
