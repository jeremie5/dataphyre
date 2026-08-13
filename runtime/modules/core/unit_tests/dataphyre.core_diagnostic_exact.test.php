<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use Dataphyre\Test\TestState;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/core.diagnostic.php';

/** Describes a host snapshot without mutating process constants, INI, or sessions. */
final class CoreDiagnosticScenario {
	private TestState $state;

	public function __construct(Context $test) {
		$this->state=$test->state('core.diagnostic', ['published'=>[]]);
	}

	/** @return array<string,mixed> */
	public function pre(array $overrides=[]): array {
		return [
			'server'=>['HTTPS'=>'on', 'REMOTE_ADDR'=>'192.0.2.10'],
			'rootpaths_defined'=>true,
			'rootpaths'=>['dataphyre'=>'/framework'],
			'php_version'=>'8.4.0',
			'extension_loaded'=>static fn(string $extension): bool=>true,
			'clock'=>static fn(): int=>1_700_000_000,
			'publish'=>fn(array $findings): TestState=>$this->state->append('published', $findings),
			...$overrides,
		];
	}

	/** @param array<string,mixed> $constants @return array<string,mixed> */
	public function post(array $constants, array $overrides=[]): array {
		return [
			'constant_defined'=>static fn(string $name): bool=>array_key_exists($name, $constants),
			'constant_value'=>static fn(string $name): mixed=>$constants[$name],
			'config'=>['timezone'=>'UTC', 'max_execution_memory'=>'64M', 'max_execution_time'=>30],
			'session_status'=>static fn(): int=>PHP_SESSION_NONE,
			'timezone'=>'UTC',
			'ini_get'=>static fn(string $name): string=>match($name){
				'memory_limit'=>'64M',
				'max_execution_time'=>'30',
			},
			'clock'=>static fn(): int=>1_700_000_000,
			'publish'=>fn(array $findings): TestState=>$this->state->append('published', $findings),
			...$overrides,
		];
	}

	/** @return list<mixed> */
	public function publications(): array {
		return $this->state->get('published', []);
	}
}

suite('Core diagnostics')
	->contract('core.diagnostic-observations', 1)
	->layer('unit')
	->risk('high')
	->watches('module:core')
	->through('connection', 'runtime-prerequisites', 'request-state', 'publication')
	->isolation('case')
	->tag('core', 'diagnostic', 'exact-coverage')
	->group('framework-coverage');

test('preflight findings explain every connection and host prerequisite state', static function(Context $t): void {
	$scenario=new CoreDiagnosticScenario($t);
	$encryptedProxy=\dataphyre\core\diagnostic::pre_tests($scenario->pre([
		'server'=>['HTTP_X_FORWARDED_PROTO'=>'https'],
	]));
	$t->contains('load balancer', $encryptedProxy[0]['info']);
	$t->contains('is encrypted', $encryptedProxy[1]['info']);

	$plainProxy=\dataphyre\core\diagnostic::pre_tests($scenario->pre([
		'server'=>['HTTP_X_FORWARDED_PROTO'=>'http'],
	]));
	$t->contains('not encrypted', $plainProxy[1]['info']);

	$directPlain=\dataphyre\core\diagnostic::pre_tests($scenario->pre([
		'server'=>[],
		'rootpaths_defined'=>false,
		'rootpaths'=>null,
		'php_version'=>'8.0.30',
		'extension_loaded'=>static fn(string $extension): bool=>$extension!=='sockets',
	]));
	$t->contains('without https', $directPlain[0]['info']);
	$t->contains('Rootpaths are not defined.', array_column($directPlain, 'error'));
	$t->contains('PHP version 8.1.0 or higher is required.', array_column($directPlain, 'error'));
	$t->contains("PHP extension 'sockets' is not loaded.", array_column($directPlain, 'error'));

	$unknownAddress=\dataphyre\core\diagnostic::pre_tests($scenario->pre(['server'=>['HTTPS'=>'on']]));
	$t->contains('(unknown)', $unknownAddress[0]['info']);
	$t->greaterThanOrEqual(4, count($scenario->publications()));
});

test('postflight findings distinguish absent constants unsafe sessions and mismatched limits', static function(Context $t): void {
	$scenario=new CoreDiagnosticScenario($t);
	$missing=\dataphyre\core\diagnostic::post_tests($scenario->post([], ['config'=>null]));
	$t->contains('Constant RUN_MODE constant is not defined.', array_column($missing, 'error'));
	$t->contains('Constant REQUEST_IP_ADDRESS is undefined or empty.', array_column($missing, 'error'));
	$t->contains('Constant REQUEST_USER_AGENT is undefined or empty.', array_column($missing, 'error'));
	$t->same('warning', $missing[3]['level']);

	$mismatched=\dataphyre\core\diagnostic::post_tests($scenario->post([
		'RUN_MODE'=>'diagnostic',
		'REQUEST_IP_ADDRESS'=>'192.0.2.10',
		'REQUEST_USER_AGENT'=>'Unit Browser',
	], [
		'session_status'=>static fn(): int=>PHP_SESSION_ACTIVE,
		'timezone'=>'America/Toronto',
		'ini_get'=>static fn(string $name): string=>'host-value',
	]));
	$errors=array_column($mismatched, 'error');
	$t->contains('Session was started in diagnostic run mode.', $errors);
	$t->contains('Timezone is not set according to dataphyre configuration.', $errors);
	$t->contains('Memory limit is not set according to configuration.', $errors);
	$t->contains('Max execution time is not set according to configuration.', $errors);

	$healthy=\dataphyre\core\diagnostic::post_tests($scenario->post([
		'RUN_MODE'=>'request',
		'REQUEST_IP_ADDRESS'=>'192.0.2.10',
		'REQUEST_USER_AGENT'=>'Unit Browser',
	]));
	$t->same([], $healthy);
	$t->same([], \dataphyre\core\diagnostic::post_tests($scenario->post([
		'RUN_MODE'=>'request',
		'REQUEST_IP_ADDRESS'=>'192.0.2.10',
		'REQUEST_USER_AGENT'=>'Unit Browser',
	], ['publish'=>null])));
});

test('default observation and dpanel publication paths remain valid for embedded callers', static function(Context $t): void {
	if(!class_exists(\dataphyre\dpanel::class)){
		$t->defineSymbols('namespace dataphyre; final class dpanel { public static function add_verbose(array $findings): void {} }');
	}
	$t->greaterThan(0, count(\dataphyre\core\diagnostic::pre_tests()));
	$t->isTrue(is_array(\dataphyre\core\diagnostic::post_tests()));
});
