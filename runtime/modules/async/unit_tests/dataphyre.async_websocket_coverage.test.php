<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre\async {
	use Dataphyre\Test\TestState;

	final class DpAsyncSocketStub {
		public function __construct(public string $name) {}
	}

	function socket_create(int $domain, int $type, int $protocol): DpAsyncSocketStub {
		$state=TestState::channel('async.websocket');
		$socket=new DpAsyncSocketStub('server-'.count($state->get('servers', [])));
		$state->append('servers', $socket);
		return $socket;
	}

	function socket_set_option(mixed $socket, int $level, int $option, mixed $value): bool {
		TestState::channel('async.websocket')->append('calls', 'set-option');
		return true;
	}

	function socket_bind(mixed $socket, string $address, int|string $port): bool {
		TestState::channel('async.websocket')->append('calls', 'bind:'.$address.':'.$port);
		return true;
	}

	function socket_listen(mixed $socket): bool {
		TestState::channel('async.websocket')->append('calls', 'listen');
		return true;
	}

	function socket_select(array &$read, mixed &$write, mixed &$except, int $seconds, int $microseconds=0): int|false {
		$directive=TestState::channel('async.websocket')->shift('select');
		if($directive==='stop' || $directive===null){
			throw new \RuntimeException('stop-websocket-loop');
		}
		if($directive==='listener'){
			$read=[$read[0]];
		}
		elseif($directive==='client'){
			$read=[end($read)];
		}
		return count($read);
	}

	function socket_accept(mixed $socket): DpAsyncSocketStub {
		$state=TestState::channel('async.websocket');
		$client=new DpAsyncSocketStub('client-'.count($state->get('clients', [])));
		$state->append('clients', $client);
		return $client;
	}

	function socket_read(mixed $socket, int $length, int $mode=PHP_BINARY_READ): string|false {
		$state=TestState::channel('async.websocket');
		if($state->get('read_queue', [])===[]){
			return false;
		}
		return $state->shift('read_queue');
	}

	function socket_recv(mixed $socket, string &$buffer, int $length, int $flags): int|false {
		$entry=TestState::channel('async.websocket')->shift('recv_queue');
		if($entry===null){
			$buffer='';
			return 0;
		}
		$buffer=$entry;
		return strlen($entry);
	}

	function socket_write(mixed $socket, string $data, ?int $length=null): int|false {
		TestState::channel('async.websocket')->append('writes', ['socket'=>$socket, 'data'=>$data]);
		return $length??strlen($data);
	}

	function socket_close(mixed $socket): void {
		TestState::channel('async.websocket')->append('closed', $socket);
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	$dp_async_websocket_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/async/';
	require_once $dp_async_websocket_root.'kernel/websocket.php';

	function dp_async_client_frame(string $payload, int $lengthCode=0): string {
		$mask="\x01\x02\x03\x04";
		$length=strlen($payload);
		if($lengthCode===126){
			$header=chr(0x81).chr(0x80|126).pack('n', $length);
		}
		elseif($lengthCode===127){
			$header=chr(0x81).chr(0x80|127).pack('NN', 0, $length);
		}
		else
		{
			$header=chr(0x81).chr(0x80|$length);
		}
		$masked='';
		for($i=0; $i<$length; $i++){
			$masked.=$payload[$i]^$mask[$i%4];
		}
		return $header.$mask.$masked;
	}

	function dp_async_ws_scenario(Context $t): TestState {
		return dp_async_ws_reset($t->state('async.websocket'));
	}

	function dp_async_ws_reset(TestState $state): TestState {
		return $state->replace([
			'servers'=>[],
			'clients'=>[],
			'calls'=>[],
			'writes'=>[],
			'closed'=>[],
			'select'=>[],
			'read_queue'=>[],
			'recv_queue'=>[],
		]);
	}

	test('async websocket covers callback registration handshake broadcast and frame lengths', static function(Context $t): void {
		$state=dp_async_ws_scenario($t);
		$server=new \dataphyre\async\web_socket_server('127.0.0.1', 8080);
		$internals=$t->nonPublic($server);
		$connected=0;
		$server->on('connect', static function()use(&$connected): void { $connected++; });
		$server->on('unsupported', static function(): void {});

		$client=new \dataphyre\async\DpAsyncSocketStub('handshake-client');
		$state->put('read_queue', ["GET /chat HTTP/1.1\r\nHost: unit.test\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n"]);
		$internals->invoke('handshake', $client);
		$t->contains('101 Web Socket Protocol Handshake', $state->get('writes')[0]['data']);
		$t->contains('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $state->get('writes')[0]['data']);

		$t->same('short', $internals->invoke('unmask', dp_async_client_frame('short')));
		$t->same(str_repeat('m', 130), $internals->invoke('unmask', dp_async_client_frame(str_repeat('m', 130), 126)));
		$t->same(str_repeat('l', 66000), $internals->invoke('unmask', dp_async_client_frame(str_repeat('l', 66000), 127)));

		$t->same(2+5, strlen($internals->invoke('mask', 'short')));
		$t->same(4+130, strlen($internals->invoke('mask', str_repeat('m', 130))));
		$t->same(10+66000, strlen($internals->invoke('mask', str_repeat('l', 66000))));

		$source=new \dataphyre\async\DpAsyncSocketStub('source');
		$peer=new \dataphyre\async\DpAsyncSocketStub('peer');
		$internals->writeProperty('clients', [$source, $peer]);
		$state->put('writes', []);
		$internals->invoke('broadcast', $source, dp_async_client_frame('broadcast'));
		$t->same(1, count($state->get('writes')));
		$t->same($peer, $state->get('writes')[0]['socket']);
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async websocket start loop covers connect message and disconnect branches with socket stubs', static function(Context $t): void {
		$state=dp_async_ws_scenario($t);
		$connects=0;
		$server=new \dataphyre\async\web_socket_server('127.0.0.1', 9001);
		$server->on('connect', static function()use(&$connects): void { $connects++; });
		$state->put('select', ['listener', 'stop']);
		$state->put('read_queue', ["GET / HTTP/1.1\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n"]);
		try{
			$server->start();
		}catch(RuntimeException $exception){
			$t->same('stop-websocket-loop', $exception->getMessage());
		}
		$t->same(1, $connects);

		dp_async_ws_reset($state);
		$messages=[];
		$server=new \dataphyre\async\web_socket_server('127.0.0.1', 9002);
		$server->on('message', static function(mixed $socket, string $message)use(&$messages): void { $messages[]=$message; });
		$state->put('select', ['listener', 'client', 'stop']);
		$state->put('read_queue', ["GET / HTTP/1.1\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n"]);
		$state->put('recv_queue', [dp_async_client_frame('message')]);
		try{
			$server->start();
		}catch(RuntimeException $exception){}
		$t->same(1, count($messages));

		dp_async_ws_reset($state);
		$disconnects=0;
		$server=new \dataphyre\async\web_socket_server('127.0.0.1', 9003);
		$server->on('disconnect', static function()use(&$disconnects): void { $disconnects++; });
		$state->put('select', ['listener', 'client', 'stop']);
		$state->put('read_queue', [
			"GET / HTTP/1.1\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n\r\n",
			false,
		]);
		$state->put('recv_queue', [null]);
		try{
			$server->start();
		}catch(RuntimeException $exception){}
		$t->same(1, $disconnects);
		$t->same(1, count($state->get('closed')));

		dp_async_ws_reset($state);
		$server=new \dataphyre\async\web_socket_server('127.0.0.1', 9004);
		$server->start(0);
	})->tag('async', 'coverage')->group('kernel-coverage');
}
