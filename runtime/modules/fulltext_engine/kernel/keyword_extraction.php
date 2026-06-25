<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Extracts candidate key phrases from text with a RAKE-style scoring pass.
 *
 * The extractor lowercases and tokenizes input, splits candidate phrases around
 * language stopwords and punctuation, scores words by degree divided by
 * frequency, and ranks phrases by the sum of their word scores. It is stateless
 * and relies on the fulltext engine module for language-specific stopwords.
 */
class keyword_extraction{

    /**
     * Extracts the highest-ranked keywords or scored key phrases from text.
     *
     * Candidate phrase scores are sorted descending and only the top third plus
     * one are returned, preserving the historical extraction threshold.
     *
     * @param string $text Source text to analyze.
     * @param bool $include_scores Whether to return phrase=>score pairs instead of phrase strings.
     * @param string $language Stopword language code.
     * @return array<string, float|int>|array<int, string> Scored phrases when requested, otherwise phrase strings.
     */
    public static function extract_keywords(string $text, bool $include_scores=true, string $language='en') : array {
        $phrases=self::generate_candidate_keywords($text, $language);
        $word_scores=self::calculate_word_scores($phrases);
        $phrase_scores=self::calculate_phrase_scores($phrases, $word_scores);
        arsort($phrase_scores);
        $one_third=ceil(count($phrase_scores)/3)+1;
        $phrase_scores=array_slice($phrase_scores, 0, $one_third);
        if($include_scores){
            return $phrase_scores;
        }
        return array_keys($phrase_scores);
    }

    /**
     * Splits tokenized text into candidate keyword phrases.
     *
     * Stopwords and punctuation terminate the current phrase. Remaining
     * non-stopword tokens are grouped in their original order for scoring.
     *
     * @param string $text Source text to tokenize.
     * @param string $language Stopword language code.
     * @return array<int, array<int, string>> Candidate phrases as token lists.
     */
    public static function generate_candidate_keywords(string $text, string $language) : array {
        $phrases=[];
        $words=self::tokenize($text);
        $phrase=[];
		$stopwords=\dataphyre\fulltext_engine::get_stopwords($language);
        foreach($words as $word){
            if(in_array($word, $stopwords) || ctype_punct($word)){
                if(count($phrase)>0){
                    $phrases[]=$phrase;
                    $phrase=[];
                }
            }
			else
			{
                $phrase[]=$word;
            }
        }
        if(count($phrase)>0){
            $phrases[]=$phrase;
            $phrase=[];
        }
        return $phrases;
    }

    /**
     * Scores each candidate phrase by summing its word scores.
     *
     *
     * @param array<int, array<int, string>> $phrases Candidate phrase token lists.
     * @param array<string, float|int> $word_scores Scores keyed by token.
     * @return array<string, float|int> Phrase scores keyed by space-joined phrase text.
     */
    public static function calculate_phrase_scores(array $phrases, array $word_scores) : array {
        $result=[];
        foreach($phrases as $phrase){
            $word_score=0;
            foreach($phrase as $word){
                $word_score+=$word_scores[$word];
            }
            $result[implode(" ", $phrase)]=$word_score;
        }
        return $result;
    }

    /**
     * Calculates RAKE-style scores for every word in the candidate phrases.
     *
     * A word score is its phrase-degree contribution divided by its frequency
     * across all candidate phrases.
     *
     * @param array<int, array<int, string>> $phrases Candidate phrase token lists.
     * @return array<string, float|int> Scores keyed by token.
     */
    public static function calculate_word_scores(array $phrases) : array {
        $result=[];
        foreach($phrases as $phrase){
            foreach($phrase as $word){
                $word_score=self::word_degree($word, $phrases)/self::word_frequency($word, $phrases);
                $result[$word]=$word_score;
            }
        }
        return $result;
    }

    /**
     * Counts the phrase-degree contribution for one word.
     *
     * Each occurrence contributes the length of the phrase it appears in, so
     * words appearing in longer candidate phrases receive a higher degree.
     *
     * @param string $word Token to score.
     * @param array<int, array<int, string>> $phrases Candidate phrase token lists.
     * @return int Degree contribution count.
     */
    public static function word_degree(string $word, array $phrases) : int {
        $count=0;
        foreach($phrases as $phrase){
            foreach($phrase as $p){
                if($p==$word){
                    $count+=count($phrase);
                }
            }
        }
        return $count;
    }

    /**
     * Counts how often a word appears across candidate phrases.
     *
     *
     * @param string $word Token to count.
     * @param array<int, array<int, string>> $phrases Candidate phrase token lists.
     * @return int Number of token occurrences.
     */
    public static function word_frequency(string $word, array $phrases) : int {
        $count = 0;
        foreach($phrases as $phrase){
            foreach($phrase as $p){
                if($p==$word){
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Converts candidate phrase token lists into display strings.
     *
     *
     * @param array<int, array<int, string>> $phrases Candidate phrase token lists.
     * @return array<int, string> Space-joined phrase strings.
     */
    public static function return_formated_phrase_list(array $phrases) : array {
        $formated_list=[];
        foreach($phrases as $phrase){
            $formated_list[]=implode(" ", $phrase);
        }
        return $formated_list;
    }

    /**
     * Tokenizes text into lowercase word and punctuation-like units.
     *
     * Unicode separator/control characters are ignored around tokens. The regex
     * keeps non-punctuation word runs and single fallback characters so
     * punctuation can still split candidate phrases.
     *
     * @param string $str Source text.
     * @return array<int, string> Lowercase tokens in source order.
     */
    public static function tokenize(string $str) : array {
        $str=mb_strtolower($str);
        $arr=[];
        preg_match_all('/([\pZ\pC]*)([^\pP\pZ\pC]+ | .)([\pZ\pC]*)/xu', $str, $arr);
        return $arr[2];
    }
	
}
