<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Http\Request;
use Dataphyre\Http\UploadedFile;
use Dataphyre\Panel\Panel;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelStandaloneHost;
use Dataphyre\Panel\PanelStandaloneHostRequestGuard;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

framework(['http','panel','routing','mvc','storage']);

suite('Panel standalone host route and request boundary')
	->tag('panel','standalone','http','routing','security','scorched-earth')
	->group('framework-coverage')
	->contract('panel.standalone.routes',1)
	->risk('critical')
	->watches('module:panel','path:runtime/modules/panel/Framework/Http/PanelStandaloneHost.php')
	->through('exact mount','reserved routes','path canonicalization','bounded request rebuild','public assets');

/** @return array<string,mixed> */
function dp_panel_standalone_error(\Dataphyre\Http\Response $response): array {
	return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
}

function dp_panel_standalone_route_surface(?callable $renderer=null): PanelInstance {
	$panel=new PanelInstance('standalone-routes-'.bin2hex(random_bytes(4)), new PanelManager());
	$panel->registerPage($panel->page('probe')->content($renderer ?? static fn(): string=>'standalone route probe'));
	return $panel;
}

function dp_panel_standalone_read_host(?PanelInstance $panel=null): PanelStandaloneHost {
	return Panel::standaloneHost($panel ?? dp_panel_standalone_route_surface(), '/panel')
		->authenticateUsing(static fn(): array=>['id'=>7])
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true);
}

test('standalone host facade is immutable and publishes a deployment manifest',static function(Context $t): void {
	$panel=dp_panel_standalone_route_surface();
	$base=Panel::standaloneHost($panel,'panel/');
	$write=$base
		->allowAnonymous()
		->allowAssets(false)
		->allowUploads()
		->developmentErrors()
		->withLimits(['max_segments'=>9,'unknown'=>1])
		->withSecurityHeaders(['Permissions-Policy'=>'camera=()']);
	$t->instanceOf(PanelStandaloneHost::class,$base);
	$t->same('/panel',$base->prefix());
	$t->same($panel,$base->panel());
	$t->same('read_only',$base->manifest()['mode']);
	$t->same('read_write',$write->manifest()['mode']);
	$t->isTrue($write->manifest()['capabilities']['uploads']);
	$t->isFalse($write->manifest()['capabilities']['assets']);
	$t->same(9,$write->manifest()['limits']['max_segments']);
	$t->isFalse(isset($write->manifest()['limits']['unknown']));
	$t->same($write->manifest(),$write->jsonSerialize());
	$t->same('read_only',$write->readOnly()->manifest()['mode']);
	$t->isFalse($write->readOnly()->manifest()['capabilities']['uploads']);
	$t->isTrue($base->matches('/panel'));
	$t->isTrue($base->matches('/panel/probe'));
	$t->isFalse($base->matches('/panelx'));
	$t->isFalse($base->matches('/other/panel'));
	$t->throws(static fn()=>Panel::standaloneHost($panel,'/panel/../escape'),InvalidArgumentException::class);
});

test('standalone host serves assets publicly with strict reserved-route methods',static function(Context $t): void {
	$host=Panel::standaloneHost(dp_panel_standalone_route_surface(),'/panel');
	$get=$host->handle(Request::create('GET','/panel/assets/panel.css'));
	$t->same(200,$get->status);
	$t->contains('text/css',(string)$get->headers['Content-Type']);
	$t->contains('immutable',(string)$get->headers['Cache-Control']);
	$t->same('standalone',$get->headers['X-Dataphyre-Panel-Host']);
	$head=$host->handle(Request::create('HEAD','/panel/assets/panel.css'));
	$t->same(200,$head->status);
	$t->same('',$head->body);
	$t->greaterThan(0,(int)$head->headers['Content-Length']);
	$conditional=$host->handle(Request::create('GET','/panel/assets/panel.css',[],[],[],[],[
		'If-None-Match'=>$get->headers['ETag'],
	]));
	$t->same(304,$conditional->status);
	$t->same('',$conditional->body);
	$t->same(405,$host->handle(Request::create('POST','/panel/assets/panel.css'))->status);
	$t->same(404,$host->handle(Request::create('GET','/panel/assets'))->status);
	$t->same(404,$host->handle(Request::create('GET','/panel/assets/panel.css/extra'))->status);
	$t->same(404,$host->allowAssets(false)->handle(Request::create('GET','/panel/assets/panel.css'))->status);
	$t->same(404,$host->handle(Request::create('GET','/panel/assets/missing.css'))->status);
});

test('standalone host rejects malformed encoded and unbounded paths before dispatch',static function(Context $t): void {
	$host=Panel::standaloneHost(dp_panel_standalone_route_surface(),'/panel');
	foreach([
		'/panel/orders/%ZZ'=>'invalid_path_encoding',
		'/panel/orders/%2Fetc'=>'invalid_path_segment',
		'/panel/orders/%252Fetc'=>'unstable_path_encoding',
		'/panel/orders/%2e%2e'=>'invalid_path_segment',
		'/panel/orders//edit'=>'invalid_path',
		"/panel/orders/\x01"=>'invalid_path',
	] as $path=>$code){
		$response=$host->handle(Request::create('GET',$path));
		$t->same(400,$response->status,$path);
		$t->same($code,dp_panel_standalone_error($response)['error']['code'],$path);
	}
	$bounded=$host->withLimits(['max_path_bytes'=>16,'max_segments'=>2,'max_segment_bytes'=>4]);
	$t->same(413,$bounded->handle(Request::create('GET','/panel/12345678901'))->status);
	$t->same(413,$bounded->handle(Request::create('GET','/panel/a/b/c'))->status);
	$t->same(400,$bounded->handle(Request::create('GET','/panel/12345'))->status);
});

test('standalone request guard bounds headers input cookies files encoding and content types',static function(Context $t): void {
	$panel=dp_panel_standalone_route_surface();
	$host=Panel::standaloneHost($panel,'/panel')->withLimits([
		'max_headers'=>2,
		'max_header_bytes'=>64,
		'max_header_total_bytes'=>128,
		'max_query_items'=>1,
		'max_query_depth'=>2,
		'max_query_bytes'=>16,
		'max_body_items'=>1,
		'max_body_depth'=>2,
		'max_body_bytes'=>16,
		'max_cookies'=>1,
		'max_cookie_bytes'=>16,
		'max_content_length'=>32,
	]);
	$t->same(413,$host->handle(Request::create('GET','/panel/probe',[],[],[],[],['A'=>'1','B'=>'2','C'=>'3']))->status);
	$t->same(400,$host->handle(Request::create('GET','/panel/probe',[],[],[],[],['A'=>"bad\nvalue"]))->status);
	$t->same(400,$host->withLimits(['max_header_bytes'=>4])->handle(Request::create('GET','/panel/probe',[],[],[],[],['A'=>'12345']))->status);
	$t->same(413,$host->withLimits(['max_header_total_bytes'=>4])->handle(Request::create('GET','/panel/probe',[],[],[],[],['A'=>'12345']))->status);
	$t->same(413,$host->handle(Request::create('GET','/panel/probe',['a'=>1,'b'=>2]))->status);
	$t->same(413,$host->handle(Request::create('GET','/panel/probe',['a'=>['b'=>['c'=>1]]]))->status);
	$t->same(413,$host->handle(Request::create('POST','/panel/probe',[],['a'=>1,'b'=>2],[],[],['Content-Type'=>'application/json']))->status);
	$t->same(400,$host->handle(Request::create('POST','/panel/probe',[],['a'=>new stdClass()],[],[],['Content-Type'=>'application/json']))->status);
	$t->same(413,$host->handle(Request::create('GET','/panel/probe',[],[],['a'=>1,'b'=>2]))->status);
	$t->same(400,$host->handle(Request::create('POST','/panel/probe',[],[],[],[],['Content-Length'=>'-1']))->status);
	$t->same(413,$host->handle(Request::create('POST','/panel/probe',[],[],[],[],['Content-Length'=>'33']))->status);
	$t->same(415,$host->handle(Request::create('POST','/panel/probe',[],['a'=>1],[],[],['Content-Type'=>'text/plain']))->status);
	$t->same(415,$host->handle(Request::create('POST','/panel/probe',[],['a'=>1],[],[],[
		'Content-Type'=>'application/json','Content-Encoding'=>'gzip',
	]))->status);
	$t->same(405,$host->handle(Request::create('TRACE','/panel/probe'))->status);

	$guard=new PanelStandaloneHostRequestGuard('/panel',PanelStandaloneHostRequestGuard::defaultLimits());
	$t->isFalse($guard->inspect(Request::create('GET','/outside'),$panel->name())['matched']);
});

test('standalone request rebuild removes route and tenant smuggling without mutating caller',static function(Context $t): void {
	$observed=null;
	$panel=dp_panel_standalone_route_surface(static function(\Dataphyre\Panel\PanelRequest $request) use (&$observed): string {
		$observed=$request;
		return 'trusted identity';
	});
	$host=Panel::standaloneHost($panel,'/panel')
		->tenantUsing(static fn(): string=>'trusted-tenant')
		->authenticateUsing(static fn(): array=>['id'=>77])
		->authorizeUsing(static fn(): bool=>true)
		->rateLimitUsing(static fn(): bool=>true);
	$caller=Request::create('GET','/panel/probe',[
		'resource'=>'stolen',
		'operation'=>'delete',
		'tenant'=>'query-tenant',
		'keep'=>'yes',
	],[
		'panel_tenant'=>'body-tenant',
	],[],[],[
		'X-Panel-Tenant'=>'header-tenant',
	],[
		'panel_surface'=>'attacker-surface',
		'panel_segments'=>['attacker'],
	],[
		'user'=>['id'=>'attacker'],
		'tenant'=>'attribute-tenant',
	]);
	$response=$host->handle($caller);
	$t->same(200,$response->status);
	$t->instanceOf(\Dataphyre\Panel\PanelRequest::class,$observed);
	$t->same('probe',$observed->resourceName());
	$t->same('trusted-tenant',$observed->tenant());
	$t->same(['id'=>77],$observed->user());
	$t->same('yes',$observed->query()['keep']);
	$t->isFalse(isset($observed->query()['resource']));
	$t->isFalse(isset($observed->query()['tenant']));
	$t->isFalse(isset($observed->input()['panel_tenant']));
	$t->isFalse(isset($observed->headers()['x-panel-tenant']));
	$t->same('stolen',$caller->query('resource'));
	$t->same('query-tenant',$caller->query('tenant'));
	$t->same('header-tenant',$caller->header('X-Panel-Tenant'));
	$t->same('attacker-surface',$caller->route('panel_surface'));
	$t->same(['id'=>'attacker'],$caller->attribute('user'));
});

test('standalone request guard covers root mounts uploads and defensive metadata branches',static function(Context $t): void {
	$defaults=PanelStandaloneHostRequestGuard::defaultLimits();
	$root=new PanelStandaloneHostRequestGuard('/', $defaults);
	$t->isTrue($root->matches(''));
	$t->isTrue($root->matches('/anything'));
	$t->same([], $root->inspect(Request::create('GET','/'), 'root-panel')['segments']);
	$t->same(404,Panel::standaloneHost(dp_panel_standalone_route_surface(),'/panel')->handle(Request::create('GET','/panel/upload/extra'))->status);
	foreach(['',"/bad\nprefix",'/bad?query','/bad\\path','/a//b','/a/../b',str_repeat('/a',600)] as $prefix){
		$t->throws(static fn()=>PanelStandaloneHostRequestGuard::normalizePrefix($prefix),InvalidArgumentException::class,$prefix);
	}
	$limits=PanelStandaloneHostRequestGuard::normalizeLimits(['max_files'=>2,'max_file_bytes'=>10,'max_file_total_bytes'=>3,'max_file_name_bytes'=>4]);
	$t->same(2,$limits['max_files']);
	$t->same(100,PanelStandaloneHostRequestGuard::normalizeLimits(['max_files'=>'100','max_body_bytes'=>'invalid'])['max_files']);

	$guard=new PanelStandaloneHostRequestGuard('/panel',$limits);
	$private=$t->nonPublic($guard);
	$t->throws(static fn()=>$private->invoke('validateHeaders',[0=>'value']),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateHeaders',['X-Test'=>new stdClass()]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateFiles',[new stdClass()]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateFiles',[
		new UploadedFile('a','x','',UPLOAD_ERR_OK,1),
		new UploadedFile('b','x','',UPLOAD_ERR_OK,1),
		new UploadedFile('c','x','',UPLOAD_ERR_OK,1),
	]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateFiles',[new UploadedFile('a','x','',UPLOAD_ERR_OK,11)]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateFiles',[new UploadedFile('long-name','x','',UPLOAD_ERR_OK,1)]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->throws(static fn()=>$private->invoke('validateFiles',[
		new UploadedFile('a','x','',UPLOAD_ERR_OK,2),
		new UploadedFile('b','x','',UPLOAD_ERR_OK,2),
	]),\Dataphyre\Panel\PanelStandaloneHostException::class);
	$t->same([], $private->invoke('legacyFiles',[new stdClass()]));

	$tmp=$t->tempFile('upload','standalone-host-upload');
	$request=Request::create('POST','/panel/upload',[],['csrf'=>'x'],[],[
		'HTTP_X_TENANT'=>'attacker',
		'REDIRECT_HTTP_PANEL_TENANT'=>'attacker',
		'HTTP_X_KEEP'=>'yes',
	],[
		'Content-Type'=>'multipart/form-data',
	],[],[
		'user'=>'attacker',
		'__panel_standalone_old'=>'remove',
		'keep'=>'yes',
	],[
		'file'=>['name'=>'a','type'=>'x','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>1],
	]);
	$inspected=(new PanelStandaloneHostRequestGuard('/panel',PanelStandaloneHostRequestGuard::defaultLimits()))->inspect($request,'surface');
	$trusted=$inspected['request'];
	$t->instanceOf(UploadedFile::class,$trusted->file('file'));
	$t->same(null,$trusted->server('HTTP_X_TENANT'));
	$t->same(null,$trusted->server('REDIRECT_HTTP_PANEL_TENANT'));
	$t->same('yes',$trusted->server('HTTP_X_KEEP'));
	$t->same(null,$trusted->attribute('user'));
	$t->same(null,$trusted->attribute('__panel_standalone_old'));
	$t->same('yes',$trusted->attribute('keep'));
});
