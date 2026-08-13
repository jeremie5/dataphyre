<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\CoverageLineNormalizer;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/tooling/CoverageLineNormalizer.php';

suite('Exact coverage structural-line normalization')
	->tag('testing', 'coverage', 'phpdbg')
	->group('framework-coverage')
	->contract('testing.coverage.structural-lines', 1)
	->layer('unit')
	->risk('critical')
	->watches('module:testing', 'path:runtime/modules/testing/tooling/CoverageLineNormalizer.php')
	->through('token classification', 'line-map normalization', 'audit metadata')
	->isolation('process');

test('phpdbg ignores only branch-free token-only structural locations', static function(Context $t): void {
	$structural=[
		'{'=>'brace-only',
		'}'=>'brace-only',
		'} finally {'=>'finally-header',
		'finally{'=>'finally-header',
		'public function skip(string $reason=""): never;'=>'declaration-only-method',
		'abstract protected function execute(): void;'=>'declaration-only-method',
	];
	foreach($structural as $line=>$reason){
		$t->same($reason, CoverageLineNormalizer::structuralReason($line), $line);
	}

	$executable=[
		'',
		'// a comment',
		'};',
		"case 'ready':",
		'case"sqlite":',
		'case -12.5:',
		'case true:',
		'default:',
		'case Contract::READY:',
		'case resolve_state():',
		"case 'ready': return true;",
		'default: return false;',
		'finally { release_lock(); }',
		'$callback=function(): void {};',
		'catch (Throwable $error) {',
		'else {',
		'break;',
		'return;',
	];
	foreach($executable as $line){
		$t->same(null, CoverageLineNormalizer::structuralReason($line), $line==='' ? 'blank line' : $line);
	}
});

test('normalization preserves raw evidence and explains every removed line', static function(Context $t): void {
	$workspace=$t->workspace('coverage-line-normalizer');
	$file=$workspace->file('SwitchFixture.php', <<<'PHP'
<?php
case 'one':
return 1;
default:
}
};
} finally {
return 2;
// comment
PHP);
	$result=CoverageLineNormalizer::phpdbg(
		$file,
		[0, -1, 2, 2, 3, 4, 5, 6, 7, 8, 9, 99],
		[3, 7, 8, 99, 100]
	);

	$t->same([2,3,4,5,6,7,8,9,99], $result['raw_executable_lines']);
	$t->same([3,4,6,8,9,99], $result['executable_lines']);
	$t->same([3,8,99], $result['covered_lines']);
	$t->same([2,5,7], $result['ignored_lines']);
	$t->same([
		'brace-only'=>[5],
		'covered-switch-label'=>[2],
		'finally-header'=>[7],
	], $result['ignored_by_reason']);

	$missing=CoverageLineNormalizer::phpdbg($workspace->path('missing.php'), [3,1,3], [3,8]);
	$t->same([1,3], $missing['raw_executable_lines']);
	$t->same([1,3], $missing['executable_lines']);
	$t->same([3], $missing['covered_lines']);
	$t->same([], $missing['ignored_lines']);
	$t->same([], $missing['ignored_by_reason']);
});

test('switch normalization treats alias chains as one arm while retaining untested bodies',static function(Context $t): void {
	$workspace=$t->workspace('coverage-switch-evidence');
	$file=$workspace->file('SwitchEvidence.php',<<<'PHP'
<?php
switch($value){
	case 'grouped-a':
	case 'grouped-b':
		$grouped=true;
		break;
	case 'untested':
		$untested=true;
		break;
	default:
		$fallback=true;
}
PHP);
	$result=CoverageLineNormalizer::phpdbg($file,[2,3,4,5,6,7,8,9,10,11,12],[2,5,6,11]);
	$t->notContains(3,$result['executable_lines'],'A consecutive alias label shares the covered grouped arm.');
	$t->notContains(4,$result['executable_lines'],'Covered grouped arm label is a phpdbg artifact.');
	$t->contains(7,$result['executable_lines'],'Untested arm label remains certifying evidence.');
	$t->notContains(10,$result['executable_lines'],'Covered default arm label is a phpdbg artifact.');
	$t->same([3,4,10,12],$result['ignored_lines']);
	$t->same([3,4,10],$result['ignored_by_reason']['covered-switch-label']);
	$t->same([12],$result['ignored_by_reason']['brace-only']);

	$unterminated=$workspace->file('UnterminatedSwitchLabel.php',"<?php\ncase 'unfinished':");
	$unterminatedResult=CoverageLineNormalizer::phpdbg($unterminated,[2],[]);
	$t->same([2],$unterminatedResult['executable_lines']);
	$t->same([],$unterminatedResult['ignored_lines']);
});

test('normalization recognizes only branch-free arguments inside a call proven to run',static function(Context $t): void {
	$workspace=$t->workspace('coverage-line-normalizer-arguments');
	$file=$workspace->file('ArgumentFixture.php', <<<'PHP'
<?php
$record=create(make([
	'id'=>$id,
],$now));
$result=success(
	$name,
	$encoded,
);
$last=success(
	$covered,
	$sanitationOptions
);
$conditional=$enabled ? success(
	$untaken,
) : fallback();
PHP);
	$result=CoverageLineNormalizer::phpdbg($file,range(2,15),[2,3,5,6,8,9,10,12,13,15]);

	$t->same([4,7,11],$result['ignored_lines']);
	$t->same([4,7,11],$result['ignored_by_reason']['covered-simple-argument-continuation']);
	$t->contains(14,$result['executable_lines'],'An unentered conditional call remains exact-coverage evidence.');
	$t->notContains(14,$result['covered_lines']);
});
