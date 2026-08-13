<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelArraySemanticBackend;
use Dataphyre\Panel\PanelQueryBetween;
use Dataphyre\Panel\PanelQueryComparison;
use Dataphyre\Panel\PanelQueryExpressionEvaluator;
use Dataphyre\Panel\PanelQueryGroup;
use Dataphyre\Panel\PanelQueryIn;
use Dataphyre\Panel\PanelQueryNull;
use Dataphyre\Panel\PanelQueryRelation;
use Dataphyre\Panel\PanelSemanticBackend;
use Dataphyre\Panel\PanelSemanticCatalog;
use Dataphyre\Panel\PanelSemanticException;
use Dataphyre\Panel\PanelSemanticExecutor;
use Dataphyre\Panel\PanelSemanticExecutionPlan;
use Dataphyre\Panel\PanelSemanticMetric;
use Dataphyre\Panel\PanelSemanticQuery;
use Dataphyre\Panel\PanelSemanticQueryResult;
use Dataphyre\Panel\PanelSemanticSort;
use Dataphyre\Panel\PanelSemanticUnsupported;
use Dataphyre\Panel\PanelSqlSchema;
use Dataphyre\Panel\PanelSqlExecutor;
use Dataphyre\Panel\PanelSqlSemanticBackend;
use Dataphyre\Panel\PanelSqlSemanticCompiler;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

function dp_panel_semantic_catalog():PanelSemanticCatalog{
	$catalog=new PanelSemanticCatalog();$common=['entity'=>'order','dimensions'=>['status','market']];
	$catalog->register(PanelSemanticMetric::from('order_count',$common+['aggregation'=>'count']))
		->register(PanelSemanticMetric::from('revenue',$common+['aggregation'=>'sum','field'=>'amount']))
		->register(PanelSemanticMetric::from('average_value',$common+['aggregation'=>'average','field'=>'amount']))
		->register(PanelSemanticMetric::from('customers',$common+['aggregation'=>'distinct_count','field'=>'customer']))
		->register(PanelSemanticMetric::from('paid_ratio',$common+['aggregation'=>'ratio','numerator_filter'=>['paid'=>true],'denominator_filter'=>[]]));
	return$catalog;
}

/** @return list<array<string,mixed>> */
function dp_panel_semantic_rows():array{return[
	['id'=>'o1','tenant_id'=>'north','owner_id'=>'u1','status'=>'open','market'=>'ca','amount'=>10,'customer'=>'a','paid'=>true],
	['id'=>'o2','tenant_id'=>'north','owner_id'=>'u1','status'=>'open','market'=>'ca','amount'=>20,'customer'=>'b','paid'=>false],
	['id'=>'o3','tenant_id'=>'north','owner_id'=>'u1','status'=>'closed','market'=>'ca','amount'=>5,'customer'=>'a','paid'=>true],
	['id'=>'o4','tenant_id'=>'north','owner_id'=>'u2','status'=>'open','market'=>'ca','amount'=>100,'customer'=>'c','paid'=>true],
	['id'=>'o5','tenant_id'=>'south','owner_id'=>'u1','status'=>'open','market'=>'ca','amount'=>200,'customer'=>'d','paid'=>true],
	['id'=>'o6','tenant_id'=>'north','owner_id'=>'u1','status'=>'open','market'=>'us','amount'=>50,'customer'=>'e','paid'=>true],
];}

function dp_panel_semantic_query(array $overrides=[]):PanelSemanticQuery{return new PanelSemanticQuery(['order_count','revenue','average_value','customers','paid_ratio'],array_replace(['dimensions'=>['status'],'filter'=>PanelQueryComparison::make('market','eq','ca'),'sorts'=>[PanelSemanticSort::desc('revenue')],'tenant'=>'north','authorization'=>['actor'=>'u1'],'limit'=>10],$overrides));}

test('semantic planning validates multi-metric dimensions sorts scope and authority-safe manifests',static function(Context $t):void{
	$catalog=dp_panel_semantic_catalog();$query=dp_panel_semantic_query();$plan=$catalog->plan($query);$t->same('order',$plan->entity());$t->same(5,count($plan->metrics()));$t->isTrue(in_array('amount',$plan->requiredFields(),true));$t->isTrue(in_array('market',$plan->requiredFields(),true));$t->same(64,strlen($plan->fingerprint()));
	$t->same('panel_semantic_catalog_v1',$catalog->checkpointType());$t->same(5,count($catalog->metrics()));$t->isTrue(count($catalog->query('order_count',dp_panel_semantic_rows(),['status']))>0);$checkpoint=$catalog->checkpoint();$catalog->remove('order_count');$t->same(4,count($catalog->metrics()));$catalog->restore($checkpoint);$t->same(5,count($catalog->metrics()));$t->same('panel_semantic_catalog_manifest',$catalog->jsonSerialize()['type']);
	$manifest=json_encode([$query,$plan],JSON_THROW_ON_ERROR);$t->notContains('north',$manifest);$t->notContains('u1',$manifest);$t->contains('authorization_serialized',$manifest);$t->same('revenue',PanelSemanticSort::desc('revenue')->target());$t->same('asc',PanelSemanticSort::asc('status')->direction());
	$t->throws(static fn()=>new PanelSemanticQuery([],[]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelSemanticQuery(['order_count'],['sorts'=>[['bad']]]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelSemanticQuery(['order_count'],['consistency'=>'linearizable']),InvalidArgumentException::class);
	$t->throws(static fn()=>$catalog->plan(new PanelSemanticQuery(['order_count'],['dimensions'=>['unknown']])),PanelSemanticUnsupported::class);
	$t->throws(static fn()=>$catalog->plan(new PanelSemanticQuery(['order_count'],['sorts'=>[PanelSemanticSort::asc('unknown')]])),InvalidArgumentException::class);
	$other=new PanelSemanticCatalog();$other->register(PanelSemanticMetric::from('one',['entity'=>'one']))->register(PanelSemanticMetric::from('two',['entity'=>'two']));$t->throws(static fn()=>$other->plan(new PanelSemanticQuery(['one','two'])),PanelSemanticUnsupported::class);
})->tag('panel','operations-os','semantics','query','plan')->maxMillis(4000);

test('array semantic backend evaluates scoped multi-metric groups with deterministic pagination',static function(Context $t):void{
	$rows=dp_panel_semantic_rows();$backend=new PanelArraySemanticBackend(static fn():array=>$rows,['name'=>'semantic_array','tenant_field'=>'tenant_id','authorize'=>static fn(array $row,array $authority):bool=>($row['owner_id']??null)===($authority['actor']??null)]);$result=dp_panel_semantic_catalog()->execute(dp_panel_semantic_query(),$backend);
	$t->same(2,$result->total());$t->isFalse($result->pushdown());$t->isTrue($result->snapshotConsistent());$t->same('open',$result->rows()[0]['dimensions']['status']);$t->same(30.0,(float)$result->rows()[0]['metrics']['revenue']);$t->same(2,$result->rows()[0]['metrics']['order_count']);$t->same(.5,$result->rows()[0]['metrics']['paid_ratio']);$t->same(15.0,(float)$result->rows()[0]['metrics']['average_value']);$t->same(2,$result->rows()[0]['metrics']['customers']);
	$t->same('closed',$result->rows()[1]['dimensions']['status']);$t->same(5.0,(float)$result->rows()[1]['metrics']['revenue']);$t->same(2,$result->jsonSerialize()['page']['returned']);$t->same(3,$result->execution()['rows_scanned']);
	$page=dp_panel_semantic_catalog()->execute(dp_panel_semantic_query(['offset'=>1,'limit'=>1]),$backend);$t->same(2,$page->total());$t->same('closed',$page->rows()[0]['dimensions']['status']);$t->notContains('u1',json_encode($result,JSON_THROW_ON_ERROR));
	$t->same('panel_array_semantic_backend_manifest',$backend->jsonSerialize()['type']);
})->tag('panel','operations-os','semantics','array','fallback')->maxMillis(4000);

test('semantic executor falls back only for capability gaps and preserves explain evidence',static function(Context $t):void{
	$rows=dp_panel_semantic_rows();$fallback=new PanelArraySemanticBackend(static fn():array=>$rows,['tenant_field'=>'tenant_id','authorize'=>static fn(array $row,array $authority):bool=>($row['owner_id']??null)===($authority['actor']??null)]);
	$primary=new class implements PanelSemanticBackend {public int $executions=0;public function name():string{return'limited';}public function capabilities():array{return['group_by'=>false];}public function unsupported(PanelSemanticExecutionPlan $plan):array{return['group_by'];}public function execute(PanelSemanticExecutionPlan $plan):PanelSemanticQueryResult{$this->executions++;throw new RuntimeException('must not execute');}};
	$result=dp_panel_semantic_catalog()->execute(dp_panel_semantic_query(),$primary,$fallback);$t->same(0,$primary->executions);$t->isTrue($result->execution()['fallback']);$t->same('limited',$result->execution()['fallback_from']);$t->same(['group_by'],$result->execution()['fallback_features']);
	$t->throws(static fn()=>dp_panel_semantic_catalog()->execute(dp_panel_semantic_query(['allow_fallback'=>false]),$primary,$fallback),PanelSemanticUnsupported::class);
	$executorCatalog=dp_panel_semantic_catalog();$executor=new PanelSemanticExecutor($executorCatalog,$primary,$fallback);$t->same($executorCatalog,$executor->catalog());$t->same($primary,$executor->primary());$t->same($fallback,$executor->fallback());$manifest=$executor->jsonSerialize();$t->isFalse($manifest['runtime_failures_trigger_fallback']);
	$error=new PanelSemanticException('semantic_probe','Semantic probe failed.',503,true);$t->same('semantic_probe',$error->publicCode());$t->same(503,$error->httpStatus());$t->isTrue($error->retryable());$t->same('semantic_probe',$error->jsonSerialize()['code']);$unsupported=new PanelSemanticUnsupported(['group_by']);$t->same(['group_by'],$unsupported->features());$t->same(['group_by'],$unsupported->jsonSerialize()['features']);
})->tag('panel','operations-os','semantics','negotiation','fallback')->maxMillis(4000);

test('SQL semantic backend pushes filters groups conditional aggregates ratios sorts and pages',static function(Context $t):void{
	$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->exec('CREATE TABLE orders (id TEXT, tenant_id TEXT, owner_id TEXT, status TEXT, market TEXT, amount REAL, customer TEXT, paid INTEGER)');$insert=$pdo->prepare('INSERT INTO orders VALUES (:id,:tenant,:owner,:status,:market,:amount,:customer,:paid)');foreach(dp_panel_semantic_rows()as$row){$insert->execute(['id'=>$row['id'],'tenant'=>$row['tenant_id'],'owner'=>$row['owner_id'],'status'=>$row['status'],'market'=>$row['market'],'amount'=>$row['amount'],'customer'=>$row['customer'],'paid'=>$row['paid']?1:0]);}
	$schema=PanelSqlSchema::make('orders',['id','tenant_id','owner_id','status','market','amount','customer','paid'],'id',['name'=>'orders','tenant_field'=>'tenant_id']);$backend=PanelSqlSemanticBackend::usingPdo($pdo,$schema,'order',['authorize'=>static fn(array $authority):PanelQueryComparison=>PanelQueryComparison::make('owner_id','eq',(string)($authority['actor']??''))]);$catalog=dp_panel_semantic_catalog();$query=dp_panel_semantic_query();$result=$catalog->execute($query,$backend);
	$t->isTrue($result->pushdown());$t->same(2,$result->total());$t->same('open',$result->rows()[0]['dimensions']['status']);$t->same(30.0,(float)$result->rows()[0]['metrics']['revenue']);$t->same(.5,(float)$result->rows()[0]['metrics']['paid_ratio']);$t->same(2,$result->rows()[0]['metrics']['customers']);$t->same(5.0,(float)$result->rows()[1]['metrics']['revenue']);$t->isTrue($result->execution()['aggregation_pushdown']);
	$plan=$catalog->plan($query);$compiled=(new PanelSqlSemanticCompiler($schema,'sqlite'))->compile($plan,PanelQueryComparison::make('owner_id','eq','u1'));$t->contains('GROUP BY',$compiled->dataSql());$t->contains('CASE WHEN',$compiled->dataSql());$t->contains('NULLIF',$compiled->dataSql());$t->notContains('north',$compiled->dataSql());$t->notContains('u1',$compiled->dataSql());$t->notNull($compiled->countSql());$t->isFalse($compiled->jsonSerialize()['sql_serialized']);$t->same(64,strlen($compiled->fingerprint()));
	$manifest=json_encode($backend,JSON_THROW_ON_ERROR);$t->notContains('CREATE TABLE',$manifest);$t->isTrue($backend->capabilities()['pagination_pushdown']);
})->tag('panel','operations-os','semantics','sql','pushdown')->maxMillis(5000);

test('semantic SQL compiler covers every aggregate and typed predicate while storage failures stay public-safe',static function(Context $t):void{
	$catalog=new PanelSemanticCatalog();$metric=['entity'=>'order','field'=>'amount','dimensions'=>['status']];$catalog->register(PanelSemanticMetric::from('minimum_amount',$metric+['aggregation'=>'minimum']))->register(PanelSemanticMetric::from('maximum_amount',$metric+['aggregation'=>'maximum']));
	$schema=PanelSqlSchema::make('orders',['id','tenant_id','status','market','amount'],'id',['tenant_field'=>'tenant_id']);$compiler=new PanelSqlSemanticCompiler($schema,'sqlite');
	$filter=PanelQueryGroup::all(PanelQueryComparison::make('amount','gte',1),PanelQueryComparison::make('status','contains','op'),PanelQueryIn::make('market',['ca',null],true));$query=new PanelSemanticQuery(['minimum_amount','maximum_amount'],['dimensions'=>['status'],'filter'=>$filter,'tenant'=>'north']);$compiled=$compiler->compile($catalog->plan($query));$t->contains('MIN(CASE WHEN',$compiled->dataSql());$t->contains('MAX(CASE WHEN',$compiled->dataSql());$t->contains('LIKE LOWER',$compiled->dataSql());$t->contains('NOT IN',$compiled->dataSql());
	$invalid=$t->nonPublic(PanelQueryComparison::class)->withoutConstructor();$invalidState=$t->nonPublic($invalid);foreach(['field'=>\Dataphyre\Panel\PanelQueryPath::make('status'),'operator'=>'invalid_operator','value'=>'open']as$name=>$value){$invalidState->writeProperty($name,$value);}$invalidPlan=$catalog->plan(new PanelSemanticQuery(['minimum_amount'],['filter'=>$invalid]));$t->throws(static fn()=>$compiler->compile($invalidPlan),PanelSemanticUnsupported::class);
	$throwing=new class implements PanelSqlExecutor {public function driver():string{return'sqlite';}public function rows(string $sql,array $parameters=[]):array{throw new RuntimeException('database offline');}public function scalar(string $sql,array $parameters=[]):mixed{return 1;}public function manifest():array{return[];}};$backend=new PanelSqlSemanticBackend($throwing,$schema,'order',['authorize'=>static fn():bool=>true]);$ungrouped=$catalog->plan(new PanelSemanticQuery(['minimum_amount'],['tenant'=>'north']));$t->throws(static fn()=>$backend->execute($ungrouped),PanelSemanticException::class);
})->tag('panel','operations-os','semantics','sql','compiler','failure-contract')->maxMillis(4000);

test('semantic SQL and array backends negotiate unsupported fields relations consistency and authorization',static function(Context $t):void{
	$schema=PanelSqlSchema::make('orders',['id','tenant_id','status','market','amount','customer','paid'],'id',['tenant_field'=>'tenant_id']);$backend=new PanelSqlSemanticBackend(new \Dataphyre\Panel\PanelPdoSqlExecutor(new PDO('sqlite::memory:')),$schema,'order');$plan=dp_panel_semantic_catalog()->plan(dp_panel_semantic_query());$t->isTrue(in_array('authorization',$backend->unsupported($plan),true));$t->isTrue(in_array('field:owner_id',$backend->unsupported($plan),true)===false);
	$relation=PanelQueryRelation::make('items',PanelQueryComparison::make('sku','eq','A'));$relationPlan=dp_panel_semantic_catalog()->plan(dp_panel_semantic_query(['filter'=>$relation]));$authorized=new PanelSqlSemanticBackend(new \Dataphyre\Panel\PanelPdoSqlExecutor(new PDO('sqlite::memory:')),$schema,'order',['authorize'=>static fn():bool=>true]);$t->isTrue(in_array('relations',$authorized->unsupported($relationPlan),true));
	$snapshotPlan=dp_panel_semantic_catalog()->plan(dp_panel_semantic_query(['consistency'=>'snapshot']));$t->isTrue(in_array('snapshot_consistency',$authorized->unsupported($snapshotPlan),true));
	$array=new PanelArraySemanticBackend(static fn():array=>[],['tenant_field'=>null,'authorize'=>static fn():bool=>true,'snapshot_consistent'=>false]);$t->isTrue(in_array('tenant',$array->unsupported($plan),true));$t->isTrue(in_array('snapshot_consistency',$array->unsupported(dp_panel_semantic_catalog()->plan(dp_panel_semantic_query(['tenant'=>null,'consistency'=>'snapshot']))),true));
	$t->throws(static fn()=>new PanelArraySemanticBackend(static fn():array=>[],['tenant_field'=>null,'require_tenant'=>true]),InvalidArgumentException::class);$t->throws(static fn()=>new PanelSqlSemanticCompiler($schema,'oracle'),InvalidArgumentException::class);
})->tag('panel','operations-os','semantics','capabilities','security')->maxMillis(4000);

test('portable expression evaluator covers groups relations null ranges membership and text operators',static function(Context $t):void{
	$row=['amount'=>10,'status'=>'Open','nullable'=>null,'tags'=>['a','b'],'items'=>[['sku'=>'A','qty'=>2],['sku'=>'B','qty'=>0]]];
	$expression=PanelQueryGroup::all(PanelQueryBetween::make('amount',5,15),PanelQueryIn::make('status',['Open']),PanelQueryNull::make('nullable'),PanelQueryComparison::make('status','starts_with','op'),PanelQueryRelation::make('items',PanelQueryComparison::make('qty','gt',1),'any'));
	$t->isTrue(PanelQueryExpressionEvaluator::matches($row,$expression));$t->isTrue(PanelQueryExpressionEvaluator::matches($row,PanelQueryComparison::make('tags','contains','a')));$t->isTrue(PanelQueryExpressionEvaluator::matches($row,PanelQueryComparison::make('status','ends_with','EN')));$t->isTrue(PanelQueryExpressionEvaluator::matches($row,PanelQueryIn::make('status',['Closed'],true)));$t->isTrue(PanelQueryExpressionEvaluator::matches($row,PanelQueryRelation::make('items',PanelQueryComparison::make('qty','lt',0),'none')));$t->isFalse(PanelQueryExpressionEvaluator::matches($row,PanelQueryBetween::make('amount',5,15,true)));$t->isTrue(PanelQueryExpressionEvaluator::matches($row,null));
})->tag('panel','data-source','query-expression','evaluator')->maxMillis(3000);
