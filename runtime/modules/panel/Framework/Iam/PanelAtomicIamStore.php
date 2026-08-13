<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Cross-process, crash-safe IAM adapter backed by immutable atomic JSON snapshots. */
final class PanelAtomicIamStore implements PanelIamStore {
	private PanelAtomicSnapshotStore $store;
	public function __construct(string $directory,int $snapshotRetention=512){$this->store=new PanelAtomicSnapshotStore($directory,'dataphyre.panel.iam.v1',['schema_version'=>1,'tenants'=>[]],$snapshotRetention);}

	public function read(string|int $tenantId):array {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$payload=$this->root($this->store->payload());$state=$payload['tenants'][$tenant]??PanelIamState::initial();if(!is_array($state)){throw new \UnexpectedValueException('Panel IAM tenant state is invalid.');}PanelIamState::assertValid($state,$tenant);return$state;
	}

	public function transaction(string|int $tenantId,callable $mutation,string $type,array $event=[]):mixed {
		$tenant=PanelIamGuard::identifier($tenantId,'tenant id');$type=PanelIamGuard::operation($type);$event=PanelIamGuard::metadata($event);
		$committed=$this->store->transaction(function(array &$payload)use($tenant,$mutation):mixed{$payload=$this->root($payload);$before=$payload['tenants'][$tenant]??PanelIamState::initial();if(!is_array($before)){throw new \UnexpectedValueException('Panel IAM tenant state is invalid.');}$after=$before;$result=$mutation($after);PanelIamState::assertTransition($before,$after,$tenant);$payload['tenants'][$tenant]=$after;return$result;},$type,['tenant_hash'=>hash('sha256',$tenant),'metadata'=>$event]);
		return$committed['result'];
	}

	public function cursor():int{return$this->store->cursor();}
	/** @return array<string,mixed> */ public function manifest():array{return['type'=>'panel_iam_store','schema_version'=>1,'adapter'=>'atomic_json','cursor'=>$this->cursor(),'capabilities'=>['atomic_commits'=>'cross_process','cross_process_locking'=>true,'crash_recovery'=>true,'tenant_scoped'=>true,'optimistic_concurrency'=>true,'idempotent_receipts'=>true,'audit_hash_chain'=>true],'secret_values_serialized'=>false,'filesystem_path_serialized'=>false];}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return$this->manifest();}

	/** @param array<string,mixed> $payload @return array{schema_version:int,tenants:array<string,mixed>} */
	private function root(array $payload):array {
		if(array_keys($payload)!==['schema_version','tenants']||($payload['schema_version']??null)!==1||!is_array($payload['tenants'])||($payload['tenants']!==[]&&array_is_list($payload['tenants']))){throw new \UnexpectedValueException('Panel IAM store root schema is invalid.');}
		return$payload;
	}
}
