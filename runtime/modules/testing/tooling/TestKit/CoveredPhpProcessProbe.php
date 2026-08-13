<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Coverage-aware PHP subprocess probe with ordinary-process fallback. */
final class CoveredPhpProcessProbe {

	/**
	 * @param list<string> $arguments
	 * @param array<string,string|int|float|bool|null> $environment
	 */
	public static function run(
		TempWorkspace $workspace,
		array $arguments,
		string $stdin='',
		?string $workingDirectory=null,
		array $environment=[],
		int $timeoutMillis=10000,
		?string $frameworkRoot=null,
		array $phpIni=[]
	): ProcessResult {
		$frameworkRoot=$frameworkRoot ?? dataphyre_path();
		$bootstrap=$frameworkRoot.'/runtime/modules/testing/tooling/CoverageSubprocess.php';
		$plan=self::plan($arguments,$bootstrap,phpIni:$phpIni);
		if($plan['instrumented']){
			$part=$workspace->path('coverage-part.json');
			$environment+=[
				'DATAPHYRE_TEST_COVERAGE_PART'=>$part,
				'DATAPHYRE_TEST_COVERAGE_FRAMEWORK_ROOT'=>$frameworkRoot,
				'DATAPHYRE_TEST_COVERAGE_RESULT_ROOT'=>$frameworkRoot,
			];
		}
		$result=ProcessProbe::run($workspace,$plan['command'],$stdin,$workingDirectory,$environment,$timeoutMillis);
		if($plan['instrumented']){
			$part=$workspace->path('coverage-part.json');
			$decoded=is_file($part) ? json_decode((string)file_get_contents($part),true) : null;
			if(!is_array($decoded)){
				$writerError=is_file($part.'.error') ? trim((string)file_get_contents($part.'.error')) : 'no writer diagnostic';
				throw new \RuntimeException('Covered subprocess did not return an exact coverage part: '.json_encode([
					'part'=>$part,
					'writer'=>$writerError,
					'process'=>$result->diagnostic(),
				], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
			}
			CoverageParts::add($decoded);
		}
		return $result;
	}

	/**
	 * @param list<string> $arguments
	 * @return array{command:list<string>,instrumented:bool,engine:string}
	 */
	public static function plan(array $arguments,string $bootstrap,?string $binary=null,?string $sapi=null,?bool $xdebugAvailable=null,?string $memoryLimit=null,array $phpIni=[]): array {
		$binary=$binary ?? PHP_BINARY;
		$sapi=strtolower($sapi ?? PHP_SAPI);
		$memoryLimit=trim($memoryLimit ?? (string)ini_get('memory_limit'));
		$memoryArguments=$memoryLimit!=='' ? ['-d','memory_limit='.$memoryLimit] : [];
		$iniArguments=PhpRuntime::iniArguments($phpIni);
		$debugger=$sapi==='phpdbg'||PhpRuntime::isDebugger($binary);
		$xdebugAvailable=$xdebugAvailable ?? function_exists('xdebug_start_code_coverage');
		if($debugger){
			return ['command'=>[$binary,...$memoryArguments,...$iniArguments,'-qrr',$bootstrap,...array_map('strval',$arguments)],'instrumented'=>true,'engine'=>'phpdbg'];
		}
		if($xdebugAvailable){
			return ['command'=>[$binary,...$memoryArguments,...$iniArguments,$bootstrap,...array_map('strval',$arguments)],'instrumented'=>true,'engine'=>'xdebug'];
		}
		return ['command'=>PhpRuntime::command($arguments,$binary,$phpIni),'instrumented'=>false,'engine'=>'included_files'];
	}
}
