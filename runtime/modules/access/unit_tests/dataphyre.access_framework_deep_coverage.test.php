<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	$dp_access_modules=\Dataphyre\Test\dataphyre_path().'/runtime/modules';
	require_once $dp_access_modules.'/access/Framework/Contracts/Guard.php';
	require_once $dp_access_modules.'/access/Framework/Contracts/UserProvider.php';
	require_once $dp_access_modules.'/access/Framework/AuthType.php';
	require_once $dp_access_modules.'/access/Framework/AuthContext.php';

	function dp_access_framework_identity_state(): \Dataphyre\Test\TestState {
		return \Dataphyre\Test\TestState::channel('access.framework.identity');
	}

	function dp_access_framework_sql_state(): \Dataphyre\Test\TestState {
		return \Dataphyre\Test\TestState::channel('access.framework.sql');
	}

	function dp_access_identity_callback(string $name,array $arguments): mixed {
		$value=dp_access_framework_identity_state()->get($name);
		return $value instanceof \Closure ? $value(...$arguments) : $value;
	}
	function dp_access_find_by_email(string $email): mixed { return dp_access_identity_callback('find_by_email',[$email]); }
	function dp_access_find_by_id(int|string $id): mixed { return dp_access_identity_callback('find_by_id',[$id]); }
	function dp_access_create_identity(array $attributes): mixed { return dp_access_identity_callback('create',[$attributes]); }
	function dp_access_verify_password(mixed $user,string $password): mixed { return dp_access_identity_callback('verify_password',[$user,$password]); }
	function dp_access_set_password(mixed $user,string $password): mixed { return dp_access_identity_callback('set_password',[$user,$password]); }
	function dp_access_mark_verified(mixed $user): mixed { return dp_access_identity_callback('mark_email_verified',[$user]); }
	function dp_access_identifier(mixed $user): mixed { return dp_access_identity_callback('identifier',[$user]); }
	function dp_access_email_verified(mixed $user): mixed { return dp_access_identity_callback('email_verified',[$user]); }

	function dp_access_provider_by_id(int|string $id): mixed { return ['id'=>$id]; }
	function dp_access_provider_by_credentials(array $credentials): mixed { return $credentials['email'] ?? null; }
	function dp_access_provider_validate(mixed $user,array $credentials): bool { return $user!==null && ($credentials['password'] ?? '')==='secret'; }
	function dp_access_provider_identifier(mixed $user): int|string|null { return is_array($user) ? ($user['id'] ?? null) : null; }

	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed {
			$state=dp_access_framework_sql_state();
			$state->append('calls',['select',$arguments]);
			return $state->get('select');
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed {
			$state=dp_access_framework_sql_state();
			$state->append('calls',['insert',$arguments]);
			return $state->get('insert');
		}
	}
	if(!function_exists('sql_update')){
		function sql_update(mixed ...$arguments): mixed {
			$state=dp_access_framework_sql_state();
			$state->append('calls',['update',$arguments]);
			return $state->get('update');
		}
	}
}

namespace dataphyre {
	if(!class_exists(access::class,false)){
		final class access {
			public static string $defaultType='session';
			/** @var list<string> */
			public static array $enabledTypes=['session','jwt','configured'];
			public static string $currentType='jwt';
			/** @var array<string,bool> */
			public static array $loggedIn=[];
			/** @var array<string,int|string|false|null> */
			public static array $userIds=[];
			public static bool $validateResult=true;
			public static bool $recoverResult=true;
			public static bool $createResult=true;
			public static bool $disableResult=true;
			public static bool $accessResult=true;
			/** @var list<array<int,mixed>> */
			public static array $calls=[];

			public static function reset(): void {
				self::$loggedIn=['session'=>true,'configured'=>true];
				self::$userIds=['session'=>42,'configured'=>43];
				self::$validateResult=true; self::$recoverResult=true; self::$createResult=true;
				self::$disableResult=true; self::$accessResult=true; self::$calls=[];
			}
			public static function default_auth_type(): string { return self::$defaultType; }
			public static function enabled_auth_types(): array { return self::$enabledTypes; }
			public static function auth_type_enabled(string $name): bool { return in_array($name,self::$enabledTypes,true); }
			public static function current_auth_type(): string { return self::$currentType; }
			public static function logged_in(?string $type=null): bool { return self::$loggedIn[$type ?? self::$defaultType] ?? false; }
			public static function userid(?string $type=null): int|string|false|null { return self::$userIds[$type ?? self::$defaultType] ?? null; }
			public static function auth_context(?string $type=null): array {
				$type ??= self::$defaultType;
				return ['auth_type'=>$type,'logged_in'=>self::logged_in($type),'userid'=>self::userid($type),'id'=>'ctx-'.$type,'cookie_name'=>'cookie-'.$type];
			}
			public static function validate_session(bool $cache=true,?string $type=null): bool { self::$calls[]=['validate',$cache,$type]; return self::$validateResult; }
			public static function recover_session(?string $type=null): bool { self::$calls[]=['recover',$type]; return self::$recoverResult; }
			public static function create_session(int $id,bool $remember=false,?string $type=null): bool { self::$calls[]=['create',$id,$remember,$type]; return self::$createResult; }
			public static function disable_session(?string $type=null): bool { self::$calls[]=['disable',$type]; return self::$disableResult; }
			public static function access(bool $session=true,bool $guest=false,bool $mobile=false,bool $robot=false): bool { self::$calls[]=['access',$session,$guest,$mobile,$robot]; return self::$accessResult; }
		}
	}

	if(!class_exists(core::class,false)){
		final class core {
			/** @var array<string,list<mixed>> */
			public static array $responses=[];
			/** @var list<array<int,mixed>> */
			public static array $calls=[];
			public static function reset(): void { self::$responses=[]; self::$calls=[]; }
			public static function dialback(string $name,mixed ...$arguments): mixed {
				self::$calls[]=[$name,...$arguments];
				return self::$responses[$name]!==[] ? array_shift(self::$responses[$name]) : null;
			}
		}
	}
}

namespace Dataphyre\Access\OAuthClient {
	if(!class_exists(Provider::class,false)){
		final class Provider { public function __construct(public string $name) {} }
	}
}

namespace Dataphyre\Access {
	if(!class_exists(OAuth::class,false)){
		final class OAuth {
			public static function provider(string $name): \Dataphyre\Access\OAuthClient\Provider { return new \Dataphyre\Access\OAuthClient\Provider($name); }
		}
	}
}

namespace DpAccessCoverage {
	use Dataphyre\Access\AuthContext;
	use Dataphyre\Access\Contracts\Guard;
	use Dataphyre\Access\Contracts\UserProvider;

	class Provider implements UserProvider {
		public function retrieveById(int|string $identifier): mixed { return ['id'=>$identifier,'provider'=>'direct']; }
		public function retrieveByCredentials(array $credentials): mixed { return $credentials['user'] ?? null; }
		public function validateCredentials(mixed $user,array $credentials): bool { return $user!==null; }
		public function authIdentifier(mixed $user): int|string|null { return is_array($user) ? ($user['id'] ?? null) : (is_int($user)||is_string($user) ? $user : null); }
	}
	final class ArgProvider extends Provider { public function __construct(public string $prefix) {} }
	final class InvalidProvider {}

	class SpyGuard implements Guard {
		public mixed $currentUser=['id'=>77,'name'=>'Ada'];
		public int|string|null $currentId=77;
		public bool $checked=true;
		public bool $validateResult=true;
		public bool $recoverResult=true;
		public bool $loginResult=true;
		public bool $attemptResult=true;
		public bool $logoutResult=true;
		/** @var list<array<int,mixed>> */
		public array $calls=[];
		public function __construct(private string $guardName='spy',private string $type='session') {}
		public function name(): string { return $this->guardName; }
		public function authType(): string { return $this->type; }
		public function check(): bool { return $this->checked; }
		public function guest(): bool { return !$this->checked; }
		public function id(): int|string|null { return $this->currentId; }
		public function user(): mixed { return $this->currentUser; }
		public function context(): AuthContext { return AuthContext::capture($this->type,$this->guardName); }
		public function validate(bool $cache=true): bool { $this->calls[]=['validate',$cache]; return $this->validateResult; }
		public function recover(): bool { $this->calls[]=['recover']; return $this->recoverResult; }
		public function login(mixed $user,bool $remember=false): bool { $this->calls[]=['login',$user,$remember]; return $this->loginResult; }
		public function loginUsingId(int|string $identifier,bool $remember=false): bool { $this->calls[]=['loginUsingId',$identifier,$remember]; return $this->loginResult; }
		public function attempt(array $credentials,bool $remember=false): bool { $this->calls[]=['attempt',$credentials,$remember]; return $this->attemptResult; }
		public function logout(): bool { $this->calls[]=['logout']; return $this->logoutResult; }
		public function claims(): array { return ['sub'=>$this->currentId]; }
		public function token(): ?string { return 'spy-token'; }
	}

	final class BareGuard implements Guard {
		public function __construct(private string $guardName='bare') {}
		public function name(): string { return $this->guardName; }
		public function authType(): string { return 'session'; }
		public function check(): bool { return false; }
		public function guest(): bool { return true; }
		public function id(): int|string|null { return null; }
		public function user(): mixed { return null; }
		public function context(): AuthContext { return AuthContext::capture('session',$this->guardName); }
		public function validate(bool $cache=true): bool { return false; }
		public function recover(): bool { return false; }
		public function login(mixed $user,bool $remember=false): bool { return false; }
		public function loginUsingId(int|string $identifier,bool $remember=false): bool { return false; }
		public function attempt(array $credentials,bool $remember=false): bool { return false; }
		public function logout(): bool { return false; }
	}
}

namespace Dataphyre\Access\Guards {
	use Dataphyre\Access\Contracts\UserProvider;
	if(!class_exists(AccessGuard::class,false)){
		final class AccessGuard extends \DpAccessCoverage\SpyGuard {
			public function __construct(string $name,string $authType,?UserProvider $provider=null){ parent::__construct($name,strtolower(trim($authType))); }
		}
	}
	if(!class_exists(JwtGuard::class,false)){
		final class JwtGuard extends \DpAccessCoverage\SpyGuard {
			public function __construct(string $name,array $config=[],?UserProvider $provider=null){ parent::__construct($name,'jwt'); }
		}
	}
}

namespace {
	use Dataphyre\Access\AccessIdentityRepository;
	use Dataphyre\Access\Auth;
	use Dataphyre\Access\AuthManager;
	use Dataphyre\Access\Providers\CallbackUserProvider;
	use Dataphyre\Test\Context;
	use DpAccessCoverage\ArgProvider;
	use DpAccessCoverage\BareGuard;
	use DpAccessCoverage\InvalidProvider;
	use DpAccessCoverage\Provider;
	use DpAccessCoverage\SpyGuard;
	use function Dataphyre\Test\test;

	if(!defined('DP_ACCESS_CFG')){
		define('DP_ACCESS_CFG',[
			'default_auth_type'=>'session',
			'auth_types'=>['session','jwt','configured'],
			'framework'=>[
				'default_guard'=>'main',
				'guards'=>[
					'main'=>['driver'=>'spy','provider'=>'configured'],
					'plain'=>['driver'=>'plain'],
					'access_builtin'=>['driver'=>'access','auth_type'=>' ADMIN ','provider'=>'configured'],
					'session_builtin'=>['driver'=>'session','auth_type'=>'session'],
					'jwt_builtin'=>['driver'=>'jwt'],
					'bad_driver'=>['driver'=>'missing'],
					'invalid_config'=>'invalid',
				],
				'providers'=>[
					'configured'=>[
						'retrieve_by_id'=>'dp_access_provider_by_id',
						'retrieve_by_credentials'=>'dp_access_provider_by_credentials',
						'validate_credentials'=>'dp_access_provider_validate',
						'auth_identifier'=>'dp_access_provider_identifier',
					],
				],
			],
			'identity'=>[
				'users_table'=>'app.users',
				'id_column'=>'user_id',
				'email_column'=>'email_address',
				'name_column'=>'display_name',
				'password_hash_column'=>'password_hash',
				'password_column'=>'legacy_password',
				'created_at_column'=>'created_on',
				'email_verified_column'=>'verified',
				'email_verified_at_column'=>'verified_at',
				'callbacks'=>[
					'find_by_email'=>'dp_access_find_by_email',
					'find_by_id'=>'dp_access_find_by_id',
					'create'=>'dp_access_create_identity',
					'verify_password'=>'dp_access_verify_password',
					'set_password'=>'dp_access_set_password',
					'mark_email_verified'=>'dp_access_mark_verified',
					'identifier'=>'dp_access_identifier',
					'email_verified'=>'dp_access_email_verified',
				],
			],
		]);
	}

	require_once $dp_access_modules.'/access/Framework/Providers/CallbackUserProvider.php';
	require_once $dp_access_modules.'/access/Framework/AccessIdentityRepository.php';
	require_once $dp_access_modules.'/access/Framework/AuthManager.php';
	require_once $dp_access_modules.'/access/Framework/Auth.php';

	test('access framework deep coverage exercises identity callbacks SQL and value normalization',static function(Context $t): void {
		$identity=$t->state('access.framework.identity');
		$sql=$t->state('access.framework.sql',['select'=>null,'insert'=>true,'update'=>1,'calls'=>[]]);
		AccessIdentityRepository::flush();
		$repository=AccessIdentityRepository::instance();
		$t->same($repository,AccessIdentityRepository::instance());
		AccessIdentityRepository::flush();
		$repository=AccessIdentityRepository::instance();

		$t->same(null,$repository->findByEmail('invalid'));
		$identity->put('find_by_email',['user_id'=>1]);
		$t->same(1,$repository->findByEmail('  USER@EXAMPLE.COM ')['user_id']);
		$identity->put('find_by_email',null);
		$sql->put('select',['user_id'=>2,'email_address'=>'sql@example.com']);
		$t->same(2,$repository->findByEmail('sql@example.com')['user_id']);
		$sql->put('select',false);
		$t->same(null,$repository->findByEmail('none@example.com'));

		$identity->put('find_by_id',['user_id'=>3]);
		$t->same(3,$repository->findById(3)['user_id']);
		$identity->put('find_by_id',null);
		$sql->put('select',['user_id'=>4]);
		$t->same(4,$repository->findById('4')['user_id']);

		$identity->put('create',['user_id'=>5]);
		$t->same(5,$repository->create(['email'=>'five@example.com'])['user_id']);
		$identity->put('create',null);
		$sql->put('insert',false);
		$t->same(null,$repository->create(['email'=>'failed@example.com']));
		$sql->put('insert',true);
		$identity->put('find_by_email',static fn(string $email): array=>['user_id'=>6,'email_address'=>$email]);
		$created=$repository->create(['email'=>' SIX@EXAMPLE.COM ','name'=>' Six ','password'=>'secret']);
		$t->same('six@example.com',$created['email_address']);
		$insertCalls=array_values(array_filter($sql->get('calls'),static fn(array $call): bool=>$call[0]==='insert'));
		$fields=end($insertCalls)[1][1];
		$t->same('six@example.com',$fields['email_address']);
		$t->same('Six',$fields['display_name']);
		$t->isTrue(password_verify('secret',$fields['password_hash']));

		$user=['user_id'=>7,'password_hash'=>password_hash('secret',PASSWORD_DEFAULT),'verified'=>false,'verified_at'=>''];
		$identity->put('verify_password',true);
		$t->isTrue($repository->verifyPassword($user,'wrong'));
		$identity->put('verify_password',null);
		$t->isTrue($repository->verifyPassword($user,'secret'));
		$t->isFalse($repository->verifyPassword(['password_hash'=>''],'secret'));
		$identity->put('set_password',false);
		$t->isFalse($repository->setPassword($user,'next'));
		$identity->put('set_password',null);
		$t->isTrue($repository->setPassword($user,'next'));
		$sql->put('update',false);
		$t->isFalse($repository->setPassword($user,'next'));
		$sql->put('update',1);

		$identity->put('mark_email_verified',true);
		$t->isTrue($repository->markEmailVerified($user));
		$identity->put('mark_email_verified',null);
		$t->isTrue($repository->markEmailVerified($user));
		$sql->put('update',false);
		$t->isFalse($repository->markEmailVerified($user));
		$sql->put('update',1);

		$identity->put('identifier','callback-id');
		$t->same('callback-id',$repository->identifier($user));
		$identity->put('identifier',new \stdClass());
		$t->same(null,$repository->identifier($user));
		$identity->put('identifier',null);
		$t->same(7,$repository->identifier($user));
		$t->same('person@example.com',$repository->email(['email_address'=>'PERSON@EXAMPLE.COM']));
		$t->same(null,$repository->email(['email_address'=>'invalid']));
		$t->same('Ada',$repository->name(['display_name'=>' Ada ']));
		$t->same('',$repository->name(['display_name'=>[]]));

		$getters=new class {
			public function getUserId(): int { return 9; }
			public function getDisplayName(): string { return ' Getter '; }
		};
		$t->same(9,$repository->identifier($getters));
		$t->same('Getter',$repository->name($getters));
		$t->same(11,$repository->identifier((object)['user_id'=>11]));
		$t->same(null,$repository->identifier(new \stdClass()));

		$identity->put('email_verified',true);
		$t->isTrue($repository->emailVerified($user));
		$identity->put('email_verified',null);
		$t->isTrue($repository->emailVerified(['verified'=>'yes','verified_at'=>'']));
		$t->isTrue($repository->emailVerified(['verified'=>false,'verified_at'=>'2026-01-01']));
		$t->isFalse($repository->emailVerified(['verified'=>false,'verified_at'=>'']));
		$t->isTrue($repository->canRegister());

		$internals=$t->nonPublic($repository);
		$t->same('fallback',$internals->invoke('config','missing.path','fallback'));
		$t->same('app.users',$internals->invoke('usersTable'));
		$t->same(null,$internals->invoke('column','missing_column',null));
		$t->same(null,$internals->invoke('value',$user,null));
		$t->same(null,$internals->invoke('callback','missing_callback',[]));
	});

	test('access framework deep coverage resolves every manager provider and guard configuration',static function(Context $t): void {
		\dataphyre\access::reset();
		AuthManager::flush();
		$manager=AuthManager::instance();
		$t->same($manager,AuthManager::instance());
		$t->same('main',$manager->defaultGuard());
		$t->contains('main',$manager->guardNames());
		$t->isTrue($manager->hasGuard('main'));
		$t->throws(static fn()=>$manager->shouldUse('   '),\InvalidArgumentException::class);
		$manager->shouldUse(' plain ');
		$t->same('plain',$manager->defaultGuard());
		$manager->forgetGuardOverride();
		$t->same('main',$manager->defaultGuard());

		$t->same(null,$manager->provider(''));
		$t->same(null,$manager->provider('unknown'));
		$configured=$manager->provider('configured');
		$t->instanceOf(CallbackUserProvider::class,$configured);
		$t->same($configured,$manager->provider('configured'));

		$direct=new Provider();
		$manager->extendProvider('direct',$direct);
		$t->same($direct,$manager->provider('direct'));
		$manager->extendProvider('class',Provider::class);
		$t->instanceOf(Provider::class,$manager->provider('class'));
		$manager->extendProvider('bad-class',InvalidProvider::class);
		$t->throws(static fn()=>$manager->provider('bad-class'),\RuntimeException::class);
		$manager->extendProvider('instance',['instance'=>$direct]);
		$t->same($direct,$manager->provider('instance'));
		$manager->extendProvider('factory',['factory'=>static fn(): Provider=>new Provider()]);
		$t->instanceOf(Provider::class,$manager->provider('factory'));
		$manager->extendProvider('factory-fallback',['factory'=>static fn(): object=>new \stdClass(),'retrieve_by_id'=>'dp_access_provider_by_id']);
		$t->instanceOf(CallbackUserProvider::class,$manager->provider('factory-fallback'));
		$manager->extendProvider('arguments',['class'=>ArgProvider::class,'arguments'=>['prefix-']]);
		$t->same('prefix-',$manager->provider('arguments')->prefix);
		$manager->extendProvider('class-fallback',['class'=>InvalidProvider::class]);
		$t->instanceOf(CallbackUserProvider::class,$manager->provider('class-fallback'));
		$manager->extendProvider('callable',static fn(int|string $id): array=>['id'=>$id]);
		$t->instanceOf(CallbackUserProvider::class,$manager->provider('callable'));
		$manager->extendProvider('invalid',42);
		$t->throws(static fn()=>$manager->provider('invalid'),\RuntimeException::class);
		$t->throws(static fn()=>$manager->extendProvider(' ',[]),\InvalidArgumentException::class);

		$t->throws(static fn()=>$manager->extendGuard(' ',static fn()=>new BareGuard()),\InvalidArgumentException::class);
		$manager->extendGuard('spy',static fn(string $name,array $config,mixed $provider): SpyGuard=>new SpyGuard($name,'session'));
		$manager->extendGuard('plain',static fn(string $name): BareGuard=>new BareGuard($name));
		$main=$manager->guard();
		$t->instanceOf(SpyGuard::class,$main);
		$t->same($main,$manager->guard('main'));
		$t->instanceOf(BareGuard::class,$manager->guard('plain'));
		$t->instanceOf(\Dataphyre\Access\Guards\AccessGuard::class,$manager->guard('access_builtin'));
		$t->same('admin',$manager->guard('access_builtin')->authType());
		$t->instanceOf(\Dataphyre\Access\Guards\AccessGuard::class,$manager->guard('session_builtin'));
		$t->instanceOf(\Dataphyre\Access\Guards\JwtGuard::class,$manager->guard('jwt_builtin'));
		$t->throws(static fn()=>$manager->guard('bad_driver'),\RuntimeException::class);
		$t->throws(static fn()=>$manager->guard('invalid_config'),\RuntimeException::class);
		$t->throws(static fn()=>$manager->guard('missing'),\RuntimeException::class);
	});

	test('access framework deep coverage forwards facade state operations hooks and aliases',static function(Context $t): void {
		\dataphyre\access::reset();
		\dataphyre\core::reset();
		AuthManager::flush();
		$manager=Auth::manager();
		$manager->extendGuard('spy',static fn(string $name): SpyGuard=>new SpyGuard($name,'session'));
		$manager->extendGuard('plain',static fn(string $name): BareGuard=>new BareGuard($name));
		$t->same('main',Auth::defaultGuard());
		$t->contains('main',Auth::guards());
		$t->isTrue(Auth::hasGuard('main'));
		Auth::shouldUse('plain');
		$t->same('plain',Auth::defaultGuard());
		Auth::forgetGuardOverride();
		$t->same('main',Auth::defaultGuard());
		$guard=Auth::guard('main');
		$t->instanceOf(SpyGuard::class,$guard);
		$t->instanceOf(CallbackUserProvider::class,Auth::provider('configured'));
		Auth::extendProvider('facade-provider',new Provider());
		$t->instanceOf(Provider::class,Auth::provider('facade-provider'));
		Auth::extendGuard('facade-guard',static fn(string $name): SpyGuard=>new SpyGuard($name));

		$t->same('session',Auth::defaultType());
		$t->same('jwt',Auth::currentType());
		$t->contains('configured',Auth::enabledTypes());
		$t->same('main',Auth::context('main')->guardName());
		$t->isTrue(Auth::check('main'));
		$t->isFalse(Auth::guest('main'));
		$t->same(77,Auth::user('main')['id']);
		$t->same(['sub'=>77],Auth::claims('main'));
		$t->same('spy-token',Auth::token('main'));
		$t->same([],Auth::claims('plain'));
		$t->same(null,Auth::token('plain'));
		$t->same(77,Auth::id('main'));
		$t->isTrue(Auth::loggedIn('main'));
		$t->same(77,Auth::userId('main'));
		$t->isTrue(Auth::createSession(88,true,'main'));

		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN']=[false];
		$t->isFalse(Auth::login(['id'=>1],false,'main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN']=[null];
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGIN']=[false];
		$t->isFalse(Auth::login(['id'=>2],true,'main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN_USING_ID']=[true];
		$t->isTrue(Auth::loginUsingId(3,false,'main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGIN_USING_ID']=[null];
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGIN_USING_ID']=[false];
		$t->isFalse(Auth::loginUsingId('4',true,'main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_ATTEMPT']=[false];
		$t->isFalse(Auth::attempt(['email'=>'x@example.com'],false,'main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_ATTEMPT']=[null];
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_AFTER_ATTEMPT']=[true];
		$t->isTrue(Auth::attempt(['email'=>'x@example.com','password'=>'secret'],true,'main'));

		$t->isTrue(Auth::validate(false,'main'));
		$t->isTrue(Auth::recover('main'));
		$t->isTrue(Auth::disable('main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGOUT']=[false];
		$t->isFalse(Auth::logout('main'));
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_BEFORE_LOGOUT']=[null];
		\dataphyre\core::$responses['CALL_ACCESS_FRAMEWORK_AUTH_AFTER_LOGOUT']=[true];
		$t->isTrue(Auth::logout('main'));
		$t->isTrue(Auth::access(true,false,true,false));
		$t->same('github',Auth::oauth('github')->name);
		Auth::flush();
	});
}
