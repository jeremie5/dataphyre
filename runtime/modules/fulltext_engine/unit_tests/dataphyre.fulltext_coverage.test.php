<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;
use Dataphyre\FulltextEngine\HydratedSearchHit;
use Dataphyre\FulltextEngine\HydratedSearchResults;
use Dataphyre\FulltextEngine\Index;
use Dataphyre\FulltextEngine\IndexDefinition;
use Dataphyre\FulltextEngine\IndexSyncReport;
use Dataphyre\FulltextEngine\Query;
use Dataphyre\FulltextEngine\Search;
use Dataphyre\FulltextEngine\SearchHit;
use Dataphyre\FulltextEngine\SearchManager;
use Dataphyre\FulltextEngine\SearchResults;
use Dataphyre\FulltextEngine\Resolvers\TableDocumentResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>['core'=>true, 'fulltext_engine'=>true],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}
if(!defined('DP_FULLTEXT_ENGINE_CFG')){
	define('DP_FULLTEXT_ENGINE_CFG', [
		'framework'=>[
			'default_language'=>'en',
			'default_limit'=>20,
			'default_boolean_mode'=>true,
			'default_threshold'=>0.25,
			'default_algorithms'=>'levenshtein',
			'default_index_type'=>'json',
			'indexes'=>[
				'products'=>['type'=>'json', 'primary_key'=>'id', 'language'=>'en'],
				'articles'=>['type'=>'json', 'primary_key'=>'slug'],
			],
			'resolvers'=>[
				'products'=>[
					'driver'=>'callback',
					'callback'=>static fn(array $ids): array=>array_combine($ids, array_map(static fn(string $id): array=>['id'=>$id], $ids)) ?: [],
				],
				'*'=>static fn(array $ids): array=>[],
			],
		],
	]);
}
$dp_fulltext_cov_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
require_once $dp_fulltext_cov_modules_root.'/core/kernel/autoloader.php';
if(!function_exists('tracelog')){
	function tracelog(...$args): void {}
}
require_once __DIR__.'/fulltext_coverage_helpers.php';
\dataphyre\autoloader::register($dp_fulltext_cov_modules_root);
\dataphyre\autoloader::register_framework_modules(['fulltext_engine']);

suite('Full-text indexing and search lifecycle')
	->contract('fulltext.search-lifecycle', 1)
	->layer('integration')
	->risk('high')
	->watches('module:fulltext_engine')
	->through('index-definition', 'index-sync', 'query', 'search', 'hydration', 'document-resolver')
	->isolation('case')
	->tag('fulltext', 'search-lifecycle')
	->group('framework-coverage');

final class DpFulltextTableRows {
	public static mixed $value=[];
}

if(!function_exists('sql_select')){
	function sql_select(mixed ...$arguments): mixed {
		return DpFulltextTableRows::$value;
	}
}

final class DpFulltextClassResolver implements DocumentResolver {
	public function resolve(array $ids, ?IndexDefinition $definition=null): array {
		return array_fill_keys(array_map('strval', $ids), ['class'=>true]);
	}
}

final class DpFulltextInvokableResolver {
	public function __invoke(array $ids): array {
		return array_fill_keys(array_map('strval', $ids), ['invokable'=>true]);
	}
}

final class DpFulltextInvalidResolver {}

test('fulltext manager index query search results and hydration form one deterministic workflow', static function(Context $t): void {
	SearchManager::flush();
	$manager=SearchManager::instance();
	$t->isTrue($manager===SearchManager::instance());
	$t->same('en', $manager->defaultLanguage());
	$t->same(20, $manager->defaultLimit());
	$t->isTrue($manager->defaultBooleanMode());
	$t->same(0.25, $manager->defaultThreshold());
	$t->same('levenshtein', $manager->defaultAlgorithms());
	$t->same('json', $manager->defaultIndexType());
	$t->throws(static fn()=>$manager->index(' '), InvalidArgumentException::class);
	$index=$manager->index(' products ');
	$t->instanceOf(Index::class, $index);
	$t->isTrue($index===$manager->index('products'));
	$t->same('products', $index->name());
	$t->isTrue($index->exists());
	$t->instanceOf(IndexDefinition::class, $index->definition());
	$t->same(null, $manager->definition('missing'));
	$t->same(1, count($manager->definitions()));

	$query=$manager->query('products')
		->where('name', 'shoe')
		->terms(['category'=>'footwear'])
		->language(' fr ')
		->limit(0)
		->boolean(false)
		->threshold(-1)
		->algorithms(' soundex ');
	$t->instanceOf(Query::class, $query);
	$t->same('products', $query->index());
	$t->same(['name'=>'shoe', 'category'=>'footwear'], $query->criteria());
	$t->producesStableResult(static fn()=>$query->fingerprint());
	$t->same(40, strlen($query->fingerprint()));
	$t->same('products', $query->executionState()['index']);
	$restored=Query::fromExecutionState($query->executionState());
	$t->same($query->criteria(), $restored->criteria());
	$t->same('products', $restored->index());
	$t->same($query->raw(), $manager->rawSearch('products', $query->criteria(), 'fr', 1, false, 0, 'soundex'));

	$results=$query->get();
	$t->instanceOf(SearchResults::class, $results);
	$t->same('products', $results->indexName());
	$t->same(1, $results->count());
	$t->same(1, $results->total());
	$t->same(1, $results->hitCount());
	$t->same(0.9, $results->certainty());
	$t->same(0.001, $results->time());
	$t->isTrue($results->isNotEmpty());
	$t->isFalse($results->isEmpty());
	$t->instanceOf(SearchHit::class, $results->first());
	$t->same(['1'], $results->ids());
	$t->same([0.9], $results->scores());
	$t->same($results->toArray(), $results->jsonSerialize());
	$t->same(1, iterator_count($results->getIterator()));
	$t->same('1', $query->first()->id());
	$t->same('1', $query->first()->key());
	$t->same(0.9, $query->first()->score());
	$t->same($query->first()->toArray(), $query->first()->jsonSerialize());

	$resolver=$manager->resolver('products');
	$t->instanceOf(DocumentResolver::class, $resolver);
	$t->isTrue($resolver===$manager->resolver('products'));
	$t->same(null, $manager->resolver(' '));
	$hydrated=$results->hydrate(static fn(array $ids): array=>['1'=>['id'=>'1'], 'unused'=>['id'=>'unused']]);
	$t->instanceOf(HydratedSearchResults::class, $hydrated);
	$t->same(1, $hydrated->count());
	$t->same([['id'=>'1']], $hydrated->documents());
	$t->same([], $hydrated->missingIds());
	$t->isTrue($hydrated->first()->resolved());
	$t->isFalse($hydrated->first()->missing());
	$t->same('1', $hydrated->first()->id());
	$t->same(0.9, $hydrated->first()->score());
	$t->same($hydrated->toArray(), $hydrated->jsonSerialize());
	$t->same(1, iterator_count($hydrated->getIterator()));
	$missing=$manager->hydrate($results, static fn(array $ids): array=>[]);
	$t->same(['1'], $missing->missingIds());
	$t->same([null], $missing->documents());
	$t->isTrue($missing->first()->missing());
	$t->producesStableResult(static fn()=>$missing->documents());
	$t->producesStableResult(static fn()=>$missing->missingIds());

	$manager->extendResolver('custom', static fn(array $ids): array=>[]);
	$t->instanceOf(DocumentResolver::class, $manager->resolver('custom'));
	$t->throws(static fn()=>$manager->extendResolver('', static fn()=>[]), InvalidArgumentException::class);
	$t->throws(static fn()=>$manager->extendResolver('bad', 42) || $manager->resolver('bad'), RuntimeException::class);
	SearchManager::flush();
})->tag('fulltext', 'coverage')->group('framework-coverage');

test('fulltext lifecycle mutations preprocessing scoring and index fluent helpers delegate exactly', static function(Context $t): void {
	SearchManager::flush();
	$manager=SearchManager::instance();
	$t->isFalse($manager->ensureIndex('', ''));
	$t->isTrue($manager->ensureIndex('products', 'id', 'json', 'en'));
	$t->isFalse($manager->ensureIndex('products', 'uuid', 'json', 'en'));
	$t->isTrue($manager->createIndex('articles', 'slug', null, null));
	$t->isTrue($manager->hasIndex('articles'));
	$t->isFalse($manager->createIndex('', 'id'));
	$t->isTrue($manager->add('articles', ['slug'=>'hello', 'title'=>'Hello']));
	$t->isFalse($manager->add('articles', ['title'=>'Missing slug']));
	$t->isTrue($manager->update('articles', ['slug'=>'hello', 'title'=>'Updated']));
	$t->isTrue($manager->remove('articles', 'hello'));
	$t->isFalse($manager->remove('articles', 'hello'));
	$t->same(['the', 'running', 'shoe'], $manager->tokenize('The running shoe'));
	$t->same('red  blue', $manager->removeStopwords('red and blue'));
	$t->same('runn', $manager->applyStemming('running'));
	$t->same(0.75, $manager->score('red shoe', 'shoe'));
	$t->same(0.0, $manager->score('', 'shoe', 'raw', 'en', false, 'exact'));

	$index=$manager->index('articles');
	$t->same('articles', $index->query()->index());
	$t->isTrue($index->add(['slug'=>'one', 'title'=>'One']));
	$t->isTrue($index->update(['slug'=>'one', 'title'=>'One updated']));
	$t->isTrue($index->remove('one'));
	$t->instanceOf(SearchResults::class, $index->search(['title'=>'one']));
	$t->isTrue(is_array($index->rawSearch(['title'=>'one'])));
	$t->instanceOf(HydratedSearchResults::class, $index->hydrate(
		SearchResults::fromKernelResponse('articles', ['results'=>[['one'=>1.0]]]),
		static fn(array $ids): array=>['one'=>['slug'=>'one']]
	));
	$index->extendResolver(static fn(array $ids): array=>[]);
	$t->instanceOf(DocumentResolver::class, $index->resolver());
	$t->isTrue($index->ensure('slug'));
	$t->isTrue($index->delete());
	$t->isFalse($index->exists());
})->tag('fulltext', 'coverage')->group('framework-coverage');

test('fulltext definition synchronization reports created unchanged mismatched invalid and pruned rows', static function(Context $t): void {
	SearchManager::flush();
	$manager=SearchManager::instance();
	$definition=IndexDefinition::fromArray([
		'name'=>'typed', 'type'=>'json', 'primary_key'=>'id', 'language'=>'en', 'analyzer'=>'simple',
	]);
	$t->isTrue($definition->isValid());
	$t->same('typed', $definition->name());
	$t->same('json', $definition->type());
	$t->same('id', $definition->primaryKeyColumnName());
	$t->same('en', $definition->language());
	$t->same(['analyzer'=>'simple'], $definition->attributes());
	$t->same($definition->toKernelArray(), $definition->jsonSerialize());
	$t->isTrue($definition->matches(new IndexDefinition('typed', 'json', 'id')));
	$t->isFalse($definition->matches(new IndexDefinition('typed', 'sql', 'id')));

	$report=$manager->sync([
		'products'=>['type'=>'sql', 'primary_key'=>'id'],
		'new-index'=>['type'=>'json', 'primary_key'=>'uuid'],
		$definition,
		'invalid'=>'bad',
	], false);
	$t->instanceOf(IndexSyncReport::class, $report);
	$t->same(2, count($report->created()));
	$t->same(1, count($report->mismatched()));
	$t->same(1, count($report->failed()));
	$t->isTrue($report->hasFailures());
	$t->isTrue($report->hasMismatches());
	$t->isFalse($report->isClean());
	$t->same($report->summary(), $report->jsonSerialize()['summary']);

	$clean=$manager->sync([
		'products'=>['type'=>'json', 'primary_key'=>'id'],
		'new-index'=>['type'=>'json', 'primary_key'=>'uuid'],
		'typed'=>['type'=>'json', 'primary_key'=>'id'],
	], true);
	$t->same(3, count($clean->unchanged()));
	$t->same(0, count($clean->failed()));
	$t->isTrue($clean->isClean());
	$t->same(DP_FULLTEXT_ENGINE_CFG['framework']['indexes'], $manager->configuredDefinitions());
	$t->instanceOf(IndexSyncReport::class, $manager->syncConfigured(false));
})->tag('fulltext', 'coverage')->group('framework-coverage');

test('fulltext static facade covers manager query resolver lifecycle and mutation entrypoints', static function(Context $t): void {
	Search::flush();
	$t->instanceOf(SearchManager::class, Search::manager());
	$t->instanceOf(Index::class, Search::index('products'));
	$t->instanceOf(Query::class, Search::query('products'));
	$t->same(1, count(Search::definitions()));
	$t->instanceOf(IndexDefinition::class, Search::definition('products'));
	$t->isTrue(Search::hasIndex('products'));
	Search::extendResolver('products', static fn(array $ids): array=>[]);
	$t->instanceOf(DocumentResolver::class, Search::resolver('products'));
	$t->instanceOf(SearchResults::class, Search::search('products', ['name'=>'shoe']));
	$t->isTrue(is_array(Search::rawSearch('products', ['name'=>'shoe'])));
	$t->isTrue(Search::createIndex('facade', 'id'));
	$t->isTrue(Search::ensureIndex('facade', 'id'));
	$t->isTrue(Search::add('facade', ['id'=>'1', 'name'=>'One']));
	$t->isTrue(Search::update('facade', ['id'=>'1', 'name'=>'Updated']));
	$t->isTrue(Search::remove('facade', '1'));
	$t->same(['red', 'shoe'], Search::tokenize('red shoe'));
	$t->same('red  blue', Search::removeStopwords('red and blue'));
	$t->same('runn', Search::applyStemming('running'));
	$t->same(0.75, Search::score('red', 'red'));
	$t->instanceOf(IndexSyncReport::class, Search::sync([]));
	$t->instanceOf(IndexSyncReport::class, Search::syncConfigured());
	$t->isTrue(Search::deleteIndex('facade'));
	Search::flush();
})->tag('fulltext', 'coverage')->group('framework-coverage');

test('fulltext remaining value caches resolver descriptors and fluent delegates cover uncached projections', static function(Context $t): void {
	Search::flush();
	$manager=Search::manager();
	$index=$manager->index('products');
	$mapper=static fn(array $row): array=>$row+['mapped'=>true];
	$t->same($index, $index->useTableResolver('products_table', 'product_id', ['product_id', 'name'], ['cache'], $mapper));
	$t->same($index, $index->useRepositoryResolver('ProductRepository', 'uuid', ['uuid', 'name'], false, $mapper));
	Search::useTableResolver('table-facade', 'products_table', 'id', '*', true, $mapper);
	Search::useRepositoryResolver('repository-facade', 'ProductRepository', 'id', '*', false, $mapper);
	$t->isTrue($manager->index('created-directly')->create('id', 'json', 'en'));

	$firstHit=new SearchHit('one', 0.8);
	$secondHit=new SearchHit('two', 0.4);
	$manual=new SearchResults('manual', [$firstHit, $secondHit], 5, 0.6, 0.02, ['raw'=>true]);
	$t->same(['one', 'two'], $manual->ids());
	$t->same(['one', 'two'], $manual->ids());
	$t->same([0.8, 0.4], $manual->scores());
	$t->same([0.8, 0.4], $manual->scores());
	$t->same(['raw'=>true], $manual->raw());
	$manualArray=$manual->toArray();
	$t->same($manualArray, $manual->toArray());
	$skipping=SearchResults::fromKernelResponse('manual', ['results'=>['invalid', ['three'=>0.2]]]);
	$t->same(['three'], $skipping->ids());

	$resolvedHit=new HydratedSearchHit($firstHit, ['id'=>'one'], true);
	$missingHit=new HydratedSearchHit($secondHit, null, false);
	$t->same($firstHit, $resolvedHit->hit());
	$t->same('one', $resolvedHit->key());
	$resolvedArray=$resolvedHit->toArray();
	$t->hasConsistentSerialization($resolvedHit, $resolvedArray);
	$definition=$manager->definition('products');
	$hydrated=new HydratedSearchResults('manual', [$resolvedHit, $missingHit], 5, 0.6, 0.02, $definition, ['raw'=>true]);
	$t->same('manual', $hydrated->indexName());
	$t->same($definition, $hydrated->definition());
	$t->same(2, $hydrated->count());
	$t->same(5, $hydrated->total());
	$t->same(0.6, $hydrated->certainty());
	$t->same(0.02, $hydrated->time());
	$t->same([$resolvedHit, $missingHit], $hydrated->hits());
	$t->same($resolvedHit, $hydrated->first());
	$t->same([['id'=>'one'], null], $hydrated->documents());
	$t->same(['two'], $hydrated->missingIds());
	$t->same(['raw'=>true], $hydrated->raw());
	$t->same(2, iterator_count($hydrated->getIterator()));
	$hydratedArray=$hydrated->toArray();
	$t->same($hydratedArray, $hydrated->toArray());
	$missingFirst=new HydratedSearchResults('manual', [$missingHit], 1, 0.1, 0.01);
	$t->same(['two'], $missingFirst->missingIds());
	$t->same([null], $missingFirst->documents());

	$report=new IndexSyncReport();
	if($definition instanceof IndexDefinition){
		$report->addPruned($definition);
	}
	$t->same(1, count($report->pruned()));
	$reportArray=$report->jsonSerialize();
	$t->same($reportArray, $report->jsonSerialize());

	$query=$manager->query('products')->where('old', 'value')->replace(['name'=>'shoe']);
	$t->same(['name'=>'shoe'], $query->criteria());
	$t->throws(static fn()=>Query::fromExecutionState([]), InvalidArgumentException::class);
	$minimalState=Query::fromExecutionState([
		'index'=>'products', 'criteria'=>'invalid', 'language'=>' ', 'max_results'=>'invalid',
		'boolean_mode'=>'invalid', 'threshold'=>'invalid', 'forced_algorithms'=>' ',
	]);
	$t->same([], $minimalState->criteria());
	$t->instanceOf(HydratedSearchResults::class, $query->hydrate(static fn(array $ids): array=>array_fill_keys($ids, ['ok'=>true])));
	Search::flush();
})->tag('fulltext', 'coverage', 'deep-coverage')->group('framework-coverage');

test('fulltext manager resolver normalization table hydration and sync failure branches are deterministic', static function(Context $t): void {
	Search::flush();
	$manager=Search::manager();
	$t->same(null, $manager->resolver(' '));
	$t->instanceOf(DocumentResolver::class, $manager->resolver('wildcard-index'));
	$manager->extendResolver('blank', '  ');
	$t->same(null, $manager->resolver('blank'));
	$manager->extendResolver('class', DpFulltextClassResolver::class);
	$t->instanceOf(DpFulltextClassResolver::class, $manager->resolver('class'));
	$manager->extendResolver('invokable', DpFulltextInvokableResolver::class);
	$t->instanceOf(DocumentResolver::class, $manager->resolver('invokable'));
	$manager->extendResolver('missing-class', 'DpFulltextMissingResolver');
	$t->throws(static fn()=>$manager->resolver('missing-class'), RuntimeException::class);
	$manager->extendResolver('invalid-class', DpFulltextInvalidResolver::class);
	$t->throws(static fn()=>$manager->resolver('invalid-class'), RuntimeException::class);
	$manager->extendResolver('invalid-driver', ['driver'=>'invalid']);
	$t->throws(static fn()=>$manager->resolver('invalid-driver'), RuntimeException::class);
	$manager->extendResolver('inferred-table', ['table'=>'documents', 'primary_key_column'=>'uuid', 'columns'=>'uuid']);
	$t->instanceOf(TableDocumentResolver::class, $manager->resolver('inferred-table'));
	$manager->extendResolver('inferred-repository', ['repository'=>'MissingRepository', 'primary_key_column'=>'uuid']);
	$t->instanceOf(DocumentResolver::class, $manager->resolver('inferred-repository'));
	$t->throws(
		static fn()=>$manager->hydrate(new SearchResults('no-resolver', [], 0, 0.0, 0.0), ''),
		RuntimeException::class
	);
	$t->isTrue($manager->ensureIndex('ensure-created', 'id', 'json', 'en'));

	$definition=$manager->definition('products');
	$t->instanceOf(IndexDefinition::class, $definition);
	$t->throws(static fn()=>new TableDocumentResolver('bad table', 'id'), InvalidArgumentException::class);
	$t->throws(static fn()=>new TableDocumentResolver('documents', 'bad-key!'), InvalidArgumentException::class);
	$t->throws(static fn()=>new TableDocumentResolver('documents', 'id', ['valid', 'bad column']), InvalidArgumentException::class);
	$star=new TableDocumentResolver('documents', 'id', '*');
	$t->same([], $star->resolve([' ', '']));
	DpFulltextTableRows::$value=false;
	$t->same([], $star->resolve(['one']));
	DpFulltextTableRows::$value=['invalid', ['name'=>'missing-id'], ['id'=>'one', 'name'=>'One']];
	$t->same(['one'=>['id'=>'one', 'name'=>'One']], $star->resolve(['one', ' ', 'one'], $definition));
	$t->same(['one'=>['id'=>'one', 'name'=>'One']], $star->resolve(['one', ' ', 'one'], $definition));
	$columns=new TableDocumentResolver('documents', 'id', ['id', 'name', 'id'], false, static fn(array $row, ?IndexDefinition $active): array=>[
		'id'=>$row['id'], 'index'=>$active?->name(),
	]);
	$t->same(['one'=>['id'=>'one', 'index'=>'products']], $columns->resolve(['one'], $definition));
	$stringColumn=new TableDocumentResolver('documents', 'id', 'name');
	$t->instanceOf(TableDocumentResolver::class, $stringColumn);
	$t->same(['id'=>'one'], $t->nonPublic($star)->invoke('mapDocument', ['id'=>'one'], $definition));

	\dataphyre\fulltext_engine::$createFailures=['cannot-create'];
	$createFailure=$manager->sync(['cannot-create'=>['type'=>'json', 'primary_key'=>'id']], false);
	$t->contains('cannot-create', array_keys($createFailure->failed()));
	\dataphyre\fulltext_engine::$createFailures=[];
	\dataphyre\fulltext_engine::$definitions['prune-success']=['name'=>'prune-success', 'type'=>'json', 'primary_key_column_name'=>'id', 'language'=>'en'];
	\dataphyre\fulltext_engine::$definitions['prune-failure']=['name'=>'prune-failure', 'type'=>'json', 'primary_key_column_name'=>'id', 'language'=>'en'];
	\dataphyre\fulltext_engine::$deleteFailures=['prune-failure'];
	$prune=$manager->sync(['products'=>['type'=>'json', 'primary_key'=>'id']], true);
	$t->isTrue(count($prune->pruned())>=2);
	$t->contains('prune-failure', array_keys($prune->failed()));
	\dataphyre\fulltext_engine::$deleteFailures=[];
	Search::flush();
})->tag('fulltext', 'coverage', 'deep-coverage')->group('framework-coverage');
