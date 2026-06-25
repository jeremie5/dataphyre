<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Computes BM25 full-text relevance scores for lightweight in-memory ranking.
 *
 * The scorer tokenizes Unicode letters, numbers, and underscores, normalizes optional corpora
 * into token lists, calculates BM25 raw scores, and exposes a normalized similarity value in
 * the `0.0..1.0` range for callers that need comparable scores.
 */
class bm25 {

	/**
	 * Returns a normalized BM25 similarity score for a document/query pair.
	 *
	 * When no corpus is supplied, the document and query form a tiny fallback corpus so inverse
	 * document frequency remains defined.
	 *
	 * @param string $document Candidate document text.
	 * @param string $query Search query text.
	 * @param array<int, string|array<int, string>>|null $corpus Optional corpus documents or pre-tokenized term lists.
	 * @param float $k1 Term-frequency saturation parameter.
	 * @param float $b Document-length normalization parameter.
	 * @return float Score clamped between `0.0` and `1.0`.
	 */
	public static function similarity(string $document, string $query, ?array $corpus=null, float $k1=1.2, float $b=0.75): float {
		$document_terms=self::tokenize($document);
		$query_terms=self::tokenize($query);
		if(empty($document_terms) || empty($query_terms)){
			return 0.0;
		}
		$corpus_terms=self::normalize_corpus($corpus);
		if(empty($corpus_terms)){
			$corpus_terms=[$document_terms, $query_terms];
		}
		$raw_score=self::raw_score_from_terms($document_terms, $query_terms, $corpus_terms, $k1, $b);
		$max_score=self::max_score_from_terms($query_terms, $corpus_terms, $k1);
		if($max_score<=0.0){
			return 0.0;
		}
		$normalized=$raw_score/$max_score;
		if($normalized<0.0){
			return 0.0;
		}
		if($normalized>1.0){
			return 1.0;
		}
		return $normalized;
	}

	/**
	 * Returns the unnormalized BM25 score for a document/query pair.
	 *
	 * @param string $document Candidate document text.
	 * @param string $query Search query text.
	 * @param array<int, string|array<int, string>>|null $corpus Optional corpus documents or pre-tokenized term lists.
	 * @param float $k1 Term-frequency saturation parameter.
	 * @param float $b Document-length normalization parameter.
	 * @return float Raw BM25 score.
	 */
	public static function raw_score(string $document, string $query, ?array $corpus=null, float $k1=1.2, float $b=0.75): float {
		$document_terms=self::tokenize($document);
		$query_terms=self::tokenize($query);
		if(empty($document_terms) || empty($query_terms)){
			return 0.0;
		}
		$corpus_terms=self::normalize_corpus($corpus);
		if(empty($corpus_terms)){
			$corpus_terms=[$document_terms, $query_terms];
		}
		return self::raw_score_from_terms($document_terms, $query_terms, $corpus_terms, $k1, $b);
	}

	/**
	 * Converts mixed corpus input into token-list documents.
	 *
	 * @param array<int, string|array<int, mixed>>|null $corpus Optional corpus values.
	 * @return array<int, array<int, string>> Normalized non-empty term lists.
	 */
	private static function normalize_corpus(?array $corpus): array {
		if(!is_array($corpus) || empty($corpus)){
			return [];
		}
		$normalized=[];
		foreach($corpus as $document){
			if(is_string($document)){
				$terms=self::tokenize($document);
			}
			elseif(is_array($document)){
				$terms=[];
				foreach($document as $term){
					$term=trim(mb_strtolower((string)$term));
					if($term!==''){
						$terms[]=$term;
					}
				}
			}
			else
			{
				continue;
			}
			if(!empty($terms)){
				$normalized[]=$terms;
			}
		}
		return $normalized;
	}

	/**
	 * Tokenizes text into lowercase searchable terms.
	 *
	 * @param string $text Source text.
	 * @return array<int, string> Unicode word tokens.
	 */
	private static function tokenize(string $text): array {
		$text=trim(mb_strtolower($text));
		if($text===''){
			return [];
		}
		preg_match_all('/[\p{L}\p{N}_]+/u', $text, $matches);
		return $matches[0] ?? [];
	}

	/**
	 * Calculates the BM25 raw score from pre-tokenized inputs.
	 *
	 * @param array<int, string> $document_terms Candidate document terms.
	 * @param array<int, string> $query_terms Query terms.
	 * @param array<int, array<int, string>> $corpus_terms Corpus term lists.
	 * @param float $k1 Term-frequency saturation parameter.
	 * @param float $b Document-length normalization parameter.
	 * @return float Raw BM25 score.
	 */
	private static function raw_score_from_terms(array $document_terms, array $query_terms, array $corpus_terms, float $k1, float $b): float {
		$document_term_frequency=array_count_values($document_terms);
		$query_term_frequency=array_count_values($query_terms);
		$average_document_length=self::average_document_length($corpus_terms);
		$document_length=max(1, count($document_terms));
		$score=0.0;
		foreach($query_term_frequency as $term=>$query_frequency){
			$term_frequency=(int)($document_term_frequency[$term] ?? 0);
			if($term_frequency<=0){
				continue;
			}
			$idf=self::inverse_document_frequency($term, $corpus_terms);
			if($idf<=0.0){
				continue;
			}
			$numerator=$term_frequency*($k1+1.0);
			$denominator=$term_frequency+($k1*(1.0-$b+($b*($document_length/max(1e-9, $average_document_length)))));
			$score+=$idf*($numerator/max(1e-9, $denominator))*max(1, (int)$query_frequency);
		}
		return $score;
	}

	/**
	 * Estimates the maximum possible score for a query within a corpus.
	 *
	 * @param array<int, string> $query_terms Query terms.
	 * @param array<int, array<int, string>> $corpus_terms Corpus term lists.
	 * @param float $k1 Term-frequency saturation parameter.
	 * @return float Maximum score used to normalize similarity.
	 */
	private static function max_score_from_terms(array $query_terms, array $corpus_terms, float $k1): float {
		$query_term_frequency=array_count_values($query_terms);
		$score=0.0;
		foreach($query_term_frequency as $term=>$query_frequency){
			$idf=self::inverse_document_frequency($term, $corpus_terms);
			if($idf<=0.0){
				continue;
			}
			$score+=$idf*($k1+1.0)*max(1, (int)$query_frequency);
		}
		return $score;
	}

	/**
	 * Calculates average document length for BM25 normalization.
	 *
	 * @param array<int, array<int, string>> $corpus_terms Corpus term lists.
	 * @return float Average document length, never below `1.0`.
	 */
	private static function average_document_length(array $corpus_terms): float {
		if(empty($corpus_terms)){
			return 1.0;
		}
		$total_length=0;
		foreach($corpus_terms as $document_terms){
			$total_length+=count($document_terms);
		}
		return max(1.0, $total_length/count($corpus_terms));
	}

	/**
	 * Calculates smoothed inverse document frequency for one term.
	 *
	 * @param string $term Query term.
	 * @param array<int, array<int, string>> $corpus_terms Corpus term lists.
	 * @return float Positive BM25 IDF value.
	 */
	private static function inverse_document_frequency(string $term, array $corpus_terms): float {
		$document_count=max(1, count($corpus_terms));
		$documents_with_term=0;
		foreach($corpus_terms as $document_terms){
			if(in_array($term, $document_terms, true)){
				$documents_with_term++;
			}
		}
		return log(1.0+(($document_count-$documents_with_term+0.5)/($documents_with_term+0.5)));
	}
}
