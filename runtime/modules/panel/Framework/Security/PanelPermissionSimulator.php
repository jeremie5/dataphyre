<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Panel;

/** Evaluates policy matrices without mutating sessions or executing actions. */
final class PanelPermissionSimulator {
	/** @param array<string,PanelSecurityPolicy> $policies */
	public static function simulate(array $policies, array $contexts, mixed $subject=null): array {
		$matrix=[]; $allowed=0; $denied=0;
		foreach($contexts as $name=>$context){
			if(is_array($context)){ $context=PanelSecurityContext::fromArray($context); }
			if(!$context instanceof PanelSecurityContext){ continue; }
			$row=[];
			foreach($policies as $ability=>$policy){
				if(!$policy instanceof PanelSecurityPolicy){ continue; }
				$decision=$policy->evaluate($context, $subject);
				$row[(string)$ability]=$decision->jsonSerialize();
				$decision->allowed() ? $allowed++ : $denied++;
			}
			$matrix[(string)$name]=['context'=>$context->jsonSerialize(), 'decisions'=>$row];
		}
		return ['type'=>'permission_simulation', 'allowed'=>$allowed, 'denied'=>$denied, 'matrix'=>$matrix];
	}

	/** Reports cross-tenant records and missing tenant metadata. */
	public static function auditTenantIsolation(array $records, PanelSecurityContext $context, string $tenantField='tenant_id'): array {
		$issues=[];
		foreach($records as $index=>$record){
			if(!is_array($record)){ continue; }
			if(!array_key_exists($tenantField, $record)){ $issues[]=['index'=>$index, 'type'=>'missing_tenant', 'message'=>'Record has no tenant boundary field.']; continue; }
			if($context->tenantId()===null || (string)$record[$tenantField]!==$context->tenantId()){ $issues[]=['index'=>$index, 'type'=>'cross_tenant', 'actual'=>(string)$record[$tenantField], 'expected'=>$context->tenantId()]; }
		}
		return ['passed'=>$issues===[], 'tenant_id'=>$context->tenantId(), 'tenant_field'=>$tenantField, 'records'=>count($records), 'issues'=>$issues];
	}
}
