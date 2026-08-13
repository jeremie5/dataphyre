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

	if(!function_exists(__NAMESPACE__.'\\headers_sent')){
		function headers_sent(): bool {
			return \Dataphyre\Panel\TestFixtures\NativeResponseProbe::headersAlreadySent();
		}
	}
	if(!function_exists(__NAMESPACE__.'\\http_response_code')){
		function http_response_code(?int $responseCode=null): int|bool {
			return \Dataphyre\Panel\TestFixtures\NativeResponseProbe::responseStatus($responseCode);
		}
	}
	if(!function_exists(__NAMESPACE__.'\\header')){
		function header(string $header, bool $replace=true, int $responseCode=0): void {
			\Dataphyre\Panel\TestFixtures\NativeResponseProbe::recordHeader($header,$replace,$responseCode);
		}
	}
}

namespace {
	use Dataphyre\Http\Request;
	use Dataphyre\Panel\Field;
	use Dataphyre\Panel\Infolist;
	use Dataphyre\Panel\InfolistEntry;
	use Dataphyre\Panel\NavigationManifest;
	use Dataphyre\Panel\PanelAssetController;
	use Dataphyre\Panel\PanelBrowserRegressionManifest;
	use Dataphyre\Panel\PanelDocumentationEntry;
	use Dataphyre\Panel\PanelPageResult;
	use Dataphyre\Panel\PanelResponseEmitter;
	use Dataphyre\Panel\ResourceForm;
	use Dataphyre\Panel\Schema;
	use Dataphyre\Panel\SchemaManifest;
	use Dataphyre\Panel\TestFixtures\NativeResponseProbe;
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\framework;
	use function Dataphyre\Test\test;

	framework(['panel','http']);
	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	test('panel browser regression manifest residual coverage normalizes all hydrated browser runner options and guards',static function(Context $t): void {
		$t->throws(static fn()=>PanelBrowserRegressionManifest::make('blank','   '),InvalidArgumentException::class);
		$manifest=PanelBrowserRegressionManifest::fromArray([
			'type'=>'panel_browser_regression_manifest','name'=>'orders browser','url'=>'/panel/orders',
			'viewport'=>['width'=>0,'height'=>-2,'deviceScaleFactor'=>0,'isMobile'=>true],
			'interactions'=>[
				'invalid',
				['type'=>'','selector'=>'#ignored'],
				['type'=>'fill','selector'=>'#search','text'=>'Orders','value'=>'open','key'=>'Enter','url'=>'/orders','path'=>'result.png','timeout_ms'=>-5,'delay_ms'=>25,'options'=>['force'=>true]],
			],
			'screenshot_path'=>'artifacts/orders.png',
			'console_policy'=>['fail_on'=>'error','allow'=>42,'ignore'=>[' known ','known','']],
			'expected_selectors'=>[
				'#orders-table',
				'#empty'=>['state'=>'hidden','count'=>0],
				['selector'=>'#title','text'=>'Orders','timeout_ms'=>-1],
				['selector'=>''],
			],
			'accessibility'=>['enabled'=>true,'fail_on'=>'critical','rules'=>['color-contrast'=>false]],
			'result'=>['format'=>'xml','path'=>'results/orders.xml','include_console'=>false,'include_accessibility'=>false,'include_screenshot'=>false],
			'meta'=>['suite'=>'panel'],
		]);
		$data=$manifest->toArray();
		$t->same(1,$data['viewport']['width']);
		$t->same(1,$data['viewport']['height']);
		$t->same(0.1,$data['viewport']['device_scale_factor']);
		$t->isTrue($data['viewport']['is_mobile']);
		$t->same(1,count($data['interactions']));
		$t->same(0,$data['interactions'][0]['timeout_ms']);
		$t->same(['force'=>true],$data['interactions'][0]['options']);
		$t->same([], $data['console_policy']['allow']);
		$t->same(['known'],$data['console_policy']['ignore']);
		$t->same(3,count($data['expected_selectors']));
		$t->same('Orders',$data['expected_selectors'][2]['text']);
		$t->same('json',$data['result']['format']);
		$t->isFalse($data['result']['include_console']);
		$t->same('panel',$data['meta']['suite']);

		$manifest->interaction('   ')->expectSelector('   ')->accessibility(false)->meta('owner','qa')->meta(' ',false);
		$t->isFalse($manifest->toArray()['accessibility']['enabled']);
		$t->same('qa',$manifest->toArray()['meta']['owner']);
		$alias=PanelBrowserRegressionManifest::make('alias','/alias',['screenshot'=>'alias.png']);
		$t->same('alias.png',$alias->toArray()['screenshot_path']);
		$t->same($manifest->toArray(),$manifest->jsonSerialize());
	})->tag('panel','panel-manifest-http-residual-exact','browser-regression','deep-coverage')->group('framework-coverage');

	test('panel navigation manifest residual coverage resolves serialized states and recursively flattens mixed trees',static function(Context $t): void {
		$entries=[
			'invalid',
			[
				'name'=>'parent','kind'=>'resource','url'=>'/parent','folder_only'=>true,'badge'=>true,'description'=>'Parent','new_tab'=>true,'active_descendant'=>true,
				'children'=>[
					'invalid-child',
					['name'=>'child','kind'=>'page','url'=>'/child','badge'=>0,'description'=>'Child','children'=>[
						['name'=>'grandchild','kind'=>'navigation_item','url'=>'/grandchild'],
					]],
				],
			],
		];
		$state=[
			'entries'=>$entries,
			'groups'=>[['name'=>'main','active'=>true]],
			'active'=>['name'=>'child'],
			'search'=>['query'=>'child','results'=>[['name'=>'child']]],
			'meta'=>['layout'=>'horizontal','mode'=>'docked'],
		];
		$nested=NavigationManifest::from(['navigation_state'=>$state],null,[],['source'=>'nested'])->toArray();
		$t->same(3,$nested['counts']['entries']);
		$t->same(3,$nested['counts']['max_depth']);
		$t->same(2,$nested['counts']['badges']);
		$t->same('horizontal',$nested['layout']);
		$t->same(1,$nested['capabilities']['groups']['active']);
		$t->same(1,$nested['capabilities']['search']['result_count']);
		$direct=NavigationManifest::from($state)->toArray();
		$t->same(3,count($direct['entries_flat']));

		$flattened=$t->nonPublic(NavigationManifest::class)->invoke('flattenEntriesWithDepth',[
			'invalid',
			['name'=>'direct-child','children'=>[['name'=>'deep-child']]],
		],2);
		$t->same(2,count($flattened));
		$t->same(3,$flattened[1]['depth']);
	})->tag('panel','panel-manifest-http-residual-exact','navigation-manifest','deep-coverage')->group('framework-coverage');

	test('panel response emitter residual coverage emits safe headers status and optional body through deterministic native seams',static function(Context $t): void {
		$native=NativeResponseProbe::reset($t);
		$result=new PanelPageResult('emitted-body',207,[
			'X-Test'=>' one ',
			'Set-Cookie'=>['a=1','b=2',"bad\r\nInjected: yes",null],
			'Bad Header'=>'ignored',
			'X-Object'=>new stdClass(),
		]);
		$emission=$t->captureOutput(static fn()=>PanelResponseEmitter::emit($result,true));
		$t->same('emitted-body',$emission->result());
		$t->same('emitted-body',$emission->output());
		$t->same(207,$native->status());
		$t->same(3,count($native->headers()));
		$t->same(false,$native->headers()[1][1]);

		$native->markHeadersSent();
		$t->same('emitted-body',PanelResponseEmitter::emit($result,false));
	})->tag('panel','panel-manifest-http-residual-exact','response-emitter','deep-coverage')->group('framework-coverage');

	test('panel schema manifest residual coverage covers source factories invalid rows recursive children and capability synthesis',static function(Context $t): void {
		$schema=Schema::make([
			Field::make('status')->required()->live()->dependsOn('kind')->meta(['component'=>['capabilities'=>['preview','','suggestions','mask']]]),
		]);
		$manifest=SchemaManifest::fromSchema($schema,'edit',['surface'=>'coverage']);
		$t->same($manifest,SchemaManifest::from($manifest));
		$t->same('schema_manifest',SchemaManifest::from(Infolist::make([InfolistEntry::make('name')]),'view')->toArray()['type']);
		$form=ResourceForm::make()->field(Field::make('title'))->meta(['source'=>'form']);
		$t->same('form',SchemaManifest::from($form,'create')->toArray()['meta']['source']);

		$components=$t->nonPublic(SchemaManifest::class)->invoke('flattenComponents',[
			'invalid',
			['kind'=>'group','name'=>'','children'=>[
				['kind'=>'field','field'=>['name'=>'nested','label'=>'Nested','live'=>true,'required'=>true,'depends_on'=>['kind']]],
			]],
		]);
		$t->same(2,count($components));
		$t->contains('children',$components[0]['capabilities']);

		$fields=$t->nonPublic(SchemaManifest::class)->invoke('fieldManifests',[
			'fields'=>[
				'invalid'=>'not-an-array',
				'status'=>[
					'name'=>'status','required'=>true,'readonly'=>true,'state_updates'=>true,'hydrates'=>true,'dehydrates'=>true,
					'reactive'=>true,'depends_on'=>['kind'],'component'=>['capabilities'=>['preview','','suggestions','mask']],
				],
			],
		],$components);
		$t->contains('reactive',$fields['status']['capabilities']);
		$t->contains('conditional',$fields['status']['capabilities']);
		$t->contains('preview',$fields['status']['capabilities']);

		$sections=$t->nonPublic(SchemaManifest::class)->invoke('sectionManifests',[
			'invalid',
			['name'=>''],
			['name'=>'details','label'=>'Details'],
		],$components);
		$t->hasKey('details',$sections);
		$t->same('Untitled',$t->nonPublic(SchemaManifest::class)->invoke('humanize',''));
	})->tag('panel','panel-manifest-http-residual-exact','schema-manifest','deep-coverage')->group('framework-coverage');

	test('panel asset controller residual coverage handles route query invocation missing head conditional and response-shape helpers',static function(Context $t): void {
		$routeRequest=Request::create('GET','/admin/assets/ignored.css',[],[],[],[],[],['asset'=>'panel.css']);
		$routeResponse=PanelAssetController::handle($routeRequest,['asset'=>'panel.css']);
		$t->same(200,$routeResponse->status);
		$t->isTrue(str_contains($routeResponse->headers['Content-Type'],'text/css'));
		$queryRequest=Request::create('HEAD','/admin/assets/ignored', ['asset'=>'panel.js']);
		$queryResponse=PanelAssetController::handle($queryRequest);
		$t->same('',$queryResponse->body);
		$t->same(200,$queryResponse->status);
		$invoked=(new PanelAssetController())(Request::create('GET','/admin/assets/panel.css'));
		$t->same(200,$invoked->status);

		$missing=PanelAssetController::response('../missing.asset',Request::create('GET','/admin/assets/missing.asset'));
		$t->same(404,$missing->status);
		$t->same('no-store',$missing->headers['Cache-Control']);
		$t->same([],$t->nonPublic(PanelAssetController::class)->invoke('responseHeaders',new stdClass()));
		$fallbackResult=new PanelPageResult('fallback',418,['X-Test'=>'yes']);
		$t->same(['X-Test'=>'yes'],$t->nonPublic(PanelAssetController::class)->invoke('responseHeaders',$fallbackResult));
		$t->same(418,$t->nonPublic(PanelAssetController::class)->invoke('responseStatus',$fallbackResult));
		$t->same(200,$t->nonPublic(PanelAssetController::class)->invoke('responseStatus',new stdClass()));
	})->tag('panel','panel-manifest-http-residual-exact','asset-controller','deep-coverage')->group('framework-coverage');
}
