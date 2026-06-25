<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../Framework/SearchHit.php';
require_once __DIR__.'/../Framework/SearchResults.php';
require_once __DIR__.'/../Framework/IndexDefinition.php';
require_once __DIR__.'/../Framework/IndexSyncReport.php';
require_once __DIR__.'/../Framework/HydratedSearchHit.php';
require_once __DIR__.'/../Framework/HydratedSearchResults.php';
require_once __DIR__.'/../Framework/SearchManager.php';
require_once __DIR__.'/../Framework/Index.php';
require_once __DIR__.'/../Framework/Query.php';
require_once __DIR__.'/../Framework/Search.php';

function fulltext_search_results_json(array $response): string {
	return json_encode(\Dataphyre\FulltextEngine\SearchResults::fromKernelResponse('products', $response), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function fulltext_search_results_summary_json(array $response): string {
	$results=\Dataphyre\FulltextEngine\SearchResults::fromKernelResponse('products', $response);
	return json_encode([
		'index'=>$results->indexName(),
		'total'=>$results->total(),
		'hit_count'=>$results->hitCount(),
		'ids'=>$results->ids(),
		'scores'=>$results->scores(),
		'first'=>$results->first()?->toArray(),
		'is_empty'=>$results->isEmpty(),
		'is_not_empty'=>$results->isNotEmpty(),
	], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function fulltext_index_definition_json(array $definition): string {
	return json_encode(\Dataphyre\FulltextEngine\IndexDefinition::fromArray($definition), JSON_UNESCAPED_SLASHES);
}

function fulltext_index_sync_report_json(): string {
	$created=\Dataphyre\FulltextEngine\IndexDefinition::fromArray([
		'name'=>'products',
		'type'=>'json',
		'primary_key'=>'sku',
	]);
	$current=\Dataphyre\FulltextEngine\IndexDefinition::fromArray([
		'name'=>'orders',
		'type'=>'sql',
		'primary_key_column_name'=>'legacy_id',
	]);
	$desired=\Dataphyre\FulltextEngine\IndexDefinition::fromArray([
		'name'=>'orders',
		'type'=>'sql',
		'primary_key_column_name'=>'id',
	]);
	$report=new \Dataphyre\FulltextEngine\IndexSyncReport();
	$report->addCreated($created);
	$report->addUnchanged($created);
	$report->addMismatched($current, $desired);
	$report->addPruned($current);
	$report->addFailed('archive', 'missing primary key');
	return json_encode($report, JSON_UNESCAPED_SLASHES);
}

function fulltext_hydrated_results_json(): string {
	$results=\Dataphyre\FulltextEngine\SearchResults::fromKernelResponse('products', [
		'results'=>[
			['sku-1'=>0.75],
			['sku-2'=>0.5],
		],
		'count'=>5,
		'certainty'=>0.61,
		'time'=>0.02,
	]);
	$hits=[
		new \Dataphyre\FulltextEngine\HydratedSearchHit($results->hits()[0], ['sku'=>'sku-1'], true),
		new \Dataphyre\FulltextEngine\HydratedSearchHit($results->hits()[1], null, false),
	];
	$hydrated=\Dataphyre\FulltextEngine\HydratedSearchResults::fromResults($results, $hits);
	return json_encode([
		'payload'=>$hydrated->toArray(),
		'documents'=>$hydrated->documents(),
		'missing_ids'=>$hydrated->missingIds(),
	], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function fulltext_query_execution_state_json(): string {
	\Dataphyre\FulltextEngine\Search::flush();
	$query=\Dataphyre\FulltextEngine\Search::query('products')
		->where('title', 'red shoes')
		->terms(['description'=>'leather', 'price'=>120])
		->language('fr')
		->limit(12)
		->boolean(false)
		->threshold(0.42)
		->algorithms('levenshtein');

	return json_encode($query->executionState(), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

function fulltext_query_from_execution_state_json(array $state): string {
	\Dataphyre\FulltextEngine\Search::flush();
	$query=\Dataphyre\FulltextEngine\Query::fromExecutionState($state);
	return json_encode([
		'index'=>$query->index(),
		'criteria'=>$query->criteria(),
		'fingerprint_payload'=>$query->fingerprintPayload(),
		'fingerprint'=>$query->fingerprint(),
	], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}
