<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * In-memory MVC rate-limiting middleware.
 *
 * Buckets are process-local and keyed by route/bucket, effective method, client identity, and
 * decay window. This makes the middleware suitable for simple single-process throttling and
 * tests, while distributed deployments should replace it with shared storage.
 */
final class ThrottleMiddleware {

	/**
	 * @var array<string, array{count:int, reset_at:int}> Process-local throttle buckets.
	 */
	private static array $buckets=[];

	/**
	 * Applies a fixed-window request limit before calling the next middleware/handler.
	 *
	 * On exhaustion, a JSON 429 response is returned with retry and rate-limit headers. Accepted
	 * responses are normalized and decorated with current limit/remaining headers.
	 *
	 * @param Request $request Current HTTP request.
	 * @param callable $next Next middleware/handler.
	 * @param int|string $maxAttempts Maximum accepted requests per window.
	 * @param int|string $decaySeconds Window length in seconds.
	 * @param ?string $bucket Optional logical bucket name; request path is used when omitted.
	 * @return Response|mixed throttled JSON response, or downstream response decorated with rate-limit headers when possible.
	 */
	public function handle(Request $request, callable $next, int|string $maxAttempts=60, int|string $decaySeconds=60, ?string $bucket=null): mixed {
		$maxAttempts=max(1, (int)$maxAttempts);
		$decaySeconds=max(1, (int)$decaySeconds);
		$key=$this->key($request, $bucket, $decaySeconds);
		$now=time();
		$bucketState=self::$buckets[$key] ?? [
			'count'=>0,
			'reset_at'=>$now+$decaySeconds,
		];
		if(($bucketState['reset_at'] ?? 0)<=$now){
			$bucketState=[
				'count'=>0,
				'reset_at'=>$now+$decaySeconds,
			];
		}
		$remaining=max(0, $maxAttempts-(int)$bucketState['count']);
		if($remaining<=0){
			$retryAfter=max(1, (int)$bucketState['reset_at']-$now);
			return Response::json(['message'=>'Too Many Requests'], 429, [
				'Retry-After'=>(string)$retryAfter,
				'X-RateLimit-Limit'=>(string)$maxAttempts,
				'X-RateLimit-Remaining'=>'0',
			]);
		}
		$bucketState['count']=(int)$bucketState['count']+1;
		self::$buckets[$key]=$bucketState;
		$response=Response::normalize($next($request), 'html');
		return $response->withHeaders([
			'X-RateLimit-Limit'=>(string)$maxAttempts,
			'X-RateLimit-Remaining'=>(string)max(0, $maxAttempts-(int)$bucketState['count']),
		]);
	}

	/**
	 * Clears all in-memory throttle buckets.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$buckets=[];
	}

	/**
	 * Builds the process-local throttle bucket key for a request.
	 *
	 * @param Request $request Current HTTP request.
	 * @param ?string $bucket Optional logical bucket name.
	 * @param int $decaySeconds Window length in seconds.
	 * @return string Stable bucket key for the current request identity.
	 */
	private function key(Request $request, ?string $bucket, int $decaySeconds): string {
		$identity=(string)($request->server('REMOTE_ADDR') ?? $request->header('X-Forwarded-For', 'local'));
		return implode('|', [
			$bucket ?: $request->path(),
			$request->effectiveMethod(),
			$identity,
			$decaySeconds,
		]);
	}
}
