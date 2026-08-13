<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Mailer\Support\HttpJsonClient;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dp_http_json_client_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
require_once $dp_http_json_client_root.'/modules/mailer/Framework/Support/HttpJsonClient.php';

function dp_http_json_client_file_url(string $path): string {
	return 'file:///'.ltrim(str_replace('\\', '/', $path), '/');
}

test('mailer HTTP JSON client validates and resets process-local adapters', static function(Context $t): void {
	$expected=[
		'ok'=>true,
		'status'=>299,
		'headers'=>['X-Harness: yes'],
		'body'=>'{"handled":true}',
		'json'=>['handled'=>true],
		'error'=>'',
	];
	$captured=[];
	try{
		HttpJsonClient::useHandler(static function(string $method, string $url, array|string|null $payload, array $headers, int $timeout) use (&$captured, $expected): array {
			$captured=[$method, $url, $payload, $headers, $timeout];
			return $expected;
		});
		$t->same($expected, HttpJsonClient::request('patch', 'https://handler.example.test', ['value'=>1], ['X-Test'=>'yes'], 7));
		$t->same(['patch', 'https://handler.example.test', ['value'=>1], ['X-Test'=>'yes'], 7], $captured);

		HttpJsonClient::useHandler(static fn(): string=>'invalid');
		$t->throws(
			static fn()=>HttpJsonClient::request('GET', 'https://handler.example.test'),
			UnexpectedValueException::class
		);
		HttpJsonClient::useHandler(null);

		HttpJsonClient::useTransport(' CURL ');
		$t->throws(static fn()=>HttpJsonClient::useTransport('socket'), InvalidArgumentException::class);
		HttpJsonClient::useTransport(null);
	}
	finally{
		HttpJsonClient::useHandler(null);
		HttpJsonClient::useTransport(null);
	}
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer HTTP JSON client executes native curl success body and failure paths locally', static function(Context $t): void {
	$path=$t->tempFile('{"curl":true}', 'mailer-http-json');
	$url=dp_http_json_client_file_url($path);
	try{
		HttpJsonClient::useTransport('curl');
		$get=HttpJsonClient::request(' get ', $url, ['body'=>'omitted'], ['Accept'=>'application/json'], 2);
		$t->contains('{"curl":true}', $get['body']);
		$t->same('', $get['error']);

		$post=HttpJsonClient::request('post', $url, 'raw-body', [0=>' X-Numeric: yes ', 1=>''], 2);
		$t->same(0, $post['status']);

		$missing=HttpJsonClient::request('HEAD', dp_http_json_client_file_url($path.'.missing'), null, [], 2);
		$t->isFalse($missing['ok']);
		$t->same(0, $missing['status']);
		$t->same('', $missing['body']);
		$t->notEmpty($missing['error']);
	}
	finally{
		HttpJsonClient::useTransport(null);
	}
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer HTTP JSON client executes native stream success and failure paths locally', static function(Context $t): void {
	try{
		HttpJsonClient::useTransport('stream');
		$get=HttpJsonClient::request(
			'GET',
			'data://text/plain,'.rawurlencode('{"stream":true}'),
			['body'=>'omitted'],
			['content-type'=>'application/custom'],
			2
		);
		$t->same(['stream'=>true], $get['json']);
		$t->same('', $get['error']);

		$post=HttpJsonClient::request('POST', 'data://text/plain,not-json', ["broken"=>"\xB1\x31"], [], 2);
		$t->same('not-json', $post['body']);
		$t->same(null, $post['json']);

		$missingPath=$t->workspace('mailer-http-json-missing')->path('missing.json');
		$missing=HttpJsonClient::request('GET', dp_http_json_client_file_url($missingPath), null, [], 2);
		$t->isFalse($missing['ok']);
		$t->same('HTTP request failed', $missing['error']);
	}
	finally{
		HttpJsonClient::useTransport(null);
	}
})->tag('mailer', 'coverage')->group('framework-coverage');

test('mailer HTTP JSON client normalizes statuses responses and header variants', static function(Context $t): void {
	$httpInternals=$t->nonPublic(HttpJsonClient::class);
	$t->same(204, $httpInternals->invoke('status', [
		'not a status',
		'HTTP/1.1 301 Moved Permanently',
		'Location: https://example.test/final',
		'HTTP/2 204',
	]));
	$t->same(0, $httpInternals->invoke('status', []));

	$success=$httpInternals->invoke('response', 201, '{"accepted":true}', '', ['X-Test: yes']);
	$t->isTrue($success['ok']);
	$t->same(['accepted'=>true], $success['json']);
	$failure=$httpInternals->invoke('response', 503, 'invalid-json', 'upstream failed');
	$t->isFalse($failure['ok']);
	$t->same(null, $failure['json']);

	$t->same(
		['X-One: one', 'X-Two: two'],
		$httpInternals->invoke('parseHeaders', "HTTP/1.1 100 Continue\r\nX-One: one\r\n\r\nHTTP/2 201\r\n X-Two: two ")
	);

	$headerLines=$httpInternals->invoke('headers', [
		0=>' X-Numeric: yes ',
		1=>' ',
		' X-Spaced '=>'value',
		''=>'ignored',
		'Content-Type'=>'text/plain',
	], ['Content-Type'=>'application/json', 'X-Default'=>'yes']);
	$t->same(['Content-Type: text/plain', 'X-Default: yes', 'X-Numeric: yes', 'X-Spaced: value'], $headerLines);

	$t->isTrue($httpInternals->invoke('hasHeader', ['Content-Type'=>'text/plain'], 'CONTENT-TYPE'));
	$t->isTrue($httpInternals->invoke('hasHeader', [0=>'Authorization: Bearer token'], 'authorization'));
	$t->isFalse($httpInternals->invoke('hasHeader', ['X-Test'=>'yes'], 'content-type'));
})->tag('mailer', 'coverage')->group('framework-coverage');
