<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Database\TableDefinition;
use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

if(!defined('DATAPHYRE_FIREWALL_DIAGNOSTIC_NO_DISPATCH')){
	define('DATAPHYRE_FIREWALL_DIAGNOSTIC_NO_DISPATCH', true);
}
require_once dirname(__DIR__, 2).'/dpanel/tooling/WorkerFixtureState.php';
require_once __DIR__.'/firewall_test_helpers.php';
require_once dirname(__DIR__).'/kernel/firewall.diagnostic.php';
require_once dirname(__DIR__, 2).'/sql/Framework/TableDefinition.php';

suite('Firewall exact boundaries')
	->contract('firewall.exact-boundaries', 1)
	->layer('integration')
	->risk('critical')
	->watches('module:firewall')
	->through('bootstrap', 'throttling', 'captcha-cache', 'diagnostics', 'schema')
	->isolation('case')
	->tag('firewall', 'exact-coverage')
	->group('framework-coverage');

test('bootstrap delegates diagnostic and request phases through named callbacks', static function(Context $t): void {
	$diagnostic=$t->spy();
	$flooding=$t->spy();
	$captcha=$t->spy();
	\dataphyre\firewall::bootstrap('diagnostic', $diagnostic, $flooding, $captcha);
	$diagnostic->assertCalledTimes($t, 1);
	$flooding->assertCalledTimes($t, 0);
	\dataphyre\firewall::bootstrap('request', $diagnostic, $flooding, $captcha);
	$flooding->assertCalledTimes($t, 1);
	$captcha->assertCalledTimes($t, 1);
});

test('flooding checks describe throttle captcha quiet and bounded-history outcomes without sleeping', static function(Context $t): void {
	$session=$t->globalMap('_SESSION');
	$sleep=$t->spy();
	$captcha=$t->spy();
	$config=['min_time'=>1000, 'action'=>'throttle', 'throttle_time'=>'2 seconds'];
	$session->put('last_requests', [99.9, 99.8, 99.7, 99.6]);
	\dataphyre\firewall::flooding_check($config, static fn(): float=>100.0, $sleep, $captcha);
	$sleep->assertCalledWith($t, [2]);

	$session->put('last_requests', [99.9, 99.8, 99.7, 99.6]);
	\dataphyre\firewall::flooding_check([...$config, 'action'=>'captcha'], static fn(): float=>100.0, $sleep, $captcha);
	$captcha->assertCalledWith($t, ['request_flooding']);

	$session->put('last_requests', [1.0]);
	\dataphyre\firewall::flooding_check($config, static fn(): float=>100.0, $sleep, $captcha);
	$session->put('last_requests', []);
	\dataphyre\firewall::flooding_check($config, static fn(): float=>100.0, $sleep, $captcha);
	$t->same([100.0], $session->get('last_requests'));

	$session->put('last_requests', array_fill(0, 14, 1.0));
	\dataphyre\firewall::flooding_check([...$config, 'min_time'=>1], static fn(): float=>100.0, $sleep, $captcha);
	$t->same(10, count($session->get('last_requests')));
	\dataphyre\firewall::flooding_check(['min_time'=>0, 'action'=>'throttle', 'throttle_time'=>'1 second'], static fn(): float=>100.0, $sleep, $captcha);
});

test('captcha cache paths unblock detect redirect and create visitor blocks', static function(Context $t): void {
	if(!class_exists(\dataphyre\cache::class)){
		$t->defineSymbols(<<<'PHP'
namespace dataphyre;
final class cache {
	public static bool $started=true;
	private static array $values=[];
	public static function get(string $key): mixed { return self::$values[$key] ?? null; }
	public static function set(string $key, mixed $value, int $expiry=0): bool { self::$values[$key]=$value; return true; }
	public static function delete(string $key): bool { unset(self::$values[$key]); return true; }
	public static function reset(): void { self::$values=[]; self::$started=true; }
}
PHP);
	}
	\dataphyre\cache::reset();
	$server=$t->globalMap('_SERVER')->merge(['REMOTE_ADDR'=>'203.0.113.40', 'REQUEST_URI'=>'/checkout']);
	$session=$t->globalMap('_SESSION');
	$session->merge(['captcha_unblock'=>true, 'captcha_blocked'=>true, 'last_requests'=>[1.0]]);
	\dataphyre\cache::set('fwcb'.md5('203.0.113.40'), 'blocked');
	\dataphyre\firewall::captcha();
	$t->isFalse($session->has('captcha_unblock'));
	$t->same(null, \dataphyre\cache::get('fwcb'.md5('203.0.113.40')));

	$redirect=$t->spy();
	\dataphyre\cache::set('fwcb'.md5('203.0.113.40'), 'blocked');
	$t->isTrue(\dataphyre\firewall::check_if_captcha_blocked($redirect));
	$redirect->assertCalledWith($t, ['/captcha?redir='.base64_encode('checkout')]);

	$session->clear();
	\dataphyre\cache::$started=false;
	$t->isFalse(\dataphyre\firewall::check_if_captcha_blocked($redirect));
	\dataphyre\cache::$started=true;
	$server->put('REQUEST_URI', '/security/captcha');
	$t->isTrue(\dataphyre\firewall::captcha_block_user('manual_review'));
	$t->same('manual_review', \dataphyre\cache::get('fwcb'.md5('203.0.113.40')));

	$session->clear();
	\dataphyre\cache::delete('fwcb'.md5('203.0.113.40'));
	\dataphyre\firewall::captcha();
});

test('diagnostics report host failures and publish all SQL dialect schemas', static function(Context $t): void {
	$required=$t->spy();
	$publish=$t->spy();
	$findings=\dataphyre\firewall\diagnostic::tests([
		'module_required'=>$required,
		'extension_loaded'=>static fn(string $extension): bool=>$extension!=='filter',
		'php_version'=>'8.0.30',
		'clock'=>static fn(): int=>1_700_000_000,
		'sql_query'=>null,
		'publish'=>$publish,
	]);
	$t->contains('PHP version 8.1.0 or higher is required.', array_column($findings, 'error'));
	$t->contains("PHP extension 'filter' is not loaded.", array_column($findings, 'error'));
	$t->same('warning', $findings[2]['level']);
	$required->assertCalledTimes($t, 2);
	$publish->assertCalledTimes($t, 1);

	$query=$t->spy();
	$t->same([], \dataphyre\firewall\diagnostic::tests([
		'module_required'=>static fn(): bool=>true,
		'extension_loaded'=>static fn(): bool=>true,
		'php_version'=>'8.4.0',
		'sql_query'=>$query,
		'publish'=>null,
	]));
	$schemas=$query->lastCall()[0];
	$t->hasKeys(['mysql','postgresql','sqlite'], $schemas);
	$t->contains('captcha_blocks', $schemas['sqlite']);
	$t->greaterThan(0, count(\dataphyre\firewall\diagnostic::tests()));
});

test('captcha block table manifest publishes expiry and address indexes', static function(Context $t): void {
	$manifest=require dirname(__DIR__).'/kernel/firewall.tables.php';
	$t->hasKey('captcha_blocks', $manifest);
	$definition=$manifest['captcha_blocks']('dataphyre.captcha_blocks');
	$t->instanceOf(TableDefinition::class, $definition);
	$t->same(['id'], $definition->primaryColumns());
});
