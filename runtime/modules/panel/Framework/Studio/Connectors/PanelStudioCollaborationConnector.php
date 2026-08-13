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
 * Route-free Studio review connector.
 *
 * Document subject, tenant, and actor are always derived from the trusted
 * editor session. Browser payloads can select an allow-listed operation, but
 * cannot supply authority, collaboration scope, or presence scope.
 */
final class PanelStudioCollaborationConnector implements \JsonSerializable {
	private const DEFAULT_LIMITS=['threads'=>20,'comments_per_thread'=>30,'directory'=>100,'watchers'=>100,'presence'=>50,'typing_per_thread'=>25];
	/** @var array<string,int> */ private readonly array $limits;

	/** @param array<string,int> $limits */
	public function __construct(
		private readonly PanelCollaborationManager $manager,
		private readonly PanelStudioIdentityConnector $identities,
		array $limits=[]
	){
		$extra=array_diff(array_keys($limits),array_keys(self::DEFAULT_LIMITS));
		if($extra!==[]){throw new \InvalidArgumentException('Studio collaboration limits contain unsupported keys.');}
		$limits=array_replace(self::DEFAULT_LIMITS,$limits);
		foreach($limits as$key=>$value){
			$maximum=match($key){'threads'=>50,'comments_per_thread'=>100,'directory'=>200,'watchers'=>200,'presence'=>100,'typing_per_thread'=>100};
			if(!is_int($value)||$value<1||$value>$maximum){throw new \InvalidArgumentException('Studio collaboration '.$key.' limit is invalid.');}
		}
		$this->limits=$limits;
	}

	public function manager():PanelCollaborationManager{return$this->manager;}
	public function identities():PanelStudioIdentityConnector{return$this->identities;}
	/** @return array<string,int> */ public function limits():array{return$this->limits;}

	public function snapshot(PanelStudioEditorSession $session):PanelStudioWorkspaceSnapshot {
		$this->assertScope($session);[$type,$id]=$this->subject($session);$actor=$session->principalId();
		$allThreads=$this->manager->threads($type,$id);$visibleThreads=array_slice($allThreads,0,$this->limits['threads']);
		$assignment=$this->manager->assignment($type,$id);$allWatcherIds=$this->manager->watchers($type,$id);$watcherIds=array_slice($allWatcherIds,0,$this->limits['watchers']);
		$presenceRows=array_slice($this->manager->presence($this->presenceScope($session)),0,$this->limits['presence']);
		$directory=array_slice($this->identities->search('',$this->limits['directory']),0,$this->limits['directory']);
		foreach($directory as$profile){if(!$profile instanceof PanelStudioIdentityProfile){throw new \UnexpectedValueException('Studio identity connector returned an invalid directory profile.');}}

		$ids=[$actor=>true];
		if(is_string($assignment['assignee']??null)){$ids[$assignment['assignee']]=true;}
		foreach($watcherIds as$userId){$ids[PanelIamGuard::identifier($userId,'Studio watcher id')]=true;}
		foreach($presenceRows as$row){if(is_string($row['user_id']??null)){$ids[$row['user_id']]=true;}}
		$commentRows=[];$typingRows=[];$visibleCommentCount=0;
		foreach($visibleThreads as$thread){
			if(is_string($thread['created_by']??null)){$ids[$thread['created_by']]=true;}
			$threadId=PanelCollaborationStateEngine::identifier($thread['id']??'','thread id',128);
			$comments=array_slice($this->manager->comments($threadId),0,$this->limits['comments_per_thread']);$commentRows[$threadId]=$comments;$visibleCommentCount+=count($comments);
			foreach($comments as$comment){
				if(is_string($comment['author']??null)){$ids[$comment['author']]=true;}
				foreach(array_slice(is_array($comment['mentions']??null)?$comment['mentions']:[],0,20)as$mention){if(is_string($mention)){$ids[$mention]=true;}}
			}
			$typingRows[$threadId]=array_slice($this->manager->typingUsers($threadId),0,$this->limits['typing_per_thread']);
			foreach($typingRows[$threadId]as$userId){$ids[$userId]=true;}
		}
		$resolved=$this->identities->resolve(array_slice(array_keys($ids),0,200));
		foreach($resolved as$key=>$profile){
			if(!$profile instanceof PanelStudioIdentityProfile||!is_string($key)||!hash_equals($key,$profile->id())){throw new \UnexpectedValueException('Studio identity connector returned an invalid resolution map.');}
		}
		foreach($directory as$profile){$resolved[$profile->id()]=$profile;}
		$identity=static fn(string $profileId):PanelStudioIdentityProfile=>$resolved[$profileId]??PanelStudioIdentityProfile::unresolved($profileId);

		$watchers=array_map(static fn(string $userId):PanelStudioIdentityProfile=>$identity($userId),$watcherIds);
		$presence=[];
		foreach($presenceRows as$row){
			$userId=PanelIamGuard::identifier($row['user_id']??'','Studio presence user id');
			$presence[]=['identity'=>$identity($userId),'last_seen_at'=>$this->publicText($row['last_seen_at']??'',64),'expires_at'=>$this->publicText($row['expires_at']??'',64)];
		}
		$threads=[];$open=0;$resolvedCount=0;
		foreach($visibleThreads as$thread){
			$threadId=PanelCollaborationStateEngine::identifier($thread['id']??'','thread id',128);$status=(string)($thread['status']??'open');
			if($status==='open'){$open++;}elseif($status==='resolved'){$resolvedCount++;}
			$comments=[];
			foreach($commentRows[$threadId]??[]as$comment){
				$author=PanelIamGuard::identifier($comment['author']??'','Studio comment author id');$mentions=[];
				foreach(array_slice(is_array($comment['mentions']??null)?$comment['mentions']:[],0,20)as$mention){if(is_string($mention)){$mentions[]=$identity(PanelIamGuard::identifier($mention,'Studio mention id'));}}
				$comments[]=[
					'id'=>PanelCollaborationStateEngine::identifier($comment['id']??'','comment id',128),
					'author'=>$identity($author),'body'=>$this->publicText($comment['body']??'',10000),
					'mentions'=>$mentions,'created_at'=>$this->publicText($comment['created_at']??'',64),
				];
			}
			$typing=array_map(static fn(string $userId):PanelStudioIdentityProfile=>$identity($userId),$typingRows[$threadId]??[]);
			$creator=PanelIamGuard::identifier($thread['created_by']??'','Studio thread creator id');
			$threads[]=[
				'id'=>$threadId,'title'=>$this->publicText($thread['title']??'',500),'status'=>$status,
				'created_by'=>$identity($creator),'created_at'=>$this->publicText($thread['created_at']??'',64),
				'updated_at'=>$this->publicText($thread['updated_at']??'',64),'comments'=>$comments,'typing'=>$typing,
			];
		}
		$assignee=is_string($assignment['assignee']??null)?$identity(PanelIamGuard::identifier($assignment['assignee'],'Studio assignee id')):null;
		$chain=$this->manager->verifyReceipts();
		return new PanelStudioWorkspaceSnapshot(
			$type,$id,$identity($actor),$directory,$assignee,$watchers,in_array($actor,$watcherIds,true),
			$presence,$threads,$this->manager->cursor(),
			[
				'threads_total'=>count($allThreads),'threads_visible'=>count($visibleThreads),
				'comments_visible'=>$visibleCommentCount,'open_threads'=>$open,'resolved_threads'=>$resolvedCount,
				'watchers_total'=>count($allWatcherIds),'watchers_visible'=>count($watchers),
				'presence'=>count($presence),'directory'=>count($directory),
			],
			$this->limits,
			['valid'=>(bool)($chain['valid']??false),'count'=>(int)($chain['count']??0),'head_hash'=>is_string($chain['head_hash']??null)?$chain['head_hash']:'']
		);
	}

	/** @param array<string,mixed> $input */
	public function handle(PanelStudioEditorSession $session,array $input):PanelStudioCollaborationResult {
		$this->assertScope($session);
		if(count($input)>256){throw new \LengthException('Studio collaboration request contains too many fields.');}
		$encoded=$input['studio_collaboration_operation']??null;
		if(!is_string($encoded)||strlen($encoded)>256){throw new \InvalidArgumentException('Studio collaboration operation is invalid.');}
		[$operation,$resource]=array_pad(explode(':',$encoded,2),2,'');[$type,$id]=$this->subject($session);$actor=$session->principalId();$changed=true;$resultId=$id;
		switch($operation){
			case'create_thread':
				$title=$this->publicText($input['studio_collaboration_title']??'',500);
				if(trim($title)===''){throw new \InvalidArgumentException('Studio review threads require a title.');}
				$thread=$this->manager->createThread($actor,$type,$id,$title,['surface'=>'studio']);
				$resultId=(string)$thread['id'];$operation='thread.create';break;
			case'comment':
				$thread=$this->assertThread($session,$resource);$values=$input['studio_collaboration_comments']??null;
				if(!is_array($values)||array_is_list($values)||count($values)>100){throw new \InvalidArgumentException('Studio collaboration comment payload is invalid.');}
				$body=$this->publicText($values[$thread['id']]??'',10000);
				if(trim($body)===''){throw new \InvalidArgumentException('Studio collaboration comments cannot be empty.');}
				$this->manager->comment($thread['id'],$actor,$body,[],['surface'=>'studio']);
				$resultId=$thread['id'];$operation='comment.create';break;
			case'resolve':
			case'reopen':
				$thread=$this->assertThread($session,$resource);$this->manager->setThreadStatus($thread['id'],$operation==='resolve'?'resolved':'open',$actor);
				$resultId=$thread['id'];$operation=$operation==='resolve'?'thread.resolve':'thread.reopen';break;
			case'assign':
				$assignee=PanelIamGuard::identifier($input['studio_collaboration_assignee']??'','Studio assignee id');
				$profile=$this->identities->resolve([$assignee])[$assignee]??null;
				if(!$profile instanceof PanelStudioIdentityProfile||!$profile->assignable()){throw new \InvalidArgumentException('Studio assignments require an active resolvable identity.');}
				$this->manager->assign($type,$id,$assignee,$actor,['surface'=>'studio']);$operation='assignment.assign';break;
			case'unassign':
				$changed=$this->manager->unassign($type,$id,$actor);$operation='assignment.unassign';break;
			case'watch':
				$this->manager->watch($type,$id,$actor,['surface'=>'studio']);$operation='watch.watch';break;
			case'unwatch':
				$changed=$this->manager->unwatch($type,$id,$actor);$operation='watch.unwatch';break;
			default:throw new \InvalidArgumentException('Studio collaboration operation is not supported.');
		}
		return new PanelStudioCollaborationResult($operation,$changed,$resultId,$this->snapshot($session));
	}

	public function acquirePresence(PanelStudioEditorSession $session,int $ttlSeconds=60):PanelStudioPresenceLease {
		$this->assertScope($session);$lease=$this->manager->acquirePresence($this->presenceScope($session),$session->principalId(),$ttlSeconds,['surface'=>'studio']);
		return new PanelStudioPresenceLease((string)($lease['lease_id']??''),(string)($lease['lease_token']??''),(string)($lease['expires_at']??''));
	}
	public function heartbeatPresence(PanelStudioEditorSession $session,string $leaseToken,int $ttlSeconds=60):PanelStudioPresenceLease {
		$this->assertScope($session);$lease=$this->manager->heartbeatPresence($this->presenceScope($session),$session->principalId(),$leaseToken,$ttlSeconds);
		return new PanelStudioPresenceLease((string)($lease['lease_id']??''),$leaseToken,(string)($lease['expires_at']??''));
	}
	public function releasePresence(PanelStudioEditorSession $session,string $leaseToken):bool {
		$this->assertScope($session);return$this->manager->releasePresence($this->presenceScope($session),$session->principalId(),$leaseToken);
	}
	public function setTyping(PanelStudioEditorSession $session,string $threadId,bool $typing=true,int $ttlSeconds=12):bool {
		$thread=$this->assertThread($session,$threadId);return$this->manager->typing($thread['id'],$session->principalId(),$typing,$ttlSeconds);
	}

	/** @return array<string,mixed> */
	public function manifest():array {
		$chain=$this->manager->verifyReceipts();
		return[
			'type'=>'panel_studio_collaboration_connector','version'=>1,'route_free'=>true,
			'identity_connector'=>$this->identities->manifest(),'limits'=>$this->limits,
			'cursor'=>$this->manager->cursor(),'receipt_chain'=>[
				'valid'=>(bool)($chain['valid']??false),'count'=>(int)($chain['count']??0),
				'head_hash'=>is_string($chain['head_hash']??null)?$chain['head_hash']:'',
			],
			'capabilities'=>[
				'threads'=>true,'comments'=>true,'resolve_reopen'=>true,'assignments'=>true,
				'watchers'=>true,'presence_leases'=>true,'typing_indicators'=>true,'identity_search'=>true,
			],
			'security'=>[
				'server_owned_subject'=>true,'server_owned_actor'=>true,'tenant_scope_enforced'=>true,
				'csrf_delegated_to_editor_boundary'=>true,'presence_token_serialized'=>false,
				'comment_html'=>false,'arbitrary_callbacks'=>false,
			],
		];
	}
	public function jsonSerialize():array{return$this->manifest();}

	/** @return array<string,mixed> */
	private function assertThread(PanelStudioEditorSession $session,string $threadId):array {
		$this->assertScope($session);$thread=$this->manager->thread(PanelCollaborationStateEngine::identifier($threadId,'thread id',128));
		[$type,$id]=$this->subject($session);
		if(!is_array($thread)||($thread['subject_type']??null)!==$type||($thread['subject_id']??null)!==$id){throw new \OutOfBoundsException('Studio collaboration thread does not belong to this document.');}
		return$thread;
	}
	private function assertScope(PanelStudioEditorSession $session):void {
		$tenant=$this->identities->tenantId();
		if($tenant!==null&&!hash_equals($tenant,$session->document()->tenantId())){throw new \LogicException('Studio identity connector tenant does not match this editor document.');}
	}
	/** @return array{string,string} */
	private function subject(PanelStudioEditorSession $session):array{return['studio_document',hash('sha256',$session->document()->tenantId()."\0".$session->document()->id())];}
	private function presenceScope(PanelStudioEditorSession $session):string{return'studio:'.$this->subject($session)[1];}
	private function publicText(mixed $value,int $maximum):string {
		if(!is_string($value)){throw new \InvalidArgumentException('Studio collaboration text is invalid.');}
		$value=trim($value);$clean=PanelSensitiveDataSanitizer::sanitize($value,['max_string_bytes'=>$maximum]);
		if(!is_string($clean)||strlen($clean)>$maximum||($clean!==''&&preg_match('//u',$clean)!==1)){throw new \InvalidArgumentException('Studio collaboration text is invalid.');}
		return$clean;
	}
}
