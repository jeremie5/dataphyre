<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

function dp_sql_kernel_flightdeck_trace_caller(\Dataphyre\Test\NonPublicAccess $sql): array {
	return $sql->invoke('trace_caller');
}

function dp_sql_kernel_flightdeck_trace_stack(\Dataphyre\Test\NonPublicAccess $sql): array {
	return $sql->invoke('trace_stack');
}
