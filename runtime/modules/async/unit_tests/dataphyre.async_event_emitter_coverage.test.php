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

if(!function_exists('tracelog')){
	function tracelog(mixed ...$arguments): void {}
}
if(!function_exists('\\dataphyre\\tracelog')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function tracelog(...$arguments): void {} }');
}
if(!function_exists('\\dataphyre\\dp_module_present')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function dp_module_present(...$arguments): bool { return false; } }');
}
if(!function_exists('\\dataphyre\\dp_define_module_config')){
	\Dataphyre\Test\define_test_symbols('namespace dataphyre { function dp_define_module_config(string $module, string $constant, array $defaults=[]): void { if(!defined($constant)){ define($constant, $defaults); } } }');
}
if(!defined('DP_ASYNC_CFG')){
	define('DP_ASYNC_CFG', [
		'dependencies'=>[],
		'included_vars'=>[],
		'excluded_vars'=>[],
		'framework'=>['default_dispatcher'=>'inline', 'pool_concurrency'=>3],
	]);
}
$dp_async_event_root=rtrim((string)(ROOTPATH['common_dataphyre_runtime']??''), '/\\').'/modules/async/';
require_once $dp_async_event_root.'kernel/async.main.php';

final class DpAsyncEmitterLogger {
	public array $messages=[];
	public function __invoke(string $message): void {
		$this->messages[]=$message;
	}
	public function log(string $message, mixed $context=null): void {
		$this->messages[]=$message;
	}
}

test('async event emitter covers priority aliases transforms defaults logging once and removal', static function(Context $t): void {
	$emitter=new \dataphyre\async\event_emitter();
	$events=[];
	$low=static function(string $value)use(&$events): void { $events[]='low:'.$value; };
	$high=static function(string $value)use(&$events): void { $events[]='high:'.$value; };
	$alias=static function(string $value)use(&$events): void { $events[]='alias:'.$value; };
	$default=static function(string $value='')use(&$events): void { $events[]='default:'.$value; };
	$emitter->on('unit.event', $low, 1, 'workers');
	$emitter->on('unit.event', $high, 9, 'workers');
	$emitter->on('unit.alias', $alias);
	$emitter->set_event_alias('unit.event', 'unit.alias');
	$emitter->set_payload_transformer('unit.event', static fn(string $value): array=>[strtoupper($value)]);
	$emitter->set_default_listener($default);
	$logger=new DpAsyncEmitterLogger();
	$emitter->enable_logging($logger);
	$emitter->emit('unit.event', 'payload');
	$t->same(['high:PAYLOAD', 'low:PAYLOAD', 'alias:PAYLOAD', 'default:PAYLOAD'], $events);
	$t->contains('Event emitted: unit.event', implode('|', $logger->messages));
	$t->same(2, $emitter->get_listener_count('unit.event'));
	$t->same(0, $emitter->get_listener_count('missing'));
	$t->same(2, count($emitter->inspect_listeners('unit.event')));
	$t->same([], $emitter->inspect_listeners('missing'));
	$t->same(2, count($emitter->get_group_listeners('workers')));
	$t->same([], $emitter->get_group_listeners('missing'));

	$once=0;
	$emitter->once('once', static function()use(&$once): void { $once++; }, 4);
	$emitter->emit('once');
	$emitter->emit('once');
	$t->same(1, $once);
	$emitter->remove_listener('unit.event', $low);
	$emitter->remove_listener('missing', $low);
	$t->same(1, $emitter->get_listener_count('unit.event'));
	$emitter->remove_group_listeners('workers');
	$emitter->remove_group_listeners('missing');
	$t->same(0, $emitter->get_listener_count('unit.event'));
	$emitter->disable_logging();
	$emitter->emit('unit.alias', 'quiet');
	$emitter->remove_all_listeners('unit.alias');
	$emitter->remove_all_listeners();
})->tag('async', 'coverage')->group('kernel-coverage');

test('async event emitter covers limits errors async mode propagation wildcard namespaces and wrappers', static function(Context $t): void {
	$emitter=new \dataphyre\async\event_emitter();
	$emitter->set_max_listeners(1);
	$kept=static function(): void {};
	$discarded=static function(): void {};
	$emitter->on('limited', $kept, 10);
	$emitter->on('limited', $discarded, 0);
	$t->same(1, $emitter->get_listener_count('limited'));

	$logger=new DpAsyncEmitterLogger();
	$emitter->enable_logging($logger);
	$emitter->on('failure', static function(): never { throw new RuntimeException('listener-failure'); });
	$emitter->emit('failure');
	$t->contains('listener-failure', implode('|', $logger->messages));

	$async=[];
	$emitter->on('async', static function(string $value)use(&$async): void { $async[]=$value; });
	$emitter->enable_async_mode();
	$emitter->emit('async', 'promise-backed');
	$emitter->disable_async_mode();
	$t->same(['promise-backed'], $async);

	$propagated=0;
	$emitter->on('propagation', static function()use(&$propagated): void { $propagated++; });
	$emitter->stop_propagation('propagation');
	$emitter->emit('propagation');
	$emitter->continue_propagation('propagation');
	$emitter->emit('propagation');
	$t->same(1, $propagated);

	$wild=[];
	$emitter->on('order.*', static function(string $value)use(&$wild): void { $wild[]=$value; });
	$emitter->add_wildcard_listener('stored.*', static function(): void {});
	$emitter->emit('order.created', 'wildcard');
	$t->same(['wildcard'], $wild);

	$namespaced=[];
	$emitter->on_namespace('billing.invoice', static function(string $value)use(&$namespaced): void { $namespaced[]='invoice:'.$value; });
	$emitter->on_namespace('shipping', static function(string $value)use(&$namespaced): void { $namespaced[]='shipping:'.$value; });
	$emitter->emit_to_namespace('billing', 'sent');
	$t->same(['invoice:sent'], $namespaced);

	$metadataListener=static function(): void {};
	$emitter->add_listener_with_metadata('metadata', $metadataListener, ['role'=>'audit']);
	$t->same([['role'=>'audit']], $emitter->get_listener_metadata('metadata'));

	$conditional=[];
	$emitter->add_conditional_listener('conditional', static function(int $value)use(&$conditional): void { $conditional[]=$value; }, static fn(int $value): bool=>$value>5);
	$emitter->emit('conditional', 2);
	$emitter->emit('conditional', 8);
	$t->same([8], $conditional);

	$intercepted=[];
	$emitter->on('intercepted', static function(string $value)use(&$intercepted): void { $intercepted[]='original:'.$value; });
	$emitter->intercept_event('intercepted', static function(callable $original, string $value)use(&$intercepted): void {
		$intercepted[]='before';
		$original($value);
	});
	$emitter->intercept_event('missing', static function(): void {});
	$emitter->emit('intercepted', 'value');
	$t->same(['before', 'original:value'], $intercepted);
})->tag('async', 'coverage')->group('kernel-coverage');

test('async event emitter covers throttle and debounce timer callbacks without recursive scheduling', static function(Context $t): void {
	$throttleEmitter=new \dataphyre\async\event_emitter();
	$throttled=0;
	$throttleEmitter->on('throttled', static function()use(&$throttled): void { $throttled++; });
	$throttleEmitter->throttle('throttled', 1000);
	$throttleEmitter->emit('throttled');
	$t->same(2, $throttled);

	$debounceEmitter=new \dataphyre\async\event_emitter();
	$debounced=0;
	$debounceEmitter->on('debounced', static function()use(&$debounced): void { $debounced++; });
	$debounceEmitter->debounce('debounced', 0);
	$debounceEmitter->emit('debounced');
	$debounceEmitter->emit('debounced');
	$debounceEmitter->emit('debounced');
	$debounceEmitter->stop_propagation('debounced');
	\dataphyre\async\coroutine::run();
	$t->same(3, $debounced);
})->tag('async', 'coverage')->group('kernel-coverage');
