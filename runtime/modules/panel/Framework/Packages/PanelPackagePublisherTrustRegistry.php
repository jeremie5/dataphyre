<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Derives publisher eligibility from current signed evidence, not a scalar popularity score. */
final class PanelPackagePublisherTrustRegistry implements \JsonSerializable {
	/** @param list<string> $blockingCategories */
	public function __construct(private readonly PanelPackageMarketplaceTrustNetwork $network,private readonly array $blockingCategories=['identity','provenance','security','malware']){PanelOperationsGuard::names($blockingCategories,'blocking publisher evidence category',96,64);}
	public function network():PanelPackageMarketplaceTrustNetwork{return$this->network;}
	public function profile(string $publisher,string|int|\DateTimeInterface|null $at=null):PanelPackagePublisherTrustProfile{
		$publisher=PanelOperationsGuard::name($publisher,'publisher');$health=$this->network->health($at);$evaluatedAt=(string)$health['as_of'];$now=(new \DateTimeImmutable($evaluatedAt))->getTimestamp();$records=[];$superseded=[];
		foreach($this->network->attestationRecords()as$record){$subject=$record['subject'];if($subject['publisher']!==$publisher)continue;$issued=(new \DateTimeImmutable($subject['issued_at']))->getTimestamp();$valid=(new \DateTimeImmutable($subject['valid_until']))->getTimestamp();if($issued>$now||$valid<=$now)continue;$records[$subject['attestation_id']]=$record;if(isset($subject['supersedes']))$superseded[$subject['supersedes']]=true;}
		$signals=[];$categories=[];$evidence=[];$reasons=[];$blocked=false;$restricted=false;
		foreach($records as$id=>$record){$subject=$record['subject'];if(isset($superseded[$id])||$subject['signal']==='withdrawn')continue;$signal=$subject['signal'];$category=$subject['category'];$signals[$signal]=($signals[$signal]??0)+1;$categories[$category]=($categories[$category]??0)+1;if($signal==='failed'&&in_array($category,$this->blockingCategories,true)){$blocked=true;$reasons['failed_'.$category]=true;}elseif($signal==='failed'||$signal==='warning'){$restricted=true;$reasons[$signal.'_'.$category]=true;}$evidence[]=['attestation_id'=>$id,'issuer'=>$subject['issuer'],'category'=>$category,'signal'=>$signal,'evidence_hash'=>$subject['evidence_hash'],'issued_at'=>$subject['issued_at'],'valid_until'=>$subject['valid_until'],'package'=>$subject['package']??null,'version'=>$subject['version']??null,'log_id'=>$record['log_id'],'event_digest'=>$record['event_digest']];}
		ksort($signals,SORT_STRING);ksort($categories,SORT_STRING);usort($evidence,static fn(array $left,array $right):int=>strcmp((string)$left['attestation_id'],(string)$right['attestation_id']));$status=$blocked?'blocked':($restricted?'restricted':(($signals['verified']??0)>0?'observed':'unknown'));if(!(bool)$health['complete'])$reasons['transparency_incomplete']=true;if((bool)$health['stale'])$reasons['transparency_stale']=true;$reasonCodes=array_keys($reasons);sort($reasonCodes,SORT_STRING);
		return new PanelPackagePublisherTrustProfile($publisher,$status,(bool)$health['complete'],(bool)$health['stale'],$signals,$categories,$reasonCodes,$evidence,$evaluatedAt);
	}
	/** @param array<string,mixed> $subject @param array<string,mixed> $context @return array<string,mixed> */public function __invoke(string $kind,array $subject,array $context=[]):array{$publisher=$subject['publisher']??null;if(!is_string($publisher))throw new \InvalidArgumentException('Publisher trust lookup requires a publisher.');return$this->profile($publisher,$context['at']??null)->jsonSerialize();}
	public function assertEligible(string $publisher,array $allowedStatuses=['observed'],string|int|\DateTimeInterface|null $at=null):PanelPackagePublisherTrustProfile{$statuses=PanelOperationsGuard::names($allowedStatuses,'allowed publisher status');foreach($statuses as$status){if(!in_array($status,['unknown','observed','restricted','blocked'],true))throw new \InvalidArgumentException('Allowed publisher status is unsupported.');}$profile=$this->profile($publisher,$at);if(!$profile->eligible($statuses))throw new \LogicException('Publisher evidence is blocked, restricted, unknown, incomplete, or stale under marketplace policy.');return$profile;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_package_publisher_trust_registry_manifest','version'=>1,'blocking_categories'=>$this->blockingCategories,'network_health'=>$this->network->health(),'capabilities'=>['signed_attestations'=>true,'evidence_expiry'=>true,'evidence_supersession'=>true,'withdrawal_events'=>true,'category_policy'=>true,'scalar_reputation_score'=>false,'fail_closed_completeness'=>true]]);}
}
