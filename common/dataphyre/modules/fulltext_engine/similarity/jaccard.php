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

class jaccard{

	public static function similarity(string $stringA, string $stringB) : float {
		$tokensA=\dataphyre\fulltext_engine::tokenize_string($stringA);
		$tokensB=\dataphyre\fulltext_engine::tokenize_string($stringB);
		$intersection=count(array_intersect($tokensA, $tokensB));
		$union=count(array_merge(array_diff($tokensA, $tokensB), $tokensB));
		return $union>0?$intersection/$union:0;
	}

}