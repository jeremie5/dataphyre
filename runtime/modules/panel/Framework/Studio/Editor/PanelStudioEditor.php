<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Public route-free facade for opening, handling, and rendering Studio editors. */
final class PanelStudioEditor implements \JsonSerializable {
	public static function open(PanelStudioManager $manager,PanelStudioDocument $document,string $principalId,?PanelStudioDefinition $initial=null):PanelStudioEditorSession{return PanelStudioEditorSession::open($manager,$document,$principalId,$initial);}
	/** @param array<string,mixed> $checkpoint */ public static function resume(PanelStudioManager $manager,PanelStudioDocument $document,string $principalId,array $checkpoint):PanelStudioEditorSession{return PanelStudioEditorSession::resume($manager,$document,$principalId,$checkpoint);}
	/** @param array<string,mixed> $input */ public static function handle(PanelStudioEditorSession $session,array $input,PanelStudioEditorOptions $options):PanelStudioEditorSession{if(array_key_exists('studio_collaboration_operation',$input)){if(!$session->synchronizeClientState($input,$options)){return$session;}self::connector($options)->handle($session,$input);return$session;}return$session->handle($input,$options);}
	/** @param array<string,mixed> $input */ public static function collaborate(PanelStudioEditorSession $session,array $input,PanelStudioEditorOptions $options):PanelStudioCollaborationResult{if(!$session->synchronizeClientState($input,$options)){throw new \RuntimeException('Studio editor collaboration client state was rejected.');}return self::connector($options)->handle($session,$input);}
	/** @return array<string,mixed> */ public static function checkpoint(PanelStudioEditorSession $session):array{return$session->checkpoint();}
	public static function render(PanelStudioEditorSession $session,PanelStudioEditorOptions $options):string{return PanelStudioEditorRenderer::render($session,$options);}
	public static function visualPreview(PanelStudioEditorSession $session,?PanelStudioVisualDataset $dataset=null,?PanelRequest $request=null):PanelStudioVisualPreview{return$session->manager()->visualRuntime()->renderSession($session,$dataset,$request);}
	public static function manifest(?PanelStudioEditorSession $session=null,?PanelStudioEditorOptions $options=null):array{$active=$session?->manager()->hasVisualRuntime()??false;$collaboration=$options?->collaborationConnector();$transport=$options?->collaborationTransport();return['type'=>'panel_studio_editor','version'=>6,'renderer'=>PanelStudioEditorRenderer::manifest($session),'visual_runtime'=>$active?$session?->manager()->visualRuntime()->manifest():null,'collaboration'=>$collaboration?->manifest(),'collaboration_transport'=>$transport?->jsonSerialize(),'integration'=>['routes_registered'=>false,'host_transport_required'=>true,'host_session_persistence_required'=>true,'server_owned_checkpoint'=>true,'manager_contract'=>'panel_studio_manifest.v4','schema_contract'=>'panel_studio_schema_registry_manifest.v3','materializer_contract'=>'panel_studio_materializer_manifest.v3','visual_runtime_contract'=>'panel_studio_visual_runtime.v2','visual_runtime_active'=>$active,'collaboration_connector_supported'=>true,'collaboration_connector_active'=>$collaboration!==null,'collaboration_live_transport_supported'=>true,'collaboration_live_transport_active'=>$transport!==null,'collaboration_subject_server_owned'=>true,'collaboration_actor_server_owned'=>true,'collaboration_presence_token_host_owned'=>true,'collaboration_preserves_client_state'=>true]];}
	public function jsonSerialize():array{return self::manifest();}
	private static function connector(PanelStudioEditorOptions $options):PanelStudioCollaborationConnector{$connector=$options->collaborationConnector();if(!$connector instanceof PanelStudioCollaborationConnector){throw new \LogicException('Studio editor collaboration is not configured.');}return$connector;}
}
