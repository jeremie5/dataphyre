<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\ActionArguments;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Http\ResponseEmitter;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http']);

class DpHttpActionDependency {}
class DpHttpActionDependencyChild extends DpHttpActionDependency {}

final class DpHttpActionController {
	public static function staticAction(string $value='static'): string {
		return $value;
	}
}

final class DpHttpInvokableAction {
	public function __invoke(string $value='invokable'): string {
		return $value;
	}
}

function dp_http_named_action(string $value='function'): string {
	return $value;
}

if(!defined('DP_HTTP_FLIGHTDECK_DEBUGBAR_STUB_LOADED') && !class_exists(dataphyre_flightdeck_debugbar::class, false)){
	define('DP_HTTP_FLIGHTDECK_DEBUGBAR_STUB_LOADED', true);
	final class dataphyre_flightdeck_debugbar {
		public static string $mode='inject';

		public static function inject(string $body): string {
			if(self::$mode==='throw'){
				throw new RuntimeException('debugbar unavailable');
			}
			if(self::$mode==='same'){
				return $body;
			}
			return $body.'<debugbar />';
		}
	}
}

test('http action arguments resolve every callable shape and argument source', static function(Context $t): void {
	$request=Request::create('GET', '/orders/42');
	$exact=new DpHttpActionDependencyChild();
	$compatible=new DpHttpActionDependencyChild();
	$action=static function(
		Request $injectedRequest,
		DpHttpActionDependencyChild $keyed,
		DpHttpActionDependency $matched,
		string $named,
		string $positional,
		string $default='fallback',
		?string $nullable=null
	): array {
		return func_get_args();
	};

	$arguments=ActionArguments::resolve(
		$action,
		$request,
		['named'=>'named-value', 0=>'positional-value'],
		[$compatible, DpHttpActionDependencyChild::class=>$exact]
	);
	$t->same($request, $arguments[0]);
	$t->same($exact, $arguments[1]);
	$t->same($compatible, $arguments[2]);
	$t->same('named-value', $arguments[3]);
	$t->same('positional-value', $arguments[4]);
	$t->same('fallback', $arguments[5]);
	$t->same(null, $arguments[6]);

	$t->same(['static'], ActionArguments::resolve([DpHttpActionController::class, 'staticAction'], $request));
	$t->same(['invokable'], ActionArguments::resolve(new DpHttpInvokableAction(), $request));
	$t->same(['function'], ActionArguments::resolve('dp_http_named_action', $request));
	$t->same([null], ActionArguments::resolve(static fn(?string $optional): ?string=>$optional, $request));
	$t->throws(
		static fn()=>ActionArguments::resolve(static fn(string $required): string=>$required, $request),
		RuntimeException::class,
		'Unable to resolve action parameter'
	);
})->tag('http', 'action-arguments', 'coverage')->maxMillis(5000);

test('http response emitter covers debugbar headers bodies and stream outcomes', static function(Context $t): void {
	$responseEmitter=$t->nonPublic(ResponseEmitter::class);

	$empty=new Response('', 204, ['Content-Length'=>'0']);
	$t->same($empty, $responseEmitter->invoke('withFlightdeckDebugbar', $empty));

	dataphyre_flightdeck_debugbar::$mode='same';
	$plain=new Response('plain', 200, ['Content-Length'=>'5']);
	$t->same($plain, $responseEmitter->invoke('withFlightdeckDebugbar', $plain));

	dataphyre_flightdeck_debugbar::$mode='throw';
	$t->same($plain, $responseEmitter->invoke('withFlightdeckDebugbar', $plain));

	dataphyre_flightdeck_debugbar::$mode='inject';
	$injected=$responseEmitter->invoke('withFlightdeckDebugbar', new Response('body', 202, [
		'Content-Length'=>'4',
		'X-List'=>['one', 'two'],
		'X-Test'=>'ready',
	]));
	$t->same('body<debugbar />', $injected->body);
	$t->missingPath('Content-Length', $injected->headers);
	$t->same(['one', 'two'], $injected->headers['X-List'] ?? null);

	$emitted=$t->captureOutput(static fn()=>ResponseEmitter::emit($injected))->output();
	$t->same('body<debugbar /><debugbar />', $emitted);
	$t->same(202, http_response_code());

	$stream=fopen('php://temp', 'r+');
	if(!is_resource($stream)){
		throw new RuntimeException('Unable to create response stream fixture.');
	}
	fwrite($stream, 'stream-body');
	rewind($stream);
	$streamed=$t->captureOutput(static fn()=>ResponseEmitter::emit(Response::stream($stream, 206, ['X-Stream'=>'ready'])))->output();
	$t->same('stream-body', $streamed);
	$t->isFalse(is_resource($stream));

	$writeOnlyPath=$t->tempFile('', 'http-emitter');
	$writeOnly=fopen($writeOnlyPath, 'w');
	if(!is_resource($writeOnly)){
		throw new RuntimeException('Unable to open write-only stream fixture.');
	}
	$t->defer(static function()use($writeOnly): void {
		if(is_resource($writeOnly)){
			fclose($writeOnly);
		}
	});
	$writeOnlyOutput=$t->captureOutput(static fn()=>@ResponseEmitter::emit(Response::stream($writeOnly)));
	$t->same('', $writeOnlyOutput->output());
	$t->isFalse(is_resource($writeOnly));
})->tag('http', 'response-emitter', 'coverage')->maxMillis(5000);
