<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;

/**
 * Static facade for the framework fulltext search manager.
 *
 * Search exposes concise static entry points for index handles, query builders,
 * definition inspection, resolver registration, raw and typed search execution,
 * hydration, index lifecycle operations, synchronization, document mutation,
 * tokenization, stemming, stopword removal, and relevance scoring. All stateful
 * behavior is delegated to SearchManager.
 */
final class Search {

	/**
	 * Returns the shared fulltext search manager used by the static facade.
	 *
	 * The manager owns per-process index handles, resolver sources, normalized
	 * resolver instances, defaults, and all calls into the snake_case kernel.
	 *
	 * @return SearchManager Shared manager instance for the current process.
	 */
	public static function manager(): SearchManager {
		return SearchManager::instance();
	}

	/**
	 * Clears the shared manager and its cached index/resolver state.
	 *
	 * Use this after changing fulltext configuration in tests or long-running workers
	 * so later facade calls rebuild manager state from configuration and the kernel.
	 *
	 * @return void
	 */
	public static function flush(): void {
		SearchManager::flush();
	}

	/**
	 * Returns a framework Index handle for a non-empty index name.
	 *
	 * The static facade delegates validation and memoization to SearchManager.
	 *
	 * @param string $name Runtime index name as configured in fulltext_engine.
	 * @return Index Cached or newly-created index handle.
	 */
	public static function index(string $name): Index {
		return self::manager()->index($name);
	}

	/**
	 * Creates a fluent query builder bound to an index.
	 *
	 * Query execution still flows through SearchManager so language, limit, boolean
	 * mode, threshold, and algorithm defaults are applied consistently.
	 *
	 * @param string $indexName Runtime index name to search.
	 * @return Query Query builder for the index.
	 */
	public static function query(string $indexName): Query {
		return self::manager()->query($indexName);
	}

	/**
	 * Returns valid typed index definitions known to the fulltext kernel.
	 *
	 * Invalid kernel rows are filtered by SearchManager and results are sorted for
	 * deterministic diagnostics output.
	 *
	 * @return array<int, IndexDefinition> Valid index definitions.
	 */
	public static function definitions(): array {
		return self::manager()->definitions();
	}

	/**
	 * Returns one valid typed index definition by name.
	 *
	 * Missing indexes, non-array kernel responses, and invalid definition shapes are
	 * normalized to null.
	 *
	 * @param string $indexName Runtime index name to inspect.
	 * @return IndexDefinition|null Typed definition when available and valid.
	 */
	public static function definition(string $indexName): ?IndexDefinition {
		return self::manager()->definition($indexName);
	}

	/**
	 * Checks whether the fulltext kernel reports an index as existing.
	 *
	 * This is an existence probe only; it does not validate definition shape.
	 *
	 * @param string $indexName Runtime index name to probe.
	 * @return bool True when the kernel knows the index.
	 */
	public static function hasIndex(string $indexName): bool {
		return self::manager()->hasIndex($indexName);
	}

	/**
	 * Returns the document resolver configured for an index.
	 *
	 * Explicit resolver registrations override configuration. Missing resolver sources
	 * return null; invalid configured sources may throw from SearchManager.
	 *
	 * @param string $indexName Runtime index name whose hits should hydrate.
	 * @return DocumentResolver|null Resolver instance or null when none is configured.
	 */
	public static function resolver(string $indexName): ?DocumentResolver {
		return self::manager()->resolver($indexName);
	}

	/**
	 * Registers or replaces a resolver source for one index.
	 *
	 * Accepted resolver sources include DocumentResolver instances, callables, class
	 * names, and table/repository/callback resolver arrays understood by SearchManager.
	 *
	 * @param string $indexName Runtime index name whose resolver is being set.
	 * @param mixed $resolver Resolver source accepted by SearchManager.
	 * @return void
	 */
	public static function extendResolver(string $indexName, mixed $resolver): void {
		self::manager()->extendResolver($indexName, $resolver);
	}

	/**
	 * Registers a table-backed resolver for search-result hydration.
	 *
	 * The generated resolver configuration is normalized later by SearchManager and
	 * resolved into a TableDocumentResolver when hydration is requested.
	 *
	 * @param string $indexName Runtime index name whose hits use this resolver.
	 * @param string $table SQL table used to load documents.
	 * @param string $primaryKey Primary key column used to match hit IDs.
	 * @param array|string $columns Columns requested from the table.
	 * @param bool|array|string|null $caching SQL cache hint for resolver queries.
	 * @param mixed $mapper Optional mapper applied to resolved rows.
	 * @return void
	 */
	public static function useTableResolver(
		string $indexName,
		string $table,
		string $primaryKey='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): void {
		self::extendResolver($indexName, [
			'driver'=>'table',
			'table'=>$table,
			'primary_key'=>$primaryKey,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
	}

	/**
	 * Registers a repository-backed resolver for search-result hydration.
	 *
	 * The generated resolver configuration is normalized later by SearchManager and
	 * resolved into a RepositoryDocumentResolver when hydration is requested.
	 *
	 * @param string $indexName Runtime index name whose hits use this resolver.
	 * @param string $repository Repository class used to load documents.
	 * @param string $primaryKey Primary key column used to match hit IDs.
	 * @param array|string $columns Columns requested from the repository.
	 * @param bool|array|string|null $caching SQL cache hint for resolver queries.
	 * @param mixed $mapper Optional mapper applied to resolved records.
	 * @return void
	 */
	public static function useRepositoryResolver(
		string $indexName,
		string $repository,
		string $primaryKey='id',
		array|string $columns='*',
		bool|array|string|null $caching=false,
		mixed $mapper=null
	): void {
		self::extendResolver($indexName, [
			'driver'=>'repository',
			'repository'=>$repository,
			'primary_key'=>$primaryKey,
			'columns'=>$columns,
			'caching'=>$caching,
			'mapper'=>$mapper,
		]);
	}

	/**
	 * Executes a typed fulltext search through the shared manager.
	 *
	 * Criteria are passed to the kernel unchanged while optional language, result
	 * limit, boolean mode, threshold, and algorithms are normalized by SearchManager.
	 *
	 * @param string $indexName Runtime index name to search.
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Kernel search criteria keyed by indexed field.
	 * @param string|null $language Optional tokenization language override.
	 * @param int|null $maxResults Optional maximum result count.
	 * @param bool|null $booleanMode Optional boolean-mode override.
	 * @param float|null $threshold Optional minimum score threshold.
	 * @param string|null $forcedAlgorithms Optional algorithm override.
	 * @return SearchResults Typed search result collection.
	 */
	public static function search(
		string $indexName,
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): SearchResults {
		return self::manager()->search($indexName, $criteria, $language, $maxResults, $booleanMode, $threshold, $forcedAlgorithms);
	}

	/**
	 * Executes a fulltext search and returns the raw kernel response.
	 *
	 * This is the diagnostics and low-level compatibility path. Manager defaults are
	 * still applied before dispatch.
	 *
	 * @param string $indexName Runtime index name to search.
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Kernel search criteria keyed by indexed field.
	 * @param string|null $language Optional tokenization language override.
	 * @param int|null $maxResults Optional maximum result count.
	 * @param bool|null $booleanMode Optional boolean-mode override.
	 * @param float|null $threshold Optional minimum score threshold.
	 * @param string|null $forcedAlgorithms Optional algorithm override.
	 * @return bool|array<int|string,mixed> Raw kernel response from find_in_index().
	 */
	public static function rawSearch(
		string $indexName,
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): bool|array {
		return self::manager()->rawSearch($indexName, $criteria, $language, $maxResults, $booleanMode, $threshold, $forcedAlgorithms);
	}

	/**
	 * Resolves documents for search hits using a configured or supplied resolver.
	 *
	 * Hit order and scoring metadata are preserved in the hydrated result set. Missing
	 * documents stay represented as unresolved hydrated hits.
	 *
	 * @param SearchResults $results Search result collection to hydrate.
	 * @param mixed $resolver Optional resolver source overriding registered config.
	 * @return HydratedSearchResults Results paired with resolved documents.
	 */
	public static function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		return self::manager()->hydrate($results, $resolver);
	}

	/**
	 * Creates a fulltext index through the kernel.
	 *
	 * Type and language defaults are supplied by SearchManager when arguments are
	 * omitted or blank.
	 *
	 * @param string $indexName Runtime index name to create.
	 * @param string $primaryKeyColumnName Column used as the document identifier.
	 * @param string|null $type Optional index storage type override.
	 * @param string|null $language Optional language metadata override.
	 * @return bool True when the kernel creates the index.
	 */
	public static function createIndex(
		string $indexName,
		string $primaryKeyColumnName,
		?string $type=null,
		?string $language=null
	): bool {
		return self::manager()->createIndex($indexName, $primaryKeyColumnName, $type, $language);
	}

	/**
	 * Deletes a fulltext index through the kernel.
	 *
	 * Cached facade handles remain lightweight command surfaces and are not removed
	 * automatically.
	 *
	 * @param string $indexName Runtime index name to delete.
	 * @return bool True when the kernel deletes the index.
	 */
	public static function deleteIndex(string $indexName): bool {
		return self::manager()->deleteIndex($indexName);
	}

	/**
	 * Ensures that an index exists with the desired metadata.
	 *
	 * Missing indexes are created. Existing mismatched indexes are reported as false
	 * rather than being migrated destructively.
	 *
	 * @param string $indexName Runtime index name to verify.
	 * @param string $primaryKeyColumnName Column used as the document identifier.
	 * @param string|null $type Optional desired index storage type.
	 * @param string|null $language Optional desired language metadata.
	 * @return bool True when the desired index exists or was created successfully.
	 */
	public static function ensureIndex(
		string $indexName,
		string $primaryKeyColumnName,
		?string $type=null,
		?string $language=null
	): bool {
		return self::manager()->ensureIndex($indexName, $primaryKeyColumnName, $type, $language);
	}

	/**
	 * Synchronizes desired index definitions with current kernel state.
	 *
	 * Missing desired indexes are created, matching ones are reported unchanged,
	 * mismatches are reported without destructive migration, and pruneMissing can
	 * delete current indexes that are absent from desired definitions.
	 *
	 * @param array<int|string,IndexDefinition|array<string,mixed>> $definitions Desired definitions.
	 * @param bool $pruneMissing Delete current indexes missing from desired state.
	 * @return IndexSyncReport Structured synchronization report.
	 */
	public static function sync(array $definitions, bool $pruneMissing=false): IndexSyncReport {
		return self::manager()->sync($definitions, $pruneMissing);
	}

	/**
	 * Synchronizes indexes against framework configuration.
	 *
	 * Desired definitions are read from DP_FULLTEXT_ENGINE_CFG through SearchManager.
	 *
	 * @param bool $pruneMissing Delete current indexes missing from configuration.
	 * @return IndexSyncReport Structured synchronization report.
	 */
	public static function syncConfigured(bool $pruneMissing=false): IndexSyncReport {
		return self::manager()->syncConfigured($pruneMissing);
	}

	/**
	 * Adds one document to a fulltext index.
	 *
	 * The values array must include the index primary key column plus searchable field
	 * values expected by the kernel definition.
	 *
	 * @param string $indexName Runtime index name receiving the document.
	 * @param array<string,scalar|null> $values Field values for the indexed document, including the index primary key.
	 * @param string|null $language Optional tokenization language override.
	 * @return bool True when the kernel accepts the document.
	 */
	public static function add(string $indexName, array $values, ?string $language=null): bool {
		return self::manager()->add($indexName, $values, $language);
	}

	/**
	 * Updates one existing indexed document.
	 *
	 * The values array follows add() shape and identifies the document through the
	 * index primary key column.
	 *
	 * @param string $indexName Runtime index name containing the document.
	 * @param array<string,scalar|null> $values Updated field values for the indexed document, including the index primary key.
	 * @param string|null $language Optional tokenization language override.
	 * @return bool True when the kernel updates the document.
	 */
	public static function update(string $indexName, array $values, ?string $language=null): bool {
		return self::manager()->update($indexName, $values, $language);
	}

	/**
	 * Removes one indexed document by primary key value.
	 *
	 * @param string $indexName Runtime index name containing the document.
	 * @param string $primaryKeyValue Stored primary key value to remove.
	 * @return bool True when the kernel removes the document.
	 */
	public static function remove(string $indexName, string $primaryKeyValue): bool {
		return self::manager()->remove($indexName, $primaryKeyValue);
	}

	/**
	 * Tokenizes text using the fulltext language pipeline.
	 *
	 * The returned data is produced by the kernel and may include normalized terms,
	 * stems, or language metadata depending on backend configuration.
	 *
	 * @param string $text Text to split into searchable tokens.
	 * @param string|null $language Optional tokenization language override.
	 * @return list<string>|array<string,mixed> Kernel token data.
	 */
	public static function tokenize(string $text, ?string $language=null): array {
		return self::manager()->tokenize($text, $language);
	}

	/**
	 * Removes language stopwords from a query string.
	 *
	 * @param string $query Raw search query.
	 * @param string|null $language Optional language override.
	 * @return string Query after kernel stopword filtering.
	 */
	public static function removeStopwords(string $query, ?string $language=null): string {
		return self::manager()->removeStopwords($query, $language);
	}

	/**
	 * Applies the fulltext stemming pipeline to a query string.
	 *
	 * @param string $query Query text to stem.
	 * @param string|null $language Optional language override.
	 * @return string Stemmed query returned by the kernel.
	 */
	public static function applyStemming(string $query, ?string $language=null): string {
		return self::manager()->applyStemming($query, $language);
	}

	/**
	 * Computes a relevance score for one indexed value and query value.
	 *
	 * When raw search value is omitted, the normalized search value is reused. Boolean
	 * mode and algorithm options follow the same defaults as search().
	 *
	 * @param string $indexValue Indexed text value to compare.
	 * @param string $searchValue Normalized search value.
	 * @param string|null $searchValueRaw Original user query, when different.
	 * @param string|null $language Optional scoring language override.
	 * @param bool|null $booleanMode Optional boolean-mode override.
	 * @param string|null $forcedAlgorithms Optional algorithm override.
	 * @return float Kernel relevance score.
	 */
	public static function score(
		string $indexValue,
		string $searchValue,
		?string $searchValueRaw=null,
		?string $language=null,
		?bool $booleanMode=null,
		?string $forcedAlgorithms=null
	): float {
		return self::manager()->score($indexValue, $searchValue, $searchValueRaw, $language, $booleanMode, $forcedAlgorithms);
	}
}
