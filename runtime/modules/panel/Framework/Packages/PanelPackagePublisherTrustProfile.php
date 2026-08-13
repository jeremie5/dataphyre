<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Evidence-based publisher profile that deliberately avoids a synthetic reputation score. */
final class PanelPackagePublisherTrustProfile implements \JsonSerializable {
	/** @param array<string,int> $signals @param array<string,int> $categories @param list<string> $reasons @param list<array<string,mixed>> $evidence */
	public function __construct(private readonly string $publisher,private readonly string $status,private readonly bool $complete,private readonly bool $stale,private readonly array $signals,private readonly array $categories,private readonly array $reasons,private readonly array $evidence,private readonly string $evaluatedAt){
		PanelOperationsGuard::name($publisher,'publisher');if(!in_array($status,['unknown','observed','restricted','blocked'],true)){throw new \InvalidArgumentException('Publisher trust status is invalid.');}PanelOperationsGuard::names($reasons,'publisher trust reason',96,256);foreach([$signals,$categories]as$counts){foreach($counts as$name=>$count){PanelOperationsGuard::name((string)$name,'publisher evidence dimension');if(!is_int($count)||$count<0)throw new \InvalidArgumentException('Publisher evidence counts are invalid.');}}foreach($evidence as$item){if(!is_array($item))throw new \InvalidArgumentException('Publisher evidence is invalid.');PanelOperationsGuard::safeMetadata($item,32);}if(PanelOperationsGuard::instant($evaluatedAt)!==$evaluatedAt)throw new \InvalidArgumentException('Publisher profile time must be canonical UTC.');
	}
	public function publisher():string{return$this->publisher;}public function status():string{return$this->status;}public function complete():bool{return$this->complete;}public function stale():bool{return$this->stale;}public function eligible(array $allowedStatuses=['observed']):bool{return$this->complete&&!$this->stale&&in_array($this->status,$allowedStatuses,true);}
	/** @return array<string,mixed> */public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_package_publisher_trust_profile','version'=>1,'publisher'=>$this->publisher,'status'=>$this->status,'complete'=>$this->complete,'stale'=>$this->stale,'signals'=>$this->signals,'categories'=>$this->categories,'reason_codes'=>$this->reasons,'evidence'=>$this->evidence,'evidence_count'=>count($this->evidence),'evaluated_at'=>$this->evaluatedAt,'score'=>null,'score_semantics'=>'not_applicable']);}
}
