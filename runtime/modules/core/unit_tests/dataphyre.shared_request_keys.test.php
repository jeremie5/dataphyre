<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\suite;
use function Dataphyre\Test\test;

require_once dirname(__DIR__, 3).'/shared_request_keys.php';

suite('Shared request key security')
	->tag('core', 'security', 'shared-request-key')
	->group('framework-coverage')
	->contract('core.shared-request-keys', 1)
	->layer('unit')
	->risk('critical')
	->isolation('case');

test('shared request keys are purpose context secret and time bound', static function(Context $t): void {
	$workspace=$t->workspace('shared-request-key-valid');
	$secret=$workspace->file('secrets/app_override_key', "unit-secret\n");
	$timestamp=1_750_000_000;
	$period=60;
	$token=dp_shared_request_key($secret, 'app_override', 'serve', $timestamp, $period);
	$t->matches('/^[a-f0-9]{64}$/D', (string)$token);
	$t->isTrue(dp_verify_shared_request_key((string)$token, $secret, 'app_override', 'serve', 0, $timestamp, $period));
	$t->isTrue(dp_verify_shared_request_key((string)$token, $secret, 'app_override', 'serve', 1, $timestamp+$period, $period));
	$t->isFalse(dp_verify_shared_request_key((string)$token, $secret, 'other', 'serve', 1, $timestamp, $period));
	$t->isFalse(dp_verify_shared_request_key((string)$token, $secret, 'app_override', 'other', 1, $timestamp, $period));
	$t->isFalse(dp_verify_shared_request_key((string)$token, $secret, 'app_override', 'serve', 1, $timestamp+(2*$period), $period));
	$other=$workspace->file('secrets/other', 'other-secret');
	$t->isFalse(dp_verify_shared_request_key((string)$token, $other, 'app_override', 'serve', 1, $timestamp, $period));
});

test('application override values accept scoped signatures and retain legacy compatibility', static function(Context $t): void {
	$workspace=$t->workspace('shared-request-app-override');
	$secret=$workspace->file('secrets/app_override_key', 'override-secret');
	$token=dp_shared_request_key($secret, 'app_override', 'serve');
	$t->same('serve', dp_app_override_application('serve,'.(string)$token, $secret));
	$t->same('serve', dp_app_override_application('serve,override-secret', $secret));
	$t->isFalse(dp_app_override_application('other,'.(string)$token, $secret));
	$t->isFalse(dp_app_override_application('../serve,'.(string)$token, $secret));
	$t->isFalse(dp_app_override_application('serve', $secret));
});

test('shared request keys fail closed for malformed inputs and unavailable secrets', static function(Context $t): void {
	$workspace=$t->workspace('shared-request-key-invalid');
	$empty=$workspace->file('secrets/empty', "\n");
	$t->isFalse(dp_shared_request_secret(''));
	$t->isFalse(dp_shared_request_secret($empty));
	$t->isFalse(dp_shared_request_secret($workspace->path('secrets/missing')));
	$t->isFalse(dp_shared_request_key($empty, 'purpose'));
	$secret=$workspace->file('secrets/valid', 'secret');
	$t->isFalse(dp_shared_request_key($secret, ' '));
	$t->isFalse(dp_verify_shared_request_key('not-a-token', $secret, 'purpose'));
	$token=dp_shared_request_key($secret, 'purpose', '', 120, 0);
	$t->isTrue(dp_verify_shared_request_key((string)$token, $secret, 'purpose', '', 0, 120, 0));
});
