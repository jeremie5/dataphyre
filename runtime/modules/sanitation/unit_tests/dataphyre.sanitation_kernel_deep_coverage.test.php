<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

namespace dataphyre {
	final class core{
		public static function dialback(string $name, mixed ...$arguments): mixed {
			$value=\Dataphyre\Test\TestState::channel('sanitation.kernel')->get($name);
			return is_callable($value) ? $value(...$arguments) : $value;
		}
	}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}

	$sanitation_kernel_runtime=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\');
	require_once $sanitation_kernel_runtime.'/modules/sanitation/kernel/sanitation.main.php';

	test('sanitation kernel covers masks dialbacks signatures and scalar conversion', static function(Context $t): void {
		$state=$t->state('sanitation.kernel');
		$private=$t->nonPublic(\dataphyre\sanitation::class);
		$state->put('CALL_SANITATION_ANONYMIZE_EMAIL','masked-by-dialback');
		$t->same('masked-by-dialback', \dataphyre\sanitation::anonymize_email('person@example.test'));
		$state->forget('CALL_SANITATION_ANONYMIZE_EMAIL');
		$t->same('', \dataphyre\sanitation::anonymize_email('   '));
		$t->same('', \dataphyre\sanitation::anonymize_email('not-an-email'));
		$t->same('', \dataphyre\sanitation::anonymize_email('@example.test'));
		$t->same('person@example.test', \dataphyre\sanitation::anonymize_email(' person@example.test ', 99));
		$t->same('######@example.test', \dataphyre\sanitation::anonymize_email('person@example.test', -5, '#'));

		$state->put('CALL_SANITATION_SANITIZE',static fn(mixed $value): string=>'dialback:'.(string)$value);
		$t->same('dialback:value', \dataphyre\sanitation::sanitize('value'));
		$state->forget('CALL_SANITATION_SANITIZE');

		$t->same(false, \dataphyre\sanitation::sanitize(['unsupported']));
		$t->same('  value  ', \dataphyre\sanitation::sanitize('  value  ', false, false, ''));
		$t->same('<b>value</b>', \dataphyre\sanitation::sanitize('<b>value</b>', true, false, ' default '));
		$t->same('&lt;b&gt;value&lt;/b&gt;', \dataphyre\sanitation::sanitize('<b>value</b>', 'default', true));
		$t->same('<b>value</b>', \dataphyre\sanitation::sanitize('<b>value</b>', 'default', false));
		$t->same('17', \dataphyre\sanitation::sanitize(17));
		$t->same('2.5', \dataphyre\sanitation::sanitize(2.5));
		$t->same('1', \dataphyre\sanitation::sanitize(true));
		$t->same('0', \dataphyre\sanitation::sanitize(false));
		$t->same('stringable', \dataphyre\sanitation::sanitize(new class implements Stringable{
			public function __toString(): string { return 'stringable'; }
		}));

		$t->same(['datatype'=>'integer', 'trim'=>false, 'escape_html'=>false], $private->invoke('resolve_sanitize_signature', false, false, 'integer'));
		$t->same(['datatype'=>'default', 'trim'=>true, 'escape_html'=>true], $private->invoke('resolve_sanitize_signature', true, null, ''));
		$t->same(['datatype'=>'default', 'trim'=>true, 'escape_html'=>false], $private->invoke('resolve_sanitize_signature', 42, false, null));
		$t->same(['datatype'=>'default', 'trim'=>true, 'escape_html'=>true], $private->invoke('resolve_sanitize_signature', ' ', null, null));

		$t->same('raw', $private->invoke('stringify_input', 'raw'));
		$t->same('8', $private->invoke('stringify_input', 8));
		$t->same('1.25', $private->invoke('stringify_input', 1.25));
		$t->same('1', $private->invoke('stringify_input', true));
		$t->same('0', $private->invoke('stringify_input', false));
		$t->same('object', $private->invoke('stringify_input', new class implements Stringable{
			public function __toString(): string { return 'object'; }
		}));
		$t->same(null, $private->invoke('stringify_input', new stdClass()));
	})->tag('sanitation', 'kernel', 'coverage')->group('framework-coverage');

	test('sanitation kernel covers every public datatype success failure and alias', static function(Context $t): void {
		$t->state('sanitation.kernel');
		$private=$t->nonPublic(\dataphyre\sanitation::class);
		$aliases=[
			' phone '=>'phone_number',
			'tel'=>'phone_number',
			'name'=>'person_name',
			'int'=>'integer',
			'bool'=>'boolean',
			'postal'=>'postal_code',
			'text'=>'default',
			'html'=>'basic_html',
			''=>'default',
			'Custom'=>'custom',
		];
		foreach($aliases as $raw=>$expected){
			$t->same($expected, $private->invoke('normalize_datatype', $raw));
		}

		$emptyTypes=['url', 'phone', 'email', 'numeric', 'integer', 'float', 'boolean', 'slug', 'username', 'postal_code', 'alphanumeric', 'text_nospecial', 'person_name', 'ascii'];
		foreach($emptyTypes as $datatype){
			$t->same('', \dataphyre\sanitation::sanitize('', $datatype));
		}

		$validCases=[
			['https://example.test/path?q=1', 'url', 'https://example.test/path?q=1'],
			['+1 (514) 555-0100', 'tel', '+1 (514) 555-0100'],
			['person@example.test', 'email', 'person@example.test'],
			['12345', 'numeric', '12345'],
			['-42', 'int', '-42'],
			['-.5', 'float', '-.5'],
			['YES', 'bool', '1'],
			['off', 'boolean', '0'],
			['Hello World', 'slug', 'hello-world'],
			['user.name_1', 'username', 'user.name_1'],
			[' h2x 1y4 ', 'postal', 'H2X 1Y4'],
			['A-b 1!', 'alphanumeric', 'Ab1'],
			['Keep, prose! #safe', 'text_nospecial', 'Keep, prose safe'],
			["anne-marie o'connor", 'name', "Anne-Marie O'Connor"],
			['Creme', 'ascii', 'Creme'],
		];
		foreach($validCases as [$value, $datatype, $expected]){
			$t->same($expected, \dataphyre\sanitation::sanitize($value, $datatype, false));
		}

		$invalidCases=[
			['not a url', 'url'],
			['https://example.test/%3Cscript%3E', 'url'],
			['letters', 'phone'],
			['not-an-email', 'email'],
			['12.5', 'numeric'],
			['1.5', 'integer'],
			['1.2.3', 'float'],
			['perhaps', 'boolean'],
			['---', 'slug'],
			['bad user', 'username'],
			['!', 'postal_code'],
		];
		foreach($invalidCases as [$value, $datatype]){
			$t->same(false, \dataphyre\sanitation::sanitize($value, $datatype));
		}

		$t->same('<script>& raw', \dataphyre\sanitation::sanitize('<script>& raw', 'unrestricted'));
		$basic=\dataphyre\sanitation::sanitize('<script>x</script><b onclick="go()">safe</b><x:item>bad</x:item>', 'html');
		$t->isTrue(is_string($basic));
		$t->isFalse(str_contains((string)$basic, '<script'));
		$t->isFalse(str_contains((string)$basic, 'onclick'));
		$t->contains('<b', (string)$basic);
		$t->same('&lt;unknown&gt;', \dataphyre\sanitation::sanitize('<unknown>', 'not-a-real-datatype'));

		$t->same(['name'=>'Ada'], \dataphyre\sanitation::sanitize_many(['name'=>' Ada ', 'email'=>'bad'], ['name'=>['not-a-type'], 'email'=>'email']));
		$t->same(['email'=>false, 'missing'=>false], \dataphyre\sanitation::sanitize_many(['email'=>'bad'], ['email'=>'email', 'missing'=>'default'], true));
	})->tag('sanitation', 'kernel', 'coverage')->group('framework-coverage');

	test('sanitation kernel protected helpers cover encoded vectors empties and fallbacks', static function(Context $t): void {
		$t->state('sanitation.kernel');
		$private=$t->nonPublic(\dataphyre\sanitation::class);
		$t->same('', $private->invoke('sanitize_url', ''));
		$t->same('https://example.test', $private->invoke('sanitize_url', 'https://example.test'));
		$t->same(false, $private->invoke('sanitize_url', 'https://example.test/\\x3cscript>'));
		$t->same(false, $private->invoke('sanitize_url', 'https://example.test/&lt;script&gt;'));
		$t->same(false, $private->invoke('sanitize_url', 'invalid'));

		$emptyHelpers=[
			'sanitize_phone_number', 'sanitize_email', 'sanitize_numeric', 'sanitize_integer',
			'sanitize_float', 'sanitize_boolean', 'sanitize_slug', 'sanitize_username',
			'sanitize_postal_code', 'sanitize_alphanumeric', 'sanitize_text_nospecial',
			'sanitize_person_name', 'ascii_fold',
		];
		foreach($emptyHelpers as $method){
			$t->same('', $private->invoke($method, ''));
		}

		$t->same('+1 / 514', $private->invoke('sanitize_phone_number', '+1 / 514'));
		$t->same(false, $private->invoke('sanitize_phone_number', 'call-me'));
		$t->same('person@example.test', $private->invoke('sanitize_email', 'person@example.test'));
		$t->same(false, $private->invoke('sanitize_email', 'bad'));
		$t->same('123', $private->invoke('sanitize_numeric', '123'));
		$t->same(false, $private->invoke('sanitize_numeric', '-1'));
		$t->same('-1', $private->invoke('sanitize_integer', '-1'));
		$t->same(false, $private->invoke('sanitize_integer', '+1'));
		$t->same('-0.5', $private->invoke('sanitize_float', '-0.5'));
		$t->same(false, $private->invoke('sanitize_float', '.'));
		foreach(['1', 'true', 'yes', 'on'] as $truthy){
			$t->same('1', $private->invoke('sanitize_boolean', $truthy));
		}
		foreach(['0', 'false', 'no', 'off'] as $falsey){
			$t->same('0', $private->invoke('sanitize_boolean', $falsey));
		}
		$t->same(false, $private->invoke('sanitize_boolean', 'unknown'));
		$t->same('hello-world', $private->invoke('sanitize_slug', 'Hello World'));
		$t->same(false, $private->invoke('sanitize_slug', '---'));
		$t->same('valid_user', $private->invoke('sanitize_username', 'valid_user'));
		$t->same(false, $private->invoke('sanitize_username', str_repeat('a', 65)));
		$t->same('H2X 1Y4', $private->invoke('sanitize_postal_code', ' h2x 1y4 '));
		$t->same(false, $private->invoke('sanitize_postal_code', 'A'));
		$t->same('Ab12', $private->invoke('sanitize_alphanumeric', 'A-b 1_2'));
		$t->same("Words, apostrophe's.", $private->invoke('sanitize_text_nospecial', "Words, apostrophe's.!@"));
		$t->same('', $private->invoke('sanitize_person_name', '123!'));
		$t->same('Anne--Marie', $private->invoke('sanitize_person_name', 'anne--marie'));
		$t->same("O'Connor", $private->invoke('sanitize_person_name', "o'connor"));
		$t->same("D’Angelo", $private->invoke('sanitize_person_name', "d’angelo"));

		$cleaned=$private->invoke('clean_encoded_vectors', '&amp; &lt;b&gt; &# 60; &#x3C');
		$t->isTrue(is_string($cleaned));
		$t->contains('&amp;', $cleaned);
		$filtered=$private->invoke('sanitize_basic_html', '<a href="javascript:go" style="-moz-binding:x" xmlns:x="x">a</a><img src="vbscript:x"><object>x</object><x:item>x</x:item>');
		$t->isFalse(str_contains($filtered, 'javascript:'));
		$t->isFalse(str_contains($filtered, 'vbscript:'));
		$t->isFalse(str_contains($filtered, '-moz-binding:'));
		$t->isFalse(str_contains($filtered, '<object'));
		$t->isFalse(str_contains($filtered, '<x:item'));

		$t->notEmpty($private->invoke('ascii_fold', 'Crème brûlée'));
		$invalidUtf8="\xFF";
		$t->same($invalidUtf8, $private->invoke('ascii_fold', $invalidUtf8));
	})->tag('sanitation', 'kernel', 'coverage')->group('framework-coverage');
}
