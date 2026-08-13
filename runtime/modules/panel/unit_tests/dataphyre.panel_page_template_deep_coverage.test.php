<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelPageTemplate;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

test('panel page template renders every structured section safely',static function(Context $t): void {
	$sections=[
		null,[],
		['type'=>'hero','eyebrow'=>'Overview','title'=>'Dashboard <unsafe>','body'=>'Welcome & inspect','actions'=>[
			['url'=>'/orders','label'=>'Orders'],['href'=>'javascript:alert(1)'],
		],'aside'=>[
			['label'=>'Tenant','value'=>'North','detail'=>'Active'],['label'=>'Mode','value'=>'Live'],
		]],
		['type'=>'metrics','items'=>[
			['label'=>'Orders','value'=>12,'detail'=>'Today','tone'=>'success'],
			['label'=>'Errors','tone'=>'invalid'],
		]],
		['type'=>'quick_actions','eyebrow'=>'Do','title'=>'Quick actions','description'=>'Common work','items'=>[
			['title'=>'Create','body'=>'Create order','url'=>'/orders/create'],['label'=>'Export','href'=>'/orders/export'],
		]],
		['type'=>'activity_grid','panels'=>[
			['eyebrow'=>'Live','title'=>'Recent','tone'=>'info','items'=>[
				['title'=>'Order 1','subtitle'=>'New','meta'=>'Now','url'=>'/orders/1'],
				['title'=>'Order 2','body'=>'Updated','href'=>'/orders/2'],
			]],
			['title'=>'Empty panel','tone'=>'invalid','items'=>[],'empty_title'=>'Quiet','empty_body'=>'No events'],
		]],
		['type'=>'card_grid','eyebrow'=>'Explore','title'=>'Areas','body'=>'Choose one','items'=>[
			['title'=>'Orders','body'=>'Manage orders','url'=>'/orders','action_label'=>'Open orders','tags'=>['Live','Core']],
			['title'=>'People','href'=>'/people','items'=>[]],
		]],
		['type'=>'swatches','title'=>'Palette','items'=>[
			['label'=>'Short','value'=>'#abc','detail'=>'Accent'],
			['label'=>'Long','value'=>'AABBCC'],
			['label'=>'Invalid','value'=>'red'],
		]],
		['type'=>'facts','eyebrow'=>'Facts','title'=>'Details','body'=>'Account facts','items'=>[
			['label'=>'Plan','value'=>'Pro','detail'=>'Annual'],['label'=>'Region','value'=>'CA'],
		]],
		['type'=>'sensitive_fields','title'=>'Secrets','items'=>[
			['label'=>'Token','revealed'=>true,'value'=>'secret','detail'=>'Audited'],
			['label'=>'Password','placeholder'=>'Masked','action'=>[
				'method'=>'invalid','url'=>'/reveal','label'=>'Reveal','hidden_fields'=>['id'=>9],
				'fields'=>[['type'=>'text','name'=>'reason','label'=>'Reason','required'=>true]],
			]],
			['label'=>'API key','action'=>['method'=>'get','href'=>'/request','label'=>'Request']],
		]],
		['type'=>'list','eyebrow'=>'Records','title'=>'Latest','body'=>'Recent records','items'=>[
			['title'=>'Order 1','subtitle'=>'Open','value'=>'$10','tone'=>'primary'],
			['label'=>'Order 2','body'=>'Closed','tone'=>'bad'],
		],'actions'=>[['url'=>'/orders','label'=>'View all']],'meta'=>'2 records'],
		['type'=>'conversation','eyebrow'=>'Inbox','list_title'=>'Chats','title'=>'Support','body'=>'Customer thread','state_label'=>'Online',
			'conversations'=>[
				['title'=>'Alice','subtitle'=>'Hello','active'=>true,'unread'=>3,'url'=>'/chat/1'],
				['label'=>'Bob','body'=>'Thanks','meta'=>'Yesterday','href'=>'/chat/2'],
			],
			'messages'=>[
				['id'=>5,'sender'=>'Alice','body'=>'Hello','time'=>'10:00'],
				['own'=>true,'title'=>'Me','subtitle'=>'Hi'],
			],
			'composer'=>[
				'method'=>'invalid','action'=>'/send','field_name'=>'reply','placeholder'=>'Reply','label'=>'Message',
				'button'=>'Send now','disabled'=>true,'no_ajax'=>true,'maxlength'=>250,'hidden_fields'=>['thread'=>1],
			],
			'realtime'=>['client'=>'socket','channel'=>'chat.1','token'=>'token','websocket'=>'wss://socket','current_user_id'=>9],
		],
		['type'=>'data_table','eyebrow'=>'Rows','title'=>'Orders','body'=>'All orders','columns'=>[
			['name'=>'name','label'=>'Name'],['name'=>'status','label'=>'Status'],['name'=>'actions','label'=>'Actions'],
		],'rows'=>[
			['name'=>'Order 1','status'=>['label'=>'Open'],'actions'=>['type'=>'actions','actions'=>[
				['method'=>'get','url'=>'/orders/1','label'=>'Open','tone'=>'primary'],
				['method'=>'invalid','url'=>'/orders/1/delete','label'=>'Delete','tone'=>'danger','hidden_fields'=>['id'=>1]],
			]]],
			'not-an-array',
		],'actions'=>[['url'=>'/orders/create','label'=>'Create']]],
		['type'=>'form','eyebrow'=>'Edit','title'=>'Profile','description'=>'Update details','method'=>'invalid','action'=>'/save','compact'=>true,'no_ajax'=>true,
			'csrf'=>true,'hidden_fields'=>['id'=>7,['name'=>'scope','value'=>'panel'],''],
			'hidden_html'=>'<input type="hidden" name="trusted" value="1">',
			'fields'=>[
				['type'=>'hidden','name'=>'id','value'=>'7'],
				['type'=>'checkbox','name'=>'enabled','label'=>'Enabled','checked'=>true,'required'=>true,'disabled'=>true,'wide'=>true],
				['type'=>'select','name'=>'status','label'=>'Status','value'=>'open','options'=>[
					['value'=>'open','label'=>'Open'],['value'=>'closed','label'=>'Closed','disabled'=>true],
				]],
				['type'=>'relationship','name'=>'owner','label'=>'Owner','value'=>'2','searchable'=>true,'search_placeholder'=>'Find owner','no_results_text'=>'None','options'=>[
					['label'=>'Team','options'=>[['value'=>'1','label'=>'Alice'],['value'=>'2','label'=>'Bob','disabled'=>true]]],
				]],
				['type'=>'textarea','name'=>'notes','label'=>'Notes','value'=>'Text <safe>','wide'=>true],
				['type'=>'email','name'=>'email','label'=>'Email','value'=>'a@example.test','placeholder'=>'Email','maxlength'=>100,'inputmode'=>'email','required'=>true],
				['type'=>'unsupported','name'=>'fallback','label'=>'Fallback'],
			],
			'actions'=>[
				['type'=>'reset','label'=>'Reset'],['type'=>'invalid','name'=>'intent','value'=>'save','label'=>'Save','disabled'=>true],
			],
			'badges'=>[['label'=>'Draft','tone'=>'warning'],['label'=>'Other','tone'=>'invalid']],
		],
		['type'=>'empty','title'=>'Nothing','description'=>'Try later'],
		['type'=>'notice','eyebrow'=>'Note','title'=>'Saved','body'=>'All good','tone'=>'success'],
		['type'=>'realtime_client','client'=>'socket-client','script'=>'/assets/socket.js','channel'=>'orders','token'=>'abc','websocket'=>'wss://socket'],
		['type'=>'document_content','eyebrow'=>'Document','title'=>'Terms','body'=>'Read carefully','paragraphs'=>[
			'First paragraph',['text'=>'Second paragraph'],['body'=>''],
		]],
		['type'=>'unknown','eyebrow'=>'Custom','title'=>'Trusted','body'=>'Body','content'=>'<b>Trusted HTML</b>'],
	];
	$template=PanelPageTemplate::make($sections);
	$html=$template->render();
	$t->same($html,(string)$template);
	$t->contains('dp-panel-template-hero',$html);
	$t->contains('dp-panel-template-stats',$html);
	$t->contains('dp-panel-template-chat',$html);
	$t->contains('dp-panel-template-table',$html);
	$t->contains('dp-panel-template-form',$html);
	$t->contains('socket.js',$html);
	$t->contains('&lt;unsafe&gt;',$html);
	$t->contains('<b>Trusted HTML</b>',$html);
	$t->notContains('javascript:alert',$html);
})->tag('panel','page-template','coverage')->group('framework-coverage');

test('panel page template renders empty states and residual section branches',static function(Context $t): void {
	$t->same('',PanelPageTemplate::make([])->render());
	$t->same('',PanelPageTemplate::make([null,[]])->render());
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('stats',['items'=>'invalid']));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('activityGrid',['panels'=>[]]));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('cardGrid',['items'=>[]]));
	$t->contains('No confidential fields',$t->nonPublic(PanelPageTemplate::class)->invoke('confidentialFields',[]));
	$t->contains('Nothing to show',$t->nonPublic(PanelPageTemplate::class)->invoke('recordList',[]));
	$t->contains('No conversations',$t->nonPublic(PanelPageTemplate::class)->invoke('chat',[]));
	$t->contains('No records',$t->nonPublic(PanelPageTemplate::class)->invoke('table',['columns'=>[['name'=>'id']],'rows'=>[]]));
	$t->contains('<tbody></tbody>',$t->nonPublic(PanelPageTemplate::class)->invoke('table',['columns'=>[],'rows'=>[]]));
	$paginated=$t->nonPublic(PanelPageTemplate::class)->invoke('table',[
		'columns'=>[['name'=>'id','label'=>'ID']],
		'rows'=>[['id'=>7]],
		'pagination'=>[
			'previous_url'=>'/panel/incidents?cursor=before',
			'next_url'=>'javascript:alert(1)',
			'item_count'=>1,
			'current_page'=>2,
		],
	]);
	$t->contains('class="dp-panel-pagination"',$paginated);
	$t->contains('rel="prev"',$paginated);
	$t->contains('Page 2',$paginated);
	$t->contains('aria-disabled="true"',$paginated);
	$t->same(false,str_contains($paginated,'javascript:'));
	$t->contains('No document content',$t->nonPublic(PanelPageTemplate::class)->invoke('documentContent',[]));
	$t->contains('script',$t->nonPublic(PanelPageTemplate::class)->invoke('realtimeClient',['client'=>'socket','script'=>'https://cdn.test/client.js','script_only'=>true]));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('realtimeClient',['client'=>'','script'=>'/client.js']));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('realtimeClient',['client'=>'socket','script'=>'javascript:bad']));

	$hero=$t->nonPublic(PanelPageTemplate::class)->invoke('hero',['title'=>'Plain']);
	$t->notContains('dp-panel-template-actions',$hero);
	$t->contains('dp-panel-template-action-list',$t->nonPublic(PanelPageTemplate::class)->invoke('actionList',[]));
	$t->contains('dp-panel-template-description-list',$t->nonPublic(PanelPageTemplate::class)->invoke('descriptionList',[]));
	$t->contains('dp-panel-template-color-swatches',$t->nonPublic(PanelPageTemplate::class)->invoke('colorSwatches',[]));
	$t->contains('dp-panel-template-form',$t->nonPublic(PanelPageTemplate::class)->invoke('form',[]));
	$t->contains('dp-panel-template-notice',$t->nonPublic(PanelPageTemplate::class)->invoke('notice',['tone'=>'bad']));
	$t->contains('<section',$t->nonPublic(PanelPageTemplate::class)->invoke('emptyState',['title'=>'Empty'],'invalid'));
	$t->contains('<div',$t->nonPublic(PanelPageTemplate::class)->invoke('emptyState',['title'=>'Empty','body'=>'Details'],'div'));
	$t->contains('<span>Details</span>',$t->nonPublic(PanelPageTemplate::class)->invoke('emptyState',['title'=>'Empty','description'=>'Details']));
})->tag('panel','page-template','coverage')->group('framework-coverage');

test('panel page template normalizes fields cells collections and urls',static function(Context $t): void {
	$t->same([],$t->nonPublic(PanelPageTemplate::class)->invoke('list','invalid'));
	$t->same(['a','b'],$t->nonPublic(PanelPageTemplate::class)->invoke('list',['x'=>'a','y'=>'b']));
	$t->same('success',$t->nonPublic(PanelPageTemplate::class)->invoke('tone',' SUCCESS '));
	$t->same('neutral',$t->nonPublic(PanelPageTemplate::class)->invoke('tone','invalid'));
	$t->same('#aabbcc',$t->nonPublic(PanelPageTemplate::class)->invoke('hexColor',' #AbC '));
	$t->same('#aabbcc',$t->nonPublic(PanelPageTemplate::class)->invoke('hexColor','AABBCC'));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('hexColor','red'));

	foreach([
		['', '#'],["/line\nbreak",'#'],['//host/path','#'],['\\server\\path','#'],
		['javascript:alert(1)','#'],['data:text/html,bad','#'],['https://example.test','https://example.test'],
		['mailto:user@example.test','mailto:user@example.test'],['tel:+15555555555','tel:+15555555555'],['/local','/local'],
	] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(PanelPageTemplate::class)->invoke('href',$value));
	}
	foreach([
		['',''],["/line\nbreak",''],['//host/script.js',''],['\\server\\script.js',''],['javascript:bad',''],
		['/script.js','/script.js'],['./script.js','./script.js'],['../script.js','../script.js'],
		['https://cdn.test/script.js','https://cdn.test/script.js'],['http://cdn.test/script.js','http://cdn.test/script.js'],['relative.js',''],
	] as [$value,$expected]){
		$t->same($expected,$t->nonPublic(PanelPageTemplate::class)->invoke('scriptUrl',$value));
	}
	$t->same('&lt;&quot;&amp;',$t->nonPublic(PanelPageTemplate::class)->invoke('e','<"&'));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('eyebrow',[]));
	$t->contains('Eyebrow',$t->nonPublic(PanelPageTemplate::class)->invoke('eyebrow',['eyebrow'=>'Eyebrow']));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('body',[]));
	$t->contains('<p>Body</p>',$t->nonPublic(PanelPageTemplate::class)->invoke('body',['body'=>'Body'],'invalid'));
	$t->contains('<span>Description</span>',$t->nonPublic(PanelPageTemplate::class)->invoke('body',['description'=>'Description'],'span'));

	$t->same('plain',$t->nonPublic(PanelPageTemplate::class)->invoke('tableCell','plain'));
	$t->same('Label',$t->nonPublic(PanelPageTemplate::class)->invoke('tableCell',['label'=>'Label']));
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('tableCell',['type'=>'actions','actions'=>[]]));
	$t->contains('<a',$t->nonPublic(PanelPageTemplate::class)->invoke('tableCell',['type'=>'form_actions','actions'=>[['method'=>'get','href'=>'/open']]]));
	$t->contains('<form',$t->nonPublic(PanelPageTemplate::class)->invoke('tableCell',['type'=>'actions','actions'=>[['method'=>'bad','href'=>'/save','hidden_fields'=>['id'=>1]]]]));

	$hidden=$t->nonPublic(PanelPageTemplate::class)->invoke('hiddenFields',['csrf'=>true,'hidden_fields'=>[
		'id'=>1,['name'=>'named','value'=>'value'],['name'=>'','value'=>'skip'],
	]]);
	$t->contains('name="id"',$hidden);
	$t->contains('name="named"',$hidden);
	$t->notContains('skip',$hidden);
	$t->same('',$t->nonPublic(PanelPageTemplate::class)->invoke('hiddenFields',['hidden_fields'=>'invalid']));

	$t->contains('type="hidden"',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'hidden','name'=>'id','value'=>'1']));
	$t->contains('type="checkbox"',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'checkbox','name'=>'yes','label'=>'Yes']));
	$t->contains('<select',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'select','name'=>'state','options'=>[['label'=>'Open']]]));
	$t->contains('dp-panel-searchable-select',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'searchable_select','name'=>'state','label'=>'State','options'=>[]]));
	$t->contains('<textarea',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'textarea','name'=>'body','value'=>'Text']));
	$t->contains('type="text"',$t->nonPublic(PanelPageTemplate::class)->invoke('formField',['type'=>'bad','name'=>'fallback']));
	$t->contains('dp-panel-searchable-select',$t->nonPublic(PanelPageTemplate::class)->invoke('searchableSelect','owner','Owner',[],'<select></select>'));
})->tag('panel','page-template','coverage')->group('framework-coverage');
