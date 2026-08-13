<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\StaticAnalysisFixture\PanelBuilderInferenceFixture;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

if(!defined('DATAPHYRE_PANEL_STATIC_ANALYSIS_EMBEDDED')){ define('DATAPHYRE_PANEL_STATIC_ANALYSIS_EMBEDDED', true); }
$dpPanelStaticAnalysisRoot=dirname(__DIR__, 4);
require_once $dpPanelStaticAnalysisRoot.'/dev/tools/panel_static_analysis.php';
require_once dirname(__DIR__).'/static-analysis/fixtures/panel-builders.php';

test('Panel generic builder contract is deterministic and exact', static function(Context $t): void {
	$evidence=$t->panel()->staticAnalysis()->contractEvidence();
	$contract=$evidence['contract'];
	$t->same(1, $contract['schema_version']);
	$t->same(28, count($contract['targets']));
	$t->same([], $evidence['check']);
	$t->same([], $evidence['phpdoc_failures']);
	$t->isTrue($evidence['deterministic']);

	$resource=$contract['targets']['Dataphyre\\Panel\\Resource'] ?? [];
	$t->isTrue(in_array('@template TRecord = mixed', $resource['class_tags'] ?? [], true));
	$t->isTrue(in_array('@return self<TModel, TState>', $resource['methods']['model'] ?? [], true));
	$t->isTrue(in_array('@param callable(TRecord,PanelRequest|null=,self<TRecord,TState>|null=,ResourceTable<TRecord,TState>|null=,string=):(?string) $resolver', $resource['methods']['rowUrl'] ?? [], true));

	$field=$contract['targets']['Dataphyre\\Panel\\Field'] ?? [];
	$t->isTrue(in_array('@return self<TRecord,THydrated,TState>', $field['methods']['hydrateUsing'] ?? [], true));
	$t->isTrue(in_array('@return list<string>', $field['methods']['validateValue'] ?? [], true));

	$registry=$contract['targets']['Dataphyre\\Panel\\PanelInstanceExtensionRegistry'] ?? [];
	$t->isTrue(in_array('@return TResult', $registry['methods']['runAs'] ?? [], true));
	$t->isTrue(in_array('@param callable(TBuilder):mixed $callback', $registry['methods']['registerConfigurator'] ?? [], true));

	$page=$contract['targets']['Dataphyre\\Panel\\PanelPage'] ?? [];
	$t->isTrue(in_array('@template TContent = mixed', $page['class_tags'] ?? [], true));
	$t->isTrue(in_array('@return self<TPageContent,TRecord,TState>', $page['methods']['content'] ?? [], true));
	$t->isTrue(in_array('@param PageTable<TRecord,TState>|array<string,mixed>|string $table', $page['methods']['table'] ?? [], true));

	$command=$contract['targets']['Dataphyre\\Panel\\PanelCommand'] ?? [];
	$t->isTrue(in_array('@param string|callable(?PanelRequest,self,?PanelManager):(string|\\Stringable|null) $url', $command['methods']['url'] ?? [], true));
	$t->isTrue(in_array('@param callable(?PanelRequest,self,?PanelManager):bool $resolver', $command['methods']['visibleUsing'] ?? [], true));
})->tag('panel', 'static-analysis', 'builders', 'phpdoc', 'contract')->maxMillis(1500);

test('Panel compile-only fixture exercises typed fluent paths without dynamic gaps', static function(Context $t): void {
	PanelBuilderInferenceFixture::compileOnly();
	$t->isTrue(true);
})->tag('panel', 'static-analysis', 'builders', 'fixture')->maxMillis(1000);

test('Panel static analysis rejects every unshaped native callable parameter', static function(Context $t): void {
	$unshaped=<<<'PHP'
<?php
final class Fixture {
	/** @param callable $handler */
	public function handler(callable $handler): void {}
}
PHP;
	$failures=dp_panel_static_analysis_public_callable_failures($unshaped, 'Fixture.php', 'Fixture');
	$t->same(1, count($failures));
	$t->isTrue(str_contains($failures[0] ?? '', 'Fixture::handler()'));

	$shaped=<<<'PHP'
<?php
final class Fixture {
	/** @param callable(string):bool $handler */
	public function handler(callable $handler): void {}
}
PHP;
	$t->same([], dp_panel_static_analysis_public_callable_failures($shaped, 'Fixture.php', 'Fixture'));

	$aliased=<<<'PHP'
<?php
/** @phpstan-type Handler callable(string):bool */
final class Fixture {
	/** @param Handler $handler */
	public function handler(callable $handler): void {}
}
PHP;
	$t->same([], dp_panel_static_analysis_public_callable_failures($aliased, 'Fixture.php', 'Fixture'));
})->tag('panel', 'static-analysis', 'callables', 'phpdoc')->maxMillis(1000);

test('Panel macro stub generation is deterministic safe and parseable', static function(Context $t): void {
	$manifest=[
		'schema_version'=>1,
		'classes'=>[
			'Dataphyre\\Panel\\Widget'=>[
				'methods'=>[
					'fromTenant'=>[
						'return'=>'self<TRecord,int,TState>',
						'static'=>true,
						'parameters'=>[['name'=>'tenant','type'=>'non-empty-string']],
					],
				],
			],
			'Dataphyre\\Panel\\Field'=>[
				'templates'=>['@template TRecord','@template TValue','@template TState of array<string,mixed>'],
				'methods'=>[
					'currencyLabel'=>[
						'return'=>'self<TRecord,TValue,TState>',
						'parameters'=>[
							['name'=>'currency','type'=>'non-empty-string','optional'=>true,'default'=>"'CAD'"],
							['name'=>'parts','type'=>'string','variadic'=>true],
						],
					],
				],
			],
		],
	];
	$stubs=dp_panel_static_analysis_macro_stubs($manifest);
	$t->same($stubs, dp_panel_static_analysis_macro_stubs($manifest));
	$t->isTrue(str_contains($stubs, '@method self<TRecord,TValue,TState> currencyLabel(non-empty-string $currency = \'CAD\', string ...$parts)'));
	$t->isTrue(str_contains($stubs, '@method static self<TRecord,int,TState> fromTenant(non-empty-string $tenant)'));
	$t->isTrue(strpos($stubs, 'namespace Dataphyre\\Panel {')!==false);
	$t->isTrue(count(token_get_all($stubs, TOKEN_PARSE))>10);

	$example=dp_panel_static_analysis_read_json(dp_panel_static_analysis_root().'/runtime/modules/panel/static-analysis/panel-macros.example.json');
	$t->isTrue(str_contains(dp_panel_static_analysis_macro_stubs($example), 'currencyLabel'));
	$t->throws(static fn()=>dp_panel_static_analysis_macro_stubs([]), InvalidArgumentException::class);
	$t->throws(static fn()=>dp_panel_static_analysis_macro_stubs(['schema_version'=>1,'classes'=>['bad class'=>[]]]), InvalidArgumentException::class);
})->tag('panel', 'static-analysis', 'macros', 'stubs')->maxMillis(1000);

test('Panel static-analysis check reports contract and JSON drift', static function(Context $t): void {
	$evidence=$t->panel()->staticAnalysis()->driftEvidence($t->tempDirectory('panel-static-analysis-contract-fixtures'));
	$t->isTrue(str_starts_with($evidence['missing'][0] ?? '', 'Missing static-analysis contract:'));
	$t->isTrue(str_starts_with($evidence['invalid'][0] ?? '', 'Invalid static-analysis contract JSON:'));
	$t->isTrue(in_array('Dataphyre\\Panel\\Field generic/callback contract drifted.', $evidence['drift'], true));
})->tag('panel', 'static-analysis', 'contract', 'drift')->maxMillis(1500);
