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

class fulltext_engine{

	private static bool $initialized=false;
	private static array $index_definitions=[];

	private static function index_definitions(): array {
		self::init();
		$definitions=self::$index_definitions;
		return is_array($definitions) ? $definitions : [];
	}

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

	private static function indexes_definition_path(): string {
		return ROOTPATH['dataphyre']."config/fulltext_engine/indexes.json";
	}

	private static function index_storage_path(string $type, string $index_name=''): string {
		$base=ROOTPATH['dataphyre']."fulltext_indexes/".$type;
		return $index_name!=='' ? $base."/".$index_name : $base;
	}

	private static function index_definition(string $index_name): ?array {
		self::init();
		$definition=self::$index_definitions[$index_name] ?? null;
		return is_array($definition) ? $definition : null;
	}

	private static function index_primary_key(string $index_name): ?string {
		$definition=self::index_definition($index_name);
		$primary_key=$definition['primary_key_column_name'] ?? null;
		return is_string($primary_key) && $primary_key!=='' ? $primary_key : null;
	}

	private static function index_entry_limit(string $type): int {
		$key=$type==='sqlite' ? 'fs_index_entry_count_for_sql' : 'fs_index_entry_count';
		return max(1, (int)DP_FULLTEXT_ENGINE_CFG[$key]);
	}

	private static function persist_index_definitions(array $index_definitions): bool {
		$filepath=self::indexes_definition_path();
		if(false!==core::file_put_contents_forced($filepath, json_encode($index_definitions))){
			self::$index_definitions=$index_definitions;
			self::$initialized=true;
			return true;
		}
		return false;
	}

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

	public static function get_index_definition(string $index_name): ?array {
		$definition=self::index_definition($index_name);
		if($definition===null){
			return null;
		}
		return array_merge(['name'=>$index_name], $definition);
	}

	public static function index_exists(string $index_name): bool {
		return self::index_definition($index_name)!==null;
	}

	private static function ensure_index_directory(string $type, string $index_name): string {
		$directory=self::index_storage_path($type, $index_name);
		if(!is_dir($directory)){
			mkdir($directory, 0777, true);
		}
		return $directory;
	}

	private static function normalize_identifier(string $identifier): string {
		$identifier=trim($identifier);
		return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) ? $identifier : '';
	}

	private static function sql_index_table(string $index_name): string|false {
		$normalized=self::normalize_identifier($index_name);
		return $normalized!=='' ? "dataphyre_fulltext_engine.index_".$normalized : false;
	}

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

	private static function sql_backend_entry_payload(array $values): string {
		return json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

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

	private static function tokenize_values(array $values, string $language): array {
		foreach($values as $key=>$value){
			$index_value=self::tokenize((string)$value, $language);
			$values[$key]=implode(' ', $index_value);
		}
		return $values;
	}

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

    public static function search(string $index_name, array $data, string $language='en', int $max_results=50, bool $boolean_mode=true, float $threshold=0.3, string $forced_algorithms='') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function count_digits(string $str) : int {
		return preg_match_all('/\d/', $str);
	}

	private static function count_terms(string $text): int {
		if($text===''){
			return 0;
		}
		preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($text), $matches);
		return count($matches[0] ?? []);
	}
	
	public static function tokenize(string $text, string $language='en') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function get_score(string $index_value, string $search_value, string $search_value_raw, string $language='en', bool $boolean_mode=false, string $forced_algorithms='') : float{
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function tokenize_string(string $string) : array {
		$string=mb_strtolower($string);
		if($string===''){
			return [];
		}
		preg_match_all('/[\p{L}\p{N}_]+/u', $string, $matches);
		$tokens=$matches[0] ?? [];
		return array_values(array_unique($tokens));
	}
	
	public static function evaluate_expression(string $index_value, array $expression) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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

	public static function tokenize_expression(string $search_value) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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

	public static function parse_expression(array $tokens) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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

    public static function update_in_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
				$F=['index_value'=>self::sql_backend_entry_payload($values)],
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

	private static function candidate_pool_limit(int $max_results, array $search_values_raw, string $forced_algorithms): int {
		$max_results=max(1, $max_results);
		if(!self::should_rerank_with_bm25($search_values_raw, $forced_algorithms)){
			return $max_results;
		}
		return min(max($max_results, $max_results*4), $max_results+200);
	}

    public static function add_to_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
				'index_value'=>self::sql_backend_entry_payload($values),
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

	public static function remove_from_index(string $index_name, string $primary_key_value) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function delete_index(string $index_name) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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

	public static function create_index(string $index_name, string $primary_key_column_name, string $type="json", $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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
	
	public static function find_in_index(string $index_name, array $search_data, string $language='en', bool $boolean_mode=false, int $max_results=50, float $threshold=0.85, string $forced_algorithms='') : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
	
	public static function get_stopwords(string $language) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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

	public static function remove_stopwords(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
		$stopwords=self::get_stopwords($language);
		$words=explode(' ', strtolower($query));
		$filteredWords=array_filter($words, function($word) use ($stopwords){ return !in_array($word, $stopwords); });
		$filteredQuery=implode(' ', $filteredWords);
		return $filteredQuery;
	}

	public static function apply_stemming(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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

	private static function sort_by_relevance(array &$results) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call_with_test', $A=func_get_args()); // Log the function call
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
