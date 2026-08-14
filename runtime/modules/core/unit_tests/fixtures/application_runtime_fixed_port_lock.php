<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

/** Serializes exact-image tests that must bind the product's fixed loopback ports. */
function dataphyre_application_runtime_fixed_port_lock(): mixed
{
	$path=sys_get_temp_dir().'/dataphyre-core-managed-runtime-ports.lock'; // dataphyre-test-architecture: exempt[unmanaged-system-temporary-directory] reason="Independent test workers need one process-shared lock for fixed product ports."
	$lock=@fopen($path,'c');
	if(!is_resource($lock) || !flock($lock,LOCK_EX)) throw new RuntimeException('Managed runtime fixed-port lock is unavailable.');
	return $lock;
}

function dataphyre_application_runtime_fixed_port_unlock(mixed &$lock): void
{
	if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
	$lock=null;
}
