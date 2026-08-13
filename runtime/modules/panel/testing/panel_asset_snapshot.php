#!/usr/bin/env php
<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

if(!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)){
	fwrite(STDERR, "Panel asset snapshots must be generated from the command line.\n");
	exit(2);
}

$options=[];
$allowedOptions=['output-dir', 'asset', 'report', 'capabilities', 'mode'];
foreach(array_slice($_SERVER['argv'], 1) as $argument){
	if($argument==='--help' || $argument==='-h'){
		echo "Usage: php panel_asset_snapshot.php --output-dir=<directory> [--asset=panel.css,panel.js] [--capabilities=shell,table] [--mode=capability|physical|full] [--report=<file>]\n";
		exit(0);
	}
	if(!str_starts_with($argument, '--') || !str_contains($argument, '=')){
		fwrite(STDERR, "Unknown argument: {$argument}\n");
		exit(2);
	}
	[$name, $value]=explode('=', substr($argument, 2), 2);
	if(!in_array($name, $allowedOptions, true) || array_key_exists($name, $options)){
		fwrite(STDERR, "Unknown or duplicate option: --{$name}\n");
		exit(2);
	}
	$options[$name]=$value;
}

$outputDirectory=trim((string)($options['output-dir'] ?? ''));
if($outputDirectory===''){
	fwrite(STDERR, "--output-dir is required.\n");
	exit(2);
}

$mode=strtolower(trim((string)($options['mode'] ?? 'full')));
if(!in_array($mode, ['capability', 'physical', 'full'], true)){
	fwrite(STDERR, "--mode must be capability, physical, or full.\n");
	exit(2);
}
$assetOptionProvided=array_key_exists('asset', $options);
$assets=array_values(array_unique(array_filter(array_map(
	static fn(string $asset): string => trim($asset),
	explode(',', (string)($options['asset'] ?? ($mode==='physical' ? '' : 'panel.css,panel.js')))
))));
$allowed=['panel.css', 'panel.js', 'panel-head.js', 'panel-editor.js', 'panel-editor-assets.js', 'panel-extensions.js', 'panel-platform.css', 'panel-quality.js'];
foreach($assets as $asset){
	$physical=preg_match('/\Apanel-(?:style|runtime)-[a-z0-9][a-z0-9-]{0,63}\.(?:css|js)\z/', $asset)===1;
	if(!in_array($asset, $allowed, true) && !$physical){
		fwrite(STDERR, "Unsupported Panel asset: {$asset}\n");
		exit(2);
	}
}
if($assets===[] && $mode!=='physical'){
	fwrite(STDERR, "At least one --asset value is required.\n");
	exit(2);
}

$capabilities=array_values(array_filter(array_map(
	static fn(string $capability): string=>trim($capability),
	preg_split('/[\s,]+/', (string)($options['capabilities'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [],
)));

$root=dirname(__DIR__, 4);
if(!defined('DATAPHYRE_MODULE_POLICY')){
	define('DATAPHYRE_MODULE_POLICY', [
		'enabled'=>[
			'core'=>true,
			'http'=>true,
			'mvc'=>true,
			'panel'=>true,
			'permission'=>true,
			'routing'=>true,
		],
		'disabled'=>[],
		'core_implicit'=>true,
	]);
}

require_once $root.'/runtime/modules/core/kernel/autoloader.php';
\dataphyre\autoloader::register($root.'/runtime/modules');
\dataphyre\autoloader::register_framework_modules(['panel', 'permission']);

$capabilitySelection=$mode==='full' || ($mode==='physical' && $capabilities===[]) ? '*' : $capabilities;
$deliveryManifest=\Dataphyre\Panel\PanelRenderer::assetManifest($capabilitySelection, $mode);
if($mode==='physical'){
	$available=[];
	foreach(['styles', 'scripts'] as $collection){
		foreach((array)($deliveryManifest[$collection] ?? []) as $descriptor){
			if(!is_array($descriptor) || ($descriptor['external'] ?? false)===true){ continue; }
			$name=trim((string)($descriptor['name'] ?? ''));
			if($name!==''){ $available[$name]=true; }
		}
	}
	if(!$assetOptionProvided){
		$assets=array_keys($available);
	}
	foreach($assets as $asset){
		if(!isset($available[$asset])){
			fwrite(STDERR, "Asset is not part of the selected physical manifest: {$asset}\n");
			exit(2);
		}
	}
	if($assets===[]){
		fwrite(STDERR, "The selected physical manifest contains no first-party assets.\n");
		exit(2);
	}
}

if(!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)){
	fwrite(STDERR, "Unable to create asset snapshot directory: {$outputDirectory}\n");
	exit(2);
}
$resolvedOutput=realpath($outputDirectory);
if(!is_string($resolvedOutput)){
	fwrite(STDERR, "Unable to resolve asset snapshot directory: {$outputDirectory}\n");
	exit(2);
}

$manifest=[
	'type'=>'dataphyre_panel_asset_snapshot',
	'generated_at'=>gmdate(DATE_ATOM),
	'asset_mode'=>$mode,
	'asset_capabilities'=>\Dataphyre\Panel\PanelRenderer::assetCapabilityManifest($capabilitySelection, $mode)->toArray(),
	'delivery'=>[
		'strategy'=>(string)($deliveryManifest['delivery']['strategy'] ?? 'aggregate'),
		'physical'=>(bool)($deliveryManifest['delivery']['physical'] ?? false),
		'style_chunks'=>array_values((array)($deliveryManifest['delivery']['style_chunks'] ?? [])),
		'runtime_chunks'=>array_values((array)($deliveryManifest['delivery']['runtime_chunks'] ?? [])),
	],
	'assets'=>[],
];
foreach($assets as $asset){
	$physicalStyle=$mode==='physical' && str_starts_with($asset, 'panel-style-');
	$payload=\Dataphyre\Panel\PanelRenderer::assetContent(
		$asset,
		($mode==='capability' && in_array($asset, ['panel.css', 'panel.js'], true)) || $physicalStyle ? $capabilitySelection : null,
	);
	if($payload===null){
		fwrite(STDERR, "Panel renderer did not produce asset: {$asset}\n");
		exit(1);
	}
	$content=(string)$payload['content'];
	$path=$resolvedOutput.DIRECTORY_SEPARATOR.$asset;
	$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
	if(file_put_contents($temporary, $content, LOCK_EX)!==strlen($content)){
		@unlink($temporary);
		fwrite(STDERR, "Unable to write complete Panel asset snapshot: {$asset}\n");
		exit(1);
	}
	if(!@rename($temporary, $path)){
		@unlink($temporary);
		fwrite(STDERR, "Unable to publish Panel asset snapshot: {$asset}\n");
		exit(1);
	}
	$manifest['assets'][$asset]=[
		'path'=>$path,
		'content_type'=>(string)$payload['content_type'],
		'bytes'=>strlen($content),
		'sha256'=>hash('sha256', $content),
	];
}

$report=trim((string)($options['report'] ?? ''));
if($report!==''){
	$reportDirectory=dirname($report);
	if(!is_dir($reportDirectory) && !mkdir($reportDirectory, 0775, true) && !is_dir($reportDirectory)){
		fwrite(STDERR, "Unable to create asset snapshot report directory: {$reportDirectory}\n");
		exit(2);
	}
	$json=json_encode($manifest, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
	$temporary=$report.'.tmp-'.bin2hex(random_bytes(6));
	if(file_put_contents($temporary, $json, LOCK_EX)!==strlen($json) || !@rename($temporary, $report)){
		@unlink($temporary);
		fwrite(STDERR, "Unable to publish Panel asset snapshot report: {$report}\n");
		exit(1);
	}
}

echo json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
