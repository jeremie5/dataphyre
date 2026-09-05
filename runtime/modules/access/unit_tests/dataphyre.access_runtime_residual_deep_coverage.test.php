<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace {
	$dpAccessResidualModules=\Dataphyre\Test\dataphyre_path().'/runtime/modules';
	if(!defined('DP_ACCESS_CFG')){
		define('DP_ACCESS_CFG',[
			'identity'=>['tokens_table'=>'access.test_tokens'],
			'framework'=>['oauth'=>[
				'timeout'=>9,
				'nested'=>['default'=>'kept'],
				'providers'=>[
					'configured'=>['client_id'=>'configured-client','nested'=>['provider'=>'set']],
					'non-array'=>'invalid-config',
				],
			]],
		]);
	}

	require_once $dpAccessResidualModules.'/access/Framework/Contracts/Authenticatable.php';
	require_once $dpAccessResidualModules.'/access/Framework/Contracts/UserProvider.php';
	require_once $dpAccessResidualModules.'/access/Framework/Exceptions/AuthenticationException.php';
	require_once $dpAccessResidualModules.'/access/Framework/Exceptions/OAuthException.php';
	require_once $dpAccessResidualModules.'/access/Framework/Jwt/JwtPayload.php';
	require_once $dpAccessResidualModules.'/access/Framework/Jwt/JwtCodec.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/OAuthUser.php';
	require_once $dpAccessResidualModules.'/access/Framework/Providers/CallbackUserProvider.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/HttpClient.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/OpenIdDiscovery.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/StateStore.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/Manager.php';
	require_once $dpAccessResidualModules.'/access/Framework/OAuthClient/Provider.php';
	require_once $dpAccessResidualModules.'/access/Framework/AccessTokenBroker.php';

	function dp_access_residual_sql_state(): \Dataphyre\Test\TestState {
		return \Dataphyre\Test\TestState::channel('access.residual.sql');
	}
	if(!function_exists('dpvks')){
		function dpvks(): array { return ['access-residual-signing-key']; }
	}
	if(!function_exists('dpvk')){
		function dpvk(): string { return dpvks()[0]; }
	}

	if(!function_exists('sql_select')){
		function sql_select(mixed ...$arguments): mixed {
			$state=dp_access_residual_sql_state();
			$state->append('calls',['select',$arguments]);
			return $state->get('select');
		}
	}
	if(!function_exists('sql_insert')){
		function sql_insert(mixed ...$arguments): mixed {
			$state=dp_access_residual_sql_state();
			$state->append('calls',['insert',$arguments]);
			return $state->get('insert');
		}
	}
	if(!function_exists('sql_update')){
		function sql_update(mixed ...$arguments): mixed {
			$state=dp_access_residual_sql_state();
			$state->append('calls',['update',$arguments]);
			return $state->get('update');
		}
	}

	function dp_access_residual_b64(string $value): string {
		return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
	}

	/** @param array<string,mixed> $headers @param array<string,mixed> $claims */
	function dp_access_residual_jwt(array $headers,array $claims,string $signature): string {
		return dp_access_residual_b64((string)json_encode($headers,JSON_THROW_ON_ERROR)).'.'
			.dp_access_residual_b64((string)json_encode($claims,JSON_THROW_ON_ERROR)).'.'
			.dp_access_residual_b64($signature);
	}

	/** @param array<string,mixed> $claims */
	function dp_access_residual_rsa_jwt(array $claims,string $algorithm,string $privateKey,int $opensslAlgorithm): string {
		$header=dp_access_residual_b64((string)json_encode(['alg'=>$algorithm,'typ'=>'JWT'],JSON_THROW_ON_ERROR));
		$payload=dp_access_residual_b64((string)json_encode($claims,JSON_THROW_ON_ERROR));
		$signature='';
		openssl_sign($header.'.'.$payload,$signature,$privateKey,$opensslAlgorithm);
		return $header.'.'.$payload.'.'.dp_access_residual_b64($signature);
	}
}

namespace dataphyre {
	if(!class_exists(core::class,false)){
		final class core {
			/** @var list<string> */
			public static array $loaded=[];
			public static function load_framework_module(string $module): bool {
				self::$loaded[]=$module;
				return true;
			}
		}
	}
}

namespace DpAccessResidualCoverage {
	use Dataphyre\Access\Contracts\Authenticatable;
	use Dataphyre\Access\OAuthClient\Manager;
	use Dataphyre\Access\OAuthClient\Provider;

	final class CustomProvider extends Provider {
		public string $marker;
		public function __construct(string $name,array $config,Manager $manager,string $marker=''){
			parent::__construct($name,$config,$manager);
			$this->marker=$marker;
		}
	}

	final class InvalidProvider {
		public function __construct(mixed ...$arguments) {}
	}

	final class ContractUser implements Authenticatable {
		public function authIdentifier(): int|string|null { return 'contract-id'; }
	}

	final class ConventionalUser {
		public function authIdentifier(): int|string|null { return 41; }
	}

	final class GetterUser {
		public function getAuthIdentifier(): int|string|null { return 'getter-id'; }
	}
}

namespace {
	use Dataphyre\Access\AccessTokenBroker;
	use Dataphyre\Access\Jwt\JwtCodec;
	use Dataphyre\Access\OAuthClient\Manager;
	use Dataphyre\Access\OAuthClient\OAuthUser;
	use Dataphyre\Access\OAuthClient\OpenIdDiscovery;
	use Dataphyre\Access\OAuthClient\Provider;
	use Dataphyre\Access\OAuthClient\StateStore;
	use Dataphyre\Access\Providers\CallbackUserProvider;
	use Dataphyre\Test\Context;
	use DpAccessResidualCoverage\ContractUser;
	use DpAccessResidualCoverage\ConventionalUser;
	use DpAccessResidualCoverage\CustomProvider;
	use DpAccessResidualCoverage\GetterUser;
	use DpAccessResidualCoverage\InvalidProvider;
	use function Dataphyre\Test\test;

	test('access token broker residual coverage creates finds and consumes normalized one-time tokens',static function(Context $t): void {
		$sql=$t->state('access.residual.sql',['select'=>null,'insert'=>true,'update'=>1,'calls'=>[]]);
		$broker=AccessTokenBroker::instance();
		$t->same($broker,AccessTokenBroker::instance());

		$created=$broker->create(' Reset-Link ','42',' User@Example.TEST ',['path'=>'/reset'],1);
		$t->isTrue(is_array($created));
		$t->same('reset_link',$created['type']);
		$t->same('42',$created['user_id']);
		$t->same(' User@Example.TEST ',$created['email']);
		$t->same(['path'=>'/reset'],$created['metadata']);
		$t->notEmpty($created['token']);
		$insertCalls=$sql->get('calls');
		$insertCall=end($insertCalls);
		$insertFields=$insertCall[1][1];
		$t->same((string)(DP_ACCESS_CFG['identity']['tokens_table'] ?? 'dataphyre.access_tokens'),$insertCall[1][0]);
		$t->same(42,$insertFields['user_id']);
		$t->same('user@example.test',$insertFields['email']);
		$t->same($created['token_hash'],$insertFields['token_hash']);
		$t->isTrue(strtotime($created['expires_at'])>=time()+59);

		$t->same(null,$broker->create('---'));
		$sql->put('insert',false);
		$t->same(null,$broker->create('invitation'));
		$sql->put('insert',true);
		$nonNumeric=$broker->create('Email Verification','external-id',null,['not-json'=>NAN]);
		$t->isTrue(is_array($nonNumeric));
		$insertCalls=$sql->get('calls');
		$insertCall=end($insertCalls);
		$t->same(null,$insertCall[1][1]['user_id']);
		$t->same('{}',$insertCall[1][1]['metadata_json']);

		$t->same(null,$broker->find('---','token'));
		$t->same(null,$broker->find('reset','   '));
		$sql->put('select',false);
		$t->same(null,$broker->find('reset-link',$created['token']));
		$sql->put('select',[
			'id'=>'expired','expires_at'=>date('Y-m-d H:i:s',time()-1),'metadata_json'=>'{"old":true}',
		]);
		$t->same(null,$broker->find('reset-link',$created['token']));
		$sql->put('select',[
			'id'=>$created['id'],'expires_at'=>date('Y-m-d H:i:s',time()+300),'metadata_json'=>'{"path":"/reset"}',
		]);
		$found=$broker->find(' Reset Link ',$created['token']);
		$t->same(['path'=>'/reset'],$found['metadata']);
		$selectCalls=$sql->get('calls');
		$selectCall=end($selectCalls);
		$t->same('reset_link',$selectCall[1][3][1]);
		$t->same($created['token_hash'],$selectCall[1][3][0]);

		$selected=$sql->get('select');
		$selected['metadata_json']='invalid-json';
		$sql->put('select',$selected);
		$t->same([],$broker->find('reset-link',$created['token'])['metadata']);
		$sql->put('select',false);
		$t->same(null,$broker->consume('reset-link',$created['token']));
		$sql->put('select',[
			'id'=>$created['id'],'expires_at'=>date('Y-m-d H:i:s',time()+300),'metadata_json'=>'{}',
		]);
		foreach([false,0,true,null,'1',2] as $notOneAffectedRow){
			$sql->put('update',$notOneAffectedRow);
			$t->same(null,$broker->consume('reset-link',$created['token']));
		}
		$sql->put('update',1);
		$t->same($created['id'],$broker->consume('reset-link',$created['token'])['id']);
		$updateCalls=$sql->get('calls');
		$updateCall=end($updateCalls);
		$t->same('WHERE id=? AND used_at IS NULL AND expires_at>=?',$updateCall[1][2]);
		$t->same($updateCall[1][1]['used_at'],$updateCall[1][3][1]);
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('oauth manager residual coverage resolves defaults overrides factories classes and clone isolation',static function(Context $t): void {
		Manager::flush();
		$manager=Manager::instance();
		$t->same($manager,Manager::instance());
		$t->contains('configured',$manager->providerNames());
		$t->isTrue($manager->hasProvider(' configured '));
		$t->isFalse($manager->hasProvider('missing'));
		$t->throws(static fn()=>$manager->provider('   '),Throwable::class);
		$t->throws(static fn()=>$manager->provider('missing'),Throwable::class);
		$t->throws(static fn()=>$manager->provider('non-array'),Throwable::class);

		$configured=$manager->provider('configured');
		$t->instanceOf(Provider::class,$configured);
		$t->same(9,$configured->config()['timeout']);
		$t->same('kept',$configured->config()['nested']['default']);
		$t->isTrue($configured!==$manager->provider('configured'));

		$t->throws(static fn()=>$manager->extendProvider(' ',[]),Throwable::class);
		$manager->extendProvider('plain',['client_id'=>'runtime-client']);
		$t->same('plain',$manager->provider('plain')->name());
		$manager->extendProvider('factory',[
			'factory'=>static fn(string $name,array $config,Manager $owner): Provider=>new Provider($name,$config,$owner),
		]);
		$t->same('factory',$manager->provider('factory')->name());
		$manager->extendProvider('bad-factory',['factory'=>static fn(): object=>new stdClass()]);
		$t->throws(static fn()=>$manager->provider('bad-factory'),Throwable::class);
		$manager->extendProvider('custom-class',['class'=>CustomProvider::class,'arguments'=>['marker-one']]);
		$custom=$manager->provider('custom-class');
		$t->instanceOf(CustomProvider::class,$custom);
		$t->same('marker-one',$custom->marker);
		$manager->extendProvider('bad-class',['class'=>InvalidProvider::class]);
		$t->throws(static fn()=>$manager->provider('bad-class'),Throwable::class);
		$manager->extendProvider('missing-class',['class'=>'DpAccessResidualCoverage\\MissingProvider']);
		$t->instanceOf(Provider::class,$manager->provider('missing-class'));
		$manager->extendProvider('scalar',7);
		$t->contains('scalar',$manager->providerNames());

		Manager::flush();
		$t->isTrue($manager!==Manager::instance());
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('oauth user residual coverage exposes every normalized token profile and callback field',static function(Context $t): void {
		$user=new OAuthUser(
			'provider-one',17,'nick','Example Person','person@example.test',true,'https://example.test/avatar.png',
			'access-token','refresh-token','id-token','Bearer',3600,['openid','email'],['locale'=>'en'],
			['sub'=>'17','nullable'=>null],['access_token'=>'access-token'],['sub'=>'17'],['code'=>'callback-code']
		);
		$t->same('provider-one',$user->provider());
		$t->same(17,$user->id());
		$t->same('nick',$user->nickname());
		$t->same('Example Person',$user->name());
		$t->same('person@example.test',$user->email());
		$t->same(true,$user->emailVerified());
		$t->same('https://example.test/avatar.png',$user->avatar());
		$t->same('access-token',$user->accessToken());
		$t->same('refresh-token',$user->refreshToken());
		$t->same('id-token',$user->idToken());
		$t->same('Bearer',$user->tokenType());
		$t->same(3600,$user->expiresIn());
		$t->same(['openid','email'],$user->scopes());
		$t->same(['locale'=>'en'],$user->attributes());
		$t->same(['sub'=>'17','nullable'=>null],$user->idTokenClaims());
		$t->same('17',$user->claim('sub'));
		$t->same('fallback',$user->claim('missing','fallback'));
		$t->same(['access_token'=>'access-token'],$user->tokenResponse());
		$t->same(['sub'=>'17'],$user->profileResponse());
		$t->same(['code'=>'callback-code'],$user->callbackParameters());
		$t->same('https://example.test/avatar.png',$user->toArray()['avatar']);
		$t->same(['code'=>'callback-code'],$user->toArray()['callback_parameters']);
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('callback user provider residual coverage adapts hooks and every identifier convention',static function(Context $t): void {
		$empty=new CallbackUserProvider();
		$t->same(null,$empty->retrieveById(1));
		$t->same(null,$empty->retrieveByCredentials(['email'=>'none@example.test']));
		$t->isFalse($empty->validateCredentials(null,[]));
		$t->isFalse($empty->validateCredentials(false,[]));
		$t->isTrue($empty->validateCredentials(0,[]));

		$provider=CallbackUserProvider::fromConfig([
			'retrieve_by_id'=>static fn(int|string $id): array=>['id'=>$id],
			'retrieve_by_credentials'=>static fn(array $credentials): array=>['id'=>2,'email'=>$credentials['email']],
			'validate_credentials'=>static fn(array $user,array $credentials): bool=>$user['email']===$credentials['email'],
			'auth_identifier'=>static fn(array $user): int|string|null=>$user['id'] ?? null,
		]);
		$t->same(9,$provider->retrieveById(9)['id']);
		$candidate=$provider->retrieveByCredentials(['email'=>'person@example.test']);
		$t->isTrue($provider->validateCredentials($candidate,['email'=>'person@example.test']));
		$t->same(2,$provider->authIdentifier($candidate));

		$t->same('contract-id',$empty->authIdentifier(new ContractUser()));
		$t->same(41,$empty->authIdentifier(new ConventionalUser()));
		$t->same('getter-id',$empty->authIdentifier(new GetterUser()));
		$t->same(31,$empty->authIdentifier((object)['id'=>31]));
		$t->same(null,$empty->authIdentifier((object)['id'=>[]]));
		$t->same('array-id',$empty->authIdentifier(['id'=>'array-id']));
		$t->same(0,$empty->authIdentifier(0));
		$t->same('scalar-id',$empty->authIdentifier('scalar-id'));
		$t->same(null,$empty->authIdentifier(new stdClass()));
		$t->same(null,$empty->authIdentifier(['id'=>[]]));
		$t->same(null,$empty->authIdentifier(true));
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('openid discovery residual coverage caches aliases and derives issuer endpoints while rejecting bad responses',static function(Context $t): void {
		$calls=[];
		$handler=static function(string $method,string $url) use (&$calls): array {
			$calls[]=[$method,$url];
			return ['status'=>200,'headers'=>[],'body'=>'{"issuer":"https://issuer.example.test"}'];
		};
		$config=['openid_configuration_url'=>'https://oauth.example.test/.well-known/direct','http'=>['handler'=>$handler]];
		$t->same('https://issuer.example.test',OpenIdDiscovery::fetch($config)['issuer']);
		$t->same('https://issuer.example.test',OpenIdDiscovery::fetch($config)['issuer']);
		$t->same(1,count($calls));
		$t->same([],OpenIdDiscovery::fetch([]));
		$t->same([],OpenIdDiscovery::fetch(['discover'=>true,'issuer'=>'   ']));

		$t->throws(static fn()=>OpenIdDiscovery::fetch([
			'discovery_url'=>'https://oauth.example.test/.well-known/invalid-json',
			'http'=>['handler'=>static fn(): array=>['status'=>200,'headers'=>[],'body'=>'not-json']],
		]),Throwable::class);
		$t->throws(static fn()=>OpenIdDiscovery::fetch([
			'discovery_url'=>'https://oauth.example.test/.well-known/failure',
			'http'=>['handler'=>static fn(): array=>['status'=>503,'headers'=>[],'body'=>'{}']],
		]),Throwable::class);
		$issuerCalls=[];
		$issuer=OpenIdDiscovery::fetch([
			'discover'=>true,
			'issuer'=>'https://issuer-two.example.test/',
			'http'=>['handler'=>static function(string $method,string $url) use (&$issuerCalls): array {
				$issuerCalls[]=[$method,$url];
				return ['status'=>200,'headers'=>[],'body'=>'{"authorization_endpoint":"https://issuer-two.example.test/authorize"}'];
			}],
		]);
		$t->same('https://issuer-two.example.test/authorize',$issuer['authorization_endpoint']);
		$t->same('https://issuer-two.example.test/.well-known/openid-configuration',$issuerCalls[0][1]);
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('oauth state store residual coverage purges malformed and expired providers and keeps one-time payloads',static function(Context $t): void {
		if(session_status()===PHP_SESSION_ACTIVE){
			session_write_close();
		}
		$session=$t->globalMap('_SESSION')->clear();
		$store=new StateStore(' ProviderX ',1);
		$t->same(null,$store->pull('missing'));
		$session->put('dp_access',['oauth'=>['states'=>[
			'broken-provider'=>'not-an-array',
			'empty-provider'=>['bad'=>['stored_at'=>0,'payload'=>[]]],
			'providerx'=>[
				'expired'=>['stored_at'=>time()-61,'payload'=>['old'=>true]],
				'malformed'=>['stored_at'=>time(),'payload'=>'not-an-array'],
			],
		]]]);
		$store->put('fresh',['return_to'=>'/dashboard']);
		$states=$session->get('dp_access')['oauth']['states'];
		$t->isFalse(isset($states['broken-provider']));
		$t->isFalse(isset($states['empty-provider']));
		$t->isFalse(isset($states['providerx']['expired']));
		$t->same(null,$store->pull('malformed'));
		$t->same(['return_to'=>'/dashboard'],$store->pull('fresh'));
		$t->same(null,$store->pull('fresh'));
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage');

	test('jwt codec residual coverage exercises HMAC algorithms parsing keys and registered claim failures',static function(Context $t): void {
		$claims=['sub'=>'user-1','iss'=>'issuer','aud'=>['other','client'],'iat'=>100,'nbf'=>100,'exp'=>300];
		foreach(['HS256','HS384','HS512'] as $algorithm){
			$config=['algorithm'=>$algorithm,'algorithms'=>[$algorithm],'secret'=>'secret-'.$algorithm,'now'=>200,'issuer'=>'issuer','audience'=>'client'];
			$token=JwtCodec::encode($claims,$config);
			$t->same('user-1',JwtCodec::decode($token,$config)->claim('sub'));
		}

		$resolverCalls=[];
		$resolverConfig=[
			'algorithm'=>'HS256','algorithms'=>['HS256'],
			'key_resolver'=>static function(string $algorithm,array $headers,array $resolvedClaims,array $config) use (&$resolverCalls): string {
				$resolverCalls[]=[$algorithm,$headers,$resolvedClaims,$config];
				return ' resolver-secret ';
			},
		];
		$resolverToken=JwtCodec::encode(['sub'=>'resolver'],$resolverConfig);
		$t->same('resolver',JwtCodec::decode($resolverToken,$resolverConfig)->claim('sub'));
		$t->notEmpty($resolverCalls);

		$keyCalls=[];
		$kidConfig=[
			'algorithm'=>'HS256','algorithms'=>['HS256'],
			'keys'=>['kid-one'=>static function(string $algorithm,array $headers,array $resolvedClaims,array $config) use (&$keyCalls): string {
				$keyCalls[]=[$algorithm,$headers,$resolvedClaims,$config];
				return ' kid-secret ';
			}],
		];
		$kidToken=JwtCodec::encode(['sub'=>'kid'],$kidConfig,['kid'=>'kid-one']);
		$t->same('kid',JwtCodec::decode($kidToken,$kidConfig)->claim('sub'));
		$t->notEmpty($keyCalls);

		$callableConfig=['algorithm'=>'HS256','algorithms'=>['HS256'],'secret'=>static fn(): string=>'callable-secret'];
		$t->same('candidate',JwtCodec::decode(JwtCodec::encode(['sub'=>'candidate'],$callableConfig),$callableConfig)->claim('sub'));
		$fallbackConfig=['algorithm'=>'HS256','algorithms'=>[],'secret'=>'fallback-secret'];
		$t->same('fallback',JwtCodec::decode(JwtCodec::encode(['sub'=>'fallback'],$fallbackConfig),$fallbackConfig)->claim('sub'));

		$t->throws(static fn()=>JwtCodec::decode(''),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode('one.two'),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode('*.e30.eA',['secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_b64('not-json').'.e30.eA',['secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_b64('{"alg":"HS256"}').'.'.dp_access_residual_b64('not-json').'.eA',['secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_jwt([],[],'x'),['algorithms'=>['HS256'],'secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_jwt(['alg'=>'HS512'],[],'x'),['algorithms'=>['HS256'],'secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(
			dp_access_residual_b64('{"alg":"HS256"}').'.'.dp_access_residual_b64('{}').'.*',
			['algorithms'=>['HS256'],'secret'=>'secret']
		),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_jwt(['alg'=>'HS256'],[],'wrong'),['algorithms'=>['HS256'],'secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode(['bad'=>NAN],['secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode([],['algorithm'=>'HS999','algorithms'=>['HS999'],'secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_jwt(['alg'=>'HS999'],[],'x'),['algorithms'=>['HS999'],'secret'=>'secret']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(dp_access_residual_jwt(['alg'=>'ES256'],[],'x'),['algorithms'=>['ES256'],'key'=>'key']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode([],['algorithm'=>'RS256','algorithms'=>['RS256'],'key'=>'key']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode([],['algorithm'=>'HS256','algorithms'=>['HS256']]),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode([],['algorithm'=>'HS256','algorithms'=>['HS256'],'key_resolver'=>static fn(): string=>' ']),Throwable::class);
		$t->throws(static fn()=>JwtCodec::encode([],['algorithm'=>'HS256','algorithms'=>['HS256'],'keys'=>['one'=>static fn(): string=>' ']],['kid'=>'one']),Throwable::class);

		$claimConfig=['algorithm'=>'HS256','algorithms'=>['HS256'],'secret'=>'claim-secret'];
		foreach([
			[['nbf'=>201],['now'=>200]],
			[['iat'=>201],['now'=>200]],
			[['exp'=>199],['now'=>200]],
			[['iss'=>'wrong'],['now'=>200,'issuer'=>'expected']],
			[['aud'=>['other']],['now'=>200,'audience'=>'expected']],
		] as [$badClaims,$validation]){
			$token=JwtCodec::encode($badClaims,$claimConfig);
			$t->throws(static fn()=>JwtCodec::decode($token,array_replace($claimConfig,$validation)),Throwable::class);
		}
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage')->maxMillis(10000);

	test('jwt codec residual coverage verifies every RSA family member and rejects bad and unsupported RSA signatures',static function(Context $t): void {
		$privateKey="-----BEGIN RSA "."PRIVATE KEY-----\n".<<<'PEM'
MIIEowIBAAKCAQEAp05omtGDKliUd9I4v9tqXb3GifVwLgTkIz/gymWp7kd2DMPq
opURt0hSIBHgYPIe6DoJun1z1VADFGmbxMqteoJl/VSErQg0jbhq0OW0z+QVu50w
2c5zmMTJQ0xJRkI67ZwEYhfjDgNNudSMYw8mlIfqQAzzrGxXS8LiP3QZwH8NdOSq
Cg9rhpbVaYjSZvCCcG+cCgw7eIiK4Fz/ABz9hRdCMY97YokRtTfmH4vvLHC+BfrY
T4sEKEzut79XtCSwiymNCK+cFnEt8V1R+Y9Rjw2al+G18KOh4KLYUjvJ9mwxTl1c
ZnGW02nKjgC84oWko1+bCdEhYiGM+z/RsvrxGQIDAQABAoIBAFDAd5zCIxz9RCvR
O7LepKg6QOm1nT+Y/MRGwKjwCOUJeOEQbt+qM7LTJVB1UGd6dZCA8tEgXBhJVjM0
BgsmCDVpWvC7Ko6Zt0PwDx5kwLDW1eaIKFv4WbMSyFHDMFrI/MhS1YrDHMRWs91N
ybTGS0jFkTr5BWPjpv7aQXl/AC74XBB5qFVMS1sO10bVHxchDPGB/q+uI7/I4Zdy
f0T0Rd5jSJmzwQNV3aI/Tm7HlzZY5CTDSueVkdCwh3k2cLvdLbGDWiUZ3n7tUQjy
khuiwit0R1OoQqhiR9fx+YuVWfiZR1szYE56dVKcHg1vVUMIFzTcRjIU0UxeML3G
v2OjgUECgYEA1i5QhMcIgHuGhYzZqcRc+cHyH29VDPrc+Rak/5ZRNSLo2Rh4AGHb
mCz+xKfbiZch4BbAIDQ5l1Ag451pu3eBKfwCPT6DXfMhRnzSBq+2MwuFmjhETeNm
/PH6pUiBXgY4WeM/njz1aTWByrmniE9hnTNujfvzHBHbsZHtdQaRuk8CgYEAx/kZ
D0HS/NpsOvfn9N6oemrGpYJltIdovfDrvS8b2VM3SepgKql5zPyonYiadvOTSCJy
ZvVfuO7xcr6sSZqqLoRlwrUpwNnL01VwAwxNKMIFtoAetwjsfRipWkr/6Y8wWHua
m1CA8ENAkIVhma/Hfa9+GssXW2oLnEDcSnYAjBcCgYBX1U535Rd7eSzFf+mTUU+/
rOWaNpHubMJJ9BteJUrQO6y5uusbXQYs9ebUxvGlDzF5MFtB2aj0gIu8TEWb93ok
uZBBhW1iDd7LhUysKUrSzBrSD9kTB/qoKKPdPEqxQGPDmQnx3pXVu3eqp1Ao+kTR
rtHbsEMWc8xgmbODlloUyQKBgQCW1PRp5aRWxAlOkR6MPEWn0FH1FN3RxTDj04x8
LcQ7r+DMB9RxWVNdolUsPZUEk8RLbHAN6JZCzzee7OLWwaoLXCHFMxBDPgPXa2IJ
aoXocDAO76Q7Oqfl02wphthwOmik1NZQv/ABSTixyWlMmqFF09CyNO1xLhOD0AhY
wZi4EQKBgF6EwAzVsbHci/4mxYFjTl1F6GRQo+hX5hy0mYmyCY5vfNfZ7YbZH+Q7
DiIzmgCcTpr8xIBok3ou8W9E8MKo6jPSjr2VdMgeztNv9DZQv7KwDmQM5bJHpjwZ
ZdCCUm08q06LBjnFphO+pg1GhskO2r/QY0a7YY1X9z6I6iWcT5G6
-----END RSA PRIVATE KEY-----
PEM;
		$publicKey=<<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAp05omtGDKliUd9I4v9tq
Xb3GifVwLgTkIz/gymWp7kd2DMPqopURt0hSIBHgYPIe6DoJun1z1VADFGmbxMqt
eoJl/VSErQg0jbhq0OW0z+QVu50w2c5zmMTJQ0xJRkI67ZwEYhfjDgNNudSMYw8m
lIfqQAzzrGxXS8LiP3QZwH8NdOSqCg9rhpbVaYjSZvCCcG+cCgw7eIiK4Fz/ABz9
hRdCMY97YokRtTfmH4vvLHC+BfrYT4sEKEzut79XtCSwiymNCK+cFnEt8V1R+Y9R
jw2al+G18KOh4KLYUjvJ9mwxTl1cZnGW02nKjgC84oWko1+bCdEhYiGM+z/Rsvrx
GQIDAQAB
-----END PUBLIC KEY-----
PEM;
		foreach([
			['RS256',OPENSSL_ALGO_SHA256],
			['RS384',OPENSSL_ALGO_SHA384],
			['RS512',OPENSSL_ALGO_SHA512],
		] as [$algorithm,$opensslAlgorithm]){
			$token=dp_access_residual_rsa_jwt(['sub'=>$algorithm],$algorithm,$privateKey,$opensslAlgorithm);
			$t->same($algorithm,JwtCodec::decode($token,['algorithms'=>[$algorithm],'public_key'=>$publicKey])->claim('sub'));
		}
		$t->throws(static fn()=>JwtCodec::decode(
			dp_access_residual_jwt(['alg'=>'RS256'],['sub'=>'bad'],'invalid-signature'),
			['algorithms'=>['RS256'],'public_key'=>$publicKey]
		),Throwable::class);
		$t->throws(static fn()=>JwtCodec::decode(
			dp_access_residual_jwt(['alg'=>'RS999'],['sub'=>'unsupported'],'signature'),
			['algorithms'=>['RS999'],'public_key'=>$publicKey]
		),Throwable::class);
	})->tag('access','access-residual-exact','deep-coverage')->group('framework-coverage')->maxMillis(10000);
}
