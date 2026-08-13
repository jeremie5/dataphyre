<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Secret-free runtime capability manifest for one IAM manager instance. */
final class PanelIamManifest implements \JsonSerializable {
	/** @param array<string,mixed> $payload */ private function __construct(private readonly array $payload){}
	public static function inspect(PanelIamManager $manager):self {
		$store=PanelSensitiveDataSanitizer::sanitize($manager->store()->manifest(),['max_depth'=>8,'max_items'=>100,'max_string_bytes'=>500]);
		return new self(['type'=>'panel_iam_manifest','version'=>1,'store'=>is_array($store)?$store:[],'authorization'=>['configured'=>$manager->authorizationConfigured(),'fail_closed'=>true,'replay_reauthorized'=>true],'approval'=>['high_risk_required'=>$manager->requiresHighRiskApproval(),'high_risk_patterns'=>$manager->highRiskPermissions()],'audit_keys'=>['current_key_id'=>$manager->currentAuditKeyId(),'accepted_key_ids'=>$manager->auditKeyIds(),'accepted_key_count'=>count($manager->auditKeyIds()),'rotation_supported'=>true,'maximum_keys'=>8],'retention'=>['audit_events'=>$manager->auditRetention(),'idempotency_receipts'=>$manager->receiptRetention()],'capabilities'=>['actor_bound_scopes'=>true,'tenant_scoped_queries'=>true,'immutable_subjects'=>true,'service_accounts'=>true,'versioned_memberships'=>true,'optimistic_concurrency'=>true,'idempotent_mutations'=>true,'separate_requester_approver'=>true,'hmac_audit_chain'=>true,'audit_key_rotation'=>true,'atomic_store'=>true],'security'=>['raw_credentials_persisted'=>false,'raw_credentials_serialized'=>false,'raw_idempotency_keys_persisted'=>false,'audit_key_serialized'=>false,'request_facing_cross_tenant_query'=>false,'trusted_internal_unscoped_reads'=>true]]);
	}
	/** @return array<string,mixed> */ public function jsonSerialize():array{return PanelManifestContract::stamp($this->payload);}
}
