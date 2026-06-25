<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre\fulltext_engine;

/**
 * Computes token-set similarity using the Jaccard coefficient.
 *
 * Tokenization is delegated to the fulltext engine so the comparison follows the
 * same word extraction path as ranking. The helper is stateless and returns
 * values in the 0..1 range.
 */
class jaccard{

	/**
	 * Returns the Jaccard similarity of two tokenized strings.
	 *
	 * The score is intersection size divided by union size after duplicate tokens
	 * are collapsed by tokenize_string(). Inputs with no tokens return zero rather
	 * than raising or dividing by zero.
	 *
	 * @param string $stringA First string to tokenize and compare.
	 * @param string $stringB Second string to tokenize and compare.
	 * @return float Similarity score from 0.0 to 1.0.
	 */
	public static function similarity(string $stringA, string $stringB) : float {
		$tokensA=\dataphyre\fulltext_engine::tokenize_string($stringA);
		$tokensB=\dataphyre\fulltext_engine::tokenize_string($stringB);
		$tokensBLookup=array_fill_keys($tokensB, true);
		$intersection=0;
		$union=count($tokensBLookup);
		foreach($tokensA as $token){
			if(isset($tokensBLookup[$token])){
				$intersection++;
			}
			else
			{
				$union++;
			}
		}
		return $union>0 ? $intersection/$union : 0;
	}

}
