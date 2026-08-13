<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Strict serializable state shape shared by activation-store adapters. */
final class PanelDomainActivationState {
	private function __construct(){}
	/** @return array<string,mixed> */public static function initial():array{return['version'=>1,'revision'=>0,'active'=>[],'history'=>[],'idempotency'=>[],'receipts'=>[]];}
	/** @param array<string,mixed> $state @return array<string,mixed> */public static function validate(array $state):array {
		if(array_keys($state)!==['version','revision','active','history','idempotency','receipts']||$state['version']!==1||!is_int($state['revision'])||$state['revision']<0||!is_array($state['active'])||!is_array($state['history'])||!is_array($state['idempotency'])||!is_array($state['receipts'])){throw new \UnexpectedValueException('Domain activation state shape is invalid.');}
		if(count($state['active'])>512||count($state['history'])>512||count($state['idempotency'])>50000||count($state['receipts'])>50000){throw new \LengthException('Domain activation state exceeds its bounded capacity.');}
		foreach($state['active']as$domain=>$entry){self::entry((string)$domain,$entry);}
		foreach($state['history']as$domain=>$versions){PanelOperationsGuard::name((string)$domain,'domain activation history id');if(!is_array($versions)||($versions!==[]&&!array_is_list($versions))||count($versions)>256){throw new \UnexpectedValueException('Domain activation history is invalid.');}foreach($versions as$entry){self::entry((string)$domain,$entry);}}
		foreach($state['receipts']as$id=>$payload){if(!is_string($id)||!is_array($payload)||PanelDomainActivationReceipt::hydrate($payload)->id()!==$id){throw new \UnexpectedValueException('Domain activation receipt state is invalid.');}}
		foreach($state['idempotency']as$hash=>$entry){if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1||!is_array($entry)||array_keys($entry)!==['fingerprint','receipt_id']||!is_string($entry['fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$entry['fingerprint'])!==1||!is_string($entry['receipt_id'])||!isset($state['receipts'][$entry['receipt_id']])){throw new \UnexpectedValueException('Domain activation idempotency state is invalid.');}}
		return$state;
	}
	private static function entry(string $domain,mixed $entry):void {PanelOperationsGuard::name($domain,'activated domain id');if(!is_array($entry)||array_keys($entry)!==['compilation','materialization_fingerprint','host_fingerprint','receipt_id']||!is_array($entry['compilation'])||!is_string($entry['materialization_fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$entry['materialization_fingerprint'])!==1||!is_string($entry['host_fingerprint'])||preg_match('/^[a-f0-9]{64}$/D',$entry['host_fingerprint'])!==1||!is_string($entry['receipt_id'])){throw new \UnexpectedValueException('Activated domain state entry is invalid.');}$compilation=PanelDomainCompilation::hydrate($entry['compilation']);if($compilation->domainId()!==$domain||!$compilation->signed()){throw new \UnexpectedValueException('Activated domain compilation state is invalid.');}}
}
