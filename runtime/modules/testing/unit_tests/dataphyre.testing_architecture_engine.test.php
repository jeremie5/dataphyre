<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\TestArchitectureAudit;
use Dataphyre\Test\TestArchitectureIndex;
use Dataphyre\Test\TestArchitectureRule;
use Dataphyre\Test\TestArchitectureViolation;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/fixtures/test_architecture_source_reader.php';
require_once dirname(__DIR__).'/tooling/TestArchitecture.php';

function dp_test_architecture_exemption(string $rule,string $reason): string {
	return 'dataphyre-test-'.'architecture: exempt['.$rule.'] reason="'.$reason.'"';
}

suite('Test architecture engine')
	->tag('testing', 'architecture', 'engine')
	->group('framework-coverage');

test('one cached source index feeds every architecture rule and returns every violation', static function(Context $t): void {
	$workspace=$t->workspace('architecture-all-rules');
	$evaluate='e'.'val';
	$globals='$'.'GLOBALS';
	$get='$'.'_GET';
	$reflectionClass='Reflection'.'Class';
	$reflectionMethod='Reflection'.'Method';
	$reflectionProperty='Reflection'.'Property';
	$setAccessible='set'.'Accessible';
	$invokeArgs='invoke'.'Args';
	$tempnam='temp'.'nam';
	$tmpfile='tmp'.'file';
	$systemTemp='sys_get_'.'temp_dir';
	$outputBuffer='ob_'.'start';
	$processOpen='proc_'.'open';
	$workspace->file('alpha/Framework/Unsafe.php',"<?php\n".$evaluate."('\$production=true;');\n");
	$workspace->file('alpha/unit_tests/broken.test.php',implode("\n",[
		'<?php',
		$evaluate."('\$test=true;');",
		$globals."['leak']=true;",
		'global '.'$legacy;',
		$get."['page']='1';",
		'$method=new '.$reflectionMethod."(stdClass::class, '__construct');",
		'$class=new '.$reflectionClass.'(stdClass::class);',
		'$property=new \\'.$reflectionProperty."(stdClass::class, 'value');",
		'$method->'.$setAccessible.'(true);',
		'$method->'.$invokeArgs.'(new stdClass(), []);',
		$tempnam.'('.$systemTemp."(), 'architecture');",
		$tmpfile.'();',
		$outputBuffer.'();',
		$processOpen.'([], [], $pipes);',
		'final class DangerousRootpathFixture {',
		'  public static function root(): string { return ROOTPATH[\'dataphyre\']; }',
		'  public static function indirect(): void { core::force_rmdir(self::root()); }',
		'  public static function direct(): void { core::force_rmdir(ROOTPATH[\'dataphyre\']); }',
		'}',
		'new stdClass();',
		$reflectionMethod.'::'.$setAccessible.'(true);',
		'global;',
	]));
	$workspace->file('alpha/unit_tests/broken.json', <<<'JSON'
{
  "custom_script": "return true;",
  "nested": {"file_dynamic": "fixture.php"}
}
JSON);
	$workspace->file('alpha/readme.txt', 'ignored');

	$audit=TestArchitectureAudit::forModulesRoot($workspace->root());
	$cached=TestArchitectureAudit::forModulesRoot($workspace->root());
	$statistics=$audit->statistics();
	$t->same(3, $statistics['files']);
	$t->same(3, $statistics['source_reads']);
	$t->same(2, $statistics['php_tokenizations']);
	$t->same(1, $statistics['json_decodes']);
	$t->same($statistics['index_identity'], $cached->statistics()['index_identity']);
	$t->same(['alpha'], $audit->moduleNames());

	$violations=$audit->violations();
	$t->same($violations, $audit->violations());
	$ruleCounts=array_count_values(array_map(static fn(TestArchitectureViolation $violation): string=>$violation->rule(), $violations));
	ksort($ruleCounts);
	$t->same([
		TestArchitectureRule::DESTRUCTIVE_ROOTPATH_OPERATION=>2,
		TestArchitectureRule::DIRECT_REFLECTION_ACCESS=>5,
		TestArchitectureRule::EXECUTABLE_JSON=>2,
		TestArchitectureRule::GLOBAL_DECLARATION=>1,
		TestArchitectureRule::RAW_GLOBAL_VARIABLE=>1,
		TestArchitectureRule::RAW_OUTPUT_BUFFER=>1,
		TestArchitectureRule::RAW_PROCESS_CONTROL=>1,
		TestArchitectureRule::RAW_SUPERGLOBAL=>1,
		TestArchitectureRule::RUNTIME_EVALUATION=>2,
		TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY=>1,
		TestArchitectureRule::UNMANAGED_TEMPORARY_FILE=>2,
	], $ruleCounts);
	$t->count(19, $violations);
	$t->count(2, $audit->violationsFor(TestArchitectureRule::DESTRUCTIVE_ROOTPATH_OPERATION));
	$t->count(5, $audit->violationsFor(TestArchitectureRule::DIRECT_REFLECTION_ACCESS));
	$t->throws(static fn()=>$audit->violationsFor('not-a-rule'), InvalidArgumentException::class);

	$first=$violations[0];
	$t->same($first->toArray(), $first->jsonSerialize());
	$t->same($first->rule(), $first->toArray()['rule']);
	$t->same($first->file(), $first->toArray()['file']);
	$t->same($first->line(), $first->toArray()['line']);
	$t->same($first->detail(), $first->toArray()['detail']);
	$t->contains('Test-architecture violations (19):', $audit->report());
	$t->contains((string)$first, $audit->report());
	$t->same(array_map(static fn(TestArchitectureViolation $violation): array=>$violation->toArray(), $violations), $audit->violationData());
})->strictIssues();

test('typed line exemptions require the exact rule and a useful justification', static function(Context $t): void {
	$workspace=$t->workspace('architecture-exemptions');
	$prefix='dataphyre-test-'.'architecture:';
	$globals='$'.'GLOBALS';
	$environment='$'.'_ENV';
	$tempnam='temp'.'nam';
	$tmpfile='tmp'.'file';
	$systemTemp='sys_get_'.'temp_dir';
	$outputBuffer='ob_'.'start';
	$processOpen='proc_'.'open';
	$lines=[
		'<?php',
		$globals.'[\'native\']=true; // '.dp_test_architecture_exemption(TestArchitectureRule::RAW_GLOBAL_VARIABLE, 'GlobalState contract must observe PHP native global storage.'),
		$environment.'[\'NATIVE\']=\'yes\'; // '.dp_test_architecture_exemption(TestArchitectureRule::RAW_SUPERGLOBAL, 'Environment restoration contract must touch the native array.'),
		$tempnam.'(\'/tmp\', \'architecture\'); // '.dp_test_architecture_exemption(TestArchitectureRule::UNMANAGED_TEMPORARY_FILE, 'Namespace failure shim delegates to the native temporary API.'),
		$tmpfile.'(); // '.dp_test_architecture_exemption(TestArchitectureRule::UNMANAGED_TEMPORARY_FILE, 'Native handle behavior is the contract under test on this line.'),
		$systemTemp.'(); // '.dp_test_architecture_exemption(TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY, 'Coverage scope deliberately compares against the system directory.'),
		$outputBuffer.'(); // '.dp_test_architecture_exemption(TestArchitectureRule::RAW_OUTPUT_BUFFER, 'Buffer lifecycle behavior is the native contract under test.'),
		$processOpen.'([], [], $pipes); // '.dp_test_architecture_exemption(TestArchitectureRule::RAW_PROCESS_CONTROL, 'Process startup failure is the native contract under test.'),
		$globals.'[\'wrong-rule\']=true; // '.dp_test_architecture_exemption(TestArchitectureRule::RAW_SUPERGLOBAL, 'This valid exemption intentionally names a different rule.'),
		$globals.'[\'short\']=true; // '.$prefix.' exempt[raw-global-variable] reason="short"',
		$globals.'[\'legacy\']=true; // '.$prefix.' explicit-global-contract',
		$globals.'[\'unknown\']=true; // '.$prefix.' exempt[unknown-rule] reason="Unknown rules never create an architecture escape hatch."',
	];
	$workspace->file('alpha/unit_tests/exemptions.test.php', implode("\n", $lines));
	$workspace->file('alpha/unit_tests/invalid.test.json', <<<'JSON'
{"custom_script":
JSON);
	$workspace->file('alpha/unit_tests/single-quoted.test.json', <<<'JSON'
{'file_dynamic':
JSON);

	$audit=TestArchitectureAudit::forModulesRoot($workspace->root());
	$t->count(4, $audit->violationsFor(TestArchitectureRule::RAW_GLOBAL_VARIABLE));
	$t->count(3, $audit->violationsFor(TestArchitectureRule::INVALID_EXEMPTION));
	$t->count(2, $audit->violationsFor(TestArchitectureRule::EXECUTABLE_JSON));
	$t->count(9, $audit->violations());
	$t->same([], $audit->violationsFor(TestArchitectureRule::RAW_SUPERGLOBAL));
	$t->same([], $audit->violationsFor(TestArchitectureRule::UNMANAGED_TEMPORARY_FILE));
	$t->same([], $audit->violationsFor(TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY));
	$t->same([], $audit->violationsFor(TestArchitectureRule::RAW_OUTPUT_BUFFER));
	$t->same([], $audit->violationsFor(TestArchitectureRule::RAW_PROCESS_CONTROL));
});

test('cache lifecycle clean reports rule catalogs and sentinel metadata stay explicit', static function(Context $t): void {
	$workspace=$t->workspace('architecture-clean');
	$workspace->file('alpha/unit_tests/clean.test.php', "<?php\nreturn true;\n");
	$workspace->file('alpha/unit_tests/clean.json', '{"assertions":[]}');

	$t->same(TestArchitectureRule::all(), array_values(array_unique(TestArchitectureRule::all())));
	$t->same([
		TestArchitectureRule::UNMANAGED_TEMPORARY_FILE,
		TestArchitectureRule::UNMANAGED_SYSTEM_TEMPORARY_DIRECTORY,
		TestArchitectureRule::RAW_GLOBAL_VARIABLE,
		TestArchitectureRule::RAW_SUPERGLOBAL,
		TestArchitectureRule::RAW_OUTPUT_BUFFER,
		TestArchitectureRule::RAW_PROCESS_CONTROL,
	], TestArchitectureRule::exemptable());
	$t->same([], TestArchitectureAudit::changedRunSentinelModules('no sentinel metadata'));
	$t->same(['alpha','beta'], TestArchitectureAudit::changedRunSentinelModules(
		"/** @dataphyre-changed-run-sentinel framework(['beta', \"alpha\", 'beta']); */"
	));
	$t->same([], TestArchitectureAudit::changedRunSentinelModules(
		"/** @dataphyre-changed-run-sentinel framework('*'); */"
	));
	$t->same(['alpha','beta'], TestArchitectureAudit::changedRunSentinelModules(
		"/** @dataphyre-changed-run-sentinel framework('*'); */",
		['beta','alpha','beta','Invalid module']
	));
	$t->throws(static fn()=>TestArchitectureAudit::forModulesRoot($workspace->path('missing')), InvalidArgumentException::class);

	$audit=TestArchitectureAudit::forModulesRoot($workspace->root());
	$identity=$audit->statistics()['index_identity'];
	$t->same([], $audit->violations());
	$t->same('No test-architecture violations.', $audit->report());

	TestArchitectureIndex::forget($workspace->root());
	$t->notSame($identity, TestArchitectureAudit::forModulesRoot($workspace->root())->statistics()['index_identity']);
	TestArchitectureIndex::forget();
});

test('tokenizer recovery ignores incomplete non-calls without inventing destructive operations', static function(Context $t): void {
	$workspace=$t->workspace('architecture-tokenizer-recovery');
	$workspace->file('alpha/unit_tests/incomplete.test.php', <<<'PHP'
<?php
// ROOTPATH force_rmdir( keeps the destructive-operation scanner engaged.
function declarationWithoutBody();
unlink;
rmdir
PHP);

	$audit=TestArchitectureAudit::forModulesRoot($workspace->root());
	$t->same([], $audit->violationsFor(TestArchitectureRule::DESTRUCTIVE_ROOTPATH_OPERATION));
});

test('the architecture index fails closed when a discovered source cannot be read', static function(Context $t): void {
	$workspace=$t->workspace('architecture-unreadable-source');
	$workspace->file('alpha/unit_tests/architecture-unreadable.php','<?php return true;');

	$t->throws(
		static fn()=>TestArchitectureAudit::forModulesRoot($workspace->root()),
		RuntimeException::class,
	);
});
