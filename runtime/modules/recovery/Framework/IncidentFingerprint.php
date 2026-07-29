<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Recovery;

/** Stable, irreversible incident identity built only from approved dimensions. */
final class IncidentFingerprint {
	private const SCOPE_KEYS=[
		'environment','tenant_id','organization_id','brand_id','store_id','location_id',
		'device_id','channel_id','surface','scope_type',
	];

	public static function for(ProblemDefinition $definition, RecoveryContext $context, Evidence $evidence): string {
		$scope=[];
		foreach(self::SCOPE_KEYS as $key){
			$value=$context->scopeValue($key);
			if(is_scalar($value) && (string)$value!=='') $scope[$key]=$value;
		}
		ksort($scope, SORT_STRING);
		$fingerprintEvidence=Evidence::from($evidence->all(), $definition->fingerprintKeys())->all();
		$canonical=self::canonical([
			'version'=>1,
			'problem'=>$definition->id(),
			'scope'=>$scope,
			'evidence'=>$fingerprintEvidence,
		]);
		return 'rec1_'.substr(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)), 0, 40);
	}

	private static function canonical(mixed $value): mixed {
		if(!is_array($value)) return $value;
		foreach($value as $key=>$item) $value[$key]=self::canonical($item);
		if(!array_is_list($value)) ksort($value, SORT_STRING);
		return $value;
	}
}
