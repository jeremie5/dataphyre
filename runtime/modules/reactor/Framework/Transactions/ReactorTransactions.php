<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Reactor;

/** Ergonomic entrypoint for transactional Reactor state. */
final class ReactorTransactions {
	public static function make(string $component,int $baseVersion=0):ReactorStateTransaction{return ReactorStateTransaction::make($component,$baseVersion);}
	public static function patch(string $operation,string $path,mixed $value=null):ReactorStatePatch{return ReactorStatePatch::make($operation,$path,$value);}
	public static function memory(array $state=[],string $component='component',int $version=0):ReactorTransactionCoordinator{$store=new ReactorInMemoryTransactionStore();if($state!==[]||$version>0){$store->seed($component,$state,$version);}return new ReactorTransactionCoordinator($store);}
	public static function filesystem(string $directory):ReactorTransactionCoordinator{return new ReactorTransactionCoordinator(new ReactorFileTransactionStore($directory));}
	public static function endpoint(ReactorTransactionStore|ReactorTransactionCoordinator $runtime):ReactorTransactionEndpoint{return new ReactorTransactionEndpoint($runtime instanceof ReactorTransactionCoordinator?$runtime:new ReactorTransactionCoordinator($runtime));}
	public static function retry(array $policy=[]):ReactorRetryPolicy{return ReactorRetryPolicy::fromArray($policy);}
	public static function manifest():array{return ['type'=>'dataphyre_reactor_transactions','version'=>2,'capabilities'=>['optimistic_updates'=>true,'exact_rollback'=>true,'compare_and_swap'=>true,'conflict_rebase'=>true,'idempotency'=>true,'offline_queue'=>true,'offline_scope_isolation'=>true,'offline_ttl_backpressure'=>true,'retry_policy'=>true,'atomic_filesystem_store'=>true,'event_stream'=>true,'named_sse_events'=>true,'cursor_reconnect_deduplication'=>true,'authorization_fail_closed'=>true,'host_transport_security_hooks'=>true,'http_endpoint'=>true,'browser_runtime'=>true]];}
}
