<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	final class PanelAuthPageStub {
		public string $label='';
		public string $icon='';
		public bool $hidden=false;
		public mixed $content_callback=null;

		public function __construct(public string $name) {}
		public function label(string $label): self { $this->label=$label; return $this; }
		public function icon(string $icon): self { $this->icon=$icon; return $this; }
		public function hideFromNavigation(): self { $this->hidden=true; return $this; }
		public function content(callable $callback): self { $this->content_callback=$callback; return $this; }
	}

	if(!class_exists(PanelInstance::class,false)){
		class PanelInstance {
			/** @var array<string,PanelAuthPageStub> */
			public array $pages=[];
			/** @var array<string,mixed> */
			public array $configuration=[];
			public mixed $authorization=null;

			public function page(string $name): PanelAuthPageStub { return new PanelAuthPageStub($name); }
			public function registerPage(PanelAuthPageStub $page): self { $this->pages[$page->name]=$page; return $this; }
			public function authorize(callable $callback): self { $this->authorization=$callback; return $this; }
			public function config(string $key,mixed $value): self { $this->configuration[$key]=$value; return $this; }
		}
	}

	if(!class_exists(PanelRequest::class,false)){
		class PanelRequest {
			/** @param array<string,mixed> $input @param array<string,mixed> $query */
			public function __construct(
				private string $request_method='GET',
				private array $input_values=[],
				private array $query_values=[],
				private ?string $resource_name=null,
			) {}
			public function method(): string { return strtoupper($this->request_method); }
			public function input(string $key,mixed $default=null): mixed { return $this->input_values[$key] ?? $default; }
			public function query(string $key,mixed $default=null): mixed { return $this->query_values[$key] ?? $default; }
			public function resourceName(): ?string { return $this->resource_name; }
		}
	}

	if(!class_exists(PanelPageResult::class,false)){
		class PanelPageResult {
			public function __construct(public string $redirect_url) {}
			public static function redirect(string $url): self { return new self($url); }
		}
	}

	if(!class_exists(PanelConfig::class,false)){
		class PanelConfig {
			/** @param array<string,mixed> $query */
			public static function url(string $page='',array $query=[]): string {
				$url='/panel'.($page!=='' ? '/'.trim($page,'/') : '');
				return $query!==[] ? $url.'?'.http_build_query($query) : $url;
			}
		}
	}
}

namespace Dataphyre\Access {
	final class PanelAuthRepositoryStub {
		/** @var array<string,mixed> */
		public array $by_email=[];
		public bool $can_register=true;
		public mixed $create_result=null;
		/** @var list<array<string,mixed>> */
		public array $created=[];

		public function findByEmail(string $email): mixed { return $this->by_email[$email] ?? null; }
		public function canRegister(): bool { return $this->can_register; }
		/** @param array<string,mixed> $attributes */
		public function create(array $attributes): mixed { $this->created[]=$attributes; return $this->create_result; }
	}

	final class PanelAuthTokensStub {
		public mixed $create_result=['token'=>'generated-token'];
		/** @var array<string,mixed> */
		public array $consumed=[];
		/** @var list<array<string,mixed>> */
		public array $created=[];

		public function create(string $purpose,mixed $user_id,?string $email,array $metadata,int $ttl): mixed {
			$this->created[]=compact('purpose','user_id','email','metadata','ttl');
			return $this->create_result;
		}
		public function consume(string $purpose,string $token): mixed { return $this->consumed[$purpose.':'.$token] ?? null; }
	}

	if(!class_exists(Auth::class,false)){
		class Auth {
			public static bool $checked=false;
			public static mixed $current_user=null;
			public static mixed $current_id=null;
			public static bool $attempt_result=false;
			public static bool $login_result=true;
			public static int $logout_count=0;
			/** @var list<array<string,mixed>> */
			public static array $attempts=[];
			/** @var list<array{user:mixed,remember:bool}> */
			public static array $logins=[];

			public static function reset(): void {
				self::$checked=false; self::$current_user=null; self::$current_id=null;
				self::$attempt_result=false; self::$login_result=true; self::$logout_count=0;
				self::$attempts=[]; self::$logins=[];
			}
			public static function check(): bool { return self::$checked; }
			/** @param array<string,mixed> $credentials */
			public static function attempt(array $credentials,bool $remember=false): bool { self::$attempts[]=$credentials+['remember'=>$remember]; return self::$attempt_result; }
			public static function user(): mixed { return self::$current_user; }
			public static function id(): mixed { return self::$current_id; }
			public static function logout(): void { self::$logout_count++; self::$checked=false; self::$current_user=null; }
			public static function login(mixed $user,bool $remember=false): bool { self::$logins[]=['user'=>$user,'remember'=>$remember]; if(self::$login_result){ self::$checked=true; self::$current_user=$user; } return self::$login_result; }
		}
	}

	if(!class_exists(AccessIdentity::class,false)){
		class AccessIdentity {
			public static PanelAuthRepositoryStub $repository;
			public static PanelAuthTokensStub $tokens;
			/** @var array<string,mixed> */
			public static array $by_email=[];
			/** @var array<int,mixed> */
			public static array $by_id=[];
			public static ?bool $verify_result=null;
			public static bool $mark_result=true;
			public static bool $set_password_result=true;

			public static function reset(): void {
				self::$repository=new PanelAuthRepositoryStub(); self::$tokens=new PanelAuthTokensStub();
				self::$by_email=[]; self::$by_id=[]; self::$verify_result=null; self::$mark_result=true; self::$set_password_result=true;
			}
			public static function repository(): PanelAuthRepositoryStub { return self::$repository; }
			public static function tokens(): PanelAuthTokensStub { return self::$tokens; }
			public static function findByEmail(string $email): mixed { return self::$by_email[$email] ?? null; }
			public static function findById(int $id): mixed { return self::$by_id[$id] ?? null; }
			public static function email(mixed $user): ?string { $email=is_object($user) ? ($user->email ?? null) : (is_array($user) ? ($user['email'] ?? null) : null); return is_string($email) && $email!=='' ? $email : null; }
			public static function identifier(mixed $user): mixed { return is_object($user) ? ($user->id ?? null) : (is_array($user) ? ($user['id'] ?? null) : null); }
			public static function emailVerified(mixed $user): bool { return (bool)(is_object($user) ? ($user->verified ?? false) : (is_array($user) ? ($user['verified'] ?? false) : false)); }
			public static function markEmailVerified(mixed $user): bool { if(self::$mark_result && is_object($user)){ $user->verified=true; } return self::$mark_result; }
			public static function verifyPassword(mixed $user,string $password): bool { return self::$verify_result ?? (is_object($user) && isset($user->password) && password_verify($password,(string)$user->password)); }
			public static function setPassword(mixed $user,string $password): bool { if(self::$set_password_result && is_object($user)){ $user->password=password_hash($password,PASSWORD_DEFAULT); } return self::$set_password_result; }
		}
	}
}

namespace dataphyre {
	if(!class_exists(core::class,false)){
		class core {
			public static string $csrf_token='panel-csrf';
			public static bool $csrf_accept=true;
			public static bool $load_mailer=true;
			/** @var list<array<int,mixed>> */
			public static array $csrf_calls=[];
			public static function reset(): void { self::$csrf_token='panel-csrf'; self::$csrf_accept=true; self::$load_mailer=true; self::$csrf_calls=[]; }
			public static function csrf(string $scope,mixed ...$arguments): string|bool {
				self::$csrf_calls[]=[$scope,...$arguments];
				return $arguments===[] ? self::$csrf_token : self::$csrf_accept && hash_equals(self::$csrf_token,(string)$arguments[0]);
			}
			public static function load_framework_module(string $module): bool { return $module==='mailer' && self::$load_mailer; }
		}
	}
}

namespace Dataphyre\Mailer {
	final class PanelAuthMailerResultStub {
		public function __construct(private bool $successful) {}
		public function ok(): bool { return $this->successful; }
	}
	if(!class_exists(Mailer::class,false)){
		class Mailer {
			public static bool $ok=true;
			/** @var list<array<string,mixed>> */
			public static array $queued=[];
			/** @var list<array<string,mixed>> */
			public static array $sent=[];
			public static function reset(): void { self::$ok=true; self::$queued=[]; self::$sent=[]; }
			/** @param array<string,mixed> $message */
			public static function queue(array $message): PanelAuthMailerResultStub { self::$queued[]=$message; return new PanelAuthMailerResultStub(self::$ok); }
			/** @param array<string,mixed> $message */
			public static function send(array $message): PanelAuthMailerResultStub { self::$sent[]=$message; return new PanelAuthMailerResultStub(self::$ok); }
		}
	}
}

namespace {
	require_once \Dataphyre\Test\dataphyre_path().'/runtime/modules/access/Framework/PanelAuth.php';

	use Dataphyre\Access\AccessIdentity;
	use Dataphyre\Access\Auth;
	use Dataphyre\Access\PanelAuth;
	use Dataphyre\Mailer\Mailer;
	use Dataphyre\Panel\PanelConfig;
	use Dataphyre\Panel\PanelInstance;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;


	function dp_panel_auth_user(int $id=1,string $email='user@example.test',string $password='current-password',bool $verified=false): object {
		return (object)['id'=>$id,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'verified'=>$verified];
	}

	function dp_panel_auth_reset(): void {
		Auth::reset();
		AccessIdentity::reset();
		\dataphyre\core::reset();
		Mailer::reset();
	}

	function dp_panel_auth_request(string $method='GET',array $input=[],array $query=[],?string $resource=null): PanelRequest {
		return new PanelRequest($method,$input,$query,$resource);
	}

	test('access panel auth registers pages protection defaults and utility URL rendering paths',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$server=$t->globalMap('_SERVER')->clear();
		if(!defined('DP_ACCESS_CFG')){
			define('DP_ACCESS_CFG',['panel_auth'=>['login_page'=>'Member Login','after_login'=>'/configured','queue_mail'=>false]]);
		}
		$panel=new PanelInstance();
		$result=PanelAuth::register($panel,['register_page'=>'Join Now']);
		$t->same($panel,$result);
		$t->count(6,$panel->pages);
		$t->hasKey('member_login',$panel->pages);
		$t->hasKey('join_now',$panel->pages);
		$t->isTrue($panel->pages['member_login']->hidden);
		$t->same('Sign in',$panel->pages['member_login']->label);
		$t->same('log-in',$panel->pages['member_login']->icon);
		$t->same('/configured',$panel->configuration['access_auth']['after_login']);
		$t->isTrue(is_callable($panel->authorization));
		$authorization=$panel->authorization;
		$t->isTrue($authorization('view',null,null,dp_panel_auth_request('GET',[],[],'member_login')));
		Auth::$checked=false;
		$t->isFalse($authorization('view',null,null,dp_panel_auth_request('GET',[],[],'dashboard')));
		Auth::$checked=true;
		$t->isTrue($authorization('view',null,null,dp_panel_auth_request('GET',[],[],'dashboard')));
		Auth::$checked=false;
		$pagePayload=($panel->pages['member_login']->content_callback)(dp_panel_auth_request());
		$t->same('Sign in',$pagePayload['title']);

		$unprotected=new PanelInstance();
		PanelAuth::register($unprotected,['protect'=>false,'login_page'=>'***']);
		$t->same(null,$unprotected->authorization);
		$t->hasKey('login',$unprotected->pages);
		$t->same(['login_page'=>'Member Login','after_login'=>'/configured','queue_mail'=>false],$panelAuth->invoke('config'));

		$t->same('/panel/example?a=1',$panelAuth->invoke('url','example',['a'=>1]));
		$t->isTrue($panelAuth->invoke('safeRelative',' /safe '));
		$t->isFalse($panelAuth->invoke('safeRelative','https://evil.test'));
		$t->isFalse($panelAuth->invoke('safeRelative','//evil.test'));
		$t->isFalse($panelAuth->invoke('safeRelative','relative'));
		$t->same('/requested',$panelAuth->invoke('afterLoginUrl',dp_panel_auth_request('POST',['return'=>'/requested']),[]));
		$t->same('/configured',$panelAuth->invoke('afterLoginUrl',dp_panel_auth_request(),['after_login'=>'/configured']));
		$t->same('/panel',$panelAuth->invoke('afterLoginUrl',dp_panel_auth_request(),['after_login'=>'https://evil.test']));
		$t->same('/bye',$panelAuth->invoke('afterLogoutUrl',['after_logout'=>'/bye']));
		$t->same('/panel/member_login',$panelAuth->invoke('afterLogoutUrl',['login_page'=>'Member Login','after_logout'=>'bad']));

		$server->put('REQUEST_URI','/panel/change?x=1');
		$t->same('/panel/change?x=1',$panelAuth->invoke('currentRelativeUrl'));
		$server->put('REQUEST_URI','https://evil.test');
		$t->same('/',$panelAuth->invoke('currentRelativeUrl'));
		$t->same('https://already.test/x',$panelAuth->invoke('absoluteUrl','https://already.test/x'));
		$server->replace(['HTTPS'=>'on','HTTP_HOST'=>'panel.test']);
		$t->same('https://panel.test/path',$panelAuth->invoke('absoluteUrl','/path'));
		$server->replace(['REQUEST_SCHEME'=>'https','SERVER_NAME'=>'fallback.test']);
		$t->same('https://fallback.test/path',$panelAuth->invoke('absoluteUrl','path'));
		$server->replace([]);
		$t->same('http://localhost/path',$panelAuth->invoke('absoluteUrl','path'));

		$t->contains('&lt;Title&gt;',$panelAuth->invoke('authShell','<Title>','sub','error','<b>body</b>'));
		$t->notContains('notice-danger',$panelAuth->invoke('authShell','title','sub','','body'));
		$t->contains('required',$panelAuth->invoke('input','field','Label','text','<&>',true));
		$t->notContains('required',$panelAuth->invoke('input','field','Label','text','',false));
		$t->same('',$panelAuth->invoke('authLinks',[]));
		$t->contains('dp-panel-auth-links',$panelAuth->invoke('authLinks',["<Label>"=>'/path?a=1&b=2']));
		$t->notContains('toolbar',$panelAuth->invoke('messagePage','Title','Message')['content']);
		$t->contains('Continue safely',$panelAuth->invoke('messagePage','Title','Message','/next','Continue safely')['content']);
		$t->contains('panel-csrf',$panelAuth->invoke('csrfInput'));
		$t->isTrue($panelAuth->invoke('validCsrf',dp_panel_auth_request('POST',['csrf'=>'panel-csrf'])));
		$t->isFalse($panelAuth->invoke('validCsrf',dp_panel_auth_request('POST',['csrf'=>'wrong'])));
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth login and logout cover authenticated csrf credential verification and form paths',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$options=['login_page'=>'login','register_page'=>'register','verify_page'=>'verify','password_reset_page'=>'reset','after_login'=>'/home','after_logout'=>'/signed-out'];
		Auth::$checked=true;
		$redirect=$panelAuth->invoke('login',dp_panel_auth_request(),$options);
		$t->instanceOf(PanelPageResult::class,$redirect);
		$t->same('/home',$redirect->redirect_url);

		Auth::$checked=false;
		$get=$panelAuth->invoke('login',dp_panel_auth_request(),$options+['require_email_verification'=>true]);
		$t->contains('Verify email',$get['content']);
		$t->contains('name="email"',$get['content']);
		$invalidCsrf=$panelAuth->invoke('login',dp_panel_auth_request('POST',['email'=>'A@Example.Test','csrf'=>'bad']),$options);
		$t->contains('form expired',$invalidCsrf['content']);

		$badCredentials=$panelAuth->invoke('login',dp_panel_auth_request('POST',['email'=>'A@Example.Test','password'=>'bad','csrf'=>'panel-csrf']),$options);
		$t->contains('did not match',$badCredentials['content']);
		$t->same('a@example.test',Auth::$attempts[0]['email']);

		$user=dp_panel_auth_user(5,'verified@example.test','secret-pass',true);
		Auth::$attempt_result=true;
		Auth::$current_user=$user;
		$success=$panelAuth->invoke('login',dp_panel_auth_request('POST',['email'=>'verified@example.test','password'=>'secret-pass','remember'=>'1','csrf'=>'panel-csrf']),$options);
		$t->same('/home',$success->redirect_url);
		$t->isTrue(Auth::$attempts[array_key_last(Auth::$attempts)]['remember']);

		$unverified=dp_panel_auth_user(6,'new@example.test','secret-pass',false);
		Auth::$current_user=$unverified;
		AccessIdentity::$tokens->create_result=null;
		$verifyRequired=$panelAuth->invoke('login',dp_panel_auth_request('POST',['email'=>'new@example.test','password'=>'secret-pass','csrf'=>'panel-csrf']),$options+['require_email_verification'=>true]);
		$t->contains('Verify your email',$verifyRequired['content']);
		$t->same(1,Auth::$logout_count);

		dp_panel_auth_reset();
		$repositoryUser=dp_panel_auth_user(7,'repo@example.test','repo-password',true);
		AccessIdentity::$by_email['repo@example.test']=$repositoryUser;
		AccessIdentity::$verify_result=true;
		$repositoryLogin=$panelAuth->invoke('login',dp_panel_auth_request('POST',['email'=>'repo@example.test','password'=>'repo-password','remember'=>'1','csrf'=>'panel-csrf'],['return'=>'/return']),$options);
		$t->same('/return',$repositoryLogin->redirect_url);
		$t->count(1,Auth::$logins);

		$form=$panelAuth->invoke('logout',dp_panel_auth_request(),$options);
		$t->same('Sign out',$form['title']);
		$t->contains('panel-csrf',$form['content']);
		$logout=$panelAuth->invoke('logout',dp_panel_auth_request('POST'),$options);
		$t->same('/signed-out',$logout->redirect_url);
		$confirm=$panelAuth->invoke('logout',dp_panel_auth_request('GET',[],['confirm'=>'1']),$options);
		$t->same('/signed-out',$confirm->redirect_url);
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth registration covers disabled validation repository creation verification and login outcomes',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$options=['login_page'=>'login','after_login'=>'/welcome'];
		$disabled=$panelAuth->invoke('registerPage',dp_panel_auth_request(),$options+['allow_registration'=>false]);
		$t->same('Registration unavailable',$disabled['title']);
		$get=$panelAuth->invoke('registerPage',dp_panel_auth_request(),$options);
		$t->contains('Create account',$get['content']);
		$csrf=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['csrf'=>'bad']),$options);
		$t->contains('form expired',$csrf['content']);
		$invalidEmail=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['email'=>'bad','password'=>'long-password','csrf'=>'panel-csrf']),$options);
		$t->contains('valid email',$invalidEmail['content']);
		$shortPassword=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['email'=>'one@example.test','password'=>'short','csrf'=>'panel-csrf']),$options);
		$t->contains('at least 8',$shortPassword['content']);

		$existing=dp_panel_auth_user(2,'one@example.test');
		AccessIdentity::$repository->by_email['one@example.test']=$existing;
		$duplicate=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['email'=>'one@example.test','password'=>'long-password','csrf'=>'panel-csrf']),$options);
		$t->contains('already exists',$duplicate['content']);
		AccessIdentity::$repository->by_email=[];
		AccessIdentity::$repository->can_register=false;
		$unsupported=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['email'=>'one@example.test','password'=>'long-password','csrf'=>'panel-csrf']),$options);
		$t->contains('No identity repository',$unsupported['content']);
		AccessIdentity::$repository->can_register=true;
		AccessIdentity::$repository->create_result=null;
		$failed=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['name'=>'Name','email'=>'one@example.test','password'=>'long-password','csrf'=>'panel-csrf']),$options);
		$t->contains('could not be created',$failed['content']);

		$created=dp_panel_auth_user(3,'created@example.test');
		AccessIdentity::$repository->create_result=$created;
		AccessIdentity::$tokens->create_result=['token'=>'verify-token'];
		$verify=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['name'=>'Created','email'=>'created@example.test','password'=>'long-password','csrf'=>'panel-csrf']),$options+['require_email_verification'=>true,'verification_ttl'=>99]);
		$t->contains('Account created',$verify['content']);
		$t->same(99,AccessIdentity::$tokens->created[0]['ttl']);

		AccessIdentity::$repository->create_result=dp_panel_auth_user(4,'login@example.test');
		$loggedIn=$panelAuth->invoke('registerPage',dp_panel_auth_request('POST',['name'=>'Login','email'=>'login@example.test','password'=>'long-password','csrf'=>'panel-csrf']),$options);
		$t->same('/welcome',$loggedIn->redirect_url);
		$t->count(1,Auth::$logins);
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth email verification covers token success expiry csrf resend and current-user forms',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$options=['login_page'=>'login','verify_page'=>'verify'];
		$user=dp_panel_auth_user(8,'verify@example.test');
		AccessIdentity::$by_id[8]=$user;
		AccessIdentity::$tokens->consumed['email_verification:good']=['user_id'=>8,'email'=>'verify@example.test'];
		$verified=$panelAuth->invoke('verifyEmail',dp_panel_auth_request('GET',[],['token'=>'good']),$options);
		$t->same('Email verified',$verified['title']);
		$t->isTrue($user->verified);

		AccessIdentity::$tokens->consumed['email_verification:email-row']=['email'=>'missing@example.test'];
		$expired=$panelAuth->invoke('verifyEmail',dp_panel_auth_request('GET',[],['token'=>'email-row']),$options);
		$t->same('Verification link expired',$expired['title']);
		$t->same('Verification link expired',$panelAuth->invoke('verifyEmail',dp_panel_auth_request('GET',[],['token'=>'unknown']),$options)['title']);

		$invalid=$panelAuth->invoke('verifyEmail',dp_panel_auth_request('POST',['email'=>'verify@example.test','csrf'=>'bad']),$options);
		$t->contains('form expired',$invalid['content']);
		AccessIdentity::$by_email['verify@example.test']=$user;
		AccessIdentity::$tokens->create_result=['token'=>'resent'];
		$resent=$panelAuth->invoke('verifyEmail',dp_panel_auth_request('POST',['email'=>' VERIFY@example.test ','csrf'=>'panel-csrf']),$options);
		$t->contains('If the account exists',$resent['content']);
		$t->same('email_verification',AccessIdentity::$tokens->created[0]['purpose']);

		Auth::$current_user=$user;
		$current=$panelAuth->invoke('verifyEmail',dp_panel_auth_request(),$options);
		$t->contains('value="verify@example.test"',$current['content']);
		Auth::$current_user=null;
		$anonymous=$panelAuth->invoke('verifyEmail',dp_panel_auth_request('GET',['email'=>'anonymous@example.test']),$options);
		$t->contains('anonymous@example.test',$anonymous['content']);
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth password reset covers token form validation update expiry and request mail paths',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$options=['login_page'=>'login','password_reset_page'=>'reset'];
		$form=$panelAuth->invoke('passwordReset',dp_panel_auth_request('GET',[],['token'=>'abc']),$options);
		$t->same('Set new password',$form['title']);
		$t->contains('value="abc"',$form['content']);
		$csrf=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['token'=>'abc','csrf'=>'bad']),$options);
		$t->contains('form expired',$csrf['content']);
		$short=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['token'=>'abc','password'=>'short','csrf'=>'panel-csrf']),$options);
		$t->contains('at least 8',$short['content']);

		$user=dp_panel_auth_user(9,'reset@example.test');
		AccessIdentity::$by_id[9]=$user;
		AccessIdentity::$tokens->consumed['password_reset:good']=['user_id'=>9];
		$updated=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['token'=>'good','password'=>'new-password','csrf'=>'panel-csrf']),$options);
		$t->same('Password updated',$updated['title']);
		$t->isTrue(password_verify('new-password',$user->password));

		AccessIdentity::$tokens->consumed['password_reset:email-row']=['email'=>'missing@example.test'];
		$expired=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['token'=>'email-row','password'=>'new-password','csrf'=>'panel-csrf']),$options);
		$t->contains('invalid or expired',$expired['content']);
		$t->contains('invalid or expired',$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['token'=>'unknown','password'=>'new-password','csrf'=>'panel-csrf']),$options)['content']);

		$invalidRequest=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['email'=>'reset@example.test','csrf'=>'bad']),$options);
		$t->contains('form expired',$invalidRequest['content']);
		AccessIdentity::$by_email['reset@example.test']=$user;
		AccessIdentity::$tokens->create_result=['token'=>'reset-token'];
		$request=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['email'=>' RESET@example.test ','csrf'=>'panel-csrf']),$options+['password_reset_ttl'=>77]);
		$t->contains('If the account exists',$request['content']);
		$t->same(77,AccessIdentity::$tokens->created[0]['ttl']);
		AccessIdentity::$by_email=[];
		$nonexistent=$panelAuth->invoke('passwordReset',dp_panel_auth_request('POST',['email'=>'missing@example.test','csrf'=>'panel-csrf']),$options);
		$t->contains('If the account exists',$nonexistent['content']);
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth password change and repository login cover anonymous validation success and failure paths',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$server=$t->globalMap('_SERVER')->clear();
		$options=['login_page'=>'login'];
		$server->put('REQUEST_URI','/panel/password-change');
		$anonymous=$panelAuth->invoke('passwordChange',dp_panel_auth_request(),$options);
		$t->same('/panel/login?return=%2Fpanel%2Fpassword-change',$anonymous->redirect_url);

		$user=dp_panel_auth_user(10,'change@example.test','old-password',true);
		Auth::$checked=true;
		Auth::$current_user=$user;
		$get=$panelAuth->invoke('passwordChange',dp_panel_auth_request(),$options);
		$t->same('Change password',$get['title']);
		$csrf=$panelAuth->invoke('passwordChange',dp_panel_auth_request('POST',['csrf'=>'bad']),$options);
		$t->contains('form expired',$csrf['content']);
		$wrong=$panelAuth->invoke('passwordChange',dp_panel_auth_request('POST',['current_password'=>'wrong','password'=>'new-password','csrf'=>'panel-csrf']),$options);
		$t->contains('current password is incorrect',$wrong['content']);
		AccessIdentity::$verify_result=true;
		$short=$panelAuth->invoke('passwordChange',dp_panel_auth_request('POST',['current_password'=>'old-password','password'=>'short','csrf'=>'panel-csrf']),$options);
		$t->contains('at least 8',$short['content']);
		$changed=$panelAuth->invoke('passwordChange',dp_panel_auth_request('POST',['current_password'=>'old-password','password'=>'new-password','csrf'=>'panel-csrf']),$options);
		$t->contains('Password changed',$changed['content']);
		AccessIdentity::$set_password_result=false;
		$failed=$panelAuth->invoke('passwordChange',dp_panel_auth_request('POST',['current_password'=>'old-password','password'=>'another-password','csrf'=>'panel-csrf']),$options);
		$t->contains('could not be changed',$failed['content']);

		Auth::$current_user=null;
		Auth::$current_id=10;
		AccessIdentity::$by_id[10]=$user;
		$idFallback=$panelAuth->invoke('passwordChange',dp_panel_auth_request(),$options);
		$t->same('Change password',$idFallback['title']);

		AccessIdentity::$by_email=[];
		$t->isFalse($panelAuth->invoke('attemptRepositoryLogin','none@example.test','password',false));
		AccessIdentity::$by_email['change@example.test']=$user;
		AccessIdentity::$verify_result=false;
		$t->isFalse($panelAuth->invoke('attemptRepositoryLogin','change@example.test','wrong',false));
		AccessIdentity::$verify_result=true;
		Auth::$login_result=true;
		$t->isTrue($panelAuth->invoke('attemptRepositoryLogin','change@example.test','right',true));
	})->tag('access','panel-auth','coverage')->group('framework-coverage');

	test('access panel auth token and mail helpers cover missing identity token module queue send and result paths',static function(Context $t): void {
		dp_panel_auth_reset();
		$panelAuth=$t->nonPublic(PanelAuth::class);
		$server=$t->globalMap('_SERVER')->clear();
		$options=['verify_page'=>'verify','password_reset_page'=>'reset','verification_ttl'=>12,'password_reset_ttl'=>34];
		$noEmail=(object)['id'=>1];
		$t->isFalse($panelAuth->invoke('sendVerification',$noEmail,$options));
		$t->isFalse($panelAuth->invoke('sendPasswordReset',$noEmail,$options));

		$user=dp_panel_auth_user(11,'mail@example.test');
		AccessIdentity::$tokens->create_result=null;
		$t->isFalse($panelAuth->invoke('sendVerification',$user,$options));
		$t->isFalse($panelAuth->invoke('sendPasswordReset',$user,$options));

		\dataphyre\core::$load_mailer=false;
		$t->isFalse($panelAuth->invoke('sendAuthMail','mail@example.test','Subject','Text','<p>Html</p>',[]));
		\dataphyre\core::$load_mailer=true;
		Mailer::$ok=true;
		$t->isTrue($panelAuth->invoke('sendAuthMail','mail@example.test','Queued','Text','<p>Html</p>',['queue_mail'=>true]));
		$t->same('mail@example.test',Mailer::$queued[0]['to']);
		$t->same(['panel_auth'],Mailer::$queued[0]['tags']);
		Mailer::$ok=false;
		$t->isFalse($panelAuth->invoke('sendAuthMail','mail@example.test','Sent','Text','<p>Html</p>',['queue_mail'=>false]));
		$t->same('Sent',Mailer::$sent[0]['subject']);

		Mailer::$ok=true;
		AccessIdentity::$tokens->create_result=['token'=>'verify-link-token'];
		$server->replace(['HTTP_HOST'=>'auth.test']);
		$t->isTrue($panelAuth->invoke('sendVerification',$user,$options));
		$t->contains('http://auth.test/panel/verify?token=verify-link-token',Mailer::$queued[array_key_last(Mailer::$queued)]['text']);
		AccessIdentity::$tokens->create_result=['token'=>'reset-link-token'];
		$t->isTrue($panelAuth->invoke('sendPasswordReset',$user,$options));
		$t->contains('reset-link-token',Mailer::$queued[array_key_last(Mailer::$queued)]['html']);
	})->tag('access','panel-auth','coverage')->group('framework-coverage');
}
