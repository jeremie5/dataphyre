<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Access\Contracts\Authenticatable;
use Dataphyre\Access\Contracts\UserProvider;
use Dataphyre\Access\Guards\AccessGuard;
use Dataphyre\Access\Guards\JwtGuard;
use Dataphyre\Access\Jwt\JwtCodec;
use Dataphyre\Access\Jwt\JwtPayload;
use Dataphyre\Access\OAuthClient\HttpClient;
use Dataphyre\Access\OAuthClient\JwksResolver;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

if(!defined('DP_ACCESS_CFG')){
	define('DP_ACCESS_CFG',[
		'framework'=>[
			'jwt'=>[
				'secret'=>'global-secret',
				'algorithms'=>['HS256'],
				'now'=>1700000000,
				'guards'=>[
					'named'=>['secret'=>'named-secret','subject_claim'=>'uid'],
					'invalid'=>'not-an-array',
				],
			],
		],
	]);
}

$dpAccessCurlConstants=[
	'CURLOPT_RETURNTRANSFER','CURLOPT_CUSTOMREQUEST','CURLOPT_HTTPHEADER','CURLOPT_FOLLOWLOCATION',
	'CURLOPT_TIMEOUT','CURLOPT_CONNECTTIMEOUT','CURLOPT_USERAGENT','CURLOPT_HEADERFUNCTION',
	'CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE',
];
$dpAccessCurlConstantStubs=[];
foreach($dpAccessCurlConstants as $index=>$constant){
	if(!defined($constant)){
		$dpAccessCurlConstantStubs[$constant]=$index+1;
	}
}
framework(['access'],[
	'constants'=>$dpAccessCurlConstantStubs,
	'functions'=>function_exists('curl_init') ? [] : ['curl_init'],
]);

if(!class_exists('dataphyre\\access',false)){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace dataphyre;
final class access {
	public static array $calls=[];
	public static bool $loggedIn=false;
	public static mixed $userId=null;
	public static bool $validateResult=false;
	public static bool $recoverResult=false;
	public static bool $createResult=false;
	public static bool $disableResult=false;
	public static array $context=[];
	public static function logged_in(string $authType): bool { self::$calls[]=['logged_in',$authType]; return self::$loggedIn; }
	public static function userid(string $authType): mixed { self::$calls[]=['userid',$authType]; return self::$userId; }
	public static function validate_session(bool $cache,string $authType): bool { self::$calls[]=['validate_session',$cache,$authType]; return self::$validateResult; }
	public static function recover_session(string $authType): bool { self::$calls[]=['recover_session',$authType]; return self::$recoverResult; }
	public static function create_session(int $userId,bool $remember,string $authType): bool { self::$calls[]=['create_session',$userId,$remember,$authType]; return self::$createResult; }
	public static function disable_session(string $authType): bool { self::$calls[]=['disable_session',$authType]; return self::$disableResult; }
	public static function auth_context(?string $authType=null): array { self::$calls[]=['auth_context',$authType]; return self::$context; }
}
PHP);
}

if(!function_exists('Dataphyre\\Access\\OAuthClient\\curl_init')){
	\Dataphyre\Test\define_test_symbols(<<<'PHP'
namespace Dataphyre\Access\OAuthClient;
function curl_init(?string $url=null): object|false {
	$transport=\Dataphyre\Test\TestState::channel('access.oauth.transport');
	$transport->append('curl_calls',['init',$url]);
	return $transport->get('curl_init',true) ? (object)['url'=>$url] : false;
}
function curl_setopt_array(object $handle,array $options): bool {
	\Dataphyre\Test\TestState::channel('access.oauth.transport')->append('curl_calls',['setopt_array',$options]);
	$callback=$options[CURLOPT_HEADERFUNCTION] ?? null;
	if(is_callable($callback)){
		$callback($handle,"HTTP/1.1 200 OK\r\n");
		$callback($handle,"\r\n");
		$callback($handle,"X-OAuth-Test: yes\r\n");
	}
	return true;
}
function curl_setopt(object $handle,int $option,mixed $value): bool {
	\Dataphyre\Test\TestState::channel('access.oauth.transport')->append('curl_calls',['setopt',$option,$value]);
	return true;
}
function curl_exec(object $handle): string|false { return \Dataphyre\Test\TestState::channel('access.oauth.transport')->get('curl_body','curl-body'); }
function curl_getinfo(object $handle,int $option): mixed { return \Dataphyre\Test\TestState::channel('access.oauth.transport')->get('curl_status',200); }
function curl_error(object $handle): string { return (string)\Dataphyre\Test\TestState::channel('access.oauth.transport')->get('curl_error','transport-error'); }
function curl_close(object $handle): void { \Dataphyre\Test\TestState::channel('access.oauth.transport')->append('curl_calls',['close']); }
function file_get_contents(string $filename,bool $useIncludePath=false,mixed $context=null,?int $offset=null,?int $length=null): string|false {
	$transport=\Dataphyre\Test\TestState::channel('access.oauth.transport');
	$transport->append('stream_calls',[$filename,$useIncludePath,$context]);
	return $transport->get('stream_body','stream-body');
}
PHP);
}

final class DpAccessOwnedProvider implements UserProvider {
	public mixed $byId=['id'=>7];
	public mixed $byCredentials=['id'=>7];
	public bool $credentialsValid=true;
	public int|string|null $identifier=7;
	public int $byIdCalls=0;
	public function retrieveById(int|string $identifier): mixed { $this->byIdCalls++; return $this->byId; }
	public function retrieveByCredentials(array $credentials): mixed { return $this->byCredentials; }
	public function validateCredentials(mixed $user,array $credentials): bool { return $this->credentialsValid; }
	public function authIdentifier(mixed $user): int|string|null { return $this->identifier; }
}

final class DpAccessOwnedAuthenticatable implements Authenticatable {
	public function __construct(private int|string|null $identifier){}
	public function authIdentifier(): int|string|null { return $this->identifier; }
}

function dp_access_owned_reset_kernel(): void {
	\dataphyre\access::$calls=[];
	\dataphyre\access::$loggedIn=false;
	\dataphyre\access::$userId=null;
	\dataphyre\access::$validateResult=false;
	\dataphyre\access::$recoverResult=false;
	\dataphyre\access::$createResult=false;
	\dataphyre\access::$disableResult=false;
	\dataphyre\access::$context=[];
}

function dp_access_owned_base64url(string $value): string {
	return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

test('access OAuth HTTP client normalizes handlers forms JSON and queries',static function(Context $t): void {
	$calls=[];
	$client=new HttpClient(['handler'=>static function(string $method,string $url,?string $body,array $headers,array $config) use (&$calls): array {
		$calls[]=compact('method','url','body','headers','config');
		return [];
	}]);
	$t->same(['status'=>0,'headers'=>[],'body'=>''],$client->send(' post ','https://oauth.test/token',['a'=>'x y'],[],['q'=>'a b']));
	$t->same('POST',$calls[0]['method']);
	$t->same('https://oauth.test/token?q=a%20b',$calls[0]['url']);
	$t->same('a=x%20y',$calls[0]['body']);
	$t->same('application/x-www-form-urlencoded',$calls[0]['headers']['Content-Type']);

	$client->send('GET','https://oauth.test/token?old=1',['slash'=>'a/b'],['content-type'=>'application/json'],['next'=>'yes']);
	$t->same('{"slash":"a/b"}',$calls[1]['body']);
	$t->same('https://oauth.test/token?old=1&next=yes',$calls[1]['url']);
	$client->send('GET','https://oauth.test/token',["bad"=>"\xB1"],['Content-Type'=>'application/json']);
	$t->same('{}',$calls[2]['body']);
	$client->send('PUT','https://oauth.test/token',['a'=>1],['X-Other'=>'v','Content-Type'=>'text/plain']);
	$t->same('a=1',$calls[3]['body']);
	$client->send('PATCH','https://oauth.test/token','raw',['X-Other'=>'v']);
	$t->same('raw',$calls[4]['body']);
	$client->send('DELETE','https://oauth.test/token',null,[],[0=>null]);
	$t->same(null,$calls[5]['body']);
	$t->same('https://oauth.test/token',$calls[5]['url']);

	$invalid=new HttpClient(['handler'=>static fn(): string=>'invalid']);
	$t->throws(static fn()=>$invalid->send('GET','https://oauth.test/fail'),Throwable::class);
})->tag('access','oauth','guards','deep-coverage')->group('framework-coverage');

test('access OAuth HTTP client covers deterministic curl and stream transports',static function(Context $t): void {
	$transport=$t->state('access.oauth.transport',[
		'curl_calls'=>[],'curl_init'=>true,'curl_body'=>'curl-ok','curl_status'=>207,
		'curl_error'=>'transport-error','stream_calls'=>[],'stream_body'=>'stream-body',
	]);
	$curl=new HttpClient(['timeout'=>0,'connect_timeout'=>0,'user_agent'=>' ']);
	$response=$curl->send('POST','https://oauth.test/curl',['a'=>1],['Accept'=>'custom/type']);
	$t->same(207,$response['status']);
	$t->same(['x-oauth-test'=>'yes'],$response['headers']);
	$t->same('curl-ok',$response['body']);
	$t->notEmpty($transport->get('curl_calls'));

	$transport->merge(['curl_init'=>true,'curl_body'=>'second','curl_status'=>200]);
	$t->same('second',(new HttpClient(['timeout'=>8,'user_agent'=>'Agent/2']))->send('GET','https://oauth.test/defaults')['body']);
	$transport->put('curl_init',false);
	$t->throws(static fn()=>(new HttpClient())->send('GET','https://oauth.test/init-fail'),Throwable::class);
	$transport->merge(['curl_init'=>true,'curl_body'=>false,'curl_status'=>0,'curl_error'=>'simulated']);
	$t->throws(static fn()=>(new HttpClient())->send('GET','https://oauth.test/request-fail'),Throwable::class);

	$transport->merge(['stream_calls'=>[],'stream_body'=>'stream-ok']);
	$stream=new HttpClient(['transport'=>'stream','timeout'=>0,'user_agent'=>'Stream/1']);
	$t->same(['status'=>0,'headers'=>[],'body'=>'stream-ok'],$stream->send('POST','https://oauth.test/stream','payload',['X-Test'=>'one']));
	$t->notEmpty($transport->get('stream_calls'));
	$transport->put('stream_body',false);
	$t->throws(static fn()=>$stream->send('GET','https://oauth.test/stream-fail'),Throwable::class);

	$streamInternals=$t->nonPublic($stream);
	$t->same([204,['x-one'=>'yes','x-two'=>'two:parts']],$streamInternals->invoke('parseResponseHeaders',['HTTP/1.1 204 No Content','X-One: yes','X-Two: two:parts']));
	$t->same([0,['x-three'=>'three']],$streamInternals->invoke('parseResponseHeaders',['not a status','no-colon','X-Three: three']));
})->tag('access','oauth','guards','deep-coverage')->group('framework-coverage');

test('JWKS resolver covers inline certificates RSA conversion and validation failures',static function(Context $t): void {
	$resolverInternals=$t->nonPublic(JwksResolver::class);
	$t->same([],JwksResolver::keys([]));
	$t->same([['kid'=>'one']],JwksResolver::keys(['jwks'=>['keys'=>[['kid'=>'one']]]]));
	$t->same([['kid'=>'raw']],JwksResolver::keys(['jwks'=>[['kid'=>'raw']]]));
	$t->throws(static fn()=>JwksResolver::resolve('RS256',[],[]),Throwable::class);

	$certificate=JwksResolver::resolve('RS256',['kid'=>'wanted'],['jwks'=>[
		'not-an-array',
		['kid'=>'wrong','alg'=>'RS256','x5c'=>['V1JPTkc=']],
		['kid'=>'wanted','alg'=>'RS512','x5c'=>['QUxHTw==']],
		['kid'=>'wanted','alg'=>'RS256','x5c'=>[" Q0VS\nVA== "]],
	]]);
	$t->contains('BEGIN CERTIFICATE',$certificate);
	$t->contains('Q0VSVA==',$certificate);
	$t->throws(static fn()=>JwksResolver::resolve('RS256',['kid'=>'absent'],['jwks'=>[['kid'=>'other','x5c'=>['QQ==']]]]),Throwable::class);
	$t->throws(static fn()=>$resolverInternals->invoke('publicKeyFromJwk',['kty'=>'EC']),Throwable::class);
	$t->throws(static fn()=>$resolverInternals->invoke('publicKeyFromJwk',['kty'=>'RSA','n'=>'abc']),Throwable::class);
	$t->throws(static fn()=>$resolverInternals->invoke('publicKeyFromJwk',['kty'=>'RSA','n'=>'%','e'=>'AQAB']),Throwable::class);

	$pem=JwksResolver::resolve('RS256',[],['jwks'=>[[
		'kty'=>'RSA','alg'=>'RS256','n'=>dp_access_owned_base64url("\x80\x01"),'e'=>dp_access_owned_base64url("\x01\x00\x01"),
	]]]);
	$t->contains('BEGIN PUBLIC KEY',$pem);
	$t->same("\x02\x01\x00",$resolverInternals->invoke('asn1Integer',''));
	$t->same("\x7f",$resolverInternals->invoke('asn1Length',127));
	$t->same("\x81\x80",$resolverInternals->invoke('asn1Length',128));
})->tag('access','oauth','jwks','deep-coverage')->group('framework-coverage');

test('JWKS resolver covers remote failures caching and kid refresh',static function(Context $t): void {
	$failure=['http'=>['handler'=>static fn(): array=>['status'=>503,'headers'=>[],'body'=>'down']]];
	$t->throws(static fn()=>JwksResolver::keys(['jwks_url'=>'https://jwks.test/status']+$failure),Throwable::class);
	$t->throws(static fn()=>JwksResolver::keys(['jwks_url'=>'https://jwks.test/json','http'=>['handler'=>static fn(): array=>['status'=>200,'headers'=>[],'body'=>'{']]]),Throwable::class);

	$flat=['jwks_url'=>'https://jwks.test/flat','http'=>['handler'=>static fn(): array=>['status'=>200,'headers'=>[],'body'=>'[{"kid":"flat"}]']]];
	$t->same([['kid'=>'flat']],JwksResolver::keys($flat));
	$t->same([['kid'=>'flat']],JwksResolver::keys($flat));
	$nested=['jwks_url'=>'https://jwks.test/nested','http'=>['handler'=>static fn(): array=>['status'=>200,'headers'=>[],'body'=>'{"keys":[{"kid":"nested"}]}']]];
	$t->same([['kid'=>'nested']],JwksResolver::keys($nested));

	$calls=0;
	$config=['jwks_url'=>'https://jwks.test/refresh','http'=>['handler'=>static function() use (&$calls): array {
		$calls++;
		$key=$calls===1
			? ['kid'=>'old','alg'=>'RS256','x5c'=>['T0xE']]
			: ['kid'=>'new','alg'=>'RS256','x5c'=>['TkVX']];
		return ['status'=>200,'headers'=>[],'body'=>json_encode(['keys'=>[$key]],JSON_THROW_ON_ERROR)];
	}]];
	$t->contains('TkVX',JwksResolver::resolve('RS256',['kid'=>'new'],$config));
	$t->same(2,$calls);
})->tag('access','oauth','jwks','deep-coverage')->group('framework-coverage');

test('access guard covers kernel state providers credentials and identifier adapters',static function(Context $t): void {
	dp_access_owned_reset_kernel();
	$provider=new DpAccessOwnedProvider();
	$guard=new AccessGuard('website',' ADMIN ',$provider);
	$t->same('website',$guard->name());
	$t->same('admin',$guard->authType());
	$t->isFalse($guard->check());
	$t->isTrue($guard->guest());
	\dataphyre\access::$userId=false;
	$t->same(null,$guard->id());
	\dataphyre\access::$userId=null;
	$t->same(null,$guard->id());
	\dataphyre\access::$userId='9';
	$t->same('9',$guard->id());

	$t->same(null,(new AccessGuard('none','user'))->user());
	$t->same(null,$guard->user());
	$t->same(null,$guard->user());
	\dataphyre\access::$loggedIn=true;
	$nullIdGuard=new AccessGuard('null-id','user',$provider);
	\dataphyre\access::$userId=null;
	$t->same(null,$nullIdGuard->user());
	\dataphyre\access::$userId=7;
	$provider->byId=['id'=>7,'version'=>1];
	$resolved=new AccessGuard('resolved','user',$provider);
	$t->same(['id'=>7,'version'=>1],$resolved->user());
	$t->same(['id'=>7,'version'=>1],$resolved->user());
	$t->same(1,$provider->byIdCalls);

	\dataphyre\access::$context=['auth_type'=>'user','logged_in'=>true,'userid'=>7,'id'=>'session-id','cookie_name'=>'auth'];
	$t->same('resolved',$resolved->context()->guardName());
	\dataphyre\access::$validateResult=true;
	$t->isTrue($resolved->validate(false));
	\dataphyre\access::$recoverResult=false;
	$t->isFalse($resolved->recover());
	\dataphyre\access::$recoverResult=true;
	$provider->byId=['id'=>7,'version'=>2];
	$t->isTrue($resolved->recover());
	$t->same(['id'=>7,'version'=>2],$resolved->user());

	$provider->identifier=null;
	$t->isFalse((new AccessGuard('login','user',$provider))->login(['id'=>7]));
	\dataphyre\access::$createResult=false;
	$provider->identifier='11';
	$t->isFalse((new AccessGuard('login','user',$provider))->login(['id'=>11],true));
	\dataphyre\access::$createResult=true;
	$loggedUser=['id'=>11];
	$loginGuard=new AccessGuard('login','user',$provider);
	$t->isTrue($loginGuard->login($loggedUser,true));
	$t->same($loggedUser,$loginGuard->user());
	$t->isFalse($loginGuard->loginUsingId('opaque'));
	$t->isTrue($loginGuard->loginUsingId(-12));
	$t->isTrue($loginGuard->loginUsingId('-13'));

	$fallback=new AccessGuard('fallback','user');
	$t->isTrue($fallback->login(new DpAccessOwnedAuthenticatable(21)));
	$t->isTrue($fallback->login(new class { public function authIdentifier(): int { return 22; } }));
	$t->isTrue($fallback->login(new class { public function getAuthIdentifier(): string { return '23'; } }));
	$t->isTrue($fallback->login(new class { public int $id=24; }));
	$t->isTrue($fallback->login(['id'=>'25']));
	$t->isTrue($fallback->login(26));
	$t->isFalse($fallback->login(new class { public float $id=1.2; }));
	$t->isFalse($fallback->login(['id'=>[]]));
	$t->isFalse($fallback->login(true));

	$t->isFalse((new AccessGuard('attempt','user'))->attempt(['email'=>'a']));
	$attemptProvider=new DpAccessOwnedProvider();
	$attempt=new AccessGuard('attempt','user',$attemptProvider);
	$attemptProvider->byCredentials=null;
	$t->isFalse($attempt->attempt(['email'=>'a']));
	$attemptProvider->byCredentials=false;
	$t->isFalse($attempt->attempt(['email'=>'a']));
	$attemptProvider->byCredentials=['id'=>31];
	$attemptProvider->credentialsValid=false;
	$t->isFalse($attempt->attempt(['email'=>'a']));
	$attemptProvider->credentialsValid=true;
	$attemptProvider->identifier=31;
	$t->isTrue($attempt->attempt(['email'=>'a'],true));

	\dataphyre\access::$disableResult=false;
	$t->isFalse($attempt->logout());
	\dataphyre\access::$disableResult=true;
	$t->isTrue($attempt->logout());
})->tag('access','guards','deep-coverage')->group('framework-coverage');

test('JWT guard covers resolvers headers payload users config overlays and stateless operations',static function(Context $t): void {
	dp_access_owned_reset_kernel();
	$server=$t->globalMap('_SERVER')->clear();
	$provider=new DpAccessOwnedProvider();
	$provider->byId=['id'=>'42'];
	$token=JwtCodec::encode(['uid'=>'42','sub'=>'unused','exp'=>1700000500],['secret'=>'named-secret','algorithms'=>['HS256']]);
	$resolverCalls=0;
	$guard=new JwtGuard('named',[
		'secret'=>'instance-secret','subject_claim'=>'uid','token_resolver'=>static function() use (&$resolverCalls,$token): string { $resolverCalls++; return ' '.$token.' '; },
		'driver'=>'jwt','provider'=>'users',
	],$provider);
	$t->same('named',$guard->name());
	$t->same('jwt',$guard->authType());
	$t->isTrue($guard->check());
	$t->isFalse($guard->guest());
	$t->same('42',$guard->id());
	$t->same(['id'=>'42'],$guard->user());
	$t->same(['id'=>'42'],$guard->user());
	$t->same(1,$provider->byIdCalls);
	$t->same('42',$guard->claims()['uid']);
	$t->same($token,$guard->token());
	\dataphyre\access::$context=['auth_type'=>'jwt','logged_in'=>true,'userid'=>'42'];
	$t->same('named',$guard->context()->guardName());
	$t->isTrue($guard->validate());
	$t->isTrue($guard->validate(false));
	$t->isTrue($guard->recover());
	$t->isFalse($guard->login(['id'=>42],true));
	$t->isFalse($guard->loginUsingId(42,true));
	$t->isFalse($guard->attempt(['token'=>'x'],true));
	$t->isFalse($guard->logout());
	$t->isTrue($resolverCalls>=2);

	$payloadGuard=new JwtGuard('plain',['secret'=>'named-secret','token_resolver'=>static fn(): string=>$token]);
	$t->instanceOf(JwtPayload::class,$payloadGuard->user());

	$missing=new JwtGuard('missing',['token_resolver'=>static fn(): mixed=>null]);
	$t->same(null,$missing->payload());
	$t->same(null,$missing->payload());
	$t->isFalse($missing->check());
	$t->isTrue($missing->guest());
	$t->same(null,$missing->id());
	$t->same(null,$missing->user());
	$t->same(null,$missing->user());
	$t->same([],$missing->claims());
	$t->same(null,$missing->token());

	$t->same(null,(new JwtGuard('invalid',['token_resolver'=>static fn(): string=>'not-a-jwt']))->payload());
	$nonScalarToken=JwtCodec::encode(['sub'=>['nested'=>true],'exp'=>1700000500],['secret'=>'global-secret','algorithms'=>['HS256']]);
	$t->same(null,(new JwtGuard('invalid',['token_resolver'=>static fn(): string=>$nonScalarToken],$provider))->user());

	$server->put('HTTP_AUTHORIZATION',' Bearer '.$token.' ');
	$headerGuard=new JwtGuard('header',['secret'=>'named-secret','token_resolver'=>static fn(): string=>'  ']);
	$t->same($token,$headerGuard->token());
	$server->forget('HTTP_AUTHORIZATION')->put('REDIRECT_HTTP_AUTHORIZATION','bearer '.$token);
	$t->same($token,(new JwtGuard('redirect',['secret'=>'named-secret']))->token());
	$server->replace(['HTTP_AUTHORIZATION'=>'Basic abc']);
	$t->same(null,(new JwtGuard('bad-header'))->token());
})->tag('access','jwt','guards','deep-coverage')->group('framework-coverage');
