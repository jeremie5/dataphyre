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

class damerau_levenshtein{

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