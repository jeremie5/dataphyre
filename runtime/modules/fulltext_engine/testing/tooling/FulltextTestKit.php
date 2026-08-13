<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\FulltextEngine\Testing;

use Dataphyre\Test\Context;
use Dataphyre\Test\Contracts\TestContext;
use InvalidArgumentException;
use RuntimeException;

/**
 * Module-owned, self-describing Fulltext vocabulary for TestKit contracts.
 *
 * Tests state which lexical or ranking contract they intend to prove while this
 * kit owns source loading, canonical data fingerprints, and reusable semantic
 * assertions. It is loaded only by the test runner's module bootstrap.
 */
final class FulltextTestKit {

	private string $moduleRoot;

	public function __construct(private TestContext $context) {
		$this->moduleRoot=dirname(__DIR__, 2);
	}

	/** Registers `$t->fulltext()` through TestKit's module-extension boundary. */
	public static function register(): void {
		Context::extend('fulltext', static fn(TestContext $context): self=>new self($context));
	}

	/** Begins a deterministic schema-and-content contract for one stopword list. */
	public function stopwords(string $language): FulltextStopwordContract {
		if(preg_match('/^[a-z]{2}$/', $language)!==1){
			throw new InvalidArgumentException('A stopword contract requires a two-letter lowercase language code.');
		}
		return new FulltextStopwordContract(
			$this->context,
			$language,
			$this->moduleRoot.'/stopwords/'.$language.'_stopwords.php'
		);
	}

	/** Loads one explicitly module-relative product algorithm source. */
	public function loadAlgorithmSource(string $relativePath): void {
		$relativePath=str_replace('\\', '/', trim($relativePath, '/\\ '));
		if($relativePath==='' || str_contains($relativePath, '..') || preg_match('/^[a-z0-9_\/-]+\.php$/i', $relativePath)!==1){
			throw new InvalidArgumentException('A fulltext algorithm source must be a safe module-relative PHP path.');
		}
		$path=$this->moduleRoot.'/'.$relativePath;
		if(!is_file($path)){
			throw new RuntimeException('Fulltext algorithm source does not exist: '.$relativePath);
		}
		require_once $path;
	}

	/** Proves the public edit-distance behavior shared by both legacy source slots. */
	public function assertEditDistanceContract(string $relativePath): void {
		$this->loadAlgorithmSource($relativePath);
		$class='dataphyre\\fulltext_engine\\damerau_levenshtein';
		$this->context->same(0.0, (float)$class::similarity('catalog', 'catalog'), 'Identical text has zero edit distance.');
		$this->context->same(3.0, (float)$class::similarity('', 'abc'), 'An empty input costs the other input length.');
		$this->context->same(1.0, (float)$class::similarity('form', 'from'), 'An adjacent transposition costs one edit.');
		$this->context->same(1.0, (float)$class::similarity('shoe', 'shoes'), 'A single insertion costs one edit.');
		$this->context->same(1.0, (float)$class::similarity('cafe', 'café'), 'Character access remains multibyte-aware.');
	}

	/** Proves normalized and raw BM25 behavior over string and pre-tokenized corpora. */
	public function assertBm25Contract(): void {
		$this->loadAlgorithmSource('kernel/bm25.php');
		$class='dataphyre\\fulltext_engine\\bm25';
		$this->context->same(0.0, $class::similarity('', 'shoe'));
		$this->context->same(0.0, $class::raw_score('shoe', ''));
		$this->context->greaterThan(0.0, $class::raw_score('shoe', 'shoe'));
		$corpus=['red leather shoe', ['blue', 'shoe', ''], 42, '', ['   ']];
		$raw=$class::raw_score('red leather shoe', 'red shoe', $corpus);
		$normalized=$class::similarity('red leather shoe', 'red shoe', $corpus);
		$this->context->greaterThan(0.0, $raw, 'Matching terms produce a positive raw relevance score.');
		$this->context->between(0.0, 1.0, $normalized, 'Normalized relevance stays inside its public range.');
		$this->context->same(0.0, $class::raw_score('coat', 'shoe', ['coat', 'shoe']));
		$this->context->same(0.0, $class::similarity('shoe', 'shoe', null, -1.0));
		$this->context->same(1.0, $class::similarity(
			'shoe',
			'shoe',
			['shoe', 'other other other other other other other other other other'],
			1.2,
			100.0
		));
		$this->context->approximately(
			$normalized,
			$class::similarity('red leather shoe', 'red shoe', $corpus),
			0.000000000001,
			'BM25 is deterministic for a stable corpus.'
		);
	}

	/** Proves n-gram selection, repetition counting, and smoothing output shape. */
	public function assertNgramContract(): void {
		$this->loadAlgorithmSource('kernel/ngram.php');
		$class='dataphyre\\fulltext_engine\\ngram';
		$this->context->same('', $class::laplace_smoothing([]));
		$this->context->same('red shoe blue shoe', $class::laplace_smoothing(['red shoe'=>2, 'blue shoe'=>1], 2));
		$this->context->same(['shoe'=>1], $class::apply_ngrams('shoe'));
		$this->context->same(['red shoe'=>1], $class::apply_ngrams('red shoe'));
		$this->context->same(['one two three'=>1], $class::apply_ngrams('one two three'));
		$this->context->same(['one two three four'=>1], $class::apply_ngrams('one two three four'));
		$this->context->same(['one two three four five'=>1, 'two three four five six'=>1], $class::apply_ngrams('one two three four five six'));
		$this->context->same(['red shoe'=>2, 'shoe red'=>1], $class::ngram('Red, shoe red shoe!', 2));
	}

	/** Proves RAKE-style candidate boundaries, word scores, and result projections. */
	public function assertKeywordExtractionContract(): void {
		$this->loadAlgorithmSource('kernel/keyword_extraction.php');
		$class='dataphyre\\fulltext_engine\\keyword_extraction';
		$phrases=$class::generate_candidate_keywords('Red leather and blue shoe.', 'en');
		$this->context->same([['red', 'leather'], ['blue', 'shoe']], $phrases);
		$this->context->same(2, $class::word_degree('red', $phrases));
		$this->context->same(1, $class::word_frequency('red', $phrases));
		$wordScores=$class::calculate_word_scores($phrases);
		$this->context->equals(['red'=>2.0, 'leather'=>2.0, 'blue'=>2.0, 'shoe'=>2.0], $wordScores);
		$this->context->equals(['red leather'=>4.0, 'blue shoe'=>4.0], $class::calculate_phrase_scores($phrases, $wordScores));
		$this->context->same(['red leather', 'blue shoe'], $class::return_formated_phrase_list($phrases));
		$this->context->same(['red leather', 'blue shoe'], $class::extract_keywords('Red leather and blue shoe.', false, 'en'));
		$this->context->equals(['red leather'=>4.0, 'blue shoe'=>4.0], $class::extract_keywords('Red leather and blue shoe.', true, 'en'));
		$this->context->same([], $class::extract_keywords('and the', true, 'en'));
		$this->context->same([['red', 'leather']], $class::generate_candidate_keywords('red leather', 'en'));
		$this->context->containsAll(['hello', ',', 'world'], $class::tokenize('Hello, world'));
	}

	/** Proves token-set union/intersection semantics, including the empty union. */
	public function assertJaccardContract(): void {
		$this->loadAlgorithmSource('similarity/jaccard.php');
		$class='dataphyre\\fulltext_engine\\jaccard';
		$this->context->same(0.0, $class::similarity('', ''));
		$this->context->same(1.0, $class::similarity('red red shoe', 'shoe red'));
		$this->context->approximately(1/3, $class::similarity('red shoe', 'shoe blue'), 0.000000000001);
		$this->context->same(0.0, $class::similarity('red', 'blue'));
	}

	/** Proves case folding, no-match behavior, transpositions, and prefix boost. */
	public function assertJaroWinklerContract(): void {
		$this->loadAlgorithmSource('similarity/jarowinkler.php');
		$class='dataphyre\\fulltext_engine_jaro_winkler';
		$this->context->same(0.0, $class::similarity('', ''));
		$this->context->same(0.0, $class::similarity('abc', 'xyz'));
		$this->context->same(1.0, $class::similarity('DATAPHYRE', 'dataphyre'));
		$this->context->approximately(0.9611111111111111, $class::similarity('martha', 'marhta'), 0.000000000001);
		$this->context->approximately(0.8133333333333332, $class::similarity('dixon', 'dicksonx'), 0.000000000001);
	}

	/**
	 * Proves the complete Elasticsearch-compatible adapter boundary through a
	 * deterministic namespaced transport script.
	 */
	public function assertCompatibleHttpAdapterContract(string $relativePath, array $configuration): void {
		$this->defineConfiguration($configuration);
		$this->loadAlgorithmSource($relativePath);
		$adapter='dataphyre\\fulltext_engine\\elasticsearch';
		$transport='dataphyre\\fulltext_engine\\FulltextCurlTransport';

		$transport::reset();
		$transport::respond(json_encode(['hits'=>['hits'=>[
			['_score'=>0.9, '_source'=>['sku'=>'shoe-1']],
			['_score'=>0.2, '_source'=>['sku'=>'ignored']],
		]]], JSON_THROW_ON_ERROR));
		$this->context->same([['shoe-1'=>0.9]], $adapter::find('Products', ['title'=>'shoe', 'sku'=>'ab'], 'sku', true, 'fr-CA', 7, 0.5));
		$searchRequest=$transport::lastRequest();
		$this->context->same('https://search.example.test/products/_search', $searchRequest['url']);
		$searchBody=$this->decodeRequestBody($searchRequest);
		$this->context->same(7, $searchBody['size']);
		$this->context->same('and', $searchBody['query']['bool']['must'][0]['match']['title']['operator']);
		$this->context->same('french', $searchBody['query']['bool']['must'][0]['match']['title']['analyzer']);
		$this->context->same('AUTO', $searchBody['query']['bool']['must'][0]['match']['title']['fuzziness']);
		$this->context->same(0, $searchBody['query']['bool']['must'][1]['match']['sku']['fuzziness']);

		$transport::reset();
		$transport::respond('{"hits":{"hits":[]}}');
		$this->context->same([], $adapter::find('Products', ['title'=>'coat'], 'sku', 'and', 'de', 2, 0.0));
		$this->context->hasKey('must', $this->decodeRequestBody($transport::lastRequest())['query']['bool']);
		$transport::reset();
		$transport::respond('not-json');
		$this->context->same([], $adapter::find('Products', ['title'=>'coat'], 'sku', 'unexpected', 'zz', 2, 0.0));
		$this->context->hasKey('should', $this->decodeRequestBody($transport::lastRequest())['query']['bool']);
		$transport::reset();
		$transport::fail();
		$this->context->same([], $adapter::find('Products', [], 'sku', false, 'en', 2, 0.0));

		foreach([
			'en-US'=>'english',
			'fr'=>'french',
			'de'=>'german',
			'es'=>'spanish',
			'zz'=>'standard',
		] as $language=>$analyzer){
			$transport::reset();
			$transport::respond('{}', 201);
			$this->context->isTrue($adapter::create_index('Products', 'sku', $language));
			$definition=$this->decodeRequestBody($transport::lastRequest());
			$this->context->same($analyzer, $definition['settings']['analysis']['analyzer']['default']['type']);
			$this->context->same('keyword', $definition['mappings']['properties']['sku']['type']);
		}
		$transport::reset();
		$transport::respond('failure', 500, 'rejected');
		$this->context->isFalse($adapter::create_index('Products', 'sku', 'en'));

		$transport::reset();
		$transport::fail();
		$this->context->isFalse($adapter::delete_index('Products'));
		$transport::reset();
		$transport::respond('{}', 404);
		$this->context->isTrue($adapter::delete_index('Products'));

		$transport::reset();
		$transport::fail();
		$this->context->isFalse($adapter::update('Products', ['name'=>'Updated'], 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[]}}');
		$this->context->isFalse($adapter::update('Products', ['name'=>'Updated'], 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[{"_id":"remote-1"}]}}');
		$transport::fail();
		$this->context->isFalse($adapter::update('Products', ['name'=>'Updated'], 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[{"_id":"remote-1"}]}}');
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::update('Products', ['name'=>'Updated'], 'sku', 'shoe-1', 'en'));
		$this->context->endsWith('/products/_doc/remote-1/_update', $transport::lastRequest()['url']);
		$this->context->same(['doc'=>['name'=>'Updated']], $this->decodeRequestBody($transport::lastRequest()));

		$transport::reset();
		$this->context->isFalse($adapter::add('Products', ['name'=>'Shoe'], '', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('failure', 503, 'offline');
		$this->context->isFalse($adapter::add('Products', [''=>'discard', 'name'=>'Shoe'], 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{}', 201);
		$this->context->isTrue($adapter::add('Products', [''=>'discard', 'name'=>'Shoe'], 'sku', 'shoe-1', 'en'));
		$this->context->same(['name'=>'Shoe', 'sku'=>'shoe-1'], $this->decodeRequestBody($transport::lastRequest()));

		$transport::reset();
		$transport::fail();
		$this->context->isFalse($adapter::remove('Products', 'sku', 'shoe-1'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[]}}');
		$this->context->isTrue($adapter::remove('Products', 'sku', 'shoe-1'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[{"_id":"remote-1"}]}}');
		$transport::fail();
		$this->context->isFalse($adapter::remove('Products', 'sku', 'shoe-1'));
		$transport::reset();
		$transport::respond('{"hits":{"hits":[{"_id":"remote-1"}]}}');
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::remove('Products', 'sku', 'shoe-1'));
		$this->context->endsWith('/products/_doc/remote-1', $transport::lastRequest()['url']);
		$this->context->throws(
			fn()=>$this->decodeRequestBody(['options'=>[]]),
			RuntimeException::class,
			'non-object JSON request'
		);
	}

	/** Proves every supported endpoint alias and the blank/default fallback. */
	public function assertCompatibleEndpointAliasContract(string $relativePath, array $configuration, string $expectedBaseUrl): void {
		$this->defineConfiguration($configuration);
		$this->loadAlgorithmSource($relativePath);
		$adapter='dataphyre\\fulltext_engine\\elasticsearch';
		$transport='dataphyre\\fulltext_engine\\FulltextCurlTransport';
		$transport::reset();
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::delete_index('AliasProbe'));
		$this->context->same(rtrim($expectedBaseUrl, '/').'/aliasprobe', $transport::lastRequest()['url']);
	}

	/** Proves Vespa packaging, retry, query, projection, and document lifecycle semantics. */
	public function assertVespaAdapterContract(string $applicationRoot): void {
		$this->defineConfiguration([
			'external_engines'=>['vespa'=>[
				'query_url'=>' https://vespa-query.example.test/ ',
				'config_url'=>' https://vespa-config.example.test/ ',
				'application_root'=>$applicationRoot,
				'archive_class'=>'dataphyre\\FulltextVespaArchiveFake',
				'prepare_max_attempts'=>2,
				'prepare_retry_delay_seconds'=>0,
				'http_timeout_seconds'=>0,
			]],
		]);
		$this->loadAlgorithmSource('external_engines/vespa.php');
		$adapter='dataphyre\\fulltext_engine\\vespa';
		$transport='dataphyre\\fulltext_engine\\FulltextCurlTransport';
		$io='dataphyre\\FulltextVespaCoreIo';
		$archive='dataphyre\\FulltextVespaArchiveFake';

		$this->context->same([], $adapter::find('products', ['q'=>'shoe'], '', true, 'en', 5, 0.5));
		$transport::reset();
		$transport::fail();
		$this->context->same([], $adapter::find('products', ['q'=>'shoe'], 'sku', true, 'en', 5, 0.5));
		$transport::reset();
		$transport::respond('not-json');
		$this->context->same([], $adapter::find('products', [], 'sku', false, 'en', 3, 0.0));
		$this->context->contains(urlencode('select * from sources * where true limit 3;'), $transport::lastRequest()['url']);
		$transport::reset();
		$transport::respond(json_encode(['root'=>['children'=>[
			['relevance'=>0.9, 'fields'=>['sku'=>'shoe-1']],
			['relevance'=>0.2, 'fields'=>['sku'=>'below-threshold']],
			['relevance'=>0.8, 'fields'=>['name'=>'missing-primary']],
			['relevance'=>0.7, 'fields'=>'invalid'],
		]]], JSON_THROW_ON_ERROR));
		$this->context->same([['shoe-1'=>0.9]], $adapter::find(
			'products',
			['first'=>"red's", 'empty'=>'', 'second'=>'shoe'],
			'sku',
			'and',
			'en',
			5,
			0.5
		));
		$searchUrl=urldecode($transport::lastRequest()['url']);
		$this->context->contains("content contains 'red\\'s' and content contains 'shoe'", $searchUrl);

		$values=['name'=>'Shoe', 'tags'=>['red'], 'meta'=>(object)['season'=>'summer'], 'empty'=>''];
		$transport::reset();
		$transport::respond('{}', 201);
		$this->context->isTrue($adapter::add('products', $values, 'sku', 'shoe / 1', 'en'));
		$this->context->endsWith('/document/v1/products/products/docid/shoe%20%2F%201', $transport::lastRequest()['url']);
		$addBody=$this->decodeRequestBody($transport::lastRequest());
		$this->context->same('shoe / 1', $addBody['fields']['sku']);
		$this->context->contains('Shoe', $addBody['fields']['content']);
		$this->context->contains('{"season":"summer"}', $addBody['fields']['content']);
		$transport::reset();
		$transport::fail();
		$this->context->isFalse($adapter::add('products', $values, 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{}', 503);
		$this->context->isFalse($adapter::add('products', $values, 'sku', 'shoe-1', 'en'));

		$transport::reset();
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::update('products', $values, 'sku', 'shoe-1', 'en'));
		$this->context->same('PUT', $transport::lastRequest()['options'][CURLOPT_CUSTOMREQUEST]);
		$transport::reset();
		$transport::fail();
		$this->context->isFalse($adapter::update('products', $values, 'sku', 'shoe-1', 'en'));
		$transport::reset();
		$transport::respond('{}', 500);
		$this->context->isFalse($adapter::update('products', $values, 'sku', 'shoe-1', 'en'));

		foreach([[false, 0, false], ['{}', 200, true], ['{}', 202, true], ['{}', 404, true], ['{}', 500, false]] as [$body, $code, $expected]){
			$transport::reset();
			$transport::respond($body, $code);
			$this->context->same($expected, $adapter::remove('products', 'sku', 'shoe-1'));
		}
		foreach([[200, true], [202, true], [404, true], [500, false]] as [$code, $expected]){
			$transport::reset();
			$transport::respond('{}', $code);
			$this->context->same($expected, $adapter::delete_index('products'));
			$this->context->startsWith('https://vespa-config.example.test/application/v2/', $transport::lastRequest()['url']);
		}

		$io::reset([false]);
		$archive::reset();
		$this->context->isFalse($adapter::create_index('schema-write-fails', 'sku'));
		$io::reset([true, false]);
		$this->context->isFalse($adapter::create_index('services-write-fails', 'sku'));
		$io::reset([], false);
		$this->context->isFalse($adapter::create_index('directory-missing', 'sku'));
		$this->context->isFalse($this->context->nonPublic($adapter)->invoke(
			'build_deployment_archive',
			$applicationRoot.'/does-not-exist',
			$applicationRoot.'/does-not-exist.zip'
		));

		$io::reset();
		$archive::reset();
		$archive::$openResult=false;
		$this->context->isFalse($adapter::create_index('archive-open-fails', 'sku'));
		$archive::reset();
		$archive::$addFileResult=false;
		$this->context->isFalse($adapter::create_index('archive-add-fails', 'sku'));
		$archive::reset();
		$archive::$closeResult=false;
		$this->context->isFalse($adapter::create_index('archive-close-fails', 'sku'));
		$archive::reset();
		$archive::$materialize=false;
		$this->context->isFalse($adapter::create_index('archive-not-materialized', 'sku'));
		$archive::reset();
		$archive::$materializeAsDirectory=true;
		$this->context->isFalse($adapter::create_index('archive-unreadable', 'sku'));

		$archive::reset();
		$transport::reset();
		$transport::fail('prepare offline');
		$transport::respond('invalid session');
		$this->context->isFalse($adapter::create_index('prepare-fails', 'sku'));
		$transport::reset();
		$transport::fail('retry once');
		$transport::respond('{"session":"/application/v2/tenant/default/session/42"}', 200);
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::create_index('prepare-retries', 'sku'));
		$this->context->endsWith('/application/v2/tenant/default/session/42/active', $transport::lastRequest()['url']);
		$transport::reset();
		$transport::respond('{"session":"/application/v2/tenant/default/session/43"}', 200);
		$transport::respond('{}', 500);
		$this->context->isFalse($adapter::create_index('activate-fails', 'sku'));

		$io::write($applicationRoot.'/existing.zip', 'stale archive');
		$transport::reset();
		$transport::respond('{"session":"/application/v2/tenant/default/session/44"}', 200);
		$transport::respond('{}', 202);
		$this->context->isTrue($adapter::create_index('existing', 'sku'));
	}

	/** Proves all legacy Vespa configuration aliases and retry-delay scheduling. */
	public function assertVespaLegacyConfigurationContract(string $applicationRoot): void {
		$this->defineConfiguration(['vespa'=>[
			'query_url'=>'https://legacy-vespa-query.example.test',
			'config_url'=>'https://legacy-vespa-config.example.test',
			'application_root'=>$applicationRoot,
			'archive_class'=>'dataphyre\\FulltextVespaArchiveFake',
			'prepare_max_attempts'=>2,
			'prepare_retry_delay_seconds'=>1,
			'http_timeout_seconds'=>4,
		]]);
		$this->loadAlgorithmSource('external_engines/vespa.php');
		$adapter='dataphyre\\fulltext_engine\\vespa';
		$transport='dataphyre\\fulltext_engine\\FulltextCurlTransport';
		$io='dataphyre\\FulltextVespaCoreIo';
		$archive='dataphyre\\FulltextVespaArchiveFake';

		$transport::reset();
		$transport::respond('{}', 201);
		$this->context->isTrue($adapter::add('legacy', ['name'=>'Shoe'], 'sku', 'one', 'en'));
		$this->context->startsWith('https://legacy-vespa-query.example.test/', $transport::lastRequest()['url']);
		$transport::reset();
		$transport::respond('{}', 202);
		$this->context->isTrue($adapter::delete_index('legacy'));
		$this->context->startsWith('https://legacy-vespa-config.example.test/', $transport::lastRequest()['url']);

		$io::reset();
		$archive::reset();
		$transport::reset();
		$transport::fail('first prepare fails');
		$transport::respond('{"session":"/application/v2/tenant/default/session/legacy"}', 200);
		$transport::respond('{}', 200);
		$this->context->isTrue($adapter::create_index('legacy', 'sku'));
		$this->context->same([1], $transport::sleepSeconds(), 'Retry delay is scheduled through the namespaced clock seam.');
	}

	/** Proves a missing configured archive implementation fails closed. */
	public function assertVespaMissingArchiveContract(string $applicationRoot): void {
		$this->defineConfiguration(['external_engines'=>['vespa'=>[
			'application_root'=>$applicationRoot,
			'archive_class'=>'DataphyreMissingArchiveImplementation',
		]]]);
		$this->loadAlgorithmSource('external_engines/vespa.php');
		$io='dataphyre\\FulltextVespaCoreIo';
		$io::reset();
		$adapter='dataphyre\\fulltext_engine\\vespa';
		$this->context->isFalse($adapter::create_index('missing-archive', 'sku'));
	}

	private function defineConfiguration(array $configuration): void {
		if(!defined('DP_FULLTEXT_ENGINE_CFG')){
			define('DP_FULLTEXT_ENGINE_CFG', $configuration);
		}
	}

	/** Proves that the module DSL rejects malformed sources and lexical declarations. */
	public function assertDslFailureContracts(): void {
		$this->context->throws(fn()=>$this->stopwords('english'), InvalidArgumentException::class);
		$this->context->throws(fn()=>$this->loadAlgorithmSource('../outside.php'), InvalidArgumentException::class);
		$this->context->throws(fn()=>$this->loadAlgorithmSource('kernel/missing.php'), RuntimeException::class);

		$workspace=$this->context->workspace('fulltext-dsl-failures');
		$this->context->throws(
			fn()=>new FulltextStopwordContract($this->context, 'xx', $workspace->path('missing.php')),
			RuntimeException::class,
			'Missing stopword declaration'
		);
		$notArray=$workspace->file('not-array.php', "<?php\n\$stopwords='invalid';\n");
		$this->context->throws(
			fn()=>new FulltextStopwordContract($this->context, 'xx', $notArray),
			RuntimeException::class,
			'must assign an array'
		);
		$invalidWord=$workspace->file('invalid-word/xx_stopwords.php', "<?php\n\$stopwords=['valid', ''];\n");
		$invalidWordContract=new FulltextStopwordContract($this->context, 'xx', $invalidWord);
		$this->context->throws(fn()=>$invalidWordContract->matches(2, 'unused'), \Throwable::class);
		$invalidUtf8=$workspace->file('invalid-utf8/xx_stopwords.php', "<?php\n\$stopwords=[chr(177).'1'];\n");
		$invalidUtf8Contract=new FulltextStopwordContract($this->context, 'xx', $invalidUtf8);
		$this->context->throws(fn()=>$invalidUtf8Contract->matches(1, 'unused'), RuntimeException::class, 'canonically encoded');
		$valid=$workspace->file('valid.php', "<?php\n\$stopwords=['one'];\n");
		$this->context->same(['one'], (new FulltextStopwordContract($this->context, 'xx', $valid))->words());
	}

	/** Proves the framework entrypoint publishes its idempotent bootstrap marker. */
	public function assertFrameworkBootstrapContract(): void {
		$this->loadAlgorithmSource('Framework/Bootstrap.php');
		$this->context->isTrue(defined('DATAPHYRE_FULLTEXT_ENGINE_FRAMEWORK_BOOTSTRAPPED'));
		$this->context->isTrue(DATAPHYRE_FULLTEXT_ENGINE_FRAMEWORK_BOOTSTRAPPED);
		$this->loadAlgorithmSource('Framework/Bootstrap.php');
		$this->context->isTrue(DATAPHYRE_FULLTEXT_ENGINE_FRAMEWORK_BOOTSTRAPPED, 'Bootstrap remains idempotent.');
	}

	/** @param array{options:array<int,mixed>} $request */
	private function decodeRequestBody(array $request): array {
		$body=(string)($request['options'][CURLOPT_POSTFIELDS] ?? '');
		$decoded=json_decode($body, true);
		if(!is_array($decoded)){
			throw new RuntimeException('Fulltext adapter emitted a non-object JSON request.');
		}
		return $decoded;
	}

	/**
	 * Proves exact public stems for a named branch corpus without exposing loops
	 * or assertion plumbing in the scenario.
	 *
	 * @param array<string,string> $examples
	 */
	public function assertStemmerContract(string $language, array $examples): void {
		$this->loadAlgorithmSource('stemmers/'.$language.'_stemmer.php');
		$class='dataphyre\\fulltext_engine\\stemming\\'.$language;
		if($language==='ar'){
			new $class();
		}
		foreach($examples as $word=>$expected){
			$this->context->same($expected, (string)$class::stem($word), "{$language} stem for {$word}");
		}
		$firstWord=(string)array_key_first($examples);
		$this->context->same(
			(string)$class::stem($firstWord),
			(string)$class::stem($firstWord),
			'Stemming remains deterministic across repeated calls.'
		);
		if($language==='ar'){
			$this->assertArabicAffixDecisionContract($class);
		}
		if($language==='fr'){
			$this->assertFrenchSuffixDecisionContract($class);
		}
	}

	/** Keeps Arabic affix-boundary branch setup behind the module DSL. */
	private function assertArabicAffixDecisionContract(string $class): void {
		$access=$this->context->nonPublic($class);
		foreach([
			'_nounMay'=>'az', '_nounPre'=>'a', '_nounPost'=>'z',
			'_nounMaxPre'=>4, '_nounMaxPost'=>6, '_nounMinStem'=>2,
			'_verbMay'=>'', '_verbPre'=>'', '_verbPost'=>'',
			'_verbMaxPre'=>4, '_verbMaxPost'=>6, '_verbMinStem'=>2,
		] as $property=>$value){
			$access->replacePropertyForTest($property, $value);
		}
		$this->context->same('ROOT', (string)$class::stem('aaROOTzz'), 'The shorter noun-boundary stem wins.');
		$this->context->same('aaaROOTzzzzzz', $access->invoke('roughStem', 'aaaaaROOTzzzzzzzz', 'az', 'a', 'z', 2, 2, 2));
		$this->context->same('bROOT', $access->invoke('roughStem', 'abROOTzz', 'abz', 'a', 'z', 4, 6, 2));
		$this->context->same('ROOTy', $access->invoke('roughStem', 'aaROOTyz', 'ayz', 'a', 'z', 4, 6, 2));
		$this->context->isNull($access->invoke('roughStem', '', 'az', 'a', 'z', 4, 6, 2));
	}

	/**
	 * Executes the French Snowball suffix table through TestKit's standardized
	 * non-public seam. Public word examples remain above; these rows name and
	 * isolate legacy region decisions that cannot be expressed with valid modern
	 * UTF-8 because the historic source table contains mojibake suffix literals.
	 */
	private function assertFrenchSuffixDecisionContract(string $class): void {
		$allRegions=[0, 0, 0];
		foreach([
			['step1', 'ababances', 'abab', 3, $allRegions],
			['step1', 'ababicatrices', 'abab', 3, $allRegions],
			['step1', 'abicatrice', 'abiqU', 3, [0, 0, 3]],
			['step1', 'abablogies', 'abablog', 3, $allRegions],
			['step1', 'ababusions', 'ababu', 3, $allRegions],
			['step1', 'ababences', 'ababent', 3, $allRegions],
			['step1', 'ababissements', 'abab', 3, $allRegions],
			['step1', 'ababements', 'abab', 3, $allRegions],
			['step1', 'ababativements', 'abab', 3, $allRegions],
			['step1', 'ababeusements', 'abab', 3, $allRegions],
			['step1', 'abeusements', 'abeux', 3, [0, 0, 99]],
			['step1', 'ababablements', 'abab', 3, $allRegions],
			['step1', 'abièrements', 'abi', 3, [0, 0, 99]],
			['step1', 'ababités', 'abab', 3, $allRegions],
			['step1', 'abababilités', 'abab', 3, $allRegions],
			['step1', 'ababilités', 'ababl', 3, [0, 0, 3]],
			['step1', 'ababicités', 'abab', 3, $allRegions],
			['step1', 'abicités', 'abiqU', 3, [0, 0, 3]],
			['step1', 'ababivités', 'abab', 3, $allRegions],
			['step1', 'ababifs', 'abab', 3, $allRegions],
			['step1', 'ababicatifs', 'abab', 3, $allRegions],
			['step1', 'abicatifs', 'abiqU', 3, [0, 0, 3]],
			['step1', 'ababeaux', 'ababeau', 3, $allRegions],
			['step1', 'ababaux', 'ababal', 3, $allRegions],
			['step1', 'ababeuses', 'abab', 3, $allRegions],
			['step1', 'abeuses', 'abeux', 3, [0, 0, 99]],
			['step1', 'ababamment', 'ababant', 2, $allRegions],
			['step1', 'ababemment', 'ababent', 2, $allRegions],
			['step1', 'abament', 'aba', 2, $allRegions],
			['step1', 'branchless', 'branchless', 2, $allRegions],
			['step2a', 'abissions', 'ab', true, $allRegions],
			['step2a', 'aaissions', 'aaissions', false, $allRegions],
			['step2a', 'branchless', 'branchless', false, $allRegions],
			['step2b', 'ababerais', 'abab', true, $allRegions],
			['step2b', 'abeantes', 'ab', true, $allRegions],
			['step2b', 'ababantes', 'abab', true, $allRegions],
			['step2b', 'ababions', 'abab', true, $allRegions],
			['step2b', 'branchless', 'branchless', false, $allRegions],
			['step4', 'ababs', 'abab', false, $allRegions],
			['step4', 'abatsion', 'abats', true, $allRegions],
			['step4', 'abarion', 'abarion', true, $allRegions],
			['step4', 'ababière', 'ababi', true, $allRegions],
			['step4', 'ababe', 'abab', true, $allRegions],
			['step4', 'ababguë', 'ababgu', true, $allRegions],
			['step4', 'branchless', 'branchless', false, $allRegions],
			['step5', 'ababenn', 'ababen', null, $allRegions],
		] as [$method, $word, $expectedWord, $expectedResult, $regions]){
			$this->assertFrenchStep($class, $method, $word, $expectedWord, $expectedResult, $regions);
		}
	}

	/** @param array{0:int,1:int,2:int} $regions */
	private function assertFrenchStep(
		string $class,
		string $method,
		string $word,
		string $expectedWord,
		mixed $expectedResult,
		array $regions
	): void {
		$stemmer=new $class();
		$access=$this->context->nonPublic($stemmer)
			->writeProperty('word', $word)
			->writeProperty('plainVowels', 'aeiouy')
			->writeProperty('rvIndex', $regions[0])
			->writeProperty('r1Index', $regions[1])
			->writeProperty('r2Index', $regions[2]);
		$result=$access->invoke($method);
		$this->context->same($expectedWord, $access->readProperty('word'), "French {$method} word transition for {$word}");
		if($expectedResult!==null){
			$this->context->same($expectedResult, $result, "French {$method} control result for {$word}");
		}
	}
}

/** Fluent assertion object for one language's declaration file. */
final class FulltextStopwordContract {

	/** @var list<string> */
	private array $words;

	public function __construct(
		private TestContext $context,
		private string $language,
		private string $path
	) {
		$this->words=$this->load();
	}

	/**
	 * Asserts both declaration schema and canonical content identity.
	 *
	 * @return $this
	 */
	public function matches(int $count, string $fingerprint, int $duplicateCount=0): self {
		$this->context->same($this->language.'_stopwords.php', basename($this->path));
		$this->context->isTrue(array_is_list($this->words), 'Stopwords are declared as a list.');
		$this->context->isTrue($this->containsOnlyNonEmptyStrings(), 'Every stopword is a non-empty string.');
		$this->context->same($count, count($this->words), 'Stopword count is an intentional content contract.');
		$this->context->same($duplicateCount, count($this->words)-count(array_unique($this->words)), 'Intentional duplicate count must remain explicit.');
		$this->context->same($fingerprint, $this->fingerprint(), 'Canonical lexical content changed.');
		return $this;
	}

	/** @return list<string> */
	public function words(): array {
		return $this->words;
	}

	/** @return list<string> */
	private function load(): array {
		if(!is_file($this->path)){
			throw new RuntimeException('Missing stopword declaration: '.$this->path);
		}
		$words=(static function(string $path): mixed {
			$stopwords=null;
			require $path;
			return $stopwords;
		})($this->path);
		if(!is_array($words)){
			throw new RuntimeException('Stopword declaration must assign an array: '.$this->path);
		}
		return array_values($words);
	}

	private function containsOnlyNonEmptyStrings(): bool {
		foreach($this->words as $word){
			if(!is_string($word) || $word===''){
				return false;
			}
		}
		return true;
	}

	private function fingerprint(): string {
		$json=json_encode($this->words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if(!is_string($json)){
			throw new RuntimeException('Stopword declaration could not be canonically encoded.');
		}
		return hash('sha256', $json);
	}
}
