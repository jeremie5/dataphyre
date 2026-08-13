<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\OAuthClient\Manager;
use Dataphyre\Access\OAuthClient\Provider;
use Dataphyre\Test\Context;
use Dataphyre\Test\FakeHttp;
use Dataphyre\Test\Spy;
use Dataphyre\Test\TestState;

if(!class_exists('dataphyre\\core',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class core {
	public static function load_framework_module(string $module): bool { return true; }
	public static function dialback(string $name,array $payload=[]): mixed {
		$handler=\Dataphyre\Test\TestState::channel('access.oauth-provider')->get('dialback_handler');
		return is_callable($handler) ? $handler($name, $payload) : null;
	}
}
PHP);
}
if(!class_exists('Dataphyre\\Access\\Auth',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Access;
final class Auth {
	public static function login(mixed $user,bool $remember=true,?string $guard=null): bool {
		$handler=\Dataphyre\Test\TestState::channel('access.oauth-provider')->get('auth_login');
		return is_callable($handler) ? (bool)$handler($user, $remember, $guard) : true;
	}
}
PHP);
}

final class DpOAuthScenario {
	public const TOKEN_URL='https://oauth.example.test/token';
	public const USERINFO_URL='https://oauth.example.test/userinfo';
	public const REVOCATION_URL='https://oauth.example.test/revoke';
	public const JWKS_URL='https://oauth.example.test/jwks';

	private TestState $state;
	private FakeHttp $http;
	private Spy $dialbacks;
	private Spy $authLogin;

	public function __construct(Context $t) {
		$t->globalMap('_GET')->clear();
		$t->globalMap('_SESSION')->clear();
		$this->state=$t->state('access.oauth-provider', ['dialback_responses'=>[], 'auth_result'=>true]);
		$this->dialbacks=$t->spy(function(string $name, array $payload): mixed {
			$responses=$this->state->get('dialback_responses', []);
			return is_array($responses) ? ($responses[$name] ?? null) : null;
		});
		$this->authLogin=$t->spy(fn(): bool=>(bool)$this->state->get('auth_result', true));
		$this->state
			->put('dialback_handler', $this->dialbacks)
			->put('auth_login', $this->authLogin);

		$profile=[
			'sub'=>'user-1',
			'email'=>'user@example.test',
			'name'=>'Example User',
			'preferred_username'=>'example',
			'picture'=>'https://example.test/avatar.png',
			'email_verified'=>'true',
		];
		$this->http=$t->fakeHttp()
			->respondJson('POST', self::TOKEN_URL, [
				'access_token'=>'access-one',
				'refresh_token'=>'refresh-one',
				'token_type'=>'Bearer',
				'expires_in'=>'3600',
				'scope'=>'openid email profile',
			])
			->respondJson('GET', self::USERINFO_URL, $profile)
			->respondJson('GET', self::USERINFO_URL.'?token=query-token&format=full', $profile)
			->respondText('POST', self::REVOCATION_URL)
			->respondJson('GET', self::JWKS_URL, ['keys'=>[]]);
	}

	public function http(): FakeHttp { return $this->http; }
	public function authLogin(): Spy { return $this->authLogin; }
	public function dialbacks(): Spy { return $this->dialbacks; }

	public function whenDialbackReturns(string $name, mixed $response): self {
		$responses=$this->state->get('dialback_responses', []);
		$responses=is_array($responses) ? $responses : [];
		$responses[$name]=$response;
		$this->state->put('dialback_responses', $responses);
		return $this;
	}

	public function withoutDialback(string $name): self {
		$responses=$this->state->get('dialback_responses', []);
		if(is_array($responses)){
			unset($responses[$name]);
			$this->state->put('dialback_responses', $responses);
		}
		return $this;
	}

	public function authResult(bool $result): self {
		$this->state->put('auth_result', $result);
		return $this;
	}

	/** @param array<string,mixed> $overrides */
	public function provider(array $overrides=[]): Provider {
		return $this->namedProvider(' mock ', $overrides);
	}

	/** @param array<string,mixed> $overrides */
	public function namedProvider(string $name, array $overrides=[]): Provider {
		$config=array_replace_recursive([
			'authorization_url'=>'https://oauth.example.test/authorize',
			'token_url'=>self::TOKEN_URL,
			'userinfo_url'=>self::USERINFO_URL,
			'revocation_url'=>self::REVOCATION_URL,
			'client_id'=>'client-one',
			'client_secret'=>'secret-one',
			'redirect_uri'=>'https://app.example.test/oauth/callback',
			'scopes'=>['openid','email','profile'],
			'state'=>true,
			'pkce'=>true,
			'nonce'=>true,
			'verify_id_token'=>false,
			'http'=>['handler'=>$this->http->handler()],
			'identity'=>[
				'id'=>['missing','sub'],
				'email'=>'email',
				'name'=>['name','full_name'],
				'nickname'=>['preferred_username','login'],
				'avatar'=>['picture','avatar_url'],
				'email_verified'=>['email_verified','verified_email'],
			],
		],$overrides);
		return $this->configuredProvider($name, $config);
	}

	/** @param array<string,mixed> $config */
	public function configuredProvider(string $name, array $config): Provider {
		$http=is_array($config['http'] ?? null) ? $config['http'] : [];
		$config['http']=array_replace($http, ['handler'=>$this->http->handler()]);
		return new Provider($name, $config, Manager::instance());
	}
}
