<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Column;
use Dataphyre\Panel\PanelComponentRegistry;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\RelationManager;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel','mvc']);

function dp_panel_record_sections_request(): PanelRequest {
	return PanelRequest::fromArray([
		'method'=>'GET','resource'=>'section-records','operation'=>'show','record'=>'R 1',
		'query'=>['tab'=>'details'],'user'=>['id'=>7,'name'=>'Operator'],
	]);
}

/** @return array<string,mixed> */
function dp_panel_record_sections_record(): array {
	return ['id'=>'R 1','name'=>'Section Record'];
}

function dp_panel_record_sections_resource(): Resource {
	return Resource::make('section-records')
		->label('Section Record')->pluralLabel('Section Records')
		->recordKeyUsing('id')->recordTitleUsing('name')
		->alertsUsing(static fn(): array=>[
			['title'=>'Risk <alert>','message'=>'Needs review','tone'=>'bad','url'=>'/panel/risk','action'=>'Open risk','meta'=>['High','',2]],
			['label'=>'Notice','body'=>'','href'=>'javascript:bad','action'=>''],
		])
		->insightsUsing(static fn(): array=>[
			['label'=>'Revenue','value'=>123,'description'=>'This month','tone'=>'success','icon'=>'$','url'=>'/panel/revenue'],
			['title'=>'Orders','metric'=>4],
		])
		->linksUsing(static fn(): array=>[
			['label'=>'Internal','url'=>'/panel/orders','description'=>'Orders','group'=>'Panel','icon'=>'link','tone'=>'info'],
			['title'=>'External','href'=>'https://example.test/path','type'=>'Docs','external'=>false],
		])
		->contactsUsing(static fn(): array=>[
			['name'=>'Ada','role'=>'Owner','email'=>'ada@example.test','phone'=>'+1 555','company'=>'Shopiro','location'=>'Toronto','status'=>'verified','url'=>'/panel/people/ada'],
			['label'=>'No details','state'=>'blocked'],
		])
		->locationsUsing(static fn(): array=>[
			['label'=>'Office','type'=>'HQ','status'=>'verified','address'=>'1 Main','address2'=>'Suite 2','city'=>'Toronto','province'=>'ON','postal'=>'A1A 1A1','country'=>'CA','lat'=>'43','lng'=>'-79','timezone'=>'America/Toronto','url'=>'https://maps.example.test'],
			['name'=>'Warehouse','street'=>'2 Side'],
		])
		->tagsUsing(static fn(): array=>[
			['name'=>'vip','label'=>'VIP','description'=>'Priority customer','tone'=>'warning'],
			'active',
		])
		->updateTagUsing(static fn(): bool=>true)
		->itemsUsing(static fn(): array=>[
			['title'=>'Widget','sku'=>'W-1','type'=>'product','quantity'=>2,'unit_price'=>10,'total'=>20,'currency'=>'CAD','status'=>'fulfilled','url'=>'/panel/items/1'],
			['service'=>'Consulting','rate'=>'USD 50','amount'=>50,'currency'=>'USD'],
		])
		->totalsUsing(static fn(): array=>[
			'currency'=>'CAD','subtotal'=>100,
			'tax'=>['label'=>'Tax','amount'=>13,'description'=>'HST','status'=>'due'],
			'paid'=>['title'=>'Paid','paid'=>'CAD 50','state'=>'settled'],
		])
		->approvalsUsing(static fn(): array=>[
			['name'=>'manager','title'=>'Manager approval','description'=>'Required','status'=>'pending','requester'=>'Ada','time'=>'Today','due'=>'Tomorrow'],
			['id'=>'finance','label'=>'Finance','status'=>'approved'],
			['key'=>'legal','title'=>'Legal','status'=>'rejected'],
		])
		->resolveApprovalUsing(static fn(): bool=>true)
		->activityUsing(static fn(): array=>[
			['title'=>'Created','message'=>'Record created','time'=>'Today','actor'=>'Ada','tone'=>'success','url'=>'/panel/activity/1','meta'=>['API','']],
			'Viewed',
		])
		->changesUsing(static fn(): array=>[
			['field'=>'status','before'=>'draft','after'=>'active','reason'=>'Approved','time'=>'Today','actor'=>'Ada','url'=>'/panel/change/1','tone'=>'info'],
			'owner'=>'Bob',
		])
		->paymentsUsing(static fn(): array=>[
			['title'=>'Charge','amount'=>25,'currency'=>'CAD','status'=>'paid','type'=>'card_payment','provider'=>'Stripe','reference'=>'ch_1','time'=>'Today','url'=>'https://dashboard.example.test'],
			['kind'=>'refund','amount_label'=>'USD 5','state'=>'refunded'],
		])
		->shipmentsUsing(static fn(): array=>[
			['title'=>'Shipment 1','tracking'=>'TRACK1','carrier'=>'UPS','service'=>'Express','status'=>'in_transit','eta'=>'Tomorrow','origin'=>'Toronto','destination'=>'Montreal','url'=>'https://tracking.example.test'],
			['label'=>'Delivered','tracking_number'=>'TRACK2','state'=>'delivered'],
		])
		->notesUsing(static fn(): array=>[
			['message'=>'Important note','author'=>'Ada','time'=>'Today'],
			['note'=>''],
		])
		->addNoteUsing(static fn(): bool=>true)
		->attachmentsUsing(static fn(): array=>[
			['name'=>'invoice.pdf','url'=>'/files/invoice.pdf','size'=>2048,'type'=>'application/pdf','time'=>'Today','author'=>'Ada'],
			['filename'=>'local.txt','bytes'=>10],
			['name'=>'','url'=>''],
		])
		->attachUsing(static fn(): bool=>true)
		->messagesUsing(static fn(): array=>[
			['subject'=>'Welcome','body'=>'Hello','channel'=>'email','status'=>'sent','recipient'=>'customer','sender'=>'Ada','time'=>'Today'],
			['message'=>'Body only','type'=>'sms','state'=>'failed'],
			['subject'=>'','body'=>''],
		])
		->sendMessageUsing(static fn(): bool=>true)
		->tasksUsing(static fn(): array=>[
			['name'=>'call','title'=>'Call customer','description'=>'Follow up','completed'=>false,'due'=>'Tomorrow','assignee'=>'Ada','status'=>'waiting'],
			['id'=>'archive','label'=>'Archive record','status'=>'completed'],
		])
		->updateTaskUsing(static fn(): bool=>true)
		->createTaskUsing(static fn(): bool=>true);
}

test('panel renderer record sections normalizes attention insight link contact location and tag payloads',static function(Context $t): void {
	$alerts=$t->nonPublic(PanelRenderer::class)->invoke('normalizeAlertItems',['Named'=>'Message',7,null,['title'=>'','message'=>''],[
		'label'=>'Alias','detail'=>'Detail','status'=>'danger','href'=>'/safe','button'=>'Go','meta'=>'one',
	]]);
	$t->same(3,count($alerts));
	$t->same('Named',$alerts[0]['title']);
	$t->same(['one'],$alerts[2]['meta']);
	$t->same('/safe',$alerts[2]['url']);

	$insights=$t->nonPublic(PanelRenderer::class)->invoke('normalizeInsightItems',[
		'Count'=>3,true,null,['title'=>'Alias','count'=>5,'detail'=>'Detail','status'=>'info','icon'=>'i','href'=>'/insight'],
		['label'=>'','metric'=>'x'],
	]);
	$t->same(4,count($insights));
	$t->same(true,$insights[1]['value']);
	$t->same('/insight',$insights[2]['url']);

	$links=$t->nonPublic(PanelRenderer::class)->invoke('normalizeLinkItems',[
		'Docs'=>'https://example.test/docs','javascript:bad',null,
		['title'=>'Profile','to'=>'/panel/profile','detail'=>'Details','type'=>'People','status'=>'info','icon'=>'user','external'=>true],
		['label'=>'','url'=>'/fallback'],
	]);
	$t->same(3,count($links));
	$t->same('Docs',$links[0]['label']);
	$t->same('/panel/profile',$links[1]['url']);
	$t->isTrue($links[1]['external']);

	$contacts=$t->nonPublic(PanelRenderer::class)->invoke('normalizeContactItems',[
		'Ada'=>'ada@example.test','Bob',null,
		['title'=>'Carol','type'=>'Owner','mail'=>'c@example.test','mobile'=>'555','organization'=>'Co','city'=>'MTL','state'=>'active','profile_url'=>'/people/c'],
		['name'=>'','status'=>'blocked'],
	]);
	$t->same(4,count($contacts));
	$t->same('ada@example.test',$contacts[0]['email']);
	$t->same('success',$contacts[2]['tone']);
	$t->same('danger',$contacts[3]['tone']);

	$locations=$t->nonPublic(PanelRenderer::class)->invoke('normalizeLocationItems',[
		'Home'=>'1 Main',null,
		['title'=>'Office','kind'=>'HQ','state'=>'invalid','line1'=>'2 Main','suite'=>'3','locality'=>'Toronto','region'=>'ON','zip'=>'A1','country_code'=>'CA','latitude'=>'43','longitude'=>'-79','tz'=>'UTC','map_url'=>'https://maps.test'],
		['label'=>'','status'=>'verified'],
	]);
	$t->same(3,count($locations));
	$t->same(['1 Main'],$locations[0]['address_lines']);
	$t->same('43, -79',$locations[1]['coordinates']);
	$t->same('danger',$locations[1]['tone']);
	$t->same('success',$locations[2]['tone']);

	$tags=$t->nonPublic(PanelRenderer::class)->invoke('normalizeTagItems',[
		'vip',4,null,[],['label'=>'Friendly','detail'=>'Description','status'=>'info'],['name'=>'','label'=>''],
	]);
	$t->same(3,count($tags));
	$t->same('vip',$tags[0]['name']);
	$t->same('friendly',$tags[2]['name']);
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');

test('panel renderer record sections normalizes ledger approval activity change payment and shipment payloads',static function(Context $t): void {
	$items=$t->nonPublic(PanelRenderer::class)->invoke('normalizeRecordItems',[
		'Widget',3,null,
		['product'=>'Product','code'=>'P1','category'=>'goods','qty'=>2,'price'=>10,'subtotal'=>20,'currency'=>'cad','state'=>'cancelled','item_url'=>'/item'],
		['title'=>'','status'=>'active'],
	]);
	$t->same(4,count($items));
	$t->same('CAD 10',$items[2]['unit_price']);
	$t->same('danger',$items[2]['tone']);
	$t->same('success',$items[3]['tone']);
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('itemMoneyValue','','CAD'));
	$t->same('10',$t->nonPublic(PanelRenderer::class)->invoke('itemMoneyValue','10',''));
	$t->same('CAD 10',$t->nonPublic(PanelRenderer::class)->invoke('itemMoneyValue','10','CAD'));
	$t->same('CAD 10',$t->nonPublic(PanelRenderer::class)->invoke('itemMoneyValue','CAD 10','CAD'));

	$totals=$t->nonPublic(PanelRenderer::class)->invoke('normalizeTotalItems',['currency'=>'cad','subtotal'=>100,null,
		'tax'=>['title'=>'tax_total','amount'=>13,'detail'=>'Tax','state'=>'due'],
		'paid'=>['name'=>'paid','paid'=>'CAD 50','status'=>'paid'],
		'empty'=>false,
	]);
	$t->same(3,count($totals));
	$t->same('CAD 100',$totals[0]['value']);
	$t->same('warning',$totals[1]['tone']);
	$t->same('success',$totals[2]['tone']);

	$approvals=$t->nonPublic(PanelRenderer::class)->invoke('normalizeApprovalItems',[
		'Manager approval',2,null,
		'finance'=>['label'=>'Finance','state'=>'','requested_by'=>'Ada','requested_at'=>'Today','deadline'=>'Tomorrow','message'=>'Please review'],
		['name'=>'','title'=>'','status'=>'approved'],
	]);
	$t->same(4,count($approvals));
	$t->same('finance',$approvals[2]['name']);
	$t->same('pending',$approvals[2]['status']);

	$activity=$t->nonPublic(PanelRenderer::class)->invoke('normalizeActivityItems',[
		'Created',4,null,
		['event'=>'Updated','description'=>'Changed','at'=>'Today','by'=>'Ada','status'=>'bad','url'=>'/activity','meta'=>'API'],
		['title'=>'','time'=>null,'actor'=>null],
	]);
	$t->same(4,count($activity));
	$t->same(['API'],$activity[2]['meta']);
	$t->same('neutral',$activity[2]['tone']);

	$changes=$t->nonPublic(PanelRenderer::class)->invoke('normalizeChangeItems',[
		'owner'=>'Ada',null,
		['label'=>'Status','old'=>'draft','new'=>'active','changed_at'=>'Today','user'=>'Ada','message'=>'Approved','href'=>'/change'],
		['field'=>'','before'=>'','after'=>''],
		['field'=>'','before'=>'x','after'=>''],
	]);
	$t->same(3,count($changes));
	$t->same('owner',$changes[0]['field']);
	$t->same('/change',$changes[1]['url']);

	$payments=$t->nonPublic(PanelRenderer::class)->invoke('normalizePaymentItems',[
		'Charge'=>10,null,
		['kind'=>'refund','value'=>5,'currency'=>'usd','state'=>'refunded','processor'=>'Stripe','transaction_id'=>'tx','paid_at'=>'Today','dashboard_url'=>'https://dash.test'],
		['event'=>'capture','gross'=>7,'currency'=>'CAD','status'=>'failed'],
		['type'=>'payout','amount_label'=>'Custom','status'=>'completed','reference'=>null],
	]);
	$t->same(4,count($payments));
	$t->same('USD 5',$payments[1]['amount']);
	$t->same('warning',$payments[1]['tone']);
	$t->same('danger',$payments[2]['tone']);
	$t->same('success',$payments[3]['tone']);
	$t->same('Card Payment',$t->nonPublic(PanelRenderer::class)->invoke('humanPaymentType','card_payment'));
	$t->isTrue($t->nonPublic(PanelRenderer::class)->invoke('humanPaymentType','')!=='');

	$shipments=$t->nonPublic(PanelRenderer::class)->invoke('normalizeShipmentItems',[
		'Package'=>'TRACK',2,null,
		['label'=>'Express','tracking_number'=>'T2','provider'=>'UPS','method'=>'Air','state'=>'delayed','estimated_delivery'=>'Tomorrow','from'=>'A','to'=>'B','tracking_url'=>'https://track.test'],
		['title'=>'','status'=>'delivered'],['name'=>'Shipped','status'=>'shipped'],
	]);
	$t->same(5,count($shipments));
	$t->same('danger',$shipments[2]['tone']);
	$t->same('success',$shipments[3]['tone']);
	$t->same('info',$shipments[4]['tone']);
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');

test('panel renderer record sections normalizes note attachment message and task payloads',static function(Context $t): void {
	$notes=$t->nonPublic(PanelRenderer::class)->invoke('normalizeNoteItems',['Note',3,null,
		['body'=>'Body','actor'=>'Ada','created_at'=>'Today'],['message'=>'','author'=>null,'time'=>null],
	]);
	$t->same(4,count($notes));
	$t->same('Ada',$notes[2]['author']);
	$t->same('',$notes[3]['message']);

	$attachments=$t->nonPublic(PanelRenderer::class)->invoke('normalizeAttachmentItems',['/files/report.pdf',null,
		['filename'=>'named.txt','href'=>'/files/named.txt','mime'=>'text/plain','bytes'=>1024,'uploaded_at'=>'Today','by'=>'Ada'],
		['name'=>'','url'=>'/files/inferred.csv'],['title'=>'local','url'=>'javascript:bad','size'=>null,'time'=>null,'author'=>null],
	]);
	$t->same(4,count($attachments));
	$t->same('report.pdf',$attachments[0]['name']);
	$t->same('inferred.csv',$attachments[2]['name']);
	$t->same('',$attachments[3]['url']);

	$messages=$t->nonPublic(PanelRenderer::class)->invoke('normalizeMessageItems',['Message',null,
		['title'=>'Subject','message'=>'Body','type'=>'email','state'=>'sent','to'=>'Customer','from'=>'Ada','sent_at'=>'Today'],
		['content'=>'Failed','status'=>'failed'],['subject'=>'','body'=>''],
	]);
	$t->same(4,count($messages));
	$t->same('success',$messages[1]['tone']);
	$t->same('danger',$messages[2]['tone']);

	$tasks=$t->nonPublic(PanelRenderer::class)->invoke('normalizeTaskItems',[
		'Task',2,null,
		'call'=>['label'=>'Call','message'=>'Follow up','done'=>'yes','due_at'=>'Tomorrow','owner'=>'Ada'],
		['id'=>'blocked','title'=>'Blocked','status'=>'blocked'],
		['key'=>'waiting','title'=>'Waiting','status'=>'waiting'],
		['name'=>'closed','title'=>'Closed','status'=>'closed'],
		['name'=>'','title'=>''],
	]);
	$t->same(7,count($tasks));
	$t->isTrue($tasks[2]['completed']);
	$t->same('danger',$tasks[3]['tone']);
	$t->same('warning',$tasks[4]['tone']);
	$t->isTrue($tasks[5]['completed']);
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');

test('panel renderer record sections renders every read only detail surface',static function(Context $t): void {
	$resource=dp_panel_record_sections_resource();
	$request=dp_panel_record_sections_request();
	$record=dp_panel_record_sections_record();
	foreach([
		'alertsHtml'=>'dp-panel-alerts','insightsHtml'=>'dp-panel-insights','linksHtml'=>'dp-panel-links',
		'contactsHtml'=>'dp-panel-contacts','locationsHtml'=>'dp-panel-locations','itemsHtml'=>'dp-panel-items',
		'totalsHtml'=>'dp-panel-totals','activityHtml'=>'dp-panel-activity','changesHtml'=>'dp-panel-changes',
		'paymentsHtml'=>'dp-panel-payments','shipmentsHtml'=>'dp-panel-shipments',
	] as $method=>$needle){
		$html=$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$request,$record);
		$t->contains($needle,$html);
		$t->contains('&lt;',($method==='alertsHtml' ? $html : '&lt;'));
	}
	$t->contains('target="_blank"',$t->nonPublic(PanelRenderer::class)->invoke('linksHtml',$resource,$request,$record));
	$t->contains('mailto:',$t->nonPublic(PanelRenderer::class)->invoke('contactsHtml',$resource,$request,$record));
	$t->contains('tel:',$t->nonPublic(PanelRenderer::class)->invoke('contactsHtml',$resource,$request,$record));
	$t->contains('CAD 10',$t->nonPublic(PanelRenderer::class)->invoke('itemsHtml',$resource,$request,$record));
	$t->contains('noopener noreferrer',$t->nonPublic(PanelRenderer::class)->invoke('shipmentsHtml',$resource,$request,$record));
	$t->same('1 entry',$t->nonPublic(PanelRenderer::class)->invoke('recordCountLabel',1,'record.entry','record.entries'));
	$t->same('2 entries',$t->nonPublic(PanelRenderer::class)->invoke('recordCountLabel',2,'record.entry','record.entries'));
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');

test('panel renderer record sections renders guarded mutation surfaces and empty states',static function(Context $t): void {
	$resource=dp_panel_record_sections_resource();
	$request=dp_panel_record_sections_request();
	$record=dp_panel_record_sections_record();
	foreach([
		'tagsHtml'=>['dp-panel-tags','tag_action','remove'],
		'approvalsHtml'=>['dp-panel-approvals','decision','approve'],
		'notesHtml'=>['dp-panel-notes','textarea','add_note'],
		'attachmentsHtml'=>['dp-panel-attachments','multipart/form-data','attachment'],
		'messagesHtml'=>['dp-panel-messages','name=&quot;body&quot;','send_message'],
		'tasksHtml'=>['dp-panel-tasks','completed','add_task'],
	] as $method=>$needles){
		$html=$t->nonPublic(PanelRenderer::class)->invoke($method,$resource,$request,$record);
		foreach($needles as $needle){
			$t->contains($needle,$html);
		}
	}

	$empty=Resource::make('empty-sections')->recordKeyUsing('id')
		->tagsUsing(static fn(): array=>[])->updateTagUsing(static fn(): bool=>true)
		->approvalsUsing(static fn(): array=>[])->resolveApprovalUsing(static fn(): bool=>true)
		->notesUsing(static fn(): array=>[])->addNoteUsing(static fn(): bool=>true)
		->attachmentsUsing(static fn(): array=>[])->attachUsing(static fn(): bool=>true)
		->messagesUsing(static fn(): array=>[])->sendMessageUsing(static fn(): bool=>true)
		->tasksUsing(static fn(): array=>[])->updateTaskUsing(static fn(): bool=>true)->createTaskUsing(static fn(): bool=>true);
	foreach(['tagsHtml','approvalsHtml','notesHtml','attachmentsHtml','messagesHtml','tasksHtml'] as $method){
		$t->contains('dp-panel-empty',$t->nonPublic(PanelRenderer::class)->invoke($method,$empty,$request,$record));
	}

	$plain=Resource::make('plain');
	foreach(['alertsHtml','insightsHtml','linksHtml','contactsHtml','locationsHtml','tagsHtml','itemsHtml','totalsHtml','approvalsHtml','activityHtml','changesHtml','paymentsHtml','shipmentsHtml','notesHtml','attachmentsHtml','messagesHtml','tasksHtml'] as $method){
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke($method,$plain,$request,$record));
	}

	$emptyConfigured=Resource::make('empty-configured')->recordKeyUsing('id')
		->alertsUsing(static fn(): array=>[])->insightsUsing(static fn(): array=>[])
		->linksUsing(static fn(): array=>[])->contactsUsing(static fn(): array=>[])
		->locationsUsing(static fn(): array=>[])->tagsUsing(static fn(): array=>[])
		->itemsUsing(static fn(): array=>[])->totalsUsing(static fn(): array=>[])
		->approvalsUsing(static fn(): array=>[])->activityUsing(static fn(): array=>[])
		->changesUsing(static fn(): array=>[])->paymentsUsing(static fn(): array=>[])
		->shipmentsUsing(static fn(): array=>[])->tasksUsing(static fn(): array=>[]);
	foreach(['alertsHtml','insightsHtml','linksHtml','contactsHtml','locationsHtml','tagsHtml','itemsHtml','totalsHtml','approvalsHtml','activityHtml','changesHtml','paymentsHtml','shipmentsHtml','tasksHtml'] as $method){
		$t->same('',$t->nonPublic(PanelRenderer::class)->invoke($method,$emptyConfigured,$request,$record));
	}

	$denyDecision=false;
	$approvalDenied=dp_panel_record_sections_resource()->authorize(
		static function(string $ability)use(&$denyDecision): bool {
			if($ability==='approval.manager.reject'){
				$denyDecision=true;
			}
			return !$denyDecision;
		}
	);
	$approvalHtml=$t->nonPublic(PanelRenderer::class)->invoke('approvalsHtml',$approvalDenied,$request,$record);
	$t->contains('Approve',$approvalHtml);
	$t->isFalse(str_contains($approvalHtml,'>Reject<'));
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');

test('panel renderer record sections renders and authorizes relation managers',static function(Context $t): void {
	$request=dp_panel_record_sections_request();
	$record=dp_panel_record_sections_record();
	PanelComponentRegistry::registerRelationType('policy_restricted',null,[
		'authorize'=>static fn(): bool=>false,
	]);
	$allowed=RelationManager::make('items')->label('Items')->queryUsing(static fn(): array=>[])
		->column(Column::make('name'));
	$denied=RelationManager::make('secret')->label('Secret')->queryUsing(static fn(): array=>[])
		->authorize(static fn(): bool=>false)->column(Column::make('name'));
	$typeDenied=RelationManager::make('restricted')->label('Restricted by type hook')->queryUsing(static fn(): array=>[])
		->meta(['relation_type'=>'policy_restricted'])->column(Column::make('name'));
	$resource=Resource::make('section-records')->recordKeyUsing('id')->relations([$allowed,$denied,$typeDenied]);
	$html=$t->nonPublic(PanelRenderer::class)->invoke('relationsHtml',$resource,$request,$record);
	$t->contains('Items',$html);
	$t->isFalse(str_contains($html,'Secret'));
	$t->notContains('Restricted by type hook',$html,'A relation-type authorization hook can remove an otherwise visible relation.');
	$t->same('',$t->nonPublic(PanelRenderer::class)->invoke('relationsHtml',Resource::make('none'),$request,$record));
})->tag('panel','renderer','record-sections','coverage')->group('framework-coverage');
