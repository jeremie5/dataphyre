<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Builds n-gram token maps for full-text query expansion.
 *
 * The helper lowercases and strips punctuation from search text, derives
 * contiguous word windows, and exposes a legacy smoothing helper used by older
 * full-text ranking experiments.
 */
class ngram{
	
	/**
	 * Applies legacy Laplace smoothing over bigram counts.
	 *
	 * The method computes smoothed probabilities for each bigram but preserves
	 * the historical return shape: a space-separated string of bigram keys.
	 *
	 * @param array<string,int|float> $bigram Bigram frequency map.
	 * @param int $alpha Additive smoothing factor.
	 * @return string Space-separated bigram keys in input iteration order.
	 */
	public static function laplace_smoothing(array $bigram, int $alpha=1){
		$total_possible_bigrams=0;
		$total_count=0;
		foreach($bigram as $count){
			$total_count+=$count;
			$total_possible_bigrams++;
		}
		$smoothed_bigram=[];
		$result_array=[];
		foreach($bigram as $bigram_key=>$count){
			$smoothed_bigram[$bigram_key]=($count+$alpha)/($total_count+$alpha*$total_possible_bigrams);
			$result_array[]=$bigram_key;
		}
		return implode(' ', $result_array);
	}
	
	/**
	 * Chooses an n-gram size for a search query and returns its frequency map.
	 *
	 * Queries of one through four words use that exact n; longer queries are
	 * capped at five-word windows to avoid unbounded sparse maps.
	 *
	 * @param string $search_query Raw user search query.
	 * @return array<string,int> N-gram frequency map.
	 */
	public static function apply_ngrams(string $search_query){
		if(is_string($search_query)){
			$tokenized_query=explode(' ', $search_query);
		}
		$word_count=count($tokenized_query);
		if($word_count==1){
			$ngrams=self::ngram($search_query, 1);
		}
		elseif($word_count==2){
			$ngrams=self::ngram($search_query, 2);
		}
		elseif($word_count==3){
			$ngrams=self::ngram($search_query, 3);
		}
		elseif($word_count==4){
			$ngrams=self::ngram($search_query, 4);
		}
		elseif($word_count>=5){
			$ngrams=self::ngram($search_query, 5);
		}
		return $ngrams;
	}
	
	/**
	 * Builds a contiguous n-gram frequency map from text.
	 *
	 * Text is lowercased with mbstring, stripped of punctuation, split on spaces,
	 * then counted by n-word window.
	 *
	 * @param string $text Raw text to tokenize.
	 * @param int $n Number of words per n-gram window.
	 * @return array<string,int> Frequency map keyed by n-gram phrase.
	 */
	public static function ngram(string $text, int $n){
		$processed_text=preg_replace('/[^\w\s]/', '', mb_strtolower($text));
		$words=explode(' ', $processed_text);
		$ngram=[];
		$word_count=count($words);
		for($i=0; $i<$word_count-$n+1; $i++){
			$ngram_key=implode(' ', array_slice($words, $i, $n));
			if(array_key_exists($ngram_key, $ngram)){
				$ngram[$ngram_key]++;
			}
			else
			{
				$ngram[$ngram_key]=1;
			}
		}
		return $ngram;
	}
	
}
