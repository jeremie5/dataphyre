<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

if(!defined('REQUEST_IP_ADDRESS')){
	define('REQUEST_IP_ADDRESS', '127.0.0.1');
}

require_once __DIR__.'/caspow_test_helpers.php';

test('CASPoW solved fixture builds a valid signed proof for declarative manifests', static function(Context $t): void {
	$payload=dp_caspow_solved_payload();
	$decoded=json_decode((string)base64_decode($payload, true), true);
	$t->isTrue(is_array($decoded));
	$t->same('unit_test_verify', $decoded['scope'] ?? null);
	$t->isTrue(is_int($decoded['counter'] ?? null));
	$t->notEmpty((string)($decoded['digest'] ?? ''));
	$t->isTrue(\dataphyre\caspow::verify_payload($payload));
})->tag('caspow','fixtures','declarative-tests')->group('framework-coverage');

test('CASPoW solved fixture can deliberately invalidate only the signature', static function(Context $t): void {
	$payload=dp_caspow_solved_payload(true);
	$decoded=json_decode((string)base64_decode($payload, true), true);
	$t->same('tampered', $decoded['signature'] ?? null);
	$t->isFalse(\dataphyre\caspow::verify_payload($payload));
})->tag('caspow','fixtures','declarative-tests')->group('framework-coverage');
