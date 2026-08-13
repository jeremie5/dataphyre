<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

/**
 * Dataphyre Panel developer CLI.
 *
 * Commands:
 *   inspect --input manifest.json
 *   diff --before old.json --after new.json
 *   blueprint --table orders --schema columns.json [--namespace App\Panel] [--format php|json]
 *   quality --name orders --url /panel/orders [--axes axes.json]
 *   inclusive-quality --name orders --url /panel/orders [--configuration inclusive.json]
 *   inclusive-quality-validate --matrix matrix.json
 *   inclusive-quality-gate --matrix matrix.json --capabilities capabilities.json --evidence evidence.json [--budgets budgets.json]
 */

$root=dirname(__DIR__, 2);
$modules=$root.'/runtime/modules';
require_once $modules.'/core/kernel/autoloader.php';
\dataphyre\autoloader::register($modules);
\dataphyre\autoloader::register_prefixes([
	'Dataphyre\\Panel\\'=>$modules.'/panel/Framework',
	'Dataphyre\\'=>$modules.'/core/Framework',
]);

use Dataphyre\Panel\PanelDeveloperToolkit;
use Dataphyre\Panel\PanelInclusiveQualityMatrix;
use Dataphyre\Panel\PanelQualityCapabilityReport;

$arguments=$argv;
array_shift($arguments);
$command=strtolower(trim((string)array_shift($arguments)));
$options=[];
for($index=0;$index<count($arguments);$index++){
	$argument=(string)$arguments[$index];
	if(!str_starts_with($argument, '--')){ continue; }
	$key=ltrim($argument, '-');
	$value=true;
	if(str_contains($key, '=')){ [$key, $value]=explode('=', $key, 2); }
	elseif(isset($arguments[$index+1]) && !str_starts_with((string)$arguments[$index+1], '--')){ $value=$arguments[++$index]; }
	$key=str_replace('-', '_',$key);
	$options[$key]=$value;
}

$readJson=static function(mixed $path, string $label): array {
	$path=trim((string)$path);
	if($path==='' || !is_file($path)){ throw new InvalidArgumentException($label.' JSON file does not exist.'); }
	$bytes=filesize($path);
	if($bytes===false || $bytes>16*1024*1024){ throw new LengthException($label.' JSON exceeds its 16777216 byte budget.'); }
	$decoded=json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	if(!is_array($decoded)){ throw new InvalidArgumentException($label.' JSON must decode to an object or array.'); }
	return $decoded;
};
$emit=static function(mixed $payload, int $code=0) use ($options): never {
	$output=is_string($payload)?$payload:json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
	if(!str_ends_with($output,"\n")){ $output.="\n"; }
	if(isset($options['output'])){
		$path=trim((string)$options['output']);
		if($path===''){ throw new InvalidArgumentException('Output path cannot be empty.'); }
		$directory=dirname($path);
		if(!is_dir($directory) && !mkdir($directory,0777,true) && !is_dir($directory)){ throw new RuntimeException('Unable to create output directory.'); }
		$temporary=$path.'.tmp-'.getmypid();
		if(file_put_contents($temporary,$output,LOCK_EX)===false){ @unlink($temporary); throw new RuntimeException('Unable to write output artifact.'); }
		if(is_file($path) && !unlink($path)){ @unlink($temporary); throw new RuntimeException('Unable to replace output artifact.'); }
		if(!rename($temporary,$path)){ @unlink($temporary); throw new RuntimeException('Unable to commit output artifact.'); }
	}
	fwrite(STDOUT,$output);
	exit($code);
};

try{
	switch($command){
		case 'inspect':
			$report=PanelDeveloperToolkit::inspect($readJson($options['input'] ?? '', 'Input'))->jsonSerialize();
			$emit($report, ($report['passed'] ?? false) ? 0 : 1);
		case 'diff':
			$report=PanelDeveloperToolkit::diff($readJson($options['before'] ?? '', 'Before'), $readJson($options['after'] ?? '', 'After'))->jsonSerialize();
			$emit($report, 0);
		case 'blueprint':
			$schema=$readJson($options['schema'] ?? '', 'Schema');
			$columns=is_array($schema['columns'] ?? null) ? $schema['columns'] : $schema;
			$blueprint=PanelDeveloperToolkit::blueprint((string)($options['table'] ?? $schema['table'] ?? ''), $columns, ['foreign_keys'=>$schema['foreign_keys'] ?? []]);
			$emit(strtolower((string)($options['format'] ?? 'json'))==='php' ? $blueprint->php((string)($options['namespace'] ?? 'App\\Panel\\Resources')) : $blueprint->jsonSerialize());
		case 'quality':
			$axes=isset($options['axes']) ? $readJson($options['axes'], 'Axes') : [];
			$emit(PanelDeveloperToolkit::qualityMatrix((string)($options['name'] ?? 'panel'), (string)($options['url'] ?? ''), $axes)->jsonSerialize());
		case 'inclusive-quality':
			$configuration=isset($options['configuration']) ? $readJson($options['configuration'], 'Inclusive quality configuration') : [];
			$emit(PanelDeveloperToolkit::inclusiveQualityMatrix((string)($options['name'] ?? 'panel'),(string)($options['url'] ?? ''),$configuration)->jsonSerialize());
		case 'inclusive-quality-validate':
			$matrix=PanelInclusiveQualityMatrix::fromArray($readJson($options['matrix'] ?? '', 'Inclusive quality matrix'));
			$browserCases=array_map(static function(\Dataphyre\Panel\PanelBrowserRegressionManifest $manifest):array { $payload=$manifest->toArray(); return ['case_id'=>$payload['name'],'profile_id'=>$payload['meta']['quality_profile']['id'],'contract_id'=>$payload['meta']['quality_contract']['id'],'url'=>$payload['url']]; },$matrix->browserManifests());
			$emit(['type'=>'panel_inclusive_quality_validation','version'=>1,'valid'=>true,'name'=>$matrix->name(),'digest'=>$matrix->digest(),'case_count'=>count($matrix->cases()),'browser_case_count'=>count($browserCases),'browser_cases'=>$browserCases]);
		case 'inclusive-quality-gate':
			$matrix=PanelInclusiveQualityMatrix::fromArray($readJson($options['matrix'] ?? '', 'Inclusive quality matrix'));
			$capabilities=PanelQualityCapabilityReport::fromArray($readJson($options['capabilities'] ?? '', 'Inclusive quality capabilities'));
			$evidencePayload=$readJson($options['evidence'] ?? '', 'Inclusive quality evidence');
			if(!array_is_list($evidencePayload)){
				$evidenceMatrix=$evidencePayload['matrix'] ?? null;
				if(!is_array($evidenceMatrix) || ($evidenceMatrix['name'] ?? null)!==$matrix->name() || ($evidenceMatrix['digest'] ?? null)!==$matrix->digest()){ throw new UnexpectedValueException('Inclusive quality evidence envelope does not match the exact matrix name and digest.'); }
			}
			$evidence=is_array($evidencePayload['evidence'] ?? null)?$evidencePayload['evidence']:$evidencePayload;
			if(!array_is_list($evidence)){ throw new InvalidArgumentException('Inclusive quality evidence JSON must be a list or an object containing an evidence list.'); }
			$budgets=isset($options['budgets'])?$readJson($options['budgets'],'Inclusive quality budgets'):[];
			$result=$matrix->evaluate($capabilities,$evidence,$budgets)->jsonSerialize();
			$emit($result,($result['passed'] ?? false)===true?0:1);
		default:
			$emit(['ok'=>false, 'message'=>'Usage: panel_developer.php inspect|diff|blueprint|quality|inclusive-quality|inclusive-quality-validate|inclusive-quality-gate [options]'], 2);
	}
}
catch(Throwable $exception){
	$emit(['ok'=>false, 'message'=>$exception->getMessage(), 'exception'=>$exception::class], 1);
}
