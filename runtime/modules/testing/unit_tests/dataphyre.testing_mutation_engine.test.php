<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use Dataphyre\Test\Mutation\MutationCandidate;
use Dataphyre\Test\Mutation\MutationCatalog;
use Dataphyre\Test\Mutation\MutationCli;
use Dataphyre\Test\Mutation\MutationJournal;
use Dataphyre\Test\Mutation\MutationPlanner;
use Dataphyre\Test\Mutation\MutationProcess;
use Dataphyre\Test\Mutation\MutationRunner;
use Dataphyre\Test\PhpRuntime;
use Dataphyre\Test\TestIsolation;
use Dataphyre\Test\TestLayer;
use Dataphyre\Test\TestRisk;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/Mutation.php';

suite('First-party mutation engine contracts')
	->contract('testing.mutation-engine', 1)
	->tag('testkit', 'mutation')
	->layer(TestLayer::Contract)
	->risk(TestRisk::High)
	->watches('module:testing', 'tooling:mutation')
	->through('php.tokenizer', 'mutation.journal', 'unit-test.runner')
	->isolation(TestIsolation::File)
	->requiresAssertions();

test('token planning mutates executable operators without touching prose', static function(Context $t): void {
	$planner=new MutationPlanner(dirname(__DIR__, 4));
	$source=<<<'PHP'
<?php
// true === false && null ?? fallback is documentation here.
$prose='true === false && null ?? fallback';
return $enabled === true && ($count >= 2 || $value ?? false);
PHP;
	$candidates=$planner->candidatesForSource('runtime/example.php', $source);
	$contracts=array_map(static fn(MutationCandidate $candidate): array=>[$candidate->operator,$candidate->original,$candidate->replacement], $candidates);

	$t->same([
		['strict_identity','===','!=='],
		['boolean_literal','true','false'],
		['logical_connective','&&','||'],
		['boundary','>=','>'],
		['logical_connective','||','&&'],
		['null_coalescing','??','?:'],
		['boolean_literal','false','true'],
	], $contracts);
	$t->contains('$enabled !== true', $candidates[0]->apply($source));
	$t->same($candidates[0]->id, $planner->candidatesForSource('runtime/example.php', $source)[0]->id);
});

test('profiles name the failure modes they pressure', static function(Context $t): void {
	$t->same(['core','http-contract','route-contract','permission-contract','data-contract'], array_keys(MutationCatalog::profiles()));
	$t->same(array_keys(MutationCatalog::operators()), MutationCatalog::profiles()['core']);
	$t->contains('logical_connective', MutationCatalog::profiles()['permission-contract']);
	$t->contains('boundary', MutationCatalog::profiles()['route-contract']);
});

test('every token operator preserves its intended inverse and literal casing', static function(Context $t): void {
	$planner=new MutationPlanner(dirname(__DIR__, 4));
	$source=<<<'PHP'
<?php
return $left !== $right
	and $left == $right
	or $left != $right
	or $left <= $right
	or TRUE
	or False;
PHP;
	$contracts=array_map(
		static fn(MutationCandidate $candidate): array=>[$candidate->operator,$candidate->original,$candidate->replacement],
		$planner->candidatesForSource('runtime/operator-contract.php', $source),
	);

	$t->same([
		['strict_identity','!==','==='],
		['logical_connective','and','or'],
		['equality','==','!='],
		['logical_connective','or','and'],
		['equality','!=','=='],
		['logical_connective','or','and'],
		['boundary','<=','<'],
		['logical_connective','or','and'],
		['boolean_literal','TRUE','FALSE'],
		['logical_connective','or','and'],
		['boolean_literal','False','True'],
	], $contracts);
	$t->same(null, MutationCatalog::replacement(T_VARIABLE, '$notAnOperator'));
});

test('candidate application rejects source drift before changing a byte', static function(Context $t): void {
	$candidate=new MutationCandidate('drift-contract', 'runtime/Rule.php', 1, 13, 'strict_identity', 'identity', '===', '!==');
	$failure=$t->throwsLike(
		static fn()=>$candidate->apply('<?php return $left == $right;'),
		RuntimeException::class,
		'Mutation source drifted for drift-contract.',
	);

	$t->same('Mutation source drifted for drift-contract.', $failure->getMessage());
});

test('repository planning is bounded by path operator and limit', static function(Context $t): void {
	$workspace=$t->workspace('mutation-plan');
	$workspace->file('runtime/modules/sample/Framework/Rule.php', "<?php\nreturn \$left === \$right && true;\n");
	$workspace->file('runtime/modules/sample/unit_tests/ignored.php', "<?php\nreturn false;\n");
	$planner=new MutationPlanner($workspace->root());
	$candidates=$planner->plan(['runtime/modules/sample'], ['strict_identity','boolean_literal'], 2);

	$t->count(2, $candidates);
	$t->same(['strict_identity','boolean_literal'], array_map(static fn(MutationCandidate $candidate): string=>$candidate->operator, $candidates));
	$t->same(['runtime/modules/sample/Framework/Rule.php'], array_values(array_unique(array_map(static fn(MutationCandidate $candidate): string=>$candidate->file, $candidates))));
	$t->throws(static fn()=>$planner->plan(['runtime/modules/sample'], ['invented']), InvalidArgumentException::class);
	$t->same([], $planner->plan(['runtime/modules/sample/Framework/Rule.php'], ['null_coalescing']));
	$t->throwsLike(static fn()=>$planner->candidatesForSource('runtime/broken.php', '<?php if ('), RuntimeException::class, 'Unable to tokenize runtime/broken.php');
	$t->throwsLike(static fn()=>$planner->plan(['']), InvalidArgumentException::class, 'Mutation path cannot be empty');
	$t->throwsLike(static fn()=>$planner->plan(['runtime/missing']), InvalidArgumentException::class, 'Mutation path does not exist');
	$t->throwsLike(static fn()=>$planner->plan([$t->tempFile('<?php return true;', 'outside-mutation-root')]), InvalidArgumentException::class, 'escapes the repository root');
	$t->throws(static fn()=>new MutationPlanner($workspace->path('missing-root')), InvalidArgumentException::class);
});

test('recovery journal restores an interrupted source mutation exactly once', static function(Context $t): void {
	$workspace=$t->workspace('mutation-recovery');
	$file=$workspace->file('Rule.php', "<?php return true;\n");
	$journal=new MutationJournal($workspace->path('cache/recovery.json'), $workspace->root());
	$mutant="<?php return false;\n";

	$journal->arm($file, (string)file_get_contents($file), $mutant);
	file_put_contents($file, $mutant);

	$t->isTrue($journal->pending());
	$t->same(str_replace('\\', '/', $file), $journal->recover());
	$t->same("<?php return true;\n", file_get_contents($file));
	$t->isFalse($journal->pending());
	$t->same(null, $journal->recover());

	$outside=$t->tempFile("<?php return true;\n", 'mutation-outside');
	$t->throws(static fn()=>$journal->arm($outside, (string)file_get_contents($outside), $mutant), RuntimeException::class);
});

test('recovery journal rejects unsupported tampered and stale recovery state', static function(Context $t): void {
	$workspace=$t->workspace('mutation-recovery-integrity');
	$file=$workspace->file('Rule.php', "<?php return true;\n");
	$mutant="<?php return false;\n";

	$unsupportedPath=$workspace->file('cache/unsupported.json', "{}\n");
	$unsupported=new MutationJournal($unsupportedPath, $workspace->root());
	$t->throwsLike(static fn()=>$unsupported->recover(), RuntimeException::class, 'unsupported format');

	$integrityPath=$workspace->path('cache/integrity.json');
	$integrity=new MutationJournal($integrityPath, $workspace->root());
	$integrity->arm($file, (string)file_get_contents($file), $mutant);
	$payload=json_decode((string)file_get_contents($integrityPath), true, flags: JSON_THROW_ON_ERROR);
	$payload['original_sha256']=str_repeat('0', 64);
	file_put_contents($integrityPath, json_encode($payload, JSON_THROW_ON_ERROR));
	$t->throwsLike(static fn()=>$integrity->recover(), RuntimeException::class, 'integrity validation');

	$stalePath=$workspace->path('cache/stale.json');
	$stale=new MutationJournal($stalePath, $workspace->root());
	$stale->arm($file, (string)file_get_contents($file), $mutant);
	file_put_contents($file, "<?php return 'third-party-change';\n");
	$t->throwsLike(static fn()=>$stale->recover(), RuntimeException::class, 'changed after the journal was armed');
	$t->same("<?php return 'third-party-change';\n", file_get_contents($file));

	$missing=new MutationJournal($workspace->path('cache/missing.json'), $workspace->root());
	$t->throwsLike(static fn()=>$missing->arm($workspace->path('Missing.php'), '', ''), RuntimeException::class, 'missing or unreadable');
	$t->throws(static fn()=>new MutationJournal($workspace->path('cache/invalid-root.json'), $workspace->path('missing-root')), InvalidArgumentException::class);
});

test('recovery journal reports an unusable journal directory as an explicit failure', static function(Context $t): void {
	$workspace=$t->workspace('mutation-recovery-directory-failure');
	$file=$workspace->file('Rule.php', "<?php return true;\n");
	$workspace->file('blocked', 'this file deliberately occupies the journal parent path');
	$journal=new MutationJournal($workspace->path('blocked/recovery.json'), $workspace->root());

	$t->throwsLike(
		static fn()=>$journal->arm($file, (string)file_get_contents($file), "<?php return false;\n"),
		RuntimeException::class,
		'Unable to create mutation journal directory.',
	);
})->allowsIssues(E_WARNING);

test('mutation execution classifies killed and surviving mutants and restores source', static function(Context $t): void {
	$workspace=$t->workspace('mutation-execution');
	$source="<?php return \$left === \$right;\n";
	$file=$workspace->file('runtime/modules/sample/Framework/Rule.php', $source);
	$candidate=(new MutationPlanner($workspace->root()))->plan(['runtime/modules/sample'], ['strict_identity'], 1)[0];
	$php=PhpRuntime::binary();
	$runner=new MutationRunner($workspace->root(), $php, $workspace->path('cache/recovery.json'));

	$killed=$runner->run([$candidate], ['baseline'=>false,'command'=>PhpRuntime::command(['-r','exit(1);'], $php),'timeout'=>10]);
	$t->same(['killed'=>1,'survived'=>0,'timeout'=>0,'invalid'=>0,'error'=>0], $killed['counts']);
	$t->same($source, file_get_contents($file));

	$survived=$runner->run([$candidate], ['baseline'=>false,'command'=>PhpRuntime::command(['-r','exit(0);'], $php),'timeout'=>10]);
	$t->same(['killed'=>0,'survived'=>1,'timeout'=>0,'invalid'=>0,'error'=>0], $survived['counts']);
	$t->same(0.0, $survived['mutation_score']);
	$t->same($source, file_get_contents($file));
});

test('baseline gating deduplicates commands and stops before mutation when tests are red', static function(Context $t): void {
	$workspace=$t->workspace('mutation-baseline');
	$source="<?php return \$left === \$right || \$left !== null;\n";
	$file=$workspace->file('runtime/modules/sample/Framework/Rule.php', $source);
	$candidates=(new MutationPlanner($workspace->root()))->plan(['runtime/modules/sample'], ['strict_identity']);
	$php=PhpRuntime::binary();
	$runner=new MutationRunner($workspace->root(), $php, $workspace->path('cache/recovery.json'));
	$countFile=$workspace->path('baseline-invocations.txt');
	$greenCommand=PhpRuntime::command([
		'-r',
		'$path=$argv[1]; $count=is_file($path) ? (int)file_get_contents($path) : 0; file_put_contents($path, (string)($count+1)); exit(0);',
		$countFile,
	], $php);

	$green=$runner->run($candidates, ['command'=>$greenCommand,'timeout'=>10]);
	$t->same(2, $green['total']);
	$t->same(3, (int)file_get_contents($countFile), 'One shared baseline plus one test process per mutant should run.');
	$t->same(['killed'=>0,'survived'=>2,'timeout'=>0,'invalid'=>0,'error'=>0], $green['counts']);
	$t->same($source, file_get_contents($file));

	$failure=$t->throwsLike(
		static fn()=>$runner->run($candidates, [
			'command'=>PhpRuntime::command(['-r','fwrite(STDERR, "baseline diagnostic"); fwrite(STDOUT, "baseline output"); exit(7);'], $php),
			'timeout'=>10,
		]),
		RuntimeException::class,
		'Mutation baseline is not green.',
	);
	$t->contains('baseline diagnostic', $failure->getMessage());
	$t->contains('baseline output', $failure->getMessage());
	$t->same($source, file_get_contents($file));
});

test('mutant execution classifies timeout invalid syntax and source drift without leaking mutations', static function(Context $t): void {
	$workspace=$t->workspace('mutation-result-classification');
	$source="<?php return \$left === \$right;\n";
	$file=$workspace->file('runtime/modules/sample/Framework/Rule.php', $source);
	$planned=(new MutationPlanner($workspace->root()))->plan(['runtime/modules/sample'], ['strict_identity'], 1)[0];
	$php=PhpRuntime::binary();
	$runner=new MutationRunner($workspace->root(), $php, $workspace->path('cache/recovery.json'));

	$timeout=$runner->run([$planned], [
		'baseline'=>false,
		'command'=>PhpRuntime::command(['-r','usleep(1500000);'], $php),
		'timeout'=>1,
	]);
	$t->same(['killed'=>0,'survived'=>0,'timeout'=>1,'invalid'=>0,'error'=>0], $timeout['counts']);
	$t->same(124, $timeout['results'][0]['exit_code']);

	$invalid=new MutationCandidate('invalid-syntax', $planned->file, $planned->line, $planned->offset, 'strict_identity', 'invalid syntax fixture', '===', ')');
	$invalidReport=$runner->run([$invalid], ['baseline'=>false,'command'=>PhpRuntime::command(['-r','exit(0);'], $php),'timeout'=>10]);
	$t->same(['killed'=>0,'survived'=>0,'timeout'=>0,'invalid'=>1,'error'=>0], $invalidReport['counts']);
	$t->contains('Errors parsing', $invalidReport['results'][0]['diagnostic']);

	$drifted=new MutationCandidate('source-drift', $planned->file, $planned->line, $planned->offset, 'strict_identity', 'drift fixture', '!==', '===');
	$driftReport=$runner->run([$drifted], ['baseline'=>false,'command'=>PhpRuntime::command(['-r','exit(0);'], $php),'timeout'=>10]);
	$t->same(['killed'=>0,'survived'=>0,'timeout'=>0,'invalid'=>0,'error'=>1], $driftReport['counts']);
	$t->contains('Mutation source drifted', $driftReport['results'][0]['diagnostic']);

	$badCommand=$runner->run([$planned], ['baseline'=>false,'command'=>[],'timeout'=>10]);
	$t->same(['killed'=>0,'survived'=>0,'timeout'=>0,'invalid'=>0,'error'=>1], $badCommand['counts']);
	$t->contains('non-empty argument array', $badCommand['results'][0]['diagnostic']);
	$t->same($source, file_get_contents($file));
});

test('default test routing selects module ownership scope fallback and name filters', static function(Context $t): void {
	$workspace=$t->workspace('mutation-default-command');
	$moduleFile=$workspace->file('runtime/modules/catalog/Framework/Rule.php', "<?php return true;\n");
	$rootFile=$workspace->file('Rule.php', "<?php return true;\n");
	$runner=new MutationRunner($workspace->root(), PhpRuntime::binary(), $workspace->path('cache/recovery.json'));
	$access=$t->nonPublic($runner);
	$moduleCandidate=new MutationCandidate('module', 'runtime/modules/catalog/Framework/Rule.php', 1, 13, 'boolean_literal', 'literal', 'true', 'false');
	$rootCandidate=new MutationCandidate('root', 'Rule.php', 1, 13, 'boolean_literal', 'literal', 'true', 'false');

	$moduleCommand=$access->invoke('testCommand', $moduleCandidate, ['test_name'=>'catalog behavior']);
	$rootCommand=$access->invoke('testCommand', $rootCandidate, []);

	$t->contains('--owner=catalog', $moduleCommand);
	$t->contains('--name=catalog behavior', $moduleCommand);
	$t->contains('--scope=all', $rootCommand);
	$t->notContains('--scope=all', $moduleCommand);
	$t->isTrue(is_file($moduleFile));
	$t->isTrue(is_file($rootFile));
});

test('exclusive mutation lock refuses concurrent ownership', static function(Context $t): void {
	$workspace=$t->workspace('mutation-lock-ownership');
	$lockPath=$workspace->path('cache/mutation/runner.lock');
	$workspace->directory('cache/mutation');
	$lock=fopen($lockPath, 'c+');
	if(!is_resource($lock)||!flock($lock, LOCK_EX|LOCK_NB)){
		throw new RuntimeException('The lock fixture could not acquire its ownership precondition.');
	}
	$t->defer(static function()use($lock): void {flock($lock, LOCK_UN); fclose($lock);});
	$runner=new MutationRunner($workspace->root(), PhpRuntime::binary(), $workspace->path('cache/recovery.json'));

	$t->throwsLike(static fn()=>$runner->run([], ['baseline'=>false]), RuntimeException::class, 'Another mutation run already owns this repository.');
});

test('mutation runner reports an unusable lock directory explicitly', static function(Context $t): void {
	$workspace=$t->workspace('mutation-lock-directory-failure');
	$workspace->file('cache', 'this file deliberately occupies the lock directory path');
	$runner=new MutationRunner($workspace->root(), PhpRuntime::binary(), $workspace->path('journal/recovery.json'));

	$t->throwsLike(static fn()=>$runner->run([], ['baseline'=>false]), RuntimeException::class, 'Unable to create mutation lock directory.');
})->allowsIssues(E_WARNING);

test('subprocess diagnostics preserve channels and exit code', static function(Context $t): void {
	$root=dirname(__DIR__, 4);
	$result=MutationProcess::run(PhpRuntime::command(['-r','fwrite(STDOUT,"visible"); fwrite(STDERR,"diagnostic"); exit(3);']), $root, 10);

	$t->same(3, $result['exit_code']);
	$t->same('visible', $result['stdout']);
	$t->same('diagnostic', $result['stderr']);
	$t->isFalse($result['timed_out']);

	$typo=MutationProcess::run(PhpRuntime::command([$root.'/bin/dataphyre-mutate','plan','--limt=99']), $root, 10);
	$t->same(2, $typo['exit_code']);
	$t->contains('Unknown mutation option: --limt', $typo['stderr']);

	$timeout=MutationProcess::run(PhpRuntime::command(['-r','usleep(1500000);']), $root, 1);
	$t->same(124, $timeout['exit_code']);
	$t->isTrue($timeout['timed_out']);
});

test('CLI help operators recovery and plans emit machine-readable contracts', static function(Context $t): void {
	$workspace=$t->workspace('mutation-cli-contracts');
	$workspace->file('runtime/modules/sample/Framework/Rule.php', "<?php return \$left !== \$right and \$count <= 2 or TRUE;\n");
	$invoke=static function(array $arguments)use($workspace,$t): array {
		$capture=$t->captureOutput(static fn()=>MutationCli::main($arguments, $workspace->root()));
		return ['exit'=>$capture->result(),'output'=>$capture->output(),'json'=>$t->tryJsonArray($capture->output())];
	};

	$help=$invoke(['dataphyre-mutate']);
	$t->same(0, $help['exit']);
	$t->contains('Dataphyre mutation testing', $help['output']);
	$t->contains('--report=path', $help['output']);
	$t->contains('--dry-run', $help['output']);
	$t->contains('--skip-baseline', $help['output']);
	$t->contains('--json', $help['output']);
	$t->contains('Unknown options and positional arguments are rejected.', $help['output']);
	$t->contains('0=successful command or no actionable survivors', $help['output']);
	$t->contains('Under phpdbg, child commands resolve', $help['output']);

	$flagHelp=$invoke(['dataphyre-mutate','--help']);
	$t->same(0, $flagHelp['exit']);
	$t->contains('php bin/dataphyre-mutate plan', $flagHelp['output']);

	$reportPath=$workspace->path('reports/operators.json');
	$operators=$invoke(['dataphyre-mutate','operators','--report',$reportPath,'--json']);
	$t->same(0, $operators['exit']);
	$t->hasKey('strict_identity', $operators['json']['operators']);
	$t->same($operators['json'], json_decode((string)file_get_contents($reportPath), true));

	$recovery=$invoke(['dataphyre-mutate','recover']);
	$t->same(0, $recovery['exit']);
	$t->same(['recovered_file'=>null], $recovery['json']);

	$plan=$invoke([
		'dataphyre-mutate',
		'plan',
		'--path', 'runtime/modules/sample/Framework/Rule.php',
		'--operator=strict_identity,equality',
		'--operator', 'boundary',
		'--profile', 'permission-contract',
		'--limit', '3',
	]);
	$t->same(0, $plan['exit']);
	$t->same('dataphyre-mutation-plan-v1', $plan['json']['format']);
	$t->same(3, $plan['json']['total']);
	$t->same(['strict_identity','logical_connective','boundary'], array_column($plan['json']['mutants'], 'operator'));

	$dryRun=$invoke(['dataphyre-mutate','run','--dry-run','--path=runtime/modules/sample/Framework/Rule.php','--limit=1']);
	$t->same(0, $dryRun['exit']);
	$t->same('dataphyre-mutation-plan-v1', $dryRun['json']['format']);

	$private=$t->nonPublic(MutationCli::class);
	$t->same(['alpha','beta'], $private->invoke('many', 'alpha,beta,alpha'));
	$t->same(['strict_identity','boundary'], $private->invoke('csv', ['strict_identity', ' boundary ']));
	$t->throwsLike(static fn()=>$private->invoke('options', ['unexpected']), InvalidArgumentException::class, 'Unexpected mutation argument');
	$t->throwsLike(static fn()=>$private->invoke('options', ['--invented']), InvalidArgumentException::class, 'Unknown mutation option');
});

test('CLI run returns survivor status and restores the source it measures', static function(Context $t): void {
	$workspace=$t->workspace('mutation-cli-run');
	$source="<?php return \$left === \$right;\n";
	$file=$workspace->file('runtime/modules/sample/Framework/Rule.php', $source);
	$workspace->file('bin/dataphyre-test', "<?php exit(0);\n");

	$capture=$t->captureOutput(static fn()=>MutationCli::main([
			'dataphyre-mutate',
			'run',
			'--path=runtime/modules/sample/Framework/Rule.php',
			'--operator=strict_identity',
			'--limit=1',
			'--timeout=10',
			'--test-name=sample contract',
			'--skip-baseline',
		], $workspace->root()));
	$exit=$capture->result();
	$report=$t->jsonArray($capture->output());

	$t->same(1, $exit);
	$t->same(['killed'=>0,'survived'=>1,'timeout'=>0,'invalid'=>0,'error'=>0], $report['counts']);
	$t->same(0, $report['mutation_score']);
	$t->same($source, file_get_contents($file));
});

test('CLI rejects unknown profiles and commands with conventional usage status', static function(Context $t): void {
	$workspace=$t->workspace('mutation-cli-errors');
	$workspace->directory('runtime/modules');

	$t->same(2, MutationCli::main(['dataphyre-mutate','plan','--profile=invented'], $workspace->root()));
	$t->same(2, MutationCli::main(['dataphyre-mutate','launch'], $workspace->root()));
});
