<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/**
 * Scheduler runner for the mailer outbox.
 *
 * The scheduling module executes this file out of band. It clamps the configured
 * batch size to the same 1..250 range as the manager, flushes due outbox rows,
 * and optionally runs retention pruning. Prune diagnostics are written only when
 * tracelog is available; flush results otherwise remain in-process runner state.
 */
$limit=(int)(DP_MAILER_CFG['scheduler']['batch_size'] ?? 25);
$flush_result=mailer::flush(max(1, min(250, $limit)));

$prune=DP_MAILER_CFG['scheduler']['prune'] ?? [];
if(is_array($prune) && ($prune['enabled'] ?? false)===true){
	$options=is_array($prune['options'] ?? null) ? $prune['options'] : [];
	$prune_result=mailer::prune($options);
	if(function_exists('tracelog')){
		tracelog(__FILE__, __LINE__, __CLASS__, __FUNCTION__, $T='Mailer scheduler prune completed', [
			'flush'=>$flush_result,
			'prune'=>$prune_result,
		]);
	}
}
