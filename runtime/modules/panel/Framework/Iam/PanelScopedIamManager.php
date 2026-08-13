<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Request-facing IAM read facade permanently bound to one actor and one tenant. */
final class PanelScopedIamManager {
	private readonly string $tenantId;
	private readonly string $actorId;
	public function __construct(private readonly PanelIamManager $manager,string|int $tenantId,string|int $actorId){$this->tenantId=PanelIamGuard::identifier($tenantId,'tenant id');$this->actorId=PanelIamGuard::identifier($actorId,'actor id');}
	public function tenantId():string{return$this->tenantId;}
	public function actorId():string{return$this->actorId;}
	public function principal(string|int $id):?PanelIamPrincipal {$id=PanelIamGuard::identifier($id,'principal id');$this->manager->authorizeQuery(PanelIamQuery::make('iam.principal.read',$this->tenantId,$this->actorId,'principal',$id));return$this->manager->principal($this->tenantId,$id);}
	public function serviceAccount(string|int $id):?PanelIamServiceAccount {$id=PanelIamGuard::identifier($id,'service account id');$this->manager->authorizeQuery(PanelIamQuery::make('iam.service.read',$this->tenantId,$this->actorId,'service',$id));return$this->manager->serviceAccount($this->tenantId,$id);}
	public function membership(string $subjectType,string|int $subjectId):?PanelIamMembership {$type=PanelIamGuard::subjectType($subjectType);$id=PanelIamGuard::identifier($subjectId,'subject id');$this->manager->authorizeQuery(PanelIamQuery::make('iam.membership.read',$this->tenantId,$this->actorId,$type,$id));return$this->manager->membership($this->tenantId,$type,$id);}
	/** @return list<PanelIamPrincipal> */ public function principals(int $limit=100):array {$this->manager->authorizeQuery(PanelIamQuery::make('iam.principal.list',$this->tenantId,$this->actorId,criteria:['limit'=>max(1,min(1000,$limit))]));return$this->manager->principals($this->tenantId,$limit);}
	/** @return list<PanelIamServiceAccount> */ public function serviceAccounts(int $limit=100):array {$this->manager->authorizeQuery(PanelIamQuery::make('iam.service.list',$this->tenantId,$this->actorId,criteria:['limit'=>max(1,min(1000,$limit))]));return$this->manager->serviceAccounts($this->tenantId,$limit);}
	/** @param array<string,mixed> $criteria @return list<PanelIamMembership> */ public function memberships(array $criteria=[],int $limit=100):array {$criteria=PanelIamGuard::metadata($criteria);$this->manager->authorizeQuery(PanelIamQuery::make('iam.membership.list',$this->tenantId,$this->actorId,criteria:array_replace($criteria,['limit'=>max(1,min(1000,$limit))])));return$this->manager->memberships($this->tenantId,$criteria,$limit);}
	/** @return list<PanelIamAuditEvent> */ public function audit(int $afterSequence=0,int $limit=100):array {$criteria=['after_sequence'=>max(0,$afterSequence),'limit'=>max(1,min(1000,$limit))];$this->manager->authorizeQuery(PanelIamQuery::make('iam.audit.read',$this->tenantId,$this->actorId,criteria:$criteria));return$this->manager->audit($this->tenantId,$afterSequence,$limit);}
}
