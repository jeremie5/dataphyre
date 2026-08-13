<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	if(!class_exists(core::class, false)){
		final class core {
			public static array $dialbacks=[];
			public static function register_dialback(string $name, callable $callback): bool {
				self::$dialbacks[$name][]=$callback;
				return true;
			}
		}
	}
	if(!class_exists(access::class, false)){
		final class access {
			public static array $context=[];
			public static function auth_context(?string $authType=null): array {
				return self::$context+($authType!==null ? ['auth_type'=>$authType] : []);
			}
		}
	}
}

namespace Dataphyre\Http {
	if(!class_exists(Request::class, false)){
		final class Request {}
	}
	if(!class_exists(Response::class, false)){
		final class Response {
			public function __construct(public string $location){}
		}
	}
}

namespace Dataphyre\Access\Exceptions {
	if(!class_exists(AuthenticationException::class, false)){
		final class AuthenticationException extends \RuntimeException {}
	}
}

namespace Dataphyre\Access {
	if(!class_exists(AccessIdentityRepository::class, false)){
		final class AccessIdentityRepository {
			private static ?self $instance=null;
			public array $calls=[];
			public static function instance(): self { return self::$instance ??= new self(); }
			public function findByEmail(string $email): mixed { $this->calls[]=['email',$email]; return ['id'=>1,'email'=>$email]; }
			public function findById(int|string $id): mixed { $this->calls[]=['id',$id]; return ['id'=>$id]; }
			public function create(array $attributes): mixed { $this->calls[]=['create',$attributes]; return $attributes+['id'=>2]; }
			public function verifyPassword(mixed $user, string $password): bool { return $password==='secret'; }
			public function setPassword(mixed $user, string $password): bool { return $password!==''; }
			public function markEmailVerified(mixed $user): bool { return true; }
			public function identifier(mixed $user): int|string|null { return is_array($user) ? ($user['id'] ?? null) : null; }
			public function email(mixed $user): ?string { return is_array($user) && isset($user['email']) ? (string)$user['email'] : null; }
			public function emailVerified(mixed $user): bool { return is_array($user) && ($user['verified'] ?? false)===true; }
		}
	}

	if(!class_exists(AccessTokenBroker::class, false)){
		final class AccessTokenBroker {
			private static ?self $instance=null;
			public static function instance(): self { return self::$instance ??= new self(); }
		}
	}

	if(!class_exists(DpAccessSmallGuard::class, false)){
		final class DpAccessSmallGuard {
			public bool $checked=true;
			public int|string|null $identifier=42;
			public bool $validated=true;
			public mixed $recovered='recovered';
			public function check(): bool { return $this->checked; }
			public function id(): int|string|null { return $this->identifier; }
			public function validate(bool $cache=true): bool { return $this->validated && $cache; }
			public function recover(): mixed { return $this->recovered; }
		}
	}

	if(!class_exists(Auth::class, false)){
		final class Auth {
			public static array $checks=[];
			public static ?string $selected=null;
			public static ?DpAccessSmallGuard $guard=null;
			public static function check(?string $guard=null): bool { return self::$checks[$guard ?? 'default'] ?? false; }
			public static function shouldUse(string $guard): void { self::$selected=$guard; }
			public static function guard(?string $guard=null): DpAccessSmallGuard { return self::$guard ??= new DpAccessSmallGuard(); }
		}
	}
}

namespace Dataphyre\Access\OAuthClient {
	use Dataphyre\Http\Response;

	if(!class_exists(OAuthUser::class, false)){
		final class OAuthUser {
			public function __construct(public string $id='user', public ?string $accessToken='access', public ?string $refreshToken='refresh'){}
		}
	}

	if(!class_exists(Provider::class, false)){
		class Provider {
			public array $options=[];
			public function with(array $options): self { $clone=clone $this; $clone->options=$options; return $clone; }
			public function authorizationUrl(): string { return 'https://provider.test/authorize?'.http_build_query($this->options); }
			public function redirect(): Response { return new Response($this->authorizationUrl()); }
			public function user(mixed $request=null): OAuthUser { return new OAuthUser('callback'); }
			public function userFromToken(string $accessToken): OAuthUser { return new OAuthUser('token', $accessToken); }
			public function refresh(string|OAuthUser $source): array { return ['access_token'=>'refreshed']; }
			public function refreshedUser(string|OAuthUser $source): OAuthUser { return new OAuthUser('refreshed', 'refreshed-access'); }
			public function revoke(string|OAuthUser $source, ?string $hint=null): bool { return $hint!=='deny'; }
			public function login(mixed $requestOrUser=null, ?string $guard=null, bool $remember=true): bool { return $remember; }
		}
	}

	if(!class_exists(Manager::class, false)){
		final class Manager {
			private static ?self $instance=null;
			/** @var array<string,Provider> */
			private array $providers=[];
			public static function instance(): self { return self::$instance ??= new self(); }
			public static function flush(): void { self::$instance=null; }
			public function providerNames(): array { return array_keys($this->providers); }
			public function hasProvider(string $name): bool { return isset($this->providers[$name]); }
			public function provider(string $name): Provider { return $this->providers[$name] ??= new Provider(); }
			public function extendProvider(string $name, mixed $config): void { $this->providers[$name]=$config instanceof Provider ? $config : new Provider(); }
		}
	}
}

namespace {
	use Dataphyre\Access\AccessIdentity;
	use Dataphyre\Access\AccessIdentityRepository;
	use Dataphyre\Access\AccessTokenBroker;
	use Dataphyre\Access\Auth;
	use Dataphyre\Access\AuthContext;
	use Dataphyre\Access\AuthType;
	use Dataphyre\Access\Exceptions\AuthenticationException;
	use Dataphyre\Access\Jwt\JwtPayload;
	use Dataphyre\Access\Middleware\Authenticate;
	use Dataphyre\Access\Middleware\Guest;
	use Dataphyre\Access\OAuth;
	use Dataphyre\Access\OAuthClient\Manager;
	use Dataphyre\Access\OAuthClient\OAuthUser;
	use Dataphyre\Access\OAuthClient\Provider;
	use Dataphyre\Http\Response;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	$dp_access_small_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/access/Framework';
	require_once $dp_access_small_root.'/AccessIdentity.php';
	require_once $dp_access_small_root.'/AuthType.php';
	require_once $dp_access_small_root.'/AuthContext.php';
	require_once $dp_access_small_root.'/Jwt/JwtPayload.php';
	require_once $dp_access_small_root.'/Middleware/Authenticate.php';
	require_once $dp_access_small_root.'/Middleware/Guest.php';
	require_once $dp_access_small_root.'/OAuth.php';
	require_once $dp_access_small_root.'/Bootstrap.php';

	test('access identity facade delegates every repository and token broker operation', static function(Context $t): void {
		$repository=AccessIdentity::repository();
		$t->instanceOf(AccessIdentityRepository::class, $repository);
		$t->instanceOf(AccessTokenBroker::class, AccessIdentity::tokens());
		$t->same('person@example.test', AccessIdentity::findByEmail('person@example.test')['email']);
		$t->same(7, AccessIdentity::findById(7)['id']);
		$t->same(2, AccessIdentity::create(['email'=>'new@example.test'])['id']);
		$user=['id'=>9, 'email'=>'user@example.test', 'verified'=>true];
		$t->isTrue(AccessIdentity::verifyPassword($user, 'secret'));
		$t->isFalse(AccessIdentity::verifyPassword($user, 'wrong'));
		$t->isTrue(AccessIdentity::setPassword($user, 'new-secret'));
		$t->isTrue(AccessIdentity::markEmailVerified($user));
		$t->same(9, AccessIdentity::identifier($user));
		$t->same('user@example.test', AccessIdentity::email($user));
		$t->isTrue(AccessIdentity::emailVerified($user));
	})->tag('access', 'small-facades', 'deep-coverage')->group('framework-coverage');

	test('access auth context payload and JWT values normalize scalar fields and expose accessors', static function(Context $t): void {
		\dataphyre\access::$context=[
			'auth_type'=>'jwt', 'logged_in'=>true, 'userid'=>'42', 'id'=>9001, 'cookie_name'=>'access_cookie',
		];
		$context=AuthContext::capture(null, 'api');
		$t->same('api', $context->guardName());
		$t->same('jwt', $context->authType());
		$t->isTrue($context->loggedIn());
		$t->same('42', $context->userId());
		$t->same('9001', $context->identifier());
		$t->same('access_cookie', $context->cookieName());
		\dataphyre\access::$context=['userid'=>['invalid'], 'cookie_name'=>null];
		$fallback=AuthContext::capture();
		$t->same(AuthType::SESSION, $fallback->authType());
		$t->same(null, $fallback->userId());
		$t->same(null, $fallback->identifier());
		$t->same(null, $fallback->cookieName());
		$authType=$t->nonPublic(AuthType::class)->withoutConstructor();
		$t->nonPublic($authType)->invoke('__construct');
		$t->instanceOf(AuthType::class, $authType);
		$t->same('jwt', AuthType::JWT);

		$payload=new JwtPayload('a.b.c', ['alg'=>'HS256'], ['sub'=>'7']);
		$t->same('a.b.c', $payload->token());
		$t->same(['alg'=>'HS256'], $payload->headers());
		$t->same(['sub'=>'7'], $payload->claims());
		$t->same('HS256', $payload->header('alg'));
		$t->same('fallback', $payload->header('kid', 'fallback'));
		$t->same('7', $payload->claim('sub'));
		$t->same(null, $payload->claim('missing'));
	})->tag('access', 'small-facades', 'deep-coverage')->group('framework-coverage');

	test('access authenticate and guest middleware cover default named blank success and denial guards', static function(Context $t): void {
		Auth::$checks=['default'=>true, 'api'=>true, ''=>true, 'guest'=>false];
		Auth::$selected=null;
		$authenticate=new Authenticate();
		$t->same('next:request', $authenticate->handle('request', static fn(string $request): string=>'next:'.$request));
		$t->same('api-next', $authenticate->handle('request', static fn(): string=>'api-next', 'missing', 'api'));
		$t->same('api', Auth::$selected);
		Auth::$selected=null;
		$t->same('blank-next', $authenticate->handle('request', static fn(): string=>'blank-next', ''));
		$t->same(null, Auth::$selected);
		Auth::$checks=['default'=>false, 'api'=>false];
		$t->throws(static fn()=>$authenticate->handle('request', static fn()=>null, 'api'), AuthenticationException::class);

		$guest=new Guest();
		Auth::$checks=['default'=>false, 'guest'=>false];
		$t->same('guest-next', $guest->handle('request', static fn(): string=>'guest-next'));
		$t->same('named-next', $guest->handle('request', static fn(): string=>'named-next', 'guest'));
		Auth::$checks=['member'=>true];
		$t->throws(static fn()=>$guest->handle('request', static fn()=>null, 'member'), AuthenticationException::class);
	})->tag('access', 'small-facades', 'deep-coverage')->group('framework-coverage');

	test('access OAuth facade delegates manager provider token user refresh revoke redirect and login flows', static function(Context $t): void {
		OAuth::flush();
		$t->instanceOf(Manager::class, OAuth::manager());
		$t->same([], OAuth::providers());
		$t->isFalse(OAuth::hasProvider('demo'));
		OAuth::extendProvider('demo', new Provider());
		$t->isTrue(OAuth::hasProvider('demo'));
		$t->same(['demo'], OAuth::providers());
		$t->instanceOf(Provider::class, OAuth::provider('demo'));
		$t->contains('scope=profile', OAuth::authorizationUrl('demo', ['scope'=>'profile']));
		$t->instanceOf(Response::class, OAuth::redirect('demo', ['prompt'=>'login']));
		$t->same('callback', OAuth::user('demo', ['code'=>'one'])->id);
		$t->same('token-value', OAuth::userFromToken('demo', 'token-value')->accessToken);
		$t->same('refreshed', OAuth::refresh('demo', 'refresh-token')['access_token']);
		$user=new OAuthUser('source');
		$t->same('refreshed-access', OAuth::refreshedUser('demo', $user)->accessToken);
		$t->isTrue(OAuth::revoke('demo', $user, 'access_token'));
		$t->isFalse(OAuth::revoke('demo', 'token', 'deny'));
		$t->isTrue(OAuth::login('demo', $user, 'session', true));
		$t->isFalse(OAuth::login('demo', null, null, false));
	})->tag('access', 'small-facades', 'deep-coverage')->group('framework-coverage');

	test('access framework bootstrap registers JWT dialbacks with non-JWT fallthrough and guard results', static function(Context $t): void {
		$t->isTrue(defined('Dataphyre\\Access\\DP_ACCESS_CFG'));
		$t->isTrue(defined('DATAPHYRE_ACCESS_FRAMEWORK_BOOTSTRAPPED'));
		$events=\dataphyre\core::$dialbacks;
		$t->same(5, count($events));
		$invoke=static fn(string $name, mixed ...$arguments): mixed=>$events[$name][0](...$arguments);
		$t->same(null, $invoke('CALL_ACCESS_LOGGED_IN_AUTH_TYPE', 'session'));
		$t->isTrue($invoke('CALL_ACCESS_LOGGED_IN_AUTH_TYPE', ' JWT '));
		$t->same(null, $invoke('CALL_ACCESS_USERID_AUTH_TYPE', 'session'));
		$t->same(42, $invoke('CALL_ACCESS_USERID_AUTH_TYPE', 'jwt'));
		$t->same(null, $invoke('CALL_ACCESS_VALIDATE_SESSION_AUTH_TYPE', 'session', true));
		$t->isTrue($invoke('CALL_ACCESS_VALIDATE_SESSION_AUTH_TYPE', 'jwt', true));
		$t->isFalse($invoke('CALL_ACCESS_VALIDATE_SESSION_AUTH_TYPE', 'jwt', false));
		$t->same(null, $invoke('CALL_ACCESS_RECOVER_SESSION_AUTH_TYPE', 'session'));
		$t->same('recovered', $invoke('CALL_ACCESS_RECOVER_SESSION_AUTH_TYPE', 'jwt'));
		$t->same(null, $invoke('CALL_ACCESS_DISABLE_SESSION_AUTH_TYPE', 'session'));
		$t->isFalse($invoke('CALL_ACCESS_DISABLE_SESSION_AUTH_TYPE', 'jwt'));
	})->tag('access', 'small-facades', 'deep-coverage')->group('framework-coverage');
}
