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

require_once __DIR__.'/flightdeck_debugbar_scenarios.php';
require_once dirname(__DIR__).'/kernel/debugbar.php';

suite('Flightdeck debugbar history boundaries')
	->tag('flightdeck','debugbar','history','client-events','coverage')
	->group('framework-coverage')
	->contract('flightdeck.debugbar.history-boundaries',1)
	->layer('integration')
	->risk('high')
	->watches('module:flightdeck')
	->through('session history','client event recording','HTTP snapshot context','storage budget')
	->isolation('process');

test('history records authenticated browser evidence and HTTP snapshots inside a bounded session',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	if(session_status()!==PHP_SESSION_ACTIVE){
		@session_start();
	}
	$t->same(PHP_SESSION_ACTIVE,session_status());
	$t->defer(static function(): void {
		if(session_status()===PHP_SESSION_ACTIVE){
			session_write_close();
		}
	});
	$session=$t->globalMap('_SESSION')->replace([]);
	$key='dataphyre_flightdeck_debugbar_history';

	$session->put($key,'invalid-history');
	$t->same([],dataphyre_flightdeck_debugbar::history());
	$t->notEmpty($debugbar->invoke('replay_token','get','/orders','history-probe-secret'));

	$current=flightdeck_debugbar_rich_snapshot();
	unset($current['comparison']);
	$current['client']=[];
	$previous=$current;
	$previous['id']='previous-snapshot';
	$previous['recorded_at']=time()-60;
	$unrelated=$current;
	$unrelated['id']='unrelated-snapshot';
	$unrelated['request']['path']='/customers';
	$unrelated['uri']='/customers';
	$event=['type'=>'js_error','message'=>'Browser failure','timestamp'=>1700000000000];
	$token=dataphyre_flightdeck_debugbar::client_token('current-snapshot');

	$session->put($key,[$current]);
	$t->hasPathValues([
		'ok'=>true,'event_count'=>0,
	],dataphyre_flightdeck_debugbar::record_client_events('current-snapshot',$token,[null]));

	$session->put($key,[$unrelated,$current]);
	$recorded=dataphyre_flightdeck_debugbar::record_client_events('current-snapshot',$token,[$event]);
	$t->hasPathValues([
		'ok'=>true,'event_count'=>1,'accepted'=>1,'linked'=>0,
	],$recorded);
	$t->missingKey('comparison',dataphyre_flightdeck_debugbar::history_snapshot('current-snapshot'));

	$session->put($key,[$unrelated,$current,$previous]);
	$t->isTrue(dataphyre_flightdeck_debugbar::record_client_events('current-snapshot',$token,[$event])['ok']);
	$t->hasKey('comparison',dataphyre_flightdeck_debugbar::history_snapshot('current-snapshot'));
	$t->same('Snapshot was not found.',dataphyre_flightdeck_debugbar::record_client_events(
		'missing-snapshot',
		dataphyre_flightdeck_debugbar::client_token('missing-snapshot'),
		[$event],
	)['message']);

	$session->put($key,[]);
	$t->same(null,$debugbar->invoke('record_snapshot',$current));
	$t->same(null,$debugbar->invoke('record_snapshot',$current,'fpm-fcgi',false,'/orders'));
	$t->same(null,$debugbar->invoke('record_snapshot',$current,'fpm-fcgi',true,'/dataphyre/debugbar'));
	$first=$debugbar->invoke('record_snapshot',$current,'fpm-fcgi',true,'/orders');
	$t->type('array',$first);
	$current['duration_ms']=(float)$current['duration_ms']+500.0;
	$second=$debugbar->invoke('record_snapshot',$current,'fpm-fcgi',true,'/orders');
	$t->hasKey('comparison',$second);
	$t->count(2,dataphyre_flightdeck_debugbar::history());

	$oversized=[];
	for($index=0;$index<8;$index++){
		$oversized[]=['id'=>'large-'.$index,'payload'=>array_fill(0,100,str_repeat((string)$index,1200))];
	}
	$bounded=$debugbar->invoke('history_within_session_budget',$oversized);
	$t->lessThan(8,count($bounded));
	$t->greaterThan(0,count($bounded));
	$resource=fopen('php://memory','rb');
	$t->contains('resource',$debugbar->invoke('clamp_history_value',$resource));
	fclose($resource);
});

test('history comparison linking and client diagnostics describe every threshold boundary',static function(Context $t): void {
	$debugbar=$t->nonPublic(dataphyre_flightdeck_debugbar::class);
	$t->same(null,$debugbar->invoke('previous_comparable_snapshot',[],['method'=>'']));
	$t->same(null,$debugbar->invoke('comparison_change',
		['request'=>['status'=>200]],
		['request'=>['status'=>200]],
		['key'=>'status','label'=>'Status','unit'=>'status'],
	));
	$t->hasPathValues([
		'direction'=>'better','tone'=>'good','significant'=>true,
	],$debugbar->invoke('comparison_change',
		['duration_ms'=>10.0],
		['duration_ms'=>100.0],
		['key'=>'duration_ms','label'=>'Duration','unit'=>'ms','warn_delta'=>1.0],
	));
	$t->same('+5',$debugbar->invoke('comparison_delta_label',5.0,0.0,'count'));
	$t->same('1.25mb',$debugbar->invoke('comparison_value_label',1.25,'mb'));

	$explicitSource=$debugbar->invoke('normalize_client_events',[['type'=>'accessibility_policy',
		'a11y_field_source'=>'split_fields',
		'a11y_issues'=>[['name'=>'email','issues'=>['width_constrained']]],
	]]);
	$t->same('split_fields',$explicitSource[0]['a11y_field_source']);
	$t->count(1,$debugbar->invoke('normalize_accessibility_fields',[
		'invalid',['name'=>'email','issues'=>['width_constrained']],
	]));

	$accessibilityEvents=[];
	for($index=0;$index<9;$index++){
		$accessibilityEvents[]=[
			'type'=>'accessibility_policy','timestamp'=>(float)$index,
			'a11y_issue_count'=>0,'a11y_adjustment_count'=>0,'a11y_checked'=>1,
		];
	}
	$accessibilityState=$debugbar->invoke('client_state_from_events',$accessibilityEvents);
	$t->same(9,$accessibilityState['accessibility_policy_events']);
	$t->count(8,$accessibilityState['accessibility_events']);
	$t->same(['width_constrained'=>1],$debugbar->invoke('accessibility_token_counts',[
		null,['issues'=>['width_constrained']],
	],'issues'));

	$event=['url'=>'https://example.test/orders','method'=>'GET'];
	$origin=['id'=>'origin','method'=>'GET','request'=>['path'=>'/orders','host'=>'example.test']];
	$wrongMethod=['id'=>'wrong-method','method'=>'POST','request'=>['path'=>'/orders','host'=>'example.test']];
	$wrongHost=['id'=>'wrong-host','method'=>'GET','request'=>['path'=>'/orders','host'=>'other.test']];
	$matching=['id'=>'matching','method'=>'GET','request'=>['path'=>'/orders','host'=>'example.test']];
	$t->same($matching,$debugbar->invoke('matching_snapshot_for_client_event',
		$event,[$origin,$wrongMethod,$wrongHost,$matching],'origin',
	));
	$t->same('///broken',$debugbar->invoke('client_event_target',['url'=>'///broken?mode=1'])['path']);
	$t->same('/from-uri',$debugbar->invoke('snapshot_target',[
		'uri'=>'https://example.test/from-uri?mode=1','request'=>['path'=>''],
	])['path']);

	$diagnostics=$debugbar->invokeCases([
		'adjusted'=>['method'=>'with_client_diagnostics','arguments'=>[[],['accessibility_adjustments'=>2]]],
		'unverified_response'=>['method'=>'with_client_diagnostics','arguments'=>[[],['production_replay'=>[
			'response_status'=>200,'replay_responded'=>1,'replay_verified'=>0,
		]]]],
		'no_response'=>['method'=>'with_client_diagnostics','arguments'=>[[],['production_replay'=>[
			'response_status'=>0,'replay_responded'=>0,'replay_verified'=>0,
		]]]],
		'client_error'=>['method'=>'with_client_diagnostics','arguments'=>[[],['production_replay'=>[
			'response_status'=>404,'replay_responded'=>1,'replay_verified'=>1,
		]]]],
		'slow_page'=>['method'=>'with_client_diagnostics','arguments'=>[[],['page_performance'=>[
			'load_ms'=>3500.0,'dom_content_loaded_ms'=>2600.0,
		]]]],
	]);
	$t->containsRows([['title'=>'Panel accessibility policies adjusted layout']],$diagnostics['adjusted']['findings']);
	$t->containsRows([['title'=>'Production replay responded without Dataphyre metrics']],$diagnostics['unverified_response']['findings']);
	$t->containsRows([['title'=>'Production replay did not return an HTTP response']],$diagnostics['no_response']['findings']);
	$t->containsRows([['title'=>'Production replay returned HTTP 404']],$diagnostics['client_error']['findings']);
	$t->containsRows([['title'=>'Browser page load is slow']],$diagnostics['slow_page']['findings']);

	include dirname(__DIR__).'/kernel/debugbar/history.php';
});
