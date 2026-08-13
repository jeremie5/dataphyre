<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	if(!class_exists(fulltext_engine::class, false)){
		/** Minimal lexical seam used only by isolated algorithm contracts. */
		final class fulltext_engine {
			/** @return list<string> */
			public static function get_stopwords(string $language): array {
				return ['and', 'the'];
			}

			/** @return list<string> */
			public static function tokenize_string(string $value): array {
				preg_match_all('/[\p{L}\p{N}_]+/u', mb_strtolower($value), $matches);
				return array_values(array_unique($matches[0] ?? []));
			}
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\dataset;
	use function Dataphyre\Test\suite;
	use function Dataphyre\Test\test;

	suite('Fulltext ranking algorithms')
		->contract('fulltext.algorithm-contracts', 1)
		->layer('unit')
		->risk('high')
		->watches('module:fulltext_engine')
		->through('distance', 'ranking', 'tokenization', 'stemming')
		->isolation('case')
		->tag('fulltext', 'algorithm', 'contract')
		->group('framework-coverage');

	dataset('fulltext stateless algorithm contracts', [
		'kernel Damerau-Levenshtein source slot'=>['edit-distance', 'kernel/damerau_levenshtein.php'],
		'similarity Damerau-Levenshtein source slot'=>['edit-distance', 'similarity/damerau_levenshtein.php'],
		'BM25 normalized relevance'=>['bm25', ''],
		'n-gram query expansion'=>['ngram', ''],
		'RAKE-style keyword extraction'=>['keyword-extraction', ''],
		'Jaccard token-set similarity'=>['jaccard', ''],
		'Jaro-Winkler prefix similarity'=>['jaro-winkler', ''],
	]);

	test('each stateless algorithm obeys its named semantic contract', static function(Context $t, string $contract, string $source): void {
		match($contract){
			'edit-distance'=>$t->fulltext()->assertEditDistanceContract($source),
			'bm25'=>$t->fulltext()->assertBm25Contract(),
			'ngram'=>$t->fulltext()->assertNgramContract(),
			'keyword-extraction'=>$t->fulltext()->assertKeywordExtractionContract(),
			'jaccard'=>$t->fulltext()->assertJaccardContract(),
			'jaro-winkler'=>$t->fulltext()->assertJaroWinklerContract(),
		};
	})->with('fulltext stateless algorithm contracts')->maxMillis(1000);

	dataset('fulltext language stemmer contracts', [
		'Arabic bounded affix stemmer'=>['ar', [
			'abc'=>'abc',
			'prefix'=>'prefix',
		]],
		'German Snowball suffix stemmer'=>['de', [
			'a'=>'a',
			'häusern'=>'haus',
			'straße'=>'strass',
			'schnellstes'=>'schnell',
			'freundlichkeit'=>'freundlich',
			'abababem'=>'ababab',
			'abababern'=>'ababab',
			'abababer'=>'ababab',
			'abababen'=>'ababab',
			'abababes'=>'ababab',
			'abababe'=>'ababab',
			'abababs'=>'ababab',
			'abababest'=>'ababab',
			'abababst'=>'ababab',
			'abababend'=>'ababab',
			'abababung'=>'ababab',
			'abababisch'=>'ababab',
			'abababik'=>'ababab',
			'abababig'=>'ababab',
			'ababablich'=>'ababab',
			'abababheit'=>'ababab',
			'ababerlich'=>'ababer',
			'ababenheit'=>'ababen',
			'aberlich'=>'aber',
			'abenheit'=>'aben',
			'abababkeit'=>'ababab',
		]],
		'English Porter suffix stemmer'=>['en', [
			'a'=>'a',
			'caresses'=>'caress',
			'ponies'=>'poni',
			'ties'=>'ti',
			'caress'=>'caress',
			'cats'=>'cat',
			'feed'=>'feed',
			'agreed'=>'agre',
			'disabled'=>'disabl',
			'plastered'=>'plaster',
			'motoring'=>'motor',
			'matting'=>'mat',
			'mating'=>'mate',
			'meeting'=>'meet',
			'milling'=>'mill',
			'messing'=>'mess',
			'meetings'=>'meet',
			'hopping'=>'hop',
			'filing'=>'file',
			'snowing'=>'snow',
			'happy'=>'happi',
			'sky'=>'sky',
			'relational'=>'relat',
			'conditional'=>'condit',
			'rational'=>'ration',
			'valenci'=>'valenc',
			'hesitanci'=>'hesit',
			'digitizer'=>'digit',
			'conformabli'=>'conform',
			'radicalli'=>'radic',
			'differentli'=>'differ',
			'vileli'=>'vile',
			'analogousli'=>'analog',
			'archaeologi'=>'archaeolog',
			'vietnamization'=>'vietnam',
			'predication'=>'predic',
			'operator'=>'oper',
			'feudalism'=>'feudal',
			'decisiveness'=>'decis',
			'hopefulness'=>'hope',
			'callousness'=>'callous',
			'formaliti'=>'formal',
			'sensitiviti'=>'sensit',
			'sensibiliti'=>'sensibl',
			'triplicate'=>'triplic',
			'formative'=>'form',
			'formalize'=>'formal',
			'electriciti'=>'electr',
			'electrical'=>'electr',
			'hopeful'=>'hope',
			'goodness'=>'good',
			'revival'=>'reviv',
			'allowance'=>'allow',
			'inference'=>'infer',
			'airliner'=>'airlin',
			'gyroscopic'=>'gyroscop',
			'adjustable'=>'adjust',
			'defensible'=>'defens',
			'irritant'=>'irrit',
			'replacement'=>'replac',
			'adjustment'=>'adjust',
			'dependent'=>'depend',
			'adoption'=>'adopt',
			'homologou'=>'homolog',
			'communism'=>'commun',
			'activate'=>'activ',
			'angulariti'=>'angular',
			'homologous'=>'homolog',
			'effective'=>'effect',
			'bowdlerize'=>'bowdler',
			'probate'=>'probat',
			'rate'=>'rate',
			'cease'=>'ceas',
			'controll'=>'control',
			'roll'=>'roll',
		]],
		'French Snowball suffix stemmer'=>['fr', [
			'a'=>'a',
			'aimer'=>'aim',
			'parler'=>'parl',
			'bcdfg'=>'bcdfg',
			'mangements'=>'mang',
			'rapidement'=>'rapid',
			'heureusement'=>'heureux',
			'finissions'=>'fin',
			'chevaux'=>'cheval',
		]],
	]);

	test('each language stemmer preserves its exact branch corpus', static function(Context $t, string $language, array $examples): void {
		$t->fulltext()->assertStemmerContract($language, $examples);
	})->with('fulltext language stemmer contracts')->maxMillis(2000);

	test('the fulltext module DSL fails closed on malformed sources and lexical declarations', static function(Context $t): void {
		$t->fulltext()->assertDslFailureContracts();
	})->tag('fulltext', 'testkit', 'failure-contract')->maxMillis(1000);

	test('the framework bootstrap publishes one idempotent module marker', static function(Context $t): void {
		$t->fulltext()->assertFrameworkBootstrapContract();
	})->tag('fulltext', 'framework-bootstrap')->maxMillis(1000);
}
