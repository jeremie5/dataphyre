<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\Infolist;
use Dataphyre\Panel\PanelManager;
use Dataphyre\Panel\PanelRenderer;
use Dataphyre\Panel\PanelRequest;
use Dataphyre\Panel\PanelStudioBoardColumn;
use Dataphyre\Panel\PanelStudioDiagnostic;
use Dataphyre\Panel\PanelStudioMaterializer;
use Dataphyre\Panel\PanelStudioPageBundle;
use Dataphyre\Panel\PanelStudioSchemaException;
use Dataphyre\Panel\PanelStudioSchemaRegistry;
use Dataphyre\Panel\Resource;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @param list<PanelStudioDiagnostic> $diagnostics @return list<string> */
function dp_panel_studio_board_codes(array $diagnostics):array{return array_map(static fn(PanelStudioDiagnostic $diagnostic):string=>$diagnostic->code(),$diagnostics);}

function dp_panel_studio_board_definition():array{
	return[
		'kind'=>'board','key'=>'fulfilment_board','properties'=>[
			'label'=>'Fulfilment','plural_label'=>'Fulfilment lanes','description'=>'Move orders through fulfilment.','url'=>'/fulfilment/board','group'=>'Operations','icon'=>'truck','sort'=>25,
			'status_field'=>'state','record_key_field'=>'order_number','record_title_field'=>'customer','record_subtitle_field'=>'email','status_widgets'=>true,
			'layout'=>'masonry','columns'=>3,'gap'=>'compact','fit'=>'fill','masonry'=>'rows','min_width'=>180,'final_row'=>'preserve',
			'card_layout'=>'brick','card_columns'=>2,'card_gap'=>'compact','card_fit'=>'fill','card_masonry'=>'rows','card_min_width'=>140,'card_final_row'=>'fill',
		],'children'=>[
			['kind'=>'board_column','key'=>'queued','properties'=>['label'=>'Queue','status'=>'Queued','tone'=>'warning','accepts_moves'=>false,'column_span'=>1,'min_width'=>'16rem'],'children'=>[]],
			['kind'=>'board_column','key'=>'doing','properties'=>['label'=>'In progress','status'=>'In Progress','tone'=>'primary','from'=>['Queued'],'transition'=>'start_work','transition_label'=>'Start work','confirmation'=>'Start this order?','basis'=>'50%','grow'=>1.5,'order'=>2,'break_before'=>false],'children'=>[]],
			['kind'=>'board_column','key'=>'done','properties'=>['label'=>'Done','status'=>'Done','tone'=>'success','column_span'=>2,'max_width'=>'40rem','fill_remainder'=>true],'children'=>[]],
		],
	];
}

function dp_panel_studio_infolist_definition():array{
	return['kind'=>'infolist','key'=>'order_details','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
		['kind'=>'show_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
			['kind'=>'infolist_entry','key'=>'customer','properties'=>['label'=>'Customer','copyable'=>true],'children'=>[]],
			['kind'=>'infolist_entry','key'=>'state','properties'=>['label'=>'State','type'=>'badge'],'children'=>[]],
		]],
	]];
}

test('resource status-board columns render read-only and gain moves only after host activation',static function(Context $t):void{
	$resource=Resource::make('orders')->label('Order')->statusField('state')->statusBoardColumns([
		'queued'=>['label'=>'Queue','tone'=>'warning','meta'=>['source'=>'declared']],
		'working'=>'Working',
		['name'=>'done','status'=>'Done','label'=>'Done','tone'=>'success'],
		42,
	]);
	$t->isTrue($resource->hasStatusBoard());$t->isFalse($resource->canTransition());$t->same(['queued','working','done'],$resource->statusViewNames());$t->same(3,count($resource->statusBoardColumnsList()));$t->same('queued',$resource->statusBoardColumnsList()['queued']['status']);$t->same('declared',$resource->statusBoardColumnsList()['queued']['meta']['source']);
	$resource=$resource->statusBoardColumn('review','In Review','Review','info',['source'=>'shorthand'])->statusBoardColumn(['name'=>'invalid_tone','status'=>'Needs care','tone'=>'neon'])->statusBoardColumn(['name'=>'','status'=>'']);
	$t->same('In Review',$resource->statusBoardColumnsList()['review']['status']);$t->same('info',$resource->statusBoardColumnsList()['review']['tone']);$t->same('neutral',$resource->statusBoardColumnsList()['invalid_tone']['tone']);$t->same(5,count($resource->statusBoardColumnsList()));
	$manifest=$resource->toArray();$t->same('state',$manifest['status_field']);$t->same(5,count($manifest['status_board_columns']));$roundTrip=Resource::fromArray($manifest);$t->same($resource->statusBoardColumnsList(),$roundTrip->statusBoardColumnsList());$t->isTrue($roundTrip->hasStatusBoard());
	$request=PanelRequest::fromArray(['resource'=>'orders','operation'=>'board']);$records=[['id'=>1,'title'=>'One','state'=>'queued'],['id'=>2,'title'=>'Two','state'=>'working'],['id'=>3,'title'=>'Three','state'=>'Done'],['id'=>4,'title'=>'Four','state'=>'missing']];
	$readOnly=PanelRenderer::statusBoard($resource,$request,$records);$t->same(200,$readOnly->status());$t->same('board',$readOnly->data()['kind']);$t->contains('Queue',$readOnly->content());$t->contains('Unmatched',$readOnly->content());$t->notContains('draggable="true"',$readOnly->content());
	$index=PanelRenderer::index($resource,PanelRequest::fromArray(['resource'=>'orders','operation'=>'index']),$records);$t->contains('operation=board',$index->content());
	$active=$resource->statusTransitions([['name'=>'start','from'=>['queued'],'to'=>'working','tone'=>'primary']])->transitionUsing(static fn():array=>['transitioned'=>true]);$t->isTrue($active->canTransition());$t->same(5,count($active->statusViewNames()));$movable=PanelRenderer::statusBoard($active,$request,$records);$t->same(200,$movable->status());$t->contains('draggable="true"',$movable->content());
	$listInput=Resource::make('archives')->statusBoardColumns(['Archived']);$t->same(['archived'],$listInput->statusViewNames());$t->same('Archived',$listInput->statusBoardColumnsList()['archived']['status']);
	$reset=$resource->statusBoardColumns([]);$t->isFalse($reset->hasStatusBoard());$t->same([],$reset->statusBoardColumnsList());$t->same(404,PanelRenderer::statusBoard($reset,$request,[])->status());
})->tag('panel','resource','board','read-only','host-activation','scorched-earth')->isolation('case')->maxMillis(6000);

test('Studio materializes every declared kind including typed boards and first-class infolists',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$definition=dp_panel_studio_board_definition();$validation=$registry->validate($definition);$t->isTrue($validation->valid());$t->same(30,count($registry->kinds()));$t->same([],$registry->manifest()['portable_only_envelope_kinds']);$t->isTrue($registry->manifest()['complete_definition_kind_coverage']);
	$runtime=$materializer->materialize($definition,$registry);$board=$runtime->root();$t->instanceOf(Resource::class,$board);$t->instanceOf(PanelStudioBoardColumn::class,$runtime->builder('root.children[0]'));$t->same('resource_board',$runtime->manifest()['root_symbol']);$t->same(3,$runtime->manifest()['builder_counts']['board_column']);$t->same(3,count($board->statusBoardColumnsList()));$t->same(2,count($board->statusTransitionsList()));$t->same(['queued','doing','done'],$board->statusViewNames());$t->isTrue($board->hasStatusBoard());$t->isFalse($board->canTransition());$t->isTrue($board->hasStatusWidgets());
	$t->same('SO-1',$board->recordKey(['order_number'=>'SO-1']));$t->same('Avery',$board->recordTitle(['customer'=>'Avery']));$t->same('buyer@example.test',$board->recordSubtitle(['email'=>'buyer@example.test']));$t->same('masonry',$board->presentations()['board_columns']['display']);$t->same('rows',$board->presentations()['board_columns']['masonry']);$t->same('brick',$board->presentations()['board_cards']['display']);$t->same(2,$board->presentations()['board_columns']['items']['done']['span']['base']);$t->isTrue($board->presentations()['board_columns']['items']['done']['fill_remainder']);
	$queued=$runtime->builder('root.children[0]');$doing=$runtime->builder('root.children[1]');$done=$runtime->builder('root.children[2]');$t->same(null,$queued->transition(['Queued','In Progress','Done']));$t->same(['Queued'],$doing->fromStatuses());$t->same(['Queued','In Progress'],$done->transition(['Queued','In Progress','Done'])['from']);$t->same('start_work',$doing->transition(['Queued','In Progress','Done'])['name']);$t->isTrue($doing->acceptsMoves());$t->same('doing',$doing->name());$t->same('In Progress',$doing->status());$t->same('In progress',$doing->label());$t->same('primary',$doing->tone());$t->same('50%',$doing->presentation()['basis']['base']);$t->same('panel_studio_board_column',$doing->jsonSerialize()['type']);$t->isFalse($doing->jsonSerialize()['runtime']['mutation_handler']);
	$request=PanelRequest::fromArray(['resource'=>'fulfilment_board','operation'=>'board']);$records=[['order_number'=>'SO-1','customer'=>'Avery','email'=>'buyer@example.test','state'=>'Queued'],['order_number'=>'SO-2','customer'=>'Mina','email'=>'ops@example.test','state'=>'In Progress'],['order_number'=>'SO-3','customer'=>'Leo','email'=>'done@example.test','state'=>'Done']];$readOnly=PanelRenderer::statusBoard($board,$request,$records);$t->same(200,$readOnly->status());$t->notContains('draggable="true"',$readOnly->content());$activated=$board->transitionUsing(static fn():array=>['transitioned'=>true]);$t->isTrue($activated->canTransition());$t->contains('draggable="true"',PanelRenderer::statusBoard($activated,$request,$records)->content());
	$infolistRuntime=$materializer->materialize(dp_panel_studio_infolist_definition(),$registry);$t->instanceOf(Infolist::class,$infolistRuntime->root());$t->same('infolist',$infolistRuntime->manifest()['root_symbol']);$t->same([],$materializer->manifest()['portable_only_envelope_kinds']);$t->isTrue($materializer->manifest()['complete_definition_kind_coverage']);$t->same('read_only_resource_with_host_bound_mutations',$materializer->manifest()['board_output']);$t->same('first_class_infolist_builder',$materializer->manifest()['infolist_output']);
	$page=['kind'=>'page','key'=>'studio_fulfilment','properties'=>['label'=>'Studio fulfilment'],'children'=>[$definition,dp_panel_studio_infolist_definition()]];$pageRuntime=$materializer->materialize($page,$registry);$bundle=$pageRuntime->root();$t->instanceOf(PanelStudioPageBundle::class,$bundle);$t->same(1,count($bundle->resources()));$t->same(1,count($bundle->infolists()));$t->same(3,$bundle->jsonSerialize()['version']);$t->same(1,$bundle->jsonSerialize()['resource_count']);$t->same(0,$bundle->jsonSerialize()['data_surface_count']);$host=new PanelManager();$registered=$bundle->registerResources($host);$t->same($bundle->resources(),$registered);$t->same($bundle->resources()[0],$host->get('fulfilment_board'));$allHost=new PanelManager();$t->same($bundle->page(),$bundle->registerAll($allHost));$t->same($bundle->page(),$allHost->getPage('studio_fulfilment'));$t->same($bundle->resources()[0],$allHost->get('fulfilment_board'));
	$badContext=['kind'=>'infolist','key'=>'bad','properties'=>[],'children'=>[['kind'=>'tabs','key'=>'tabs','properties'=>[],'children'=>[['kind'=>'tab','key'=>'tab','properties'=>[],'children'=>[['kind'=>'field','key'=>'name','properties'=>[],'children'=>[]]]]]]]];$t->same('materializer_context_mismatch',$materializer->diagnose($badContext,$registry)[0]->code());$t->throws(static fn()=>$materializer->materialize($badContext,$registry),PanelStudioSchemaException::class);
})->tag('panel','studio','board','infolist','materialization','masonry','scorched-earth')->isolation('case')->maxMillis(8000);

test('Studio board contracts reject ambiguous lanes transitions and malformed typed builders',static function(Context $t):void{
	$registry=PanelStudioSchemaRegistry::defaults();$materializer=new PanelStudioMaterializer();$duplicate=dp_panel_studio_board_definition();$duplicate['children'][1]['properties']['status']='Queued';$duplicate['children'][1]['properties']['transition']='move_to_done';$duplicate['children'][2]['properties']['transition']='move_to_done';$codes=dp_panel_studio_board_codes($materializer->diagnose($duplicate,$registry));$t->isTrue(in_array('duplicate_board_status',$codes,true));$t->isTrue(in_array('duplicate_board_transition',$codes,true));$t->throws(static fn()=>$materializer->materialize($duplicate,$registry),PanelStudioSchemaException::class);
	$missing=dp_panel_studio_board_definition();$missing['children'][1]['properties']['from']=['Missing'];$t->same('board_transition_source_missing',$materializer->diagnose($missing,$registry)[0]->code());$t->throws(static fn()=>$materializer->materialize($missing,$registry),PanelStudioSchemaException::class);
	$emptyTransition=dp_panel_studio_board_definition();$emptyTransition['children'][1]['properties']['transition']='invalid transition';$t->same('property_pattern_mismatch',$registry->diagnose($emptyTransition)[0]->code());
	$unsupportedBuild=Closure::bind(static function(PanelStudioMaterializer $subject):void{$builders=[];$symbols=[];$identities=[];$subject->build(['kind'=>'extension_node','key'=>'extension','properties'=>[],'children'=>[]],'root',$builders,$symbols,$identities);},null,PanelStudioMaterializer::class);$t->instanceOf(Closure::class,$unsupportedBuild);$t->throws(static fn()=>$unsupportedBuild($materializer),PanelStudioSchemaException::class);
	$t->throws(static fn()=>new PanelStudioBoardColumn('Bad name','Queued','Queue',transitionName:'move'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','','Queue',transitionName:'move'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','Queued','Queue','neon',transitionName:'move'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','Queued','Queue',transitionName:'Bad move'),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','Queued','Queue',transitionName:'move',transitionLabel:str_repeat('x',161)),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','Queued','Queue',transitionName:'move',fromStatuses:['queued'=>'Queued']),InvalidArgumentException::class);$t->throws(static fn()=>new PanelStudioBoardColumn('queued','Queued','Queue',transitionName:'move',fromStatuses:['']),InvalidArgumentException::class);
	$static=new PanelStudioBoardColumn('queued','Queued','Queue','neutral',false,'invalid transition','',null,'',['span'=>2]);$t->same(null,$static->transition(['Queued']));$t->same(null,$static->jsonSerialize()['transition_name']);$t->same(2,$static->presentation()['span']['base']);$deduplicated=new PanelStudioBoardColumn('done','Done','Done','success',true,'move_to_done','Move',['Queued','Queued'],'',[]);$t->same(['Queued'],$deduplicated->fromStatuses());
})->tag('panel','studio','board','validation','security','scorched-earth')->isolation('case')->maxMillis(6000);
