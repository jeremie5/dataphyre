<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/debugbar/assets.php';

final class DpFlightdeckDebugbarAssetHarness {
	use dataphyre_flightdeck_debugbar_assets;
}

suite('Flightdeck debugbar asset boundaries')
	->tag('flightdeck','debugbar','assets','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.asset-boundaries',1)
	->layer('unit')
	->risk('medium')
	->watches('module:flightdeck')
	->through('HTML extraction','safe local resolution','issue classification','MIME catalog','encoding fallback')
	->isolation('process');

test('asset diagnostics stay bounded and explain every local resolution boundary',static function(Context $t): void {
	$assets=$t->nonPublic(DpFlightdeckDebugbarAssetHarness::class);
	$t->globalMap('_SERVER')->replace([
		'HTTP_HOST'=>'example.test',
		'HTTPS'=>'off',
	]);

	$t->same([],$assets->invoke('response_assets','<img src="">'));
	$largeHtml='';
	for($index=1;$index<=181;$index++){
		$largeHtml.='<img src="/assets/image-'.$index.'.png">';
	}
	$t->count(180,$assets->invoke('response_assets',$largeHtml));
	$t->same('unparseable',$assets->invoke('asset_probe','http://:')['status']);

	$t->same([
		'empty'=>'empty_url',
		'whitespace'=>'whitespace_in_url',
		'loopback'=>'loopback_host',
		'asset_slashes'=>'double_slash_assets',
		'path_slashes'=>'double_slash_path',
		'unparseable_asset_slashes'=>'double_slash_assets',
	],$assets->invokeCases([
		'empty'=>['method'=>'asset_issue','arguments'=>['',[]]],
		'whitespace'=>['method'=>'asset_issue','arguments'=>['/assets/app file.css',[]]],
		'loopback'=>['method'=>'asset_issue','arguments'=>['http://localhost/app.css',[]]],
		'asset_slashes'=>['method'=>'asset_issue','arguments'=>['/assets//app.css',[]]],
		'path_slashes'=>['method'=>'asset_issue','arguments'=>['/build//app.css',[]]],
		'unparseable_asset_slashes'=>['method'=>'asset_issue','arguments'=>['http://:/assets//app.css',[]]],
	]));

	$workspace=$t->workspace('flightdeck-assets');
	$assetFile=$workspace->file('assets/app.css','body{color:#123}');
	$roots=['/',$workspace->root()];
	for($index=1;$index<=30;$index++){
		$roots[]=$workspace->path('root-'.$index);
	}
	define('DATAPHYRE_FLIGHTDECK_CONFIG',['debugbar'=>['asset_roots'=>$roots]]);
	if(!defined('DATAPHYRE_PROJECT_ROOT')){
		define('DATAPHYRE_PROJECT_ROOT',$workspace->path('project'));
	}
	if(!defined('ROOTPATH')){
		define('ROOTPATH',['root'=>$workspace->path('rootpath')]);
	}
	define('APP','asset-app');
	define('DATAPHYRE_APPLICATION_ROOTS',[$workspace->path('applications'),'']);
	$t->globalMap('_SERVER')->replace([
		'HTTP_HOST'=>'example.test',
		'DOCUMENT_ROOT'=>$workspace->root(),
	]);
	$found=$assets->invoke('asset_probe','/assets/app.css');
	$t->hasPathValues([
		'status'=>'found',
		'local_path'=>str_replace('\\','/',$assetFile),
		'size_bytes'=>strlen('body{color:#123}'),
	],$found);
	$t->greaterThanOrEqual(80,count($assets->invoke('asset_candidate_paths','assets//app.css')));
	$t->containsAll(['assets//app.css','assets/app.css','/app.css','app.css'],$assets->invoke('asset_relative_variants','assets//app.css'));

	$t->same([
		'json'=>'application/json','map'=>'application/json','png'=>'image/png',
		'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml',
		'webp'=>'image/webp','avif'=>'image/avif','ico'=>'image/x-icon',
		'woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','otf'=>'font/otf',
		'mp3'=>'audio/mpeg','mp4'=>'video/mp4',
	],$assets->invokeCases([
		'json'=>['method'=>'expected_asset_mime','arguments'=>['asset.json']],
		'map'=>['method'=>'expected_asset_mime','arguments'=>['asset.map']],
		'png'=>['method'=>'expected_asset_mime','arguments'=>['asset.png']],
		'jpg'=>['method'=>'expected_asset_mime','arguments'=>['asset.jpg']],
		'jpeg'=>['method'=>'expected_asset_mime','arguments'=>['asset.jpeg']],
		'gif'=>['method'=>'expected_asset_mime','arguments'=>['asset.gif']],
		'svg'=>['method'=>'expected_asset_mime','arguments'=>['asset.svg']],
		'webp'=>['method'=>'expected_asset_mime','arguments'=>['asset.webp']],
		'avif'=>['method'=>'expected_asset_mime','arguments'=>['asset.avif']],
		'ico'=>['method'=>'expected_asset_mime','arguments'=>['asset.ico']],
		'woff'=>['method'=>'expected_asset_mime','arguments'=>['asset.woff']],
		'woff2'=>['method'=>'expected_asset_mime','arguments'=>['asset.woff2']],
		'ttf'=>['method'=>'expected_asset_mime','arguments'=>['asset.ttf']],
		'otf'=>['method'=>'expected_asset_mime','arguments'=>['asset.otf']],
		'mp3'=>['method'=>'expected_asset_mime','arguments'=>['asset.mp3']],
		'mp4'=>['method'=>'expected_asset_mime','arguments'=>['asset.mp4']],
	]));

	$t->same([],$assets->invoke('duplicate_html_ids','<div id=""></div>'));
	$t->type('integer',$assets->invoke('mojibake_count',"invalid-utf8-\xC3\x28"));

	include dirname(__DIR__).'/kernel/debugbar/assets.php';
});
