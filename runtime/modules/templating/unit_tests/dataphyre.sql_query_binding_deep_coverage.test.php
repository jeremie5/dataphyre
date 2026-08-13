<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Database\TableQuery;
use Dataphyre\Templating\BindingContext;
use Dataphyre\Templating\SqlQueryBinding;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['sql','templating']);

final class DpSqlQueryBindingRepositoryProbe {
	/** @var list<array{method:string,args:list<mixed>}> */
	public array $calls=[];

	public function __construct(
		public mixed $state=['caching'=>['query-default']],
		public mixed $fingerprintValue='query-fingerprint',
		public mixed $repositoryTarget='DpSqlQueryBindingRepository'
	){}

	public function repositoryClass(): mixed { return $this->repositoryTarget; }
	public function executionState(): mixed { return $this->state; }
	public function fingerprint(): mixed { return $this->fingerprintValue; }

	public function get(mixed ...$args): array { return $this->capture('get',$args); }
	public function first(mixed ...$args): array { return $this->capture('first',$args); }
	public function getRecords(mixed ...$args): array { return $this->capture('getRecords',$args); }
	public function firstRecord(mixed ...$args): array { return $this->capture('firstRecord',$args); }
	public function paginate(mixed ...$args): array { return $this->capture('paginate',$args); }
	public function paginateRecords(mixed ...$args): array { return $this->capture('paginateRecords',$args); }
	public function value(mixed ...$args): array { return $this->capture('value',$args); }
	public function pluck(mixed ...$args): array { return $this->capture('pluck',$args); }
	public function keyBy(mixed ...$args): array { return $this->capture('keyBy',$args); }
	public function count(mixed ...$args): array { return $this->capture('count',$args); }
	public function exists(mixed ...$args): array { return $this->capture('exists',$args); }

	/** @param list<mixed> $args */
	private function capture(string $method,array $args): array {
		$call=['method'=>$method,'args'=>$args];
		$this->calls[]=$call;
		return $call;
	}
}

final class DpSqlQueryBindingTableProbe {
	public function __construct(
		public mixed $state=[],
		public mixed $fingerprintValue='table-fingerprint',
		public mixed $tableTarget='query_binding_rows'
	){}
	public function table(): mixed { return $this->tableTarget; }
	public function executionState(): mixed { return $this->state; }
	public function fingerprint(): mixed { return $this->fingerprintValue; }
}

final class DpSqlQueryBindingBareProbe {}

test('templating SQL query binding deep coverage validates supported queries aliases and immutable identity helpers',static function(Context $t): void {
	$table=new TableQuery('query_binding_rows');
	$t->isTrue(SqlQueryBinding::supports($table));
	$t->isFalse(SqlQueryBinding::supports(new stdClass()));
	$t->throws(static fn()=>SqlQueryBinding::make(new stdClass()),InvalidArgumentException::class);

	$aliases=[
		''=>'records',
		' GET '=>'rows',
		'first'=>'first',
		'record'=>'first_record',
		'paginate'=>'page',
		'paginate_records'=>'page_records',
		'value'=>'value',
		'pluck'=>'pluck',
		'keyby'=>'key_by',
		'count'=>'count',
		'exists'=>'exists',
	];
	foreach($aliases as $alias=>$mode){
		$binding=SqlQueryBinding::make($table,$alias);
		$t->same('sql.query.'.$mode,$binding->name());
	}
	$t->throws(static fn()=>SqlQueryBinding::make($table,'unsupported-mode'),InvalidArgumentException::class);

	$direct=new SqlQueryBinding(new DpSqlQueryBindingBareProbe(),'records',[],'custom.sql.binding');
	$t->same('custom.sql.binding',$direct->name());
	$t->same('custom.sql.binding',$direct->inheritIdentity()->useExecutionStateIdentity()->name());
})->tag('templating','sql-query-binding','deep-coverage')->group('framework-coverage');

test('templating SQL query binding deep coverage resolves every output mode and validates required options',static function(Context $t): void {
	$context=new BindingContext('query-binding.tpl',false);
	$hydrator=new stdClass();
	$cases=[
		['rows',['columns'=>['id','name'],'caching'=>['rows-cache']],'get',[['id','name'],['rows-cache']]],
		['first',['columns'=>'*','caching'=>false],'first',['*',false]],
		['records',['columns'=>['id'],'hydrator'=>$hydrator,'caching'=>'records-cache'],'getRecords',[['id'],$hydrator,'records-cache']],
		['first_record',[],'firstRecord',[null,null,null]],
		['page',['page'=>0,'per_page'=>-5,'columns'=>['id'],'caching'=>true],'paginate',[1,1,['id'],true]],
		['page_records',['page'=>2,'per_page'=>25,'columns'=>'*','hydrator'=>$hydrator,'caching'=>['page-cache']],'paginateRecords',[2,25,'*',$hydrator,['page-cache']]],
		['value',['column'=>' amount ','caching'=>false],'value',['amount',false]],
		['pluck',['column'=>' name ','key_column'=>' code ','caching'=>'pluck-cache'],'pluck',['name','code','pluck-cache']],
		['key_by',['key_column'=>' id ','columns'=>['id','name'],'caching'=>['key-cache']],'keyBy',['id',['id','name'],['key-cache']]],
		['count',['caching'=>false],'count',[false]],
		['exists',[],'exists',[null]],
	];
	foreach($cases as [$mode,$options,$method,$arguments]){
		$result=(new SqlQueryBinding(new DpSqlQueryBindingRepositoryProbe(),$mode,$options))->resolve($context);
		$t->same($method,$result['method']);
		$t->same($arguments,$result['args']);
	}

	$withoutKey=(new SqlQueryBinding(
		new DpSqlQueryBindingRepositoryProbe(),
		'pluck',
		['column'=>'name']
	))->resolve($context);
	$t->same(['name',null,null],$withoutKey['args']);

	$t->throws(
		static fn()=>(new SqlQueryBinding(new DpSqlQueryBindingRepositoryProbe(),'value'))->resolve($context),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>(new SqlQueryBinding(new DpSqlQueryBindingRepositoryProbe(),'key_by',['key_column'=>'   ']))->resolve($context),
		InvalidArgumentException::class
	);
	$t->throws(
		static fn()=>(new SqlQueryBinding(new DpSqlQueryBindingRepositoryProbe(),'not-a-mode'))->resolve($context),
		InvalidArgumentException::class
	);
})->tag('templating','sql-query-binding','deep-coverage')->group('framework-coverage');

test('templating SQL query binding deep coverage describes repository table and unavailable query identities',static function(Context $t): void {
	$context=new BindingContext('query-binding.tpl',true);
	$hydrator=new stdClass();
	$repository=new DpSqlQueryBindingRepositoryProbe(
		[
			'table'=>'repository_rows',
			'filters'=>[['column'=>'active','operator'=>'=','value'=>true]],
			'caching'=>[' query-default ','','query-default',17,'other-cache'],
		],
		' repository-fingerprint ',
		' DpSqlQueryBindingRepository '
	);
	$binding=new SqlQueryBinding($repository,'page',[
		'columns'=>['id','name'],
		'column'=>' name ',
		'key_column'=>' id ',
		'page'=>0,
		'per_page'=>0,
		'hydrator'=>$hydrator,
		'caching'=>['runtime-cache'],
		'inherit_query_identity'=>true,
		'binding_cache'=>[
			'ttl'=>0,
			'names'=>[' extra-cache ','','query-default',false],
			'identity'=>['tenant'=>7],
		],
	],'repository.page');

	$metadata=$binding->metadata();
	$t->same('repository',$metadata['query_target_type']);
	$t->same('DpSqlQueryBindingRepository',$metadata['query_target']);
	$t->same('repository-fingerprint',$metadata['query_fingerprint']);
	$t->same('fingerprint',$metadata['query_identity_source']);
	$t->same(['query-default','other-cache'],$metadata['query_cache_names']);
	$t->same(1,$metadata['query_options']['page']);
	$t->same(1,$metadata['query_options']['per_page']);
	$t->same(stdClass::class,$metadata['query_options']['hydrator']);
	$t->isTrue($metadata['persistent_cache']['explicit_identity']);
	$t->isFalse($metadata['persistent_cache']['inherits_query_fingerprint']);

	$identity=$binding->cacheIdentity($context);
	$t->same('fingerprint',$identity['query_identity_source']);
	$t->same('repository-fingerprint',$identity['query_fingerprint']);
	$t->isFalse(array_key_exists('state',$identity));
	$t->isFalse(array_key_exists('hydrator',$identity['options']));
	$t->same([
		'ttl'=>1,
		'names'=>['query-default','other-cache','extra-cache'],
		'identity'=>['tenant'=>7],
	],$binding->persistentCache($context));

	$stateIdentity=$binding->useExecutionStateIdentity()->cacheIdentity($context);
	$t->same('execution_state',$stateIdentity['query_identity_source']);
	$t->same($repository->state,$stateIdentity['state']);
	$t->isFalse(array_key_exists('query_fingerprint',$stateIdentity));

	$tableBinding=new SqlQueryBinding(
		new DpSqlQueryBindingTableProbe(['caching'=>' table-cache '],' table-fingerprint ',' table_rows '),
		'page_records',
		['page'=>3,'per_page'=>15,'hydrator'=>'callable-name','inherit_query_identity'=>true]
	);
	$tableMetadata=$tableBinding->metadata();
	$t->same('table',$tableMetadata['query_target_type']);
	$t->same('table_rows',$tableMetadata['query_target']);
	$t->same('string',$tableMetadata['query_options']['hydrator']);
	$t->same(['table-cache'],$tableMetadata['query_cache_names']);

	$bare=new SqlQueryBinding(new DpSqlQueryBindingBareProbe(),'records');
	$bareMetadata=$bare->metadata();
	$t->isFalse(array_key_exists('query_target_type',$bareMetadata));
	$t->isFalse(array_key_exists('query_target',$bareMetadata));
	$t->isFalse($bareMetadata['query_identity_available']);
	$t->same([],$bare->cacheIdentity($context)['state'] ?? []);

	$invalidTable=new SqlQueryBinding(new DpSqlQueryBindingTableProbe('not-an-array',42,['bad']),'rows');
	$invalidMetadata=$invalidTable->metadata();
	$t->same('table',$invalidMetadata['query_target_type']);
	$t->isFalse(array_key_exists('query_target',$invalidMetadata));
	$t->isFalse($invalidMetadata['query_identity_available']);

	$blankRepository=new SqlQueryBinding(
		new DpSqlQueryBindingRepositoryProbe(['caching'=>true],'   ','   '),
		'rows',
		['inherit_query_identity'=>true]
	);
	$blankMetadata=$blankRepository->metadata();
	$t->same('repository',$blankMetadata['query_target_type']);
	$t->isFalse(array_key_exists('query_target',$blankMetadata));
	$t->same('execution_state',$blankMetadata['query_identity_source']);
	$t->same([],$blankMetadata['query_cache_names'] ?? []);
})->tag('templating','sql-query-binding','deep-coverage')->group('framework-coverage');

test('templating SQL query binding deep coverage normalizes every persistent cache configuration',static function(Context $t): void {
	$context=new BindingContext('query-binding.tpl',false);
	$query=new DpSqlQueryBindingRepositoryProbe(
		['caching'=>[' base ','',3,'base','second']],
		'cache-fingerprint'
	);

	foreach([null,false,new stdClass(),'not-numeric'] as $config){
		$binding=new SqlQueryBinding($query,'rows',['binding_cache'=>$config]);
		$t->same(null,$binding->persistentCache($context));
	}
	$t->same(1,(new SqlQueryBinding($query,'rows',['binding_cache'=>0]))->persistentCache($context)['ttl']);
	$t->same(7,(new SqlQueryBinding($query,'rows',['binding_cache'=>'7']))->persistentCache($context)['ttl']);
	$t->same(300,(new SqlQueryBinding($query,'rows',['binding_cache'=>true]))->persistentCache($context)['ttl']);

	$arrayConfig=new SqlQueryBinding($query,'rows',[
		'inherit_query_identity'=>true,
		'binding_cache'=>['names'=>' third ','identity'=>null],
	]);
	$t->same([
		'ttl'=>300,
		'names'=>['base','second','third'],
		'identity'=>null,
	],$arrayConfig->persistentCache($context));
	$cacheMetadata=$arrayConfig->metadata()['persistent_cache'];
	$t->isFalse($cacheMetadata['explicit_identity']);
	$t->isTrue($cacheMetadata['requested_query_fingerprint_identity']);
	$t->isTrue($cacheMetadata['inherits_query_fingerprint']);

	$noNames=new SqlQueryBinding(
		new DpSqlQueryBindingTableProbe(['caching'=>null],null,'cacheless_rows'),
		'rows',
		['binding_cache'=>2.9]
	);
	$t->same(['ttl'=>2,'names'=>[],'identity'=>null],$noNames->persistentCache($context));
})->tag('templating','sql-query-binding','deep-coverage')->group('framework-coverage');
