<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Typed three-way Studio merge result with path-level conflict evidence. */
final class PanelStudioMergeResult implements \JsonSerializable {
	/** @param list<array<string,mixed>> $conflicts @param list<string> $approvers */public function __construct(private readonly string $sourceBranch,private readonly string $targetBranch,private readonly string $baseHash,private readonly string $sourceHash,private readonly string $targetHash,private readonly ?PanelStudioDefinition $definition,private readonly array $conflicts,private readonly string $strategy,private readonly array $approvers=[]){foreach([$sourceBranch,$targetBranch]as$branch){PanelOperationsGuard::name($branch,'Studio branch');}foreach([$baseHash,$sourceHash,$targetHash]as$hash){if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1){throw new \InvalidArgumentException('Studio merge hash is invalid.');}}if(!in_array($strategy,['manual','ours','theirs'],true)||($conflicts===[]&&$definition===null)){throw new \InvalidArgumentException('Studio merge result is invalid.');}foreach($conflicts as$conflict){if(!is_array($conflict)||!isset($conflict['path'],$conflict['base_digest'],$conflict['source_digest'],$conflict['target_digest'])){throw new \InvalidArgumentException('Studio merge conflict is invalid.');}}foreach($approvers as$approver){PanelOperationsGuard::identifier((string)$approver,'Studio merge approver');}}
	public function sourceBranch():string{return$this->sourceBranch;}public function targetBranch():string{return$this->targetBranch;}public function resolved():bool{return$this->definition!==null;}public function definition():?PanelStudioDefinition{return$this->definition;}/** @return list<array<string,mixed>> */public function conflicts():array{return$this->conflicts;}/** @return list<string> */public function approvers():array{return$this->approvers;}
	public function fingerprint():string{return PanelOperationsGuard::digest($this->values());}public function jsonSerialize():array{return PanelManifestContract::stamp($this->values()+['fingerprint'=>$this->fingerprint()]);}
	/** @return array<string,mixed> */private function values():array{return['type'=>'panel_studio_merge_result_manifest','version'=>1,'source_branch'=>$this->sourceBranch,'target_branch'=>$this->targetBranch,'base_hash'=>$this->baseHash,'source_hash'=>$this->sourceHash,'target_hash'=>$this->targetHash,'resolved'=>$this->resolved(),'definition'=>$this->definition?->jsonSerialize(),'conflicts'=>$this->conflicts,'strategy'=>$this->strategy,'approvers'=>$this->approvers];}
}
