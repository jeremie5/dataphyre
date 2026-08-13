<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__).'/kernel/language_additions.php';

suite('Core language additions')
	->contract('core.language-additions', 1)
	->layer('unit')
	->risk('medium')
	->watches('module:core')
	->through('json-statuses', 'runtime-detection', 'identifiers', 'arrays', 'filesystem-copy')
	->isolation('case')
	->tag('core', 'language', 'exact-coverage')
	->group('framework-coverage');

test('JSON validation names every native decoder failure and unknown future statuses', static function(Context $t): void {
	$t->same(true, validate_json('{"ready":true}'));
	$t->same('Maximum stack depth exceeded', validate_json(str_repeat('[', 513).str_repeat(']', 513)));
	$t->same('Underflow or the modes mismatch', validate_json('[1}'));
	$t->same('Unexpected control character found', validate_json("\"line\x01break\""));
	$t->same('Syntax error, malformed JSON', validate_json('{broken'));
	$t->same('Malformed UTF-8 characters, possibly incorrectly encoded', validate_json("\"\xB1\x31\""));
	$t->same('Unknown error', json_validation_result(PHP_INT_MAX));
});

test('date CLI and identifier helpers expose deterministic contracts', static function(Context $t): void {
	$previousTimezone=date_default_timezone_get();
	date_default_timezone_set('UTC');
	$t->defer(static fn()=>date_default_timezone_set($previousTimezone));
	$t->same('1970-01-01 00:00:00', current_datetime(0));
	$t->matches('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', current_datetime());

	$web=['REMOTE_ADDR'=>'192.0.2.10', 'HTTP_USER_AGENT'=>'browser', 'REQUEST_METHOD'=>'GET', 'argv'=>[]];
	$t->isTrue(is_cli(['stdin'=>true, 'sapi'=>'fpm-fcgi', 'environment'=>[], 'server'=>$web]));
	$t->isTrue(is_cli(['stdin'=>false, 'sapi'=>'cli', 'environment'=>[], 'server'=>$web]));
	$t->isTrue(is_cli(['stdin'=>false, 'sapi'=>'fpm-fcgi', 'environment'=>['SHELL'=>'/bin/sh'], 'server'=>$web]));
	$t->isTrue(is_cli(['stdin'=>false, 'sapi'=>'fpm-fcgi', 'environment'=>[], 'server'=>['argv'=>['script.php'], 'REQUEST_METHOD'=>'GET']]));
	$t->isTrue(is_cli(['stdin'=>false, 'sapi'=>'fpm-fcgi', 'environment'=>[], 'server'=>['REMOTE_ADDR'=>'192.0.2.10']]));
	$t->isFalse(is_cli(['stdin'=>false, 'sapi'=>'fpm-fcgi', 'environment'=>[], 'server'=>$web]));
	$t->isTrue(is_cli());

	$identifier=uuid();
	$t->isTrue(is_uuid($identifier));
	$t->isFalse(is_uuid('not-a-uuid'));
	$t->isTrue(is_base64('ZGF0YQ=='));
	$t->isFalse(is_base64('not base64'));
	$t->isFalse(is_base64('='));
	$t->isFalse(is_base64('ZE=='));
	$t->isTrue(is_timestamp(0));
});

test('string and array helpers describe preservation truncation and invalid-input behavior', static function(Context $t): void {
	$t->same([1=>'changed', 3=>'changed'], array_replace_values([1=>'old', 2=>'kept', 3=>'old'], 'old', 'changed'));
	$t->same([], array_replace_values([], 'old', 'changed'));
	$t->same(['field2'=>'a', 'field3'=>'b'], prefix_array_keys(['a', 'b'], 'field', 2));
	$t->same([], prefix_array_keys([], 'field'));

	$t->same('short', ellipsis('short', 5));
	$t->same('...fghij', ellipsis('abcdefghij', 5, 'left'));
	$t->same('ab...ij', ellipsis('abcdefghij', 5, 'center'));
	$t->same('abcde...', ellipsis('abcdefghij', 5));
	$t->same('abcde...', ellipsis('abcdefghij', 5, 'unknown'));
	$t->same(4, array_average([2, 4, 6]));

	$values=['alpha'=>1, 'beta'=>2, 7=>3];
	$shuffled=array_shuffle($values);
	$t->same($values, array_replace(array_fill_keys(array_keys($values), null), $shuffled));
	$t->same([], array_shuffle([]));
	$t->same(0, array_count(false));
	$t->same(0, array_count(null));
	$t->same(0, array_count('not-an-array'));
	$t->same(2, array_count(['a', 'b']));
});

test('folder copying preserves nested trees without depending on a core-class recursion shim', static function(Context $t): void {
	$workspace=$t->workspace('core-language-copy');
	$source=$workspace->directory('source/nested');
	$workspace->file('source/root.txt', 'root');
	$workspace->file('source/nested/child.txt', 'child');
	$destination=$workspace->path('destination');

	copy_folder(dirname($source), $destination);

	$t->same('root', file_get_contents($destination.'/root.txt'));
	$t->same('child', file_get_contents($destination.'/nested/child.txt'));
});
