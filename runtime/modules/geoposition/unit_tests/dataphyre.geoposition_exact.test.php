<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/geoposition_test_helpers.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Geoposition deterministic lookup behavior')
	->contract('geoposition.deterministic-lookups', 1)
	->layer('integration')
	->risk('high')
	->watches('module:geoposition')
	->through('normalization', 'postal-rules', 'prefix-resolution', 'subdivisions', 'distance', 'schema')
	->isolation('case')
	->tag('geoposition', 'exact-coverage')
	->group('framework-coverage');

test('normalization helpers expose configuration rules regex pairs and coordinate validity', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\geoposition::class);
	$t->same(DP_GEOPOSITION_CFG, $internals->invoke('config'));
	$t->same('dataphyre.postal_codes_regex', $internals->invoke('postal_codes_regex_table'));
	$t->same('dataphyre.postal_codes', $internals->invoke('postal_codes_table'));
	$t->endsWith('/geoposition/datasets/subdivision_positions.json', $internals->invoke('subdivision_positions_path'));
	$t->endsWith('/geoposition/datasets/geography_catalog.json', $internals->invoke('geography_catalog_path'));
	$t->same(['fr-ca', 'fr', 'en'], $internals->invoke('locale_candidates', ' fr_CA '));
	$t->same(['en'], $internals->invoke('locale_candidates', ''));
	$t->same('Québec', $internals->invoke('localized_catalog_name', ['names'=>['en'=>'Quebec', 'fr'=>'Québec']], 'fr-CA', 'QC'));
	$t->same('QC', $internals->invoke('localized_catalog_name', ['names'=>[]], 'es', 'QC'));
	$t->same('H2X 1Y4', $internals->invoke('normalize_postal_code', ' H2X 1Y4 '));
	$t->same([], $internals->invoke('postal_code_rule_map', null));
	$t->same([], $internals->invoke('postal_code_rule_map', '   '));
	$t->same(null, $internals->invoke('regex_replace_pair', null));
	$t->same(null, $internals->invoke('regex_replace_pair', 'missing separator'));
	$t->same(['/\\s+/', ''], $internals->invoke('regex_replace_pair', '/\\s+/␞'));
	$t->same(['/x/', '-'], $internals->invoke('regex_replace_pair', '/x/âž-'));
	$t->isFalse($internals->invoke('normalize_point', null));
	$t->isFalse($internals->invoke('normalize_point', ['latitude'=>'unknown', 'longitude'=>1]));
	$t->same([
		'latitude'=>45.5, 'longitude'=>-73.6, 'lat'=>45.5, 'long'=>-73.6,
	], $internals->invoke('normalize_point', ['latitude'=>'45.5', 'longitude'=>'-73.6']));
	$t->isFalse($internals->invoke('point_components', ['lat'=>'invalid']));
	$t->same([
		'latitude'=>45.5, 'longitude'=>-73.6,
	], $internals->invoke('point_components', ['lat'=>45.5, 'long'=>-73.6]));
});

test('subdivision dataset loading caches missing malformed injected and real ISO records', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\geoposition::class);
	$exists=$t->spy()->willReturn(false);
	$reader=$t->spy()->willReturn('{}');
	$internals->writeProperty('subdivision_positions_cache', null);
	$t->same([], $internals->invoke('subdivision_positions', '/missing.json', $exists, $reader));
	$t->same([], $internals->invoke('subdivision_positions', '/missing.json', $exists, $reader));
	$exists->assertCalledTimes($t, 1);
	$reader->assertCalledTimes($t, 0);

	$internals->writeProperty('subdivision_positions_cache', null);
	$t->same([], $internals->invoke(
		'subdivision_positions',
		'/malformed.json',
		static fn(): bool=>true,
		static fn(): string=>'not-json'
	));

	$internals->writeProperty('subdivision_positions_cache', null);
	$injected=$internals->invoke(
		'subdivision_positions',
		'/fixture.json',
		static fn(): bool=>true,
		static fn(): string=>'{"CA":{"CA-QC":{"latitude":45.5,"longitude":-73.6}}}'
	);
	$t->same(45.5, $injected['CA']['CA-QC']['latitude']);

	$internals->writeProperty('subdivision_positions_cache', null);
	$quebec=\dataphyre\geoposition::get_position_for_subdivision(' ca ', 'qc');
	$t->type('array', $quebec);
	$t->same('CA-QC', $quebec['subdivision']);
	$t->greaterThan(40, $quebec['latitude']);
	$t->isFalse(\dataphyre\geoposition::get_position_for_subdivision('CA', 'missing'));
	$t->isFalse(\dataphyre\geoposition::get_position_for_subdivision('BB', 'BB'));
});

test('geography catalogs localize countries and dependent subdivisions without SQL', static function(Context $t): void {
	DpGeopositionWorkerScenario::begin();
	$internals=$t->nonPublic(\dataphyre\geoposition::class);
	$exists=$t->spy()->willReturn(true);
	$reader=$t->spy()->willReturn('{"countries":{"CA":{"alpha_3":"CAN","numeric":"124","names":{"en":"Canada","fr":"Canada"}}},"subdivisions":{"CA":[{"code":"QC","full_code":"CA-QC","type":"Province","names":{"en":"Quebec","fr":"Québec"}}]}}');
	$internals->writeProperty('geography_catalog_cache', null);
	$fixture=$internals->invoke('geography_catalog_data', '/fixture.json', $exists, $reader);
	$t->same('CAN', $fixture['countries']['CA']['alpha_3']);
	$t->same('Québec', \dataphyre\geoposition::subdivision_catalog(' ca ', 'fr-CA')[0]['name']);
	$t->same('Canada', \dataphyre\geoposition::country_catalog('fr-CA')[0]['name']);
	$t->same([], \dataphyre\geoposition::subdivision_catalog('US', 'en'));
	$exists->assertCalledTimes($t, 1);
	$reader->assertCalledTimes($t, 1);

	$internals->writeProperty('geography_catalog_cache', null);
	$countries=\dataphyre\geoposition::country_catalog('fr-CA');
	$canada=array_values(array_filter($countries, static fn(array $country): bool=>$country['code']==='CA'))[0] ?? null;
	$t->same('Canada', $canada['name'] ?? null);
	$quebec=array_values(array_filter(
		\dataphyre\geoposition::subdivision_catalog('CA', 'fr-CA'),
		static fn(array $subdivision): bool=>$subdivision['code']==='QC'
	))[0] ?? null;
	$t->same('Québec', $quebec['name'] ?? null);
	$t->same('CA-QC', $quebec['full_code'] ?? null);
	$t->same(true, in_array('America/Toronto', \dataphyre\geoposition::timezone_catalog('CA'), true));
	$t->same(true, in_array('America/Toronto', \dataphyre\geoposition::timezone_catalog(), true));
	$t->same([], \dataphyre\geoposition::timezone_catalog('not-a-country'));
	$t->same([], DpGeopositionWorkerScenario::lookups());
});

test('postal formatting applies regex case digit and letter rules without leaking SQL mechanics', static function(Context $t): void {
	DpGeopositionWorkerScenario::begin();
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', false);
	$t->same('h2x 1y4', \dataphyre\geoposition::reformat_postal_code(' ca ', '', ' h2x 1y4 '));

	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', [
		'reformatting_regex'=>'/\\s+/␞',
		'reformatting_rules'=>'force_uppercase',
	]);
	$t->same('H2X1Y4', \dataphyre\geoposition::reformat_postal_code('ca', 'qc', 'h2x 1y4'));

	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', ['reformatting_rules'=>'force_lowercase']);
	$t->same('ab-12', \dataphyre\geoposition::reformat_postal_code('GB', '*', 'AB-12'));
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', ['reformatting_rules'=>'digits_only']);
	$t->same('123', \dataphyre\geoposition::reformat_postal_code('US', '*', 'A1-B2 C3'));
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', ['reformatting_rules'=>'letters_only']);
	$t->same('ABC', \dataphyre\geoposition::reformat_postal_code('US', '*', 'A1-B2 C3'));
	\dataphyre_dpanel_worker_fixture_state::returnFromSql('select', ['reformatting_regex'=>'no separator']);
	$t->same('unchanged', \dataphyre\geoposition::reformat_postal_code('US', '*', 'unchanged'));
});

test('postal validation reformats first then distinguishes permissive matching and rejected rules', static function(Context $t): void {
	DpGeopositionWorkerScenario::begin();
	DpGeopositionWorkerScenario::postalLookups([false, false]);
	$t->isTrue(\dataphyre\geoposition::validate_postal_code('CA', 'QC', ' H2X 1Y4 '));

	DpGeopositionWorkerScenario::postalLookups([
		['reformatting_regex'=>'/\\s+/␞', 'reformatting_rules'=>'force_uppercase'],
		['validation_regex'=>'/^[A-Z][0-9][A-Z][0-9][A-Z][0-9]$/'],
	]);
	$t->isTrue(\dataphyre\geoposition::validate_postal_code('CA', 'QC', 'h2x 1y4'));

	DpGeopositionWorkerScenario::postalLookups([
		['reformatting_rules'=>'force_uppercase'],
		['validation_regex'=>'/^Z/'],
	]);
	$t->isFalse(\dataphyre\geoposition::validate_postal_code('CA', 'QC', 'h2x'));

	DpGeopositionWorkerScenario::postalLookups([false, ['validation_regex'=>'']]);
	$t->isTrue(\dataphyre\geoposition::validate_postal_code('CA', 'QC', 'anything'));
});

test('postal geocoding progressively truncates alphanumeric and numeric prefixes', static function(Context $t): void {
	DpGeopositionWorkerScenario::begin();
	DpGeopositionWorkerScenario::postalLookups([
		false,
		false,
		['latitude'=>'45.5017', 'longitude'=>'-73.5673', 'subdivision'=>'QC'],
	]);
	$position=\dataphyre\geoposition::get_position_for_postal_code('ca', 'H2X1Y4');
	$t->same(['H2X1Y4', 'H2X1Y', 'H2X1'], DpGeopositionWorkerScenario::lookedUpPostalPrefixes());
	$t->same('QC', $position['subdivision']);

	DpGeopositionWorkerScenario::begin();
	DpGeopositionWorkerScenario::postalLookups([
		false,
		false,
		['latitude'=>40.0, 'longitude'=>-75.0, 'subdivision'=>'PA'],
	]);
	$t->type('array', \dataphyre\geoposition::get_position_for_postal_code('US', '12345'));
	$t->same(['12345', '1234', '123'], DpGeopositionWorkerScenario::lookedUpPostalPrefixes());

	DpGeopositionWorkerScenario::begin();
	$t->isFalse(\dataphyre\geoposition::get_position_for_postal_code('US', '1'));
	$t->same([], DpGeopositionWorkerScenario::lookups());
	DpGeopositionWorkerScenario::postalLookups([['latitude'=>null, 'longitude'=>null]]);
	$t->isFalse(\dataphyre\geoposition::get_position_for_postal_code('US', '12'));
});

test('all three location distance APIs share fast precise and unresolved behavior', static function(Context $t): void {
	$internals=$t->nonPublic(\dataphyre\geoposition::class);
	$internals->writeProperty('subdivision_positions_cache', [
		'CA'=>[
			'CA-QC'=>['latitude'=>45.5017, 'longitude'=>-73.5673],
			'CA-ON'=>['latitude'=>43.6532, 'longitude'=>-79.3832],
		],
	]);
	$t->greaterThan(500, \dataphyre\geoposition::distance_between_subdivisions('CA', 'QC', 'CA', 'ON'));
	$t->greaterThan(500, \dataphyre\geoposition::distance_between_subdivisions('CA', 'QC', 'CA', 'ON', true));
	$t->isFalse(\dataphyre\geoposition::distance_between_subdivisions('CA', 'QC', 'CA', 'missing'));

	DpGeopositionWorkerScenario::begin();
	DpGeopositionWorkerScenario::postalLookups([
		['latitude'=>45.5017, 'longitude'=>-73.5673],
		['latitude'=>43.6532, 'longitude'=>-79.3832],
	]);
	$t->greaterThan(500, \dataphyre\geoposition::distance_between_postal_codes('CA', 'H2X', 'CA', 'M5V'));
	DpGeopositionWorkerScenario::postalLookups([false, false, false]);
	$t->isFalse(\dataphyre\geoposition::distance_between_postal_codes('CA', 'H2X', 'CA', 'M5V', true));

	$montreal=['lat'=>45.5017, 'long'=>-73.5673];
	$toronto=['latitude'=>43.6532, 'longitude'=>-79.3832];
	$t->greaterThan(500, \dataphyre\geoposition::distance_between_points($montreal, $toronto));
	$t->greaterThan(500, \dataphyre\geoposition::distance_between_points($montreal, $toronto, true));
	$t->isFalse(\dataphyre\geoposition::distance_between_points($montreal, ['latitude'=>'missing']));
});

test('distance formulas define coincident equatorial polar and nonconvergent edge values', static function(Context $t): void {
	$t->same(0.0, \dataphyre\geoposition::vincenty_great_circle_distance(45.5, -73.6, 45.5, -73.6));
	$t->greaterThan(100, \dataphyre\geoposition::vincenty_great_circle_distance(0, 0, 0, 1));
	$t->same(0.0, \dataphyre\geoposition::vincenty_great_circle_distance(90, 0, 90, 180));
	$t->same(0.0, \dataphyre\geoposition::vincenty_great_circle_distance(0, 0, 0, 180));
	$t->same(0.0, \dataphyre\geoposition::vincenty_great_circle_distance(0, 0, 0.5, 179.7));
	$t->greaterThan(0, \dataphyre\geoposition::haversine_great_circle_distance(0, 0, 0, 1, 1_000));
});

test('postal lookup table manifests preserve country prefix and subdivision uniqueness', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/geoposition.tables.php';
	$t->sameKeys(['postal_codes_regex', 'postal_codes'], $manifest);
	foreach($manifest as $name=>$factory){
		$definition=$factory('fixture.'.$name);
		$t->instanceOf(TableDefinition::class, $definition);
		$t->same(['id'], $definition->primaryColumns());
	}
});
