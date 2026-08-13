<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

final class PanelCommandFabricState {
	private function __construct(){}

	/** @return array<string,mixed> */
	public static function initial():array {
		return [
			'version'=>1,'revision'=>0,'sequence'=>0,'anchor_hash'=>str_repeat('0',64),
			'commands'=>[],'receipts'=>[],'events'=>[],'subscriber_cursors'=>[],
		];
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	public static function validate(array $state):array {
		$keys=array_keys($state);sort($keys,SORT_STRING);
		$required=['anchor_hash','commands','events','receipts','revision','sequence','subscriber_cursors','version'];sort($required,SORT_STRING);
		if(
			$keys!==$required||$state['version']!==1||!is_int($state['revision'])||$state['revision']<0
			||!is_int($state['sequence'])||$state['sequence']<0
			||!is_string($state['anchor_hash'])||preg_match('/^[a-f0-9]{64}$/D',$state['anchor_hash'])!==1
			||!is_array($state['commands'])||!is_array($state['receipts'])||!is_array($state['events'])||!is_array($state['subscriber_cursors'])
		){
			throw new \UnexpectedValueException('Command fabric state is invalid.');
		}
		if(count($state['commands'])>50000||count($state['receipts'])>50000||count($state['events'])>200000||count($state['subscriber_cursors'])>1024){
			throw new \LengthException('Command fabric state exceeds its capacity.');
		}
		foreach($state['commands'] as $hash=>$entry){
			$entryKeys=is_array($entry)?array_keys($entry):[];sort($entryKeys,SORT_STRING);
			$entryRequired=['attempts','envelope','fingerprint','sealed','status','updated_at'];sort($entryRequired,SORT_STRING);
			if(
				!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_array($entry)||$entryKeys!==$entryRequired
				||!is_string($entry['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$entry['fingerprint'])!==1
				||!in_array($entry['status'],['executing','succeeded','failed','denied'],true)
				||!is_array($entry['envelope'])||!is_array($entry['sealed'])
				||!is_int($entry['attempts'])||$entry['attempts']<1||!is_string($entry['updated_at'])
			){
				throw new \UnexpectedValueException('Command fabric journal entry is invalid.');
			}
			PanelOperationsGuard::instant($entry['updated_at']);
		}
		foreach($state['receipts'] as $hash=>$payload){
			if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_array($payload)){
				throw new \UnexpectedValueException('Command fabric receipt state is invalid.');
			}
			PanelCommandReceipt::hydrate($payload);
		}
		$lastSequence=0;
		foreach($state['events'] as $id=>$payload){
			if(!is_string($id)||!is_array($payload)){
				throw new \UnexpectedValueException('Command fabric event state is invalid.');
			}
			$event=PanelEventEnvelope::hydrate($payload);
			if($event->id()!==$id||$event->sequence()<=$lastSequence){
				throw new \UnexpectedValueException('Command fabric event order is invalid.');
			}
			$lastSequence=$event->sequence();
		}
		if($lastSequence!==$state['sequence']&&$state['events']!==[]){
			throw new \UnexpectedValueException('Command fabric event sequence is inconsistent.');
		}
		foreach($state['subscriber_cursors'] as $name=>$cursor){
			PanelOperationsGuard::name((string)$name,'command fabric subscriber',128);
			if(!is_int($cursor)||$cursor<0||$cursor>$state['sequence']){
				throw new \UnexpectedValueException('Command fabric subscriber cursor is invalid.');
			}
		}
		return $state;
	}
}
