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
			public static function logged_in(?string $authType=null): bool {
				return self::$logged;
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
}

namespace {
	use Dataphyre\Http\Request;
	use Dataphyre\Http\Response;
	use Dataphyre\Mvc\AccessMiddleware;
	use Dataphyre\Mvc\CacheMiddleware;
	use Dataphyre\Mvc\CsrfMiddleware;
	use Dataphyre\Mvc\GuestMiddleware;
	use Dataphyre\Mvc\HttpException;
	use Dataphyre\Mvc\MvcApplication;
	use Dataphyre\Mvc\PermissionAnyMiddleware;
	use Dataphyre\Mvc\PermissionMiddleware;
	use Dataphyre\Mvc\Session;
	use Dataphyre\Mvc\SessionMiddleware;
	use Dataphyre\Mvc\SignedUrl;
	use Dataphyre\Mvc\SignedUrlMiddleware;
	use Dataphyre\Mvc\ThrottleMiddleware;
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

	test('mvc throttle middleware covers accepted exhausted expired and fallback identity buckets', static function(Context $t): void {
		ThrottleMiddleware::flush();
		$middleware=new ThrottleMiddleware();
		$request=Request::create('GET', '/limited', [], [], [], ['REMOTE_ADDR'=>'127.0.0.1']);
		$first=$middleware->handle($request, static fn(): string=>'first', 1, 60);
		$t->same(200, $first->status);
		$t->same('0', $first->headers['X-RateLimit-Remaining']);
		$limited=$middleware->handle($request, static fn(): string=>'never', 1, 60);
		$t->same(429, $limited->status);
		$t->same('0', $limited->headers['X-RateLimit-Remaining']);

		$internals=$t->nonPublic($middleware);
		$key=$internals->invoke('key', $request, null, 60);
		$t->nonPublic(ThrottleMiddleware::class)->replacePropertyForTest('buckets', [
			$key=>['count'=>99, 'reset_at'=>time()-1],
		]);
		$reset=$middleware->handle($request, static fn(): string=>'reset', 1, 60);
		$t->same(200, $reset->status);
		$fallback=Request::create('POST', '/fallback', [], [], [], [], ['X-Forwarded-For'=>'203.0.113.1']);
		$fallbackKey=$internals->invoke('key', $fallback, 'custom', 1);
		$t->contains('custom|POST|203.0.113.1|1', $fallbackKey);
		ThrottleMiddleware::flush();
	})->tag('mvc', 'middleware', 'deep-coverage')->group('framework-coverage');
}
