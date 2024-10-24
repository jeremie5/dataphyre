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

namespace dataphyre;

class fulltext_engine_jaro_winkler{
	
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
		// Calculate the common prefix length, limit it to a maximum of 4 characters
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
		// Adjust the scaling factor to ensure the similarity score stays between 0 and 1
		$jaroWinkler=$jaro+($prefix*0.1*(1-$jaro));
		return $jaroWinkler;
	}
}