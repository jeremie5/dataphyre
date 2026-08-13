<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelCollaborationManager;
use Dataphyre\Panel\PanelInMemoryCollaborationStore;
use Dataphyre\Panel\PanelInMemoryStudioStore;
use Dataphyre\Panel\PanelStudioArrayIdentityConnector;
use Dataphyre\Panel\PanelStudioCollaborationConnector;
use Dataphyre\Panel\PanelStudioDefinition;
use Dataphyre\Panel\PanelStudioDocument;
use Dataphyre\Panel\PanelStudioEditor;
use Dataphyre\Panel\PanelStudioEditorCommand;
use Dataphyre\Panel\PanelStudioEditorOptions;
use Dataphyre\Panel\PanelStudioManager;
use Dataphyre\Panel\PanelStudioPolicy;
use Dataphyre\Panel\PanelStudioPreviewSigner;

require_once __DIR__.'/../../unit_tests/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

/** @return array{theme:string,direction:string,zoom:string,reflow:string} */
function dp_panel_studio_editor_fixture_options(array $arguments):array {
	$options=['theme'=>'dark','direction'=>'ltr','zoom'=>'100','reflow'=>'desktop'];
	foreach($arguments as$argument){
		if(!is_string($argument)||!str_starts_with($argument,'--')||!str_contains($argument,'=')){continue;}
		[$name,$value]=explode('=',substr($argument,2),2);
		if(array_key_exists($name,$options)){$options[$name]=$value;}
	}
	if(!in_array($options['theme'],['auto','light','dark','glass'],true)){throw new InvalidArgumentException('Invalid fixture theme.');}
	if(!in_array($options['direction'],['ltr','rtl'],true)){throw new InvalidArgumentException('Invalid fixture direction.');}
	if(!in_array($options['zoom'],['75','100','125','fit'],true)){throw new InvalidArgumentException('Invalid fixture zoom.');}
	if(!in_array($options['reflow'],['desktop','tablet','mobile'],true)){throw new InvalidArgumentException('Invalid fixture reflow.');}
	return$options;
}

$fixture=dp_panel_studio_editor_fixture_options(array_slice($argv,1));
$tick=0;
$clock=static function()use(&$tick):string{$tick++;return sprintf('2026-07-14T12:00:%02d+00:00',$tick%60);};
$signer=new PanelStudioPreviewSigner(['current'=>str_repeat('K',32)],'current',static fn():int=>1_800_000_000,static fn():string=>'studioeditorfixturepreview0001');
$manager=new PanelStudioManager(new PanelInMemoryStudioStore(),PanelStudioPolicy::trustedMaintenance(['fixture']),null,$signer,0,$clock);
$document=PanelStudioDocument::make('fixture-tenant','orders-studio-showroom','Orders workspace editor');
$definition=PanelStudioDefinition::from([
	'kind'=>'page','key'=>'orders','properties'=>['label'=>'Orders workspace','description'=>'Compose a trusted order-management surface.'],'children'=>[
		['kind'=>'form','key'=>'order_form','properties'=>['columns'=>2,'layout'=>'masonry'],'children'=>[
			['kind'=>'form_section','key'=>'identity','properties'=>['label'=>'Identity','columns'=>2],'children'=>[
				['kind'=>'field','key'=>'name','properties'=>['label'=>'Customer name','required'=>true],'children'=>[]],
				['kind'=>'field','key'=>'email','properties'=>['label'=>'Email address','type'=>'email','required'=>true],'children'=>[]],
				['kind'=>'field','key'=>'market','properties'=>['label'=>'Market','type'=>'select','options'=>['ca'=>'Canada','us'=>'United States','eu'=>'European Union']],'children'=>[]],
			]],
			['kind'=>'form_section','key'=>'fulfilment','properties'=>['label'=>'Fulfilment','columns'=>2],'children'=>[
				['kind'=>'field','key'=>'channel','properties'=>['label'=>'Channel','type'=>'select','options'=>['retail'=>'Retail','wholesale'=>'Wholesale']],'children'=>[]],
				['kind'=>'field','key'=>'status','properties'=>['label'=>'Status','type'=>'select','options'=>['review'=>'Review','packing'=>'Packing']],'children'=>[]],
			]],
		]],
		['kind'=>'table','key'=>'orders_table','properties'=>['density'=>'compact'],'children'=>[
			['kind'=>'column','key'=>'id','properties'=>['label'=>'Order ID','sortable'=>true],'children'=>[]],
			['kind'=>'column','key'=>'customer','properties'=>['label'=>'Customer','sortable'=>true],'children'=>[]],
		]],
	],
]);
$session=PanelStudioEditor::open($manager,$document,'fixture',$definition);
$session->save('studio-editor-showroom-save');
$session->apply(PanelStudioEditorCommand::select('orders/order_form/identity/email'));
$collaboration=new PanelStudioCollaborationConnector(
	new PanelCollaborationManager(new PanelInMemoryCollaborationStore()),
	new PanelStudioArrayIdentityConnector([
		['id'=>'fixture','display_name'=>'Avery Designer','status'=>'active','source'=>'showroom'],
		['id'=>'mina','display_name'=>'Mina Reviewer','status'=>'active','source'=>'showroom'],
		['id'=>'noah','display_name'=>'Noah Observer','status'=>'suspended','source'=>'showroom'],
	],'fixture-tenant')
);
$thread=$collaboration->handle($session,[
	'studio_collaboration_operation'=>'create_thread',
	'studio_collaboration_title'=>'Confirm the fulfilment handoff',
]);
$threadId=(string)$thread->resourceId();
$collaboration->handle($session,[
	'studio_collaboration_operation'=>'comment:'.$threadId,
	'studio_collaboration_comments'=>[$threadId=>'The order surface is ready for a focused review.'],
]);
$collaboration->handle($session,[
	'studio_collaboration_operation'=>'assign',
	'studio_collaboration_assignee'=>'mina',
]);
$collaboration->handle($session,['studio_collaboration_operation'=>'watch']);
$collaboration->acquirePresence($session,120);
$options=PanelStudioEditorOptions::make([
	'action_url'=>'/studio/edit',
	'preview_url'=>'/studio/preview',
	'csrf_name'=>'_token',
	'csrf_token'=>str_repeat('C',32),
	'theme'=>$fixture['theme'],
	'direction'=>$fixture['direction'],
	'title'=>'Panel Studio',
	'editor_id'=>'orders-studio',
	'inline_assets'=>true,
	'zoom'=>$fixture['zoom'],
	'reflow'=>$fixture['reflow'],
	'collaboration_connector'=>$collaboration,
]);
$background=$fixture['theme']==='light'?'#f4f7fb':'#07101e';
$html=PanelStudioEditor::render($session,$options);

echo '<!doctype html><html lang="en" dir="'.htmlspecialchars($fixture['direction'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Panel Studio showroom</title><style>html{background:'.htmlspecialchars($background,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'}body{margin:0;min-width:0;padding:16px;background:transparent;font-family:Inter,ui-sans-serif,system-ui,sans-serif}@media(max-width:520px){body{padding:0}}</style></head><body>'.$html.'</body></html>';
