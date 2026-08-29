<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Mvc;

use Dataphyre\ClientAddress;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;

/**
 * Cross-worker fixed-window rate-limiting middleware.
 *
 * The default store requires Dataphyre Cache to expose a healthy process-shared
 * backend. It never treats Cache's request-local availability fallback as
 * security state. Applications may bind another atomic ThrottleStore, while
 * LocalThrottleStore remains an explicit single-process/test-only choice.
 */
final class ThrottleMiddleware {
	private const MAX_DECAY_SECONDS=31536000;

	/** Storage used for atomic fixed-window counters. */
	private ThrottleStore $store;

	/** @var callable():int Clock returning Unix seconds. */
	private $clock;

	/** Avoids repeating the same backend-unavailable trace on every request. */
	private static bool $unavailableTraced=false;

	/**
	 * Builds throttle middleware around an injectable atomic store.
	 *
	 * The container can bind ThrottleStore for a database or another shared
	 * backend. Without a binding, shared Dataphyre Cache is required.
	 *
	 * @param ?ThrottleStore $store Atomic policy store, or null for shared cache.
	 * @param ?callable $clock Optional deterministic Unix-second clock for tests.
	 */
	public function __construct(?ThrottleStore $store=null, ?callable $clock=null){
		$this->store=$store ?? new SharedCacheThrottleStore();
		$this->clock=$clock ?? static fn(): int=>time();
	}

	/**
	 * Applies a fixed-window request limit before calling the next handler.
	 *
	 * An explicit bucket is an application-wide logical scope: routes and HTTP
	 * methods using the same bucket, limit, and window share one counter. Without
	 * a bucket, the effective method and request path form the route scope.
	 *
	 * Identity always includes the trusted client address. A server-side
	 * `throttle_identity` request attribute takes precedence over the authenticated
	 * access subject. An optional body/query field adds a hashed credential-target
	 * dimension without retaining its value in the store key.
	 *
	 * @param Request $request Current HTTP request.
	 * @param callable $next Next middleware/handler.
	 * @param int|string $maxAttempts Maximum accepted requests per window.
	 * @param int|string $decaySeconds Window length in seconds.
	 * @param ?string $bucket Optional cross-route logical bucket name.
	 * @param ?string $requestField Optional body/query dot path identifying a credential target.
	 * @return Response|mixed throttled/unavailable response, or the decorated downstream response.
	 */
	public function handle(
		Request $request,
		callable $next,
		int|string $maxAttempts=60,
		int|string $decaySeconds=60,
		?string $bucket=null,
		?string $requestField=null
	): mixed {
		$maxAttempts=max(1, (int)$maxAttempts);
		$decaySeconds=min(self::MAX_DECAY_SECONDS, max(1, (int)$decaySeconds));
		$now=max(0, (int)($this->clock)());
		$windowStart=$now-($now%$decaySeconds);
		$resetAt=$windowStart+$decaySeconds;
		$key=$this->key(
			$request,
			$bucket,
			$requestField,
			$maxAttempts,
			$decaySeconds,
			$windowStart
		);
		try{
			$count=$this->store->increment($key, max(1, $resetAt-$now+1));
		}catch(\Throwable){
			$count=false;
		}
		if(!is_int($count) || $count<1){
			return $this->unavailable($maxAttempts);
		}
		$headers=[
			'X-RateLimit-Limit'=>(string)$maxAttempts,
			'X-RateLimit-Remaining'=>(string)max(0, $maxAttempts-$count),
			'X-RateLimit-Reset'=>(string)$resetAt,
		];
		if($count>$maxAttempts){
			return Response::json(['message'=>'Too Many Requests'], 429, [
				'Retry-After'=>(string)max(1, $resetAt-$now),
			]+$headers);
		}
		$response=Response::normalize($next($request), 'html');
		return $response->withHeaders($headers);
	}

	/**
	 * Clears only the explicitly process-local store used by tests/single hosts.
	 *
	 * Shared counters are intentionally not flushed because the cache facade's
	 * flush operation would also delete unrelated application data.
	 */
	public static function flush(): void {
		LocalThrottleStore::flush();
		self::$unavailableTraced=false;
	}

	/** Returns a fail-closed response when no atomic shared policy store is available. */
	private function unavailable(int $maxAttempts): Response {
		if(self::$unavailableTraced===false){
			self::$unavailableTraced=true;
			if(function_exists('tracelog')){
				\tracelog(
					__FILE__,
					__LINE__,
					__CLASS__,
					__FUNCTION__,
					'Throttle policy store is unavailable; rejecting the request.',
					'warning'
				);
			}
		}
		return Response::json(['message'=>'Rate limiter unavailable'], 503, [
			'Cache-Control'=>'no-store',
			'Retry-After'=>'1',
			'X-RateLimit-Limit'=>(string)$maxAttempts,
			'X-RateLimit-Remaining'=>'0',
		]);
	}

	/**
	 * Builds an opaque fixed-window key without retaining identity values.
	 *
	 * @param Request $request Current HTTP request.
	 * @param ?string $bucket Optional cross-route logical bucket.
	 * @param ?string $requestField Optional credential-target field.
	 * @param int $maxAttempts Configured request limit.
	 * @param int $decaySeconds Configured window length.
	 * @param int $windowStart Fixed-window Unix timestamp.
	 * @return string Bounded namespaced SHA-256 cache key.
	 */
	private function key(
		Request $request,
		?string $bucket,
		?string $requestField,
		int $maxAttempts,
		int $decaySeconds,
		int $windowStart
	): string {
		$bucket=trim((string)$bucket);
		$scope=$bucket!==''
			? ['bucket', $bucket]
			: ['route', $request->effectiveMethod(), $request->path()];
		$app=$request->attribute('app');
		$appName=$app instanceof MvcApplication ? $app->name() : 'default';
		$material=[
			'v2',
			$appName,
			...$scope,
			$maxAttempts,
			$decaySeconds,
			$windowStart,
			'client:'.$this->identityHash($this->clientIp($request)),
			'actor:'.$this->actorIdentity($request),
			'target:'.$this->targetIdentity($request, $requestField),
		];
		return 'dataphyre:mvc:throttle:v2:'.hash('sha256', implode("\0", array_map('strval', $material)));
	}

	/** Resolves the server-controlled subject dimension, falling back to anonymous. */
	private function actorIdentity(Request $request): string {
		$attribute=$this->identityValue($request->attribute('throttle_identity'));
		if($attribute!==null){
			return 'attribute:'.$this->identityHash($attribute);
		}
		try{
			$context=Mvc::authContext();
			$user=$this->identityValue($context['userid'] ?? null);
			if(($context['logged_in'] ?? false)===true && $user!==null){
				$authType=$this->identityValue($context['auth_type'] ?? 'default') ?? 'default';
				return 'auth:'.$this->identityHash($authType."\0".$user);
			}
		}catch(\Throwable){
		}
		return 'anonymous';
	}

	/** Resolves and hashes an optional request credential-target field. */
	private function targetIdentity(Request $request, ?string $requestField): string {
		$requestField=trim((string)$requestField);
		if($requestField===''){
			return 'none';
		}
		$missing=new \stdClass();
		$value=$request->input($requestField, $missing);
		if($value===$missing){
			$value=$request->query($requestField, $missing);
		}
		$value=$value===$missing ? null : $this->identityValue($value);
		return $this->identityHash($requestField).':'.($value===null ? 'missing' : $this->identityHash($value));
	}

	/**
	 * Resolves the effective client IP without trusting a raw forwarding header.
	 *
	 * A ClientAddress request attribute is accepted because attributes are attached
	 * by server-side middleware. Otherwise the core resolver is used only when its
	 * raw peer matches this Request envelope; detached requests fall back to their
	 * REMOTE_ADDR transport peer.
	 */
	private function clientIp(Request $request): string {
		$address=$request->attribute('client_address');
		if($address instanceof ClientAddress){
			return $this->normalizeIp($address->ip());
		}
		$remote=$this->normalizeIp((string)$request->server('REMOTE_ADDR', ''));
		try{
			if(
				class_exists('\dataphyre\core', false)
				&& method_exists('\dataphyre\core', 'get_client_ip_details')
			){
				$details=\dataphyre\core::get_client_ip_details();
				if(
					is_array($details)
					&& hash_equals($remote, $this->normalizeIp((string)($details['remote_addr'] ?? '')))
				){
					return $this->normalizeIp((string)($details['ip'] ?? ''));
				}
			}
		}catch(\Throwable){
		}
		return $remote;
	}

	/** Converts a scalar or Stringable identity into a stable non-empty string. */
	private function identityValue(mixed $value): ?string {
		if(is_float($value) && !is_finite($value)){
			return null;
		}
		if(is_bool($value)){
			return $value ? 'true' : 'false';
		}
		if(!is_scalar($value) && !$value instanceof \Stringable){
			return null;
		}
		$value=trim((string)$value);
		return $value==='' ? null : $value;
	}

	/** Canonicalizes an IP or returns one shared unknown-client sentinel. */
	private function normalizeIp(string $ip): string {
		$packed=@inet_pton(trim($ip));
		if($packed===false){
			return '0.0.0.0';
		}
		$normalized=@inet_ntop($packed);
		return is_string($normalized) && $normalized!=='' ? strtolower($normalized) : '0.0.0.0';
	}

	/** Produces a fixed-size, non-reversible identity component. */
	private function identityHash(string $value): string {
		return hash('sha256', $value);
	}
}
