<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(access::class, false)){
		final class access {
			public static bool $logged=false;
			public static bool|int|string $userid=false;
			public static string $authType='session';
			public static function logged_in(?string $authType=null): bool {
				self::$authType=$authType ?? self::$authType;
				return self::$logged;
			}
			public static function userid(?string $authType=null): bool|int|string {
				self::$authType=$authType ?? self::$authType;
				return self::$logged ? self::$userid : false;
			}
			public static function auth_context(?string $authType=null): array {
				self::$authType=$authType ?? self::$authType;
				return [
					'auth_type'=>self::$authType,
					'logged_in'=>self::$logged,
					'userid'=>self::userid(self::$authType),
				];
			}
		}
	}

	if(!class_exists(permission::class, false)){
		final class permission {
			public static function check(mixed $required, mixed $subject=null, array $context=[]): bool {
				return is_array($required) && in_array('allow', $required, true);
			}

			public static function any(mixed $required, mixed $subject=null, array $context=[]): bool {
				return is_array($required) && in_array('allow', $required, true);
			}
		}
	}

	if(!class_exists(cache::class, false)){
		final class cache {
			public static bool $shared=false;
			public static bool $dropAfterIncrement=false;
			public static int $count=0;
			public static array $calls=[];

			public static function reset(): void {
				self::$shared=false;
				self::$dropAfterIncrement=false;
				self::$count=0;
				self::$calls=[];
			}

			public static function isShared(): bool {
				return self::$shared;
			}

			public static function incrementShared(string $key, int $offset=1, int $expiration=0): int|false {
				if(self::$shared===false){
					return false;
				}
				self::$calls[]=['key'=>$key, 'offset'=>$offset, 'expiration'=>$expiration];
				self::$count+=$offset;
				if(self::$dropAfterIncrement){
					self::$shared=false;
				}
				return self::$count;
			}
		}
	}
}

namespace {
	use Dataphyre\ClientAddress;
	use Dataphyre\Http\Request;
	use Dataphyre\Http\Response;
	use Dataphyre\Mvc\AccessMiddleware;
	use Dataphyre\Mvc\CacheMiddleware;
	use Dataphyre\Mvc\CsrfMiddleware;
	use Dataphyre\Mvc\GuestMiddleware;
	use Dataphyre\Mvc\HttpException;
	use Dataphyre\Mvc\LocalThrottleStore;
	use Dataphyre\Mvc\MvcApplication;
	use Dataphyre\Mvc\PermissionAnyMiddleware;
	use Dataphyre\Mvc\PermissionMiddleware;
	use Dataphyre\Mvc\Session;
	use Dataphyre\Mvc\SessionMiddleware;
	use Dataphyre\Mvc\SharedCacheThrottleStore;
	use Dataphyre\Mvc\SignedUrl;
	use Dataphyre\Mvc\SignedUrlMiddleware;
	use Dataphyre\Mvc\ThrottleMiddleware;
	use Dataphyre\Mvc\ThrottleStore;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!defined('DATAPHYRE_MODULE_POLICY')){
		define('DATAPHYRE_MODULE_POLICY', [
			'enabled'=>['core'=>true, 'http'=>true, 'routing'=>true, 'mvc'=>true],
			'disabled'=>['access'=>true, 'permission'=>true],
			'core_implicit'=>true,
		]);
	}
	$dp_mvc_middleware_modules_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules';
	require_once $dp_mvc_middleware_modules_root.'/core/kernel/autoloader.php';
	require_once $dp_mvc_middleware_modules_root.'/core/Framework/ClientAddress.php';
	\dataphyre\autoloader::register($dp_mvc_middleware_modules_root);
	\dataphyre\autoloader::register_framework_modules(['http', 'routing', 'mvc']);

	function dp_mvc_middleware_request_from_url(string $url, string $method='GET'): Request {
		$parts=parse_url($url);
		$query=[];
		parse_str((string)($parts['query'] ?? ''), $query);
		return Request::create($method, (string)($parts['path'] ?? '/'), $query);
	}

	test('mvc authentication and permission middleware cover allow deny and empty policies', static function(Context $t): void {
		$request=Request::create('GET', '/protected');
		$next=static fn(Request $received): string=>'next:'.$received->path();
		$access=new AccessMiddleware();
		$guest=new GuestMiddleware();

		\dataphyre\access::$logged=true;
		$t->same('next:/protected', $access->handle($request, $next, 'web'));
		$t->throws(static fn()=>$guest->handle($request, $next, 'web'), HttpException::class);
		\dataphyre\access::$logged=false;
		$t->throws(static fn()=>$access->handle($request, $next), HttpException::class);
		$t->same('next:/protected', $guest->handle($request, $next));

		$permission=new PermissionMiddleware();
		$permissionAny=new PermissionAnyMiddleware();
		$t->same('next:/protected', $permission->handle($request, $next, 'deny', 'allow'));
		$t->throws(static fn()=>$permission->handle($request, $next, 'deny'), HttpException::class);
		$t->throws(static fn()=>$permission->handle($request, $next), HttpException::class);
		$t->same('next:/protected', $permissionAny->handle($request, $next, 'allow'));
		$t->throws(static fn()=>$permissionAny->handle($request, $next, 'deny'), HttpException::class);
		$t->throws(static fn()=>$permissionAny->handle($request, $next), HttpException::class);
	})->tag('mvc', 'middleware', 'deep-coverage')->group('framework-coverage');

	test('mvc cache csrf and session middleware cover validators tokens and termination', static function(Context $t): void {
		$cache=new CacheMiddleware();
		$request=Request::create('GET', '/cached');
		$response=$cache->handle($request, static fn(): string=>'cached', 120, 'private', 'stable', 100);
		$t->same('private, max-age=120', $response->headers['Cache-Control']);
		$t->same('"stable"', $response->headers['ETag']);
		$t->isTrue(isset($response->headers['Last-Modified']));
		$conditional=$cache->handle(
			Request::create('GET', '/cached', [], [], [], [], ['If-None-Match'=>'"stable"']),
			static fn(): string=>'cached',
			-10,
			'public',
			'stable',
			'invalid date value'
		);
		$t->same(304, $conditional->status);
		$dated=$cache->handle($request, static fn(): string=>'dated', 1, 'public', '', '2026-01-01 00:00:00 UTC');
		$t->isTrue(isset($dated->headers['Last-Modified']));

		Session::flush();
		$csrf=new CsrfMiddleware();
		$t->same('safe', $csrf->handle(Request::create('GET', '/form'), static fn(): string=>'safe'));
		$invalid=$csrf->handle(Request::create('POST', '/form'), static fn(): string=>'never');
		$t->same(419, $invalid->status);
		$token=Session::token();
		$valid=$csrf->handle(Request::create('POST', '/form', [], ['_token'=>$token]), static fn(): string=>'valid');
		$t->same('valid', $valid);

		$sessionMiddleware=new SessionMiddleware();
		$sessionRequest=Request::create('GET', '/session');
		Session::flash('notice', 'saved');
		$t->same('session-ok', $sessionMiddleware->handle($sessionRequest, static fn(): string=>'session-ok'));
		$t->same(Session::class, $sessionRequest->attribute('session'));
		$sessionMiddleware->terminate($sessionRequest, Response::make('done'));
		$t->same('saved', Session::get('notice'));
		Session::flush();
	})->tag('mvc', 'middleware', 'deep-coverage')->group('framework-coverage');

	test('mvc signed URL middleware covers explicit configured environment and invalid secrets', static function(Context $t): void {
		$next=static fn(Request $request): string=>'signed:'.$request->path();
		$explicit=new SignedUrlMiddleware('explicit-secret');
		$request=dp_mvc_middleware_request_from_url(SignedUrl::sign('/signed?value=1', 'explicit-secret'));
		$t->same('signed:/signed', $explicit->handle($request, $next));
		$t->same(403, $explicit->handle(Request::create('GET', '/signed'), $next)->status);

		$app=new MvcApplication('signed-middleware-config', ['signed_url_secret'=>'configured-secret']);
		$configuredRequest=dp_mvc_middleware_request_from_url(SignedUrl::sign('/configured', 'configured-secret'));
		$configuredRequest->setAttribute('app', $app);
		$t->same('signed:/configured', (new SignedUrlMiddleware())->handle($configuredRequest, $next));

		$t->setEnvironmentForTest(['DATAPHYRE_MVC_SIGNING_KEY'=>'environment-secret']);
		$environmentRequest=dp_mvc_middleware_request_from_url(SignedUrl::sign('/environment', 'environment-secret'));
		$t->same('signed:/environment', (new SignedUrlMiddleware(''))->handle($environmentRequest, $next));
		$t->setEnvironmentForTest(['DATAPHYRE_MVC_SIGNING_KEY'=>null]);
		$t->throws(static fn()=>(new SignedUrlMiddleware())->handle(Request::create('GET', '/no-secret'), $next), RuntimeException::class);
	})->tag('mvc', 'middleware', 'deep-coverage')->group('framework-coverage');

	test('mvc throttle middleware shares logical buckets across routes with bounded composite identities', static function(Context $t): void {
		ThrottleMiddleware::flush();
		$now=120;
		$middleware=new ThrottleMiddleware(new LocalThrottleStore(), static function()use(&$now): int {
			return $now;
		});
		$request=Request::create(
			'POST',
			'/login',
			[],
			['account'=>['name'=>'person@example.test']],
			[],
			['REMOTE_ADDR'=>'192.0.2.10'],
			['X-Forwarded-For'=>'203.0.113.200']
		);
		$request->setAttribute('throttle_identity', 'tenant-a:visitor');
		$first=$middleware->handle($request, static fn(): string=>'first', 1, 60, 'credential-entry', 'account.name');
		$t->same(200, $first->status);
		$t->same('0', $first->headers['X-RateLimit-Remaining']);
		$t->same('180', $first->headers['X-RateLimit-Reset']);

		$secondRoute=Request::create(
			'PUT',
			'/account/recover',
			[],
			['account'=>['name'=>'person@example.test']],
			[],
			['REMOTE_ADDR'=>'192.0.2.10'],
			['X-Forwarded-For'=>'198.51.100.99']
		);
		$secondRoute->setAttribute('throttle_identity', 'tenant-a:visitor');
		$limited=$middleware->handle($secondRoute, static fn(): string=>'never', 1, 60, 'credential-entry', 'account.name');
		$t->same(429, $limited->status);
		$t->same('0', $limited->headers['X-RateLimit-Remaining']);
		$t->same('60', $limited->headers['Retry-After']);

		$internals=$t->nonPublic($middleware);
		$key=$internals->invoke('key', $request, 'credential-entry', 'account.name', 1, 60, 120);
		$t->contains('dataphyre:mvc:throttle:v2:', $key);
		$t->notContains('person@example.test', $key);
		$t->notContains('192.0.2.10', $key);
		$t->notContains('credential-entry', $key);
		$trustedProxyRequest=Request::create('POST', '/login', [], [], [], [
			'REMOTE_ADDR'=>'10.0.0.20',
		]);
		$trustedProxyRequest->setAttribute('client_address', new ClientAddress(
			'203.0.113.45',
			'10.0.0.20',
			'header',
			'HTTP_X_FORWARDED_FOR',
			true
		));
		$t->same('203.0.113.45', $internals->invoke('clientIp', $trustedProxyRequest));

		$differentTarget=Request::create('POST', '/login', [], [
			'account'=>['name'=>'other@example.test'],
		], [], ['REMOTE_ADDR'=>'192.0.2.10']);
		$differentTarget->setAttribute('throttle_identity', 'tenant-a:visitor');
		$t->same(200, $middleware->handle(
			$differentTarget,
			static fn(): string=>'target',
			1,
			60,
			'credential-entry',
			'account.name'
		)->status);

		$differentActor=Request::create('POST', '/login', [], [
			'account'=>['name'=>'person@example.test'],
		], [], ['REMOTE_ADDR'=>'192.0.2.10']);
		$differentActor->setAttribute('throttle_identity', 'tenant-a:other-visitor');
		$t->same(200, $middleware->handle(
			$differentActor,
			static fn(): string=>'actor',
			1,
			60,
			'credential-entry',
			'account.name'
		)->status);

		$differentClient=Request::create('POST', '/login', [], [
			'account'=>['name'=>'person@example.test'],
		], [], ['REMOTE_ADDR'=>'192.0.2.11']);
		$differentClient->setAttribute('throttle_identity', 'tenant-a:visitor');
		$t->same(200, $middleware->handle(
			$differentClient,
			static fn(): string=>'client',
			1,
			60,
			'credential-entry',
			'account.name'
		)->status);

		$now=180;
		$reset=$middleware->handle($request, static fn(): string=>'reset', 1, 60, 'credential-entry', 'account.name');
		$t->same(200, $reset->status);
		ThrottleMiddleware::flush();
	})->tag('mvc', 'middleware', 'throttle', 'identity', 'concurrency')->group('framework-coverage');

	test('mvc throttle middleware uses authenticated subjects and fails closed without policy storage', static function(Context $t): void {
		ThrottleMiddleware::flush();
		\dataphyre\access::$logged=true;
		\dataphyre\access::$userid='user-42';
		$middleware=new ThrottleMiddleware(new LocalThrottleStore(), static fn(): int=>300);
		$first=Request::create('GET', '/profile', [], [], [], ['REMOTE_ADDR'=>'198.51.100.4']);
		$second=Request::create('DELETE', '/sessions/current', [], [], [], ['REMOTE_ADDR'=>'198.51.100.4']);
		$t->same(200, $middleware->handle($first, static fn(): string=>'first', 1, 60, 'account-actions')->status);
		$t->same(429, $middleware->handle($second, static fn(): string=>'never', 1, 60, 'account-actions')->status);

		$ran=false;
		$unavailableStore=new class implements ThrottleStore {
			public function increment(string $key, int $ttlSeconds): int|false {
				return false;
			}
		};
		$unavailable=(new ThrottleMiddleware($unavailableStore, static fn(): int=>300))->handle(
			Request::create('GET', '/strict', [], [], [], ['REMOTE_ADDR'=>'203.0.113.8']),
			static function()use(&$ran): string {
				$ran=true;
				return 'unsafe';
			},
			10,
			60,
			'strict-policy'
		);
		$t->same(503, $unavailable->status);
		$t->same('no-store', $unavailable->headers['Cache-Control']);
		$t->same('0', $unavailable->headers['X-RateLimit-Remaining']);
		$t->isFalse($ran);
		$throwingStore=new class implements ThrottleStore {
			public function increment(string $key, int $ttlSeconds): int|false {
				throw new RuntimeException('simulated policy-store outage');
			}
		};
		$t->same(503, (new ThrottleMiddleware($throwingStore, static fn(): int=>300))->handle(
			Request::create('GET', '/strict', [], [], [], ['REMOTE_ADDR'=>'203.0.113.8']),
			static fn(): string=>'unsafe',
			10,
			60,
			'strict-policy'
		)->status);
		\dataphyre\access::$logged=false;
		\dataphyre\access::$userid=false;
		ThrottleMiddleware::flush();
	})->tag('mvc', 'middleware', 'throttle', 'authentication', 'fail-closed')->group('framework-coverage');

	test('mvc shared cache throttle store rejects local fallback and mid-operation degradation', static function(Context $t): void {
		\dataphyre\cache::reset();
		$store=new SharedCacheThrottleStore();
		$t->isFalse($store->increment('opaque-key', 60));
		$t->same([], \dataphyre\cache::$calls);

		\dataphyre\cache::$shared=true;
		$t->same(1, $store->increment('opaque-key', 60));
		$t->same(60, \dataphyre\cache::$calls[0]['expiration'] ?? null);
		\dataphyre\cache::$dropAfterIncrement=true;
		$t->isFalse($store->increment('opaque-key', 60));
		\dataphyre\cache::reset();
	})->tag('mvc', 'middleware', 'throttle', 'cache', 'fail-closed')->group('framework-coverage');
}
