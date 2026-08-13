<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Deterministic process-local IAM reference adapter with atomic copy-on-write commits. */
final class PanelMemoryIamStore implements PanelIamStore {
	/** @var array<string,array<string,mixed>> */ private array $tenants=[];
	private int $cursor=0;

	public function read(string|int $tenantId):array {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$state=$this->tenants[$tenant]??PanelIamState::initial();PanelIamState::assertValid($state,$tenant);return$state;
	}

	public function transaction(string|int $tenantId,callable $mutation,string $type,array $event=[]):mixed {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$type=PanelIamGuard::operation($type);$event=PanelIamGuard::metadata($event);$before=$this->read($tenant);$after=$before;
		$result=$mutation($after);PanelIamState::assertTransition($before,$after,$tenant);$this->tenants[$tenant]=$after;$this->cursor++;return$result;
	}

	public function cursor():int{return$this->cursor;}
	/** @return array<string,mixed> */ public function manifest():array{return['type'=>'panel_iam_store','schema_version'=>1,'adapter'=>'memory','cursor'=>$this->cursor,'tenant_count'=>count($this->tenants),'capabilities'=>['atomic_commits'=>'process_local','tenant_scoped'=>true,'optimistic_concurrency'=>true,'idempotent_receipts'=>true,'audit_hash_chain'=>true],'secret_values_serialized'=>false];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return$this->manifest();}
}
