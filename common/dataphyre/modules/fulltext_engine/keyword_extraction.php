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


namespace dataphyre\fulltext_engine;

class keyword_extraction{

    public static function extract_keywords(string $text, bool $includeScores=true, string $language='en') : array {
        $phrases=self::generate_candidate_keywords($text, $language);
        $wordScores=self::calculate_word_scores($phrases);
        $phraseScores=self::calculate_phrase_scores($phrases, $wordScores);
        arsort($phraseScores);
        $oneThird=ceil(count($phraseScores)/3)+1;
        $phraseScores=array_slice($phraseScores, 0, $oneThird);
        if($includeScores){
            return $phraseScores;
        }
        return array_keys($phraseScores);
    }

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

    public static function calculate_phrase_scores(array $phrases, array $wordScores) : array {
        $result=[];
        foreach($phrases as $phrase){
            $wordScore=0;
            foreach($phrase as $word){
                $wordScore+=$wordScores[$word];
            }
            $result[implode(" ", $phrase)]=$wordScore;
        }
        return $result;
    }

    public static function calculate_word_scores(array $phrases) : array {
        $result=[];
        foreach($phrases as $phrase){
            foreach($phrase as $word){
                $wordScore=self::word_degree($word, $phrases)/self::word_frequency($word, $phrases);
                $result[$word]=$wordScore;
            }
        }
        return $result;
    }

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

    public static function return_formated_phrase_list(array $phrases) : int {
        $formatedList=[];
        foreach($phrases as $phrase){
            $formatedList[]=implode(" ", $phrase);
        }
        return $formatedList;
    }

    public static function tokenize(string $str) : array {
        $str=mb_strtolower($str);
        $arr=[];
        preg_match_all('/([\pZ\pC]*)([^\pP\pZ\pC]+ | .)([\pZ\pC]*)/xu', $str, $arr);
        return $arr[2];
    }
	
}