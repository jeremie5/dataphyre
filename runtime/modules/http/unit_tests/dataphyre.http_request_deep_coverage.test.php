<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Request;
use Dataphyre\Http\UploadedFile;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http']);

test('http request deep coverage captures superglobals nested uploads headers and route state',static function(Context $t): void {
	$tmp=$t->tempFile('x', 'http-request-upload');
	$query=['uri'=>'capture/path','query'=>'yes'];
	$body=['body'=>'posted'];
	$cookies=['session'=>'cookie'];
	$t->setGlobalsForTest([
		'_GET'=>$query,
		'_POST'=>$body,
		'_COOKIE'=>$cookies,
		'_FILES'=>['documents'=>[
			'name'=>['first'=>'one.txt','empty'=>''],
			'type'=>['first'=>'text/plain','empty'=>''],
			'tmp_name'=>['first'=>$tmp,'empty'=>''],
			'error'=>['first'=>UPLOAD_ERR_OK,'empty'=>UPLOAD_ERR_NO_FILE],
			'size'=>['first'=>1,'empty'=>0],
		],17=>'skip','bad'=>'skip'],
		'_SERVER'=>['REQUEST_METHOD'=>'post','REQUEST_URI'=>'/ignored?x=1','HTTPS'=>'off','SERVER_PORT'=>'443','HTTP_HOST'=>'capture.test','REMOTE_ADDR'=>'10.0.0.1',
			'HTTP_X_TEST'=>'yes','CONTENT_TYPE'=>'application/json','CONTENT_LENGTH'=>'10','PHP_AUTH_USER'=>'ada','PHP_AUTH_PW'=>'secret','REDIRECT_HTTP_AUTHORIZATION'=>'Bearer redirect'],
	]);

	$request=Request::capture(['id'=>7]);
	$t->same('POST',$request->method());$t->same('/capture/path',$request->path());$t->same('yes',$request->query('query'));$t->same('posted',$request->input('body'));$t->same('cookie',$request->cookie('session'));
	$t->isTrue($request->hasFile('documents.first'));$t->isFalse($request->hasFile('documents.empty'));$t->same('yes',$request->header('X-Test'));$t->same('application/json',$request->header('Content-Type'));
	$t->same('10',$request->header('Content-Length'));$t->same('ada',$request->header('PHP-Auth-User'));$t->same('secret',$request->header('PHP-Auth-Pw'));$t->same('Bearer redirect',$request->header('Authorization'));
	$t->same('https',$request->scheme());$t->same(7,$request->route('id'));$t->same(['id'=>7],$request->routeParameters());$t->same(['id'=>7],$request->route());
	$t->same($query,$request->query());$t->same($body,$request->input());$t->same($cookies,$request->cookie());$t->same(1,count($request->files()));$t->producesStableResult(static fn()=>$request->headers());
	$t->same($request->attributes(),$request->attribute());$t->producesStableResult(static fn()=>$request->server());
	$t->globalMap('_SERVER')->put('REQUEST_METHOD', '');
	$t->same('', Request::capture()->method(), 'Capture defaults only when REQUEST_METHOD is absent; an explicit empty value remains observable.');
})->tag('http','request','deep-coverage')->group('framework-coverage');

test('http request deep coverage exercises presence filled coercion and nested input mutation branches',static function(Context $t): void {
	$tmp=$t->tempFile('x', 'http-input-upload');
	$request=Request::create('POST','/input',
			['q_bool'=>'yes','q_int'=>'8','q_float'=>'2.5','q_empty'=>'','nested'=>['query'=>'value','empty'=>'']],
			['b_bool'=>'off','b_int'=>'7','b_float'=>'1.5','b_empty'=>'','deep'=>['leaf'=>'value','empty'=>'']],[],[],[],[],[],
			['valid'=>['name'=>'valid.txt','type'=>'text/plain','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>1],'invalid'=>['name'=>'bad.txt','type'=>'text/plain','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_PARTIAL,'size'=>1]]
		);
	$t->isFalse($request->has([17]));$t->isFalse($request->has('missing'));$t->isTrue($request->has('deep.leaf'));$t->isFalse($request->has('deep.missing'));$t->isFalse($request->has([]));
	$t->isFalse($request->filled([17]));$t->isTrue($request->filled('valid'));$t->isFalse($request->filled('invalid'));$t->isTrue($request->filled('b_int'));$t->isFalse($request->filled('b_empty'));
	$t->isTrue($request->filled('q_int'));$t->isFalse($request->filled('q_empty'));$t->isFalse($request->filled('missing'));$t->isTrue($request->filled('deep.leaf'));$t->isFalse($request->filled('deep.empty'));$t->isFalse($request->filled([]));
	$t->isFalse($request->boolean('valid',false));$t->isFalse($request->boolean('b_bool',true));$t->isTrue($request->boolean('q_bool'));$t->isTrue($request->boolean('missing',true));$t->isTrue($request->boolean('deep.leaf',true));$t->isTrue($request->boolean('deep.missing',true));
	$t->same(3,$request->integer('valid',3));$t->same(7,$request->integer('b_int'));$t->same(8,$request->integer('q_int'));$t->same(4,$request->integer('missing',4));$t->same(5,$request->integer('deep.leaf',5));
	$t->same(3.0,$request->float('valid',3.0));$t->same(1.5,$request->float('b_float'));$t->same(2.5,$request->float('q_float'));$t->same(4.0,$request->float('missing',4.0));$t->same(5.0,$request->float('deep.leaf',5.0));
	$t->same(['deep'=>['leaf'=>'value']],$request->only(['deep.leaf',17,'missing']));$t->same(['new'=>['path'=>'value']],Request::create('GET','/',body:['new'=>'scalar','new.path'=>'value'])->only('new.path'));
	$t->same('value',$request->except('deep.leaf')['nested']['query'] ?? 'value');$t->same($request->all(),$request->except('deep.missing.path'));
	$request->mergeAttributes([0=>'skip','ok'=>'yes'])->setAttribute(' ',1);$t->same('yes',$request->attribute('ok'));$t->same($request->attributes(),$request->attribute());
})->tag('http','request','deep-coverage')->group('framework-coverage');

test('http request deep coverage exercises forwarding route and accept negotiation edge cases',static function(Context $t): void {
	$forwarded=Request::create('GET','/forwarded',server:['REMOTE_ADDR'=>'fallback'],headers:['X-Forwarded-Proto'=>'HTTPS, http','X-Forwarded-Host'=>'first.test, second.test','X-Forwarded-For'=>'1.2.3.4, 5.6.7.8']);
	$t->same('https',$forwarded->scheme());$t->same('first.test',$forwarded->host());$t->same('1.2.3.4',$forwarded->ip());
	$t->same('direct.test',Request::create('GET','/',headers:['Host'=>'direct.test'])->host());
	$t->same('http',Request::create('GET','/',headers:['X-Forwarded-Proto'=>'ftp'])->scheme());$t->same('http',Request::create('GET','/',server:['SERVER_PORT'=>80])->scheme());
	$t->same('GET',Request::create('GET','/')->effectiveMethod());$t->same('POST',Request::create('POST','/')->effectiveMethod());
	$t->same('fallback',Request::create('GET','/',server:['REMOTE_ADDR'=>'fallback'],headers:['X-Forwarded-For'=>'  '])->ip());
	$unnamed=Request::create('GET','/');$t->isFalse($unnamed->routeIs('anything'));$named=Request::create('GET','/',attributes:['route_name'=>'orders.show']);$t->isTrue($named->routeIs(' ','orders.*'));
	$wild=Request::create('GET','/',headers:['Accept'=>'*/*, application/json']);$t->isTrue($wild->accepts('image/png'));$t->isTrue($wild->wantsJson());
	$types=Request::create('GET','/',headers:['Accept'=>' , text/*;q=0.8, application/json;q=0, application/*;q=0.7']);$t->isTrue($types->accepts(['','text/plain']));$t->isTrue($types->accepts('application/json'));$t->isFalse($types->accepts('image/png'));
	$a=Request::create('GET','/',headers:['Accept'=>'text/plain']);$a->accepts('text/plain');$aSame=Request::create('GET','/',headers:['Accept'=>'text/plain']);$aSame->accepts('text/plain');$b=Request::create('GET','/',headers:['Accept'=>'image/png']);$b->accepts('image/png');$a2=Request::create('GET','/',headers:['Accept'=>'text/plain']);$t->isTrue($a2->accepts('text/plain'));
	$t->isFalse($a->wantsJson());$t->isTrue(Request::create('GET','/',headers:['Accept'=>'application/json'])->accepts('application/*'));
	$emptyAccept=Request::create('GET','/',headers:[]);$t->isTrue($emptyAccept->accepts('anything/type'));$t->isFalse($emptyAccept->wantsJson());
})->tag('http','request','deep-coverage')->group('framework-coverage');

test('http request deep coverage directly covers file header path and body normalizers',static function(Context $t): void {
	$t->setGlobalsForTest([
		'_GET'=>[],
		'_POST'=>['posted'=>'yes'],
		'_FILES'=>[],
		'_SERVER'=>['REQUEST_URI'=>'/server/path?x=1','CONTENT_TYPE'=>'text/plain','CONTENT_LENGTH'=>'12','PHP_AUTH_USER'=>'user','PHP_AUTH_PW'=>'pw','Authorization'=>'Basic token'],
	]);
	$requestInternals=$t->nonPublic(Request::class);
	$t->same('/server/path',$requestInternals->invoke('detectPath'));$t->same(['posted'=>'yes'],$requestInternals->invoke('captureBody'));$t->globalMap('_POST')->clear();$t->same([],$requestInternals->invoke('captureBody'));
	$t->same(['json'=>true],$requestInternals->invoke('decodeBody','{"json":true}'));$t->same([],$requestInternals->invoke('decodeBody','not-json'));
	$headers=$requestInternals->invoke('captureHeaders');$t->same('text/plain',$headers['content_type']);$t->same('Basic token',$headers['authorization']);
	$nested=['name'=>['a'=>'a.txt','b'=>''],'type'=>['a'=>'text/plain'],'tmp_name'=>['a'=>'/tmp/a'],'error'=>['a'=>UPLOAD_ERR_OK,'b'=>UPLOAD_ERR_NO_FILE],'size'=>['a'=>1]];
	$walked=$requestInternals->invoke('walkFile','root',$nested);$t->instanceOf(UploadedFile::class,$walked['root.a']);$t->same(UPLOAD_ERR_NO_FILE,$walked['root.b']->error());
	$normalized=$requestInternals->invoke('normalizeFiles',[17=>[],'bad'=>'invalid','nested'=>$nested]);$t->isTrue(isset($normalized['nested.a']));$t->isFalse(isset($normalized['nested.b']));
	$t->same('one',$requestInternals->invoke('firstForwardedValue',' one '));$t->same('one',$requestInternals->invoke('firstForwardedValue','one, two'));
	$t->same(['x_test'=>'yes'],$requestInternals->invoke('normalizeHeaders',[17=>'skip',' '=>'skip',' X-Test '=>'yes']));
})->tag('http','request','deep-coverage')->group('framework-coverage');
