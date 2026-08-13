<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http']);

test('HTTP conditional responses give If-None-Match precedence over If-Modified-Since', static function(Context $t): void {
	$modified=new DateTimeImmutable('2026-01-01 00:00:00 UTC');
	$response=Response::make('current representation')->withEtag('current')->withLastModified($modified);
	$staleTagWithFutureDate=Request::create('GET', '/resource', headers:[
		'If-None-Match'=>'"stale"',
		'If-Modified-Since'=>'Thu, 01 Jan 2037 00:00:00 GMT',
	]);
	$t->isFalse($response->isNotModified($staleTagWithFutureDate));
	$t->same(200, $response->withConditionalHeaders($staleTagWithFutureDate)->status);
	$t->same('current representation', $response->withConditionalHeaders($staleTagWithFutureDate)->body);

	$matchingTagWithOldDate=Request::create('GET', '/resource', headers:[
		'If-None-Match'=>'W/"current"',
		'If-Modified-Since'=>'Thu, 01 Jan 2020 00:00:00 GMT',
	]);
	$t->isTrue($response->isNotModified($matchingTagWithOldDate));
	$t->same(304, $response->withConditionalHeaders($matchingTagWithOldDate)->status);

	$withoutEtag=Response::make('untagged representation')->withLastModified($modified);
	$t->isTrue($withoutEtag->isNotModified(Request::create('GET', '/resource', headers:['If-None-Match'=>'*'])));
	$t->isFalse($withoutEtag->isNotModified($staleTagWithFutureDate));
	$t->isTrue($withoutEtag->isNotModified(Request::create('GET', '/resource', headers:[
		'If-Modified-Since'=>'Thu, 01 Jan 2037 00:00:00 GMT',
	])));
})->tag('http', 'response', 'conditional', 'rfc9110', 'unit');
