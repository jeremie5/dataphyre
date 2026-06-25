<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Calculates Damerau-Levenshtein edit distance for full-text fuzzy matching.
 *
 * The implementation is multibyte-aware and treats insertion, deletion,
 * substitution, and adjacent transposition as single edit operations so search
 * scoring can handle common typing errors without byte-oriented string drift.
 */
class damerau_levenshtein{

	/**
	 * Returns the edit distance between two strings.
	 *
	 * Identical strings return 0. Empty inputs return the length of the other
	 * string. Lower values represent closer matches and can be converted to a
	 * similarity score by callers that know their own ranking thresholds.
	 *
	 * @param string $str1 First UTF-8 string to compare.
	 * @param string $str2 Second UTF-8 string to compare.
	 * @return float Number of edit operations required to transform one string into the other.
	 */
	public static function similarity(string $str1, string $str2) : float {
		if($str1===$str2)return 0;
		$len1=mb_strlen($str1);
		$len2=mb_strlen($str2);
		if($len1===0||$len2===0)return max($len1,$len2);
		$previous_row=range(0,$len2);
		$current_row=[];
		for($i=1;$i<=$len1;$i++){
			$current_row[0]=$i;
			for($j=1;$j<=$len2;$j++){
				$cost=mb_substr($str1,$i-1,1)===mb_substr($str2,$j-1,1)?0:1;
				$current_row[$j]=min(
					$current_row[$j-1]+1,
					$previous_row[$j]+1,
					$previous_row[$j-1]+$cost
				);
				if($i>1&&$j>1&&mb_substr($str1,$i-1,1)===mb_substr($str2,$j-2,1)&&mb_substr($str1,$i-2,1)===mb_substr($str2,$j-1,1)){
					$current_row[$j]=min(
						$current_row[$j],
						$previous_row[$j-2]+$cost
					);
				}
			}
			$previous_row=$current_row;
		}
		return $current_row[$len2];
	}

}
