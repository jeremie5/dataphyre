<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace dataphyre {
	use Dataphyre\Test\TestState;

	function tracelog(mixed ...$arguments): void {}
	function dp_module_present(mixed ...$arguments): bool { return false; }
	function dp_define_module_config(string $module, string $constant, array $defaults=[]): void {
		if(!defined($constant)){
			define($constant, $defaults);
		}
	}

	function curl_init(?string $url=null): object {
		TestState::channel('async.kernel')->append('curl_calls', ['init', $url]);
		return (object)['url'=>$url];
	}

	function curl_setopt_array(object $handle, array $options): bool {
		TestState::channel('async.kernel')->append('curl_calls', ['options', $options]);
		return true;
	}

	function curl_exec(object $handle): string|false {
		return TestState::channel('async.kernel')->get('curl_response', 'response-body');
	}

	function curl_error(object $handle): string {
		return (string)TestState::channel('async.kernel')->get('curl_error', '');
	}

	function curl_getinfo(object $handle): array {
		return TestState::channel('async.kernel')->get('curl_info', ['http_code'=>200]);
	}

	function curl_close(object $handle): void {
		TestState::channel('async.kernel')->append('curl_calls', ['close', $handle->url]);
	}

	function feof($stream): bool {
		if(TestState::channel('async.kernel')->get('stream_read_failure', false)===true){
			return false;
		}
		return \feof($stream);
	}

	function fread($stream, int $length): string|false {
		if(TestState::channel('async.kernel')->get('stream_read_failure', false)===true){
			return false;
		}
		return \fread($stream, $length);
	}

	function fwrite($stream, string $data): int|false {
		if(TestState::channel('async.kernel')->get('stream_write_failure', false)===true){
			return false;
		}
		return \fwrite($stream, $data);
	}
}

namespace {
	use Dataphyre\Test\Context;
	use Dataphyre\Test\TestState;
	use function Dataphyre\Test\test;

	if(!function_exists('tracelog')){
		function tracelog(mixed ...$arguments): void {}
	}
	if(!defined('DP_ASYNC_CFG')){
		define('DP_ASYNC_CFG', [
			'dependencies'=>[],
			'included_vars'=>[],
			'excluded_vars'=>[],
			'framework'=>['default_dispatcher'=>'inline', 'pool_concurrency'=>3],
		]);
	}
	$dp_async_kernel_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/async/';
	require_once $dp_async_kernel_root.'kernel/async.main.php';
	require_once $dp_async_kernel_root.'Framework/Bootstrap.php';
	require_once $dp_async_kernel_root.'unit_tests/async_test_helpers.php';

	function dp_async_kernel_scenario(Context $t, array $overrides=[]): TestState {
		return $t->state('async.kernel', array_replace([
			'curl_calls'=>[],
			'curl_response'=>'response-body',
			'curl_error'=>'',
			'curl_info'=>['http_code'=>200],
			'stream_read_failure'=>false,
			'stream_write_failure'=>false,
		], $overrides));
	}

	test('async kernel HTTP promises cover success headers JSON post and transport rejection', static function(Context $t): void {
		$state=dp_async_kernel_scenario($t, [
			'curl_response'=>'plain-body',
			'curl_info'=>['http_code'=>201, 'content_type'=>'text/plain'],
		]);

		$get=\dataphyre\async::get_url('https://unit.test/get', ['Accept: text/plain']);
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same('plain-body', $get->value());

		$getHeaders=\dataphyre\async::get_url('https://unit.test/headers', [], true, -5);
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same('plain-body', $getHeaders->value()['body']);
		$t->same(201, $getHeaders->value()['headers']['http_code']);

		$post=\dataphyre\async::post_url('https://unit.test/post', ['name'=>'value'], ['X-Test: yes']);
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same('plain-body', $post->value());

		$postHeaders=\dataphyre\async::post_url('https://unit.test/post-headers', ['id'=>7], [], true, 4);
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same(201, $postHeaders->value()['headers']['http_code']);

		$state->put('curl_response', '{"ok":true,"count":2}');
		$getJson=\dataphyre\async::get_json('https://unit.test/json');
		$postJson=\dataphyre\async::post_json('https://unit.test/json-post', ['sent'=>true]);
		\dataphyre\async\coroutine::run();
		$t->same(['ok'=>true, 'count'=>2], $getJson->value());
		$t->same(['ok'=>true, 'count'=>2], $postJson->value());

		$state->put('curl_error', 'transport-failure');
		$failed=\dataphyre\async::get_url('https://unit.test/fail');
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same('rejected', $failed->state());
		$t->same('transport-failure', $failed->value()->getMessage());
		$postFailed=\dataphyre\async::post_url('https://unit.test/post-fail', ['failed'=>true]);
		\dataphyre\async::run_event_loop();
		\dataphyre\async\coroutine::run();
		$t->same('rejected', $postFailed->state());
		$t->isTrue(count($state->get('curl_calls'))>0);
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async kernel streams cover reads writes and failure results', static function(Context $t): void {
		$state=dp_async_kernel_scenario($t);
		$stream=fopen('php://temp', 'w+b');
		\fwrite($stream, 'stream-data');
		rewind($stream);
		$read=\dataphyre\async::read_stream($stream);
		\dataphyre\async\coroutine::run();
		$t->same('stream-data', $read->value());

		$state->put('stream_read_failure', true);
		$readFailure=\dataphyre\async::read_stream($stream);
		\dataphyre\async\coroutine::run();
		$t->same('Error reading stream', $readFailure->value()->getMessage());
		$state->put('stream_read_failure', false);

		$output=fopen('php://temp', 'w+b');
		$write=\dataphyre\async::write_stream($output, 'written');
		\dataphyre\async\coroutine::run();
		$t->same(7, $write->value());
		$state->put('stream_write_failure', true);
		$writeFailure=\dataphyre\async::write_stream($output, 'ignored');
		\dataphyre\async\coroutine::run();
		$t->same('Error writing to stream', $writeFailure->value()->getMessage());
		$state->put('stream_write_failure', false);
		fclose($stream);
		fclose($output);
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('async kernel scheduling queues limits tokens timers context and facade delegates are deterministic', static function(Context $t): void {
		$asyncInternals=$t->nonPublic(\dataphyre\async::class);
		$throttled=0;
		\dataphyre\async::throttle('unit-throttle', static function()use(&$throttled): void { $throttled++; }, 0);
		\dataphyre\async::throttle('unit-throttle', static function()use(&$throttled): void { $throttled+=100; }, 0);
		\dataphyre\async\coroutine::run();
		\dataphyre\async::throttle('unit-throttle', static function()use(&$throttled): void { $throttled++; }, 0);
		\dataphyre\async\coroutine::run();
		$t->same(2, $throttled);

		$debounced=0;
		\dataphyre\async::debounce('unit-debounce', static function()use(&$debounced): void { $debounced+=10; }, 0);
		\dataphyre\async::debounce('unit-debounce', static function()use(&$debounced): void { $debounced++; }, 0);
		\dataphyre\async\coroutine::run();
		$t->same(1, $debounced);

		$queue=[];
		\dataphyre\async::queue(static function()use(&$queue): void {
			$queue[]='first';
			\dataphyre\async::queue(static function()use(&$queue): void { $queue[]='second'; });
		});
		$t->same(['first', 'second'], $queue);

		$logged=[];
		$asyncInternals->invoke('log', 'ignored-without-logger');
		\dataphyre\async::set_logger(static function(string $message)use(&$logged): void { $logged[]=$message; });
		$asyncInternals->invoke('log', 'logged');
		try{
			$asyncInternals->invoke('handle_error', new RuntimeException('handled'));
		}catch(RuntimeException $exception){
			// The private handler intentionally forwards the runtime error after logging it.
		}
		$t->same('logged', $logged[0]);
		$t->contains('Error: handled', implode('|', $logged));

		$token=\dataphyre\async::create_cancellation_token();
		$t->isFalse(\dataphyre\async::is_cancelled($token));
		$t->isFalse(\dataphyre\async::is_cancelled('unknown-token'));
		\dataphyre\async::cancel_token('unknown-token');
		\dataphyre\async::cancel_token($token);
		$t->isTrue(\dataphyre\async::is_cancelled($token));

		$batch=[];
		$asyncInternals->writeProperty('prioritized_event_loop', [2=>[static function()use(&$batch): void { $batch[]='priority'; }]]);
		$asyncInternals->writeProperty('current_batch', [static function()use(&$batch): void { $batch[]='current'; }]);
		\dataphyre\async::process_batches();
		$t->same(['current', 'priority'], $batch);
		$asyncInternals->writeProperty('current_batch', [static function()use(&$batch): void { $batch[]='current-only'; }]);
		\dataphyre\async::process_batches();
		$t->same(['current', 'priority', 'current-only'], $batch);

		$rate=[];
		$asyncInternals->writeProperty('current_rate', 0);
		$asyncInternals->writeProperty('rate_limit', 1);
		$asyncInternals->invoke('manage_rate_limiting', static function()use(&$rate): void { $rate[]='direct'; });
		$asyncInternals->invoke('manage_rate_limiting', static function()use(&$rate): void { $rate[]='queued'; });
		$asyncInternals->invoke('task_rate_complete');
		$asyncInternals->invoke('task_rate_complete');
		$t->same(['direct', 'queued'], $rate);

		$concurrency=[];
		$asyncInternals->writeProperty('waiting_queue', []);
		$asyncInternals->writeProperty('current_concurrency', 0);
		$asyncInternals->writeProperty('concurrency_limit', 1);
		$asyncInternals->invoke('manage_concurrency', static function()use(&$concurrency): void { $concurrency[]='direct'; });
		$asyncInternals->invoke('manage_concurrency', static function()use(&$concurrency): void { $concurrency[]='queued'; });
		$asyncInternals->invoke('task_complete');
		$asyncInternals->invoke('task_complete');
		$t->same(['direct', 'queued'], $concurrency);

		$eventLoop=[];
		$asyncInternals->invoke('add_to_event_loop', static function()use(&$eventLoop): void { $eventLoop[]='later'; }, 5);
		$asyncInternals->invoke('add_to_event_loop', static function()use(&$eventLoop): void { $eventLoop[]='earlier'; }, -1);
		\dataphyre\async::run_event_loop();
		$asyncInternals->writeProperty('current_batch', [static function()use(&$eventLoop): void { $eventLoop[]='batch'; }]);
		\dataphyre\async::run_event_loop();
		$t->same(['earlier', 'later', 'batch'], $eventLoop);

		\dataphyre\async::set_batch_size(9);
		$t->same(9, $asyncInternals->readProperty('batch_size'));

		$timed=new \dataphyre\async\promise(static function(): void {});
		$timed=\dataphyre\async::with_timeout(static function(): void {}, 0);
		\dataphyre\async\coroutine::run();
		$t->isTrue($timed->is_cancelled());

		$parallel=\dataphyre\async::parallel([
			static fn(): string=>'parallel-one',
			static fn(): string=>'parallel-two',
		]);
		\dataphyre\async\coroutine::run();
		$t->same(['parallel-one', 'parallel-two'], $parallel->value());

		$timer=0;
		\dataphyre\async::set_timeout(static function()use(&$timer): void { $timer++; }, 0);
		\dataphyre\async\coroutine::run();
		$t->same(1, $timer);
		$cancelledTimer=\dataphyre\async::set_timeout(static function()use(&$timer): void { $timer+=100; }, 0);
		\dataphyre\async::cancel($cancelledTimer);
		\dataphyre\async::cancel(-1);

		$interval=0;
		\dataphyre\async::set_interval(static function()use(&$interval): never {
			$interval++;
			throw new RuntimeException('stop-interval');
		}, 0);
		\dataphyre\async\coroutine::run();
		$t->same(1, $interval);

		$deferred=0;
		\dataphyre\async::defer(static function()use(&$deferred): void { $deferred++; });
		$awaited=\dataphyre\async::await(static fn(): string=>'awaited');
		\dataphyre\async\coroutine::run();
		$t->same(1, $deferred);
		$t->same('awaited', $awaited->value());
		\dataphyre\async::set_context('unit-key', 'unit-value');
		$t->same('unit-value', \dataphyre\async::get_context('unit-key'));
		$t->same(null, \dataphyre\async::get_context('missing-key'));

		$resolved=new \dataphyre\async\promise(static fn(callable $resolve): mixed=>$resolve('resolved'));
		$t->same('resolved', \dataphyre\async::timeout($resolved, 50)->value());
		$t->same('retried', \dataphyre\async::retry(static fn(): \dataphyre\async\promise=>new \dataphyre\async\promise(
			static fn(callable $resolve): mixed=>$resolve('retried')
		), 1)->value());

		$facadeTimer=0;
		$afterId=\Dataphyre\Async\Async::after(static function()use(&$facadeTimer): void { $facadeTimer++; }, 0);
		\Dataphyre\Async\Async::cancel($afterId);
		$everyId=\Dataphyre\Async\Async::every(static function()use(&$facadeTimer): never {
			$facadeTimer++;
			throw new RuntimeException('stop-facade-interval');
		}, 0);
		\dataphyre\async\coroutine::run();
		\Dataphyre\Async\Async::cancel($everyId);
		$t->same(1, $facadeTimer);

		$bridge=[];
		\dataphyre\async::on_event('bridge-event', static function(string $value)use(&$bridge): void { $bridge[]=$value; }, 2);
		\dataphyre\async::add_listener_with_metadata('bridge-meta', static function(): void {}, ['source'=>'async']);
		$emitter=$asyncInternals->readProperty('event_emitter');
		$emitter->emit('bridge-event', 'bridged');
		$t->same(['bridged'], $bridge);
		$t->same(['source'=>'async'], $emitter->get_listener_metadata('bridge-meta')[0]);
	})->tag('async', 'coverage')->group('kernel-coverage');

	test('coroutine kernel covers reentrancy missing tasks generators errors sleep fallback and cancellation', static function(Context $t): void {
		$coroutineInternals=$t->nonPublic(\dataphyre\async\coroutine::class);
		$events=[];
		\dataphyre\async\coroutine::create(static function()use(&$events): void {
			$events[]='reentrant';
			\dataphyre\async\coroutine::run();
		}, 3);
		\dataphyre\async\coroutine::create(static function(): never { throw new RuntimeException('fiber-error'); }, 2);
		\dataphyre\async\coroutine::create(static function()use(&$events): Generator {
			$events[]='generator-start';
			yield 'checkpoint';
			$events[]='generator-end';
			return 'generator-return';
		}, 1);
		$generated=\dataphyre\async\coroutine::async(static function()use(&$events): Generator {
			$events[]='async-generator-start';
			yield 'checkpoint';
			$events[]='async-generator-end';
			return 'async-generator-return';
		});
		$rejected=\dataphyre\async\coroutine::async(static function(): never {
			throw new RuntimeException('async-coroutine-failure');
		});
		\dataphyre\async\coroutine::run();
		$t->same('async-generator-return', $generated->value());
		$t->same('async-coroutine-failure', $rejected->value()->getMessage());
		$t->same(['reentrant', 'generator-start', 'generator-end', 'async-generator-start', 'async-generator-end'], $events);

		$missingId=\dataphyre\async\coroutine::create(static function(): void {});
		$currentFibers=$coroutineInternals->readProperty('fibers');
		unset($currentFibers[$missingId]);
		$coroutineInternals->writeProperty('fibers', $currentFibers);
		\dataphyre\async\coroutine::run();

		try{
			\dataphyre\async\coroutine::sleep(0);
		}catch(FiberError $exception){
			$t->contains('fiber', strtolower($exception->getMessage()));
		}
		$coroutineInternals->writeProperty('waiting', []);
		$cancelId=\dataphyre\async\coroutine::create(static function(): void {});
		\dataphyre\async\coroutine::cancel($cancelId);
		\dataphyre\async\coroutine::cancel($cancelId);
		$t->same(null, \dataphyre\async\coroutine::get_context('never-set'));
	})->tag('async', 'coverage')->group('kernel-coverage');
}
