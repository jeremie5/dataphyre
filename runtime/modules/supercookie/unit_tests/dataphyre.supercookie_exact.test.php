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
use Dataphyre\Test\Spy;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once __DIR__.'/supercookie_test_helpers.php';

/** Owns an in-memory aggregate cookie and captures every outbound cookie write. */
final class SupercookieScenario {
	private TestState $cookies;
	public Spy $writer;

	public function __construct(Context $test, bool $writeResult=true) {
		$this->cookies=$test->state('supercookie.cookies');
		$this->writer=$test->spy()->willReturn($writeResult);
	}

	/** @return array<string,mixed> */
	public function runtime(string $host='example.test:8443'): array {
		return [
			'read_cookie'=>fn(string $name): mixed=>$this->cookies->get($name),
			'mirror_cookie'=>fn(string $name, string $value): TestState=>$this->cookies->put($name, $value),
			'write_cookie'=>$this->writer,
			'clock'=>static fn(): int=>1_700_000_000,
			'host'=>$host,
		];
	}

	/** @param array<string,mixed>|string $payload */
	public function seed(array|string $payload): void {
		$this->cookies->put('__Secure-DATA', is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload);
	}

	/** @return array<string,mixed> */
	public function values(): array {
		$decoded=json_decode((string)$this->cookies->get('__Secure-DATA', '{}'), true);
		return is_array($decoded) ? $decoded : [];
	}
}

suite('Supercookie aggregate storage')
	->contract('supercookie.aggregate-storage', 1)
	->layer('unit')
	->risk('high')
	->watches('module:supercookie')
	->through('read', 'write', 'delete', 'domain-normalization', 'transport-failure')
	->isolation('case')
	->tag('supercookie', 'exact-coverage')
	->group('framework-coverage');

test('dialbacks can replace each aggregate operation without touching cookie state', static function(Context $t): void {
	\dataphyre\core::register_dialback('CALL_SUPERCOOKIE_DEL', static fn(string $name): bool=>$name==='remove');
	\dataphyre\core::register_dialback('CALL_SUPERCOOKIE_GET', static fn(string $name): string=>'dialback:'.$name);
	\dataphyre\core::register_dialback('CALL_SUPERCOOKIE_SET', static fn(string $name, mixed $value): bool=>$name==='allowed' && $value===1);
	$t->isTrue(\dataphyre\supercookie::del('remove'));
	$t->same('dialback:value', \dataphyre\supercookie::get('value'));
	$t->isTrue(\dataphyre\supercookie::set('allowed', 1));
});

test('logical values round trip through one normalized secure cookie', static function(Context $t): void {
	$scenario=new SupercookieScenario($t);
	$t->isTrue(\dataphyre\supercookie::set('theme', 'dark', $scenario->runtime('shop.example.test:8443')));
	$t->same('dark', \dataphyre\supercookie::get('theme', $scenario->runtime()));
	$t->same(null, \dataphyre\supercookie::get('missing', $scenario->runtime()));
	$t->same(['theme'=>'dark'], $scenario->values());
	$scenario->writer->assertCalledWith($t, [
		'__Secure-DATA',
		'{"theme":"dark"}',
		1_702_592_000,
		'/',
		'shop.example.test',
		true,
		true,
	]);

	$t->isFalse(\dataphyre\supercookie::set('bad name', 'value', $scenario->runtime()));
	$scenario->seed('malformed-json');
	$t->isTrue(\dataphyre\supercookie::set('recovered', 1, $scenario->runtime('bad=host.test:443')));
	$t->same(['recovered'=>1], $scenario->values());
});

test('deletion rewrites existing aggregates and reports absent or failed transports', static function(Context $t): void {
	$scenario=new SupercookieScenario($t);
	$scenario->seed(['keep'=>1, 'remove'=>2]);
	$t->isTrue(\dataphyre\supercookie::del('remove', $scenario->runtime()));
	$t->same(['keep'=>1], $scenario->values());
	$t->isTrue(\dataphyre\supercookie::del('missing', $scenario->runtime()));

	$absent=new SupercookieScenario($t);
	$t->isFalse(\dataphyre\supercookie::del('missing', $absent->runtime()));
	$t->same(null, \dataphyre\supercookie::get('missing', $absent->runtime()));

	$failed=new SupercookieScenario($t, false);
	$failed->seed(['remove'=>true]);
	$t->isFalse(\dataphyre\supercookie::del('remove', $failed->runtime()));
	$t->isFalse(\dataphyre\supercookie::set('value', true, $failed->runtime()));
});

test('default request mirroring remains compatible with the PHP cookie superglobal', static function(Context $t): void {
	$cookies=$t->globalMap('_COOKIE')->clear();
	$writer=$t->spy()->willReturn(true);
	$t->isTrue(\dataphyre\supercookie::set('locale', 'en-CA', [
		'write_cookie'=>$writer,
		'clock'=>static fn(): int=>1_700_000_000,
		'host'=>'example.test',
	]));
	$t->same('{"locale":"en-CA"}', $cookies->get('__Secure-DATA'));
	$t->same('en-CA', \dataphyre\supercookie::get('locale'));
});
