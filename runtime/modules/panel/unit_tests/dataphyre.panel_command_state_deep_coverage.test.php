<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCommandState;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);


/** @return array<int,mixed> */
function dp_panel_command_state_definitions(): array {
	return [
		'skip scalar',
		42,
		[
			'name'=>'open-orders','label'=>'Open orders','group'=>'Operations','url'=>' /panel/orders ',
			'description'=>'Review pending orders','icon'=>' shopping-cart ','new_tab'=>true,'sort'=>20,
			'keywords'=>['pending','','fulfillment'],'source'=>' Resource ','tone'=>' Primary ',
			'client_action'=>' Open Modal ','meta'=>['client_action'=>'ignored','custom'=>true],
		],
		[
			'name'=>'dashboard','category'=>'Navigation','href'=>'/panel','sort'=>10,
			'keywords'=>['home'],'source'=>'navigation','meta'=>['client_action'=>'focus_search'],
		],
		[
			'name'=>'alpha','label'=>'Alpha','group'=>'Operations','sort'=>20,
			'description'=>'Alphabetical command','keywords'=>'invalid','meta'=>'invalid',
		],
		[
			'name'=>'','label'=>'','group'=>' ','sort'=>100,'source'=>' ','tone'=>' ','client_action'=>' ',
		],
	];
}

test('panel command state constructs exposes and serializes supplied payloads',static function(Context $t): void {
	$commands=[['name'=>'one']];
	$groups=[['label'=>'Group','count'=>1,'commands'=>$commands]];
	$matched=[['name'=>'one']];
	$state=new PanelCommandState($commands,$groups,$matched,'query',['custom'=>true]);
	$t->same($commands,$state->commands());
	$t->same($groups,$state->groups());
	$t->same($matched,$state->matched());
	$t->same('query',$state->query());
	$t->same(['custom'=>true],$state->meta());
	$t->same([
		'commands'=>$commands,'groups'=>$groups,'matched'=>$matched,'query'=>'query','meta'=>['custom'=>true],
	],$state->jsonSerialize());
	$t->same($state->jsonSerialize(),json_decode((string)json_encode($state),true));
})->tag('panel','command-state','coverage')->group('framework-coverage');

test('panel command state normalizes sorts groups and merges request metadata',static function(Context $t): void {
	$request=PanelRequest::fromArray([
		'resource'=>'orders','operation'=>'index','query'=>['command'=>'fallback','command_search'=>' pending '],
		'user'=>['id'=>7],
	]);
	$state=PanelCommandState::make(dp_panel_command_state_definitions(),$request,null,[
		'custom'=>'value','match_count'=>99,
	]);
	$t->same('pending',$state->query());
	$t->same(4,count($state->commands()));
	$t->same(['dashboard','alpha','open-orders',''],array_column($state->commands(),'name'));
	$t->same(3,count($state->groups()));
	$t->same(['Navigation','Operations','Commands'],array_column($state->groups(),'label'));
	$t->same(1,count($state->matched()));
	$t->same('open-orders',$state->matched()[0]['name']);
	$t->same('value',$state->meta()['custom']);
	$t->same(4,$state->meta()['command_count']);
	$t->same(3,$state->meta()['group_count']);
	$t->same(99,$state->meta()['match_count']);
	$t->same('orders',$state->meta()['request']['resource']);

	$open=array_values(array_filter($state->commands(),static fn(array $command): bool=>$command['name']==='open-orders'))[0];
	$t->same('Open orders',$open['label']);
	$t->same('Operations',$open['category']);
	$t->same('/panel/orders',$open['url']);
	$t->same('/panel/orders',$open['href']);
	$t->isTrue($open['new_tab']);
	$t->same(['pending','fulfillment'],$open['keywords']);
	$t->same('resource',$open['source']);
	$t->same('primary',$open['tone']);
	$t->same('open_modal',$open['client_action']);
	$t->isTrue($open['meta']['custom']);

	$dashboard=$state->commands()[0];
	$t->same('Dashboard',$dashboard['label']);
	$t->same('Navigation',$dashboard['group']);
	$t->same('/panel',$dashboard['url']);
	$t->same('focus_search',$dashboard['client_action']);

	$fallback=$state->commands()[3];
	$t->same('Command',$fallback['label']);
	$t->same('Commands',$fallback['group']);
	$t->same('panel',$fallback['source']);
	$t->same('neutral',$fallback['tone']);
	$t->same(null,$fallback['description']);
	$t->same(null,$fallback['icon']);
	$t->same(null,$fallback['client_action']);
})->tag('panel','command-state','coverage')->group('framework-coverage');

test('panel command state resolves query precedence and matches every searchable field',static function(Context $t): void {
	$commandStateInternals=$t->nonPublic(PanelCommandState::class);
	$commands=dp_panel_command_state_definitions();
	$request=PanelRequest::fromArray(['query'=>['command'=>' dashboard ','command_search'=>' ']]);
	$t->same('explicit',PanelCommandState::make($commands,$request,' explicit ')->query());
	$t->same('',PanelCommandState::make($commands,$request,null)->query());
	$t->same('dashboard',PanelCommandState::make($commands,PanelRequest::fromArray(['query'=>['command'=>' dashboard ']]))->query());
	$t->same('',PanelCommandState::make($commands,null,null)->query());
	$t->same(4,count(PanelCommandState::make($commands)->matched()));

	$normalized=PanelCommandState::make($commands)->commands();
	foreach([
		'open orders'=>'open-orders',
		'operations'=>'alpha',
		'alphabetical'=>'alpha',
		'fulfillment'=>'open-orders',
		'navigation'=>'dashboard',
	] as $query=>$expected){
		$matches=$commandStateInternals->invoke('matchCommands',$normalized,$query);
		$t->isTrue($matches!==[]);
		$t->same($expected,$matches[0]['name']);
	}
	$t->same([],$commandStateInternals->invoke('matchCommands',$normalized,'missing value'));
	$t->same($normalized,$commandStateInternals->invoke('matchCommands',$normalized,'   '));

	$groups=$commandStateInternals->invoke('groupCommands',$normalized);
	$t->same(3,count($groups));
	$t->same(2,$groups[1]['count']);
	$t->same('Operations',$groups[1]['label']);
	$t->same([],$commandStateInternals->invoke('groupCommands',[]));

	$full=$commandStateInternals->invoke('normalizeCommand',[
		'name'=>'run-report','label'=>' ','category'=>' ','href'=>' /report ','description'=>' desc ','icon'=>' icon ',
		'new_tab'=>false,'sort'=>'5','keywords'=>['one',0,false,null],'source'=>' ','tone'=>' ','meta'=>['client_action'=>'refresh'],
	]);
	$t->same('Run Report',$full['label']);
	$t->same('Commands',$full['group']);
	$t->same(['one'],$full['keywords']);
	$t->same('refresh',$full['client_action']);
	$t->same('desc',$full['description']);
	$t->same('icon',$full['icon']);
	$t->isFalse($full['new_tab']);
	$t->same(5,$full['sort']);

	$t->same('Run Report',$commandStateInternals->invoke('humanize','run_report'));
	$t->same('Command',$commandStateInternals->invoke('humanize','---'));
})->tag('panel','command-state','coverage')->group('framework-coverage');
