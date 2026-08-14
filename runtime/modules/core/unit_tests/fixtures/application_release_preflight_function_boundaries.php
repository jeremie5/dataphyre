<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Dataphyre\Release;

/** Deterministic native-failure controls for the isolated preflight boundary case. */
final class ApplicationReleasePreflightFunctionBoundary {
	public static ?string $mode=null;
	public static ?string $blockedSuffix=null;
	public static int $statusCalls=0;
	public static int $fgetsCalls=0;

	public static function reset(): void {
		self::$mode=null;
		self::$blockedSuffix=null;
		self::$statusCalls=0;
		self::$fgetsCalls=0;
	}
}

function mkdir(string $directory, int $permissions=0777, bool $recursive=false): bool {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='mkdir_false') return false;
	return \mkdir($directory,$permissions,$recursive);
}

function is_file(string $filename): bool {
	$suffix=ApplicationReleasePreflightFunctionBoundary::$blockedSuffix;
	if(is_string($suffix) && str_ends_with(str_replace('\\','/',$filename),$suffix)) return false;
	return \is_file($filename);
}

function proc_open( // dataphyre-test-architecture: exempt[raw-process-control] reason="Namespace seam deterministically models release-preflight process startup failure."
	array|string $command,
	array $descriptorSpec,
	mixed &$pipes,
	?string $workingDirectory=null,
	?array $environment=null,
	?array $options=null,
): mixed {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='proc_open_false'){
		$pipes=[];
		return false;
	}
	return \proc_open($command,$descriptorSpec,$pipes,$workingDirectory,$environment,$options);
}

function proc_get_status(mixed $process): array|false {
	ApplicationReleasePreflightFunctionBoundary::$statusCalls++;
	$mode=ApplicationReleasePreflightFunctionBoundary::$mode;
	if($mode==='invalid_first_status' && ApplicationReleasePreflightFunctionBoundary::$statusCalls===1) return false;
	$status=\proc_get_status($process);
	if($mode==='exit_after_open' && ApplicationReleasePreflightFunctionBoundary::$statusCalls>=2 && is_array($status)){
		$status['running']=false;
		$status['exitcode']=0;
	}
	return $status;
}

function posix_getpgid(int $processId): int|false {
	if(in_array(ApplicationReleasePreflightFunctionBoundary::$mode,['group_failure','stop_group_mismatch'],true)) return false;
	return \posix_getpgid($processId);
}

function function_exists(string $function): bool {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='no_posix' && $function==='posix_getpgid') return false;
	return \function_exists($function);
}

function stream_socket_server(
	string $address,
	mixed &$errorCode=null,
	mixed &$errorMessage=null,
	int $flags=STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
	mixed $context=null,
): mixed {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='socket_server_false') return false;
	return $context===null
		? \stream_socket_server($address,$errorCode,$errorMessage,$flags)
		: \stream_socket_server($address,$errorCode,$errorMessage,$flags,$context);
}

function stream_socket_get_name(mixed $socket, bool $remote): string|false {
	return match(ApplicationReleasePreflightFunctionBoundary::$mode){
		'bad_socket_name'=>'invalid',
		'out_of_range_socket_name'=>'127.0.0.1:0',
		default=>\stream_socket_get_name($socket,$remote),
	};
}

function stream_socket_client(
	string $address,
	mixed &$errorCode=null,
	mixed &$errorMessage=null,
	?float $timeout=null,
	int $flags=STREAM_CLIENT_CONNECT,
	mixed $context=null,
): mixed {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='temporary_client') return fopen('php://temp','w+b');
	return $context===null
		? \stream_socket_client($address,$errorCode,$errorMessage,$timeout ?? (float)ini_get('default_socket_timeout'),$flags)
		: \stream_socket_client($address,$errorCode,$errorMessage,$timeout ?? (float)ini_get('default_socket_timeout'),$flags,$context);
}

function fwrite(mixed $stream, string $data, ?int $length=null): int|false {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='temporary_client') return 0;
	return $length===null ? \fwrite($stream,$data) : \fwrite($stream,$data,$length);
}

function fgets(mixed $stream, ?int $length=null): string|false {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='header_read_false'){
		ApplicationReleasePreflightFunctionBoundary::$fgetsCalls++;
		return ApplicationReleasePreflightFunctionBoundary::$fgetsCalls===1 ? "HTTP/1.1 200 OK\r\n" : false;
	}
	return $length===null ? \fgets($stream) : \fgets($stream,$length);
}

function feof(mixed $stream): bool {
	if(ApplicationReleasePreflightFunctionBoundary::$mode==='header_read_false') return false;
	return \feof($stream);
}
