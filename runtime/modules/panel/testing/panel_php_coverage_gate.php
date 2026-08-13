<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelCoverageGate;

if(!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)){
	fwrite(STDERR, "Panel PHP coverage gate must run from the command line.\n");
	exit(2);
}

require_once dirname(__DIR__).'/Framework/Testing/PanelCoverageGate.php';

$options=[];
$allowedOptions=['coverage', 'module-root', 'require-engine', 'minimum-percent', 'json'];
foreach(array_slice($argv, 1) as $argument){
	$argument=(string)$argument;
	if($argument==='--help' || $argument==='-h'){
		echo "Usage: php panel_php_coverage_gate.php --coverage=report.json [--module-root=path] [--require-engine=exact|xdebug|phpdbg] [--minimum-percent=100] [--json]\n";
		exit(0);
	}
	if(!str_starts_with($argument, '--')){
		fwrite(STDERR, "Unknown argument: {$argument}\n");
		exit(2);
	}
	$raw=substr($argument, 2);
	[$name, $value]=str_contains($raw, '=') ? explode('=', $raw, 2) : [$raw, true];
	if(!in_array($name, $allowedOptions, true) || array_key_exists($name, $options) || ($name!=='json' && $value===true)){
		fwrite(STDERR, "Unknown, duplicate, or valueless option: --{$name}\n");
		exit(2);
	}
	$options[$name]=$value;
}

$coverage=trim((string)($options['coverage'] ?? ''));
if($coverage===''){
	fwrite(STDERR, "Usage: php panel_php_coverage_gate.php --coverage=report.json [--module-root=path] [--require-engine=exact|xdebug|phpdbg] [--minimum-percent=100] [--json]\n");
	exit(2);
}
$requiredEngine=strtolower(trim((string)($options['require-engine'] ?? 'exact')));
if(!in_array($requiredEngine, ['exact', 'xdebug', 'phpdbg'], true)){
	fwrite(STDERR, "--require-engine must be exact, xdebug, or phpdbg.\n");
	exit(2);
}
$minimum=$options['minimum-percent'] ?? 100;
if(!is_numeric($minimum) || (float)$minimum<0.0 || (float)$minimum>100.0){
	fwrite(STDERR, "--minimum-percent must be between 0 and 100.\n");
	exit(2);
}

try{
	$gate=PanelCoverageGate::fromFile($coverage, isset($options['module-root']) ? (string)$options['module-root'] : null, [
		'require_engine'=>$requiredEngine,
		'minimum_percent'=>(float)$minimum,
	]);
	$report=$gate->jsonSerialize();
	$json=json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
	if(array_key_exists('json', $options)){
		echo $json."\n";
	}else{
		$percent=$report['coverage_percent']===null ? 'n/a' : number_format((float)$report['coverage_percent'], 2).'%';
		echo sprintf(
			"Panel PHP coverage: %s; files %d/%d; lines %d/%d; engine %s.\n",
			$gate->passed() ? 'PASS' : 'FAIL',
			(int)$report['reported_source_file_count'],
			(int)$report['source_file_count'],
			(int)$report['covered_lines'],
			(int)$report['executable_lines'],
			implode(',', (array)$report['engines']) ?: 'none'
		);
		echo 'Coverage percent: '.$percent."\n";
		if(!$gate->passed()){
			echo 'Failures: '.implode(', ', array_map(static fn(array $failure): string=>(string)($failure['name']??'unknown'), $gate->failures()))."\n";
			$missing=array_slice($gate->missingFiles(), 0, 20);
			if($missing!==[]){
				echo "Missing source files (first 20):\n - ".implode("\n - ", $missing)."\n";
			}
			$uncovered=array_slice($gate->uncoveredFiles(), 0, 20, true);
			if($uncovered!==[]){
				echo "Files with uncovered executable lines (first 20):\n";
				foreach($uncovered as $file=>$stats){
					$lines=array_slice((array)($stats['uncovered_lines']??[]), 0, 30);
					echo ' - '.$file.': '.($lines!==[] ? implode(',', $lines) : ((int)($stats['covered']??0)).'/'.((int)($stats['executable']??0)))."\n";
				}
			}
			echo "Use --json for the complete machine-readable report.\n";
		}
	}
	exit($gate->passed() ? 0 : 1);
}catch(Throwable $exception){
	fwrite(STDERR, 'Panel PHP coverage gate error: '.$exception->getMessage()."\n");
	exit(2);
}
