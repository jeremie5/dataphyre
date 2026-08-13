<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Immutable actor-bound, single-tenant IAM read authorization envelope. */
final class PanelIamQuery implements \JsonSerializable {
	/** @param array<string,mixed> $criteria */
	private function __construct(private readonly string $ability,private readonly string $tenantId,private readonly string $actorId,private readonly ?string $subjectType,private readonly ?string $subjectId,private readonly array $criteria){}
	/** @param array<string,mixed> $criteria */
	public static function make(string $ability,string|int $tenantId,string|int $actorId,?string $subjectType=null,string|int|null $subjectId=null,array $criteria=[]):self {
		$ability=PanelIamGuard::operation($ability);if(!str_starts_with($ability,'iam.')){throw new \InvalidArgumentException('Panel IAM query ability must use the iam namespace.');}
		if(($subjectType===null)!==($subjectId===null)){throw new \InvalidArgumentException('Panel IAM query subjects require both type and id.');}
		return new self($ability,PanelIamGuard::identifier($tenantId,'tenant id'),PanelIamGuard::identifier($actorId,'actor id'),$subjectType!==null?PanelIamGuard::subjectType($subjectType):null,$subjectId!==null?PanelIamGuard::identifier($subjectId,'subject id'):null,PanelIamGuard::metadata($criteria));
	}
	public function ability():string{return$this->ability;}
	public function tenantId():string{return$this->tenantId;}
	public function actorId():string{return$this->actorId;}
	public function subjectType():?string{return$this->subjectType;}
	public function subjectId():?string{return$this->subjectId;}
	/** @return array<string,mixed> */ public function criteria():array{return$this->criteria;}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return['type'=>'panel_iam_query','ability'=>$this->ability,'tenant_id'=>$this->tenantId,'actor_id'=>$this->actorId,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'criteria'=>$this->criteria];}
}
