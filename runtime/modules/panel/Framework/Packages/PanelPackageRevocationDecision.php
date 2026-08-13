<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Panel;

/** Immutable fail-closed decision from the synchronized marketplace revocation set. */
final class PanelPackageRevocationDecision implements \JsonSerializable {
	/** @param list<array<string,mixed>> $matches */
	public function __construct(private readonly string $kind,private readonly string $subjectDigest,private readonly bool $complete,private readonly bool $stale,private readonly array $matches,private readonly string $evaluatedAt){
		PanelOperationsGuard::name($kind,'revocation decision kind');if(preg_match('/^[a-f0-9]{64}$/D',$subjectDigest)!==1){throw new \InvalidArgumentException('Revocation decision subject digest is invalid.');}foreach($matches as$match){if(!is_array($match)){throw new \InvalidArgumentException('Revocation decision matches are invalid.');}PanelOperationsGuard::safeMetadata($match,32);}if(PanelOperationsGuard::instant($evaluatedAt)!==$evaluatedAt){throw new \InvalidArgumentException('Revocation decision time must be canonical UTC.');}
	}
	public function revoked():bool{return$this->matches!==[];}public function complete():bool{return$this->complete;}public function stale():bool{return$this->stale;}public function allowed():bool{return$this->complete&&!$this->stale&&!$this->revoked();}/** @return list<array<string,mixed>> */public function matches():array{return$this->matches;}
	public function assertAllowed():self{if(!$this->allowed()){throw new \LogicException($this->revoked()?'Package operation is blocked by a verified revocation.':'Package revocation state is incomplete or stale.');}return$this;}
	/** @return array<string,mixed> */public function jsonSerialize():array{return PanelManifestContract::stamp(['type'=>'panel_package_revocation_decision','version'=>1,'kind'=>$this->kind,'subject_digest'=>$this->subjectDigest,'complete'=>$this->complete,'stale'=>$this->stale,'revoked'=>$this->revoked(),'allowed'=>$this->allowed(),'matches'=>$this->matches,'evaluated_at'=>$this->evaluatedAt]);}
}
