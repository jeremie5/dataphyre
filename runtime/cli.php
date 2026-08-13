<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace dataphyre;

/** Native CLI termination boundary kept outside composable module commands. */
function cli_terminate(int $status): void {
	if(defined('DATAPHYRE_CLI_NO_TERMINATE') && DATAPHYRE_CLI_NO_TERMINATE===true){
		return;
	}
	exit($status);
}
