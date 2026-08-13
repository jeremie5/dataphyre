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
 * Typed, callback-free Studio definition for one Resource status-board lane.
 *
 * A lane always contributes a read-only status view. When it accepts moves it
 * also contributes declarative transition metadata; the host still has to bind
 * the Resource's authorized save or transition handler before mutations exist.
 */
final class PanelStudioBoardColumn implements \JsonSerializable {
	/** @var list<string>|null */ private readonly ?array $fromStatuses;
	/** @var array<string,mixed> */ private readonly array $presentation;

	/**
	 * @param list<string>|null $fromStatuses Null means every other declared lane.
	 * @param array<string,mixed> $presentation Responsive collection-item metadata.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $status,
		private readonly string $label,
		private readonly string $tone='neutral',
		private readonly bool $acceptsMoves=true,
		private readonly string $transitionName='',
		private readonly string $transitionLabel='',
		?array $fromStatuses=null,
		private readonly string $confirmation='',
		array $presentation=[],
	){
		if(preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$name)!==1){
			throw new \InvalidArgumentException('Studio board-column names must be normalized identifiers.');
		}
		if(trim($status)==='' || strlen($status)>128 || preg_match('//u',$status)!==1 || strlen($label)>160 || preg_match('//u',$label)!==1){
			throw new \InvalidArgumentException('Studio board-column status and label values are invalid.');
		}
		if(!in_array($tone,['neutral','primary','success','warning','danger','info'],true)){
			throw new \InvalidArgumentException('Studio board-column tone is invalid.');
		}
		if($acceptsMoves && preg_match('/^[a-z][a-z0-9_.-]{0,127}$/',$transitionName)!==1){
			throw new \InvalidArgumentException('Movable Studio board columns require a normalized transition name.');
		}
		if(strlen($transitionLabel)>160 || strlen($confirmation)>1024 || preg_match('//u',$transitionLabel.$confirmation)!==1){
			throw new \InvalidArgumentException('Studio board-column transition copy is invalid.');
		}
		if($fromStatuses!==null){
			if(!array_is_list($fromStatuses) || count($fromStatuses)>PanelStudioDefinition::MAX_PROPERTIES){
				throw new \InvalidArgumentException('Studio board-column source statuses must be a bounded list.');
			}
			$normalized=[];
			foreach($fromStatuses as $from){
				if(!is_string($from) || trim($from)==='' || strlen($from)>128 || preg_match('//u',$from)!==1){
					throw new \InvalidArgumentException('Studio board-column source statuses are invalid.');
				}
				$from=trim($from);
				if(!in_array($from,$normalized,true)){$normalized[]=$from;}
			}
			$fromStatuses=$normalized;
		}
		$this->fromStatuses=$fromStatuses;
		$this->presentation=PanelCollectionItemPresentation::normalize($presentation);
	}

	public function name():string{return$this->name;}
	public function status():string{return$this->status;}
	public function label():string{return$this->label;}
	public function tone():string{return$this->tone;}
	public function acceptsMoves():bool{return$this->acceptsMoves;}
	/** @return list<string>|null */ public function fromStatuses():?array{return$this->fromStatuses;}
	/** @return array<string,mixed> */ public function presentation():array{return$this->presentation;}

	/** @return array{name:string,status:string,label:string,tone:string,meta:array<string,mixed>} */
	public function resourceDefinition():array{
		return[
			'name'=>$this->name,
			'status'=>$this->status,
			'label'=>$this->label,
			'tone'=>$this->tone,
			'meta'=>['studio_board_column'=>true],
		];
	}

	/**
	 * @param list<string> $declaredStatuses
	 * @return array<string,mixed>|null
	 */
	public function transition(array $declaredStatuses):?array{
		if(!$this->acceptsMoves){return null;}
		$from=$this->fromStatuses;
		if($from===null){
			$from=[];
			foreach($declaredStatuses as$status){if($status!==$this->status&&!in_array($status,$from,true)){$from[]=$status;}}
		}
		return[
			'name'=>$this->transitionName,
			'to'=>$this->status,
			'from'=>$from,
			'label'=>$this->transitionLabel!==''?$this->transitionLabel:$this->label,
			'tone'=>$this->tone,
			'confirmation'=>$this->confirmation,
		];
	}

	public function jsonSerialize():array{
		return[
			'type'=>'panel_studio_board_column',
			'version'=>1,
			'name'=>$this->name,
			'status'=>$this->status,
			'label'=>$this->label,
			'tone'=>$this->tone,
			'accepts_moves'=>$this->acceptsMoves,
			'transition_name'=>$this->acceptsMoves?$this->transitionName:null,
			'transition_label'=>$this->acceptsMoves?$this->transitionLabel:null,
			'from_statuses'=>$this->acceptsMoves?$this->fromStatuses:null,
			'confirmation'=>$this->acceptsMoves?$this->confirmation:null,
			'presentation'=>$this->presentation,
			'runtime'=>[
				'read_only_view'=>true,
				'mutation_handler'=>false,
				'host_activation_required_for_moves'=>$this->acceptsMoves,
			],
		];
	}
}
