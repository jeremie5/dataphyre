<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Cache\SharedCacheProbeCommand;

require_once dirname(__DIR__).'/Framework/SharedCacheProbeCommand.php';

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
	exit(SharedCacheProbeCommand::main($argv ?? []));
}
