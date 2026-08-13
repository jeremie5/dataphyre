<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);
namespace Dataphyre\Panel;

/** Bounded, secret-free Studio review workspace projection. */
final class PanelStudioWorkspaceSnapshot implements \JsonSerializable {
	/** @var list<PanelStudioIdentityProfile> */ private readonly array $directory;
	/** @var list<PanelStudioIdentityProfile> */ private readonly array $watchers;
	/** @var list<array{identity:PanelStudioIdentityProfile,last_seen_at:string,expires_at:string}> */ private readonly array $presence;
	/** @var list<array<string,mixed>> */ private readonly array $threads;
	/** @var array<string,int> */ private readonly array $counts;
	/** @var array<string,int> */ private readonly array $limits;
	/** @var array{valid:bool,count:int,head_hash:string} */ private readonly array $receiptChain;

	/**
	 * @param list<PanelStudioIdentityProfile> $directory
	 * @param list<PanelStudioIdentityProfile> $watchers
	 * @param list<array{identity:PanelStudioIdentityProfile,last_seen_at:string,expires_at:string}> $presence
	 * @param list<array<string,mixed>> $threads
	 * @param array<string,int> $counts
	 * @param array<string,int> $limits
	 * @param array<string,mixed> $receiptChain
	 */
	public function __construct(
		private readonly string $subjectType,
		private readonly string $subjectId,
		private readonly PanelStudioIdentityProfile $currentIdentity,
		array $directory,
		private readonly ?PanelStudioIdentityProfile $assignment,
		array $watchers,
		private readonly bool $watching,
		array $presence,
		array $threads,
		private readonly int $cursor,
		array $counts,
		array $limits,
		array $receiptChain
	){
		if($subjectType!=='studio_document'){throw new \InvalidArgumentException('Studio collaboration snapshot subject type is invalid.');}
		PanelCollaborationStateEngine::identifier($subjectId,'Studio collaboration subject id');
		if($cursor<0){throw new \InvalidArgumentException('Studio collaboration cursor cannot be negative.');}
		$this->directory=self::profiles($directory,200,'directory');
		$this->watchers=self::profiles($watchers,200,'watchers');
		$this->presence=self::normalizePresence($presence);
		$this->threads=self::normalizeThreads($threads);
		$this->counts=self::integers($counts,'count',false);
		$this->limits=self::integers($limits,'limit',true);
		$valid=$receiptChain['valid']??null;$count=$receiptChain['count']??null;$head=$receiptChain['head_hash']??null;
		if(!is_bool($valid)||!is_int($count)||$count<0||!is_string($head)||($head!==''&&preg_match('/^[a-f0-9]{64}$/D',$head)!==1)){throw new \InvalidArgumentException('Studio collaboration receipt-chain projection is invalid.');}
		$this->receiptChain=['valid'=>$valid,'count'=>$count,'head_hash'=>$head];
	}

	public function subjectType():string{return$this->subjectType;}
	public function subjectId():string{return$this->subjectId;}
	public function currentIdentity():PanelStudioIdentityProfile{return$this->currentIdentity;}
	/** @return list<PanelStudioIdentityProfile> */ public function directory():array{return$this->directory;}
	public function assignment():?PanelStudioIdentityProfile{return$this->assignment;}
	/** @return list<PanelStudioIdentityProfile> */ public function watchers():array{return$this->watchers;}
	public function watching():bool{return$this->watching;}
	/** @return list<array{identity:PanelStudioIdentityProfile,last_seen_at:string,expires_at:string}> */ public function presence():array{return$this->presence;}
	/** @return list<array<string,mixed>> */ public function threads():array{return$this->threads;}
	public function cursor():int{return$this->cursor;}
	/** @return array<string,int> */ public function counts():array{return$this->counts;}
	/** @return array<string,int> */ public function limits():array{return$this->limits;}
	/** @return array{valid:bool,count:int,head_hash:string} */ public function receiptChain():array{return$this->receiptChain;}

	/** Compact browser model: no directory, comments, presence leases, or thread bodies. @return array<string,mixed> */
	public function model():array {
		return[
			'type'=>'panel_studio_workspace_summary','version'=>1,
			'subject'=>['type'=>$this->subjectType,'id'=>$this->subjectId],
			'current_identity'=>$this->currentIdentity->jsonSerialize(),
			'assignment'=>$this->assignment?->jsonSerialize(),'watching'=>$this->watching,
			'cursor'=>$this->cursor,'counts'=>$this->counts,'limits'=>$this->limits,
			'receipt_chain'=>$this->receiptChain,
		];
	}

	/** @return array<string,mixed> */
	public function jsonSerialize():array {
		$serialize=static fn(PanelStudioIdentityProfile $profile):array=>$profile->jsonSerialize();
		$presence=array_map(static fn(array $item):array=>['identity'=>$item['identity']->jsonSerialize(),'last_seen_at'=>$item['last_seen_at'],'expires_at'=>$item['expires_at']],$this->presence);
		$threads=array_map(static function(array $thread):array{
			$thread['created_by']=$thread['created_by']->jsonSerialize();
			$thread['typing']=array_map(static fn(PanelStudioIdentityProfile $profile):array=>$profile->jsonSerialize(),$thread['typing']);
			$thread['comments']=array_map(static function(array $comment):array{
				$comment['author']=$comment['author']->jsonSerialize();
				$comment['mentions']=array_map(static fn(PanelStudioIdentityProfile $profile):array=>$profile->jsonSerialize(),$comment['mentions']);
				return$comment;
			},$thread['comments']);
			return$thread;
		},$this->threads);
		return[
			'type'=>'panel_studio_workspace_snapshot','version'=>1,
			'subject'=>['type'=>$this->subjectType,'id'=>$this->subjectId],
			'current_identity'=>$this->currentIdentity->jsonSerialize(),
			'directory'=>array_map($serialize,$this->directory),
			'assignment'=>$this->assignment?->jsonSerialize(),
			'watchers'=>array_map($serialize,$this->watchers),'watching'=>$this->watching,
			'presence'=>$presence,'threads'=>$threads,'cursor'=>$this->cursor,
			'counts'=>$this->counts,'limits'=>$this->limits,'receipt_chain'=>$this->receiptChain,
		];
	}

	/** @param list<mixed> $profiles @return list<PanelStudioIdentityProfile> */
	private static function profiles(array $profiles,int $maximum,string $label):array {
		if(!array_is_list($profiles)||count($profiles)>$maximum){throw new \LengthException("Studio collaboration {$label} projection is invalid.");}
		foreach($profiles as$profile){if(!$profile instanceof PanelStudioIdentityProfile){throw new \InvalidArgumentException("Studio collaboration {$label} projection requires identity profiles.");}}
		return array_values($profiles);
	}

	/** @param list<array<string,mixed>> $presence @return list<array{identity:PanelStudioIdentityProfile,last_seen_at:string,expires_at:string}> */
	private static function normalizePresence(array $presence):array {
		if(!array_is_list($presence)||count($presence)>100){throw new \LengthException('Studio collaboration presence projection is invalid.');}
		$result=[];
		foreach($presence as$item){
			if(!is_array($item)||array_is_list($item)||count($item)!==3||array_diff(array_keys($item),['identity','last_seen_at','expires_at'])!==[]||!(($item['identity']??null)instanceof PanelStudioIdentityProfile)){throw new \InvalidArgumentException('Studio collaboration presence entry is invalid.');}
			$result[]=['identity'=>$item['identity'],'last_seen_at'=>self::text($item['last_seen_at'],64),'expires_at'=>self::text($item['expires_at'],64)];
		}
		return$result;
	}

	/** @param list<array<string,mixed>> $threads @return list<array<string,mixed>> */
	private static function normalizeThreads(array $threads):array {
		if(!array_is_list($threads)||count($threads)>50){throw new \LengthException('Studio collaboration thread projection is invalid.');}
		$result=[];
		foreach($threads as$thread){
			if(!is_array($thread)||array_is_list($thread)||!(($thread['created_by']??null)instanceof PanelStudioIdentityProfile)||!is_array($thread['comments']??null)||!is_array($thread['typing']??null)){throw new \InvalidArgumentException('Studio collaboration thread entry is invalid.');}
			$id=PanelCollaborationStateEngine::identifier($thread['id']??'','thread id',128);
			$status=$thread['status']??null;if(!is_string($status)||!in_array($status,['open','resolved','closed','archived'],true)){throw new \InvalidArgumentException('Studio collaboration thread status is invalid.');}
			$comments=[];
			if(!array_is_list($thread['comments'])||count($thread['comments'])>100){throw new \LengthException('Studio collaboration comment projection is invalid.');}
			foreach($thread['comments']as$comment){
				if(!is_array($comment)||array_is_list($comment)||!(($comment['author']??null)instanceof PanelStudioIdentityProfile)||!is_array($comment['mentions']??null)){throw new \InvalidArgumentException('Studio collaboration comment entry is invalid.');}
				$comments[]=[
					'id'=>PanelCollaborationStateEngine::identifier($comment['id']??'','comment id',128),
					'author'=>$comment['author'],'body'=>self::text($comment['body']??'',10000),
					'mentions'=>self::profiles($comment['mentions'],20,'mentions'),
					'created_at'=>self::text($comment['created_at']??'',64),
				];
			}
			$result[]=[
				'id'=>$id,'title'=>self::text($thread['title']??'',500),'status'=>$status,
				'created_by'=>$thread['created_by'],'created_at'=>self::text($thread['created_at']??'',64),
				'updated_at'=>self::text($thread['updated_at']??'',64),'comments'=>$comments,
				'typing'=>self::profiles($thread['typing'],100,'typing'),
			];
		}
		return$result;
	}

	/** @param array<string,mixed> $values @return array<string,int> */
	private static function integers(array $values,string $label,bool $positive):array {
		if($values!==[]&&array_is_list($values)){throw new \InvalidArgumentException("Studio collaboration {$label} map is invalid.");}
		$result=[];
		foreach($values as$key=>$value){
			if(!is_string($key)||preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key)!==1||!is_int($value)||$value<($positive?1:0)){throw new \InvalidArgumentException("Studio collaboration {$label} entry is invalid.");}
			$result[$key]=$value;
		}
		ksort($result,SORT_STRING);return$result;
	}
	private static function text(mixed $value,int $maximum):string {
		if(!is_string($value)||strlen($value)>$maximum||($value!==''&&preg_match('//u',$value)!==1)){throw new \InvalidArgumentException('Studio collaboration text projection is invalid.');}
		return$value;
	}
}
