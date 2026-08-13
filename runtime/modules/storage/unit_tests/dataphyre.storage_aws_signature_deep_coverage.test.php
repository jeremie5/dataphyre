<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Storage\Support\AwsSignatureV4;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

$dp_storage_signer_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/storage';
require_once $dp_storage_signer_root.'/Framework/Support/AwsSignatureV4.php';

test('storage AWS signer rejects missing credentials for header and presigned requests', static function(Context $t): void {
	$t->throws(
		static fn()=>AwsSignatureV4::headers('GET', 'https://bucket.example.test/object', []),
		RuntimeException::class
	);
	$t->throws(
		static fn()=>AwsSignatureV4::presignedUrl('GET', 'https://bucket.example.test/object', [], time()+60),
		RuntimeException::class
	);
})->tag('storage', 'coverage')->group('framework-coverage');
