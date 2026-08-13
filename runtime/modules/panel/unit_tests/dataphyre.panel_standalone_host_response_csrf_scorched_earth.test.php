<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\Response;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelContext;
use Dataphyre\Panel\PanelCsrfTokenBridge;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelStandaloneHostContext;
use Dataphyre\Panel\PanelStandaloneHostException;
use Dataphyre\Panel\PanelStandaloneHostResponseGuard;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['http','panel','routing','mvc']);

suite('Panel standalone host response and csrf boundary')
	->tag('panel','standalone','http','response','csrf','redirect','scorched-earth')
	->group('framework-coverage')
	->contract('panel.standalone.response-csrf',1)
	->risk('critical')
	->watches('module:panel','path:runtime/modules/panel/Framework/Http/PanelStandaloneHostResponseGuard.php')
	->through('header sanitization','stream preservation','head semantics','redirect containment','token issuance');

function dp_panel_standalone_response_surface(): PanelInstance {
	return new PanelInstance('standalone-response-'.bin2hex(random_bytes(4)),new PanelManager());
}

function dp_panel_standalone_response_context(
	string $method='GET',
	string $path='/panel/probe',
	array $segments=['probe'],
): PanelStandaloneHostContext {
	$request=Request::create($method,$path);
	return new PanelStandaloneHostContext(
		$request,
		dp_panel_standalone_response_surface(),
		'page',
		in_array($method,['GET','HEAD'],true)?'panel.page.read':'panel.page.mutate',
		'/panel',
		$segments,
		null,
		$method,
		!in_array($method,['GET','HEAD'],true),
		['id'=>9,'secret'=>'redacted'],
		'private-tenant',
		'response-request-9',
	);
}

test('standalone context is immutable typed and metadata-only when serialized',static function(Context $t): void {
	$context=dp_panel_standalone_response_context();
	$t->same('page',$context->routeKind());
	$t->same($context->panel()->name(),$context->jsonSerialize()['panel']);
	$t->same('panel.page.read',$context->ability());
	$t->same('/panel',$context->prefix());
	$t->same(['probe'],$context->segments());
	$t->same(null,$context->asset());
	$t->same('GET',$context->method());
	$t->isFalse($context->unsafe());
	$t->same('private-tenant',$context->tenant());
	$t->same(9,$context->user()['id']);
	$t->same('response-request-9',$context->requestId());
	$serialized=$context->jsonSerialize();
	$t->isTrue($serialized['user_present']);
	$t->isTrue($serialized['tenant_present']);
	$t->same(1,$serialized['segment_count']);
	$t->same(64,strlen($serialized['route_digest']));
	$t->isFalse(isset($serialized['segments']));
	$t->isFalse(isset($serialized['user']));
	$t->isFalse(isset($serialized['tenant']));
	$changed=$context->withUser('next-user')->withTenant('next-tenant')->withRequest(Request::create('GET','/panel/next'));
	$t->same('next-user',$changed->user());
	$t->same('next-tenant',$changed->tenant());
	$t->same('/panel/next',$changed->request()->path());
	$t->same('/panel/probe',$context->request()->path());
});

test('response guard strips transport headers preserves cookies and applies host policy',static function(Context $t): void {
	$context=dp_panel_standalone_response_context();
	$guard=new PanelStandaloneHostResponseGuard('/panel',[
		'Permissions-Policy'=>'camera=(), microphone=()',
	]);
	$response=new Response('body',200,[
		'Content-Type'=>'text/plain',
		'Connection'=>'keep-alive, X-Hop',
		'Keep-Alive'=>'timeout=5',
		'X-Hop'=>'remove-me',
		'X-End-To-End'=>'keep-me',
		'Set-Cookie'=>['first=1; HttpOnly','second=2; Secure'],
	]);
	$normalized=$guard->normalize($response,$context->request(),$context);
	$t->same(200,$normalized->status);
	$t->same('body',$normalized->body);
	$t->isFalse(isset($normalized->headers['Connection']));
	$t->isFalse(isset($normalized->headers['Keep-Alive']));
	$t->isFalse(isset($normalized->headers['X-Hop']));
	$t->same('keep-me',$normalized->headers['X-End-To-End']);
	$t->same(['first=1; HttpOnly','second=2; Secure'],$normalized->headers['Set-Cookie']);
	$t->same('no-store',$normalized->headers['Cache-Control']);
	$t->same('nosniff',$normalized->headers['X-Content-Type-Options']);
	$t->same('same-origin',$normalized->headers['Referrer-Policy']);
	$t->same('SAMEORIGIN',$normalized->headers['X-Frame-Options']);
	$t->same('camera=(), microphone=()',$normalized->headers['Permissions-Policy']);
	$t->same('response-request-9',$normalized->headers['X-Correlation-ID']);
	$t->same('standalone',$normalized->headers['X-Dataphyre-Panel-Host']);
	$t->same('body',$response->body);

	$asset=$guard->normalize(new Response('asset',200,['Cache-Control'=>'public, max-age=10']),$context->request(),$context,true);
	$t->same('public, max-age=10',$asset->headers['Cache-Control']);
});

test('response guard rejects invalid downstream status header values and names',static function(Context $t): void {
	$guard=new PanelStandaloneHostResponseGuard('/panel');
	$context=dp_panel_standalone_response_context();
	$t->throws(static fn()=>$guard->normalize(new Response('',199),$context->request(),$context),PanelStandaloneHostException::class);
	$t->throws(static fn()=>$guard->normalize(new Response('',200,["Bad Header"=>'x']),$context->request(),$context),PanelStandaloneHostException::class);
	$t->throws(static fn()=>$guard->normalize(new Response('',200,['X-Test'=>"bad\nvalue"]),$context->request(),$context),PanelStandaloneHostException::class);
	$t->throws(static fn()=>$guard->normalize(new Response('',200,['X-Test'=>new stdClass()]),$context->request(),$context),PanelStandaloneHostException::class);
	$badSecurity=new PanelStandaloneHostResponseGuard('/panel',["Bad Header"=>'x']);
	$t->throws(static fn()=>$badSecurity->normalize(new Response('ok'),$context->request(),$context),PanelStandaloneHostException::class);
	$page=$guard->normalize(PanelPageResult::html('panel result',202),$context->request(),$context);
	$t->same(202,$page->status);
	$t->same('panel result',$page->body);
	$scalar=$guard->normalize('scalar result',$context->request(),$context);
	$t->contains('scalar result',$scalar->body);
	$emptyHeader=$guard->normalize(new Response('empty',200,['X-Empty'=>[]]),$context->request(),$context);
	$t->isFalse(isset($emptyHeader->headers['X-Empty']));
	$canonical=$t->nonPublic($guard);
	$t->same('Content-MD5',$canonical->invoke('canonicalName','content-md5'));
	$t->same('ETag',$canonical->invoke('canonicalName','etag'));
	$t->same('TE',$canonical->invoke('canonicalName','te'));
	$t->same('WWW-Authenticate',$canonical->invoke('canonicalName','www-authenticate'));
});

test('response guard preserves streams and enforces head and no-content representation rules',static function(Context $t): void {
	$guard=new PanelStandaloneHostResponseGuard('/panel');
	$stream=fopen('php://temp','w+b');
	fwrite($stream,'streamed payload');
	rewind($stream);
	$response=Response::stream($stream,200,['Content-Type'=>'application/octet-stream']);
	$getContext=dp_panel_standalone_response_context();
	$get=$guard->normalize($response,$getContext->request(),$getContext);
	$t->isTrue($get->isStreamed());
	$t->same($stream,$get->stream);

	$headContext=dp_panel_standalone_response_context('HEAD');
	$head=$guard->normalize(new Response('head payload',200,['Content-Type'=>'text/plain']),$headContext->request(),$headContext);
	$t->same('',$head->body);
	$t->same((string)strlen('head payload'),$head->headers['Content-Length']);
	$headStream=$guard->normalize($response,Request::create('HEAD','/panel/probe'),$headContext);
	$t->isFalse($headStream->isStreamed());

	foreach([204,304] as $status){
		$empty=$guard->normalize(new Response('must disappear',$status,[
			'Content-Type'=>'text/plain','Content-Length'=>'14','Content-Disposition'=>'inline','ETag'=>'"abc"',
		]),$getContext->request(),$getContext);
		$t->same('',$empty->body);
		$t->isFalse(isset($empty->headers['Content-Type']));
		$t->isFalse(isset($empty->headers['Content-Length']));
		$t->isFalse(isset($empty->headers['Content-Disposition']));
		$t->same('"abc"',$empty->headers['ETag']);
	}
	fclose($stream);
});

test('response redirects are mount-contained unless an explicit safe policy approves them',static function(Context $t): void {
	$context=dp_panel_standalone_response_context();
	$guard=new PanelStandaloneHostResponseGuard('/panel');
	$inside=$guard->normalize(new Response('',303,['Location'=>'/panel/next']),$context->request(),$context);
	$t->same('/panel/next',$inside->headers['Location']);
	foreach(['/outside','https://example.test/next','javascript:alert(1)','//example.test/path','http://user:pass@example.test/path',"/panel/bad\nheader"] as $target){
		$t->throws(static fn()=>$guard->normalize(new Response('',303,['Location'=>$target]),$context->request(),$context),PanelStandaloneHostException::class,$target);
	}
	$allow=new PanelStandaloneHostResponseGuard('/panel',[],static fn(string $target): bool=>str_starts_with($target,'https://trusted.example/'));
	$external=$allow->normalize(new Response('',302,['Location'=>'https://trusted.example/next']),$context->request(),$context);
	$t->same('https://trusted.example/next',$external->headers['Location']);
	$t->throws(static fn()=>$allow->normalize(new Response('',302,['Location'=>'https://other.example/']),$context->request(),$context),PanelStandaloneHostException::class);
	$throwing=new PanelStandaloneHostResponseGuard('/panel',[],static fn()=>throw new RuntimeException('redirect policy'));
	$t->throws(static fn()=>$throwing->normalize(new Response('',302,['Location'=>'/outside']),$context->request(),$context),PanelStandaloneHostException::class);
});

test('csrf token bridge caches per request and fails closed when a host issuer is invalid',static function(Context $t): void {
	$context=dp_panel_standalone_response_context();
	$calls=0;
	$tokens=PanelContext::run([
		PanelCsrfTokenBridge::HOST_CONTEXT=>$context,
		PanelCsrfTokenBridge::ISSUER_CONTEXT=>static function(string $scope) use (&$calls): string {
			$calls++;
			return $scope.'-token';
		},
		PanelCsrfTokenBridge::CACHE_CONTEXT=>new ArrayObject(),
	],static fn(): array=>[
		PanelCsrfTokenBridge::formToken(),
		PanelCsrfTokenBridge::formToken(),
		PanelCsrfTokenBridge::uploadToken(),
		PanelCsrfTokenBridge::formInput(),
	]);
	$t->same('panel-token',$tokens[0]);
	$t->same($tokens[0],$tokens[1]);
	$t->same('dp_panel_upload-token',$tokens[2]);
	$t->contains('name="_token"',$tokens[3]);
	$t->same(2,$calls);

	$invalid=PanelContext::run([
		PanelCsrfTokenBridge::ISSUER_CONTEXT=>static fn(): array=>['not-a-token'],
		PanelCsrfTokenBridge::CACHE_CONTEXT=>new ArrayObject(),
	],static fn(): string=>PanelCsrfTokenBridge::formToken());
	$t->same('',$invalid);
	$throwing=PanelContext::run([
		PanelCsrfTokenBridge::ISSUER_CONTEXT=>static fn()=>throw new RuntimeException('issuer'),
	],static fn(): string=>PanelCsrfTokenBridge::formToken());
	$t->same('',$throwing);
	$missing=PanelContext::run([
		PanelCsrfTokenBridge::ISSUER_CONTEXT=>null,
	],static fn(): string=>PanelCsrfTokenBridge::formToken());
	$t->same('',$missing);
	$t->same('',PanelCsrfTokenBridge::formToken(''));

	$fallback=PanelContext::run([
		PanelCsrfTokenBridge::FALLBACK_CONTEXT=>static fn(string $scope,bool $upload): string=>($upload?'upload:':'form:').$scope,
		PanelCsrfTokenBridge::CACHE_CONTEXT=>new ArrayObject(),
	],static fn(): array=>[
		PanelCsrfTokenBridge::formToken('fallback-form'),
		PanelCsrfTokenBridge::uploadToken('fallback-upload'),
	]);
	$t->same('form:fallback-form',$fallback[0]);
	$t->same('upload:fallback-upload',$fallback[1]);
	$failedFallback=PanelContext::run([
		PanelCsrfTokenBridge::FALLBACK_CONTEXT=>static fn()=>throw new RuntimeException('fallback failed'),
	],static fn(): string=>PanelCsrfTokenBridge::formToken('fallback-throws'));
	$t->same('',$failedFallback);
	$t->isTrue(is_string(PanelCsrfTokenBridge::formToken('mvc-default')));
	$t->isTrue(is_string(PanelCsrfTokenBridge::uploadToken('core-default')));
});

test('standalone host contains internal failures and supports built-in router pass-through',static function(Context $t): void {
	$panel=dp_panel_standalone_response_surface();
	$panel->registerPage($panel->page('explode')->content(static fn()=>throw new RuntimeException('database-password-secret')));
	$host=Panel::standaloneHost($panel,'/panel')
		->authenticateUsing(static fn(): array=>['id'=>1])
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true);
	$production=$host->handle(Request::create('GET','/panel/explode'));
	$t->same(500,$production->status);
	$t->notContains('database-password-secret',$production->body);
	$t->same('no-store',$production->headers['Cache-Control']);
	$development=$host->developmentErrors()->handle(Request::create('GET','/panel/explode'));
	$t->same(500,$development->status);
	$t->contains('RuntimeException',$development->body);
	$t->isFalse($host->serve(Request::create('GET','/outside')));
	$output=$t->captureOutput(static fn(): \Dataphyre\Http\Response=>$host->emit(Request::create('GET','/panel/missing')));
	$t->contains('Page not found',$output->output());
});

test('standalone host supports deterministic capture adapters and bounded sapi capture',static function(Context $t): void {
	$named=\Dataphyre\Panel\PanelStandaloneHost::surface('standalone-captured','/captured');
	$default=\Dataphyre\Panel\PanelStandaloneHost::surface(null,'/');
	$t->same('standalone-captured',$named->panel()->name());
	$t->same('default',$default->panel()->name());
	$t->isTrue($named->captureUsing(static fn(): Request=>Request::create('GET','/captured'))->manifest()['capabilities']['custom_request_capture']);
	$t->isTrue($named->redirectUsing(static fn(): bool=>true)->manifest()['capabilities']['external_redirect_policy']);
	$t->same(404,$named->handle(Request::create('GET','/outside'))->status);

	$host=Panel::standaloneHost(dp_panel_standalone_response_surface(),'/panel');
	$private=$t->nonPublic($host);
	$captured=$private->invoke('capture',[
		'server'=>[
			'REQUEST_METHOD'=>'POST',
			'REQUEST_URI'=>'/panel/probe?ignored=1',
			'CONTENT_TYPE'=>'application/json',
			'CONTENT_LENGTH'=>'12',
			'HTTP_X_TEST'=>'yes',
			'REDIRECT_HTTP_AUTHORIZATION'=>'Bearer fallback',
			0=>'ignored',
		],
		'query'=>['page'=>2],
		'cookies'=>['session'=>'cookie'],
		'raw_body'=>'{"ok":true}',
	]);
	$t->same('POST',$captured->method());
	$t->same('/panel/probe',$captured->path());
	$t->same(true,$captured->input('ok'));
	$t->same(2,$captured->query('page'));
	$t->same('cookie',$captured->cookie('session'));
	$t->same('yes',$captured->header('X-Test'));
	$t->same('Bearer fallback',$captured->header('Authorization'));

	$reader=$private->invoke('capture',[
		'server'=>['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/panel/probe','CONTENT_TYPE'=>'application/problem+json'],
		'body_reader'=>static fn(int $limit): string=>'{"limit":'.$limit.'}',
	]);
	$t->same($host->manifest()['limits']['max_body_bytes']+1,$reader->input('limit'));
	$post=$private->invoke('capture',[
		'server'=>['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/panel/probe','CONTENT_TYPE'=>'application/json'],
		'post'=>['already'=>'parsed'],
		'raw_body'=>'{"ignored":true}',
	]);
	$t->same('parsed',$post->input('already'));
	$emptyJson=$private->invoke('capture',[
		'server'=>['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/panel/probe','CONTENT_TYPE'=>'application/json'],
	]);
	$t->same([],$emptyJson->input());

	foreach([
		['server'=>['CONTENT_LENGTH'=>'bad']],
		['server'=>['CONTENT_TYPE'=>'application/json'],'raw_body'=>new stdClass()],
		['server'=>['CONTENT_TYPE'=>'application/json'],'raw_body'=>'{bad'],
		['server'=>['CONTENT_TYPE'=>'application/json'],'raw_body'=>'true'],
	] as $runtime){
		$t->throws(static fn()=>$private->invoke('capture',$runtime),PanelStandaloneHostException::class);
	}
	$small=Panel::standaloneHost(dp_panel_standalone_response_surface(),'/panel')->withLimits(['max_body_bytes'=>4]);
	$t->throws(static fn()=>$t->nonPublic($small)->invoke('capture',[
		'server'=>['CONTENT_TYPE'=>'application/json'],
		'raw_body'=>'{"large":true}',
	]),PanelStandaloneHostException::class);

	$headers=$private->invoke('captureHeaders',[
		'HTTP_AUTHORIZATION'=>'Bearer direct',
		'CONTENT_TYPE'=>'text/plain',
		'CONTENT_LENGTH'=>'3',
		0=>'ignored',
	]);
	$t->same('Bearer direct',$headers['authorization']);
	$t->same('text/plain',$headers['content_type']);
	$t->same('3',$headers['content_length']);
	$t->same([], $private->invoke('safeErrorHeaders',["Bad Header"=>'x',"X-Bad"=>"bad\nvalue"]));

	$invalidCapture=$host->captureUsing(static fn(): string=>'not a request');
	$invalidOutput=$t->captureOutput(static fn(): Response=>$invalidCapture->emit());
	$t->contains('invalid_captured_request',$invalidOutput->output());
	$boundedFailure=$host->captureUsing(static fn()=>throw new PanelStandaloneHostException('capture_rejected',413,'Capture rejected.'));
	$boundedOutput=$t->captureOutput(static fn(): Response=>$boundedFailure->emit());
	$t->contains('capture_rejected',$boundedOutput->output());
	$unexpected=$host->captureUsing(static fn()=>throw new RuntimeException('capture exploded'));
	$unexpectedOutput=$t->captureOutput(static fn(): Response=>$unexpected->emit());
	$t->contains('internal_error',$unexpectedOutput->output());

	$server=$t->globalMap('_SERVER')->replace(['REQUEST_URI'=>'/outside','REQUEST_METHOD'=>'GET']);
	$t->isFalse($host->serve());
	$server->replace(['REQUEST_URI'=>'/panel/missing','REQUEST_METHOD'=>'GET']);
	$capturing=$host
		->allowAnonymous()
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true)
		->captureUsing(static fn(): Request=>Request::create('GET','/panel/missing'));
	$served=null;
	$servedOutput=$t->captureOutput(static function() use ($capturing,&$served): void {
		$served=$capturing->serve();
	});
	$t->isTrue($served);
	$t->contains('Page not found',$servedOutput->output());
});
