<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\CoverageLanes;
use function Dataphyre\Test\dataphyre_path;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/CoverageLanes.php';

/** Returns one top-level GitHub Actions job without requiring a YAML extension. */
function dp_testing_exact_ci_job(string $workflow, string $name): string {
	$pattern='/^  '.preg_quote($name, '/').':\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:\R|\z)/ms';
	if(preg_match($pattern, $workflow, $matches)!==1){
		throw new RuntimeException('CI job was not found: '.$name.'.');
	}
	return (string)$matches['job'];
}

suite('Exclusive framework coverage ownership')
	->tag('testing', 'coverage', 'architecture')
	->group('framework-coverage')
	->contract('testing.coverage.exclusive-lanes', CoverageLanes::VERSION)
	->layer('architecture')
	->risk('critical')
	->watches('module:*', 'path:runtime/modules/testing/tooling/CoverageLanes.php')
	->through('line ownership', 'contract boundaries', 'dependency provenance')
	->isolation('process');

test('representative sources name one lane and one verification contract', static function(Context $t): void {
	$expectations=[
		'runtime/modules/http/Framework/Client.php'=>['first-party-exact',true],
		'runtime/modules/mvc/Framework/Bootstrap.php'=>['process-exact',true],
		'runtime/modules/core/kernel/core.main.php'=>['process-exact',true],
		'runtime/modules/datadoc/ui/index.php'=>['web-exact',true],
		'runtime/modules/flightdeck/kernel/view.php'=>['web-exact',true],
		'runtime/modules/tracelog/kernel/assets.php'=>['web-exact',true],
		'runtime/modules/fulltext_engine/stopwords/en_stopwords.php'=>['data-contract',false],
		'runtime/modules/profanity/datasets/en/product.php'=>['data-contract',false],
		'runtime/modules/stripe/src/lib/Stripe.php'=>['dependency-upstream',false],
		'runtime/modules/sql/third_party/adminer/adminer.php'=>['dependency-upstream',false],
		'runtime/modules/'.'shopiro'.'_devapi/shopiro-client/src/ShopiroClient.php'=>['dependency-upstream',false],
		'runtime/modules/panel/testing/panel_test_runner.php'=>['test-harness',false],
		'runtime/modules/testing/unit_tests/example.test.php'=>['test-harness',false],
		'runtime/modules/testing/tooling/code_worker.php'=>['coverage-self-transport',false],
	];
	foreach($expectations as $path=>[$lane,$lineCoverage]){
		$assignment=CoverageLanes::assign($path);
		$t->same($lane,$assignment['lane'],$path);
		$t->same($lineCoverage,$assignment['line_coverage'],$path);
		$t->notEmpty($assignment['verification'],$path);
		$t->notEmpty($assignment['description'],$path);
	}
	$t->same('first-party-exact',CoverageLanes::assign('runtime/modules/example/Framework/NewFeature.php')['lane']);
	$t->same('runtime/modules/testing/tooling/code_worker.php',CoverageLanes::canonicalPath('common/dataphyre/runtime/modules/testing/tooling/code_worker.php'));
	$t->same('runtime/modules/testing/tooling/code_worker.php',CoverageLanes::canonicalPath('/workspace/vendor/dataphyre/dataphyre/runtime/modules/testing/tooling/code_worker.php'));
	$t->same('coverage-self-transport',CoverageLanes::assign('common/dataphyre/runtime/modules/testing/tooling/code_worker.php')['lane']);
	$t->same('coverage-self-transport',CoverageLanes::assign('/workspace/vendor/dataphyre/dataphyre/runtime/modules/testing/tooling/code_worker.php')['lane']);
	$t->throwsLike(
		static fn()=>CoverageLanes::assign('runtime/modules/profanity/datasets/unit_tests/ambiguous.test.php'),
		LogicException::class,
		'multiple exclusive lanes'
	);
});

test('every framework PHP file has an exclusive declared owner', static function(Context $t): void {
	$root=dataphyre_path().'/runtime/modules';
	$definitions=CoverageLanes::definitions();
	$counts=array_fill_keys(array_keys($definitions),0);
	$files=[];
	$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $entry){
		if(!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension())!=='php'){continue;}
		$relative='runtime/modules/'.str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
		$assignment=CoverageLanes::assign($relative);
		$t->hasKey($assignment['lane'],$definitions,$relative);
		$counts[$assignment['lane']]++;
		$files[]=$relative;
	}
	$t->greaterThan(1000,count($files));
	$t->same(count($files),array_sum($counts));
	$t->greaterThan(0,$counts['first-party-exact']);
	$t->greaterThan(0,$counts['process-exact']);
	$t->greaterThan(0,$counts['web-exact']);
	$t->greaterThan(0,$counts['data-contract']);
	$t->greaterThan(0,$counts['dependency-upstream']);
	$t->greaterThan(0,$counts['test-harness']);
	$t->greaterThan(0,$counts['coverage-self-transport']);
	$t->same(0,$counts['generated']);

	$targets=[];
	foreach(CoverageLanes::exclusionRules() as $rule){
		$t->notEmpty($rule['target']);
		$t->notEmpty($rule['reason']);
		$t->isFalse($definitions[$rule['lane']]['line_coverage']);
		$targets[]=$rule['target'];
	}
	$t->same($targets,array_values(array_unique($targets)));
});

test('every Framework Migrations source remains first-party exact', static function(Context $t): void {
	$root=dataphyre_path().'/runtime/modules';
	$migrations=[];
	$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
	foreach($iterator as $entry){
		if(!$entry instanceof SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension())!=='php'){continue;}
		$relative='runtime/modules/'.str_replace('\\','/',substr($entry->getPathname(),strlen($root)+1));
		if(!str_contains($relative,'/Framework/Migrations/')){continue;}
		$assignment=CoverageLanes::assign($relative);
		$t->same('first-party-exact',$assignment['lane'],$relative);
		$t->isTrue($assignment['line_coverage'],$relative);
		$migrations[]=$relative;
	}
	$t->greaterThan(0,count($migrations));
	$t->same($migrations,array_values(array_unique($migrations)));
});

test('generic framework CI jobs pin the closed-world exact coverage contract', static function(Context $t): void {
	$root=dataphyre_path();
	$workflows=[[
		'path'=>$root.'/.github/workflows/ci.yml',
		'job'=>'framework-exact-coverage',
		'embedded'=>false,
	]];
	$hostRoot=dirname($root,2);
	$embeddedDataphyre=realpath($hostRoot.'/common/dataphyre');
	if(is_string($embeddedDataphyre) && $embeddedDataphyre===realpath($root)){
		$hostContracts=[];
		foreach(array_merge(
			glob($hostRoot.'/.github/workflows/*.yml') ?: [],
			glob($hostRoot.'/.github/workflows/*.yaml') ?: []
		) as $hostWorkflow){
			$source=(string)file_get_contents($hostWorkflow);
			if(preg_match('/^  dataphyre-framework-exact-coverage:\R/m',$source)!==1){continue;}
			$hostContracts[]=[
				'path'=>$hostWorkflow,
				'job'=>'dataphyre-framework-exact-coverage',
				'embedded'=>true,
			];
		}
		$t->same(1,count($hostContracts),'Embedded hosts must declare one generic Dataphyre framework exact-coverage job.');
		array_push($workflows,...$hostContracts);
	}
	foreach($workflows as $contract){
		$workflow=(string)file_get_contents($contract['path']);
		$job=dp_testing_exact_ci_job($workflow,$contract['job']);
		foreach([
			'coverage: xdebug',
			'XDEBUG_MODE: coverage',
			'--scope=framework',
			'--kind=code',
			'--fail-skipped',
			'--fail-todo',
			'--coverage=cache/ci/framework.coverage.json',
			'--coverage-require=xdebug',
			'--coverage-source=runtime/modules',
			'--coverage-closed-world',
			'--coverage-min-percent=100',
			'--junit=cache/ci/framework.junit.xml',
			'--profile=cache/ci/framework.profile.json',
			'> cache/ci/framework.summary.json',
			'framework.coverage.json',
			'framework.junit.xml',
			'framework.profile.json',
			'framework.summary.json',
			'if-no-files-found: error',
		] as $marker){
			$t->contains($marker,$job,$contract['job'].' must pin '.$marker.'.');
		}
		if($contract['embedded']){
			$t->contains('working-directory: common/dataphyre',$job);
		}
	}
});
