<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function dp_sql_kernel_recursive_trace_stack(int $depth,\Dataphyre\Test\NonPublicAccess $sql): array {
	if($depth>0){
		return dp_sql_kernel_recursive_trace_stack($depth-1,$sql);
	}
	return $sql->invoke('trace_stack');
}
