<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\Jwt\JwtCodec;
use Dataphyre\Access\Jwt\JwtPayload;
use Dataphyre\Access\OAuthClient\AuthorizationRequest;
use Dataphyre\Access\OAuthClient\HttpClient;
use Dataphyre\Access\OAuthClient\Manager;
use Dataphyre\Access\OAuthClient\OAuthUser;
use Dataphyre\Access\OAuthClient\Provider;
use Dataphyre\Access\OAuthClient\StateStore;
use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Test\Context;
use Dataphyre\Test\FakeHttpRequest;
use Dataphyre\Test\FakeHttpResponse;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!defined('DP_ACCESS_CFG')){
	define('DP_ACCESS_CFG',['framework'=>['oauth'=>['providers'=>[]]]]);
}
framework(['access','http']);

require_once __DIR__.'/fixtures/oauth_provider_scenario.php';

function dp_oauth_user(?string $access='access-one',?string $refresh='refresh-one'): OAuthUser {
	return new OAuthUser('mock','user-1','example','Example User','user@example.test',true,null,$access,$refresh);
}

test('oauth provider deep coverage exposes immutable metadata and builds stateful and stateless authorization requests',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$provider=$oauth->provider([
		'extra_authorize_parameters'=>['prompt'=>'consent'],
	]);
	$t->same('mock',$provider->name());
	$t->hasKey('client_id',$provider->config());
	$t->isTrue($provider->manager()===Manager::instance());
	$scoped=$provider->scopes([' email ','openid','','email'])->redirectUri(' https://app.example.test/other ')->with(['login_hint'=>'a@example.test']);
	$t->same('mock',$scoped->name());
	$request=$scoped->authorizationRequest();
	$t->instanceOf(AuthorizationRequest::class,$request);
	$t->same('mock',$request->provider());
	$t->notEmpty($request->state());
	$t->notEmpty($request->codeVerifier());
	$t->notEmpty($request->nonce());
	$t->isTrue(str_contains($request->url(),'prompt=consent'));
	$t->isTrue(str_contains($request->url(),'login_hint=a%40example.test'));
	$t->isTrue(str_contains($request->url(),'scope=email%20openid'));
	$t->isTrue(str_contains($provider->authorizationUrl(),'client_id=client-one'));
	$t->instanceOf(Response::class,$provider->redirect());

	$stateless=$provider->stateless();
	$statelessRequest=$stateless->authorizationRequest();
	$t->same(null,$statelessRequest->state());
	$t->same(null,$statelessRequest->codeVerifier());
	$t->same(null,$statelessRequest->nonce());
	$t->isTrue($provider!==$stateless);
	$t->throws(static fn()=>$oauth->provider(['authorization_url'=>' '])->authorizationRequest(),Throwable::class);
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage exchanges callbacks maps profiles and rejects callback and state errors',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$provider=$oauth->provider();
	$authorization=$provider->authorizationRequest();
	$user=$provider->user(['code'=>'code-one','state'=>$authorization->state()]);
	$t->instanceOf(OAuthUser::class,$user);
	$t->same('mock',$user->provider());
	$t->same('user-1',$user->id());
	$t->same('user@example.test',$user->email());
	$t->same('Example User',$user->name());
	$t->same('example',$user->nickname());
	$t->same(true,$user->emailVerified());
	$t->same('access-one',$user->accessToken());
	$t->same(['openid','email','profile'],$user->scopes());
	$t->throws(static fn()=>$provider->user(['code'=>'replay','state'=>$authorization->state()]),Throwable::class);
	$t->throws(static fn()=>$provider->user(['error'=>'access_denied','error_description'=>'Denied']),Throwable::class);
	$t->throws(static fn()=>$provider->user(['oauth_error'=>'denied','error_message'=>'No']),Throwable::class);
	$t->throws(static fn()=>$provider->user([]),Throwable::class);
	$t->throws(static fn()=>$provider->user(['code'=>'missing-state']),Throwable::class);
	$t->throws(static fn()=>$provider->user(['code'=>'bad-state','state'=>'unknown']),Throwable::class);

	$fromToken=$provider->userFromToken(' bearer-token ');
	$t->same('bearer-token',$fromToken->accessToken());
	$t->throws(static fn()=>$provider->userFromToken(' '),Throwable::class);
	$request=Request::create('GET','/oauth',['code'=>'request-code']);
	$stateless=$oauth->provider(['state'=>false,'pkce'=>false,'nonce'=>false])->stateless(false);
	$t->instanceOf(OAuthUser::class,$stateless->user($request));
	$t->globalMap('_GET')->replace(['code'=>'global-code']);
	$t->instanceOf(OAuthUser::class,$stateless->user());
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage refreshes revokes and reports provider response failures',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$provider=$oauth->provider(['state'=>false,'pkce'=>false,'nonce'=>false]);
	$oauth->http()->respondUsing(
		'POST',
		DpOAuthScenario::TOKEN_URL,
		static fn(FakeHttpRequest $request): FakeHttpResponse=>$request->formValue('grant_type')==='refresh_token'
			? FakeHttpResponse::form(['access_token'=>'refreshed','expires_in'=>60])
			: FakeHttpResponse::form(['access_token'=>'exchanged'])
	);
	$refreshed=$provider->refresh(' refresh-original ');
	$t->same('refreshed',$refreshed['access_token']);
	$t->same('refresh-original',$refreshed['refresh_token']);
	$t->same('refresh-one',$provider->refresh(dp_oauth_user())['refresh_token']);
	$t->instanceOf(OAuthUser::class,$provider->refreshedUser('refresh-original'));
	$t->throws(static fn()=>$provider->refresh(' '),Throwable::class);
	$t->throws(static fn()=>$provider->refresh(dp_oauth_user('access',null)),Throwable::class);

	$t->isTrue($provider->revoke(' access-token '));
	$t->isTrue($provider->revoke(dp_oauth_user(),'refresh_token'));
	$t->isTrue($provider->revoke(dp_oauth_user(),null));
	$t->throws(static fn()=>$provider->revoke(' '),Throwable::class);
	$t->throws(static fn()=>$provider->revoke(dp_oauth_user(null,null),'refresh_token'),Throwable::class);
	$oauth->http()->assertFormRequested($t, 'POST', DpOAuthScenario::TOKEN_URL, ['grant_type'=>'refresh_token']);
	$oauth->http()->assertFormRequested($t, 'POST', DpOAuthScenario::REVOCATION_URL, ['token'=>'access-token']);

	$oauth->http()->respondJson('POST', DpOAuthScenario::TOKEN_URL, ['refresh_token'=>'only']);
	$t->throws(static fn()=>$provider->refresh('refresh'),Throwable::class);
	$t->throws(static fn()=>$t->nonPublic($provider)->invoke('exchangeCodeForToken', 'code', null),Throwable::class);
	$oauth->http()->respondJson('POST', DpOAuthScenario::TOKEN_URL, ['error_description'=>'bad refresh'], 400);
	$t->throws(static fn()=>$provider->refresh('refresh'),Throwable::class);
	$oauth->http()->respondFailure('POST', DpOAuthScenario::REVOCATION_URL, 500, 'error=bad');
	$t->throws(static fn()=>$provider->revoke('access'),Throwable::class);
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage validates id tokens and nonce bindings with direct and resolver keys',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$secret='provider-secret';
	$claims=['iss'=>'https://issuer.example.test','aud'=>'client-one','exp'=>time()+300,'iat'=>time(),'nonce'=>'expected-nonce','sub'=>'jwt-user'];
	$token=JwtCodec::encode($claims,['algorithm'=>'HS256','algorithms'=>['HS256'],'key'=>$secret]);
	$provider=$oauth->provider([
		'verify_id_token'=>true,
		'id_token_algorithms'=>['HS256'],
		'id_token_key'=>$secret,
		'id_token_issuer'=>'https://issuer.example.test',
		'id_token_audience'=>'client-one',
		'id_token_leeway'=>0,
	]);
	$providerInternals=$t->nonPublic($provider);
	$t->same(null,$providerInternals->invoke('validateIdToken', [], null));
	$t->instanceOf(JwtPayload::class,$providerInternals->invoke('validateIdToken', ['id_token'=>$token], ['nonce'=>'expected-nonce']));
	$t->throws(static fn()=>$providerInternals->invoke('validateIdToken', ['id_token'=>$token], ['nonce'=>'wrong']),Throwable::class);
	$t->same(null,$t->nonPublic($oauth->provider(['verify_id_token'=>false]))->invoke('validateIdToken', ['id_token'=>$token], null));
	$claimsProvider=$oauth->provider([
		'verify_id_token'=>true,
		'id_token_algorithms'=>['HS256'],
		'id_token_key'=>$secret,
		'id_token_issuer'=>'https://issuer.example.test',
		'id_token_audience'=>'client-one',
		'id_token_leeway'=>0,
		'userinfo_url'=>'',
	]);
	$claimsUser=$t->nonPublic($claimsProvider)->invoke('oauthUserFromTokenResponse', [
		'access_token'=>'claims-access','id_token'=>$token,
	], [], ['nonce'=>'expected-nonce']);
	$t->same('jwt-user',$claimsUser->id());

	$resolverProvider=$oauth->provider([
		'verify_id_token'=>true,
		'id_token_algorithms'=>['HS256'],
		'jwks'=>[['kty'=>'oct','kid'=>'one','alg'=>'HS256']],
		'jwks_url'=>'https://oauth.example.test/jwks',
		'issuer'=>'https://issuer.example.test',
		'discover'=>false,
	]);
	$resolverInternals=$t->nonPublic($resolverProvider);
	$t->throws(static fn()=>$resolverInternals->invoke('validateIdToken', ['id_token'=>$token], null),Throwable::class);
	$keyConfig=$resolverInternals->invoke('idTokenKeyConfig');
	$t->hasKey('jwks',$keyConfig);
	$t->hasKey('jwks_url',$keyConfig);
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage resolves local users and honors login dialbacks',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$user=dp_oauth_user();
	$noResolver=$oauth->provider(['resolve_user'=>null]);
	$t->same(null,$noResolver->resolveLocalUser($user));
	$t->isFalse($noResolver->login($user,'web',false));

	$provider=$oauth->provider(['resolve_user'=>static fn(OAuthUser $oauthUser,Provider $provider): array=>['id'=>$oauthUser->id()]]);
	$t->same(['id'=>'user-1'],$provider->resolveLocalUser($user));
	$oauth->whenDialbackReturns('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_RESOLVE_LOCAL_USER', 'before-user');
	$t->same('before-user',$provider->resolveLocalUser($user));
	$oauth->withoutDialback('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_RESOLVE_LOCAL_USER');
	$oauth->whenDialbackReturns('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_RESOLVE_LOCAL_USER', 'after-user');
	$t->same('after-user',$provider->resolveLocalUser($user));
	$oauth->withoutDialback('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_RESOLVE_LOCAL_USER');

	$oauth->whenDialbackReturns('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_LOGIN', false);
	$t->isFalse($provider->login($user,'admin',true));
	$oauth->withoutDialback('CALL_ACCESS_FRAMEWORK_OAUTH_BEFORE_LOGIN')->authResult(true);
	$t->isTrue($provider->login($user,'admin',true));
	$oauth->whenDialbackReturns('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_LOGIN', false);
	$t->isFalse($provider->login($user,'admin',true));
	$oauth->authLogin()->assertCalled($t);
	$oauth->withoutDialback('CALL_ACCESS_FRAMEWORK_OAUTH_AFTER_LOGIN');
	$flowProvider=$oauth->provider([
		'state'=>false,'pkce'=>false,'nonce'=>false,
		'resolve_user'=>static fn(OAuthUser $oauthUser): array=>['id'=>$oauthUser->id()],
	]);
	$t->isTrue($flowProvider->login(['code'=>'login-code'],'web',false));
	$oauth->dialbacks()->assertCalled($t);
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage exercises private normalization auth transport and mapping helpers',static function(Context $t): void {
	$oauth=new DpOAuthScenario($t);
	$provider=$oauth->provider([
		'scope_separator'=>',',
		'scopes'=>'openid,email,,email',
		'extra_token_parameters'=>'invalid',
		'userinfo_url'=>'',
	]);
	$internals=$t->nonPublic($provider);
	$t->same(['openid','email'],$internals->invoke('resolveScopes'));
	$t->same([],$internals->invoke('normalizeScopes', 42));
	$t->same(['a','b'],$internals->invoke('normalizeScopes', [' a ','b','a','']));
	$t->same(null,$t->nonPublic($oauth->provider(['redirect_uri'=>null]))->invoke('resolveRedirectUri'));
	$blankRedirect=$provider->redirectUri(' ');
	$t->same(null,$t->nonPublic($blankRedirect)->invoke('resolveRedirectUri'));
	$t->same('verifier',$t->nonPublic($oauth->provider(['pkce_method'=>'plain']))->invoke('codeChallenge', 'verifier'));
	$t->notEmpty($internals->invoke('codeChallenge', 'verifier'));
	$t->notEmpty($internals->invoke('generateRandomToken', 8));
	$t->same('https://x.test/path',$internals->invoke('appendQuery', 'https://x.test/path', []));
	$t->same('https://x.test/path?a=1',$internals->invoke('appendQuery', 'https://x.test/path', ['a'=>1]));
	$t->same('https://x.test/path?old=1&a=1',$internals->invoke('appendQuery', 'https://x.test/path?old=1', ['a'=>1]));
	$t->same([],$internals->invoke('decodeResponseBody', ['body'=>' ']));
	$t->same(['a'=>1],$internals->invoke('decodeResponseBody', ['body'=>'{"a":1}']));
	$t->same(['a'=>'1'],$internals->invoke('decodeResponseBody', ['body'=>'a=1']));
	$internals->invoke('throwForHttpError', ['status'=>204], [], 'ok');
	$t->throws(static fn()=>$internals->invoke('throwForHttpError', ['status'=>500], ['message'=>'broken'], 'test'),Throwable::class);
	$t->same([],$internals->invoke('extraParameters', 'token'));
	$t->same('yes',$internals->invoke('firstString', ['a'=>' ','b'=>' yes '], ['a','b']));
	$t->same(null,$internals->invoke('firstString', ['a'=>1], ['a']));
	$t->same(null,$internals->invoke('nullableString', 1));
	$t->same('x',$internals->invoke('nullableString', ' x '));
	$t->same(null,$internals->invoke('nullableString', ' '));
	$t->same(12,$internals->invoke('nullableInt', '12'));
	$t->same(null,$internals->invoke('nullableInt', 'x'));
	foreach([[true,true],['yes',true],['0',false],[2,true],[0,false],['maybe',null],[1.2,null]] as [$input,$expected]){
		$t->same($expected,$internals->invoke('nullableBool', $input));
	}
	$t->same(null,$internals->invoke('identityValue', null, [], [], []));
	$t->same('fallback',$internals->invoke('identityValue', ['missing','nested.value'], ['nested'=>['value'=>'fallback']], [], []));
	$t->same(null,$internals->invoke('identityValue', ['missing'], [], [], []));
	$t->same('default',$internals->invoke('arrayGet', [], '', 'default'));
	$t->same('default',$internals->invoke('arrayGet', ['a'=>[]], 'a.b', 'default'));
	$t->same('value',$internals->invoke('arrayGet', ['a'=>['b'=>'value']], 'a.b', 'default'));
	$t->instanceOf(HttpClient::class,$internals->invoke('httpClient'));
	$t->instanceOf(StateStore::class,$internals->invoke('stateStore'));

	foreach(['authorization_url','token_url','userinfo_url','jwks_url','revocation_url','issuer','unknown'] as $key){
		$internals->invoke('discoveryKey', $key);
	}
	$t->throws(static fn()=>$internals->invoke('requiredConfig', 'missing'),Throwable::class);
	$t->same('fallback',$internals->invoke('configValue', 'unknown', 'fallback'));
	$t->same([],$internals->invoke('discoveryConfiguration'));
	$t->same([],$internals->invoke('fetchUserinfo', ['access_token'=>'token']));
	$t->same([],$t->nonPublic($oauth->provider(['userinfo_url'=>DpOAuthScenario::USERINFO_URL]))->invoke('fetchUserinfo', []));
	$t->same(null,$t->nonPublic($provider->stateless())->invoke('consumeTransaction', []));
	$t->same(null,$t->nonPublic($oauth->provider(['state'=>false,'pkce'=>false,'nonce'=>false]))->invoke('consumeTransaction', []));
	$queryUserinfo=$oauth->provider([
		'userinfo_auth_method'=>'query',
		'userinfo_token_parameter'=>'token',
		'userinfo_query'=>['format'=>'full'],
	]);
	$t->hasKey('sub',$t->nonPublic($queryUserinfo)->invoke('fetchUserinfo', ['access_token'=>'query-token']));
	$oauth->http()->assertRequested($t, 'GET', DpOAuthScenario::USERINFO_URL.'?token=query-token&format=full');
	$formUserinfo=$oauth->provider([
		'userinfo_auth_method'=>'form',
		'userinfo_token_parameter'=>'token',
		'extra_userinfo_parameters'=>['include'=>'all'],
	]);
	$t->hasKey('sub',$t->nonPublic($formUserinfo)->invoke('fetchUserinfo', ['access_token'=>'form-token']));
	$oauth->http()->assertFormRequested($t, 'GET', DpOAuthScenario::USERINFO_URL, ['token'=>'form-token','include'=>'all']);

	$discoveryUrl='https://oauth.example.test/discovery-success';
	$oauth->http()->respondJson('GET', $discoveryUrl, ['authorization_endpoint'=>'https://discovered.example.test/authorize']);
	$discovered=$oauth->configuredProvider('discovered',[
		'client_id'=>'client-one',
		'discovery_url'=>$discoveryUrl,
	]);
	$t->same('https://discovered.example.test/authorize',$t->nonPublic($discovered)->invoke('configValue', 'authorization_url'));
	$failedDiscoveryUrl='https://oauth.example.test/discovery-fail';
	$oauth->http()->respondFailure('GET', $failedDiscoveryUrl, 500, 'error');
	$failedDiscovery=$oauth->configuredProvider('failed-discovery',['discovery_url'=>$failedDiscoveryUrl]);
	$t->same([],$t->nonPublic($failedDiscovery)->invoke('discoveryConfiguration'));

	$basicAuth=$t->nonPublic($oauth->provider(['token_auth_method'=>'client_secret_basic']))->capture(
		'clientAuthHeadersAndPayload',
		payload: [],
		configKey: 'token_auth_method',
	);
	$t->hasKey('Authorization',$basicAuth->result());
	$noAuth=$t->nonPublic($oauth->provider(['token_auth_method'=>'none']))->capture(
		'clientAuthHeadersAndPayload',
		payload: [],
		configKey: 'token_auth_method',
	);
	$t->same([],$noAuth->result());
	$clientIdPost=$t->nonPublic($oauth->provider(['token_auth_method'=>'client_id_post']))->capture(
		'clientAuthHeadersAndPayload',
		payload: [],
		configKey: 'token_auth_method',
	);
	$t->same('client-one',$clientIdPost->argument('payload')['client_id']);
	$t->isFalse(isset($clientIdPost->argument('payload')['client_secret']));
	$refreshAuth=$t->nonPublic($oauth->provider(['refresh_auth_method'=>'','token_auth_method'=>'client_secret_post']))->capture(
		'clientAuthHeadersAndPayload',
		payload: [],
		configKey: 'refresh_auth_method',
	);
	$t->same('secret-one',$refreshAuth->argument('payload')['client_secret']);

	$t->same('refresh-one',$internals->invoke('extractRefreshToken', dp_oauth_user()));
	$t->same('token',$internals->invoke('extractRefreshToken', ' token '));
	$t->same('access-one',$internals->invoke('extractRevocationToken', dp_oauth_user(), null));
	$t->same('refresh-one',$internals->invoke('extractRevocationToken', dp_oauth_user(), 'refresh_token'));
	$t->same('token',$internals->invoke('extractRevocationToken', ' token ', null));
	$t->same('access_token',$internals->invoke('normalizeRevocationHint', ' invalid ', dp_oauth_user()));
	$t->same('refresh_token',$internals->invoke('normalizeRevocationHint', ' REFRESH_TOKEN ', 'token'));
	$t->same(null,$internals->invoke('normalizeRevocationHint', 'invalid', 'token'));
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage')->maxMillis(10000);

test('oauth provider deep coverage accepts injected HTTP handlers and rejects invalid handler responses',static function(Context $t): void {
	$handler=$t->spy()->willReturn(['status'=>201,'headers'=>'invalid','body'=>123]);
	$client=new HttpClient(['handler'=>$handler]);
	$response=$client->send(' post ','https://oauth.example.test/token',['a'=>1]);
	$t->same(201,$response['status']);
	$t->same([],$response['headers']);
	$t->same('123',$response['body']);
	$handler->assertCalled($t);
	$invalidHandler=$t->spy()->willReturn(null);
	$invalid=new HttpClient(['handler'=>$invalidHandler]);
	$t->throws(static fn()=>$invalid->send('GET','https://oauth.example.test/token'),Throwable::class);
	$invalidHandler->assertCalled($t);
})->tag('access','oauth','provider','deep-coverage')->group('framework-coverage');
