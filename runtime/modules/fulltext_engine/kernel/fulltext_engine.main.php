<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

require_once(dirname(__DIR__)."/similarity/jarowinkler.php");
require_once(dirname(__DIR__)."/similarity/damerau_levenshtein.php");
require_once(dirname(__DIR__)."/similarity/jaccard.php");

require_once(__DIR__."/keyword_extraction.php");
require_once(__DIR__."/bm25.php");
require_once(__DIR__."/ngram.php");

require_once(dirname(__DIR__)."/external_engines/vespa.php");
require_once(dirname(__DIR__)."/external_engines/elastic.php");

dp_define_module_config('fulltext_engine', 'DP_FULLTEXT_ENGINE_CFG', [
	'fs_index_entry_count'=>1000,
	'fs_index_entry_count_for_sql'=>100000,
	'framework'=>[
		'default_language'=>'en',
		'default_limit'=>50,
		'default_boolean_mode'=>true,
		'default_threshold'=>0.3,
		'default_algorithms'=>'',
		'default_index_type'=>'json',
		'indexes'=>[],
		'resolvers'=>[],
	],
	'external_engines'=>[],
]);

/**
 * Legacy kernel facade for Dataphyre full-text indexes and similarity search.
 *
 * This class owns the original procedural fulltext engine surface: index
 * definition persistence, JSON/SQLite/SQL/external index storage, query
 * tokenization, boolean expression matching, fuzzy scoring, optional BM25
 * reranking, and stopword/stemmer loading. Index definitions live in the
 * application Dataphyre config tree, while index entries may be stored on disk,
 * in SQL tables, or in external engines depending on the index type.
 *
 * The kernel API intentionally remains snake_case for backwards compatibility;
 * framework wrappers can expose richer object APIs around this lower-level
 * storage and ranking contract.
 */
class fulltext_engine{

	private static bool $initialized=false;
	private static array $index_definitions=[];
	private const TOKENIZE_CACHE_LIMIT=256;
	private static array $tokenize_cache=[];

	/**
	 * Loads normalized index definitions from the in-memory cache.
	 *
	 * @return array<string, array<string, mixed>> Index definitions keyed by index name.
	 */
	private static function index_definitions(): array {
		self::init();
		$definitions=self::$index_definitions;
		return is_array($definitions) ? $definitions : [];
	}

	/**
	 * Initializes or refreshes the index-definition cache from disk.
	 *
	 * Invalid or missing JSON resolves to an empty definition set rather than
	 * throwing, because callers treat unknown indexes as normal miss states.
	 *
	 * @param bool $force_reload Whether to ignore the current process cache.
	 */
	private static function init(bool $force_reload=false): void {
		if(self::$initialized===true && $force_reload===false){
			return;
		}
		$indexes_path=self::indexes_definition_path();
		$indexes=[];
		if(file_exists($indexes_path)){
			$decoded=json_decode((string)file_get_contents($indexes_path), true);
			if(is_array($decoded)){
				$indexes=$decoded;
			}
		}
		self::$index_definitions=$indexes;
		self::$initialized=true;
	}

	/**
	 * Returns the JSON manifest path that stores index definitions.
	 *
	 * @return string Absolute path under the current Dataphyre application config.
	 */
	private static function indexes_definition_path(): string {
		return ROOTPATH['dataphyre']."config/fulltext_engine/indexes.json";
	}

	/**
	 * Builds the filesystem storage root for a local index type.
	 *
	 * @param string $type Storage backend directory such as `json` or `sqlite`.
	 * @param string $index_name Optional index directory name.
	 * @return string Absolute storage path.
	 */
	private static function index_storage_path(string $type, string $index_name=''): string {
		$base=ROOTPATH['dataphyre']."fulltext_indexes/".$type;
		return $index_name!=='' ? $base."/".$index_name : $base;
	}

	/**
	 * Returns one raw index definition from the manifest cache.
	 *
	 * @param string $index_name Manifest key.
	 * @return array<string, mixed>|null Definition without an injected `name` field.
	 */
	private static function index_definition(string $index_name): ?array {
		self::init();
		$definition=self::$index_definitions[$index_name] ?? null;
		return is_array($definition) ? $definition : null;
	}

	/**
	 * Resolves the configured primary-key column for an index.
	 *
	 * @param string $index_name Manifest key.
	 * @return ?string Primary-key column name used for entry identity.
	 */
	private static function index_primary_key(string $index_name): ?string {
		$definition=self::index_definition($index_name);
		$primary_key=$definition['primary_key_column_name'] ?? null;
		return is_string($primary_key) && $primary_key!=='' ? $primary_key : null;
	}

	/**
	 * Returns the local shard size for JSON or SQLite index files.
	 *
	 * @param string $type Index storage type.
	 * @return int Positive maximum number of entries per local shard.
	 */
	private static function index_entry_limit(string $type): int {
		$key=$type==='sqlite' ? 'fs_index_entry_count_for_sql' : 'fs_index_entry_count';
		return max(1, (int)DP_FULLTEXT_ENGINE_CFG[$key]);
	}

	/**
	 * Persists the complete index-definition manifest and refreshes cache state.
	 *
	 * @param array<string, array<string, mixed>> $index_definitions Manifest content to write.
	 * @return bool True when the manifest file was written.
	 */
	private static function persist_index_definitions(array $index_definitions): bool {
		$filepath=self::indexes_definition_path();
		if(false!==core::file_put_contents_forced($filepath, json_encode($index_definitions))){
			self::$index_definitions=$index_definitions;
			self::$initialized=true;
			return true;
		}
		return false;
	}

	/**
	 * Returns all configured indexes with their manifest names injected.
	 *
	 * Non-array manifest entries are filtered out so callers receive only
	 * usable definitions.
	 *
	 * @return array<string, array<string, mixed>> Definitions keyed by index name.
	 */
	public static function get_index_definitions(): array {
		$definitions=self::index_definitions();
		foreach($definitions as $index_name=>$definition){
			if(!is_array($definition)){
				unset($definitions[$index_name]);
				continue;
			}
			$definitions[$index_name]=array_merge(['name'=>$index_name], $definition);
		}
		return $definitions;
	}

	/**
	 * Returns one configured index definition with its name injected.
	 *
	 * @param string $index_name Manifest key.
	 * @return array<string, mixed>|null Definition for the index, or null when missing.
	 */
	public static function get_index_definition(string $index_name): ?array {
		$definition=self::index_definition($index_name);
		if($definition===null){
			return null;
		}
		return array_merge(['name'=>$index_name], $definition);
	}

	/**
	 * Checks whether an index definition exists in the manifest cache.
	 *
	 * @param string $index_name Manifest key.
	 * @return bool True when the index can be resolved.
	 */
	public static function index_exists(string $index_name): bool {
		return self::index_definition($index_name)!==null;
	}

	/**
	 * Ensures a local index directory exists before JSON or SQLite writes.
	 *
	 * @param string $type Local storage type.
	 * @param string $index_name Normalized index name.
	 * @return string Absolute directory path.
	 */
	private static function ensure_index_directory(string $type, string $index_name): string {
		$directory=self::index_storage_path($type, $index_name);
		if(!is_dir($directory)){
			mkdir($directory, 0777, true);
		}
		return $directory;
	}

	/**
	 * Normalizes an index or column identifier to the SQL-safe kernel subset.
	 *
	 * Only bare ASCII identifiers are accepted here because SQL backend table
	 * and column names are interpolated into DDL/DML strings.
	 *
	 * @param string $identifier Candidate index or column identifier.
	 * @return string Trimmed identifier, or an empty string when invalid.
	 */
	private static function normalize_identifier(string $identifier): string {
		$identifier=trim($identifier);
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) ? $identifier : '';
	}

	/**
	 * Builds the SQL table name for a SQL-backed fulltext index.
	 *
	 * Index names are restricted to bare identifiers before interpolation. The
	 * returned name always targets the `dataphyre_fulltext_engine` schema and uses
	 * the `index_` prefix so user-controlled index names cannot select arbitrary
	 * tables.
	 *
	 * @param string $index_name Candidate index name.
	 * @return string|false Fully qualified SQL table name, or false for invalid identifiers.
	 */
	private static function sql_index_table(string $index_name): string|false {
		$normalized=self::normalize_identifier($index_name);
		return $normalized!=='' ? "dataphyre_fulltext_engine.index_".$normalized : false;
	}

	/**
	 * Creates the SQL storage table for an index when it does not already exist.
	 *
	 * The SQL backend stores each indexed document as one primary-key column plus a
	 * JSON text column named `index_value`. DDL strings are assembled only after the
	 * table and primary-key identifiers have been normalized by callers.
	 *
	 * @param string $index_name Normalized index name.
	 * @param string $primary_column_name Normalized primary-key column name.
	 * @return bool True when DDL execution succeeds.
	 */
	private static function sql_backend_create_table(string $index_name, string $primary_column_name): bool {
		$table=self::sql_index_table($index_name);
		if($table===false || $primary_column_name===''){
			return false;
		}
		return sql_query([
			'mysql'=>"CREATE TABLE IF NOT EXISTS ".$table." (".$primary_column_name." VARCHAR(191) NOT NULL, index_value LONGTEXT NOT NULL, PRIMARY KEY (".$primary_column_name."))",
			'postgresql'=>"CREATE TABLE IF NOT EXISTS ".$table." (".$primary_column_name." TEXT PRIMARY KEY, index_value TEXT NOT NULL)",
			'sqlite'=>"CREATE TABLE IF NOT EXISTS ".$table." (".$primary_column_name." TEXT PRIMARY KEY, index_value TEXT NOT NULL)",
		], null, false, false)!==false;
	}

	/**
	 * Drops the SQL storage table for a SQL-backed index.
	 *
	 * @param string $index_name Index name to convert into the storage table name.
	 * @return bool True when the drop statement succeeds or the table is already absent.
	 */
	private static function sql_backend_drop_table(string $index_name): bool {
		$table=self::sql_index_table($index_name);
		if($table===false){
			return false;
		}
		return sql_query([
			'mysql'=>"DROP TABLE IF EXISTS ".$table,
			'postgresql'=>"DROP TABLE IF EXISTS ".$table,
			'sqlite'=>"DROP TABLE IF EXISTS ".$table,
		], null, false, false)!==false;
	}

	/**
	 * Serializes an indexed entry for SQL storage.
	 *
	 * Values are already tokenized before reaching this helper. JSON keeps the SQL
	 * backend compatible with the local JSON/SQLite entry shape while avoiding a
	 * schema change for every indexed field.
	 *
	 * @param array<string, mixed> $values Tokenized entry values keyed by indexed column.
	 * @return string JSON stored in the SQL `index_value` column.
	 */
	private static function sql_backend_entry_json(array $values): string {
		return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Converts a SQL row back into the generic in-memory index entry shape.
	 *
	 * Newer SQL indexes store the entry as JSON in `index_value`; older or
	 * custom rows can still be interpreted by removing the primary-key column
	 * and treating the remaining columns as entry fields.
	 *
	 * @param array<string, mixed> $row SQL result row.
	 * @param string $primary_column_name Primary-key column configured for the index.
	 * @return array{primary_key:mixed,entry:array<string,mixed>}|null Normalized entry, or null when the primary key is absent.
	 */
	private static function sql_backend_entry_from_row(array $row, string $primary_column_name): ?array {
		if(!array_key_exists($primary_column_name, $row)){
			return null;
		}
		$primary_key=$row[$primary_column_name];
		if(array_key_exists('index_value', $row)){
			$decoded=json_decode((string)$row['index_value'], true);
			if(is_array($decoded)){
				return [
					'primary_key'=>$primary_key,
					'entry'=>$decoded,
				];
			}
		}
		unset($row[$primary_column_name]);
		return [
			'primary_key'=>$primary_key,
			'entry'=>$row,
		];
	}

	/**
	 * Builds a coarse SQL LIKE prefilter before PHP scoring is applied.
	 *
	 * The SQL backend still uses the same PHP scoring pipeline as local JSON and
	 * SQLite backends; this prefilter only bounds the candidate pool. Terms shorter
	 * than two characters are ignored to avoid broad LIKE scans with little ranking
	 * value.
	 *
	 * @param array<string, string> $search_data Tokenized search values.
	 * @param bool $boolean_mode Whether terms must all match (`AND`) or any may match (`OR`).
	 * @param int $max_results Candidate row limit.
	 * @return array{params:string,vars:array<int,string>} SQL tail and bindings.
	 */
	private static function sql_search_prefilter(array $search_data, bool $boolean_mode, int $max_results): array {
		$terms=[];
		foreach($search_data as $search_value){
			foreach(preg_split('/\s+/', trim((string)$search_value)) as $term){
				$term=trim((string)$term);
				if($term!=='' && mb_strlen($term)>=2){
					$terms[$term]=true;
				}
			}
		}
		$params=' LIMIT '.max(1, $max_results);
		$vars=[];
		if(!empty($terms)){
			$clauses=[];
			foreach(array_keys($terms) as $term){
				$clauses[]='index_value LIKE ?';
				$vars[]='%'.$term.'%';
			}
			$params='WHERE '.implode($boolean_mode ? ' AND ' : ' OR ', $clauses).$params;
		}
		return [
			'params'=>$params,
			'vars'=>$vars,
		];
	}

	/**
	 * Tokenizes every indexed field value before persistence.
	 *
	 * Primary keys are removed before this helper is called. Returned values are
	 * space-joined token strings, matching the field comparison shape used by the
	 * fuzzy scoring pipeline.
	 *
	 * @param array<string, mixed> $values Raw entry values keyed by field.
	 * @param string $language Language hint used for stopwords and stemming.
	 * @return array<string, string> Tokenized field values.
	 */
	private static function tokenize_values(array $values, string $language): array {
		foreach($values as $key=>$value){
			$index_value=self::tokenize((string)$value, $language);
			$values[$key]=implode(' ', $index_value);
		}
		return $values;
	}

	/**
	 * Flattens an index entry into plain text for BM25 reranking.
	 *
	 * Structured field values are encoded as JSON so arrays and objects still
	 * contribute searchable text without changing the stored entry shape.
	 *
	 * @param array<string, mixed> $entry Indexed entry data.
	 * @return string Space-joined scalar/JSON field text.
	 */
	private static function flatten_entry_text(array $entry): string {
		$parts=[];
		foreach($entry as $value){
			if(is_array($value) || is_object($value)){
				$value=json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
			$value=trim((string)$value);
			if($value!==''){
				$parts[]=$value;
			}
		}
		return implode(' ', $parts);
	}

	/**
	 * Joins non-empty raw search values into the query text used by rerankers.
	 *
	 * @param array<string, mixed> $search_values_raw Caller-provided un-tokenized search values.
	 * @return string Combined query text.
	 */
	private static function combined_query_text(array $search_values_raw): string {
		$parts=[];
		foreach($search_values_raw as $value){
			$value=trim((string)$value);
			if($value!==''){
				$parts[]=$value;
			}
		}
		return implode(' ', $parts);
	}

	/**
	 * Determines whether match candidates should be reranked with BM25.
	 *
	 * BM25 is chosen explicitly through `bm25`, or automatically for long/multi
	 * term queries when no other algorithm is forced.
	 *
	 * @param array<string, mixed> $search_values_raw Caller-provided un-tokenized search values.
	 * @param string $forced_algorithms Optional algorithm override.
	 * @return bool True when the final candidate set should use BM25 scores.
	 */
	private static function should_rerank_with_bm25(array $search_values_raw, string $forced_algorithms): bool {
		if($forced_algorithms==='bm25'){
			return true;
		}
		if($forced_algorithms!==''){
			return false;
		}
		$query_text=self::combined_query_text($search_values_raw);
		return mb_strlen($query_text)>50 || self::count_terms($query_text)>10;
	}

	/**
	 * Computes the best field-level score between one indexed entry and a search map.
	 *
	 * Field names must match unless the search map uses `*`, which compares the
	 * query against every indexed field. The highest field score becomes the entry
	 * score before thresholding.
	 *
	 * @param array<string, mixed> $entry Tokenized indexed entry.
	 * @param array<string, string> $search_data Tokenized search values.
	 * @param array<string, mixed> $search_values_raw Raw search values keyed like `$search_data`.
	 * @param string $language Language hint.
	 * @param bool $boolean_mode Whether to evaluate raw query text as a boolean expression.
	 * @param string $forced_algorithms Optional scoring algorithm override.
	 * @return float Best score across compared fields.
	 */
	private static function entry_match_score(array $entry, array $search_data, array $search_values_raw, string $language, bool $boolean_mode, string $forced_algorithms): float {
		$best_score=0.0;
		foreach($entry as $key1=>$index_value){
			foreach($search_data as $key2=>$search_value){
				if($key1===$key2 || $key2==='*'){
					$raw_search_value=(string)($search_values_raw[$key2] ?? $search_value);
					$score=self::get_score((string)$index_value, (string)$search_value, $raw_search_value, $language, $boolean_mode, $forced_algorithms);
					if($score>$best_score){
						$best_score=$score;
					}
				}
			}
		}
		return $best_score;
	}

	/**
	 * Adds or updates a candidate result when an entry clears the score threshold.
	 *
	 * If the same primary key is encountered more than once, the highest score
	 * wins and its flattened text is retained for possible BM25 reranking.
	 *
	 * @param array<string, array{score:float,entry_text:string}> $result_primarykeys Candidate map mutated in place.
	 * @param string|int $primary_key Entry identity.
	 * @param array<string, mixed> $entry Tokenized indexed entry.
	 * @param array<string, string> $search_data Tokenized search values.
	 * @param array<string, mixed> $search_values_raw Raw search values.
	 * @param string $language Language hint.
	 * @param bool $boolean_mode Whether to evaluate raw query text as a boolean expression.
	 * @param float $threshold Minimum accepted score.
	 * @param string $forced_algorithms Optional scoring algorithm override.
	 */
	private static function append_entry_matches(array &$result_primarykeys, string|int $primary_key, array $entry, array $search_data, array $search_values_raw, string $language, bool $boolean_mode, float $threshold, string $forced_algorithms): void {
		$score=self::entry_match_score($entry, $search_data, $search_values_raw, $language, $boolean_mode, $forced_algorithms);
		if($score<$threshold){
			return;
		}
		$primary_key=(string)$primary_key;
		$current_score=(float)($result_primarykeys[$primary_key]['score'] ?? 0.0);
		if($score>$current_score){
			$result_primarykeys[$primary_key]=[
				'score'=>$score,
				'entry_text'=>self::flatten_entry_text($entry),
			];
		}
	}

	/**
	 * Converts the candidate map into the public result list shape.
	 *
	 * Long unforced queries are reranked with BM25 before materialization.
	 *
	 * @param array<string, array{score:float,entry_text:string}> $result_primarykeys Candidate map keyed by primary key.
	 * @param array<string, mixed> $search_values_raw Raw search values.
	 * @param string $forced_algorithms Optional scoring algorithm override.
	 * @return array<int, array<string, float>> Result rows shaped as `[primaryKey => score]`.
	 */
	private static function finalize_result_matches(array $result_primarykeys, array $search_values_raw, string $forced_algorithms): array {
		if(empty($result_primarykeys)){
			return [];
		}
		if(self::should_rerank_with_bm25($search_values_raw, $forced_algorithms)){
			$query_text=self::combined_query_text($search_values_raw);
			$corpus=[];
			foreach($result_primarykeys as $result){
				$entry_text=trim((string)($result['entry_text'] ?? ''));
				if($entry_text!==''){
					$corpus[]=$entry_text;
				}
			}
			foreach($result_primarykeys as $primary_key=>$result){
				$entry_text=trim((string)($result['entry_text'] ?? ''));
				if($entry_text===''){
					continue;
				}
				$result_primarykeys[$primary_key]['score']=fulltext_engine\bm25::similarity($entry_text, $query_text, $corpus);
			}
		}
		$materialized=[];
		foreach($result_primarykeys as $primary_key=>$result){
			$materialized[]=[(string)$primary_key=>(float)($result['score'] ?? 0.0)];
		}
		return $materialized;
	}

    /**
     * Searches an index and returns scored primary-key matches plus summary metrics.
     *
     * The input data is a field-to-query map. Field-specific queries compare
     * against the same indexed field; the special `*` field compares against all
     * indexed fields. Results are sorted by descending relevance, trimmed to the
     * requested limit, and accompanied by average certainty and elapsed time.
     *
     * @param string $index_name Configured index name.
     * @param array<string, mixed> $data Field-to-query search data.
     * @param string $language Language hint for tokenization.
     * @param int $max_results Maximum returned matches.
     * @param bool $boolean_mode Whether raw query values should be parsed as boolean expressions.
     * @param float $threshold Minimum score required for a candidate.
     * @param string $forced_algorithms Optional scoring algorithm override.
     * @return array<string,mixed> Search response, or empty array when the index cannot be searched.
     */
    public static function search(string $index_name, array $data, string $language='en', int $max_results=50, bool $boolean_mode=true, float $threshold=0.3, string $forced_algorithms='') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$start_micros=microtime(true);
		$results=[];
		if(false!==$search_results=self::find_in_index($index_name, $data, $language, $boolean_mode, $max_results, $threshold, $forced_algorithms)){
			self::sort_by_relevance($search_results);
			if(count($search_results)>$max_results){
				$search_results=array_slice($search_results, 0, $max_results);
			}
			$results['results']=$search_results;
			$results['count']=$result_count=count($results['results']);
			$average_score=0;
			$total_score=0;
			if($result_count>0){
				foreach($results['results'] as $result){
					foreach($result as $score){
						$total_score+=$score;
					}
				}
				$average_score=$total_score/$result_count;
			}
			$results['certainty']=$average_score;
			$results['time']=round(microtime(true)-$start_micros, 3);
		}
		return $results;
    }

	/**
	 * Counts numeric digits in a string for algorithm selection heuristics.
	 *
	 * @param string $str Text to inspect.
	 * @return int Number of digit characters.
	 */
	public static function count_digits(string $str) : int {
		return preg_match_all('/\d/', $str);
	}

	/**
	 * Counts normalized word-like terms in a string.
	 *
	 * @param string $text Text to inspect.
	 * @return int Number of Unicode letter/number/underscore terms.
	 */
	private static function count_terms(string $text): int {
		if($text===''){
			return 0;
		}
		preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($text), $matches);
		return count($matches[0] ?? []);
	}

	/**
	 * Converts free text into normalized search tokens.
	 *
	 * The pipeline removes stopwords, applies the best available stemmer,
	 * expands longer phrases with n-grams and smoothing, then delegates keyword
	 * extraction to produce the final token list.
	 *
	 * @param string $text Raw text from an indexed value or query.
	 * @param string $language Language hint; the first two characters select stopword/stemmer files.
	 * @return array<int, string> Extracted search tokens.
	 */
	public static function tokenize(string $text, string $language='en') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		if(strlen($text)>2){
			$text=self::remove_stopwords($text, $language);
			$text=self::apply_stemming($text, $language);
		}
		$word_count=count(explode(' ', $text));
		if($word_count>2){
			$text=fulltext_engine\ngram::apply_ngrams($text);
			$text=fulltext_engine\ngram::laplace_smoothing($text);
		}
		if(is_array($text)){
			$text=implode(' ', $text);
		}
		$text=fulltext_engine\keyword_extraction::extract_keywords($text, false, $language);
		return $text;
	}

	/**
	 * Scores one indexed value against one search value.
	 *
	 * Boolean mode parses the raw search expression and returns 1.0 or 0.0.
	 * Otherwise the method chooses a scoring algorithm from query length, term
	 * count, digit count, and optional override. Short terms prefer
	 * Jaccard/Damerau/Jaro-Winkler combinations, medium strings use Levenshtein
	 * variants, and long text falls back to BM25.
	 *
	 * @param string $index_value Tokenized indexed field value.
	 * @param string $search_value Tokenized search value.
	 * @param string $search_value_raw Raw query value used for boolean parsing.
	 * @param string $language Language hint.
	 * @param bool $boolean_mode Whether to evaluate the raw query as a boolean expression.
	 * @param string $forced_algorithms Optional algorithm override.
	 * @return float Normalized score from 0.0 to roughly 1.0, depending on algorithm.
	 */
	public static function get_score(string $index_value, string $search_value, string $search_value_raw, string $language='en', bool $boolean_mode=false, string $forced_algorithms='') : float{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$normalized_score=0;
		if($boolean_mode===true){
			$tokens=self::tokenize_expression($search_value_raw);
			$expr=self::parse_expression($tokens);
			return self::evaluate_expression($index_value, $expr);
		}
		$index_value_length=mb_strlen($index_value);
		$search_value_length=mb_strlen($search_value);
		$index_value_words=self::count_terms($index_value);
		$search_value_words=self::count_terms($search_value);
		$index_value_digits=self::count_digits($index_value);
		$search_value_digits=self::count_digits($search_value);
		$max_length=max($index_value_length, $search_value_length);
		$max_words=max($index_value_words, $search_value_words);
		$max_digits=max($index_value_digits, $search_value_digits);
		if(($search_value_words<=2 && empty($forced_algorithms)) || $forced_algorithms==='jaccard_damerau_lavenshtein1'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*5;
			if($normalized_score1<0.1) return $normalized_score1;
			$normalized_score2=0;
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$score=1-($score/$max_length);
				$normalized_score2=$score*1;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		}
		elseif(($search_value_words<=3 && empty($forced_algorithms)) || $forced_algorithms==='jaccard_damerau_lavenshtein2'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*3;
			if($normalized_score1<0.1) return $normalized_score1;
			$normalized_score2=0;
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$score=1-($score/$max_length);
				$normalized_score2=$score*1;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		}
		elseif(($max_length<=10 && $max_words<=10 && empty($forced_algorithms)) || $forced_algorithms==='jaccard_winkler'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*1;
			if($normalized_score1<0.1) return $normalized_score1;
			$normalized_score2=0;
			if(false!==$score=fulltext_engine\jaro_winkler::similarity($index_value, $search_value)){
				$normalized_score2=$score;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		}
		elseif(($max_length<=20 && $max_words<=50 && $max_digits<=5 && empty($forced_algorithms)) || $forced_algorithms==='lavenshtein'){
			if(false!==$score=levenshtein($index_value, $search_value)){
				$normalized_score=1-($score/$max_length);
			}
		}
		elseif(($max_length<=50 && $max_words<=50 && $max_digits>5 && empty($forced_algorithms)) || $forced_algorithms==='damerau_lavenshtein'){
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$normalized_score=1-($score/$max_length);
			}
		}
		elseif((($max_length>50 || $max_words>10) && empty($forced_algorithms)) || $forced_algorithms==='bm25'){
			$normalized_score=fulltext_engine\bm25::similarity($index_value, $search_value);
		}
		else
		{
			similar_text($index_value, $search_value, $score);
			$normalized_score=$score/100;
			if($normalized_score>1){
				$normalized_score=1;
			}
		}
		return $normalized_score;
	}

	/**
	 * Extracts unique lowercase term tokens from a string.
	 *
	 * @param string $string Text to tokenize without stopword or stemming passes.
	 * @return array<int, string> Unique Unicode word tokens in encounter order.
	 */
	public static function tokenize_string(string $string) : array {
		if(isset(self::$tokenize_cache[$string])){
			return self::$tokenize_cache[$string];
		}
		$original=$string;
		$string=mb_strtolower($string);
		if($string===''){
			return self::remember_tokenized_string($original, []);
		}
		preg_match_all('/[\p{L}\p{N}_]+/u', $string, $matches);
		$tokens=$matches[0] ?? [];
		return self::remember_tokenized_string($original, array_values(array_unique($tokens)));
	}

	private static function remember_tokenized_string(string $string, array $tokens): array {
		if(count(self::$tokenize_cache)>=self::TOKENIZE_CACHE_LIMIT){
			self::$tokenize_cache=[];
		}
		self::$tokenize_cache[$string]=$tokens;
		return $tokens;
	}

	/**
	 * Evaluates a parsed boolean search expression against one indexed value.
	 *
	 * Expressions are expected in reverse-polish form from
	 * {@see self::parse_expression()}. Terms prefixed with `+` are required,
	 * terms prefixed with `-` are excluded, and unprefixed terms require a
	 * case-insensitive substring match.
	 *
	 * @param string $index_value Tokenized indexed field value.
	 * @param array<int, string> $expression Reverse-polish expression tokens.
	 * @return bool True when the expression matches the indexed value.
	 */
	public static function evaluate_expression(string $index_value, array $expression) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$stack=[];
		foreach($expression as $token){
			if($token==='AND' || $token==='OR'){
				$right=(bool)array_pop($stack);
				$left=(bool)array_pop($stack);
				$stack[]=$token==='AND'
					? ($left && $right)
					: ($left || $right);
			}
			elseif($token==='NOT'){
				$stack[]=!(bool)array_pop($stack);
			}
			else
			{
				$required=substr($token, 0, 1)==='+';
				$excluded=substr($token, 0, 1)==='-';
				$term=$required || $excluded ? substr($token, 1) : $token;
				if($term===''){
					continue;
				}
				$match=preg_match('/'.preg_quote($term, '/').'/iu', $index_value)===1;
				$stack[]=$excluded ? !$match : $match;
			}
		}
		return !empty($stack) ? (bool)array_pop($stack) : false;
	}

	/**
	 * Tokenizes a boolean search string into operands, operators, and parentheses.
	 *
	 * Supported operators are `AND`, `OR`, `NOT`, parentheses, and `+`/`-`
	 * prefixes on individual terms.
	 *
	 * @param string $search_value Raw boolean query text.
	 * @return array<int, string> Expression tokens.
	 */
	public static function tokenize_expression(string $search_value) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		preg_match_all('/\(|\)|\bAND\b|\bOR\b|\bNOT\b|[+\-]?[^()\s]+/iu', $search_value, $matches);
		$expr=[];
		foreach($matches[0] ?? [] as $part){
			$part=trim((string)$part);
			if($part===''){
				continue;
			}
			if(preg_match('/^(AND|OR|NOT|\(|\))$/i', $part)){
				$part=strtoupper(trim($part));
			}
			$expr[]=$part;
		}
		return $expr;
	}

	/**
	 * Parses expression tokens into reverse-polish notation.
	 *
	 * Uses shunting-yard precedence where `NOT` binds tighter than `AND`, which
	 * binds tighter than `OR`. Unknown tokens are treated as search operands.
	 *
	 * @param array<int, string> $tokens Tokens from {@see self::tokenize_expression()}.
	 * @return array<int, string> Reverse-polish expression.
	 */
	public static function parse_expression(array $tokens) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$output=[];
		$operators=[];
		$precedence=[
			'OR'=>1,
			'AND'=>2,
			'NOT'=>3,
		];
		foreach($tokens as $token){
			if($token==='('){
				$operators[]=$token;
			}
			elseif($token===')'){
				while(!empty($operators) && end($operators)!=='('){
					$output[]=array_pop($operators);
				}
				if(!empty($operators) && end($operators)==='('){
					array_pop($operators);
				}
			}
			elseif(isset($precedence[$token])){
				while(
					!empty($operators)
					&& end($operators)!=='('
					&& (($precedence[end($operators)] ?? 0)>=$precedence[$token])
				){
					$output[]=array_pop($operators);
				}
				$operators[]=$token;
			}
			else
			{
				$output[]=$token;
			}
		}
		while(!empty($operators)){
			$operator=array_pop($operators);
			if($operator!=='(' && $operator!==')'){
				$output[]=$operator;
			}
		}
		return $output;
	}

    /**
     * Replaces an existing indexed entry across the configured backend.
     *
     * The configured primary-key field must be present in `$values`; all other
     * fields are tokenized before persistence. JSON and SQLite backends scan
     * local shards, SQL updates the backend table, and external engines receive
     * the raw entry data through their adapter.
     *
     * @param string $index_name Configured index name.
     * @param array<string, mixed> $values Entry values including the primary-key field.
     * @param string $language Language hint for tokenization.
     * @return bool True when an existing entry was updated.
     */
    public static function update_in_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$definition=self::index_definition($index_name);
		if($definition===null){
			return false;
		}
		$primary_key=self::index_primary_key($index_name);
		if($primary_key===null || !array_key_exists($primary_key, $values)){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
		}
		$index_type=(string)($definition['type'] ?? 'json');
		$primary_key_value=$values[$primary_key];
		unset($values[$primary_key]);
		$values=self::tokenize_values($values, $language);

		if($index_type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$index_folder=self::index_storage_path('sqlite', $index_name);
			$fileid=0;
			while(true){
				$filepath=$index_folder."/".$fileid.".db";
				if(!file_exists($filepath)){
					break;
				}
				$db=new \SQLite3($filepath);
				$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key = :primary_key_value');
				$stmt->bindValue(':primary_key_value', $primary_key_value);
				$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
				if(($result['count'] ?? 0)>0){
					$values_json=json_encode($values);
					$stmt=$db->prepare('UPDATE entries SET index_value = :values_json WHERE primary_key = :primary_key_value');
					$stmt->bindValue(':primary_key_value', $primary_key_value);
					$stmt->bindValue(':values_json', $values_json);
					$updated=$stmt->execute()!==false;
					$db->close();
					return $updated;
				}
				$db->close();
				$fileid++;
			}
			return false;
		}
		if($index_type==='sql'){
			$table=self::sql_index_table($index_name);
			if($table===false){
				return false;
			}
			if(self::sql_backend_create_table($index_name, $primary_key)!==true){
				return false;
			}
			$updated=sql_update(
				$L=$table,
				$F=['index_value'=>self::sql_backend_entry_json($values)],
				$P="WHERE ".$primary_key."=?",
				$V=[$primary_key_value],
				$CC=true
			);
			return $updated!==false && $updated!==null;
		}
		if($index_type==='elastic'){
			return fulltext_engine\elasticsearch::update($index_name, $values, $primary_key, $primary_key_value, $language);
		}
		if($index_type==='vespa'){
			return fulltext_engine\vespa::update($index_name, $values, $primary_key, $primary_key_value, $language);
		}
		if($index_type==='json'){
			$index_folder=self::ensure_index_directory('json', $index_name);
			$fileid=0;
			while(true){
				$filepath=$index_folder."/".$fileid;
				if(!file_exists($filepath)){
					break;
				}
				$current_index=json_decode((string)file_get_contents($filepath), true);
				if(!is_array($current_index)){
					$fileid++;
					continue;
				}
				if(array_key_exists((string)$primary_key_value, $current_index) || array_key_exists($primary_key_value, $current_index)){
					$current_index[$primary_key_value]=$values;
					return false!==core::file_put_contents_forced($filepath, json_encode($current_index));
				}
				$fileid++;
			}
		}
		return false;
	}

	/**
	 * Calculates how many backend candidates to inspect before final trimming.
	 *
	 * BM25 reranking needs a wider candidate pool so the final top-N list is
	 * not limited by the first-pass fuzzy score.
	 *
	 * @param int $max_results Requested public result count.
	 * @param array<string, mixed> $search_values_raw Raw search values.
	 * @param string $forced_algorithms Optional scoring algorithm override.
	 * @return int Positive candidate pool size.
	 */
	private static function candidate_pool_limit(int $max_results, array $search_values_raw, string $forced_algorithms): int {
		$max_results=max(1, $max_results);
		if(!self::should_rerank_with_bm25($search_values_raw, $forced_algorithms)){
			return $max_results;
		}
		return min(max($max_results, $max_results*4), $max_results+200);
	}

    /**
     * Adds a new entry to an existing index.
     *
     * The entry must include the configured primary-key field. Duplicate primary
     * keys are rejected for local JSON/SQLite stores and delegated to backend
     * adapters for SQL/external stores. Non-primary fields are tokenized before
     * local persistence.
     *
     * @param string $index_name Configured index name.
     * @param array<string, mixed> $values Entry values including the primary-key field.
     * @param string $language Language hint for tokenization.
     * @return bool True when the entry was inserted.
     */
    public static function add_to_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$definition=self::index_definition($index_name);
		if($definition===null){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index is not defined");
			return false;
		}
		$primary_key=self::index_primary_key($index_name);
		if($primary_key===null || !array_key_exists($primary_key, $values)){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
		}
		$index_type=(string)($definition['type'] ?? 'json');
		$primary_key_value=$values[$primary_key];
		unset($values[$primary_key]);
		$values=self::tokenize_values($values, $language);

		if($index_type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$index_folder=self::ensure_index_directory('sqlite', $index_name);
			$fileid=0;
			$entry_limit=self::index_entry_limit('sqlite');
			while(true){
				$filepath=$index_folder."/".$fileid.".db";
				$db=new \SQLite3($filepath);
				$db->exec('CREATE TABLE IF NOT EXISTS entries (primary_key TEXT, index_value TEXT)');
				$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key=:primary_key_value');
				$stmt->bindValue(':primary_key_value', $primary_key_value);
				$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
				if(($result['count'] ?? 0)>0){
					$db->close();
					return false;
				}
				$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries');
				$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
				if(($result['count'] ?? 0)<$entry_limit){
					break;
				}
				$db->close();
				$fileid++;
			}
			$values_json=json_encode($values);
			$stmt=$db->prepare('INSERT INTO entries (primary_key, index_value) VALUES (:primary_key_value, :values_json)');
			$stmt->bindValue(':primary_key_value', $primary_key_value);
			$stmt->bindValue(':values_json', $values_json);
			$inserted=$stmt->execute()!==false;
			$db->close();
			return $inserted;
		}
		if($index_type==='sql'){
			$table=self::sql_index_table($index_name);
			if($table===false){
				return false;
			}
			if(self::sql_backend_create_table($index_name, $primary_key)!==true){
				return false;
			}
			$fields=[
				$primary_key=>$primary_key_value,
				'index_value'=>self::sql_backend_entry_json($values),
			];
			return false!==sql_insert($table, $fields, null, true);
		}
		if($index_type==='elastic'){
			return fulltext_engine\elasticsearch::add($index_name, $values, $primary_key, $primary_key_value, $language);
		}
		if($index_type==='vespa'){
			return fulltext_engine\vespa::add($index_name, $values, $primary_key, $primary_key_value, $language);
		}
		if($index_type==='json'){
			$index_folder=self::ensure_index_directory('json', $index_name);
			$fileid=0;
			$filepath=$index_folder."/".$fileid;
			$entry_limit=self::index_entry_limit('json');
			while(file_exists($filepath)){
				$current_index=json_decode((string)file_get_contents($filepath), true);
				if(!is_array($current_index)){
					$current_index=[];
				}
				if(array_key_exists((string)$primary_key_value, $current_index) || array_key_exists($primary_key_value, $current_index)){
					return false;
				}
				if(count($current_index)<$entry_limit){
					break;
				}
				$fileid++;
				$filepath=$index_folder."/".$fileid;
			}
			if(!isset($current_index) || !is_array($current_index)){
				$current_index=[];
			}
			$current_index[$primary_key_value]=$values;
			return false!==core::file_put_contents_forced($filepath, json_encode($current_index));
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
		return false;
	}

	/**
	 * Removes one entry from an index by primary-key value.
	 *
	 * Local backends scan shards until the key is found. JSON shard files are
	 * deleted when the removed entry leaves the shard empty.
	 *
	 * @param string $index_name Configured index name.
	 * @param string $primary_key_value Primary-key value to delete.
	 * @return bool True when an entry was removed.
	 */
	public static function remove_from_index(string $index_name, string $primary_key_value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$definition=self::index_definition($index_name);
		$primary_column_name=self::index_primary_key($index_name);
		if($definition===null || $primary_column_name===null){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
		}
		$index_type=(string)($definition['type'] ?? 'json');
		if($index_type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$index_folder=self::index_storage_path('sqlite', $index_name);
			$fileid=0;
			while(true){
				$filepath=$index_folder."/".$fileid.".db";
				if(!file_exists($filepath)){
					break;
				}
				$db=new \SQLite3($filepath);
				$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key = :primary_key_value');
				$stmt->bindValue(':primary_key_value', $primary_key_value);
				$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
				if(($result['count'] ?? 0)>0){
					$stmt=$db->prepare('DELETE FROM entries WHERE primary_key = :primary_key_value');
					$stmt->bindValue(':primary_key_value', $primary_key_value);
					$deleted=$stmt->execute()!==false;
					$db->close();
					return $deleted;
				}
				$db->close();
				$fileid++;
			}
			return false;
		}
		if($index_type==='sql'){
			$table=self::sql_index_table($index_name);
			if($table===false){
				return false;
			}
			return sql_delete(
				$L=$table,
				$P="WHERE ".$primary_column_name."=?",
				$V=[$primary_key_value]
			)!==false;
		}
		if($index_type==='elastic'){
			return fulltext_engine\elasticsearch::remove($index_name, $primary_column_name, $primary_key_value);
		}
		if($index_type==='vespa'){
			return fulltext_engine\vespa::remove($index_name, $primary_column_name, $primary_key_value);
		}
		if($index_type==='json'){
			$index_folder=self::index_storage_path('json', $index_name);
			$fileid=0;
			while(true){
				$filepath=$index_folder."/".$fileid;
				if(!file_exists($filepath)){
					break;
				}
				$current_index=json_decode((string)file_get_contents($filepath), true);
				if(!is_array($current_index)){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed reading index.', 'safemode');
				}
				if(array_key_exists((string)$primary_key_value, $current_index) || array_key_exists($primary_key_value, $current_index)){
					unset($current_index[$primary_key_value], $current_index[(string)$primary_key_value]);
					core::file_put_contents_forced($filepath, json_encode($current_index));
					if(count($current_index)===0){
						unlink($filepath);
					}
					return true;
				}
				$fileid++;
			}
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed finding index for removal.', 'safemode');
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
		return false;
	}

	/**
	 * Deletes an index definition and its backend storage.
	 *
	 * Local backends remove their index directory, SQL drops the generated table,
	 * and external engines receive a delete-index call. The manifest is updated
	 * only after backend deletion succeeds.
	 *
	 * @param string $index_name Configured index name.
	 * @return bool True when backend storage and manifest state were removed.
	 */
	public static function delete_index(string $index_name) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::init();
		$index_definitions=self::index_definitions();
		if(!isset($index_definitions[$index_name])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index not defined");
			return false;
		}
		$type=(string)($index_definitions[$index_name]['type'] ?? 'json');
		$deleted=false;
		if($type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$deleted=core::force_rmdir(self::index_storage_path('sqlite', $index_name));
		}
		elseif($type==='sql'){
			$deleted=self::sql_backend_drop_table($index_name);
		}
		elseif($type==='elastic'){
			$deleted=fulltext_engine\elasticsearch::delete_index($index_name);
		}
		elseif($type==='vespa'){
			$deleted=fulltext_engine\vespa::delete_index($index_name);
		}
		elseif($type==='json'){
			$deleted=core::force_rmdir(self::index_storage_path('json', $index_name));
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
			return false;
		}
		if($deleted!==true){
			return false;
		}
		unset($index_definitions[$index_name]);
		if(self::persist_index_definitions($index_definitions)) return true;
		core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed reading index definition.', 'safemode');
		return false;
	}

	/**
	 * Creates an index definition and prepares its backend storage.
	 *
	 * Index and primary-key names are restricted to bare identifiers because
	 * SQL storage interpolates them into table and column names. Supported
	 * backends are `json`, `sqlite`, `sql`, `elastic`, and `vespa`.
	 *
	 * @param string $index_name New index identifier.
	 * @param string $primary_key_column_name Entry identity field.
	 * @param string $type Backend type.
	 * @param mixed $language Language hint forwarded to external backends where relevant.
	 * @return bool True when backend initialization and manifest persistence succeed.
	 */
	public static function create_index(string $index_name, string $primary_key_column_name, string $type="json", $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		self::init();
		$index_name=self::normalize_identifier($index_name);
		$primary_key_column_name=self::normalize_identifier($primary_key_column_name);
		$type=strtolower(trim($type));
		if($index_name==='' || $primary_key_column_name===''){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T='Invalid index identifier', $S='warning');
			return false;
		}
		$index_definitions=self::index_definitions();
		if(isset($index_definitions[$index_name])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index already defined");
			return false;
		}
		if($type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$index_definitions[$index_name]=array(
				"type"=>$type,
				"primary_key_column_name"=>$primary_key_column_name
			);
			self::ensure_index_directory('sqlite', $index_name);
		}
		elseif($type==='sql'){
			$index_definitions[$index_name]=array(
				"type"=>$type,
				"primary_key_column_name"=>$primary_key_column_name
			);
			if(self::sql_backend_create_table($index_name, $primary_key_column_name)!==true){
				return false;
			}
		}
		elseif($type==='vespa'){
			$index_definitions[$index_name]=array(
				"type"=>$type,
				"primary_key_column_name"=>$primary_key_column_name
			);
			if(fulltext_engine\vespa::create_index($index_name, $primary_key_column_name)!==true){
				return false;
			}
		}
		elseif($type==='elastic'){
			$index_definitions[$index_name]=array(
				"type"=>$type,
				"primary_key_column_name"=>$primary_key_column_name
			);
			if(fulltext_engine\elasticsearch::create_index($index_name, $primary_key_column_name, $language)!==true){
				return false;
			}
		}
		elseif($type==='json'){
			$index_definitions[$index_name]=array(
				"type"=>$type,
				"primary_key_column_name"=>$primary_key_column_name
			);
			self::ensure_index_directory('json', $index_name);
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type \"$type\"", "fatal");
			return false;
		}
		if(self::persist_index_definitions($index_definitions)) return true;
		return false;
	}

	/**
	 * Searches backend storage and returns untrimmed scored primary-key matches.
	 *
	 * This is the lower-level search primitive used by {@see self::search()}.
	 * It tokenizes search values, reads backend candidates, applies the common
	 * PHP scoring pipeline, and returns false only when the index or backend
	 * cannot be resolved.
	 *
	 * @param string $index_name Configured index name.
	 * @param array<string, mixed> $search_data Field-to-query search data.
	 * @param string $language Language hint for tokenization.
	 * @param bool $boolean_mode Whether raw query values should be parsed as boolean expressions.
	 * @param int $max_results Requested public result count used to size the candidate pool.
	 * @param float $threshold Minimum accepted score.
	 * @param string $forced_algorithms Optional scoring algorithm override.
	 * @return false|array<int, array<string, float>> Candidate result rows shaped as `[primaryKey => score]`.
	 */
	public static function find_in_index(string $index_name, array $search_data, string $language='en', bool $boolean_mode=false, int $max_results=50, float $threshold=0.85, string $forced_algorithms='') : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$result_primarykeys=[];
		$definition=self::index_definition($index_name);
		if($definition===null){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index $index_name does not exist", $S='fatal');
			return false;
		}
		$search_values_raw=$search_data;
		foreach($search_data as $key=>$value){
			$search_value=self::tokenize((string)$value, $language);
			$search_data[$key]=implode(' ', $search_value);
		}
		$candidate_limit=self::candidate_pool_limit($max_results, $search_values_raw, $forced_algorithms);
		$index_type=(string)($definition['type'] ?? 'json');
		if($index_type==='sqlite'){
			if(!extension_loaded('sqlite3')){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
			}
			$index_folder=self::index_storage_path('sqlite', $index_name);
			$fileid=0;
			while($candidate_limit>count($result_primarykeys)){
				$filepath=$index_folder."/".$fileid.".db";
				if(!file_exists($filepath)){
					break;
				}
				else
				{
					$db=new \SQLite3($filepath);
					$stmt=$db->prepare('SELECT * FROM entries');
					$results=$stmt->execute();
					while($row=$results->fetchArray(SQLITE3_ASSOC)){
						$primary_key=$row['primary_key'];
						$entry=json_decode($row['index_value'], true);
						if(is_array($entry)){
							self::append_entry_matches($result_primarykeys, $primary_key, $entry, $search_data, $search_values_raw, $language, $boolean_mode, $threshold, $forced_algorithms);
						}
					}
					$db->close();
					$fileid++;
				}
			}
			return self::finalize_result_matches($result_primarykeys, $search_values_raw, $forced_algorithms);
		}
		elseif($index_type==='sql'){
			$primary_column_name=self::index_primary_key($index_name);
			$table=self::sql_index_table($index_name);
			if($primary_column_name===null || $table===false){
				return false;
			}
			$prefilter=self::sql_search_prefilter($search_data, $boolean_mode, $candidate_limit);
			$rows=sql_select(
				$S="*",
				$L=$table,
				$P=$prefilter['params'],
				$V=$prefilter['vars'],
				$F=true
			);
			if($rows===false){
				$rows=sql_select(
					$S="*",
					$L=$table,
					$P=' LIMIT '.max(1, $candidate_limit),
					$V=[],
					$F=true
				);
			}
			if($rows!==false){
				foreach($rows as $row){
					$normalized_row=self::sql_backend_entry_from_row($row, $primary_column_name);
					if($normalized_row===null){
						continue;
					}
					self::append_entry_matches(
						$result_primarykeys,
						$normalized_row['primary_key'],
						$normalized_row['entry'],
						$search_data,
						$search_values_raw,
						$language,
						$boolean_mode,
						$threshold,
						$forced_algorithms
					);
					if(count($result_primarykeys)>=$candidate_limit){
						break;
					}
				}
				return self::finalize_result_matches($result_primarykeys, $search_values_raw, $forced_algorithms);
			}
		}
		elseif($index_type==='elastic'){
			$primary_column_name=self::index_primary_key($index_name);
			return fulltext_engine\elasticsearch::find($index_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold);
		}
		elseif($index_type==='vespa'){
			$primary_column_name=self::index_primary_key($index_name);
			return fulltext_engine\vespa::find($index_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold);
		}
		elseif($index_type==='json'){
			$index_folder=self::index_storage_path('json', $index_name);
			$fileid=0;
			while($candidate_limit>count($result_primarykeys)){
				$filepath=$index_folder."/".$fileid;
				if(!file_exists($filepath)){
					break;
				}
				else
				{
					if(false!==$current_index=json_decode((string)file_get_contents($filepath),true)){
						foreach($current_index as $primary_key=>$entry){
							if(is_array($entry)){
								self::append_entry_matches($result_primarykeys, $primary_key, $entry, $search_data, $search_values_raw, $language, $boolean_mode, $threshold, $forced_algorithms);
								if(count($result_primarykeys)>=$candidate_limit){
									break 2;
								}
							}
						}
					}
					else
					{
						core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed reading index.', 'safemode');
					}
					$fileid++;
				}
			}
			return self::finalize_result_matches($result_primarykeys, $search_values_raw, $forced_algorithms);
		}
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
		return false;
	}

	/**
	 * Loads stopwords for a language, falling back to English when available.
	 *
	 * Stopword files are selected by the first two characters of the language
	 * code and are expected to define `$stopwords`.
	 *
	 * @param string $language Locale or language code.
	 * @return array<int, string> Stopword list.
	 */
	public static function get_stopwords(string $language) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$language_prefix=substr($language, 0, 2);
		$file_path=dirname(__DIR__)."/stopwords/{$language_prefix}_stopwords.php";
		if(file_exists($file_path)){
			require($file_path);
		}
		elseif($language_prefix!=='en'){
			$fallback_path=dirname(__DIR__)."/stopwords/en_stopwords.php";
			if(file_exists($fallback_path)){
				require($fallback_path);
			}
		}
		return $stopwords ?? [];
	}

	/**
	 * Removes configured stopwords from a query string.
	 *
	 * The comparison is lowercase and whitespace-split, so punctuation handling
	 * is intentionally left to later tokenization stages.
	 *
	 * @param string $query Raw query text.
	 * @param string $language Locale or language code.
	 * @return string Query text with stopword terms removed.
	 */
	public static function remove_stopwords(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$stopwords=self::get_stopwords($language);
		$words=explode(' ', strtolower($query));
		$filtered_words=array_filter($words, function($word) use ($stopwords){ return !in_array($word, $stopwords); });
		$filtered_query=implode(' ', $filtered_words);
		return $filtered_query;
	}

	/**
	 * Applies the best available language stemmer to every whitespace term.
	 *
	 * Stemmer classes are loaded from module stemmer files by two-character
	 * language prefix, with an English fallback. When no stemmer is available,
	 * the original query is returned unchanged.
	 *
	 * @param string $query Query text after stopword removal.
	 * @param string $language Locale or language code.
	 * @return string Stemmed query text.
	 */
	public static function apply_stemming(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		$load_stemmer=function($language){
			$language_prefix=substr($language, 0, 2);
		$file_path=dirname(__DIR__)."/stemmers/{$language_prefix}_stemmer.php";
			if(file_exists($file_path)){
				require_once($file_path);
				$class_name="fulltext_engine\stemming\\".$language_prefix;
				if(class_exists($class_name)){
					return new $class_name();
				}
			}
			return null;
		};
		$stemmer=$load_stemmer($language);
		if(!$stemmer){
			$stemmer=$load_stemmer('en');
		}
		if(is_object($stemmer)){
			$words=explode(' ', $query);
			$stemmed_words=array_map(function($word)use($stemmer){
				return $stemmer->stem($word);
			}, $words);
			return implode(' ', $stemmed_words);
		}
		return $query;
	}

	/**
	 * Sorts result rows by descending relevance score.
	 *
	 * @param array<int, array<string, float>> $results Result rows mutated in place.
	 * @return array<int, array<string, float>> The sorted result rows.
	 */
	private static function sort_by_relevance(array &$results) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=null); // Log the function call
		usort($results, function($a, $b){
			$a_value=reset($a);
			$b_value=reset($b);
			if($a_value==$b_value){
				return 0;
			}
			return($a_value<$b_value)?-1:1;
		});
		$results=array_reverse($results);
		return $results;
	}

}
