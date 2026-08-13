<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelCsrfTokenBridge;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelStandaloneHost;
use Dataphyre\Panel\PanelStandaloneHostContext;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['http','panel','routing','mvc','storage']);

suite('Panel standalone host security policies')
	->tag('panel','standalone','http','security','csrf','authorization','scorched-earth')
	->group('framework-coverage')
	->contract('panel.standalone.security',1)
	->risk('critical')
	->watches('module:panel','path:runtime/modules/panel/Framework/Security/PanelCsrfTokenBridge.php')
	->through('authentication','authorization','rate limiting','origin','csrf','tenant isolation','upload gating');

function dp_panel_standalone_security_surface(mixed $content='secure panel'): PanelInstance {
	$panel=new PanelInstance('standalone-security-'.bin2hex(random_bytes(4)),new PanelManager());
	$panel->registerPage($panel->page('secure')->content($content));
	return $panel;
}

/** @return array<string,mixed> */
function dp_panel_standalone_security_error(\Dataphyre\Http\Response $response): array {
	return json_decode($response->body,true,512,JSON_THROW_ON_ERROR);
}

function dp_panel_standalone_security_host(?PanelInstance $panel=null): PanelStandaloneHost {
	return Panel::standaloneHost($panel ?? dp_panel_standalone_security_surface(),'/panel')
		->authenticateUsing(static fn(PanelStandaloneHostContext $context): array=>[
			'id'=>17,
			'request_id'=>$context->requestId(),
		])
		->authorizeUsing(static fn(string $ability): bool=>str_starts_with($ability,'panel.'))
		->rateLimitUsing(static fn(): bool=>true);
}

function dp_panel_standalone_mutation_request(array $body=['_token'=>'good-token'],string $path='/panel/secure'): Request {
	return Request::create('POST',$path,[],$body,[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded',
		'Origin'=>'https://panel.example.test',
	]);
}

test('standalone page policies fail closed for missing denied and throwing callbacks',static function(Context $t): void {
	$panel=dp_panel_standalone_security_surface();
	$request=Request::create('GET','/panel/secure',[],[],[],[],['X-Request-ID'=>'request-17']);

	$missingAuth=Panel::standaloneHost($panel,'/panel')
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(503,$missingAuth->status);
	$t->same('authentication_unavailable',dp_panel_standalone_security_error($missingAuth)['error']['code']);
	$t->same('request-17',$missingAuth->headers['X-Correlation-ID']);

	$unauthenticated=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): null=>null)
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(401,$unauthenticated->status);

	$authThrow=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn()=>throw new RuntimeException('secret authentication failure'))
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(503,$authThrow->status);
	$t->notContains('secret authentication failure',$authThrow->body);

	$missingAuthorization=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(503,$missingAuthorization->status);

	$forbidden=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn(): bool=>false)
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(403,$forbidden->status);

	$authorizationThrow=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn()=>throw new RuntimeException('authorization offline'))
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(503,$authorizationThrow->status);

	$missingRate=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(503,$missingRate->status);

	$limited=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): array=>['allowed'=>false,'retry_after'=>17])
		->handle($request);
	$t->same(429,$limited->status);
	$t->same('17',$limited->headers['Retry-After']);

	$rateThrow=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn()=>throw new RuntimeException('rate backend offline'))
		->handle($request);
	$t->same(503,$rateThrow->status);

	$anonymous=Panel::standaloneHost($panel,'/panel')
		->allowAnonymous()
		->authorizeUsing(static fn(PanelStandaloneHostContext $context): bool=>$context->user()===null)
		->rateLimitUsing(static fn(): bool=>true)
		->handle($request);
	$t->same(200,$anonymous->status);
});

test('standalone tenant resolver owns identity and invalid resolver output fails closed',static function(Context $t): void {
	$seen=null;
	$panel=dp_panel_standalone_security_surface(static function(\Dataphyre\Panel\PanelRequest $request) use (&$seen): string {
		$seen=$request->tenant();
		return 'tenant='.$seen;
	});
	$host=dp_panel_standalone_security_host($panel)->tenantUsing(
		static fn(PanelStandaloneHostContext $context): string=>$context->request()->header('X-Trusted-Workspace')==='north' ? 'north' : 'default'
	);
	$response=$host->handle(Request::create('GET','/panel/secure',['tenant'=>'attacker'],[],[],[],[
		'X-Panel-Tenant'=>'attacker',
		'X-Trusted-Workspace'=>'north',
	]));
	$t->same(200,$response->status);
	$t->same('north',$seen);

	$invalid=dp_panel_standalone_security_host($panel)
		->tenantUsing(static fn(): array=>['invalid'])
		->handle(Request::create('GET','/panel/secure'));
	$t->same(503,$invalid->status);
	$t->same('invalid_tenant',dp_panel_standalone_security_error($invalid)['error']['code']);

	$throwing=dp_panel_standalone_security_host($panel)
		->tenantUsing(static fn()=>throw new RuntimeException('tenant backend'))
		->handle(Request::create('GET','/panel/secure'));
	$t->same(503,$throwing->status);
});

test('mutation-capable hosts require complete origin and csrf policy even on safe renders',static function(Context $t): void {
	$panel=dp_panel_standalone_security_surface();
	$incomplete=dp_panel_standalone_security_host($panel)->allowMutations();
	$response=$incomplete->handle(Request::create('GET','/panel/secure'));
	$t->same(503,$response->status);
	$t->same('mutation_security_unavailable',dp_panel_standalone_security_error($response)['error']['code']);
	$t->contains('origin',$incomplete->manifest()['security']['missing']);
	$t->contains('csrf_issuer',$incomplete->manifest()['security']['missing']);
	$t->contains('csrf_validator',$incomplete->manifest()['security']['missing']);

	$readOnly=dp_panel_standalone_security_host($panel);
	$t->same(405,$readOnly->handle(dp_panel_standalone_mutation_request())->status);
	$t->same('GET, HEAD',$readOnly->handle(dp_panel_standalone_mutation_request())->headers['Allow']);
});

test('standalone origin and csrf validation reject every unsafe failure mode',static function(Context $t): void {
	$panel=dp_panel_standalone_security_surface();
	$base=dp_panel_standalone_security_host($panel)
		->allowMutations()
		->originUsing(static fn(string $origin): bool=>$origin==='https://panel.example.test')
		->csrfUsing(static fn(): string=>'good-token',static fn(string $token,string $scope): bool=>$token==='good-token'&&$scope==='panel');

	$missingOrigin=$base->handle(Request::create('POST','/panel/secure',[],['_token'=>'good-token'],[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded',
	]));
	$t->same(403,$missingOrigin->status);
	$t->same('invalid_origin',dp_panel_standalone_security_error($missingOrigin)['error']['code']);

	$malformedOrigin=$base->handle(Request::create('POST','/panel/secure',[],['_token'=>'good-token'],[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded','Origin'=>'null',
	]));
	$t->same(403,$malformedOrigin->status);

	$deniedOrigin=$base->handle(Request::create('POST','/panel/secure',[],['_token'=>'good-token'],[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded','Origin'=>'https://evil.example.test',
	]));
	$t->same(403,$deniedOrigin->status);

	$originThrow=$base
		->originUsing(static fn()=>throw new RuntimeException('origin backend'))
		->handle(dp_panel_standalone_mutation_request());
	$t->same(503,$originThrow->status);

	$missingCsrf=$base->handle(dp_panel_standalone_mutation_request([]));
	$t->same(419,$missingCsrf->status);
	$t->same('csrf_failed',dp_panel_standalone_security_error($missingCsrf)['error']['code']);

	$deniedCsrf=$base->handle(dp_panel_standalone_mutation_request(['_token'=>'wrong']));
	$t->same(419,$deniedCsrf->status);

	$csrfThrow=$base
		->csrfUsing(static fn(): string=>'good-token',static fn()=>throw new RuntimeException('csrf backend'))
		->handle(dp_panel_standalone_mutation_request());
	$t->same(503,$csrfThrow->status);

	$valid=$base->handle(dp_panel_standalone_mutation_request());
	$t->isFalse(in_array($valid->status,[403,419,503],true));
});

test('standalone csrf issuer is request-scoped cached and feeds rendered form fields',static function(Context $t): void {
	$issued=0;
	$panel=dp_panel_standalone_security_surface(static function() use (&$issued): string {
		return PanelCsrfTokenBridge::formInput().PanelCsrfTokenBridge::formInput();
	});
	$host=dp_panel_standalone_security_host($panel)
		->allowMutations()
		->originUsing(static fn(): bool=>true)
		->csrfUsing(static function() use (&$issued): string {
			$issued++;
			return 'issued-token';
		},static fn(): bool=>true);
	$first=$host->handle(Request::create('GET','/panel/secure'));
	$t->same(200,$first->status);
	$t->same(1,$issued);
	$t->same(2,substr_count($first->body,'value="issued-token"'));
	$second=$host->handle(Request::create('GET','/panel/secure'));
	$t->same(2,$issued);
	$t->same(200,$second->status);

	$blank=$host
		->csrfUsing(static fn(): string=>"\n",static fn(): bool=>true)
		->handle(Request::create('GET','/panel/secure'));
	$t->notContains('name="_token"',$blank->body);
});

test('standalone upload route is separately opt-in and reuses outer csrf validation',static function(Context $t): void {
	$panel=dp_panel_standalone_security_surface();
	$disabled=dp_panel_standalone_security_host($panel);
	$t->same(404,$disabled->handle(Request::create('POST','/panel/upload'))->status);

	$host=dp_panel_standalone_security_host($panel)
		->allowUploads()
		->originUsing(static fn(string $origin): bool=>$origin==='https://panel.example.test')
		->csrfUsing(static fn(string $scope): string=>$scope==='dp_panel_upload'?'upload-token':'form-token',
			static fn(string $token,string $scope): bool=>$token==='upload-token'&&$scope==='dp_panel_upload');
	$t->same(405,$host->handle(Request::create('GET','/panel/upload'))->status);
	$missing=$host->handle(Request::create('POST','/panel/upload',[],['upload_id'=>'missing'],[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded','Origin'=>'https://panel.example.test',
	]));
	$t->same(419,$missing->status);
	$valid=$host->handle(Request::create('POST','/panel/upload',[],[
		'upload_id'=>'missing','csrf'=>'upload-token',
	],[],[],[
		'Content-Type'=>'application/x-www-form-urlencoded','Origin'=>'https://panel.example.test',
	]));
	$t->same(422,$valid->status);
	$t->contains('application/json',(string)$valid->headers['Content-Type']);
});

test('standalone policies cover optional tenant anonymous authentication and options contracts',static function(Context $t): void {
	$panel=dp_panel_standalone_security_surface();
	$base=dp_panel_standalone_security_host($panel);
	$t->same(200,$base->tenantUsing(static fn(): null=>null)->handle(Request::create('GET','/panel/secure'))->status);
	$t->same(503,$base->tenantUsing(static fn(): string=>"bad\nTenant")->handle(Request::create('GET','/panel/secure'))->status);
	$anonymous=$base
		->allowAnonymous()
		->authenticateUsing(static fn(): null=>null)
		->authorizeUsing(static fn(PanelStandaloneHostContext $context): bool=>$context->user()===null);
	$t->same(200,$anonymous->handle(Request::create('GET','/panel/secure'))->status);
	$readOptions=$base->handle(Request::create('OPTIONS','/panel/secure'));
	$t->same(405,$readOptions->status);
	$t->same('GET, HEAD',$readOptions->headers['Allow']);
	$write=$base
		->allowMutations()
		->originUsing(static fn(): bool=>true)
		->csrfUsing(static fn(): string=>'token',static fn(): bool=>true);
	$writeOptions=$write->handle(Request::create('OPTIONS','/panel/secure'));
	$t->same(405,$writeOptions->status);
	$t->same('GET, HEAD, POST, PUT, PATCH, DELETE',$writeOptions->headers['Allow']);
});
