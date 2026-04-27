<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
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