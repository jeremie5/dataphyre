<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre;

use InvalidArgumentException;
use LogicException;

/**
 * Registers authenticated application WebSocket endpoints with Dataphyre's
 * fixed realtime runtime.
 *
 * Applications provide code callbacks only. Dataphyre owns the listener,
 * ports, protocol implementation, limits, lifecycle, and TLS edge boundary.
 * The authorization callback receives a bounded handshake array containing
 * `path`, `origin`, normalized lowercase `headers`, parsed `query`, and
 * `remote_address`. It must validate the Origin and application credential,
 * then return a small authorization-context array or `false`.
 *
 * The event callback receives the authorization context and the last cursor.
 * It returns exactly `['cursor'=>?string, 'events'=>list<mixed>]`. Each event
 * is JSON encoded by the framework as one outbound WebSocket text message.
 */
final class realtime {
	private const FRAMEWORK_PROBE_PATH='/dataphyre/runtime/realtime/probe';
	/** @var array<string,array{authorize:callable,events:callable}> */
	private static array $routes=[];
	private static bool $sealed=false;

	/**
	 * Register one exact WebSocket path.
	 *
	 * @param callable(array{path:string,origin:string,headers:array<string,string>,query:array<string,string>,remote_address:string}):array|false $authorize
	 * @param callable(array<string,mixed>,?string):array{cursor:?string,events:list<mixed>} $events
	 */
	public static function register(string $path, callable $authorize, callable $events): void {
		if(self::$sealed){
			throw new LogicException('Realtime registrations are sealed for this process.');
		}
		if(!self::validPath($path)){
			throw new InvalidArgumentException('Realtime path must be one exact, normalized absolute path.');
		}
		if(isset(self::$routes[$path])){
			throw new InvalidArgumentException('Realtime path is already registered.');
		}
		if(\count(self::$routes)>=128){
			throw new LogicException('Realtime registration limit was reached.');
		}
		self::$routes[$path]=['authorize'=>$authorize, 'events'=>$events];
		\ksort(self::$routes, \SORT_STRING);
	}

	/**
	 * Seal and return registrations to the fixed framework runtime.
	 *
	 * @internal Applications must not call this method.
	 * @return array<string,array{authorize:callable,events:callable}>
	 */
	public static function runtimeRoutes(): array {
		$pool=(string)(\getenv('DATAPHYRE_RUNTIME_POOL') ?: '');
		if(!\in_array($pool, ['realtime','realtime-preflight'], true)){
			throw new LogicException('Realtime registrations are available only to the fixed Dataphyre runtime.');
		}
		self::$sealed=true;
		return self::$routes;
	}

	/**
	 * Return deterministic, callback-free registration evidence.
	 *
	 * @internal
	 * @return array{route_count:int,registration_sha256:string}
	 */
	public static function runtimeEvidence(): array {
		$routes=self::runtimeRoutes();
		$paths=\array_keys($routes);
		$encoded=\json_encode($paths, \JSON_UNESCAPED_SLASHES|\JSON_THROW_ON_ERROR);
		return [
			'route_count'=>\count($paths),
			'registration_sha256'=>'sha256:'.\hash('sha256', $encoded),
		];
	}

	private static function validPath(string $path): bool {
		return $path!==self::FRAMEWORK_PROBE_PATH
			&& \strlen($path)>=2
			&& \strlen($path)<=256
			&& !\str_contains($path, '//')
			&& \preg_match('#^/(?:[A-Za-z0-9._~-]+/)*[A-Za-z0-9._~-]+$#D', $path)===1
			&& !\str_contains($path, '/./')
			&& !\str_contains($path, '/../')
			&& !\str_ends_with($path, '/.')
			&& !\str_ends_with($path, '/..');
	}
}
