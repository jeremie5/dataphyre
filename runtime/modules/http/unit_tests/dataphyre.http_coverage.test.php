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

test('http requests normalize routes inputs files headers negotiation attributes and macros', static function(Context $t): void {
	$tmp=$t->tempFile('ready', 'http-request');
	$t->defer([Request::class, 'flushMacros']);
	{
		$request=Request::create(
			'POST',
			'/orders/42',
			['page'=>'2', 'search'=>' ada '],
			['_method'=>'PATCH', 'active'=>'yes', 'quantity'=>'7', 'weight'=>'1.5', 'blank'=>'', 'name'=>'Ada'],
			['session'=>'cookie-value'],
			[
				'HTTPS'=>'on',
				'HTTP_HOST'=>'example.test:8443',
				'SERVER_PORT'=>'8443',
				'REMOTE_ADDR'=>'127.0.0.1',
				'HTTP_USER_AGENT'=>'DataphyreTest/1.0',
			],
			[
				'Accept'=>'application/json, text/html;q=0.8',
				'Content-Type'=>'application/json; charset=utf-8',
				'X-Requested-With'=>'XMLHttpRequest',
			],
			['order'=>'42', '_route'=>'orders.show'],
			['tenant_id'=>7, 'route_name'=>'orders.show'],
			['document'=>[
				'name'=>'document.txt',
				'tmp_name'=>$tmp,
				'error'=>UPLOAD_ERR_OK,
				'size'=>5,
				'type'=>'text/plain',
			]]
		);

		$t->same('POST', $request->originalMethod());
		$t->same('POST', $request->method());
		$t->same('PATCH', $request->effectiveMethod());
		$t->same('/orders/42', $request->path());
		$t->same('https', $request->scheme());
		$t->same('example.test:8443', $request->host());
		$t->same('https://example.test:8443', $request->root());
		$t->contains('/orders/42', $request->url());
		$t->contains('page=2', $request->fullUrl());
		$t->same('127.0.0.1', $request->ip());
		$t->same('DataphyreTest/1.0', $request->userAgent());
		$t->same('42', $request->route('order'));
		$t->same('orders.show', $request->routeName());
		$t->isTrue($request->routeIs('orders.*'));
		$t->isFalse($request->routeIs('users.*'));
		$t->same('2', $request->query('page'));
		$t->same('Ada', $request->input('name'));
		$t->subset(['name'=>'Ada', 'page'=>'2'], $request->all());
		$t->same(['name'=>'Ada', 'page'=>'2'], $request->only(['name', 'page']));
		$t->missingPath('blank', $request->except('blank'));
		$t->isTrue($request->has(['name', 'page']));
		$t->isFalse($request->filled('blank'));
		$t->isTrue($request->boolean('active'));
		$t->same(7, $request->integer('quantity'));
		$t->same(1.5, $request->float('weight'));
		$t->same('cookie-value', $request->cookie('session'));
		$t->isTrue($request->hasFile('document'));
		$t->instanceOf(\Dataphyre\Http\UploadedFile::class, $request->file('document'));
		$t->same('document.txt', $request->file('document')->clientOriginalName());
		$t->same('application/json; charset=utf-8', $request->header('content-type'));
		$t->isTrue($request->ajax());
		$t->isTrue($request->isJson());
		$t->isTrue($request->wantsJson());
		$t->isTrue($request->expectsJson());
		$t->isTrue($request->accepts('application/json'));
		$t->isFalse($request->accepts('image/png'));
		$t->isFalse($request->acceptsAnyContentType());
		$t->same('on', $request->server('HTTPS'));
		$t->same(7, $request->attribute('tenant_id'));
		$changed=$request->mergeRouteParameters(['invoice'=>'9'])->setAttribute('scope', 'billing')->mergeAttributes(['trace'=>'ready']);
		$t->same('9', $changed->route('invoice'));
		$t->same('billing', $changed->attribute('scope'));
		$t->same('ready', $changed->attribute('trace'));

		Request::flushMacros();
		Request::macro('tenant', function(): int {
			return 7;
		});
		$t->isTrue(Request::hasMacro('tenant'));
		$t->same(7, $request->tenant());
		$t->throws(static fn()=>$request->missingMacro(), BadMethodCallException::class);
		$t->throws(static fn()=>Request::macro('', static fn()=>null), InvalidArgumentException::class);
		Request::flushMacros();
	}
})->tag('http', 'request', 'coverage')->maxMillis(5000);

test('http responses cover factories streams cache validators cookies files macros and normalization', static function(Context $t): void {
	$tmp=$t->tempFile('response-body', 'http-response');
	$stream=fopen('php://temp', 'r+');
	if(!is_resource($stream)){
		throw new RuntimeException('Unable to create response stream fixture.');
	}
	$t->defer(static function()use($stream): void {
		if(is_resource($stream)){
			fclose($stream);
		}
	});
	$t->defer([Response::class, 'flushMacros']);
	fwrite($stream, 'stream-body');
	rewind($stream);
	{
		$plain=Response::make('ready', 202, ['X-Test'=>'ready']);
		$t->same(202, $plain->status);
		$t->same('ready', $plain->body);
		$t->isFalse($plain->isStreamed());
		$t->isTrue(Response::stream($stream)->isStreamed());
		$t->throws(static fn()=>Response::stream('not-a-stream'), InvalidArgumentException::class);

		$json=Response::json(['ok'=>true]);
		$t->same('{"ok":true}', $json->body);
		$t->same(201, Response::created(['id'=>42], '/orders/42')->status);
		$t->same('/orders/42', Response::created(['id'=>42], '/orders/42')->headers['Location'] ?? null);
		$t->contains('<main>', Response::html('<main>Ready</main>')->body);
		$t->same(204, Response::noContent()->status);
		$t->same(204, Response::no_content()->status);
		$t->same('response-body', Response::file($tmp, 'inline.txt')->body);
		$t->contains('attachment', (string)(Response::download($tmp, 'download.txt')->headers['Content-Disposition'] ?? ''));
		$t->throws(static fn()=>Response::file($tmp.'.missing'), InvalidArgumentException::class);

		$headers=$plain->withHeaders(['X-New'=>'new'])->withHeaders(['X-Test'=>'replaced'], true)->withHeader('X-Array', ['one', 'two']);
		$t->same('new', $headers->headers['X-New'] ?? null);
		$t->same('replaced', $headers->headers['X-Test'] ?? null);
		$t->same(['one', 'two'], $headers->headers['X-Array'] ?? null);
		$t->contains('no-store', (string)(Response::make()->noCache()->headers['Cache-Control'] ?? ''));
		$t->same('public, max-age=60', Response::make()->cacheFor(60)->headers['Cache-Control'] ?? null);
		$t->same('private, max-age=30', Response::make()->privateCacheFor(30)->headers['Cache-Control'] ?? null);

		$modified=new DateTimeImmutable('2026-01-01 00:00:00 UTC');
		$conditional=Response::make('cached')->withEtag('contract')->withLastModified($modified);
		$etagRequest=Request::create('GET', '/cached', headers:['If-None-Match'=>'W/"contract"']);
		$dateRequest=Request::create('GET', '/cached', headers:['If-Modified-Since'=>'Thu, 01 Jan 2026 00:00:00 GMT']);
		$t->isTrue($conditional->isNotModified($etagRequest));
		$t->isTrue($conditional->isNotModified($dateRequest));
		$t->same(304, $conditional->withConditionalHeaders($etagRequest)->status);
		$t->same('', $conditional->notModified()->body);
		$t->same($conditional, $conditional->withEtag(''));

		$cookies=Response::make()
			->withCookie('session', 'token', 10, '/', 'example.test', true, true, 'Strict')
			->withoutCookie('legacy');
		$t->count(2, $cookies->headers['Set-Cookie'] ?? []);
		$t->contains('SameSite=None', Response::cookieHeader('cross', 'value', 0, '/', '', true, true, 'None'));
		$t->throws(static fn()=>Response::cookieHeader('bad name', 'value'), InvalidArgumentException::class);

		Response::flushMacros();
		Response::macro('accepted', static fn(array $payload): Response=>Response::json($payload, 202));
		Response::macro('tagged', function(string $value): Response {
			return $this->withHeader('X-Tag', $value);
		});
		$t->isTrue(Response::hasMacro('accepted'));
		$t->same(202, Response::accepted(['ok'=>true])->status);
		$t->same('ready', Response::make()->tagged('ready')->headers['X-Tag'] ?? null);
		$t->throws(static fn()=>Response::missingMacro(), BadMethodCallException::class);
		$t->throws(static fn()=>Response::make()->missingMacro(), BadMethodCallException::class);
		$t->throws(static fn()=>Response::macro('', static fn()=>null), InvalidArgumentException::class);
		Response::flushMacros();

		$t->same($plain, Response::normalize($plain));
		$t->same('{"ok":true}', Response::normalize(['ok'=>true])->body);
		$t->same(204, Response::normalize(null)->status);
		$t->same('text/html; charset=utf-8', Response::normalize('<p>Ready</p>', 'html')->headers['Content-Type'] ?? null);
		$t->same('raw', Response::normalize('raw')->body);
	}
})->tag('http', 'response', 'coverage')->maxMillis(5000);
