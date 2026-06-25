<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Computes edit-distance similarity using a Damerau-Levenshtein variant.
 *
 * The helper is stateless and multibyte-aware for character access. Despite the
 * method name, the returned value is an edit distance rather than a normalized
 * similarity ratio: lower is more similar and zero means an exact match.
 */
class damerau_levenshtein{

	/**
	 * Returns the edit distance between two strings.
	 *
	 * Insertions, deletions, substitutions, and adjacent transpositions are counted
	 * with unit cost. Empty-string comparisons return the length of the non-empty
	 * input, and identical strings return zero.
	 *
	 * @param string $str1 First string to compare.
	 * @param string $str2 Second string to compare.
	 * @return float Edit distance; lower values indicate closer strings.
	 */
	public static function similarity(string $str1, string $str2) : float {
		if($str1===$str2)return 0;
		$len1=mb_strlen($str1);
		$len2=mb_strlen($str2);
		if($len1===0||$len2===0)return max($len1,$len2);
		$previousRow=range(0,$len2);
		$currentRow=[];
		for($i=1;$i<=$len1;$i++){
			$currentRow[0]=$i;
			for($j=1;$j<=$len2;$j++){
				$cost=mb_substr($str1,$i-1,1)===mb_substr($str2,$j-1,1)?0:1;
				$currentRow[$j]=min(
					$currentRow[$j-1]+1, //Insertion
					$previousRow[$j]+1, //Deletion
					$previousRow[$j-1]+$cost //Substitution
				);
				if($i>1&&$j>1&&mb_substr($str1,$i-1,1)===mb_substr($str2,$j-2,1)&&mb_substr($str1,$i-2,1)===mb_substr($str2,$j-1,1)){
					$currentRow[$j]=min(
						$currentRow[$j],
						$previousRow[$j-2]+$cost //Transposition
					);
				}
			}
			$previousRow=$currentRow;
		}
		return $currentRow[$len2];
	}

}
