<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace Dataphyre\Test;

/** Exclusive ownership manifest for every PHP source in an exact report. */
final class CoverageLanes {

	public const VERSION=2;
	private const COVERAGE_TRANSPORTS=[
		'runtime/modules/testing/tooling/code_worker.php',
		'runtime/modules/testing/tooling/WorkerCoverage.php',
		'runtime/modules/testing/tooling/CoverageSubprocess.php',
		'runtime/modules/dpanel/kernel/dpanel.worker.php',
	];
	private const WEB_ENTRYPOINTS=[
		'runtime/modules/tracelog/kernel/assets.php',
		'runtime/modules/tracelog/kernel/assets_support.php',
		'runtime/modules/tracelog/kernel/plotter.php',
		'runtime/modules/tracelog/kernel/viewer.php',
		'runtime/modules/caspow/kernel/endpoint.php',
		'runtime/modules/dpanel/kernel/panel.php',
		'runtime/modules/stripe/kernel/webhook.php',
		'runtime/modules/vestra/kernel/loader.php',
	];

	/** @return array<string,array{line_coverage:bool,verification:string,description:string}> */
	public static function definitions(): array {
		return [
			'first-party-exact'=>[
				'line_coverage'=>true,
				'verification'=>'unit + property + mutation contracts',
				'description'=>'Framework-owned request-time source measured by an exact line engine.',
			],
			'process-exact'=>[
				'line_coverage'=>true,
				'verification'=>'coverage-carrying subprocess scenarios',
				'description'=>'Bootstrap, diagnostic, schema, scheduler, compiler, and CLI entrypoints.',
			],
			'web-exact'=>[
				'line_coverage'=>true,
				'verification'=>'coverage-carrying HTTP/browser scenarios',
				'description'=>'Web endpoints, views, and development surfaces measured through their real boundary.',
			],
			'data-contract'=>[
				'line_coverage'=>false,
				'verification'=>'schema + encoding + uniqueness + deterministic hash contracts',
				'description'=>'PHP data declarations verified as content rather than request-time statements.',
			],
			'dependency-upstream'=>[
				'line_coverage'=>false,
				'verification'=>'pin + checksum + license + adapter smoke + upstream evidence',
				'description'=>'Bundled third-party packages owned and tested by their upstream project.',
			],
			'test-harness'=>[
				'line_coverage'=>false,
				'verification'=>'harness self-tests + fixture integrity contracts',
				'description'=>'Test definitions, fixtures, documentation sources, and dedicated regression scripts.',
			],
			'coverage-self-transport'=>[
				'line_coverage'=>false,
				'verification'=>'direct Xdebug + phpdbg + included-file payload contracts',
				'description'=>'Coverage transports that must stop an engine before serializing their own evidence.',
			],
			'generated'=>[
				'line_coverage'=>false,
				'verification'=>'generator provenance + deterministic regeneration + diff gate',
				'description'=>'Generated source; this lane is intentionally empty until provenance is declared.',
			],
		];
	}

	/** @return array{lane:string,line_coverage:bool,verification:string,description:string} */
	public static function assign(string $relative): array {
		$path=self::canonicalPath($relative);
		$matches=[];
		if(self::isCoverageTransport($path)){$matches[]='coverage-self-transport';}
		if(self::isDataContract($path)){$matches[]='data-contract';}
		if(self::isDependency($path)){$matches[]='dependency-upstream';}
		if(self::isHarness($path)){$matches[]='test-harness';}
		if(self::isProcess($path)){$matches[]='process-exact';}
		if(self::isWeb($path)){$matches[]='web-exact';}
		$matches=array_values(array_unique($matches));
		if(count($matches)>1){
			throw new \LogicException('Coverage source matches multiple exclusive lanes: '.$path.' => '.implode(', ', $matches));
		}
		$lane=$matches[0] ?? 'first-party-exact';
		$definition=self::definitions()[$lane];
		return ['lane'=>$lane]+$definition;
	}

	/** Normalizes standalone, embedded, vendor, and absolute module paths. */
	public static function canonicalPath(string $relative): string {
		$path=ltrim(str_replace('\\','/',$relative),'/');
		$rooted='/'.$path;
		$offset=strpos($rooted,'/runtime/modules/');
		return $offset===false ? $path : substr($rooted,$offset+1);
	}

	/** @return list<array{target:string,reason:string,lane:string}> */
	public static function exclusionRules(): array {
		return [
			['target'=>'**/unit_tests/**','reason'=>'test definitions and fixtures measure product source; they are not product coverage targets','lane'=>'test-harness'],
			['target'=>'**/documentation/**','reason'=>'documentation has no executable runtime contract','lane'=>'test-harness'],
			['target'=>'**/static-analysis/**','reason'=>'analyzer fixtures and generated contracts describe source types; they are not request-time product code','lane'=>'test-harness'],
			['target'=>'runtime/modules/*/testing/**','reason'=>'module-specific process probes, fakes, and fixtures are verified by dedicated harness contracts','lane'=>'test-harness'],
			['target'=>'runtime/modules/mvc/kernel/mvc_regression.php','reason'=>'the MVC regression executable is a dedicated test harness, not request-time product code','lane'=>'test-harness'],
			['target'=>'runtime/modules/testing/tooling/code_worker.php','reason'=>'the transport harness must snapshot coverage before it can serialize its own result','lane'=>'coverage-self-transport'],
			['target'=>'runtime/modules/dpanel/kernel/dpanel.worker.php','reason'=>'the legacy diagnostic transport must stop coverage before serializing its own result','lane'=>'coverage-self-transport'],
			['target'=>'runtime/modules/testing/tooling/WorkerCoverage.php','reason'=>'the shared worker transport stops exact engines while producing their final line map','lane'=>'coverage-self-transport'],
			['target'=>'runtime/modules/testing/tooling/CoverageSubprocess.php','reason'=>'the auto-prepend transport must stop child coverage before serializing its own result','lane'=>'coverage-self-transport'],
			['target'=>'runtime/modules/fulltext_engine/stopwords/**','reason'=>'stopword declarations are verified by deterministic data contracts rather than statement coverage','lane'=>'data-contract'],
			['target'=>'runtime/modules/profanity/datasets/**','reason'=>'profanity declarations are verified by deterministic data contracts rather than statement coverage','lane'=>'data-contract'],
			['target'=>'runtime/modules/stripe/src/**','reason'=>'bundled Stripe SDK source is third-party code with its own upstream verification boundary','lane'=>'dependency-upstream'],
			['target'=>'runtime/modules/'.'cj'.'dropshipping/'.'cj'.'dropshipping-client/**','reason'=>'bundled CJ client source is third-party code with its own upstream verification boundary','lane'=>'dependency-upstream'],
			['target'=>'runtime/modules/sql/third_party/adminer/**','reason'=>'bundled Adminer source is third-party code with its own upstream verification boundary','lane'=>'dependency-upstream'],
		];
	}

	private static function isCoverageTransport(string $path): bool {
		return in_array($path,self::COVERAGE_TRANSPORTS,true);
	}

	private static function isDataContract(string $path): bool {
		return str_starts_with($path,'runtime/modules/fulltext_engine/stopwords/')
			|| str_starts_with($path,'runtime/modules/profanity/datasets/');
	}

	private static function isDependency(string $path): bool {
		return str_starts_with($path,'runtime/modules/stripe/src/')
			|| str_starts_with($path,'runtime/modules/'.'cj'.'dropshipping/'.'cj'.'dropshipping-client/')
			|| str_starts_with($path,'runtime/modules/sql/third_party/adminer/');
	}

	private static function isHarness(string $path): bool {
		return str_contains('/'.$path,'/unit_tests/')
			|| str_contains('/'.$path,'/documentation/')
			|| str_contains('/'.$path,'/static-analysis/')
			|| str_ends_with($path,'.test.php')
			|| preg_match('#^runtime/modules/(?!testing/)[^/]+/testing/#',$path)===1
			|| $path==='runtime/modules/mvc/kernel/mvc_regression.php';
	}

	private static function isProcess(string $path): bool {
		if(self::isCoverageTransport($path)||self::isDataContract($path)||self::isDependency($path)||self::isHarness($path)){return false;}
		$base=basename($path);
		return $base==='Bootstrap.php'
			|| preg_match('/(?:^|[._-])(main|tables|diagnostic|scheduler|compiler|scaffolder|wrapper|task[_-]?runner|check)\.php$/i',$base)===1;
	}

	private static function isWeb(string $path): bool {
		if(self::isCoverageTransport($path)||self::isDataContract($path)||self::isDependency($path)||self::isHarness($path)||self::isProcess($path)){return false;}
		if(str_starts_with($path,'runtime/modules/datadoc/ui/')){return true;}
		if(str_starts_with($path,'runtime/modules/flightdeck/kernel/')){return true;}
		return in_array($path,self::WEB_ENTRYPOINTS,true);
	}
}
