<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';

/**
 * Reads the legacy manifest expectation for a helper now exercised by TestKit.
 */
function dp_panel_legacy_expected(string $function): mixed {
	$manifest=json_decode((string)file_get_contents(__DIR__.'/dataphyre.panel.test_harness.json'), true);
	if(!is_array($manifest)){
		throw new RuntimeException('Panel legacy unit-test manifest is invalid.');
	}
	foreach($manifest as $case){
		if(is_array($case) && ($case['function'] ?? null)===$function){
			return $case['expected'] ?? null;
		}
	}
	throw new RuntimeException('Panel legacy expectation was not found for '.$function.'.');
}

test('panel harness covers result data notifications and accessibility', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_test_harness_result_assertions'), dp_panel_test_harness_result_assertions());
})->tag('panel', 'compatibility', 'harness')->maxMillis(1000);

test('panel responsive asset contract remains stable', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_responsive_asset_summary_json'), dp_panel_responsive_asset_summary_json());
})->tag('panel', 'visual', 'responsive')->maxMillis(1000);

test('panel permission bridge enforces resource page and relation abilities', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_permission_bridge_summary_json'), dp_panel_permission_bridge_summary_json());
})->tag('panel', 'permission', 'integration')->maxMillis(3000);

test('panel navigation and command search preserve visibility state', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_test_harness_navigation_summary'), dp_panel_test_harness_navigation_summary());
})->tag('panel', 'navigation', 'commands')->maxMillis(1000);

test('panel page results preserve download and JSON response contracts', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_page_result_download_summary_json'), dp_panel_page_result_download_summary_json());
})->tag('panel', 'http', 'download')->maxMillis(1000);

test('panel lazy commands preserve sorted visible state', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_test_harness_command_visibility_summary_json'), dp_panel_test_harness_command_visibility_summary_json());
})->tag('panel', 'commands', 'visibility')->maxMillis(1000);

test('panel regression suite records pass and skip detail', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_regression_suite_summary_json'), dp_panel_regression_suite_summary_json());
})->tag('panel', 'regression', 'harness')->maxMillis(1000);

test('panel localization resolves scopes and fallbacks', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_localization_catalogue_summary_json'), dp_panel_localization_catalogue_summary_json());
})->tag('panel', 'localization')->maxMillis(1000);

test('panel notification adapter tracks delivery and inbox state', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_notification_adapter_summary_json'), dp_panel_notification_adapter_summary_json());
})->tag('panel', 'notification')->maxMillis(1000);

test('panel routes round trip across routing and MVC adapters', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_route_compatibility_summary_json'), dp_panel_route_compatibility_summary_json());
})->tag('panel', 'routing', 'http')->maxMillis(2000);

test('panel host fragment requests return JSON payloads', static function(Context $t): void {
	$t->same(dp_panel_legacy_expected('dp_panel_host_http_fragment_summary_json'), dp_panel_host_http_fragment_summary_json());
})->tag('panel', 'host', 'http')->maxMillis(1000);
