<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fulltext_external_engine_test_helpers.php';

suite('Fulltext compatible HTTP engines')
	->contract('fulltext.compatible-engine-adapter', 1)
	->layer('unit')
	->risk('critical')
	->watches('module:fulltext_engine')
	->through('request-shaping', 'transport-failure', 'result-projection', 'document-lifecycle')
	->isolation('case')
	->tag('fulltext', 'external-engine', 'http-contract')
	->group('framework-coverage');

dataset('compatible engine source slots', [
	'Elasticsearch adapter'=>['external_engines/elastic.php'],
	'OpenSearch adapter'=>['external_engines/opensearch.php'],
	'legacy Solr compatibility slot'=>['external_engines/solr.php'],
]);

test('each compatible adapter preserves the complete HTTP and result contract', static function(Context $t, string $source): void {
	$t->fulltext()->assertCompatibleHttpAdapterContract($source, [
		'external_engines'=>['elastic'=>['url'=>' https://search.example.test/ ']],
	]);
})->with('compatible engine source slots')->maxMillis(2000);

dataset('compatible engine endpoint aliases', [
	'Elasticsearch legacy elastic key'=>['external_engines/elastic.php', ['elastic'=>['url'=>'https://legacy-elastic.example.test']], 'https://legacy-elastic.example.test'],
	'Elasticsearch nested elasticsearch key'=>['external_engines/elastic.php', ['external_engines'=>['elasticsearch'=>['url'=>'https://nested-elasticsearch.example.test']]], 'https://nested-elasticsearch.example.test'],
	'Elasticsearch legacy elasticsearch key'=>['external_engines/elastic.php', ['elasticsearch'=>['url'=>'https://legacy-elasticsearch.example.test']], 'https://legacy-elasticsearch.example.test'],
	'Elasticsearch blank endpoint fallback'=>['external_engines/elastic.php', ['external_engines'=>['elastic'=>['url'=>' ']]], 'http://127.0.0.1:9200'],
	'OpenSearch legacy elastic key'=>['external_engines/opensearch.php', ['elastic'=>['url'=>'https://legacy-elastic.example.test']], 'https://legacy-elastic.example.test'],
	'OpenSearch nested elasticsearch key'=>['external_engines/opensearch.php', ['external_engines'=>['elasticsearch'=>['url'=>'https://nested-elasticsearch.example.test']]], 'https://nested-elasticsearch.example.test'],
	'OpenSearch legacy elasticsearch key'=>['external_engines/opensearch.php', ['elasticsearch'=>['url'=>'https://legacy-elasticsearch.example.test']], 'https://legacy-elasticsearch.example.test'],
	'OpenSearch blank endpoint fallback'=>['external_engines/opensearch.php', ['external_engines'=>['elastic'=>['url'=>' ']]], 'http://127.0.0.1:9200'],
	'Solr slot legacy elastic key'=>['external_engines/solr.php', ['elastic'=>['url'=>'https://legacy-elastic.example.test']], 'https://legacy-elastic.example.test'],
	'Solr slot nested elasticsearch key'=>['external_engines/solr.php', ['external_engines'=>['elasticsearch'=>['url'=>'https://nested-elasticsearch.example.test']]], 'https://nested-elasticsearch.example.test'],
	'Solr slot legacy elasticsearch key'=>['external_engines/solr.php', ['elasticsearch'=>['url'=>'https://legacy-elasticsearch.example.test']], 'https://legacy-elasticsearch.example.test'],
	'Solr slot blank endpoint fallback'=>['external_engines/solr.php', ['external_engines'=>['elastic'=>['url'=>' ']]], 'http://127.0.0.1:9200'],
]);

test('each compatible adapter resolves every documented endpoint alias', static function(Context $t, string $source, array $configuration, string $expectedBaseUrl): void {
	$t->fulltext()->assertCompatibleEndpointAliasContract($source, $configuration, $expectedBaseUrl);
})->with('compatible engine endpoint aliases')->maxMillis(1000);
