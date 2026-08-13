<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	$dp_access_fallback_modules=\Dataphyre\Test\dataphyre_path().'/runtime/modules';
	require_once $dp_access_fallback_modules.'/access/Framework/Contracts/Guard.php';
	require_once $dp_access_fallback_modules.'/access/Framework/Contracts/UserProvider.php';
	require_once $dp_access_fallback_modules.'/access/Framework/AuthType.php';
	require_once $dp_access_fallback_modules.'/access/Framework/AuthContext.php';
	if(!defined('DP_ACCESS_CFG')){
		define('DP_ACCESS_CFG',[
			'default_auth_type'=>'legacy',
			'auth_types'=>[],
			'framework'=>['default_guard'=>'','guards'=>'invalid','providers'=>'invalid'],
		]);
	}
}

namespace dataphyre {
	final class access {
		public static function default_auth_type(): string { return 'legacy'; }
		public static function enabled_auth_types(): array { return []; }
		public static function auth_type_enabled(string $name): bool { return $name==='dynamic'; }
		public static function logged_in(?string $type=null): bool { return false; }
		public static function userid(?string $type=null): null { return null; }
		public static function auth_context(?string $type=null): array { return ['auth_type'=>$type ?? 'legacy']; }
		public static function validate_session(bool $cache=true,?string $type=null): bool { return false; }
		public static function recover_session(?string $type=null): bool { return false; }
		public static function create_session(int $id,bool $remember=false,?string $type=null): bool { return false; }
		public static function disable_session(?string $type=null): bool { return false; }
	}
}

namespace Dataphyre\Access\Guards {
	use Dataphyre\Access\AuthContext;
	use Dataphyre\Access\Contracts\Guard;
	use Dataphyre\Access\Contracts\UserProvider;
	class AccessGuard implements Guard {
		public function __construct(private string $guardName,private string $type,?UserProvider $provider=null) {}
		public function name(): string { return $this->guardName; }
		public function authType(): string { return $this->type; }
		public function check(): bool { return false; }
		public function guest(): bool { return true; }
		public function id(): int|string|null { return null; }
		public function user(): mixed { return null; }
		public function context(): AuthContext { return AuthContext::capture($this->type,$this->guardName); }
		public function validate(bool $cache=true): bool { return false; }
		public function recover(): bool { return false; }
		public function login(mixed $user,bool $remember=false): bool { return false; }
		public function loginUsingId(int|string $identifier,bool $remember=false): bool { return false; }
		public function attempt(array $credentials,bool $remember=false): bool { return false; }
		public function logout(): bool { return false; }
	}
	final class JwtGuard extends AccessGuard {
		public function __construct(string $name,array $config=[],?UserProvider $provider=null){ parent::__construct($name,'jwt',$provider); }
	}
}

namespace {
	use Dataphyre\Access\AuthManager;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	require_once $dp_access_fallback_modules.'/access/Framework/Providers/CallbackUserProvider.php';
	require_once $dp_access_fallback_modules.'/access/Framework/AuthManager.php';

	test('access auth manager fallback coverage infers legacy and dynamic guards without config maps',static function(Context $t): void {
		AuthManager::flush();
		$manager=AuthManager::instance();
		$t->same('legacy',$manager->defaultGuard());
		$t->same(['legacy'],$manager->guardNames());
		$t->isTrue($manager->hasGuard('legacy'));
		$t->same('legacy',$manager->guard()->authType());
		$t->same('dynamic',$manager->guard('dynamic')->authType());
		$t->same(null,$manager->provider('missing'));
		$t->throws(static fn()=>$manager->guard('absent'),\RuntimeException::class);
	});
}
