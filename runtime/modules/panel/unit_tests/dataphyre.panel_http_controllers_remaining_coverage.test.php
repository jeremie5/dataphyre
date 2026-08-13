<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel {
	require_once __DIR__.'/panel_test_probes.php';

	if(!function_exists(__NAMESPACE__.'\\class_exists')){
		function class_exists(string $class,bool $autoload=true): bool {
			$class=ltrim($class,'\\');
			if(!\Dataphyre\Panel\TestFixtures\PanelControllerEnvironment::classAvailable($class)){
				return false;
			}
			return \class_exists($class,$autoload);
		}
	}
}

namespace {
	use Dataphyre\Http\Request;
	use Dataphyre\Http\UploadedFile;
	use Dataphyre\Panel\Panel;
	use Dataphyre\Panel\PanelContext;
	use Dataphyre\Panel\PanelHost;
	use Dataphyre\Panel\PanelInstance;
	use Dataphyre\Panel\PanelManager;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelRequest;
	use Dataphyre\Panel\PanelRouteController;
	use Dataphyre\Panel\PanelStorageUploadEndpoint;
	use Dataphyre\Panel\TestFixtures\PanelControllerEnvironment;
	use Dataphyre\Panel\PanelUploadController;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module,string $constant,array $defaults=[]): void {
			if(!defined($constant)){
				define($constant,$defaults);
			}
		}
	}

	if(!defined('DP_STORAGE_CFG')){
		define('DP_STORAGE_CFG',['default_disk'=>'local','disks'=>[]]);
	}
	framework(['http','panel','storage']);

	test('panel upload controller covers dependency absence invocation deletion file normalization CSRF bypass and fallbacks',static function(Context $t): void {
		$environment=PanelControllerEnvironment::reset($t)->withoutClass(PanelStorageUploadEndpoint::class);
		$unavailable=PanelContext::run(['upload_csrf'=>false],static fn(): mixed=>PanelUploadController::handle(
			Request::create('POST','/panel/upload',[],['upload_id'=>'missing'])
		));
		$t->same(503,$unavailable->status ?? null);
		$environment->restoreClass(PanelStorageUploadEndpoint::class);

		$delete=PanelContext::run(['upload_csrf'=>false],static fn(): mixed=>PanelUploadController::handle(
			Request::create('POST','/panel/upload',[],['dp_panel_upload_delete'=>'1'])
		));
		$t->same(422,$delete->status ?? null);
		$t->same(405,(new PanelUploadController())(Request::create('GET','/panel/upload'))->status ?? null);

		$tmp=$t->tempFile('payload','dp-panel-controller-upload');
		$uploaded=new UploadedFile('Original.txt','text/plain',$tmp,UPLOAD_ERR_OK,7);
		$legacy=['name'=>'legacy.txt','type'=>'text/plain','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>7];
		$normalized=$t->nonPublic(PanelUploadController::class)->invoke('filesArray',[
			'asset.part'=>$uploaded,
			'legacy'=>$legacy,
			7=>$legacy,
			'ignored'=>'scalar',
		]);
		$t->same('Original.txt',$normalized['asset']['name']);
		$t->same('text/plain',$normalized['asset']['type']);
		$t->same($tmp,$normalized['asset']['tmp_name']);
		$t->same(UPLOAD_ERR_OK,$normalized['asset']['error']);
		$t->same(7,$normalized['asset']['size']);
		$t->same($legacy,$normalized['legacy']);
		$t->same($legacy,$normalized['7']);
		$t->isFalse(isset($normalized['ignored']));

		$t->same(true,PanelContext::run(['upload_csrf'=>false],static fn(): bool=>$t->nonPublic(PanelUploadController::class)->invoke('csrfValid',Request::create('POST','/panel/upload'),[])));

		$environment->withoutClass('Dataphyre\\Http\\Response');
		$fallback=$t->nonPublic(PanelUploadController::class)->invoke('json',['ok'=>true],207,['X-Test'=>'yes']);
		$t->instanceOf(PanelPageResult::class,$fallback);
		$t->same(207,$fallback->status());
		$environment->restoreClass('Dataphyre\\Http\\Response');
		$t->same(418,$t->nonPublic(PanelUploadController::class)->invoke('responseStatus',PanelPageResult::html('',418)));
		$t->same(200,$t->nonPublic(PanelUploadController::class)->invoke('responseStatus','plain-response'));
	})->tag('panel','controller','upload','coverage')->group('framework-coverage');

	test('panel route controller covers invocation mount inference segment normalization response status and failure tracing',static function(Context $t): void {
		$surface=new PanelInstance('route-controller',new PanelManager());
		Panel::registerSurface($surface,'route-controller');
		$request=Request::create('GET','/actual-path',[],[],[],[],[],[
			'panel_surface'=>'route-controller','panel_segments'=>['different'],
		]);
		$response=(new PanelRouteController())($request);
		$t->same(404,$response->status ?? null);

		$t->same('/application/admin',$t->nonPublic(PanelRouteController::class)->invoke('mountPrefix',Request::create('GET','/application/admin/orders'),['panel_mount_prefix'=>'/admin'],));
		$t->same('/admin',$t->nonPublic(PanelRouteController::class)->invoke('mountPrefix',Request::create('GET','/other/path'),['panel_mount_prefix'=>'/admin'],));
		$t->same('/application',$t->nonPublic(PanelRouteController::class)->invoke('mountPrefix',Request::create('GET','/application/orders/A%20B'),['panel_segments'=>['orders','A B']],));
		$t->same('/unsegmented',$t->nonPublic(PanelRouteController::class)->invoke('mountPrefix',Request::create('GET','/unsegmented'),[],));
		$t->same('',$t->nonPublic(PanelRouteController::class)->invoke('mountPrefix',Request::create('GET','/application/orders'),['panel_segments'=>['different']],));
		$t->same(['orders',' ','A B'],$t->nonPublic(PanelRouteController::class)->invoke('routeSegments',[
			'panel_segments'=>['/orders/',' ','A%20B'],
		]));
		$t->same(['orders','42'],$t->nonPublic(PanelRouteController::class)->invoke('routeSegments',['path'=>'/orders/42/']));
		$t->same([],$t->nonPublic(PanelRouteController::class)->invoke('routeSegments',['segments'=>new stdClass()]));
		$t->same(409,$t->nonPublic(PanelRouteController::class)->invoke('responseStatus',PanelPageResult::html('',409)));
		$t->same(200,$t->nonPublic(PanelRouteController::class)->invoke('responseStatus',null));

		$throwing=new PanelInstance('throwing-route',new PanelManager());
		$throwing->registerPage($throwing->page('explode')->content(static fn()=>throw new RuntimeException('route explosion')));
		Panel::registerSurface($throwing,'throwing-route');
		$t->throws(static fn()=>PanelRouteController::handle(Request::create(
			'GET','/explode',[],[],[],[],[],[
				'panel_surface'=>'throwing-route','panel_mount_prefix'=>'/','panel_segments'=>['explode'],
			]
		)),RuntimeException::class);
	})->tag('panel','controller','route','coverage')->group('framework-coverage');

	test('panel host covers user rebinding request normalization rendering emission and fragment helpers',static function(Context $t): void {
		$surface=new PanelInstance('host-controller',new PanelManager());
		$host=PanelHost::surface($surface,['id'=>11]);
		$t->same($surface,$host->panel());
		$t->same($surface,$host->withUser(['id'=>12])->panel());

		$arrayRequest=$t->nonPublic($host)->invoke('request',['resource'=>'missing']);
		$t->instanceOf(PanelRequest::class,$arrayRequest);
		$t->same(['id'=>11],$arrayRequest->user());
		$captured=$t->nonPublic($host)->invoke('request',null);
		$t->instanceOf(PanelRequest::class,$captured);
		$t->same(['id'=>11],$captured->user());

		$t->instanceOf(PanelPageResult::class,$host->render(null,'index'));
		$response=$host->response(['resource'=>'missing','operation'=>'index']);
		$t->same(404,$response->status ?? null);
		$emitted=$host->emit(PanelRequest::fromArray([
			'resource'=>'missing','operation'=>'index',
			'query'=>['__panel_partial'=>'fragment'],
			'headers'=>['X-Requested-With'=>'DataphyrePanelFragment'],
		]),false);
		$t->isTrue(is_array(json_decode($emitted,true,512,JSON_THROW_ON_ERROR)));

		$redirect=PanelPageResult::redirect('/next',['effects'=>[['type'=>'refresh']]],[['message'=>'Moved']],307);
		$fragment=$t->nonPublic($host)->invoke('fragmentResult',$redirect);
		$payload=json_decode($fragment->content(),true);
		$t->same('/next',$payload['redirect_to']);
		$t->same(307,$payload['status']);
		$t->same('refresh',$payload['effects'][0]['type']);
		$t->same('',$t->nonPublic($host)->invoke('htmlTitle','<p>No document title</p>'));
	})->tag('panel','controller','host','coverage')->group('framework-coverage');
}
