<?php
 /*************************************************************************
 *  2020-2024 Shopiro Ltd.
 *  All Rights Reserved.
 * 
 * NOTICE: All information contained herein is, and remains the 
 * property of Shopiro Ltd. and is provided under a dual licensing model.
 * 
 * This software is available for personal use under the Free Personal Use License.
 * For commercial applications that generate revenue, a Commercial License must be 
 * obtained. See the LICENSE file for details.
 *
 * This software is provided "as is", without any warranty of any kind.
 */

namespace dataphyre;

tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Module initialization");

require_once(__DIR__."/similarity/jarowinkler.php");
require_once(__DIR__."/similarity/damerau_levenshtein.php");
require_once(__DIR__."/similarity/jaccard.php");

require_once(__DIR__."/keyword_extraction.php");
require_once(__DIR__."/ngram.php");

require_once(__DIR__."/external_engines/vespa.php");
require_once(__DIR__."/external_engines/elastic.php");

$configurations['dataphyre']['fulltext_engine']['fs_index_entry_count']=1000;
$configurations['dataphyre']['fulltext_engine']['fs_index_entry_count_for_sql']=100000;

$configurations['dataphyre']['fulltext_engine']['indexes']=json_decode(file_get_contents($rootpath['dataphyre']."config/fulltext_engine/indexes.json"), true);

class fulltext_engine{

    public static function search(string $index_name, array $data, string $language='en', int $max_results=50, bool $boolean_mode=true, float $threshold=0.3, string $forced_algorithms='') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$start_micros=microtime(true);
		$results=[];
		if(false!==$search_results=self::find_in_index($index_name, $data, $language, $boolean_mode, $max_results, $threshold, $forced_algorithms)){
			self::sort_by_relevance($search_results);
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
	
	public static function tokenize(string $text, string $language='en') : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		if(strlen($text)>2){
			$text=self::remove_stopwords($text, $language);
			$text=self::apply_stemming($text, $language);
		}
		if($ct=count(explode(' ', $text))>2){
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$normalized_score=0;
		if($boolean_mode===true){
			$tokens=self::tokenize_expression($search_value_raw);
			$expr=self::parse_expression($tokens);
			return self::evaluate_expression($index_value, $expr);
		}
		$index_value_length=mb_strlen($index_value);
		$search_value_length=mb_strlen($search_value);
		$index_value_words=str_word_count($index_value);
		$search_value_words=str_word_count($search_value);
		$index_value_digits=self::count_digits($index_value);
		$search_value_digits=self::count_digits($search_value);
		$max_length=max($index_value_length, $search_value_length);
		$max_words=max($index_value_words, $search_value_words);
		$max_digits=max($index_value_digits, $search_value_digits);
		if($search_value_words<=2 && empty($forced_algorithms) || $forced_algorithms==='jaccard_damerau_lavenshtein1'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*5;
			if($normalized_score1<0.1) return $normalized_score1;
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$score=1-($score/$max_length);
				$normalized_score2=$score*1;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		}
		elseif($search_value_words<=3 && empty($forced_algorithms) || $forced_algorithms==='jaccard_damerau_lavenshtein2'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*3;
			if($normalized_score1<0.1) return $normalized_score1;
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$score=1-($score/$max_length);
				$normalized_score2=$score*1;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		}
		elseif($max_length<=10 && $max_words<=10 && empty($forced_algorithms) || $forced_algorithms==='jaccard_winkler'){
			$score=fulltext_engine\jaccard::similarity($index_value, $search_value);
			$normalized_score1=$score*1;
			if($normalized_score1<0.1) return $normalized_score1;
			if(false!==$score=fulltext_engine\jaro_winkler::similarity($index_value, $search_value)){
				$normalized_score2=$score;
			}
			$normalized_score=$normalized_score1+$normalized_score2;
		} 
		elseif($max_length<=20 && $max_words<=50 && $max_digits<=5 && empty($forced_algorithms) || $forced_algorithms==='lavenshtein'){
			if(false!==$score=levenshtein($index_value, $search_value)){
				$normalized_score=1-($score/$max_length);
			}
		}
		elseif($max_length<=50 && $max_words<=50 && $max_digits>5 && empty($forced_algorithms) || $forced_algorithms==='damerau_lavenshtein'){
			if(false!==$score=fulltext_engine\damerau_levenshtein::similarity($index_value, $search_value)){
				$normalized_score=1-($score/$max_length);
			}
		}
		else
		{
			if(false!==$score=similar_text($index_value, $search_value)){
				$normalized_score=$score/100;
			}
		}
		return $normalized_score;
	}
	
	public static function get_synonyms(string $word, string $language="en") : void {
		
	}
	
	public static function tokenize_string(string $string) : array {
		return array_unique(str_word_count(strtolower($string), 1));
	}
	
	public static function evaluate_expression(string $index_value, array $expression) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$stack=[];
		foreach($expression as $term){
			if($term==='AND' || $term==='OR' || $term==='NOT'){
				$stack[]=$term;
			}
			else
			{
				$required=substr($term, 0, 1)==='+';
				$excluded=substr($term, 0, 1)==='-';
				$term=$required || $excluded ? substr($term, 1):$term;
				$match=preg_match('/'.preg_quote($term).'/i', $index_value);
				if($excluded){
					$match=!$match;
				}
				if(!empty($stack)){
					$operator=array_pop($stack);
					if($operator==='AND'){
						$match=array_pop($stack) && $match;
					}
					elseif($operator==='OR'){
						$match=array_pop($stack) || $match;
					}
					elseif($operator==='NOT'){
						$match=array_pop($stack) && !$match;
					}
				}
				$stack[]=$match;
			}
		}
		return array_pop($stack);
	}

	public static function tokenize_expression(string $search_value) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$pattern='/(\(|\)|\+|-|AND\s+|OR\s+|NOT\s+)/i';
		$parts=preg_split($pattern, $search_value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		$expr=[];
		foreach($parts as $part){
			if(preg_match($pattern, $part)){
				$part=strtoupper(trim($part));
			}
			$expr[]=$part;
		}
		return $expr;
	}

	public static function parse_expression(array $tokens) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$output=[];
		$operators=[];
		foreach($tokens as $token){
			if($token==='(') {
				$operators[]=$token;
			}
			elseif($token===')'){
				while(($op=array_pop($operators))!=='('){
					$output[]=$op;
				}
			}
			elseif($token==='AND' || $token==='OR' || $token==='NOT'){
				$operators[]=$token;
			}
			else
			{
				$output[]=$token;
			}
		}
		return array_merge($output, array_reverse($operators));
	}

    public static function update_in_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        global $configurations;
		global $rootpath;
        $index_type=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['type'];
		if(!isset($values[$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']])){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
		}
		else
		{
			$primary_key_value=$values[$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']];
			$primary_key=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name'];
			unset($values[$primary_key]);
			if($index_type==='sqlite'){
				if(!extension_loaded('sqlite3')){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
				}
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/sqlite/".$index_name;
				$fileid=0;
				$found_file=false;
				while(true){
					$filepath=$index_folder."/".$fileid.".db";
					if(file_exists($filepath)){
						$db=new \SQLite3($filepath);
						$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key = :primary_key_value');
						$stmt->bindValue(':primary_key_value', $primary_key_value);
						$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
						if($result['count']>0){
							$found_file=true;
							break;
						}
						$db->close();
					}
					else
					{
						break;
					}
					$fileid++;
				}
				if(!$found_file){
					return false;
				}
				foreach($values as $key=>$value){
					$index_value=self::tokenize($value, $language);
					$values[$key]=implode(' ', $index_value);
				}
				$values_json=json_encode($values);
				$stmt=$db->prepare('UPDATE entries SET values = :values_json WHERE primary_key = :primary_key_value');
				$stmt->bindValue(':primary_key_value', $primary_key_value);
				$stmt->bindValue(':values_json', $values_json);
				if($stmt->execute()){
					$db->close();
					return true;
				}
				else
				{
					$db->close();
					return false;
				}
			}
			elseif($index_type==='sql'){
				$values_list=implode(',', array_keys($values));
				sql_update(
					$L="dataphyre_fulltext_engine.index_".$index_name, 
					$F=$values_list,
					$P="WHERE ?=?",
					$V=array($values, $primary_key, $primary_key_value),
					$CC=true
				);
			}
			elseif($index_type==='elastic'){
				fulltext_engine\elasticsearch::update($index_name, $values, $primary_key, $primary_key_value, $language);
			}
			elseif($index_type==='vespa'){
				fulltext_engine\vespa::update($index_name, $values, $primary_key, $primary_key_value, $language);
			}
			elseif($index_type==='json'){
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/json/".$index_name;
				$fileid=0;
				$filepath=$index_folder."/".$fileid;
				$found_file=false;
				while(file_exists($filepath)){
					$current_index=json_decode(file_get_contents($filepath), true);
					if(in_array($primary_key_value, $current_index)){
						return false;
					}
					if(count($current_index)<$configurations['dataphyre']['fulltext_engine']['fs_index_entry_count']){
						$found_file=true;
						break;
					}
					$fileid++;
					$filepath=$index_folder."/".$fileid;
				}
				if(!$found_file){
					$current_index=[];
				}
				foreach($values as $key=>$value){
					$index_value=self::tokenize($value, $language);
					$values[$key]=implode(' ', $index_value);
				}
				$current_index[$primary_key_value]=$values;
				if(false!==core::file_put_contents_forced($filepath, json_encode($current_index))){
					return true;
				}
			}
		}
	}

    public static function add_to_index(string $index_name, array $values, string $language='en') : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
        global $configurations;
		global $rootpath;
		if(!isset($configurations['dataphyre']['fulltext_engine']['indexes'][$index_name])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index is not defined");
		}
		else
		{
			$index_type=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['type'];
			if(!isset($values[$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']])){
				core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
			}
			else
			{
				$primary_key_value=$values[$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']];
				$primary_key=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name'];
				unset($values[$primary_key]);
				if($index_type==='sqlite'){
					if(!extension_loaded('sqlite3')){
						core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
					}
					$index_folder=$rootpath['dataphyre']."fulltext_indexes/sqlite/".$index_name;
					if (!is_dir($index_folder)) {
						mkdir($index_folder, 0777, true);
					}
					$fileid=0;
					while(true){
						$filepath=$index_folder."/".$fileid.".db";
						$db=new \SQLite3($filepath);
						$db->exec('CREATE TABLE IF NOT EXISTS entries (primary_key TEXT, index_value TEXT)');
						$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key=:primary_key_value');
						$stmt->bindValue(':primary_key_value', $primary_key_value);
						$result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
						if($result['count']>0){
							$db->close();
							return false;
						}
						$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries');
						$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
						if($result['count']<$configurations['dataphyre']['fulltext_engine']['fs_index_entry_count_for_sql']){
							break;
						}
						$db->close();
						$fileid++;
					}
					foreach($values as $key=>$value){
						$index_value=self::tokenize($value, $language);
						$values[$key]=implode(' ', $index_value);
					}
					$values_json=json_encode($values);
					$stmt = $db->prepare('INSERT INTO entries (primary_key, index_value) VALUES (:primary_key_value, :values_json)');
					$stmt->bindValue(':primary_key_value', $primary_key_value);
					$stmt->bindValue(':values_json', $values_json);
					if($stmt->execute()){
						$db->close();
						return true;
					}
					else
					{
						$db->close();
						return false;
					}
				}
				elseif($index_type==='sql'){
					$values_list=implode(',', array_keys($values));
					sql_insert(
						$L="dataphyre_fulltext_engine.index_".$index_name, 
						$F=$values_list,
						$V=array($values),
						$CC=true
					);
				}
				elseif($index_type==='elastic'){
					$result_primarykeys=fulltext_engine\elasticsearch::add($index_name, $values, $primary_column_name, $primary_key_value, $language);
				}
				elseif($index_type==='vespa'){
					$result_primarykeys=fulltext_engine\vespa::add($index_name, $values, $primary_column_name, $primary_key_value, $language);
				}
				elseif($index_type==='json'){
					$index_folder=$rootpath['dataphyre']."fulltext_indexes/json/".$index_name;
					$fileid=0;
					$filepath=$index_folder."/".$fileid;
					$found_file=false;
					while(file_exists($filepath)){
						$current_index=json_decode(file_get_contents($filepath), true);
						if(in_array($primary_key_value, $current_index)){
							return false;
						}
						if(count($current_index)<$configurations['dataphyre']['fulltext_engine']['fs_index_entry_count']){
							$found_file=true;
							break;
						}
						$fileid++;
						$filepath=$index_folder."/".$fileid;
					}
					if(!$found_file){
						$current_index=[];
					}
					foreach($values as $key=>$value){
						$index_value=self::tokenize($value, $language);
						$values[$key]=implode(' ', $index_value);
					}
					$current_index[$primary_key_value]=$values;
					if(false!==core::file_put_contents_forced($filepath, json_encode($current_index))){
						return true;
					}
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
					return false;
				}
			}
		}
	}

	public static function remove_from_index(string $index_name, string $primary_key_value) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		global $rootpath;
		if(!isset($values[$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']])){
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Primary key not found for index.', 'safemode');
		}
		else
		{
			$index_type=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['type'];
			if($index_type==='sqlite'){
				if(!extension_loaded('sqlite3')){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
				}
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/sqlite/".$index_name;
				$fileid=0;
				$found_file=false;
				while(true){
					$filepath=$index_folder."/".$fileid.".db";
					if(file_exists($filepath)){
						$db=new \SQLite3($filepath);
						$stmt=$db->prepare('SELECT COUNT(*) as count FROM entries WHERE primary_key = :primary_key_value');
						$stmt->bindValue(':primary_key_value', $primary_key_value);
						$result=$stmt->execute()->fetchArray(SQLITE3_ASSOC);
						if($result['count']>0){
							$found_file=true;
							break;
						}
						$db->close();
					}
					else
					{
						break;
					}
					$fileid++;
				}
				if(!$found_file){
					return false;
				}
				foreach($values as $key=>$value){
					$index_value=self::tokenize($value, $language);
					$values[$key]=implode(' ', $index_value);
				}
				$values_json=json_encode($values);
				$stmt=$db->prepare('DELETE FROM entries WHERE primary_key = :primary_key_value');
				$stmt->bindValue(':primary_key_value', $primary_key_value);
				if($stmt->execute()){
					$db->close();
					return true;
				}
				else
				{
					$db->close();
					return false;
				}
			}
			elseif($index_type==='sql'){
				sql_delete(
					$L="dataphyre_fulltext_engine.index_".$index_name, 
					$P="WHERE ".$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name']."=?", 
					$V=array($primary_key_value)
				);
			}
			elseif($index_type==='elastic'){
				$result_primarykeys=fulltext_engine\elasticsearch::remove($index_name, $primary_column_name, $primary_key_value);
			}
			elseif($index_type==='vespa'){
				$result_primarykeys=fulltext_engine\vespa::remove($index_name, $primary_column_name, $primary_key_value);
			}
			elseif($index_type==='json'){
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/json/".$index_name;
				$primary_column_name=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name'];
				$fileid=0;
				while(true){
					$filepath=$index_folder."/".$fileid;
					if(!file_exists($filepath)){
						break;
					}
					else
					{
						if(false!==$current_index=json_decode(file_get_contents($filepath),true)){
							if(in_array($primary_key_value, $current_index)){
								break;
							}
						}
						else
						{
							core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed reading index.', 'safemode');
						}
						$fileid++;
					}
				}
				if(!empty($current_index)){
					unset($current_index[$primary_key_value]);
					file_put_contents($filepath, json_encode($current_index));
					if(count($current_index)===0){
						unlink($filepath);
					}
				}
				else
				{
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed finding index for removal.', 'safemode');
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
				return false;
			}
		}
	}
	
	public static function delete_index(string $index_name) : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		global $rootpath;
		$filepath=$rootpath['dataphyre']."config/fulltext_engine/indexes.json";
		if(false!==$index_definitions=json_decode(file_get_contents($filepath),true)){
			if(isset($index_definitions[$index_name])){
				$type=$index_definitions[$index_name]['type'];
				if($type==='sqlite'){
					if(!extension_loaded('sqlite3')){
						core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
					}
					core::force_rmdir($rootpath['dataphyre']."fulltext_indexes/sqlite/".$index_name);
				}
				elseif($type==='sql'){
					// Delete sql index
				}
				elseif($type==='elastic'){
					fulltext_engine\elasticsearch::delete_index($index_name);
				}
				elseif($type==='json'){
					core::force_rmdir($rootpath['dataphyre']."fulltext_indexes/json/".$index_name);
				}
				else
				{
					tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
					return false;
				}
				unset($index_definitions[$index_name]);
				if(false!==file_put_contents($filepath, json_encode($index_definitions))){
					$configurations['dataphyre']['fulltext_engine']['indexes']=$index_definitions;
					return true;
				}
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index not defined");
			}
		}
		else
		{
			core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: Failed reading index definition.', 'safemode');
		}
		return false;
	}

	public static function create_index(string $index_name, string $primary_key_column_name, string $type="json") : bool {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		global $rootpath;
		$filepath=$rootpath['dataphyre']."config/fulltext_engine/indexes.json";
		$index_definitions=json_decode(file_get_contents($filepath),true);
		if(!isset($index_definitions[$index_name])){
			if($type==='sqlite'){
				if(!extension_loaded('sqlite3')){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
				}
				$index_definitions[$index_name]=array(
					"type"=>$type,
					"primary_key_column_name"=>$primary_key_column_name
				);
			}
			elseif($type==='sql'){
				$index_definitions[$index_name]=array(
					"type"=>$type,
					"primary_key_column_name"=>$primary_key_column_name
				);
			}
			elseif($type==='elastic'){
				$index_definitions[$index_name]=array(
					"type"=>$type,
					"primary_key_column_name"=>$primary_key_column_name
				);
				fulltext_engine\elasticsearch::create_index($index_name, $primary_key_column_name);
			}
			elseif($type==='json'){
				$index_definitions[$index_name]=array(
					"type"=>$type,
					"primary_key_column_name"=>$primary_key_column_name
				);
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type \"$type\"", "fatal");
				return false;
			}
			if(false!==core::file_put_contents_forced($filepath, json_encode($index_definitions))){
				$configurations['dataphyre']['fulltext_engine']['indexes']=$index_definitions;
				return true;
			}
		}
		else
		{
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index already defined");
		}
		return false;
	}
	
	public static function find_in_index(string $index_name, array $search_data, string $language='en', bool $boolean_mode=false, int $max_results=50, float $threshold=0.85, string $forced_algorithms='') : bool|array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		global $configurations;
		global $rootpath;
		$result_primarykeys=[];
		if(!isset($configurations['dataphyre']['fulltext_engine']['indexes'][$index_name])){
			tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Index $index_name does not exist", $S='fatal');
			return false;
		}
		else
		{
			$search_values_raw=$search_data;
			foreach($search_data as $key=>$value){
				$search_value=self::tokenize($value, $language);
				$search_data[$key]=implode(' ', $search_value);
			}
			$index_type=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['type'];
			if($index_type==='sqlite'){
				if(!extension_loaded('sqlite3')){
					core::unavailable(__DIR__,__FILE__,__LINE__,__CLASS__,__FUNCTION__, $D='DataphyreFulltextEngine: SQLite3 is not enabled in the current environment.', 'safemode');
				}
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/sqlite/".$index_name;
				$fileid=0;
				while($max_results>count($result_primarykeys)){
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
							foreach($entry as $key1=>$index_value){
								foreach($search_data as $key2=>$search_value){
									if($key1===$key2 || $key2==='*'){
										if($score=self::get_score($index_value, $search_value, $search_values_raw[$key2], $language, $boolean_mode, $forced_algorithms)){
											if($score>=$threshold){
												$result_primarykeys[]=array($primary_key=>$score);
											}
										}
									}
								}
							}
						}
						$db->close();
						$fileid++;
					}
				}
			}
			elseif($index_type==='sql'){
				$primary_column_name=$configurations['dataphyre']['fulltext_engine']['indexes'][$index_name]['primary_key_column_name'];
				foreach($search_data as $key=>$value){
					$extracted=fulltext_engine\keyword_extraction::extract_keywords($value, false, $language);
					$bigram=fulltext_engine_ngram::bigram(implode(' ', $extracted));
					$smoothed=fulltext_engine_ngram::laplace_smoothing($ngrams);
					$search_data[$key]=implode(' ', $smoothed);
				}
				$query_fields=implode(',',array_keys($search_data));
				if(false!==$rows=sql_select(
					$S=$primary_column_name.", MATCH(?) AGAINST(? IN NATURAL LANGUAGE MODE) as score", 
					$L="dataphyre_fulltext_engine.index_".$index_name, 
					$P="WHERE MATCH(?) AGAINST(? IN NATURAL LANGUAGE MODE) LIMIT ?", 
					$V=array($search_data, $query_fields, $search_data, $query_fields, $max_results), 
					$F=true
				)){
					foreach($rows as $row){
						$result_primarykeys[]=array($row[$primary_column_name]=>$row['score']);
					}
				}
			}
			elseif($index_type==='elastic'){
				$result_primarykeys=fulltext_engine\elasticsearch::find($index_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold);
			}
			elseif($index_type==='vespa'){
				$result_primarykeys=fulltext_engine\vespa::find($index_name, $search_data, $primary_column_name, $boolean_mode, $language, $max_results, $threshold);
			}
			elseif($index_type==='json'){
				$index_folder=$rootpath['dataphyre']."fulltext_indexes/json/".$index_name;
				$fileid=0;
				while($max_results>count($result_primarykeys)){
					$filepath=$index_folder."/".$fileid;
					if(!file_exists($filepath)){
						break;
					}
					else
					{
						if(false!==$current_index=json_decode(file_get_contents($filepath),true)){
							foreach($current_index as $primary_key=>$entry){
								foreach($entry as $key1=>$index_value){
									if($max_results>count($result_primarykeys)){
										foreach($search_data as $key2=>$search_value){
											if($key1===$key2 || $key2==='*'){
												if($score=self::get_score($index_value, $search_value, $search_values_raw[$key2], $language, $boolean_mode, $forced_algorithms)){
													if($score>=$threshold){
														$result_primarykeys[]=array($primary_key=>$score);
													}
												}
											}
										}
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
			}
			else
			{
				tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T="Unknown index type");
				return false;
			}
			return $result_primarykeys;
		}
		return false;
	}
	
	public static function get_stopwords(string $language) : array {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$include=function($language)use(&$stopwords){
			$filePath=__DIR__."/stopwords/{$language}_stopwords.php";
			if(file_exists($filePath)){
				require($filePath);
			}
			else
			{
				if($language!=='en'){
					$include('en');
				}
			}
		};
		$language_prefix=substr($language, 0, 2);
		$include($language_prefix);
		return $stopwords ?? [];
	}

	public static function remove_stopwords(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$stopwords=self::get_stopwords($language);
		$words=explode(' ', strtolower($query));
		$filteredWords=array_filter($words, function($word) use ($stopwords){ return !in_array($word, $stopwords); });
		$filteredQuery=implode(' ', $filteredWords);
		return $filteredQuery;
	}

	public static function apply_stemming(string $query, string $language) : string {
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
		$load_stemmer=function($language){
			$language_prefix=substr($language, 0, 2);
			$file_path=__DIR__."/stemmers/{$language_prefix}_stemmer.php";
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
		tracelog(__FILE__,__LINE__,__CLASS__,__FUNCTION__, $T=null, $S='function_call', $A=func_get_args()); // Log the function call
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