<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Reactor\ReactorSigner;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['reactor']);

test('reactor signer creates a private process-local development key when no secret exists', static function(Context $t): void {
	$payload=['component'=>'local'];
	$signature=ReactorSigner::sign($payload);
	$t->same(64, strlen($signature));
	$t->same($signature, ReactorSigner::sign($payload));
	$t->isTrue(ReactorSigner::verify($payload, $signature));
	$t->isFalse(ReactorSigner::verify($payload, ''));
	$t->isFalse(ReactorSigner::verify($payload, 'malformed'));
	$t->notSame(hash_hmac('sha256', '{"component":"local"}', 'dataphyre-reactor-local-secret'), $signature);
	$manifest=ReactorSigner::manifest();
	$t->hasPathValues([
		'type'=>'reactor_signer',
		'schema_version'=>1,
		'configured'=>false,
		'ready'=>true,
		'source'=>'process_ephemeral',
		'strong_secrets'=>true,
		'ephemeral_process_local'=>true,
		'unsigned_debug_payloads'=>false,
		'secrets_serialized'=>false,
	], $manifest);
	$t->notContains('dataphyre-reactor-local-secret', json_encode($manifest, JSON_THROW_ON_ERROR));
	$t->throws(static fn()=>ReactorSigner::sign(['invalid'=>new stdClass()]), InvalidArgumentException::class);
})->tag('reactor', 'coverage')->group('framework-coverage');
