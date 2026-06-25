<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\FulltextEngine;

use Dataphyre\FulltextEngine\Contracts\DocumentResolver;
use Dataphyre\FulltextEngine\Resolvers\CallbackDocumentResolver;
use Dataphyre\FulltextEngine\Resolvers\RepositoryDocumentResolver;
use Dataphyre\FulltextEngine\Resolvers\TableDocumentResolver;

/**
 * Object-oriented coordinator for Dataphyre fulltext indexes, queries, and hydration.
 *
 * SearchManager is the framework bridge over the snake_case fulltext kernel. It
 * centralizes default option normalization, index lifecycle commands, raw and
 * typed search execution, resolver registration, configured resolver expansion,
 * document hydration, and index-definition synchronization. The manager keeps
 * only per-process handles and resolver caches; durable index state remains in
 * the kernel backend.
 */
final class SearchManager {

	private static ?self $instance=null;

	/** @var array<string, Index> */
	private array $indexes=[];

	/** @var array<string, DocumentResolver> */
	private array $resolvers=[];

	/** @var array<string, mixed> */
	private array $resolverSources=[];

	/**
	 * Prevents direct construction outside the process-local singleton.
	 *
	 * SearchManager owns per-process caches for Index handles and
	 * resolver instances. Keeping construction private ensures callers share the
	 * same normalized framework state through instance() unless flush() is used.
	 */
	private function __construct(){}

	/**
	 * Returns the process-local fulltext search manager singleton.
	 *
	 * The manager owns cached Index handles, resolver sources, and normalized
	 * resolver instances for the current PHP process. Calling instance() is the
	 * canonical entry point for framework callers that need object-oriented
	 * access to the snake_case fulltext kernel.
	 *
	 * @return self Shared manager instance for the current process.
	 */
	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	/**
	 * Drops the cached manager and all per-process index and resolver state.
	 *
	 * Use this in tests or long-running workers after changing fulltext
	 * configuration so subsequent instance() calls rebuild manager state from
	 * DP_FULLTEXT_ENGINE_CFG and kernel index metadata.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$instance=null;
	}

	/**
	 * Returns a framework Index handle for a non-empty index name.
	 *
	 * Index handles are memoized by their trimmed name. The handle is a lightweight
	 * command surface; it does not create or validate the underlying kernel index
	 * until a lifecycle or mutation method is invoked.
	 *
	 * @param string $name Runtime index name as configured in fulltext_engine.
	 * @return Index Cached or newly-created framework index handle.
	 * @throws \InvalidArgumentException When the index name is empty after trimming.
	 */
	public function index(string $name): Index {
		$name=trim($name);
		if($name===''){
			throw new \InvalidArgumentException('Index name cannot be empty.');
		}
		if(isset($this->indexes[$name])){
			return $this->indexes[$name];
		}
		return $this->indexes[$name]=new Index($this, $name);
	}

	/**
	 * Creates a fluent query builder bound to the named fulltext index.
	 *
	 * The builder defers execution until its terminal operation calls back through
	 * this manager, so default language, limit, boolean mode, threshold, and
	 * algorithm selection are still applied consistently.
	 *
	 * @param string $indexName Runtime index name to search.
	 * @return Query Query builder associated with the requested index.
	 */
	public function query(string $indexName): Query {
		return $this->index($indexName)->query();
	}

	/**
	 * Loads valid kernel index definitions as typed framework objects.
	 *
	 * Invalid rows returned by the kernel are ignored so documentation, sync, and
	 * diagnostics consume only definitions with a name, type, and primary key.
	 * Returned definitions are sorted by index name for deterministic documentation
	 * and test output.
	 *
	 * @return array<int, IndexDefinition> Valid definitions known to the kernel.
	 */
	public function definitions(): array {
		$definitions=\dataphyre\fulltext_engine::get_index_definitions();
		$typed=[];
		foreach($definitions as $definition){
			if(is_array($definition)){
				$typedDefinition=IndexDefinition::fromArray($definition);
				if($typedDefinition->isValid()){
					$typed[]=$typedDefinition;
				}
			}
		}
		usort($typed, static fn(IndexDefinition $a, IndexDefinition $b): int => $a->name()<=>$b->name());
		return $typed;
	}

	/**
	 * Loads one valid kernel index definition by index name.
	 *
	 * A missing index, a non-array kernel response, or an invalid definition shape
	 * resolves to null instead of leaking kernel storage details into framework
	 * callers.
	 *
	 * @param string $indexName Runtime index name to inspect.
	 * @return IndexDefinition|null Typed definition when the kernel has a valid row.
	 */
	public function definition(string $indexName): ?IndexDefinition {
		$definition=\dataphyre\fulltext_engine::get_index_definition($indexName);
		if(!is_array($definition)){
			return null;
		}
		$typedDefinition=IndexDefinition::fromArray($definition);
		return $typedDefinition->isValid() ? $typedDefinition : null;
	}

	/**
	 * Checks whether the fulltext kernel currently knows the index.
	 *
	 * This is a direct existence probe; it does not validate definition shape or
	 * create missing indexes.
	 *
	 * @param string $indexName Runtime index name to probe.
	 * @return bool True when the kernel reports an existing index.
	 */
	public function hasIndex(string $indexName): bool {
		return \dataphyre\fulltext_engine::index_exists($indexName);
	}

	/**
	 * Resolves the document hydrator configured for an index.
	 *
	 * Explicit sources registered through extendResolver() take precedence over
	 * DP_FULLTEXT_ENGINE_CFG. A wildcard config entry under "*" is used when the
	 * index-specific source is absent. Normalized resolvers are cached by index
	 * name after successful construction.
	 *
	 * @param string $indexName Runtime index name whose hits need hydration.
	 * @return DocumentResolver|null Resolver instance, or null when no source exists.
	 * @throws \RuntimeException When a configured resolver source is invalid.
	 */
	public function resolver(string $indexName): ?DocumentResolver {
		$indexName=trim($indexName);
		if($indexName===''){
			return null;
		}
		if(isset($this->resolvers[$indexName])){
			return $this->resolvers[$indexName];
		}
		$source=$this->resolverSources[$indexName] ?? $this->configuredResolverSource($indexName);
		if($source===null){
			return null;
		}
		$resolver=$this->normalizeResolver($source, $indexName);
		if($resolver===null){
			return null;
		}
		return $this->resolvers[$indexName]=$resolver;
	}

	/**
	 * Registers or replaces the resolver source for one index.
	 *
	 * The source can be a DocumentResolver, callable, class name, or resolver
	 * configuration array. Any previously-normalized resolver for the index is
	 * discarded so the next resolver() call reflects the new source.
	 *
	 * @param string $indexName Runtime index name whose resolver is being extended.
	 * @param mixed $resolver Resolver source accepted by normalizeResolver().
	 * @return void
	 * @throws \InvalidArgumentException When the index name is empty after trimming.
	 */
	public function extendResolver(string $indexName, mixed $resolver): void {
		$indexName=trim($indexName);
		if($indexName===''){
			throw new \InvalidArgumentException('Index name cannot be empty.');
		}
		$this->resolverSources[$indexName]=$resolver;
		unset($this->resolvers[$indexName]);
	}

	/**
	 * Returns the configured fallback language for tokenization and scoring.
	 *
	 * Empty configuration values fall back to English so kernel calls always receive
	 * a concrete language code.
	 *
	 * @return string Non-empty language code used when callers omit language.
	 */
	public function defaultLanguage(): string {
		$language=trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_language'] ?? ''));
		return $language!=='' ? $language : 'en';
	}

	/**
	 * Returns the configured default maximum result count.
	 *
	 * Values below one are clamped to one to avoid impossible query limits reaching
	 * the kernel search functions.
	 *
	 * @return int Positive default result limit.
	 */
	public function defaultLimit(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['framework']['default_limit'] ?? 50));
	}

	/**
	 * Returns whether boolean query interpretation is enabled by default.
	 *
	 * The value comes from framework fulltext configuration and defaults to true to
	 * preserve the kernel's historical search behavior.
	 *
	 * @return bool Default boolean-mode flag for search and scoring calls.
	 */
	public function defaultBooleanMode(): bool {
		return (bool)(DP_FULLTEXT_ENGINE_CFG['framework']['default_boolean_mode'] ?? true);
	}

	/**
	 * Returns the configured minimum score threshold.
	 *
	 * Negative configuration values are clamped to zero so callers never pass an
	 * invalid lower bound into the scoring pipeline.
	 *
	 * @return float Non-negative score threshold.
	 */
	public function defaultThreshold(): float {
		return max(0.0, (float)(DP_FULLTEXT_ENGINE_CFG['framework']['default_threshold'] ?? 0.3));
	}

	/**
	 * Returns the configured algorithm override string.
	 *
	 * An empty string delegates algorithm selection to the kernel, while a non-empty
	 * value is forwarded to search and score operations after trimming.
	 *
	 * @return string Trimmed default algorithm list or an empty kernel-default marker.
	 */
	public function defaultAlgorithms(): string {
		return trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_algorithms'] ?? ''));
	}

	/**
	 * Returns the configured default index storage type.
	 *
	 * Empty configuration falls back to json. Non-empty values are lowercased before
	 * index creation and synchronization compare desired state.
	 *
	 * @return string Lowercase index type used for new definitions.
	 */
	public function defaultIndexType(): string {
		$type=strtolower(trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_index_type'] ?? '')));
		return $type!=='' ? $type : 'json';
	}

	/**
	 * Executes a normalized fulltext search and wraps the kernel response.
	 *
	 * Criteria are passed through unchanged to the kernel, while optional language,
	 * limit, boolean mode, threshold, and algorithm arguments are normalized against
	 * manager defaults. Kernel success, empty results, and failure responses are
	 * interpreted by SearchResults::fromKernelResponse().
	 *
	 * @param string $indexName Runtime index name to search.
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Kernel search criteria keyed by indexed field.
	 * @param string|null $language Language code override for tokenization.
	 * @param int|null $maxResults Maximum number of hits; null uses defaultLimit().
	 * @param bool|null $booleanMode Boolean-mode override; null uses defaultBooleanMode().
	 * @param float|null $threshold Minimum score override; null uses defaultThreshold().
	 * @param string|null $forcedAlgorithms Algorithm override; null uses defaultAlgorithms().
	 * @return SearchResults Typed search result collection for the requested index.
	 */
	public function search(
		string $indexName,
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): SearchResults {
		return SearchResults::fromKernelResponse(
			$indexName,
			\dataphyre\fulltext_engine::search(
				$indexName,
				$criteria,
				$this->normalizeLanguage($language),
				$this->normalizeLimit($maxResults),
				$this->normalizeBooleanMode($booleanMode),
				$this->normalizeThreshold($threshold),
				$this->normalizeAlgorithms($forcedAlgorithms)
			)
		);
	}

	/**
	 * Executes the kernel search API without wrapping the response.
	 *
	 * This is useful for diagnostics and compatibility with lower-level callers that
	 * need the raw array or false result produced by fulltext_engine::find_in_index().
	 * Framework defaults are still applied before dispatch.
	 *
	 * @param string $indexName Runtime index name to search.
	 * @param array<string,string|int|float|bool|list<string>|null> $criteria Kernel search criteria keyed by indexed field.
	 * @param string|null $language Language code override for tokenization.
	 * @param int|null $maxResults Maximum number of hits; null uses defaultLimit().
	 * @param bool|null $booleanMode Boolean-mode override; null uses defaultBooleanMode().
	 * @param float|null $threshold Minimum score override; null uses defaultThreshold().
	 * @param string|null $forcedAlgorithms Algorithm override; null uses defaultAlgorithms().
	 * @return bool|array<int|string,mixed> Raw kernel search response.
	 */
	public function rawSearch(
		string $indexName,
		array $criteria,
		?string $language=null,
		?int $maxResults=null,
		?bool $booleanMode=null,
		?float $threshold=null,
		?string $forcedAlgorithms=null
	): bool|array {
		return \dataphyre\fulltext_engine::find_in_index(
			$indexName,
			$criteria,
			$this->normalizeLanguage($language),
			$this->normalizeBooleanMode($booleanMode),
			$this->normalizeLimit($maxResults),
			$this->normalizeThreshold($threshold),
			$this->normalizeAlgorithms($forcedAlgorithms)
		);
	}

	/**
	 * Resolves documents for search hits and returns hydrated result objects.
	 *
	 * A resolver passed directly to the method overrides registered and configured
	 * sources. Hit order and score metadata are preserved; unresolved hits remain in
	 * the collection with their resolved flag set to false.
	 *
	 * @param SearchResults $results Search results whose hit IDs should be resolved.
	 * @param mixed $resolver Optional resolver source accepted by normalizeResolver().
	 * @return HydratedSearchResults Results paired with resolved documents.
	 * @throws \RuntimeException When no resolver exists or the resolver source is invalid.
	 */
	public function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		$indexName=$results->indexName();
		$definition=$this->definition($indexName);
		$resolverInstance=$resolver!==null
			? $this->normalizeResolver($resolver, $indexName)
			: $this->resolver($indexName);
		if($resolverInstance===null){
			throw new \RuntimeException("No fulltext document resolver is registered for index '{$indexName}'.");
		}
		$documents=$resolverInstance->resolve($results->ids(), $definition);
		$hydratedHits=[];
		foreach($results->hits() as $hit){
			$id=$hit->id();
			$resolved=array_key_exists($id, $documents);
			$document=$resolved ? $documents[$id] : null;
			$hydratedHits[]=new HydratedSearchHit($hit, $document, $resolved);
		}
		return HydratedSearchResults::fromResults($results, $hydratedHits, $definition);
	}

	/**
	 * Creates a kernel fulltext index using normalized type and language defaults.
	 *
	 * The type defaults to defaultIndexType() when omitted or blank; language is
	 * normalized through normalizeLanguage() before dispatching to the kernel.
	 *
	 * @param string $indexName Runtime index name to create.
	 * @param string $primaryKeyColumnName Column used as the document identifier.
	 * @param string|null $type Optional index storage type override.
	 * @param string|null $language Optional language override for index metadata.
	 * @return bool True when the kernel creates the index.
	 */
	public function createIndex(
		string $indexName,
		string $primaryKeyColumnName,
		?string $type=null,
		?string $language=null
	): bool {
		$type=$type!==null && trim($type)!=='' ? strtolower(trim($type)) : $this->defaultIndexType();
		return \dataphyre\fulltext_engine::create_index(
			$indexName,
			$primaryKeyColumnName,
			$type,
			$this->normalizeLanguage($language)
		);
	}

	/**
	 * Deletes a fulltext index through the kernel API.
	 *
	 * The method does not clear cached Index handles; those handles remain command
	 * surfaces and will observe the deleted state through later kernel calls.
	 *
	 * @param string $indexName Runtime index name to delete.
	 * @return bool True when the kernel deletes the index.
	 */
	public function deleteIndex(string $indexName): bool {
		return \dataphyre\fulltext_engine::delete_index($indexName);
	}

	/**
	 * Ensures that a valid index definition exists and matches desired metadata.
	 *
	 * Missing indexes are created. Existing indexes are not mutated; the method
	 * returns whether their current definition already matches the desired type,
	 * primary key column, and language.
	 *
	 * @param string $indexName Runtime index name to verify.
	 * @param string $primaryKeyColumnName Column used as the document identifier.
	 * @param string|null $type Optional desired index storage type.
	 * @param string|null $language Optional desired language metadata.
	 * @return bool True when the desired index exists or was created successfully.
	 */
	public function ensureIndex(
		string $indexName,
		string $primaryKeyColumnName,
		?string $type=null,
		?string $language=null
	): bool {
		$desired=new IndexDefinition(
			$indexName,
			$type!==null && trim($type)!=='' ? strtolower(trim($type)) : $this->defaultIndexType(),
			$primaryKeyColumnName,
			$language!==null && trim($language)!=='' ? trim($language) : null
		);
		if(!$desired->isValid()){
			return false;
		}
		$current=$this->definition($indexName);
		if($current!==null){
			return $current->matches($desired);
		}
		return $this->createIndex($desired->name(), $desired->primaryKeyColumnName(), $desired->type(), $desired->language());
	}

	/**
	 * Adds one document to an existing kernel fulltext index.
	 *
	 * The values array must include the index primary key column expected by the
	 * kernel definition plus any searchable fields the index stores.
	 *
	 * @param string $indexName Runtime index name receiving the document.
	 * @param array<string,scalar|null> $values Field values for the indexed document, including the index primary key.
	 * @param string|null $language Optional language override for tokenization.
	 * @return bool True when the kernel accepts the document.
	 */
	public function add(string $indexName, array $values, ?string $language=null): bool {
		return \dataphyre\fulltext_engine::add_to_index($indexName, $values, $this->normalizeLanguage($language));
	}

	/**
	 * Replaces one indexed document through the kernel API.
	 *
	 * The values array follows the same shape as add() and must identify the record
	 * by the index primary key column.
	 *
	 * @param string $indexName Runtime index name containing the document.
	 * @param array<string,scalar|null> $values Updated field values for the indexed document, including the index primary key.
	 * @param string|null $language Optional language override for tokenization.
	 * @return bool True when the kernel updates the document.
	 */
	public function update(string $indexName, array $values, ?string $language=null): bool {
		return \dataphyre\fulltext_engine::update_in_index($indexName, $values, $this->normalizeLanguage($language));
	}

	/**
	 * Removes one indexed document by primary key value.
	 *
	 * The primary key value is passed directly to the kernel; callers are
	 * responsible for formatting it exactly as the index stored it.
	 *
	 * @param string $indexName Runtime index name containing the document.
	 * @param string $primaryKeyValue Stored primary key value to remove.
	 * @return bool True when the kernel removes the document.
	 */
	public function remove(string $indexName, string $primaryKeyValue): bool {
		return \dataphyre\fulltext_engine::remove_from_index($indexName, $primaryKeyValue);
	}

	/**
	 * Tokenizes text using the fulltext kernel language pipeline.
	 *
	 * The returned array is the kernel token data and may include normalized
	 * terms, stems, and metadata depending on the configured language backend.
	 *
	 * @param string $text Text to split into searchable tokens.
	 * @param string|null $language Optional language override for tokenization.
	 * @return list<string>|array<string,mixed> Kernel token data for the normalized language.
	 */
	public function tokenize(string $text, ?string $language=null): array {
		return \dataphyre\fulltext_engine::tokenize($text, $this->normalizeLanguage($language));
	}

	/**
	 * Removes language stopwords from a search query string.
	 *
	 * Language normalization mirrors search execution so preprocessing helpers and
	 * real queries use the same default language.
	 *
	 * @param string $query Raw user or system search query.
	 * @param string|null $language Optional language override.
	 * @return string Query after kernel stopword filtering.
	 */
	public function removeStopwords(string $query, ?string $language=null): string {
		return \dataphyre\fulltext_engine::remove_stopwords($query, $this->normalizeLanguage($language));
	}

	/**
	 * Applies the kernel stemming pipeline to a query string.
	 *
	 * The result is suitable for diagnostics or manual scoring workflows that need
	 * to mirror the same language normalization as indexed searches.
	 *
	 * @param string $query Query text to stem.
	 * @param string|null $language Optional language override.
	 * @return string Stemmed query returned by the kernel.
	 */
	public function applyStemming(string $query, ?string $language=null): string {
		return \dataphyre\fulltext_engine::apply_stemming($query, $this->normalizeLanguage($language));
	}

	/**
	 * Computes a fulltext relevance score for an indexed value and search value.
	 *
	 * When the raw search value is omitted, the normalized search value is reused.
	 * Boolean mode and algorithm arguments follow the same defaults as search().
	 *
	 * @param string $indexValue Indexed text value to compare.
	 * @param string $searchValue Normalized search value.
	 * @param string|null $searchValueRaw Original user query, when different.
	 * @param string|null $language Optional language override for scoring.
	 * @param bool|null $booleanMode Boolean-mode override.
	 * @param string|null $forcedAlgorithms Algorithm override.
	 * @return float Kernel relevance score.
	 */
	public function score(
		string $indexValue,
		string $searchValue,
		?string $searchValueRaw=null,
		?string $language=null,
		?bool $booleanMode=null,
		?string $forcedAlgorithms=null
	): float {
		$searchValueRaw=$searchValueRaw!==null ? $searchValueRaw : $searchValue;
		return \dataphyre\fulltext_engine::get_score(
			$indexValue,
			$searchValue,
			$searchValueRaw,
			$this->normalizeLanguage($language),
			$this->normalizeBooleanMode($booleanMode),
			$this->normalizeAlgorithms($forcedAlgorithms)
		);
	}

	/**
	 * Compares desired fulltext definitions with current kernel state.
	 *
	 * Desired definitions may be IndexDefinition instances or arrays accepted by
	 * normalizeDefinitionInput(). Missing definitions are created, matching ones are
	 * reported unchanged, mismatches are reported without destructive migration, and
	 * pruneMissing optionally deletes indexes absent from desired state.
	 *
	 * @param array<int|string,IndexDefinition|array<string,mixed>> $definitions Desired definitions.
	 * @param bool $pruneMissing Delete current indexes that are absent from desired state.
	 * @return IndexSyncReport Created, unchanged, mismatched, pruned, and failed indexes.
	 */
	public function sync(array $definitions, bool $pruneMissing=false): IndexSyncReport {
		$report=new IndexSyncReport();
		$desired=[];
		foreach($definitions as $indexName=>$definition){
			$normalized=$this->normalizeDefinitionInput($indexName, $definition);
			if($normalized===null){
				$report->addFailed(is_string($indexName) ? $indexName : '', 'Invalid index definition.');
				continue;
			}
			$desired[$normalized->name()]=$normalized;
		}

		$current=[];
		foreach($this->definitions() as $definition){
			$current[$definition->name()]=$definition;
		}

		foreach($desired as $indexName=>$definition){
			if(!isset($current[$indexName])){
				if($this->createIndex($definition->name(), $definition->primaryKeyColumnName(), $definition->type(), $definition->language())){
					$report->addCreated($definition);
				}
				else
				{
					$report->addFailed($definition->name(), 'Failed creating index.');
				}
				continue;
			}

			if($current[$indexName]->matches($definition)){
				$report->addUnchanged($current[$indexName]);
				continue;
			}

			$report->addMismatched($current[$indexName], $definition);
		}

		if($pruneMissing){
			foreach($current as $indexName=>$definition){
				if(isset($desired[$indexName])){
					continue;
				}
				if($this->deleteIndex($indexName)){
					$report->addPruned($definition);
				}
				else
				{
					$report->addFailed($indexName, 'Failed deleting index.');
				}
			}
		}

		return $report;
	}

	/**
	 * Synchronizes kernel indexes against DP_FULLTEXT_ENGINE_CFG definitions.
	 *
	 * This is the framework-level maintenance entry point used by setup tools and
	 * diagnostics when the desired index list should come from configuration.
	 *
	 * @param bool $pruneMissing Delete current indexes missing from configuration.
	 * @return IndexSyncReport Result of syncing configuredDefinitions().
	 */
	public function syncConfigured(bool $pruneMissing=false): IndexSyncReport {
		return $this->sync($this->configuredDefinitions(), $pruneMissing);
	}

	/**
	 * Returns raw framework index definitions from configuration.
	 *
	 * Non-array configuration is treated as an empty definition set. The values are
	 * intentionally not normalized here so sync() can report invalid definitions in
	 * its structured IndexSyncReport.
	 *
	 * @return array Raw DP_FULLTEXT_ENGINE_CFG framework index definitions.
	 */
	public function configuredDefinitions(): array {
		$configured=DP_FULLTEXT_ENGINE_CFG['framework']['indexes'] ?? null;
		return is_array($configured) ? $configured : [];
	}

	/**
	 * Normalizes a requested search language against manager defaults.
	 *
	 * blank or null language values collapse to defaultLanguage() so every
	 * kernel tokenization, search, scoring, and index lifecycle call receives a
	 * concrete language string.
	 */
	private function normalizeLanguage(?string $language): string {
		$language=$language!==null ? trim($language) : '';
		return $language!=='' ? $language : $this->defaultLanguage();
	}

	/**
	 * Normalizes maximum result counts for search calls.
	 *
	 * limits are clamped to at least one and fall back to defaultLimit()
	 * when omitted, preventing invalid or empty result limits from reaching the
	 * fulltext kernel.
	 */
	private function normalizeLimit(?int $maxResults): int {
		return max(1, $maxResults ?? $this->defaultLimit());
	}

	/**
	 * Normalizes optional boolean-mode overrides.
	 *
	 * null means "use framework default" while explicit true/false values
	 * are preserved for raw and typed search/scoring calls.
	 */
	private function normalizeBooleanMode(?bool $booleanMode): bool {
		return $booleanMode ?? $this->defaultBooleanMode();
	}

	/**
	 * Normalizes score thresholds for result filtering.
	 *
	 * negative values are clamped to zero and null falls back to
	 * defaultThreshold(), keeping score comparisons in a valid non-negative domain.
	 */
	private function normalizeThreshold(?float $threshold): float {
		return max(0.0, $threshold ?? $this->defaultThreshold());
	}

	/**
	 * Normalizes algorithm override strings.
	 *
	 * non-empty caller overrides win, blank overrides defer to the
	 * configured defaultAlgorithms() value, and an empty result signals kernel
	 * default algorithm selection.
	 */
	private function normalizeAlgorithms(?string $forcedAlgorithms): string {
		$forcedAlgorithms=$forcedAlgorithms!==null ? trim($forcedAlgorithms) : '';
		return $forcedAlgorithms!=='' ? $forcedAlgorithms : $this->defaultAlgorithms();
	}

	/**
	 * Resolves a configured resolver source for an index.
	 *
	 * index-specific framework resolver configuration takes precedence
	 * over the wildcard "*" source. Non-array resolver configuration means no
	 * configured resolver is available.
	 */
	private function configuredResolverSource(string $indexName): mixed {
		$resolvers=DP_FULLTEXT_ENGINE_CFG['framework']['resolvers'] ?? null;
		if(!is_array($resolvers)){
			return null;
		}
		if(array_key_exists($indexName, $resolvers)){
			return $resolvers[$indexName];
		}
		if(array_key_exists('*', $resolvers)){
			return $resolvers['*'];
		}
		return null;
	}

	/**
	 * Converts a resolver source into a DocumentResolver instance.
	 *
	 * accepted sources are existing DocumentResolver objects, resolver
	 * config arrays, callables, callable objects, and class names. Invalid class or
	 * driver shapes throw runtime errors so hydration never proceeds with an
	 * ambiguous resolver.
	 */
	private function normalizeResolver(mixed $resolver, string $indexName): ?DocumentResolver {
		if($resolver instanceof DocumentResolver){
			return $resolver;
		}
		if(is_array($resolver)){
			return $this->resolverFromArray($resolver, $indexName);
		}
		if(is_callable($resolver)){
			return new CallbackDocumentResolver($resolver);
		}
		if(is_string($resolver)){
			$resolver=trim($resolver);
			if($resolver===''){
				return null;
			}
			if(!class_exists($resolver)){
				throw new \RuntimeException("Fulltext document resolver class '{$resolver}' does not exist for index '{$indexName}'.");
			}
			$instance=new $resolver();
			if($instance instanceof DocumentResolver){
				return $instance;
			}
			if(is_callable($instance)){
				return new CallbackDocumentResolver($instance);
			}
			throw new \RuntimeException("Fulltext document resolver '{$resolver}' is not a valid resolver for index '{$indexName}'.");
		}
		throw new \RuntimeException("Invalid fulltext document resolver supplied for index '{$indexName}'.");
	}

	/**
	 * Builds a resolver from array configuration.
	 *
	 * table and repository drivers define persistence-backed hydration
	 * sources, callback drivers wrap custom code, and missing driver values are
	 * inferred from table/repository keys before invalid drivers fail loudly.
	 */
	private function resolverFromArray(array $resolver, string $indexName): ?DocumentResolver {
		$driver=strtolower(trim((string)($resolver['driver'] ?? $resolver['type'] ?? '')));
		if($driver===''){
			if(isset($resolver['table'])){
				$driver='table';
			}
			elseif(isset($resolver['repository'])){
				$driver='repository';
			}
		}
		if($driver==='table'){
			$table=trim((string)($resolver['table'] ?? ''));
			$primaryKeyColumn=trim((string)($resolver['primary_key'] ?? $resolver['primary_key_column'] ?? 'id'));
			$columns=$resolver['columns'] ?? '*';
			$caching=$resolver['caching'] ?? false;
			$mapper=$resolver['mapper'] ?? null;
			return new TableDocumentResolver($table, $primaryKeyColumn, $columns, $caching, $mapper);
		}
		if($driver==='repository'){
			$repository=trim((string)($resolver['repository'] ?? ''));
			$primaryKeyColumn=trim((string)($resolver['primary_key'] ?? $resolver['primary_key_column'] ?? 'id'));
			$columns=$resolver['columns'] ?? '*';
			$caching=$resolver['caching'] ?? false;
			$mapper=$resolver['mapper'] ?? null;
			return new RepositoryDocumentResolver($repository, $primaryKeyColumn, $columns, $caching, $mapper);
		}
		if($driver==='callback' && isset($resolver['callback']) && is_callable($resolver['callback'])){
			return new CallbackDocumentResolver($resolver['callback']);
		}
		throw new \RuntimeException("Invalid fulltext document resolver driver '{$driver}' for index '{$indexName}'.");
	}

	/**
	 * Normalizes configured sync input into an IndexDefinition.
	 *
	 * sync accepts typed definitions or array definitions keyed by name.
	 * Invalid shapes return null so sync() can report structured failures without
	 * throwing or mutating kernel index state.
	 */
	private function normalizeDefinitionInput(int|string $indexName, mixed $definition): ?IndexDefinition {
		if($definition instanceof IndexDefinition){
			return $definition;
		}
		if(!is_array($definition)){
			return null;
		}
		if(isset($definition['name']) && is_string($definition['name']) && trim($definition['name'])!==''){
			$typedDefinition=IndexDefinition::fromArray($definition);
			return $typedDefinition->isValid() ? $typedDefinition : null;
		}
		if(!is_string($indexName) || trim($indexName)===''){
			return null;
		}
		$definition['name']=$indexName;
		$typedDefinition=IndexDefinition::fromArray($definition);
		return $typedDefinition->isValid() ? $typedDefinition : null;
	}
}
