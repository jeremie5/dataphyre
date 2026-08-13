<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Reactor;

use Dataphyre\Test\TestState;

function dp_reactor_transaction_failure_enabled(string $seam): bool {
	return TestState::channelIfActive('reactor.transaction-failures')?->get($seam,false)===true;
}

function fopen(string $filename,string $mode,bool $useIncludePath=false,mixed $context=null): mixed {
	if(dp_reactor_transaction_failure_enabled('fopen')){
		return false;
	}
	return $context===null
		? \fopen($filename,$mode,$useIncludePath)
		: \fopen($filename,$mode,$useIncludePath,$context);
}

function flock(mixed $stream,int $operation,mixed &$wouldBlock=null): bool {
	if(dp_reactor_transaction_failure_enabled('flock')){
		return false;
	}
	return \flock($stream,$operation,$wouldBlock);
}

function rename(string $from,string $to,mixed $context=null): bool {
	if(dp_reactor_transaction_failure_enabled('rename')){
		return false;
	}
	return $context===null ? \rename($from,$to) : \rename($from,$to,$context);
}

function random_bytes(int $length): string {
	if(dp_reactor_transaction_failure_enabled('random_bytes')){
		throw new \RuntimeException('random bytes unavailable');
	}
	return \random_bytes($length);
}
