<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2025 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace DataphyreUnitTests;

require_once __DIR__.'/../Framework/AssetPolicy.php';
require_once __DIR__.'/../Framework/AssetManifest.php';
require_once __DIR__.'/../Framework/BindingContext.php';
require_once __DIR__.'/../Framework/BindingResolution.php';
require_once __DIR__.'/../Framework/TemplateContract.php';

function templating_asset_policy_summary_json(array $definition): string {
	return json_encode(\Dataphyre\Templating\AssetPolicy::fromArray($definition)->summary(), JSON_UNESCAPED_SLASHES);
}

function templating_asset_policy_chain_summary_json(array $definition): string {
	$policy=\Dataphyre\Templating\AssetPolicy::fromArray($definition)
		->withoutPreload('css', 'img')
		->preload('font')
		->scriptDefer()
		->moduleScripts()
		->styleMedia('print')
		->fontCrossorigin('none');
	return json_encode($policy->summary(), JSON_UNESCAPED_SLASHES);
}

function templating_template_contract_json(array $definition): string {
	return json_encode(\Dataphyre\Templating\TemplateContract::fromArray($definition)->toArray(), JSON_UNESCAPED_SLASHES);
}

function templating_template_contract_chain_json(): string {
	$contract=\Dataphyre\Templating\TemplateContract::define(['title'], ['subtitle'])
		->requiredProp('count', 'Integer', 0)
		->optionalProp('cta', ' string ', 'Buy')
		->requiredSlots('main', 'main')
		->optionalSlots('aside')
		->allowAdditionalData(false);
	return json_encode($contract->toArray(), JSON_UNESCAPED_SLASHES);
}

function templating_binding_resolution_marker(bool $skip, mixed $value): string {
	$resolution=$skip
		? \Dataphyre\Templating\BindingResolution::skipped($value)
		: \Dataphyre\Templating\BindingResolution::value($value);
	return ($resolution->isSkipped() ? 'skipped' : 'value').':'.json_encode($resolution->result(), JSON_UNESCAPED_SLASHES);
}

function templating_binding_context_json(): string {
	$context=new \Dataphyre\Templating\BindingContext(
		'card',
		false,
		['product'=>['title'=>'Boots', 'price'=>129]],
		['colors'=>['accent'=>'red']],
		['body'=>'Hello'],
		['product'=>['price'=>99]],
		['render_trace_id'=>'render-1']
	);
	return json_encode([
		'binding_trace_id'=>$context->bindingTraceId(),
		'has_title'=>$context->has('product.title'),
		'missing'=>$context->get('missing.value', 'fallback'),
		'override_price'=>$context->get('product.price'),
		'slot'=>$context->slot('body'),
		'theme'=>$context->themeValue('colors.accent'),
	], JSON_UNESCAPED_SLASHES);
}

function templating_asset_manifest_summary_json(array $payload): string {
	$manifest=\Dataphyre\Templating\AssetManifest::fromArray($payload);
	return json_encode([
		'body_html'=>$manifest->bodyHtml(),
		'has_missing'=>$manifest->hasMissingAssets(),
		'head_html'=>$manifest->headHtml(),
		'signature_length'=>strlen($manifest->signature()),
		'summary'=>$manifest->summary(),
	], JSON_UNESCAPED_SLASHES);
}
