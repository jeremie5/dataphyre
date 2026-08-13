<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	require_once __DIR__.'/fulltext_kernel_test_helpers.php';
	require_once dirname(__DIR__).'/kernel/fulltext_engine.main.php';
}

namespace {
	use Dataphyre\Test\Context;
	use dataphyre\core;
	use dataphyre\fulltext_engine;
	use dataphyre\FulltextKernelExtensions;
	use dataphyre\FulltextKernelSql;
	use dataphyre\FulltextKernelWorkspace;
	use dataphyre\fulltext_engine\FulltextCurlTransport;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	suite('Fulltext legacy kernel contract')
		->contract('fulltext.legacy-kernel', 1)
		->layer('unit')
		->risk('high')
		->watches('module:fulltext_engine')
		->through('manifest', 'lexical-pipeline', 'local-backends', 'external-backends')
		->sandboxesRootpath('dataphyre')
		->isolation('case')
		->tag('fulltext', 'legacy-kernel', 'contract')
		->group('framework-coverage');

	/** @param array<string,array<string,mixed>|mixed> $definitions */
	function dp_fulltext_kernel(Context $t, array $definitions=[]): \Dataphyre\Test\NonPublicAccess {
		FulltextKernelWorkspace::reset();
		$kernel=$t->nonPublic(fulltext_engine::class);
		$kernel->writeProperty('initialized', true);
		$kernel->writeProperty('index_definitions', $definitions);
		$kernel->writeProperty('tokenize_cache', []);
		return $kernel;
	}

	/** @param array<string,array<string,mixed>|mixed> $definitions */
	function dp_fulltext_definitions(\Dataphyre\Test\NonPublicAccess $kernel, array $definitions): void {
		$kernel->writeProperty('initialized', true);
		$kernel->writeProperty('index_definitions', $definitions);
	}

	test('manifest and ranking primitives expose one deterministic kernel contract', static function(Context $t): void {
		$kernel=dp_fulltext_kernel($t);
		$kernel->writeProperty('initialized', false);
		$t->same([], fulltext_engine::get_index_definitions());
		$t->same([], fulltext_engine::get_index_definitions());

		FulltextKernelWorkspace::manifest('{invalid');
		$kernel->writeProperty('initialized', false);
		$t->same([], fulltext_engine::get_index_definitions());

		FulltextKernelWorkspace::manifest([
			'products'=>['type'=>'json', 'primary_key_column_name'=>'id'],
			'invalid'=>'not-an-index',
		]);
		$kernel->invoke('init', true);
		$t->hasKey('products', fulltext_engine::get_index_definitions());
		$t->isFalse(array_key_exists('invalid', fulltext_engine::get_index_definitions()));
		$t->same('products', fulltext_engine::get_index_definition('products')['name']);
		$t->same(null, fulltext_engine::get_index_definition('missing'));
		$t->isTrue(fulltext_engine::index_exists('products'));
		$t->isFalse(fulltext_engine::index_exists('missing'));
		$t->same('id', $kernel->invoke('index_primary_key', 'products'));
		$t->same(null, $kernel->invoke('index_primary_key', 'missing'));
		$t->same(1, $kernel->invoke('index_entry_limit', 'json'));
		$t->same(1, $kernel->invoke('index_entry_limit', 'sqlite'));
		$t->contains('indexes.json', $kernel->invoke('indexes_definition_path'));
		$t->contains('fulltext_indexes/json/products', str_replace('\\', '/', $kernel->invoke('index_storage_path', 'json', 'products')));
		$t->contains('fulltext_indexes/json', str_replace('\\', '/', $kernel->invoke('index_storage_path', 'json')));

		$directory=$kernel->invoke('ensure_index_directory', 'json', 'products');
		$t->isTrue(is_dir($directory));
		$t->same($directory, $kernel->invoke('ensure_index_directory', 'json', 'products'));
		$t->same('valid_name', $kernel->invoke('normalize_identifier', ' valid_name '));
		$t->same('', $kernel->invoke('normalize_identifier', 'bad-name'));
		$t->same('dataphyre_fulltext_engine.index_products', $kernel->invoke('sql_index_table', 'products'));
		$t->isFalse($kernel->invoke('sql_index_table', 'bad-name'));

		FulltextKernelSql::respond('query', false, true, false, true);
		$t->isFalse($kernel->invoke('sql_backend_create_table', 'bad-name', 'id'));
		$t->isFalse($kernel->invoke('sql_backend_create_table', 'products', ''));
		$t->isFalse($kernel->invoke('sql_backend_create_table', 'products', 'id'));
		$t->isTrue($kernel->invoke('sql_backend_create_table', 'products', 'id'));
		$t->isFalse($kernel->invoke('sql_backend_drop_table', 'bad-name'));
		$t->isFalse($kernel->invoke('sql_backend_drop_table', 'products'));
		$t->isTrue($kernel->invoke('sql_backend_drop_table', 'products'));
		$t->same('{"title":"red"}', $kernel->invoke('sql_backend_entry_json', ['title'=>'red']));
		$t->same(null, $kernel->invoke('sql_backend_entry_from_row', ['name'=>'red'], 'id'));
		$t->same(['primary_key'=>'1', 'entry'=>['title'=>'red']], $kernel->invoke(
			'sql_backend_entry_from_row', ['id'=>'1', 'index_value'=>'{"title":"red"}'], 'id'
		));
		$t->same(['primary_key'=>'2', 'entry'=>['title'=>'blue']], $kernel->invoke(
			'sql_backend_entry_from_row', ['id'=>'2', 'title'=>'blue'], 'id'
		));
		$t->same(['primary_key'=>'3', 'entry'=>['index_value'=>'invalid']], $kernel->invoke(
			'sql_backend_entry_from_row', ['id'=>'3', 'index_value'=>'invalid'], 'id'
		));

		$t->same(['params'=>' LIMIT 1', 'vars'=>[]], $kernel->invoke('sql_search_prefilter', ['name'=>'a'], false, 0));
		$andPrefilter=$kernel->invoke('sql_search_prefilter', ['name'=>'red shoe red'], true, 5);
		$t->contains(' AND ', $andPrefilter['params']);
		$t->same(['%red%', '%shoe%'], $andPrefilter['vars']);
		$t->contains(' OR ', $kernel->invoke('sql_search_prefilter', ['name'=>'red shoe'], false, 5)['params']);

		$t->same(['title'=>'red shoe'], $kernel->invoke('tokenize_values', ['title'=>'red shoes'], 'en'));
		$t->same('red {"size":2} {"label":"object"}', $kernel->invoke('flatten_entry_text', [
			'red', '', ['size'=>2], (object)['label'=>'object'],
		]));
		$t->same('red shoe', $kernel->invoke('combined_query_text', [' red ', '', 'shoe']));
		$t->isTrue($kernel->invoke('should_rerank_with_bm25', ['q'=>'shoe'], 'bm25'));
		$t->isFalse($kernel->invoke('should_rerank_with_bm25', ['q'=>'shoe'], 'exact'));
		$t->isFalse($kernel->invoke('should_rerank_with_bm25', ['q'=>'short query'], ''));
		$t->isTrue($kernel->invoke('should_rerank_with_bm25', ['q'=>str_repeat('word ', 12)], ''));
		$t->same(1, $kernel->invoke('candidate_pool_limit', 0, ['q'=>'shoe'], 'exact'));
		$t->same(20, $kernel->invoke('candidate_pool_limit', 5, ['q'=>'shoe'], 'bm25'));

		$t->same(0.0, $kernel->invoke('entry_match_score', ['title'=>'red'], ['other'=>'red'], ['other'=>'red'], 'en', false, 'lavenshtein'), 'Different field names do not cross-match.');
		$t->same(1.0, $kernel->invoke('entry_match_score', ['title'=>'red'], ['*'=>'red'], ['*'=>'red'], 'en', false, 'lavenshtein'), 'The wildcard field compares against every indexed field.');
		$matches=[];
		$kernel->capture('append_entry_matches', result_primarykeys: $matches, primary_key: 1, entry: ['title'=>'red'], search_data: ['title'=>'blue'], search_values_raw: ['title'=>'blue'], language: 'en', boolean_mode: false, threshold: 1.1, forced_algorithms: 'lavenshtein');
		$t->same([], $matches);
		$captured=$kernel->capture('append_entry_matches', result_primarykeys: [], primary_key: 1, entry: ['title'=>'red'], search_data: ['title'=>'red'], search_values_raw: ['title'=>'red'], language: 'en', boolean_mode: false, threshold: 0.0, forced_algorithms: 'lavenshtein');
		$matches=$captured->argument('result_primarykeys');
		$t->hasKey('1', $matches);
		$captured=$kernel->capture('append_entry_matches', result_primarykeys: $matches, primary_key: 1, entry: ['title'=>'blue'], search_data: ['title'=>'red'], search_values_raw: ['title'=>'red'], language: 'en', boolean_mode: false, threshold: 0.0, forced_algorithms: 'lavenshtein');
		$t->hasKey('1', $captured->argument('result_primarykeys'));
		$t->same([], $kernel->invoke('finalize_result_matches', [], [], ''));
		$t->same([['1'=>1.0]], $kernel->invoke('finalize_result_matches', $matches, ['q'=>'red'], 'exact'));
		$reranked=$kernel->invoke('finalize_result_matches', $matches+['2'=>['score'=>0.5, 'entry_text'=>'']], ['q'=>'red'], 'bm25');
		$t->count(2, $reranked);

		$sortable=[['low'=>0.2], ['same'=>0.2], ['high'=>0.9]];
		$sorted=$kernel->capture('sort_by_relevance', results: $sortable);
		$t->same(['high'=>0.9], $sorted->result()[0]);

		core::scriptFilesystem([false]);
		$t->isFalse($kernel->invoke('persist_index_definitions', ['products'=>[]]));
		core::scriptFilesystem();
		$t->isTrue($kernel->invoke('persist_index_definitions', ['products'=>[]]));
	})->maxMillis(3000);

	test('tokenization counts terms and normalizes short and repeated lexical input', static function(Context $t): void {
		$kernel=dp_fulltext_kernel($t);
		$t->same(3, fulltext_engine::count_digits('a1b2c3'));
		$t->same(0, $kernel->invoke('count_terms', ''));
		$t->same(3, $kernel->invoke('count_terms', 'Red shoe 2'));
		$t->isTrue(is_array(fulltext_engine::tokenize('a')));
		$t->isTrue(is_array(fulltext_engine::tokenize('the red running shoes', 'en')));
		$t->same(['red', 'shoe'], fulltext_engine::tokenize_string('Red red shoe'));
		$t->same(['red', 'shoe'], fulltext_engine::tokenize_string('Red red shoe'));
		$t->same([], fulltext_engine::tokenize_string(''));
	})->maxMillis(1000);

	test('ranking algorithms preserve boolean exact fuzzy and BM25 score boundaries', static function(Context $t): void {
		dp_fulltext_kernel($t);
		$t->same(1.0, fulltext_engine::get_score('red shoe', 'red', 'red', 'en', true));
		$t->same(0.0, fulltext_engine::get_score('red shoe', 'blue', 'blue', 'en', true));
		$t->isTrue(fulltext_engine::get_score('red', 'red', 'red', 'en', false, 'jaccard_damerau_lavenshtein1')>0.1);
		$t->isTrue(fulltext_engine::get_score('red', 'blue', 'blue', 'en', false, 'jaccard_damerau_lavenshtein1')<0.1);
		$t->isTrue(fulltext_engine::get_score('red shoe sale', 'red shoe sale', '', 'en', false, 'jaccard_damerau_lavenshtein2')>0.1);
		$t->isTrue(fulltext_engine::get_score('red', 'red', '', 'en', false, 'jaccard_winkler')>0.1);
		$t->isTrue(fulltext_engine::get_score('abcdefghijk', 'abcdefghijl', '', 'en', false, 'lavenshtein')>0.0);
		$t->isTrue(fulltext_engine::get_score('1234567890', '1234567891', '', 'en', false, 'damerau_lavenshtein')>0.0);
		$t->isTrue(fulltext_engine::get_score(str_repeat('red ', 20), 'red shoe', '', 'en', false, 'bm25')>=0.0);
		$t->isTrue(fulltext_engine::get_score('red', 'red', '', 'en', false, 'fallback')>=0.0);
	})->maxMillis(1000);

	test('token cache evicts bounded history without changing the newest normalized value', static function(Context $t): void {
		dp_fulltext_kernel($t);
		for($index=0; $index<260; $index++){
			fulltext_engine::tokenize_string('cache-'.$index);
		}
		$t->same(['cache', '259'], fulltext_engine::tokenize_string('cache-259'));
	})->maxMillis(1000);

	test('boolean expression parsing and evaluation handle grouping negation and malformed tokens', static function(Context $t): void {
		dp_fulltext_kernel($t);
		$tokens=fulltext_engine::tokenize_expression('(red AND blue) OR NOT green');
		$t->same(['(', 'red', 'AND', 'blue', ')', 'OR', 'NOT', 'green'], $tokens);
		$expression=fulltext_engine::parse_expression($tokens);
		$t->isTrue(fulltext_engine::evaluate_expression('red blue', $expression));
		$t->isTrue(fulltext_engine::evaluate_expression('red shoe', ['+red', '-blue', 'AND']));
		$t->isFalse(fulltext_engine::evaluate_expression('red blue', ['+red', '-blue', 'AND']));
		$t->isFalse(fulltext_engine::evaluate_expression('red', ['+', '']));
		$t->isFalse(fulltext_engine::evaluate_expression('red', []));
		$t->same(['red'], fulltext_engine::parse_expression(['(', 'red', ')', ')', '(']));
	})->maxMillis(1000);

	test('language assets fall back safely across stopwords and stemming catalogs', static function(Context $t): void {
		dp_fulltext_kernel($t);
		$t->isTrue(count(fulltext_engine::get_stopwords('en-CA'))>0);
		$t->isTrue(count(fulltext_engine::get_stopwords('zz'))>0);
		$t->isFalse(str_contains(fulltext_engine::remove_stopwords('red and blue', 'en'), 'and'));
		$t->same('run shoe', fulltext_engine::apply_stemming('running shoes', 'en'));
		$t->same('run shoe', fulltext_engine::apply_stemming('running shoes', 'zz'));
	})->maxMillis(1000);

	test('JSON index creation validates identifiers backend types duplicates and manifest writes', static function(Context $t): void {
		dp_fulltext_kernel($t);
		$t->same([], fulltext_engine::search('missing', ['*'=>'red']));
		$t->isFalse(fulltext_engine::create_index('bad-name', 'id'));
		$t->isFalse(fulltext_engine::create_index('unknown', 'id', 'unknown'));
		$t->isTrue(fulltext_engine::create_index('products', 'id', 'json'));
		$t->isFalse(fulltext_engine::create_index('products', 'id', 'json'));
		core::scriptFilesystem([false]);
		$t->isFalse(fulltext_engine::create_index('writefail', 'id', 'json'));
		core::scriptFilesystem();
	})->maxMillis(1500);

	test('JSON entry writes enforce primary keys duplicates updates and malformed-shard recovery', static function(Context $t): void {
		dp_fulltext_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		$t->isFalse(fulltext_engine::add_to_index('missing', ['id'=>'1']));
		$t->throws(static fn()=>fulltext_engine::add_to_index('products', ['title'=>'missing id']), RuntimeException::class);
		$t->isTrue(fulltext_engine::add_to_index('products', ['id'=>'1', 'title'=>'Red shoes']));
		$t->isFalse(fulltext_engine::add_to_index('products', ['id'=>'1', 'title'=>'Duplicate']));
		$t->isTrue(fulltext_engine::add_to_index('products', ['id'=>'2', 'title'=>'Blue shoes']));
		$t->isTrue(fulltext_engine::update_in_index('products', ['id'=>'1', 'title'=>'Red running shoes']));
		$t->isFalse(fulltext_engine::update_in_index('products', ['id'=>'missing', 'title'=>'Missing']));
		$t->isFalse(fulltext_engine::update_in_index('missing', ['id'=>'1']));
		$t->throws(static fn()=>fulltext_engine::update_in_index('products', ['title'=>'missing id']), RuntimeException::class);

		FulltextKernelWorkspace::jsonShard('products', 0, '{invalid');
		$t->isTrue(fulltext_engine::update_in_index('products', ['id'=>'2', 'title'=>'Blue running shoes']));
	})->maxMillis(1500);

	test('JSON search scans shards ranks matches and reports malformed index storage', static function(Context $t): void {
		$kernel=dp_fulltext_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, ['1'=>['title'=>'red shoe'], '3'=>['title'=>'red boot']]);
		FulltextKernelWorkspace::jsonShard('products', 1, ['2'=>['title'=>'blue shoe']]);
		$search=fulltext_engine::search('products', ['*'=>'red'], 'en', 1, false, 0.0, 'lavenshtein');
		$t->same(1, $search['count']);
		$t->isTrue($search['certainty']>=0.0);
		$t->hasKey('time', $search);
		$t->same([], fulltext_engine::find_in_index('products', ['*'=>'none'], 'en', false, 2, 1.1, 'lavenshtein'));

		FulltextKernelWorkspace::jsonShard('broken', 0, '{invalid');
		dp_fulltext_definitions($kernel, ['broken'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		$t->throws(static fn()=>fulltext_engine::find_in_index('broken', ['*'=>'red'], 'en', false, 2, 0.0), RuntimeException::class);
	})->maxMillis(1500);

	test('JSON removal rewrites populated shards and deletes shards when their final entry leaves', static function(Context $t): void {
		dp_fulltext_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, ['1'=>['title'=>'red'], '2'=>['title'=>'blue']]);
		$t->isTrue(fulltext_engine::remove_from_index('products', '1'));
		$t->isTrue(file_exists(FulltextKernelWorkspace::root().'fulltext_indexes/json/products/0'));
		$t->isTrue(fulltext_engine::remove_from_index('products', '2'));
		$t->isFalse(file_exists(FulltextKernelWorkspace::root().'fulltext_indexes/json/products/0'));
		$t->throws(static fn()=>fulltext_engine::remove_from_index('products', 'missing'), RuntimeException::class);
		$t->throws(static fn()=>fulltext_engine::remove_from_index('missing', '1'), RuntimeException::class);
	})->maxMillis(1500);

	test('unknown backends reject every CRUD operation without pretending to own storage', static function(Context $t): void {
		dp_fulltext_kernel($t, ['mystery'=>['type'=>'mystery', 'primary_key_column_name'=>'id']]);
		$t->isFalse(fulltext_engine::add_to_index('mystery', ['id'=>'1', 'title'=>'red']));
		$t->isFalse(fulltext_engine::update_in_index('mystery', ['id'=>'1', 'title'=>'red']));
		$t->isFalse(fulltext_engine::remove_from_index('mystery', '1'));
		$t->isFalse(fulltext_engine::find_in_index('mystery', ['*'=>'red']));
		$t->isFalse(fulltext_engine::delete_index('mystery'));
		$t->isFalse(fulltext_engine::delete_index('missing'));
	})->maxMillis(1000);

	test('JSON index deletion reports a storage-removal failure without changing its definition', static function(Context $t): void {
		dp_fulltext_kernel($t, ['deletefail'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		mkdir(FulltextKernelWorkspace::root().'fulltext_indexes/json/deletefail', 0777, true);
		core::scriptFilesystem([], [false]);
		$t->isFalse(fulltext_engine::delete_index('deletefail'));
	})->maxMillis(1000);

	test('JSON index deletion surfaces a manifest-write failure after storage removal', static function(Context $t): void {
		dp_fulltext_kernel($t, ['persistfail'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		mkdir(FulltextKernelWorkspace::root().'fulltext_indexes/json/persistfail', 0777, true);
		core::scriptFilesystem([false]);
		$t->throws(static fn()=>fulltext_engine::delete_index('persistfail'), RuntimeException::class);
	})->maxMillis(1500);

	test('SQLite lifecycle is hermetic across capacity duplicate update scan and deletion paths', static function(Context $t): void {
		$kernel=dp_fulltext_kernel($t);
		FulltextKernelExtensions::sqlite(false);
		$t->throws(static fn()=>fulltext_engine::create_index('disabled', 'id', 'sqlite'), RuntimeException::class);
		FulltextKernelExtensions::sqlite(true);
		$t->isTrue(fulltext_engine::create_index('records', 'id', 'sqlite'));
		$t->isTrue(fulltext_engine::add_to_index('records', ['id'=>'1', 'title'=>'Red shoes']));
		$t->isFalse(fulltext_engine::add_to_index('records', ['id'=>'1', 'title'=>'Duplicate']));
		$t->isTrue(fulltext_engine::add_to_index('records', ['id'=>'2', 'title'=>'Blue shoes']));
		$t->isTrue(fulltext_engine::update_in_index('records', ['id'=>'2', 'title'=>'Blue running shoes']));
		$t->isFalse(fulltext_engine::update_in_index('records', ['id'=>'missing', 'title'=>'Missing']));
		$found=fulltext_engine::find_in_index('records', ['*'=>'shoe'], 'en', false, 10, 0.0, 'lavenshtein');
		$t->isTrue(is_array($found));
		$t->count(2, $found);
		$t->isTrue(fulltext_engine::remove_from_index('records', '2'));
		$t->isFalse(fulltext_engine::remove_from_index('records', 'missing'));

		FulltextKernelWorkspace::sqliteShard('records', 0, ['bad'=>'{invalid']);
		$t->same([], fulltext_engine::find_in_index('records', ['*'=>'red'], 'en', false, 10, 0.0));

		FulltextKernelExtensions::sqlite(false);
		$t->throws(static fn()=>fulltext_engine::add_to_index('records', ['id'=>'3']), RuntimeException::class);
		$t->throws(static fn()=>fulltext_engine::update_in_index('records', ['id'=>'1']), RuntimeException::class);
		$t->throws(static fn()=>fulltext_engine::remove_from_index('records', '1'), RuntimeException::class);
		$t->throws(static fn()=>fulltext_engine::find_in_index('records', ['*'=>'red']), RuntimeException::class);
		$t->throws(static fn()=>fulltext_engine::delete_index('records'), RuntimeException::class);
		FulltextKernelExtensions::sqlite(true);
		$t->isTrue(fulltext_engine::delete_index('records'));
	})->maxMillis(4000);

	test('SQL lifecycle normalizes persistence fallbacks and candidate projections', static function(Context $t): void {
		$kernel=dp_fulltext_kernel($t);
		FulltextKernelSql::respond('query', false, true);
		$t->isFalse(fulltext_engine::create_index('failed', 'id', 'sql'));
		$t->isTrue(fulltext_engine::create_index('records', 'id', 'sql'));

		FulltextKernelSql::respond('query', true, false, true, true);
		FulltextKernelSql::respond('insert', false, true);
		$t->isFalse(fulltext_engine::add_to_index('records', ['id'=>'1', 'title'=>'Red']));
		$t->isFalse(fulltext_engine::add_to_index('records', ['id'=>'2', 'title'=>'Blue']));
		$t->isTrue(fulltext_engine::add_to_index('records', ['id'=>'3', 'title'=>'Green']));

		FulltextKernelSql::respond('update', null, false, true);
		$t->isFalse(fulltext_engine::update_in_index('records', ['id'=>'1', 'title'=>'Red']));
		$t->isFalse(fulltext_engine::update_in_index('records', ['id'=>'1', 'title'=>'Red']));
		$t->isTrue(fulltext_engine::update_in_index('records', ['id'=>'1', 'title'=>'Red']));
		FulltextKernelSql::respond('delete', false, true);
		$t->isFalse(fulltext_engine::remove_from_index('records', '1'));
		$t->isTrue(fulltext_engine::remove_from_index('records', '1'));

		FulltextKernelSql::respond('select', false, [
			['missing'=>'primary'],
			['id'=>'1', 'index_value'=>'{"title":"red shoe"}'],
			['id'=>'2', 'index_value'=>'invalid', 'title'=>'blue shoe'],
		]);
		$found=fulltext_engine::find_in_index('records', ['*'=>'red shoe'], 'en', true, 10, 0.0, 'lavenshtein');
		$t->isTrue(is_array($found));
		$t->isTrue(count($found)>=1);
		FulltextKernelSql::respond('select', false, false);
		$t->isFalse(fulltext_engine::find_in_index('records', ['*'=>'red']));

		dp_fulltext_definitions($kernel, ['bad-name'=>['type'=>'sql', 'primary_key_column_name'=>'id']]);
		$t->isFalse(fulltext_engine::add_to_index('bad-name', ['id'=>'1']));
		$t->isFalse(fulltext_engine::update_in_index('bad-name', ['id'=>'1']));
		$t->isFalse(fulltext_engine::remove_from_index('bad-name', '1'));
		$t->isFalse(fulltext_engine::find_in_index('bad-name', ['*'=>'red']));

		dp_fulltext_definitions($kernel, ['records'=>['type'=>'sql', 'primary_key_column_name'=>'id']]);
		FulltextKernelSql::respond('query', false, true);
		$t->isFalse(fulltext_engine::delete_index('records'));
		$t->isTrue(fulltext_engine::delete_index('records'));
		$t->isTrue(count(FulltextKernelSql::calls())>0);
	})->maxMillis(4000);

	test('Elasticsearch lifecycle delegates every index operation through the scripted HTTP transport', static function(Context $t): void {
		dp_fulltext_kernel($t);
		FulltextCurlTransport::respond('{}', 201);
		$t->isTrue(fulltext_engine::create_index('elastic_records', 'id', 'elastic', 'en'));
		FulltextCurlTransport::respond('{}', 201);
		$t->isTrue(fulltext_engine::add_to_index('elastic_records', ['id'=>'1', 'title'=>'Red'], 'en'));
		FulltextCurlTransport::respond('{"hits":{"hits":[{"_id":"doc-1"}]}}', 200);
		FulltextCurlTransport::respond('{}', 200);
		$t->isTrue(fulltext_engine::update_in_index('elastic_records', ['id'=>'1', 'title'=>'Blue'], 'en'));
		FulltextCurlTransport::respond('{"hits":{"hits":[{"_id":"doc-1","_score":0.9,"_source":{"id":"1"}}]}}', 200);
		$t->count(1, fulltext_engine::find_in_index('elastic_records', ['title'=>'Blue'], 'en', false, 10, 0.0));
		FulltextCurlTransport::respond('{"hits":{"hits":[{"_id":"doc-1"}]}}', 200);
		FulltextCurlTransport::respond('{}', 200);
		$t->isTrue(fulltext_engine::remove_from_index('elastic_records', '1'));
		FulltextCurlTransport::respond('{}', 200);
		$t->isTrue(fulltext_engine::delete_index('elastic_records'));
		$t->count(8, FulltextCurlTransport::requests());
	})->maxMillis(3000);

	test('Vespa index creation packages and activates one deployment through two HTTP requests', static function(Context $t): void {
		dp_fulltext_kernel($t);
		FulltextCurlTransport::respond('{"session":"/session/1"}', 200);
		FulltextCurlTransport::respond('{}', 200);
		$t->isTrue(fulltext_engine::create_index('vespa_records', 'id', 'vespa'));
		$t->count(2, FulltextCurlTransport::requests());
	})->maxMillis(1500);

	test('Vespa document insertion maps a Dataphyre record to one HTTP write', static function(Context $t): void {
		dp_fulltext_kernel($t, ['vespa_records'=>['type'=>'vespa', 'primary_key_column_name'=>'id']]);
		FulltextCurlTransport::respond('{}', 201);
		$t->isTrue(fulltext_engine::add_to_index('vespa_records', ['id'=>'1', 'title'=>'Red']));
		$t->count(1, FulltextCurlTransport::requests());
	})->maxMillis(1000);

	test('Vespa document updates retain primary identity through one HTTP write', static function(Context $t): void {
		dp_fulltext_kernel($t, ['vespa_records'=>['type'=>'vespa', 'primary_key_column_name'=>'id']]);
		FulltextCurlTransport::respond('{}', 200);
		$t->isTrue(fulltext_engine::update_in_index('vespa_records', ['id'=>'1', 'title'=>'Blue']));
		$t->count(1, FulltextCurlTransport::requests());
	})->maxMillis(1000);

	test('Vespa search maps result children into scored Dataphyre primary keys', static function(Context $t): void {
		dp_fulltext_kernel($t, ['vespa_records'=>['type'=>'vespa', 'primary_key_column_name'=>'id']]);
		FulltextCurlTransport::respond('{"root":{"children":[{"relevance":0.8,"fields":{"id":"1"}}]}}', 200);
		$t->count(1, fulltext_engine::find_in_index('vespa_records', ['title'=>'Blue'], 'en', false, 10, 0.0));
		$t->count(1, FulltextCurlTransport::requests());
	})->maxMillis(1000);

	test('Vespa document removal accepts asynchronous transport completion', static function(Context $t): void {
		dp_fulltext_kernel($t, ['vespa_records'=>['type'=>'vespa', 'primary_key_column_name'=>'id']]);
		FulltextCurlTransport::respond('{}', 202);
		$t->isTrue(fulltext_engine::remove_from_index('vespa_records', '1'));
		$t->count(1, FulltextCurlTransport::requests());
	})->maxMillis(1000);

	test('Vespa index deletion removes the definition after asynchronous transport completion', static function(Context $t): void {
		dp_fulltext_kernel($t, ['vespa_records'=>['type'=>'vespa', 'primary_key_column_name'=>'id']]);
		FulltextCurlTransport::respond('{}', 202);
		$t->isTrue(fulltext_engine::delete_index('vespa_records'));
		$t->count(1, FulltextCurlTransport::requests());
	})->maxMillis(1000);
}
