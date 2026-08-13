<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Http\Response;
use Dataphyre\Panel\PanelNotification;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['http','panel']);

test('panel page result exposes constructor html accessors serialization and http adaptation',static function(Context $t): void {
	$direct=new PanelPageResult('<main>Direct</main>',201,['X-Test'=>'yes'],['id'=>7],[['message'=>'Ready']],null);
	$t->same('<main>Direct</main>',$direct->content());
	$t->same(201,$direct->status());
	$t->same(['X-Test'=>'yes'],$direct->headers());
	$t->same(['id'=>7],$direct->data());
	$t->same([['message'=>'Ready']],$direct->notifications());
	$t->same(null,$direct->redirectTo());
	$t->isFalse($direct->isRedirect());
	$t->same('<main>Direct</main>',(string)$direct);
	$t->same([
		'status'=>201,'headers'=>['X-Test'=>'yes'],'data'=>['id'=>7],
		'notifications'=>[['message'=>'Ready']],'redirect_to'=>null,
	],$direct->jsonSerialize());
	$t->same($direct->jsonSerialize(),json_decode((string)json_encode($direct),true));

	$html=PanelPageResult::html('<p>Hello</p>',202,['kind'=>'html'],[
		PanelNotification::success('Saved','Done'),
		['message'=>'Careful','type'=>'warning','title'=>'Heads up'],
		'Plain information',
		'   ',
		42,
	]);
	$t->same(202,$html->status());
	$t->same('text/html; charset=utf-8',$html->headers()['Content-Type']);
	$t->same(['kind'=>'html'],$html->data());
	$t->same(3,count($html->notifications()));
	$t->same('success',$html->notifications()[0]['type']);
	$t->same('warning',$html->notifications()[1]['type']);
	$t->same('info',$html->notifications()[2]['type']);

	$response=$html->toResponse();
	$t->isTrue($response instanceof Response);
	$t->same(202,$response->status);
	$t->same('<p>Hello</p>',$response->body);
})->tag('panel','page-result','coverage')->group('framework-coverage');

test('panel page result creates safe csv and json downloads including encoding fallbacks',static function(Context $t): void {
	$csv=PanelPageResult::csv("id,name\n1,Alice",' ../Quarter 1\r\n.csv ',['rows'=>1]);
	$t->same(200,$csv->status());
	$t->same('text/csv; charset=utf-8',$csv->headers()['Content-Type']);
	$t->same('attachment; filename="..-Quarter-1-r-n.csv"',$csv->headers()['Content-Disposition']);
	$t->same(['rows'=>1],$csv->data());
	$t->contains('Alice',$csv->content());
	$t->same('attachment; filename="panel-export.csv"',PanelPageResult::csv('',' ')->headers()['Content-Disposition']);

	$json=PanelPageResult::jsonDownload(['path'=>'/orders/1','label'=>'Café'],' Report 2026.json ',['rows'=>2]);
	$t->same('application/json; charset=utf-8',$json->headers()['Content-Type']);
	$t->same('attachment; filename="Report-2026.json"',$json->headers()['Content-Disposition']);
	$t->contains('"path": "/orders/1"',$json->content());
	$t->contains('Café',$json->content());
	$t->same(['rows'=>2],$json->data());
	$t->same('attachment; filename="panel-export.json"',PanelPageResult::jsonDownload([],"\t")->headers()['Content-Disposition']);
	$t->same("\u{FFFD}1",json_decode(PanelPageResult::jsonDownload(["bad"=>"\xB1\x31"])->content(),true,512,JSON_THROW_ON_ERROR)['bad']);

	$inline=PanelPageResult::json(['ok'=>true,'path'=>'/a'],207,[
		'Content-Type'=>'application/problem+json','X-Trace'=>'abc',
	]);
	$t->same(207,$inline->status());
	$t->same('application/problem+json',$inline->headers()['Content-Type']);
	$t->same('abc',$inline->headers()['X-Trace']);
	$t->same('{"ok":true,"path":"/a"}',$inline->content());
	$t->same("\u{FFFD}1",json_decode(PanelPageResult::json(["bad"=>"\xB1\x31"])->content(),true,512,JSON_THROW_ON_ERROR)['bad']);
})->tag('panel','page-result','coverage')->group('framework-coverage');

test('panel page result redirect accepts local and http destinations while rejecting executable targets',static function(Context $t): void {
	$local=PanelPageResult::redirect('/panel/orders?view=all',['kind'=>'redirect'],[
		PanelNotification::info('Moving'),['message'=>'Second'],'Third',null,
	],307);
	$t->same(307,$local->status());
	$t->same('/panel/orders?view=all',$local->headers()['Location']);
	$t->same('/panel/orders?view=all',$local->redirectTo());
	$t->isTrue($local->isRedirect());
	$t->same(['kind'=>'redirect'],$local->data());
	$t->same(3,count($local->notifications()));
	$t->contains('url=/panel/orders?view=all',$local->content());

	$escaped=PanelPageResult::redirect('/panel/search?q="<tag>"');
	$t->contains('&quot;&lt;tag&gt;&quot;',$escaped->content());
	$t->same('/panel/search?q="<tag>"',$escaped->headers()['Location']);

	foreach([
		''=>'#',"\r\nLocation: https://evil.test"=>'#','//evil.test/path'=>'#',
		'\\evil.test\path'=>'#','javascript:alert(1)'=>'#','DATA:text/html,bad'=>'#','ftp://example.test/file'=>'#',
		'https://example.test/path'=>'https://example.test/path','HTTP://example.test'=>'HTTP://example.test',
		'orders/42'=>'orders/42',"/panel/orders\r\nX-Evil: yes"=>'/panel/orders',
	] as $candidate=>$expected){
		$result=PanelPageResult::redirect($candidate);
		$t->same($expected,$result->headers()['Location']);
		$t->same($expected,$result->redirectTo());
	}

	$t->same('#',$t->nonPublic(PanelPageResult::class)->invoke('redirectTarget','#'));
	$t->same('fallback',$t->nonPublic(PanelPageResult::class)->invoke('singleLine',"\x00\x01\x7F",'fallback'));
	$t->same('value',$t->nonPublic(PanelPageResult::class)->invoke('singleLine'," va\x00lue\nignored"));
	$t->same('&lt;&amp;&quot;&#039;',$t->nonPublic(PanelPageResult::class)->invoke('e','<&"\''));
})->tag('panel','page-result','coverage')->group('framework-coverage');
