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
	use dataphyre\FulltextKernelSql;
	use dataphyre\FulltextKernelWorkspace;
	use dataphyre\fulltext_engine\FulltextCurlTransport;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	suite('Fulltext exact kernel failures and bounds')
		->contract('fulltext.kernel-exact', 1)
		->layer('unit')
		->risk('high')
		->watches('module:fulltext_engine')
		->through('bounded-results', 'shard-recovery', 'backend-failures', 'stemmer-fallback')
		->sandboxesRootpath('dataphyre')
		->isolation('case')
		->tag('fulltext', 'kernel', 'exact-coverage')
		->group('framework-coverage');

	/** @param array<string,array<string,mixed>> $definitions */
	function dp_fulltext_exact_kernel(Context $t, array $definitions=[]): \Dataphyre\Test\NonPublicAccess {
		FulltextKernelWorkspace::reset();
		$kernel=$t->nonPublic(fulltext_engine::class);
		$kernel->writeProperty('initialized', true);
		$kernel->writeProperty('index_definitions', $definitions);
		$kernel->writeProperty('tokenize_cache', []);
		return $kernel;
	}

	test('search widens its candidate pool for BM25 then returns only the requested top results', static function(Context $t): void {
		dp_fulltext_exact_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, [
			'1'=>['title'=>'red shoe catalog entry'],
			'2'=>['title'=>'red shoe catalog sale'],
		]);
		$result=fulltext_engine::search('products', ['*'=>'red shoe catalog'], 'en', 1, false, 0.0, 'bm25');
		$t->same(1, $result['count']);
		$t->count(1, $result['results']);
	});

	test('boolean parsing pops higher precedence operators and missing stemmers preserve the original query', static function(Context $t): void {
		dp_fulltext_exact_kernel($t);
		$t->same(['red', 'blue', 'AND', 'green', 'OR'], fulltext_engine::parse_expression(['red', 'AND', 'blue', 'OR', 'green']));
		$t->same('unchanged query', fulltext_engine::apply_stemming('unchanged query', 'zz', static fn(string $language): null=>null));
	});

	test('SQL updates fail before mutation when their backing table cannot be prepared', static function(Context $t): void {
		dp_fulltext_exact_kernel($t, ['records'=>['type'=>'sql', 'primary_key_column_name'=>'id']]);
		FulltextKernelSql::respond('query', false);
		$t->isFalse(fulltext_engine::update_in_index('records', ['id'=>'1', 'title'=>'Red']));

		FulltextKernelSql::reset();
		$rows=[];
		for($id=1; $id<=5; $id++){
			$rows[]=['id'=>(string)$id, 'index_value'=>'{"title":"red"}'];
		}
		FulltextKernelSql::respond('select', $rows);
		$t->count(1, fulltext_engine::find_in_index('records', ['*'=>'red'], 'en', false, 1, 0.0, 'exact'));
	});

	test('JSON shard writes recover malformed or empty files and removal scans later shards', static function(Context $t): void {
		dp_fulltext_exact_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, '{corrupt');
		$t->isTrue(fulltext_engine::add_to_index('products', ['id'=>'1', 'title'=>'Recovered']));

		dp_fulltext_exact_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, []);
		$t->isTrue(fulltext_engine::add_to_index('products', ['id'=>'1', 'title'=>'Filled']));

		dp_fulltext_exact_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, '{corrupt');
		$t->throws(static fn()=>fulltext_engine::remove_from_index('products', '1'), RuntimeException::class);

		dp_fulltext_exact_kernel($t, ['products'=>['type'=>'json', 'primary_key_column_name'=>'id']]);
		FulltextKernelWorkspace::jsonShard('products', 0, ['other'=>['title'=>'Other']]);
		FulltextKernelWorkspace::jsonShard('products', 1, ['target'=>['title'=>'Target']]);
		$t->isTrue(fulltext_engine::remove_from_index('products', 'target'));
	});

	test('external index creation propagates Vespa and Elasticsearch setup failures', static function(Context $t): void {
		dp_fulltext_exact_kernel($t);
		FulltextCurlTransport::fail('vespa unavailable');
		$t->isFalse(fulltext_engine::create_index('vespa_failure', 'id', 'vespa'));

		dp_fulltext_exact_kernel($t);
		FulltextCurlTransport::respond('{}', 500);
		$t->isFalse(fulltext_engine::create_index('elastic_failure', 'id', 'elastic'));
	});

	test('Vespa deployment removes an archive that becomes unreadable after packaging', static function(Context $t): void {
		dp_fulltext_exact_kernel($t);
		$t->isFalse(\dataphyre\fulltext_engine\vespa::create_index(
			'archive_failure',
			'id',
			static fn(string $path): false=>false,
		));
	});
}
