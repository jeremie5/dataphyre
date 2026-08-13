<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Database {
	final class TableQuery {
		/** @var array<int,array{key:string,operator:string,value:mixed}> */
		public array $predicates=[];

		public function __construct(public readonly string $table) {}

		public function where(string $key, string $operator, mixed $value): self {
			$this->predicates[]=['key'=>$key, 'operator'=>$operator, 'value'=>$value];
			return $this;
		}

		public function first(): mixed {
			return DB::nextRecord();
		}
	}

	final class DB {
		/** @var array<int,string> */
		public static array $tables=[];
		/** @var array<int,mixed> */
		private static array $records=[];

		public static function reset(): void {
			self::$tables=[];
			self::$records=[];
		}

		public static function queue(mixed ...$records): void {
			self::$records=array_merge(self::$records, $records);
		}

		public static function nextRecord(): mixed {
			return self::$records===[] ? null : array_shift(self::$records);
		}

		public static function table(string $table): TableQuery {
			self::$tables[]=$table;
			return new TableQuery($table);
		}
	}
}

namespace {
	use Dataphyre\Database\DB;
	use Dataphyre\Database\TableQuery;
	use Dataphyre\Mvc\Model;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY', [
			'enabled'=>['core'=>true, 'mvc'=>true],
			'disabled'=>['sql'=>true],
			'core_implicit'=>true,
		]);
	}
	$dp_mvc_model_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $dp_mvc_model_modules_root.'/core/kernel/autoloader.php';
	\dataphyre\autoloader::register($dp_mvc_model_modules_root);
	\dataphyre\autoloader::register_framework_modules(['mvc']);

	final class DpMvcModelConvention extends Model {}

	final class DpMvcModelExplicit extends Model {
		protected static ?string $table='audit_events';
	}

	final class DpMvcModelBlankOverride extends Model {
		protected static ?string $table='   ';
	}

	test('mvc model table queries finds and attribute accessors cover the complete base contract', static function(Context $t): void {
		DB::reset();
		$t->same('audit_events', DpMvcModelExplicit::table());
		$t->same('dp_mvc_model_conventions', DpMvcModelConvention::table());
		$t->same('dp_mvc_model_blank_overrides', DpMvcModelBlankOverride::table());

		$query=DpMvcModelExplicit::query();
		$t->instanceOf(TableQuery::class, $query);
		$t->same('audit_events', $query->table);
		$t->same(['audit_events'], DB::$tables);

		DB::queue(
			['uuid'=>'record-7', 'name'=>'Ada'],
			(object)['uuid'=>'record-8'],
			null
		);
		$t->same(['uuid'=>'record-7', 'name'=>'Ada'], DpMvcModelExplicit::find('record-7', 'uuid'));
		$t->same(null, DpMvcModelExplicit::find('record-8', 'uuid'));
		$t->same(null, DpMvcModelExplicit::find('missing'));
		$t->same(['audit_events', 'audit_events', 'audit_events', 'audit_events'], DB::$tables);

		$manual=DpMvcModelExplicit::query()->where('slug', '=', 'first');
		$t->same([['key'=>'slug', 'operator'=>'=', 'value'=>'first']], $manual->predicates);

		$model=new DpMvcModelConvention([
			'name'=>'Ada',
			'nullable'=>null,
			0=>'ignored',
		]);
		$t->same('Ada', $model->get('name'));
		$t->same('fallback', $model->get('nullable', 'fallback'));
		$t->same('fallback', $model->get('missing', 'fallback'));
		$t->same($model, $model->fill(['name'=>'Grace', 1=>'ignored-again']));
		$t->same($model, $model->set('active', true));
		$t->same([
			'name'=>'Grace',
			'nullable'=>null,
			'active'=>true,
		], $model->toArray());
	})->tag('mvc', 'model', 'coverage')->group('framework-coverage');
}
