<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\dataset;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

suite('Fulltext lexical declarations')
	->contract('fulltext.stopword-catalog', 1)
	->layer('unit')
	->risk('high')
	->watches('module:fulltext_engine')
	->through('stopword-declaration', 'schema-contract', 'content-fingerprint')
	->isolation('case')
	->tag('fulltext', 'stopwords', 'data-contract')
	->group('framework-coverage');

dataset('fulltext stopword catalog', [
	'Arabic lexical declaration'=>['ar', 162, 'fcef70aff02cf9351ed372497620bb3840ca36ff67c3aa79aae930755106546f', 0],
	'Bulgarian lexical declaration'=>['bg', 259, 'd1e4fbe360d210c03c7eeaf18f5d0eaaf384070f1035eb96fdcb2795e9279028', 0],
	'Bengali lexical declaration'=>['bn', 119, '9a81b1a0429412c314846aa822b236cf6e6731e75abb11a7a66c4c3dc6a4d81e', 3],
	'Czech lexical declaration'=>['cs', 256, '3876afedd7885c712d2546fbdeb889a284f0ada1fd49509d3381130d224d9bca', 0],
	'German lexical declaration'=>['de', 603, '8096a1d2ed134ef7cf40a18acc39833ed7adaa9cde87cf209c11384feecec048', 25],
	'English lexical declaration'=>['en', 179, '7a14e09812acf3a01a20a17e5d0f0b6418efe7062978bc645ad22f8c1daed958', 0],
	'Spanish lexical declaration'=>['es', 313, 'd339ca9c61c60de0cb7fd88789f5c9e32b02f9600268c306c10551ac74327957', 0],
	'Persian lexical declaration'=>['fa', 522, '6d5cbde32348f5702f8889347614e28a31781b220d00ca381cbb2172ad3450cf', 4],
	'Finnish lexical declaration'=>['fi', 747, 'f4174e6c6e40290cbff64013e360e9279135f7fd7cc50f909acd5035c6a5e899', 0],
	'French lexical declaration'=>['fr', 654, '4f20963726f66171f43d66a69eec97da8b479fb236c3ebf9f6de8831db310d39', 0],
	'Hindi lexical declaration'=>['hi', 163, '69d64c7cc28f87043597771af316f06da0792ae189693fb0d44f69da2b301252', 0],
	'Hungarian lexical declaration'=>['hu', 246, 'ea4c19e661b0550f88d9d7f808727d8a928d150021b12ff990c2710a25147439', 7],
	'Italian lexical declaration'=>['it', 287, '9b9ce2f2da45e29cd002215b68d0a4d61922dd5a6a64ad83ac48fc2bd0306c1e', 2],
	'Marathi lexical declaration'=>['mr', 97, '233e658a861725f041e9f42fdcdf7fa19bf68e74705c14ecfb9f0d54cfa28b38', 0],
	'Polish lexical declaration'=>['pl', 343, '40f596fb87ec1accb0807e8a9bf15b139bf6f1c42babd79ba2d815f6c03e9fdf', 0],
	'Portuguese lexical declaration'=>['pt', 466, '6e78a06869ec95662906434631b5dc5320e4918e6b3f3f78874f822e5d0736e9', 1],
]);

test('each stopword declaration preserves its schema and canonical lexical content', static function(
	Context $t,
	string $language,
	int $count,
	string $fingerprint,
	int $duplicates
): void {
	$t->fulltext()->stopwords($language)->matches($count, $fingerprint, $duplicates);
})->with('fulltext stopword catalog')->maxMillis(1000);
