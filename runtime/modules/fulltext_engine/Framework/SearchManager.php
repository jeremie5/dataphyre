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

final class SearchManager {

	private static ?self $instance=null;

	/** @var array<string, Index> */
	private array $indexes=[];

	/** @var array<string, DocumentResolver> */
	private array $resolvers=[];

	/** @var array<string, mixed> */
	private array $resolver_sources=[];

	private function __construct(){}

	public static function instance(): self {
		if(self::$instance===null){
			self::$instance=new self();
		}
		return self::$instance;
	}

	public static function flush(): void {
		self::$instance=null;
	}

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

	public function query(string $index_name): Query {
		return $this->index($index_name)->query();
	}

	/**
	 * @return array<int, IndexDefinition>
	 */
	public function definitions(): array {
		$definitions=\dataphyre\fulltext_engine::get_index_definitions();
		$typed=[];
		foreach($definitions as $definition){
			if(is_array($definition)){
				$typed_definition=IndexDefinition::fromArray($definition);
				if($typed_definition->isValid()){
					$typed[]=$typed_definition;
				}
			}
		}
		usort($typed, static fn(IndexDefinition $a, IndexDefinition $b): int => $a->name()<=>$b->name());
		return $typed;
	}

	public function definition(string $index_name): ?IndexDefinition {
		$definition=\dataphyre\fulltext_engine::get_index_definition($index_name);
		if(!is_array($definition)){
			return null;
		}
		$typed_definition=IndexDefinition::fromArray($definition);
		return $typed_definition->isValid() ? $typed_definition : null;
	}

	public function hasIndex(string $index_name): bool {
		return \dataphyre\fulltext_engine::index_exists($index_name);
	}

	public function resolver(string $index_name): ?DocumentResolver {
		$index_name=trim($index_name);
		if($index_name===''){
			return null;
		}
		if(isset($this->resolvers[$index_name])){
			return $this->resolvers[$index_name];
		}
		$source=$this->resolver_sources[$index_name] ?? $this->configuredResolverSource($index_name);
		if($source===null){
			return null;
		}
		$resolver=$this->normalizeResolver($source, $index_name);
		if($resolver===null){
			return null;
		}
		return $this->resolvers[$index_name]=$resolver;
	}

	public function extendResolver(string $index_name, mixed $resolver): void {
		$index_name=trim($index_name);
		if($index_name===''){
			throw new \InvalidArgumentException('Index name cannot be empty.');
		}
		$this->resolver_sources[$index_name]=$resolver;
		unset($this->resolvers[$index_name]);
	}

	public function defaultLanguage(): string {
		$language=trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_language'] ?? ''));
		return $language!=='' ? $language : 'en';
	}

	public function defaultLimit(): int {
		return max(1, (int)(DP_FULLTEXT_ENGINE_CFG['framework']['default_limit'] ?? 50));
	}

	public function defaultBooleanMode(): bool {
		return (bool)(DP_FULLTEXT_ENGINE_CFG['framework']['default_boolean_mode'] ?? true);
	}

	public function defaultThreshold(): float {
		return max(0.0, (float)(DP_FULLTEXT_ENGINE_CFG['framework']['default_threshold'] ?? 0.3));
	}

	public function defaultAlgorithms(): string {
		return trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_algorithms'] ?? ''));
	}

	public function defaultIndexType(): string {
		$type=strtolower(trim((string)(DP_FULLTEXT_ENGINE_CFG['framework']['default_index_type'] ?? '')));
		return $type!=='' ? $type : 'json';
	}

	public function search(
		string $index_name,
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): SearchResults {
		return SearchResults::fromKernelResponse(
			$index_name,
			\dataphyre\fulltext_engine::search(
				$index_name,
				$criteria,
				$this->normalizeLanguage($language),
				$this->normalizeLimit($max_results),
				$this->normalizeBooleanMode($boolean_mode),
				$this->normalizeThreshold($threshold),
				$this->normalizeAlgorithms($forced_algorithms)
			)
		);
	}

	public function rawSearch(
		string $index_name,
		array $criteria,
		?string $language=null,
		?int $max_results=null,
		?bool $boolean_mode=null,
		?float $threshold=null,
		?string $forced_algorithms=null
	): bool|array {
		return \dataphyre\fulltext_engine::find_in_index(
			$index_name,
			$criteria,
			$this->normalizeLanguage($language),
			$this->normalizeBooleanMode($boolean_mode),
			$this->normalizeLimit($max_results),
			$this->normalizeThreshold($threshold),
			$this->normalizeAlgorithms($forced_algorithms)
		);
	}

	public function hydrate(SearchResults $results, mixed $resolver=null): HydratedSearchResults {
		$index_name=$results->indexName();
		$definition=$this->definition($index_name);
		$resolver_instance=$resolver!==null
			? $this->normalizeResolver($resolver, $index_name)
			: $this->resolver($index_name);
		if($resolver_instance===null){
			throw new \RuntimeException("No fulltext document resolver is registered for index '{$index_name}'.");
		}
		$documents=$resolver_instance->resolve($results->ids(), $definition);
		$hydrated_hits=[];
		foreach($results->hits() as $hit){
			$id=$hit->id();
			$resolved=array_key_exists($id, $documents);
			$document=$resolved ? $documents[$id] : null;
			$hydrated_hits[]=new HydratedSearchHit($hit, $document, $resolved);
		}
		return HydratedSearchResults::fromResults($results, $hydrated_hits, $definition);
	}

	public function createIndex(
		string $index_name,
		string $primary_key_column_name,
		?string $type=null,
		?string $language=null
	): bool {
		$type=$type!==null && trim($type)!=='' ? strtolower(trim($type)) : $this->defaultIndexType();
		return \dataphyre\fulltext_engine::create_index(
			$index_name,
			$primary_key_column_name,
			$type,
			$this->normalizeLanguage($language)
		);
	}

	public function deleteIndex(string $index_name): bool {
		return \dataphyre\fulltext_engine::delete_index($index_name);
	}

	public function ensureIndex(
		string $index_name,
		string $primary_key_column_name,
		?string $type=null,
		?string $language=null
	): bool {
		$desired=new IndexDefinition(
			$index_name,
			$type!==null && trim($type)!=='' ? strtolower(trim($type)) : $this->defaultIndexType(),
			$primary_key_column_name,
			$language!==null && trim($language)!=='' ? trim($language) : null
		);
		if(!$desired->isValid()){
			return false;
		}
		$current=$this->definition($index_name);
		if($current!==null){
			return $current->matches($desired);
		}
		return $this->createIndex($desired->name(), $desired->primaryKeyColumnName(), $desired->type(), $desired->language());
	}

	public function add(string $index_name, array $values, ?string $language=null): bool {
		return \dataphyre\fulltext_engine::add_to_index($index_name, $values, $this->normalizeLanguage($language));
	}

	public function update(string $index_name, array $values, ?string $language=null): bool {
		return \dataphyre\fulltext_engine::update_in_index($index_name, $values, $this->normalizeLanguage($language));
	}

	public function remove(string $index_name, string $primary_key_value): bool {
		return \dataphyre\fulltext_engine::remove_from_index($index_name, $primary_key_value);
	}

	public function tokenize(string $text, ?string $language=null): array {
		return \dataphyre\fulltext_engine::tokenize($text, $this->normalizeLanguage($language));
	}

	public function removeStopwords(string $query, ?string $language=null): string {
		return \dataphyre\fulltext_engine::remove_stopwords($query, $this->normalizeLanguage($language));
	}

	public function applyStemming(string $query, ?string $language=null): string {
		return \dataphyre\fulltext_engine::apply_stemming($query, $this->normalizeLanguage($language));
	}

	public function score(
		string $index_value,
		string $search_value,
		?string $search_value_raw=null,
		?string $language=null,
		?bool $boolean_mode=null,
		?string $forced_algorithms=null
	): float {
		$search_value_raw=$search_value_raw!==null ? $search_value_raw : $search_value;
		return \dataphyre\fulltext_engine::get_score(
			$index_value,
			$search_value,
			$search_value_raw,
			$this->normalizeLanguage($language),
			$this->normalizeBooleanMode($boolean_mode),
			$this->normalizeAlgorithms($forced_algorithms)
		);
	}

	public function sync(array $definitions, bool $prune_missing=false): IndexSyncReport {
		$report=new IndexSyncReport();
		$desired=[];
		foreach($definitions as $index_name=>$definition){
			$normalized=$this->normalizeDefinitionInput($index_name, $definition);
			if($normalized===null){
				$report->addFailed(is_string($index_name) ? $index_name : '', 'Invalid index definition.');
				continue;
			}
			$desired[$normalized->name()]=$normalized;
		}

		$current=[];
		foreach($this->definitions() as $definition){
			$current[$definition->name()]=$definition;
		}

		foreach($desired as $index_name=>$definition){
			if(!isset($current[$index_name])){
				if($this->createIndex($definition->name(), $definition->primaryKeyColumnName(), $definition->type(), $definition->language())){
					$report->addCreated($definition);
				}
				else
				{
					$report->addFailed($definition->name(), 'Failed creating index.');
				}
				continue;
			}

			if($current[$index_name]->matches($definition)){
				$report->addUnchanged($current[$index_name]);
				continue;
			}

			$report->addMismatched($current[$index_name], $definition);
		}

		if($prune_missing){
			foreach($current as $index_name=>$definition){
				if(isset($desired[$index_name])){
					continue;
				}
				if($this->deleteIndex($index_name)){
					$report->addPruned($definition);
				}
				else
				{
					$report->addFailed($index_name, 'Failed deleting index.');
				}
			}
		}

		return $report;
	}

	public function syncConfigured(bool $prune_missing=false): IndexSyncReport {
		return $this->sync($this->configuredDefinitions(), $prune_missing);
	}

	public function configuredDefinitions(): array {
		$configured=DP_FULLTEXT_ENGINE_CFG['framework']['indexes'] ?? null;
		return is_array($configured) ? $configured : [];
	}

	private function normalizeLanguage(?string $language): string {
		$language=$language!==null ? trim($language) : '';
		return $language!=='' ? $language : $this->defaultLanguage();
	}

	private function normalizeLimit(?int $max_results): int {
		return max(1, $max_results ?? $this->defaultLimit());
	}

	private function normalizeBooleanMode(?bool $boolean_mode): bool {
		return $boolean_mode ?? $this->defaultBooleanMode();
	}

	private function normalizeThreshold(?float $threshold): float {
		return max(0.0, $threshold ?? $this->defaultThreshold());
	}

	private function normalizeAlgorithms(?string $forced_algorithms): string {
		$forced_algorithms=$forced_algorithms!==null ? trim($forced_algorithms) : '';
		return $forced_algorithms!=='' ? $forced_algorithms : $this->defaultAlgorithms();
	}

	private function configuredResolverSource(string $index_name): mixed {
		$resolvers=DP_FULLTEXT_ENGINE_CFG['framework']['resolvers'] ?? null;
		if(!is_array($resolvers)){
			return null;
		}
		if(array_key_exists($index_name, $resolvers)){
			return $resolvers[$index_name];
		}
		if(array_key_exists('*', $resolvers)){
			return $resolvers['*'];
		}
		return null;
	}

	private function normalizeResolver(mixed $resolver, string $index_name): ?DocumentResolver {
		if($resolver instanceof DocumentResolver){
			return $resolver;
		}
		if(is_array($resolver)){
			return $this->resolverFromArray($resolver, $index_name);
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
				throw new \RuntimeException("Fulltext document resolver class '{$resolver}' does not exist for index '{$index_name}'.");
			}
			$instance=new $resolver();
			if($instance instanceof DocumentResolver){
				return $instance;
			}
			if(is_callable($instance)){
				return new CallbackDocumentResolver($instance);
			}
			throw new \RuntimeException("Fulltext document resolver '{$resolver}' is not a valid resolver for index '{$index_name}'.");
		}
		throw new \RuntimeException("Invalid fulltext document resolver supplied for index '{$index_name}'.");
	}

	private function resolverFromArray(array $resolver, string $index_name): ?DocumentResolver {
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
			$primary_key_column=trim((string)($resolver['primary_key'] ?? $resolver['primary_key_column'] ?? 'id'));
			$columns=$resolver['columns'] ?? '*';
			$caching=$resolver['caching'] ?? false;
			$mapper=$resolver['mapper'] ?? null;
			return new TableDocumentResolver($table, $primary_key_column, $columns, $caching, $mapper);
		}
		if($driver==='repository'){
			$repository=trim((string)($resolver['repository'] ?? ''));
			$primary_key_column=trim((string)($resolver['primary_key'] ?? $resolver['primary_key_column'] ?? 'id'));
			$columns=$resolver['columns'] ?? '*';
			$caching=$resolver['caching'] ?? false;
			$mapper=$resolver['mapper'] ?? null;
			return new RepositoryDocumentResolver($repository, $primary_key_column, $columns, $caching, $mapper);
		}
		if($driver==='callback' && isset($resolver['callback']) && is_callable($resolver['callback'])){
			return new CallbackDocumentResolver($resolver['callback']);
		}
		throw new \RuntimeException("Invalid fulltext document resolver driver '{$driver}' for index '{$index_name}'.");
	}

	private function normalizeDefinitionInput(int|string $index_name, mixed $definition): ?IndexDefinition {
		if($definition instanceof IndexDefinition){
			return $definition;
		}
		if(!is_array($definition)){
			return null;
		}
		if(isset($definition['name']) && is_string($definition['name']) && trim($definition['name'])!==''){
			$typed_definition=IndexDefinition::fromArray($definition);
			return $typed_definition->isValid() ? $typed_definition : null;
		}
		if(!is_string($index_name) || trim($index_name)===''){
			return null;
		}
		$definition['name']=$index_name;
		$typed_definition=IndexDefinition::fromArray($definition);
		return $typed_definition->isValid() ? $typed_definition : null;
	}
}
