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

require_once dirname(__DIR__).'/kernel/access.qr.php';

suite('Access local QR renderer invariants')
	->contract('access.local-qr-renderer', 1)
	->layer('unit')
	->risk('high')
	->watches('module:access')
	->through('capacity', 'codewords', 'reed-solomon', 'matrix', 'svg')
	->isolation('case')
	->tag('access', 'exact-coverage', 'qr')
	->group('framework-coverage');

test('QR public boundary rejects empty and oversized payloads and clamps presentation dimensions', static function(Context $t): void {
	$t->same(false, \dataphyre\access_qr_renderer::svg_data_uri(''));
	$t->same(false, \dataphyre\access_qr_renderer::svg_data_uri(str_repeat('x', 4096)));
	$uri=\dataphyre\access_qr_renderer::svg_data_uri('A', 1, -5);
	$t->type('string', $uri);
	$t->startsWith('data:image/svg+xml;base64,', $uri);
	$svg=base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
	$t->type('string', $svg);
	$t->contains('width="64"', $svg);
	$t->contains('viewBox="0 0 21 21"', $svg);

	$wide=\dataphyre\access_qr_renderer::svg_data_uri('presentation-clamp', 900, 2);
	$wide_svg=base64_decode(substr((string)$wide, strlen('data:image/svg+xml;base64,')), true);
	$t->contains('width="512"', $wide_svg);
});

test('QR capacity and codeword internals make selected-version assumptions explicit', static function(Context $t): void {
	$qr=$t->nonPublic(\dataphyre\access_qr_renderer::class);
	$t->same(1, $qr->invoke('select_version', 1));
	$t->same(null, $qr->invoke('select_version', 100_000));
	$t->greaterThan(0, $qr->invoke('byte_capacity', 1));
	$codewords=$qr->invoke('create_codewords', 'A', 1);
	$t->same(26, count($codewords));
	$t->throws(static fn()=>$qr->invoke('create_codewords', str_repeat('x', 200), 1), LengthException::class);
	$t->same(0, $qr->invoke('gf_mul', 0, 17));
	$t->same(0, $qr->invoke('gf_mul', 17, 0));
	$t->greaterThan(0, $qr->invoke('gf_mul', 17, 19));
});

test('QR version seven and ten layouts exercise version BCH and multi-center alignment placement', static function(Context $t): void {
	$qr=$t->nonPublic(\dataphyre\access_qr_renderer::class);
	$version_six_capacity=$qr->invoke('byte_capacity', 6);
	$payload=str_repeat('V', $version_six_capacity+1);
	$t->same(7, $qr->invoke('select_version', strlen($payload)));
	$uri=\dataphyre\access_qr_renderer::svg_data_uri($payload, 240, 4);
	$t->type('string', $uri);
	$svg=base64_decode(substr($uri, strlen('data:image/svg+xml;base64,')), true);
	$t->contains('viewBox="0 0 53 53"', $svg);
	$t->greaterThan(0, $qr->invoke('version_bits', 7));
	$t->same([], $qr->invoke('alignment_positions', 1));
	$t->greaterThan(3, count($qr->invoke('alignment_positions', 10)));
});
