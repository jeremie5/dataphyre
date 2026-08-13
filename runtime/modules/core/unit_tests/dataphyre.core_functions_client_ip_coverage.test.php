<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	const DP_CORE_CFG=[
		'private_key'=>['client-ip-key'],
		'encryption_version'=>0,
		'core'=>[
			'client_ip_identification'=>[
				'default_ip'=>'192.0.2.1',
				'trusted_proxies'=>['bad/99', '10.0.0.0/8', '127.0.0.1'],
				'trusted_ip_headers'=>['HTTP_X_BAD_IP', 'HTTP_X_FORWARDED_FOR'],
			],
		],
	];
	function tracelog(mixed ...$arguments): void {}
}

namespace {
	use Dataphyre\Test\Context;
	use function Dataphyre\Test\test;
	if(!defined('CPU_USAGE')){ define('CPU_USAGE', 10.0); }
	if(!defined('CFG')){
		define('CFG', new class implements ArrayAccess {
			private array $data=[];
			public function &raw(): array { return $this->data; }
			public function offsetExists(mixed $offset): bool { return isset($this->data[(string)$offset]); }
			public function offsetGet(mixed $offset): mixed { return $this->data[(string)$offset] ?? null; }
			public function offsetSet(mixed $offset, mixed $value): void { $this->data[(string)$offset]=$value; }
			public function offsetUnset(mixed $offset): void { unset($this->data[(string)$offset]); }
		});
	}
	$kernel=rtrim((string)(ROOTPATH['common_dataphyre_runtime'] ?? ''), '/\\').'/modules/core/kernel';
	require_once $kernel.'/core_functions.php';

	test('core functions resolve trusted exact and CIDR proxy client addresses safely', static function(Context $t): void {
		$server=$t->globalMap('_SERVER')->merge([
			'REMOTE_ADDR'=>'10.2.3.4',
			'HTTP_X_BAD_IP'=>'not-an-ip',
			'HTTP_X_FORWARDED_FOR'=>'203.0.113.8, 10.2.3.4',
		]);
		$cidr=\dataphyre\core::get_client_ip_details();
		$t->same('203.0.113.8', $cidr['ip']);
		$t->same('header', $cidr['source']);
		$t->same('HTTP_X_FORWARDED_FOR', $cidr['source_header']);
		$t->isTrue($cidr['trusted_proxy']);
		$t->same('203.0.113.8', \dataphyre\core::get_client_ip());

		$server->put('REMOTE_ADDR','127.0.0.1')->put('HTTP_X_FORWARDED_FOR','198.51.100.9');
		$exact=\dataphyre\core::get_client_ip_details();
		$t->same('198.51.100.9', $exact['ip']);
		$t->isTrue($exact['trusted_proxy']);

		$server->put('REMOTE_ADDR','192.0.2.44');
		$direct=\dataphyre\core::get_client_ip_details();
		$t->same('192.0.2.44', $direct['ip']);
		$t->same('remote_addr', $direct['source']);
		$t->isFalse($direct['trusted_proxy']);
	})->tag('core', 'functions', 'client-ip', 'coverage')->group('framework-coverage');
}
