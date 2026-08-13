<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Composable scheduler runner for the mailer outbox.
 */
final class mailer_scheduler {
	/**
	 * Flushes a bounded outbox batch and optionally prunes retained records.
	 *
	 * @param ?array<string,mixed> $scheduler Scheduler configuration override.
	 * @return array{flush:array<string,mixed>,prune:?array<string,mixed>}
	 */
	public static function run(
		?array $scheduler=null,
		?callable $flush=null,
		?callable $prune=null,
		?callable $log=null
	): array {
		$scheduler??=(array)(DP_MAILER_CFG['scheduler'] ?? []);
		$limit=max(1, min(250, (int)($scheduler['batch_size'] ?? 25)));
		$flush??=[mailer::class, 'flush'];
		$flushResult=(array)$flush($limit);
		$pruneConfig=$scheduler['prune'] ?? [];
		$pruneResult=null;
		if(is_array($pruneConfig) && ($pruneConfig['enabled'] ?? false)===true){
			$options=is_array($pruneConfig['options'] ?? null) ? $pruneConfig['options'] : [];
			$prune??=[mailer::class, 'prune'];
			$pruneResult=(array)$prune($options);
			$log??=function_exists('tracelog') ? 'tracelog' : null;
			if(is_callable($log)){
				$log(__FILE__, __LINE__, __CLASS__, __FUNCTION__, 'Mailer scheduler prune completed', [
					'flush'=>$flushResult,
					'prune'=>$pruneResult,
				]);
			}
		}
		return ['flush'=>$flushResult, 'prune'=>$pruneResult];
	}
}

if(!defined('DATAPHYRE_MAILER_SCHEDULER_NO_DISPATCH')){
	mailer_scheduler::run();
}
