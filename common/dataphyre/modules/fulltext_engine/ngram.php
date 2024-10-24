<?php
/*************************************************************************
*  2020-2022 Shopiro Ltd.
*  All Rights Reserved.
* 
* NOTICE:  All information contained herein is, and remains the 
* property of Shopiro Ltd. and its suppliers, if any. The 
* intellectual and technical concepts contained herein are 
* proprietary to Shopiro Ltd. and its suppliers and may be 
* covered by Canadian and Foreign Patents, patents in process, and 
* are protected by trade secret or copyright law. Dissemination of 
* this information or reproduction of this material is strictly 
* forbidden unless prior written permission is obtained from Shopiro Ltd..
*/

namespace dataphyre\fulltext_engine;

class ngram{
	
	public static function laplace_smoothing(array $bigram, int $alpha=1){
		$totalPossibleBigrams=0;
		$totalCount=0;
		foreach($bigram as $count){
			$totalCount+=$count;
			$totalPossibleBigrams++;
		}
		$smoothedBigram=[];
		$resultArray=[];
		foreach($bigram as $bigramKey=>$count){
			$smoothedBigram[$bigramKey]=($count+$alpha)/($totalCount+$alpha*$totalPossibleBigrams);
			$resultArray[]=$bigramKey;
		}
		return implode(' ', $resultArray);
	}
	
	public static function apply_ngrams(string $searchQuery){
		if(is_string($searchQuery)){
			$tokenizedQuery=explode(' ', $searchQuery);
		}
		$wordCount=count($tokenizedQuery);
		if($wordCount==1){
			$ngrams=self::ngram($searchQuery, 1);
		}
		elseif($wordCount==2){
			$ngrams=self::ngram($searchQuery, 2);
		}
		elseif($wordCount==3){
			$ngrams=self::ngram($searchQuery, 3);
		}
		elseif($wordCount==4){
			$ngrams=self::ngram($searchQuery, 4);
		}
		elseif($wordCount>=5){
			$ngrams=self::ngram($searchQuery, 5);
		}
		return $ngrams;
	}
	
	public static function ngram(string $text, int $n){
		$processedText=preg_replace('/[^\w\s]/', '', mb_strtolower($text));
		$words=explode(' ', $processedText);
		$ngram=[];
		$wordCount=count($words);
		for($i=0; $i<$wordCount-$n+1; $i++){
			$ngramKey=implode(' ', array_slice($words, $i, $n));
			if(array_key_exists($ngramKey, $ngram)){
				$ngram[$ngramKey]++;
			}
			else
			{
				$ngram[$ngramKey]=1;
			}
		}
		return $ngram;
	}
	
}