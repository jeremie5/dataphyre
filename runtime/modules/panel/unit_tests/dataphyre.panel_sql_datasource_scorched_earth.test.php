<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelDataQuery;
use Dataphyre\Panel\PanelAdapterConformanceCatalog;
use Dataphyre\Panel\PanelAdapterConformanceRunner;
use Dataphyre\Panel\PanelPdoDataSource;
use Dataphyre\Panel\PanelPdoSqlExecutor;
use Dataphyre\Panel\PanelQueryBetween;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelQueryGroup;
use Dataphyre\Panel\PanelQueryIn;
use Dataphyre\Panel\PanelQueryNull;
use Dataphyre\Panel\PanelQueryRelation;
use Dataphyre\Panel\PanelSqlAccessDeniedException;
use Dataphyre\Panel\PanelSqlCursorCodec;
use Dataphyre\Panel\PanelSqlCursorException;
use Dataphyre\Panel\PanelSqlDataSource;
use Dataphyre\Panel\PanelSqlExecutionException;
use Dataphyre\Panel\PanelSqlExecutor;
use Dataphyre\Panel\PanelSqlQueryCompiler;
use Dataphyre\Panel\PanelSqlRelation;
use Dataphyre\Panel\PanelSqlSchema;
use Dataphyre\Panel\PanelUnsupportedQueryException;
use Dataphyre\Test\Context;
use Dataphyre\Test\ScriptedPdoStatement;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

/** @return array{PanelSqlSchema,PanelSqlSchema} */
function dp_panel_sql_test_schemas(): array {
	$items=PanelSqlSchema::make('order_items', ['id','order_id','tenant_id','sku','quantity'], 'id', [
		'name'=>'order_items', 'tenant_field'=>'tenant_id', 'search_fields'=>['sku'],
	]);
	$orders=PanelSqlSchema::make('orders', ['id','tenant_id','owner_id','email','status','total','rank','deleted_at'], 'id', [
		'name'=>'orders', 'tenant_field'=>'tenant_id', 'search_fields'=>['email','status'],
		'relations'=>[PanelSqlRelation::make('items', $items, 'id', 'order_id')], 'max_limit'=>250,
	]);
	return [$orders,$items];
}
test('SQL schema and compiler parameterize the full typed AST with deterministic keyset plans', static function(Context $t): void {
	[$orders,$items]=dp_panel_sql_test_schemas();
	$t->same('orders', $orders->name());
	$t->same('order_items', $orders->relation('items')->schema()->name());
	$t->same(1, $orders->relationDepth());
	$t->same('tenant_id', $items->tenantField());
	$t->isTrue($orders->requiresTenant());
	$t->same(250, $orders->maxLimit());
	$t->same($orders->manifest(), $orders->jsonSerialize());
	$t->same(64, strlen($orders->fingerprint()));

	$query=PanelDataQuery::make()
		->whereExpression(PanelQueryGroup::all(
			PanelQueryIn::make('status', ['open',null]),
			PanelQueryRelation::make('items', PanelQueryComparison::make('quantity','gte',2))
		))
		->search('buyer open')->select(['email'])->sort('rank','desc','first')
		->aggregate('orders_count','count')->aggregate('gross','sum','total')
		->tenant('north')->authorization(['actor'=>'u1'])->limit(2);
	$scope=PanelQueryComparison::make('owner_id','eq','u1');
	$compiler=new PanelSqlQueryCompiler($orders, 'sqlite');
	$plan=$compiler->compile($query, $scope);
	$t->contains('EXISTS (SELECT 1 FROM "order_items" r1', $plan->sql());
	$t->contains('ORDER BY CASE WHEN t0."rank" IS NULL THEN 0 ELSE 1 END ASC, t0."rank" DESC', $plan->sql());
	$t->contains('LIMIT 3', $plan->sql());
	$t->notContains('north', $plan->sql());
	$t->notContains('u1', $plan->sql());
	$t->same(['email','id'], $plan->projectedFields());
	$t->same(['rank','id'], array_column($plan->cursorSorts(), 'field'));
	$t->same('__dp_cursor_0', $plan->cursorSorts()[0]['alias']);
	$t->same('id', $plan->cursorSorts()[1]['alias']);
	$t->contains('COUNT(*)', (string)$plan->aggregateSql());
	$t->same(2, count($plan->aggregateSpecs()));
	$t->isFalse($plan->jsonSerialize()['parameters_serialized']);
	$t->same(64, strlen($compiler->contextFingerprint($query, $scope)));

	$keyset=$compiler->compile($query, $scope, ['offset'=>2,'values'=>[null,'o2']]);
	$t->contains('CASE WHEN t0."rank" IS NULL THEN 0 ELSE 1 END > 0', $keyset->sql());
	$t->same(2, $keyset->offset());
	$t->throws(static fn()=>$compiler->compile($query, $scope, ['offset'=>2,'values'=>['wrong']]), InvalidArgumentException::class);

	$mysql=(new PanelSqlQueryCompiler($orders, 'mysql'))->compile(PanelDataQuery::make()->tenant('north'), $scope);
	$t->contains('FROM `orders` t0', $mysql->sql());
	$t->throws(static fn()=>new PanelSqlQueryCompiler($orders, 'oracle'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders; DROP TABLE users', ['id']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'=>'id','alias'=>'id']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'missing'), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['require_tenant'=>true]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlRelation::make('items', $items, 'id', 'missing'), InvalidArgumentException::class);
	$t->throws(static fn()=>$orders->column('password'), OutOfBoundsException::class);
	$t->throws(static fn()=>$orders->relation('payments'), OutOfBoundsException::class);
})->tag('panel','data-source','sql','compiler','security')->maxMillis(3000);

test('SQL compiler preserves null membership range negation relation quantifiers and projection failures', static function(Context $t): void {
	[$orders]=dp_panel_sql_test_schemas(); $compiler=new PanelSqlQueryCompiler($orders, 'pgsql');
	$query=PanelDataQuery::make()->tenant('north')->whereExpression(PanelQueryGroup::all(
		PanelQueryComparison::make('deleted_at','neq',null),
		PanelQueryIn::make('status', [], false),
		PanelQueryIn::make('owner_id', [], true),
		PanelQueryRelation::make('items', PanelQueryNull::make('sku'), 'none'),
		PanelQueryRelation::make('items', PanelQueryComparison::make('quantity','gt',0), 'all')
	));
	$sql=$compiler->compile($query, PanelQueryComparison::make('owner_id','neq','blocked'))->sql();
	$t->contains('IS NOT NULL', $sql);
	$t->contains('0 = 1', $sql);
	$t->contains('1 = 1', $sql);
	$t->contains('NOT EXISTS', $sql);
	$t->contains('NOT COALESCE', $sql);
	$t->throws(static fn()=>$compiler->compile(PanelDataQuery::make()->tenant('north')->include(['items'])), PanelUnsupportedQueryException::class);
	$t->throws(static fn()=>$compiler->compile(PanelDataQuery::make()->tenant('north')->select(['unknown'])), OutOfBoundsException::class);
	$t->throws(static fn()=>$compiler->compile(PanelDataQuery::make()->tenant('north')->search('no configured override', ['unknown'])), OutOfBoundsException::class);
	$t->throws(static fn()=>$compiler->compile(PanelDataQuery::make()->tenant('north')->where('email','eq',['not'=>'scalar'])), InvalidArgumentException::class);
})->tag('panel','data-source','sql','ast','null-semantics')->maxMillis(2000);

test('SQL compiler parameterizes every text-pattern and inclusive-range expression', static function(Context $t): void {
	[$orders]=dp_panel_sql_test_schemas();
	$compiler=new PanelSqlQueryCompiler($orders, 'sqlite');
	$escaped='buyer!%!_'.'\\';
	foreach([
		'contains'=>'%'.$escaped.'%',
		'not_contains'=>'%'.$escaped.'%',
		'starts_with'=>$escaped.'%',
		'ends_with'=>'%'.$escaped,
	] as $operator=>$expectedPattern){
		$plan=$compiler->compile(PanelDataQuery::make()->tenant('north')->where('email',$operator,'buyer%_\\'));
		$t->contains('LIKE LOWER(:p', $plan->sql());
		$t->contains("ESCAPE '!'", $plan->sql());
		$t->notContains('buyer', $plan->sql());
		$t->isTrue(in_array($expectedPattern,array_values($plan->parameters()),true));
		if($operator==='not_contains'){ $t->contains('NOT (', $plan->sql()); }
	}
	$range=$compiler->compile(PanelDataQuery::make()->tenant('north')->whereExpression(
		PanelQueryBetween::make('total',10,20,true)
	));
	$t->contains('NOT (', $range->sql());
	$t->contains('t0."total" >=', $range->sql());
	$t->contains('t0."total" <=', $range->sql());
	$t->notContains('10', $range->sql());
	$t->notContains('20', $range->sql());
})->tag('panel','data-source','sql','patterns','range','parameterization')->maxMillis(2000);

test('SQL cursor codec authenticates scope expiry shape and retained-key rotation', static function(Context $t): void {
	$now=2_000_000_000; $fingerprint=str_repeat('a',64); $old=str_repeat('o',32); $new=str_repeat('n',32);
	$codec=new PanelSqlCursorCodec(['old'=>$old], 'old', static function()use(&$now): int{return $now;});
	$token=$codec->encode($fingerprint, [null,'order-2',7,1.5,true], 2, 30);
	$decoded=$codec->decode($token, $fingerprint);
	$t->same(2, $decoded['offset']);
	$t->same([null,'order-2',7,1.5,true], $decoded['values']);
	$t->same('old', $decoded['key_id']);
	$t->same('old', $codec->activeKeyId());
	$t->same(1, $codec->retainedKeyCount());
	$t->isFalse($codec->jsonSerialize()['secrets_serialized']);

	$rotated=new PanelSqlCursorCodec(['new'=>$new,'old'=>$old], 'new', static function()use(&$now): int{return $now;});
	$t->same('old', $rotated->decode($token, $fingerprint)['key_id']);
	$t->throws(static fn()=>$codec->decode(substr($token,0,-1).($token[-1]==='A'?'B':'A'), $fingerprint), InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->decode($token, str_repeat('b',64)), InvalidArgumentException::class);
	$now+=30;
	$t->throws(static fn()=>$codec->decode($token, $fingerprint), InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->encode($fingerprint, [], 0, 1), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlCursorCodec(['bad'=>'short']), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlCursorCodec(['old'=>$old], 'missing'), InvalidArgumentException::class);
	$badClock=new PanelSqlCursorCodec(['old'=>$old], 'old', static fn(): string=>'bad');
	$t->throws(static fn()=>$badClock->encode($fingerprint, [], 0), UnexpectedValueException::class);
})->tag('panel','data-source','sql','cursor','rotation')->maxMillis(2000);

test('SQL data source authorizes before execution redacts authority and emits forward keyset pages', static function(Context $t): void {
	[$orders]=dp_panel_sql_test_schemas();
	$executor=new class implements PanelSqlExecutor {
		public array $calls=[];
		public array $pages=[
			[
				['email'=>'one@example.test','id'=>'o1','__dp_cursor_0'=>null],
				['email'=>'two@example.test','id'=>'o2','__dp_cursor_0'=>5],
				['email'=>'three@example.test','id'=>'o3','__dp_cursor_0'=>7],
			],
			[['email'=>'three@example.test','id'=>'o3','__dp_cursor_0'=>7]],
		];
		public function driver(): string { return 'sqlite'; }
		public function rows(string $sql,array $parameters=[]): array {
			$this->calls[]=['rows',$sql,$parameters];
			if(str_contains($sql,' ORDER BY ')){ return array_shift($this->pages) ?? []; }
			return [['orders_count'=>'3','gross'=>'60.5','minimum'=>10.5]];
		}
		public function scalar(string $sql,array $parameters=[]): mixed { $this->calls[]=['scalar',$sql,$parameters]; return '3'; }
		public function manifest(): array { return ['type'=>'fake','password'=>'must-redact','durable'=>false]; }
	};
	$authorizations=0; $now=2_000_000_000;
	$source=new PanelSqlDataSource($executor, $orders, [
		'name'=>'Remote Orders', 'authorization_mode'=>'callback',
		'authorize'=>static function(array $authority)use(&$authorizations){ $authorizations++; return PanelQueryComparison::make('owner_id','eq',(string)($authority['actor']??'')); },
		'cursor_keys'=>['active'=>str_repeat('k',32)], 'cursor_ttl'=>300,
		'clock'=>static function()use(&$now): int{return $now;},
	]);
	$query=PanelDataQuery::make()->tenant('north')->authorization(['actor'=>'u1','secret'=>'hidden'])
		->select(['email'])->sort('rank','desc','first')->limit(2)
		->aggregate('orders_count','count')->aggregate('gross','sum','total')->aggregate('minimum','min','total');
	$first=$source->query($query);
	$t->same(['o1','o2'], array_column($first->items(),'id'));
	$t->same(['orders_count'=>3,'gross'=>60.5,'minimum'=>10.5], $first->aggregates());
	$t->same(3, $first->page()->total());
	$t->notNull($first->page()->nextCursor());
	$t->same([], $first->querySpec()->authorizationMetadata());
	$t->same(null, $first->querySpec()->tenantKey());
	$t->isFalse($first->metadata()['authorization_metadata_serialized']);
	$t->same('[redacted]', $source->manifest()['executor']['password']);
	$t->isFalse($source->manifest()['security']['dsn_serialized']);
	$t->isTrue($source->capabilities()['stable_record_keys']);
	$t->same('id', $source->capabilities()['record_key_field']);
	$t->same($orders, $source->schema());
	$t->same($executor, $source->executor());
	$t->instanceOf(PanelSqlCursorCodec::class, $source->cursorCodec());

	$second=$source->query($query->cursor($first->page()->nextCursor()));
	$t->same(['o3'], array_column($second->items(),'id'));
	$t->same(2, $second->page()->offset());
	$t->same(null, $second->page()->nextCursor());
	$t->same(2, $authorizations);
	$t->isTrue(count($executor->calls)>=6);
	$t->throws(static fn()=>$source->query($query->cursor('invalid')), PanelSqlCursorException::class);

	$noTenant=$query->tenant(null);
	$calls=count($executor->calls);
	$t->throws(static fn()=>$source->query($noTenant), PanelSqlAccessDeniedException::class);
	$t->same($calls, count($executor->calls));
	$denied=new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>['a'=>str_repeat('a',32)]]);
	$t->throws(static fn()=>$denied->query($query), PanelSqlAccessDeniedException::class);
	$t->same($calls, count($executor->calls));
})->tag('panel','data-source','sql','authorization','pagination')->maxMillis(3000);

test('scripted PDO protocol covers the facade and executor without an installed database driver', static function(Context $t): void {
	$key=['active'=>str_repeat('s',32)];
	$schema=PanelSqlSchema::make('portable_items', ['id'], 'id');
	$pdo=$t->scriptedPdo('sqlite')
		->queueRows([['id'=>'i1'],['id'=>'i2']])
		->queueRows([['id'=>'i1']]);
	$source=new PanelPdoDataSource($pdo, $schema, [
		'authorization_mode'=>'trusted', 'cursor_keys'=>$key, 'count_total'=>false,
	]);
	$first=$source->query(PanelDataQuery::make()->limit(1));
	$t->same(['i1'], array_column($first->items(), 'id'));
	$t->notNull($first->page()->nextCursor());
	$t->same('i1', $source->find('i1')['id']);
	$t->isTrue($source->capabilities()['pdo']);
	$t->same('pdo', $source->manifest()['facade']);
	$t->same($source->manifest(), $source->jsonSerialize());
	$t->instanceOf(PanelSqlDataSource::class, $source->source());

	$protocol=$t->scriptedPdo('pgsql')
		->queueRows([['id'=>1]])
		->queueStatement((new ScriptedPdoStatement([], '2'))->failCloseWith(new RuntimeException('ignored close failure')));
	$executor=new PanelPdoSqlExecutor($protocol);
	$t->same('pgsql', $executor->driver());
	$t->same([['id'=>1]], $executor->rows('SELECT id FROM portable_items WHERE id=:p1', [
		'p1'=>null, 'p2'=>true, 'p3'=>3, 'p4'=>1.5, 'p5'=>'value',
	]));
	$bindings=$protocol->statements()[0]->bindings();
	$t->same(PDO::PARAM_NULL, $bindings[':p1']['type']);
	$t->same(PDO::PARAM_BOOL, $bindings[':p2']['type']);
	$t->same(PDO::PARAM_INT, $bindings[':p3']['type']);
	$t->same(PDO::PARAM_STR, $bindings[':p4']['type']);
	$t->same(PDO::PARAM_STR, $bindings[':p5']['type']);
	$t->same('2', $executor->scalar('SELECT COUNT(*) FROM portable_items'));
	$t->isTrue($protocol->statements()[0]->closed());
	$t->isTrue($protocol->statements()[1]->closed());
	$t->same('pdo', $executor->manifest()['adapter']);

	$t->throws(static fn()=>new PanelPdoSqlExecutor($t->scriptedPdo('oracle')), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelPdoSqlExecutor($t->scriptedPdo()->failDriverWith(new RuntimeException('driver'))), InvalidArgumentException::class);
	$t->throws(static fn()=>$executor->rows(' '), InvalidArgumentException::class);
	$t->throws(static fn()=>$executor->rows(str_repeat('x',262145)), InvalidArgumentException::class);
	$t->throws(static fn()=>$executor->rows('SELECT 1', ['wrong'=>1]), InvalidArgumentException::class);

	$miss=new PanelPdoSqlExecutor($t->scriptedPdo()->queuePrepareMiss());
	$t->throws(static fn()=>$miss->rows('SELECT 1'), PanelSqlExecutionException::class);
	$prepareFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queuePrepareFailure(new RuntimeException('prepare')));
	$t->throws(static fn()=>$prepareFailure->scalar('SELECT 1'), PanelSqlExecutionException::class);
	$bindFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->failBindWith(new RuntimeException('bind'))
	));
	$t->throws(static fn()=>$bindFailure->rows('SELECT :p1', ['p1'=>1]), PanelSqlExecutionException::class);
	$executeFalse=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->returnExecuteResult(false)
	));
	$t->throws(static fn()=>$executeFalse->rows('SELECT 1'), PanelSqlExecutionException::class);
	$executeFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->failExecuteWith(new RuntimeException('execute'))
	));
	$t->throws(static fn()=>$executeFailure->rows('SELECT 1'), PanelSqlExecutionException::class);
	$invalidRows=new PanelPdoSqlExecutor($t->scriptedPdo()->queueRows([[]]));
	$t->throws(static fn()=>$invalidRows->rows('SELECT 1'), PanelSqlExecutionException::class);
	$rowFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->failRowsWith(new RuntimeException('fetch rows'))
	));
	$t->throws(static fn()=>$rowFailure->rows('SELECT 1'), PanelSqlExecutionException::class);
	$knownRowFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->failRowsWith(new PanelSqlExecutionException('known'))
	));
	$t->throws(static fn()=>$knownRowFailure->rows('SELECT 1'), PanelSqlExecutionException::class);
	$scalarFailure=new PanelPdoSqlExecutor($t->scriptedPdo()->queueStatement(
		(new ScriptedPdoStatement())->failScalarWith(new RuntimeException('fetch scalar'))
	));
	$t->throws(static fn()=>$scalarFailure->scalar('SELECT 1'), PanelSqlExecutionException::class);
})->tag('panel','data-source','sql','pdo','portable','protocol')->maxMillis(3000);

test('PDO SQL adapter executes nested tenant search aggregate and cursor queries against SQLite when available', static function(Context $t): void {
	if(!in_array('sqlite', PDO::getAvailableDrivers(), true)){ $t->isTrue(true); return; }
	$pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec('CREATE TABLE orders (id TEXT PRIMARY KEY, tenant_id TEXT NOT NULL, owner_id TEXT NOT NULL, email TEXT, status TEXT, total REAL, rank INTEGER, deleted_at TEXT)');
	$pdo->exec('CREATE TABLE order_items (id TEXT PRIMARY KEY, order_id TEXT NOT NULL, tenant_id TEXT NOT NULL, sku TEXT, quantity INTEGER)');
	$pdo->exec("INSERT INTO orders VALUES ('o1','north','u1','alpha@example.test','open',10.5,NULL,NULL),('o2','north','u1','beta@example.test','open',20,5,NULL),('o3','north','u1','gamma@example.test','closed',30,7,NULL),('o4','south','u1','hidden@example.test','open',99,1,NULL),('o5','north','u2','other@example.test','open',50,2,NULL)");
	$pdo->exec("INSERT INTO order_items VALUES ('i1','o1','north','A-1',2),('i2','o2','north','B-1',1),('i3','o3','north','A-1',4),('i4','o4','south','A-1',9)");
	[$orders]=dp_panel_sql_test_schemas();
	$options=[
		'authorization_mode'=>'callback',
		'authorize'=>static fn(array $authority)=>PanelQueryComparison::make('owner_id','eq',(string)($authority['actor']??'')),
		'cursor_keys'=>['active'=>str_repeat('s',32)],
	];
	$source=new PanelPdoDataSource($pdo, $orders, $options);
	$query=PanelDataQuery::make()->tenant('north')->authorization(['actor'=>'u1'])
		->whereExpression(PanelQueryRelation::make('items', PanelQueryComparison::make('sku','eq','A-1')))
		->sort('rank','asc','last')->limit(1)->aggregate('count','count')->aggregate('gross','sum','total');
	$first=$source->query($query);
	$t->same(['o3'], array_column($first->items(),'id'));
	$t->same(['count'=>2,'gross'=>40.5], $first->aggregates());
	$t->notNull($first->page()->nextCursor());
	$second=$source->query($query->cursor($first->page()->nextCursor()));
	$t->same(['o1'], array_column($second->items(),'id'));
	$t->same('o1', $source->find('o1', PanelDataQuery::make()->tenant('north')->authorization(['actor'=>'u1']))['id']);
	$scope=PanelDataQuery::make()->tenant('north')->authorization(['actor'=>'u1'])->limit(10)->sort('id');
	$t->same(['o1'], array_column($source->query($scope->where('rank','lt',0))->items(),'id'));
	$t->same(['o1','o3'], array_column($source->query($scope->where('rank','not_between',[0,6]))->items(),'id'));
	$t->same(['o1','o2','o3'], array_column($source->query($scope->where('deleted_at','contains',''))->items(),'id'));
	$t->same([], $source->query($scope->where('deleted_at','not_contains',''))->items());
	$t->isTrue($source->capabilities()['pdo']);
	$t->same('pdo', $source->manifest()['facade']);
	$t->same($source->source(), $source->source());
	$t->same('sqlite', (new PanelPdoSqlExecutor($pdo))->driver());
	$conformanceScope=PanelDataQuery::make()->tenant('north')->authorization(['actor'=>'u1']);
	$conformance=(new PanelAdapterConformanceRunner())->run(PanelAdapterConformanceCatalog::dataSource(),$source,[
		'query'=>$conformanceScope->limit(5), 'find_scope'=>$conformanceScope,
		'known_id'=>'o1', 'missing_id'=>'absent',
	]);
	$t->isTrue($conformance->passed());
	$t->same(3,$conformance->summary()['passed']);
})->tag('panel','data-source','sql','pdo','sqlite','integration')->maxMillis(5000);

test('SQL adapter rejects invalid configuration decisions results and public error leakage', static function(Context $t): void {
	[$orders]=dp_panel_sql_test_schemas();
	$executor=new class implements PanelSqlExecutor {
		public int $calls=0;
		public function driver(): string { return 'sqlite'; }
		public function rows(string $sql,array $parameters=[]): array { $this->calls++; return [['id'=>new stdClass()]]; }
		public function scalar(string $sql,array $parameters=[]): mixed { $this->calls++; return -1; }
		public function manifest(): array { return ['type'=>'bad']; }
	};
	$key=['a'=>str_repeat('a',32)];
	$t->throws(static fn()=>new PanelSqlDataSource($executor, $orders, []), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>$key,'authorization_mode'=>'callback']), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>$key,'authorization_mode'=>'trusted','authorize'=>static fn()=>true]), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>$key,'unknown'=>true]), InvalidArgumentException::class);
	$invalid=new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>$key,'authorization_mode'=>'callback','authorize'=>static fn()=>new stdClass()]);
	$t->throws(static fn()=>$invalid->query(PanelDataQuery::make()->tenant('north')), PanelSqlAccessDeniedException::class);
	$t->same(0, $executor->calls);
	$trusted=new PanelSqlDataSource($executor, $orders, ['cursor_keys'=>$key,'authorization_mode'=>'trusted']);
	$t->throws(static fn()=>$trusted->query(PanelDataQuery::make()->tenant('north')), PanelSqlExecutionException::class);
	try{ throw new PanelSqlExecutionException('rows', new RuntimeException('SQL and password leak')); }
	catch(PanelSqlExecutionException $error){
		$t->same('Panel SQL data source execution failed.', $error->getMessage());
		$t->same('rows', $error->operation());
		$t->isTrue($error->jsonSerialize()['retryable']);
	}
	$denial=new PanelSqlAccessDeniedException('tenant_required');
	$t->same('tenant_required', $denial->reason());
	$t->same('denied', $denial->jsonSerialize()['status']);
	$cursorError=new PanelSqlCursorException(new RuntimeException('signature details'));
	$t->same('invalid_cursor', $cursorError->jsonSerialize()['status']);
	$t->notContains('signature details', $cursorError->getMessage());

	$throwingExecutor=new class implements PanelSqlExecutor {
		public string $stage='scalar';
		public function driver(): string{return 'sqlite';}
		public function rows(string $sql,array $parameters=[]): array{throw new RuntimeException('private SQL, credentials, and parameters');}
		public function scalar(string $sql,array $parameters=[]): mixed{throw new RuntimeException('private SQL, credentials, and parameters');}
		public function manifest(): array{return ['type'=>'throwing'];}
	};
	$throwingSource=new PanelSqlDataSource($throwingExecutor,$orders,['cursor_keys'=>$key,'authorization_mode'=>'trusted']);
	foreach([true,false] as $countTotal){
		$source=$countTotal ? $throwingSource : new PanelSqlDataSource($throwingExecutor,$orders,['cursor_keys'=>$key,'authorization_mode'=>'trusted','count_total'=>false]);
		$error=null;
		try{ $source->query(PanelDataQuery::make()->tenant('north')); }
		catch(PanelSqlExecutionException $caught){ $error=$caught; }
		$t->instanceOf(PanelSqlExecutionException::class,$error);
		$t->same($countTotal ? 'count' : 'rows',$error->operation());
		$t->notContains('private SQL',(string)$error->getMessage());
	}
})->tag('panel','data-source','sql','adversarial','errors')->maxMillis(3000);

test('SQL adversarial boundaries keep manifests schema edges and impossible guards observable', static function(Context $t): void {
	[$orders,$items]=dp_panel_sql_test_schemas(); $key=['a'=>str_repeat('a',32)];
	$t->same(['id','tenant_id','owner_id','email','status','total','rank','deleted_at'], array_keys($orders->fields()));
	$t->same(['items'], array_keys($orders->relations()));
	$t->same('panel_sql_relation', $orders->relation('items')->jsonSerialize()['type']);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['relations'=>['wrong'=>PanelSqlRelation::make('items',$items,'id','order_id')]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['relations'=>[PanelSqlRelation::make('items',$items,'missing','order_id')]]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['name'=>7]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['tenant_field'=>7]), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['require_tenant'=>'yes']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['search_fields'=>'id']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['relations'=>'items']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', ['id'], 'id', ['max_limit'=>'10']), InvalidArgumentException::class);
	$t->throws(static fn()=>PanelSqlSchema::make('orders', [new stdClass()]), InvalidArgumentException::class);
	$deep=PanelSqlSchema::make('depth_0',['id']);
	for($depth=1;$depth<=16;$depth++){
		$deep=PanelSqlSchema::make('depth_'.$depth,['id'],'id',['relations'=>[PanelSqlRelation::make('child',$deep,'id','id')]]);
	}
	$t->throws(static fn()=>PanelSqlSchema::make('depth_17',['id'],'id',['relations'=>[PanelSqlRelation::make('child',$deep,'id','id')]]), LengthException::class);

	$fingerprint=str_repeat('c',64); $now=1000; $codec=new PanelSqlCursorCodec($key, 'a', static function()use(&$now): int{return $now;});
	$base64=static fn(string $value): string=>rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
	$t->throws(static fn()=>$codec->decode($base64('{').'.'.$base64(str_repeat('x',32)), $fingerprint), InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->decode($base64('[]').'.'.$base64(str_repeat('x',32)), $fingerprint), InvalidArgumentException::class);
	$t->throws(static fn()=>$codec->decode($base64('{"v":1}').'.'.$base64(str_repeat('x',32)), $fingerprint), InvalidArgumentException::class);

	$manifestFailure=new class implements PanelSqlExecutor {
		public function driver(): string{return 'sqlite';}
		public function rows(string $sql,array $parameters=[]): array{return [];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 0;}
		public function manifest(): array{throw new RuntimeException('secret');}
	};
	$t->throws(static fn()=>new PanelSqlDataSource($manifestFailure,$orders,['cursor_keys'=>$key]), InvalidArgumentException::class);
	$driverFailure=new class implements PanelSqlExecutor {
		public function driver(): string{throw new RuntimeException('secret');}
		public function rows(string $sql,array $parameters=[]): array{return [];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 0;}
		public function manifest(): array{return ['type'=>'driver-failure'];}
	};
	$t->throws(static fn()=>new PanelSqlDataSource($driverFailure,$orders,['cursor_keys'=>$key]), InvalidArgumentException::class);
	$never=new class implements PanelSqlExecutor {
		public function driver(): string{return 'sqlite';} public function rows(string $sql,array $parameters=[]): array{return [];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 0;} public function manifest(): array{return ['type'=>'never'];}
	};
	$t->throws(static fn()=>new PanelSqlDataSource($never,$orders,['cursor_keys'=>$key,'name'=>7]), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($never,$orders,['cursor_keys'=>$key,'authorization_mode'=>7]), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($never,$orders,['cursor_keys'=>$key,'cursor_active_key'=>7]), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($never,$orders,['cursor_keys'=>$key,'cursor_ttl'=>'30']), InvalidArgumentException::class);
	$t->throws(static fn()=>new PanelSqlDataSource($never,$orders,['cursor_keys'=>$key,'count_total'=>1]), InvalidArgumentException::class);
	$throwingAuthorization=new PanelSqlDataSource(new class implements PanelSqlExecutor {
		public function driver(): string{return 'sqlite';}
		public function rows(string $sql,array $parameters=[]): array{return [];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 0;}
		public function manifest(): array{return ['type'=>'never-called'];}
	},$orders,['cursor_keys'=>$key,'authorization_mode'=>'callback','authorize'=>static function(){throw new RuntimeException('policy secret');}]);
	$t->throws(static fn()=>$throwingAuthorization->query(PanelDataQuery::make()->tenant('north')), PanelSqlAccessDeniedException::class);

	$floatExecutor=new class implements PanelSqlExecutor {
		public mixed $cursor=1.5;
		public function driver(): string{return 'sqlite';}
		public function rows(string $sql,array $parameters=[]): array{return [['id'=>'o1','__dp_cursor_0'=>$this->cursor]];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 0;}
		public function manifest(): array{return ['type'=>'float'];}
	};
	$floatSource=new PanelSqlDataSource($floatExecutor,$orders,['cursor_keys'=>$key,'authorization_mode'=>'trusted','count_total'=>false]);
	$result=$floatSource->query(PanelDataQuery::make()->tenant('north')->select(['id'])->sort('rank'));
	$t->same(null,$result->page()->total());
	$t->same($floatSource->manifest(),$floatSource->jsonSerialize());
	$floatExecutor->cursor=new stdClass();
	$t->throws(static fn()=>$floatSource->query(PanelDataQuery::make()->tenant('north')->select(['id'])->sort('rank')), PanelSqlExecutionException::class);
	$oversizedExecutor=new class implements PanelSqlExecutor {
		public function driver(): string{return 'sqlite';}
		public function rows(string $sql,array $parameters=[]): array{return [['id'=>'o1','__dp_cursor_0'=>str_repeat('x',5000)],['id'=>'o2','__dp_cursor_0'=>'sentinel']];}
		public function scalar(string $sql,array $parameters=[]): mixed{return 2;}
		public function manifest(): array{return ['type'=>'oversized'];}
	};
	$oversizedSource=new PanelSqlDataSource($oversizedExecutor,$orders,['cursor_keys'=>$key,'authorization_mode'=>'trusted','count_total'=>false]);
	$oversizedError=null;
	try{ $oversizedSource->query(PanelDataQuery::make()->tenant('north')->select(['id'])->sort('email')->limit(1)); }
	catch(PanelSqlExecutionException $error){ $oversizedError=$error; }
	$t->instanceOf(PanelSqlExecutionException::class,$oversizedError);
	$t->same('cursor_encode',$oversizedError->operation());

	$compiler=new PanelSqlQueryCompiler($orders,'sqlite');
	$allAggregates=PanelDataQuery::make()->tenant('north')->aggregate('distinct','distinct_count','status')->aggregate('minimum','min','total')->aggregate('maximum','max','total');
	$t->contains('COUNT(DISTINCT', (string)$compiler->compile($allAggregates)->aggregateSql());
	$t->contains('MIN(', (string)$compiler->compile($allAggregates)->aggregateSql());
	$t->contains('MAX(', (string)$compiler->compile($allAggregates)->aggregateSql());
	$invalidAggregate=PanelDataQuery::make()->tenant('north');
	$t->nonPublic($invalidAggregate)->writeProperty('aggregates', [['alias'=>'invalid','function'=>'median','field'=>'total']]);
	$t->throws(static fn()=>$compiler->compile($invalidAggregate), LogicException::class);
	$unknown=new class implements \Dataphyre\Panel\PanelQueryExpression {
		public function type():string{return 'unknown';} public function depth():int{return 1;}
		public function fields():array{return [];} public function operators():array{return [];}
		public function jsonSerialize():array{return ['type'=>'unknown'];}
	};
	$t->throws(static fn()=>$compiler->compile(PanelDataQuery::make()->tenant('north')->replaceExpression($unknown)), InvalidArgumentException::class);
	$thousand=range(1,1000);
	$parameterBomb=PanelDataQuery::make()->tenant('north')->whereExpression(PanelQueryGroup::all(
		PanelQueryIn::make('status',$thousand), PanelQueryIn::make('owner_id',$thousand), PanelQueryIn::make('email',$thousand)
	));
	$t->throws(static fn()=>$compiler->compile($parameterBomb), LengthException::class);
	foreach(['gt','gte','lt','lte'] as $operator){ $compiler->compile(PanelDataQuery::make()->tenant('north')->where('rank',$operator,null)); }
	$compilerInternals=$t->nonPublic($compiler);
	$t->throws(static fn()=>$compilerInternals->invoke('comparison','t0."rank"','bogus',null), LogicException::class);
})->tag('panel','data-source','sql','exact-coverage','fault-injection')->maxMillis(5000);
