<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Explainable policy result and the obligations required before execution. */
final class PanelPolicyDecision implements \JsonSerializable {
	/** @param list<string> $matchedRules @param list<string> $reasons @param array<string,mixed> $obligations @param list<array<string,mixed>> $trace */
	public function __construct(private readonly bool $allowed,private readonly string $requestFingerprint,private readonly array $matchedRules,private readonly array $reasons,private readonly array $obligations,private readonly array $trace,private readonly int $revision){if(preg_match('/^[a-f0-9]{64}$/D',$requestFingerprint)!==1){throw new \InvalidArgumentException('Policy decision request fingerprint is invalid.');}if($revision<0){throw new \InvalidArgumentException('Policy decision revision cannot be negative.');}}
	public function allowed():bool{return$this->allowed;}/** @return list<string> */public function matchedRules():array{return$this->matchedRules;}/** @return list<string> */public function reasons():array{return$this->reasons;}/** @return array<string,mixed> */public function obligations():array{return$this->obligations;}/** @return list<array<string,mixed>> */public function trace():array{return$this->trace;}public function revision():int{return$this->revision;}
	public function requiresApproval():bool{return(int)($this->obligations['approval_count']??0)>0;}public function approvalCount():int{return max(0,(int)($this->obligations['approval_count']??0));}public function confirmationRequired():bool{return($this->obligations['confirmation']??false)===true;}public function mfaLevel():int{return max(0,(int)($this->obligations['mfa_level']??0));}
	public function assertAllowed():self {if(!$this->allowed){throw new \LogicException($this->reasons[0]??'Policy denied the operation.');}return$this;}
	public function jsonSerialize():array{return['type'=>'panel_policy_decision','allowed'=>$this->allowed,'request_fingerprint'=>$this->requestFingerprint,'matched_rules'=>$this->matchedRules,'reasons'=>$this->reasons,'obligations'=>$this->obligations,'trace'=>$this->trace,'revision'=>$this->revision];}
}
