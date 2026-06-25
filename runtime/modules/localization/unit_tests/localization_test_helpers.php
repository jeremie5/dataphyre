<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../Framework/LocaleDefinition.php';
require_once __DIR__.'/../Framework/LocaleDefinitionCatalog.php';
require_once __DIR__.'/../Framework/LocaleDefinitionBatchResult.php';
require_once __DIR__.'/../Framework/LocalizationRebuildSelection.php';
require_once __DIR__.'/../Framework/LocalizationMaintenanceResult.php';
require_once __DIR__.'/../Framework/UnknownLocaleEntry.php';
require_once __DIR__.'/../Framework/UnknownLocaleCatalog.php';

function localization_definition_json(array $data): string {
	return json_encode(\Dataphyre\Localization\LocaleDefinition::fromArray($data), JSON_UNESCAPED_SLASHES);
}

function localization_definition_source_json(array $data): string {
	$definition=\Dataphyre\Localization\LocaleDefinition::fromArray($data);
	return json_encode([
		'source_branch'=>$definition->sourceBranch(),
		'source_commit'=>$definition->sourceCommit(),
		'serialized'=>$definition,
	], JSON_UNESCAPED_SLASHES);
}

function localization_definition_catalog_json(array $entries, array $filters, int $limit, int $offset): string {
	return json_encode(\Dataphyre\Localization\LocaleDefinitionCatalog::fromArray($entries, $filters, $limit, $offset), JSON_UNESCAPED_SLASHES);
}

function localization_unknown_entry_json(string $name, array $data): string {
	return json_encode(\Dataphyre\Localization\UnknownLocaleEntry::fromArray($name, $data), JSON_UNESCAPED_SLASHES);
}

function localization_batch_result_json(string $operation, bool $ok, int $requested, int $processed, int $skipped, bool $rebuilt, int $targets): string {
	return json_encode(new \Dataphyre\Localization\LocaleDefinitionBatchResult($operation, $ok, $requested, $processed, $skipped, $rebuilt, $targets), JSON_UNESCAPED_SLASHES);
}

function localization_rebuild_selection_json(string $kind): string {
	$selection=match($kind){
		'global' => \Dataphyre\Localization\LocalizationRebuildSelection::global(['en', 'fr']),
		'theme' => \Dataphyre\Localization\LocalizationRebuildSelection::theme(['fr'], ['shop']),
		'local' => \Dataphyre\Localization\LocalizationRebuildSelection::local(['en'], ['admin'], ['/orders/index.php']),
		default => \Dataphyre\Localization\LocalizationRebuildSelection::all(),
	};
	return json_encode($selection, JSON_UNESCAPED_SLASHES);
}

function localization_maintenance_result_json(): string {
	$selection=\Dataphyre\Localization\LocalizationRebuildSelection::local(['en'], ['admin'], ['/orders/index.php']);
	return json_encode(new \Dataphyre\Localization\LocalizationMaintenanceResult('rebuild', 'ok', true, false, 3, true, $selection), JSON_UNESCAPED_SLASHES);
}

function localization_unknown_catalog_json(array $entries): string {
	$catalog=\Dataphyre\Localization\UnknownLocaleCatalog::fromArray($entries);
	return json_encode([
		'count'=>$catalog->count(),
		'first'=>$catalog->first(),
		'has_checkout'=>$catalog->has('Checkout.Title'),
		'lookup'=>$catalog->get('footer.copy'),
		'names'=>$catalog->names(),
	], JSON_UNESCAPED_SLASHES);
}

function localization_unknown_catalog_empty_json(): string {
	$catalog=\Dataphyre\Localization\UnknownLocaleCatalog::fromArray([
		'ignored'=>'not an array',
	]);
	return json_encode([
		'count'=>$catalog->count(),
		'first'=>$catalog->first(),
		'has_any'=>$catalog->has('anything'),
		'names'=>$catalog->names(),
	], JSON_UNESCAPED_SLASHES);
}
