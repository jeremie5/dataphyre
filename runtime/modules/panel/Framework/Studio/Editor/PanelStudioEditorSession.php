<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/**
 * Bounded route-free editing session layered exclusively on trusted Studio contracts.
 *
 * The host owns transport and PHP-session persistence. This class owns deterministic
 * tree commands, optimistic revision checks, validation, undo/redo, save, and preview.
 */
final class PanelStudioEditorSession implements \JsonSerializable {
	public const MAX_HISTORY=50;
	public const MAX_CHECKPOINT_BYTES=2097152;
	private PanelStudioDefinition $definition;
	private int $baseRevision;
	private string $baseHash;
	private string $selectedPath;
	/** @var list<array{definition:PanelStudioDefinition,selection:string}> */ private array $undo=[];
	/** @var list<array{definition:PanelStudioDefinition,selection:string}> */ private array $redo=[];
	/** @var list<PanelStudioDiagnostic> */ private array $sessionDiagnostics=[];
	private ?int $remoteRevision=null;
	private ?PanelStudioReceipt $lastReceipt=null;
	private ?PanelStudioPreviewIntent $previewIntent=null;

	private function __construct(private readonly PanelStudioManager $manager,private readonly PanelStudioDocument $document,private readonly string $principalId,PanelStudioDefinition $definition,int $baseRevision,string $baseHash){
		PanelStudioDocument::scope($principalId,'principal');if($baseRevision<0||($baseRevision===0)!==($baseHash==='')||($baseRevision>0&&preg_match('/^[a-f0-9]{64}$/',$baseHash)!==1)){throw new \InvalidArgumentException('Studio editor base revision identity is invalid.');}
		$this->definition=$definition;$this->baseRevision=$baseRevision;$this->baseHash=$baseHash;$this->selectedPath=(string)$definition->root()['key'];
	}
	public static function open(PanelStudioManager $manager,PanelStudioDocument $document,string $principalId,?PanelStudioDefinition $initial=null):self{
		$head=$manager->head($document->tenantId(),$document->id(),$principalId);
		if($head){$session=new self($manager,$document,$principalId,$head->definition(),$head->number(),$head->contentHash());$session->sessionDiagnostics=$manager->compatibilityDiagnostics($head);return$session;}
		$initial??=PanelStudioDefinition::from(['kind'=>'page','key'=>'page','properties'=>['label'=>$document->title()],'children'=>[]]);
		return new self($manager,$document,$principalId,$initial,0,'');
	}
	/** Restores bounded server-owned state. Never accept checkpoints directly from a browser. @param array<string,mixed> $checkpoint */
	public static function resume(PanelStudioManager $manager,PanelStudioDocument $document,string $principalId,array $checkpoint):self{
		$expected=['base','definition','redo','remote_revision','scope','selected_path','type','undo','version'];$keys=array_keys($checkpoint);sort($keys,SORT_STRING);if($keys!==$expected||($checkpoint['type']??null)!=='panel_studio_editor_checkpoint'||($checkpoint['version']??null)!==1){throw new \InvalidArgumentException('Studio editor checkpoint envelope is invalid.');}
		$scope=$checkpoint['scope'];if(!is_array($scope)||array_is_list($scope)||!self::exactKeys($scope,['tenant_id','document_id','principal_id'])||$scope!=['tenant_id'=>$document->tenantId(),'document_id'=>$document->id(),'principal_id'=>$principalId]){throw new \InvalidArgumentException('Studio editor checkpoint scope does not match this editor.');}
		$base=$checkpoint['base'];if(!is_array($base)||array_is_list($base)||!self::exactKeys($base,['revision','hash'])||!is_int($base['revision']??null)||!is_string($base['hash']??null)){throw new \InvalidArgumentException('Studio editor checkpoint revision identity is invalid.');}
		if(!is_array($checkpoint['definition'])||array_is_list($checkpoint['definition'])){throw new \InvalidArgumentException('Studio editor checkpoint definition is invalid.');}$definition=PanelStudioDefinition::from($checkpoint['definition']);$session=new self($manager,$document,$principalId,$definition,$base['revision'],$base['hash']);
		if(!is_string($checkpoint['selected_path'])||!$session->pathExists($checkpoint['selected_path'],$definition)){throw new \InvalidArgumentException('Studio editor checkpoint selection is invalid.');}$session->selectedPath=$checkpoint['selected_path'];$session->undo=$session->checkpointSnapshots($checkpoint['undo'],'undo');$session->redo=$session->checkpointSnapshots($checkpoint['redo'],'redo');
		$remote=$checkpoint['remote_revision'];if($remote!==null&&(!is_int($remote)||$remote<0)){throw new \InvalidArgumentException('Studio editor checkpoint remote revision is invalid.');}$session->remoteRevision=$remote;$head=$manager->head($document->tenantId(),$document->id(),$principalId);$matches=$head?($head->number()===$session->baseRevision&&$session->baseHash!==''&&hash_equals($head->contentHash(),$session->baseHash)):($session->baseRevision===0&&$session->baseHash==='');if(!$matches){$session->remoteRevision=$head?->number()??0;}if($session->conflicted()){$session->conflictNotice();}return$session;
	}
	public function manager():PanelStudioManager{return$this->manager;}
	public function document():PanelStudioDocument{return$this->document;}
	public function principalId():string{return$this->principalId;}
	public function definition():PanelStudioDefinition{return$this->definition;}
	public function baseRevision():int{return$this->baseRevision;}
	public function baseHash():string{return$this->baseHash;}
	public function selectedPath():string{return$this->selectedPath;}
	public function selectedNode():array{return$this->nodeAt($this->definition->root(),self::segments($this->selectedPath));}
	public function dirty():bool{return$this->baseHash===''||!hash_equals($this->baseHash,$this->definition->hash());}
	public function canUndo():bool{return$this->undo!==[];}
	public function canRedo():bool{return$this->redo!==[];}
	public function conflicted():bool{return$this->remoteRevision!==null;}
	public function remoteRevision():?int{return$this->remoteRevision;}
	public function lastReceipt():?PanelStudioReceipt{return$this->lastReceipt;}
	public function previewIntent():?PanelStudioPreviewIntent{return$this->previewIntent;}
	/** @return array<string,mixed> Bounded array suitable for a trusted server-side session/cache. */
	public function checkpoint():array{
		$serialize=static fn(array$snapshot):array=>['definition'=>$snapshot['definition']->root(),'selection'=>$snapshot['selection']];$undo=array_map($serialize,$this->undo);$redo=array_map($serialize,$this->redo);$build=function()use(&$undo,&$redo):array{return['type'=>'panel_studio_editor_checkpoint','version'=>1,'scope'=>['tenant_id'=>$this->document->tenantId(),'document_id'=>$this->document->id(),'principal_id'=>$this->principalId],'base'=>['revision'=>$this->baseRevision,'hash'=>$this->baseHash],'definition'=>$this->definition->root(),'selected_path'=>$this->selectedPath,'undo'=>$undo,'redo'=>$redo,'remote_revision'=>$this->remoteRevision];};$checkpoint=$build();while(strlen(json_encode($checkpoint,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>self::MAX_CHECKPOINT_BYTES&&($undo!==[]||$redo!==[])){if($undo!==[]){array_shift($undo);}else{array_shift($redo);}$checkpoint=$build();}if(strlen(json_encode($checkpoint,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))>self::MAX_CHECKPOINT_BYTES){throw new \LengthException('Studio editor checkpoint exceeds its server persistence budget.');}return$checkpoint;
	}
	public function validation():PanelStudioValidation{return$this->manager->registry()->validate($this->definition);}
	/** @return list<PanelStudioDiagnostic> */ public function diagnostics():array{
		$all=array_merge($this->validation()->diagnostics(),$this->sessionDiagnostics);$unique=[];foreach($all as$diagnostic){$key=implode("\0",[$diagnostic->path(),$diagnostic->code(),$diagnostic->message(),$diagnostic->severity()]);$unique[$key]=$diagnostic;}$all=array_values($unique);usort($all,static fn(PanelStudioDiagnostic $a,PanelStudioDiagnostic $b):int=>[$a->severity(),$a->path(),$a->code()]<=>[$b->severity(),$b->path(),$b->code()]);return$all;
	}
	public function apply(PanelStudioEditorCommand $command):self{
		$this->clearTransient();$payload=$command->payload();
		switch($command->type()){
			case'select':$this->nodeAt($this->definition->root(),self::segments($payload['path']));$this->selectedPath=$payload['path'];break;
			case'add':$this->addNode($payload['parent'],$payload['kind'],$payload['key']);break;
			case'remove':$this->removeNode($payload['path']);break;
			case'move':$this->moveNode($payload['path'],$payload['direction']);break;
			case'update':$this->updateNode($payload['path'],$payload['key'],$payload['properties']);break;
			case'replace':$this->commit($payload['definition'],$this->pathExists($this->selectedPath,$payload['definition'])?$this->selectedPath:(string)$payload['definition']->root()['key']);break;
			case'undo':$this->undo();break;
			case'redo':$this->redo();break;
		}
		return$this;
	}
	public function undo():self{
		$this->clearTransient();$snapshot=array_pop($this->undo);if(!is_array($snapshot)){$this->notice('editor.undo','nothing_to_undo','There is no editor change to undo.','info');return$this;}
		$this->redo[]=$this->snapshot();$this->definition=$snapshot['definition'];$this->selectedPath=$snapshot['selection'];$this->previewIntent=null;$this->trim($this->redo);$this->notice('editor.undo','editor_undo','The last editor change was undone.','info');return$this;
	}
	public function redo():self{
		$this->clearTransient();$snapshot=array_pop($this->redo);if(!is_array($snapshot)){$this->notice('editor.redo','nothing_to_redo','There is no editor change to redo.','info');return$this;}
		$this->undo[]=$this->snapshot();$this->definition=$snapshot['definition'];$this->selectedPath=$snapshot['selection'];$this->previewIntent=null;$this->trim($this->undo);$this->notice('editor.redo','editor_redo','The editor change was restored.','info');return$this;
	}
	public function save(?string $idempotencyKey=null):?PanelStudioReceipt{
		$this->clearTransient();if($this->conflicted()){$this->conflictNotice();return null;}
		$validation=$this->validation();if(!$validation->valid()){$this->sessionDiagnostics=$validation->diagnostics();return null;}
		$idempotencyKey=$idempotencyKey!==null&&trim($idempotencyKey)!==''?$idempotencyKey:'studio-editor-save-'.$this->baseRevision.'-'.substr($this->definition->hash(),0,32);
		try{$receipt=$this->manager->saveDraft($this->document,$this->definition,$this->baseRevision,$idempotencyKey,$this->principalId);}
		catch(PanelStudioSchemaException $error){$this->sessionDiagnostics=$error->diagnostics();return null;}
		catch(\Throwable $error){$this->runtimeFailure($error,'editor.save','editor_save_failed');return null;}
		$this->lastReceipt=$receipt;$this->baseRevision=$receipt->revision();$this->baseHash=$this->definition->hash();$this->undo=[];$this->redo=[];$this->remoteRevision=null;$this->previewIntent=null;$this->notice('editor.save','editor_saved','The Studio draft was saved.','info');return$receipt;
	}
	public function preview(int $ttlSeconds=300,string $audience='panel_studio_preview'):?PanelStudioPreviewIntent{
		$this->clearTransient();if($this->conflicted()){$this->conflictNotice();return null;}if($this->dirty()||$this->baseRevision<1){$this->notice('editor.preview','preview_requires_save','Save the current draft before opening a signed preview.');return null;}
		try{$this->previewIntent=$this->manager->preview($this->document->tenantId(),$this->document->id(),$this->baseRevision,$this->principalId,$ttlSeconds,$audience);}
		catch(PanelStudioSchemaException $error){$this->sessionDiagnostics=$error->diagnostics();return null;}
		catch(\Throwable $error){$this->runtimeFailure($error,'editor.preview','editor_preview_failed');return null;}
		$this->notice('editor.preview','preview_ready','A signed revision-bound preview is ready.','info');return$this->previewIntent;
	}
	public function refresh():self{
		$this->clearTransient();$head=$this->manager->head($this->document->tenantId(),$this->document->id(),$this->principalId);
		if(!$head){if($this->baseRevision>0){$this->remoteRevision=0;$this->conflictNotice();}return$this;}
		if($head->number()===$this->baseRevision&&hash_equals($head->contentHash(),$this->baseHash)){$this->sessionDiagnostics=$this->manager->compatibilityDiagnostics($head);$this->notice('editor.refresh','editor_current','The editor already matches the latest revision.','info');return$this;}
		if($this->dirty()){$this->remoteRevision=$head->number();$this->conflictNotice();return$this;}
		return$this->loadHead($head,'The latest Studio revision was loaded.');
	}
	public function discardAndReload():self{
		$head=$this->manager->head($this->document->tenantId(),$this->document->id(),$this->principalId);if(!$head){throw new \OutOfBoundsException('Studio editor cannot reload a missing document.');}return$this->loadHead($head,'Local editor changes were discarded and the latest revision was loaded.');
	}
	/**
	 * Handles one same-origin POST payload. The supplied options enforce CSRF before
	 * any client definition or command is inspected.
	 * @param array<string,mixed> $input
	 */
	public function handle(array $input,PanelStudioEditorOptions $options):self{
		if(!$this->synchronizeClientState($input,$options)){return$this;}
		try{
			if(is_string($input['studio_select']??null)){return$this->apply(PanelStudioEditorCommand::select($input['studio_select']));}
			if(is_string($input['studio_add_kind']??null)){return$this->apply(PanelStudioEditorCommand::add($this->inputPath($input,'studio_parent',$this->selectedPath),$input['studio_add_kind']));}
			if(is_string($input['studio_remove']??null)){return$this->apply(PanelStudioEditorCommand::remove($input['studio_remove']));}
			if(is_string($input['studio_move']??null)){$parts=explode(':',$input['studio_move'],2);if(count($parts)!==2){throw new \InvalidArgumentException('Studio editor move command is malformed.');}return$this->apply(PanelStudioEditorCommand::move($parts[1],$parts[0]));}
			$action=is_string($input['studio_action']??null)?$input['studio_action']:'';
			return match($action){
				'update'=>$this->apply(PanelStudioEditorCommand::update($this->inputPath($input,'studio_path',$this->selectedPath),is_string($input['studio_key']??null)?$input['studio_key']:'',$this->submittedProperties($input))),
				'undo'=>$this->undo(),'redo'=>$this->redo(),
				'save'=>$this->save(is_string($input['studio_idempotency_key']??null)?$input['studio_idempotency_key']:null)!==null?$this:$this,
				'preview'=>$this->preview()!==null?$this:$this,
				'refresh'=>$this->refresh(),'discard'=>$this->discardAndReload(),
				default=>throw new \InvalidArgumentException('Studio editor request does not contain a supported command.'),
			};
		}catch(PanelStudioSchemaException $error){$this->sessionDiagnostics=$error->diagnostics();return$this;}
		catch(\Throwable $error){$message=PanelSensitiveDataSanitizer::sanitize($error->getMessage(),['max_string_bytes'=>512]);$message=is_string($message)&&trim($message)!==''?$message:'The editor command was rejected.';$this->notice('editor.command','editor_command_failed',$message);return$this;}
	}
	/**
	 * Imports optimistic browser state without executing an editor command.
	 * Collaboration submissions use this first so rerendering cannot discard
	 * unsaved composition changes or mutate review state from a stale base.
	 * @param array<string,mixed> $input
	 */
	public function synchronizeClientState(array $input,PanelStudioEditorOptions $options):bool{
		if(!$options->verifyCsrf($input)){throw new \RuntimeException('Studio editor CSRF verification failed.');}
		if(count($input)>256){throw new \LengthException('Studio editor request contains too many fields.');}
		try{
			$this->importClientDefinition($input);
			return true;
		}catch(PanelStudioSchemaException $error){$this->sessionDiagnostics=$error->diagnostics();return false;}
		catch(\Throwable $error){$message=PanelSensitiveDataSanitizer::sanitize($error->getMessage(),['max_string_bytes'=>512]);$message=is_string($message)&&trim($message)!==''?$message:'The editor client state was rejected.';$this->notice('editor.command','editor_client_state_failed',$message);return false;}
	}
	public function jsonSerialize():array{
		return['type'=>'panel_studio_editor_session','version'=>2,'document'=>$this->document->jsonSerialize(),'principal_id'=>$this->principalId,'definition_hash'=>$this->definition->hash(),'base_revision'=>$this->baseRevision,'base_hash'=>$this->baseHash,'selected_path'=>$this->selectedPath(),'dirty'=>$this->dirty(),'can_undo'=>$this->canUndo(),'can_redo'=>$this->canRedo(),'conflicted'=>$this->conflicted(),'remote_revision'=>$this->remoteRevision,'last_receipt'=>$this->lastReceipt?->jsonSerialize(),'preview'=>$this->previewIntent?->jsonSerialize(),'validation'=>$this->validation()->jsonSerialize(),'diagnostics'=>array_map(static fn(PanelStudioDiagnostic $diagnostic):array=>$diagnostic->jsonSerialize(),$this->diagnostics()),'capabilities'=>['route_free'=>true,'ssr_no_js'=>true,'progressive_enhancement'=>true,'keyboard_reorder'=>true,'pointer_drag_reorder'=>true,'undo_redo'=>true,'optimistic_save'=>true,'signed_artifact_preview'=>true,'complete_definition_kind_coverage'=>$this->manager->registry()->manifest()['complete_definition_kind_coverage']===true,'portable_only_affordances'=>true,'board_and_infolist_inspector'=>true,'server_checkpoint'=>true,'collaboration_state_sync'=>true],'security'=>['manager_authorization'=>true,'csrf_required'=>true,'same_origin_actions'=>true,'raw_html'=>false,'callbacks'=>false,'user_classes'=>false,'secrets_in_manifest'=>false,'checkpoint_server_owned'=>true]];
	}

	private function addNode(string $parentPath,string $kind,string $key):void{
		$root=$this->definition->root();$parent=$this->nodeAt($root,self::segments($parentPath));$this->assertAccepts($parent,$kind);$key=$key!==''?$key:$this->uniqueKey($parent,$kind);foreach($parent['children']as$child){if($child['key']===$key){throw new \LogicException('Studio editor sibling keys must be unique.');}}
		$parent['children'][]=['kind'=>$kind,'key'=>$key,'properties'=>$this->defaults($kind),'children'=>[]];$root=$this->replaceAt($root,self::segments($parentPath),$parent);$this->commit(PanelStudioDefinition::from($root),$parentPath.'/'.$key);
	}
	/** @return list<array{definition:PanelStudioDefinition,selection:string}> */ private function checkpointSnapshots(mixed $snapshots,string $label):array{if(!is_array($snapshots)||!array_is_list($snapshots)||count($snapshots)>self::MAX_HISTORY){throw new \InvalidArgumentException("Studio editor checkpoint {$label} history is invalid.");}$output=[];foreach($snapshots as$snapshot){if(!is_array($snapshot)||array_is_list($snapshot)||!self::exactKeys($snapshot,['definition','selection'])||!is_array($snapshot['definition'])||array_is_list($snapshot['definition'])||!is_string($snapshot['selection'])){throw new \InvalidArgumentException("Studio editor checkpoint {$label} snapshot is invalid.");}$definition=PanelStudioDefinition::from($snapshot['definition']);if(!$this->pathExists($snapshot['selection'],$definition)){throw new \InvalidArgumentException("Studio editor checkpoint {$label} selection is invalid.");}$output[]=['definition'=>$definition,'selection'=>$snapshot['selection']];}return$output;}
	private function removeNode(string $path):void{
		$parentPath=self::parentPath($path);if($parentPath===null){throw new \LogicException('Studio editor root components cannot be removed.');}$root=$this->definition->root();$parent=$this->nodeAt($root,self::segments($parentPath));$key=self::lastKey($path);$index=$this->childIndex($parent,$key);array_splice($parent['children'],$index,1);$root=$this->replaceAt($root,self::segments($parentPath),$parent);$this->commit(PanelStudioDefinition::from($root),$parentPath);
	}
	private function moveNode(string $path,string $direction):void{
		$parentPath=self::parentPath($path);if($parentPath===null){throw new \LogicException('Studio editor root components cannot be moved.');}$root=$this->definition->root();$parent=$this->nodeAt($root,self::segments($parentPath));$index=$this->childIndex($parent,self::lastKey($path));$node=$parent['children'][$index];
		if(in_array($direction,['up','down'],true)){$target=$direction==='up'?$index-1:$index+1;if($target<0||$target>=count($parent['children'])){throw new \OutOfBoundsException('Studio editor component is already at that boundary.');}$parent['children'][$index]=$parent['children'][$target];$parent['children'][$target]=$node;$root=$this->replaceAt($root,self::segments($parentPath),$parent);$this->commit(PanelStudioDefinition::from($root),$parentPath.'/'.$node['key']);return;}
		if($direction==='indent'){
			if($index===0){throw new \OutOfBoundsException('Studio editor components need a previous sibling before they can be indented.');}$target=$parent['children'][$index-1];$this->assertAccepts($target,$node['kind']);foreach($target['children']as$child){if($child['key']===$node['key']){throw new \LogicException('Studio editor move would duplicate a sibling key.');}}array_splice($parent['children'],$index,1);$target['children'][]=$node;$parent['children'][$index-1]=$target;$root=$this->replaceAt($root,self::segments($parentPath),$parent);$this->commit(PanelStudioDefinition::from($root),$parentPath.'/'.$target['key'].'/'.$node['key']);return;
		}
		$grandPath=self::parentPath($parentPath);if($grandPath===null){throw new \OutOfBoundsException('Studio editor component is already at the outer editable level.');}$grand=$this->nodeAt($root,self::segments($grandPath));$this->assertAccepts($grand,$node['kind']);foreach($grand['children']as$child){if($child['key']===$node['key']){throw new \LogicException('Studio editor move would duplicate a sibling key.');}}array_splice($parent['children'],$index,1);$root=$this->replaceAt($root,self::segments($parentPath),$parent);$grand=$this->nodeAt($root,self::segments($grandPath));$parentIndex=$this->childIndex($grand,self::lastKey($parentPath));array_splice($grand['children'],$parentIndex+1,0,[$node]);$root=$this->replaceAt($root,self::segments($grandPath),$grand);$this->commit(PanelStudioDefinition::from($root),$grandPath.'/'.$node['key']);
	}
	/** @param array<string,mixed> $properties */ private function updateNode(string $path,string $key,array $properties):void{
		$root=$this->definition->root();$node=$this->nodeAt($root,self::segments($path));$parentPath=self::parentPath($path);if($parentPath!==null&&$key!==$node['key']){$parent=$this->nodeAt($root,self::segments($parentPath));foreach($parent['children']as$child){if($child['key']===$key){throw new \LogicException('Studio editor sibling keys must be unique.');}}}
		$node['key']=$key;$node['properties']=$properties;$root=$this->replaceAt($root,self::segments($path),$node);$newPath=$parentPath===null?$key:$parentPath.'/'.$key;$this->commit(PanelStudioDefinition::from($root),$newPath);
	}
	private function commit(PanelStudioDefinition $definition,string $selection):void{
		$this->undo[]=$this->snapshot();$this->trim($this->undo);$this->redo=[];$this->definition=$definition;$this->selectedPath=$selection;$this->previewIntent=null;$this->lastReceipt=null;
	}
	/** @return array{definition:PanelStudioDefinition,selection:string} */ private function snapshot():array{return['definition'=>$this->definition,'selection'=>$this->selectedPath];}
	/** @param list<array{definition:PanelStudioDefinition,selection:string}> $history */ private function trim(array &$history):void{while(count($history)>self::MAX_HISTORY){array_shift($history);}}
	private function loadHead(PanelStudioRevision $head,string $message):self{
		$this->definition=$head->definition();$this->baseRevision=$head->number();$this->baseHash=$head->contentHash();$this->selectedPath=$this->pathExists($this->selectedPath,$this->definition)?$this->selectedPath:(string)$this->definition->root()['key'];$this->undo=[];$this->redo=[];$this->remoteRevision=null;$this->previewIntent=null;$this->lastReceipt=null;$this->sessionDiagnostics=$this->manager->compatibilityDiagnostics($head);$this->notice('editor.refresh','editor_reloaded',$message,'info');return$this;
	}
	/** @param array<string,mixed> $input */ private function importClientDefinition(array $input):void{
		$json=$input['studio_definition_json']??null;if($json===null){return;}if(!is_string($json)||strlen($json)>PanelStudioDefinition::MAX_JSON_BYTES){throw new \LengthException('Studio editor definition payload is invalid or too large.');}
		$revision=$input['studio_base_revision']??null;$hash=$input['studio_base_hash']??null;if(!is_string($revision)||preg_match('/^\d{1,10}$/',$revision)!==1||!is_string($hash)||!hash_equals($this->baseHash,$hash)||(int)$revision!==$this->baseRevision){$this->remoteRevision=$this->manager->head($this->document->tenantId(),$this->document->id(),$this->principalId)?->number()??0;$this->conflictNotice();throw new \RuntimeException('Studio editor client state is based on a stale revision.');}
		$decoded=json_decode($json,true,PanelStudioDefinition::MAX_DEPTH+4,JSON_THROW_ON_ERROR);if(!is_array($decoded)||array_is_list($decoded)){throw new \InvalidArgumentException('Studio editor definition JSON must contain one root object.');}$definition=PanelStudioDefinition::from($decoded);
		if(!hash_equals($definition->hash(),$this->definition->hash())){$selection=is_string($input['studio_selected_path']??null)&&$this->pathExists($input['studio_selected_path'],$definition)?$input['studio_selected_path']:(string)$definition->root()['key'];$this->commit($definition,$selection);}
	}
	/** @param array<string,mixed> $input @return array<string,mixed> */ private function submittedProperties(array $input):array{
		$path=$this->inputPath($input,'studio_path',$this->selectedPath);$node=$this->nodeAt($this->definition->root(),self::segments($path));$schema=$this->manager->registry()->schema((string)$node['kind']);if(!$schema){throw new \LogicException('Portable-only Studio components do not expose a trusted property inspector.');}
		$submitted=$input['studio_properties']??[];if(!is_array($submitted)||($submitted!==[]&&array_is_list($submitted))||count($submitted)>PanelStudioDefinition::MAX_PROPERTIES){throw new \InvalidArgumentException('Studio editor submitted properties are malformed.');}
		$booleanFields=$input['studio_boolean_fields']??[];if(!is_array($booleanFields)||!array_is_list($booleanFields)){throw new \InvalidArgumentException('Studio editor boolean-field metadata is malformed.');}
		$booleans=[];foreach($booleanFields as$name){if(!is_string($name)||!$schema->property($name)instanceof PanelStudioPropertySchema||$schema->property($name)?->type()!=='boolean'){throw new \InvalidArgumentException('Studio editor boolean-field metadata is invalid.');}$booleans[$name]=true;}
		$properties=$node['properties'];foreach($submitted as$name=>$value){if(!is_string($name)||!$schema->property($name)instanceof PanelStudioPropertySchema){throw new \InvalidArgumentException('Studio editor submitted an unsupported property.');}$properties[$name]=$this->decodeProperty($schema->property($name),$value);if($properties[$name]===self::unsetMarker()){unset($properties[$name]);}}
		foreach($booleans as$name=>$_){$properties[$name]=array_key_exists($name,$submitted)&&in_array($submitted[$name],[true,1,'1','true','on'],true);}
		ksort($properties,SORT_STRING);return$properties;
	}
	private function decodeProperty(PanelStudioPropertySchema $schema,mixed $value):mixed{
		if(!is_string($value)&&!is_int($value)&&!is_float($value)&&!is_bool($value)&&$value!==null){throw new \InvalidArgumentException('Studio editor property inputs must be scalar.');}$value=is_string($value)?$value:(is_bool($value)?($value?'true':'false'):(string)$value);if($value==='__dp_studio_unset__'){return self::unsetMarker();}
		$enum=$schema->manifest()['enum']??[];if($enum!==[]){$decoded=json_decode($value,true,8,JSON_THROW_ON_ERROR);foreach($enum as$option){if(self::canonical($decoded)===self::canonical($option)){return$decoded;}}throw new \InvalidArgumentException('Studio editor enum property is invalid.');}
		return match($schema->type()){
			'string'=>$value,
			'integer'=>preg_match('/^-?\d+$/D',$value)===1?(int)$value:throw new \InvalidArgumentException('Studio editor integer property is invalid.'),
			'number'=>is_numeric($value)&&is_finite((float)$value)?(float)$value:throw new \InvalidArgumentException('Studio editor number property is invalid.'),
			'boolean'=>in_array($value,['1','true','on'],true),
			'string_list','number_list','scalar_map'=>$this->decodeCollection($value),
			'scalar'=>$this->decodeScalar($value),
		};
	}
	private function decodeCollection(string $value):array{$decoded=json_decode($value,true,PanelStudioDefinition::MAX_DEPTH,JSON_THROW_ON_ERROR);if(!is_array($decoded)){throw new \InvalidArgumentException('Studio editor collection properties require JSON arrays or objects.');}return$decoded;}
	private function decodeScalar(string $value):mixed{if($value==='null'){return null;}if(preg_match('/^(?:true|false|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)$/D',$value)===1){return json_decode($value,true,8,JSON_THROW_ON_ERROR);}if(strlen($value)>=2&&$value[0]==='"'&&str_ends_with($value,'"')){$decoded=json_decode($value,true,8,JSON_THROW_ON_ERROR);if(is_string($decoded)){return$decoded;}}return$value;}
	private static function unsetMarker():object{static$marker;return$marker??=new \stdClass();}
	/** @param array<string,mixed> $input */ private function inputPath(array $input,string $name,string $fallback):string{$value=$input[$name]??$fallback;return PanelStudioEditorCommand::path($value);}
	private function uniqueKey(array $parent,string $kind):string{$used=[];foreach($parent['children']as$child){$used[$child['key']]=true;}for($index=1;$index<=PanelStudioDefinition::MAX_NODES;$index++){$candidate=$kind.'_'.$index;if(!isset($used[$candidate])){return$candidate;}}throw new \OverflowException('Studio editor could not allocate a unique component key.');}
	/** @return array<string,mixed> */ private function defaults(string $kind):array{$schema=$this->manager->registry()->schema($kind);if(!$schema){throw new \LogicException('Portable-only Studio kinds cannot be added to a trusted editor document.');}$properties=[];foreach($schema->properties()as$name=>$property){if($property->hasDefault()){$properties[$name]=$property->defaultValue();}}return$properties;}
	private function assertAccepts(array $parent,string $kind):void{$schema=$this->manager->registry()->schema((string)$parent['kind']);if(!$schema||!$schema->childRule($kind)){throw new \LogicException("Studio {$kind} components are not allowed under {$parent['kind']}.");}$count=0;foreach($parent['children']as$child){if($child['kind']===$kind){$count++;}}if($count>=$schema->childRule($kind)->maximum()){throw new \OverflowException('Studio editor child-kind cardinality is full.');}if(count($parent['children'])>=$schema->maximumChildren()){throw new \OverflowException('Studio editor parent child cardinality is full.');}}
	private function childIndex(array $parent,string $key):int{foreach($parent['children']as$index=>$child){if($child['key']===$key){return$index;}}throw new \OutOfBoundsException('Studio editor component path does not exist.');}
	/** @param list<string> $segments */ private function nodeAt(array $node,array $segments,int $offset=0):array{if(($segments[$offset]??null)!==$node['key']){throw new \OutOfBoundsException('Studio editor component path does not exist.');}if($offset===count($segments)-1){return$node;}foreach($node['children']as$child){if($child['key']===($segments[$offset+1]??null)){return$this->nodeAt($child,$segments,$offset+1);}}throw new \OutOfBoundsException('Studio editor component path does not exist.');}
	/** @param list<string> $segments */ private function replaceAt(array $node,array $segments,array $replacement,int $offset=0):array{if(($segments[$offset]??null)!==$node['key']){throw new \OutOfBoundsException('Studio editor component path does not exist.');}if($offset===count($segments)-1){return$replacement;}foreach($node['children']as$index=>$child){if($child['key']===($segments[$offset+1]??null)){$node['children'][$index]=$this->replaceAt($child,$segments,$replacement,$offset+1);return$node;}}throw new \OutOfBoundsException('Studio editor component path does not exist.');}
	private function pathExists(string $path,PanelStudioDefinition $definition):bool{try{$this->nodeAt($definition->root(),self::segments($path));return true;}catch(\Throwable){return false;}}
	/** @return list<string> */ private static function segments(string $path):array{PanelStudioEditorCommand::path($path);return explode('/',$path);}
	private static function parentPath(string $path):?string{PanelStudioEditorCommand::path($path);$position=strrpos($path,'/');return$position===false?null:substr($path,0,$position);}
	private static function lastKey(string $path):string{PanelStudioEditorCommand::path($path);$position=strrpos($path,'/');return$position===false?$path:substr($path,$position+1);}
	private function clearTransient():void{$this->sessionDiagnostics=[];if($this->conflicted()){$this->conflictNotice();}}
	private function runtimeFailure(\Throwable $error,string $path,string $code):void{$head=$this->manager->head($this->document->tenantId(),$this->document->id(),$this->principalId);if(($head?->number()??0)!==$this->baseRevision||($head&&$this->baseHash!==''&&!hash_equals($head->contentHash(),$this->baseHash))){$this->remoteRevision=$head?->number()??0;$this->conflictNotice();return;}$message=PanelSensitiveDataSanitizer::sanitize($error->getMessage(),['max_string_bytes'=>512]);$this->notice($path,$code,is_string($message)&&trim($message)!==''?$message:'The Studio editor operation failed.');}
	private function conflictNotice():void{$remote=$this->remoteRevision===0?'missing':(string)$this->remoteRevision;$this->notice('editor.revision','remote_revision_conflict','The remote Studio revision changed ('.$remote.'). Reload it before saving local changes.');}
	private function notice(string $path,string $code,string $message,string $severity='error'):void{$this->sessionDiagnostics[]=new PanelStudioDiagnostic($path,$code,$message,$severity);}
	private static function canonical(mixed $value):string{return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
	/** @param list<string> $expected */ private static function exactKeys(array $value,array $expected):bool{$keys=array_keys($value);sort($keys,SORT_STRING);sort($expected,SORT_STRING);return$keys===$expected;}
}
