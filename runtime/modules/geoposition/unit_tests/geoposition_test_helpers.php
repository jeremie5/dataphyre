<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace {
	if(!function_exists('tracelog')){
		function tracelog(...$args): void {}
	}
	if(!function_exists('dp_module_required')){
		function dp_module_required(string $module, string $dependency): void {}
	}
	if(!function_exists('dp_define_module_config')){
		function dp_define_module_config(string $module, string $constant, array $config): void {
			if(!defined($constant)){
				define($constant, $config);
			}
		}
	}
}

namespace DataphyreUnitTests {

if(!function_exists(__NAMESPACE__.'\\dp_define_module_config')){
	function dp_define_module_config(string $module, string $constant, array $config): void {
		if(!defined($constant)){
			define($constant, $config);
		}
	}
}

require_once __DIR__.'/../kernel/geoposition.main.php';

function geoposition_distance_between_points_rounded(array $first, array $second, bool $better_precision=false): float|bool {
	$distance=\dataphyre\geoposition::distance_between_points($first, $second, $better_precision);
	return is_float($distance) ? round($distance, 3) : $distance;
}

function geoposition_haversine_rounded(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float {
	return round(\dataphyre\geoposition::haversine_great_circle_distance($latitude1, $longitude1, $latitude2, $longitude2), 3);
}

function geoposition_vincenty_rounded(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float {
	return round(\dataphyre\geoposition::vincenty_great_circle_distance($latitude1, $longitude1, $latitude2, $longitude2), 3);
}

function geoposition_internal_normalization_json(): string {
	$reflection=new \ReflectionClass(\dataphyre\geoposition::class);
	$normalize_country=$reflection->getMethod('normalize_country_code');
	$normalize_country->setAccessible(true);
	$normalize_subdivision=$reflection->getMethod('normalize_subdivision_code');
	$normalize_subdivision->setAccessible(true);
	$rule_map=$reflection->getMethod('postal_code_rule_map');
	$rule_map->setAccessible(true);
	$point=$reflection->getMethod('normalize_point');
	$point->setAccessible(true);
	$rules=$rule_map->invoke(null, ' force_uppercase, digits_only, ,letters_only ');
	ksort($rules);
	return json_encode([
		'country'=>$normalize_country->invoke(null, ' ca '),
		'default_subdivision'=>$normalize_subdivision->invoke(null, ''),
		'point'=>$point->invoke(null, ['lat'=>'45.5', 'long'=>'-73.6', 'subdivision'=>'QC']),
		'rules'=>$rules,
		'subdivision'=>$normalize_subdivision->invoke(null, ' qc '),
	], JSON_UNESCAPED_SLASHES);
}
}
