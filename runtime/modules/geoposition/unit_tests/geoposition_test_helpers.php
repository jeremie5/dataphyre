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
	if(!function_exists('sql_define_table')){
		function sql_define_table(...$args): void {}
	}
	if(!function_exists('sql_select')){
		function sql_select(...$args): mixed {
			return \dataphyre_dpanel_worker_fixture_state::dispatchSql('select', $args, false);
		}
	}
	if(!class_exists(DpGeopositionWorkerScenario::class, false)){
		final class DpGeopositionWorkerScenario {
			public static function begin(): void {
				\dataphyre_dpanel_worker_fixture_state::resetSql();
			}

			/** @param list<mixed> $results */
			public static function postalLookups(array $results): void {
				$queue=$results;
				\dataphyre_dpanel_worker_fixture_state::respondToSql('select', static function() use (&$queue): mixed {
					return array_shift($queue) ?? false;
				});
			}

			/** @return list<array<int,mixed>> */
			public static function lookups(): array {
				return \dataphyre_dpanel_worker_fixture_state::sqlCalls('select');
			}

			/** @return list<string> */
			public static function lookedUpPostalPrefixes(): array {
				return array_values(array_filter(array_map(
					static fn(array $call): ?string=>isset($call[3][1]) ? (string)$call[3][1] : null,
					self::lookups()
				)));
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
	$rules=\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(
		\dataphyre\geoposition::class,
		'postal_code_rule_map',
		[' force_uppercase, digits_only, ,letters_only '],
	);
	ksort($rules);
	return json_encode([
		'country'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\geoposition::class,'normalize_country_code',[' ca ']),
		'default_subdivision'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\geoposition::class,'normalize_subdivision_code',['']),
		'point'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\geoposition::class,'normalize_point',[['lat'=>'45.5','long'=>'-73.6','subdivision'=>'QC']]),
		'rules'=>$rules,
		'subdivision'=>\dataphyre_dpanel_worker_fixture_state::invokeNonPublic(\dataphyre\geoposition::class,'normalize_subdivision_code',[' qc ']),
	], JSON_UNESCAPED_SLASHES);
}
}
