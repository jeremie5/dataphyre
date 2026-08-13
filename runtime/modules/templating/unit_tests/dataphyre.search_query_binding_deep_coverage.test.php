<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\FulltextEngine {
	final class DpSearchBindingResults { public function hits(): array { return ['hit-1','hit-2']; } }
	final class DpSearchBindingHydrated { public function documents(): array { return ['doc-1','doc-2']; } }
	class Query {
		public static array $resolvers=[];
		public function __construct(public mixed $indexValue=' products ',public mixed $fingerprintValue=' search-fingerprint ',public mixed $state=['term'=>'shoe']){}
		public function get(): object { return new DpSearchBindingResults(); }
		public function first(): array { return ['id'=>1]; }
		public function raw(): array { return ['raw'=>true]; }
		public function hydrate(mixed $resolver=null): object { self::$resolvers[]=$resolver; return new DpSearchBindingHydrated(); }
		public function index(): mixed { return $this->indexValue; }
		public function fingerprint(): mixed { return $this->fingerprintValue; }
		public function executionState(): mixed { return $this->state; }
	}
}

namespace {
	use Dataphyre\FulltextEngine\Query;
	use Dataphyre\Templating\BindingContext;
	use Dataphyre\Templating\SearchQueryBinding;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['templating']);

	final class DpSearchBindingBareProbe {}
	final class DpSearchBindingStateProbe {
		public function __construct(public mixed $indexValue=null,public mixed $fingerprintValue=null,public mixed $state=[]){ }
		public function index(): mixed { return $this->indexValue; }
		public function fingerprint(): mixed { return $this->fingerprintValue; }
		public function executionState(): mixed { return $this->state; }
	}

	test('templating search query binding deep coverage validates support aliases names and identity clones',static function(Context $t): void {
		$query=new Query();$t->isTrue(SearchQueryBinding::supports($query));$t->isFalse(SearchQueryBinding::supports(new stdClass()));$t->throws(static fn()=>SearchQueryBinding::make(new stdClass()),InvalidArgumentException::class);
		$aliases=[''=>'results',' results '=>'results','get'=>'results','hits'=>'hits','first'=>'first','raw'=>'raw','hydrate'=>'hydrated','hydrated'=>'hydrated','docs'=>'documents','documents'=>'documents'];
		foreach($aliases as $alias=>$mode){ $binding=SearchQueryBinding::make($query,$alias);$t->same('search.query.'.$mode,$binding->name()); }
		$t->throws(static fn()=>SearchQueryBinding::make($query,'unknown'),InvalidArgumentException::class);
		$direct=new SearchQueryBinding(new DpSearchBindingBareProbe(),'results',[],'custom.search');$t->same('custom.search',$direct->name());$t->same('custom.search',$direct->inheritIdentity()->useExecutionStateIdentity()->name());
	})->tag('templating','search-query-binding','deep-coverage')->group('framework-coverage');

	test('templating search query binding deep coverage resolves every search result mode',static function(Context $t): void {
		$context=new BindingContext('search.tpl',false);$resolver=new stdClass();$query=new Query();
		$t->isTrue((new SearchQueryBinding($query,'results'))->resolve($context) instanceof \Dataphyre\FulltextEngine\DpSearchBindingResults);
		$t->same(['hit-1','hit-2'],(new SearchQueryBinding($query,'hits'))->resolve($context));$t->same(['id'=>1],(new SearchQueryBinding($query,'first'))->resolve($context));$t->same(['raw'=>true],(new SearchQueryBinding($query,'raw'))->resolve($context));
		$t->isTrue((new SearchQueryBinding($query,'hydrated',['resolver'=>$resolver]))->resolve($context) instanceof \Dataphyre\FulltextEngine\DpSearchBindingHydrated);
		$t->same(['doc-1','doc-2'],(new SearchQueryBinding($query,'documents',['resolver'=>'resolver-name']))->resolve($context));
		$t->throws(static fn()=>(new SearchQueryBinding($query,'invalid'))->resolve($context),InvalidArgumentException::class);
	})->tag('templating','search-query-binding','deep-coverage')->group('framework-coverage');

	test('templating search query binding deep coverage describes fingerprint and execution state identities',static function(Context $t): void {
		$context=new BindingContext('search.tpl',true);$resolver=new stdClass();$query=new Query(' products ',' fingerprint ',['index'=>'products','query'=>'shoe']);
		$binding=new SearchQueryBinding($query,'documents',['resolver'=>$resolver,'inherit_query_identity'=>true,'binding_cache'=>['ttl'=>0,'names'=>[' products ','','products',false,'search'],'identity'=>['tenant'=>7]]],'search.documents');
		$metadata=$binding->metadata();$t->same($metadata,$binding->metadata());$t->same('products',$metadata['query_target']);$t->same('fingerprint',$metadata['query_fingerprint']);$t->same('fingerprint',$metadata['query_identity_source']);$t->same(stdClass::class,$metadata['query_options']['resolver']);
		$t->isTrue($metadata['persistent_cache']['explicit_identity']);$t->isFalse($metadata['persistent_cache']['inherits_query_fingerprint']);
		$identity=$binding->cacheIdentity($context);$t->same('fingerprint',$identity['query_identity_source']);$t->same('fingerprint',$identity['query_fingerprint']);$t->isFalse(array_key_exists('state',$identity));
		$t->same(['ttl'=>1,'names'=>['products','search'],'identity'=>['tenant'=>7]],$binding->persistentCache($context));
		$state=$binding->useExecutionStateIdentity()->cacheIdentity($context);$t->same('execution_state',$state['query_identity_source']);$t->same($query->state,$state['state']);$t->isFalse(array_key_exists('query_fingerprint',$state));

		$blank=new SearchQueryBinding(new DpSearchBindingStateProbe(' ',42,'bad-state'),'results',['inherit_query_identity'=>true]);$blankMetadata=$blank->metadata();$t->isFalse(array_key_exists('query_target',$blankMetadata));$t->isFalse($blankMetadata['query_identity_available']);$t->isFalse(array_key_exists('state',$blank->cacheIdentity($context)));
		$bare=new SearchQueryBinding(new DpSearchBindingBareProbe(),'results',['resolver'=>'callable-name']);$bareMetadata=$bare->metadata();$t->isFalse(array_key_exists('query_target',$bareMetadata));$t->isFalse(array_key_exists('query_fingerprint',$bareMetadata));$t->same('string',$bareMetadata['query_options']['resolver']);$t->isFalse(array_key_exists('state',$bare->cacheIdentity($context)));
	})->tag('templating','search-query-binding','deep-coverage')->group('framework-coverage');

	test('templating search query binding deep coverage normalizes all persistent cache forms and names',static function(Context $t): void {
		$context=new BindingContext('search.tpl',false);$query=new Query();
		foreach([null,false,new stdClass(),'invalid'] as $config){ $t->same(null,(new SearchQueryBinding($query,'results',['binding_cache'=>$config]))->persistentCache($context)); }
		$t->same(1,(new SearchQueryBinding($query,'results',['binding_cache'=>0]))->persistentCache($context)['ttl']);$t->same(2,(new SearchQueryBinding($query,'results',['binding_cache'=>2.9]))->persistentCache($context)['ttl']);$t->same(7,(new SearchQueryBinding($query,'results',['binding_cache'=>'7']))->persistentCache($context)['ttl']);
		$t->same(300,(new SearchQueryBinding($query,'results',['binding_cache'=>true]))->persistentCache($context)['ttl']);
		$inherited=new SearchQueryBinding($query,'results',['inherit_query_identity'=>true,'binding_cache'=>['names'=>' shared ','identity'=>null]]);$cache=$inherited->metadata()['persistent_cache'];$t->isFalse($cache['explicit_identity']);$t->isTrue($cache['requested_query_fingerprint_identity']);$t->isTrue($cache['inherits_query_fingerprint']);$t->same(['shared'],$inherited->persistentCache($context)['names']);
		$invalidNames=new SearchQueryBinding($query,'results',['binding_cache'=>['ttl'=>5,'names'=>true]]);$t->same([],$invalidNames->persistentCache($context)['names']);
	})->tag('templating','search-query-binding','deep-coverage')->group('framework-coverage');
}
