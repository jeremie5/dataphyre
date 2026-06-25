<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Computes case-insensitive Jaro-Winkler similarity for fulltext ranking.
 *
 * The implementation lowercases inputs before splitting multibyte characters,
 * rewards a shared prefix up to four characters, and returns a normalized 0..1
 * score where higher values indicate closer strings.
 */
class fulltext_engine_jaro_winkler{

	/**
	 * Returns the Jaro-Winkler similarity score for two strings.
	 *
	 * Matching uses the standard Jaro window based on string length, counts
	 * transpositions, and applies the Winkler prefix boost with a 0.1 scaling
	 * factor. Strings with no matching characters return zero.
	 *
	 * @param string $str1 First string to compare.
	 * @param string $str2 Second string to compare.
	 * @return float Similarity score from 0.0 to 1.0.
	 */
	public static function similarity(string $str1, string $str2): float{
		$str1=strtolower($str1);
		$str2=strtolower($str2);
		$len1=mb_strlen($str1);
		$len2=mb_strlen($str2);
		$window=max(0, floor(max($len1, $len2)/2)-1);
		$matches=0;
		$transpositions=0;
		$prefix=0;
		$str1Chars=mb_str_split($str1);
		$str2Chars=mb_str_split($str2);
		$matched1=array_fill(0, $len1, false);
		$matched2=array_fill(0, $len2, false);
		for($i=0; $i<$len1; $i++){
			$start=max(0, $i-$window);
			$end=min($i+$window+1, $len2);
			for($j=$start; $j<$end; $j++){
				if($matched2[$j] || $str1Chars[$i]!==$str2Chars[$j]){
					continue;
				}
				$matched1[$i]=true;
				$matched2[$j]=true;
				$matches++;
				break;
			}
		}
		if($matches===0){
			return 0.0;
		}
		$k=0;
		for($i=0; $i<$len1; $i++){
			if(!$matched1[$i]){
				continue;
			}
			while(!$matched2[$k]){
				$k++;
			}
			if($str1Chars[$i]!==$str2Chars[$k]){
				$transpositions++;
			}
			$k++;
		}
		for($i=0; $i<min(4, $len1, $len2); $i++){
			if($str1Chars[$i]===$str2Chars[$i]){
				$prefix++;
			}
			else
			{
				break;
			}
		}
		$jaro=($matches/$len1+$matches/$len2+($matches-($transpositions/2))/$matches)/3;
		$jaroWinkler=$jaro+($prefix*0.1*(1-$jaro));
		return $jaroWinkler;
	}
}
